<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The script of the upload form has the base address of the wiki in a string: it must not be able to leave the string or the script */
final class UploadFormBaseUriTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['BASE_URI' => '/sub/w2"+alert(1)+"</script><b>'];
	}

	public function testBaseAddressInTheScriptIsEscapedForJavaScript(): void
	{
		// (JSON escapes: \u0022 is a quote, \u003C and \u003E are the angle brackets; written with chr(92) for the backslash)
		$u = chr(92) . 'u';
		$body = $this->http->get('/index.php', ['action' => 'upload', 'page' => 'Home'])->body;
		$this->assertSame(1, substr_count($body, '</script>'), 'the script has no end tag in its text');
		$this->assertStringContainsString(
			'fetch("/sub/w2' . $u . '0022+alert(1)+' . $u . '0022' . $u . '003C/script' . $u . '003E' . $u . '003Cb' . $u . '003E/api.php?task=checkupload&filename="+encodeURIComponent(filename)',
			$body
		);
		$this->assertStringNotContainsString('fetch("/sub/w2"+alert(1)', $body);
	}
}
