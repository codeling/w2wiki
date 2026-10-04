<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * W2
 *
 * Functions for access control: CSRF tokens, IP allowlist, passwords and the
 * throttling of failed logins.
 * Nothing happens when this file is loaded, see auth.php for that.
 */

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
 * The address of the client for throttling logins: the address the web server
 * sees, or, if that is one of the trusted proxies, the last address in the
 * X-Forwarded-For header which does not belong to a trusted proxy. The header
 * is ignored for requests from other addresses, because any client can send it.
 * IPv6 addresses are shortened to their /64 network, since one client usually
 * controls a whole /64 and could otherwise use a new address for every attempt.
 *
 * @param string[] $trustedProxies entries like for the IP allowlist, see ipMatches()
 */
function clientAddress(array $server, array $trustedProxies = array())
{
	$ip = (string)($server['REMOTE_ADDR'] ?? '');
	$isTrusted = function ($address) use ($trustedProxies) {
		foreach ( $trustedProxies as $proxy )
		{
			if ( ipMatches($address, $proxy) )
			{
				return true;
			}
		}
		return false;
	};
	if ( $trustedProxies && $isTrusted($ip) && !empty($server['HTTP_X_FORWARDED_FOR']) )
	{
		foreach ( array_reverse(explode(',', $server['HTTP_X_FORWARDED_FOR'])) as $forwarded )
		{
			$forwarded = trim($forwarded);
			if ( filter_var($forwarded, FILTER_VALIDATE_IP) === false )
			{
				break;   // not an address: don't trust the rest of the chain
			}
			$ip = $forwarded;
			if ( !$isTrusted($forwarded) )
			{
				break;
			}
		}
	}
	$binary = @inet_pton($ip);
	if ( $binary !== false && strlen($binary) === 16 )
	{
		$ip = inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
	}
	return $ip;
}

/**
 * Limits for failed logins from the settings in config.php (a value of 0 for
 * maxFailures turns the throttling off)
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
	return rtrim(sys_get_temp_dir(), '/\\') . '/w2-login-' . substr(hash('sha256', __DIR__), 0, 16);
}

/**
 * Read, change and write back one record of the throttle folder while holding
 * a lock on its file, so that parallel requests can't both see the old state.
 * $update gets the record (an array, empty if there is none) and returns the
 * new record (empty to delete it).
 *
 * @return bool false if the folder or file can't be used
 */
function loginThrottleUpdate($folder, $name, callable $update)
{
	if ( !is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder) )
	{
		return false;
	}
	$file = fopen($folder . '/' . hash('sha256', 'w2-login|' . $name) . '.json', 'c+');
	if ( $file === false )
	{
		return false;
	}
	if ( !flock($file, LOCK_EX) )
	{
		fclose($file);
		return false;
	}
	$record = json_decode((string)stream_get_contents($file), true);
	$record = $update(is_array($record) ? $record : array());
	ftruncate($file, 0);
	rewind($file);
	if ( $record )
	{
		fwrite($file, json_encode($record));
	}
	fflush($file);
	flock($file, LOCK_UN);
	fclose($file);
	return true;
}

/**
 * Delete the records which are not needed any more (their lockout is over for
 * a while, see loginThrottleStart())
 */
function loginThrottlePrune($folder, $now, $maxAge)
{
	foreach ( glob($folder . '/*.json') ?: array() as $file )
	{
		$modified = @filemtime($file);
		if ( $modified !== false && $now - $modified > $maxAge )
		{
			@unlink($file);
		}
	}
}

/**
 * Call before checking a password. Every attempt is counted right away (a
 * correct password takes it back with loginThrottleSucceeded()), so parallel
 * requests can't make more guesses than allowed. Returns 0 if the password may
 * be checked, otherwise the number of seconds until the next attempt is
 * possible, or -1 if the records can't be used. Attempts during a lockout are
 * refused without checking the password and don't extend the lockout.
 *
 * Per client ($clientKey, see clientAddress()): after the first
 * "maxFailures" failures, the client is locked out for "lockoutSeconds", which
 * double with every further failure up to "lockoutMaxSeconds". The count is
 * forgotten after a quiet time as long as the longest lockout. Across all
 * clients, "globalMaxFailures" failures are allowed per "globalWindowSeconds",
 * which limits what an attacker with many addresses can do (and lets nobody
 * log in for the rest of the window, since the password can't be checked).
 */
function loginThrottleStart($folder, $clientKey, $now, array $limits)
{
	if ( $limits['maxFailures'] <= 0 )
	{
		return 0;
	}
	$wait = 0;
	$stored = loginThrottleUpdate($folder, 'client|' . $clientKey, function ($record) use (&$wait, $now, $limits) {
		$count = (int)($record['count'] ?? 0);
		$blockedUntil = (int)($record['blockedUntil'] ?? 0);
		$last = (int)($record['last'] ?? 0);
		if ( $count > 0 && $now > max($blockedUntil, $last) + $limits['lockoutMaxSeconds'] )
		{
			$count = 0;
			$blockedUntil = 0;
		}
		if ( $blockedUntil > $now )
		{
			$wait = $blockedUntil - $now;
			return array('count' => $count, 'blockedUntil' => $blockedUntil, 'last' => $last);
		}
		$count++;
		if ( $count >= $limits['maxFailures'] )
		{
			$step = min($count - $limits['maxFailures'], 30);
			$blockedUntil = $now + min($limits['lockoutSeconds'] * (2 ** $step), $limits['lockoutMaxSeconds']);
		}
		return array('count' => $count, 'blockedUntil' => $blockedUntil, 'last' => $now);
	});
	if ( $stored && $wait === 0 && $limits['globalMaxFailures'] > 0 )
	{
		$stored = loginThrottleUpdate($folder, 'global', function ($record) use (&$wait, $now, $limits) {
			$start = (int)($record['start'] ?? 0);
			$count = (int)($record['count'] ?? 0);
			if ( $start === 0 || $now >= $start + $limits['globalWindowSeconds'] )
			{
				$start = $now;
				$count = 0;
			}
			if ( $count >= $limits['globalMaxFailures'] )
			{
				$wait = $start + $limits['globalWindowSeconds'] - $now;
			}
			else
			{
				$count++;
			}
			return array('start' => $start, 'count' => $count);
		});
		if ( $stored && $wait > 0 )
		{
			// this attempt doesn't happen: give it back to the client
			loginThrottleUpdate($folder, 'client|' . $clientKey, function ($record) {
				$record['count'] = max(0, (int)($record['count'] ?? 1) - 1);
				$record['blockedUntil'] = 0;
				return $record['count'] > 0 ? $record : array();
			});
		}
	}
	if ( !$stored )
	{
		return -1;
	}
	if ( mt_rand(1, 100) === 1 )
	{
		loginThrottlePrune($folder, $now, 2 * $limits['lockoutMaxSeconds'] + $limits['globalWindowSeconds']);
	}
	return $wait;
}

/**
 * Call after a correct password: forget the failures of the client (and take
 * the attempt back from the count over all clients)
 */
function loginThrottleSucceeded($folder, $clientKey, array $limits)
{
	if ( $limits['maxFailures'] <= 0 )
	{
		return;
	}
	loginThrottleUpdate($folder, 'client|' . $clientKey, fn($record) => array());
	if ( $limits['globalMaxFailures'] > 0 )
	{
		loginThrottleUpdate($folder, 'global', function ($record) {
			$record['count'] = max(0, (int)($record['count'] ?? 1) - 1);
			return $record;
		});
	}
}
