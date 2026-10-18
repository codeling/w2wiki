<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** GIT_PULL_ENABLED, but the pages repository has no remote */
final class GitPullWithoutRemoteTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'GIT_PULL_ENABLED' => true, 'GIT_PULL_VIEW_INTERVAL' => 0, 'GIT_PULL_EDIT_INTERVAL' => 0];
	}

	public function testPagesAreStillShownWithAnError(): void
	{
		$logSize = $this->server->logSize();
		$response = $this->http->get($this->pageUrl('Home'));
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Error executing git command', $this->noteInResponse($response));
		$this->assertStringContainsString('W2: error executing command', $this->server->logSince($logSize));
		$this->assertSame('', $this->server->git('status --porcelain'));
	}
}
