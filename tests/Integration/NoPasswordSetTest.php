<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** With REQUIRE_PASSWORD but no W2_PASSWORD_HASH, nobody can log in, and the login page says why */
final class NoPasswordSetTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true];
	}

	public function testLoginIsRefusedUntilAPasswordIsSet(): void
	{
		foreach (['secret', 'x'] as $password) {
			$response = $this->login($password);
			$this->assertStringNotContainsString('Welcome to W2', $response->body);
			$this->assertStringContainsString('Login is disabled until a password is set', $response->body);
			$this->assertStringNotContainsString('<code>', $response->body);
		}
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}
}
