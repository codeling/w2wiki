<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * W2
 *
 * Session handling, IP restriction and password checks, shared by all entry
 * points (index.php, api.php). Requires config.php to be loaded.
 */

require_once "auth_functions.php";

if ( REQUIRE_PASSWORD )
{
	ini_set('session.gc_maxlifetime', W2_SESSION_LIFETIME);
}
ini_set('session.use_strict_mode', 1);
session_set_cookie_params(sessionCookieParams($_SERVER));
session_name(W2_SESSION_NAME);
session_start();

// token protecting state-changing requests against cross-site request forgery
if ( empty($_SESSION['csrf_token']) )
{
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
		// (api.php doesn't load the locale)
		$text = function_exists('__') ? __('Access from IP address %s is not allowed') : 'Access from IP address %s is not allowed';
		print "<html><body>" . sprintf($text, htmlspecialchars($ip)) . "</body></html>";
		exit;
	}
}
