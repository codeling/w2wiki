<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
	public function testStaticFilesGetTheModificationTimeAsVersion(): void
	{
		$expected = filemtime(dirname(__DIR__, 2) . '/wiki.js');
		$this->assertSame(BASE_URI . "/wiki.js?v=$expected", assetURL('wiki.js'));
		$this->assertMatchesRegularExpression('#^/icons/home\.svg\?v=\d+$#', assetURL('icons/home.svg'));
	}

	public function testMissingFilesGetNoVersion(): void
	{
		$this->assertSame(BASE_URI . '/missing.js', assetURL('missing.js'));
	}

	public function testVersionedUrlChangesWithTheFile(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'w2');
		touch($file, 1000000);
		clearstatcache();
		$first = versionedURL('/x', $file);
		touch($file, 2000000);
		clearstatcache();
		$second = versionedURL('/x', $file);
		unlink($file);
		$this->assertSame('/x?v=1000000', $first);
		$this->assertSame('/x?v=2000000', $second);
	}

	public function testUploadLinksInRenderedMarkdownAreVersioned(): void
	{
		$dir = PAGES_PATH . '/' . UPLOAD_FOLDER;
		@mkdir($dir, 0777, true);
		$file = "$dir/w2-asset-test image.png";
		file_put_contents($file, 'x');
		touch($file, 1234567);
		try {
			$html = toHTML('![a](/images/w2-asset-test%20image.png) [f](/images/w2-asset-test%20image.png) ![n](/images/none.png) [e](http://example.com/images/a.png)');
			$this->assertSame(2, substr_count($html, '/images/w2-asset-test%20image.png?v=1234567"'));
			$this->assertStringContainsString('src="/images/none.png"', $html);
			$this->assertStringContainsString('href="http://example.com/images/a.png"', $html);
		} finally {
			unlink($file);
		}
	}
}
