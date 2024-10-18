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
// The subfolder in PAGES_PATH that uploads get stored to
define('UPLOAD_FOLDER', 'images');

// PAGES_EXT
//
// The extension of the Markdown files in the PAGES_PATH
// folder which are displayed by W2
define('PAGES_EXT', 'md');


// BASE_URI
//
// The base URI for this W2 installation.  You only need to change this if we guess wrong.
// You should not use a trailing slash.
define('BASE_URI', str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']));

// SELF
//
// The path component of the URL to the main script, such as: /w2/index.php
define('SELF', $_SERVER['SCRIPT_NAME']);

// VIEW 
//
// Needed only if your web server spawns PHP as a CGI instead of an internal module.
// For example: define('VIEW', '?action=view&page=');
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
define('VALID_UPLOAD_TYPES', 'application/pdf,image/gif,image/heic,image/heif,image/jpeg,image/pjpeg,image/png,image/svg+xml,image/webp');

// VALID_UPLOAD_EXTS
//
// Acceptable filename extensions for file uploads
// Value is a comma-separated string of filename extensions
define('VALID_UPLOAD_EXTS', 'bmp,gif,heic,heif,jpg,jpeg,pdf,png,svg,webp');

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
// The password for the wiki, if REQUIRE_PASSWORD is true
// Replace 'secret' with your password to set your password.
define('W2_PASSWORD', 'secret');

// W2_PASSWORD_HASH
//
// Alternate (more secure) password storage.
// To use a hashed password, Comment out the W2_PASSWORD definition above and uncomment
// this one, using the result of sha1('your_password') as the value.
//
// In Mac OS X, you can do this from the Terminal:   
//     echo -n 'your_password' | openssl sha1
//
// define('W2_PASSWORD_HASH', 'e5e9fa1ba31ecd1ae84f75caaa474f3a663f05f4');
define('W2_PASSWORD_HASH', '');

// allowedIPs
//
// A whitelist of IP addresses that are allowed access to the wiki. 
// If empty, all IPs are allowed.
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
