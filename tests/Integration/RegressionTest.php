<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** Main views and workflows keep working (and don't log PHP errors, see AppTestCase::tearDown) */
final class RegressionTest extends AppTestCase
{
	public static function views(): array
	{
		return [
			'home' => ['/index.php'],
			'page' => ['/index.php/MarkdownSyntax'],
			'page by query' => ['/index.php?action=view&page=Home'],
			'all pages' => ['/index.php?action=all'],
			'all pages by size' => ['/index.php?action=all&sortBy=size'],
			'all pages by date' => ['/index.php?action=all&sortBy=recent'],
			'new page' => ['/index.php?action=new'],
			'upload' => ['/index.php?action=upload'],
			'upload by size' => ['/index.php?action=upload&sortBy=size'],
			'edit' => ['/index.php?action=edit&page=Home'],
			'rename' => ['/index.php?action=rename&page=Home'],
			'delete' => ['/index.php?action=delete&page=Home'],
			'links here' => ['/index.php?action=view&page=Home&linkshere=true'],
			'missing page' => ['/index.php/Nonexistent'],
			'search' => ['/index.php?action=search&q=home'],
			'empty search' => ['/index.php?action=search&q='],
			'image rename' => ['/index.php?action=imgRename&imgName=a.gif&prevpage=Home'],
			'image delete' => ['/index.php?action=imgDelete&imgName=a.gif&prevpage=Home'],
		];
	}

	#[DataProvider('views')]
	public function testViewsAreShown(string $url): void
	{
		$response = $this->http->get($url);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<div class="main">', $response->body);
		$this->assertStringContainsString('</html>', $response->body);
	}

	public function testSidebarIsShownOnAllPages(): void
	{
		$body = $this->http->get('/index.php/MarkdownSyntax')->body;
		$this->assertStringContainsString('<div class="sidebar">', $body);
		$this->assertStringContainsString('href="/index.php/Home"', $body);
	}

	public function testMarkdownIsRendered(): void
	{
		$this->savePage('Md', "# Title\n\nSome *emphasis*, **bold** and `code`.\n\n- one\n- two\n");
		$body = $this->http->get($this->pageUrl('Md'))->body;
		foreach (['<h1>', '<em>emphasis</em>', '<strong>bold</strong>', '<code>code</code>', '<li>one</li>'] as $html) {
			$this->assertStringContainsString($html, $body);
		}
	}

	public function testPageLinksShowWhetherPagesExist(): void
	{
		$this->savePage('Links', '[[Home]] and [[Missing Page]]');
		$body = $this->http->get($this->pageUrl('Links'))->body;
		$this->assertStringContainsString('<a href="/index.php/Home">Home</a>', $body);
		$this->assertStringContainsString('<a href="/index.php/Missing%20Page" class="noexist">Missing Page</a>', $body);
	}

	public function testPagesCanBeEditedAndTheChangeIsShown(): void
	{
		$note = $this->noteAfter($this->savePage('Home', 'edited home page', false));
		$this->assertSame('Saved', $note);
		$this->assertStringContainsString('edited home page', $this->http->get('/index.php')->body);
	}

	public function testMissingPageOffersToCreateItAndHintsAtSimilarPages(): void
	{
		$this->savePage('Similar Page', 'x');
		$body = $this->http->get($this->pageUrl('Simular Page'))->body;
		$this->assertStringContainsString('Creating new page', $body);
		$this->assertStringContainsString('Found similar page', $body);
		$this->assertStringContainsString('href="/index.php/Similar%20Page"', $body);
	}

	public function testPagesInSubfoldersAreFoundByTheirName(): void
	{
		$this->savePage('folder/Deep', 'deep text');
		$response = $this->http->get($this->pageUrl('Deep'));
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php/folder/Deep', $response->location());
		$this->assertStringContainsString('redirected instead', $this->http->follow($response)->body);
	}

	public function testSearchFindsPagesByNameAndContent(): void
	{
		$this->savePage('Findable', 'contains a rare-word');
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => 'rare-word'])->body;
		$this->assertStringContainsString('href="/index.php/Findable"', $body);
		$this->assertStringContainsString('1 matches', $body);
		$body = $this->http->get('/index.php', ['action' => 'search', 'q' => 'findab'])->body;
		$this->assertStringContainsString('href="/index.php/Findable"', $body);
	}

	public function testWhatLinksHere(): void
	{
		$this->savePage('Linker', 'see [[Home]]');
		$body = $this->http->get('/index.php', ['action' => 'view', 'page' => 'Home', 'linkshere' => 'true'])->body;
		$this->assertStringContainsString('What links here:', $body);
		$this->assertStringContainsString('href="/index.php/Linker"', $body);
	}

	/** wiki.js warns about leaving the editor with unsaved changes, also for pages which don't exist yet */
	public function testEditorsLoadTheUnsavedChangesWarning(): void
	{
		$pages = [
			'new page by name' => $this->http->get($this->pageUrl('Not yet existing')),
			'new page' => $this->http->get('/index.php', ['action' => 'new']),
			'existing page' => $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home']),
		];
		foreach ($pages as $description => $response) {
			$this->assertStringContainsString('<script src="/wiki.js"></script>', $response->body, $description);
			$this->assertStringContainsString('id="drawer"', $response->body, "$description: formatting help");
		}
		$this->assertStringNotContainsString('wiki.js', $this->http->get('/index.php')->body, 'not needed when viewing');
		$this->assertStringNotContainsString('id="drawer"', $this->http->get('/index.php')->body);
	}

	/**
	 * wiki.js (which assumes the editor, its formatting help and the save button exist) must be loaded exactly
	 * on the pages with an editor, and all of them must have the formatting help
	 */
	public function testEditorScriptFormattingHelpAndEditorAppearTogether(): void
	{
		$this->savePage('Existing', 'text');
		$urls = [
			'/index.php', '/index.php?action=all', '/index.php?action=upload', '/index.php?action=search&q=a',
			'/index.php?action=rename&page=Existing', '/index.php?action=delete&page=Existing',
			'/index.php?action=imgRename&imgName=a.gif&prevpage=Home', '/index.php/Existing', '/index.php/Missing',
			'/index.php?action=new', '/index.php?action=edit&page=Existing',
		];
		foreach ($urls as $url) {
			$body = $this->http->get($url)->body;
			$hasEditor = str_contains($body, 'id="text"');
			$hasSave = str_contains($body, 'id="save"');
			$this->assertSame($hasEditor, $hasSave, $url);
			$this->assertSame($hasEditor, str_contains($body, '/wiki.js'), "$url: script");
			$this->assertSame($hasEditor, str_contains($body, 'id="drawer"'), "$url: formatting help");
		}
		$this->assertStringContainsString('id="text"', $this->http->get('/index.php?action=new')->body);
	}

	public function testResponsesAreNotCached(): void
	{
		$this->assertSame('no-store', $this->http->get('/index.php')->header('cache-control'));
	}
}
