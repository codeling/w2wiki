<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Without a config.php, the wiki runs with the default settings */
final class MissingConfigTest extends AppTestCase
{
	protected function serverOptions(): array
	{
		return ['config' => false];
	}

	public function testTheWikiWorksWithDefaultSettings(): void
	{
		$home = $this->http->get('/index.php');
		$this->assertSame(200, $home->status);
		$this->assertStringContainsString('Welcome to W2', $home->body);
		$this->assertStringContainsString('<title>Wiki: Home</title>', $home->body);

		$this->assertStringContainsString('rows="18"', $this->http->get('/index.php', ['action' => 'new'])->body);
		$this->savePage('Created Page', 'Text of the page');
		$this->assertStringContainsString('Text of the page', $this->http->get($this->pageUrl('Created Page'))->body);
		$this->assertContains('Created Page.md', $this->server->pageFiles());

		$this->assertSame(200, $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'a.gif'])->status);
	}

	public function testUploadsWorkWithDefaultSettings(): void
	{
		$this->upload('picture.gif', self::gif());
		$this->assertSame(['picture.gif'], $this->uploadedFiles());
	}

	public function testNoPasswordIsRequired(): void
	{
		$this->assertStringNotContainsString('name="p"', $this->http->get('/index.php')->body);
	}

	public function testTheMissingFileIsLoggedOncePerSession(): void
	{
		$offset = $this->server->logSize();
		$this->http->get('/index.php');
		$this->http->get('/index.php', ['action' => 'all']);
		$this->assertSame(1, substr_count($this->server->logSince($offset), 'config.php not found: using the default settings'));

		$offset = $this->server->logSize();
		$this->newClient()->get('/index.php');
		$this->assertSame(1, substr_count($this->server->logSince($offset), 'config.php not found: using the default settings'));
	}
}
