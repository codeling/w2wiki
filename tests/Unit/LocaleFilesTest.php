<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The locale files are valid and well-formed. Texts used in the code which a language doesn't translate are
 * reported as "incomplete" but don't fail, because __() falls back to the English text.
 */
final class LocaleFilesTest extends TestCase
{
	private const SOURCE_FILES = ['index.php', 'api.php', 'auth.php', 'auth_functions.php', 'functions.php'];
	private const ROOT_LOCALE = 'en';

	public static function locales(): array
	{
		$result = [];
		foreach (glob(dirname(__DIR__, 2) . '/locales/*.php') as $file) {
			$result[basename($file, '.php')] = [basename($file, '.php')];
		}
		return $result;
	}

	/** Loads a locale file the way index.php does, but in isolation from the globals of the test run */
	private static function load(string $locale): array
	{
		$file = dirname(__DIR__, 2) . "/locales/$locale.php";
		return (function () use ($file) {
			$w2_word_set = null;
			require $file;
			return $w2_word_set;
		})();
	}

	/** @return string[] keys of literal __() calls in the code (dynamic calls such as __($action) can't be known) */
	private static function usedKeys(): array
	{
		$keys = [];
		foreach (self::SOURCE_FILES as $file) {
			$tokens = array_values(array_filter(
				token_get_all((string)file_get_contents(dirname(__DIR__, 2) . "/$file")),
				fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
			));
			foreach ($tokens as $i => $token) {
				$previous = $tokens[$i - 1] ?? '';
				if (is_array($token) && $token[0] === T_STRING && $token[1] === '__' && ($tokens[$i + 1] ?? '') === '('
					&& !(is_array($previous) && $previous[0] === T_FUNCTION)
					&& is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
					$keys[] = eval('return ' . $tokens[$i + 2][1] . ';');
				}
			}
		}
		return array_values(array_unique($keys));
	}

	/** The keys of the array literal in the file, in order, to find duplicates (PHP silently keeps the last one) */
	private static function declaredKeys(string $locale): array
	{
		$tokens = array_values(array_filter(
			token_get_all((string)file_get_contents(dirname(__DIR__, 2) . "/locales/$locale.php")),
			fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
		));
		$keys = [];
		foreach ($tokens as $i => $token) {
			if (is_array($token) && $token[0] === T_DOUBLE_ARROW && is_array($tokens[$i - 1])
				&& $tokens[$i - 1][0] === T_CONSTANT_ENCAPSED_STRING) {
				$keys[] = eval('return ' . $tokens[$i - 1][1] . ';');
			}
		}
		return $keys;
	}

	#[DataProvider('locales')]
	public function testFileDefinesAnArrayOfStrings(string $locale): void
	{
		$words = self::load($locale);
		$this->assertIsArray($words);
		$this->assertNotEmpty($words);
		foreach ($words as $key => $value) {
			$this->assertIsString($key);
			$this->assertIsString($value, "value of '$key'");
		}
	}

	#[DataProvider('locales')]
	public function testFileHasNoOutputAndNoByteOrderMark(string $locale): void
	{
		$source = (string)file_get_contents(dirname(__DIR__, 2) . "/locales/$locale.php");
		$this->assertStringStartsWith('<?php', $source, 'no byte order mark or output before the PHP tag');
		ob_start();
		self::load($locale);
		$this->assertSame('', ob_get_clean());
	}

	#[DataProvider('locales')]
	public function testFileIsValidUtf8WithoutControlCharacters(string $locale): void
	{
		$source = (string)file_get_contents(dirname(__DIR__, 2) . "/locales/$locale.php");
		$this->assertTrue(mb_check_encoding($source, 'UTF-8'), 'not UTF-8');
		foreach (self::load($locale) as $key => $value) {
			$this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $value, "value of '$key'");
		}
	}

	#[DataProvider('locales')]
	public function testFileHasNoDuplicateKeys(string $locale): void
	{
		$keys = self::declaredKeys($locale);
		$this->assertNotEmpty($keys);
		$this->assertSame([], array_keys(array_filter(array_count_values($keys), fn($n) => $n > 1)));
	}

	#[DataProvider('locales')]
	public function testTranslationsAreNotEmpty(string $locale): void
	{
		// __() treats empty values like missing ones, so they only hide the fallback
		foreach (self::load($locale) as $key => $value) {
			$this->assertNotSame('', trim($value), "value of '$key'");
		}
	}

	#[DataProvider('locales')]
	public function testDateFormatsAreValid(string $locale): void
	{
		$words = self::load($locale);
		foreach (['date_format', 'date_format_no_time'] as $key) {
			if (!isset($words[$key])) {
				continue;
			}
			$formatted = date($words[$key], 86400 * 365);
			$this->assertNotSame($words[$key], $formatted, "$key is not a date format");
			$this->assertMatchesRegularExpression('/\d/', $formatted, $key);
			$this->assertNotFalse(\DateTime::createFromFormat($words[$key], $formatted), "$key can't be parsed back");
		}
	}

	#[DataProvider('locales')]
	public function testPlaceholdersAreKept(string $locale): void
	{
		$this->addToAssertionCount(1);
		foreach (self::load($locale) as $key => $value) {
			if (str_contains($key, '%s')) {
				$this->assertSame(substr_count($key, '%s'), substr_count($value, '%s'), "placeholders of '$key'");
			}
		}
	}

	/** Texts used in the code, and the entries which aren't texts of the code (e.g. titles looked up by action name) */
	private static function knownKeys(): array
	{
		// 'Home' is the value of DEFAULT_PAGE, which is looked up with __(DEFAULT_PAGE)
		return array_values(array_unique(array_merge(self::usedKeys(), ['Home'], array_keys(self::load(self::ROOT_LOCALE)))));
	}

	public function testEnglishOnlyHasEntriesWhichDifferFromTheirKey(): void
	{
		// __() returns the key for texts without entry, so identical entries only need to be maintained
		foreach (self::load(self::ROOT_LOCALE) as $key => $value) {
			$this->assertNotSame($key, $value, "'$key' is shown as it is without an entry in locales/en.php");
		}
	}

	public function testExtractionFindsTheTextsOfTheCode(): void
	{
		$keys = self::usedKeys();
		foreach (['Wrong password', 'Invalid page name', 'Upload error', 'date_format'] as $key) {
			$this->assertContains($key, $keys);
		}
	}

	#[DataProvider('locales')]
	public function testNoUnknownTexts(string $locale): void
	{
		// e.g. a text of the code was renamed or has a typo (then it's never used)
		$unknown = array_values(array_diff(array_keys(self::load($locale)), self::knownKeys()));
		$this->assertSame([], $unknown, "texts of locales/$locale.php which are not used in the code");
	}

	#[DataProvider('locales')]
	public function testMissingTranslationsAreReported(string $locale): void
	{
		if ($locale === self::ROOT_LOCALE) {
			$this->addToAssertionCount(1);
			return;
		}
		$missing = array_values(array_diff(self::knownKeys(), array_keys(self::load($locale))));
		if ($missing) {
			$this->markTestIncomplete(count($missing) . " texts of the code are not translated in locales/$locale.php (the English text is shown): " . implode(', ', $missing));
		}
		$this->addToAssertionCount(1);
	}
}
