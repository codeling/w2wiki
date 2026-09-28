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

if ( count($allowedIPs) > 0 )
{
	$ip = $_SERVER['REMOTE_ADDR'];
	$accepted = false;

	foreach ( $allowedIPs as $allowed )
	{
		if ( strncmp($allowed, $ip, strlen($allowed)) == 0 )
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
