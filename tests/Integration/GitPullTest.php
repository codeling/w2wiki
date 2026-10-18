<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppServer;
use W2\Tests\Support\GitTestCase;

/** GIT_PULL_ENABLED with a local bare repository as origin, which "somebody else" changes through a clone */
final class GitPullTest extends GitTestCase
{
	private const CONFLICT_NOTE = 'There were merge conflicts while pulling changes from the remote repository. The pull was cancelled and the wiki keeps its own version. Please resolve the conflicts in the pages repository (ask the wiki administrator).';

	protected function configOverrides(): array
	{
		// (the editor is opened to get a security token for every POST, see AppTestCase::csrfToken())
		return [
			'GIT_COMMIT_ENABLED' => true, 'GIT_PUSH_ENABLED' => true, 'GIT_PULL_ENABLED' => true,
			'GIT_PULL_VIEW_INTERVAL' => 0, 'GIT_PULL_EDIT_INTERVAL' => 0,
		];
	}

	protected function serverOptions(): array
	{
		return ['gitRemote' => true];
	}

	/** Commit a page in another clone of the remote repository, and push it */
	private function pushFromElsewhere(string $page, string $text): string
	{
		$clone = $this->server->rootDir() . '/pull-clone';
		shell_exec('rm -rf ' . escapeshellarg($clone));
		$this->server->git('clone -q ' . escapeshellarg($this->server->remoteDir()) . ' ' . escapeshellarg($clone), $this->server->rootDir());
		file_put_contents("$clone/$page.md", $text);
		$this->server->git('add -A', $clone);
		$this->server->git('-c user.name=x -c user.email=x@example.com -c commit.gpgsign=false commit -q -m ' . escapeshellarg("elsewhere: $page"), $clone);
		$this->server->git('push -q origin main', $clone);
		return $clone;
	}

	public function testViewingPullsChangesFromTheRemoteRepository(): void
	{
		$this->pushFromElsewhere('Elsewhere', 'written in another clone');
		$this->assertFileDoesNotExist($this->pageFile('Elsewhere'));

		$response = $this->http->get($this->pageUrl('Elsewhere'));
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('written in another clone', $response->body);
		$this->assertSame('', $this->noteInResponse($response));
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testViewingDoesNotPullBeforeItsIntervalHasPassed(): void
	{
		$this->overrideIntervals(3600, 3600);
		$this->server->setLastPull(time() - 3500);
		$this->pushFromElsewhere('Elsewhere', 'text');

		$this->http->get($this->pageUrl('Home'));
		$this->assertFileDoesNotExist($this->pageFile('Elsewhere'));
	}

	/** The intervals are configuration values, so the test needs another server for other values */
	private function overrideIntervals(int $view, int $edit): void
	{
		$this->server = AppServer::get(
			['GIT_PULL_VIEW_INTERVAL' => $view, 'GIT_PULL_EDIT_INTERVAL' => $edit] + $this->configOverrides(),
			$this->serverOptions()
		);
		$this->server->reset();
		$this->http = $this->newClient();
	}

	public function testEditorPullsAfterItsShortIntervalWhileViewingDoesNot(): void
	{
		$this->overrideIntervals(3600, 3600);
		$this->server->setLastPull(time() - 301);
		$this->pushFromElsewhere('Home', 'changed elsewhere');

		// (the interval of the editor is at most five minutes, whatever is configured)
		$editor = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home']);
		$this->assertStringContainsString('changed elsewhere', $editor->body);
		$this->assertSame('changed elsewhere', $this->pageText('Home'));

		// (a pull has just been attempted, so neither the editor nor the view pull again)
		$this->pushFromElsewhere('Home', 'changed again');
		$this->assertStringContainsString('changed elsewhere', $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home'])->body);
		$this->assertStringContainsString('changed elsewhere', $this->http->get($this->pageUrl('Home'))->body);
	}

	public function testViewingPullsAfterItsLongInterval(): void
	{
		$this->overrideIntervals(3600, 300);
		$this->server->setLastPull(time() - 3601);
		$this->pushFromElsewhere('Elsewhere', 'text');
		$this->assertStringContainsString('text', $this->http->get($this->pageUrl('Elsewhere'))->body);

		$this->pushFromElsewhere('Another', 'later');
		$this->http->get($this->pageUrl('Another'));
		$this->assertFileDoesNotExist($this->pageFile('Another'));
	}

	public function testLocalCommitsWhichCouldNotBePushedAreMergedAndPushed(): void
	{
		// (no pull while saving, nor in the view after it)
		$this->overrideIntervals(3600, 3600);
		$this->server->setLastPull(time());
		$this->pushFromElsewhere('Elsewhere', 'remote text');

		// the push is rejected, as the remote has changed
		$this->assertStringContainsString('Error executing git command', $this->noteAfter($this->savePage('Local', 'local text')));
		$this->assertSame('1', $this->server->git('rev-list --count origin/main..HEAD'));

		$this->server->setLastPull(time() - 3601);
		$this->http->get($this->pageUrl('Home'));
		$this->assertSame('remote text', $this->pageText('Elsewhere'));
		$this->assertSame('local text', $this->pageText('Local'));
		$this->assertSame('0', $this->server->git('rev-list --count origin/main..HEAD'));
		$this->assertSame($this->server->git('rev-parse HEAD'), $this->server->git('rev-parse main', $this->server->remoteDir()));
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testConflictingChangesAreReportedAndNothingIsChanged(): void
	{
		$this->overrideIntervals(3600, 3600);
		$this->server->setLastPull(time());
		$this->pushFromElsewhere('Home', 'remote version');
		$this->noteAfter($this->savePage('Home', 'local version', false));
		$head = $this->server->git('rev-parse HEAD');
		$this->server->setLastPull(time() - 3601);

		$response = $this->http->get($this->pageUrl('Home'));
		$this->assertSame(200, $response->status);
		$this->assertSame(self::CONFLICT_NOTE, $this->noteInResponse($response));
		$this->assertStringContainsString('local version', $response->body);
		$this->assertStringNotContainsString('<<<<<<<', $response->body);
		$this->assertSame('local version', $this->pageText('Home'));
		$this->assertSame($head, $this->server->git('rev-parse HEAD'));
		$this->assertSame('', $this->server->git('status --porcelain'));
		$this->assertSame('', $this->server->git('rev-parse -q --verify MERGE_HEAD'));
		$this->assertStringNotContainsString('remote version', $this->server->git('show HEAD:Home.md'));
	}

	public function testNoteOfTheActionWhichHasJustRedirectedIsKept(): void
	{
		$this->overrideIntervals(0, 3600);
		$this->server->setLastPull(time());
		$this->pushFromElsewhere('Home', 'remote version');
		$this->noteAfter($this->savePage('Home', 'local version', false));

		$this->server->setLastPull(time());
		$note = $this->noteAfter($this->savePage('Other', 'text'));
		$this->assertStringStartsWith('Created', $note);
		$this->assertStringContainsString('merge conflicts', $note);
	}

	public function testRemoteWhichCannotBeReachedIsReportedAndPagesStayUsable(): void
	{
		$this->server->git('remote set-url origin ' . escapeshellarg($this->server->rootDir() . '/does-not-exist.git'));

		$logSize = $this->server->logSize();
		$response = $this->http->get($this->pageUrl('Home'));
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Error executing git command', $this->noteInResponse($response));
		$this->assertStringContainsString('W2: error executing command', $this->server->logSince($logSize));
		$this->assertStringNotContainsString('does-not-exist', $response->body);
	}
}
