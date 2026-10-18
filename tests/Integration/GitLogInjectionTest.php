<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** Values from requests are written to the error log without control characters, so they cannot forge entries */
final class GitLogInjectionTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true];
	}

	public function testLineBreaksInCommitMessagesCannotForgeLogEntries(): void
	{
		$logSize = $this->server->logSize();
		// "git commit" fails, and the failing command (with the message) is logged
		$response = $this->whileCommitsFail(fn() => $this->postAction('save', [
			'page' => 'Home', 'isNew' => '', 'newText' => 'changed text',
			'gitmsg' => "first\nW2: forged log entry\r\nsecond",
		]));
		$this->assertSame(303, $response->status);

		$log = $this->server->logSince($logSize);
		$this->assertStringContainsString('W2: error executing command', $log);
		$this->assertStringContainsString('\x0aW2: forged log entry\x0d\x0a', $log, 'control characters are shown escaped');
		foreach (preg_split('/\r?\n/', $log) as $line) {
			$this->assertStringStartsNotWith('W2: forged', $line);
			$this->assertStringNotContainsString("\r", $line);
		}
	}
}
