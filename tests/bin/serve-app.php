<?php

// Serves a fresh copy of the wiki (with the test configuration) until the process is terminated.
//
//   php tests/bin/serve-app.php --port=8090 [--svg]
//
// --svg enables SVG uploads (needs the enshrined/svg-sanitize test dependency). Used by the browser tests.

use W2\Tests\Support\AppServer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['port:', 'svg']);
$port = (int)($options['port'] ?? 0);
$svg = isset($options['svg']);
if ($svg && AppServer::svgSanitizerDir() === null) {
	fwrite(STDERR, "enshrined/svg-sanitize is not installed (run composer install)\n");
	exit(2);
}

$server = AppServer::get(
	$svg ? ['SVG_UPLOADS_ENABLED' => true] : [],
	array_filter(['port' => $port ?: null, 'svgSanitizer' => $svg ?: null])
);
$server->reset();

// shut down cleanly (removing the temporary files) when terminated
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT] as $signal) {
	pcntl_signal($signal, fn() => exit(0));
}
echo 'W2 test server: ', $server->baseUrl(), "\n";
while (true) {
	sleep(1);
}
