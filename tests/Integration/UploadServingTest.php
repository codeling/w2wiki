<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Uploaded files are served by file.php (the web server doesn't serve the uploads folder) */
final class UploadServingTest extends AppTestCase
{
	public function testUploadedFilesAreServedWithTheirType(): void
	{
		$this->upload('a.gif', self::gif());
		$response = $this->http->get('/file.php', ['name' => 'a.gif']);
		$this->assertSame(200, $response->status);
		$this->assertSame(self::gif(), $response->body);
		$this->assertSame('image/gif', $response->header('content-type'));
		$this->assertSame('nosniff', $response->header('x-content-type-options'));
		$this->assertSame("inline; filename*=UTF-8''a.gif", $response->header('content-disposition'));
		$this->assertSame((string)strlen(self::gif()), $response->header('content-length'));
		$this->assertNull($response->header('content-security-policy'), 'not the policy of the pages');
		$this->assertNull($response->header('pragma'), 'no headers of the session against caching');
		$this->assertNull($response->header('expires'));
	}

	/** (the protection of the pages folder by the web server is tested by tests/Server, the built-in server ignores it) */
	public function testUploadsAreNotServedUnderTheAddressUsedInPages(): void
	{
		$this->upload('a.gif', self::gif());
		$this->assertStringNotContainsString(self::gif(), $this->http->get('/images/a.gif')->body);
	}

	public function testPagesLinkToFilePhpWithTheVersionOfTheFile(): void
	{
		$this->upload('v.gif', self::gif());
		$this->savePage('Pic', "![x](/images/v.gif)\n\n[doc](/images/v.gif#page=2)");
		$body = $this->http->get($this->pageUrl('Pic'))->body;
		$this->assertSame(1, preg_match('#<img src="(/file\.php\?name=v\.gif&amp;v=(\d+))"#', $body, $image), 'image address');
		$this->assertSame((string)filemtime($this->imageFile('v.gif')), $image[2]);
		$this->assertMatchesRegularExpression('#<a href="/file\.php\?name=v\.gif&amp;v=\d+\#page=2"#', $body);
		// (the text of the page is unchanged)
		$this->assertSame("![x](/images/v.gif)\n\n[doc](/images/v.gif#page=2)", $this->pageText('Pic'));

		$response = $this->http->get(html_entity_decode($image[1]));
		$this->assertSame(200, $response->status);
		$this->assertSame('private, max-age=31536000, immutable', $response->header('cache-control'));
	}

	public function testUnversionedRequestsCanBeAnsweredWithNotModified(): void
	{
		$this->upload('a.gif', self::gif());
		$response = $this->http->get('/file.php', ['name' => 'a.gif']);
		$this->assertSame('private, no-cache', $response->header('cache-control'));
		$etag = (string)$response->header('etag');
		$this->assertNotSame('', $etag);
		$this->assertNotNull($response->header('last-modified'));

		$again = $this->http->get('/file.php', ['name' => 'a.gif'], ["If-None-Match: $etag"]);
		$this->assertSame(304, $again->status);
		$this->assertSame('', $again->body);
		$since = $this->http->get('/file.php', ['name' => 'a.gif'], ['If-Modified-Since: ' . $response->header('last-modified')]);
		$this->assertSame(304, $since->status);
	}

	public function testMissingAndHiddenFilesAreNotFound(): void
	{
		mkdir($this->server->imagesDir() . '/sub');
		foreach (['missing.gif', '.htaccess', '', '..', 'sub', '../Home.md', '../../config.php'] as $name) {
			$response = $this->http->get('/file.php', ['name' => $name]);
			$this->assertSame(404, $response->status, $name);
			$this->assertStringNotContainsString('Require', $response->body);
			$this->assertStringNotContainsString('Welcome', $response->body);
		}
		$this->assertSame(404, $this->http->get('/file.php')->status, 'no name');
	}

	public function testArraysAreRefused(): void
	{
		$this->assertSame(400, $this->http->get('/file.php?name[]=a.gif')->status);
		$this->assertSame(400, $this->http->get('/file.php?name=a.gif&v[]=1')->status);
	}

	public function testScriptsInTheUploadsFolderAreOnlyOfferedForDownload(): void
	{
		file_put_contents($this->imageFile('x.php'), '<?php echo 6 * 7, "-executed";');
		file_put_contents($this->imageFile('x.html'), '<script>alert(1)</script>');
		foreach (['x.php', 'x.html'] as $name) {
			$response = $this->http->get('/file.php', ['name' => $name]);
			$this->assertSame(200, $response->status, $name);
			$this->assertStringNotContainsString('42-executed', $response->body);
			$this->assertSame('application/octet-stream', $response->header('content-type'), $name);
			$this->assertStringStartsWith('attachment;', (string)$response->header('content-disposition'), $name);
		}
	}

	public function testSvgFilesAreSandboxed(): void
	{
		file_put_contents($this->imageFile('s.svg'), '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
		$response = $this->http->get('/file.php', ['name' => 's.svg']);
		$this->assertSame(200, $response->status);
		$this->assertSame('image/svg+xml', $response->header('content-type'));
		$this->assertMatchesRegularExpression('/(^|[ ;])sandbox($|[ ;])/', (string)$response->header('content-security-policy'));
		$this->assertStringContainsString("default-src 'none'", (string)$response->header('content-security-policy'));
	}

	public function testLinksOutOfTheUploadsFolderAreNotFollowed(): void
	{
		symlink($this->server->rootDir() . '/config.php', $this->imageFile('config.gif'));
		$response = $this->http->get('/file.php', ['name' => 'config.gif']);
		$this->assertSame(404, $response->status);
		$this->assertStringNotContainsString('W2_PASSWORD_HASH', $response->body);
	}

	public function testOtherMethodsAreRefused(): void
	{
		$this->upload('a.gif', self::gif());
		$response = $this->http->post('/file.php', ['x' => '1'], ['name' => 'a.gif']);
		$this->assertSame(405, $response->status);
		$this->assertSame('', trim(str_replace('Method not allowed', '', $response->body)));
	}

	public function testTheThumbnailsOfTheListAreServed(): void
	{
		$this->upload('a.gif', self::gif());
		$list = $this->http->get('/index.php', ['action' => 'upload'])->body;
		$this->assertSame(1, preg_match('#<img class="thumbImg" src="([^"]+)"#', $list, $thumbnail));
		$this->assertSame(self::gif(), $this->http->get(html_entity_decode($thumbnail[1]))->body);
	}

	public function testALeftOverStaticLinkIsReportedInTheLog(): void
	{
		symlink('pages/images', $this->server->rootDir() . '/images');
		try {
			$offset = $this->server->logSize();
			$this->http->get('/index.php', ['action' => 'upload']);
			$this->assertStringContainsString('may let the web server serve the uploads without the password and IP checks', $this->server->logSince($offset));
			// (once per session)
			$offset = $this->server->logSize();
			$this->http->get('/index.php', ['action' => 'upload']);
			$this->assertStringNotContainsString('may let the web server serve', $this->server->logSince($offset));
		} finally {
			unlink($this->server->rootDir() . '/images');
		}
	}
}
