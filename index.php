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
			// (like the failed ones: a login nobody expected is the sign of a stolen password)
			w2Log("successful login from " . $clientKey);
			// prevent session fixation
			session_regenerate_id(true);
			$_SESSION['password'] = true;
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}
		elseif ( legacyPasswordMatches($_POST['p']) )
		{
			// (no login with the settings of earlier versions; but the user has just proven to know the
			// password, so we can offer its hash for the new setting)
			\WebAuth\LoginThrottle::succeeded($throttleFolder, $clientKey, $limits);
			w2Log("login refused: " . legacyPasswordSetting() . " in config.php is no longer supported; set W2_PASSWORD_HASH to a password_hash() hash (the login page shows one of the password just entered)");
			$migrationNote = (new \W2\Note())
				->format(translate('Logging in with %s in config.php is no longer supported, as it can be guessed quickly by anybody who sees config.php. To log in, set W2_PASSWORD_HASH in config.php to this hash of your password (in single quotes):'), array(legacyPasswordSetting()))
				->text(' ')
				->code(password_hash($_POST['p'], PASSWORD_DEFAULT));
			$loginMessage = \W2\Html::raw($migrationNote->render());
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
		if ( W2_PASSWORD_HASH === '' && legacyPasswordSetting() === '' )
		{
			$notes[] = translate('Login is disabled until a password is set: please set W2_PASSWORD_HASH in config.php (see config.sample.php).');
		}
		// (the page may show a hash of the password, see above)
		header("Cache-Control: no-store");
		print templates()->render('layout', array(
			'title' => translate('Log In'), 'pageTitle' => \W2\Html::raw(PAGE_TITLE), 'bodyClass' => 'login', 'editor' => false, 'script' => false, 'savedDraft' => '',
			'content' => templates()->render('login', array(
				'heading' => translate('Log In'), 'notes' => $notes, 'passwordLabel' => translate('Password'),
				'buttonLabel' => translate('Log In'), 'csrfField' => \W2\Html::raw(csrfField())
			))
		));
		exit;
	}
}

// Support functions

/**
 * The dialog to upload files, which the editor and the upload page have (the script of the page opens it)
 */
function uploadDialog()
{
	return templates()->render('upload-dialog', array(
		'shrink' => imageProcessingAvailable(), 'csrfToken' => csrfToken(), 'apiUrl' => BASE_URI . '/api.php'
	));
}

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

/**
 * Answers a request made by the script of the editor (an upload with json=1) in JSON and ends it
 *
 * @param array $data	"success", the "message" (plain text) and, if the file was stored, its name ("file") and the "link" to it
 */
function jsonAnswer($status, array $data)
{
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($data + array('success' => false, 'message' => ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
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
// (the editor uploads files with a request of its own, and wants the answer as JSON instead of a redirect;
// from the query, as PHP drops the form of a request which is too large)
$uploadJson = $action === 'uploaded' && ($_GET['json'] ?? '') === '1';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES)
{
	// PHP discards the whole body of a request larger than post_max_size (no token, no file)
	if ($uploadJson)
	{
		jsonAnswer(413, array('message' => sprintf(__('The upload is too large: the server accepts requests of up to %s (post_max_size).'), ini_get('post_max_size'))));
	}
	http_response_code(413);
	die(sprintf(__('The upload is too large: the server accepts requests of up to %s (post_max_size).'), h(ini_get('post_max_size'))).' '.
		__('Nothing was uploaded or saved. Please go back and try again with a smaller file.'));
}
$saveRefused = false;
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted', 'linkMention'), true) &&
	($_SERVER['REQUEST_METHOD'] !== 'POST' || !isValidCSRFToken($_POST['csrf_token'] ?? null)))
{
	if ($uploadJson)
	{
		jsonAnswer(403, array('message' => __('Invalid request: missing or wrong security token. Please go back, reload the page and try again.')));
	}
	http_response_code(403);
	if ($action !== 'save' || $_SERVER['REQUEST_METHOD'] !== 'POST' || isCrossSiteRequest($_SERVER))
	{
		die(__('Invalid request: missing or wrong security token. Please go back, reload the page and try again.'));
	}
	// usually the session expired while editing: nothing is saved, but the editor is shown
	// again with the text (and the new token), so that the text isn't lost
	$saveRefused = true;
}
if (in_array($action, array('save', 'uploaded', 'renamed', 'deleted', 'imgRenamed', 'imgDeleted', 'linkMention'), true) && !$saveRefused)
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
$baseHash = null;
$diff = null;
$content = \W2\Html::raw('');
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
		$baseHash = \W2\Changes::textHash($text);
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
	$postedHash = $_POST['baseHash'] ?? '';
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
		$result = (new \W2\Changes())->save($page, (bool)$isNew, $newText, $_POST['gitmsg'] ?? '', $postedHash !== '' ? $postedHash : null);
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
	if (!$isNew)
	{
		// (the editor sends the hash it was sent, until the user has seen what somebody else has changed: then the next
		// save is meant to replace it)
		$baseHash = ($postedHash !== '' ? $postedHash : null);
		if (isset($result) && $result->conflict)
		{
			$current = pageStore()->read($result->page) ?? '';
			$baseHash = \W2\Changes::textHash($current);
			$differences = \W2\LineDiff::compare($current, $newText);
			$diff = ($differences === null) ? null : \W2\LineDiff::withContext($differences);
		}
	}
	$_SESSION['note'] = $note->toArray();
}

if ( isEditorAction($action) )
{
	$showContext = ($action === 'new' && $newPage != "" && !$triedSave);
	$similarPages = $showContext ? (new \W2\Lists())->similarPages($newPage) : null;
	$pageContext = $showContext ? (new \W2\Lists())->pageContext($newPage) : null;
	// (after a failed save, the draft is the one of the editor which sent the text, e.g. of a new page without title)
	$draftKey = ($triedSave && $postedDraftKey !== '') ? $postedDraftKey :
		"w2wiki-draft:" . SELF . (($action === 'edit') ? ":edit:$page" : ":new:$newPage");
	$content = templates()->render('editor', array(
		'draftKey' => $draftKey, 'unsaved' => $triedSave,
		'isNew' => $action !== 'edit', 'csrfField' => \W2\Html::raw(csrfField()),
		'page' => $action === 'edit' ? $page : '', 'newPage' => $newPage, 'similarPages' => $similarPages, 'pageContext' => $pageContext,
		'text' => $text, 'rows' => EDIT_ROWS, 'gitEnabled' => GIT_COMMIT_ENABLED, 'gitMessage' => $oldgitmsg,
		'baseHash' => $action === 'edit' ? $baseHash : null, 'diff' => $diff,
		'uploadDialog' => DISABLE_UPLOADS ? null : uploadDialog()
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
	$lists = new \W2\Lists();
	$sortBy = $lists->sortKey($_REQUEST['sortBy'] ?? 'name');
	$prevpage = requireValidPreviousPage('page');
	// (for the administrator; once per session, as long as the setup isn't changed)
	if ( empty($_SESSION['upload_setup_checked']) )
	{
		foreach ( uploadSetupProblems() as $problem )
		{
			w2Log($problem);
		}
		$_SESSION['upload_setup_checked'] = true;
	}
	$content = templates()->render('upload-page', array(
		'dialog' => DISABLE_UPLOADS ? null : uploadDialog(), 'list' => $lists->uploads($sortBy, $prevpage)
	));
}
else if ( $action === 'uploaded' )
{
	if ( DISABLE_UPLOADS )
	{
		if ($uploadJson)
		{
			jsonAnswer(403, array('message' => __('Invalid access. Uploads are disabled in the configuration.')));
		}
		die(__('Invalid access. Uploads are disabled in the configuration.'));
	}
	$result = (new \W2\Uploads())->store($_FILES['userfile'] ?? array(), $_POST['newname'] ?? '', ($_POST['overwrite'] ?? '') === 'true',
		isset($_POST['resize']) && $_POST['resize'] === 'true', intval($_POST['maxsize'] ?? 1200), parseCropRect($_POST['crop'] ?? ''));
	if ($uploadJson)
	{
		$stored = $result->file !== '';
		jsonAnswer($stored ? 200 : 422, array('success' => $stored, 'message' => $result->note->toText(),
			'file' => $result->file, 'link' => $stored ? imageLinkText($result->file) : ''));
	}
	redirectWithNote(requireValidPreviousPage('prevpage'), $result->note);
}
else if ( $action === 'rename' || $action === 'delete' || $action === 'imgDelete' || $action === 'imgRename')
{
	if ($action === 'imgDelete' || $action === 'imgRename' )
	{
		$page = sanitizeFilename($_REQUEST['imgName'] ?? '');
	}
	$actionName = ($action === 'delete' || $action === 'imgDelete') ? translate('Delete') : translate('Rename');
	$content = templates()->render('rename-delete', array(
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
else if ( $action === 'linkMention' )
{
	$baseHash = is_string($_POST['baseHash'] ?? null) ? $_POST['baseHash'] : '';
	$result = (new \W2\Changes())->linkMention(sanitizeFilename($_POST['page'] ?? ''), sanitizeFilename($_POST['target'] ?? ''), $baseHash);
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
	$lists = new \W2\Lists();
	$content = $lists->allPages($lists->sortKey($_REQUEST['sortBy'] ?? 'name'));
}
else if ( $action === 'search' )
{
	$content = (new \W2\Lists())->search($_REQUEST['q'] ?? '');
}
else
{
	$content = \W2\Html::raw(empty($text) ? '' : toHTML($text));
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
	$toolbar[] = array('href' => SELF . "?action=upload&page=" . urlencode($uploadPage), 'icon' => assetURL("w2-icons/media.svg"), 'label' => translate('Media'));
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

$linksHere = ($action === 'view' && isset($_GET['linkshere'])) ? (new \W2\Lists())->linksHere($page) : null;

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
	'bodyClass' => isEditorAction($action) ? "editor" : "", 'editor' => isEditorAction($action),
	'script' => isEditorAction($action) || ($action === 'upload' && !DISABLE_UPLOADS) || $savedDraft !== '', 'savedDraft' => $savedDraft,
	'content' => templates()->render('page', array(
		'title' => $title, 'modified' => $modified,
		'pageActions' => ($action === 'view' || $action === 'rename' || $action === 'delete' || $action === 'edit')
			? getPageActions($page, $action, "") : null,
		'toolbar' => $toolbar,
		'drawer' => isEditorAction($action) ? templates()->render('drawer', array('uploadUrl' => uploadUrlPrefix(), 'uploads' => !DISABLE_UPLOADS)) : null,
		'sidebar' => $sidebar, 'linksHere' => $linksHere,
		'note' => $note->isEmpty() ? null : \W2\Html::raw($note->render()),
		'content' => $content
	))
));
