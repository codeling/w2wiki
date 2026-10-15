<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * W2
 *
 * Functions for access control: CSRF tokens, IP allowlist and passwords.
 * Nothing happens when this file is loaded, see auth.php for that.
 */

// Inline event handlers used by the wiki's own pages (allowed in the Content-Security-Policy by their hash)
const HANDLER_TOGGLE_DRAWER = 'toggleDrawer(); return false;';
const HANDLER_GO_BACK = 'history.go(-1);';

function csrfToken()
{
	return $_SESSION['csrf_token'];
}

function isValidCSRFToken($token)
{
	return is_string($token) && hash_equals(csrfToken(), $token);
}

/**
 * Whether the given IP address matches an allowlist entry, which is either
 * a single address (e.g. "192.168.1.10"), a CIDR range (e.g. "192.168.1.0/24"
 * or "fd00::/8"), or an address prefix ending in "." or ":" (e.g. "10.0.")
 */
function ipMatches($ip, $allowed)
{
	$allowed = trim($allowed);
	if ( str_ends_with($allowed, '.') || str_ends_with($allowed, ':') )
	{
		return str_starts_with($ip, $allowed);
	}
	$parts = explode('/', $allowed, 2);
	$ipBin = @inet_pton($ip);
	$netBin = @inet_pton($parts[0]);
	if ( $ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin) )
	{
		return false;
	}
	$maxBits = strlen($ipBin) * 8;
	$bits = (count($parts) > 1) ? $parts[1] : $maxBits;
	if ( !ctype_digit((string)$bits) || $bits > $maxBits )
	{
		return false;
	}
	$bits = (int)$bits;
	$bytes = intdiv($bits, 8);
	if ( substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes) )
	{
		return false;
	}
	$remaining = $bits % 8;
	if ( $remaining === 0 )
	{
		return true;
	}
	$mask = (0xFF << (8 - $remaining)) & 0xFF;
	return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/**
 * Check the given password against W2_PASSWORD_HASH (created with PHP's
 * password_hash, or a legacy unsalted SHA-1 hash), or W2_PASSWORD
 */
function isCorrectPassword($password, $passwordHash = null, $plainPassword = null)
{
	$passwordHash ??= defined('W2_PASSWORD_HASH') ? W2_PASSWORD_HASH : '';
	$plainPassword ??= defined('W2_PASSWORD') ? W2_PASSWORD : '';
	if ( !is_string($password) || $password === '' )
	{
		return false;
	}
	if ( $passwordHash !== '' )
	{
		if ( password_get_info($passwordHash)['algoName'] !== 'unknown' )
		{
			return password_verify($password, $passwordHash);
		}
		return hash_equals(strtolower($passwordHash), sha1($password));
	}
	// refuse to work with the well-known default password
	return $plainPassword !== '' && $plainPassword !== 'secret' && hash_equals($plainPassword, $password);
}

/**
 * Whether the request came in over HTTPS, as reported by the web server.
 * Headers such as X-Forwarded-Proto are deliberately not trusted, because any
 * client can send them: behind a TLS-terminating reverse proxy the wiki sees
 * plain HTTP.
 */
function isHttpsRequest(array $server)
{
	return !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
}

/**
 * Parameters of the session cookie for a request ($_SERVER)
 */
function sessionCookieParams(array $server)
{
	return array(
		'lifetime' => REQUIRE_PASSWORD ? W2_SESSION_LIFETIME : 0,
		'path' => '/',
		'secure' => isHttpsRequest($server),
		'httponly' => true,
		'samesite' => 'Lax'
	);
}

/**
 * Whether the current session may access the wiki
 */
function isLoggedIn()
{
	return !REQUIRE_PASSWORD || !empty($_SESSION['password']);
}

function csrfField()
{
	return "<input type=\"hidden\" name=\"csrf_token\" value=\"" . h(csrfToken()) . "\" />";
}

/**
 * Nonce which allows the inline script of a page in the Content-Security-Policy (new for every request)
 */
function cspNonce()
{
	static $nonce = null;
	return $nonce ??= base64_encode(random_bytes(16));
}

/**
 * The Content-Security-Policy of the pages of the wiki: only scripts and styles from the wiki itself
 * (plus the nonce'd inline script, and the wiki's own inline event handlers by hash), no plugins, no
 * framing, forms only to the wiki. Images may come from anywhere, as pages can include external images.
 */
function contentSecurityPolicy()
{
	$handlerHashes = array_map(fn($handler) => "'sha256-" . base64_encode(hash('sha256', $handler, true)) . "'",
		array(HANDLER_TOGGLE_DRAWER, HANDLER_GO_BACK));
	return implode('; ', array(
		"default-src 'self'",
		"script-src 'self' 'nonce-" . cspNonce() . "' 'unsafe-hashes' " . implode(' ', $handlerHashes),
		"style-src 'self'",
		"img-src 'self' data: http: https:",
		"object-src 'none'",
		"base-uri 'none'",
		"form-action 'self'",
		"frame-ancestors 'none'"
	));
}

/**
 * Security headers sent with every response of the wiki's scripts ($server is $_SERVER)
 */
function securityHeaders(array $server)
{
	$headers = array(
		'Content-Security-Policy' => contentSecurityPolicy(),
		'X-Content-Type-Options' => 'nosniff',
		'X-Frame-Options' => 'DENY',
		'Referrer-Policy' => 'same-origin',
		'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
		'Cross-Origin-Opener-Policy' => 'same-origin'
	);
	if ( isHttpsRequest($server) )
	{
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}
	return $headers;
}
