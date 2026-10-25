<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * Unsalted SHA-1 password hashes (as documented in earlier versions) don't allow logging in any more; entering
 * the password shows a password_hash hash of it to replace them with
 */
final class AuthLegacyHashTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true, 'W2_PASSWORD_HASH' => sha1('legacy')];
	}

	public function testCorrectPasswordDoesNotLogIn(): void
	{
		$this->assertStringNotContainsString('Welcome to W2', $this->login('legacy')->body);
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}

	public function testWrongPasswordIsRefused(): void
	{
		$this->assertStringContainsString('Wrong password', $this->login('legacyx')->body);
	}

	public function testCorrectPasswordIsShownAReplacementHash(): void
	{
		$logOffset = $this->server->logSize();
		$response = $this->login('legacy');
		$hash = $this->offeredHash($response->body, 'W2_PASSWORD_HASH');
		$this->assertNotNull($hash, 'the note with the new hash is missing');
		$this->assertTrue(password_verify('legacy', $hash), 'the offered hash must be a hash of the password');
		$this->assertStringStartsWith('$2y$', $hash);
		$this->assertStringNotContainsString('Wrong password', $response->body);
		$this->assertStringContainsString('no-store', (string)$response->header('cache-control'));
		$this->assertStringContainsString('login refused: W2_PASSWORD_HASH in config.php is no longer supported', $this->server->logSince($logOffset));
	}

	public function testNoHashIsShownForAWrongPassword(): void
	{
		$body = $this->login('legacyx')->body;
		$this->assertNull($this->offeredHash($body, 'W2_PASSWORD_HASH'));
		$this->assertStringNotContainsString('<code>', $body);
	}

	public function testNoHashIsShownWithoutAPassword(): void
	{
		$this->assertStringNotContainsString('<code>', $this->http->get('/index.php')->body);
	}

	/** The hash which the note after entering the password offers as the replacement of the setting */
	private function offeredHash(string $html, string $setting): ?string
	{
		return preg_match('/<p class="note">[^<]*' . $setting . '[^<]*<code>([^<]+)<\/code><\/p>/', $html, $matches)
			? html_entity_decode($matches[1], ENT_QUOTES) : null;
	}
}
