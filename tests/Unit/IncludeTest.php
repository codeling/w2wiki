<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use W2\Tests\Support\HtmlAssertions;

final class IncludeTest extends TestCase
{
	use HtmlAssertions;

	private const PREFIX = 'W2IncludeTest';
	private array $created = [];

	protected function tearDown(): void
	{
		foreach ($this->created as $file) {
			@unlink($file);
		}
	}

	private function page(string $name, string $text): void
	{
		$file = fileNameForPage(self::PREFIX . $name);
		file_put_contents($file, $text);
		$this->created[] = $file;
	}

	public function testEmbedsPageContent(): void
	{
		$this->page('Two', "### This is page 2\n\nlorem ipsum\n");
		$html = toHTML("## Page 1\n\nintro\n\n![[" . self::PREFIX . "Two]]\n\nafter\n");
		$this->assertStringContainsString('<h2><a id="Page-1">Page 1</a></h2>', $html);
		$this->assertStringContainsString('<h3><a id="This-is-page-2">This is page 2</a></h3>', $html);
		$this->assertStringContainsString('<p>lorem ipsum</p>', $html);
		$this->assertStringContainsString('<p>after</p>', $html);
		$this->assertStringNotContainsString('![[', $html);
	}

	public function testNestedEmbeds(): void
	{
		$this->page('B', "![[" . self::PREFIX . "C]]\n");
		$this->page('C', "deepest\n");
		$this->assertStringContainsString('deepest', toHTML("![[" . self::PREFIX . "B]]"));
	}

	public function testCyclesAreReplacedByMarker(): void
	{
		$this->page('A', "a text\n\n![[" . self::PREFIX . "B]]\n");
		$this->page('B', "b text\n\n![[" . self::PREFIX . "A]]\n");
		$html = toHTML("![[" . self::PREFIX . "A]]");
		$this->assertStringContainsString('a text', $html);
		$this->assertStringContainsString('b text', $html);
		$this->assertStringContainsString('include failed (cycle)', $html);
	}

	public function testDepthLimit(): void
	{
		for ($i = 0; $i <= INCLUDE_MAX_DEPTH + 1; $i++) {
			$this->page("Level$i", "level$i\n\n![[" . self::PREFIX . 'Level' . ($i + 1) . "]]\n");
		}
		$html = toHTML("![[" . self::PREFIX . "Level0]]");
		$this->assertStringContainsString('too deeply nested', $html);
		$this->assertStringNotContainsString('level' . (INCLUDE_MAX_DEPTH + 1), $html);
	}

	public function testExponentialEmbedsAreLimited(): void
	{
		$this->page('Big', str_repeat('x', 1000) . "\n");
		$this->page('Twice', "![[" . self::PREFIX . "Big]]\n\n![[" . self::PREFIX . "Big]]\n");
		$text = '';
		for ($i = 0; $i < 20; $i++) {
			$text .= "![[" . self::PREFIX . "Twice]]\n\n";
		}
		$budget = 5000;
		$expanded = expandIncludes($text, array(), $budget);
		$this->assertLessThan(10000, strlen($expanded));
		$this->assertStringContainsString('too large', $expanded);
	}

	public function testInvalidTargetsAreNotRead(): void
	{
		foreach (['Missing page', '../config', '..\\config', '.hidden', 'a/.hidden', UPLOAD_FOLDER . '/x', '/etc/passwd', 'C:config'] as $name) {
			$html = toHTML("![[$name]]");
			$this->assertStringContainsString('include failed (not found)', $html, $name);
			$this->assertStringNotContainsString('<?php', $html, $name);
		}
	}

	public function testNotExpandedInCodeOrInline(): void
	{
		$this->page('Two', "SECRET\n");
		$name = self::PREFIX . 'Two';
		foreach (["```\n![[$name]]\n```", "~~~\n![[$name]]\n~~~", "    ![[$name]]", "text ![[$name]] text"] as $markdown) {
			$this->assertStringNotContainsString('SECRET', toHTML($markdown), $markdown);
		}
		$this->assertStringContainsString('SECRET', toHTML("```\ncode\n```\n\n![[$name]]"));
	}

	public function testEmbeddedContentIsSanitizedLikeOtherContent(): void
	{
		$this->page('Evil', "<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n![i](data:text/html,x)\n");
		$this->assertNoActiveContent('<html><body>' . toHTML("![[" . self::PREFIX . "Evil]]") . '</body></html>');
	}

	public function testLinksInEmbeddedPagesAreRendered(): void
	{
		$this->page('Links', "see [[Home]]\n");
		$this->assertStringContainsString('<a href=', toHTML("![[" . self::PREFIX . "Links]]"));
	}
}
