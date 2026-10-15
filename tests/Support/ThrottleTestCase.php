<?php

namespace W2\Tests\Support;

/**
 * Base class for tests of the throttling of failed logins: a wiki with a password (hunter2), and a
 * folder for the throttle records of its own which is emptied before and after every test
 */
abstract class ThrottleTestCase extends AppTestCase
{
	protected function throttleFolder(): string
	{
		return sys_get_temp_dir() . '/w2-throttle-it-' . getmypid() . '-' . md5(static::class);
	}

	/** @return array<string, mixed> */
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
			'LOGIN_THROTTLE_FOLDER' => $this->throttleFolder(),
		];
	}

	protected function setUp(): void
	{
		$this->clearThrottleFolder();
		parent::setUp();
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		$this->clearThrottleFolder();
		@rmdir($this->throttleFolder());
	}

	private function clearThrottleFolder(): void
	{
		foreach (glob($this->throttleFolder() . '/*') ?: [] as $file) {
			unlink($file);
		}
	}

	protected function assertLockedOut(HttpResponse $response): void
	{
		$this->assertSame(429, $response->status);
		$this->assertStringContainsString('Too many failed login attempts', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
		$this->assertMatchesRegularExpression('/^\d+$/', (string)$response->header('retry-after'));
		$this->assertGreaterThan(0, (int)$response->header('retry-after'));
	}

	protected function assertLoggedIn(?HttpClient $client = null): void
	{
		$this->assertStringContainsString('Welcome to W2', ($client ?? $this->http)->get('/index.php')->body);
	}

	protected function assertNotLoggedIn(?HttpClient $client = null): void
	{
		$this->assertStringNotContainsString('Welcome to W2', ($client ?? $this->http)->get('/index.php')->body);
	}
}
