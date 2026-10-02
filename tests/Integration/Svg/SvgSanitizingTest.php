<?php

namespace W2\Tests\Integration\Svg;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\SvgAppTestCase;

/** Uploading SVG files with SVG_UPLOADS_ENABLED and enshrined/svg-sanitize installed */
final class SvgSanitizingTest extends SvgAppTestCase
{
	private static function fixtureNames(array $exclude): array
	{
		$names = [];
		foreach (glob(dirname(__DIR__, 2) . '/fixtures/svg/*.svg') as $file) {
			$name = basename($file, '.svg');
			if (!in_array($name, $exclude, true)) {
				$names[$name] = [$name];
			}
		}
		return $names;
	}

	public static function dangerousFixtures(): array
	{
		return self::fixtureNames(['benign']);
	}

	public static function notSvgFixtures(): array
	{
		return [
			'html' => ['html'], 'php' => ['php'], 'text' => ['not-svg'], 'empty' => ['empty'],
			'not well-formed' => ['not-wellformed'], 'script behind byte order mark' => ['bom-script'],
		];
	}

	/** Every dangerous file is either refused, or stored without anything dangerous */
	#[DataProvider('dangerousFixtures')]
	public function testDangerousSvgFilesAreRefusedOrSanitized(string $fixture): void
	{
		$note = $this->noteAfter($this->uploadSvg("$fixture.svg", $this->svgFixture($fixture)));
		$stored = $this->uploadedFiles();
		if ($stored === []) {
			$this->assertStringContainsString('invalid file type', $note);
			return;
		}
		$this->assertSame(["$fixture.svg"], $stored);
		$this->assertStringContainsString("File '$fixture.svg' uploaded", $note);
		$this->assertSafeSvg((string)file_get_contents($this->imageFile("$fixture.svg")));
	}

	public static function sanitizedFixtures(): array
	{
		return [
			'script element' => ['script'], 'event handlers' => ['onload'], 'javascript links' => ['jshref'],
			'processing instruction' => ['pi'], 'stylesheet imports' => ['style'], 'remote references' => ['use'],
			'animated links' => ['animate'], 'namespaced script' => ['nsscript'], 'CDATA script' => ['cdata-script'],
			'entity encoded link' => ['entity-href'],
		];
	}

	/** The sanitizer removes dangerous parts, but keeps otherwise valid files */
	#[DataProvider('sanitizedFixtures')]
	public function testDangerousPartsAreRemovedFromValidFiles(string $fixture): void
	{
		$this->uploadSvg("$fixture.svg", $this->svgFixture($fixture));
		$this->assertSame(["$fixture.svg"], $this->uploadedFiles());
		$svg = (string)file_get_contents($this->imageFile("$fixture.svg"));
		$this->assertSafeSvg($svg);
		$this->assertStringContainsString('<svg', $svg);
	}

	public function testHarmlessSvgFilesAreKept(): void
	{
		$this->uploadSvg('benign.svg', $this->svgFixture('benign'));
		$svg = (string)file_get_contents($this->imageFile('benign.svg'));
		$this->assertSafeSvg($svg);
		foreach (['<linearGradient', '<stop', '<path d="M0 0L10 10"', 'fill="url(#g)"', '<text', 'Hi', 'viewBox="0 0 10 10"'] as $part) {
			$this->assertStringContainsString($part, $svg);
		}
	}

	#[DataProvider('notSvgFixtures')]
	public function testFilesWhichAreNotSvgAreRefused(string $fixture): void
	{
		$note = $this->noteAfter($this->uploadSvg("$fixture.svg", $this->svgFixture($fixture)));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public static function otherFileNames(): array
	{
		return [
			'png' => ['x.png'], 'gif' => ['x.gif'], 'jpeg' => ['x.jpg'], 'pdf' => ['x.pdf'],
			'php' => ['x.php'], 'hidden' => ['.x.svg'], 'svg then php' => ['x.svg.php'],
		];
	}

	/** SVG content is only accepted in files named .svg, where it gets sanitized */
	#[DataProvider('otherFileNames')]
	public function testSvgContentWithOtherFileNamesIsRefused(string $fileName): void
	{
		$note = $this->noteAfter($this->uploadSvg($fileName, $this->svgFixture('script')));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testHugeSvgFilesAreRefused(): void
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>' . str_repeat('a', 1100000) . '</text></svg>';
		$note = $this->noteAfter($this->uploadSvg('huge.svg', $svg));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testSvgFilesAreListedAsImages(): void
	{
		$this->uploadSvg('benign.svg', $this->svgFixture('benign'));
		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('<img class="thumbImg" src="/images/benign.svg"', $list);
		$this->assertStringContainsString('![Image Description](/images/benign.svg)', $list);
	}

	public function testExistingSvgFilesAreOnlyOverwrittenAfterConfirmation(): void
	{
		$this->uploadSvg('a.svg', $this->svgFixture('benign'));
		$before = file_get_contents($this->imageFile('a.svg'));

		$note = $this->noteAfter($this->uploadSvg('a.svg', $this->svgFixture('script')));
		$this->assertStringContainsString('already exists', $note);
		$this->assertSame($before, file_get_contents($this->imageFile('a.svg')));

		$this->uploadSvg('a.svg', $this->svgFixture('script'), ['overwrite' => 'true']);
		$this->assertNotSame($before, file_get_contents($this->imageFile('a.svg')));
		$this->assertSafeSvg((string)file_get_contents($this->imageFile('a.svg')));
	}

	public function testSvgFilesCanBeRenamedButNotToScripts(): void
	{
		$this->uploadSvg('a.svg', $this->svgFixture('benign'));
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renameImage('a.svg', 'a.php')));
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renameImage('a.svg', 'a.html')));
		$this->assertStringContainsString('Image renamed', $this->noteAfter($this->renameImage('a.svg', 'b c.svg')));
		$this->assertSame(['b_c.svg'], $this->uploadedFiles());
	}

	public function testResizeOptionNeverSendsSvgFilesToImageMagick(): void
	{
		// with the option set, other images are processed by ImageMagick (an extension which is not always installed)
		$note = $this->noteAfter($this->uploadSvg('a.svg', $this->svgFixture('benign'), ['resize' => 'true', 'maxsize' => '20']));
		$this->assertStringContainsString("File 'a.svg' uploaded", $note);
		$this->assertSame(['a.svg'], $this->uploadedFiles());
	}
}
