<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Tests connect from 127.0.0.1 */
final class IpAllowlistDeniedTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		// 127.0.0.10 must not be mistaken for a prefix of (or equal to) 127.0.0.1
		return ['$allowedIPs' => ['10.0.0.0/8', '127.0.0.10', '::1', '192.168.0.0/16']];
	}

	public function testOtherAddressesAreRefused(): void
	{
		foreach (['/index.php', '/index.php/Home', '/api.php?task=checkupload&filename=a.png'] as $url) {
			$response = $this->http->get($url);
			$this->assertSame(403, $response->status, $url);
			$this->assertStringContainsString('127.0.0.1 is not allowed', $response->body);
			$this->assertStringNotContainsString('Welcome to W2', $response->body);
		}
	}

	public function testRefusedResponsesHaveSecurityHeaders(): void
	{
		$response = $this->http->get('/index.php');
		$this->assertSame('DENY', $response->header('x-frame-options'));
		$this->assertStringContainsString("frame-ancestors 'none'", (string)$response->header('content-security-policy'));
	}

	public function testChangesAreRefusedToo(): void
	{
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'Sneaky', 'newText' => 'x', 'isNew' => 'true']);
		$this->assertSame(403, $response->status);
		$this->assertFileDoesNotExist($this->pageFile('Sneaky'));
	}
}
