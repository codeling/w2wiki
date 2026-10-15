<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** LOGIN_MAX_FAILURES = 0 turns the throttling off, and no files are written */
final class LoginThrottleDisabledTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
			'LOGIN_MAX_FAILURES' => 0,
			'LOGIN_THROTTLE_FOLDER' => '/dev/null/w2-login',
		];
	}

	public function testManyFailuresAreNotLimited(): void
	{
		for ($i = 0; $i < 15; $i++) {
			$this->assertStringContainsString('Wrong password', $this->login('nope')->body);
		}
		$this->login('hunter2');
		$this->assertStringContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}
}
