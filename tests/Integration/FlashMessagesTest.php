<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/** The message after an action is built from plain text and escaped when it is shown (W2\Flash) */
final class FlashMessagesTest extends GitTestCase
{
	/** The HTML of the message in the page which follows a redirect */
	private function noteHtml(\W2\Tests\Support\HttpResponse $redirect): string
	{
		$this->assertSame(303, $redirect->status);
		preg_match('/<div class="note">(.*?)<\/div>/s', $this->http->follow($redirect)->body, $matches);
		return $matches[1] ?? '';
	}

	public function testNamesInTheMessageOfRenamingAndDeletingAreEscaped(): void
	{
		$this->savePage('A<b>x</b>', 'text');
		$this->savePage('Linker', 'see [[A<b>x</b>]]');
		$html = $this->noteHtml($this->renamePage('A<b>x</b>', 'B<i>y</i> "q"'));
		$this->assertStringContainsString('Renamed A&lt;b&gt;x&lt;/b&gt; to B&lt;i&gt;y&lt;/i&gt; &quot;q&quot;', $html);
		$this->assertStringContainsString('<ul><li>Linker (1 matches)</li></ul>', $html);
		$this->assertStringNotContainsString('<b>', $html);
		$this->assertStringNotContainsString('<i>', $html);

		$html = $this->noteHtml($this->deletePage('B<i>y</i> "q"'));
		$this->assertStringContainsString('Removed B&lt;i&gt;y&lt;/i&gt; &quot;q&quot;', $html);
	}

	public function testFileNamesInTheMessageOfUploadsAreEscaped(): void
	{
		$html = $this->noteHtml($this->upload('a<b>.gif', self::gif()));
		$this->assertStringContainsString("File &#039;a&lt;b&gt;.gif&#039; uploaded!", $html);
		$this->assertStringContainsString('<pre>![Image Description](/images/a&lt;b&gt;.gif)</pre>', $html);
		$this->assertStringNotContainsString('<b>', $html);
	}

	public function testMessageOfAFailedSaveHasALinkToEditTheExistingPage(): void
	{
		$this->savePage('Taken', 'text');
		$response = $this->savePage('Taken', 'other text');
		$this->assertSame(200, $response->status);
		preg_match('/<div class="note">(.*?)<\/div>/s', $response->body, $matches);
		$this->assertStringContainsString("Error creating page &#039;Taken&#039; - it already exists!", $matches[1]);
		$this->assertStringContainsString('<a href="?action=edit&amp;page=Taken">edit</a>', $matches[1]);
	}

	public function testMessageIsShownOnce(): void
	{
		$redirect = $this->savePage('Once', 'text');
		$this->assertSame('Created', $this->noteHtml($redirect));
		$this->assertStringNotContainsString('class="note"', $this->http->get('/index.php/Once')->body);
	}

	public function testCommitMessagesOfPagesAreNotEscaped(): void
	{
		$this->savePage("It's <b>", 'text');
		$this->renamePage("It's <b>", 'Its & "so"');
		$this->assertSame('Renamed It\'s <b> to Its - "so"', $this->lastCommitMessage());
		$this->deletePage('Its - "so"');
		$this->assertSame('Removed Its - "so"', $this->lastCommitMessage());
	}
}
