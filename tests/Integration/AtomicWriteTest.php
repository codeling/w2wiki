<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Pages are replaced in one step, so no temporary files stay behind, and the permissions of pages are kept */
final class AtomicWriteTest extends AppTestCase
{
	/** @return string[] files in the pages folder which are not pages (but not the folders) */
	private function strayFiles(): array
	{
		$stray = [];
		foreach (scandir($this->server->pagesDir()) as $name) {
			if (str_starts_with($name, '.w2-write-')) {
				$stray[] = $name;
			}
		}
		return $stray;
	}

	public function testSaveReplacesPageAndLeavesNoTemporaryFile(): void
	{
		$this->assertSame(303, $this->savePage('Atomic', 'first')->status);
		$this->assertSame(303, $this->savePage('Atomic', 'second text', false)->status);
		$this->assertSame('second text', $this->pageText('Atomic'));
		$this->assertSame([], $this->strayFiles());
	}

	public function testSavePageInNewFolder(): void
	{
		$this->assertSame(303, $this->savePage('Folder/Sub page', 'text')->status);
		$this->assertSame('text', $this->pageText('Folder/Sub page'));
	}

	public function testSavingKeepsPermissionsOfPage(): void
	{
		$this->savePage('Perms', 'first');
		chmod($this->pageFile('Perms'), 0664);
		$this->savePage('Perms', 'second', false);
		clearstatcache();
		$this->assertSame(0664, fileperms($this->pageFile('Perms')) & 0777);
	}

	public function testRenamingUpdatesLinksWithoutTemporaryFiles(): void
	{
		$this->savePage('Target', 'x');
		$this->savePage('Linker', 'see [[Target]] and [[Target|text]]');
		$this->postAction('renamed', ['oldPageName' => 'Target', 'newName' => 'Moved']);
		$this->assertSame('see [[Moved]] and [[Moved|text]]', $this->pageText('Linker'));
		$this->assertSame([], $this->strayFiles());
	}

	public function testRenamingImageUpdatesPagesWithoutTemporaryFiles(): void
	{
		$this->upload('pic.gif', self::gif(), 'image/gif', ['resize' => '']);
		$this->savePage('Gallery', '![a](/images/pic.gif)');
		$this->renameImage('pic.gif', 'pic2.gif');
		$this->assertSame('![a](/images/pic2.gif)', $this->pageText('Gallery'));
		$this->assertSame([], $this->strayFiles());
	}
}
