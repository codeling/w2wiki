<?php
// SPDX-License-Identifier: MIT

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wiki's functions around the shared library in webauth/ (whose own tests are in webauth/tests):
 * the settings they use, and the behaviour the wiki relies on
 */
final class AuthFunctionsTest extends TestCase
{
	public static function passwordConfigurations(): array
	{
		$bcrypt = password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]);
		return [
			// password, hash setting, plain password setting, expected
			'bcrypt hash, right password' => ['hunter2', $bcrypt, '', true],
			'bcrypt hash, wrong password' => ['hunter3', $bcrypt, '', false],
			'bcrypt hash, plain password setting is ignored' => ['secret', $bcrypt, 'secret', false],
			'argon2 style hash is recognized' => ['x', '$argon2id$v=19$m=1,t=1,p=1$c29tZXNhbHQ$aGFzaA', '', false],
			'sha1 hash, right password' => ['legacy', sha1('legacy'), '', true],
			'sha1 hash in capitals' => ['legacy', strtoupper(sha1('legacy')), '', true],
			'sha1 hash, wrong password' => ['legacyx', sha1('legacy'), '', false],
			'sha1 hash, hash itself as password' => [sha1('legacy'), sha1('legacy'), '', false],
			'magic hash collision (both 0e...)' => ['aaK1STfY', sha1('aaroZmOk'), '', false],
			'magic hash, right password' => ['aaroZmOk', sha1('aaroZmOk'), '', true],
			'plain password, right' => ['correct horse', '', 'correct horse', true],
			'plain password, wrong' => ['correct horsf', '', 'correct horse', false],
			'plain password, different case' => ['Correct Horse', '', 'correct horse', false],
			'default password is refused' => ['secret', '', 'secret', false],
			'nothing configured' => ['anything', '', '', false],
			'empty password with empty hash' => ['', '', '', false],
			'empty password with hash' => ['', sha1(''), '', false],
			'empty password with plain password' => ['', '', 'x', false],
		];
	}

	#[DataProvider('passwordConfigurations')]
	public function testPasswordChecking(string $password, string $hash, string $plain, bool $expected): void
	{
		$this->assertSame($expected, isCorrectPassword($password, $hash, $plain));
	}

	public static function hashes(): array
	{
		return [
			'bcrypt' => [password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), false],
			'default algorithm' => [password_hash('x', PASSWORD_DEFAULT), false],
			'argon2id' => [defined('PASSWORD_ARGON2ID') ? password_hash('x', PASSWORD_ARGON2ID) : '$2y$04$' . str_repeat('a', 53), false],
			'sha1' => [sha1('x'), true],
			'sha1 in capitals' => [strtoupper(sha1('x')), true],
			'no hash' => ['', false],
		];
	}

	#[DataProvider('hashes')]
	public function testLegacyPasswordHashesAreRecognized(string $hash, bool $legacy): void
	{
		$this->assertSame($legacy, isLegacyPasswordHash($hash));
	}

	public function testPasswordsWhichAreNotStringsAreRefused(): void
	{
		$this->assertFalse(isCorrectPassword(null, '', 'x'));
		$this->assertFalse(isCorrectPassword(['x'], '', 'x'));
		$this->assertFalse(isCorrectPassword(true, '', '1'));
	}

	public function testCsrfTokens(): void
	{
		$_SESSION = ['csrf_token' => str_repeat('ab', 32)];
		$this->assertSame(str_repeat('ab', 32), csrfToken());
		$this->assertTrue(isValidCSRFToken(str_repeat('ab', 32)));
		$this->assertFalse(isValidCSRFToken(str_repeat('ab', 31)));
		$this->assertFalse(isValidCSRFToken(''));
		$this->assertFalse(isValidCSRFToken(null));
		$this->assertFalse(isValidCSRFToken(['x']));
		$this->assertSame(
			'<input type="hidden" name="csrf_token" value="' . str_repeat('ab', 32) . '" />',
			csrfField()
		);
		unset($_SESSION);
	}

	public function testCrossSiteRequests(): void
	{
		$this->assertTrue(isCrossSiteRequest(['HTTP_SEC_FETCH_SITE' => 'cross-site']));
		$this->assertTrue(isCrossSiteRequest(['HTTP_SEC_FETCH_SITE' => 'same-site']));
		$this->assertFalse(isCrossSiteRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']));
		$this->assertFalse(isCrossSiteRequest(['HTTP_SEC_FETCH_SITE' => 'none']));
		$this->assertFalse(isCrossSiteRequest([]));
	}

	public static function serverVariables(): array
	{
		return [
			'HTTPS on' => [['HTTPS' => 'on'], true],
			'HTTPS 1' => [['HTTPS' => '1'], true],
			'HTTPS off' => [['HTTPS' => 'off'], false],
			'HTTPS empty' => [['HTTPS' => ''], false],
			'HTTPS unset' => [[], false],
			'X-Forwarded-Proto is not trusted' => [['HTTP_X_FORWARDED_PROTO' => 'https'], false],
			'X-Forwarded-Ssl is not trusted' => [['HTTP_X_FORWARDED_SSL' => 'on'], false],
			'forwarded header does not override HTTPS off' => [['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], false],
			'port 443 alone is not HTTPS' => [['SERVER_PORT' => '443'], false],
		];
	}

	#[DataProvider('serverVariables')]
	public function testSessionCookieIsSecureOnlyOverHttps(array $server, bool $secure): void
	{
		$params = sessionCookieParams($server);
		$this->assertSame($secure, $params['secure']);
		$this->assertTrue($params['httponly']);
		$this->assertSame('Lax', $params['samesite']);
		$this->assertSame('/', $params['path']);
		$this->assertSame(0, $params['lifetime'], 'no login: a session cookie (REQUIRE_PASSWORD is false by default)');
	}

	public function testDefaultLoginThrottleFolderIsPerInstallation(): void
	{
		$this->assertSame(
			rtrim(sys_get_temp_dir(), '/') . '/w2-login-' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 16),
			loginThrottleFolder()
		);
	}

	public function testStrictTransportSecurityIsSentOnlyOverHttps(): void
	{
		$this->assertArrayNotHasKey('Strict-Transport-Security', securityHeaders([]));
		$this->assertArrayNotHasKey('Strict-Transport-Security', securityHeaders(['HTTPS' => 'off']));
		$this->assertSame('max-age=31536000', securityHeaders(['HTTPS' => 'on'])['Strict-Transport-Security']);
		$this->assertArrayNotHasKey('Strict-Transport-Security', securityHeaders(['HTTP_X_FORWARDED_PROTO' => 'https']), 'not trusted');
	}

	public function testContentSecurityPolicyAllowsOnlyTheWikisOwnScripts(): void
	{
		$policy = contentSecurityPolicy();
		$this->assertStringContainsString("script-src 'self' 'nonce-" . cspNonce() . "' 'unsafe-hashes' 'sha256-", $policy);
		$this->assertStringNotContainsString('unsafe-inline', $policy);
		$this->assertStringNotContainsString('unsafe-eval', $policy);
	}
}
