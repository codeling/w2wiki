<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\MaliciousMarkdown;

/** Reflected and stored cross-site scripting */
final class XssTest extends AppTestCase
{
	private const SCRIPT = '<script>alert(1)</script>';

	// --- reflected -----------------------------------------------------------

	public function testSearchQueryIsEscaped(): void
	{
		$response = $this->http->get('/index.php', ['action' => 'search', 'q' => self::SCRIPT]);
		$this->assertStringNotContainsString(self::SCRIPT, $response->body);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
		$this->assertNoActiveContent($response->body);
	}

	public function testPageNameInUrlIsEscaped(): void
	{
		$response = $this->http->get($this->pageUrl('"><script>alert(1)</script>'));
		$this->assertStringNotContainsString(self::SCRIPT, $response->body);
		$this->assertNoActiveContent($response->body);
	}

	public function testPageNameInQueryIsEscapedInAllActionPages(): void
	{
		foreach (['view', 'edit', 'rename', 'delete', 'upload'] as $action) {
			$response = $this->http->get('/index.php', ['action' => $action, 'page' => '"><b onmouseover=alert(1)>x']);
			$this->assertNoActiveContent($response->body, $action === 'upload');
			$this->assertStringNotContainsString('<b onmouseover', $response->body, "action $action");
		}
	}

	public function testUnknownActionIsEscaped(): void
	{
		$response = $this->http->get('/index.php', ['action' => self::SCRIPT]);
		$this->assertStringNotContainsString(self::SCRIPT, $response->body);
	}

	public function testPreviousPageParameterIsNotReflected(): void
	{
		// only existing pages are accepted (see PreviousPageTest); the value must never be shown
		$response = $this->http->get('/index.php', ['action' => 'upload', 'page' => '"><b>x']);
		$this->assertSame(400, $response->status);
		$this->assertStringNotContainsString('<b>x', $response->body);
	}

	public function testPreviousPageIsEscapedInFormsEvenIfThePageNameIsUnusual(): void
	{
		$name = 'Say "hi" <b>';
		$this->savePage($name, 'text');
		$body = $this->http->get('/index.php', ['action' => 'upload', 'page' => $name])->body;
		$this->assertStringContainsString('name="prevpage" value="Say &quot;hi&quot; &lt;b&gt;"', $body);
		$this->assertNoActiveContent($body, true);
	}

	public function testImageNameIsEscapedInRenameAndDeleteForms(): void
	{
		foreach (['imgRename', 'imgDelete'] as $action) {
			$response = $this->http->get('/index.php', ['action' => $action, 'imgName' => '"><b>x', 'prevpage' => 'Home']);
			$this->assertSame(200, $response->status);
			$this->assertStringNotContainsString('<b>x', $response->body);
			$this->assertStringContainsString('&lt;b&gt;x', $response->body);
		}
	}

	public function testEditorShowsPageTextAsText(): void
	{
		$text = '</textarea>' . self::SCRIPT;
		$this->savePage('Editor', $text);
		$response = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Editor']);
		$this->assertNoActiveContent($response->body);
		$textarea = $this->elements($response->body, 'textarea')[0];
		$this->assertSame($text, $textarea->textContent);
	}

	// --- stored ----------------------------------------------------------------

	public static function maliciousMarkdown(): array
	{
		return MaliciousMarkdown::all();
	}

	#[DataProvider('maliciousMarkdown')]
	public function testStoredMarkdownDoesNotProduceActiveContent(string $markdown): void
	{
		$this->savePage('Stored', $markdown);
		$response = $this->http->get($this->pageUrl('Stored'));
		$this->assertSame(200, $response->status);
		$this->assertNoActiveContent($response->body);
	}

	public function testSafeLinksStillWork(): void
	{
		$this->savePage('Links', '[ok](http://example.com/?a=1&b=2) [rel](/foo) [mail](mailto:a@b.c) [[Home#x|Go home]] ![img](/images/a.png)');
		$html = $this->http->get($this->pageUrl('Links'))->body;
		$hrefs = array_map(fn($a) => $a->getAttribute('href'), $this->elements($html, 'a'));
		$this->assertContains('http://example.com/?a=1&b=2', $hrefs);
		$this->assertContains('/foo', $hrefs);
		$this->assertContains('/index.php/Home#x', $hrefs);
		$this->assertTrue(count(array_filter($hrefs, fn($h) => str_starts_with($h, 'mailto:'))) === 1);
		$sources = array_map(fn($i) => $i->getAttribute('src'), $this->elements($html, 'img'));
		$this->assertContains('/images/a.png', $sources);
	}

	public function testUploadedFileNameIsEscapedInUploadListAndMessage(): void
	{
		$name = '<img src=x onerror=alert(1)>.gif';
		$response = $this->upload($name, self::gif());
		$note = $this->http->follow($response)->body;
		$this->assertNoActiveContent($note);
		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertNoActiveContent($list, true);
		$this->assertStringContainsString('&lt;img', $list);
	}

	public function testPageNamesAreEscapedInListsAndSearch(): void
	{
		$name = 'x<b>y';
		$this->savePage($name, 'needle');
		foreach ([['action' => 'all'], ['action' => 'search', 'q' => 'needle']] as $query) {
			$body = $this->http->get('/index.php', $query)->body;
			$this->assertStringNotContainsString('x<b>y', $body, json_encode($query));
			$this->assertNoActiveContent($body);
		}
	}
}
