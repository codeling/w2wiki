<?php
define('W2APP', true);

require_once "config.php";
require_once "auth.php";

header('Content-Type: application/json');

if ( !isLoggedIn() )
{
	http_response_code(403);
	echo json_encode(array('error' => 'not logged in'));
	exit;
}

$task = $_REQUEST['task'] ?? '';

if ($task == 'checkupload')
{
	// only consider file names directly within the uploads folder
	$filename = basename(str_replace('\\', '/', (string)($_REQUEST['filename'] ?? '')));
	echo json_encode($filename !== '' && file_exists(PAGES_PATH . "/". UPLOAD_FOLDER . "/" . $filename));
}
