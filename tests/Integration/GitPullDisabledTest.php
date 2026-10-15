<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** GIT_PULL_ENABLED is off (the default): the wiki never fetches changes from the remote repository */
final class GitPullDisabledTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'GIT_PUSH_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['gitRemote' => true];
	}

	public function testRemoteChangesAreNotPulled(): void
	{
		$clone = $this->server->rootDir() . '/clone';
		$this->server->git('clone -q ' . escapeshellarg($this->server->remoteDir()) . ' ' . escapeshellarg($clone), $this->server->rootDir());
		file_put_contents("$clone/Elsewhere.md", 'text');
		$this->server->git('add -A', $clone);
		$this->server->git('-c user.name=x -c user.email=x@example.com -c commit.gpgsign=false commit -q -m other', $clone);
		$this->server->git('push -q origin main', $clone);

		$this->http->get($this->pageUrl('Home'));
		$this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home']);
		$this->assertFileDoesNotExist($this->pageFile('Elsewhere'));
		$this->assertFileDoesNotExist($this->server->gitSyncFile());
	}
}
