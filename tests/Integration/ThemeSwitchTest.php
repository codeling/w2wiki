<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/** The buttons in the toolbar to choose the color scheme, which is kept in a cookie */
final class ThemeSwitchTest extends AppTestCase
{
	public function testWithoutAChoiceTheSystemDecides(): void
	{
		$body = $this->http->get('/index.php')->body;
		$this->assertMatchesRegularExpression('#<html lang="[a-z]+">#', $body, 'no data-theme');
		$this->assertStringContainsString('<meta name="color-scheme" content="light dark" />', $body);
		$this->assertSame('light', $this->nextMode($body), 'the button chooses the scheme after the system\'s');
	}

	public function testTheToolbarHasTheButtonWithTheIconsAndItsForm(): void
	{
		$body = $this->http->get('/index.php/Home')->body;
		$this->assertStringContainsString('<form method="post" action="/index.php" class="themeswitch rightaligned">', $body);
		$this->assertStringContainsString('<input type="hidden" name="action" value="theme" />', $body);
		$this->assertMatchesRegularExpression('#<input type="hidden" name="return" value="/index\.php/Home" />#', $body);
		$this->assertSame(1, substr_count($body, '<button type="submit" name="mode"'), 'one button');
		// (the icons of all three schemes: the style sheet shows the one for the scheme in use)
		foreach (['auto' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $mode => $name) {
			$this->assertMatchesRegularExpression(
				'#<svg class="icon icon-theme-' . $mode . '" viewBox="0 0 16 16" role="img" aria-label="Color scheme: ' . $name . '">'
				. '<title>Color scheme: ' . $name . '</title><use href="/w2-icons/theme-' . $mode . '\.svg\?v=\d+\#i"/></svg>#',
				$body
			);
		}
	}

	public function testChoosingDarkSetsTheCookieAndGoesBack(): void
	{
		$response = $this->postAction('theme', ['mode' => 'dark', 'return' => '/index.php?action=all']);
		$this->assertSame(303, $response->status);
		$this->assertSame('/index.php?action=all', $response->location());
		$this->assertSame('dark', $response->setCookieValue('w2_theme'));
		$cookie = $response->headerValues('set-cookie')[0];
		$this->assertStringContainsString('HttpOnly', $cookie);
		$this->assertStringContainsString('SameSite=Lax', $cookie);

		$body = $this->http->get('/index.php', ['action' => 'all'])->body;
		$this->assertMatchesRegularExpression('#<html lang="[a-z]+" data-theme="dark">#', $body);
		$this->assertSame('auto', $this->nextMode($body), 'after dark comes the system\'s scheme');
	}

	public function testLightAndBackToAuto(): void
	{
		$this->postAction('theme', ['mode' => 'light']);
		$body = $this->http->get('/index.php')->body;
		$this->assertStringContainsString('data-theme="light"', $body);
		$this->assertSame('dark', $this->nextMode($body));
		$response = $this->postAction('theme', ['mode' => 'auto']);
		$this->assertSame('deleted', $response->setCookieValue('w2_theme'), 'the cookie is removed');
		$this->assertStringNotContainsString('data-theme', $this->http->get('/index.php')->body);
	}

	public function testWithoutAReturnAddressItGoesToTheScript(): void
	{
		$this->assertSame('/index.php', $this->postAction('theme', ['mode' => 'dark'])->location());
	}

	public function testAnAddressOfAnotherSiteIsNotFollowed(): void
	{
		foreach (['http://example.com/', '//example.com/', '/index.php.evil'] as $return) {
			$this->assertSame('/index.php', $this->postAction('theme', ['mode' => 'dark', 'return' => $return])->location(), $return);
		}
	}

	public function testAnUnknownModeIsRefused(): void
	{
		$response = $this->postAction('theme', ['mode' => 'blue']);
		$this->assertSame(400, $response->status);
		$this->assertNull($response->setCookieValue('w2_theme'));
		$this->assertStringNotContainsString('data-theme', $this->http->get('/index.php')->body);
	}

	public function testAnInvalidCookieIsIgnored(): void
	{
		$body = $this->http->get('/index.php', [], ['Cookie: w2_theme="><script>alert(1)</script>'])->body;
		$this->assertStringNotContainsString('data-theme', $body);
		$this->assertStringNotContainsString('alert(1)', $body);
	}

	public function testItNeedsPostAndTheToken(): void
	{
		$token = $this->csrfToken();
		$this->assertSame(403, $this->http->get('/index.php', ['action' => 'theme', 'mode' => 'dark', 'csrf_token' => $token])->status);
		$this->assertSame(403, $this->http->post('/index.php', ['action' => 'theme', 'mode' => 'dark'])->status);
		$this->assertSame(403, $this->http->post('/index.php', ['action' => 'theme', 'mode' => 'dark', 'csrf_token' => str_repeat('a', 64)])->status);
		$this->assertStringNotContainsString('data-theme', $this->http->get('/index.php')->body);
	}

	/** The scheme which the button in the toolbar chooses */
	private function nextMode(string $html): string
	{
		$this->assertSame(1, preg_match('#<button type="submit" name="mode" value="([a-z]+)">#', $html, $matches));
		return $matches[1];
	}
}
