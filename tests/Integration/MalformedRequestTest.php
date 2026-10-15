<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** Requests with parameters of the wrong kind are refused with an error status instead of crashing PHP (HTTP 500) */
final class MalformedRequestTest extends AppTestCase
{
	public static function getRequests(): array
	{
		return [
			'search array' => ['/index.php?action=search&q[]=a'],
			'page array' => ['/index.php?action=view&page[]=a'],
			'action array' => ['/index.php?action[]=view'],
			'sortBy array' => ['/index.php?action=all&sortBy[]=name'],
			'null byte in page' => ['/index.php?action=view&page=a%00b'],
			'null byte in path' => ['/index.php/a%00b'],
			'api filename array' => ['/api.php?task=checkupload&filename[]=a.gif'],
			'api null byte' => ['/api.php?task=checkupload&filename=a%00.gif'],
		];
	}

	#[DataProvider('getRequests')]
	public function testMalformedGetRequestsAreRefused(string $url): void
	{
		$response = $this->http->get($url);
		$this->assertSame(400, $response->status);
		$this->assertStringNotContainsString('Fatal error', $response->body);
		$this->assertStringNotContainsString('Stack trace', $response->body);
	}

	public static function postRequests(): array
	{
		return [
			'new text array' => ['save', ['page' => 'X', 'isNew' => 'true', 'newText' => ['a']]],
			'page array' => ['save', ['page' => ['X'], 'isNew' => 'true', 'newText' => 'a']],
			'null byte in page' => ['save', ['page' => "X\0Y", 'isNew' => 'true', 'newText' => 'a']],
			'rename arrays' => ['renamed', ['oldPageName' => ['Home'], 'newName' => ['X']]],
			'delete null byte' => ['deleted', ['oldPageName' => "Home\0"]],
			'image rename array' => ['imgRenamed', ['oldPageName' => ['a.gif'], 'newName' => 'b.gif', 'prevpage' => 'Home']],
		];
	}

	#[DataProvider('postRequests')]
	public function testMalformedPostRequestsAreRefusedWithoutChanges(string $action, array $fields): void
	{
		$before = $this->server->pageFiles();
		$response = $this->postAction($action, $fields);
		$this->assertSame(400, $response->status);
		$this->assertSame($before, $this->server->pageFiles());
	}

	public function testRequestsWithoutTheExpectedFieldsDoNotCrash(): void
	{
		foreach (['renamed', 'deleted', 'imgDeleted', 'imgRenamed'] as $action) {
			$response = $this->postAction($action, ['prevpage' => 'Home']);
			$this->assertContains($response->status, [303, 400], $action);
		}
		$this->assertSame(200, $this->http->get('/index.php?action=search')->status, 'search without a term');
		$this->assertSame(200, $this->http->get('/index.php?action=imgDelete&prevpage=Home')->status);
	}

	public function testUploadWithoutFileIsReportedNotCrashing(): void
	{
		$response = $this->postAction('uploaded', ['prevpage' => 'Home']);
		$this->assertSame(303, $response->status);
		$this->assertStringContainsString('No file selected', $this->noteAfter($response));
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testUploadedFileMustBeASingleFile(): void
	{
		$response = $this->postAction('uploaded', [
			'userfile[]' => new \W2\Tests\Support\CurlFileLike(self::gif(), 'a.gif', 'image/gif'),
			'prevpage' => 'Home',
		]);
		$this->assertContains($response->status, [303, 400]);
		$this->assertSame([], $this->uploadedFiles());
	}
}
