<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** GIT_PUSH_ENABLED in a repository without a remote: errors only show a generic message */
final class GitPushWithoutRemoteTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'GIT_PUSH_ENABLED' => true];
	}

	public function testFailingPushShowsGenericErrorAndLogsDetails(): void
	{
		$logSize = $this->server->logSize();
		$response = $this->savePage('Local', 'text');
		$note = $this->noteAfter($response);

		$this->assertStringStartsWith('Created', $note);
		$this->assertMatchesRegularExpression(
			"/Error executing git command \\(return value: \\d+\\); see the web server's error log for details\\./",
			$note
		);
		$this->assertSame('Local created', $this->lastCommitMessage(), 'the commit is made, only the push failed');

		// neither the command nor its output nor absolute paths are shown to the user
		foreach ([$this->server->pagesDir(), $this->server->rootDir(), sys_get_temp_dir(), 'git push', 'git commit', 'fatal', 'cd '] as $secret) {
			$this->assertStringNotContainsString($secret, $note);
			$this->assertStringNotContainsString($secret, $this->http->follow($response)->body);
		}

		$log = $this->server->logSince($logSize);
		$this->assertStringContainsString('W2: error executing command', $log);
		$this->assertStringContainsString('git push', $log);
		$this->assertStringContainsString($this->server->pagesDir(), $log);
	}
}
