<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

final class UploadsDisabledTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['DISABLE_UPLOADS' => true];
	}

	public function testUploadFormIsNotOfferedAndUploadsAreRefused(): void
	{
		$page = $this->http->get('/index.php', ['action' => 'upload']);
		$this->assertStringContainsString('disabled on this installation', $page->body);
		$this->assertStringNotContainsString('type="file"', $page->body);

		$response = $this->upload('a.gif', self::gif());
		$this->assertStringContainsString('Uploads are disabled', $response->body);
		$this->assertSame([], $this->uploadedFiles());
	}

	/** (DISABLE_UPLOADS only stops new uploads: the files which are there are still shown) */
	public function testExistingFilesAreStillServed(): void
	{
		file_put_contents($this->imageFile('a.gif'), self::gif());
		$this->assertSame(self::gif(), $this->http->get('/file.php', ['name' => 'a.gif'])->body);
	}

	public function testEditorHasNoUploadButtonOrDialog(): void
	{
		$body = $this->http->get('/index.php', ['action' => 'new'])->body;
		$this->assertStringContainsString('id="drawer-control"', $body);
		$this->assertStringNotContainsString('id="uploadbutton"', $body);
		$this->assertStringNotContainsString('id="uploaddialog"', $body);
	}
}
