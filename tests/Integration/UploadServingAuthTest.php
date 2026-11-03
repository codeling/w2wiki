<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** With a password, uploaded files are only served to users who are logged in, like the pages */
final class UploadServingAuthTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
		];
	}

	public function testFilesNeedALogin(): void
	{
		file_put_contents($this->imageFile('secret.gif'), self::gif());
		$response = $this->http->get('/file.php', ['name' => 'secret.gif']);
		$this->assertSame(403, $response->status);
		$this->assertStringNotContainsString(self::gif(), $response->body);
		$this->assertSame('no-store', $response->header('cache-control'));

		$this->login('hunter2');
		$response = $this->http->get('/file.php', ['name' => 'secret.gif']);
		$this->assertSame(200, $response->status);
		$this->assertSame(self::gif(), $response->body);
		$this->assertStringStartsWith('private', (string)$response->header('cache-control'), 'not for shared caches');

		// (the session still works for the pages afterwards: it was only read)
		$this->assertStringContainsString('Welcome to W2', $this->http->get('/index.php')->body);
		$this->http->get('/index.php', ['action' => 'logout', 'csrf_token' => $this->csrfToken()]);
		$this->assertSame(403, $this->http->get('/file.php', ['name' => 'secret.gif'])->status);
	}

	public function testAnotherSessionIsNotLoggedIn(): void
	{
		file_put_contents($this->imageFile('secret.gif'), self::gif());
		$this->login('hunter2');
		$this->assertSame(200, $this->http->get('/file.php', ['name' => 'secret.gif'])->status);
		$this->assertSame(403, $this->newClient()->get('/file.php', ['name' => 'secret.gif'])->status);
	}
}
