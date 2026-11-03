<?php
define('W2APP', true);
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Serves the uploaded files: file.php?name=<name>[&v=<version>] (index.php and api.php are the other entry points).
 * The uploads folder (UPLOAD_PATH) is not served by the web server, so the files have the same protection as the
 * pages: authentication by the web server, IP allowlist and password (see auth.php). What is sent, and with which
 * headers, is decided by W2\UploadResponse.
 */

require_once "functions.php";
// User configurable options (see index.php):
if ( is_file(__DIR__ . '/config.php') )
{
	require_once __DIR__ . '/config.php';
}
else
{
	$w2ConfigMissing = true;
}
require_once __DIR__ . '/defaults.php';
// (the images of a page are requested at the same time: they only read the session, so they don't wait for each other)
$w2ReadOnlySession = true;
require_once "auth.php";

if ( !isLoggedIn() )
{
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	header('Cache-Control: no-store');
	echo "Not logged in\n";
	exit;
}

if ( hasMalformedRequestData($_GET, $_POST, $_SERVER) )
{
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Invalid request\n";
	exit;
}

$response = (new \W2\UploadResponse(uploadPath()))->respond(
	$_GET['name'] ?? '', (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), isset($_GET['v']),
	$_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null
);
http_response_code($response['status']);
foreach ( $response['headers'] as $name => $value )
{
	if ( $value === null )
	{
		header_remove($name);
	}
	else
	{
		header("$name: $value");
	}
}
if ( $response['file'] !== null )
{
	readfile($response['file']);
}
else
{
	echo $response['body'];
}
