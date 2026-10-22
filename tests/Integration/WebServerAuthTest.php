<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\HttpResponse;

/**
 * Authentication by the web server (REQUIRE_WEB_SERVER_AUTH). The built-in server does no authentication
 * and no TLS, so a router sets REMOTE_USER and HTTPS like a web server would, unless the request has the
 * header X-Test-No-User or X-Test-Plain-Http.
 */
final class WebServerAuthTest extends AppTestCase
{
	public const ROUTER = <<<'PHP'
if (!isset($_SERVER['HTTP_X_TEST_NO_USER'])) { $_SERVER['REMOTE_USER'] = 'alice'; }
if (!isset($_SERVER['HTTP_X_TEST_PLAIN_HTTP'])) { $_SERVER['HTTPS'] = 'on'; }
PHP;

	private const URLS = ['/index.php', '/index.php/Home', '/index.php?action=all', '/api.php?task=checkupload&filename=a.png'];

	protected function configOverrides(): array
	{
		return ['REQUIRE_WEB_SERVER_AUTH' => true];
	}

	protected function serverOptions(): array
	{
		return ['router' => self::ROUTER];
	}

	private function assertRefused(HttpResponse $response, string $message): void
	{
		$this->assertSame(403, $response->status);
		$this->assertStringContainsString($message, $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
		$this->assertStringStartsWith('text/plain', (string)$response->header('content-type'));
		$this->assertNull($response->setCookieValue('W2'), 'no session for refused requests');
	}

	public function testAuthenticatedUsersOverHttpsCanUseTheWiki(): void
	{
		$response = $this->http->get('/index.php');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Welcome to W2', $response->body);
		$this->assertSame('max-age=31536000', $response->header('strict-transport-security'));
		$this->assertStringContainsString('false', $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'a.png'])->body);
	}

	public function testRequestsWithoutAuthenticatedUserAreRefused(): void
	{
		foreach (self::URLS as $url) {
			$this->assertRefused($this->http->get($url, [], ['X-Test-No-User: 1']), 'the web server did not authenticate you');
		}
	}

	public function testRequestsOverPlainHttpAreRefused(): void
	{
		foreach (self::URLS as $url) {
			$this->assertRefused($this->http->get($url, [], ['X-Test-Plain-Http: 1']), 'only available over HTTPS');
		}
	}

	public function testHeadersOfTheClientDoNotAuthenticate(): void
	{
		$response = $this->http->get('/index.php', [], [
			'X-Test-No-User: 1',
			'Authorization: Basic ' . base64_encode('alice:secret'),
			'Remote-User: alice',
			'X-Remote-User: alice',
			'X-Forwarded-User: alice',
		]);
		$this->assertSame(403, $response->status);
	}

	public function testChangesAreRefusedWithoutAuthenticatedUser(): void
	{
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'Sneaky', 'newText' => 'x', 'isNew' => 'true'], [], ['X-Test-No-User: 1']);
		$this->assertSame(403, $response->status);
		$this->assertFileDoesNotExist($this->pageFile('Sneaky'));
	}

	public function testRefusedResponsesHaveSecurityHeaders(): void
	{
		$response = $this->http->get('/index.php', [], ['X-Test-No-User: 1']);
		$this->assertSame('DENY', $response->header('x-frame-options'));
		$this->assertStringContainsString("frame-ancestors 'none'", (string)$response->header('content-security-policy'));
	}
}
