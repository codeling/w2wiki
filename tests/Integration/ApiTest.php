<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

final class ApiTest extends AppTestCase
{
	public function testCheckUploadReportsExistingFiles(): void
	{
		file_put_contents($this->imageFile('a.png'), 'x');
		$response = $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'a.png']);
		$this->assertSame('true', trim($response->body));
		$this->assertStringContainsString('json', (string)$response->header('content-type'));
		$this->assertSame('false', trim($this->http->get('/api.php', ['task' => 'checkupload', 'filename' => 'b.png'])->body));
	}

	public static function traversalNames(): array
	{
		return [
			'parent folders' => ['../../../../../../etc/passwd'],
			'parent page folder' => ['../Home.md'],
			'parent page folder with backslashes' => ['..\\Home.md'],
			'absolute path' => ['/etc/passwd'],
			'folder itself' => ['.'],
			'empty' => [''],
		];
	}

	#[DataProvider('traversalNames')]
	public function testCheckUploadOnlyLooksIntoTheUploadsFolder(string $name): void
	{
		$response = $this->http->get('/api.php', ['task' => 'checkupload', 'filename' => $name]);
		$this->assertSame('false', trim($response->body));
	}

	public function testMissingParametersDoNotCauseErrors(): void
	{
		foreach ([[], ['task' => 'checkupload'], ['task' => 'unknown']] as $query) {
			$this->assertSame(200, $this->http->get('/api.php', $query)->status);
		}
	}
}
