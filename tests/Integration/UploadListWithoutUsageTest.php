<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

final class UploadListWithoutUsageTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['SHOW_PAGES_WHERE_FILE_USED' => false];
	}

	public function testUploadListWorksWithoutUsageColumn(): void
	{
		$this->upload('a.gif', self::gif());
		$this->savePage('Gallery', '![x](/images/a.gif)');
		$response = $this->http->get('/index.php', ['action' => 'upload']);
		$this->assertSame(200, $response->status);
		$this->assertStringContainsString('Total: 1', $response->body);
		$this->assertStringNotContainsString('Used on page', $response->body);
		$this->assertStringNotContainsString('href="/index.php/Gallery"', $response->body);
	}
}
