<?php if (!defined('W2APP')){ die('No direct access.'); }
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * The default value of every setting, used for the ones which config.php does
 * not define (or for all of them if there is no config.php). It is loaded after
 * config.php and never overrides it. The settings are explained in
 * config.sample.php, which has the same values: change settings in config.php,
 * not here. (tests/Unit/ConfigDefaultsTest.php checks that the two files agree.)
 */

// Site layout settings
defined('PAGES_PATH') || define('PAGES_PATH', __DIR__ . '/pages');
defined('UPLOAD_FOLDER') || define('UPLOAD_FOLDER', 'images');
defined('UPLOAD_PATH') || define('UPLOAD_PATH', PAGES_PATH . '/' . UPLOAD_FOLDER);
defined('UPLOAD_URL') || define('UPLOAD_URL', UPLOAD_FOLDER);
defined('PAGES_EXT') || define('PAGES_EXT', 'md');
defined('BASE_URI') || define('BASE_URI', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));
defined('SELF') || define('SELF', $_SERVER['SCRIPT_NAME']);
defined('VIEW') || define('VIEW', '');
defined('DEFAULT_PAGE') || define('DEFAULT_PAGE', 'Home');
defined('CSS_FILE') || define('CSS_FILE', 'index.css');
defined('SIDEBAR_PAGE') || define('SIDEBAR_PAGE', '_sidebar');
defined('PAGE_TITLE') || define('PAGE_TITLE', 'Wiki: ');

// File upload settings
defined('DISABLE_UPLOADS') || define('DISABLE_UPLOADS', false);
defined('VALID_UPLOAD_TYPES') || define('VALID_UPLOAD_TYPES', 'application/pdf,image/gif,image/heic,image/heif,image/jpeg,image/pjpeg,image/png,image/webp');
defined('VALID_UPLOAD_EXTS') || define('VALID_UPLOAD_EXTS', 'bmp,gif,heic,heif,jpg,jpeg,pdf,png,webp');
defined('SVG_UPLOADS_ENABLED') || define('SVG_UPLOADS_ENABLED', false);
defined('SHOW_PAGES_WHERE_FILE_USED') || define('SHOW_PAGES_WHERE_FILE_USED', true);
defined('IMAGE_PROCESSING_ENABLED') || define('IMAGE_PROCESSING_ENABLED', true);
defined('IMAGE_EXTS_TO_CONVERT') || define('IMAGE_EXTS_TO_CONVERT', 'heic,heif');
defined('MAX_IMAGE_PIXELS') || define('MAX_IMAGE_PIXELS', 100000000);
defined('CONVERT_FORMAT') || define('CONVERT_FORMAT', 'jpg');

// Interface settings
defined('TITLE_DATE') || define('TITLE_DATE', 'j-M-Y g:i A');
defined('EDIT_ROWS') || define('EDIT_ROWS', 18);
defined('AUTOLINK_PAGE_TITLES') || define('AUTOLINK_PAGE_TITLES', false);
defined('INCLUDE_MAX_DEPTH') || define('INCLUDE_MAX_DEPTH', 5);
defined('INCLUDE_MAX_SIZE') || define('INCLUDE_MAX_SIZE', 1048576);

// Security and session settings (no authentication unless configured; logging in is impossible
// until W2_PASSWORD_HASH is set)
defined('REQUIRE_PASSWORD') || define('REQUIRE_PASSWORD', false);
defined('REQUIRE_WEB_SERVER_AUTH') || define('REQUIRE_WEB_SERVER_AUTH', false);
defined('WEB_SERVER_AUTH_REQUIRE_HTTPS') || define('WEB_SERVER_AUTH_REQUIRE_HTTPS', true);
defined('W2_PASSWORD_HASH') || define('W2_PASSWORD_HASH', '');
$allowedIPs ??= array();
defined('LOGIN_MAX_FAILURES') || define('LOGIN_MAX_FAILURES', 5);
defined('LOGIN_LOCKOUT_SECONDS') || define('LOGIN_LOCKOUT_SECONDS', 60);
defined('LOGIN_LOCKOUT_MAX_SECONDS') || define('LOGIN_LOCKOUT_MAX_SECONDS', 3600);
defined('LOGIN_MAX_FAILURES_PER_HOUR') || define('LOGIN_MAX_FAILURES_PER_HOUR', 100);
defined('LOGIN_THROTTLE_FOLDER') || define('LOGIN_THROTTLE_FOLDER', '');
$trustedProxies ??= array();
defined('W2_SESSION_LIFETIME') || define('W2_SESSION_LIFETIME', 60 * 60 * 24 * 30);
defined('W2_SESSION_NAME') || define('W2_SESSION_NAME', 'W2');

// Git integration
defined('GIT_COMMIT_ENABLED') || define('GIT_COMMIT_ENABLED', false);
defined('GIT_PUSH_ENABLED') || define('GIT_PUSH_ENABLED', false);
defined('GIT_PULL_ENABLED') || define('GIT_PULL_ENABLED', false);
defined('GIT_PULL_VIEW_INTERVAL') || define('GIT_PULL_VIEW_INTERVAL', 60 * 60);
defined('GIT_PULL_EDIT_INTERVAL') || define('GIT_PULL_EDIT_INTERVAL', 5 * 60);

// Locale and encoding settings
defined('W2_CHARSET') || define('W2_CHARSET', 'UTF-8');
defined('W2_LOCALE') || define('W2_LOCALE', 'en');
