<?php
// SPDX-License-Identifier: MIT
/*
 * Measures how often the actions of the wiki list all pages (getAllPageNames() / PageStore::names()), what that costs,
 * and how long the requests take, for a pages folder of realistic size. It is a baseline for changes which are meant to
 * make this faster, not a test: run it before and after, and compare.
 *
 *   php tests/Performance/benchmark.php [--pages=100,300,1000] [--runs=7] [--save=before.json] [--compare=before.json]
 *
 * For every size a pages folder is generated (always the same: seeded random numbers) with nested folders, [[links]]
 * between the pages, images, a sidebar, and a copy of the wiki is configured to use it. Every scenario is run in a
 * fresh PHP process (as a request would be), the median of the runs is reported. Counters are added to a *copy* of
 * PageStore.php and functions.php (nothing of the wiki is changed), so the counts are those of the code as it is.
 *
 *   calls   calls of getAllPageNames()      scans   times the pages folder is read from disk
 *   names   time in getAllPageNames()       total   time of the request in the script (without starting PHP)
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
const APP_FILES = ['index.php', 'api.php', 'auth.php', 'auth_functions.php', 'functions.php', 'defaults.php', 'config.sample.php', 'index.css', 'wiki.js'];
const APP_DIRS = ['Michelf', 'locales', 'src', 'w2-icons', 'webauth', 'vendor'];

// --- the child process: one request ---------------------------------------------------------------------------

if (($argv[1] ?? '') === '--request') {
	$scenario = json_decode((string)getenv('W2_BENCH_SCENARIO'), true);
	$started = hrtime(true);
	$_SERVER = ['REQUEST_METHOD' => $scenario['method'], 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php' . ($scenario['pathInfo'] ?? ''),
		'SCRIPT_FILENAME' => getcwd() . '/index.php', 'REQUEST_URI' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
		'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'REQUEST_TIME' => time(), 'REQUEST_TIME_FLOAT' => microtime(true),
		'CONTENT_LENGTH' => '0'] + $_SERVER;
	if (isset($scenario['pathInfo'])) {
		$_SERVER['PATH_INFO'] = $scenario['pathInfo'];
	}
	$_GET = $scenario['get'] ?? [];
	$_POST = $scenario['post'] ?? [];
	$_REQUEST = $_GET + $_POST;
	if ($scenario['method'] === 'POST') {
		$_SERVER['CONTENT_LENGTH'] = '100';
		// (the session of the request has the token which the form would send)
		session_id('benchsession');
	}
	register_shutdown_function(function () use ($started) {
		$bench = $GLOBALS['W2_BENCH'] ?? [];
		file_put_contents((string)getenv('W2_BENCH_OUT'), json_encode([
			'total_ms' => (hrtime(true) - $started) / 1e6, 'peak_mb' => memory_get_peak_usage(true) / 1048576,
			'calls' => $bench['calls'] ?? null, 'names_calls' => $bench['names_calls'] ?? null, 'scans' => $bench['scans'] ?? null,
			'names_ms' => isset($bench['names_ns']) ? $bench['names_ns'] / 1e6 : null,
		]));
	});
	$GLOBALS['W2_BENCH'] = ['calls' => 0, 'names_calls' => 0, 'scans' => 0, 'names_ns' => 0];
	ob_start();
	require 'index.php';
	exit(0);
}

// --- the benchmark ---------------------------------------------------------------------------------------------

$options = getopt('', ['pages::', 'runs::', 'save::', 'compare::']);
$sizes = array_map('intval', explode(',', $options['pages'] ?? '100,300,1000'));
$runs = max(1, (int)($options['runs'] ?? 7));
$appRoot = dirname(__DIR__, 2);
$work = sys_get_temp_dir() . '/w2-bench-' . bin2hex(random_bytes(4));
mkdir($work);
register_shutdown_function(fn() => removeTree($work));

function removeTree(string $path): void
{
	if (is_dir($path) && !is_link($path)) {
		foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
			removeTree("$path/$entry");
		}
		rmdir($path);
	} elseif (file_exists($path) || is_link($path)) {
		unlink($path);
	}
}

function copyTree(string $from, string $to): void
{
	if (is_dir($from)) {
		mkdir($to, 0755, true);
		foreach (array_diff(scandir($from), ['.', '..']) as $entry) {
			copyTree("$from/$entry", "$to/$entry");
		}
	} else {
		copy($from, $to);
	}
}

/** Adds the counters to the copy of the wiki; @return string[] the counters which could not be added */
function addCounters(string $app): array
{
	$missing = [];
	$count = '$GLOBALS[\'W2_BENCH\'][\'%1$s\'] = ($GLOBALS[\'W2_BENCH\'][\'%1$s\'] ?? 0) + %2$s;';
	$edit = function (string $file, string $search, string $replace) use ($app, &$missing) {
		$text = (string)file_get_contents("$app/$file");
		if (substr_count($text, $search) !== 1) {
			$missing[] = "$file: $search";
			return;
		}
		file_put_contents("$app/$file", str_replace($search, $replace, $text));
	};
	$timed = fn(string $inner) => '$__start = hrtime(true); $__result = ' . $inner . '; ' . sprintf($count, 'names_ns', '(hrtime(true) - $__start)') . ' return $__result;';
	$edit('functions.php', "function getAllPageNames()\n{", 'function getAllPageNames()
{
	' . sprintf($count, 'calls', '1') . '
	' . $timed('W2BenchGetAllPageNames()') . '
}
function W2BenchGetAllPageNames()
{');
	$edit('src/PageStore.php', "public function names(): array\n\t{", 'public function names(): array
	{
		' . sprintf($count, 'names_calls', '1') . '
		return $this->namesUncounted();
	}

	public function namesUncounted(): array
	{');
	$edit('src/PageStore.php', '$names = $this->namesIn(\'\');', sprintf($count, 'scans', '1') . ' $names = $this->namesIn(\'\');');
	return $missing;
}

function sentence(array $words): string
{
	$n = mt_rand(6, 16);
	$s = [];
	for ($i = 0; $i < $n; $i++) {
		$s[] = $words[mt_rand(0, count($words) - 1)];
	}
	return ucfirst(implode(' ', $s)) . '.';
}

/** A pages folder with $count pages (and images, a sidebar): names, folders, sizes and links like a wiki which has grown */
function generatePages(string $dir, int $count): array
{
	mt_srand(42);
	$words = explode(' ', 'the of and to in is you that it he was for on are as with his they at be this from have or by one had not but what all were when we there can an your which their said if do will each about how up out them then she many some so these would other into has more her two like him see time could no make than first been its who now people my made over did down only way find use may water long little very after words called just where most know get through back much before go good new write our me man too any day same right look think also around another came come work three word must because does part even place well such here take why help put different away again off went old number great tell men say small every found still between name should home big give air line set own under read last never us left end along while might next sound below saw something thought both few those always looked show large often together asked house don\'t world going want school important until form food keep children feet land side without boy once animals life enough took sometimes four head above kind began almost live page got earth need far hand high year mother light country father let night picture being study second soon story since white ever paper hard near sentence better best across during today however sure knew try told young sun thing whole hear example heard several change answer room sea against top turned learn point city play toward five using himself usually');
	$topics = ['Project', 'Meeting notes', 'Recipe', 'Book', 'Idea', 'Howto', 'Travel', 'Reading list', 'Notes on', 'Checklist', 'Journal', 'Server', 'Budget'];
	$names = ['Home', 'Markdown Syntax'];
	for ($i = count($names); $i < $count; $i++) {
		$topic = $topics[mt_rand(0, count($topics) - 1)];
		$title = $topic . ' ' . ucfirst($words[mt_rand(0, count($words) - 1)]) . ' ' . $i;
		// (about one page in six is in a folder, some in a folder in a folder)
		$roll = mt_rand(1, 12);
		$names[] = $roll <= 2 ? "$topic/$title" : ($roll === 3 ? "Archive/" . (2015 + mt_rand(0, 9)) . "/$title" : $title);
	}
	mkdir("$dir/images", 0755, true);
	$images = [];
	for ($i = 1; $i <= 30; $i++) {
		file_put_contents("$dir/images/photo$i.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
		$images[] = "photo$i.png";
	}
	foreach ($names as $index => $name) {
		$text = "# " . basename($name) . "\n\n";
		$paragraphs = mt_rand(2, 12);
		for ($p = 0; $p < $paragraphs; $p++) {
			$kind = mt_rand(1, 10);
			if ($kind <= 6) {
				$text .= implode(' ', array_map(fn() => sentence($words), range(1, mt_rand(2, 6)))) . "\n\n";
			} elseif ($kind === 7) {
				$text .= "## " . ucfirst(sentence($words)) . "\n\n";
			} elseif ($kind === 8) {
				$text .= implode("\n", array_map(fn() => '* ' . sentence($words), range(1, mt_rand(3, 8)))) . "\n\n";
			} elseif ($kind === 9) {
				$text .= "```\n" . implode("\n", array_map(fn() => '  ' . sentence($words), range(1, mt_rand(2, 6)))) . "\n```\n\n";
			} else {
				$text .= "| a | b |\n|---|---|\n| " . sentence($words) . " | " . sentence($words) . " |\n\n";
			}
			if (mt_rand(1, 3) === 1) {
				$target = $names[mt_rand(0, count($names) - 1)];
				$text .= "See [[$target]] and [[" . $names[mt_rand(0, count($names) - 1)] . "|a related page]].\n\n";
			}
		}
		if (mt_rand(1, 5) === 1) {
			$text .= "![photo](/images/" . $images[mt_rand(0, count($images) - 1)] . ")\n";
		}
		$file = "$dir/$name.md";
		is_dir(dirname($file)) || mkdir(dirname($file), 0755, true);
		file_put_contents($file, $text);
	}
	$sidebar = "### Pages\n\n" . implode("\n", array_map(fn($n) => "* [[$n]]", array_slice($names, 0, 10))) . "\n";
	file_put_contents("$dir/_sidebar.md", $sidebar);
	return $names;
}

function median(array $values): float
{
	sort($values);
	$n = count($values);
	return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function runRequest(string $app, string $pages, array $scenario, string $sessions, bool $autolink): array
{
	$out = "$sessions/out-" . bin2hex(random_bytes(3)) . '.json';
	// (the token which the form of a change would send)
	file_put_contents("$sessions/sess_benchsession", 'csrf_token|s:64:"' . str_repeat('a', 64) . '";');
	$command = [PHP_BINARY, '-d', "session.save_path=$sessions", '-d', 'display_errors=0', '-d', 'error_log=/dev/null', "$app/benchmark-entry.php", '--request'];
	$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $app, [
		'W2_BENCH_SCENARIO' => json_encode($scenario), 'W2_BENCH_OUT' => $out, 'W2_BENCH_PAGES' => $pages, 'W2_BENCH_AUTOLINK' => $autolink ? '1' : '',
	] + getenv());
	proc_close($process);
	$result = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;
	return is_array($result) ? $result : ['total_ms' => null];
}

// the scenarios: what the actions of the wiki do (writing ones change the pages, so they get a fresh copy every time)
$token = str_repeat('a', 64);
$scenarios = [
	'view page' => ['method' => 'GET', 'pathInfo' => '/Home'],
	'view page, autolink titles' => ['method' => 'GET', 'pathInfo' => '/Home', 'autolink' => true],
	'view page without sidebar' => ['method' => 'GET', 'pathInfo' => '/Markdown Syntax', 'noSidebar' => true],
	'view missing page (editor, similar names)' => ['method' => 'GET', 'pathInfo' => '/Does not exist'],
	'what links here' => ['method' => 'GET', 'pathInfo' => '/Home', 'get' => ['linkshere' => 'true']],
	'edit' => ['method' => 'GET', 'get' => ['action' => 'edit', 'page' => 'Home']],
	'new (empty editor)' => ['method' => 'GET', 'get' => ['action' => 'new']],
	'all pages' => ['method' => 'GET', 'get' => ['action' => 'all']],
	'search' => ['method' => 'GET', 'get' => ['action' => 'search', 'q' => 'water']],
	'uploads (usage shown)' => ['method' => 'GET', 'get' => ['action' => 'upload', 'page' => 'Home']],
	'save existing page' => ['method' => 'POST', 'write' => true, 'post' => ['action' => 'save', 'page' => 'Home', 'isNew' => '', 'newText' => "# Home\n\nchanged", 'csrf_token' => $token, 'gitmsg' => '']],
	'rename page' => ['method' => 'POST', 'write' => true, 'post' => ['action' => 'renamed', 'oldPageName' => 'Markdown Syntax', 'newName' => 'Markdown Cheat Sheet', 'csrf_token' => $token]],
	'delete page' => ['method' => 'POST', 'write' => true, 'post' => ['action' => 'deleted', 'oldPageName' => 'Markdown Syntax', 'csrf_token' => $token]],
	'rename image' => ['method' => 'POST', 'write' => true, 'post' => ['action' => 'imgRenamed', 'oldPageName' => 'photo1.png', 'newName' => 'sunrise.png', 'prevpage' => 'Home', 'csrf_token' => $token]],
	'delete image' => ['method' => 'POST', 'write' => true, 'post' => ['action' => 'imgDeleted', 'oldPageName' => 'photo1.png', 'prevpage' => 'Home', 'csrf_token' => $token]],
];

// the copy of the wiki, with counters
$app = "$work/app";
mkdir($app);
foreach (APP_FILES as $file) {
	is_file("$appRoot/$file") && copy("$appRoot/$file", "$app/$file");
}
foreach (APP_DIRS as $dir) {
	is_dir("$appRoot/$dir") && copyTree("$appRoot/$dir", "$app/$dir");
}
copy(__FILE__, "$app/benchmark-entry.php");
file_put_contents("$app/config.php", "<?php if (!defined('W2APP')){ die('No direct access.'); }\n"
	. "define('PAGES_PATH', getenv('W2_BENCH_PAGES'));\ndefine('AUTOLINK_PAGE_TITLES', getenv('W2_BENCH_AUTOLINK') === '1');\n"
	. "define('GIT_COMMIT_ENABLED', false);\n");
$missing = addCounters($app);
if ($missing) {
	fwrite(STDERR, "counters not available (the code has changed; the counts are shown as -):\n  " . implode("\n  ", $missing) . "\n");
}
$sessions = "$work/sessions";
mkdir($sessions);

$results = [];
foreach ($sizes as $size) {
	$base = "$work/pages-$size";
	mkdir($base);
	generatePages($base, $size);
	$bytes = 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
		$bytes += $file->getSize();
	}
	fwrite(STDERR, sprintf("%d pages (%.1f MB), %d runs per scenario...\n", $size, $bytes / 1048576, $runs));
	foreach ($scenarios as $name => $scenario) {
		$samples = [];
		for ($run = 0; $run < $runs + 1; $run++) {
			$pages = $base;
			if (!empty($scenario['write'])) {
				$pages = "$work/pages-run";
				removeTree($pages);
				copyTree($base, $pages);
			}
			if (!empty($scenario['noSidebar'])) {
				$pages = "$work/pages-nosidebar";
				removeTree($pages);
				copyTree($base, $pages);
				unlink("$pages/_sidebar.md");
			}
			$sample = runRequest($app, $pages, $scenario, $sessions, !empty($scenario['autolink']));
			if ($run > 0 && $sample['total_ms'] !== null) {
				// (the first run only warms the file system cache)
				$samples[] = $sample;
			}
		}
		$pick = fn(string $key) => $samples && $samples[0][$key] !== null ? median(array_column($samples, $key)) : null;
		$results[$size][$name] = ['calls' => $pick('calls'), 'names_calls' => $pick('names_calls'), 'scans' => $pick('scans'),
			'names_ms' => $pick('names_ms'), 'total_ms' => $pick('total_ms'), 'peak_mb' => $pick('peak_mb')];
	}
}

$before = isset($options['compare']) ? json_decode((string)file_get_contents($options['compare']), true) : null;
$number = fn($value, int $decimals = 1) => $value === null ? '-' : number_format($value, $decimals);
foreach ($results as $size => $rows) {
	echo "\n$size pages\n";
	printf("%-30s %5s %5s %5s %9s %9s %6s", 'scenario', 'calls', 'names', 'scans', 'names ms', 'total ms', 'share');
	echo $before ? sprintf(" %12s %9s", 'before total', 'change') : '', "\n";
	foreach ($rows as $name => $row) {
		$share = ($row['names_ms'] !== null && $row['total_ms']) ? sprintf('%d%%', round(100 * $row['names_ms'] / $row['total_ms'])) : '-';
		printf("%-30s %5s %5s %5s %9s %9s %6s", $name, $number($row['calls'], 0), $number($row['names_calls'], 0), $number($row['scans'], 0),
			$number($row['names_ms']), $number($row['total_ms']), $share);
		if ($before) {
			$old = $before[$size][$name]['total_ms'] ?? null;
			echo sprintf(" %12s %9s", $number($old), $old && $row['total_ms'] ? sprintf('%+d%%', round(100 * ($row['total_ms'] - $old) / $old)) : '-');
		}
		echo "\n";
	}
}
if (isset($options['save'])) {
	file_put_contents($options['save'], json_encode($results, JSON_PRETTY_PRINT));
	fwrite(STDERR, "\nsaved to {$options['save']}\n");
}
