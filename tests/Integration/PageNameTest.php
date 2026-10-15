<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** Page names: URL encoding, restrictions, renaming and deleting */
final class PageNameTest extends AppTestCase
{
	public static function pageNames(): array
	{
		// name => [name as entered, resulting file name, expected URL of the page]
		return [
			'plus sign' => ['A+B', 'A+B', '/index.php/A%2BB'],
			'space' => ['C D', 'C D', '/index.php/C%20D'],
			'percent sign' => ['100%', '100%', '/index.php/100%25'],
			'percent encoded text' => ['x%41y', 'x%41y', '/index.php/x%2541y'],
			'replaced characters' => ['Q&A?', 'Q-A?', '/index.php/Q-A%3F'],
			'subfolder' => ['sub/page', 'sub/page', '/index.php/sub/page'],
		];
	}

	#[DataProvider('pageNames')]
	public function testPageNamesSurviveARoundTrip(string $name, string $fileName, string $url): void
	{
		$response = $this->savePage($name, 'text of ' . $name);
		$this->assertSame(303, $response->status);
		$this->assertSame($url, $response->location());
		$this->assertFileExists($this->server->pagesDir() . "/$fileName.md");

		$page = $this->http->follow($response);
		$this->assertSame(200, $page->status);
		$this->assertStringContainsString('<span class="title">' . htmlspecialchars($fileName, ENT_QUOTES), $page->body);
		$this->assertStringContainsString('text of', $page->body);
		$this->assertStringNotContainsString('does not exist', $page->body);
	}

	public function testPageNamesAreNotDecodedTwice(): void
	{
		$this->savePage('x%41y', 'percent');
		$this->savePage('xAy', 'letter');
		$this->assertStringContainsString('percent', $this->http->get($this->pageUrl('x%41y'))->body);
		$this->assertStringContainsString('letter', $this->http->get($this->pageUrl('xAy'))->body);
	}

	public function testOldLinksWithPlusForSpaceAreRedirected(): void
	{
		$this->savePage('C D', 'text');
		$response = $this->http->get('/index.php/C+D');
		$this->assertSame(301, $response->status);
		$this->assertSame('/index.php/C%20D', $response->location());
	}

	public function testPageLinksEncodeSpacesAsPercent20(): void
	{
		$body = $this->http->get('/index.php')->body;
		$this->assertStringContainsString('href="/index.php/Markdown%20Syntax"', $body);
		$this->assertStringNotContainsString('Markdown+Syntax', $body);
	}

	public static function invalidNames(): array
	{
		return [
			'uploads folder' => ['images/x'],
			'hidden page' => ['.hidden'],
			'hidden folder' => ['sub/.git/x'],
			'absolute path' => ['/abs'],
			'empty segment' => ['a//b'],
		];
	}

	#[DataProvider('invalidNames')]
	public function testInvalidPageNamesAreRefused(string $name): void
	{
		$before = $this->server->pageFiles();
		$note = $this->noteInResponse($this->savePage($name, 'text'));
		$this->assertStringContainsString('invalid page name', $note);
		$this->assertSame($before, $this->server->pageFiles());
	}

	#[DataProvider('invalidNames')]
	public function testInvalidPageNamesAreRefusedWhenSavingWithoutTheNewFlag(string $name): void
	{
		// the client decides whether a page is new, so the name has to be checked either way
		$before = $this->server->pageFiles();
		$note = $this->noteInResponse($this->savePage($name, 'text', false));
		$this->assertStringContainsString('invalid page name', $note);
		$this->assertSame($before, $this->server->pageFiles());
		$this->assertFileDoesNotExist($this->server->pagesDir() . '/.hidden/evil.md');
	}

	public function testExistingPagesCanStillBeEdited(): void
	{
		$this->assertSame(303, $this->savePage('Home', 'edited', false)->status);
		$this->assertSame('edited', $this->pageText('Home'));
	}

	public function testExistingPagesAreNotOverwrittenWhenCreatingPages(): void
	{
		$note = $this->noteInResponse($this->savePage('Home', 'overwritten'));
		$this->assertStringContainsString('already exists', $note);
		$this->assertStringContainsString('Welcome to W2', $this->pageText('Home'));
	}

	public function testPageCanBeRenamedAndLinksAreUpdated(): void
	{
		$this->savePage('Old', 'old');
		$this->savePage('Linker', 'See [[Old]] and [[Old|the old page]] and [[Old#part]] and [[Other]].');
		$note = $this->noteAfter($this->postAction('renamed', ['oldPageName' => 'Old', 'newName' => 'New Name']));
		$this->assertStringContainsString('Renamed', $note);
		$this->assertFileDoesNotExist($this->pageFile('Old'));
		$this->assertFileExists($this->pageFile('New Name'));
		$this->assertSame(
			'See [[New Name]] and [[New Name|the old page]] and [[New Name#part]] and [[Other]].',
			$this->pageText('Linker')
		);
	}

	public function testRenamingNeverOverwritesAnotherPage(): void
	{
		$this->savePage('Source', 'source text');
		$note = $this->noteAfter($this->postAction('renamed', ['oldPageName' => 'Source', 'newName' => 'Home']));
		$this->assertStringContainsString('Error renaming', $note);
		$this->assertStringContainsString('Welcome to W2', $this->pageText('Home'));
		$this->assertFileExists($this->pageFile('Source'));
	}

	public function testPagesCannotBeRenamedIntoTheUploadsFolder(): void
	{
		$this->savePage('Source', 'source text');
		$note = $this->noteAfter($this->postAction('renamed', ['oldPageName' => 'Source', 'newName' => 'images/Source']));
		$this->assertStringContainsString('Error renaming', $note);
		$this->assertFileDoesNotExist($this->server->imagesDir() . '/Source.md');
	}

	public function testRenamingTreatsSpecialCharactersLiterally(): void
	{
		$this->savePage('A(B*', 'text');
		$this->savePage('Linker', 'See [[A(B*]] and [[A(B*|x]] and [[AB]].');
		$this->postAction('renamed', ['oldPageName' => 'A(B*', 'newName' => 'C$1\\2']);
		$this->assertFileExists($this->pageFile('C$1-2'), 'backslashes in names are replaced by "-"');
		$this->assertSame('See [[C$1-2]] and [[C$1-2|x]] and [[AB]].', $this->pageText('Linker'));
	}

	public function testDeletingPageRemovesOnlyLinksToThatPage(): void
	{
		$this->savePage('Victim', 'text');
		$this->savePage('Linker', 'a [[Victim|first]] and [[Other|second]] and [[Victim]] end');
		$note = $this->noteAfter($this->postAction('deleted', ['oldPageName' => 'Victim']));
		$this->assertStringContainsString('Removed', $note);
		$this->assertFileDoesNotExist($this->pageFile('Victim'));
		$this->assertSame('a  and [[Other|second]] and  end', $this->pageText('Linker'));
	}

	public function testWhatLinksHereListsPagesEscapedAndLiterally(): void
	{
		$this->savePage('A(B*', 'text');
		$this->savePage('Linker', 'see [[A(B*]]');
		$body = $this->http->get('/index.php', ['action' => 'view', 'page' => 'A(B*', 'linkshere' => 'true'])->body;
		$this->assertStringContainsString('href="/index.php/Linker"', $body);
	}

	public function testMessagesDoNotRevealServerPaths(): void
	{
		unlink($this->pageFile('_sidebar'));
		$body = $this->http->get('/index.php')->body;
		$this->assertStringContainsString('Sidebar file could not be found', $body);
		$this->assertStringNotContainsString($this->server->rootDir(), $body);
	}
}
