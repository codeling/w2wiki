<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * W2
 *
 * Copyright (C) 2007-2009 Steven Frank <http://stevenf.com/>
 * Code may be re-used as long as the above copyright notice is retained.
 * See README.md for full details.
 *
 * Written with Coda: <http://panic.com/coda/>
 *
 */

// --------------------
// Site layout settings
// --------------------

// PAGES_PATH
//
// The path to the raw text documents maintained by W2
// You should not use a trailing slash.
define('PAGES_PATH', dirname(__FILE__). '/pages');

// UPLOAD_FOLDER
//
// The subfolder in PAGES_PATH that uploads get stored to: a single folder name
// (no slashes). It is not part of the wiki: Markdown files within it are not pages,
// and pages cannot be created there. The web server has to serve it under
// UPLOAD_URL (see "Uploaded images" in INSTALL.md).
define('UPLOAD_FOLDER', 'images');

// UPLOAD_URL
//
// The URL path, relative to BASE_URI, under which the web server serves UPLOAD_FOLDER.
// This is what links to uploads in pages look like: BASE_URI/UPLOAD_URL/name
define('UPLOAD_URL', UPLOAD_FOLDER);

// PAGES_EXT
//
// The extension of the Markdown files in the PAGES_PATH
// folder which are displayed by W2
define('PAGES_EXT', 'md');


// URL setup: BASE_URI, SELF and VIEW (see "Web server setup" in INSTALL.md)
//
// BASE_URI
//
// The base URI for this W2 installation: the folder of the script, which static files
// (style sheet, icons, uploaded images) are referenced from. You only need to change this
// if we guess wrong, e.g. behind a reverse proxy which changes the path.
// You should not use a trailing slash; it is empty if W2 is installed in the web root.
define('BASE_URI', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));

// SELF
//
// The path component of the URL to the main script, such as: /w2/index.php
define('SELF', $_SERVER['SCRIPT_NAME']);

// VIEW 
//
// Needed only if your web server does not pass PATH_INFO to PHP (so that URLs like
// /index.php/Page don't work), e.g. nginx or a CGI setup without PATH_INFO support.
// For example: define('VIEW', '?action=view&page=');
// The page name is appended directly to this value (without a slash); links to pages
// and redirects (e.g. after saving) use it instead of index.php/Page.
define('VIEW', '');

// DEFAULT_PAGE
//
// The name of the page to show as the "Home" page.
// Value is a string, the title of a page (case-sensitive!)
define('DEFAULT_PAGE', 'Home');

// CSS_FILE
//
// The CSS file to load to style the wiki, relative to BASE_URI
define('CSS_FILE', 'index.css');

// SIDEBAR_FILE
//
// The name of the page to be shown as sidebar (leave empty to disable sidebar feature)
define ('SIDEBAR_PAGE', '_sidebar');

// PAGE_TITLE
//
// A title prepended to the title head tag of all pages of the wiki
define ('PAGE_TITLE', 'Wiki: ');

// --------------------
// File upload settings
// --------------------

// DISABLE_UPLOADS
//
// Globally enable/disable file uploads
define('DISABLE_UPLOADS', false);

// VALID_UPLOAD_TYPES
//
// Acceptable file types for file uploads.  This is a good idea for security.
// Value is a comma-separated string of MIME types.
// Note: SVG files are not part of this list; see SVG_UPLOADS_ENABLED below.
define('VALID_UPLOAD_TYPES', 'application/pdf,image/gif,image/heic,image/heif,image/jpeg,image/pjpeg,image/png,image/webp');

// VALID_UPLOAD_EXTS
//
// Acceptable filename extensions for file uploads
// Value is a comma-separated string of filename extensions
define('VALID_UPLOAD_EXTS', 'bmp,gif,heic,heif,jpg,jpeg,pdf,png,webp');

// SVG_UPLOADS_ENABLED
//
// Allow uploading SVG files. SVG files can contain scripts, which would run in
// the context of the wiki when such a file is opened directly, so uploaded SVGs
// are cleaned by the enshrined/svg-sanitize library, which is not bundled with
// W2 (it is GPL licensed) and needs to be installed via Composer, see
// INSTALL.md. Without it, SVG uploads are refused even if this is set to true.
define('SVG_UPLOADS_ENABLED', false);

// SHOW_PAGES_WHERE_FILE_USED
//
// On uploads page, show on which pages a file is referenced
// With many pages and image files, this can take some time to determine and therefore slow down loading the list of uploads!
define('SHOW_PAGES_WHERE_FILE_USED', true);

// IMAGE_EXTS_TO_CONVERT
// uploaded images with these extensions need to be converted to another format (see also CONVERT_FORMAT)
define('IMAGE_EXTS_TO_CONVERT', 'heic,heif');

// CONVERT_FORMAT
// format to convert uploaded images to which need to be converted (see IMAGE_EXTS_TO_CONVERT)
define('CONVERT_FORMAT', 'jpg');

// ------------------
// Interface settings
// ------------------

// TITLE_DATE
//
// The format to use when displaying page modification times.
// See the manual for the PHP 'date()' function for the specification:
// http://php.net/manual/en/function.date.php
// Note that these settings are overridden by the
// date_format/date_format_no_time in the used locale!
define('TITLE_DATE', 'j-M-Y g:i A');
define('TITLE_DATE_NO_TIME', 'j-M-Y');

// EDIT_ROWS
//
// Default size of the text editing area in text rows.
define('EDIT_ROWS', 18);

// AUTOLINK_PAGE_TITLES
//
// Automatically converts any page titles appearing in text into links
// to the named page. This might degrade performance if you have many
// thousands of pages.
define('AUTOLINK_PAGE_TITLES', false);


// -----------------------------
// Security and session settings
// -----------------------------

// REQUIRE_PASSWORD
//
// Is a password required to access this wiki?
define('REQUIRE_PASSWORD', false);

// W2_PASSWORD
//
// The password for the wiki, if REQUIRE_PASSWORD is true and W2_PASSWORD_HASH
// is empty. Replace 'secret' with your password to set your password; logging
// in is refused as long as the default 'secret' is configured.
define('W2_PASSWORD', 'secret');

// W2_PASSWORD_HASH
//
// Alternate (more secure) password storage, takes precedence over W2_PASSWORD.
// Set it to the output of PHP's password_hash function for your password,
// which you can for example create on the command line like this:
//     php -r 'echo password_hash("your_password", PASSWORD_DEFAULT), "\n";'
//
// Note: since the hash contains '$' characters, use single quotes, e.g.:
// define('W2_PASSWORD_HASH', '$2y$10$...');
//
// For backwards compatibility, an (unsalted, and therefore not recommended)
// SHA-1 hash of the password is also still accepted.
define('W2_PASSWORD_HASH', '');

// allowedIPs
//
// A whitelist of IP addresses that are allowed access to the wiki.
// If empty, all IPs are allowed.
// Entries can be single addresses (e.g. '192.168.1.10'), CIDR ranges
// (e.g. '192.168.1.0/24' or 'fd00::/8'), or address prefixes ending in
// '.' or ':' (e.g. '192.168.1.').
$allowedIPs = array();

// W2_SESSION_LIFETIME
// 
// How long before a login session expires?  Default is 30 days
define('W2_SESSION_LIFETIME', 60 * 60 * 24 * 30);

// W2_SESSION_NAME
//
// Name for session (used in the cookie)
define('W2_SESSION_NAME', 'W2');


// -----------------------------
// Git Integration
// -----------------------------

// GIT_COMMIT_ENABLED
//
// Enable/Disable committing changes in page folder to local git repository
define('GIT_COMMIT_ENABLED', false);

// GIT_PUSH_ENABLED
//
// Enable/Disable pushing changes in page folder to a remote git repository
define('GIT_PUSH_ENABLED', false);


// -----------------------------
// Locale and encoding settings
// -----------------------------

// W2_CHARSET
//
// Value for meta charset.
define('W2_CHARSET', 'UTF-8');

// W2_LOCALE
//
// Name for locale.
define('W2_LOCALE', 'en');
