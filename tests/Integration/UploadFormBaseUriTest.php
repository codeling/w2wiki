<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The dialog to upload files has the base address of the wiki in an attribute: it must not be able to leave the attribute */
final class UploadFormBaseUriTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['BASE_URI' => '/sub/w2"+alert(1)+"</script><b>'];
	}

	public function testBaseAddressInTheDialogIsEscapedForHtml(): void
	{
		foreach (['/index.php?action=upload&page=Home', '/index.php?action=new'] as $url) {
			$body = $this->http->get($url)->body;
			$this->assertStringContainsString(
				'data-check-url="/sub/w2&quot;+alert(1)+&quot;&lt;/script&gt;&lt;b&gt;/api.php?task=checkupload"',
				$body,
				$url
			);
			$this->assertStringNotContainsString('<b>/api.php', $body, $url);
			// (the script of the page is the only one, and nothing was added to the page)
			$this->assertSame(1, substr_count($body, '<script'), $url);
			$this->assertSame(1, substr_count($body, '</script>'), $url);
		}
	}
}
