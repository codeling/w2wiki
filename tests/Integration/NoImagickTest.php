<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * Uploads on a server without image processing: images are stored as they are, unless they have to be converted,
 * which is refused with a message, not a fatal error. IMAGE_PROCESSING_ENABLED = false has the same effect as a
 * missing Imagick extension (which can't be removed from a server which has it, and "disable_classes" leaves the class).
 */
final class NoImagickTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['IMAGE_EXTS_TO_CONVERT' => 'heic,heif,gif', 'IMAGE_PROCESSING_ENABLED' => false];
	}

	public function testUploadDialogOffersNoShrinkingOrCroppingWithoutImagick(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'upload', 'page' => 'Home'])->body;
		$this->assertStringContainsString('id="uploadfile"', $body);
		$this->assertStringContainsString('id="uploadnewname"', $body);
		$this->assertStringNotContainsString('id="uploadresize"', $body);
		$this->assertStringNotContainsString('id="uploadmaxsize"', $body);
		$this->assertStringNotContainsString('id="cropbox"', $body);
	}

	public function testImageIsStoredAsItIsAndMessageSaysSo(): void
	{
		$png = self::png();
		$note = $this->noteAfter($this->upload('pic.png', $png, 'image/png', ['resize' => 'true', 'maxsize' => '20']));
		$this->assertStringContainsString("File 'pic.png' uploaded!", $note);
		$this->assertStringContainsString('not shrunk or rotated', $note);
		$this->assertStringContainsString('imagick', $note);
		$this->assertSame($png, file_get_contents($this->imageFile('pic.png')));
	}

	public function testUploadWithoutShrinkingHasNoMessageAboutIt(): void
	{
		$note = $this->noteAfter($this->upload('plain.png', self::png(), 'image/png'));
		$this->assertStringContainsString("File 'plain.png' uploaded!", $note);
		$this->assertStringNotContainsString('imagick', $note);
		$this->assertFileExists($this->imageFile('plain.png'));
	}

	public function testImageWhichNeedsConversionIsRefused(): void
	{
		$logOffset = $this->server->logSize();
		$note = $this->noteAfter($this->upload('anim.gif', self::gif(), 'image/gif'));
		$this->assertStringContainsString('Upload error', $note);
		$this->assertStringContainsString('anim.gif needs to be converted to jpg', $note);
		$this->assertStringContainsString('imagick', $note);
		$this->assertSame([], $this->uploadedFiles());
		$this->assertStringContainsString("can't convert the upload anim.gif", $this->server->logSince($logOffset));
	}

	public function testCroppedImageIsRefusedInsteadOfStoredWhole(): void
	{
		$logOffset = $this->server->logSize();
		$note = $this->noteAfter($this->upload('pic.png', self::png(), 'image/png', ['crop' => '0,0,0.5,0.5']));
		$this->assertStringContainsString('Upload error', $note);
		$this->assertStringContainsString('pic.png can not be cropped', $note);
		$this->assertSame([], $this->uploadedFiles());
		$this->assertStringContainsString("can't crop the upload pic.png", $this->server->logSince($logOffset));
	}

	public function testEditorOffersNoCropWithoutImagick(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'new'])->body;
		$this->assertStringContainsString('id="uploaddialog"', $body);
		$this->assertStringNotContainsString('id="cropbox"', $body);
		$this->assertStringNotContainsString('id="uploadresize"', $body);
	}

	public function testPdfIsStoredWithoutProcessing(): void
	{
		$note = $this->noteAfter($this->upload('doc.pdf', "%PDF-1.4\n%%EOF\n", 'application/pdf', ['resize' => 'true']));
		$this->assertStringContainsString("File 'doc.pdf' uploaded!", $note);
		$this->assertStringNotContainsString('imagick', $note);
		$this->assertFileExists($this->imageFile('doc.pdf'));
	}
}
