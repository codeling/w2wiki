<?php
// SPDX-License-Identifier: MIT

// Makes the constants of the settings known to PHPStan, which includes this file: it can't evaluate the
// definitions in defaults.php (some use $_SERVER or are conditional) when it analyses them.
define('W2APP', true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/defaults.php';
