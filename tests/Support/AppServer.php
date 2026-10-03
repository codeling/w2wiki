<?php

namespace W2\Tests\Support;

/**
 * Runs a private copy of the wiki (with an individual configuration) on
 * PHP's built-in web server, for the duration of the test run.
 *
 * The application to test is taken from the repository root, or from the
 * folder given in the environment variable W2_APP_ROOT.
 */
final class AppServer
{
	private const APP_FILES = ['index.php', 'api.php', 'auth.php', 'auth_functions.php', 'functions.php', 'config.php', 'index.css', 'wiki.js'];
	private const APP_DIRS = ['Michelf', 'locales', 'w2-icons'];

	/** @var array<string, AppServer> running servers by configuration */
	private static array $instances = [];
	private static bool $shutdownRegistered = false;

	private string $appRoot;
	private string $dir;
	private string $logFile;
	private string $pagesFolder;
	private string $uploadFolder;
	private int $port;
	/** @var array<string, mixed> */
	private array $options;
	/** @var resource */
	private $process;

	/**
	 * Get the server for a configuration, starting it if necessary.
	 *
	 * @param array<string, mixed> $overrides values for constants defined in config.php (by name),
	 *                                        or for the variable $allowedIPs
	 * @param array<string, mixed> $options   "svgSanitizer": make the enshrined/svg-sanitize library
	 *                                        (see svgSanitizerDir()) available to the app;
	 *                                        "port": use this port instead of a free one;
	 *                                        "git": make the pages folder a git repository (with user.name and
	 *                                        user.email), see initGit(); "gitRemote": also create a local bare
	 *                                        repository as "origin" (implies "git");
	 *                                        "pagesFolder": name of the pages folder (default "pages"), e.g. with
	 *                                        spaces and quotes, which is set as PAGES_PATH;
	 *                                        "phpIni": PHP ini values for the server, e.g. ['post_max_size' => '100K']
	 */
	public static function get(array $overrides = [], array $options = []): self
	{
		ksort($overrides);
		ksort($options);
		$key = md5(serialize([$overrides, $options]));
		if (!isset(self::$instances[$key])) {
			self::$instances[$key] = new self($overrides, $options);
		}
		if (!self::$shutdownRegistered) {
			register_shutdown_function([self::class, 'stopAll']);
			self::$shutdownRegistered = true;
		}
		return self::$instances[$key];
	}

	public static function stopAll(): void
	{
		foreach (self::$instances as $server) {
			$server->stop();
		}
		self::$instances = [];
	}

	/**
	 * Folder of the enshrined/svg-sanitize library used by tests (from the
	 * test dependencies, or given in W2_SVG_SANITIZER_DIR), if available
	 */
	public static function svgSanitizerDir(): ?string
	{
		$dir = getenv('W2_SVG_SANITIZER_DIR') ?: dirname(__DIR__, 2) . '/vendor/enshrined/svg-sanitize';
		return is_file("$dir/src/Sanitizer.php") ? $dir : null;
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @param array<string, mixed> $options
	 */
	private function __construct(array $overrides, array $options)
	{
		$this->options = $options;
		$this->appRoot = rtrim(getenv('W2_APP_ROOT') ?: dirname(__DIR__, 2), '/');
		$this->dir = sys_get_temp_dir() . '/w2test-' . bin2hex(random_bytes(6));
		$this->logFile = $this->dir . '.log';
		$this->pagesFolder = (string)($options['pagesFolder'] ?? 'pages');
		$this->uploadFolder = (string)($overrides['UPLOAD_FOLDER'] ?? 'images');
		mkdir($this->dir . '/sessions', 0777, true);
		foreach (self::APP_FILES as $file) {
			if (is_file("$this->appRoot/$file")) {
				copy("$this->appRoot/$file", "$this->dir/$file");
			}
		}
		foreach (self::APP_DIRS as $subDir) {
			if (is_dir("$this->appRoot/$subDir")) {
				self::copyDir("$this->appRoot/$subDir", "$this->dir/$subDir");
			}
		}
		if (is_dir("$this->appRoot/pages")) {
			self::copyDir("$this->appRoot/pages", $this->pagesDir());
			if ($this->uploadFolder !== 'images' && is_dir($this->pagesDir() . '/images')) {
				rename($this->pagesDir() . '/images', $this->imagesDir());
			}
		}
		if (!is_dir($this->imagesDir())) {
			mkdir($this->imagesDir(), 0777, true);
		}
		// the uploads folder is served statically from the root folder, see README.md
		symlink($this->pagesFolder . '/' . $this->uploadFolder, "$this->dir/" . ($overrides['UPLOAD_URL'] ?? $this->uploadFolder));
		if ($this->pagesFolder !== 'pages') {
			$overrides['PAGES_PATH'] = $this->pagesDir();
		}
		$this->writeConfig($overrides);
		$this->initGit();
		if (!empty($options['svgSanitizer'])) {
			$this->installSvgSanitizer();
		}
		$this->start();
	}

	public function baseUrl(): string
	{
		return "http://127.0.0.1:$this->port";
	}

	public function rootDir(): string
	{
		return $this->dir;
	}

	public function pagesDir(): string
	{
		return $this->dir . '/' . $this->pagesFolder;
	}

	/** Folder of the bare repository which is "origin" of the pages repository (option "gitRemote") */
	public function remoteDir(): string
	{
		return $this->dir . '/remote.git';
	}

	/** Output of git (without the trailing line break) in the pages folder, or in the given repository */
	public function git(string $arguments, ?string $dir = null): string
	{
		$command = 'git -C ' . escapeshellarg($dir ?? $this->pagesDir()) . ' ' . $arguments . ' 2>&1';
		return rtrim((string)shell_exec($command), "\n");
	}

	public function imagesDir(): string
	{
		return $this->pagesDir() . '/' . $this->uploadFolder;
	}

	public function logSize(): int
	{
		clearstatcache(true, $this->logFile);
		return is_file($this->logFile) ? (int)filesize($this->logFile) : 0;
	}

	public function logSince(int $offset): string
	{
		$log = is_file($this->logFile) ? (string)file_get_contents($this->logFile) : '';
		return (string)substr($log, $offset);
	}

	/** Restore the initial pages, and remove all uploaded images */
	public function reset(): void
	{
		$keep = [$this->pagesDir() . '/.htaccess', $this->imagesDir() . '/.htaccess'];
		if (!is_dir($this->imagesDir())) {
			mkdir($this->imagesDir(), 0777, true);
		}
		self::removeContents($this->pagesDir(), $keep);
		foreach (glob($this->appRoot . '/pages/*.md') as $page) {
			copy($page, $this->pagesDir() . '/' . basename($page));
		}
		$this->initGit();
	}

	/**
	 * Make the pages folder a repository with the initial pages committed (branch main;
	 * with a local bare repository as origin if requested). Does nothing without the option.
	 */
	private function initGit(): void
	{
		if (empty($this->options['git']) && empty($this->options['gitRemote'])) {
			return;
		}
		$remote = $this->remoteDir();
		if (is_dir($remote)) {
			self::removeContents($remote, []);
			rmdir($remote);
		}
		$commands = [
			'init -q -b main', 'config user.name "W2 Test"', 'config user.email test@example.com',
			'config commit.gpgsign false', 'add -A', 'commit -q -m "Initial pages"',
		];
		if (!empty($this->options['gitRemote'])) {
			$this->git('init -q --bare -b main ' . escapeshellarg($remote), $this->dir);
			$commands[] = 'remote add origin ' . escapeshellarg($remote);
			$commands[] = 'push -q -u origin main';
		}
		foreach ($commands as $command) {
			$this->git($command);
		}
	}

	/** All page files (relative paths) currently below the pages folder, sorted */
	public function pageFiles(): array
	{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
			$this->pagesDir(),
			\FilesystemIterator::SKIP_DOTS
		));
		foreach ($iterator as $file) {
			$files[] = substr($file->getPathname(), strlen($this->pagesDir()) + 1);
		}
		sort($files);
		return $files;
	}

	public function stop(): void
	{
		if (is_resource($this->process)) {
			proc_terminate($this->process);
			proc_close($this->process);
		}
		self::removeContents($this->dir, []);
		@rmdir($this->dir);
		@unlink($this->logFile);
	}

	/** @param array<string, mixed> $overrides */
	private function writeConfig(array $overrides): void
	{
		$config = (string)file_get_contents("$this->appRoot/config.php");
		foreach ($overrides as $name => $value) {
			$literal = var_export($value, true);
			if (str_starts_with($name, '$')) {
				$pattern = '/^' . preg_quote($name, '/') . '\s*=.*;[ \t]*$/m';
				$replacement = "$name = $literal;";
			} else {
				$pattern = '/^define\s*\(\s*\'' . preg_quote($name, '/') . '\'\s*,.*\);[ \t]*$/m';
				$replacement = "define('$name', $literal);";
			}
			$config = preg_replace_callback($pattern, fn() => $replacement, $config, -1, $count);
			if ($count !== 1) {
				throw new \RuntimeException("Can't override $name: found $count definitions in config.php");
			}
		}
		file_put_contents("$this->dir/config.php", $config);
	}

	/** Provide the library like an installation with Composer would (vendor/autoload.php) */
	private function installSvgSanitizer(): void
	{
		$library = self::svgSanitizerDir() ?? throw new \RuntimeException('enshrined/svg-sanitize is not available');
		self::copyDir($library, "$this->dir/vendor/enshrined/svg-sanitize");
		file_put_contents("$this->dir/vendor/autoload.php", <<<'PHP'
<?php
spl_autoload_register(function ($class) {
	$prefix = 'enshrined\\svgSanitize\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/enshrined/svg-sanitize/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
PHP);
	}

	private function start(): void
	{
		if (isset($this->options['port'])) {
			$this->port = (int)$this->options['port'];
		} else {
			$socket = stream_socket_server('tcp://127.0.0.1:0');
			$this->port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
			fclose($socket);
		}

		$command = [
			PHP_BINARY, '-S', "127.0.0.1:$this->port",
			'-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=-1',
			'-d', 'opcache.enable=0', '-d', "session.save_path=$this->dir/sessions",
		];
		foreach ($this->options['phpIni'] ?? [] as $name => $value) {
			array_push($command, '-d', "$name=$value");
		}
		$this->process = proc_open(
			$command,
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']],
			$pipes,
			$this->dir,
			// git commands run by the app must not depend on the configuration of the test machine
			// (or find a repository around the temp folder)
			['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CEILING_DIRECTORIES' => sys_get_temp_dir()]
			+ getenv()
		);
		if (!is_resource($this->process)) {
			throw new \RuntimeException('Could not start PHP built-in web server');
		}
		for ($i = 0; $i < 100; $i++) {
			$connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
			if ($connection) {
				fclose($connection);
				return;
			}
			usleep(100000);
		}
		throw new \RuntimeException("PHP built-in web server did not start:\n" . @file_get_contents($this->logFile));
	}

	private static function copyDir(string $from, string $to): void
	{
		mkdir($to, 0777, true);
		foreach (scandir($from) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			is_dir("$from/$entry") ? self::copyDir("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
		}
	}

	/** @param string[] $keep paths of files to keep */
	private static function removeContents(string $dir, array $keep): void
	{
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = "$dir/$entry";
			if (is_link($path) || is_file($path)) {
				if (!in_array($path, $keep, true)) {
					unlink($path);
				}
			} else {
				self::removeContents($path, $keep);
				// keep folders that contain kept files
				@rmdir($path);
			}
		}
	}
}
