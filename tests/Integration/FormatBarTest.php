<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * The formatting toolbar above the text of the editor (format-bar.php) and its dialog for tables (table-dialog.php):
 * hidden without the script (setupFormatBar() in wiki.js, tests/Browser/toolbar.spec.js), with buttons which name what
 * they do and their keys, and without inline handlers.
 */
final class FormatBarTest extends AppTestCase
{
	/** @return string the toolbar in the page */
	private function toolbar(string $body): string
	{
		$this->assertSame(1, preg_match('#<div id="formatbar".*?\n</div>#s', $body, $match), 'the page has the toolbar');
		return $match[0];
	}

	public function testTheEditorHasTheToolbarAboveTheText(): void
	{
		$this->savePage('Formatted', 'text');
		foreach (['/index.php?action=new', '/index.php?action=edit&page=Formatted', $this->pageUrl("Not yet written")] as $url) {
			$body = $this->http->get($url)->body;
			$this->assertStringContainsString('<div id="formatbar" class="formatbar" role="toolbar" aria-label="Formatting" aria-controls="text" aria-keyshortcuts="Alt+F10" hidden="hidden">', $body, $url);
			// (in the form, directly above the text)
			$this->assertMatchesRegularExpression('#<form id="edit".*<div id="formatbar".*?\n</div>\n<div class="editorbox"><textarea id="text"#s', $body, $url);
			$this->assertNoActiveContent($this->toolbar($body));
		}
		$this->assertStringNotContainsString('id="formatbar"', $this->http->get('/index.php/Formatted')->body, 'only in the editor');
	}

	public function testButtonsNameWhatTheyDoAndTheirKeys(): void
	{
		$toolbar = $this->toolbar($this->http->get('/index.php?action=new')->body);
		preg_match_all('#<button type="button" data-format="([a-z]+)"#', $toolbar, $formats);
		$this->assertSame(['bold', 'italic', 'link', 'wikilink', 'bullets', 'numbers', 'quote', 'code', 'rule'], $formats[1]);
		$this->assertStringContainsString('<button type="button" data-format="bold" title="Bold (Ctrl+B)" aria-keyshortcuts="Control+B Meta+B"><svg class="icon icon-format-text-bold" viewBox="0 0 16 16" role="img" aria-label="Bold (Ctrl+B)">', $toolbar);
		$this->assertStringContainsString('data-format="italic" title="Italic (Ctrl+I)" aria-keyshortcuts="Control+I Meta+I"', $toolbar);
		$this->assertStringContainsString('data-format="link" title="Link (Ctrl+K)" aria-keyshortcuts="Control+K Meta+K"', $toolbar);
		$this->assertStringContainsString('aria-label="Link to a page of the wiki: [[selected text]]">[[ ]]</button>', $toolbar);
		// (every button has a name: the label of its icon, or its own)
		preg_match_all('#<button\b[^>]*>(.*?)</button>#s', $toolbar, $buttons, PREG_SET_ORDER);
		$this->assertCount(17, $buttons);
		foreach ($buttons as $button) {
			$this->assertTrue(preg_match('#aria-label="[^"]+"#', $button[0]) === 1 || trim(strip_tags($button[1])) !== '', $button[0]);
		}
		// (the headings: a menu of levels 1 to 4)
		$this->assertStringContainsString('<button type="button" id="headingbutton" title="Heading: level 1 to 4 for the lines of the cursor (the same level again removes it)" aria-haspopup="menu" aria-expanded="false" aria-controls="headingmenu">', $toolbar);
		$this->assertStringContainsString('<span id="headingmenu" class="headingmenu" role="menu" aria-label="Heading" hidden="hidden">', $toolbar);
		for ($level = 1; $level <= 4; $level++) {
			$this->assertStringContainsString('<button type="button" role="menuitemradio" aria-checked="false" tabindex="-1" data-level="' . $level . '">Heading ' . $level . '</button>', $toolbar);
		}
		$this->assertStringNotContainsString('data-level="5"', $toolbar);
		// (no strikethrough: PHP Markdown has none, see docs/MarkdownLibrary.md)
		$this->assertStringNotContainsString('strike', $toolbar);
	}

	public function testUploadAndHelpAreInTheToolbarOfTheText(): void
	{
		$body = $this->http->get('/index.php?action=new')->body;
		$toolbar = $this->toolbar($body);
		$this->assertMatchesRegularExpression('#<button type="button" id="uploadbutton" title="Upload a file and insert a link to it at the cursor[^"]*" aria-haspopup="dialog"><svg class="icon icon-upload"#', $toolbar);
		$this->assertMatchesRegularExpression('#<button type="button" id="drawer-control" class="helpbutton" title="Formatting help" aria-controls="drawer" aria-expanded="false"><svg class="icon icon-help"[^>]*>.*?</svg></button>\n</div>$#s', $toolbar, 'the help is the last button');
		// (not in the toolbar of the page any more, which has the drawer itself)
		preg_match('#<div class="toolbar">.*?<div class="main">#s', $body, $pageToolbar);
		$this->assertStringContainsString('<div id="drawer" class="inactive">', $pageToolbar[0]);
		$this->assertStringNotContainsString('drawer-control', $pageToolbar[0]);
		$this->assertStringNotContainsString('uploadbutton', $pageToolbar[0]);
		$this->assertStringNotContainsString('icongroup', $body);
	}

	public function testTheTableDialogComesAfterTheForm(): void
	{
		$body = $this->http->get('/index.php?action=new')->body;
		$this->assertStringContainsString('<button type="button" id="tablebutton" title="Table: of a size to choose, or of the selected lines with cells separated by tabs or commas (as copied from a spreadsheet)" aria-haspopup="dialog">', $body);
		$formEnd = strpos($body, '</form>', (int)strpos($body, 'id="edit"'));
		$this->assertNotFalse($formEnd);
		$this->assertGreaterThan($formEnd, strpos($body, '<dialog id="tabledialog" class="tabledialog" aria-labelledby="tabletitle" data-msg-column="Column %s">'));
		$this->assertStringContainsString('<form method="dialog">', $body);
		$this->assertStringContainsString('<input id="tablecolumns" type="number" min="1" max="20" value="3" required="required" />', $body);
		$this->assertStringContainsString('<input id="tablerows" type="number" min="1" max="100" value="3" required="required" />', $body);
		$this->assertStringContainsString('<button type="submit" id="tableinsert" value="insert">Insert</button>', $body);
		$this->assertStringNotContainsString('<dialog id="tabledialog" class="tabledialog" open', $body);
		$this->assertStringNotContainsString('id="tabledialog"', $this->http->get('/index.php/Home')->body);
	}

	public function testHelpAndListOfShortcutsNameTheToolbar(): void
	{
		$body = $this->http->get('/index.php?action=new')->body;
		preg_match('#<div id="drawer".*?</div>\s*</div>#s', $body, $drawer);
		$this->assertStringContainsString('The buttons above the text format the selected text', $drawer[0]);
		$this->assertStringContainsString('| Column 1 | Column 2 |<br/>| --- | --- |', $drawer[0]);
		$this->assertStringContainsString('<tr><th scope="row"><kbd>Alt</kbd>+<kbd>F10</kbd></th><td>Go to the formatting toolbar', $body);
	}
}
