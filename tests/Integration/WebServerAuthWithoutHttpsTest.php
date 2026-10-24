<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Authentication by the web server without the HTTPS requirement (WEB_SERVER_AUTH_REQUIRE_HTTPS), and in German */
final class WebServerAuthWithoutHttpsTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['REQUIRE_WEB_SERVER_AUTH' => true, 'WEB_SERVER_AUTH_REQUIRE_HTTPS' => false, 'W2_LOCALE' => 'de'];
	}

	protected function serverOptions(): array
	{
		// (the session cookie is only sent back over plain HTTP if the request is not marked as HTTPS)
		return ['router' => WebServerAuthTest::ROUTER . "\nunset(\$_SERVER['HTTPS']);"];
	}

	public function testAuthenticatedUsersCanEdit(): void
	{
		$this->assertSame(200, $this->http->get('/index.php')->status);
		$this->savePage('New Page', 'Some text');
		$this->assertSame('Some text', $this->pageText('New Page'));
	}

	public function testRequestsWithoutAuthenticatedUserAreRefused(): void
	{
		$response = $this->http->get('/index.php', [], ['X-Test-No-User: 1']);
		$this->assertSame(403, $response->status);
		$this->assertSame('Zugriff verweigert: Der Webserver hat dich nicht angemeldet.', $response->body);
	}
}
