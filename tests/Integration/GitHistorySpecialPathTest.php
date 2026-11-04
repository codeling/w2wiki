<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\GitTestCase;

/**
 * The history of a page with special characters in its name, in a pages folder with spaces, quotes and shell characters
 * (the commands get the names as arguments, without a shell)
 */
final class GitHistorySpecialPathTest extends GitTestCase
{
	private const PAGE = "Sub dir/It's \"quoted\" \$(touch pwned) `x`; Ünïcode [a]*?";

	protected function serverOptions(): array
	{
		return ['git' => true, 'pagesFolder' => "my pages it's \"quoted\" \$(touch pwned) `x`; &"];
	}

	public function testHistoryVersionDiffAndRestoreWork(): void
	{
		$this->assertSame('Created', $this->noteAfter($this->savePage(self::PAGE, 'first text')));
		$this->savePage(self::PAGE, 'second text', false);
		$this->assertFileExists($this->pageFile(self::PAGE));
		[, $old] = explode("\n", $this->server->git('log --format=%H'));

		$history = $this->http->get('/index.php', ['action' => 'history', 'page' => self::PAGE]);
		$this->assertSame(200, $history->status);
		$this->assertStringContainsString('Versions 1 to 2 of 2', $history->body);
		$this->assertStringContainsString(htmlspecialchars(self::PAGE . ' created', ENT_QUOTES), $history->body);

		$version = $this->http->get('/index.php', ['action' => 'version', 'page' => self::PAGE, 'rev' => $old]);
		$this->assertSame(200, $version->status);
		$this->assertStringContainsString('<p>first text</p>', $version->body);

		$diff = $this->http->get('/index.php', ['action' => 'diff', 'page' => self::PAGE, 'rev' => $old, 'to' => 'current']);
		$this->assertStringContainsString('<span class="removed">- first text</span>', $diff->body);
		$this->assertStringContainsString('<span class="added">+ second text</span>', $diff->body);

		$restore = $this->http->get('/index.php', ['action' => 'restore', 'page' => self::PAGE, 'rev' => $old]);
		$this->assertStringContainsString('>first text</textarea>', $restore->body);

		$this->assertSame([], glob($this->server->rootDir() . '/pwned*') ?: []);
		$this->assertSame([], glob($this->server->pagesDir() . '/pwned*') ?: []);
	}

	public function testInjectionsThroughTheRevisionAndThePageFail(): void
	{
		foreach (['$(touch pwned)', '`touch pwned`', '-c core.pager=touch pwned', "HEAD\nx", 'HEAD;touch pwned'] as $revision) {
			$response = $this->http->get('/index.php', ['action' => 'version', 'page' => 'Home', 'rev' => $revision]);
			$this->assertSame(404, $response->status, $revision);
		}
		foreach (['$(touch pwned)', '--exec=touch pwned', "-c core.pager=touch pwned"] as $page) {
			$this->assertSame(404, $this->http->get('/index.php', ['action' => 'history', 'page' => $page])->status, $page);
		}
		$this->assertSame([], glob($this->server->rootDir() . '/pwned*') ?: []);
		$this->assertSame([], glob($this->server->pagesDir() . '/pwned*') ?: []);
	}
}
