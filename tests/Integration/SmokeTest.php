<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

final class SmokeTest extends AppTestCase
{
	public function testHomePageIsShown(): void
	{
		$response = $this->http->get('/index.php');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Welcome to W2', $response->body);
		$this->assertNoActiveContent($response->body);
	}

	public function testPagesCanBeSavedAndReadBack(): void
	{
		$response = $this->savePage('Smoke Test', 'Hello *world*');
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php/Smoke%20Test', $response->location());
		$this->assertSame('Hello *world*', $this->pageText('Smoke Test'));
		$this->assertStringContainsString('<em>world</em>', $this->http->follow($response)->body);
	}

	public function testStateIsResetBetweenTests(): void
	{
		$files = $this->server->pageFiles();
		foreach (['Home.md', 'MarkdownSyntax.md', '_sidebar.md'] as $page) {
			$this->assertContains($page, $files);
		}
		$this->assertNotContains('Smoke Test.md', $files);
		$this->assertSame([], $this->uploadedFiles());
	}
}
