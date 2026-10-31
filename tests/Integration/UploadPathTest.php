<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** UPLOAD_PATH: the uploads folder can be outside the pages folder, e.g. next to it */
final class UploadPathTest extends AppTestCase
{
	protected function serverOptions(): array
	{
		return ['pagesFolder' => 'data/pages', 'uploadsFolder' => 'data/media'];
	}

	public function testUploadsAreStoredServedAndListedThere(): void
	{
		$note = $this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertStringContainsString('![Image Description](/images/a.gif)', $note, 'the address in pages is still UPLOAD_URL');
		$this->assertFileExists($this->server->rootDir() . '/data/media/a.gif');
		$this->assertFileDoesNotExist($this->server->pagesDir() . '/images/a.gif');

		$this->assertSame(self::gif(), $this->http->get('/file.php', ['name' => 'a.gif'])->body);
		$this->assertStringContainsString('src="/file.php?name=a.gif&amp;v=', $this->http->get('/index.php', ['action' => 'upload'])->body);
		$this->assertSame('true', $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'a.gif'])->body);
		$this->assertSame('false', $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'b.gif'])->body);
	}

	public function testRenamingAndDeletingUpdatesThePages(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![x](/images/a.gif "T")');
		$this->renameImage('a.gif', 'b.gif');
		$this->assertFileExists($this->server->rootDir() . '/data/media/b.gif');
		$this->assertSame('![x](/images/b.gif "T")', $this->pageText('Gallery'));
		$this->deleteImage('b.gif');
		$this->assertFileDoesNotExist($this->server->rootDir() . '/data/media/b.gif');
		$this->assertSame('', $this->pageText('Gallery'));
	}

	public function testAFolderOfTheNameOfUploadFolderIsAnOrdinaryPageFolder(): void
	{
		$this->noteAfter($this->savePage('images/x', 'text in a page folder'));
		$this->assertSame('text in a page folder', $this->pageText('images/x'));
		$this->assertStringContainsString('images/x', $this->http->get('/index.php', ['action' => 'all'])->body);
	}
}
