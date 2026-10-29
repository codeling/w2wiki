<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The part of an image to keep, as sent by the upload dialog of the editor */
final class CropRectTest extends TestCase
{
	public static function rects(): array
	{
		return [
			'part of the image' => ['0.1,0.2,0.5,0.6', [0.1, 0.2, 0.5, 0.6]],
			'spaces are allowed' => [' 0.25 , 0.25 , 0.5 , 0.5 ', [0.25, 0.25, 0.5, 0.5]],
			'integers' => ['0,0,1,0.5', [0.0, 0.0, 1.0, 0.5]],
			'exponent' => ['1e-1,0,0.5,0.5', [0.1, 0.0, 0.5, 0.5]],
			'beyond the right and bottom border is cut off' => ['0.5,0.5,0.9,0.9', [0.5, 0.5, 0.5, 0.5]],
			'before the left and top border starts at the border' => ['-0.2,-0.1,0.5,0.5', [0.0, 0.0, 0.5, 0.5]],
		];
	}

	#[DataProvider('rects')]
	public function testRectangleIsKeptInsideTheImage(string $text, array $expected): void
	{
		$rect = parseCropRect($text);
		$this->assertIsArray($rect);
		$this->assertEqualsWithDelta($expected, $rect, 1e-9);
	}

	public static function noCrop(): array
	{
		return [
			'nothing' => [''],
			'whole image' => ['0,0,1,1'],
			'whole image, with digits' => ['0.00000,0.00000,1.00000,1.00000'],
			'three numbers' => ['0.1,0.1,0.5'],
			'five numbers' => ['0.1,0.1,0.5,0.5,0.5'],
			'text' => ['a,b,c,d'],
			'empty element' => ['0.1,,0.5,0.5'],
			'nan' => ['NAN,0,0.5,0.5'],
			'no width' => ['0.1,0.1,0,0.5'],
			'too narrow' => ['0.1,0.1,0.0001,0.5'],
			'nothing is left at the border' => ['1,0,0.5,0.5'],
			'semicolon' => ['0.1;0.1;0.5;0.5'],
		];
	}

	#[DataProvider('noCrop')]
	public function testInvalidRectangleAndWholeImageMeanNoCrop(string $text): void
	{
		$this->assertNull(parseCropRect($text));
	}

	public function testOtherTypesMeanNoCrop(): void
	{
		$this->assertNull(parseCropRect(null));
		$this->assertNull(parseCropRect(['0,0,0.5,0.5']));
	}
}
