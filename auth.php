<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * W2
 *
 * Session handling, IP restriction and password checks, shared by all entry
 * points (index.php, api.php). Requires config.php to be loaded.
 */

if ( REQUIRE_PASSWORD )
{
	ini_set('session.gc_maxlifetime', W2_SESSION_LIFETIME);
}
ini_set('session.use_strict_mode', 1);
session_set_cookie_params(array(
	'lifetime' => REQUIRE_PASSWORD ? W2_SESSION_LIFETIME : 0,
	'path' => '/',
	'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
	'httponly' => true,
	'samesite' => 'Lax'
));
session_name(W2_SESSION_NAME);
session_start();

// token protecting state-changing requests against cross-site request forgery
if ( empty($_SESSION['csrf_token']) )
{
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

if ( count($allowedIPs) > 0 )
{
	$ip = $_SERVER['REMOTE_ADDR'];
	$accepted = false;

	foreach ( $allowedIPs as $allowed )
	{
		if ( ipMatches($ip, $allowed) )
		{
			$accepted = true;
			break;
		}
	}

	if ( !$accepted )
	{
		http_response_code(403);
		print "<html><body>Access from IP address ".htmlspecialchars($ip)." is not allowed</body></html>";
		exit;
	}
}

/**
 * Check the given password against W2_PASSWORD_HASH (created with PHP's
 * password_hash, or a legacy unsalted SHA-1 hash), or W2_PASSWORD
 */
function isCorrectPassword($password)
{
	if ( !is_string($password) || $password === '' )
	{
		return false;
	}
	if ( defined('W2_PASSWORD_HASH') && W2_PASSWORD_HASH !== '' )
	{
		if ( password_get_info(W2_PASSWORD_HASH)['algoName'] !== 'unknown' )
		{
			return password_verify($password, W2_PASSWORD_HASH);
		}
		return hash_equals(strtolower(W2_PASSWORD_HASH), sha1($password));
	}
	// refuse to work with the well-known default password
	return defined('W2_PASSWORD') && W2_PASSWORD !== '' && W2_PASSWORD !== 'secret' &&
		hash_equals(W2_PASSWORD, $password);
}

/**
 * Whether the current session may access the wiki
 */
function isLoggedIn()
{
	return !REQUIRE_PASSWORD || !empty($_SESSION['password']);
}
