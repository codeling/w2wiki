<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** The pages folder is a repository, but GIT_COMMIT_ENABLED is off: there is no history of the pages */
final class GitHistoryDisabledTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return [];
	}

	public function testThereIsNoHistoryIcon(): void
	{
		foreach (['/index.php/Home', '/index.php?action=all', '/index.php?action=edit&page=Home'] as $url) {
			$body = $this->http->get($url)->body;
			$this->assertStringContainsString('icon-rename', $body, "$url has the other page actions");
			$this->assertStringNotContainsString('icon-history', $body, $url);
			$this->assertStringNotContainsString('action=history', $body, $url);
		}
	}

	public function testTheHistoryActionsAreRefused(): void
	{
		$hash = $this->server->git('rev-parse HEAD');
		foreach (['history', 'version', 'diff', 'restore'] as $action) {
			$response = $this->http->get('/index.php', ['action' => $action, 'page' => 'Home', 'rev' => $hash]);
			$this->assertSame(404, $response->status, $action);
			$this->assertStringContainsString('The history of pages is only available if the changes are committed to git (GIT_COMMIT_ENABLED in config.php).', $response->body, $action);
			$this->assertStringNotContainsString('<form id="edit"', $response->body, $action);
			$this->assertStringNotContainsString('Initial pages', $response->body, $action);
		}
	}
}
