<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** Static resources must be referenced relative to BASE_URI, so W2 works in a subfolder */
final class BaseUriTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return ['BASE_URI' => '/sub/w2'];
	}

	public function testAllStaticResourcesUseBaseUri(): void
	{
		$body = $this->http->get('/index.php')->body;
		$edit = $this->http->get('/index.php', ['action' => 'edit', 'page' => 'Home'])->body;
		foreach ([$body, $edit] as $html) {
			preg_match_all('/(?:src|href)="(\/[^"]*w2-icons\/[^"]*|\/[^"]*\.(?:css|js))"/', $html, $matches);
			$this->assertNotEmpty($matches[1]);
			foreach ($matches[1] as $url) {
				$this->assertStringStartsWith('/sub/w2/', $url);
			}
		}
		$this->assertStringNotContainsString('"/w2-icons/', $body . $edit);
	}
}
