<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNamesTest extends TestCase
{
	public static function fileNames(): array
	{
		return [
			'unchanged' => ['Some page.md', 'Some page.md'],
			'tilde' => ['~root', '-root'],
			'parent folder' => ['../../etc/passwd', '-/-/etc/passwd'],
			'more dots' => ['a...b', 'a-.b'],
			'backslash' => ['a\\b', 'a-b'],
			'colon' => ['C:file', 'C-file'],
			'pipe' => ['a|b', 'a-b'],
			'ampersand' => ['Q&A', 'Q-A'],
			'single dot stays' => ['.hidden', '.hidden'],
		];
	}

	#[DataProvider('fileNames')]
	public function testSanitizeFilename(string $name, string $expected): void
	{
		$this->assertSame($expected, sanitizeFilename($name));
	}

	public static function extensions(): array
	{
		return [
			['a.gif', 'gif'], ['a.GIF', 'gif'], ['a.tar.gz', 'gz'], ['photo.jpeg', 'jpeg'], ['shell.php', 'php'],
			['noextension', null], ['trailingdot.', null], ['.hidden', 'hidden'], ['dir.d/file', null],
		];
	}

	#[DataProvider('extensions')]
	public function testFileExtension(string $name, ?string $expected): void
	{
		$this->assertSame($expected, getFileExt($name));
	}

	public function testHiddenFiles(): void
	{
		$this->assertTrue(isHiddenFile('.htaccess'));
		$this->assertTrue(isHiddenFile('.x.gif'));
		$this->assertTrue(isHiddenFile('dir/.git'));
		$this->assertFalse(isHiddenFile('a.gif'));
		$this->assertFalse(isHiddenFile('dir.d/a'));
	}

	public static function uploadNames(): array
	{
		return [
			'gif' => ['a.gif', true], 'png' => ['a.png', true], 'jpeg' => ['a.jpeg', true], 'pdf' => ['a.pdf', true],
			'upper case' => ['A.PNG', true], 'heic' => ['a.heic', true],
			'svg is not enabled by default' => ['a.svg', false],
			'php' => ['a.php', false], 'upper case php' => ['a.PHP', false], 'phtml' => ['a.phtml', false],
			'phar' => ['a.phar', false], 'htaccess' => ['.htaccess', false], 'no extension' => ['image', false],
			'script after image extension' => ['a.gif.php', false], 'hidden image' => ['.a.gif', false],
			'html' => ['a.html', false], 'empty' => ['', false],
		];
	}

	#[DataProvider('uploadNames')]
	public function testValidUploadNames(string $name, bool $expected): void
	{
		$this->assertSame($expected, hasValidUploadExt($name));
	}

	public function testSvgIsNotAcceptedUnlessEnabledAndTheLibraryIsInstalled(): void
	{
		$this->assertFalse(svgUploadsAvailable());
		$this->assertNotContains('svg', validUploadExts());
		$this->assertNotContains('image/svg+xml', validUploadTypes());
		$this->assertContains('image/gif', validUploadTypes());
		$this->assertContains('gif', validUploadExts());
	}

	public static function pageNames(): array
	{
		return [
			'simple' => ['Home', true], 'with spaces' => ['Two words', true], 'subfolder' => ['sub/page', true],
			'deep subfolders' => ['a/b/c', true], 'dot inside' => ['version 1.2', true],
			'uploads folder' => ['images/x', false], 'uploads folder itself' => ['images', false],
			'name starting like the uploads folder' => ['images2/x', true], 'in a subfolder named images' => ['sub/images/x', true],
			'hidden' => ['.hidden', false], 'hidden folder' => ['sub/.git/x', false], 'absolute' => ['/abs', false],
			'empty segment' => ['a//b', false], 'trailing slash' => ['a/', false], 'empty' => ['', false],
		];
	}

	#[DataProvider('pageNames')]
	public function testValidPageNames(string $name, bool $expected): void
	{
		$this->assertSame($expected, isValidPageName($name));
	}

	public function testPageFileNames(): void
	{
		$this->assertSame(PAGES_PATH . '/Home.md', fileNameForPage('Home'));
		$this->assertSame(PAGES_PATH . '/sub/page.md', fileNameForPage('sub/page'));
	}

	public function testAllPageNamesIncludeThePagesOfTheWiki(): void
	{
		$names = getAllPageNames();
		$this->assertContains('Home', $names);
		$this->assertContains('MarkdownSyntax', $names);
	}

	public static function replacementStrings(): array
	{
		return [['plain'], ['$1'], ['\\1'], ['\\\\'], ['${1}'], ['a$b\\c'], ['$0$9'], ['C$1\\2'], ['']];
	}

	/** Replacement strings must be inserted literally, not interpreted (backreferences) */
	#[DataProvider('replacementStrings')]
	public function testReplacementStringsAreInsertedLiterally(string $text): void
	{
		$this->assertSame("[$text]", preg_replace('/x/', '[' . pregReplacementQuote($text) . ']', 'x'));
		$this->assertSame($text, preg_replace('/x/', pregReplacementQuote($text), 'x'));
	}

	public function testSortingByLengthDescending(): void
	{
		$names = ['a', 'ccc', 'bb'];
		usort($names, 'descLengthSort');
		$this->assertSame(['ccc', 'bb', 'a'], $names);
	}
}
