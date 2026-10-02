<?php

// Makes the wiki's functions available to unit tests (in the same way as index.php does, but
// without any session handling or output), using the default config.php

require dirname(__DIR__) . '/vendor/autoload.php';

define('W2APP', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$allowedIPs = [];

require dirname(__DIR__) . '/functions.php';
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/auth_functions.php';

// the translations are used through a global variable
(function () {
	global $w2_word_set;
	require dirname(__DIR__) . '/locales/en.php';
})();
