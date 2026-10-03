<?php

namespace W2\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Tests\Support\CurlFileLike;
use W2\Tests\Support\HttpClient;
use W2\Tests\Support\HttpResponse;

/**
 * Tests the protection provided by the web server configuration (.htaccess files for Apache, the
 * rules in INSTALL.md for nginx) against a wiki served by a real web server. Run with
 * tests/Server/run.sh, which sets the environment variables W2_SERVER_URL (e.g. http://127.0.0.1:8080)
 * and W2_SERVER_ROOT (the folder with the wiki, which is writable for the tests).
 */
final class ServerConfigTest extends TestCase
{
	private string $root;
	private HttpClient $http;
	/** @var string[] files created by a test */
	private array $created = [];

	protected function setUp(): void
	{
		$url = getenv('W2_SERVER_URL');
		$root = getenv('W2_SERVER_ROOT');
		if (!$url || !$root) {
			$this->markTestSkipped('W2_SERVER_URL and W2_SERVER_ROOT are not set, see tests/Server/run.sh');
		}
		$this->root = rtrim($root, '/');
		$this->http = new HttpClient($url);
	}

	protected function tearDown(): void
	{
		foreach (array_reverse($this->created) as $path) {
			is_dir($path) ? @rmdir($path) : @unlink($path);
		}
		if (isset($this->root)) {
			foreach (glob($this->root . '/pages/images/*') ?: [] as $file) {
				@unlink($file);
			}
		}
	}

	/** Create a file (and folders) in the served wiki folder */
	private function place(string $relativePath, string $content): void
	{
		$path = "$this->root/$relativePath";
		$missing = [];
		for ($dir = dirname($path); !is_dir($dir); $dir = dirname($dir)) {
			array_unshift($missing, $dir);
		}
		foreach ($missing as $dir) {
			mkdir($dir, 0777);
			chmod($dir, 0777);
			$this->created[] = $dir;
		}
		file_put_contents($path, $content);
		chmod($path, 0666);
		$this->created[] = $path;
	}

	private function assertNotServed(HttpResponse $response, string $secret = ''): void
	{
		$this->assertContains($response->status, [403, 404], 'status ' . $response->status);
		if ($secret !== '') {
			$this->assertStringNotContainsString($secret, $response->body);
		}
	}

	// --- application ---------------------------------------------------------------

	public function testTheWikiWorks(): void
	{
		$response = $this->http->get('/index.php');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Welcome to W2', $response->body);
		$this->assertStringContainsString('Welcome to W2', $this->http->get('/index.php/Home')->body, 'PATH_INFO');
		$this->assertStringContainsString('Welcome to W2', $this->http->get('/index.php', ['action' => 'view', 'page' => 'Home'])->body);
	}

	/** With VIEW set (for servers without PATH_INFO), the links to pages are query URLs which work */
	public function testPageLinksWithViewSetting(): void
	{
		$config = "$this->root/config.php";
		$original = file_get_contents($config);
		$changed = str_replace("define('VIEW', '');", "define('VIEW', '?action=view&page=');", $original, $count);
		$this->assertSame(1, $count, 'VIEW not found in config.php');
		$this->place('pages/Some Page.md', 'Text of some page');
		file_put_contents($config, $changed);
		sleep(3); // (PHP's opcache may re-check config.php only every 2 seconds)
		try {
			$html = $this->http->get('/index.php', ['action' => 'all'])->body;
			$this->assertStringNotContainsString('page=/', $html);
			$this->assertStringContainsString('href="/index.php?action=view&amp;page=Some%20Page"', $html);
			$this->assertStringContainsString('Text of some page', $this->http->get('/index.php', ['action' => 'view', 'page' => 'Some Page'])->body);
			$this->assertStringContainsString('Text of some page', $this->http->get('/index.php?action=view&page=Some%20Page')->body);
		} finally {
			file_put_contents($config, $original);
			sleep(3);
		}
	}

	#[DataProvider('publicFiles')]
	public function testStaticFilesOfTheWikiAreServed(string $path): void
	{
		$this->assertSame(200, $this->http->get($path)->status);
	}

	public static function publicFiles(): array
	{
		return [['/index.css'], ['/wiki.js'], ['/w2-icons/home.svg'], ['/w2-icons/w2-icon.png']];
	}

	#[DataProvider('publicFiles')]
	public function testStaticFilesAreCachedLong(string $path): void
	{
		$this->assertSame('max-age=31536000, immutable', $this->http->get($path)->header('cache-control'));
	}

	public function testUploadsAreCachedLong(): void
	{
		$this->place('pages/images/a.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
		$this->place('pages/images/s.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
		$this->place('pages/images/d.pdf', '%PDF-1.0');
		foreach (['a.gif', 's.svg', 'd.pdf'] as $name) {
			$this->assertSame('max-age=31536000, immutable', $this->http->get("/images/$name")->header('cache-control'), $name);
		}
	}

	public function testPagesAreNotCached(): void
	{
		$this->assertStringContainsString('no-store', (string)$this->http->get('/index.php')->header('cache-control'));
	}

	#[DataProvider('sourceFiles')]
	public function testSourceCodeIsNotDisclosed(string $path): void
	{
		$response = $this->http->get($path);
		$this->assertStringNotContainsString('W2_PASSWORD', $response->body);
		$this->assertStringNotContainsString('<?php', $response->body);
		$this->assertStringNotContainsString('function ', $response->body);
	}

	public static function sourceFiles(): array
	{
		return [['/config.php'], ['/auth.php'], ['/auth_functions.php'], ['/functions.php'], ['/locales/en.php']];
	}

	// --- pages and hidden files ----------------------------------------------------------

	#[DataProvider('pageFiles')]
	public function testPageSourcesCannotBeFetchedDirectly(string $path): void
	{
		$this->assertNotServed($this->http->get($path), 'Welcome to W2');
	}

	public static function pageFiles(): array
	{
		return [
			['/pages/Home.md'], ['/pages/_sidebar.md'], ['/pages/MarkdownSyntax.md'], ['/pages/'], ['/pages/.htaccess'],
			['/pages/images/'], ['/pages/images/.htaccess'],
		];
	}

	/** The uploads are public, but they must have the same protection when fetched via the pages folder */
	public function testUploadsInThePagesFolderHaveTheSameProtection(): void
	{
		$this->place('pages/images/a.gif', 'GIF89a');
		$response = $this->http->get('/pages/images/a.gif');
		$this->assertContains($response->status, [200, 403, 404]);
		if ($response->status === 200) {
			$this->assertSame('nosniff', strtolower((string)$response->header('x-content-type-options')));
		}
	}

	#[DataProvider('folders')]
	public function testDirectoryListingsAreNotShown(string $path): void
	{
		$this->place('pages/images/listed-file.gif', 'GIF89a');
		$response = $this->http->get($path);
		$this->assertStringNotContainsString('listed-file.gif', $response->body);
		$this->assertStringNotContainsString('Index of', $response->body);
	}

	public static function folders(): array
	{
		return [['/images/'], ['/pages/images/'], ['/Michelf/'], ['/locales/'], ['/w2-icons/']];
	}

	public function testHiddenFilesAndFoldersAreNotServed(): void
	{
		$this->place('.git/config', "[core]\n\trepositoryformatversion = 0\n");
		$this->place('.git/HEAD', 'ref: refs/heads/main');
		$this->place('pages/.git/config', "[core]\n\trepositoryformatversion = 0\n");
		$this->assertNotServed($this->http->get('/.git/config'), 'repositoryformatversion');
		$this->assertNotServed($this->http->get('/.git/HEAD'), 'refs/heads');
		$this->assertNotServed($this->http->get('/pages/.git/config'), 'repositoryformatversion');
		$this->assertNotServed($this->http->get('/.htaccess'));
	}

	public function testComposerLibrariesAreNotServed(): void
	{
		$this->place('vendor/autoload.php', '<?php echo "vendor-code-executed";');
		$this->place('vendor/composer/installed.json', '{"packages": []}');
		$this->assertNotServed($this->http->get('/vendor/autoload.php'), 'vendor-code-executed');
		$this->assertNotServed($this->http->get('/vendor/composer/installed.json'), 'packages');
	}

	// --- uploads ---------------------------------------------------------------------

	public static function scriptNames(): array
	{
		return [
			'php' => ['x.php'], 'upper case php' => ['x.PHP'], 'php5' => ['x.php5'], 'php8' => ['x.php8'], 'phtml' => ['x.phtml'],
			'phar' => ['x.phar'], 'pht' => ['x.pht'], 'php then image extension' => ['x.php.gif'], 'php then unknown extension' => ['x.php.xyz'],
			'image extension then php' => ['x.gif.php'],
		];
	}

	#[DataProvider('scriptNames')]
	public function testScriptsInTheUploadsFolderAreNeverExecuted(string $name): void
	{
		$this->place("pages/images/$name", '<?php echo 6 * 7, "-executed";');
		$this->assertStringNotContainsString('42-executed', $this->http->get("/images/$name")->body, 'through the images link');
		$this->assertStringNotContainsString('42-executed', $this->http->get("/pages/images/$name")->body, 'in the pages folder');
	}

	public function testUploadedFilesAreServedWithProtectiveHeaders(): void
	{
		$this->place('pages/images/a.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
		$response = $this->http->get('/images/a.gif');
		$this->assertSame(200, $response->status);
		$this->assertSame('nosniff', strtolower((string)$response->header('x-content-type-options')));
		$this->assertStringContainsString('image/gif', (string)$response->header('content-type'));
	}

	public function testSvgFilesAreSandboxed(): void
	{
		$this->place('pages/images/s.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
		$response = $this->http->get('/images/s.svg');
		$this->assertSame(200, $response->status);
		$this->assertMatchesRegularExpression('/(^|[ ;])sandbox($|[ ;])/', (string)$response->header('content-security-policy'));
		$this->assertStringContainsString("default-src 'none'", (string)$response->header('content-security-policy'));
		$this->assertSame('nosniff', strtolower((string)$response->header('x-content-type-options')));
	}

	public function testUploadingThroughTheWikiWorksEndToEnd(): void
	{
		$page = $this->http->get('/index.php', ['action' => 'upload']);
		$this->assertSame(200, $page->status);
		preg_match('/name="csrf_token" value="([^"]+)"/', $page->body, $matches);
		$gif = new CurlFileLike(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'e2e.gif', 'image/gif');
		$response = $this->http->post('/index.php', ['action' => 'uploaded', 'csrf_token' => $matches[1], 'prevpage' => 'Home', 'userfile' => $gif]);
		$this->assertSame(303, $response->status, $response->body);
		$this->created[] = "$this->root/pages/images/e2e.gif";

		$image = $this->http->get('/images/e2e.gif');
		$this->assertSame(200, $image->status);
		$this->assertStringContainsString('image/gif', (string)$image->header('content-type'));

		// the file is listed, and can be deleted again
		$this->assertStringContainsString('e2e.gif', $this->http->get('/index.php', ['action' => 'upload'])->body);
		$response = $this->http->post('/index.php', ['action' => 'imgDeleted', 'csrf_token' => $matches[1], 'oldPageName' => 'e2e.gif', 'prevpage' => 'Home']);
		$this->assertSame(303, $response->status);
		$this->assertSame(404, $this->http->get('/images/e2e.gif')->status);
	}

	public function testUploadedScriptsAreRefusedByTheWiki(): void
	{
		$page = $this->http->get('/index.php', ['action' => 'upload']);
		preg_match('/name="csrf_token" value="([^"]+)"/', $page->body, $matches);
		$script = new CurlFileLike("GIF89a\x01\x00\x01\x00\x00\x00\x00;<?php echo 6 * 7, '-executed'; ?>", 'shell.php', 'image/gif');
		$this->http->post('/index.php', ['action' => 'uploaded', 'csrf_token' => $matches[1], 'prevpage' => 'Home', 'userfile' => $script]);
		$this->assertFileDoesNotExist("$this->root/pages/images/shell.php");
		$this->assertStringNotContainsString('42-executed', $this->http->get('/images/shell.php')->body);
	}
}
