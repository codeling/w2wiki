<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EscapingAndUrlsTest extends TestCase
{
	public static function escapedStrings(): array
	{
		return [
			'text' => ['plain text', 'plain text'],
			'tags' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
			'quotes' => ['"double" and \'single\'', '&quot;double&quot; and &#039;single&#039;'],
			'ampersand' => ['a & b &amp; c', 'a &amp; b &amp;amp; c'],
			'empty' => ['', ''],
			'null' => [null, ''],
			'umlauts' => ['Grüße', 'Grüße'],
		];
	}

	#[DataProvider('escapedStrings')]
	public function testEscapingForHtml(?string $input, string $expected): void
	{
		$this->assertSame($expected, h($input));
	}

	public function testInvalidUtf8IsSubstitutedInsteadOfDroppingTheWholeString(): void
	{
		$this->assertSame("a\u{FFFD}b", h("a\xFFb"));
	}

	public function testTranslationsAreEscapedAndFallBackToTheLabel(): void
	{
		$this->assertSame('Save', __('Save'));
		$this->assertSame('&lt;b&gt;unknown&lt;/b&gt;', __('<b>unknown</b>'));
		$this->assertSame('&quot;alt&quot;', __('<b>unknown</b>', '"alt"'));
	}

	public static function pageUrls(): array
	{
		return [
			'simple' => ['Home', '/index.php/Home'],
			'space' => ['A B', '/index.php/A%20B'],
			'plus' => ['A+B', '/index.php/A%2BB'],
			'percent' => ['100%', '/index.php/100%25'],
			'subfolder' => ['folder/Page', '/index.php/folder/Page'],
			'anchor' => ['Page#section', '/index.php/Page#section'],
			'umlaut' => ['Übung', '/index.php/%C3%9Cbung'],
			'characters replaced in file names' => ['a&b:c', '/index.php/a-b-c'],
			'parent folder' => ['../x', '/index.php/-/x'],
			'quotes' => ['"><script>', '/index.php/%22%3E%3Cscript%3E'],
		];
	}

	#[DataProvider('pageUrls')]
	public function testPageUrls(string $page, string $expected): void
	{
		$this->assertSame($expected, pageURL($page));
	}

	public function testPageLinks(): void
	{
		$this->assertSame('<a href="/index.php/A%20B">Title</a>', pageLink('A B', 'Title'));
		$this->assertSame('<a href="/index.php/A" class="x">T</a>', pageLink('A', 'T', ' class="x"'));
	}

	public static function urls(): array
	{
		return [
			'http' => ['http://example.com/?a=1&b=2', 'http://example.com/?a=1&b=2'],
			'https' => ['https://example.com', 'https://example.com'],
			'ftp' => ['ftp://example.com/file', 'ftp://example.com/file'],
			'mailto' => ['mailto:me@example.com', 'mailto:me@example.com'],
			'tel' => ['tel:+123456', 'tel:+123456'],
			'upper case scheme' => ['HTTPS://EXAMPLE.COM', 'HTTPS://EXAMPLE.COM'],
			'absolute path' => ['/foo/bar', '/foo/bar'],
			'relative path' => ['foo/bar.html', 'foo/bar.html'],
			'anchor' => ['#section', '#section'],
			'query only' => ['?a=b', '?a=b'],
			'protocol relative' => ['//example.com/x', '//example.com/x'],
			'empty' => ['', ''],
			'colon later in a relative path' => ['./a:b', './a:b'],
			'javascript' => ['javascript:alert(1)', '#'],
			'javascript upper case' => ['JAVASCRIPT:alert(1)', '#'],
			'javascript mixed case' => ['JaVaScRiPt:alert(1)', '#'],
			'javascript with tab' => ["java\tscript:alert(1)", '#'],
			'javascript with newline' => ["java\nscript:alert(1)", '#'],
			'javascript with leading space' => [" javascript:alert(1)", '#'],
			'javascript with control character' => ["\x01javascript:alert(1)", '#'],
			'javascript as numeric entity' => ['&#106;avascript:alert(1)', '#'],
			'javascript as hex entity' => ['&#x6A;avascript:alert(1)', '#'],
			'javascript with colon entity' => ['javascript&colon;alert(1)', '#'],
			'data' => ['data:text/html,<script>alert(1)</script>', '#'],
			'vbscript' => ['vbscript:msgbox(1)', '#'],
			'file' => ['file:///etc/passwd', '#'],
			'unknown scheme' => ['view-source:http://example.com', '#'],
		];
	}

	#[DataProvider('urls')]
	public function testUrlsWithDangerousSchemesAreNeutralized(string $url, string $expected): void
	{
		$this->assertSame($expected, filterURL($url));
	}

	public static function htmlIds(): array
	{
		return [
			'spaces' => ['Two words', 'Two-words'],
			'tags' => ['<em>Emphasis</em> here', 'Emphasis-here'],
			'quotes' => ['a"b\'c', 'a&quot;b&#039;c'],
			'entities' => ['a &amp; b', 'a-&amp;-b'],
		];
	}

	#[DataProvider('htmlIds')]
	public function testHeadingAnchorsAreSafeAttributeValues(string $caption, string $expected): void
	{
		$this->assertSame($expected, toHTMLID($caption));
	}

	public static function fileSizes(): array
	{
		return [
			[0, '0B'], [1, '1B'], [999, '999B'], [1024, '1.00K'], [1536, '1.50K'],
			[10240, '10.00K'], [1048576, '1.00M'], [5 * 1048576, '5.00M'], [1073741824, '1.00G'],
		];
	}

	#[DataProvider('fileSizes')]
	public function testHumanFileSizes(int $bytes, string $expected): void
	{
		$this->assertSame($expected, humanFilesize($bytes));
	}

	public function testImageLinkText(): void
	{
		$this->assertSame('![Image Description](/images/a.gif)', imageLinkText('a.gif'));
	}
}
