<?php
define('W2APP', true);
/*
 * W2
 *
 * Copyright (C) 2007-2011 Steven Frank <http://stevenf.com/>
 *
 * Code may be re-used as long as the above copyright notice is retained.
 * See README.txt for full details.
 *
 * Written with Coda: <http://panic.com/coda/>
 *
 */


// Helper functions (and optional libraries installed via Composer):
require_once "functions.php";

// User configurable options:
require_once "config.php";

// Load configured localization:
require_once 'locales/' . W2_LOCALE . '.php';

const ImageExtensions = array("bmp", "gif", "heic", "heif","jpg", "jpeg", "png", "svg", "webp");

// Session handling, IP and password checks:
require_once "auth.php";

function printHeader($title, $action, $bodyclass="")
{
	print "<!doctype html>\n";
	print "<html lang=\"" . W2_LOCALE . "\">\n";
	print "  <head>\n";
	print "    <meta charset=\"" . W2_CHARSET . "\">\n";
	print "    <link rel=\"apple-touch-icon\" href=\"" . assetURL("w2-icons/w2-icon.png") . "\"/>\n";
	print "    <link rel=\"icon\" href=\"" . assetURL("w2-icons/w2-icon.png") . "\"/>\n";
	print "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n";
	print "    <link type=\"text/css\" rel=\"stylesheet\" href=\"" . assetURL(CSS_FILE) ."\" />\n";
	print "    <title>".PAGE_TITLE."$title</title>\n";
	if (isEditorAction($action))
	{
		// (warns when leaving the editor with unsaved changes; for new pages too, as they would be lost silently)
		// (not relative: pages can be shown below the script, like /index.php/Page)
		print "    <script src=\"" . assetURL("wiki.js") . "\"></script>\n";
	}
	print "  </head>\n";
	print "  <body".($bodyclass != "" ? " class=\"$bodyclass\"":"").">\n";
}

function printFooter()
{
	print "  </body>\n";
	print "</html>";
}

function printDrawer()
{
	print "      <div id=\"drawer\" class=\"inactive\">\n".
		"        <a href=\"\" onclick=\"".HANDLER_TOGGLE_DRAWER."\"><img src=\"" . assetURL("w2-icons/close.svg") . "\" alt=\"".__('Close')."\" title=\"".__('Close')."\" class=\"icon rightaligned\"/></a>\n".
		"        <h5>".__('Markdown Syntax Helper')."</h5>\n".
		"        <div>\n".
		"# ".__('Header')." 1<br/>".
		"## ".__('Header')." 2<br/>".
		"### ".__('Header')." 3<br/>".
		"#### ".__('Header')." 4<br/>".
		"##### ".__('Header')." 5<br/>".
		"###### ".__('Header')." 6<br/>".
		"<br/>".
		"*".__('Emphasize')."* - <em>".__('Emphasize')."</em><br/>".
		"_".__('Emphasize')."_ - <em>".__('Emphasize')."</em><br/>".
		"**".__('Bold')."** - <strong>".__('Bold')."</strong><br/>".
		"__".__('Bold')."__ - <strong>".__('Bold')."</strong><br/>".
		"<br/>".
		"[[".__('Link to page')."]]<br/>".
		"&lt;http://example.com/&gt;<br/>".
		"[".__('link text')."](http://url)<br/><br/>".
		"![".__('Alt text')."](".h(uploadUrlPrefix())."image.jpg)<br/>".
		"![".__('Alt text')."](".h(uploadUrlPrefix())."image.jpg \"".__('Optional title')."\")<br/>".
		"<br/>".
		"- ".__('Unordered list')."<br/>".
		"+ ".__('Unordered list')."<br/>".
		"* ".__('Unordered list')."<br/>".
		"1. ".__('Ordered list')."<br/>".
		"<br/>".
		"> ".__('Blockquote')."<br/>".
		"```".__('Code')."```<br/>".
		"`".__('Inline code')."`<br/><br/>".
		"*** ".__('Horizontal rule')."<br/>".
		"--- ".__('Horizontal rule')."<br/>\n".
		"        </div>\n".
		"      </div>\n".
		"      <a id=\"drawer-control\" href=\"\" onclick=\"".HANDLER_TOGGLE_DRAWER."\">\n".
		"        <span class=\"icongroup\">\n".
		"          <img src=\"" . assetURL("w2-icons/format-text-bold.svg") . "\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"          <img src=\"" . assetURL("w2-icons/format-text-italic.svg") . "\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"          <img src=\"" . assetURL("w2-icons/format-text-code.svg") . "\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"        </span>\n".
		"      </a>\n";
}

if ( !isLoggedIn() )
{
	$loginFailed = false;
	if ( isset($_POST['p']) )
	{
		if ( isCorrectPassword($_POST['p']) )
		{
			// prevent session fixation
			session_regenerate_id(true);
			$_SESSION['password'] = true;
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}
		else
		{
			$loginFailed = true;
			error_log("W2: failed login attempt from " . $_SERVER['REMOTE_ADDR']);
			sleep(2);   // slow down brute-force attempts
		}
	}
	if ( empty($_SESSION['password']) )
	{
		printHeader( __('Log In'), '', "login");
		print "    <h1>" . __('Log In') . "</h1>\n";
		if ( $loginFailed )
		{
			print "    <p class=\"note\">" . __('Wrong password') . "</p>\n";
		}
		if ( (!defined('W2_PASSWORD_HASH') || W2_PASSWORD_HASH === '') && defined('W2_PASSWORD') && W2_PASSWORD === 'secret' )
		{
			print "    <p class=\"note\">" . __('Login is disabled while the default password is configured; please set W2_PASSWORD_HASH (or W2_PASSWORD) in config.php.') . "</p>\n";
		}
		print "    <form method=\"post\">\n";
		print "      ".__('Password') . ": <input type=\"password\" name=\"p\">\n";
		print "      <input type=\"submit\" value=\"" . __('Log In') . "\">\n";
		print "    </form>\n";
		printFooter();
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

function redirectWithMessage($page, $msg)
{
	$_SESSION["msg"] = $msg;
	header("HTTP/1.1 303 See Other");
	header("Location: " . pageURL($page) );
	exit;
}

function checkedExecute(&$msg, $cmd)
{
	$returnValue = 0;
	$output = '';
	exec($cmd, $output, $returnValue);
	if ($returnValue != 0)
	{
		// details (command, output) may reveal server internals, so only log them
		error_log("W2: error executing command $cmd (return value: $returnValue): ".implode(" ", $output));
		$msg .= "<br/>".sprintf(__("Error executing git command (return value: %s); see the web server's error log for details."), h($returnValue));
	}
	return ($returnValue == 0);
}

function gitChangeHandler($commitmsg, &$msg)
{
	if (!GIT_COMMIT_ENABLED)
	{
		return;
	}
	if (checkedExecute($msg, "cd ".escapeshellarg(PAGES_PATH)." && git add -A && git commit -m ".escapeshellarg($commitmsg)))
	{
		if (!GIT_PUSH_ENABLED)
		{
			return;
		}
		checkedExecute($msg, "cd ".escapeshellarg(PAGES_PATH)." && git push");
	}
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
if ($action === 'view' || $action === 'edit')
{
	if ( isExistingPage($page) )
	{
		$text = file_get_contents($filename);
	}
	else
	{
		// links used to encode spaces in page names as '+'; keep them working
		$plusPage = str_replace('+', ' ', $page);
		if ( $action === 'view' && $plusPage !== $page && file_exists(fileNameForPage($plusPage)) )
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
				redirectWithMessage($p, sprintf(__("Page %s does not exist, redirected instead to first page in a subfolder with matching filename (%s)"), h($page), h($p)));
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
	$msg = '';
	$newText = $_POST['newText'] ?? '';
	$isNew = $_POST['isNew'] ?? '';
	if ($isNew)
	{
		$page = str_replace(array('|','#'), '', $page);
		$filename = fileNameForPage($page);
	}
	// (the name is checked when saving existing pages too: the client decides whether a page is new)
	if (($isNew && file_exists($filename)) || !isValidPageName($page))
	{
		$msg .= ($isNew && file_exists($filename))
			? sprintf(__("Error creating page '%s' - it already exists! Please choose a different name, or %s the existing page (this discards current text!)!"), h($page), "<a href=\"?action=edit&amp;page=".urlencode($page)."\">".__('edit')."</a>")."\n"
			: sprintf(__("Error creating page '%s' - invalid page name! Page names must not start with '%s/', or contain empty or hidden ('.'-prefixed) folder names."), h($page), h(UPLOAD_FOLDER))."\n";
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
		if ( !file_exists( dirname($filename) ) )
		{
			mkdir(dirname($filename), 0755, true);
		}
		$success = file_put_contents($filename, $newText);
		if ( $success === FALSE)
		{
			$msg .= __('Error saving changes! Make sure your web server has write access to the pages folder.')."\n";
			error_log("W2: error saving $filename");
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
			$msg .= ($isNew ? __('Created'): __('Saved'));
			$usermsg = $_POST['gitmsg'] ?? '';
			$commitmsg = $page . ($usermsg !== '' ?  (": ".$usermsg) : ($isNew ? " created" : " changed"));
			gitChangeHandler($commitmsg, $msg);
			redirectWithMessage($page, $msg);
		}
	}
	// saving failed: show the editor again (with the entered title, text and message)
	// instead of redirecting, so that nothing typed is lost
	$_SESSION["msg"] = $msg;
}

if ( isEditorAction($action) )
{
	$draftKey = "w2wiki-draft:" . SELF . (($action === 'edit') ? ":edit:$page" : ":new:$newPage");
	$html .= "<form id=\"edit\" method=\"post\" action=\"" . SELF . "\"".
		" data-draft-key=\"".h($draftKey)."\"".
		" data-msg-restored=\"".__('Restored unsaved draft from %s.')."\"".
		" data-msg-conflict=\"".__('Warning: the page was changed since this draft was started.')."\"".
		" data-msg-discard=\"".__('Discard draft')."\">\n";
	$html .= csrfField() . "\n";

	if ( $action === 'edit' )
	{
		$html .= "<input type=\"hidden\" name=\"page\" value=\"".h($page)."\" />\n";
	}
	else
	{
		if ($newPage != "" && !$triedSave)
		{
			$html .= "<div class=\"note\">". __('Creating new page since no page with given title exists!') ;
			// check if similar page exists...
			$pageNames = getAllPageNames();
			foreach($pageNames as $pageName)
			{
				if (levenshtein(strtoupper($newPage), strtoupper($pageName)) < sqrt(min(strlen($newPage), strlen($pageName))) )
				{
					$html .= "<br/><strong>".__('Note').":</strong> ".sprintf(__('Found similar page %s. Maybe you meant to edit this instead?'), pageLink($pageName, h($pageName)));
				}
			}
			$html .= "</div>\n";
		}
		$html .= "<p>" . __('Title') . ": <input id=\"title\" title=\"".__("Character restrictions: '#' and '|' have a special meaning in page links, they will therefore be removed; also, characters '~', '..', '\\', ':', '|', '&' might cause trouble in filenames and are therefore replaced by '-'.")."\" type=\"text\" name=\"page\" value=\"".h($newPage)."\" class=\"pagename\" placeholder=\"".__('Name of new page (restrictions in tip)')."\"/></p>\n";

	}

	$html .= "<p><textarea id=\"text\" name=\"newText\" rows=\"" . EDIT_ROWS . "\" autocomplete=\"off\" autocorrect=\"off\" autocapitalize=\"off\" spellcheck=\"false\">".h($text)."</textarea></p>\n";
	if (GIT_COMMIT_ENABLED)
	{
		$html .= "<p>".__('Message').": <input type=\"text\" id=\"gitmsg\" name=\"gitmsg\" value=\"".h($oldgitmsg)."\" /></p>\n";
	}

	$html .= "<p><input type=\"hidden\" name=\"action\" value=\"save\" />\n";
	$html .= "<input type=\"hidden\" name=\"isNew\" value=\"".(($action==='new')?"true":"")."\" />\n";
	$html .= '<input id="save" type="submit" value="'. __('Save') .'" />'."\n";
	$html .= '<input id="cancel" type="button" onclick="'.HANDLER_GO_BACK.'" value="'. __('Cancel') .'" />'."\n";
	$html .= "</p></form>\n";
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
		$html .= '<form id="upload" method="post" action="' . SELF . '" enctype="multipart/form-data"><p>'."\n".
			'<input type="hidden" name="action" value="uploaded" />'.csrfField().
			'<input type="hidden" name="prevpage" value="'.h($prevpage).'" />'.
			'<input type="hidden" id="overwrite" name="overwrite" value="" />'.
			'<input id="file" type="file" name="userfile" />'."\n".
			'<input id="resize" type="checkbox" checked="checked" name="resize" value="true">'.
			'<label for="resize">'.__('Shrink if larger than ').'</label>'.
			'<input id="maxsize" type="number" name="maxsize" min="20" max="8192" value="1200">'."\n".
			'<label for="maxsize" id="maxsizelabel">'.__('Pixels').'</label>'.
			'<input id="upload" type="submit" value="' . __('Upload') . '" />'.
			"\n</p></form>\n";
		$html .= '<script type="application/javascript" nonce="'.cspNonce().'">'."\n".
			'function processForm(e) {'."\n".
			'    e.preventDefault();'."\n".
			'    var fileInput = document.getElementById("file");'."\n".
			'    if (fileInput.files.length == 0) { alert('.__js('No file selected!').'); return; }'."\n".
			'    var filename = fileInput.files[0].name;'."\n".
			'    fetch("'.BASE_URI.'/api.php?task=checkupload&filename="+encodeURIComponent(filename))'."\n".
			'        .then((response) => {'."\n".
			'            response.json().then((data) => {'."\n".
			'                upload = true;'."\n".
			'                if (data) {'."\n".
			'                     upload = window.confirm('.__js('File %s already exists. Overwrite?').'.replace("%s", () => filename));'."\n".
			'                }'."\n".
			'                if (upload) {'."\n".
			'                    document.getElementById("overwrite").value = data ? "true" : "";'."\n".
			'                    var myform = document.getElementById("upload");'."\n".
			'                    myform.submit();'."\n".
			'                }'."\n".
			'            });'."\n".
			'        })'."\n".
			'        .catch((err) => { console.log(err); });'."\n".
			'}'."\n".
			'window.addEventListener("load", function(event) {'."\n".
			'    var form = document.getElementById("upload");'."\n".
			'    form.addEventListener("submit", processForm);'."\n".
			'});'."\n".
			'</script>';

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
			$text = file_get_contents(fileNameForPage($searchPage));
			foreach ($imgNames as $imgName)
			{
				$baseImgName = basename($imgName);
				if ( preg_match("@\(".preg_quote(uploadUrlPrefix().$baseImgName, '@')."[)\s]@i", $text) )
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
	if ( $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE )
	{
		$limit = ini_get('upload_max_filesize');
		redirectWithMessage(requireValidPreviousPage('prevpage'), sprintf(
			__('Upload error: the file is larger than the allowed %s (upload_max_filesize).'), h($limit)));
	}
	$tmpName = $_FILES['userfile']['tmp_name'];
	$dstName = sanitizeFilename($_FILES['userfile']['name']);
	$dstName = str_replace(" ", "_", $dstName);  // image display currently doesn't like spaces!
	// $fileType = $_FILES['userfile']['type']; // as noted in https://www.php.net/manual/en/reserved.variables.files.php, the type specified here is client-specified and thus shouldn't be trusted
	$fileType = mime_content_type($tmpName);
	$dstName = basename($dstName);
	$fileExt = getFileExt($dstName);
	$msg = '';
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
		$pathNoExt = substr($path, 0, strlen($path)-strlen($fileExt)-1);
		$finalPath = ($doProcess && $doConvert) ? ($pathNoExt.".".CONVERT_FORMAT) : $path;
		if ($doProcess)
		{
			$path = $pathNoExt . "-tmp-process." . $fileExt;
		}
		if ( file_exists($finalPath) && ($_POST['overwrite'] ?? '') !== 'true' )
		{
			$msg .= __('Upload error').": ".sprintf(__('%s already exists!'), h(basename($finalPath)));
		}
		else if ( ($svgData !== null) ? (file_put_contents($path, $svgData) !== false) : (move_uploaded_file($tmpName, $path) === true) )
		{
			$commitMsg = "File '$dstName' uploaded!";
			$msg .= sprintf(__("File '%s' uploaded!"), h($dstName))." ";
			$processFailed = false;
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
							$msg .= sprintf(__('Original size was %s, resized to %s.'), "$size[0]x$size[1]", "$newSize[0]x$newSize[1]")." ";
						}
						catch (ImagickException $e)
						{
							$msg .= __('Resizing file failed!')." ";
						}
					}
					$ori = $img->getImageOrientation();
					if($ori)
					{
						switch($ori)
						{
						case imagick::ORIENTATION_RIGHTTOP:
							$msg .= sprintf(__('Image rotated by %s°.'), '+90')." ";
							$img->rotateImage('#000',90);
							break;
						case imagick::ORIENTATION_BOTTOMRIGHT:
							$msg .= sprintf(__('Image rotated by %s°.'), '180')." ";
							$img->rotateImage('#000',180);
							break;
						case imagick:: ORIENTATION_LEFTBOTTOM:
							$msg .= sprintf(__('Image rotated by %s°.'), '-90')." ";
							$img->rotateImage('#000',-90);
							break;
//						default:
//							$msg .= "Unknown EXIF orientation specification: ".$ori.". ";
//							break;
						}
						$img->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
					}
					if ($doConvert)
					{
						$dstName = substr($dstName, 0, strlen($dstName)-strlen($fileExt)).CONVERT_FORMAT;
						$img->setImageFormat(CONVERT_FORMAT);
						$msg .= sprintf(__('Converted to format %s.'), h(CONVERT_FORMAT))." ";
					}
					$img->writeImage($finalPath);
					unlink($path);
					$img->clear();
				}
				catch (ImagickException $e)
				{
					// e.g. a corrupt image with a valid header: don't leave the half-processed upload behind
					error_log('W2: processing the upload failed: '.$e->getMessage());
					if (file_exists($path))
					{
						unlink($path);
					}
					$processFailed = true;
					$msg = __('Upload error').": ".sprintf(__('%s could not be processed (is it a valid image?)'), h($dstName));
				}
			}

			if (!$processFailed)
			{
				gitChangeHandler($commitMsg, $msg);
				$msg .= sprintf(__('Use %s to refer to it!'), "<pre>".h(imageLinkText($dstName))."</pre>");
			}
		}
		else
		{
			$error_code = $_FILES['userfile']['error'];
			if ( $error_code === 0 )
			{
				// Likely a permissions issue
				error_log("W2: can't write upload to $path");
				$msg .= __('Upload error') .": ".__("Can't write to the uploads folder")."<br/><br/>\n".
					__('Check that your permissions are set correctly.');
			}
			else
			{
				// Give generic error message
				$msg .= __('Upload error').", error #".$error_code."<br/><br/>\n".
					sprintf(__('Please see %s for more information.'), "<a href=\"https://www.php.net/manual/en/features.file-upload.errors.php\">".__('here')."</a>")."<br/><br/>\n".
					sprintf(__('If you see this message, please %s'), "<a href=\"https://github.com/codeling/w2wiki/issues\">".__('file a bug to improve w2wiki')."</a>");
			}
		}
	}
	else
	{
		$msg .= __('Upload error: invalid file type');
		error_log("Upload error: file name = $dstName, invalid file type $fileType");
	}
	$prevpage = requireValidPreviousPage('prevpage');
	redirectWithMessage($prevpage, $msg);
}
else if ( $action === 'rename' || $action === 'delete' || $action === 'imgDelete' || $action === 'imgRename')
{
	if ($action === 'imgDelete' || $action === 'imgRename' )
	{
		$page = sanitizeFilename($_REQUEST['imgName']);
	}
	$actionName = ($action === 'delete' || $action === 'imgDelete')?__('Delete'):__('Rename');
	$html .= "<form id=\"$action\" method=\"post\" action=\"" . SELF . "\">";
	$html .= csrfField();
	$html .= "<p>".$actionName." ".h($page)." ".
		(($action==='rename' || $action==='imgRename')
			? (__('to')." <input id=\"newName\" type=\"text\" name=\"newName\" value=\"" . h($page) . "\" class=\"pagename\" />")
			: "?")
		. "</p>";
	$html .= "<p><input id=\"$action\" type=\"submit\" value=\"$actionName\">";
	$html .= "<input id=\"cancel\" type=\"button\" onclick=\"".HANDLER_GO_BACK."\" value=\"".__('Cancel')."\" />\n";
	$html .= "<input type=\"hidden\" name=\"action\" value=\"{$action}d\" />";
	$html .= "<input type=\"hidden\" name=\"oldPageName\" value=\"" . h($page) . "\" />";
	if ($action === 'imgDelete' || $action === 'imgRename')
	{
		$prevpage = requireValidPreviousPage('prevpage');
		$html .= '<input type="hidden" name="prevpage" value="'.h($prevpage).'" />';
	}
	$html .= "</p></form>";
}
else if ( $action === 'renamed' || $action === 'deleted')
{
	// TODO: prevent relative filenames from being injected
	$oldPageName = sanitizeFilename($_POST['oldPageName']);
	$newPageName = ($action === 'deleted') ? "": sanitizeFilename($_POST['newName']);
	$msg = '';
	if ($action === 'deleted')
	{
		$success = isExistingPage($oldPageName) && unlink(fileNameForPage($oldPageName));
	}
	else if (!isExistingPage($oldPageName) || !isValidPageName($newPageName) || file_exists(fileNameForPage($newPageName)))
	{
		$success = false;
	}
	else
	{
		$folderName = dirname(fileNameForPage($newPageName));
		if ( !file_exists($folderName) )
		{
			mkdir($folderName, 0755, true);
		}
		$success = rename(fileNameForPage($oldPageName), fileNameForPage($newPageName));
	}
	if ($success)
	{
		$message = ($action === 'deleted')
			? (__('Removed')." ".h($oldPageName))
			: (__('Renamed')." ".h($oldPageName)." ".__('to')." ".h($newPageName));
		$msg .= $message;
		// Change links in all pages to point to new page
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = file_get_contents(fileNameForPage($replacePage));
			$count = 0;
			$newContent = preg_replace("/\[\[".preg_quote($oldPageName, '/')."([|#].*?\]\]|\]\])/",
				(($action === 'deleted') ? "" : "[[".pregReplacementQuote($newPageName)."\\1"),
				$content, -1, $count);
			if ($count > 0) // if something changed
			{
				$changedPages[] = h($replacePage)." ($count ".__('matches').")";
				file_put_contents(fileNameForPage($replacePage), $newContent);
			}
		}
		if (count($changedPages) > 0)
		{
			$msg .= "<br/>\n".__('Updated links in the following pages:')."\n<ul><li>";
			$msg .= implode("</li><li>", $changedPages);
			$msg .= "</li></ul>";
		}
		gitChangeHandler($message, $msg);
		$page = $newPageName;
	}
	else
	{
		$msg .= ($action === 'deleted')
			? (__('Error deleting file')." ".h($oldPageName))
			: (__('Error renaming file')." ".h($oldPageName)." ".__('to')." ".h($newPageName));
		$page = $oldPageName;
	}
	if ($action === 'deleted' && $success)
	{
		$page  = DEFAULT_PAGE;
	}
	redirectWithMessage($page, $msg);
}
else if ( $action === 'imgDeleted' || $action === 'imgRenamed' )
{
	$oldImgName = basename(sanitizeFilename($_POST['oldPageName']));
	$imgPath = PAGES_PATH . "/". UPLOAD_FOLDER . "/";
	$oldImgPath = $imgPath . $oldImgName;
	$newImgName = ($action === 'imgDeleted') ? "": basename(str_replace(" ", "_", sanitizeFilename($_POST['newName'])));
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
		// an image containing PHP code could be renamed to .php and executed
		$success = is_file($oldImgPath) && hasValidUploadExt($newImgName) &&
			!file_exists($imgPath.$newImgName) &&
			rename($oldImgPath, $imgPath.$newImgName);
	}

	if ($success)
	{
		$msg = ($action === 'imgDeleted')
			? (__('Image deleted').": ".h($oldImgName))
			: (__('Image renamed').": ".h($oldImgName)." ".__('to')." ".h($newImgName));
		// the commit message is plain text (the note above is HTML)
		$commitMsg = ($action === 'imgDeleted')
			? (__('Image deleted').": ".$oldImgName)
			: (__('Image renamed').": ".$oldImgName." ".__('to')." ".$newImgName);
		$changedPageNames = array();
		// Change references to image in all pages:
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = file_get_contents(fileNameForPage($replacePage));
			$count = 0;
			// matches /<UPLOAD_URL>/name, optionally prefixed with BASE_URI and followed by a "title"
			$newContent = preg_replace("/!\[(.*?)\]\(((?:".preg_quote(BASE_URI, '/').")?".preg_quote(substr(uploadUrlPrefix(), strlen(BASE_URI)), '/').")".preg_quote($oldImgName, '/')."(\s+\"[^\"]*\")?\)/",
				(($action === 'imgDeleted') ? "" : "![\\1](\\2".pregReplacementQuote($newImgName)."\\3)"),
				$content, -1, $count);
			if ($count > 0) // if something changed
			{
				$changedPages[] = h($replacePage)." ($count ".__('matches').")";
				$changedPageNames[] = $replacePage;
				file_put_contents(fileNameForPage($replacePage), $newContent);
			}
		}
		if (count($changedPages) > 0)
		{
			$commitMsg .= " (".__('Updated images in the following pages:')." ".implode(", ", $changedPageNames).")";
			$msg .= "<br/>\n".__('Updated images in the following pages:')."\n<ul><li>";
			$msg .= implode("</li><li>", $changedPages);
			$msg .= "</li></ul>";
		}
		gitChangeHandler($commitMsg, $msg);
	}
	else
	{
		$msg = ($action === 'imgDeleted')
			? (__('Error deleting image: ')." (".h($oldImgName).")")
			: (__('Error renaming image: ').h($oldImgName)." ".__('to')." ".h($newImgName));
	}
	$prevpage = requireValidPreviousPage('prevpage');
	redirectWithMessage($prevpage, $msg);
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
		$fileItem->recent = filemtime(fileNameForPage($page));
		$fileItem->size = filesize(fileNameForPage($page));
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
	$q = $_REQUEST['q'];
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
				$text = file_get_contents(fileNameForPage($searchPage));
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

$datetime = '';

if ( ($action === 'all'))
{
	$title = __("All");
}
else if ( $action === 'upload' )
{
	$title = __("Upload");
}
else if ( $action === 'new' )
{
	$title = __("New");
}
else if ( $action === 'search' )
{
	$title = __("Search");
}
else if (isset($filename) && $filename != '')
{
	$title = (($action === 'edit')? (__('Edit').": "):"") . h($page);
	$date_format = __('date_format', TITLE_DATE);
	if ( $date_format )
	{
		$datetime = "<span class=\"titledate\">" . date($date_format, @filemtime($filename)) . "</span>";
	}
}
else
{
	$title = __($action);
}

// Disable caching on the client (the iPhone is pretty agressive about this
// and it can cause problems with the editing function)
header("Cache-Control: no-store");
printHeader($title, $action);
print "    <div class=\"titlebar\"><span class=\"title\">$title</span>$datetime";
if ($action === 'view' || $action === 'rename' || $action === 'delete' || $action === 'edit')
{
	print(getPageActions($page, $action, ""));
}
print "    </div>\n";
print "    <div class=\"toolbar\">\n";
print "      <a href=\"" . SELF . "\"><img src=\"" . assetURL("w2-icons/home.svg") . "\" alt=\"". __(DEFAULT_PAGE) . "\" title=\"". __(DEFAULT_PAGE) . "\" class=\"icon\"></a>\n";
print "      <a href=\"" . SELF . "?action=all\"><img src=\"" . assetURL("w2-icons/list.svg") . "\" alt=\"". __('All') . "\" title=\"". __('All') . "\" class=\"icon\"></a>\n";
print "      <a href=\"" . SELF . "?action=new\"><img src=\"" . assetURL("w2-icons/new.svg") . "\" alt=\"".__('New')."\" title=\"".__('New')."\" class=\"icon\"></a>\n";
if ( !DISABLE_UPLOADS )
{
	$uploadPage = isset($page) ? $page : (isset($prevpage)? $prevpage : DEFAULT_PAGE);
	if ( !isExistingPage($uploadPage) )
	{
		// (the upload page only accepts existing pages to return to; e.g. not the page which is just being created)
		$uploadPage = DEFAULT_PAGE;
	}
	print "      <a href=\"" . SELF . "?action=upload&amp;page=".urlencode($uploadPage)."\"><img src=\"" . assetURL("w2-icons/upload.svg") . "\" alt=\"".__('Upload')."\" title=\"".__('Upload')."\" class=\"icon\"/></a>\n";
}
if ( REQUIRE_PASSWORD )
{
	print "      <a href=\"" . SELF . "?action=logout&amp;csrf_token=" . urlencode(csrfToken()) . "\">". __('Log out') . "</a>";
}
print "      <form method=\"post\" action=\"" . SELF . "?action=search\">\n";
print "        <input class=\"search\" placeholder=\"". __('Search') ."\" size=\"20\" id=\"search\" type=\"text\" name=\"q\" />\n      </form>\n";
if (isEditorAction($action))
{
	printDrawer();
}
print "    </div>\n";
if (SIDEBAR_PAGE != '')
{
	print "    <div class=\"sidebar\">\n\n";
	$sidebarFile = fileNameForPage(SIDEBAR_PAGE);
	if (file_exists($sidebarFile))
	{
		$text = file_get_contents($sidebarFile);
	}
	else
	{
		$text = __('Sidebar file could not be found')." (".SIDEBAR_PAGE.")";
	}
	print toHTML($text);
	print "    </div>\n";
}
if ($action === 'view' && isset($_GET['linkshere']))
{
	print "<div class=\"linkshere\">".__('What links here:')."<ul>";
	$pagenames = getAllPageNames();
	foreach($pagenames as $searchPage)
	{
		$text = file_get_contents(fileNameForPage($searchPage));
		if ( preg_match("/\[\[".preg_quote($page, '/')."/i", $text) )
		{
			$link = pageLink($searchPage, h($searchPage), "");
			print("        <li>$link</li>\n");
		}
	}
	print "</ul></div>";
}
print "    <div class=\"main\">\n\n";
if(isset($_SESSION['msg']) && $_SESSION['msg'] != '')
{
	print "      <div class=\"note\">".$_SESSION['msg']."</div>";
	unset($_SESSION['msg']);
}
print "$html\n";
print "    </div>\n";
printFooter();
