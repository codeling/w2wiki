# Tests

The tests start a private copy of the wiki (with an individual `config.php`
per test class) on PHP's built-in web server, and talk to it over HTTP. They
need PHP 8.1+ with the extensions `curl`, `dom`, `fileinfo` and `libxml`. `ImageProcessingTest` (resizing,
rotating and converting images) also needs `imagick`, and is skipped without it; the HEIC tests are skipped
if ImageMagick can't read HEIC files (needs libheif with an HEVC decoder).

```
composer install
composer test              # everything
composer test:unit         # fast tests of helper functions
composer test:integration  # HTTP tests, all but the SVG upload tests
composer test:server       # rules for Apache and nginx, and the container image, needs Docker or Podman (see below)
composer test:browser      # scripts in a real browser, see below
composer test:svg          # SVG uploads, needs the enshrined/svg-sanitize test dependency
vendor/bin/phpunit --filter UploadTest
```

Every test starts with the initial pages, no uploads, and a new session. A test
also fails if the wiki logged PHP warnings, notices or deprecations while it ran.

## Layout

- `tests/fixtures/images`: JPEGs with EXIF orientation 1, 3, 6 and 8 (60x30 pixels, red left, blue right) and a small HEIC file.
- `tests/fixtures/svg`: SVG files with scripts, event handlers, external references, entities etc. for the SVG upload tests.

- `tests/Support`: `AppServer` (runs the app), `HttpClient` (cookie-aware, doesn't follow
  redirects), `AppTestCase` (base class with helpers such as `savePage()`, `upload()`,
  `assertNoActiveContent()`).
- `tests/Unit`: tests of functions from `functions.php` and `auth_functions.php` (loaded by
  `tests/bootstrap.php` with the default `config.php`, without session or output).
  `SourceGuardTest` looks at the tokens of the PHP files and fails on dangerous constructs: functions running code or
  commands, variables in regular expressions that are not quoted with `preg_quote()`, files included by variable
  names, and request data read outside the entry points.
  `LocaleFilesTest` checks the files in `locales/` (valid PHP, no duplicate keys, UTF-8, date formats) ;
  `locales/en.php` only has entries that differ from their key (`__()` returns the key for texts without entry).
  Texts of the code (`__()` calls) missing in the other languages are only reported as "incomplete".
- `tests/Integration`: the tests. `LocaleTest` runs the main views with every locale file (the data set name is
  the locale, set with `W2_LOCALE`); `LocaleEscapingTest` uses a generated locale with markup in its texts. To test with another configuration, override
  `configOverrides()` in the test class; it returns values for the constants defined in
  `config.php` (or `$allowedIPs`).

The git integration is tested with the server options `git` and `gitRemote` (see `AppServer::get()`):
they make the pages folder of the test copy a git repository (with a local bare repository as `origin`),
and `pagesFolder` renames the pages folder, e.g. to a path with spaces and quotes. The `Git*Test` classes
check commits (`git log`), pushes, shell escaping and the generic error messages. They need `git` (2.28+).

## Running the tests against another version of the wiki

`W2_APP_ROOT=/path/to/checkout vendor/bin/phpunit` tests that checkout instead of this
one, which is useful to see that the tests fail without a fix. Add `W2_IGNORE_PHP_LOG=1`
for versions that log PHP warnings.

## Web server rules

The protection which depends on the web server (`.htaccess` files for Apache, the rules from
INSTALL.md for nginx: no access to page sources, `.git` and `vendor`, no scripts in the uploads,
sandboxed SVGs, no directory listings) is tested against real servers in containers (Docker or Podman):

```
tests/Server/run.sh apache      # php:apache
tests/Server/run.sh nginx       # nginx + php-fpm
tests/Server/run.sh container   # the image built from the Containerfile
W2_SUBFOLDER=/w2 tests/Server/run.sh nginx   # with the wiki installed in a subfolder (also for apache)
```

The script serves a copy of the wiki and runs the suite `server` with the environment variables
`W2_SERVER_URL` and `W2_SERVER_ROOT`; without them these tests are skipped. `apache` and `nginx` use
host networking and the ports 8080 (`W2_SERVER_PORT`) and, for nginx, 9000; `container` publishes the
port 8080 of the image as `W2_SERVER_PORT` instead. `W2_NGINX_IMAGE` and `W2_PHP_VERSION`
select the images. The nginx rules tested are in `tests/Server/nginx/w2.conf.template`; a unit test
makes sure they are the same as in INSTALL.md.

- The container engine is `docker` if it is installed, otherwise `podman`; `W2_CONTAINER_ENGINE=podman`
  selects it. Files are mounted with `z` (shared SELinux label), so that this works on Fedora & co.
- `container` builds the image (or uses `W2_IMAGE=name`), copies its files to a temporary folder, and
  runs the image on that folder, with `pages` as a separate mount (it is a volume of the image). So the
  tests also cover the start script of the image (default pages, `.htaccess` files, git repository), its
  Apache and PHP settings, and a web server which doesn't run as root. It starts the image with
  `W2_UMASK=000`, so that the tests (another user) can change files which the web server user created.

What the container tests found out (and the Containerfile does, see "Running in a container" in INSTALL.md):

- The stock `php:apache` image has `AllowOverride None` and no `mod_headers`: the `.htaccess` files of W2
  are ignored (pages, `.git` and uploads would be served, and PHP files in the uploads executed), and the
  headers for uploads (nosniff, SVG sandbox, caching) are missing. `tests/Server/apache.conf` is the
  settings of a typical Apache installation which make them work; the image has the same.
- Uploads are served from the `images` link in the wiki folder, so the pages folder must be inside it:
  a volume at another path (like `/pages`) is not served, and `PAGES_PATH` is a constant in `config.php`.
- Files which the web server user creates in a mounted folder belong to another user than the tests:
  the folder has to be writable for everybody, and git needs `safe.directory` for such a folder.
- With a read-only root file system, only `/tmp` and the pages folder have to be writable.

## Browser tests

`tests/Browser` contains [Playwright](https://playwright.dev) tests which try to run scripts in a real
browser (Chromium): stored and reflected XSS payloads (the same texts as the PHP tests use), uploaded
file names, and uploaded SVG files opened directly. Any `alert()` or event handler that runs fails a
test. `canary.spec.js` checks that the helpers really notice scripts.

```
cd tests/Browser
npm ci
npx playwright install chromium    # not needed if the browsers are already installed
npx playwright test                # or: composer test:browser
```

The wiki is started by `tests/bin/serve-app.php` (on the ports 8090, and 8091 with SVG uploads).
With `W2_BASE_URL` the tests use a wiki which is already running instead. `W2_BROWSER=1
tests/Server/run.sh apache` (or `nginx`) runs part of them against the real web servers, including
the check that scripts in old SVG files of the uploads folder are blocked by their headers.

Like the PHP tests, the browser tests can be run against another version with
`W2_APP_ROOT=/path/to/checkout php tests/bin/serve-app.php --port=8092` and `W2_BASE_URL=http://127.0.0.1:8092`.
