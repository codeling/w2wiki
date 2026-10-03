<?php

namespace W2\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that talk to a running wiki. Every test starts with
 * the initial pages and no uploaded images, and a new browser session.
 */
abstract class AppTestCase extends TestCase
{
	use HtmlAssertions;

	protected AppServer $server;
	protected HttpClient $http;
	/**
	 * Set to true in tests which are expected to make PHP log warnings. (The
	 * environment variable W2_IGNORE_PHP_LOG disables the check for all tests,
	 * e.g. to run the suite against old versions, see W2_APP_ROOT.)
	 */
	protected bool $allowPhpErrors = false;
	private int $logOffset = 0;

	/** @return array<string, mixed> config.php values to override, see AppServer::get() */
	protected function configOverrides(): array
	{
		return [];
	}

	/** @return array<string, mixed> options for the test server, see AppServer::get() */
	protected function serverOptions(): array
	{
		return [];
	}

	protected function setUp(): void
	{
		if (!empty($this->serverOptions()['svgSanitizer']) && AppServer::svgSanitizerDir() === null) {
			$this->markTestSkipped('enshrined/svg-sanitize is not installed (run composer install)');
		}
		$this->server = AppServer::get($this->configOverrides(), $this->serverOptions());
		$this->server->reset();
		$this->http = $this->newClient();
		$this->logOffset = $this->server->logSize();
	}

	protected function tearDown(): void
	{
		if (!$this->allowPhpErrors && !getenv('W2_IGNORE_PHP_LOG')) {
			$this->assertDoesNotMatchRegularExpression(
				'/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/',
				$this->server->logSince($this->logOffset),
				'The wiki logged PHP errors while running this test'
			);
		}
	}

	protected function newClient(): HttpClient
	{
		return new HttpClient($this->server->baseUrl());
	}

	// --- requests -----------------------------------------------------

	protected function pageUrl(string $page): string
	{
		return '/index.php/' . rawurlencode($page);
	}

	/**
	 * CSRF token of the (new or existing) session of the given client; empty
	 * if the wiki doesn't have any, which lets the other tests also run
	 * against versions without CSRF protection (see W2_APP_ROOT)
	 */
	protected function csrfToken(?HttpClient $client = null): string
	{
		$response = ($client ?? $this->http)->get('/index.php', ['action' => 'new']);
		return preg_match('/name="csrf_token" value="([^"]+)"/', $response->body, $matches) ? html_entity_decode($matches[1]) : '';
	}

	/** POST a form to index.php including a valid CSRF token */
	protected function postAction(string $action, array $fields = [], ?HttpClient $client = null): HttpResponse
	{
		$client ??= $this->http;
		return $client->post('/index.php', ['action' => $action, 'csrf_token' => $this->csrfToken($client)] + $fields);
	}

	/** Submit the login form */
	protected function login(string $password, ?HttpClient $client = null): HttpResponse
	{
		return ($client ?? $this->http)->post('/index.php', ['p' => $password]);
	}

	protected static function gif(): string
	{
		return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
	}

	protected static function png(): string
	{
		return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
	}

	protected function savePage(string $name, string $text, bool $isNew = true): HttpResponse
	{
		return $this->postAction('save', [
			'page' => $name, 'isNew' => $isNew ? 'true' : '', 'newText' => $text, 'gitmsg' => '',
		]);
	}

	protected function upload(string $fileName, string $content, string $mimeType = 'image/gif', array $extra = []): HttpResponse
	{
		return $this->postAction('uploaded', [
			'userfile' => new CurlFileLike($content, $fileName, $mimeType),
			'prevpage' => 'Home',
		] + $extra);
	}

	protected function renameImage(string $old, string $new): HttpResponse
	{
		return $this->postAction('imgRenamed', ['oldPageName' => $old, 'newName' => $new, 'prevpage' => 'Home']);
	}

	protected function deleteImage(string $name): HttpResponse
	{
		return $this->postAction('imgDeleted', ['oldPageName' => $name, 'prevpage' => 'Home']);
	}

	/** The message shown after a redirect (fetches the redirect target) */
	protected function noteAfter(HttpResponse $response): string
	{
		$this->assertSame(303, $response->status, 'expected a redirect');
		return $this->noteOf($this->http->follow($response));
	}

	/** The message shown directly in the response (e.g. by the editor after saving failed) */
	protected function noteInResponse(HttpResponse $response): string
	{
		$this->assertSame(200, $response->status, 'expected the page with the message, not a redirect');
		return $this->noteOf($response);
	}

	private function noteOf(HttpResponse $page): string
	{
		if (!preg_match('/<div class="note">(.*?)<\/div>/s', $page->body, $matches)) {
			return '';
		}
		return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES));
	}

	// --- files ---------------------------------------------------------

	protected function pageFile(string $page): string
	{
		return $this->server->pagesDir() . '/' . $page . '.md';
	}

	protected function pageText(string $page): string
	{
		$this->assertFileExists($this->pageFile($page));
		return (string)file_get_contents($this->pageFile($page));
	}

	protected function imageFile(string $name): string
	{
		return $this->server->imagesDir() . '/' . $name;
	}

	/** @return string[] uploaded files (not counting the .htaccess file) */
	protected function uploadedFiles(): array
	{
		return array_values(array_diff(scandir($this->server->imagesDir()), ['.', '..', '.htaccess']));
	}
}
