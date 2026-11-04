<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * The keyboard shortcuts (setupShortcuts() in wiki.js, tests/Browser/shortcuts.spec.js): the links and the search box
 * which they use name their keys in data-shortcut, and every page has the list of them (shortcuts.php) and the script.
 */
final class KeyboardShortcutsTest extends AppTestCase
{
	/** @return array<string, string> the keys of the shortcuts of the page => the tag of the element with them */
	private function shortcuts(string $body): array
	{
		preg_match_all('#<(a|input)\b[^>]*\bdata-shortcut="([^"]*)"#', $body, $matches, PREG_SET_ORDER);
		$result = [];
		foreach ($matches as $match) {
			$this->assertArrayNotHasKey($match[2], $result, "only one element has the shortcut {$match[2]}");
			$result[$match[2]] = $match[1];
		}
		ksort($result);
		return $result;
	}

	public function testAPageHasTheShortcutsOfItsLinks(): void
	{
		$body = $this->http->get('/index.php/Home')->body;
		$this->assertSame(['/' => 'input', 'e' => 'a', 'g a' => 'a', 'g h' => 'a', 'n' => 'a'], $this->shortcuts($body));
		$this->assertStringContainsString('<a href="/index.php?action=edit&amp;page=Home" data-shortcut="e" aria-keyshortcuts="e"><svg class="icon icon-edit"', $body);
		$this->assertStringContainsString('<a href="/index.php" data-shortcut="g h"><svg class="icon icon-home"', $body);
		$this->assertStringContainsString('id="search" type="text" name="q" data-shortcut="/" aria-keyshortcuts="/" />', $body);
		// (no history without git)
		$this->assertStringNotContainsString('icon-history', $body);
		$this->assertNoActiveContent($body);
	}

	public function testOtherViewsHaveTheShortcutsOfWhatTheyShow(): void
	{
		$this->savePage('Other', 'text');
		$toolbar = ['/' => 'input', 'g a' => 'a', 'g h' => 'a', 'n' => 'a'];
		// (the icons in the rows of the list of all pages have no shortcuts: "e" is for the page which is shown)
		foreach (['/index.php?action=all', '/index.php?action=search&q=text', '/index.php?action=upload&page=Home'] as $url) {
			$this->assertSame($toolbar, $this->shortcuts($this->http->get($url)->body), $url);
		}
		$this->assertStringContainsString('icon-edit', $this->http->get('/index.php?action=all')->body);
		// (the editor has no icon to edit the page)
		$this->assertSame($toolbar, $this->shortcuts($this->http->get('/index.php?action=edit&page=Other')->body));
		$this->assertSame(['/' => 'input', 'e' => 'a', 'g a' => 'a', 'g h' => 'a', 'n' => 'a'], $this->shortcuts($this->http->get('/index.php?action=rename&page=Other')->body));
	}

	public function testEveryPageHasTheListOfShortcuts(): void
	{
		$body = $this->http->get('/index.php/Home')->body;
		$this->assertSame(1, substr_count($body, '<dialog id="shortcuts" class="shortcuts" aria-labelledby="shortcutstitle">'));
		$this->assertMatchesRegularExpression('#<dialog id="shortcuts".*<h5 id="shortcutstitle">Keyboard shortcuts</h5>.*</dialog>\s*</body>#s', $body);
		$this->assertMatchesRegularExpression('#<button type="button" class="shortcutsclose rightaligned"><svg class="icon icon-close"[^>]*aria-label="Close"#', $body);
		foreach (['/', 'e', 'n', 'g h', 'g a', 'h'] as $keys) {
			$this->assertStringContainsString('<tr data-for="' . $keys . '">', $body);
		}
		$this->assertStringContainsString('<kbd>g</kbd> then <kbd>h</kbd>', $body);
		$this->assertStringNotContainsString('In the editor', $body, 'the keys of the editor only in the editor');

		$editor = $this->http->get('/index.php?action=new')->body;
		$this->assertStringContainsString('<caption>In the editor</caption>', $editor);
		$this->assertStringContainsString('<tr><th scope="row"><kbd>Ctrl</kbd>+<kbd>S</kbd></th><td>Save</td></tr>', $editor);
		$this->assertStringContainsString('On a Mac, press Cmd instead of Ctrl', $editor);
		// (the dialog is not open without the script, and comes after the form, so that Enter in the form can't reach it)
		$this->assertMatchesRegularExpression('#</form>.*<dialog id="shortcuts" class="shortcuts"#s', $editor);
		$this->assertStringNotContainsString('<dialog id="shortcuts" class="shortcuts" open', $editor);
	}

	public function testEveryPageLoadsTheScript(): void
	{
		$this->savePage('Other', 'text');
		foreach (['/index.php', '/index.php/Other', '/index.php?action=all', '/index.php?action=search&q=a', '/index.php?action=upload',
			'/index.php?action=rename&page=Other', '/index.php?action=new', '/index.php?action=history&page=Other'] as $url) {
			$body = $this->http->get($url)->body;
			$this->assertSame(1, preg_match_all('#<script src="/wiki\.js\?v=\d+" defer="defer"></script>#', $body), $url);
			$this->assertSame(1, substr_count($body, '<script'), $url);
		}
	}
}
