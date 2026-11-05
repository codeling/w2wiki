<?php
define('W2APP', true);
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * JSON interface for the scripts of the wiki's own pages (index.php is the other entry point):
 * answers whether an upload with a given name already exists, renders the text of the editor
 * for its preview, and tells what exists under the title of a new page.
 */

require_once "functions.php";
// The settings which are derived from the address of the script (SELF, BASE_URI, see config.sample.php) are those of
// index.php, in the same folder: links in the rendered text lead to the pages, which index.php shows
$_SERVER['SCRIPT_NAME'] = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api.php'), '/\\') . '/index.php';
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
// (the messages of the answers are shown to the user)
require_once 'locales/' . W2_LOCALE . '.php';
require_once "auth.php";

header('Content-Type: application/json');

/**
 * Sends the answer in JSON and ends the request; an error has a code ("error", for scripts) and a "message"
 * (translated plain text, for the user)
 */
function apiAnswer($status, $data)
{
	http_response_code($status);
	// (a text which is not valid UTF-8 must not make the answer empty)
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

if ( !isLoggedIn() )
{
	apiAnswer(403, array('error' => 'not logged in',
		'message' => translate('You are not logged in (any more). Please log in again, in another tab to keep the text of this one.')));
}

if ( hasMalformedRequestData($_GET, $_POST, $_SERVER) )
{
	apiAnswer(400, array('error' => 'invalid request', 'message' => translate('Invalid request')));
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
elseif ($task === 'preview')
{
	// The text of the editor (newText) as HTML, made like the HTML of a page which is viewed (so that links, embeds,
	// anchors and uploads are the same), for the preview in the editor. Nothing is stored. The task is part of the
	// query, as PHP drops the form of a request which is too large.
	if ( ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' )
	{
		header('Allow: POST');
		apiAnswer(405, array('error' => 'method not allowed', 'message' => translate('Invalid request')));
	}
	if ( (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST )
	{
		// (PHP discards the whole body of a request larger than post_max_size)
		apiAnswer(413, array('error' => 'too large',
			'message' => sprintf(translate('The text is too large for the preview: the server accepts requests of up to %s (post_max_size).'), ini_get('post_max_size'))));
	}
	if ( !isValidCSRFToken($_POST['csrf_token'] ?? null) )
	{
		apiAnswer(403, array('error' => 'invalid token',
			'message' => translate('The security token is missing or wrong, for example because the session has expired. Save the page or reload it to get a new one; the text is kept.')));
	}
	// (nothing in the session changes, and other requests of the editor, like saving, need not wait for the rendering)
	session_write_close();
	$text = $_POST['newText'] ?? '';
	// (like index.php shows a page)
	apiAnswer(200, array('html' => empty($text) ? '' : toHTML($text)));
}
elseif ($task === 'newpage')
{
	// What the editor of a new page shows about the title which was entered (as HTML, rendered like the page which is
	// opened with that title): that a page of the name exists already, similar pages, and the pages which link to the name
	// or mention it. Nothing is changed.
	$title = mb_substr((string)($_GET['title'] ?? ''), 0, 200);
	$hints = (new \W2\Lists())->newPageHints($title);
	$html = trim($hints['name']) === '' ? '' : (string)templates()->render('new-page-hints', array(
		'csrfField' => \W2\Html::raw(csrfField()), 'name' => $hints['name'], 'exists' => $hints['exists'],
		'similarPages' => $hints['similarPages'], 'pageContext' => $hints['pageContext']));
	session_write_close();
	apiAnswer(200, array('exists' => $hints['exists'] !== null, 'html' => $html));
}
