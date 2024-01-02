<?php
define('W2APP', true);

require_once "config.php";


$task = $_REQUEST['task'];

if ($task == 'checkupload')
{
	$filename = $_REQUEST['filename'];
	echo (file_exists(PAGES_PATH . "/". UPLOAD_FOLDER . "/" . $filename) ? "true" : "false");
}


