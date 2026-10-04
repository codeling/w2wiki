<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\ThrottleTestCase;

/** Behind a trusted reverse proxy, clients are told apart by X-Forwarded-For; 4 failures per hour in total */
final class LoginThrottleProxyTest extends ThrottleTestCase
{
	protected function configOverrides(): array
	{
		return parent::configOverrides() + [
			'LOGIN_MAX_FAILURES' => 2,
			'LOGIN_MAX_FAILURES_PER_HOUR' => 4,
			'$trustedProxies' => ['127.0.0.1'],
		];
	}

	private function loginAs(string $address, string $password): \W2\Tests\Support\HttpResponse
	{
		return $this->login($password, $this->clients[$address] ??= $this->newClient(), null, ["X-Forwarded-For: $address"]);
	}

	/** @var array<string, \W2\Tests\Support\HttpClient> */
	private array $clients = [];

	public function testClientsBehindTheProxyAreCountedSeparately(): void
	{
		$this->loginAs('198.51.100.1', 'nope');
		$this->loginAs('198.51.100.1', 'nope');
		$this->assertLockedOut($this->loginAs('198.51.100.1', 'hunter2'));

		$response = $this->loginAs('198.51.100.2', 'hunter2');
		$this->assertSame(200, $response->status);
		$this->assertLoggedIn($this->clients['198.51.100.2']);
	}

	public function testAddressesWrittenByTheClientAreIgnored(): void
	{
		// the proxy appends the real address of its client to what the client sent
		$headers = fn(string $fake) => ["X-Forwarded-For: $fake, 198.51.100.7"];
		for ($i = 0; $i < 2; $i++) {
			$this->login('nope', null, null, $headers("6.6.6.$i"));
		}
		$this->assertLockedOut($this->login('nope', null, null, $headers('6.6.6.99')));
	}

	public function testGlobalLimitStopsEveryone(): void
	{
		for ($i = 1; $i <= 4; $i++) {
			$this->assertStringContainsString('Wrong password', $this->loginAs("198.51.100.$i", 'nope')->body);
		}
		// nobody has been locked out individually, but too many failures have been made in total
		$this->assertLockedOut($this->loginAs('198.51.100.50', 'hunter2'));
		$this->assertNotLoggedIn($this->clients['198.51.100.50']);
	}
}
