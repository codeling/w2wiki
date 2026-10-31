<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The upload page with DISABLE_UPLOADS: a message, no form and no list */
final class UploadPageDisabledTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['DISABLE_UPLOADS' => true];
	}

	public function testUploadPageOnlyShowsAMessage(): void
	{
		$response = $this->http->get('/index.php', ['action' => 'upload']);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('<p>Image uploading has been disabled on this installation.</p>', $response->body);
		$this->assertStringNotContainsString('id="uploadbutton"', $response->body);
		$this->assertStringNotContainsString('id="uploaddialog"', $response->body);
		$this->assertStringNotContainsString('wiki.js', $response->body);
		$this->assertStringContainsString('Total: 0 images', $response->body);
	}
}
