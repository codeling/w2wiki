<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\ThrottleTestCase;

/** Failed logins are limited per client address (3 failures, then a lockout) */
final class LoginThrottleTest extends ThrottleTestCase
{
	protected function configOverrides(): array
	{
		return parent::configOverrides() + ['LOGIN_MAX_FAILURES' => 3];
	}

	public function testWrongPasswordsAreAnsweredQuickly(): void
	{
		$response = $this->login('nope');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Wrong password', $response->body);
		$this->assertLessThan(1.5, $response->seconds, 'no sleep() any more');
	}

	public function testClientIsLockedOutAfterTheAllowedFailures(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->assertStringContainsString('Wrong password', $this->login('nope')->body);
		}
		$response = $this->login('nope');
		$this->assertLockedOut($response);
		$this->assertLessThanOrEqual(60, (int)$response->header('retry-after'));
	}

	public function testCorrectPasswordIsRefusedDuringTheLockout(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->login('nope');
		}
		$this->assertLockedOut($this->login('hunter2'));
		$this->assertNotLoggedIn();
	}

	public function testLockoutAppliesToNewSessions(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->login('nope');
		}
		// a new client has no cookies, so this is a new session
		$other = $this->newClient();
		$this->assertLockedOut($this->login('hunter2', $other));
		$this->assertNotLoggedIn($other);
	}

	public function testAttemptsWithNewSessionsAreCountedToo(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->assertStringContainsString('Wrong password', $this->login('nope', $this->newClient())->body);
		}
		$this->assertLockedOut($this->login('nope', $this->newClient()));
	}

	public function testLockedOutClientCanStillViewTheLoginForm(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->login('nope');
		}
		$response = $this->http->get('/index.php');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('name="p"', $response->body);
	}

	public function testCorrectPasswordResetsTheCount(): void
	{
		for ($round = 0; $round < 3; $round++) {
			$this->login('nope');
			$this->login('nope');
			$client = $this->newClient();
			$this->login('hunter2', $client);
			$this->assertLoggedIn($client);
		}
	}

	public function testForwardedHeaderDoesNotHelpWithoutTrustedProxies(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->login('nope', null, null, ["X-Forwarded-For: 198.51.100.$i"]);
		}
		$this->assertLockedOut($this->login('nope', null, null, ['X-Forwarded-For: 198.51.100.99']));
	}

	public function testLockoutIsNotShownForOtherRequests(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->login('nope');
		}
		$this->assertSame(403, $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'a.png'])->status);
	}
}
