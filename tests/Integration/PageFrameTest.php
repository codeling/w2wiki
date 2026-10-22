<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The frame around every page (templates layout and page): document, title bar, toolbar, sidebar */
final class PageFrameTest extends AppTestCase
{
	public function testDocumentOfAPage(): void
	{
		$body = $this->http->get('/index.php/Home')->body;
		$this->assertStringStartsWith("<!doctype html>\n<html lang=\"en\">", $body);
		$this->assertStringContainsString('<meta charset="UTF-8">', $body);
		$this->assertStringContainsString('<title>Wiki: Home</title>', $body);
		$this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1" />', $body);
		$this->assertStringNotContainsString('wiki.js', $body, 'the script is only for the editor');
		$this->assertStringNotContainsString('id="drawer"', $body);
		$this->assertMatchesRegularExpression('#<body>\s*<div class="titlebar">#', $body);
		$this->assertStringEndsWith("</body>\n</html>\n", $body);
	}

	public function testDocumentOfTheEditor(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home'])->body;
		$this->assertStringContainsString('initial-scale=1, interactive-widget=resizes-content" />', $body);
		$this->assertMatchesRegularExpression('#<script src="/wiki\.js\?v=\d+"></script>#', $body);
		$this->assertStringContainsString('<body class="editor">', $body);
		$this->assertStringContainsString('<div id="drawer" class="inactive">', $body);
		$this->assertStringContainsString('<a id="drawer-control" href=""', $body);
		$this->assertStringNotContainsString('class="sidebar"', $body, 'no sidebar in the editor');
	}

	public function testToolbar(): void
	{
		$body = $this->http->get('/index.php/Home')->body;
		preg_match('#<div class="toolbar">(.*?)<form#s', $body, $matches);
		preg_match_all('#<a href="([^"]*)"><img src="[^"]*" alt="([^"]*)" title="\2" class="icon"></a>#', $matches[1], $items, PREG_SET_ORDER);
		$this->assertSame(
			[['/index.php', 'Home'], ['/index.php?action=all', 'All'], ['/index.php?action=new', 'New'], ['/index.php?action=upload&amp;page=Home', 'Upload']],
			array_map(fn($item) => [$item[1], $item[2]], $items)
		);
		$this->assertStringNotContainsString('action=logout', $body, 'no way to log out without a password');
		$this->assertStringContainsString('<form method="get" action="/index.php">', $body);
		$this->assertStringContainsString('<input type="hidden" name="action" value="search" />', $body);
	}

	public function testSidebarIsShownWhenReading(): void
	{
		$this->assertMatchesRegularExpression('#<div class="sidebar">\s*<.*</div>#s', $this->http->get('/index.php/Home')->body);
	}

	public function testTitleAndNamesAreEscaped(): void
	{
		$name = 'A<b>"x" \'y\'';  // (an "&" in a name is replaced by "-")
		$this->savePage($name, 'text');
		$body = $this->http->get($this->pageUrl($name))->body;
		$escaped = 'A&lt;b&gt;&quot;x&quot; &#039;y&#039;';
		$this->assertStringContainsString("<title>Wiki: $escaped</title>", $body);
		$this->assertStringContainsString("<span class=\"title\">$escaped</span>", $body);
		$this->assertStringNotContainsString('<b>', $body);
		$this->assertMatchesRegularExpression('#<span class="title">[^<]*</span><span class="titledate">[^<]+</span>#', $body, 'the date follows the title');
	}

	public function testLinksHereList(): void
	{
		$this->savePage('Target', 'text');
		$this->savePage('Linker', 'see [[Target]]');
		$this->savePage('Li<n>ker', 'also [[Target|this]]');
		$this->savePage('Other', 'nothing');
		$body = $this->http->get('/index.php', ['action' => 'view', 'page' => 'Target', 'linkshere' => 'true'])->body;
		$this->assertMatchesRegularExpression('#<div class="linkshere">What links here:<ul>\s*(<li>.*</li>\s*)+</ul></div>#s', $body);
		$this->assertStringContainsString('<li><a href="/index.php/Linker">Linker</a></li>', $body);
		$this->assertStringContainsString('<li><a href="/index.php/Li%3Cn%3Eker">Li&lt;n&gt;ker</a></li>', $body);
		$this->assertStringNotContainsString('>Other</a></li>', $body);
		$this->assertStringNotContainsString('class="linkshere"', $this->http->get('/index.php/Target')->body, 'only when asked for');
	}

	/** @return string the title bar of a page */
	private function titleBar(string $url): string
	{
		preg_match('#<div class="titlebar">(.*?)</div>#s', $this->http->get($url)->body, $matches);
		return $matches[1] ?? '';
	}

	public function testPageActionsInTheTitleBarOnlyForPages(): void
	{
		foreach (['edit', 'delete', 'rename'] as $action) {
			$this->assertStringContainsString("action=$action&amp;page=Home", $this->titleBar('/index.php/Home'), "on the page: $action");
		}
		// (all but the action which is being done)
		foreach (['edit', 'delete', 'rename'] as $current) {
			$bar = $this->titleBar("/index.php?action=$current&page=Home");
			foreach (array_diff(['edit', 'delete', 'rename'], [$current]) as $other) {
				$this->assertStringContainsString("action=$other&amp;page=Home", $bar, "$other while doing $current");
			}
			$this->assertStringNotContainsString("action=$current&amp;page=Home", $bar, "$current while doing $current");
		}
		$this->assertStringNotContainsString('<a ', $this->titleBar('/index.php?action=all'));
		$this->assertStringNotContainsString('<a ', $this->titleBar('/index.php?action=search&q=x'));
	}

	public function testNoteIsShownInTheMainPartOnce(): void
	{
		$body = $this->http->follow($this->savePage('Fresh', 'text'))->body;
		$this->assertMatchesRegularExpression('#<div class="main">\s*<div class="note">Created</div>#', $body);
	}
}
