<?php
// SPDX-License-Identifier: MIT
/*
 * Times the pieces of the slow actions in one process, for a pages folder (see benchmark.php --generate): the work of
 * listing pages, reading them, building the lists, and rendering. Used to find out where the time of a request goes.
 *
 *   php tests/Performance/benchmark.php --pages=1000 --generate=/tmp/pages1000
 *   php tests/Performance/profile.php /tmp/pages1000 [autolink]
 *
 * Every piece is run once to warm up and then seven times; the median is shown. The numbers are those of a warm process
 * (file system cache, compiled regular expressions), so they are lower than the times of a request in benchmark.php.
 */

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_dir($argv[1])) {
	fwrite(STDERR, "usage: php tests/Performance/profile.php PAGES_FOLDER [autolink]\n");
	exit(1);
}
$root = dirname(__DIR__, 2);
$data = rtrim($argv[1], '/');
define('W2APP', true);
define('PAGES_PATH', $data);
define('AUTOLINK_PAGE_TITLES', ($argv[2] ?? '') === 'autolink');
const ImageExtensions = array("bmp", "gif", "heic", "heif", "jpg", "jpeg", "png", "svg", "webp");
$_SERVER['SCRIPT_NAME'] = '/index.php';

$start = hrtime(true);
require "$root/functions.php";
require "$root/defaults.php";
require "$root/auth_functions.php";
(function () use ($root) {
	global $w2_word_set;
	require "$root/locales/en.php";
})();
printf("%-62s %8.2f ms\n", 'load functions, defaults, translations', (hrtime(true) - $start) / 1e6);

function timed(string $label, callable $piece, int $runs = 7): mixed
{
	$piece();
	$times = [];
	for ($i = 0; $i < $runs; $i++) {
		$begin = hrtime(true);
		$result = $piece();
		$times[] = (hrtime(true) - $begin) / 1e6;
	}
	sort($times);
	printf("%-62s %8.2f ms\n", $label, $times[intdiv($runs, 2)]);
	return $result;
}

$store = pageStore();
$names = timed('names(): directory walk and sort', fn() => $store->names());
$walk = new ReflectionMethod($store, 'namesIn');
timed('  of which the directory walk', fn() => $walk->invoke($store, ''));
timed('read the text of every page', function () use ($store, $names) {
	foreach ($names as $page) {
		$store->read($page);
	}
});
timed('modified() and size() of every page', function () use ($store, $names) {
	foreach ($names as $page) {
		$store->modified($page);
		$store->size($page);
	}
});
timed('getPageActions() for every page (the icons of a row)', function () use ($names) {
	foreach ($names as $page) {
		getPageActions($page, 'all', '-dark');
	}
});
timed('assetURL() 4 times for every page (is_file + filemtime)', function () use ($names) {
	foreach ($names as $page) {
		foreach (['edit', 'delete', 'rename', 'link'] as $icon) {
			assetURL("w2-icons/$icon-dark.svg");
		}
	}
});
timed('pageURL() for every page', function () use ($names) {
	foreach ($names as $page) {
		pageURL($page);
	}
});

echo "\nthe lists\n";
$lists = new W2\Lists();
timed('all pages, sorted by name', fn() => $lists->allPages('name'));
timed('all pages, sorted by date', fn() => $lists->allPages('recent'));
timed('search for a word', fn() => $lists->search('water'));
timed('what links here', fn() => $lists->linksHere('Home'));
timed('uploads, with the pages where each file is used', fn() => $lists->uploads('name', 'Home'));

echo "\nuploads: the search for the files in the pages\n";
$texts = [];
foreach ($names as $page) {
	$texts[$page] = $store->read($page) ?? '';
}
$images = array_map('basename', glob("$data/images/*") ?: []);
printf("%d pages x %d files\n", count($texts), count($images));
timed('UploadReferences::count() for every page and file', function () use ($texts, $images) {
	foreach ($texts as $text) {
		foreach ($images as $image) {
			uploadReferences()->count($text, $image);
		}
	}
});
timed('str_contains() for every page and file (same number of searches)', function () use ($texts, $images) {
	foreach ($texts as $text) {
		foreach ($images as $image) {
			str_contains($text, $image);
		}
	}
});

echo "\nrendering a page (autolink " . (AUTOLINK_PAGE_TITLES ? 'on' : 'off') . ")\n";
$home = $store->read('Home') ?? '';
$sizes = array_map('strlen', $texts);
$largest = array_search(max($sizes), $sizes);
printf("page text: median %d bytes, largest %d bytes\n", array_values($sizes)[intdiv(count($sizes), 2)], max($sizes));
timed('toHTML() of Home', fn() => toHTML($home));
timed('toHTML() of the largest page', fn() => toHTML($texts[$largest]));
timed('toHTML() of the sidebar', fn() => toHTML($store->read(SIDEBAR_PAGE) ?? ''));
$markdown = function (string $text) {
	$parser = new Michelf\MarkdownExtra();
	$parser->no_markup = true;
	return $parser->transform($text);
};
timed('Markdown renderer alone, Home', fn() => $markdown($home));
timed('Markdown renderer alone, the largest page', fn() => $markdown($texts[$largest]));
if (AUTOLINK_PAGE_TITLES) {
	$html = $markdown($home);
	timed('autolink: one search for every page title in the HTML of Home', function () use ($html, $names) {
		$titles = $names;
		uasort($titles, "descLengthSort");
		foreach ($titles as $title) {
			$html = preg_replace("/(?<![\[a-zA-Z])" . preg_quote($title, '/') . "(?![\]a-zA-Z])/i", "[[" . pregReplacementQuote($title) . "]]", $html);
		}
		return $html;
	});
}

echo "\nrename and delete: the replacement in the pages (without reading and writing)\n";
timed('replace the links to a page in every page', function () use ($texts) {
	foreach ($texts as $text) {
		preg_replace("/\[\[" . preg_quote('Markdown Syntax', '/') . "([|#].*?\]\]|\]\])/", '[[X\1', $text);
	}
});
timed('replace the references to an image in every page', function () use ($texts) {
	foreach ($texts as $text) {
		uploadReferences()->rename($text, 'photo1.png', 'sunrise.png');
	}
});
