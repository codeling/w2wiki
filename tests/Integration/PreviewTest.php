<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\HttpClient;
use W2\Tests\Support\HttpResponse;
use W2\Tests\Support\MaliciousMarkdown;

/**
 * The preview of the editor (api.php?task=preview): the text rendered like the page, without storing anything.
 * The pages folder is a git repository (with commits enabled), to see that the preview doesn't change it, and requests
 * are limited to 100K, to see the answer to a text which is too large.
 */
final class PreviewTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['git' => true, 'phpIni' => ['post_max_size' => '100K']];
	}

	/** Sends the text for the preview, with the token of the session (or the given one) */
	private function preview(string $text, ?string $token = null, ?HttpClient $client = null): HttpResponse
	{
		$client ??= $this->http;
		return $client->post('/api.php', ['csrf_token' => $token ?? $this->csrfToken($client), 'newText' => $text], ['task' => 'preview']);
	}

	/** The HTML of the preview (the request must succeed) */
	private function previewHtml(string $text): string
	{
		$response = $this->preview($text);
		$this->assertSame(200, $response->status, $response->body);
		$this->assertStringStartsWith('application/json', (string)$response->header('content-type'));
		$answer = json_decode($response->body, true);
		$this->assertIsArray($answer);
		$this->assertIsString($answer['html'] ?? null);
		return $answer['html'];
	}

	/** @return array<string, mixed> the decoded answer of a request which failed with the status */
	private function assertRefused(int $status, string $error, HttpResponse $response): array
	{
		$this->assertSame($status, $response->status, $response->body);
		$this->assertStringStartsWith('application/json', (string)$response->header('content-type'));
		$answer = json_decode($response->body, true);
		$this->assertIsArray($answer);
		$this->assertSame($error, $answer['error'] ?? null);
		$this->assertIsString($answer['message'] ?? null);
		$this->assertNotSame('', $answer['message']);
		$this->assertArrayNotHasKey('html', $answer);
		return $answer;
	}

	public function testThePreviewIsTheHtmlOfThePage(): void
	{
		file_put_contents($this->imageFile('cat.gif'), self::gif());
		$this->savePage('Embedded part', "Text of the *embedded* page.");
		$text = "# A heading\n\nLinks to [[Home]], [[Home#Section|a section]] and [[Nowhere]].\n\n![[Embedded part]]\n\n"
			. "![A cat](/images/cat.gif) and [the file](/images/cat.gif)\n\n## Second heading\n\n```\n[[Not a link]]\n```\n";
		$this->savePage('Previewed page', $text);
		// (the first view shows the note about saving)
		$this->http->get($this->pageUrl('Previewed page'));
		$view = $this->http->get($this->pageUrl('Previewed page'))->body;

		$html = $this->previewHtml($text);
		$this->assertStringContainsString("<div class=\"main\">\n\n" . $html . "\n    </div>", $view, 'the preview is the same as the view of the page');
	}

	public function testPageLinksAndMissingPagesAreMarked(): void
	{
		$html = $this->previewHtml('See [[Home]] and [[Nowhere|elsewhere]].');
		$this->assertStringContainsString('<a href="/index.php/Home">Home</a>', $html);
		$this->assertStringContainsString('<a href="/index.php/Nowhere" class="noexist">elsewhere</a>', $html);
	}

	public function testHeadingsHaveTheirAnchors(): void
	{
		$html = $this->previewHtml("# My heading\n\n## Second one");
		$this->assertStringContainsString('<h1><a id="My-heading">My heading</a></h1>', $html);
		$this->assertStringContainsString('<h2><a id="Second-one">Second one</a></h2>', $html);
	}

	public function testEmbedsShowTheSavedPagesWithTheLimitsOfEmbeds(): void
	{
		$this->savePage('Part A', "Part A text\n\n![[Part B]]");
		$this->savePage('Part B', "Part B text\n\n![[Part A]]");
		$html = $this->previewHtml("![[Part A]]\n\n![[Missing part]]");
		$this->assertStringContainsString('Part A text', $html);
		$this->assertStringContainsString('Part B text', $html);
		$this->assertStringContainsString('[include failed (cycle): <code>Part A</code>]', $html);
		$this->assertStringContainsString('[include failed (not found): <code>Missing part</code>]', $html);
	}

	public function testUploadsAreServedByFilePhp(): void
	{
		file_put_contents($this->imageFile('dog.gif'), self::gif());
		$html = $this->previewHtml('![A dog](/images/dog.gif)');
		$this->assertMatchesRegularExpression('#<img src="/file\.php\?name=dog\.gif&amp;v=\d+" alt="A dog" />#', $html);
	}

	public function testEmptyTextGivesAnEmptyPreview(): void
	{
		$this->assertSame('', $this->previewHtml(''));
	}

	public static function maliciousMarkdown(): array
	{
		return MaliciousMarkdown::all();
	}

	#[DataProvider('maliciousMarkdown')]
	public function testScriptsInTheTextAreEscaped(string $markdown): void
	{
		$this->assertNoActiveContent('<!doctype html><html><body>' . $this->previewHtml($markdown) . '</body></html>');
	}

	public function testRawHtmlIsShownAsText(): void
	{
		$html = $this->previewHtml('<script>alert(1)</script> <b onclick="x()">bold</b>');
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringNotContainsString('<b', $html);
		$this->assertStringContainsString('&lt;script>alert(1)&lt;/script> &lt;b onclick="x()">bold&lt;/b>', $html);
	}

	public function testTextWhichIsNotUtf8StillGivesAnAnswer(): void
	{
		$html = $this->previewHtml("Broken \xC3\x28 text");
		$this->assertStringContainsString('Broken', $html);
	}

	public function testNothingIsStoredOrCommitted(): void
	{
		file_put_contents($this->imageFile('kept.gif'), self::gif());
		$pagesBefore = $this->server->pageFiles();
		$homeBefore = $this->pageText('Home');
		$commitsBefore = $this->server->git('rev-list --count HEAD');
		$uploadsBefore = $this->uploadedFiles();

		$this->previewHtml("# New text\n\n[[Not yet a page]] ![[Home]] ![x](/images/kept.gif)");
		$this->previewHtml('');

		$this->assertSame($pagesBefore, $this->server->pageFiles());
		$this->assertSame($homeBefore, $this->pageText('Home'));
		$this->assertSame($uploadsBefore, $this->uploadedFiles());
		$this->assertSame($commitsBefore, $this->server->git('rev-list --count HEAD'));
		// (only the upload which the test put there is new)
		$this->assertSame('?? images/kept.gif', $this->server->git('status --porcelain'));
	}

	public function testTheSessionIsNotChanged(): void
	{
		$token = $this->csrfToken();
		$this->savePage('Saved before', 'x');
		// (with the token from before: getting one would show the note)
		$this->assertSame(200, $this->preview('text', $token)->status);
		// (the note about saving is still there, and so is the token)
		$this->assertStringContainsString('Created', $this->http->get($this->pageUrl('Saved before'))->body);
		$this->assertSame($token, $this->csrfToken());
	}

	public function testOnlyPostRequestsAreAnswered(): void
	{
		$response = $this->http->get('/api.php', ['task' => 'preview', 'newText' => '# x', 'csrf_token' => $this->csrfToken()]);
		$this->assertRefused(405, 'method not allowed', $response);
		$this->assertSame('POST', $response->header('allow'));
	}

	public function testTheTokenIsRequired(): void
	{
		$this->assertRefused(403, 'invalid token', $this->http->post('/api.php', ['newText' => '# x'], ['task' => 'preview']));
		$this->assertRefused(403, 'invalid token', $this->preview('# x', 'wrong'));
		// (the token of another session)
		$this->assertRefused(403, 'invalid token', $this->preview('# x', $this->csrfToken($this->newClient())));
		// (the session has expired: a new one, with a new token)
		$token = $this->csrfToken();
		$answer = $this->assertRefused(403, 'invalid token', $this->preview('# x', $token, $this->newClient()));
		$this->assertStringContainsString('session has expired', $answer['message']);
	}

	public static function malformedRequests(): array
	{
		return [
			'text array' => [['newText' => ['a']]],
			'null byte in text' => [['newText' => "a\0b"]],
			'token array' => [['csrf_token' => ['a'], 'newText' => 'a']],
		];
	}

	/** @param array<string, mixed> $fields */
	#[DataProvider('malformedRequests')]
	public function testMalformedRequestsAreRefused(array $fields): void
	{
		$fields += ['csrf_token' => $this->csrfToken()];
		// (http_build_query() makes "newText[0]=a" of an array)
		$response = $this->http->post('/api.php', $fields, ['task' => 'preview']);
		$this->assertRefused(400, 'invalid request', $response);
	}

	public function testTextLargerThanPostMaxSizeIsRefusedWithTheLimit(): void
	{
		$this->allowPhpErrors = true; // PHP logs "POST Content-Length exceeds the limit"
		$answer = $this->assertRefused(413, 'too large', $this->preview(str_repeat('a', 300 * 1024)));
		$this->assertStringContainsString('100K (post_max_size)', $answer['message']);
	}

	public function testTextBelowTheLimitIsRendered(): void
	{
		$this->assertStringContainsString(str_repeat('b', 1000), $this->previewHtml(str_repeat('b', 50 * 1024)));
	}

	public function testTheEditorsHaveThePreviewForTheScript(): void
	{
		foreach ([['action' => 'new'], ['action' => 'edit', 'page' => 'Home'], ['action' => 'edit', 'page' => 'Not there']] as $query) {
			$body = $this->http->get('/index.php', $query)->body;
			$this->assertStringContainsString('<div id="preview" class="preview" role="region" aria-label="Preview" hidden="hidden" data-url="/api.php?task=preview"', $body);
			$this->assertStringContainsString('<input id="previewbutton" type="button" value="Preview"', $body);
			$this->assertMatchesRegularExpression('/<input id="previewbutton"[^>]* aria-pressed="false" aria-controls="preview" aria-keyshortcuts="Control\+Alt\+P Meta\+Alt\+P" hidden="hidden" \/>/', $body);
			// (inside the box of the text, which is side by side with it)
			$this->assertMatchesRegularExpression('/<div class="editorbox"><textarea id="text".*?<\/textarea>.*?<div id="preview"/s', $body);
			$this->assertNoActiveContent($body);
		}
	}

	public function testTheFormattingHelpMentionsThePreview(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'new'])->body;
		$this->assertStringContainsString('The Preview button below the text shows how the page will look', $body);
	}
}
