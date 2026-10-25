<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use Michelf\MarkdownExtra;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown library is a Composer package (michelf/php-markdown) with a copy in the folder Michelf, which the wiki
 * uses where Composer is not used. Both must be the same version of the library, or the wiki would behave differently.
 */
final class MarkdownLibraryTest extends TestCase
{
	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	/** The folder with the Michelf files of the class which is loaded */
	private static function loadedFolder(): string
	{
		return dirname((string)(new \ReflectionClass(MarkdownExtra::class))->getFileName());
	}

	private static function isComposerFolder(string $folder): bool
	{
		return str_contains(str_replace('\\', '/', $folder), '/vendor/michelf/');
	}

	private static function version(string $folder): string
	{
		$found = preg_match('/const MARKDOWNLIB_VERSION = "([^"]+)"/', (string)file_get_contents("$folder/Markdown.php"), $matches);
		self::assertSame(1, $found, "no version found in $folder/Markdown.php");
		return $matches[1];
	}

	/** @return string[] the PHP files in the folder, by name */
	private static function files(string $folder): array
	{
		return array_map('basename', glob("$folder/*.php") ?: []);
	}

	public function testTheCopyHasTheLicense(): void
	{
		$this->assertFileExists(self::root() . '/Michelf/License.md');
		$this->assertNotSame([], self::files(self::root() . '/Michelf'));
	}

	public function testTheComposerPackageIsUsedWhenItIsInstalled(): void
	{
		if (!is_dir(self::root() . '/vendor/michelf/php-markdown')) {
			$this->markTestSkipped('michelf/php-markdown is not installed (composer install)');
		}
		$this->assertTrue(self::isComposerFolder(self::loadedFolder()), 'loaded from ' . self::loadedFolder());
	}

	public function testTheCopyIsTheInstalledVersionOfThePackage(): void
	{
		$package = self::loadedFolder();
		if (!self::isComposerFolder($package)) {
			$this->markTestSkipped('michelf/php-markdown is not installed (composer install)');
		}
		$copy = self::root() . '/Michelf';
		if (self::version($package) !== self::version($copy)) {
			$this->markTestIncomplete(sprintf(
				'The package michelf/php-markdown is version %s, the copy in the folder Michelf is %s: copy the files of vendor/michelf/php-markdown/Michelf and its License.md to the folder Michelf',
				self::version($package), self::version($copy)
			));
		}
		$this->assertSame(self::files($package), self::files($copy), 'the same files');
		foreach (self::files($package) as $file) {
			$this->assertFileEquals("$package/$file", "$copy/$file", "$file differs from the package");
		}
	}

	public function testTheCopyIsUsedWithoutComposer(): void
	{
		$folder = sys_get_temp_dir() . '/w2-markdown-' . bin2hex(random_bytes(6));
		mkdir($folder);
		try {
			copy(self::root() . '/functions.php', "$folder/functions.php");
			foreach (['Michelf', 'src'] as $subFolder) {
				mkdir("$folder/$subFolder");
				foreach (glob(self::root() . "/$subFolder/*.php") ?: [] as $file) {
					copy($file, "$folder/$subFolder/" . basename($file));
				}
			}
			// (no vendor folder, so no Composer autoloader)
			$code = "define('W2APP', true); \$_SERVER['SCRIPT_NAME'] = '/index.php'; require 'functions.php';"
				. "echo (new ReflectionClass('Michelf\\\\MarkdownExtra'))->getFileName(), \"\\n\", (new Michelf\\MarkdownExtra)->transform('**bold**');";
			$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $folder);
			$this->assertIsResource($process);
			$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
			proc_close($process);
			$this->assertSame($folder . "/Michelf/MarkdownExtra.php\n<p><strong>bold</strong></p>\n", $output);
		} finally {
			foreach (['Michelf', 'src'] as $subFolder) {
				array_map('unlink', glob("$folder/$subFolder/*") ?: []);
				@rmdir("$folder/$subFolder");
			}
			@unlink("$folder/functions.php");
			@rmdir($folder);
		}
	}
}
