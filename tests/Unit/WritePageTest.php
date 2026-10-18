<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WritePageTest extends TestCase
{
	private string $folder;

	protected function setUp(): void
	{
		$this->folder = sys_get_temp_dir() . '/w2-write-test-' . bin2hex(random_bytes(6));
		mkdir($this->folder);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->folder . '/{,.}*', GLOB_BRACE) ?: [] as $file)
		{
			if (is_file($file) || is_link($file))
			{
				unlink($file);
			}
		}
		rmdir($this->folder);
	}

	private function filesInFolder(): array
	{
		return array_values(array_diff(scandir($this->folder), ['.', '..']));
	}

	public function testCreatesFile(): void
	{
		$this->assertTrue(writeFileAtomically("$this->folder/new.md", "Hello\n"));
		$this->assertSame("Hello\n", file_get_contents("$this->folder/new.md"));
		$this->assertSame(0666 & ~umask(), fileperms("$this->folder/new.md") & 0777);
	}

	public function testReplacesContentAndLeavesNoTemporaryFile(): void
	{
		file_put_contents("$this->folder/page.md", "old text that is longer");
		$this->assertTrue(writeFileAtomically("$this->folder/page.md", "new"));
		$this->assertSame("new", file_get_contents("$this->folder/page.md"));
		$this->assertSame(['page.md'], $this->filesInFolder());
	}

	public function testWritesEmptyText(): void
	{
		file_put_contents("$this->folder/page.md", "something");
		$this->assertTrue(writeFileAtomically("$this->folder/page.md", ""));
		$this->assertSame("", file_get_contents("$this->folder/page.md"));
	}

	public function testKeepsPermissionsOfExistingFile(): void
	{
		file_put_contents("$this->folder/page.md", "old");
		chmod("$this->folder/page.md", 0640);
		$this->assertTrue(writeFileAtomically("$this->folder/page.md", "new"));
		clearstatcache();
		$this->assertSame(0640, fileperms("$this->folder/page.md") & 0777);
	}

	public function testWritesThroughSymbolicLink(): void
	{
		file_put_contents("$this->folder/real.md", "old");
		symlink("$this->folder/real.md", "$this->folder/link.md");
		$this->assertTrue(writeFileAtomically("$this->folder/link.md", "new"));
		$this->assertTrue(is_link("$this->folder/link.md"));
		$this->assertSame("new", file_get_contents("$this->folder/real.md"));
	}

	public function testFailsForMissingFolderAndKeepsNothingBehind(): void
	{
		$this->assertFalse(@writeFileAtomically("$this->folder/missing/page.md", "x"));
		$this->assertSame([], $this->filesInFolder());
	}

	public function testFailsForDanglingSymbolicLink(): void
	{
		symlink("$this->folder/gone.md", "$this->folder/link.md");
		$this->assertFalse(writeFileAtomically("$this->folder/link.md", "x"));
		$this->assertSame(['link.md'], $this->filesInFolder());
	}
}
