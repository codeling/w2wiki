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
	private const APP_FILES = ['index.php', 'api.php', 'auth.php', 'config.php', 'index.css', 'wiki.js'];
	private const APP_DIRS = ['Michelf', 'locales', 'icons', 'pages'];

	/** @var array<string, AppServer> running servers by configuration */
	private static array $instances = [];
	private static bool $shutdownRegistered = false;

	private string $appRoot;
	private string $dir;
	private string $logFile;
	private int $port;
	/** @var resource */
	private $process;

	/**
	 * Get the server for a configuration, starting it if necessary.
	 *
	 * @param array<string, mixed> $overrides values for constants defined in config.php (by name),
	 *                                        or for the variable $allowedIPs
	 */
	public static function get(array $overrides = []): self
	{
		ksort($overrides);
		$key = md5(serialize($overrides));
		if (!isset(self::$instances[$key])) {
			self::$instances[$key] = new self($overrides);
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

	/** @param array<string, mixed> $overrides */
	private function __construct(array $overrides)
	{
		$this->appRoot = rtrim(getenv('W2_APP_ROOT') ?: dirname(__DIR__, 2), '/');
		$this->dir = sys_get_temp_dir() . '/w2test-' . bin2hex(random_bytes(6));
		$this->logFile = $this->dir . '.log';
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
		if (!is_dir("$this->dir/pages/images")) {
			mkdir("$this->dir/pages/images", 0777, true);
		}
		// the uploads folder is served statically from the root folder, see README.md
		symlink('pages/images', "$this->dir/images");
		$this->writeConfig($overrides);
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
		return $this->dir . '/pages';
	}

	public function imagesDir(): string
	{
		return $this->dir . '/pages/images';
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
		self::removeContents($this->pagesDir(), $keep);
		self::removeContents($this->imagesDir(), $keep);
		if (!is_dir($this->imagesDir())) {
			mkdir($this->imagesDir(), 0777, true);
		}
		foreach (glob($this->appRoot . '/pages/*.md') as $page) {
			copy($page, $this->pagesDir() . '/' . basename($page));
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

	private function start(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);

		$command = [
			PHP_BINARY, '-S', "127.0.0.1:$this->port",
			'-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=-1',
			'-d', 'opcache.enable=0', '-d', "session.save_path=$this->dir/sessions",
		];
		$this->process = proc_open(
			$command,
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']],
			$pipes,
			$this->dir
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
