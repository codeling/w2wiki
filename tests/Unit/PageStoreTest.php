<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\PageStore;

/** The pages of the wiki as files in a folder (W2\PageStore), tested in a temporary folder */
final class PageStoreTest extends TestCase
{
	private string $root;
	private PageStore $store;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/w2-store-test-' . bin2hex(random_bytes(6));
		mkdir($this->root);
		$this->store = new PageStore($this->root, 'md', 'uploads');
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);
	}

	private function removeTree(string $folder): void
	{
		foreach (array_diff(scandir($folder), ['.', '..']) as $entry) {
			$path = "$folder/$entry";
			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}
		rmdir($folder);
	}

	private function put(string $relative, string $content = 'x'): void
	{
		if (!is_dir(dirname("$this->root/$relative"))) {
			mkdir(dirname("$this->root/$relative"), 0755, true);
		}
		file_put_contents("$this->root/$relative", $content);
	}

	public static function validNames(): array
	{
		return [
			'plain' => ['Home', true], 'with space' => ['Some page', true], 'subfolder' => ['sub/Page', true],
			'deep' => ['a/b/c', true], 'dots inside' => ['v1.2 notes', true], 'unicode' => ['Übung', true],
			'empty' => ['', false], 'hidden' => ['.hidden', false], 'hidden folder' => ['sub/.git/x', false],
			'parent' => ['../x', false], 'parent inside' => ['a/../b', false], 'trailing slash' => ['a/', false],
			'leading slash' => ['/a', false], 'double slash' => ['a//b', false],
			'uploads folder' => ['uploads/x', false], 'uploads folder itself' => ['uploads', false],
			'name starting like uploads' => ['uploads2/x', true], 'uploads deeper down' => ['sub/uploads/x', true],
		];
	}

	#[DataProvider('validNames')]
	public function testValidNames(string $name, bool $valid): void
	{
		$this->assertSame($valid, $this->store->isValidName($name));
	}

	public function testPathOfAPage(): void
	{
		$this->assertSame("$this->root/Home.md", $this->store->path('Home'));
		$this->assertSame("$this->root/sub/page.md", $this->store->path('sub/page'));
		$this->assertSame("$this->root/Home.txt", (new PageStore($this->root, 'txt', 'uploads'))->path('Home'));
	}

	public function testExistsOnlyForValidNamesOfExistingFiles(): void
	{
		$this->put('Home.md');
		$this->put('sub/Page.md');
		$this->put('.secret.md');
		$this->put('uploads/inside.md');
		$this->put('notes.txt');
		foreach (['Home', 'sub/Page'] as $name) {
			$this->assertTrue($this->store->exists($name), $name);
		}
		foreach (['Missing', '', '.secret', 'uploads/inside', 'notes', '../x', 'sub'] as $name) {
			$this->assertFalse($this->store->exists($name), $name);
		}
	}

	public function testReadModifiedAndSize(): void
	{
		$this->put('Home.md', "hello\n");
		touch("$this->root/Home.md", 1000000000);
		$this->assertSame("hello\n", $this->store->read('Home'));
		$this->assertSame(1000000000, $this->store->modified('Home'));
		$this->assertSame(6, $this->store->size('Home'));
		foreach (['Missing', '.x', 'uploads/x'] as $name) {
			$this->assertNull($this->store->read($name), $name);
			$this->assertNull($this->store->modified($name), $name);
			$this->assertNull($this->store->size($name), $name);
		}
	}

	public function testNamesListsAllPagesWithSubfoldersAndNothingElse(): void
	{
		$this->put('Home.md');
		$this->put('Other page.md');
		$this->put('sub/Page.md');
		$this->put('sub/deeper/Leaf.md');
		$this->put('notes.txt');
		$this->put('readme');
		$this->put('.hidden.md');
		$this->put('.git/objects/x.md');
		$this->put('sub/.cache/y.md');
		$this->put('uploads/image.md');
		$this->put('sub/uploads/Kept.md');
		mkdir("$this->root/empty");
		$names = $this->store->names();
		sort($names);
		$this->assertSame(['Home', 'Other page', 'sub/Page', 'sub/deeper/Leaf', 'sub/uploads/Kept'], $names);
	}

	public function testNamesOfAnEmptyOrMissingFolder(): void
	{
		$this->assertSame([], $this->store->names());
		$this->assertSame([], @(new PageStore("$this->root/missing", 'md', 'uploads'))->names());
	}

	public function testWriteCreatesAndReplacesPagesAndTheirFolders(): void
	{
		$this->assertTrue($this->store->write('New', 'first'));
		$this->assertSame('first', file_get_contents("$this->root/New.md"));
		$this->assertTrue($this->store->write('New', 'second'));
		$this->assertSame('second', $this->store->read('New'));
		$this->assertTrue($this->store->write('a/b/Deep', 'text'));
		$this->assertSame('text', file_get_contents("$this->root/a/b/Deep.md"));
		// (nothing else is left in the folder, like a temporary file of the atomic write)
		$this->assertSame(['New.md', 'a'], array_values(array_diff(scandir($this->root), ['.', '..'])));
		$this->assertSame(['Deep.md'], array_values(array_diff(scandir("$this->root/a/b"), ['.', '..'])));
	}

	public function testWriteRefusesInvalidNames(): void
	{
		foreach (['', '.hidden', '../escape', 'a/../../escape', 'uploads/x', 'a//b', '/abs'] as $name) {
			$this->assertFalse($this->store->write($name, 'x'), $name);
		}
		$this->assertSame([], array_values(array_diff(scandir($this->root), ['.', '..'])));
		$this->assertFileDoesNotExist(dirname($this->root) . '/escape.md');
	}

	public function testWriteFailsWhenAFileIsInTheWayOfAFolder(): void
	{
		$this->put('blocked', 'a file, not a folder');
		$this->assertFalse(@$this->store->write('blocked/Page', 'x'));
		$this->assertSame('a file, not a folder', file_get_contents("$this->root/blocked"));
	}

	public function testDelete(): void
	{
		$this->put('Gone.md');
		$this->put('.secret.md');
		$this->assertTrue($this->store->delete('Gone'));
		$this->assertFileDoesNotExist("$this->root/Gone.md");
		$this->assertFalse($this->store->delete('Gone'), 'already deleted');
		$this->assertFalse($this->store->delete('.secret'), 'invalid name');
		$this->assertFileExists("$this->root/.secret.md");
	}

	public function testRenameMovesPagesAndCreatesFolders(): void
	{
		$this->put('Old.md', 'content');
		$this->assertTrue($this->store->rename('Old', 'New'));
		$this->assertFileDoesNotExist("$this->root/Old.md");
		$this->assertSame('content', $this->store->read('New'));
		$this->assertTrue($this->store->rename('New', 'sub/folder/Moved'));
		$this->assertSame('content', $this->store->read('sub/folder/Moved'));
		$this->assertFileDoesNotExist("$this->root/New.md");
	}

	public function testRenameNeverReplacesAndChecksNames(): void
	{
		$this->put('A.md', 'a');
		$this->put('B.md', 'b');
		$this->assertFalse($this->store->rename('A', 'B'), 'target exists');
		$this->assertFalse($this->store->rename('Missing', 'C'), 'source missing');
		$this->assertFalse($this->store->rename('A', '.hidden'), 'hidden target');
		$this->assertFalse($this->store->rename('A', 'uploads/A'), 'target in the uploads folder');
		$this->assertFalse($this->store->rename('A', '../A'), 'target outside');
		$this->assertFalse($this->store->rename('A', ''), 'empty target');
		$this->assertSame('a', $this->store->read('A'));
		$this->assertSame('b', $this->store->read('B'));
		$this->assertSame(['A.md', 'B.md'], array_values(array_diff(scandir($this->root), ['.', '..'])));
	}

	public function testRenameToTheSameNameFails(): void
	{
		$this->put('A.md', 'a');
		$this->assertFalse($this->store->rename('A', 'A'));
		$this->assertSame('a', $this->store->read('A'));
	}
}
