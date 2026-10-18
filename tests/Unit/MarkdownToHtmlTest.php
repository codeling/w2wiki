<?php
// SPDX-License-Identifier: MIT

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

	public function testBracesAreNoLongerImageShorthand(): void
	{
		$this->assertStringNotContainsString('<img', toHTML('{{a.png}}'));
	}

	public function testMarkdownImagesAndSafeLinks(): void
	{
		$html = toHTML('![alt](/images/a.png "title") [link](https://example.com/?a=1&b=2) [rel](other.html)');
		$this->assertStringContainsString('<img src="/images/a.png" alt="alt" title="title" />', $html);
		$this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
		$this->assertStringContainsString('href="other.html"', $html);
	}

	public static function placesWithoutPageLinks(): array
	{
		return [
			'url of a link' => ['[a](http://x/[[Home]])', '<a href="http://x/[[Home]]">a</a>'],
			'title of a link' => ['[a](http://x "[[Home|text]]")', 'title="[[Home|text]]"'],
			'alt of an image' => ['![[[Home]]](/images/a.gif)', 'alt="[[Home]]"'],
			'inline code' => ['`[[Home]]`', '<code>[[Home]]</code>'],
			'code block' => ["    [[Home]]\n", "<pre><code>[[Home]]\n</code></pre>"],
			'fenced code block' => ["```\n[[Home|x]]\n```", "[[Home|x]]\n</code></pre>"],
		];
	}

	#[DataProvider('placesWithoutPageLinks')]
	public function testPageLinksAreOnlyMadeInText(string $markdown, string $expected): void
	{
		$html = toHTML($markdown);
		$this->assertStringContainsString($expected, $html);
		$this->assertStringNotContainsString('/index.php/Home', $html);
		// (the markup stays as the parser made it: no tag inside a tag)
		$this->assertDoesNotMatchRegularExpression('/<[^>]*<a /', $html);
	}

	public function testPageLinksWorkInTextAroundOtherMarkup(): void
	{
		$html = toHTML("See *[[Home]]* and [[Home|the *start*]], `[[Home]]`, [text](http://x) [[Missing]].");
		$this->assertStringContainsString('<em><a href="/index.php/Home">Home</a></em>', $html);
		$this->assertStringContainsString('<a href="/index.php/Home">the <em>start</em></a>', $html);
		$this->assertStringContainsString('<code>[[Home]]</code>', $html);
		$this->assertStringContainsString('<a href="/index.php/Missing" class="noexist">Missing</a>', $html);
		$this->assertStringContainsString('<a href="http://x">text</a>', $html);
	}

	public function testPlaceholdersCannotBeForgedByPageText(): void
	{
		$html = toHTML('@@W2' . str_repeat('0', 16) . '0@@ and @@W20@@ and [[Home]]');
		$this->assertStringContainsString('@@W2' . str_repeat('0', 16) . '0@@ and @@W20@@', $html);
		$this->assertStringContainsString('<a href="/index.php/Home">Home</a>', $html);
	}
}
