<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** GIT_PUSH_ENABLED with a local bare repository as origin */
final class GitPushTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'GIT_PUSH_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['gitRemote' => true];
	}

	public function testChangesArePushedToTheRemoteRepository(): void
	{
		$this->assertSame('Initial pages', $this->server->git('log -1 --format=%s', $this->server->remoteDir()));

		$this->assertSame('Created', $this->noteAfter($this->savePage('Pushed', 'text')));
		$this->assertSame('Pushed created', $this->server->git('log -1 --format=%B', $this->server->remoteDir()));
		$this->assertSame($this->server->git('rev-parse HEAD'), $this->server->git('rev-parse main', $this->server->remoteDir()));

		$this->noteAfter($this->upload('a.gif', self::gif()));
		$this->noteAfter($this->deletePage('Pushed'));
		$this->assertSame(4, (int)$this->server->git('rev-list --count main', $this->server->remoteDir()));
		$this->assertSame('Removed Pushed', $this->server->git('log -1 --format=%B', $this->server->remoteDir()));
		$this->assertSame('', $this->server->git('status --porcelain'));
		$this->assertSame('0', $this->server->git('rev-list --count origin/main..HEAD'));
	}

	public function testMessagesAreStoredLiterallyInTheRemoteRepository(): void
	{
		$message = "it's \"quoted\" `x` $(y)\nÄ 日本";
		$this->savePageWithMessage('Home', 'changed', $message, false);
		$this->assertSame('Home: ' . $message, $this->server->git('log -1 --format=%B', $this->server->remoteDir()));
	}

	public function testNothingIsPushedWhenTheCommitFails(): void
	{
		// nothing changed, therefore "git commit" fails
		$note = $this->noteAfter($this->savePage('Home', file_get_contents($this->pageFile('Home')), false));
		$this->assertStringContainsString('Error executing git command (return value: 1)', $note);
		$this->assertSame(1, $this->commitCount());
	}

	public function testRemoteChangesWhichCannotBeMergedAreReportedAsAnError(): void
	{
		// somebody else pushed a change, so this push is rejected
		$clone = $this->server->rootDir() . '/clone';
		$this->server->git('clone -q ' . escapeshellarg($this->server->remoteDir()) . ' ' . escapeshellarg($clone), $this->server->rootDir());
		$this->server->git('-c user.name=x -c user.email=x@example.com commit -q --allow-empty -m other', $clone);
		$this->server->git('push -q origin main', $clone);

		$logSize = $this->server->logSize();
		$note = $this->noteAfter($this->savePage('Rejected', 'text'));
		$this->assertStringContainsString('Created', $note);
		$this->assertStringContainsString('Error executing git command', $note);
		$this->assertSame('Rejected created', $this->lastCommitMessage(), 'the change is still committed locally');
		$this->assertStringContainsString('W2: error executing command', $this->server->logSince($logSize));
	}
}
