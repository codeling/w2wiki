<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
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
	// don't fail fatally for unknown classes, so class_exists() can be used to probe for optional ones
	$file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, ltrim($class, '\\')) . '.php';
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
		return h(is_null($alt_word) ? $label : $alt_word);
	}
	return h($w2_word_set[$label]);
}

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

/**
 * Whether the page of this action contains the editor (text area, save button
 * and formatting help), and needs its script wiki.js
 */
function isEditorAction($action)
{
	return $action === 'edit' || $action === 'new';
}

function isExistingPage($page)
{
	return $page !== "" && file_exists(fileNameForPage($page));
}

function imageLinkText($imgName)
{
	return "![".__("Image Description")."](".BASE_URI."/".UPLOAD_FOLDER."/$imgName)";
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
	$segments = explode('/', $page);
	foreach ($segments as $segment)
	{
		if ($segment === '' || str_starts_with($segment, '.'))
		{
			return false;
		}
	}
	return $segments[0] !== UPLOAD_FOLDER;
}

function getFileExt($fileName)
{
	return preg_match('/\.([^.\/]+)$/', $fileName, $matches) ? strtolower($matches[1]) : null;
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
	return defined('SVG_UPLOADS_ENABLED') && SVG_UPLOADS_ENABLED && class_exists('enshrined\\svgSanitize\\Sanitizer');
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
	$previousLibxmlSetting = libxml_use_internal_errors(true);
	$clean = $sanitizer->sanitize(file_get_contents($tmpName));
	libxml_clear_errors();
	libxml_use_internal_errors($previousLibxmlSetting);
	if ( !is_string($clean) || trim($clean) === '' )
	{
		error_log("W2: SVG upload rejected: not a valid SVG file");
		return false;
	}
	return $clean;
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

function toHTML($inText)
{
	$parser = new MarkdownExtra;
	$parser->no_markup = true;
	$parser->url_filter_func = 'filterURL';
	$outHTML  = $parser->transform($inText);
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
		$linkedFilename = fileNameForPage(sanitizeFilename($pagePart));
		$exists = file_exists($linkedFilename);
		$outHTML = str_replace("[[$fullLinkText]]",
			pageLink($linkedPage, $linkText, ($exists? "" : " class=\"noexist\"")), $outHTML);
	}

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
	return versionedURL(BASE_URI . "/" . UPLOAD_FOLDER . "/" . rawurlencode($name), PAGES_PATH . "/" . UPLOAD_FOLDER . "/" . $name);
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
	$prefix = BASE_URI . "/" . UPLOAD_FOLDER . "/";
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
				"&amp;page=".urlencode($page)."\"><img src=\"".assetURL("w2-icons/".$pageActions[$i].$imgSuffix.".svg")."\" alt=\"".$pageActionNames[$i]."\" title=\"".$pageActionNames[$i]."\" class=\"icon\"></a>\n";
		}
	}
	$result .= "      <a href=\"" . SELF . "?action=view&amp;page=".urlencode($page)."&linkshere=true\"><img src=\"".assetURL("w2-icons/link".$imgSuffix.".svg")."\" alt=\"".__('Show links here')."\" title=\"".__('Show links here')."\" class=\"icon\"/></a>\n";
	return $result;
}
