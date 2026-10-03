<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** GIT_COMMIT_ENABLED, but the pages folder is not a git repository */
final class GitNoRepositoryTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true];
	}

	public function testPageIsSavedAndAGenericErrorIsShown(): void
	{
		$logSize = $this->server->logSize();
		$response = $this->savePage('Plain', 'text');
		$note = $this->noteAfter($response);

		$this->assertSame('text', $this->pageText('Plain'));
		$this->assertStringStartsWith('Created', $note);
		$this->assertStringContainsString("Error executing git command (return value: 128); see the web server's error log for details.", $note);

		foreach ([$this->server->pagesDir(), $this->server->rootDir(), 'git add', 'not a git repository', 'fatal'] as $secret) {
			$this->assertStringNotContainsString($secret, $this->http->follow($response)->body);
		}
		$log = $this->server->logSince($logSize);
		$this->assertStringContainsString('W2: error executing command', $log);
		$this->assertStringContainsString('not a git repository', $log);
		$this->assertDirectoryDoesNotExist($this->server->pagesDir() . '/.git');
	}

	public function testUploadAndImageChangesShowTheErrorToo(): void
	{
		$this->assertStringContainsString('Error executing git command', $this->noteAfter($this->upload('a.gif', self::gif())));
		$this->assertStringContainsString('Error executing git command', $this->noteAfter($this->renameImage('a.gif', 'b.gif')));
		$this->assertStringContainsString('Error executing git command', $this->noteAfter($this->deleteImage('b.gif')));
		$this->assertSame([], $this->uploadedFiles());
	}
}
