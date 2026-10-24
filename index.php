<?php
define('W2APP', true);
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Copyright (C) 2007-2011 Steven Frank <http://stevenf.com/>
 *
 * Code may be re-used as long as the above copyright notice is retained.
 * See README.md for full details.
 *
 * Written with Coda: <http://panic.com/coda/>
 *
 */


// Helper functions (and optional libraries installed via Composer):
require_once "functions.php";

// User configurable options (config.php is optional; defaults.php provides the settings it doesn't define):
if ( is_file(__DIR__ . '/config.php') )
{
	require_once __DIR__ . '/config.php';
}
else
{
	$w2ConfigMissing = true;
}
require_once __DIR__ . '/defaults.php';

// Load configured localization:
require_once 'locales/' . W2_LOCALE . '.php';

const ImageExtensions = array("bmp", "gif", "heic", "heif","jpg", "jpeg", "png", "svg", "webp");

// Session handling, IP and password checks:
require_once "auth.php";

if ( !isLoggedIn() )
{
	$loginFailed = false;
	$loginMessage = '';
	if ( isset($_POST['p']) )
	{
		$limits = loginThrottleLimits();
		$throttleFolder = loginThrottleFolder();
		$clientKey = \WebAuth\ClientAddress::get($_SERVER, $trustedProxies);
		if ( !isValidCSRFToken($_POST['csrf_token'] ?? null) )
		{
			// (the password isn't checked, so this is no way to guess it)
			$loginMessage = translate('Your session has expired. Please try again.');
		}
		elseif ( ($wait = \WebAuth\LoginThrottle::start($throttleFolder, $clientKey, time(), $limits)) !== 0 )
		{
			if ( $wait < 0 )
			{
				w2Log("cannot use the login throttle folder " . $throttleFolder . "; make it writable for the web server, set LOGIN_THROTTLE_FOLDER, or set LOGIN_MAX_FAILURES to 0 to turn the throttling off");
				http_response_code(503);
				$loginMessage = translate('Logging in is not possible at the moment because of a server configuration problem. See the web server\'s error log for details.');
			}
			else
			{
				w2Log("login from " . $clientKey . " refused for " . $wait . " seconds (too many failed attempts)");
				http_response_code(429);
				header('Retry-After: ' . $wait);
				$loginMessage = sprintf(translate('Too many failed login attempts. Please try again in %s seconds.'), $wait);
			}
		}
		elseif ( isCorrectPassword($_POST['p']) )
		{
			\WebAuth\LoginThrottle::succeeded($throttleFolder, $clientKey, $limits);
			// prevent session fixation
			session_regenerate_id(true);
			$_SESSION['password'] = true;
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
			if ( isLegacyPasswordHash() )
			{
				// (the user has just proven to know the password, so we can offer its new hash)
				w2Log("logged in with a deprecated SHA-1 password hash; replace W2_PASSWORD_HASH in config.php by a password_hash() hash");
				$_SESSION['note'] = (new \W2\Note())
					->text(translate('W2_PASSWORD_HASH in config.php is an unsalted SHA-1 hash. This is deprecated and will stop working in a future version. Please replace it by this hash of your password (in single quotes):') . ' ')
					->code(password_hash($_POST['p'], PASSWORD_DEFAULT))
					->toArray();
			}
		}
		else
		{
			$loginFailed = true;
			w2Log("failed login attempt from " . $clientKey);
		}
	}
	if ( empty($_SESSION['password']) )
	{
		$notes = array();
		if ( $loginFailed )
		{
			$notes[] = translate('Wrong password');
		}
		if ( $loginMessage !== '' )
		{
			$notes[] = $loginMessage;
		}
		if ( W2_PASSWORD_HASH === '' && W2_PASSWORD === 'secret' )
		{
			$notes[] = translate('Login is disabled while the default password is configured; please set W2_PASSWORD_HASH (or W2_PASSWORD) in config.php.');
		}
		print templates()->render('layout', array(
			'title' => translate('Log In'), 'pageTitle' => \W2\Html::raw(PAGE_TITLE), 'bodyClass' => 'login', 'editor' => false, 'savedDraft' => '',
			'content' => templates()->render('login', array(
				'heading' => translate('Log In'), 'notes' => $notes, 'passwordLabel' => translate('Password'),
				'buttonLabel' => translate('Log In'), 'csrfField' => \W2\Html::raw(csrfField())
			))
		));
		exit;
	}
}

// Support functions

// Reads a "page to go back to" value from $_REQUEST[$paramName], restricted
// to the name of a page that actually exists. Denies the request entirely
// if the given value isn't a valid, existing page name.
function requireValidPreviousPage($paramName)
{
	$rawValue = isset($_REQUEST[$paramName]) ? $_REQUEST[$paramName] : DEFAULT_PAGE;
	// (request values are already decoded)
	$page = sanitizeFilename($rawValue);
	if ( !isExistingPage($page) )
	{
		header("HTTP/1.1 400 Bad Request");
		die(__('Invalid page name'));
	}
	return $page;
}

function redirectWithNote($page, \W2\Note $note)
{
	$_SESSION['note'] = $note->toArray();
	header("HTTP/1.1 303 See Other");
	header("Location: " . pageURL($page) );
	exit;
}

/**
 * Notes a failed git command (details like the command and its output may reveal server internals, so they are
 * only logged) and returns whether it succeeded.
 *
 * @param array $knownErrors	messages (plain text) for exit codes which have a meaning of their own, by code
 */
function checkedGit(\W2\Note $note, \W2\GitResult $result, array $knownErrors = array())
{
	if (!$result->succeeded())
	{
		w2Log("error executing command {$result->command} (return value: {$result->code}): ".$result->output);
		$note->br();
		if (isset($knownErrors[$result->code]))
		{
			$note->text($knownErrors[$result->code]);
		}
		else
		{
			$note->format(translate("Error executing git command (return value: %s); see the web server's error log for details."), array($result->code));
		}
	}
	return $result->succeeded();
}

// Seconds after which git is stopped when it talks to the remote repository (which may not answer)
const GIT_REMOTE_TIMEOUT = 20;

function gitChangeHandler($commitmsg, \W2\Note $note)
{
	if (!GIT_COMMIT_ENABLED)
	{
		return;
	}
	// (not while a pull is running; the lock was usually taken before the change was made)
	$lock = gitLock(true);
	$git = new \W2\Git(PAGES_PATH);
	if (checkedGit($note, $git->run(array('add', '-A'))))
	{
		// (nothing to commit, e.g., if a page was saved without changes, is no error, and nothing to push;
		// "git diff --cached --quiet" exits with 1 if there is something to commit)
		$changes = $git->run(array('diff', '--cached', '--quiet'));
		if ($changes->code !== 0 && checkedGit($note, $changes->code === 1 ? $git->run(array('commit', '-m', $commitmsg)) : $changes))
		{
			if (GIT_PUSH_ENABLED)
			{
				checkedGit($note, $git->run(array('push'), GIT_REMOTE_TIMEOUT));
			}
		}
	}
	gitUnlock($lock);
}

// Return value of the pull command if the changes could not be merged
const GIT_PULL_CONFLICT = 3;

// Merges the changes from the remote repository into the pages (and pushes the result
// if pushing is enabled), if GIT_PULL_ENABLED and the interval for the action has passed.
// Merge conflicts are undone (so the pages never contain conflict markers) and reported.
function gitPull($action, \W2\Note $note)
{
	if (!GIT_PULL_ENABLED || !GIT_COMMIT_ENABLED)
	{
		return;
	}
	$lock = gitPullStart($action, time());
	if ($lock === null)
	{
		return;
	}
	// (no password prompt, and no waiting forever for a remote which doesn't answer)
	$git = new \W2\Git(PAGES_PATH);
	$pull = $git->run(array('pull', '--no-rebase', '--no-edit'), GIT_REMOTE_TIMEOUT);
	if (!$pull->succeeded() && $git->run(array('rev-parse', '-q', '--verify', 'MERGE_HEAD'))->succeeded())
	{
		$git->run(array('merge', '--abort'));
		$pull = new \W2\GitResult(GIT_PULL_CONFLICT, $pull->output, false);
	}
	$conflict = translate('There were merge conflicts while pulling changes from the remote repository. The pull was cancelled and the wiki keeps its own version. Please resolve the conflicts in the pages repository (ask the wiki administrator).');
	$pullNote = new \W2\Note();
	if (checkedGit($pullNote, $pull, array(GIT_PULL_CONFLICT => $conflict)) && GIT_PUSH_ENABLED)
	{
		// (also publishes the merge, and commits which could not be pushed earlier)
		checkedGit($pullNote, $git->run(array('push'), GIT_REMOTE_TIMEOUT));
	}
	gitUnlock($lock);
	$note->append($pullNote);
}

function destroy_session()
{
	if ( isset($_COOKIE[session_name()]) )
	{
		setcookie(session_name(), '', array('expires' => time() - 42000, 'path' => '/',
			'httponly' => true, 'samesite' => 'Lax'));
	}
	session_destroy();
	unset($_SESSION["password"]);
	unset($_SESSION);
}

// Main code

if (hasMalformedRequestData($_GET, $_POST, $_SERVER, $_FILES))
{
	http_response_code(400);
	die(__('Invalid request'));
}
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES)
{
	// PHP discards the whole body of a request larger than post_max_size (no token, no file)
	http_response_code(413);
	die(sprintf(__('The upload is too large: the server accepts requests of up to %s (post_max_size).'), h(ini_get('post_max_size'))).' '.
		__('Nothing was uploaded or saved. Please go back and try again with a smaller file.'));
}
$saveRefused = false;
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted'), true) &&
	($_SERVER['REQUEST_METHOD'] !== 'POST' || !isValidCSRFToken($_POST['csrf_token'] ?? null)))
{
	http_response_code(403);
	if ($action !== 'save' || $_SERVER['REQUEST_METHOD'] !== 'POST' || isCrossSiteRequest($_SERVER))
	{
		die(__('Invalid request: missing or wrong security token. Please go back, reload the page and try again.'));
	}
	// usually the session expired while editing: nothing is saved, but the editor is shown
	// again with the text (and the new token), so that the text isn't lost
	$saveRefused = true;
}
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted'), true) && !$saveRefused)
{
	// (before anything is changed: a pull must not run between a change and its commit)
	gitLockForChange();
}
if ($action === 'logout' && !isValidCSRFToken($_GET['csrf_token'] ?? null))
{
	$action = 'view';
}
$newPage = "";
$text = "";
$html = "";
if ($action === 'view' || $action === 'edit' || $action === 'save' || $action === 'rename' || $action === 'delete')
{
	// Look for page name following the script name in the URL, like this:
	// http://stevenf.com/w2demo/index.php/Markdown%20Syntax
	//
	// Otherwise, get page name from 'page' request variable.

	$path_info = isset($_SERVER["PATH_INFO"]) ? $_SERVER["PATH_INFO"]: '';
	$request_page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '';
	$page = preg_match('@^/@', $path_info) ? substr($path_info, 1) : $request_page;
	// both PATH_INFO and request variables are already URL-decoded
	$page = sanitizeFilename($page);
	if ( $page == "" )
	{
		$page = DEFAULT_PAGE;
	}
	$filename = fileNameForPage($page);
}
if ($action === 'view' || $action === 'edit' || $action === 'new')
{
	// (before the page is read, so that the editor shows what the others have changed)
	$pullNote = new \W2\Note();
	gitPull($action, $pullNote);
	if (!$pullNote->isEmpty())
	{
		// (keeps the note of the action which has just redirected here)
		$_SESSION['note'] = \W2\Note::fromArray($_SESSION['note'] ?? null)->append($pullNote)->toArray();
	}
}
if ($action === 'view' || $action === 'edit')
{
	if ( isExistingPage($page) )
	{
		$text = pageStore()->read($page) ?? '';
	}
	else
	{
		// links used to encode spaces in page names as '+'; keep them working
		$plusPage = str_replace('+', ' ', $page);
		if ( $action === 'view' && $plusPage !== $page && isExistingPage($plusPage) )
		{
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: " . pageURL($plusPage));
			exit;
		}
		$pages = getAllPageNames();
		foreach ($pages as $p)
		{
			$basePage = basename($p);
			if ($basePage == $page) {
				redirectWithNote($p, (new \W2\Note())->format(translate("Page %s does not exist, redirected instead to first page in a subfolder with matching filename (%s)"), array($page, $p)));
			}
		}
		$newPage = $page;
		$action = 'new';
	}
}
$oldgitmsg = "";
$triedSave = false;
$postedDraftKey = "";
if ( $action == 'save' )
{
	$newText = $_POST['newText'] ?? '';
	$isNew = $_POST['isNew'] ?? '';
	$postedDraftKey = $_POST['draftKey'] ?? '';
	if (!str_starts_with($postedDraftKey, 'w2wiki-draft:'))
	{
		$postedDraftKey = '';
	}
	if ($isNew)
	{
		$page = str_replace(array('|','#'), '', $page);
	}
	if ($saveRefused)
	{
		$note = (new \W2\Note())->text(translate('The text was not saved, because the security token was missing or wrong (for example because the session has expired). Please check the text and save again.'));
	}
	else
	{
		$result = (new \W2\Changes())->save($page, (bool)$isNew, $newText, $_POST['gitmsg'] ?? '');
		$note = $result->note;
		if ($result->success)
		{
			// (the browser keeps the draft until it sees this, in case the save fails on the way)
			$_SESSION['savedDraft'] = $postedDraftKey;
			redirectWithNote($result->page, $note);
		}
	}
	// saving failed: show the editor again (with the entered title, text and message)
	// instead of redirecting, so that nothing typed is lost
	$action = $isNew ? 'new' : 'edit';
	$text = $newText;
	$newPage = $page;
	if (GIT_COMMIT_ENABLED)
	{
		$oldgitmsg = $_POST['gitmsg'] ?? '';
	}
	$triedSave = true;
	$_SESSION['note'] = $note->toArray();
}

if ( isEditorAction($action) )
{
	$similarPages = null;
	if ($action === 'new' && $newPage != "" && !$triedSave)
	{
		// check if similar page exists...
		$similarPages = array();
		foreach(getAllPageNames() as $pageName)
		{
			if (levenshtein(strtoupper($newPage), strtoupper($pageName)) < sqrt(min(strlen($newPage), strlen($pageName))) )
			{
				$similarPages[] = array('href' => pageURL($pageName), 'name' => $pageName);
			}
		}
	}
	// (after a failed save, the draft is the one of the editor which sent the text, e.g. of a new page without title)
	$draftKey = ($triedSave && $postedDraftKey !== '') ? $postedDraftKey :
		"w2wiki-draft:" . SELF . (($action === 'edit') ? ":edit:$page" : ":new:$newPage");
	$html .= templates()->render('editor', array(
		'draftKey' => $draftKey, 'unsaved' => $triedSave,
		'isNew' => $action !== 'edit', 'csrfField' => \W2\Html::raw(csrfField()),
		'page' => $action === 'edit' ? $page : '', 'newPage' => $newPage, 'similarPages' => $similarPages,
		'text' => $text, 'rows' => EDIT_ROWS, 'gitEnabled' => GIT_COMMIT_ENABLED, 'gitMessage' => $oldgitmsg
	));
}
else if ( $action === 'logout' )
{
	destroy_session();
	header("Location: " . SELF);
	exit;
}
else if ( $action === 'upload' )
{
	$sortBy = isset($_REQUEST['sortBy']) ? $_REQUEST['sortBy'] : 'name';
	if (!in_array($sortBy, array('name', 'recent', 'size')))
	{
		$sortBy = 'name';
	}
	$prevpage = requireValidPreviousPage('page');
	if ( DISABLE_UPLOADS )
	{
		$html .= '<p>' . __('Image uploading has been disabled on this installation.') . '</p>';
	}
	else
	{
		// (texts in the script are JavaScript string literals, not HTML; BASE_URI is the inside of one)
		$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$html .= templates()->render('upload-form', array(
			'csrfField' => \W2\Html::raw(csrfField()), 'prevPage' => $prevpage, 'shrink' => imageProcessingAvailable(),
			'nonce' => cspNonce(), 'baseUri' => \W2\Html::raw(substr(json_encode(BASE_URI, $jsonFlags), 1, -1)),
			'noFileText' => \W2\Html::raw(__js('No file selected!')), 'overwriteText' => \W2\Html::raw(__js('File %s already exists. Overwrite?'))
		));
	}
	// list files in UPLOAD_FOLDER
	$path = PAGES_PATH . "/". UPLOAD_FOLDER . "/*";
	$imgNames = array_filter(glob($path), 'is_file');
	$imgList = array();
	foreach($imgNames as $imgName)
	{
		$fileItem = new StdClass();
		$fileItem->name = $imgName;
		$fileItem->recent = filemtime($imgName);
		$fileItem->size = filesize($imgName);
		$imgList[] = $fileItem;
	}
	$sortFun = function($a, $b) use ($sortBy)
	{
		return ($sortBy === 'name') ?
			strnatcasecmp($a->name, $b->name) :
			$b->$sortBy <=> $a->$sortBy;
	};
	usort($imgList, $sortFun);

	$imgPages = array();
	if (SHOW_PAGES_WHERE_FILE_USED)
	{
		$pagenames = getAllPageNames();
		foreach($pagenames as $searchPage)
		{
			$text = pageStore()->read($searchPage) ?? '';
			foreach ($imgNames as $imgName)
			{
				if ( uploadReferences()->count($text, basename($imgName)) > 0 )
				{
					$imgPages[$imgName][] = $searchPage;
				}
			}
		}
	}

	$columns = array(array(translate('Name'), 'name'), array(translate('Usage'), null),
		array(translate('Modified'), 'recent'), array(translate('Size'), 'size'), array(translate('Action'), null));
	if (SHOW_PAGES_WHERE_FILE_USED)
	{
		$columns[] = array(translate('Used on page'), null);
	}
	$date_format = __('date_format', TITLE_DATE);
	$rows = array();
	foreach ($imgList as $img)
	{
		$baseImgName = basename($img->name);
		$usedOn = array();
		foreach ($imgPages[$img->name] ?? array() as $usedPage)
		{
			$usedOn[] = array('href' => pageURL($usedPage), 'name' => $usedPage);
		}
		$rows[] = array(
			'thumbnail' => in_array(getFileExt($baseImgName), ImageExtensions, true) ? uploadURL($baseImgName) : null,
			'name' => $baseImgName, 'markdown' => imageLinkText($baseImgName),
			'modified' => date($date_format, $img->recent), 'size' => humanFilesize($img->size),
			'renameHref' => SELF."?action=imgRename&prevpage=".urlencode($prevpage)."&imgName=".urlencode($baseImgName),
			'deleteHref' => SELF."?action=imgDelete&prevpage=".urlencode($prevpage)."&imgName=".urlencode($baseImgName),
			'pages' => $usedOn);
	}
	$html .= templates()->render('upload-list', array(
		'total' => count($imgNames), 'head' => sortableHeadings($action, $sortBy, $columns),
		'showUsage' => SHOW_PAGES_WHERE_FILE_USED, 'rows' => $rows));
}
else if ( $action === 'uploaded' )
{
	if ( DISABLE_UPLOADS )
	{
		die(__('Invalid access. Uploads are disabled in the configuration.'));
	}
	$uploadError = $_FILES['userfile']['error'] ?? UPLOAD_ERR_NO_FILE;
	if ( $uploadError === UPLOAD_ERR_NO_FILE )
	{
		redirectWithNote(requireValidPreviousPage('prevpage'), (new \W2\Note())->text(translate('Upload error').": ".translate('No file selected!')));
	}
	if ( $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE )
	{
		$limit = ini_get('upload_max_filesize');
		redirectWithNote(requireValidPreviousPage('prevpage'), (new \W2\Note())->format(
			translate('Upload error: the file is larger than the allowed %s (upload_max_filesize).'), array($limit)));
	}
	$tmpName = $_FILES['userfile']['tmp_name'];
	$dstName = uploadTargetName($_FILES['userfile']['name'], $_POST['newname'] ?? '');
	// $fileType = $_FILES['userfile']['type']; // as noted in https://www.php.net/manual/en/reserved.variables.files.php, the type specified here is client-specified and thus shouldn't be trusted
	$fileType = mime_content_type($tmpName);
	$fileExt = getFileExt($dstName);
	$note = new \W2\Note();
	$typeAllowed = in_array($fileType, validUploadTypes(), true);
	$svgData = null;
	if ( $fileExt === 'svg' )
	{
		// the detected type of SVG files varies (depending on the system's magic database);
		// what counts is that the sanitizer accepts the file as SVG
		$typeAllowed = in_array($fileType, array('image/svg+xml', 'text/xml', 'application/xml', 'text/plain'), true);
		if ( $typeAllowed )
		{
			$svgData = sanitizeUploadedSvg($tmpName);
			$typeAllowed = ($svgData !== false);
		}
	}
	// (and the content must be of the type belonging to the extension, which decides how it is processed)
	if ($typeAllowed && hasValidUploadExt($dstName) && ($fileExt === 'svg' || uploadTypeMatchesExt($fileType, $fileExt)))
	{
		$path = PAGES_PATH . "/". UPLOAD_FOLDER . "/$dstName";
		$doResize = isset($_POST['resize']) && $_POST['resize'] === 'true';
		$doConvert = in_array($fileExt, explode(',', IMAGE_EXTS_TO_CONVERT));
		// never let ImageMagick parse SVG files (external references, delegates)
		$doProcess = in_array($fileExt, ImageExtensions) && $fileExt !== 'svg' && ($doConvert || $doResize);
		// (without the imagick extension, images are stored as they are, unless they have to be converted)
		$resizeSkipped = false;
		$conversionImpossible = false;
		if ($doProcess && !imageProcessingAvailable())
		{
			$conversionImpossible = $doConvert;
			$resizeSkipped = !$doConvert;
			$doProcess = false;
			$doResize = false;
		}
		$pathNoExt = substr($path, 0, strlen($path)-strlen($fileExt)-1);
		$finalPath = ($doProcess && $doConvert) ? ($pathNoExt.".".CONVERT_FORMAT) : $path;
		if ($doProcess)
		{
			$path = $pathNoExt . "-tmp-process." . $fileExt;
		}
		if ( $conversionImpossible )
		{
			w2Log("can't convert the upload $dstName: image processing is not available");
			$note->text(translate('Upload error').": ")
				->format(translate('%s needs to be converted to %s, but image processing (the PHP extension imagick) is not available on this server.'), array($dstName, CONVERT_FORMAT));
		}
		else if ( file_exists($finalPath) && ($_POST['overwrite'] ?? '') !== 'true' )
		{
			$note->text(translate('Upload error').": ")
				->format(translate('%s already exists!'), array(basename($finalPath)));
		}
		else if ( ($svgData !== null) ? (file_put_contents($path, $svgData) !== false) : (move_uploaded_file($tmpName, $path) === true) )
		{
			$commitMsg = "File '$dstName' uploaded!";
			$note->format(translate("File '%s' uploaded!"), array($dstName))->text(" ");
			$processFailed = false;
			if ($resizeSkipped)
			{
				$note->text(translate('The image was not shrunk or rotated: image processing (the PHP extension imagick) is not available on this server.')." ");
			}
			if ($doProcess)
			{
				try
				{
					// the format is given explicitly, ImageMagick must not guess it from the content
					$source = imageMagickFormatPrefix($fileExt) . $path;
					$probe = new Imagick();
					$probe->pingImage($source);
					$pixels = $probe->getImageWidth() * $probe->getImageHeight();
					$probe->clear();
					if ($pixels > MAX_IMAGE_PIXELS)
					{
						throw new ImagickException('image has too many pixels');
					}
					$img = new Imagick($source);
					if ($doResize)
					{
						$size = array($img->getImageWidth(), $img->getImageHeight());
						$maxsize = max(20, min(8192, intval($_POST['maxsize'] ?? 1200)));
						$doResize = ($size[0] > $maxsize || $size[1] > $maxsize);
					}
					if ($doResize)
					{
						$newSize = array(0, 0);
						$idx0 = ($size[0] > $size[1]) ? 0 : 1;
						$idx1 = ($idx0 == 0) ? 1 : 0;
						$newSize[$idx0] = $maxsize;
						$newSize[$idx1] = (int)round($size[$idx1] * $maxsize / $size[$idx0]);
						try
						{
							$img->resizeImage($newSize[0], $newSize[1], imagick::FILTER_LANCZOS, 1);
							$note->format(translate('Original size was %s, resized to %s.'), array("$size[0]x$size[1]", "$newSize[0]x$newSize[1]"))->text(" ");
						}
						catch (ImagickException $e)
						{
							$note->text(translate('Resizing file failed!')." ");
						}
					}
					$ori = $img->getImageOrientation();
					if($ori)
					{
						switch($ori)
						{
						case imagick::ORIENTATION_RIGHTTOP:
							$note->format(translate('Image rotated by %s°.'), array('+90'))->text(" ");
							$img->rotateImage('#000',90);
							break;
						case imagick::ORIENTATION_BOTTOMRIGHT:
							$note->format(translate('Image rotated by %s°.'), array('180'))->text(" ");
							$img->rotateImage('#000',180);
							break;
						case imagick:: ORIENTATION_LEFTBOTTOM:
							$note->format(translate('Image rotated by %s°.'), array('-90'))->text(" ");
							$img->rotateImage('#000',-90);
							break;
//						default:
//							$note->text("Unknown EXIF orientation specification: ".$ori.". ");
//							break;
						}
						$img->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
					}
					if ($doConvert)
					{
						$dstName = substr($dstName, 0, strlen($dstName)-strlen($fileExt)).CONVERT_FORMAT;
						$img->setImageFormat(CONVERT_FORMAT);
						$note->format(translate('Converted to format %s.'), array(CONVERT_FORMAT))->text(" ");
					}
					$img->writeImage($finalPath);
					unlink($path);
					$img->clear();
				}
				catch (ImagickException $e)
				{
					// e.g. a corrupt image with a valid header: don't leave the half-processed upload behind
					w2Log('processing the upload failed: '.$e->getMessage());
					if (file_exists($path))
					{
						unlink($path);
					}
					$processFailed = true;
					$note = (new \W2\Note())->text(translate('Upload error').": ")
						->format(translate('%s could not be processed (is it a valid image?)'), array($dstName));
				}
			}

			if (!$processFailed)
			{
				gitChangeHandler($commitMsg, $note);
				$note->format(translate('Use %s to refer to it!'), array(\W2\Note::block(imageLinkText($dstName))));
			}
		}
		else
		{
			$error_code = $_FILES['userfile']['error'];
			if ( $error_code === 0 )
			{
				// Likely a permissions issue
				w2Log("can't write upload to $path");
				$note->text(translate('Upload error') .": ".translate("Can't write to the uploads folder"))->br(2)
					->text(translate('Check that your permissions are set correctly.'));
			}
			else
			{
				// Give generic error message
				$note->text(translate('Upload error').", error #".$error_code)->br(2)
					->format(translate('Please see %s for more information.'), array(\W2\Note::link(translate('here'), 'https://www.php.net/manual/en/features.file-upload.errors.php')))->br(2)
					->format(translate('If you see this message, please %s'), array(\W2\Note::link(translate('file a bug to improve w2wiki'), 'https://github.com/codeling/w2wiki/issues')));
			}
		}
	}
	else
	{
		$note->text(translate('Upload error: invalid file type'));
		w2Log("Upload error: file name = $dstName, invalid file type $fileType");
	}
	$prevpage = requireValidPreviousPage('prevpage');
	redirectWithNote($prevpage, $note);
}
else if ( $action === 'rename' || $action === 'delete' || $action === 'imgDelete' || $action === 'imgRename')
{
	if ($action === 'imgDelete' || $action === 'imgRename' )
	{
		$page = sanitizeFilename($_REQUEST['imgName'] ?? '');
	}
	$actionName = ($action === 'delete' || $action === 'imgDelete') ? translate('Delete') : translate('Rename');
	$html .= templates()->render('rename-delete', array(
		'id' => $action, 'label' => $actionName, 'name' => $page, 'askNewName' => ($action === 'rename' || $action === 'imgRename'),
		'nextAction' => $action . 'd', 'csrfField' => \W2\Html::raw(csrfField()),
		'prevPage' => ($action === 'imgDelete' || $action === 'imgRename') ? requireValidPreviousPage('prevpage') : null
	));
}
else if ( $action === 'renamed' || $action === 'deleted')
{
	// TODO: prevent relative filenames from being injected
	$oldPageName = sanitizeFilename($_POST['oldPageName'] ?? '');
	$changes = new \W2\Changes();
	$result = ($action === 'deleted')
		? $changes->deletePage($oldPageName)
		: $changes->renamePage($oldPageName, sanitizeFilename($_POST['newName'] ?? ''));
	redirectWithNote($result->page, $result->note);
}
else if ( $action === 'imgDeleted' || $action === 'imgRenamed' )
{
	$oldImgName = basename(sanitizeFilename($_POST['oldPageName'] ?? ''));
	$changes = new \W2\Changes();
	$result = ($action === 'imgDeleted')
		? $changes->deleteImage($oldImgName)
		: $changes->renameImage($oldImgName, basename(str_replace(" ", "_", sanitizeFilename($_POST['newName'] ?? ''))));
	redirectWithNote(requireValidPreviousPage('prevpage'), $result->note);
}
else if ( $action === 'all' )
{
	$pageNames = getAllPageNames();
	$filelist = array();
	$sortBy = isset($_REQUEST['sortBy']) ? $_REQUEST['sortBy'] : 'name';
	if (!in_array($sortBy, array('name', 'recent', 'size')))
	{
		$sortBy = 'name';
	}
	foreach($pageNames as $pageName)
	{
		$fileItem = new StdClass();
		$fileItem->name = $pageName;
		$fileItem->recent = pageStore()->modified($pageName) ?? 0;
		$fileItem->size = pageStore()->size($pageName) ?? 0;
		$filelist[] = $fileItem;
	}
	$sortFun = function($a, $b) use ($sortBy)
	{
		return ($sortBy === 'name') ?
			strnatcasecmp($a->name, $b->name) :
			$b->$sortBy <=> $a->$sortBy;
	};
	usort($filelist, $sortFun);
	$date_format = translate('date_format', TITLE_DATE);
	$rows = array();
	foreach ($filelist as $file)
	{
		$rows[] = array('href' => pageURL($file->name), 'name' => $file->name, 'modified' => date($date_format, $file->recent),
			'size' => humanFilesize($file->size), 'actions' => getPageActions($file->name, $action, "-dark"));
	}
	$html .= templates()->render('all-pages', array(
		'total' => count($pageNames), 'rows' => $rows,
		'head' => sortableHeadings($action, $sortBy, array(
			array(translate('Name'), 'name'), array(translate('Modified'), 'recent'), array(translate('Size'), 'size'), array(translate('Action'), null)))
	));
}
else if ( $action === 'search' )
{
	$matches = 0;
	$q = $_REQUEST['q'] ?? '';
	$results = array();
	$create = null;
	if ( trim($q) != "" )
	{
		$found = FALSE;
		$matchingPages = array();
		foreach (getAllPageNames() as $searchPage)
		{
			if (strcasecmp($searchPage, $q) == 0)
			{
				$found = TRUE;
			}
			if (stripos($searchPage, $q) !== false)
			{
				array_unshift($matchingPages, $searchPage);
				++$matches;
			}
			else
			{
				$text = pageStore()->read($searchPage) ?? '';
				if ( stripos($text, $q) !== false )
				{
					$matchingPages[] = $searchPage;
					++$matches;
				}
			}
		}
		foreach ($matchingPages as $matchingPage)
		{
			$results[] = array('href' => pageURL($matchingPage), 'name' => $matchingPage, 'exact' => (strcasecmp($matchingPage, $q) == 0));
		}
		if (!$found)
		{
			$create = array('href' => pageURL($q), 'label' => translate('Create page')." '".$q."'");
		}
	}
	$html .= templates()->render('search', array(
		'heading' => translate('Search').": ".$q, 'searched' => (trim($q) != ""), 'results' => $results, 'create' => $create, 'matches' => $matches
	));
}
else
{
	$html .= empty($text) ? '' : toHTML($text);
}

$modified = '';

if ( ($action === 'all'))
{
	$title = translate("All");
}
else if ( $action === 'upload' )
{
	$title = translate("Upload");
}
else if ( $action === 'new' )
{
	$title = translate("New");
}
else if ( $action === 'search' )
{
	$title = translate("Search");
}
else if (isset($filename) && $filename != '')
{
	$title = (($action === 'edit')? (translate('Edit').": "):"") . $page;
	$date_format = translate('date_format', TITLE_DATE);
	if ( $date_format )
	{
		$modified = date($date_format, pageStore()->modified($page) ?? 0);
	}
}
else
{
	$title = translate($action);
}

// The toolbar: the pages of the wiki and what can be done with them
$toolbar = array(
	array('href' => SELF, 'icon' => assetURL("w2-icons/home.svg"), 'label' => translate(DEFAULT_PAGE)),
	array('href' => SELF . "?action=all", 'icon' => assetURL("w2-icons/list.svg"), 'label' => translate('All')),
	array('href' => SELF . "?action=new", 'icon' => assetURL("w2-icons/new.svg"), 'label' => translate('New'))
);
if ( !DISABLE_UPLOADS )
{
	$uploadPage = isset($page) ? $page : (isset($prevpage)? $prevpage : DEFAULT_PAGE);
	if ( !isExistingPage($uploadPage) )
	{
		// (the upload page only accepts existing pages to return to; e.g. not the page which is just being created)
		$uploadPage = DEFAULT_PAGE;
	}
	$toolbar[] = array('href' => SELF . "?action=upload&page=" . urlencode($uploadPage), 'icon' => assetURL("w2-icons/upload.svg"), 'label' => translate('Upload'));
}
if ( REQUIRE_PASSWORD )
{
	$toolbar[] = array('href' => SELF . "?action=logout&csrf_token=" . urlencode(csrfToken()), 'icon' => null, 'label' => translate('Log out'));
}

$sidebar = null;
if (SIDEBAR_PAGE != '' && showsSidebar($action))
{
	$sidebar = \W2\Html::raw(toHTML(pageStore()->read(SIDEBAR_PAGE) ?? (translate('Sidebar file could not be found')." (".SIDEBAR_PAGE.")")));
}

$linksHere = null;
if ($action === 'view' && isset($_GET['linkshere']))
{
	$linksHere = array();
	foreach(getAllPageNames() as $searchPage)
	{
		if ( preg_match("/\[\[".preg_quote($page, '/')."/i", pageStore()->read($searchPage) ?? '') )
		{
			$linksHere[] = array('href' => pageURL($searchPage), 'name' => $searchPage);
		}
	}
}

$note = \W2\Note::fromArray($_SESSION['note'] ?? null);
unset($_SESSION['note']);

// (the key of the local draft which the browser can remove, because its text has just been saved)
$savedDraft = $_SESSION['savedDraft'] ?? '';
unset($_SESSION['savedDraft']);

// Disable caching on the client (the iPhone is pretty agressive about this
// and it can cause problems with the editing function)
header("Cache-Control: no-store");
print templates()->render('layout', array(
	'title' => $title, 'pageTitle' => \W2\Html::raw(PAGE_TITLE),
	'bodyClass' => isEditorAction($action) ? "editor" : "", 'editor' => isEditorAction($action), 'savedDraft' => $savedDraft,
	'content' => templates()->render('page', array(
		'title' => $title, 'modified' => $modified,
		'pageActions' => ($action === 'view' || $action === 'rename' || $action === 'delete' || $action === 'edit')
			? getPageActions($page, $action, "") : null,
		'toolbar' => $toolbar,
		'drawer' => isEditorAction($action) ? templates()->render('drawer', array('uploadUrl' => uploadUrlPrefix())) : null,
		'sidebar' => $sidebar, 'linksHere' => $linksHere,
		'note' => $note->isEmpty() ? null : \W2\Html::raw($note->render()),
		'content' => \W2\Html::raw($html)
	))
));
