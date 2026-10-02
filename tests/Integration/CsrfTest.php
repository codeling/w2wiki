<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\CurlFileLike;

/** State-changing requests need POST and the session's CSRF token */
final class CsrfTest extends AppTestCase
{
	public function testSavingWithGetRequestIsRefused(): void
	{
		$token = $this->csrfToken();
		$response = $this->http->get('/index.php', [
			'action' => 'save', 'page' => 'Home', 'newText' => 'pwned', 'csrf_token' => $token,
		]);
		$this->assertSame(403, $response->status);
		$this->assertStringNotContainsString('pwned', $this->pageText('Home'));
	}

	public function testSavingWithoutTokenIsRefused(): void
	{
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'Home', 'newText' => 'pwned']);
		$this->assertSame(403, $response->status);
		$this->assertStringNotContainsString('pwned', $this->pageText('Home'));
	}

	public function testSavingWithWrongTokenIsRefused(): void
	{
		$this->csrfToken();
		$response = $this->http->post('/index.php', [
			'action' => 'save', 'page' => 'Home', 'newText' => 'pwned', 'csrf_token' => str_repeat('a', 64),
		]);
		$this->assertSame(403, $response->status);
		$this->assertStringNotContainsString('pwned', $this->pageText('Home'));
	}

	public function testTokenOfAnotherSessionIsRefused(): void
	{
		$otherToken = $this->csrfToken($this->newClient());
		$this->csrfToken();
		$response = $this->http->post('/index.php', [
			'action' => 'save', 'page' => 'Home', 'newText' => 'pwned', 'csrf_token' => $otherToken,
		]);
		$this->assertSame(403, $response->status);
	}

	public function testSavingWithValidTokenWorks(): void
	{
		$response = $this->savePage('Home', 'changed', false);
		$this->assertSame(303, $response->status);
		$this->assertSame('changed', $this->pageText('Home'));
	}

	public static function protectedActions(): array
	{
		return [
			'save' => ['save', ['page' => 'Home', 'newText' => 'pwned', 'isNew' => '']],
			'rename' => ['renamed', ['oldPageName' => 'Home', 'newName' => 'Gone']],
			'delete' => ['deleted', ['oldPageName' => 'Home']],
			'image rename' => ['imgRenamed', ['oldPageName' => 'a.gif', 'newName' => 'b.gif']],
			'image delete' => ['imgDeleted', ['oldPageName' => 'a.gif']],
			'upload' => ['uploaded', []],
		];
	}

	#[DataProvider('protectedActions')]
	public function testActionsRequirePostAndToken(string $action, array $fields): void
	{
		file_put_contents($this->imageFile('a.gif'), 'GIF89a');
		$token = $this->csrfToken();

		$attempts = [
			'GET with token' => $this->http->get('/index.php', ['action' => $action, 'csrf_token' => $token] + $fields),
			'POST without token' => $this->http->post('/index.php', ['action' => $action] + $fields),
			'POST with wrong token' => $this->http->post('/index.php', ['action' => $action, 'csrf_token' => 'wrong'] + $fields),
		];
		foreach ($attempts as $description => $response) {
			$this->assertSame(403, $response->status, "$action: $description");
		}
		$this->assertFileExists($this->imageFile('a.gif'));
		$this->assertFileExists($this->pageFile('Home'));
		$this->assertStringNotContainsString('pwned', $this->pageText('Home'));
		$this->assertFileDoesNotExist($this->pageFile('Gone'));
		$this->assertFileDoesNotExist($this->imageFile('b.gif'));
	}

	public function testUploadWithoutTokenIsRefused(): void
	{
		$gif = new CurlFileLike(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'a.gif', 'image/gif');
		$this->csrfToken();
		$response = $this->http->post('/index.php', ['action' => 'uploaded', 'userfile' => $gif]);
		$this->assertSame(403, $response->status);
		$this->assertSame([], $this->uploadedFiles());
	}

	public static function formActions(): array
	{
		return [
			'new page' => [['action' => 'new']],
			'edit' => [['action' => 'edit', 'page' => 'Home']],
			'rename' => [['action' => 'rename', 'page' => 'Home']],
			'delete' => [['action' => 'delete', 'page' => 'Home']],
			'upload' => [['action' => 'upload']],
			'image rename' => [['action' => 'imgRename', 'imgName' => 'a.gif']],
			'image delete' => [['action' => 'imgDelete', 'imgName' => 'a.gif']],
		];
	}

	#[DataProvider('formActions')]
	public function testFormsContainTheSessionToken(array $query): void
	{
		$token = $this->csrfToken();
		$body = $this->http->get('/index.php', $query)->body;
		$this->assertStringContainsString('name="csrf_token" value="' . $token . '"', $body);
	}

	public function testLogoutNeedsToken(): void
	{
		$withoutToken = $this->http->get('/index.php', ['action' => 'logout']);
		$this->assertSame(200, $withoutToken->status, 'logout without token only shows the page');

		$withToken = $this->http->get('/index.php', ['action' => 'logout', 'csrf_token' => $this->csrfToken()]);
		$this->assertSame(302, $withToken->status);
	}
}
