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

	private static function text(array $words, string $key): string
	{
		return htmlspecialchars($words[$key] ?? $key, ENT_QUOTES, 'UTF-8');
	}

	#[DataProvider('locales')]
	public function testListHeadersAndFormButtonsAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		foreach (['/index.php?action=upload', '/index.php?action=all'] as $url) {
			$body = $this->http->get($url)->body;
			foreach (['Name', 'Modified', 'Size'] as $key) {
				$this->assertStringContainsString('>' . self::text($words, $key) . '</', $body, "$locale: $url $key");
			}
		}
		$this->assertStringContainsString('value="' . self::text($words, 'Cancel') . '"', $this->http->get('/index.php?action=delete&page=Home')->body);
		$this->assertStringContainsString('<h1>' . self::text($words, 'Search') . ': home</h1>', $this->http->get('/index.php?action=search&q=home')->body);
	}

	#[DataProvider('locales')]
	public function testUploadScriptMessagesAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		$body = $this->http->get('/index.php?action=upload')->body;
		foreach (['No file selected!', 'File %s already exists. Overwrite?'] as $key) {
			$literal = json_encode($words[$key] ?? $key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
			$this->assertStringContainsString($literal, $body, "$locale: $key");
		}
	}

	#[DataProvider('locales')]
	public function testPageCreationErrorsAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		$existing = $this->savePage('Home', 'again')->body;
		$key = "Error creating page '%s' - it already exists! Please choose a different name, or %s the existing page (this discards current text!)!";
		$this->assertStringContainsString(
			sprintf(self::text($words, $key), 'Home', '<a href="?action=edit&amp;page=Home">' . self::text($words, 'edit') . '</a>'),
			$existing,
			$locale
		);
		$key = "Error creating page '%s' - invalid page name! Page names must not start with '%s/', or contain empty or hidden ('.'-prefixed) folder names.";
		$this->assertStringContainsString(
			sprintf(self::text($words, $key), '.hidden/x', 'images'),
			$this->savePage('.hidden/x', 'text')->body,
			$locale
		);
	}

	#[DataProvider('locales')]
	public function testUploadMessagesAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		$upload = fn() => $this->upload('a.gif', self::gif());
		$note = $this->noteAfter($upload());
		$this->assertStringContainsString(html_entity_decode(sprintf(self::text($words, "File '%s' uploaded!"), 'a.gif'), ENT_QUOTES), $note, $locale);
		$this->assertStringContainsString(
			html_entity_decode(sprintf(self::text($words, 'Use %s to refer to it!'), '![' . ($words['Image Description'] ?? 'Image Description') . '](/images/a.gif)'), ENT_QUOTES),
			$note,
			$locale
		);
		$note = $this->noteAfter($upload());
		$this->assertStringContainsString(html_entity_decode(sprintf(self::text($words, '%s already exists!'), 'a.gif'), ENT_QUOTES), $note, $locale);
	}

	#[DataProvider('locales')]
	public function testSimilarPageNoteIsTranslated(string $locale): void
	{
		$words = self::words($locale);
		$this->savePage('Similar Page', 'x');
		$body = $this->http->get($this->pageUrl('Simular Page'))->body;
		$this->assertStringContainsString('<strong>' . self::text($words, 'Note') . ':</strong>', $body, $locale);
		$this->assertStringContainsString(
			sprintf(self::text($words, 'Found similar page %s. Maybe you meant to edit this instead?'), '<a href="/index.php/Similar%20Page">Similar Page</a>'),
			$body,
			$locale
		);
	}

	#[DataProvider('locales')]
	public function testMessagesOfRejectedRequestsAreTranslated(string $locale): void
	{
		$words = self::words($locale);
		$response = $this->http->post('/index.php', ['action' => 'save', 'page' => 'X', 'newText' => 'x']);
		$this->assertSame(403, $response->status);
		$this->assertStringContainsString(
			self::text($words, 'Invalid request: missing or wrong security token. Please go back, reload the page and try again.'),
			$response->body,
			$locale
		);
	}
}
