<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Hashes that look like numbers in scientific notation must not match each other ("0e..." == "0e...") */
final class AuthMagicHashTest extends AppTestCase
{
	// sha1('aaroZmOk') and sha1('aaK1STfY') are both "0e" followed by digits only
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true, 'W2_PASSWORD_HASH' => sha1('aaroZmOk')];
	}

	public function testAnotherPasswordWithAMagicHashIsRefused(): void
	{
		$this->assertMatchesRegularExpression('/^0e\d+$/', sha1('aaK1STfY'));
		$this->assertMatchesRegularExpression('/^0e\d+$/', sha1('aaroZmOk'));
		$this->assertStringContainsString('Wrong password', $this->login('aaK1STfY')->body);
		// (the right password is only shown the hash to replace the SHA-1 hash with, see AuthLegacyHashTest)
		$this->assertStringContainsString('<code>$2y$', $this->login('aaroZmOk')->body);
	}
}
