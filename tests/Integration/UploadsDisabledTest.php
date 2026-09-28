<?php

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
}
