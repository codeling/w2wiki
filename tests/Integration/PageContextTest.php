<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The new page tells which pages link to it or mention its name (by file name: the page may be in a folder) */
final class PageContextTest extends AppTestCase
{
	private function newPageBody(string $page): string
	{
		return $this->http->get($this->pageUrl($page))->body;
	}

	public function testPagesWhichLinkToTheFileNameAreListedWithABacklinkButton(): void
	{
		$this->savePage('Browsers', 'Use [[Firefox]] or [[Chromium]].');
		$this->savePage('Other', 'See [[Software/Firefox|the fox]].');
		$this->savePage('Unrelated', 'Only [[Firefoxes]].');
		$body = $this->newPageBody('Software/Firefox');
		$this->assertStringContainsString('Pages which already link to a page of this name:', $body);
		$this->assertStringContainsString('<a href="/index.php/Browsers">Browsers</a> <button type="button" class="backlink" data-page="Browsers"', $body);
		$this->assertStringContainsString('<a href="/index.php/Other">Other</a> <button type="button" class="backlink" data-page="Other"', $body);
		$this->assertStringNotContainsString('href="/index.php/Unrelated"', $body);
		$this->assertStringNotContainsString('Pages which mention', $body);
	}

	public function testPagesWhichMentionTheNameAreListedWithTheText(): void
	{
		$this->savePage('Notes', "Intro\nI like <b>Firefox</b> & more.\n");
		$body = $this->newPageBody('Software/Firefox');
		$this->assertStringContainsString('Pages which mention the name without a link:', $body);
		$this->assertStringContainsString('<a href="/index.php/Notes">Notes</a>: <span class="snippet">I like &lt;b&gt;Firefox&lt;/b&gt; &amp; more.</span>', $body);
		$this->assertStringNotContainsString('<b>Firefox</b>', $body);
		$this->assertStringContainsString('name="target" value="Software/Firefox"', $body);
	}

	public function testNothingIsShownWithoutContextAndForExistingPages(): void
	{
		$body = $this->newPageBody('Completely Unknown');
		$this->assertStringNotContainsString('class="pagecontext"', $body);
		$this->assertStringNotContainsString('class="pagecontext"', $this->http->get($this->pageUrl('Home'))->body);
	}

	public function testMentionCanBeLinkedInTheOtherPage(): void
	{
		$this->savePage('Notes', "A firefox here.\n");
		$response = $this->postAction('linkMention', ['page' => 'Notes', 'target' => 'Software/Firefox', 'baseHash' => $this->baseHashOf('Notes')]);
		$this->assertSame('Linked Software/Firefox in the page Notes', $this->noteAfter($response));
		$this->assertSame("A [[Software/Firefox|firefox]] here.\n", str_replace("\r\n", "\n", $this->pageText('Notes')));
		$body = $this->newPageBody('Software/Firefox');
		$this->assertStringContainsString('Pages which already link to a page of this name:', $body);
		$this->assertStringNotContainsString('Pages which mention', $body);
	}

	public function testLinkingIsRefusedWhenThePageHasChanged(): void
	{
		$this->savePage('Notes', "A firefox here.\n");
		$hash = $this->baseHashOf('Notes');
		$this->savePage('Notes', "Changed firefox.\n", false);
		$note = $this->noteAfter($this->postAction('linkMention', ['page' => 'Notes', 'target' => 'Software/Firefox', 'baseHash' => $hash]));
		$this->assertSame('The page Notes was changed or deleted since the list was shown. Nothing was changed.', $note);
		$this->assertSame("Changed firefox.\n", str_replace("\r\n", "\n", $this->pageText('Notes')));
	}

	public function testLinkingNeedsASecurityToken(): void
	{
		$this->savePage('Notes', "A firefox here.\n");
		$response = $this->http->post('/index.php', ['action' => 'linkMention', 'page' => 'Notes', 'target' => 'Firefox', 'baseHash' => $this->baseHashOf('Notes')]);
		$this->assertSame(403, $response->status);
		$this->assertSame("A firefox here.\n", str_replace("\r\n", "\n", $this->pageText('Notes')));
	}

	/** the hash which the list of mentions contains for the page (of the text as it is in the file) */
	private function baseHashOf(string $page): string
	{
		return hash('sha256', $this->pageText($page));
	}
}
