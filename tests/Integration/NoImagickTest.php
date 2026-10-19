<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * Uploads on a server without the Imagick extension (simulated with disable_classes, as the extension may be installed):
 * images are stored as they are, unless they have to be converted, which is refused with a message, not a fatal error.
 */
final class NoImagickTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['IMAGE_EXTS_TO_CONVERT' => 'heic,heif,gif'];
	}

	protected function serverOptions(): array
	{
		return ['phpIni' => ['disable_classes' => 'Imagick']];
	}

	public function testUploadFormOffersNoShrinkingWithoutImagick(): void
	{
		$form = $this->http->get('/index.php', ['action' => 'upload', 'page' => 'Home'])->body;
		$this->assertStringContainsString('name="userfile"', $form);
		$this->assertStringNotContainsString('name="resize"', $form);
		$this->assertStringNotContainsString('name="maxsize"', $form);
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

	public function testPdfIsStoredWithoutProcessing(): void
	{
		$note = $this->noteAfter($this->upload('doc.pdf', "%PDF-1.4\n%%EOF\n", 'application/pdf', ['resize' => 'true']));
		$this->assertStringContainsString("File 'doc.pdf' uploaded!", $note);
		$this->assertStringNotContainsString('imagick', $note);
		$this->assertFileExists($this->imageFile('doc.pdf'));
	}
}
