<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** A configured name of the uploads folder: it is used for files and links, and its content is not part of the wiki */
final class UploadFolderConfigTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['UPLOAD_FOLDER' => 'pictures'];
	}

	public function testUploadsUseTheConfiguredFolderAndUrl(): void
	{
		$note = $this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertStringContainsString('![Image Description](/pictures/a.gif)', $note);
		$this->assertSame(['a.gif'], $this->uploadedFiles());
		$this->assertFileExists($this->server->pagesDir() . '/pictures/a.gif');
		$this->assertDirectoryDoesNotExist($this->server->pagesDir() . '/images');

		$this->savePage('Pic', '![x](/pictures/a.gif)');
		$this->assertMatchesRegularExpression('#<img src="/pictures/a\.gif\?v=\d+"#', $this->http->get($this->pageUrl('Pic'))->body);
		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertStringContainsString('src="/pictures/a.gif?v=', $list);
		$this->assertStringContainsString('(/pictures/image.jpg)', $this->http->get('/index.php', ['action' => 'new', 'page' => 'X'])->body);
	}

	public function testRenamingAndDeletingImagesUpdatesPages(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![x](/pictures/a.gif "T")');
		$this->renameImage('a.gif', 'b.gif');
		$this->assertSame('![x](/pictures/b.gif "T")', $this->pageText('Gallery'));
		$this->deleteImage('b.gif');
		$this->assertSame('', $this->pageText('Gallery'));
	}

	public function testPagesCanNotBeCreatedInTheUploadsFolder(): void
	{
		$this->assertStringContainsString('invalid page name', $this->noteInResponse($this->savePage('pictures/x', 'text')));
		$this->assertFileDoesNotExist($this->pageFile('pictures/x'));
		// while "images" is a page folder like any other now
		$this->noteAfter($this->savePage('images/x', 'text'));
		$this->assertSame('text', $this->pageText('images/x'));
	}

	public function testMarkdownFilesInTheUploadsFolderAreNotPages(): void
	{
		file_put_contents($this->server->imagesDir() . '/secret.md', 'hidden-content-42');
		$this->savePage('Normal', 'x');

		$this->assertStringNotContainsString('pictures/secret', $this->http->get('/index.php', ['action' => 'all'])->body);
		$this->assertStringContainsString('0 matches', $this->http->get('/index.php', ['action' => 'search', 'q' => 'hidden-content-42'])->body);
		foreach (['view', 'edit'] as $action) {
			$body = $this->http->get('/index.php', ['action' => $action, 'page' => 'pictures/secret'])->body;
			$this->assertStringNotContainsString('hidden-content-42', $body, $action);
		}
		$this->assertStringContainsString('invalid page name', $this->noteInResponse($this->savePage('pictures/secret', 'changed', false)));
		$this->postAction('renamed', ['oldPageName' => 'pictures/secret', 'newName' => 'Moved']);
		$this->postAction('deleted', ['oldPageName' => 'pictures/secret']);
		$this->assertSame('hidden-content-42', file_get_contents($this->server->imagesDir() . '/secret.md'));
		$this->assertFileDoesNotExist($this->pageFile('Moved'));
	}
}
