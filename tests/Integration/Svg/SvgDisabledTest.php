<?php

namespace W2\Tests\Integration\Svg;

use W2\Tests\Support\AppTestCase;

/** With the library installed, SVG uploads are still refused unless enabled in the configuration */
final class SvgDisabledTest extends AppTestCase
{
	protected function serverOptions(): array
	{
		return ['svgSanitizer' => true];
	}

	public function testSvgUploadsAreRefusedUnlessEnabled(): void
	{
		$svg = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/svg/benign.svg');
		$this->assertStringContainsString('invalid file type', $this->noteAfter($this->upload('a.svg', $svg, 'image/svg+xml')));
		$this->assertSame([], $this->uploadedFiles());
	}
}
