<?php

// Prints the Markdown texts trying to inject scripts (from the PHP tests) as JSON, for the browser tests

use W2\Tests\Support\MaliciousMarkdown;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$markdown = [];
foreach (MaliciousMarkdown::all() as $name => $case) {
	$markdown[$name] = $case[0];
}
echo json_encode($markdown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
