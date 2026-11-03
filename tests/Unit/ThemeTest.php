<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The color scheme chosen with the buttons of the toolbar, and the icons */
final class ThemeTest extends TestCase
{
	public function testThereAreThreeModes(): void
	{
		$this->assertSame(['auto', 'light', 'dark'], THEME_MODES);
		foreach (THEME_MODES as $mode) {
			$this->assertTrue(isThemeMode($mode));
			// (every mode has an icon)
			$this->assertFileExists(dirname(__DIR__, 2) . "/w2-icons/theme-$mode.svg");
		}
	}

	#[DataProvider('invalidModes')]
	public function testOtherValuesAreNoModes(mixed $value): void
	{
		$this->assertFalse(isThemeMode($value));
		$this->assertSame('auto', themeModeFrom($value));
	}

	public static function invalidModes(): array
	{
		return [[null], [''], ['Dark'], ['dark '], ['blue'], [['dark']], [1], [true]];
	}

	public function testTheButtonGoesThroughTheModesAndStartsAgain(): void
	{
		$this->assertSame('light', nextThemeMode('auto'));
		$this->assertSame('dark', nextThemeMode('light'));
		$this->assertSame('auto', nextThemeMode('dark'));
		$this->assertSame('light', nextThemeMode('unknown'), 'the system\'s scheme is the one before light');
	}

	public function testTheModeComesFromTheCookie(): void
	{
		$this->assertSame('dark', themeModeFrom('dark'));
		$this->assertSame('light', themeModeFrom('light'));
		$this->assertSame('auto', themeModeFrom(null));
	}

	#[DataProvider('returnUrls')]
	public function testReturnUrlStaysInTheWiki(mixed $url, string $expected): void
	{
		$this->assertSame($expected, themeReturnUrl($url, '/w/index.php'));
	}

	public static function returnUrls(): array
	{
		return [
			'the script' => ['/w/index.php', '/w/index.php'],
			'a query' => ['/w/index.php?action=all', '/w/index.php?action=all'],
			'a page in the path' => ['/w/index.php/Some%20Page', '/w/index.php/Some%20Page'],
			'a fragment' => ['/w/index.php/Home#top', '/w/index.php/Home#top'],
			'nothing' => [null, '/w/index.php'],
			'empty' => ['', '/w/index.php'],
			'another site' => ['http://example.com/', '/w/index.php'],
			'no scheme' => ['//example.com/w/index.php', '/w/index.php'],
			'another script' => ['/w/index.php.evil', '/w/index.php'],
			'another folder' => ['/other/index.php', '/w/index.php'],
			'a line break' => ["/w/index.php?x=1\r\nSet-Cookie: a=b", '/w/index.php'],
			'a space' => ['/w/index.php?x= y', '/w/index.php'],
			'a backslash' => ['/w/index.php/\\evil.com', '/w/index.php'],
			'not text' => [['/w/index.php'], '/w/index.php'],
		];
	}

	public function testIconIsAnInlineSvgWhichUsesTheFile(): void
	{
		$icon = (string)iconSvg('edit', 'Edit <it> & "more"', 'rightaligned');
		$this->assertMatchesRegularExpression(
			'#^<svg class="icon icon-edit rightaligned" viewBox="0 0 16 16" role="img" aria-label="Edit &lt;it&gt; &amp; &quot;more&quot;">'
			. '<title>Edit &lt;it&gt; &amp; &quot;more&quot;</title><use href="/w2-icons/edit\.svg\?v=\d+\#i"/></svg>$#',
			$icon
		);
	}

	/** The files are shown by <use>: every one has the element with the id, and paints with currentColor (no style elements, which the policy of the pages blocks) */
	public function testIconFilesCanBeUsed(): void
	{
		$files = glob(dirname(__DIR__, 2) . '/w2-icons/*.svg') ?: [];
		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$svg = (string)file_get_contents($file);
			$name = basename($file);
			$this->assertStringContainsString(' id="i"', $svg, "$name has the element which iconSvg() refers to");
			$this->assertStringContainsString('currentColor', $svg, "$name paints with the color of the page");
			$this->assertStringNotContainsString('<style', $svg, "$name has no style element");
			$this->assertStringNotContainsString(' style=', $svg, "$name has no style attribute");
		}
	}
}
