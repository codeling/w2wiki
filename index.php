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
		$clientKey = clientAddress($_SERVER, $trustedProxies);
		if ( !isValidCSRFToken($_POST['csrf_token'] ?? null) )
		{
			// (the password isn't checked, so this is no way to guess it)
			$loginMessage = translate('Your session has expired. Please try again.');
		}
		elseif ( ($wait = loginThrottleStart($throttleFolder, $clientKey, time(), $limits)) !== 0 )
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
			loginThrottleSucceeded($throttleFolder, $clientKey, $limits);
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
			'title' => translate('Log In'), 'pageTitle' => \W2\Html::raw(PAGE_TITLE), 'bodyClass' => 'login', 'editor' => false,
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

// $knownErrors: messages (plain text) for return values which have a meaning of their own, by return value
// $silentReturnValues: return values which are no error, but still mean the command did not succeed
function checkedExecute(\W2\Note $note, $cmd, $knownErrors = array(), $silentReturnValues = array())
{
	$returnValue = 0;
	$output = '';
	exec($cmd, $output, $returnValue);
	if ($returnValue != 0 && !in_array($returnValue, $silentReturnValues))
	{
		// details (command, output) may reveal server internals, so only log them
		w2Log("error executing command $cmd (return value: $returnValue): ".implode(" ", $output));
		$note->br();
		if (isset($knownErrors[$returnValue]))
		{
			$note->text($knownErrors[$returnValue]);
		}
		else
		{
			$note->format(translate("Error executing git command (return value: %s); see the web server's error log for details."), array($returnValue));
		}
	}
	return ($returnValue == 0);
}

// Return value of the commit command if there is nothing to commit
const GIT_NOTHING_TO_COMMIT = 4;

function gitChangeHandler($commitmsg, \W2\Note $note)
{
	if (!GIT_COMMIT_ENABLED)
	{
		return;
	}
	// (not while a pull is running; the lock was usually taken before the change was made)
	$lock = gitLock(true);
	// (nothing to commit, e.g., if a page was saved without changes, is no error, and nothing to push)
	$commit = "cd ".escapeshellarg(PAGES_PATH)." && git add -A && { ".
		"if git diff --cached --quiet; then exit ".GIT_NOTHING_TO_COMMIT."; fi; ".
		"git commit -m ".escapeshellarg($commitmsg)."; }";
	if (checkedExecute($note, $commit, array(), array(GIT_NOTHING_TO_COMMIT)))
	{
		if (GIT_PUSH_ENABLED)
		{
			checkedExecute($note, "cd ".escapeshellarg(PAGES_PATH)." && git push");
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
	$pull = "cd ".escapeshellarg(PAGES_PATH)." && ".
		"if command -v timeout >/dev/null 2>&1; then limit='timeout 20'; else limit=; fi; ".
		"GIT_TERMINAL_PROMPT=0 \$limit git pull --no-rebase --no-edit; result=\$?; ".
		"if [ \$result -ne 0 ] && git rev-parse -q --verify MERGE_HEAD >/dev/null 2>&1; then git merge --abort; exit ".GIT_PULL_CONFLICT."; fi; ".
		"exit \$result";
	$conflict = translate('There were merge conflicts while pulling changes from the remote repository. The pull was cancelled and the wiki keeps its own version. Please resolve the conflicts in the pages repository (ask the wiki administrator).');
	$pullNote = new \W2\Note();
	if (checkedExecute($pullNote, $pull, array(GIT_PULL_CONFLICT => $conflict)) && GIT_PUSH_ENABLED)
	{
		// (also publishes the merge, and commits which could not be pushed earlier)
		checkedExecute($pullNote, "cd ".escapeshellarg(PAGES_PATH)." && GIT_TERMINAL_PROMPT=0 git push");
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
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted'), true) &&
	($_SERVER['REQUEST_METHOD'] !== 'POST' || !isValidCSRFToken($_POST['csrf_token'] ?? null)))
{
	http_response_code(403);
	die(__('Invalid request: missing or wrong security token. Please go back, reload the page and try again.'));
}
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted'), true))
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
if ( $action == 'save' )
{
	$note = new \W2\Note();
	$newText = $_POST['newText'] ?? '';
	$isNew = $_POST['isNew'] ?? '';
	if ($isNew)
	{
		$page = str_replace(array('|','#'), '', $page);
		$filename = fileNameForPage($page);
	}
	// (the name is checked when saving existing pages too: the client decides whether a page is new)
	if (($isNew && isExistingPage($page)) || !isValidPageName($page))
	{
		if ($isNew && isExistingPage($page))
		{
			$note->format(translate("Error creating page '%s' - it already exists! Please choose a different name, or %s the existing page (this discards current text!)!"),
				array($page, \W2\Note::link(translate('edit'), "?action=edit&page=".urlencode($page))));
		}
		else
		{
			$note->format(translate("Error creating page '%s' - invalid page name! Page names must not start with '%s/', or contain empty or hidden ('.'-prefixed) folder names."),
				array($page, UPLOAD_FOLDER));
		}
		$action = $isNew ? 'new' : 'edit';
		$text = $newText;
		$newPage = $page;
		if (GIT_COMMIT_ENABLED)
		{
			$oldgitmsg = $_POST['gitmsg'] ?? '';
		}
		$triedSave = true;
	}
	else
	{
		$success = writePage($page, $newText);
		if ( !$success )
		{
			$note->text(translate('Error saving changes! Make sure your web server has write access to the pages folder.'));
			w2Log("error saving $filename");
			$action = ($isNew ? 'new' : 'edit');
			$text = $newText;
			$newPage = $page;
			if (GIT_COMMIT_ENABLED)
			{
				$oldgitmsg = $_POST['gitmsg'] ?? '';
			}
			$triedSave = true;
		}
		else
		{
			$note->text($isNew ? translate('Created') : translate('Saved'));
			$usermsg = $_POST['gitmsg'] ?? '';
			$commitmsg = $page . ($usermsg !== '' ?  (": ".$usermsg) : ($isNew ? " created" : " changed"));
			gitChangeHandler($commitmsg, $note);
			redirectWithNote($page, $note);
		}
	}
	// saving failed: show the editor again (with the entered title, text and message)
	// instead of redirecting, so that nothing typed is lost
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
	$html .= templates()->render('editor', array(
		'draftKey' => "w2wiki-draft:" . SELF . (($action === 'edit') ? ":edit:$page" : ":new:$newPage"),
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

	$html .= "<p>".__('Total').": ".count($imgNames)." ".__('images')."</p>";
	$imgPages = array();
	if (SHOW_PAGES_WHERE_FILE_USED)
	{
		$pagenames = getAllPageNames();
		foreach($pagenames as $searchPage)
		{
			$text = pageStore()->read($searchPage) ?? '';
			foreach ($imgNames as $imgName)
			{
				$baseImgName = basename($imgName);
				if ( uploadReferences()->count($text, $baseImgName) > 0 )
				{
					if (array_key_exists($imgName, $imgPages))
					{
						array_push($imgPages[$imgName], $searchPage);
					}
					else
					{
						$imgPages[$imgName] = array( $searchPage );
					}
				}
			}
		}
	}

	$html .= "<table><thead>";
	$html .= "<tr>".
		"<th>".(($sortBy!='name')?("<a href=\"".SELF."?action=upload&sortBy=name\">".__('Name')."</a>"):"<span class=\"sortBy\">".__('Name')."</span>")."</th>".
		"<th>".__("Usage")."</th>".
		"<th>".(($sortBy!='recent')?("<a href=\"".SELF."?action=upload&sortBy=recent\">".__('Modified')."</a>"):"<span class=\"sortBy\">".__('Modified')."</span>")."</th>".
		"<th>".(($sortBy!='size')?("<a href=\"".SELF."?action=upload&sortBy=size\">".__('Size')."</a>"):"<span class=\"sortBy\">".__('Size')."</span>")."</th>".
		"<th>".__("Action")."</th>";
	if (SHOW_PAGES_WHERE_FILE_USED)
	{
		$html .=  "<th>".__("Used on page")."</th>";
	}
	$html .= "</tr></thead><tbody>";
	$date_format = __('date_format', TITLE_DATE);

	foreach ($imgList as $img)
	{
		$baseImgName = basename($img->name);
		$isImg = false;
		foreach(ImageExtensions as $ext)
		{
			if (str_ends_with($baseImgName, $ext))
			{
				$isImg = true;
			}
		}
		$html .= "<tr>".
			"<td>".($isImg?"<img class=\"thumbImg\" src=\"".h(uploadURL($baseImgName))."\" />":"<span class=\"thumbPlaceHolder\"></span>")."<span class=\"uploadFileName\">".h($baseImgName)."</span></td>".
			"<td><pre>".h(imageLinkText($baseImgName))."</pre></td>".
			"<td><nobr>".date($date_format, $img->recent)."</nobr></td>".
			"<td><nobr>".humanFilesize($img->size)."</nobr></td>".
			"<td>".
			    "<a href=\"".SELF."?action=imgRename&amp;prevpage=".urlencode($prevpage)."&amp;imgName=".urlencode($baseImgName)."\"><img src=\"" . assetURL("w2-icons/rename-dark.svg") . "\" alt=\"".__('Rename')."\" title=\"".__('Rename')."\" class=\"icon\"/></a>".
			    "<a href=\"".SELF."?action=imgDelete&amp;prevpage=".urlencode($prevpage)."&amp;imgName=".urlencode($baseImgName)."\"><img src=\"" . assetURL("w2-icons/delete.svg") . "\" alt=\"".__('Delete')."\" title=\"".__('Delete')."\" class=\"icon\"/></a></td>";
		if (SHOW_PAGES_WHERE_FILE_USED)
		{
			$html .= "<td>";
			if (array_key_exists($img->name, $imgPages))
			{
				foreach($imgPages[$img->name] as $page)
				{
					$html .= pageLink($page, h($page));
				}
			}
			$html .= "</td>";
		}
		$html .= "</tr>\n";
	}
	$html .= "</tbody></table>\n";
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
	$newPageName = ($action === 'deleted') ? "": sanitizeFilename($_POST['newName'] ?? '');
	$note = new \W2\Note();
	if ($action === 'deleted')
	{
		$success = pageStore()->delete($oldPageName);
	}
	else
	{
		$success = pageStore()->rename($oldPageName, $newPageName);
	}
	if ($success)
	{
		// (plain text, which is also the message of the commit)
		$message = ($action === 'deleted')
			? (translate('Removed')." ".$oldPageName)
			: (translate('Renamed')." ".$oldPageName." ".translate('to')." ".$newPageName);
		$note->text($message);
		// Change links in all pages to point to new page
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = pageStore()->read($replacePage) ?? '';
			$count = 0;
			$newContent = preg_replace("/\[\[".preg_quote($oldPageName, '/')."([|#].*?\]\]|\]\])/",
				(($action === 'deleted') ? "" : "[[".pregReplacementQuote($newPageName)."\\1"),
				$content, -1, $count);
			if ($count > 0) // if something changed
			{
				if ( writePage($replacePage, $newContent) )
				{
					$changedPages[] = $replacePage." ($count ".translate('matches').")";
				}
				else
				{
					w2Log("error updating links in " . fileNameForPage($replacePage));
				}
			}
		}
		if (count($changedPages) > 0)
		{
			$note->items(translate('Updated links in the following pages:'), $changedPages);
		}
		gitChangeHandler($message, $note);
		$page = $newPageName;
	}
	else
	{
		$note->text(($action === 'deleted')
			? (translate('Error deleting file')." ".$oldPageName)
			: (translate('Error renaming file')." ".$oldPageName." ".translate('to')." ".$newPageName));
		$page = $oldPageName;
	}
	if ($action === 'deleted' && $success)
	{
		$page  = DEFAULT_PAGE;
	}
	redirectWithNote($page, $note);
}
else if ( $action === 'imgDeleted' || $action === 'imgRenamed' )
{
	$oldImgName = basename(sanitizeFilename($_POST['oldPageName'] ?? ''));
	$imgPath = PAGES_PATH . "/". UPLOAD_FOLDER . "/";
	$oldImgPath = $imgPath . $oldImgName;
	$newImgName = ($action === 'imgDeleted') ? "": basename(str_replace(" ", "_", sanitizeFilename($_POST['newName'] ?? '')));
	if (isHiddenFile($oldImgName))
	{
		// never touch e.g. the .htaccess file protecting the uploads folder
		$success = false;
	}
	else if ($action == 'imgDeleted')
	{
		$success = is_file($oldImgPath) && unlink($oldImgPath);
	}
	else
	{
		// only allow renaming to a valid upload extension; otherwise, e.g.
		// an image containing PHP code could be renamed to .php and executed;
		// the type must stay the same (a PNG must not become an unsanitized SVG)
		$success = is_file($oldImgPath) && hasValidUploadExt($newImgName) && renameKeepsFileType($oldImgName, $newImgName) &&
			!file_exists($imgPath.$newImgName) &&
			rename($oldImgPath, $imgPath.$newImgName);
	}

	$note = new \W2\Note();
	if ($success)
	{
		$commitMsg = ($action === 'imgDeleted')
			? (translate('Image deleted').": ".$oldImgName)
			: (translate('Image renamed').": ".$oldImgName." ".translate('to')." ".$newImgName);
		$note->text($commitMsg);
		$changedPageNames = array();
		// Change references to image in all pages:
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = pageStore()->read($replacePage) ?? '';
			list($newContent, $count) = ($action === 'imgDeleted')
				? uploadReferences()->removeImages($content, $oldImgName)
				: uploadReferences()->rename($content, $oldImgName, $newImgName);
			if ($count > 0) // if something changed
			{
				if ( writePage($replacePage, $newContent) )
				{
					$changedPages[] = $replacePage." ($count ".translate('matches').")";
					$changedPageNames[] = $replacePage;
				}
				else
				{
					w2Log("error updating links in " . fileNameForPage($replacePage));
				}
			}
		}
		if (count($changedPages) > 0)
		{
			$commitMsg .= " (".translate('Updated images in the following pages:')." ".implode(", ", $changedPageNames).")";
			$note->items(translate('Updated images in the following pages:'), $changedPages);
		}
		gitChangeHandler($commitMsg, $note);
	}
	else
	{
		$note->text(($action === 'imgDeleted')
			? (translate('Error deleting image: ')." (".$oldImgName.")")
			: (translate('Error renaming image: ').$oldImgName." ".translate('to')." ".$newImgName));
	}
	$prevpage = requireValidPreviousPage('prevpage');
	redirectWithNote($prevpage, $note);
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
	foreach($pageNames as $page)
	{
		$fileItem = new StdClass();
		$fileItem->name = $page;
		$fileItem->recent = pageStore()->modified($page) ?? 0;
		$fileItem->size = pageStore()->size($page) ?? 0;
		$filelist[] = $fileItem;
	}
	$sortFun = function($a, $b) use ($sortBy)
	{
		return ($sortBy === 'name') ?
			strnatcasecmp($a->name, $b->name) :
			$b->$sortBy <=> $a->$sortBy;
	};
	usort($filelist, $sortFun);
	$html .= "<p>".__('Total').": ".count($pageNames)." ".__("pages")."</p>";
	$html .= "<table><thead>";
	$html .= "<tr>".
		"<th>".(($sortBy!='name')?("<a href=\"".SELF."?action=all&sortBy=name\">".__('Name')."</a>"):"<span class=\"sortBy\">".__('Name')."</span>")."</th>".
		"<th>".(($sortBy!='recent')?("<a href=\"".SELF."?action=all&sortBy=recent\">".__('Modified')."</a>"):"<span class=\"sortBy\">".__('Modified')."</span>")."</th>".
		"<th>".(($sortBy!='size')?("<a href=\"".SELF."?action=all&sortBy=size\">".__('Size')."</a>"):"<span class=\"sortBy\">".__('Size')."</span>")."</th>".
		"<th>".__('Action')."</th>".
		"</tr></thead><tbody>";
	$date_format = __('date_format', TITLE_DATE);

	foreach ($filelist as $file)
	{
		$html .= "<tr>".
			"<td>".pageLink($file->name, h($file->name))."</td>".
			"<td valign=\"top\"><nobr>".date( $date_format, $file->recent)."</nobr></td>".
			"<td valign=\"top\"><nobr>".humanFilesize($file->size)."</nobr></td>".
			"<td class=\"pageActions\">".getPageActions($file->name, $action,"-dark")."</td>".
			"</tr>\n";
	}
	$html .= "</tbody></table>\n";
}
else if ( $action === 'search' )
{
	$matches = 0;
	$q = $_REQUEST['q'] ?? '';
	$html .= "    <h1>".__('Search').": ".h($q)."</h1>\n";

	if ( trim($q) != "" )
	{
		$html .= "    <ul>\n";
		$pagenames = getAllPageNames();
		$found = FALSE;
		$matchingPages = array();
		foreach ($pagenames as $searchPage)
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
		foreach ($matchingPages as $page)
		{
			$link = pageLink($page, h($page), (strcasecmp($page, $q) == 0)? " class=\"literalMatch\"": "");
			$html .= "        <li>$link</li>\n";
		}
		if (!$found)
		{
			$html .= "        <li>".pageLink($q, __('Create page')." '".h($q)."'", " class=\"noexist\"")."</li>";
		}
		$html .= "      </ul>\n";
	}
	$html .= "      <p>$matches ".__('matches')."</p>\n";
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

// Disable caching on the client (the iPhone is pretty agressive about this
// and it can cause problems with the editing function)
header("Cache-Control: no-store");
print templates()->render('layout', array(
	'title' => $title, 'pageTitle' => \W2\Html::raw(PAGE_TITLE),
	'bodyClass' => isEditorAction($action) ? "editor" : "", 'editor' => isEditorAction($action),
	'content' => templates()->render('page', array(
		'title' => $title, 'modified' => $modified,
		'pageActions' => ($action === 'view' || $action === 'rename' || $action === 'delete' || $action === 'edit')
			? \W2\Html::raw(getPageActions($page, $action, "")) : null,
		'toolbar' => $toolbar,
		'drawer' => isEditorAction($action) ? templates()->render('drawer', array('uploadUrl' => uploadUrlPrefix())) : null,
		'sidebar' => $sidebar, 'linksHere' => $linksHere,
		'note' => $note->isEmpty() ? null : \W2\Html::raw($note->render()),
		'content' => \W2\Html::raw($html)
	))
));
