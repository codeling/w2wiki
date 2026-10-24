<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Helper functions without side effects (nothing happens when this file is
 * loaded; the functions use the constants defined in config.php when called).
 */

use Michelf\MarkdownExtra;

// Optional libraries installed via Composer (see INSTALL.md)
if ( is_file(__DIR__ . '/vendor/autoload.php') )
{
	require_once __DIR__ . '/vendor/autoload.php';
}

// Install PSR-4-compatible class autoloader
spl_autoload_register(function($class){
	// the classes of W2 (namespace W2) are in the folder src; others (like Michelf\Markdown) in the folder of their namespace
	$class = ltrim($class, '\\');
	$path = str_starts_with($class, 'W2\\') ? 'src' . DIRECTORY_SEPARATOR . substr($class, 3) : $class;
	$file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $path) . '.php';
	// don't fail fatally for unknown classes, so class_exists() can be used to probe for optional ones
	if ( is_file($file) )
	{
		require $file;
	}
});

const MAX_SVG_UPLOAD_SIZE = 1048576;

/**
 * Escape a string for safe output in HTML text and attribute values
 */
function h($str)
{
	return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, W2_CHARSET);
}

/**
 * Get translated word, as plain text (not escaped: for text which is escaped later, like the parts of a
 * W2\Note; use __() for text which is put in HTML as it is)
 *
 * String	$label		Key for locale word
 * String	$alt_word	Alternative word
 * return	String
 */
function translate( $label, $alt_word = null )
{
	global $w2_word_set;
	if( empty($w2_word_set[$label]) )
	{
		return is_null($alt_word) ? $label : $alt_word;
	}
	return $w2_word_set[$label];
}

/**
 * Get translated word, escaped for use in HTML
 *
 * String	$label		Key for locale word
 * String	$alt_word	Alternative word
 * return	String
 */
function __( $label, $alt_word = null )
{
	return h(translate($label, $alt_word));
}

/**
 * Get translated word as a JavaScript string literal (with quotes), for inline scripts
 *
 * String	$label		Key for locale word
 * return	String
 */
function __js( $label )
{
	return json_encode(html_entity_decode(__($label), ENT_QUOTES | ENT_HTML5, W2_CHARSET), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function descLengthSort($val_1, $val_2)
{
	$firstVal = strlen($val_1);
	$secondVal = strlen($val_2);
	return ( $firstVal > $secondVal ) ?
		-1 : ( ( $firstVal < $secondVal ) ? 1 : 0);
}

/**
 * The pages of the wiki (see W2\PageStore, which does the work)
 */
function pageStore()
{
	static $store = null;
	return $store ??= new W2\PageStore(PAGES_PATH, PAGES_EXT, UPLOAD_FOLDER);
}

function getAllPageNames()
{
	return pageStore()->names();
}

/**
 * The templates for the HTML of the pages (see W2\Template)
 */
function templates()
{
	static $templates = null;
	return $templates ??= new W2\Template(__DIR__ . '/src/templates');
}

/**
 * The references to uploaded files in the text of pages (see W2\UploadReferences)
 */
function uploadReferences()
{
	static $references = null;
	return $references ??= new W2\UploadReferences(BASE_URI, UPLOAD_URL);
}

/**
 * The text for a log entry without control characters (like line breaks, which could be used to forge
 * entries of the log) and limited in length; control characters are shown as \xNN
 */
function logText($text)
{
	$text = preg_replace_callback('/[\x00-\x1f\x7f]/', fn($m) => sprintf('\\x%02x', ord($m[0])), (string)$text);
	return strlen($text) > 2000 ? substr($text, 0, 2000) . '...' : $text;
}

/**
 * Write to the error log of the web server, with the prefix "W2: " (values from requests, file names and
 * output of programs must be part of the message only via this function, so that they cannot add entries
 * of their own)
 */
function w2Log($message)
{
	error_log('W2: ' . logText($message));
}

/**
 * Whether the request contains data the wiki cannot handle: the parameters it reads are single text values
 * (not arrays like "page[]=x") without null bytes (which file functions refuse with an error), the same
 * holds for the page name in the URL, and an uploaded file is a single file.
 *
 * @param array $get $_GET
 * @param array $post $_POST
 * @param array $server $_SERVER
 * @param array $files $_FILES
 */
function hasMalformedRequestData(array $get, array $post, array $server, array $files = array())
{
	$textParameters = array('action', 'page', 'q', 'sortBy', 'task', 'filename', 'newname', 'newName', 'oldPageName',
		'imgName', 'prevpage', 'csrf_token', 'newText', 'isNew', 'gitmsg', 'overwrite', 'resize', 'maxsize', 'linkshere', 'p', 'draftKey');
	foreach ( array($get, $post) as $values )
	{
		foreach ( $textParameters as $name )
		{
			if ( isset($values[$name]) && (!is_string($values[$name]) || str_contains($values[$name], "\0")) )
			{
				return true;
			}
		}
	}
	if ( str_contains((string)($server['PATH_INFO'] ?? ''), "\0") )
	{
		return true;
	}
	return isset($files['userfile']) && !is_string($files['userfile']['tmp_name'] ?? null);
}

function fileNameForPage($page)
{
	return pageStore()->path($page);
}

/**
 * Replace the content of a file (a page) in one step: the text is written to a temporary file in the same folder,
 * which then takes the place of the file. Readers (and a git command running at the same time) see either the old
 * or the new text, never half of a write, and a write which fails (e.g. a full disk) keeps the old text.
 * The permissions of an existing file are kept; if the file is a symbolic link, the file it points to is replaced.
 *
 * return bool	whether the file was written
 */
function writeFileAtomically($filename, $text)
{
	if ( is_link($filename) )
	{
		$target = realpath($filename);
		if ( $target === false )
		{
			return false;
		}
		$filename = $target;
	}
	// (a hidden name: never a page, and not listed or found while it exists)
	$temp = @tempnam(dirname($filename), '.w2-write-');
	if ( $temp === false )
	{
		return false;
	}
	$mode = is_file($filename) ? (fileperms($filename) & 0777) : (0666 & ~umask());
	if ( file_put_contents($temp, $text) !== strlen($text) || !chmod($temp, $mode) || !rename($temp, $filename) )
	{
		@unlink($temp);
		return false;
	}
	return true;
}

/**
 * Save the text of a page (see W2\PageStore::write())
 */
function writePage($page, $text)
{
	return pageStore()->write($page, $text);
}

/**
 * Whether the page of this action contains the editor (text area, save button
 * and formatting help), and needs its script wiki.js
 */
function isEditorAction($action)
{
	return $action === 'edit' || $action === 'new';
}

/**
 * Whether the sidebar is shown on the page of this action: only where the
 * wiki is read or browsed, not where a task is done in a form (editing,
 * creating, uploading, renaming or deleting), which needs the whole width
 */
function showsSidebar($action)
{
	return $action === 'view' || $action === 'all' || $action === 'search';
}

/**
 * The file used to serialize git commands in the pages folder, which also
 * holds the time of the last pull attempt (in the system's temporary folder)
 */
function gitSyncFile()
{
	return rtrim(sys_get_temp_dir(), '/\\') . '/w2-git-' . substr(hash('sha256', PAGES_PATH), 0, 16);
}

/**
 * Seconds which must pass after a pull attempt until the action may pull again:
 * GIT_PULL_VIEW_INTERVAL for viewing, GIT_PULL_EDIT_INTERVAL (at most five
 * minutes) for the editor
 */
function gitPullInterval($action)
{
	if ( isEditorAction($action) )
	{
		return max(0, min(5 * 60, (int)GIT_PULL_EDIT_INTERVAL));
	}
	return max(0, (int)GIT_PULL_VIEW_INTERVAL);
}

/**
 * The lock held by this request and how often it was taken (see gitLock())
 *
 * @return array{0: resource|null, 1: int}
 */
function &gitLockState()
{
	static $state = array(null, 0);
	return $state;
}

/**
 * Wait for the lock for git commands in the pages folder (if $wait), or
 * only take it if nobody else holds it. The lock is also held while pages
 * and uploads are changed (see gitLockForChange()), so that a pull can't
 * overwrite or conflict with a change which is not yet committed.
 *
 * A request can take the lock again while it holds it (e.g. to commit the
 * changes it has made); it is released when gitUnlock() has been called for
 * each time it was taken, or when the request ends.
 *
 * @return resource|null the lock to give to gitUnlock(), or null if it
 *                       can't be taken (the caller then goes on without)
 */
function gitLock($wait)
{
	$state = &gitLockState();
	if ( $state[0] !== null )
	{
		$state[1]++;
		return $state[0];
	}
	$file = @fopen(gitSyncFile(), 'c+');
	if ( $file === false )
	{
		w2Log("cannot use " . gitSyncFile() . " to serialize git commands");
		return null;
	}
	if ( !flock($file, $wait ? LOCK_EX : LOCK_EX | LOCK_NB) )
	{
		fclose($file);
		return null;
	}
	$state = array($file, 1);
	return $file;
}

/**
 * Release a lock from gitLock() (it is held until this has been called as often as the lock was taken)
 */
function gitUnlock($lock)
{
	$state = &gitLockState();
	if ( $lock === null || $lock !== $state[0] )
	{
		return;
	}
	if ( --$state[1] > 0 )
	{
		return;
	}
	flock($lock, LOCK_UN);
	fclose($lock);
	$state = array(null, 0);
}

/**
 * Take the lock before pages or uploads are changed, if changes are committed to git: a pull
 * running at the same time could otherwise overwrite the change, or fail because of it. The lock
 * is held until the request ends (the changes are committed before that, see gitChangeHandler()).
 *
 * @return resource|null
 */
function gitLockForChange()
{
	return GIT_COMMIT_ENABLED ? gitLock(true) : null;
}

/**
 * Whether $action should pull now: takes the lock (see gitLock()) and returns
 * it if the last attempt, remembered in the lock file, is old enough, and no
 * other request is using git. The attempt is recorded right away, so a remote
 * which does not answer costs one timeout per interval, not one per request.
 *
 * @return resource|null the lock to give to gitUnlock(), or null: don't pull
 */
function gitPullStart($action, $now)
{
	$lock = gitLock(false);
	if ( $lock === null )
	{
		return null;
	}
	$last = (int)stream_get_contents($lock);
	if ( $last > 0 && $last <= $now && $now - $last < gitPullInterval($action) )
	{
		gitUnlock($lock);
		return null;
	}
	ftruncate($lock, 0);
	rewind($lock);
	fwrite($lock, (string)$now);
	fflush($lock);
	return $lock;
}

function isExistingPage($page)
{
	return pageStore()->exists($page);
}

/**
 * URL prefix of uploaded files, including the trailing slash: BASE_URI/UPLOAD_URL/
 */
function uploadUrlPrefix()
{
	return BASE_URI . "/" . UPLOAD_URL . "/";
}

/**
 * Whether the page name is within the uploads folder (which is served statically,
 * and not part of the wiki)
 */
function isInUploadFolder($page)
{
	return explode('/', $page)[0] === UPLOAD_FOLDER;
}

/**
 * How to refer to an uploaded file in a page (plain text, not HTML: escape it where it is written)
 */
function imageLinkText($imgName)
{
	return "![".translate("Image Description")."](".uploadUrlPrefix()."$imgName)";
}

function sanitizeFilename($inFileName)
{
	return str_replace(array('~', '..', '\\', ':', '|', '&'), '-', $inFileName);
}

// escape a string for use as literal text in a preg_replace replacement
function pregReplacementQuote($str)
{
	return str_replace(array('\\', '$'), array('\\\\', '\\$'), $str);
}

/**
 * Whether a page with the given name may be created: page names may contain
 * subfolders, but no hidden or empty path segments, and pages must not be
 * stored within the (statically served) uploads folder
 */
function isValidPageName($page)
{
	return pageStore()->isValidName($page);
}

function getFileExt($fileName)
{
	return preg_match('/\.([^.\/]+)$/', $fileName, $matches) ? strtolower($matches[1]) : null;
}

/**
 * The name under which an uploaded file gets stored: the name of the uploaded file,
 * or the (optional) name the user chose instead. The chosen name keeps the extension
 * of the uploaded file, as the content must match it (so "cat" becomes "cat.jpg").
 */
function uploadTargetName($uploadedName, $chosenName = '')
{
	$clean = function($name) {
		// spaces are replaced as image display currently doesn't like them
		return basename(str_replace(" ", "_", sanitizeFilename(trim((string)$name))));
	};
	$dstName = $clean($uploadedName);
	$chosen = $clean($chosenName);
	if ( $chosen === '' )
	{
		return $dstName;
	}
	$ext = getFileExt($dstName);
	if ( $ext !== null && getFileExt($chosen) !== $ext )
	{
		$chosen .= "." . $ext;
	}
	return $chosen;
}

function isHiddenFile($fileName)
{
	return str_starts_with(basename($fileName), '.');
}

/**
 * Whether SVG uploads are enabled in the configuration, and the library
 * needed to sanitize them (enshrined/svg-sanitize) is installed
 */
function svgUploadsAvailable()
{
	return SVG_UPLOADS_ENABLED && class_exists('enshrined\\svgSanitize\\Sanitizer');
}

function validUploadTypes()
{
	return explode(',', VALID_UPLOAD_TYPES);
}

function validUploadExts()
{
	$exts = explode(',', VALID_UPLOAD_EXTS);
	if ( svgUploadsAvailable() )
	{
		$exts[] = 'svg';
	}
	return $exts;
}

/**
 * Remove everything from an uploaded SVG file that could be used to run
 * scripts or load remote content (scripts, event handlers, javascript: URLs,
 * external references, ...) with the enshrined/svg-sanitize library.
 *
 * return String|false		cleaned up SVG, or false if the file isn't a valid SVG
 */
function sanitizeUploadedSvg($tmpName)
{
	if ( !svgUploadsAvailable() || !is_uploaded_file($tmpName) || filesize($tmpName) > MAX_SVG_UPLOAD_SIZE )
	{
		return false;
	}
	$sanitizer = new enshrined\svgSanitize\Sanitizer();
	$sanitizer->removeRemoteReferences(true);
	$sanitizer->minify(true);
	return sanitizeSvgText((string)file_get_contents($tmpName), $sanitizer);
}

/**
 * Run the SVG sanitizer on the text of an SVG file. Whatever goes wrong inside the library (it doesn't
 * only return false for broken files: version 1.1.0 throws a ValueError for some of them), the file is
 * refused, never stored, and the request goes on.
 *
 * @param object $sanitizer	an enshrined\svgSanitize\Sanitizer, set up for the wiki
 * return String|false		cleaned up SVG, or false if the text isn't a valid SVG file
 */
function sanitizeSvgText($svg, $sanitizer)
{
	$previousLibxmlSetting = libxml_use_internal_errors(true);
	try
	{
		$clean = $sanitizer->sanitize($svg);
	}
	catch ( \Throwable $e )
	{
		w2Log("SVG upload rejected: the SVG sanitizer failed: " . get_class($e) . ": " . $e->getMessage());
		return false;
	}
	finally
	{
		libxml_clear_errors();
		libxml_use_internal_errors($previousLibxmlSetting);
	}
	if ( !is_string($clean) || trim($clean) === '' )
	{
		w2Log("SVG upload rejected: not a valid SVG file");
		return false;
	}
	return $clean;
}

/**
 * Content types (as detected from the file content) which a file with the given extension may have.
 * Without this, the content of any accepted type could be stored (and processed by ImageMagick) under
 * any accepted extension, e.g. a PDF as "x.png". Extensions without an entry are not restricted further.
 */
function uploadTypesForExt($ext)
{
	$types = array(
		'bmp' => array('image/bmp', 'image/x-ms-bmp'),
		'gif' => array('image/gif'),
		'heic' => array('image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'),
		'heif' => array('image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'),
		'jpg' => array('image/jpeg', 'image/pjpeg'),
		'jpeg' => array('image/jpeg', 'image/pjpeg'),
		'pdf' => array('application/pdf'),
		'png' => array('image/png'),
		'webp' => array('image/webp'),
	);
	// (no extension: null, which can't be an array key)
	return $ext === null ? null : ($types[$ext] ?? null);
}

function uploadTypeMatchesExt($type, $ext)
{
	$allowed = uploadTypesForExt($ext);
	return $allowed === null || in_array($type, $allowed, true);
}

/**
 * Whether uploaded images can be shrunk, rotated and converted: needs the PHP extension imagick
 * (which not every installation has), and IMAGE_PROCESSING_ENABLED. Otherwise, such uploads are
 * stored as they are.
 */
function imageProcessingAvailable()
{
	return IMAGE_PROCESSING_ENABLED && extension_loaded('imagick') && class_exists('Imagick');
}

/**
 * Prefix for the file name given to ImageMagick, which forces the format instead of guessing it from
 * the content of the file ("png:/path/file.png")
 */
function imageMagickFormatPrefix($ext)
{
	return ($ext === 'jpg' || $ext === 'jpeg') ? 'jpeg:' : $ext . ':';
}

/**
 * Whether renaming an uploaded file keeps its type, i.e. the new extension is the same as the old one (or
 * one that stands for the same content, like jpg and jpeg). The content was checked against the old
 * extension when it was uploaded (an SVG was sanitized), so it must not get another one by renaming.
 */
function renameKeepsFileType($oldName, $newName)
{
	$oldExt = getFileExt($oldName);
	$newExt = getFileExt($newName);
	if ( $oldExt === $newExt )
	{
		return true;
	}
	$oldTypes = uploadTypesForExt($oldExt);
	return $oldTypes !== null && $oldTypes === uploadTypesForExt($newExt);
}

function hasValidUploadExt($fileName)
{
	return !isHiddenFile($fileName) && in_array(getFileExt($fileName), validUploadExts(), true);
}

function pageURL($page)
{
	$encoded = str_replace("%2F", "/", str_replace("%23", "#", rawurlencode(sanitizeFilename($page))));
	// with VIEW (e.g. '?action=view&page=') the page name is a query value, otherwise it is the PATH_INFO
	return SELF . (VIEW !== '' ? VIEW : "/") . $encoded;
}

function pageLink($page, $title, $attributes="")
{
	return "<a href=\"" . htmlspecialchars(pageURL($page), ENT_QUOTES) ."\"$attributes>$title</a>";
}

/**
 * Replace the tags (with their attributes) and the code blocks and inline code in HTML from the Markdown
 * parser by placeholders, so that the text between them can be searched and changed without damaging them.
 * The placeholders contain a random value, so that text of a page cannot imitate one.
 *
 * return array	'html': the HTML with placeholders; 'restore': function which puts the original back
 */
function maskTagsAndCode($html)
{
	$nonce = bin2hex(random_bytes(8));
	$originals = array();
	$mask = function($m) use (&$originals, $nonce)
	{
		$originals[] = $m[0];
		return "@@W2" . $nonce . (count($originals) - 1) . "@@";
	};
	// (all other "<" in the text are escaped by the parser; quotes of attribute values may contain ">")
	$html = preg_replace_callback('/<(pre|code)\b.*?<\/\1>/s', $mask, $html);
	$html = preg_replace_callback('/<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $mask, $html);
	return array(
		'html' => $html,
		'restore' => function($text) use (&$originals, $nonce)
		{
			return preg_replace_callback('/@@W2' . preg_quote($nonce, '/') . '(\d+)@@/', fn($m) => $originals[(int)$m[1]], $text);
		}
	);
}

function toHTMLID($noid)
{	// in HTML5, only spaces aren't allowed
	return h(str_replace(" ", "-", html_entity_decode(strip_tags($noid), ENT_QUOTES | ENT_HTML5, W2_CHARSET)));
}

/**
 * Neutralize URLs with potentially dangerous schemes (like javascript: or
 * data:) in Markdown links and images; relative URLs are left untouched.
 */
function filterURL($url)
{
	// browsers ignore whitespace and control characters within the scheme
	$normalized = preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, W2_CHARSET));
	if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $matches) &&
		!in_array(strtolower($matches[1]), array('http', 'https', 'mailto', 'ftp', 'ftps', 'tel'), true))
	{
		return '#';
	}
	return $url;
}

/**
 * Replace lines consisting only of an embed ("![[Page name]]") with the Markdown source of that page,
 * recursively. Embeds inside fenced code blocks are left alone. Anything which can't be embedded (an
 * invalid or missing page, a page already being embedded, or exceeding the limits INCLUDE_MAX_DEPTH
 * and INCLUDE_MAX_SIZE) is replaced by a visible marker instead.
 *
 * $stack	names of the pages currently being embedded (to detect cycles)
 * $budget	bytes still available for embedded text; shared by all embeds of a rendering
 */
function expandIncludes($text, array $stack = array(), &$budget = null)
{
	$budget ??= INCLUDE_MAX_SIZE;
	$maxDepth = INCLUDE_MAX_DEPTH;
	$fence = null;
	$lines = preg_split('/(\r\n|\n|\r)/', $text);
	foreach ($lines as $i => $line)
	{
		if ( preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) )
		{
			if ( $fence === null )
			{
				$fence = $m[1];
			}
			else if ( $m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence) && trim($line) === $m[1] )
			{
				$fence = null;
			}
			continue;
		}
		if ( $fence !== null || !preg_match('/^!\[\[([^\[\]|#]+)\]\][ \t]*$/', $line, $m) )
		{
			continue;
		}
		$page = sanitizeFilename(trim($m[1]));
		$failure = null;
		if ( !isValidPageName($page) || !isExistingPage($page) )
		{
			$failure = "not found";
		}
		else if ( in_array($page, $stack, true) )
		{
			$failure = "cycle";
		}
		else if ( count($stack) >= $maxDepth )
		{
			$failure = "too deeply nested";
		}
		else
		{
			$included = (string)pageStore()->read($page);
			$budget -= strlen($included);
			if ( $budget < 0 )
			{
				$failure = "too large";
			}
			else
			{
				$lines[$i] = "\n" . expandIncludes($included, array_merge($stack, array($page)), $budget) . "\n";
			}
		}
		if ( $failure !== null )
		{
			$lines[$i] = "*[include failed (" . $failure . "): `" . str_replace('`', "'", $page) . "`]*";
		}
	}
	return implode("\n", $lines);
}

function toHTML($inText)
{
	$inText = expandIncludes($inText);
	$parser = new MarkdownExtra;
	$parser->no_markup = true;
	$parser->url_filter_func = 'filterURL';
	$outHTML  = $parser->transform($inText);
	// page links are looked for in the text only: not in tags (like the URL or title of a link) or in code
	$masked = maskTagsAndCode($outHTML);
	$outHTML = $masked['html'];
	if ( AUTOLINK_PAGE_TITLES )
	{
		$pagenames = getAllPageNames();
		uasort($pagenames, "descLengthSort");
		foreach ( $pagenames as $pageName )
		{
			// match pageName, but only if it isn't inside another word or inside braces (as in "[$pageName]").
			$outHTML = preg_replace("/(?<![\[a-zA-Z])".preg_quote($pageName, '/')."(?![\]a-zA-Z])/i", "[[".pregReplacementQuote($pageName)."]]", $outHTML);
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
		$exists = isExistingPage(sanitizeFilename($pagePart));
		$outHTML = str_replace("[[$fullLinkText]]",
			pageLink($linkedPage, $linkText, ($exists? "" : " class=\"noexist\"")), $outHTML);
	}

	$outHTML = $masked['restore']($outHTML);

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
	return versionUploadLinks($outHTML);
}

/**
 * URL of a static file of the wiki (path relative to the W2 folder, e.g. "wiki.js" or "w2-icons/home.svg"),
 * with a version parameter taken from the modification time of the file. A changed file therefore gets
 * a new URL, which allows serving the files with long cache lifetimes. Files that don't exist get no parameter.
 */
function assetURL($path)
{
	return versionedURL(BASE_URI . "/" . $path, __DIR__ . "/" . $path);
}

/**
 * URL of an uploaded file (name without folder), versioned like assetURL(), as uploads can be overwritten
 */
function uploadURL($name)
{
	return versionedURL(uploadUrlPrefix() . rawurlencode($name), PAGES_PATH . "/" . UPLOAD_FOLDER . "/" . $name);
}

function versionedURL($url, $file)
{
	$mtime = is_file($file) ? filemtime($file) : false;
	return $mtime === false ? $url : $url . "?v=" . $mtime;
}

/**
 * Add the version parameter to links and images in rendered Markdown which point to uploaded files
 */
function versionUploadLinks($html)
{
	$prefix = uploadUrlPrefix();
	return preg_replace_callback('/\b(src|href)="' . preg_quote(h($prefix), '/') . '([^"?#\/]+)"/',
		function($m) use ($prefix)
		{
			$name = rawurldecode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, W2_CHARSET));
			if ( $name === '' || $name[0] === '.' || str_contains($name, "\0") )
			{
				return $m[0];
			}
			return $m[1] . '="' . h(versionedURL($prefix . rawurlencode($name), PAGES_PATH . "/" . UPLOAD_FOLDER . "/" . $name)) . '"';
		}, $html);
}

function humanFilesize($bytes, $decimals = 2) {
	$sz = 'BKMGTP';
	$factor = floor((strlen($bytes) - 1) / 3);
	return sprintf("%.".($factor==0?0:$decimals)."f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}

/**
 * The headings of a table which can be sorted by some of its columns, for the template table-head.
 *
 * @param string $action	the action which shows the table (the headings are links to it)
 * @param string $sortBy	the column which the table is sorted by
 * @param array<int, array{0: string, 1: string|null}> $columns	the label of each column, and what to sort by to
 *                          sort by it (or null for a column which can't be sorted by)
 * return W2\Html
 */
function sortableHeadings($action, $sortBy, $columns)
{
	$headings = array();
	foreach ($columns as list($label, $key))
	{
		$headings[] = array('label' => $label, 'current' => ($key !== null && $key === $sortBy),
			'href' => ($key === null || $key === $sortBy) ? null : SELF . "?action=" . $action . "&sortBy=" . $key);
	}
	return templates()->render('table-head', array('columns' => $headings));
}

/**
 * The icons to do something with a page (edit, delete, rename, show the pages which link to it), except for
 * the action which is being done, as links to the actions.
 *
 * @param string $imgSuffix	"-dark" for the icons on a light background
 * return W2\Html
 */
function getPageActions($page, $action, $imgSuffix)
{
	$pageActions = array('edit' => translate('Edit'), 'delete' => translate('Delete'), 'rename' => translate('Rename'));
	$items = array();
	foreach ($pageActions as $name => $label)
	{
		if ($action != $name)
		{
			$items[] = array('href' => SELF . "?action=" . $name . "&page=" . urlencode($page),
				'icon' => assetURL("w2-icons/" . $name . $imgSuffix . ".svg"), 'label' => $label);
		}
	}
	$items[] = array('href' => SELF . "?action=view&page=" . urlencode($page) . "&linkshere=true",
		'icon' => assetURL("w2-icons/link" . $imgSuffix . ".svg"), 'label' => translate('Show links here'));
	return templates()->render('page-actions', array('items' => $items));
}
