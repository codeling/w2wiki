<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The forms of the pages (templates editor, rename-delete, upload-form): markup, and values which are escaped */
final class FormsTest extends AppTestCase
{
	private const HOSTILE_TEXT = "</textarea><script>alert(1)</script>\n\n<img src=x onerror=alert(2)> & \"q\"";

	/** @return \DOMElement the form with this id */
	private function form(string $html, string $id): \DOMElement
	{
		foreach ($this->elements($html, 'form') as $form) {
			if ($form->getAttribute('id') === $id) {
				return $form;
			}
		}
		$this->fail("no form $id");
	}

	/** @return array<string, string> the fields of a form with a name: name => value */
	private function fields(\DOMElement $form): array
	{
		$fields = [];
		foreach (['input', 'textarea'] as $tag) {
			foreach ($form->getElementsByTagName($tag) as $field) {
				if ($field->getAttribute('name') !== '') {
					$fields[$field->getAttribute('name')] = $tag === 'textarea' ? $field->textContent : $field->getAttribute('value');
				}
			}
		}
		return $fields;
	}

	// --- editor -------------------------------------------------------------------------

	public function testEditorOfAnExistingPage(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home'])->body;
		$form = $this->form($body, 'edit');
		$this->assertSame('post', $form->getAttribute('method'));
		$this->assertSame('/index.php', $form->getAttribute('action'));
		$this->assertSame('w2wiki-draft:/index.php:edit:Home', $form->getAttribute('data-draft-key'));
		$this->assertFalse($form->hasAttribute('data-unsaved'));
		$this->assertSame('w2wiki-draft:/index.php:edit:Home', $this->fields($form)['draftKey']);
		$this->assertSame('Restored unsaved draft from %s.', $form->getAttribute('data-msg-restored'));
		$fields = $this->fields($form);
		$this->assertEqualsCanonicalizing(['csrf_token', 'draftKey', 'page', 'newText', 'action', 'isNew'], array_keys($fields));
		$this->assertSame('Home', $fields['page']);
		$this->assertSame('save', $fields['action']);
		$this->assertSame('', $fields['isNew']);
		// (the page file has CRLF line endings; HTML parsers differ in whether they keep the CR of a text area)
		$this->assertSame(
			str_replace("\r\n", "\n", (string)file_get_contents($this->pageFile('Home'))),
			str_replace("\r\n", "\n", $fields['newText'])
		);
		$this->assertStringContainsString("[[MarkdownSyntax|Markdown Syntax]]", $body, 'the text is in the page');
		$this->assertStringContainsString('<textarea id="text" name="newText" rows="18"', $body);
		$this->assertStringContainsString('<input id="save" type="submit" value="Save" />', $body);
		$this->assertStringContainsString('<input id="cancel" type="button" onclick="history.go(-1);" value="Cancel" />', $body);
		$this->assertStringContainsString('<p class="editorbox">', $body);
		$this->assertStringContainsString('<p class="editactions">', $body);
	}

	public function testEditorOfANewPage(): void
	{
		$form = $this->form($this->http->get('/index.php', ['action' => 'new'])->body, 'edit');
		$fields = $this->fields($form);
		$this->assertEqualsCanonicalizing(['csrf_token', 'draftKey', 'page', 'newText', 'action', 'isNew'], array_keys($fields));
		$this->assertSame('', $fields['page']);
		$this->assertSame('true', $fields['isNew']);
		$this->assertSame('w2wiki-draft:/index.php:new:', $form->getAttribute('data-draft-key'));
	}

	public function testTextAndNamesInTheEditorAreEscaped(): void
	{
		$this->savePage('Hostile', self::HOSTILE_TEXT);
		$body = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Hostile'])->body;
		$fields = $this->fields($this->form($body, 'edit'));
		$this->assertSame(self::HOSTILE_TEXT, $fields['newText'], 'the text comes back as it was saved');
		$this->assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body);
		$this->assertNoActiveContent($body, true);
	}

	public function testTextAfterAFailedSaveStaysInTheEditor(): void
	{
		$response = $this->savePage('.hidden', self::HOSTILE_TEXT);
		$this->assertSame(200, $response->status);
		$fields = $this->fields($this->form($response->body, 'edit'));
		$this->assertSame(self::HOSTILE_TEXT, $fields['newText']);
		$this->assertSame('.hidden', $fields['page']);
		$this->assertNoActiveContent($response->body, true);
	}

	// --- drafts kept in the browser -------------------------------------------------------

	private const DRAFT_KEY = 'w2wiki-draft:/index.php:new:';

	public function testFailedSaveShowsTheEditorAgainWithTheDraftOfTheEditorWhichSentTheText(): void
	{
		$response = $this->postAction('save', ['page' => '', 'isNew' => 'true', 'newText' => 'unsaved text', 'gitmsg' => '', 'draftKey' => self::DRAFT_KEY]);
		$this->assertSame(200, $response->status);
		$form = $this->form($response->body, 'edit');
		$this->assertSame('true', $form->getAttribute('data-unsaved'), 'the text in the editor is not saved');
		$this->assertSame(self::DRAFT_KEY, $form->getAttribute('data-draft-key'), 'a new page without a title has the draft it had');
		$this->assertSame(self::DRAFT_KEY, $this->fields($form)['draftKey']);
		$this->assertSame('unsaved text', $this->fields($form)['newText']);
	}

	public function testDraftKeyWhichIsNotOneIsIgnored(): void
	{
		foreach (['', 'something else', 'W2wiki-draft:/x', '../../w2wiki-draft:'] as $posted) {
			$response = $this->postAction('save', ['page' => '.hidden', 'isNew' => 'true', 'newText' => 't', 'gitmsg' => '', 'draftKey' => $posted]);
			$form = $this->form($response->body, 'edit');
			$this->assertSame('w2wiki-draft:/index.php:new:.hidden', $form->getAttribute('data-draft-key'), "posted: $posted");
		}
	}

	public function testDraftKeyIsEscaped(): void
	{
		$key = 'w2wiki-draft:"><script>alert(1)</script>';
		$response = $this->postAction('save', ['page' => '', 'isNew' => 'true', 'newText' => 't', 'gitmsg' => '', 'draftKey' => $key]);
		$this->assertSame($key, $this->form($response->body, 'edit')->getAttribute('data-draft-key'));
		$this->assertNoActiveContent($response->body, true);
	}

	public function testSavedDraftIsReportedOnceAfterSaving(): void
	{
		$key = 'w2wiki-draft:/index.php:edit:Home';
		$saved = $this->postAction('save', ['page' => 'Home', 'isNew' => '', 'newText' => 'new text', 'gitmsg' => '', 'draftKey' => $key]);
		$body = $this->http->follow($saved)->body;
		$this->assertStringContainsString('<body data-saved-draft="' . $key . '">', $body);
		$this->assertMatchesRegularExpression('#<script src="/wiki\.js\?v=\d+"></script>#', $body, 'the script removes the draft');
		$after = $this->http->get('/index.php/Home')->body;
		$this->assertStringNotContainsString('data-saved-draft', $after);
		$this->assertStringNotContainsString('wiki.js', $after);
	}

	public function testSavedDraftIsEscaped(): void
	{
		$key = 'w2wiki-draft:"><script>alert(1)</script>';
		$saved = $this->postAction('save', ['page' => 'Home', 'isNew' => '', 'newText' => 'text', 'gitmsg' => '', 'draftKey' => $key]);
		$body = $this->http->follow($saved)->body;
		$this->assertStringContainsString('data-saved-draft="w2wiki-draft:&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
		$this->assertNoActiveContent($body);
	}

	public function testTitleOfANewPageIsEscaped(): void
	{
		// (the editor of a page which does not exist yet is for a new page with this title)
		$title = 'x"><script>alert(1)</script>';
		$body = $this->http->get('/index.php', ['action' => 'edit', 'page' => $title])->body;
		$this->assertSame($title, $this->fields($this->form($body, 'edit'))['page']);
		$this->assertStringContainsString('value="x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $body);
		$this->assertNoActiveContent($body, true);
	}

	public function testNewPageWithAnAlmostExistingNameSuggestsThePageThatExists(): void
	{
		$this->savePage('Hello World', 'text');
		$this->savePage('Hello Worl<d', 'text');
		$body = $this->http->get('/index.php/' . rawurlencode('Hello Worlds'))->body;
		$this->assertStringContainsString('<div class="note">Creating new page since no page with given title exists!', $body);
		$this->assertStringContainsString('<strong>Note:</strong> Found similar page <a href="/index.php/Hello%20World">Hello World</a>.', $body);
		$this->assertStringContainsString('<a href="/index.php/Hello%20Worl%3Cd">Hello Worl&lt;d</a>', $body);
		$this->assertStringNotContainsString('Worl<d', $body);
		$this->assertStringNotContainsString('Found similar page', $this->http->get('/index.php/' . rawurlencode('Quite Different Name'))->body);
	}

	// --- rename and delete --------------------------------------------------------------

	public function testRenameFormOfAPage(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'rename', 'page' => 'Home'])->body;
		$form = $this->form($body, 'rename');
		$this->assertSame(['csrf_token', 'newName', 'action', 'oldPageName'], array_keys($this->fields($form)));
		$this->assertSame(['newName' => 'Home', 'action' => 'renamed', 'oldPageName' => 'Home'], array_slice($this->fields($form), 1));
		$this->assertStringContainsString('<p>Rename Home to <input id="newName" type="text" name="newName" value="Home" class="pagename" /></p>', $body);
		$this->assertStringContainsString('<input id="rename" type="submit" value="Rename">', $body);
	}

	public function testDeleteFormOfAPage(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'delete', 'page' => 'Home'])->body;
		$form = $this->form($body, 'delete');
		$this->assertSame(['action' => 'deleted', 'oldPageName' => 'Home'], array_slice($this->fields($form), 1));
		$this->assertStringContainsString('<p>Delete Home ?</p>', $body);
		$this->assertStringNotContainsString('name="newName"', $body);
	}

	public function testImageFormsReturnToThePage(): void
	{
		$rename = $this->http->get('/index.php', ['action' => 'imgRename', 'imgName' => 'a.gif', 'prevpage' => 'Home'])->body;
		$this->assertSame(
			['newName' => 'a.gif', 'action' => 'imgRenamed', 'oldPageName' => 'a.gif', 'prevpage' => 'Home'],
			array_slice($this->fields($this->form($rename, 'imgRename')), 1)
		);
		$delete = $this->http->get('/index.php', ['action' => 'imgDelete', 'imgName' => 'a.gif', 'prevpage' => 'Home'])->body;
		$this->assertSame(
			['action' => 'imgDeleted', 'oldPageName' => 'a.gif', 'prevpage' => 'Home'],
			array_slice($this->fields($this->form($delete, 'imgDelete')), 1)
		);
		$this->assertStringContainsString('<p>Delete a.gif ?</p>', $delete);
	}

	public function testNamesInTheRenameAndDeleteFormsAreEscaped(): void
	{
		$name = 'A"><script>alert(1)</script>';
		$this->savePage($name, 'text');
		foreach (['rename', 'delete'] as $action) {
			$body = $this->http->get('/index.php', ['action' => $action, 'page' => $name])->body;
			$fields = $this->fields($this->form($body, $action));
			$this->assertSame($name, $fields['oldPageName'], $action);
			$this->assertStringContainsString('A&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body, $action);
			$this->assertNoActiveContent($body, true);
		}
		$body = $this->http->get('/index.php', ['action' => 'imgDelete', 'imgName' => $name, 'prevpage' => 'Home'])->body;
		$this->assertSame($name, $this->fields($this->form($body, 'imgDelete'))['oldPageName']);
		$this->assertNoActiveContent($body, true);
	}

	// --- upload -------------------------------------------------------------------------

	public function testUploadForm(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'upload', 'page' => 'Home'])->body;
		$form = $this->form($body, 'upload');
		$this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
		$names = array_keys($this->fields($form));
		foreach (['csrf_token', 'action', 'prevpage', 'overwrite', 'userfile', 'newname'] as $name) {
			$this->assertContains($name, $names);
		}
		// (images can be shrunk where the Imagick extension is available)
		$this->assertSame(extension_loaded('imagick'), in_array('resize', $names, true));
		$this->assertSame(extension_loaded('imagick'), in_array('maxsize', $names, true));
		$fields = $this->fields($form);
		$this->assertSame('uploaded', $fields['action']);
		$this->assertSame('Home', $fields['prevpage']);
		$this->assertStringContainsString('<input id="newname" type="text" name="newname" placeholder="Keep file name" />', $body);
		$this->assertStringContainsString('<input id="upload" type="submit" value="Upload" />', $body);
		$this->assertStringContainsString('function processForm(e) {', $body);
		$this->assertStringContainsString('alert("No file selected!");', $body);
		$this->assertStringContainsString('window.confirm("File %s already exists. Overwrite?".replace("%s", () => filename))', $body);
		$this->assertSame(1, substr_count($body, '</script>'));
	}
}
