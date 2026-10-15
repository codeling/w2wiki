<?php

namespace W2\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use W2\Tests\Support\AppTestCase;

/** Security headers of the responses of index.php and api.php */
final class SecurityHeadersTest extends AppTestCase
{
	public static function urls(): array
	{
		return [
			'page' => ['/index.php'], 'editor' => ['/index.php?action=edit&page=Home'], 'upload' => ['/index.php?action=upload'],
			'rename' => ['/index.php?action=rename&page=Home'], 'missing page' => ['/index.php/Nothing'],
			'api' => ['/api.php?task=checkupload&filename=a.gif'],
		];
	}

	#[DataProvider('urls')]
	public function testResponsesHaveSecurityHeaders(string $url): void
	{
		$response = $this->http->get($url);
		$this->assertSame('nosniff', $response->header('x-content-type-options'));
		$this->assertSame('DENY', $response->header('x-frame-options'));
		$this->assertSame('same-origin', $response->header('referrer-policy'));
		$this->assertNotEmpty($response->header('permissions-policy'));
		$this->assertSame('same-origin', $response->header('cross-origin-opener-policy'));
		$this->assertNull($response->header('strict-transport-security'), 'only for HTTPS');
		$csp = (string)$response->header('content-security-policy');
		foreach (["default-src 'self'", "object-src 'none'", "base-uri 'none'", "form-action 'self'", "frame-ancestors 'none'"] as $directive) {
			$this->assertStringContainsString($directive, $csp);
		}
		$this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $csp);
		$this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-eval'/", $csp);
	}

	public function testInlineScriptOfTheUploadPageIsAllowedByNonceOnly(): void
	{
		$first = $this->http->get('/index.php?action=upload');
		$this->assertSame(1, preg_match("/script-src [^;]*'nonce-([A-Za-z0-9+\/=]+)'/", (string)$first->header('content-security-policy'), $nonce));
		$this->assertStringContainsString('<script type="application/javascript" nonce="' . $nonce[1] . '">', $first->body);

		$second = $this->http->get('/index.php?action=upload');
		preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", (string)$second->header('content-security-policy'), $nonce2);
		$this->assertNotSame($nonce[1], $nonce2[1], 'the nonce is new for every request');
	}

	public function testInlineEventHandlersOfTheWikiAreAllowedByHash(): void
	{
		$csp = (string)$this->http->get('/index.php?action=edit&page=Home')->header('content-security-policy');
		$this->assertStringContainsString("'unsafe-hashes'", $csp);
		foreach (['toggleDrawer(); return false;', 'history.go(-1);'] as $handler) {
			$this->assertStringContainsString("'sha256-" . base64_encode(hash('sha256', $handler, true)) . "'", $csp);
		}
	}
}
