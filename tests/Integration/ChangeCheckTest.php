<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Saving is refused if somebody else has changed the page since the editor was opened (the hash in the form) */
final class ChangeCheckTest extends AppTestCase
{
	private function baseHashOfEditor(string $page): string
	{
		$body = $this->http->get('/index.php', ['action' => 'edit', 'page' => $page])->body;
		$this->assertSame(1, preg_match('/<input type="hidden" name="baseHash" value="([0-9a-f]{64})" \/>/', $body, $matches), 'the editor sends a hash');
		return $matches[1];
	}

	private function saveWithHash(string $page, string $text, ?string $hash): \W2\Tests\Support\HttpResponse
	{
		return $this->postAction('save', ['page' => $page, 'isNew' => '', 'newText' => $text, 'gitmsg' => ''] + ($hash === null ? [] : ['baseHash' => $hash]));
	}

	public function testSavingAnUnchangedPageWorks(): void
	{
		$hash = $this->baseHashOfEditor('Home');
		$this->assertSame('Saved', $this->noteAfter($this->saveWithHash('Home', 'first change', $hash)));
		$this->assertSame('first change', $this->pageText('Home'));
	}

	public function testSavingOverAChangeIsRefusedAndTheTextStaysInTheEditor(): void
	{
		$hash = $this->baseHashOfEditor('Home');
		$this->assertSame('Saved', $this->noteAfter($this->saveWithHash('Home', 'their text', null)));

		$response = $this->saveWithHash('Home', 'my text', $hash);
		$this->assertStringContainsString('was changed (or deleted) by someone else since you opened it', $this->noteInResponse($response));
		$this->assertSame('their text', $this->pageText('Home'), 'nothing was saved');
		$this->assertStringContainsString('>my text</textarea>', $response->body);
		// the editor now carries the hash of their text: saving again is a decision to replace it
		$this->assertSame(1, preg_match('/name="baseHash" value="([0-9a-f]{64})"/', $response->body, $matches));
		$this->assertNotSame($hash, $matches[1]);
		$this->assertSame('Saved', $this->noteAfter($this->saveWithHash('Home', 'my text', $matches[1])));
		$this->assertSame('my text', $this->pageText('Home'));
	}

	public function testSavingAPageWhichWasDeletedIsRefused(): void
	{
		$this->savePage('Short lived', 'text');
		$hash = $this->baseHashOfEditor('Short lived');
		unlink($this->pageFile('Short lived'));
		$this->assertStringContainsString('was changed (or deleted)', $this->noteInResponse($this->saveWithHash('Short lived', 'again', $hash)));
		$this->assertFileDoesNotExist($this->pageFile('Short lived'));
	}

	public function testFormsWithoutAHashAreStillAccepted(): void
	{
		$this->assertSame('Saved', $this->noteAfter($this->saveWithHash('Home', 'no hash', null)));
		$this->assertSame('Saved', $this->noteAfter($this->saveWithHash('Home', 'empty hash', '')));
	}

	public function testANewPageNeedsNoHash(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'new'])->body;
		$this->assertStringNotContainsString('name="baseHash"', $body);
		$this->assertSame('Created', $this->noteAfter($this->savePage('Brand new', 'x')));
	}
}
