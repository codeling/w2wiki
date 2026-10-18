<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** A config.php (like one from an older version) which defines only some settings: the others have their defaults */
final class PartialConfigTest extends AppTestCase
{
	protected function serverOptions(): array
	{
		return ['config' => "define('PAGE_TITLE', 'Mine: ');\ndefine('DEFAULT_PAGE', 'Start');\n\$trustedProxies = array('127.0.0.1');\n"];
	}

	public function testDefinedSettingsAreUsedAndTheOthersHaveDefaults(): void
	{
		$this->savePage('Start', 'The start page');
		$home = $this->http->get('/index.php');
		$this->assertStringContainsString('<title>Mine: Start</title>', $home->body);
		$this->assertStringContainsString('The start page', $home->body);
		$this->assertStringContainsString('rows="18"', $this->http->get('/index.php', ['action' => 'new'])->body);

		$this->upload('picture.gif', self::gif());
		$this->assertSame(['picture.gif'], $this->uploadedFiles());
	}

	public function testConfigFileIsNotReportedMissing(): void
	{
		$offset = $this->server->logSize();
		$this->http->get('/index.php');
		$this->assertStringNotContainsString('config.php not found', $this->server->logSince($offset));
	}
}
