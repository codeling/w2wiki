<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Unsalted SHA-1 password hashes (as documented in earlier versions) still work, but are deprecated */
final class AuthLegacyHashTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true, 'W2_PASSWORD_HASH' => sha1('legacy')];
	}

	public function testCorrectPasswordIsAccepted(): void
	{
		$this->assertStringContainsString('Welcome to W2', $this->login('legacy')->body);
	}

	public function testWrongPasswordIsRefused(): void
	{
		$this->assertStringContainsString('Wrong password', $this->login('legacyx')->body);
	}

	/** The hash which the notice after logging in offers as the replacement */
	private function offeredHash(string $html): ?string
	{
		return preg_match('/<div class="note">[^<]*SHA-1[^<]*<code>([^<]+)<\/code><\/div>/', $html, $matches)
			? html_entity_decode($matches[1], ENT_QUOTES) : null;
	}

	public function testLoginOffersAReplacementHash(): void
	{
		$logOffset = $this->server->logSize();
		$hash = $this->offeredHash($this->login('legacy')->body);
		$this->assertNotNull($hash, 'the note with the new hash is missing');
		$this->assertTrue(password_verify('legacy', $hash), 'the offered hash must be a hash of the password');
		$this->assertStringStartsWith('$2y$', $hash);
		$this->assertStringContainsString('deprecated SHA-1 password hash', $this->server->logSince($logOffset));
	}

	public function testNoteIsShownOnlyOnce(): void
	{
		$this->login('legacy');
		$this->assertNull($this->offeredHash($this->http->get('/index.php')->body));
	}

	public function testNoteIsNotShownAfterFailedLogin(): void
	{
		$this->assertNull($this->offeredHash($this->login('legacyx')->body));
		$this->assertStringNotContainsString('<code>', $this->login('legacyx')->body);
	}
}
