<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * A password in plain text in W2_PASSWORD (a setting of earlier versions) doesn't allow logging in any more;
 * entering it shows a password_hash hash of it for W2_PASSWORD_HASH
 */
final class AuthPlainPasswordTest extends AppTestCase
{
	/** (a config.php of an earlier version: W2_PASSWORD is no longer in config.sample.php) */
	protected function serverOptions(): array
	{
		return ['config' => "define('REQUIRE_PASSWORD', true);\ndefine('W2_PASSWORD', 'correct horse');\n"];
	}

	public function testCorrectPasswordDoesNotLogInButIsShownAHash(): void
	{
		$logOffset = $this->server->logSize();
		$response = $this->login('correct horse');
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
		$this->assertMatchesRegularExpression('/<p class="note">[^<]*W2_PASSWORD [^<]*<code>([^<]+)<\/code><\/p>/', $response->body);
		preg_match('/<code>([^<]+)<\/code>/', $response->body, $matches);
		$this->assertTrue(password_verify('correct horse', html_entity_decode($matches[1], ENT_QUOTES)));
		$this->assertStringContainsString('login refused: W2_PASSWORD in config.php is no longer supported', $this->server->logSince($logOffset));
	}

	public function testWrongPasswordIsRefused(): void
	{
		$body = $this->login('correct horsf')->body;
		$this->assertStringContainsString('Wrong password', $body);
		$this->assertStringNotContainsString('<code>', $body);
	}

	public function testLoginPageDoesNotSayThatNoPasswordIsSet(): void
	{
		$this->assertStringNotContainsString('Login is disabled', $this->http->get('/index.php')->body);
	}
}
