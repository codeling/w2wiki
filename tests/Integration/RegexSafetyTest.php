<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** User input must never be interpreted as a regular expression */
final class RegexSafetyTest extends AppTestCase
{
	public function testSearchFindsTextWithRegexCharacters(): void
	{
		$this->savePage('Meta', 'uses foo(bar and a+b');
		foreach (['foo(bar', '(', 'a+b', '[', '\\'] as $query) {
			$body = $this->http->get('/index.php', ['action' => 'search', 'q' => $query])->body;
			$matches = $query === '[' || $query === '\\' ? 0 : 1;
			if ($matches) {
				$this->assertStringContainsString('href="/index.php/Meta"', $body, "query $query");
			} else {
				$this->assertStringNotContainsString('href="/index.php/Meta"', $body, "query $query");
			}
			$this->assertMatchesRegularExpression('/<p>\d+ matches<\/p>/', $body, "query $query");
		}
	}

	public function testSearchDoesNotUseRegularExpressions(): void
	{
		$this->savePage('Aaa', str_repeat('a', 40));
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => 'a.a'])->body;
		$this->assertStringNotContainsString('href="/index.php/Aaa"', $body);
	}

	public function testCatastrophicPatternReturnsQuickly(): void
	{
		$this->savePage('Aaa', str_repeat('a', 60) . '!');
		$response = $this->http->get('/index.php', ['action' => 'search', 'q' => '(a+)+$']);
		$this->assertSame(200, $response->status);
		$this->assertLessThan(2.0, $response->seconds);
		$this->assertStringContainsString('0 matches', $response->body);
	}

	public function testSearchIsCaseInsensitiveSubstringSearch(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => 'wELCOME'])->body;
		$this->assertStringContainsString('href="/index.php/Home"', $body);
	}

	public function testDeletingPageWithDeliberatelyOverlappingLinksKeepsOtherLinks(): void
	{
		$this->savePage('A', 'text');
		$this->savePage('Linker', '[[A|x]] and [[B|y]]');
		$this->postAction('deleted', ['oldPageName' => 'A']);
		$this->assertSame(' and [[B|y]]', $this->pageText('Linker'));
	}

	public function testImageReferencesAreUpdatedLiterallyOnRename(): void
	{
		$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
		$this->upload('a(1).gif', $gif);
		$this->savePage('Gallery', '![x](/images/a(1).gif) ![y](/images/aa.gif)');
		$this->renameImage('a(1).gif', 'c$1.gif');
		$this->assertSame('![x](/images/c$1.gif) ![y](/images/aa.gif)', $this->pageText('Gallery'));
		$this->assertFileExists($this->imageFile('c$1.gif'));
	}

	public function testImageReferencesAreRemovedLiterallyOnDelete(): void
	{
		$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
		$this->upload('a.b.gif', $gif);
		$this->savePage('Gallery', '![x](/images/a.b.gif) ![y](/images/aXb.gif)');
		$this->deleteImage('a.b.gif');
		$this->assertSame(' ![y](/images/aXb.gif)', $this->pageText('Gallery'));
	}
}
