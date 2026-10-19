<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** sanitizeSvgText(): the result of the SVG sanitizer library is used only if it is a non-empty string */
final class SvgSanitizeTextTest extends TestCase
{
	private function sanitizer(callable $sanitize): object
	{
		return new class($sanitize) {
			public function __construct(private $sanitize)
			{
			}

			public function sanitize($svg)
			{
				return ($this->sanitize)($svg);
			}
		};
	}

	public function testCleanedTextIsReturned(): void
	{
		$this->assertSame('<svg/>', sanitizeSvgText('<svg onload="x"/>', $this->sanitizer(fn() => '<svg/>')));
	}

	public function testSanitizerReceivesTheText(): void
	{
		$given = null;
		sanitizeSvgText('<svg id="a"/>', $this->sanitizer(function ($svg) use (&$given) {
			$given = $svg;
			return '<svg/>';
		}));
		$this->assertSame('<svg id="a"/>', $given);
	}

	public function testNoResultIsRefused(): void
	{
		foreach ([false, null, '', " \n\t"] as $result) {
			$this->assertFalse(sanitizeSvgText('<svg', $this->sanitizer(fn() => $result)), var_export($result, true));
		}
	}

	public function testExceptionsAndErrorsOfTheLibraryAreRefused(): void
	{
		$failures = [
			'ValueError' => fn() => throw new \ValueError('strpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'),
			'TypeError' => fn() => throw new \TypeError('wrong type'),
			'Exception' => fn() => throw new \RuntimeException('broken'),
		];
		foreach ($failures as $name => $failure) {
			$this->assertFalse(sanitizeSvgText('<svg', $this->sanitizer($failure)), $name);
		}
	}

	public function testLibxmlSettingIsRestoredAfterAFailure(): void
	{
		foreach ([true, false] as $before) {
			libxml_use_internal_errors($before);
			sanitizeSvgText('<svg', $this->sanitizer(fn() => throw new \ValueError('x')));
			$this->assertSame($before, libxml_use_internal_errors(), 'after a failure, setting was ' . var_export($before, true));
			sanitizeSvgText('<svg', $this->sanitizer(fn() => '<svg/>'));
			$this->assertSame($before, libxml_use_internal_errors(), 'after success, setting was ' . var_export($before, true));
		}
		libxml_use_internal_errors(false);
	}
}
