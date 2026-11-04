<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\HttpResponse;

/** The preview of the editor (api.php?task=preview) with a password: only for those who are logged in */
final class PreviewAuthTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
		];
	}

	private function preview(string $text): HttpResponse
	{
		// (the login page has a token too)
		return $this->http->post('/api.php', ['csrf_token' => $this->csrfToken(), 'newText' => $text], ['task' => 'preview']);
	}

	private function assertNotLoggedIn(HttpResponse $response): void
	{
		$this->assertSame(403, $response->status);
		$answer = json_decode($response->body, true);
		$this->assertSame('not logged in', $answer['error'] ?? null);
		$this->assertStringContainsString('You are not logged in', $answer['message'] ?? '');
		$this->assertStringNotContainsString('Secret', $response->body);
	}

	public function testThePreviewNeedsALogin(): void
	{
		$this->assertNotLoggedIn($this->preview('# Secret [[Home]]'));

		$this->login('hunter2');
		$response = $this->preview('# Secret [[Home]]');
		$this->assertSame(200, $response->status, $response->body);
		$this->assertStringContainsString('<a id=\"Secret-Home\">Secret <a href=\"/index.php/Home\">Home</a></a>', $response->body);

		$this->http->get('/index.php', ['action' => 'logout', 'csrf_token' => $this->csrfToken()]);
		$this->assertNotLoggedIn($this->preview('# Secret'));
	}

	public function testGetRequestsWithoutLoginAreRefusedAsNotLoggedIn(): void
	{
		$this->assertNotLoggedIn($this->http->get('/api.php', ['task' => 'preview', 'newText' => '# Secret']));
	}
}
