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
		// (this server is shared with other tests, so the clone must not stay behind)
		$clone = $this->server->rootDir() . '/pull-disabled-clone';
		shell_exec('rm -rf ' . escapeshellarg($clone));
		$this->server->git('clone -q ' . escapeshellarg($this->server->remoteDir()) . ' ' . escapeshellarg($clone), $this->server->rootDir());
		file_put_contents("$clone/Elsewhere.md", 'text');
		$this->server->git('add -A', $clone);
		$this->server->git('-c user.name=x -c user.email=x@example.com -c commit.gpgsign=false commit -q -m other', $clone);
		$this->server->git('push -q origin main', $clone);

		$this->http->get($this->pageUrl('Home'));
		$this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home']);
		$this->assertFileDoesNotExist($this->pageFile('Elsewhere'));
		$this->assertFileDoesNotExist($this->server->gitSyncFile());
		shell_exec('rm -rf ' . escapeshellarg($clone));
	}
}
