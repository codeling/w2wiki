<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\HttpResponse;

/** Password protection with a password_hash() hash */
final class AuthTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
		];
	}

	private function assertLoginForm(HttpResponse $response): void
	{
		$this->assertStringContainsString('name="p"', $response->body);
		$this->assertStringNotContainsString('Welcome to W2', $response->body);
	}

	public function testWikiIsHiddenWithoutLogin(): void
	{
		foreach (['/index.php', '/index.php/Home', '/index.php?action=all', '/index.php?action=search&q=welcome'] as $url) {
			$this->assertLoginForm($this->http->get($url));
		}
	}

	public function testWrongPasswordsAreRefused(): void
	{
		$response = $this->login('nope');
		$this->assertStringContainsString('Wrong password', $response->body);
		$this->assertLoginForm($response);
		$this->assertLoginForm($this->http->get('/index.php'));
	}

	public function testLoginFormHasTheCsrfToken(): void
	{
		$body = $this->http->get('/index.php')->body;
		$this->assertSame(1, preg_match('/<form method="post">.*name="csrf_token" value="([0-9a-f]{64})".*<\/form>/s', $body, $matches));
		$this->assertSame($matches[1], $this->csrfToken());
	}

	public function testLoginWithoutTokenIsRefused(): void
	{
		$this->http->get('/index.php');
		$response = $this->http->post('/index.php', ['p' => 'hunter2']);
		$this->assertLoginForm($response);
		$this->assertStringContainsString('session has expired', $response->body);
		$this->assertLoginForm($this->http->get('/index.php'));
	}

	public function testLoginWithWrongTokenIsRefused(): void
	{
		$response = $this->login('hunter2', null, str_repeat('a', 64));
		$this->assertLoginForm($response);
		$this->assertStringContainsString('session has expired', $response->body);
		$this->assertLoginForm($this->http->get('/index.php'));
	}

	public function testLoginWithTokenOfAnotherSessionIsRefused(): void
	{
		$otherToken = $this->csrfToken($this->newClient());
		$this->csrfToken();
		$this->assertLoginForm($this->login('hunter2', null, $otherToken));
		$this->assertLoginForm($this->http->get('/index.php'));
	}

	public function testRequestsWithoutTokenAreNotCountedAsFailedLogins(): void
	{
		// (they don't check the password; the limit of 5 failures would be reached otherwise)
		for ($i = 0; $i < 8; $i++) {
			$this->http->post('/index.php', ['p' => 'nope']);
		}
		$this->assertSame(200, $this->login('nope')->status);
	}

	public function testEmptyPasswordIsRefused(): void
	{
		$this->assertLoginForm($this->login(''));
	}

	public function testCorrectPasswordGivesAccess(): void
	{
		$response = $this->login('hunter2');
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Welcome to W2', $response->body);
		$this->assertStringContainsString('Welcome to W2', $this->http->get('/index.php')->body);
	}

	public function testSessionIsRegeneratedOnLogin(): void
	{
		$before = $this->http->get('/index.php');
		$tokenBefore = $this->extractToken($this->http->get('/index.php?action=new')->body);
		$after = $this->login('hunter2');
		$sessionBefore = $before->setCookieValue('W2');
		$sessionAfter = $after->setCookieValue('W2');
		$this->assertNotNull($sessionBefore);
		$this->assertNotNull($sessionAfter, 'a new session cookie must be sent on login');
		$this->assertNotSame($sessionBefore, $sessionAfter);
		$this->assertNotSame($tokenBefore, $this->csrfToken(), 'the CSRF token must change on login');
	}

	public function testSessionCookieIsHttpOnlyAndSameSite(): void
	{
		$cookies = $this->http->get('/index.php')->headerValues('set-cookie');
		$sessionCookies = array_values(array_filter($cookies, fn($c) => str_starts_with($c, 'W2=')));
		$this->assertCount(1, $sessionCookies);
		$this->assertMatchesRegularExpression('/;\s*HttpOnly/i', $sessionCookies[0]);
		$this->assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $sessionCookies[0]);
		$this->assertDoesNotMatchRegularExpression('/;\s*Secure/i', $sessionCookies[0], 'no Secure flag over plain HTTP');
	}

	public function testForwardedProtoHeaderDoesNotMakeTheSessionCookieSecure(): void
	{
		// any client can send the header, so only the web server's own HTTPS setting counts
		$cookies = $this->http->get('/index.php', [], ['X-Forwarded-Proto: https', 'X-Forwarded-Ssl: on'])
			->headerValues('set-cookie');
		$sessionCookies = array_values(array_filter($cookies, fn($c) => str_starts_with($c, 'W2=')));
		$this->assertCount(1, $sessionCookies);
		$this->assertDoesNotMatchRegularExpression('/;\s*Secure/i', $sessionCookies[0]);
	}

	public function testWikiCanBeUsedAfterLogin(): void
	{
		$this->login('hunter2');
		$this->assertSame(303, $this->savePage('After Login', 'text')->status);
		$this->assertFileExists($this->pageFile('After Login'));
	}

	public function testSavingWithoutLoginIsRefused(): void
	{
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'Sneaky', 'newText' => 'x', 'isNew' => 'true']);
		$this->assertFileDoesNotExist($this->pageFile('Sneaky'));
		$this->assertNotSame(303, $response->status);
	}

	public function testLogoutEndsTheSession(): void
	{
		$this->login('hunter2');
		$response = $this->http->get('/index.php', ['action' => 'logout', 'csrf_token' => $this->csrfToken()]);
		$this->assertSame(302, $response->status);
		$this->assertLoginForm($this->http->get('/index.php'));
	}

	public function testApiRequiresLogin(): void
	{
		$url = '/api.php?task=checkupload&filename=a.png';
		file_put_contents($this->imageFile('a.png'), 'x');
		$response = $this->http->get($url);
		$this->assertSame(403, $response->status);
		$this->assertStringNotContainsString('true', $response->body);

		$this->login('hunter2');
		$response = $this->http->get($url);
		$this->assertSame(200, $response->status);
		$this->assertSame('true', trim($response->body));
	}

	public function testPlainPasswordSettingIsNotUsedWhenAHashIsConfigured(): void
	{
		$this->assertLoginForm($this->login('secret'));
	}

	private function extractToken(string $html): string
	{
		preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches);
		return $matches[1] ?? '';
	}
}
