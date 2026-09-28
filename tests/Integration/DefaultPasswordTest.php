<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The default password must not be usable */
final class DefaultPasswordTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_PASSWORD' => true];
	}

	public function testLoginIsRefusedWhileTheDefaultPasswordIsConfigured(): void
	{
		$response = $this->login('secret');
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
		$this->assertStringContainsString('Login is disabled', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}
}
