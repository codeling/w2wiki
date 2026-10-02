<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Unsalted SHA-1 password hashes (as documented in earlier versions) still work */
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
}
