<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

final class IpAllowlistTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['$allowedIPs' => ['10.0.0.0/8', '127.0.0.0/8']];
	}

	public function testAddressInsideAllowedRangeHasAccess(): void
	{
		$response = $this->http->get('/index.php');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Welcome to W2', $response->body);
	}
}
