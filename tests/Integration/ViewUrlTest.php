<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** With VIEW set (web servers without PATH_INFO), page URLs are query strings without a slash before the page name */
final class ViewUrlTest extends AppTestCase
{
	private const VIEW = '?action=view&page=';

	protected function configOverrides(): array
	{
		return ['VIEW' => self::VIEW];
	}

	public function testSavingRedirectsToQueryUrl(): void
	{
		$response = $this->savePage('Test', 'content');
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php?action=view&page=Test', $response->location());
		$this->assertStringContainsString('content', $this->http->get('/index.php', ['action' => 'view', 'page' => 'Test'])->body);
	}

	public function testRedirectEncodesPageName(): void
	{
		$response = $this->savePage('A B', 'x');
		$this->assertSame('/index.php?action=view&page=A%20B', $response->location());
	}

	public function testPageLinksHaveNoLeadingSlash(): void
	{
		$this->savePage('Test', 'text');
		$this->savePage('Other', 'see [[Test]]');
		$html = $this->http->get('/index.php', ['action' => 'view', 'page' => 'Other'])->body;
		$this->assertStringContainsString('href="/index.php?action=view&amp;page=Test"', $html);
		$this->assertStringNotContainsString('page=/', $html);
	}

	public function testEditorTitleHasNoLeadingSlash(): void
	{
		$this->savePage('Test', 'text');
		$html = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Test'])->body;
		$this->assertStringNotContainsString('page=/', $html);
		$this->assertStringNotContainsString('>/Test<', $html);
		$this->assertMatchesRegularExpression('/name="page" value="Test"/', $html);
	}

	public function testUploadLinkIsValid(): void
	{
		$html = $this->http->get('/index.php')->body;
		$this->assertMatchesRegularExpression('/href="\/index\.php\?action=upload&amp;page=Home"/', $html);
	}
}
