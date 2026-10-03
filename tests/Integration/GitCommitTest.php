<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\GitTestCase;

/** GIT_COMMIT_ENABLED: every change is committed to the repository in the pages folder */
final class GitCommitTest extends GitTestCase
{
	public function testTestRepositoryStartsWithOneCommit(): void
	{
		$this->assertSame(1, $this->commitCount());
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testNewPageIsCommitted(): void
	{
		$note = $this->noteAfter($this->savePage('Fresh', 'text'));
		$this->assertSame('Created', $note);
		$this->assertSame(2, $this->commitCount());
		$this->assertSame('Fresh created', $this->lastCommitMessage());
		$this->assertSame("A\tFresh.md", $this->lastCommitChanges());
		$this->assertSame('', $this->server->git('status --porcelain'));
	}

	public function testChangedPageIsCommitted(): void
	{
		$this->noteAfter($this->savePage('Home', 'changed text', false));
		$this->assertSame(2, $this->commitCount());
		$this->assertSame('Home changed', $this->lastCommitMessage());
		$this->assertSame("M\tHome.md", $this->lastCommitChanges());
	}

	public function testMessageOfEditorIsPartOfTheCommitMessage(): void
	{
		$this->savePageWithMessage('Home', 'changed text', 'fixed a typo', false);
		$this->assertSame('Home: fixed a typo', $this->lastCommitMessage());
		$this->savePageWithMessage('Other', 'text', 'first version');
		$this->assertSame('Other: first version', $this->lastCommitMessage());
	}

	public function testPageInSubfolderIsCommitted(): void
	{
		$this->savePage('sub/folder/Deep', 'text');
		$this->assertSame('sub/folder/Deep created', $this->lastCommitMessage());
		$this->assertSame("A\tsub/folder/Deep.md", $this->lastCommitChanges());
	}

	public function testRenamedPageIsCommittedWithUpdatedLinks(): void
	{
		$this->savePage('Old', 'text');
		$this->savePage('Linking', 'see [[Old]]');
		$note = $this->noteAfter($this->renamePage('Old', 'New'));
		$this->assertStringContainsString('Renamed Old to New', $note);
		$this->assertSame(4, $this->commitCount());
		$this->assertStringStartsWith('Renamed Old to New', $this->lastCommitMessage());
		$this->assertSame("M\tLinking.md\nD\tOld.md\nA\tNew.md", $this->sortedLines($this->lastCommitChanges(), ['M', 'D', 'A']));
		$this->assertSame('see [[New]]', $this->pageText('Linking'));
	}

	public function testDeletedPageIsCommitted(): void
	{
		$this->savePage('Victim', 'text');
		$this->deletePage('Victim');
		$this->assertSame(3, $this->commitCount());
		$this->assertSame('Removed Victim', $this->lastCommitMessage());
		$this->assertSame("D\tVictim.md", $this->lastCommitChanges());
	}

	public function testFailedRenameAndDeleteAreNotCommitted(): void
	{
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renamePage('Home', 'MarkdownSyntax')));
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renamePage('Home', 'images/Home')));
		$this->assertStringContainsString('Error renaming', $this->noteAfter($this->renamePage('Missing', 'Other')));
		$this->assertStringContainsString('Error deleting', $this->noteAfter($this->deletePage('Missing')));
		$this->assertFileDoesNotExist($this->pageFile('Other'));
		$this->assertSame(1, $this->commitCount());
	}

	public function testUploadedImageIsCommitted(): void
	{
		$note = $this->noteAfter($this->upload('a.gif', self::gif()));
		$this->assertStringContainsString("File 'a.gif' uploaded", $note);
		$this->assertSame(2, $this->commitCount());
		$this->assertSame("File 'a.gif' uploaded!", $this->lastCommitMessage());
		$this->assertSame("A\timages/a.gif", $this->lastCommitChanges());
	}

	public function testRenamedImageIsCommitted(): void
	{
		$this->upload('a.gif', self::gif());
		$this->noteAfter($this->renameImage('a.gif', 'b.gif'));
		$this->assertSame(3, $this->commitCount());
		$this->assertSame('Image renamed: a.gif to b.gif', $this->lastCommitMessage());
		$this->assertSame("D\timages/a.gif\nA\timages/b.gif", $this->sortedLines($this->lastCommitChanges(), ['D', 'A']));
	}

	public function testDeletedImageIsCommitted(): void
	{
		$this->upload('a.gif', self::gif());
		$this->noteAfter($this->deleteImage('a.gif'));
		$this->assertSame(3, $this->commitCount());
		$this->assertSame('Image deleted: a.gif', $this->lastCommitMessage());
		$this->assertSame("D\timages/a.gif", $this->lastCommitChanges());
	}

	public function testImageChangesListUpdatedPagesInThePlainTextCommitMessage(): void
	{
		$this->upload("a'b.gif", self::gif());
		$this->savePage("Gall'ery", '![x](/images/a\'b.gif)');
		$this->savePage('Other', '![y](/images/a\'b.gif)');
		$this->deleteImage("a'b.gif");
		$this->assertSame("Image deleted: a'b.gif (Updated images in the following pages: Gall'ery, Other)", $this->lastCommitMessage());
		$this->assertStringContainsString("M\tGall'ery.md", $this->lastCommitChanges());
		$this->assertSame('', $this->server->git('status --porcelain'));

		$this->upload('c.gif', self::gif());
		$this->savePage('Third', '![z](/images/c.gif)');
		$this->renameImage('c.gif', "d's.gif");
		$this->assertSame("Image renamed: c.gif to d's.gif (Updated images in the following pages: Third)", $this->lastCommitMessage());
	}

	// --- shell escaping ------------------------------------------------------------------

	public static function commitMessages(): array
	{
		return [
			'double quotes' => ['say "hello"'],
			'single quotes' => ["it's a 'test'"],
			'command substitution' => ['$(touch pwned-1)'],
			'backticks' => ['`touch pwned-2`'],
			'variables' => ['$HOME ${PATH} %s %d'],
			'semicolon and pipes' => ['a; touch pwned-3 | b && touch pwned-4 || c'],
			'redirection' => ['x > pwned-5 < /dev/null'],
			'backslashes' => ['C:\\dir\\n \\" \\\\'],
			'newlines' => ["first line\nsecond line\n\nthird paragraph"],
			'non-ASCII text' => ['Änderung: 日本語, ünï, emoji 🎉'],
			'leading dash' => ['--amend -m x'],
		];
	}

	#[DataProvider('commitMessages')]
	public function testMessageOfEditorIsStoredLiterally(string $message): void
	{
		$this->noteAfter($this->savePageWithMessage('Home', 'changed', $message, false));
		$this->assertSame(2, $this->commitCount());
		$this->assertSame('Home: ' . $message, $this->lastCommitMessage());
		$this->assertNothingWasExecuted();
	}

	#[DataProvider('commitMessages')]
	public function testPageNamesInCommitMessagesAreStoredLiterally(string $name): void
	{
		// characters which are replaced in page names or have a special meaning in links are removed
		$page = trim(str_replace(['|', '#', '&', ':', '\\', '..', '~', '>', '<'], '', $name));
		if ($page === '' || str_contains($page, "\n") || str_contains($page, '/')) {
			$this->markTestSkipped('not a usable page name');
		}
		$this->noteAfter($this->savePage($page, 'text'));
		$this->assertSame(2, $this->commitCount(), "page $page");
		$this->assertSame($page . ' created', $this->lastCommitMessage());
		$this->assertNothingWasExecuted();
	}

	public function testUploadedFileNamesInCommitMessagesAreStoredLiterally(): void
	{
		$this->upload('$(touch pwned-1).gif', self::gif());
		$this->upload("it's.gif", self::gif());
		$this->assertSame(3, $this->commitCount());
		$this->assertSame("File 'it's.gif' uploaded!", $this->lastCommitMessage());
		$this->assertNothingWasExecuted();
	}

	public function testRenamingToSpecialNamesIsStoredLiterally(): void
	{
		$this->savePage('Old', 'text');
		$this->renamePage('Old', 'New `touch pwned-2` $(touch pwned-3)');
		$this->assertSame('Renamed Old to New `touch pwned-2` $(touch pwned-3)', $this->lastCommitMessage());
		$this->assertNothingWasExecuted();
	}

	// --- editor ------------------------------------------------------------------------------

	public function testEditorShowsMessageFieldForEditingAndForNewPages(): void
	{
		$edit = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home'])->body;
		$this->assertStringContainsString('<input type="text" id="gitmsg" name="gitmsg" value="" />', $edit);

		$new = $this->http->get('/index.php', ['action' => 'new'])->body;
		$this->assertStringContainsString('name="gitmsg"', $new);

		// a link to a page which doesn't exist yet leads to the form for a new page
		$missing = $this->http->get($this->pageUrl('Does not exist'))->body;
		$this->assertStringContainsString('Creating new page', $missing);
		$this->assertStringContainsString('name="gitmsg"', $missing);
	}

	public function testMessageFieldIsOnlyShownOnTheEditorPages(): void
	{
		$this->assertStringNotContainsString('name="gitmsg"', $this->http->get($this->pageUrl('Home'))->body);
	}

	public function testSavingAnExistingPageAsNewIsRefusedWithoutCommit(): void
	{
		$response = $this->savePageWithMessage('Home', 'overwritten', 'my message');
		$this->assertStringContainsString('already exists', $this->noteInResponse($response));
		$this->assertSame(1, $this->commitCount());
		$this->assertStringNotContainsString('overwritten', $this->pageText('Home'));
	}

	public function testMessageIsKeptWhenSavingFails(): void
	{
		$response = $this->savePageWithMessage('Home', 'my new text', 'my message');
		// no redirect: the form is shown again, with the error
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('already exists', $response->body);
		$this->assertStringContainsString('<input type="text" id="gitmsg" name="gitmsg" value="my message" />', $response->body);
		$this->assertStringContainsString('my new text</textarea>', $response->body);
		$this->assertStringContainsString('name="page" value="Home"', $response->body);
		$this->assertSame(1, $this->commitCount());

		// the error is shown once
		$this->assertStringNotContainsString('already exists', $this->http->get($this->pageUrl('Home'))->body);
	}

	/** No command from the text of the messages has been executed (it would create these files) */
	private function assertNothingWasExecuted(): void
	{
		$this->assertSame([], glob($this->server->pagesDir() . '/pwned*') ?: [], 'a command was executed');
		$this->assertSame([], glob($this->server->rootDir() . '/pwned*') ?: [], 'a command was executed');
	}

	/** Lines of a list of changes, sorted by status in the given order, and by name */
	private function sortedLines(string $changes, array $order): string
	{
		$lines = explode("\n", $changes);
		usort($lines, fn($a, $b) => [array_search($a[0], $order), $a] <=> [array_search($b[0], $order), $b]);
		return implode("\n", $lines);
	}
}
