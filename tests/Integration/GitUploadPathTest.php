<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** With the uploads folder next to the pages folder in one git repository, uploads are committed like pages */
final class GitUploadPathTest extends GitTestCase
{
	protected function serverOptions(): array
	{
		return ['git' => true, 'gitRoot' => 'data', 'pagesFolder' => 'data/pages', 'uploadsFolder' => 'data/media'];
	}

	public function testUploadsAreCommitted(): void
	{
		$this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertSame("A\tmedia/a.gif", $this->lastCommitChanges());

		$this->savePage('Gallery', '![x](/images/a.gif)');
		$this->noteAfter($this->renameImage('a.gif', 'b.gif'));
		$this->assertSame(["A\tmedia/b.gif", "D\tmedia/a.gif", "M\tpages/Gallery.md"], $this->changedFiles());

		$this->noteAfter($this->deleteImage('b.gif'));
		$this->assertSame(["D\tmedia/b.gif", "M\tpages/Gallery.md"], $this->changedFiles());
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testNoProblemIsReportedForTheSetup(): void
	{
		$offset = $this->server->logSize();
		$this->http->get('/index.php', ['action' => 'upload']);
		$this->assertStringNotContainsString('not in the git repository', $this->server->logSince($offset));
	}

	/** @return string[] the lines of lastCommitChanges(), sorted */
	private function changedFiles(): array
	{
		$lines = explode("\n", $this->lastCommitChanges());
		sort($lines);
		return $lines;
	}
}
