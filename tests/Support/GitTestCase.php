<?php

namespace W2\Tests\Support;

/**
 * Base class for tests of the git integration (GIT_COMMIT_ENABLED, GIT_PUSH_ENABLED): the
 * pages folder is a git repository, which is reset to the initial pages before every test.
 */
abstract class GitTestCase extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['GIT_COMMIT_ENABLED' => true];
	}

	protected function serverOptions(): array
	{
		return ['git' => true];
	}

	protected function savePageWithMessage(string $name, string $text, string $message, bool $isNew = true): HttpResponse
	{
		return $this->postAction('save', [
			'page' => $name, 'isNew' => $isNew ? 'true' : '', 'newText' => $text, 'gitmsg' => $message,
		]);
	}

	protected function renamePage(string $old, string $new): HttpResponse
	{
		return $this->postAction('renamed', ['oldPageName' => $old, 'newName' => $new]);
	}

	protected function deletePage(string $name): HttpResponse
	{
		return $this->postAction('deleted', ['oldPageName' => $name]);
	}

	protected function commitCount(): int
	{
		return (int)$this->server->git('rev-list --count HEAD');
	}

	/** Message of the latest commit */
	protected function lastCommitMessage(): string
	{
		return $this->server->git('log -1 --format=%B');
	}

	/** Files changed by the latest commit, as "status<TAB>path" lines */
	protected function lastCommitChanges(): string
	{
		return $this->server->git('show --no-renames --name-status --format= HEAD');
	}
}
