# Performance

Measurements of how the wiki behaves with a pages folder of realistic size, how to repeat them, and what they show.
Nothing here changes the wiki: the findings are a basis for deciding what is worth improving.

## Repeating the measurements

```
php tests/Performance/benchmark.php --pages=100,300,1000 --save=before.json    # every action, in a fresh PHP process
php tests/Performance/benchmark.php --pages=1000 --compare=before.json         # after a change: the difference
php tests/Performance/benchmark.php --pages=1000 --generate=/tmp/pages1000     # only create the pages folder
php tests/Performance/profile.php /tmp/pages1000 [autolink]                    # the pieces of the slow actions
```

`benchmark.php` runs every action as a request in a fresh PHP process (median of the runs after one warm-up run) and
counts the calls of `getAllPageNames()` and the reads of the pages folder with counters which it adds to a *copy* of
the code. `profile.php` runs the pieces of the slow actions in one process, to see where a request spends its time.

The generated pages folder is always the same (seeded random numbers): pages of 0.2 to 4.3 KB (median 1.3 KB;
1000 pages are 1.7 MB) with headings, lists, tables, code, `[[links]]` between pages and images; about a quarter of the
pages are in folders (a third of those two levels deep); 30 images in the uploads folder, a sidebar.

**About the figures.** They come from a container (PHP 8.3.6 CLI, no opcache, PCRE JIT on, warm file system cache) and
vary by up to a third between runs, so compare runs made on the same machine, and trust the order of magnitude and the
shares, not the last digit. A request in the benchmark compiles all PHP files and all regular expressions again; a
server with opcache and PHP-FPM keeps both between requests, so its requests are faster than these (see "Autolink").
The times are measured inside the script: starting PHP and the web server are not part of them.

## Baseline

`calls`: calls of `getAllPageNames()`; `names`: calls of `PageStore::names()`; `scans`: times the pages folder was read
from disk; `names ms`: time in `getAllPageNames()`; `total ms`: time of the request in the script; `share`: names ms of
total.

```
100 pages
scenario                       calls names scans  names ms  total ms  share
view page                          0     0     0       0.0       8.1     0%
view page, autolink titles         2     2     2       0.7      10.4     6%
view page without sidebar          0     0     0       0.0       7.8     0%
view missing page (editor, similar names)     2     2     2       0.6       4.7    13%
what links here                    1     1     1       0.3       9.5     3%
edit                               0     0     0       0.0       3.7     0%
new (empty editor)                 0     0     0       0.0       3.9     0%
all pages                          1     1     1       0.6      13.4     5%
search                             1     1     1       0.5      10.4     5%
uploads (usage shown)              1     1     1       0.3       7.2     4%
save existing page                 0     0     0       0.0       3.1     0%
rename page                        1     1     1       0.5       6.2     7%
delete page                        1     1     1       0.4       6.0     7%
rename image                       1     1     1       0.5       4.5    11%
delete image                       1     1     1       0.5       4.5    11%

300 pages
scenario                       calls names scans  names ms  total ms  share
view page                          0     0     0       0.0       8.1     0%
view page, autolink titles         2     2     2       1.8      14.2    13%
view page without sidebar          0     0     0       0.0       8.8     0%
view missing page (editor, similar names)     2     2     2       2.4       7.6    32%
what links here                    1     1     1       0.8       9.9     8%
edit                               0     0     0       0.0       3.4     0%
new (empty editor)                 0     0     0       0.0       3.2     0%
all pages                          1     1     1       1.1      17.5     6%
search                             1     1     1       1.0      10.4    10%
uploads (usage shown)              1     1     1       0.9      12.3     7%
save existing page                 0     0     0       0.0       3.8     0%
rename page                        1     1     1       0.9       6.5    14%
delete page                        1     1     1       1.0       6.6    15%
rename image                       1     1     1       1.0       6.1    17%
delete image                       1     1     1       1.6       9.0    18%

1000 pages
scenario                       calls names scans  names ms  total ms  share
view page                          0     0     0       0.0       7.7     0%
view page, autolink titles         2     2     2       5.7      26.4    21%
view page without sidebar          0     0     0       0.0       7.6     0%
view missing page (editor, similar names)     2     2     2       4.9       9.1    54%
what links here                    1     1     1       2.5      15.9    16%
edit                               0     0     0       0.0       3.4     0%
new (empty editor)                 0     0     0       0.0       3.1     0%
all pages                          1     1     1       2.9      43.3     7%
search                             1     1     1       2.9      17.7    16%
uploads (usage shown)              1     1     1       2.7      28.8     9%
save existing page                 0     0     0       0.0       3.2     0%
rename page                        1     1     1       3.0      13.5    23%
delete page                        1     1     1       2.9      14.1    21%
rename image                       1     1     1       3.0      14.4    21%
delete image                       1     1     1       3.2      14.3    22%
```

## Listing all pages (`getAllPageNames()`)

**How often.** With the default settings an action reads the pages folder at most once, and most actions never do:

| Calls per request | Actions |
|---|---|
| 0 | view and edit of an existing page, save, upload, the empty editor for a new page |
| 1 | all pages, search, what links here, the list of uploads (with the pages where each file is used), renaming and deleting a page or an image |
| 2 | viewing a page which does not exist (the lookup of a page of that name in a folder, then the hint for similar names in the editor); viewing a page with `AUTOLINK_PAGE_TITLES` on (once for the page, once for the sidebar) |

**What one listing costs** (`names()`: a walk of the folders with a `stat` per entry, and a natural-order sort):
about 0.5 ms for 100 pages, 1 ms for 300, 2.6 to 3 ms for 1000 (1.7 ms of that is the walk). It is 7 to 16 % of
the request in all pages, search, what links here and the list of uploads, about 21 % in the renaming and deleting
actions (which are otherwise short), and 54 % of the editor for a missing page, which is only 9 ms.

**Links to pages do not list the pages.** Whether the page behind a `[[link]]` exists (it is shown differently if not)
is checked with `isExistingPage()`, which is `PageStore::exists()`: a check of the name and one `file_exists()` of the
page's file, so one filesystem stat per link, for the links in the page and in the sidebar. Nothing is kept between
the checks, so a page which is linked three times is checked three times; `PageStore::read()` also checks `exists()`
before reading, so every read of a page is two filesystem calls. These stats are not counted by the benchmark (which
counts the listings); their cost is within the rendering times above: `toHTML()` of the sidebar, with 10 links, takes
0.3 ms in all, the page 0.5 ms. At this size they are no concern.

**Decision: the result of `getAllPageNames()` is not kept for the request.** It would save one listing in the two
cases which list twice: about 3 ms of 26 ms for the autolink view, and about 2.5 ms of 9 ms for the editor for a
missing page, at 1000 pages. All other actions would not change. The slower parts are elsewhere (below). The question
can be asked again for much larger folders, for a slow or remote file system (a cold cache makes every listing cost
more), or when more of the actions need the list.

## Where the time goes

All figures for 1000 pages, from `profile.php` (a warm process), in ms.

| Piece | ms |
|---|---|
| Start of the script: load functions, defaults, translations | 1.5 |
| Render one page with the Markdown renderer (median page / largest page) | 0.3 / 0.6 |
| `toHTML()` of one page, with the sidebar (autolink off) | 0.5 + 0.3 |
| `names()` (walk 1.7, sort 0.9) | 2.6 |
| Read the text of all pages | 4.9 |
| `modified()` and `size()` of all pages | 3.0 |
| `getPageActions()` for every row of the list of all pages | 21 |
| `UploadReferences::count()` for every page and every file (30 000 searches) | 25 |
| The same 30 000 searches with `str_contains()` | 21 |
| Replace the links to a page in all pages (regular expression) | 0.4 |
| Replace the references to an image in all pages | 1.2 |
| `toHTML()` of one page, autolink on, first call in a process / later calls | 19 / 5 |

### Findings

1. **A request for a single page is cheap** and does not depend on the number of pages: viewing 7.7 ms, editing and
   saving 3 to 4 ms at 1000 pages as at 100 pages (without opcache, which compiles the sources again every time).
   Rendering the Markdown is 0.3 to 0.6 ms; it is not a concern.

2. **The list of all pages is the slowest request** (34 to 52 ms at 1000 pages, growing linearly with the pages) and
   most of it is the icons: `getPageActions()` is called for every row, 21 ms in total. Each call asks four times for
   the address of an icon (`assetURL()`: `is_file()` and `filemtime()` of a file which is the same for every row; 3.6 ms
   for the 4000 calls) and renders a small template (the rest of the 21 ms, which was not measured separately: an
   include, an output buffer and the escaping for every row). The addresses and the whole group of icons could be made
   once and used for every row. Sorting by date needs `modified()` and `size()` of every page (3 ms).

3. **The list of uploads grows with pages times files.** To show where each file is used, every page is searched for
   every file: 30 000 searches for 1000 pages and 30 files, 25 ms, of which the actual searching is nearly all (the same
   searches with `str_contains()` take 21 ms): each page's text is read through once for every file. With 300 files it
   would be about ten times as much (an estimate from the linear growth, not measured). One pass over each page which
   collects all references to uploaded files, and a lookup of the names in that result, would make it grow with the pages
   only. `SHOW_PAGES_WHERE_FILE_USED` turns the search off.

4. **Autolink (`AUTOLINK_PAGE_TITLES`) is the one setting which makes viewing a page depend on the number of pages.**
   For every title a regular expression is applied to the page, for the page and again for the sidebar. In a fresh
   process the first rendering takes 19 ms (compiling 1000 different patterns) and the later ones 5 ms (4.4 ms of
   `toHTML()` of which 2.6 ms is the listing); the benchmark, which starts a new process for every request, therefore
   shows 26 ms for a view (7.7 ms without autolink). A PHP-FPM worker keeps compiled patterns between requests (a cache
   of 4096 patterns per process), so a server in steady state is closer to the 5 ms: but with more than about 4000
   pages, plus the patterns of the other features, the cache cannot hold them and every request compiles them again.
   With the JIT of PCRE off, compiling is quicker (12 ms) and matching slower (9 ms).

5. **Everything which looks at the text of all pages is linear and starts from reading them: 4.9 ms per 1000 pages
   (1.7 MB).** Search (15 to 18 ms), what links here (7.6 to 16 ms), the list of uploads, and renaming and deleting
   (13 to 14 ms, of which the replacement itself is 0.4 ms and the writing only concerns the pages with links) all
   include it. This is fine for hundreds of pages. A cached index of the pages (names, dates, links, perhaps the
   text) would take that cost away for large wikis; it is the larger of the Step 3 ideas of the architecture plan and
   needs a way to keep the index current (changes by the wiki, by git pulls, by editing the files directly).

6. **The write actions are cheap** (saving 3 ms; renaming, deleting 13 to 14 ms at 1000 pages), the time being the one
   of listing and reading the pages to find the links.

## Priorities

By the effect for a user and the size of the change, none of them is done yet:

1. The icons of the list of all pages: made once instead of for every row (about half of the time of the slowest
   request). Small.
2. The search for uploads in the pages: one pass instead of one per file. Small to medium; only matters with many
   uploaded files.
3. Autolink: one combined pattern, or the page names matched without a regular expression per name, and the sidebar
   handled the same way. Medium; only matters if the setting is used on large wikis.
4. A cached index of the pages. Large; only matters beyond some thousands of pages.

Not worth doing for speed: keeping the list of page names for the request (see above), the Markdown renderer.
