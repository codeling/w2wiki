<?php
// Constants for PHPStan only (never loaded by the wiki or by the tests).
// config.php defines BASE_URI from $_SERVER, which PHPStan can't evaluate, so it doesn't know the constant.
define('BASE_URI', '');
