<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * config.sample.php documents the settings, defaults.php provides the values of those which config.php doesn't
 * define: they must agree, and the code must not use a setting which has no default.
 */
final class ConfigDefaultsTest extends TestCase
{
	private const CODE_FILES = ['index.php', 'api.php', 'file.php', 'auth.php', 'auth_functions.php', 'functions.php'];

	/** @return array{constants: array<string, mixed>, variables: array<string, mixed>} what the file defines when loaded alone */
	private static function settingsDefinedBy(string $file): array
	{
		$script = 'define("W2APP", true); $_SERVER["SCRIPT_NAME"] = "/index.php"; require ' . var_export(dirname(__DIR__, 2) . "/$file", true) . ';'
			. ' $constants = get_defined_constants(true)["user"]; unset($constants["W2APP"]);'
			. ' echo json_encode(["constants" => $constants, "variables" => ["allowedIPs" => $allowedIPs ?? null, "trustedProxies" => $trustedProxies ?? null]]);';
		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
		$settings = json_decode((string)$output, true);
		self::assertIsArray($settings, "$file can't be loaded: $output");
		ksort($settings['constants']);
		return $settings;
	}

	public function testSampleAndDefaultsHaveTheSameSettings(): void
	{
		$this->assertSame(
			self::settingsDefinedBy('config.sample.php'),
			self::settingsDefinedBy('defaults.php'),
			'config.sample.php and defaults.php must define the same settings with the same values'
		);
	}

	public function testDefaultsDoNotNeedAConfigFile(): void
	{
		$settings = self::settingsDefinedBy('defaults.php');
		$this->assertGreaterThan(30, count($settings['constants']));
		$this->assertSame([], $settings['variables']['allowedIPs']);
		$this->assertSame([], $settings['variables']['trustedProxies']);
	}

	/** Every upper-case constant in the code is a setting (which must have a default), declared by the code itself, or defined by PHP */
	public function testEverySettingUsedByTheCodeHasADefault(): void
	{
		$missing = [];
		$tokensOf = fn(string $file) => array_values(array_filter(
			token_get_all((string)file_get_contents(dirname(__DIR__, 2) . "/$file")),
			fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
		));
		$declared = [];
		foreach (self::CODE_FILES as $file) {
			foreach ($tokensOf($file) as $i => $token) {
				if (is_array($token) && $token[0] === T_CONST) {
					$declared[] = $tokensOf($file)[$i + 1][1];
				}
			}
		}
		foreach (self::CODE_FILES as $file) {
			$tokens = $tokensOf($file);
			foreach ($tokens as $i => $token) {
				if (!is_array($token) || $token[0] !== T_STRING || !preg_match('/^[A-Z][A-Z0-9_]+$/', $token[1])) {
					continue;
				}
				$previous = $tokens[$i - 1] ?? '';
				$next = $tokens[$i + 1] ?? '';
				$isDeclarationOrMember = is_array($previous) && in_array($previous[0], [T_CONST, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NS_SEPARATOR], true);
				if ($isDeclarationOrMember || $next === '(' || (is_array($next) && $next[0] === T_DOUBLE_COLON)) {
					continue;
				}
				if (!defined($token[1]) && !in_array($token[1], $declared, true)) {
					$missing[$token[1]] = "$file line $token[2]";
				}
			}
		}
		$this->assertSame([], $missing, 'constants used by the code which have no default in defaults.php');
	}
}
