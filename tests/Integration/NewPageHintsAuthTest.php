<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The hints for the title of a new page (api.php?task=newpage) with a password: only for those who are logged in */
final class NewPageHintsAuthTest extends AppTestCase
{
	protected function configOverrides(): array
	{
		return [
			'REQUIRE_PASSWORD' => true,
			'W2_PASSWORD_HASH' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]),
		];
	}

	public function testTheHintsNeedALogin(): void
	{
		$response = $this->http->get('/api.php', ['task' => 'newpage', 'title' => 'Home']);
		$this->assertSame(403, $response->status);
		$this->assertSame('not logged in', json_decode($response->body, true)['error'] ?? null);
		$this->assertStringNotContainsString('exists already', $response->body);

		$this->login('hunter2');
		$this->assertTrue($this->newPageHints('Home')['exists']);
	}
}
