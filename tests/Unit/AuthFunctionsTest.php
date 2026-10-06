<?php

namespace W2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthFunctionsTest extends TestCase
{
	public static function ipAddresses(): array
	{
		return [
			// address, allowlist entry, expected
			'same address' => ['10.0.0.1', '10.0.0.1', true],
			'address with the same beginning' => ['10.0.0.123', '10.0.0.1', false],
			'address which begins like the entry' => ['10.0.0.1', '10.0.0.10', false],
			'inside a /24' => ['192.168.1.77', '192.168.1.0/24', true],
			'outside a /24' => ['192.168.10.1', '192.168.1.0/24', false],
			'first address of a /24' => ['192.168.1.0', '192.168.1.0/24', true],
			'last address of a /24' => ['192.168.1.255', '192.168.1.0/24', true],
			'inside a /8' => ['10.1.2.3', '10.0.0.0/8', true],
			'outside a /8' => ['11.1.2.3', '10.0.0.0/8', false],
			'inside a /12' => ['172.16.5.1', '172.16.0.0/12', true],
			'last address inside a /12' => ['172.31.255.255', '172.16.0.0/12', true],
			'first address outside a /12' => ['172.32.0.1', '172.16.0.0/12', false],
			'/32 is a single address' => ['1.2.3.4', '1.2.3.4/32', true],
			'/32 does not match a neighbour' => ['1.2.3.5', '1.2.3.4/32', false],
			'/0 matches everything' => ['1.2.3.4', '0.0.0.0/0', true],
			'/25 boundary inside' => ['192.168.1.127', '192.168.1.0/25', true],
			'/25 boundary outside' => ['192.168.1.128', '192.168.1.0/25', false],
			'prefix with dot' => ['192.168.1.5', '192.168.1.', true],
			'prefix with dot, other network' => ['192.168.10.5', '192.168.1.', false],
			'prefix with dots does not match a longer number' => ['10.00.1.1', '10.0.', false],
			'entry with spaces' => ['10.0.0.1', ' 10.0.0.1 ', true],
			'ipv6 same address' => ['::1', '::1', true],
			'ipv6 different address' => ['::2', '::1', false],
			'ipv6 in a /8' => ['fd00::1', 'fd00::/8', true],
			'ipv6 outside a /8' => ['fe80::1', 'fd00::/8', false],
			'ipv6 in a /32' => ['2001:db8::1', '2001:db8::/32', true],
			'ipv6 outside a /32' => ['2001:db9::1', '2001:db8::/32', false],
			'ipv6 /33 boundary' => ['2001:db8:8000::1', '2001:db8::/33', false],
			'ipv6 prefix' => ['fe80::1234', 'fe80:', true],
			'ipv4 address vs ipv6 range' => ['10.0.0.1', '::/0', false],
			'ipv6 address vs ipv4 range' => ['::1', '0.0.0.0/0', false],
			'mask too long for ipv4' => ['1.2.3.4', '1.2.3.4/33', false],
			'negative mask' => ['1.2.3.4', '1.2.3.4/-1', false],
			'mask which is not a number' => ['1.2.3.4', '1.2.3.0/abc', false],
			'empty mask' => ['1.2.3.4', '1.2.3.4/', false],
			'garbage entry' => ['1.2.3.4', 'junk', false],
			'empty entry' => ['1.2.3.4', '', false],
			'garbage address' => ['junk', '1.2.3.4', false],
		];
	}

	#[DataProvider('ipAddresses')]
	public function testIpAddressMatching(string $ip, string $allowed, bool $expected): void
	{
		$this->assertSame($expected, ipMatches($ip, $allowed));
	}

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
		$this->assertSame($secure, isHttpsRequest($server));
		$params = sessionCookieParams($server);
		$this->assertSame($secure, $params['secure']);
		$this->assertTrue($params['httponly']);
		$this->assertSame('Lax', $params['samesite']);
		$this->assertSame('/', $params['path']);
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
