<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Functions for access control: CSRF tokens, IP allowlist, passwords and the
 * throttling of failed logins. Most of them use the shared library in webauth/
 * (a git subtree of https://github.com/codeling/php-webauth, see README.md):
 * change it there, not here.
 * Nothing happens when this file is loaded, see auth.php for that.
 */

require_once __DIR__ . '/webauth/webauth.php';

// Inline event handlers used by the wiki's own pages (allowed in the Content-Security-Policy by their hash)
const HANDLER_TOGGLE_DRAWER = 'toggleDrawer(); return false;';
const HANDLER_GO_BACK = 'history.go(-1);';

function csrfToken()
{
	return $_SESSION['csrf_token'];
}

function isValidCSRFToken($token)
{
	return \WebAuth\Csrf::isValid($_SESSION['csrf_token'] ?? null, $token);
}

/**
 * Whether the browser says that the request comes from another site (Sec-Fetch-Site header);
 * requests without the header (older browsers, other clients) don't count as such
 */
function isCrossSiteRequest($server)
{
	$site = $server['HTTP_SEC_FETCH_SITE'] ?? '';
	return $site !== '' && $site !== 'same-origin' && $site !== 'none';
}

/**
 * Check the given password against W2_PASSWORD_HASH (created with PHP's
 * password_hash, or a deprecated unsalted SHA-1 hash), or W2_PASSWORD
 */
function isCorrectPassword($password, $passwordHash = null, $plainPassword = null)
{
	$passwordHash ??= W2_PASSWORD_HASH;
	$plainPassword ??= W2_PASSWORD;
	// refuse to work with the well-known default password
	return \WebAuth\Password::verify($password, $passwordHash, $plainPassword !== 'secret' ? $plainPassword : '');
}

/**
 * Whether the given W2_PASSWORD_HASH is a legacy unsalted SHA-1 hash (as
 * opposed to a hash created with PHP's password_hash). Deprecated: support for
 * these will be removed in a future version.
 */
function isLegacyPasswordHash($passwordHash = null)
{
	return \WebAuth\Password::isLegacyHash($passwordHash ?? W2_PASSWORD_HASH);
}

/**
 * Parameters of the session cookie for a request ($_SERVER)
 */
function sessionCookieParams(array $server)
{
	return \WebAuth\Session::cookieParams($server, REQUIRE_PASSWORD ? W2_SESSION_LIFETIME : 0);
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
 * Limits for failed logins from the settings in config.php (a value of 0 for
 * maxFailures turns the throttling off), see WebAuth\LoginThrottle::start()
 */
function loginThrottleLimits()
{
	return array(
		'maxFailures' => LOGIN_MAX_FAILURES,
		'lockoutSeconds' => LOGIN_LOCKOUT_SECONDS,
		'lockoutMaxSeconds' => LOGIN_LOCKOUT_MAX_SECONDS,
		'globalMaxFailures' => LOGIN_MAX_FAILURES_PER_HOUR,
		'globalWindowSeconds' => 3600
	);
}

/**
 * The folder with the records of failed logins: LOGIN_THROTTLE_FOLDER, or a
 * folder in the temporary folder of the system (not served by the web server,
 * and outside of the pages folder, which may be a git repository)
 */
function loginThrottleFolder()
{
	if ( LOGIN_THROTTLE_FOLDER !== '' )
	{
		return LOGIN_THROTTLE_FOLDER;
	}
	return \WebAuth\LoginThrottle::defaultFolder('w2-login', __DIR__);
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
	if ( \WebAuth\Https::isRequest($server) )
	{
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}
	return $headers;
}
