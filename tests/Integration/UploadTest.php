<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** Uploading, renaming and deleting images */
final class UploadTest extends AppTestCase
{
	private const POLYGLOT = "GIF89a\x01\x00\x01\x00\x00\x00\x00;<?php echo 'code executed'; ?>";

	public function testImagesCanBeUploadedAndAreListed(): void
	{
		$note = $this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertStringContainsString("File 'a.gif' uploaded", $note);
		$this->assertStringContainsString('![Image Description](/images/a.gif)', $note);
		$this->upload('b.png', self::png(), 'image/png');
		$this->assertSame(['a.gif', 'b.png'], $this->uploadedFiles());
		$this->assertSame(self::gif(), file_get_contents($this->imageFile('a.gif')));

		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('<img class="thumbImg" src="/images/a.gif"', $list);
		$this->assertStringContainsString('Total: 2', $list);
	}

	public function testSpacesInFileNamesAreReplaced(): void
	{
		$this->upload('pic one.gif', self::gif());
		$this->assertSame(['pic_one.gif'], $this->uploadedFiles());
	}

	public function testPathsInFileNamesAreIgnored(): void
	{
		$pagesBefore = $this->server->pageFiles();
		$this->upload('../../x.gif', self::gif());
		$this->assertSame(['x.gif'], $this->uploadedFiles());
		$this->assertFileDoesNotExist($this->server->rootDir() . '/x.gif');
		$this->assertFileDoesNotExist($this->server->pagesDir() . '/x.gif');
		$this->assertSame(array_merge($pagesBefore, ['images/x.gif']), $this->sorted($this->server->pageFiles()));
	}

	public static function refusedFileNames(): array
	{
		return [
			'php' => ['shell.php'], 'phtml' => ['shell.phtml'], 'php5' => ['shell.php5'],
			'phar' => ['shell.phar'], 'upper case php' => ['shell.PHP'],
			'image extension then php' => ['shell.gif.php'], 'no extension' => ['shell'],
			'htaccess' => ['.htaccess'], 'hidden image' => ['.hidden.gif'],
		];
	}

	#[DataProvider('refusedFileNames')]
	public function testScriptsAndHiddenFilesAreRefused(string $fileName): void
	{
		$before = $this->uploadedFiles();
		$note = $this->noteAfter($this->upload($fileName, self::POLYGLOT));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame($before, $this->uploadedFiles());
	}

	public function testHtmlNamedAsImageIsRefused(): void
	{
		$note = $this->noteAfter($this->upload('page.gif', '<html><script>alert(1)</script></html>'));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public static function svgUploads(): array
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';
		return [
			'svg' => ['x.svg', $svg],
			'svg named png' => ['x.png', $svg],
			'svg named pdf' => ['x.pdf', $svg],
			'svg with xml header' => ['x.svg', '<?xml version="1.0"?>' . $svg],
		];
	}

	#[DataProvider('svgUploads')]
	public function testSvgUploadsAreRefusedByDefault(string $fileName, string $content): void
	{
		$note = $this->noteAfter($this->upload($fileName, $content, 'image/svg+xml'));
		$this->assertStringContainsString('invalid file type', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testExistingImagesAreOnlyOverwrittenAfterConfirmation(): void
	{
		$this->upload('a.gif', self::gif());
		$changed = self::gif() . str_repeat("\0", 10);

		$note = $this->noteAfter($this->upload('a.gif', $changed));
		$this->assertStringContainsString('already exists', $note);
		$this->assertSame(self::gif(), file_get_contents($this->imageFile('a.gif')));

		$note = $this->noteAfter($this->upload('a.gif', $changed, 'image/gif', ['overwrite' => 'true']));
		$this->assertStringContainsString("File 'a.gif' uploaded", $note);
		$this->assertSame($changed, file_get_contents($this->imageFile('a.gif')));
	}

	public function testUploadFormOnlyAsksForOverwriteConfirmationViaScript(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('name="overwrite"', $body);
		$this->assertStringContainsString('/api.php?task=checkupload&filename="+encodeURIComponent(filename)', $body);
	}

	// --- rename ----------------------------------------------------------------------

	public function testImagesCanBeRenamedAndReferencesAreUpdated(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![Image Description](/images/a.gif) and ![other](/images/b.gif)');
		$note = $this->noteAfter($this->renameImage('a.gif', 'pic two.gif'));
		$this->assertStringContainsString('Image renamed', $note);
		$this->assertSame(['pic_two.gif'], $this->uploadedFiles());
		$this->assertSame('![Image Description](/images/pic_two.gif) and ![other](/images/b.gif)', $this->pageText('Gallery'));
	}

	public static function scriptFileNames(): array
	{
		return [
			'php' => ['poly.php'], 'phtml' => ['poly.phtml'], 'upper case' => ['poly.PHP'],
			'no extension' => ['poly'], 'htaccess' => ['.htaccess'], 'hidden' => ['.poly.gif'],
			'double extension' => ['poly.gif.php'], 'empty' => [''],
		];
	}

	#[DataProvider('scriptFileNames')]
	public function testImagesCannotBeRenamedToScriptsOrHiddenFiles(string $newName): void
	{
		$this->upload('poly.gif', self::POLYGLOT);
		$note = $this->noteAfter($this->renameImage('poly.gif', $newName));
		$this->assertStringContainsString('Error renaming', $note);
		$this->assertSame(['poly.gif'], $this->uploadedFiles());
	}

	public function testRenamingNeverOverwritesAnotherImage(): void
	{
		$this->upload('a.gif', self::gif());
		$this->upload('b.gif', self::gif() . 'b');
		$note = $this->noteAfter($this->renameImage('a.gif', 'b.gif'));
		$this->assertStringContainsString('Error renaming', $note);
		$this->assertSame(self::gif() . 'b', file_get_contents($this->imageFile('b.gif')));
		$this->assertFileExists($this->imageFile('a.gif'));
	}

	public function testPathsInNewImageNamesAreIgnored(): void
	{
		$this->upload('a.gif', self::gif());
		$this->renameImage('a.gif', '../../evil.gif');
		$this->assertSame(['evil.gif'], $this->uploadedFiles());
		$this->assertFileDoesNotExist($this->server->rootDir() . '/evil.gif');
	}

	public function testOnlyFilesInUploadsFolderCanBeRenamedOrDeleted(): void
	{
		file_put_contents($this->server->pagesDir() . '/secret.gif', 'not an upload');
		$this->renameImage('../secret.gif', 'moved.gif');
		$this->deleteImage('../secret.gif');
		$this->assertFileExists($this->server->pagesDir() . '/secret.gif');
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testHiddenFilesInUploadsFolderAreProtected(): void
	{
		file_put_contents($this->imageFile('.keep'), 'x');
		$this->assertStringContainsString('Error deleting', $this->noteAfter($this->deleteImage('.keep')));
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renameImage('.keep', 'kept.gif')));
		$this->assertFileExists($this->imageFile('.keep'));
		$this->assertFileDoesNotExist($this->imageFile('kept.gif'));
	}

	// --- delete ----------------------------------------------------------------------

	public function testImagesCanBeDeletedAndReferencesAreRemoved(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', 'before ![Image Description](/images/a.gif) after');
		$note = $this->noteAfter($this->deleteImage('a.gif'));
		$this->assertStringContainsString('Image deleted', $note);
		$this->assertSame([], $this->uploadedFiles());
		$this->assertSame('before  after', $this->pageText('Gallery'));
	}

	public function testUploadListShowsWhereImagesAreUsed(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![x](/images/a.gif)');
		$body = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('Used on page', $body);
		$this->assertStringContainsString('<a href="/index.php/Gallery">Gallery</a>', $body);
	}

	public function testUploadListCanBeSorted(): void
	{
		$this->upload('a.gif', self::gif());
		$this->upload('b.gif', self::gif() . 'longer');
		foreach (['name', 'recent', 'size', 'nonsense'] as $sortBy) {
			$response = $this->http->get('/index.php', ['action' => 'upload', 'sortBy' => $sortBy]);
			$this->assertSame(200, $response->status, $sortBy);
			$this->assertStringContainsString('Total: 2', $response->body);
		}
	}

	/** @param string[] $files */
	private function sorted(array $files): array
	{
		sort($files);
		return $files;
	}
}
