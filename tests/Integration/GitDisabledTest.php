<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** The pages folder is a repository, but GIT_COMMIT_ENABLED is off (the default) */
final class GitDisabledTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return [];
	}

	public function testNothingIsCommitted(): void
	{
		$this->assertSame('Created', $this->noteAfter($this->savePage('Fresh', 'text')));
		$this->savePageWithMessage('Home', 'changed', 'a message', false);
		$this->renamePage('Fresh', 'Renamed');
		$this->deletePage('Renamed');
		$this->upload('a.gif', self::gif());
		$this->renameImage('a.gif', 'b.gif');
		$this->deleteImage('b.gif');

		$this->assertSame(1, $this->commitCount());
		$this->assertSame(" M Home.md", $this->server->git('status --porcelain'));
	}

	public function testNoErrorIsShown(): void
	{
		$note = $this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertStringNotContainsString('git', $note);
	}

	public function testEditorHasNoMessageField(): void
	{
		foreach ([['action' => 'edit', 'page' => 'Home'], ['action' => 'new']] as $query) {
			$this->assertStringNotContainsString('gitmsg', $this->http->get('/index.php', $query)->body);
		}
		$this->assertStringNotContainsString('gitmsg', $this->http->get($this->pageUrl('Does not exist'))->body);
	}
}
