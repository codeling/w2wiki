<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Images with more pixels than MAX_IMAGE_PIXELS are refused instead of being decoded */
final class ImageTooLargeTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['MAX_IMAGE_PIXELS' => 100];
	}

	protected function setUp(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('The Imagick extension is not installed');
		}
		parent::setUp();
	}

	private static function image(int $width, int $height): string
	{
		$img = new \Imagick();
		$img->newImage($width, $height, new \ImagickPixel('red'), 'png');
		return $img->getImagesBlob();
	}

	public function testImageWithTooManyPixelsIsRefusedAndRemoved(): void
	{
		$note = $this->noteAfter($this->upload('big.png', self::image(11, 10), 'image/png', ['resize' => 'true']));
		$this->assertStringContainsString('could not be processed', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testImageWithinTheLimitIsAccepted(): void
	{
		$note = $this->noteAfter($this->upload('ok.png', self::image(10, 10), 'image/png', ['resize' => 'true']));
		$this->assertStringContainsString('uploaded', $note);
		$this->assertSame(['ok.png'], $this->uploadedFiles());
	}
}
