<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use W2\UploadResponse;

/** How file.php answers requests for uploaded files (W2\UploadResponse), tested in a temporary folder */
final class UploadResponseTest extends TestCase
{
	private string $root;
	private string $folder;
	private UploadResponse $response;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/w2-upload-response-test-' . bin2hex(random_bytes(6));
		$this->folder = "$this->root/uploads";
		mkdir($this->folder, 0777, true);
		$this->response = new UploadResponse($this->folder);
	}

	protected function tearDown(): void
	{
		$this->removeTree($this->root);
	}

	private function removeTree(string $folder): void
	{
		foreach (array_diff(scandir($folder), ['.', '..']) as $entry) {
			$path = "$folder/$entry";
			is_dir($path) && !is_link($path) ? $this->removeTree($path) : unlink($path);
		}
		rmdir($folder);
	}

	private function put(string $name, string $content = 'x', int $mtime = 1700000000): void
	{
		file_put_contents("$this->folder/$name", $content);
		touch("$this->folder/$name", $mtime);
	}

	private function get(string $name, bool $versioned = false, ?string $ifNoneMatch = null, ?string $ifModifiedSince = null, string $method = 'GET'): array
	{
		return $this->response->respond($name, $method, $versioned, $ifNoneMatch, $ifModifiedSince);
	}

	public function testAnImageIsSentWithItsTypeAndHeaders(): void
	{
		$this->put('a.gif', 'GIF89a');
		$answer = $this->get('a.gif');
		$this->assertSame(200, $answer['status']);
		$this->assertSame("$this->folder/a.gif", $answer['file']);
		$headers = $answer['headers'];
		$this->assertSame('image/gif', $headers['Content-Type']);
		$this->assertSame("inline; filename*=UTF-8''a.gif", $headers['Content-Disposition']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertSame('6', $headers['Content-Length']);
		$this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $headers['Last-Modified']);
		$this->assertArrayHasKey('Content-Security-Policy', $headers);
		$this->assertNull($headers['Content-Security-Policy'], 'the policy of the pages is removed');
	}

	public static function types(): array
	{
		return [
			['a.jpg', 'image/jpeg', 'inline'], ['a.JPEG', 'image/jpeg', 'inline'], ['a.png', 'image/png', 'inline'], ['a.webp', 'image/webp', 'inline'],
			['a.heic', 'image/heic', 'inline'], ['a.bmp', 'image/bmp', 'inline'], ['a.pdf', 'application/pdf', 'inline'],
			['a.svg', 'image/svg+xml', 'inline'], ['a.txt', 'text/plain; charset=UTF-8', 'inline'], ['a.mp4', 'video/mp4', 'inline'],
			// (whatever the content is: types are never guessed)
			['a.html', 'application/octet-stream', 'attachment'], ['a.php', 'application/octet-stream', 'attachment'],
			['a.js', 'application/octet-stream', 'attachment'], ['a.xml', 'application/octet-stream', 'attachment'],
			['noextension', 'application/octet-stream', 'attachment'], ['a.gif.html', 'application/octet-stream', 'attachment'],
		];
	}

	#[DataProvider('types')]
	public function testTheTypeIsTakenFromTheExtension(string $name, string $type, string $disposition): void
	{
		$this->put($name, '<html><script>alert(1)</script></html>');
		$headers = $this->get($name)['headers'];
		$this->assertSame($type, $headers['Content-Type']);
		$this->assertStringStartsWith("$disposition;", $headers['Content-Disposition']);
	}

	public function testSvgFilesAreSandboxed(): void
	{
		$this->put('s.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
		$this->assertSame(UploadResponse::SVG_POLICY, $this->get('s.svg')['headers']['Content-Security-Policy']);
		$this->assertStringContainsString('sandbox', UploadResponse::SVG_POLICY);
		$this->assertStringContainsString("default-src 'none'", UploadResponse::SVG_POLICY);
	}

	public function testTheNameIsEncodedInTheDisposition(): void
	{
		$this->put('Grüße "x";.png');
		$this->assertSame("inline; filename*=UTF-8''Gr%C3%BC%C3%9Fe%20%22x%22%3B.png", $this->get('Grüße "x";.png')['headers']['Content-Disposition']);
	}

	public static function refusedNames(): array
	{
		return [
			'empty' => [''], 'hidden file' => ['.htaccess'], 'parent folder' => ['..'], 'folder' => ['sub'], 'in a subfolder' => ['sub/a.gif'],
			'outside' => ['../outside.gif'], 'backslash' => ['sub\\a.gif'], 'null byte' => ["a.gif\0.png"], 'missing' => ['missing.gif'],
			'link' => ['link.gif'],
		];
	}

	#[DataProvider('refusedNames')]
	public function testOnlyRegularFilesDirectlyInTheFolderAreSent(string $name): void
	{
		$this->put('.htaccess', 'Require all denied');
		mkdir("$this->folder/sub");
		file_put_contents("$this->folder/sub/a.gif", 'GIF89a');
		file_put_contents("$this->root/outside.gif", 'secret');
		symlink("$this->root/outside.gif", "$this->folder/link.gif");
		$answer = $this->get($name);
		$this->assertSame(404, $answer['status']);
		$this->assertNull($answer['file']);
		$this->assertSame('no-store', $answer['headers']['Cache-Control']);
	}

	public function testVersionedAddressesAreCachedLongAndPrivately(): void
	{
		$this->put('a.gif');
		$this->assertSame('private, max-age=31536000, immutable', $this->get('a.gif', true)['headers']['Cache-Control']);
		$this->assertSame('private, no-cache', $this->get('a.gif')['headers']['Cache-Control']);
	}

	public function testUnchangedFilesAreAnsweredWithNotModified(): void
	{
		$this->put('a.gif', 'GIF89a', 1700000000);
		$etag = $this->get('a.gif')['headers']['ETag'];
		$this->assertMatchesRegularExpression('/^"[0-9a-f]+-[0-9a-f]+"$/', $etag);

		foreach ([$etag, "W/$etag", "\"other\", $etag", '*'] as $ifNoneMatch) {
			$answer = $this->get('a.gif', false, $ifNoneMatch);
			$this->assertSame(304, $answer['status'], $ifNoneMatch);
			$this->assertNull($answer['file']);
			$this->assertArrayNotHasKey('Content-Length', $answer['headers']);
		}
		$this->assertSame(200, $this->get('a.gif', false, '"other"')['status']);
		$this->assertSame(304, $this->get('a.gif', false, null, 'Tue, 14 Nov 2023 22:13:20 GMT')['status']);
		$this->assertSame(200, $this->get('a.gif', false, null, 'Tue, 14 Nov 2023 22:13:19 GMT')['status']);
		$this->assertSame(200, $this->get('a.gif', false, null, 'no date')['status']);
		// (If-None-Match decides if it is given)
		$this->assertSame(200, $this->get('a.gif', false, '"other"', 'Tue, 14 Nov 2023 22:13:20 GMT')['status']);

		touch("$this->folder/a.gif", 1700000100);
		clearstatcache();
		$this->assertSame(200, $this->get('a.gif', false, $etag)['status'], 'a changed file has another tag');
	}

	public function testHeadSendsTheHeadersOnly(): void
	{
		$this->put('a.gif', 'GIF89a');
		$answer = $this->get('a.gif', false, null, null, 'HEAD');
		$this->assertSame(200, $answer['status']);
		$this->assertNull($answer['file']);
		$this->assertSame('6', $answer['headers']['Content-Length']);
	}

	public function testOtherMethodsAreRefused(): void
	{
		$this->put('a.gif');
		$answer = $this->get('a.gif', false, null, null, 'POST');
		$this->assertSame(405, $answer['status']);
		$this->assertSame('GET, HEAD', $answer['headers']['Allow']);
		$this->assertNull($answer['file']);
	}
}
