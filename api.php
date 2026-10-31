<?php
define('W2APP', true);
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * JSON interface for the scripts of the wiki's own pages (index.php is the other entry point):
 * answers whether an upload with a given name already exists.
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
require_once "auth.php";

header('Content-Type: application/json');

if ( !isLoggedIn() )
{
	http_response_code(403);
	echo json_encode(array('error' => 'not logged in'));
	exit;
}

if ( hasMalformedRequestData($_GET, $_POST, $_SERVER) )
{
	http_response_code(400);
	echo json_encode(array('error' => 'invalid request'));
	exit;
}

$task = $_REQUEST['task'] ?? '';

if ($task == 'checkupload')
{
	// only consider file names directly within the uploads folder
	// (the name the file is going to be stored under, if the user chose a different one)
	$filename = uploadTargetName((string)($_REQUEST['filename'] ?? ''), (string)($_REQUEST['newname'] ?? ''));
	// hidden files (like .htaccess) are not uploads, and "." / ".." are folders
	$isUpload = $filename !== '' && !str_starts_with($filename, '.');
	echo json_encode($isUpload && file_exists(uploadPath($filename)));
}
