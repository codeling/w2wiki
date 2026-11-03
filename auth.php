<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Authentication by the web server, session handling, IP restriction and password checks, shared by all entry
 * points (index.php, api.php, file.php). Requires the settings (config.php, defaults.php) to be loaded.
 */

require_once "auth_functions.php";

foreach ( securityHeaders($_SERVER) as $name => $value )
{
	header("$name: $value");
}

// Authentication by the web server (REQUIRE_WEB_SERVER_AUTH): stop unless the request came in over
// HTTPS and the web server authenticated the user (REMOTE_USER), before a session is created
if ( REQUIRE_WEB_SERVER_AUTH )
{
	// (__() escapes for HTML, the messages are plain text)
	$plain = fn($text) => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, W2_CHARSET);
	\WebAuth\ServerAuth::guard(array(
		'require_https' => WEB_SERVER_AUTH_REQUIRE_HTTPS,
		// (sent with the security headers above)
		'hsts' => false,
		'log_prefix' => 'W2',
		'messages' => array(
			'https' => $plain(__('Access denied: this wiki is only available over HTTPS.')),
			'auth' => $plain(__('Access denied: the web server did not authenticate you.'))
		)
	));
}

// (without password too: the session holds the token of the editor's form, and editing can take hours)
ini_set('session.gc_maxlifetime', W2_SESSION_LIFETIME);
ini_set('session.use_strict_mode', 1);
session_set_cookie_params(sessionCookieParams($_SERVER));
session_name(W2_SESSION_NAME);
if ( !empty($w2ReadOnlySession) )
{
	// (file.php only needs to know whether the user is logged in: the session is not locked while the file is sent,
	// and caching is left to file.php, without the headers against it which PHP adds for sessions)
	session_start(array('read_and_close' => true, 'cache_limiter' => ''));
}
else
{
	session_start();

	// (once per session, not for every request)
	if ( !empty($w2ConfigMissing) && empty($_SESSION['config_missing_logged']) )
	{
		w2Log('config.php not found: using the default settings; copy config.sample.php to config.php to change them');
		$_SESSION['config_missing_logged'] = true;
	}

	// token protecting state-changing requests against cross-site request forgery
	if ( empty($_SESSION['csrf_token']) )
	{
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
}

if ( count($allowedIPs) > 0 )
{
	$ip = $_SERVER['REMOTE_ADDR'];

	if ( !\WebAuth\Ip::isInList($ip, $allowedIPs) )
	{
		http_response_code(403);
		// (api.php and file.php don't load the locale)
		$text = function_exists('__') ? __('Access from IP address %s is not allowed') : 'Access from IP address %s is not allowed';
		print "<html><body>" . sprintf($text, htmlspecialchars($ip)) . "</body></html>";
		exit;
	}
}
