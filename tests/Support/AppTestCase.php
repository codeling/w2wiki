<?php

namespace W2\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that talk to a running wiki. Every test starts with
 * the initial pages and no uploaded images, and a new browser session.
 */
abstract class AppTestCase extends TestCase
{
	protected AppServer $server;
	protected HttpClient $http;
	/** set to true in tests which are expected to make PHP log warnings */
	protected bool $allowPhpErrors = false;
	private int $logOffset = 0;

	/** @return array<string, mixed> config.php values to override, see AppServer::get() */
	protected function configOverrides(): array
	{
		return [];
	}

	protected function setUp(): void
	{
		$this->server = AppServer::get($this->configOverrides());
		$this->server->reset();
		$this->http = $this->newClient();
		$this->logOffset = $this->server->logSize();
	}

	protected function tearDown(): void
	{
		if (!$this->allowPhpErrors) {
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

	/** CSRF token of the (new or existing) session of the given client */
	protected function csrfToken(?HttpClient $client = null): string
	{
		$response = ($client ?? $this->http)->get('/index.php', ['action' => 'new']);
		$this->assertMatchesRegularExpression('/name="csrf_token" value="([^"]+)"/', $response->body);
		preg_match('/name="csrf_token" value="([^"]+)"/', $response->body, $matches);
		return html_entity_decode($matches[1]);
	}

	/** POST a form to index.php including a valid CSRF token */
	protected function postAction(string $action, array $fields = [], ?HttpClient $client = null): HttpResponse
	{
		$client ??= $this->http;
		return $client->post('/index.php', ['action' => $action, 'csrf_token' => $this->csrfToken($client)] + $fields);
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
		$page = $this->http->follow($response);
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

	// --- HTML ------------------------------------------------------------

	protected function dom(string $html): \DOMDocument
	{
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML('<?xml encoding="UTF-8">' . $html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $document;
	}

	/** @return \DOMElement[] */
	protected function elements(string $html, string $tag): array
	{
		return iterator_to_array($this->dom($html)->getElementsByTagName($tag));
	}

	/**
	 * Assert that the HTML contains nothing a browser would run: no scripts
	 * (except wiki.js), no event handler attributes (except the wiki's own),
	 * no javascript:/data: URLs and no frames or plugins.
	 */
	protected function assertNoActiveContent(string $html, bool $allowInlineScripts = false): void
	{
		$ownHandlers = ['toggleDrawer(); return false;', 'history.go(-1);'];
		$urlAttributes = ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'background'];
		foreach ($this->dom($html)->getElementsByTagName('*') as $element) {
			$tag = strtolower($element->nodeName);
			if ($tag === 'script') {
				if ($element->hasAttribute('src')) {
					$this->assertSame('wiki.js', $element->getAttribute('src'), 'unexpected external script');
				} elseif (!$allowInlineScripts) {
					$this->fail('inline <script> found: ' . substr($element->textContent, 0, 80));
				}
			}
			$this->assertNotContains($tag, ['iframe', 'object', 'embed', 'applet', 'base'], "unexpected <$tag> element");
			foreach ($element->attributes as $attribute) {
				$name = strtolower($attribute->name);
				if (str_starts_with($name, 'on')) {
					$this->assertContains($attribute->value, $ownHandlers, "event handler attribute $name on <$tag>");
				}
				if (in_array($name, $urlAttributes, true)) {
					$url = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $attribute->value));
					$this->assertDoesNotMatchRegularExpression('/^(javascript|vbscript|data):/', $url, "dangerous URL in $name of <$tag>");
				}
			}
		}
	}
}
