<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\Tests\Support\HtmlAssertions;
use W2\Tests\Support\MaliciousMarkdown;

final class MarkdownToHtmlTest extends TestCase
{
	use HtmlAssertions;

	public static function maliciousMarkdown(): array
	{
		return MaliciousMarkdown::all();
	}

	#[DataProvider('maliciousMarkdown')]
	public function testMaliciousMarkdownProducesNoActiveContent(string $markdown): void
	{
		$this->assertNoActiveContent('<html><body>' . toHTML($markdown) . '</body></html>');
	}

	public function testBasicFormatting(): void
	{
		$html = toHTML("# Title\n\nText with *emphasis*, **bold** and `code`.\n\n- one\n- two\n\n> quote\n");
		foreach (['<h1><a id="Title">Title</a></h1>', '<em>emphasis</em>', '<strong>bold</strong>', '<code>code</code>', '<li>one</li>', '<blockquote>'] as $part) {
			$this->assertStringContainsString($part, $html);
		}
	}

	public function testRawHtmlIsEscaped(): void
	{
		$html = toHTML('<b>bold</b> and <script>x</script>');
		$this->assertStringNotContainsString('<b>', $html);
		$this->assertStringContainsString('&lt;b>bold&lt;/b>', $html);
	}

	public function testLinksToExistingAndMissingPages(): void
	{
		$html = toHTML('[[Home]] [[Missing Page]] [[Home#part|the start]] [[Home|other text]]');
		$this->assertStringContainsString('<a href="/index.php/Home">Home</a>', $html);
		$this->assertStringContainsString('<a href="/index.php/Missing%20Page" class="noexist">Missing Page</a>', $html);
		$this->assertStringContainsString('<a href="/index.php/Home#part">the start</a>', $html);
		$this->assertStringContainsString('<a href="/index.php/Home">other text</a>', $html);
	}

	public function testHeadingAnchorsHandleSpecialCharacters(): void
	{
		$html = toHTML('## Fish & "chips"');
		$this->assertStringContainsString('<a id="Fish-&amp;-&quot;chips&quot;">', $html);
	}

	public function testImageShorthand(): void
	{
		$this->assertStringContainsString('<img src="/images/a b.png" alt="a b.png" />', toHTML('{{a b.png}}'));
		$this->assertStringContainsString('<img src="/images/x&quot; onerror=&quot;y" alt="x&quot; onerror=&quot;y" />', toHTML('{{x" onerror="y}}'));
	}

	public function testMarkdownImagesAndSafeLinks(): void
	{
		$html = toHTML('![alt](/images/a.png "title") [link](https://example.com/?a=1&b=2) [rel](other.html)');
		$this->assertStringContainsString('<img src="/images/a.png" alt="alt" title="title" />', $html);
		$this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
		$this->assertStringContainsString('href="other.html"', $html);
	}
}
