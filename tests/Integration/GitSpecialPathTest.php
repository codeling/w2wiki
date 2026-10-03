<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** The pages path is escaped in the shell command (PAGES_PATH with spaces, quotes and shell characters) */
final class GitSpecialPathTest extends GitTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true, 'GIT_PUSH_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['gitRemote' => true, 'pagesFolder' => "my pages it's \"quoted\" \$(touch pwned) `x`; &"];
	}

	public function testCommitAndPushWorkInAFolderWithSpecialCharacters(): void
	{
		$this->assertStringContainsString(' ', $this->server->pagesDir());
		$this->assertStringContainsString("'", $this->server->pagesDir());
		$this->assertStringContainsString('"', $this->server->pagesDir());

		$this->assertSame('Created', $this->noteAfter($this->savePage('Special', 'text')));
		$this->assertSame('Special created', $this->lastCommitMessage());
		$this->assertSame('Special created', $this->server->git('log -1 --format=%B', $this->server->remoteDir()));

		$this->assertSame('File \'a.gif\' uploaded!', trim(strip_tags(html_entity_decode(explode(' Use ', $this->noteAfter($this->upload('a.gif', self::gif())))[0], ENT_QUOTES))));
		$this->assertSame("File 'a.gif' uploaded!", $this->lastCommitMessage());
		$this->assertSame(3, $this->commitCount());
		$this->assertSame([], glob($this->server->rootDir() . '/pwned*') ?: []);
		$this->assertSame([], glob($this->server->pagesDir() . '/pwned*') ?: []);
	}
}
