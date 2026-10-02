<?php

namespace W2\Tests\Integration\Svg;

use W2\Tests\Support\AppTestCase;

/** Without the library, SVG uploads stay refused even if enabled in the configuration */
final class SvgFailClosedTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['SVG_UPLOADS_ENABLED' => true];
	}

	public function testSvgUploadsAreRefusedWithoutTheLibrary(): void
	{
		$svg = (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/svg/benign.svg');
		$this->assertStringContainsString('invalid file type', $this->noteAfter($this->upload('a.svg', $svg, 'image/svg+xml')));
		$this->assertSame([], $this->uploadedFiles());
	}

	public function testOtherUploadsStillWork(): void
	{
		$this->assertStringContainsString('uploaded', $this->noteAfter($this->upload('a.gif', self::gif())));
		$this->assertSame(['a.gif'], $this->uploadedFiles());
	}
}
