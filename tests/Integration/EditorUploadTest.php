<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;
use W2\Tests\Support\CurlFileLike;

/** Uploads from the editor: the upload action answers in JSON (json=1) and the editor offers the upload */
final class EditorUploadTest extends AppTestCase
{
	/** @return array{0: \W2\Tests\Support\HttpResponse, 1: mixed} the response and its decoded JSON */
	private function jsonUpload(string $fileName, string $content, array $extra = [], ?string $token = null, string $mimeType = 'image/gif'): array
	{
		$response = $this->http->post('/index.php', [
			'csrf_token' => $token ?? $this->csrfToken(),
			'userfile' => new CurlFileLike($content, $fileName, $mimeType),
		] + $extra, ['action' => 'uploaded', 'json' => '1']);
		return [$response, json_decode($response->body, true)];
	}

	public function testValidImageIsStoredAndTheLinkIsReturned(): void
	{
		[$response, $json] = $this->jsonUpload('my cat.gif', self::gif());
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('json', (string)$response->header('content-type'));
		$this->assertTrue($json['success']);
		$this->assertSame('my_cat.gif', $json['file']);
		$this->assertSame('![Image Description](/images/my_cat.gif)', $json['link']);
		$this->assertStringContainsString("File 'my_cat.gif' uploaded", $json['message']);
		$this->assertSame(['my_cat.gif'], $this->uploadedFiles());
		$this->assertSame(self::gif(), file_get_contents($this->imageFile('my_cat.gif')));
	}

	public function testChosenNameIsUsed(): void
	{
		[, $json] = $this->jsonUpload('IMG_1.gif', self::gif(), ['newname' => 'cat']);
		$this->assertSame('cat.gif', $json['file']);
	}

	public function testRefusedFileIsNotStored(): void
	{
		[$response, $json] = $this->jsonUpload('evil.php', '<?php echo 1;', [], null, 'text/plain');
		$this->assertSame(422, $response->status);
		$this->assertFalse($json['success']);
		$this->assertSame('', $json['file']);
		$this->assertSame('', $json['link']);
		$this->assertStringContainsString('invalid file type', $json['message']);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testExistingFileIsOnlyReplacedWithOverwrite(): void
	{
		$this->jsonUpload('a.gif', self::gif());
		[$response, $json] = $this->jsonUpload('a.gif', self::gif() . "\0\0");
		$this->assertSame(422, $response->status);
		$this->assertFalse($json['success']);
		$this->assertStringContainsString('a.gif already exists', $json['message']);
		$this->assertSame(self::gif(), file_get_contents($this->imageFile('a.gif')));

		[$response, $json] = $this->jsonUpload('a.gif', self::gif() . "\0\0", ['overwrite' => 'true']);
		$this->assertSame(200, $response->status);
		$this->assertSame('a.gif', $json['file']);
		$this->assertSame(self::gif() . "\0\0", file_get_contents($this->imageFile('a.gif')));
	}

	public function testMissingFileIsAnError(): void
	{
		$response = $this->http->post('/index.php', ['csrf_token' => $this->csrfToken()], ['action' => 'uploaded', 'json' => '1']);
		$json = json_decode($response->body, true);
		$this->assertSame(422, $response->status);
		$this->assertFalse($json['success']);
		$this->assertStringContainsString('No file selected', $json['message']);
	}

	public function testWrongOrMissingCsrfTokenIsRefusedInJson(): void
	{
		foreach (['wrong', ''] as $token) {
			[$response, $json] = $this->jsonUpload('a.gif', self::gif(), [], $token);
			$this->assertSame(403, $response->status);
			$this->assertStringContainsString('json', (string)$response->header('content-type'));
			$this->assertFalse($json['success']);
			$this->assertStringContainsString('security token', $json['message']);
		}
		$get = $this->http->get('/index.php', ['action' => 'uploaded', 'json' => '1']);
		$this->assertSame(403, $get->status);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testTokenOfAnotherSessionIsRefused(): void
	{
		$token = $this->csrfToken();
		$response = $this->newClient()->post('/index.php', ['csrf_token' => $token, 'userfile' => new CurlFileLike(self::gif(), 'a.gif', 'image/gif')],
			['action' => 'uploaded', 'json' => '1']);
		$this->assertSame(403, $response->status);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testEditorOffersTheUploadForNewAndExistingPages(): void
	{
		$this->savePage('Pic', 'text');
		foreach ([['action' => 'new'], ['action' => 'edit', 'page' => 'Pic']] as $query) {
			$body = $this->http->get('/index.php', $query)->body;
			$this->assertStringContainsString('id="uploadbutton"', $body);
			$this->assertStringContainsString('id="uploaddialog"', $body);
			$this->assertStringContainsString('id="uploadnewname"', $body);
			$this->assertStringContainsString('data-upload-url="/index.php?action=uploaded&amp;json=1"', $body);
			$this->assertStringContainsString('data-check-url="/api.php?task=checkupload"', $body);
		}
	}

	public function testUploadControlsAreNotShownInTheEditorItself(): void
	{
		// (they are in the dialog after the form, which the script opens from the button of the toolbar)
		$body = $this->http->get('/index.php', ['action' => 'new'])->body;
		$formEnd = strpos($body, '</form>', strpos($body, 'id="edit"'));
		$this->assertNotFalse($formEnd);
		$this->assertStringNotContainsString('id="uploadfile"', substr($body, 0, $formEnd));
		$this->assertGreaterThan($formEnd, strpos($body, 'id="uploaddialog"'));
		$this->assertGreaterThan($formEnd, strpos($body, 'id="cropbox"'));
	}

	public function testUploadWithCropAnswersWithTheSizeInTheMessage(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('The Imagick extension is not installed');
		}
		$png = (function () {
			$img = new \Imagick();
			$img->newImage(40, 20, new \ImagickPixel('red'), 'png');
			return $img->getImagesBlob();
		})();
		[$response, $json] = $this->jsonUpload('c.png', $png, ['crop' => '0,0,0.5,1'], null, 'image/png');
		$this->assertSame(200, $response->status);
		$this->assertTrue($json['success']);
		$this->assertStringContainsString('Cropped to 20x20.', $json['message']);
		$this->assertSame([20, 20], array_slice(getimagesize($this->imageFile('c.png')), 0, 2));
	}
}
