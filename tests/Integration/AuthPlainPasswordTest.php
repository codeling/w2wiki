<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** A password configured in W2_PASSWORD */
final class AuthPlainPasswordTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true, 'W2_PASSWORD' => 'correct horse'];
	}

	public function testCorrectPasswordIsAccepted(): void
	{
		$this->assertStringContainsString('Welcome to W2', $this->login('correct horse')->body);
	}

	public function testWrongPasswordIsRefused(): void
	{
		$this->assertStringContainsString('Wrong password', $this->login('correct horsf')->body);
	}
}
