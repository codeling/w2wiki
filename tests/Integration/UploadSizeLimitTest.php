<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Uploads larger than the PHP limits post_max_size and upload_max_filesize */
final class UploadSizeLimitTest extends AppTestCase
{
	protected function serverOptions(): array
	{
		return ['phpIni' => ['post_max_size' => '100K', 'upload_max_filesize' => '40K']];
	}

	/** a GIF with padding after the image data, which is still a valid image */
	private static function gifOfSize(int $bytes): string
	{
		return self::gif() . str_repeat("\0", $bytes - strlen(self::gif()));
	}

	public function testUploadAboveUploadMaxFilesizeIsRefusedWithTheLimit(): void
	{
		$pagesBefore = $this->server->pageFiles();
		$note = $this->noteAfter($this->upload('big.gif', self::gifOfSize(60 * 1024)));
		$this->assertStringContainsString('Upload error: the file is larger than the allowed 40K (upload_max_filesize)', $note);
		$this->assertStringNotContainsString('error #', $note);
		$this->assertSame([], $this->uploadedFiles());
		$this->assertSame($pagesBefore, $this->server->pageFiles());
	}

	public function testUploadAbovePostMaxSizeIsRefusedWithTheLimit(): void
	{
		$this->allowPhpErrors = true; // PHP logs "POST Content-Length exceeds the limit"
		$pagesBefore = $this->server->pageFiles();
		$response = $this->upload('huge.gif', self::gifOfSize(300 * 1024));
		$this->assertSame(413, $response->status);
		$this->assertStringContainsString('The upload is too large', $response->body);
		$this->assertStringContainsString('100K (post_max_size)', $response->body);
		$this->assertStringNotContainsString('security token', $response->body);
		$this->assertSame([], $this->uploadedFiles());
		$this->assertSame($pagesBefore, $this->server->pageFiles());
	}

	public function testUploadBelowTheLimitsStillWorks(): void
	{
		$this->assertStringContainsString("File 'ok.gif' uploaded", $this->noteAfter($this->upload('ok.gif', self::gifOfSize(10 * 1024))));
		$this->assertSame(['ok.gif'], $this->uploadedFiles());
	}

	public function testMissingTokenIsStillRefusedAsForbidden(): void
	{
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'X', 'content' => 'x']);
		$this->assertSame(403, $response->status);
	}
}
