<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Logging in is impossible until a password is set (the default password 'secret' of earlier versions is none) */
final class DefaultPasswordTest extends AppTestCase
{
	/** (a config.php of an earlier version: W2_PASSWORD is no longer in config.sample.php) */
	protected function serverOptions(): array
	{
		return ['config' => "define('REQUIRE_PASSWORD', true);\ndefine('W2_PASSWORD', 'secret');\n"];
	}

	public function testLoginIsRefusedWhileTheDefaultPasswordIsConfigured(): void
	{
		$response = $this->login('secret');
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
		$this->assertStringContainsString('Login is disabled', $response->body);
		$this->assertStringNotContainsString('<code>', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}
}
