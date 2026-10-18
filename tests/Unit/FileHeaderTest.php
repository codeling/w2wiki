<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every source file of W2 names its license with an SPDX identifier near the top (see "License" in README.md),
 * and it is the license of the project.
 */
final class FileHeaderTest extends TestCase
{
	private const EXTENSIONS = ['php', 'js', 'css', 'sh', 'yml', 'yaml'];
	private const EXTENSIONLESS = ['Containerfile'];

	/** Folders which are not part of the sources of W2: dependencies (Michelf is php-markdown with its own license), caches, user data */
	private const EXCLUDED = ['.git', 'vendor', 'Michelf', 'node_modules', '.phpunit.cache', 'pages'];

	/** How far down a file the identifier may be (it can be in the comment after a shebang or `<?php` line) */
	private const HEADER_LINES = 10;

	private static function root(): string
	{
		return dirname(__DIR__, 2);
	}

	private static function projectLicense(): string
	{
		$composer = json_decode((string)file_get_contents(self::root() . '/composer.json'), true);
		self::assertIsArray($composer);
		return $composer['license'];
	}

	/** @return array<string, array{string}> */
	public static function sourceFiles(): array
	{
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator(self::root(), \FilesystemIterator::SKIP_DOTS),
			fn (\SplFileInfo $file) => !in_array($file->getFilename(), self::EXCLUDED, true)
		));
		foreach ($iterator as $file) {
			$path = substr($file->getPathname(), strlen(self::root()) + 1);
			// config.php is the settings of an installation, not part of the sources
			$isSource = in_array($file->getExtension(), self::EXTENSIONS, true) || in_array($file->getFilename(), self::EXTENSIONLESS, true);
			if ($isSource && $path !== 'config.php') {
				$files[$path] = [$path];
			}
		}
		ksort($files);
		return $files;
	}

	#[DataProvider('sourceFiles')]
	public function testFileNamesTheLicense(string $path): void
	{
		$head = implode("\n", array_slice(file(self::root() . "/$path", FILE_IGNORE_NEW_LINES) ?: [], 0, self::HEADER_LINES));
		$this->assertMatchesRegularExpression(
			'/SPDX-License-Identifier: ' . preg_quote(self::projectLicense(), '/') . '$/m',
			$head,
			"$path needs `SPDX-License-Identifier: " . self::projectLicense() . '` in a comment in its first ' . self::HEADER_LINES . ' lines'
		);
	}

	public function testLicenseFileIsTheProjectLicense(): void
	{
		$this->assertSame('MIT', self::projectLicense(), 'the SPDX identifiers in the files and README.md need to change with the license');
		$this->assertStringStartsWith('MIT License', (string)file_get_contents(self::root() . '/LICENSE'));
	}

	public function testSourceFilesAreFound(): void
	{
		$this->assertGreaterThan(50, count(self::sourceFiles()));
		$this->assertArrayHasKey('index.php', self::sourceFiles());
		$this->assertArrayNotHasKey('Michelf/Markdown.php', self::sourceFiles());
	}
}
