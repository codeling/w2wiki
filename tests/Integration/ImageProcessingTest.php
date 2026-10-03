<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/**
 * Image processing of uploads with ImageMagick: shrinking, converting (HEIC/HEIF and, for these tests, GIF)
 * and applying the EXIF orientation. Skipped if the Imagick extension is missing.
 */
final class ImageProcessingTest extends AppTestCase
{
	private const FIXTURES = __DIR__ . '/../fixtures/images';

	protected function configOverrides(): array
	{
		// GIF is added so that the conversion can be tested without HEIC support
		return ['IMAGE_EXTS_TO_CONVERT' => 'heic,heif,gif'];
	}

	protected function setUp(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('The Imagick extension is not installed');
		}
		parent::setUp();
	}

	private static function image(int $width, int $height, string $format = 'png'): string
	{
		$img = new \Imagick();
		$img->newImage($width, $height, new \ImagickPixel('red'), $format);
		return $img->getImagesBlob();
	}

	/** @return array{0: int, 1: int} width and height of an uploaded file */
	private function sizeOf(string $name): array
	{
		$this->assertFileExists($this->imageFile($name));
		$size = getimagesize($this->imageFile($name));
		$this->assertIsArray($size, "$name is not an image");
		return [$size[0], $size[1]];
	}

	private function uploadResized(string $name, string $content, array $extra = []): string
	{
		return $this->noteAfter($this->upload($name, $content, 'image/png', ['resize' => 'true'] + $extra));
	}

	// --- resize ----------------------------------------------------------------------

	public function testLargeLandscapeImageIsShrunkKeepingTheAspectRatio(): void
	{
		$note = $this->uploadResized('wide.png', self::image(100, 50), ['maxsize' => '40']);
		$this->assertSame([40, 20], $this->sizeOf('wide.png'));
		$this->assertStringContainsString('Original size was 100x50, resized to 40x20.', $note);
		$this->assertStringNotContainsString('failed', $note);
		$this->assertSame(['wide.png'], $this->uploadedFiles());
	}

	public function testLargePortraitImageIsShrunkKeepingTheAspectRatio(): void
	{
		$note = $this->uploadResized('tall.png', self::image(50, 100), ['maxsize' => '40']);
		$this->assertSame([20, 40], $this->sizeOf('tall.png'));
		$this->assertStringContainsString('resized to 20x40.', $note);
	}

	public function testSquareImageIsShrunk(): void
	{
		$this->uploadResized('square.png', self::image(60, 60), ['maxsize' => '30']);
		$this->assertSame([30, 30], $this->sizeOf('square.png'));
	}

	public function testSmallerImagesAreNotResized(): void
	{
		$note = $this->uploadResized('small.png', self::image(30, 15), ['maxsize' => '40']);
		$this->assertSame([30, 15], $this->sizeOf('small.png'));
		$this->assertStringNotContainsString('resized', $note);
		$this->assertStringNotContainsString('failed', $note);
	}

	public function testImageWithExactlyTheMaximumSizeIsKept(): void
	{
		$note = $this->uploadResized('exact.png', self::image(40, 25), ['maxsize' => '40']);
		$this->assertSame([40, 25], $this->sizeOf('exact.png'));
		$this->assertStringNotContainsString('resized', $note);
	}

	public function testImagesAreNotProcessedWithoutTheCheckbox(): void
	{
		$content = self::image(100, 50);
		$note = $this->noteAfter($this->upload('wide.png', $content, 'image/png', ['maxsize' => '40']));
		$this->assertStringContainsString("File 'wide.png' uploaded", $note);
		$this->assertStringNotContainsString('resized', $note);
		$this->assertSame($content, file_get_contents($this->imageFile('wide.png')));
	}

	public static function maxSizes(): array
	{
		return [
			'zero' => ['0', 20],
			'one' => ['1', 20],
			'negative' => ['-5', 20],
			'below the minimum' => ['19', 20],
			'minimum' => ['20', 20],
			'text' => ['abc', 20],
			'empty' => ['', 20],
			'decimal' => ['30.9', 30],
			'maximum' => ['8192', 8192],
			'above the maximum' => ['99999999', 8192],
			'huge number' => ['99999999999999999999999', 8192],
		];
	}

	#[DataProvider('maxSizes')]
	public function testMaxSizeIsClampedOnTheServer(string $maxsize, int $expected): void
	{
		// 9000x100 is larger than every allowed maximum, so the longer side shows the one which was used
		$this->uploadResized('big.png', self::image(9000, 100), ['maxsize' => $maxsize]);
		$this->assertSame($expected, $this->sizeOf('big.png')[0]);
	}

	public function testMissingMaxSizeDefaultsTo1200(): void
	{
		$this->uploadResized('big.png', self::image(2000, 1000));
		$this->assertSame([1200, 600], $this->sizeOf('big.png'));
	}

	// --- EXIF orientation ------------------------------------------------------------

	public static function orientations(): array
	{
		return [
			'upside down' => [3, [60, 30], 'rotated by 180°'],
			'clockwise' => [6, [30, 60], 'rotated by +90°'],
			'counterclockwise' => [8, [30, 60], 'rotated by -90°'],
		];
	}

	#[DataProvider('orientations')]
	public function testExifOrientationIsAppliedAndReset(int $orientation, array $expectedSize, string $expectedNote): void
	{
		$file = file_get_contents(self::FIXTURES . "/orientation-$orientation.jpg");
		$this->assertSame($orientation, (new \Imagick(self::FIXTURES . "/orientation-$orientation.jpg"))->getImageOrientation());

		$note = $this->noteAfter($this->upload('photo.jpg', $file, 'image/jpeg', ['resize' => 'true', 'maxsize' => '1200']));
		$this->assertStringContainsString($expectedNote, $note);
		$this->assertSame($expectedSize, $this->sizeOf('photo.jpg'));
		$this->assertSame(\Imagick::ORIENTATION_TOPLEFT, (new \Imagick($this->imageFile('photo.jpg')))->getImageOrientation());
		$this->assertSame(['photo.jpg'], $this->uploadedFiles());
	}

	public function testRotationMovesThePixelsToTheRightPlace(): void
	{
		// the fixture is red on the left and blue on the right
		$this->upload('turned.jpg', file_get_contents(self::FIXTURES . '/orientation-3.jpg'), 'image/jpeg', ['resize' => 'true']);
		$img = new \Imagick($this->imageFile('turned.jpg'));
		$this->assertSame('blue', $this->dominantColor($img->getImagePixelColor(5, 15)));
		$this->assertSame('red', $this->dominantColor($img->getImagePixelColor(55, 15)));

		$this->upload('right.jpg', file_get_contents(self::FIXTURES . '/orientation-6.jpg'), 'image/jpeg', ['resize' => 'true']);
		$img = new \Imagick($this->imageFile('right.jpg'));
		$this->assertSame('red', $this->dominantColor($img->getImagePixelColor(15, 5)));
		$this->assertSame('blue', $this->dominantColor($img->getImagePixelColor(15, 55)));
	}

	private function dominantColor(\ImagickPixel $pixel): string
	{
		$c = $pixel->getColor();
		return $c['r'] > $c['b'] ? 'red' : 'blue';
	}

	public function testNormalOrientationIsNotRotated(): void
	{
		$note = $this->noteAfter($this->upload('photo.jpg', file_get_contents(self::FIXTURES . '/orientation-1.jpg'), 'image/jpeg', ['resize' => 'true']));
		$this->assertStringNotContainsString('rotated', $note);
		$this->assertSame([60, 30], $this->sizeOf('photo.jpg'));
	}

	public function testOrientationIsKeptWithoutProcessing(): void
	{
		$file = file_get_contents(self::FIXTURES . '/orientation-6.jpg');
		$this->upload('photo.jpg', $file, 'image/jpeg');
		$this->assertSame($file, file_get_contents($this->imageFile('photo.jpg')));
	}

	public function testResizeAndRotationCanBeCombined(): void
	{
		$note = $this->noteAfter($this->upload('photo.jpg', file_get_contents(self::FIXTURES . '/orientation-6.jpg'), 'image/jpeg', ['resize' => 'true', 'maxsize' => '40']));
		$this->assertStringContainsString('resized to 40x20', $note);
		$this->assertStringContainsString('rotated by +90°', $note);
		$this->assertSame([20, 40], $this->sizeOf('photo.jpg'));
	}

	// --- conversion ------------------------------------------------------------------

	public function testImagesWithConvertedExtensionsAreConverted(): void
	{
		// GIF is configured for conversion in this test class
		$note = $this->noteAfter($this->upload('anim.gif', self::image(10, 10, 'gif')));
		$this->assertStringContainsString('Converted to format jpg.', $note);
		$this->assertSame(['anim.jpg'], $this->uploadedFiles());
		$this->assertSame('image/jpeg', mime_content_type($this->imageFile('anim.jpg')));
		$this->assertStringContainsString('![Image Description](/images/anim.jpg)', $note);
	}

	private function requireHeic(): void
	{
		if (!in_array('HEIC', \Imagick::queryFormats('HEIC'), true)) {
			$this->markTestSkipped('This ImageMagick has no HEIC support');
		}
		try {
			new \Imagick(self::FIXTURES . '/sample.heic');
		} catch (\ImagickException $e) {
			$this->markTestSkipped('This ImageMagick can not read HEIC files: ' . $e->getMessage());
		}
	}

	public function testHeicIsConvertedAndTheOriginalIsRemoved(): void
	{
		$this->requireHeic();
		$note = $this->noteAfter($this->upload('IMG 1.heic', file_get_contents(self::FIXTURES . '/sample.heic'), 'image/heic'));
		$this->assertStringContainsString('Converted to format jpg.', $note);
		$this->assertStringContainsString('![Image Description](/images/IMG_1.jpg)', $note);
		$this->assertSame(['IMG_1.jpg'], $this->uploadedFiles());
		$this->assertSame([64, 32], $this->sizeOf('IMG_1.jpg'));
		$this->assertSame('image/jpeg', mime_content_type($this->imageFile('IMG_1.jpg')));
	}

	public function testHeicCanBeConvertedAndResized(): void
	{
		$this->requireHeic();
		$note = $this->noteAfter($this->upload('pic.heif', file_get_contents(self::FIXTURES . '/sample.heic'), 'image/heif', ['resize' => 'true', 'maxsize' => '32']));
		$this->assertStringContainsString('resized to 32x16', $note);
		$this->assertSame(['pic.jpg'], $this->uploadedFiles());
		$this->assertSame([32, 16], $this->sizeOf('pic.jpg'));
	}

	public function testOverwriteConfirmationAppliesToTheNameAfterConversion(): void
	{
		$this->upload('anim.jpg', self::image(5, 5, 'jpeg'), 'image/jpeg');
		$before = file_get_contents($this->imageFile('anim.jpg'));

		$note = $this->noteAfter($this->upload('anim.gif', self::image(10, 10, 'gif')));
		$this->assertStringContainsString('anim.jpg already exists', $note);
		$this->assertSame($before, file_get_contents($this->imageFile('anim.jpg')));
		$this->assertSame(['anim.jpg'], $this->uploadedFiles());

		$note = $this->noteAfter($this->upload('anim.gif', self::image(10, 10, 'gif'), 'image/gif', ['overwrite' => 'true']));
		$this->assertStringContainsString('Converted to format jpg.', $note);
		$this->assertSame([10, 10], $this->sizeOf('anim.jpg'));
		$this->assertSame(['anim.jpg'], $this->uploadedFiles());
	}

	/** Has a valid PNG header (so it is accepted as an image) but broken image data */
	private static function corruptPng(): string
	{
		return substr(self::image(40, 40), 0, 60) . str_repeat("\xde\xad", 30);
	}

	// --- failures --------------------------------------------------------------------

	public function testCorruptImageGivesAnErrorMessageAndLeavesNoFiles(): void
	{
		$corrupt = self::corruptPng();
		$response = $this->upload('broken.png', $corrupt, 'image/png', ['resize' => 'true']);
		$note = $this->noteAfter($response);
		$this->assertStringContainsString('could not be processed', $note);
		$this->assertStringNotContainsString('Use ', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testCorruptImageToConvertLeavesNoFiles(): void
	{
		$note = $this->noteAfter($this->upload('broken.gif', "GIF89a\x10\x00\x10\x00\xf0\x00\x00" . str_repeat("\xff", 8), 'image/gif'));
		$this->assertStringContainsString('could not be processed', $note);
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testFailedProcessingKeepsAnExistingImage(): void
	{
		$this->upload('keep.png', self::png(), 'image/png');
		$before = file_get_contents($this->imageFile('keep.png'));
		$corrupt = self::corruptPng();
		$note = $this->noteAfter($this->upload('keep.png', $corrupt, 'image/png', ['resize' => 'true', 'overwrite' => 'true']));
		$this->assertStringContainsString('could not be processed', $note);
		$this->assertSame(['keep.png'], $this->uploadedFiles());
		$this->assertSame($before, file_get_contents($this->imageFile('keep.png')));
	}

	// --- ImageMagick features which uploads must not trigger -------------------------

	public static function delegateContents(): array
	{
		return [
			'msl' => ['<?xml version="1.0"?><image><read filename="xc:red[10x10]"/><write filename="%s"/></image>'],
			'msl in a png' => ["\x89PNG\r\n\x1a\n" . '<image><read filename="xc:red[10x10]"/><write filename="%s"/></image>'],
			'msl behind a gif header' => ['GIF89a<image><read filename="xc:red[10x10]"/><write filename="%s"/></image>'],
			'mvg' => ["push graphic-context\nviewbox 0 0 10 10\nimage over 0,0 0,0 'label:@%s'\npop graphic-context"],
			'ephemeral' => ['ephemeral:%s'],
			'text' => ["text:%s"],
			'inline' => ['inline:data:image/png;base64,AAAA'],
			'url' => ['https://127.0.0.1:1/%s'],
		];
	}

	#[DataProvider('delegateContents')]
	public function testImageMagickDelegatesAreNotTriggeredByUploads(string $template): void
	{
		$marker = sys_get_temp_dir() . '/w2-imagick-marker-' . bin2hex(random_bytes(6)) . '.png';
		$content = sprintf($template, $marker);
		foreach (['png', 'gif', 'jpg', 'webp'] as $ext) {
			$this->upload("evil.$ext", $content, 'image/png', ['resize' => 'true']);
			$this->upload("evil-$ext.heic", $content, 'image/heic');
		}
		$this->assertFileDoesNotExist($marker, 'ImageMagick executed the uploaded file');
		foreach ($this->uploadedFiles() as $file) {
			$this->assertDoesNotMatchRegularExpression('/-tmp-process/', $file);
		}
		@unlink($marker);
	}
}
