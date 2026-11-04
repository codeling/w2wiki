<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** The history of a page when the repository is a folder above the pages folder (pages in data/pages, the repository is data) */
final class GitHistoryParentRepositoryTest extends GitTestCase
{
	protected function serverOptions(): array
	{
		return ['git' => true, 'gitRoot' => 'data', 'pagesFolder' => 'data/pages'];
	}

	public function testVersionsAreFoundRelativeToThePagesFolder(): void
	{
		$this->savePage('sub/Nested', 'nested one');
		$this->savePage('sub/Nested', 'nested two', false);
		$this->renamePage('sub/Nested', 'Moved');
		$hashes = explode("\n", $this->server->git('log --format=%H', $this->server->gitDir()));

		$history = $this->http->get('/index.php', ['action' => 'history', 'page' => 'Moved'])->body;
		$this->assertStringContainsString('Versions 1 to 3 of 3', $history);
		$this->assertStringContainsString('<p>nested one</p>', $this->http->get('/index.php', ['action' => 'version', 'page' => 'Moved', 'rev' => $hashes[2]])->body);
		$this->assertStringContainsString('<span class="added">+ nested two</span>', $this->http->get('/index.php', ['action' => 'diff', 'page' => 'Moved', 'rev' => $hashes[1]])->body);
	}
}
