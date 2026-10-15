<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** If the records of failed logins can't be stored, nobody can log in (instead of having no protection) */
final class LoginThrottleUnavailableTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
			// below a file, so it can't be created
			'LOGIN_THROTTLE_FOLDER' => '/dev/null/w2-login',
		];
	}

	public function testLoginIsRefusedWithAnExplanation(): void
	{
		$response = $this->login('hunter2');
		$this->assertSame(503, $response->status);
		$this->assertStringContainsString('server configuration problem', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}
}
