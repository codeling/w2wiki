<?php

namespace W2\Tests\Integration;

use W2\Tests\Support\AppTestCase;

/**
 * Translations are escaped like all other output, so a locale file (e.g. from a third party) with quotes
 * or markup in its texts can't break out of attributes or add elements. Uses a generated locale file.
 */
final class LocaleEscapingTest extends AppTestCase
{
	private const PAYLOAD = '"><img src=x onerror=alert(1)>\'<script>alert(2)</script>&amp;';

	protected function configOverrides(): array
	{
		return ['W2_LOCALE' => 'generated'];
	}

	protected function setUp(): void
	{
		parent::setUp();
		$texts = [];
		foreach (['All', 'New', 'Upload', 'Search', 'Home', 'Edit', 'Save', 'Cancel', 'Title', 'Close', 'Formatting help', 'Header', 'Bold', 'Emphasize'] as $key) {
			$texts[$key] = self::PAYLOAD . $key;
		}
		file_put_contents($this->server->rootDir() . '/locales/generated.php', "<?php\n\$w2_word_set = " . var_export($texts, true) . ";\n");
	}

	protected function tearDown(): void
	{
		@unlink($this->server->rootDir() . '/locales/generated.php');
		parent::tearDown();
	}

	public function testTextsWithMarkupAndQuotesAreEscaped(): void
	{
		foreach (['/index.php', '/index.php?action=all', '/index.php?action=new', '/index.php?action=edit&page=Home'] as $url) {
			$response = $this->http->get($url);
			$this->assertSame(200, $response->status, $url);
			$this->assertStringNotContainsString('<script>alert(2)', $response->body, $url);
			$this->assertStringNotContainsString('<img src=x', $response->body, $url);
			$this->assertStringNotContainsString('onerror=alert(1)>', $response->body, $url);
			$this->assertStringContainsString(
				'title="' . htmlspecialchars(self::PAYLOAD . 'All', ENT_QUOTES, 'UTF-8') . '"',
				$response->body,
				"the text is shown, escaped: $url"
			);
		}
	}

	public function testAttributesCannotBeClosedByTranslations(): void
	{
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="UTF-8">' . $this->http->get('/index.php')->body);
		foreach ($dom->getElementsByTagName('img') as $img) {
			$this->assertFalse($img->hasAttribute('onerror'), 'an img element got an event handler');
			$this->assertNotSame('x', $img->getAttribute('src'));
		}
	}
}
