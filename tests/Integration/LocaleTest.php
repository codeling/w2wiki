<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/**
 * Every locale file can be used: the main views are shown (PHP warnings fail the test, see AppTestCase::tearDown),
 * with the language of the page set, and the translated texts used.
 *
 * The locale is the name of the data set (see configOverrides()).
 */
final class LocaleTest extends AppTestCase
{
	private const VIEWS = [
		'/index.php',
		'/index.php/MarkdownSyntax',
		'/index.php?action=all',
		'/index.php?action=new',
		'/index.php?action=upload',
		'/index.php?action=edit&page=Home',
		'/index.php?action=rename&page=Home',
		'/index.php?action=delete&page=Home',
		'/index.php?action=view&page=Home&linkshere=true',
		'/index.php/Nonexistent',
		'/index.php?action=search&q=home',
		'/index.php?action=imgRename&imgName=a.gif&prevpage=Home',
		'/index.php?action=imgDelete&imgName=a.gif&prevpage=Home',
	];

	public static function locales(): array
	{
		$result = [];
		foreach (glob(dirname(__DIR__, 2) . '/locales/*.php') as $file) {
			$result[basename($file, '.php')] = [basename($file, '.php')];
		}
		return $result;
	}

	protected function configOverrides(): array
	{
		return ['W2_LOCALE' => (string)$this->dataName()];
	}

	/** @return array<string, string> */
	private static function words(string $locale): array
	{
		return (function () use ($locale) {
			defined('W2APP') || define('W2APP', true);
			$w2_word_set = [];
			require dirname(__DIR__, 2) . "/locales/$locale.php";
			return $w2_word_set;
		})();
	}

	#[DataProvider('locales')]
	public function testAllViewsAreShown(string $locale): void
	{
		foreach (self::VIEWS as $url) {
			$response = $this->http->get($url);
			$this->assertSame(200, $response->status, "$locale: $url");
			$this->assertStringContainsString('<div class="main">', $response->body, "$locale: $url");
			$this->assertStringContainsString('</html>', $response->body, "$locale: $url");
		}
	}

	#[DataProvider('locales')]
	public function testLanguageAndCharsetOfThePage(string $locale): void
	{
		$response = $this->http->get('/index.php');
		$this->assertStringContainsString("<html lang=\"$locale\">", $response->body);
		$this->assertStringContainsString('<meta charset="UTF-8">', $response->body);
		$this->assertTrue(mb_check_encoding($response->body, 'UTF-8'), 'the page is not valid UTF-8');
	}

	#[DataProvider('locales')]
	public function testToolbarTooltipsAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		$body = $this->http->get('/index.php')->body;
		foreach (['All', 'New', 'Upload'] as $key) {
			$text = htmlspecialchars($words[$key] ?? $key, ENT_QUOTES, 'UTF-8');
			$this->assertStringContainsString("title=\"$text\"", $body, "$locale: tooltip '$key'");
		}
		$this->assertStringContainsString('placeholder="' . htmlspecialchars($words['Search'] ?? 'Search', ENT_QUOTES, 'UTF-8') . '"', $body);
	}

	#[DataProvider('locales')]
	public function testTitlesAndDatesUseTheLocale(string $locale): void
	{
		$words = self::words($locale);
		$body = $this->http->get('/index.php?action=all')->body;
		$this->assertStringContainsString('<span class="title">' . htmlspecialchars($words['All'] ?? 'All', ENT_QUOTES, 'UTF-8') . '</span>', $body);
		$page = $this->http->get('/index.php/Home')->body;
		$format = $words['date_format'] ?? null;
		if ($format !== null) {
			$this->assertMatchesRegularExpression('/<span class="titledate">[^<]+<\/span>/', $page);
		}
	}
}
