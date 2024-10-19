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

// Install PSR-4-compatible class autoloader
spl_autoload_register(function($class){
	require str_replace('\\', DIRECTORY_SEPARATOR, ltrim($class, '\\')).'.php';
});

// Get Markdown class
use Michelf\MarkdownExtra;


// User configurable options:
require_once "config.php";

// Load configured localization:
require_once 'locales/' . W2_LOCALE . '.php';

const ImageExtensions = array("bmp", "gif", "heic", "heif","jpg", "jpeg", "png", "svg", "webp");

/**
 * Get translated word
 *
 * String	$label		Key for locale word
 * String	$alt_word	Alternative word
 * return	String
 */
function __( $label, $alt_word = null )
{
	global $w2_word_set;
	if( empty($w2_word_set[$label]) )
	{
		return is_null($alt_word) ? $label : $alt_word;
	}
	return htmlspecialchars($w2_word_set[$label], ENT_QUOTES);
}

if ( REQUIRE_PASSWORD )
{
	ini_set('session.gc_maxlifetime', W2_SESSION_LIFETIME);
	session_set_cookie_params(W2_SESSION_LIFETIME);
}
session_name(W2_SESSION_NAME);
session_start();


if ( count($allowedIPs) > 0 )
{
	$ip = $_SERVER['REMOTE_ADDR'];
	$accepted = false;

	foreach ( $allowedIPs as $allowed )
	{
		if ( strncmp($allowed, $ip, strlen($allowed)) == 0 )
		{
			$accepted = true;
			break;
		}
	}

	if ( !$accepted )
	{
		print "<html><body>Access from IP address $ip is not allowed</body></html>";
		exit;
	}
}

function printHeader($title, $action, $bodyclass="")
{
	print "<!doctype html>\n";
	print "<html lang=\"" . W2_LOCALE . "\">\n";
	print "  <head>\n";
	print "    <meta charset=\"" . W2_CHARSET . "\">\n";
	print "    <link rel=\"apple-touch-icon\" href=\"/icons/w2-icon.png\"/>\n";
	print "    <link rel=\"icon\" href=\"/icons/w2-icon.png\"/>\n";
	print "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n";
	print "    <link type=\"text/css\" rel=\"stylesheet\" href=\"" . BASE_URI . "/" . CSS_FILE ."\" />\n";
	print "    <title>".PAGE_TITLE."$title</title>\n";
	if ($action === 'edit')
	{
		print "    <script src=\"wiki.js\"></script>\n";
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
		"        <a href=\"\" onclick=\"toggleDrawer(); return false;\"><img src=\"/icons/close.svg\" alt=\"".__('Close')."\" title=\"".__('Close')."\" class=\"icon rightaligned\"/></a>\n".
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
		"_".__('Emphasize')."* - <em>".__('Emphasize')."</em><br/>".
		"**".__('Bold')."** - <strong>".__('Bold')."</strong><br/>".
		"__".__('Bold')."__ - <strong>".__('Bold')."</strong><br/>".
		"<br/>".
		"[[Link to page]]<br/>".
		"&lt;http://example.com/&gt;<br/>".
		"[link text](http://url)<br/><br/>".
		"{{image.jpg}}<br/>".
		"![Alt text](/images/image.jpg)<br/>".
		"![Alt text](/images/image.jpg \"Optional title\")<br/>".
		"<br/>".
		"- Unordered list<br/>".
		"+ Unordered list<br/>".
		"* Unordered list<br/>".
		"1. Ordered list<br/>".
		"<br/>".
		"> Blockquote<br/>".// <blockquote>Blockquotes</blockquote>\n".
		"```Code```<br/>". //<pre>Code</pre>\n\n".
		"`inline-code`<br/><br/>".
		"*** Horizontal rule<br/>".
		"--- Horizontal rule<br/>\n".
		"        </div>\n".
		"      </div>\n".
		"      <a id=\"drawer-control\" href=\"\" onclick=\"toggleDrawer(); return false;\">\n".
		"        <span class=\"icongroup\">\n".
		"          <img src=\"/icons/format-text-bold.svg\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"          <img src=\"/icons/format-text-italic.svg\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"          <img src=\"/icons/format-text-code.svg\" alt=\"".__('Formatting help')."\" title=\"".__('Formatting help')."\" class=\"icon\"/>\n".
		"        </span>\n".
		"      </a>\n";
}

if ( REQUIRE_PASSWORD && !isset($_SESSION['password']) )
{
	if ( !defined('W2_PASSWORD_HASH') || W2_PASSWORD_HASH == '' )
		define('W2_PASSWORD_HASH', sha1(W2_PASSWORD));

	if ( (isset($_POST['p'])) && (sha1($_POST['p']) == W2_PASSWORD_HASH) )
		$_SESSION['password'] = W2_PASSWORD_HASH;
	else
	{
		printHeader( __('Log In'), '', "login");
		print "    <h1>" . __('Log In') . "</h1>\n";
		print "    <form method=\"post\">\n";
		print "      ".__('Password') . ": <input type=\"password\" name=\"p\">\n";
		print "      <input type=\"submit\" value=\"" . __('Log In') . "\">\n";
		print "    </form>\n";
		printFooter();
		exit;
	}
}

// Support functions

function descLengthSort($val_1, $val_2)
{
	$firstVal = strlen($val_1);
	$secondVal = strlen($val_2);
	return ( $firstVal > $secondVal ) ?
		-1 : ( ( $firstVal < $secondVal ) ? 1 : 0);
}

function getAllPageNames($path = "")
{
	$filenames = array();
	$dir = opendir(PAGES_PATH . "/$path" );
	while ( $filename = readdir($dir) )
	{
		if ( $filename === "." || $filename === ".." )
		{
			continue;
		}
		if ( is_dir( PAGES_PATH . "/$path/$filename" ) )
		{
			array_push($filenames, ...getAllPageNames( "$path/$filename" ) );
			continue;
		}
		if ( preg_match("/".PAGES_EXT."$/", $filename) != 1)
		{
			continue;
		}
		$filename = substr($filename, 0, -(strlen(PAGES_EXT)+1) );
		$filenames[] = substr("$path/$filename", 1);
	}
	closedir($dir);
	return $filenames;
}

function fileNameForPage($page)
{
	return PAGES_PATH . "/$page." . PAGES_EXT;
}

function imageLinkText($imgName)
{
	return "![".__("Image Description")."](".BASE_URI."/".UPLOAD_FOLDER."/$imgName)";
}

function sanitizeFilename($inFileName)
{
	return str_replace(array('~', '..', '\\', ':', '|', '&'), '-', $inFileName);
}

function pageURL($page)
{
	return SELF . VIEW . "/".str_replace("%2F", "/", str_replace("%23", "#", urlencode(sanitizeFilename($page))));
}

function pageLink($page, $title, $attributes="")
{
	return "<a href=\"" . pageURL($page) ."\"$attributes>$title</a>";
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
		$msg .= "<br/>Error executing command ".$cmd." (return value: ".$returnValue."): ".implode(" ", $output);
	}
	return ($returnValue == 0);
}

function gitChangeHandler($commitmsg, &$msg)
{
	if (!GIT_COMMIT_ENABLED)
	{
		return;
	}
	if (checkedExecute($msg, "cd ".PAGES_PATH." && git add -A && git commit -m ".escapeshellarg($commitmsg)))
	{
		if (!GIT_PUSH_ENABLED)
		{
			return;
		}
		checkedExecute($msg, "cd ".PAGES_PATH." && git push");
	}
}

function toHTMLID($noid)
{	// in HTML5, only spaces aren't allowed
	return str_replace(" ", "-", strip_tags($noid));
}

function toHTML($inText)
{
	$parser = new MarkdownExtra;
	$parser->no_markup = true;
	$outHTML  = $parser->transform($inText);
	if ( AUTOLINK_PAGE_TITLES )
	{
		$pagenames = getAllPageNames();
		uasort($pagenames, "descLengthSort");
		foreach ( $pagenames as $pageName )
		{
			// match pageName, but only if it isn't inside another word or inside braces (as in "[$pageName]").
			$outHTML = preg_replace("/(?<![\[a-zA-Z])$pageName(?![\]a-zA-Z])/i", "[[$pageName]]", $outHTML);
		}
	}
	preg_match_all(
		"/\[\[(.*?)\]\]/",
		$outHTML,
		$matches,
		PREG_PATTERN_ORDER
	);
	for ($i = 0; $i < count($matches[0]); $i++)
	{
		$fullLinkText = $matches[1][$i];
		$linkTitleSplit = explode('|', $fullLinkText);
		$linkedPage = $linkTitleSplit[0];    // split away potential link text
		$linkText = (count($linkTitleSplit) > 1) ? $linkTitleSplit[1] : $linkedPage;
		$pagePart = explode('#', $linkedPage)[0];  // split away a potential anchor part
		$linkedFilename = fileNameForPage(sanitizeFilename($pagePart));
		$exists = file_exists($linkedFilename);
		$outHTML = str_replace("[[$fullLinkText]]",
			pageLink($linkedPage, $linkText, ($exists? "" : " class=\"noexist\"")), $outHTML);
	}
	$outHTML = preg_replace("/\{\{(.*?)\}\}/", "<img src=\"" . BASE_URI . "/images/\\1\" alt=\"\\1\" />", $outHTML);

	// add an anchor in all title tags (h1/2/3/4):
	preg_match_all(
		"/<h([1-4])>(.*?)<\/h\\1>/",
		$outHTML,
		$matches,
		PREG_PATTERN_ORDER
	);
	for ($i = 0; $i < count($matches[0]); $i++)
	{
		$prefix = "<h".$matches[1][$i].">";
		$caption = $matches[2][$i];
		$suffix = substr_replace($prefix, "/", 1, 0);
		$outHTML = str_replace("$prefix$caption$suffix",
			"$prefix<a id=\"".toHTMLID($caption)."\">$caption</a>$suffix", $outHTML);
	}
	return $outHTML;
}

function destroy_session()
{
	if ( isset($_COOKIE[session_name()]) )
	{
		setcookie(session_name(), '', time() - 42000, '/');
	}
	session_destroy();
	unset($_SESSION["password"]);
	unset($_SESSION);
}

function getPageActions($page, $action, $imgSuffix)
{
	$pageActions = array('edit', 'delete', 'rename');
	$pageActionNames = array(__('Edit'), __('Delete'), __('Rename'));
	$result = '';
	for ($i = 0; $i < count($pageActions); $i++ )
	{
		if ($action != $pageActions[$i])
		{
			$result .= "      <a href=\"".SELF."?action=".$pageActions[$i].
				"&amp;page=".urlencode($page)."\"><img src=\"/icons/".$pageActions[$i].$imgSuffix.".svg\" alt=\"".$pageActionNames[$i]."\" title=\"".$pageActionNames[$i]."\" class=\"icon\"></a>\n";
		}
	}
	$result .= "      <a href=\"" . SELF . "?action=view&amp;page=".urlencode($page)."&linkshere=true\"><img src=\"/icons/link".$imgSuffix.".svg\" alt=\"".__('Show links here')."\" title=\"".__('Show links here')."\" class=\"icon\"/></a>\n";
	return $result;
}

// Main code

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';
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
	$page = sanitizeFilename(urldecode($page));
	if ( $page == "" )
	{
		$page = DEFAULT_PAGE;
	}
	$filename = fileNameForPage($page);
}
if ($action === 'view' || $action === 'edit')
{
	if ( file_exists($filename) )
	{
		$text = file_get_contents($filename);
	}
	else
	{
		$newPage = $page;
		$action = 'new';
	}
}
$oldgitmsg = "";
$triedSave = false;
if ( $action == 'save' )
{
	$msg = '';
	$newText = $_REQUEST['newText'];
	$isNew = $_REQUEST['isNew'];
	if ($isNew)
	{
		$page = str_replace(array('|','#'), '', $page);
		$filename = fileNameForPage($page);
	}
	if ($isNew && file_exists($filename))
	{
		$msg .= "Error creating page '$page' - it already exists! Please choose a different name, or <a href=\"?action=edit&amp;page=".urlencode($page)."\">edit</a> the existing page (this discards current text!)!</div>\n";
		$action = 'new';
		$text = $newText;
		$newPage = $page;
		if (GIT_COMMIT_ENABLED)
		{
			$oldgitmsg = $_REQUEST['gitmsg'];
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
			$msg .= "Error saving changes! Make sure your web server has write access to " . PAGES_PATH . "\n";
			$action = ($isNew ? 'new' : 'edit');
			$text = $newText;
			$newPage = $page;
			if (GIT_COMMIT_ENABLED)
			{
				$oldgitmsg = $_REQUEST['gitmsg'];
			}
			$triedSave = true;
		}
		else
		{
			$msg .= ($isNew ? __('Created'): __('Saved'));
			$usermsg = $_REQUEST['gitmsg'];
			$commitmsg = $page . ($usermsg !== '' ?  (": ".$usermsg) : ($isNew ? " created" : " changed"));
			gitChangeHandler($commitmsg, $msg);
		}
	}
	redirectWithMessage($page, $msg);
}

if ( $action === 'edit' || $action === 'new' )
{
	$formAction = SELF . (($action === 'edit') ? "/$page" : "");
	$html .= "<form id=\"edit\" method=\"post\" action=\"$formAction\">\n";

	if ( $action === 'edit' )
	{
		$html .= "<input type=\"hidden\" name=\"page\" value=\"$page\" />\n";
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
					$html .= "<br/><strong>Note:</strong> Found similar page ".pageLink($pageName, $pageName).". Maybe you meant to edit this instead?";
				}
			}
			$html .= "</div>\n";
		}
		$html .= "<p>" . __('Title') . ": <input id=\"title\" title=\"".__("Character restrictions: '#' and '|' have a special meaning in page links, they will therefore be removed; also, characters '~', '..', '\\', ':', '|', '&' might cause trouble in filenames and are therefore replaced by '-'.")."\" type=\"text\" name=\"page\" value=\"$newPage\" class=\"pagename\" placeholder=\"".__('Name of new page (restrictions in tip)')."\"/></p>\n";

	}

	$html .= "<p><textarea id=\"text\" name=\"newText\" rows=\"" . EDIT_ROWS . "\" autocomplete=\"off\" autocorrect=\"off\" autocapitalize=\"off\" spellcheck=\"false\">$text</textarea></p>\n";
	if (GIT_COMMIT_ENABLED)
	{
		$html .= "<p>Message: <input type=\"text\" id=\"gitmsg\" name=\"gitmsg\" value=\"$oldgitmsg\" /></p>\n";
	}

	$html .= "<p><input type=\"hidden\" name=\"action\" value=\"save\" />\n";
	$html .= "<input type=\"hidden\" name=\"isNew\" value=\"".(($action==='new')?"true":"")."\" />\n";
	$html .= '<input id="save" type="submit" value="'. __('Save') .'" />'."\n";
	$html .= '<input id="cancel" type="button" onclick="history.go(-1);" value="'. __('Cancel') .'" />'."\n";
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
	$prevpage = isset($_REQUEST['page']) ? urldecode(@$_REQUEST['page']) : DEFAULT_PAGE;
	if ( DISABLE_UPLOADS )
	{
		$html .= '<p>' . __('Image uploading has been disabled on this installation.') . '</p>';
	}
	else
	{
		$html .= '<form id="upload" method="post" action="' . SELF . '" enctype="multipart/form-data"><p>'."\n".
			'<input type="hidden" name="action" value="uploaded" />'.
			'<input type="hidden" name="prevpage" value="'.$prevpage.'" />'.
			'<input id="file" type="file" name="userfile" />'."\n".
			'<input id="resize" type="checkbox" checked="checked" name="resize" value="true">'.
			'<label for="resize">'.__('Shrink if larger than ').'</label>'.
			'<input id="maxsize" type="number" name="maxsize" min="20" max="8192" value="1200">'."\n".
			'<label for="maxsize" id="maxsizelabel">'.__('Pixels').'</label>'.
			'<input id="upload" type="submit" value="' . __('Upload') . '" />'.
			"\n</p></form>\n";
		$html .= '<script type="application/javascript">'."\n".
			'function processForm(e) {'."\n".
			'    e.preventDefault();'."\n".
			'    var fileInput = document.getElementById("file");'."\n".
			'    if (fileInput.files.length == 0) { alert("No file selected!"); return; }'."\n".
			'    var filename = fileInput.files[0].name;'."\n".
			'    fetch("/api.php?task=checkupload&filename="+filename)'."\n".
			'        .then((response) => {'."\n".
			'            response.json().then((data) => {'."\n".
			'                upload = true;'."\n".
			'                if (data) {'."\n".
			'                     upload = window.confirm("File "+filename+" already exists. Overwrite?");'."\n".
			'                }'."\n".
			'                if (upload) {'."\n".
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
	natcasesort($imgNames);
	$html .= "<p>".__('Total').": ".count($imgNames)." ".__('images')."</p>";
	$imgPages = array();
	if (SHOW_PAGES_WHERE_FILE_USED)
		$pagenames = getAllPageNames();
		foreach($pagenames as $searchPage)
		{
			$text = file_get_contents(fileNameForPage($searchPage));
			foreach ($imgNames as $imgName)
			{
				$baseImgName = basename($imgName);
				if ( preg_match("@\(/images/".preg_quote($baseImgName)."@i", $text) )
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


	$html .= "<table><thead>";
	$html .= "<tr>".
/*
		"<td>".(($sortBy!='name')?("<a href=\"".SELF."?action=all&sortBy=name\">Name</a>"):"<span class=\"sortBy\">Name</span>")."</td>".
		"<td>".(($sortBy!='recent')?("<a href=\"".SELF."?action=all&sortBy=recent\">Modified</a>"):"<span class=\"sortBy\">Modified</span>")."</td>".
 */		"<th>".__("Name")."</th><th>".__("Usage")."</th><th>".__("Modified")."</th><th>".__("Action")."</th>";
	if (SHOW_PAGES_WHERE_FILE_USED)
	{
		$html .=  "<th>".__("Used on page")."</th>";
	}
	$html .= "</tr></thead><tbody>";
	$date_format = __('date_format', TITLE_DATE);

	foreach ($imgNames as $imgName)
	{
		$baseImgName = basename($imgName);
		$isImg = false;
		foreach(ImageExtensions as $ext)
		{
			if (str_ends_with($baseImgName, $ext))
			{
				$isImg = true;
			}
		}
		$html .= "<tr>".
			"<td>".($isImg?"<img class=\"thumbImg\" src=\"".BASE_URI."/".UPLOAD_FOLDER."/".$baseImgName."\" />":"<span class=\"thumbPlaceHolder\"></span>")."<span class=\"uploadFileName\">".$baseImgName."</span></td>".
			"<td><pre>".imageLinkText($baseImgName)."</pre></td>".
			"<td><nobr>".date($date_format, filemtime($imgName))."</nobr></td>".
			"<td>".
			    "<a href=\"".SELF."?action=imgRename&amp;prevpage=".urlencode($prevpage)."&amp;imgName=".urlencode($baseImgName)."\"><img src=\"/icons/rename-dark.svg\" alt=\"".__('Rename')."\" title=\"".__('Rename')."\" class=\"icon\"/></a>".
			    "<a href=\"".SELF."?action=imgDelete&amp;prevpage=".urlencode($prevpage)."&amp;imgName=".urlencode($baseImgName)."\"><img src=\"/icons/delete.svg\" alt=\"".__('Delete')."\" title=\"".__('Delete')."\" class=\"icon\"/></a></td>";
		if (SHOW_PAGES_WHERE_FILE_USED)
		{
			$html .= "<td>";
			if (array_key_exists($imgName, $imgPages))
			{
				foreach($imgPages[$imgName] as $page)
				{
					$html .= pageLink($page, $page);
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
		die('Invalid access. Uploads are disabled in the configuration.');
	}
	$tmpName = $_FILES['userfile']['tmp_name'];
	$dstName = sanitizeFilename($_FILES['userfile']['name']);
	$dstName = str_replace(" ", "_", $dstName);  // image display currently doesn't like spaces!
	// $fileType = $_FILES['userfile']['type']; // as noted in https://www.php.net/manual/en/reserved.variables.files.php, the type specified here is client-specified and thus shouldn't be trusted
	$fileType = mime_content_type($tmpName);
	preg_match('/\.([^.]+)$/', $dstName, $matches);
	$fileExt = isset($matches[1]) ? strtolower($matches[1]) : null;
	$msg = '';
	if (in_array($fileType, explode(',', VALID_UPLOAD_TYPES)) &&
	    in_array($fileExt, explode(',', VALID_UPLOAD_EXTS)))
	{
		$path = PAGES_PATH . "/". UPLOAD_FOLDER . "/$dstName";
		$doResize = isset($_POST['resize']) && $_POST['resize'] === 'true';
		$doConvert = in_array($fileExt, explode(',', IMAGE_EXTS_TO_CONVERT));
		$doProcess = in_array($fileExt, ImageExtensions) && ($doConvert || $doResize);
		$pathNoExt = substr($path, 0, strlen($path)-strlen($fileExt)-1);
		if ($doProcess)
		{
			$finalPath = $doConvert ? ($pathNoExt.".".CONVERT_FORMAT) : $path;
			$path = $pathNoExt . "-tmp-process." . $fileExt;
		}
		if ( move_uploaded_file($tmpName, $path) === true )
		{
			$commitMsg = "File '$dstName' uploaded!";
			$msg .= $commitMsg." ";
			if ($doProcess)
			{
				$img = new Imagick($path);
				if ($doResize)
				{
					$size = array($img->getImageWidth(), $img->getImageHeight());
					$maxsize = intval($_POST['maxsize']);
					$doResize = ($size[0] > $maxsize || $size[1] > $maxsize);
				}
				if ($doResize)
				{
					$newSize = array(0, 0);
					$idx0 = ($size[0] > $size[1]) ? 0 : 1;
					$idx1 = ($idx0 == 0) ? 1 : 0;
					$newSize[$idx0] = $maxsize;
					$newSize[$idx1] = (int)round($size[$idx1] * $maxsize / $size[$idx0]);
					if (!$img->resizeImage($newSize[0], $newSize[1], imagick::FILTER_LANCZOS, 1))
					{
						$msg .= "Original size was $size[0]x$size[1], resized to $newSize[0]x$newSize[1]. ";
					}
					else
					{
						$msg .= "Resizing file failed! ";
					}
				}
				$ori = $img->getImageOrientation();
				error_log("orientation: $ori");
				if($ori)
				{
					switch($ori)
					{
					case imagick::ORIENTATION_RIGHTTOP:
						$msg .= "Image rotated by +90°. ";
						$img->rotateImage('#000',90);
						break;
					case imagick::ORIENTATION_BOTTOMRIGHT:
						$msg .= "Image rotated by 180°. ";
						$img->rotateImage('#000',180);
						break;
					case imagick:: ORIENTATION_LEFTBOTTOM:
						$msg .= "Image rotated by -90°. ";
						$img->rotateImage('#000',-90);
						break;
//					default:
//						$msg .= "Unknown EXIF orientation specification: ".$ori.". ";
//						break;
					}
					$img->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
				}
				if ($doConvert)
				{
					$dstName = substr($dstName, 0, strlen($dstName)-strlen($fileExt)).CONVERT_FORMAT;
					$img->setImageFormat(CONVERT_FORMAT);
					$msg .= "Converted to format ".CONVERT_FORMAT.". ";
				}
				$img->writeImage($finalPath);
				unlink($path);
				$img->clear();
			}
			gitChangeHandler($commitMsg, $msg);
			$msg .= "Use <pre>".imageLinkText($dstName)."</pre> to refer to it!";
		}
		else
		{
			$error_code = $_FILES['userfile']['error'];
			if ( $error_code === 0 )
			{
				// Likely a permissions issue
				$msg .= __('Upload error') .": Can't write to ".$path."<br/><br/>\n".
					"Check that your permissions are set correctly.";
			}
			else
			{
				// Give generic error message
				$msg .= __('Upload error').", error #".$error_code."<br/><br/>\n".
					"Please see <a href=\"https://www.php.net/manual/en/features.file-upload.errors.php\">here</a> for more information.<br/><br/>\n".
					"If you see this message, please <a href=\"https://github.com/codeling/w2wiki/issues\">file a bug to improve w2wiki</a>";
			}
		}
	}
	else
	{
		$msg .= __('Upload error: invalid file type');
		error_log("Upload error: file name = $dstName, invalid file type $fileType");
	}
	$prevpage = isset($_REQUEST['prevpage']) ? $_REQUEST['prevpage'] : DEFAULT_PAGE;
	redirectWithMessage($prevpage, $msg);
}
else if ( $action === 'rename' || $action === 'delete' || $action === 'imgDelete' || $action === 'imgRename')
{
	if ($action === 'imgDelete' || $action === 'imgRename' )
	{
		$page = sanitizeFilename(urldecode($_REQUEST['imgName']));
	}
	$actionName = ($action === 'delete' || $action === 'imgDelete')?__('Delete'):__('Rename');
	$html .= "<form id=\"$action\" method=\"post\" action=\"" . SELF . "\">";
	$html .= "<p>".$actionName." $page ".
		(($action==='rename' || $action==='imgRename')
			? (__('to')." <input id=\"newName\" type=\"text\" name=\"newName\" value=\"" . htmlspecialchars($page) . "\" class=\"pagename\" />")
			: "?")
		. "</p>";
	$html .= "<p><input id=\"$action\" type=\"submit\" value=\"$actionName\">";
	$html .= "<input id=\"cancel\" type=\"button\" onclick=\"history.go(-1);\" value=\"Cancel\" />\n";
	$html .= "<input type=\"hidden\" name=\"action\" value=\"${action}d\" />";
	$html .= "<input type=\"hidden\" name=\"oldPageName\" value=\"" . htmlspecialchars($page) . "\" />";
	if ($action === 'imgDelete' || $action === 'imgRename')
	{
		$prevpage = isset($_REQUEST['prevpage']) ? urldecode(@$_REQUEST['prevpage']) : DEFAULT_PAGE;
		$html .= '<input type="hidden" name="prevpage" value="'.$prevpage.'" />';
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
		$success = unlink(fileNameForPage($oldPageName));
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
			? (__('Removed')." ".$oldPageName)
			: (__('Renamed')." ".$oldPageName." ".__('to')." ".$newPageName);
		$msg .= $message;
		// Change links in all pages to point to new page
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = file_get_contents(fileNameForPage($replacePage));
			$count = 0;
			$regexSaveOldPageName = str_replace("/", "\\/", $oldPageName);
			$newContent = preg_replace("/\[\[$regexSaveOldPageName([|#].*\]\]|\]\])/",
				(($action === 'deleted') ? "" : "[[$newPageName\\1"),
				$content, -1, $count);
			if ($count > 0) // if something changed
			{
				$changedPages[] = $replacePage." ($count ".__('matches').")";
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
			? (__('Error deleting file')." ".$oldPageName)
			: (__('Error renaming file')." ".$oldPageName." ".__('to')." ".$newPageName);
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
	// TODO: prevent relative filenames from being injected
	$oldImgName = sanitizeFilename($_REQUEST['oldPageName']);
	$imgPath = PAGES_PATH . "/". UPLOAD_FOLDER . "/";
	$oldImgPath = $imgPath . $oldImgName;
	$newImgName = ($action === 'imgDeleted') ? "": sanitizeFilename($_POST['newName']);
	if ($action == 'imgDeleted')
	{
		$success = unlink($oldImgPath);
	}
	else
	{
		$success = rename($oldImgPath, $imgPath.$newImgName);
	}

	if ($success)
	{
		$msg = ($action === 'imgDeleted')
			? (__('Image deleted').": ".$oldImgPath)
			: (__('Image renamed').": ".$oldImgName." ".__('to')." ".$newImgName);
		// Change references to image in all pages:
		$pagenames = getAllPageNames();
		$changedPages = array();
		foreach ($pagenames as $replacePage)
		{
			$content = file_get_contents(fileNameForPage($replacePage));
			$count = 0;
			$newContent = preg_replace("/!\[(.*?)\]\(\/images\/$oldImgName\)/",   // escape / because it is used as delimiter
				(($action === 'imgDeleted') ? "" : "![\\1](/images/$newImgName)"),
				$content, -1, $count);
			if ($count > 0) // if something changed
			{
				$changedPages[] = $replacePage." ($count ".__('matches').")";
				file_put_contents(fileNameForPage($replacePage), $newContent);
			}
		}
		if (count($changedPages) > 0)
		{
			$msg .= "<br/>\n".__('Updated images in the following pages:')."\n<ul><li>";
			$msg .= implode("</li><li>", $changedPages);
			$msg .= "</li></ul>";
		}
		gitChangeHandler($msg, $msg);
	}
	else
	{
		$msg = ($action === 'imgDeleted')
			? (__('Error deleting image: ')." (".$oldImgName.")")
			: (__('Error renaming image: ').$oldImgName." ".__('to')." ".$newImgName);
	}
	$prevpage = isset($_REQUEST['prevpage']) ? $_REQUEST['prevpage'] : DEFAULT_PAGE;
	redirectWithMessage($prevpage, $msg);
}
else if ( $action === 'all' )
{
	$pageNames = getAllPageNames();
	$filelist = array();
	$sortBy = isset($_REQUEST['sortBy']) ? $_REQUEST['sortBy'] : 'name';
	if (!in_array($sortBy, array('name', 'recent')))
	{
		$sortBy = 'name';
	}
	if ($sortBy === 'name')
	{
		natcasesort($pageNames);
		foreach($pageNames as $page)
		{
			$filelist[$page] = filemtime(fileNameForPage($page));
		}
	}
	else
	{
		foreach($pageNames as $page)
		{
			$filelist[$page] = filemtime(fileNameForPage($page));
		}
		arsort($filelist, SORT_NUMERIC);
	}
	$html .= "<p>".__('Total').": ".count($pageNames)." ".__("pages")."</p>";
	$html .= "<table><thead>";
	$html .= "<tr>".
		"<td>".(($sortBy!='name')?("<a href=\"".SELF."?action=all&sortBy=name\">Name</a>"):"<span class=\"sortBy\">".__('Name')."</span>")."</td>".
		"<td>".(($sortBy!='recent')?("<a href=\"".SELF."?action=all&sortBy=recent\">".__('Modified')."</a>"):"<span class=\"sortBy\">".__('Modified')."</span>")."</td>".
		"<td>".__('Action')."</td>".
		"</tr></thead><tbody>";
	$date_format = __('date_format', TITLE_DATE);

	foreach ($filelist as $pageName => $pageDate)
	{
		$html .= "<tr>".
			"<td>".pageLink($pageName, $pageName)."</td>".
			"<td valign=\"top\"><nobr>".date( $date_format, $pageDate)."</nobr></td>".
				"<td class=\"pageActions\">".getPageActions($pageName, $action,"-dark")."</td>".
				"</tr>\n";
	}
	$html .= "</tbody></table>\n";
}
else if ( $action === 'search' )
{
	$matches = 0;
	$q = $_REQUEST['q'];
	$html .= "    <h1>Search: $q</h1>\n";

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
			if (preg_match("@$q@i", $searchPage))
			{
				array_unshift($matchingPages, $searchPage);
				++$matches;
			}
			else
			{
				$text = file_get_contents(fileNameForPage($searchPage));
				if ( preg_match("@$q@i", $text) )
				{
					$matchingPages[] = $searchPage;
					++$matches;
				}
			}
		}
		foreach ($matchingPages as $page)
		{
			$link = pageLink($page, $page, (strcasecmp($page, $q) == 0)? " class=\"literalMatch\"": "");
			$html .= "        <li>$link</li>\n";
		}
		if (!$found)
		{
			$html .= "        <li>".pageLink($q, __('Create page')." '$q'", " class=\"noexist\"")."</li>";
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
	$title = (($action === 'edit')? (__('Edit').": "):"") . $page;
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
print "      <a href=\"" . SELF . "\"><img src=\"/icons/home.svg\" alt=\"". __(DEFAULT_PAGE) . "\" title=\"". __(DEFAULT_PAGE) . "\" class=\"icon\"></a>\n";
print "      <a href=\"" . SELF . "?action=all\"><img src=\"/icons/list.svg\" alt=\"". __('All') . "\" title=\"". __('All') . "\" class=\"icon\"></a>\n";
print "      <a href=\"" . SELF . "?action=new\"><img src=\"/icons/new.svg\" alt=\"".__('New')."\" title=\"".__('New')."\" class=\"icon\"></a>\n";
if ( !DISABLE_UPLOADS )
{
	$uploadPage = isset($page) ? $page : (isset($prevpage)? $prevpage : DEFAULT_PAGE);
	print "      <a href=\"" . SELF . VIEW . "?action=upload&amp;page=".urlencode($uploadPage)."\"><img src=\"/icons/upload.svg\" alt=\"".__('Upload')."\" title=\"".__('Upload')."\" class=\"icon\"/></a>\n";
}
if ( REQUIRE_PASSWORD )
{
	print "      <a href=\"" . SELF . "?action=logout\">". __('Log out') . "</a>";
}
print "      <form method=\"post\" action=\"" . SELF . "?action=search\">\n";
print "        <input class=\"search\" placeholder=\"". __('Search') ."\" size=\"20\" id=\"search\" type=\"text\" name=\"q\" />\n      </form>\n";
if ($action === 'edit')
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
		$text = __('Sidebar file could not be found')." ($sidebarFile)";
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
		$regexSavePage = str_replace("/", "\\/", $page);
		if ( preg_match("/\[\[$regexSavePage/i", $text) )
		{
			$link = pageLink($searchPage, $searchPage, "");
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
