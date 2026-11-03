<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
	public function testStaticFilesGetTheModificationTimeAsVersion(): void
	{
		$expected = filemtime(dirname(__DIR__, 2) . '/wiki.js');
		$this->assertSame(BASE_URI . "/wiki.js?v=$expected", assetURL('wiki.js'));
		$this->assertMatchesRegularExpression('#^/w2-icons/home\.svg\?v=\d+$#', assetURL('w2-icons/home.svg'));
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

	public function testVersionIsAddedToAnExistingQuery(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'w2-asset-test');
		touch($file, 1000000);
		try {
			$this->assertSame('/file.php?name=a&v=1000000', versionedURL('/file.php?name=a', $file));
		} finally {
			unlink($file);
		}
	}

	/** Links to uploads in pages point to file.php, which serves them (with the version of the file, if it exists) */
	public function testUploadLinksInRenderedMarkdownPointToFilePhp(): void
	{
		$dir = uploadPath();
		@mkdir($dir, 0777, true);
		$file = "$dir/w2-asset-test image.png";
		file_put_contents($file, 'x');
		touch($file, 1234567);
		try {
			$html = toHTML('![a](/images/w2-asset-test%20image.png) [f](/images/w2-asset-test%20image.png) ![n](/images/none.png) [e](http://example.com/images/a.png)'
				. ' [p](/images/w2-asset-test%20image.png#page=2) [q](/images/none.png?x=1) [h](/images/.htaccess)');
			$this->assertSame(2, substr_count($html, '"/file.php?name=w2-asset-test%20image.png&amp;v=1234567"'));
			$this->assertStringContainsString('src="/file.php?name=none.png"', $html);
			$this->assertStringContainsString('href="http://example.com/images/a.png"', $html);
			$this->assertStringContainsString('href="/file.php?name=w2-asset-test%20image.png&amp;v=1234567#page=2"', $html, 'the fragment is kept');
			$this->assertStringContainsString('href="/file.php?name=none.png"', $html, 'the query is dropped');
			$this->assertStringContainsString('href="/images/.htaccess"', $html, 'hidden files are not uploads');
			$this->assertStringNotContainsString('src="/images/', $html);
		} finally {
			unlink($file);
		}
	}
}
