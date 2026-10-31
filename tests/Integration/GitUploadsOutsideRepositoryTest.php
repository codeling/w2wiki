<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** An uploads folder outside the git repository of the pages is reported in the log: its files are not committed */
final class GitUploadsOutsideRepositoryTest extends GitTestCase
{
	protected function serverOptions(): array
	{
		return ['git' => true, 'uploadsFolder' => 'media'];
	}

	public function testTheSetupIsReported(): void
	{
		$offset = $this->server->logSize();
		$this->http->get('/index.php', ['action' => 'upload']);
		$this->assertStringContainsString('(UPLOAD_PATH) is not in the git repository of the pages', $this->server->logSince($offset));
	}

	public function testUploadsStillWork(): void
	{
		$this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertSame(self::gif(), $this->http->get('/file.php', ['name' => 'a.gif'])->body);
		$this->assertSame(1, $this->commitCount(), 'nothing to commit in the repository of the pages');
	}
}
