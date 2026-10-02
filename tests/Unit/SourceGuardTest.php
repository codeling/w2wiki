<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Looks at the tokens of the wiki's PHP files for constructs which caused (or could easily cause)
 * security problems: code execution, unquoted values in regular expressions, unserialize()...
 */
final class SourceGuardTest extends TestCase
{
	private const FILES = ['index.php', 'api.php', 'auth.php', 'auth_functions.php', 'functions.php', 'config.php'];

	/** Functions which run code or commands, or are otherwise dangerous with user input */
	private const FORBIDDEN_FUNCTIONS = [
		'system', 'passthru', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec', 'unserialize', 'extract',
		'assert', 'create_function', 'parse_str', 'mb_ereg_replace', 'eval', 'call_user_func', 'call_user_func_array',
	];

	public static function files(): array
	{
		return array_map(fn($file) => [$file], self::FILES);
	}

	/** @return array<int, array|string> */
	private static function tokens(string $file): array
	{
		return array_values(array_filter(
			token_get_all((string)file_get_contents(dirname(__DIR__, 2) . "/$file")),
			fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
		));
	}

	private static function text(array|string $token): string
	{
		return is_array($token) ? $token[1] : $token;
	}

	private static function isCall(array $tokens, int $i): bool
	{
		$previous = $tokens[$i - 1] ?? '';
		return is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && ($tokens[$i + 1] ?? '') === '('
			&& !(is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true));
	}

	/** Whether the token belongs to (or joins) a string, so a variable next to it becomes part of the string */
	private static function isPartOfString(array|string $token): bool
	{
		return $token === '.' || $token === '"' || (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true));
	}

	#[DataProvider('files')]
	public function testNoDangerousFunctionsAreCalled(string $file): void
	{
		$tokens = self::tokens($file);
		foreach ($tokens as $i => $token) {
			if (is_array($token) && $token[0] === T_EVAL) {
				$this->fail("eval in $file line {$token[2]}");
			}
			if (self::isCall($tokens, $i)) {
				$name = strtolower($token[1]);
				$this->assertNotContains($name, self::FORBIDDEN_FUNCTIONS, "$name() in $file line {$token[2]}");
			}
		}
		$this->addToAssertionCount(1);
	}

	/** Commands are only run by checkedExecute(), whose callers quote the arguments */
	public function testExecIsOnlyUsedByCheckedExecute(): void
	{
		$callers = [];
		foreach (self::FILES as $file) {
			$tokens = self::tokens($file);
			$function = '';
			foreach ($tokens as $i => $token) {
				if (is_array($token) && $token[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null)) {
					$function = $tokens[$i + 1][1];
				}
				if (self::isCall($tokens, $i) && strtolower($token[1]) === 'exec') {
					$callers[] = "$file: $function";
				}
			}
		}
		$this->assertSame(['index.php: checkedExecute'], $callers);
	}

	/** Only the autoloader includes files with a name which is not a fixed string or a constant */
	#[DataProvider('files')]
	public function testFilesAreNotIncludedWithVariableNames(string $file): void
	{
		$tokens = self::tokens($file);
		foreach ($tokens as $i => $token) {
			if (!is_array($token) || !in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
				continue;
			}
			$statement = [];
			for ($j = $i + 1; ($tokens[$j] ?? ';') !== ';'; $j++) {
				$statement[] = self::text($tokens[$j]);
			}
			$code = implode(' ', $statement);
			if (str_contains($code, '$')) {
				$this->assertSame(['functions.php', '$file'], [$file, $code], "include with a variable in $file line {$token[2]}");
			}
		}
		$this->addToAssertionCount(1);
	}

	/**
	 * The pattern of preg_* functions may contain values only when they are quoted with preg_quote()
	 * (user input must not be interpreted as a regular expression)
	 */
	#[DataProvider('files')]
	public function testRegularExpressionsDoNotContainUnquotedVariables(string $file): void
	{
		$tokens = self::tokens($file);
		foreach ($tokens as $i => $token) {
			if (!self::isCall($tokens, $i) || !str_starts_with(strtolower($token[1]), 'preg_') || strtolower($token[1]) === 'preg_quote') {
				continue;
			}
			$depth = 0;
			$quoteDepths = [];
			for ($j = $i + 2; $j < count($tokens); $j++) {
				$current = $tokens[$j];
				$text = self::text($current);
				if ($text === '(') {
					$depth++;
					if (is_array($tokens[$j - 1]) && strtolower($tokens[$j - 1][1]) === 'preg_quote') {
						$quoteDepths[] = $depth;
					}
				} elseif ($text === ')') {
					if ($depth === 0) {
						break;
					}
					if (end($quoteDepths) === $depth) {
						array_pop($quoteDepths);
					}
					$depth--;
				} elseif ($text === ',' && $depth === 0) {
					break;   // the end of the pattern
				} elseif (is_array($current) && in_array($current[0], [T_VARIABLE, T_ENCAPSED_AND_WHITESPACE], true) && $quoteDepths === []) {
					$this->fail("$token[1]() in $file line {$current[2]} contains $text which is not quoted with preg_quote()");
				}
			}
		}
		$this->addToAssertionCount(1);
	}

	/** Values in replacement strings would be interpreted ($1, \1); they need pregReplacementQuote() */
	public function testReplacementStringsWithVariablesAreQuoted(): void
	{
		$tokens = self::tokens('index.php');
		foreach ($tokens as $i => $token) {
			if (!self::isCall($tokens, $i) || strtolower($token[1]) !== 'preg_replace') {
				continue;
			}
			$depth = 0;
			$commas = 0;
			$quoted = [];
			for ($j = $i + 2; $j < count($tokens); $j++) {
				$text = self::text($tokens[$j]);
				if ($text === '(') {
					$depth++;
					if (is_array($tokens[$j - 1]) && strtolower($tokens[$j - 1][1]) === 'pregreplacementquote') {
						$quoted[] = $depth;
					}
				} elseif ($text === ')') {
					if ($depth === 0) {
						break;
					}
					if (end($quoted) === $depth) {
						array_pop($quoted);
					}
					$depth--;
				} elseif ($text === ',' && $depth === 0) {
					$commas++;
				} elseif ($commas === 1 && is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE && $quoted === []
					&& (self::isPartOfString($tokens[$j - 1]) || self::isPartOfString($tokens[$j + 1]))) {
					$this->fail("preg_replace() in index.php line {$tokens[$j][2]} has an unquoted replacement value $text");
				}
			}
		}
		$this->addToAssertionCount(1);
	}

	/** Request data must only be read by the entry points, which are responsible for escaping it */
	#[DataProvider('files')]
	public function testRequestDataIsOnlyReadByEntryPoints(string $file): void
	{
		$allowed = ['index.php', 'api.php', 'auth.php'];
		$superGlobals = ['$_REQUEST', '$_GET', '$_POST', '$_FILES', '$_COOKIE'];
		foreach (self::tokens($file) as $token) {
			if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], $superGlobals, true)) {
				$this->assertContains($file, $allowed, "$token[1] in $file line {$token[2]}");
			}
		}
		$this->addToAssertionCount(1);
	}
}
