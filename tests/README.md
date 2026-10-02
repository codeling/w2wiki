# Tests

The tests start a private copy of the wiki (with an individual `config.php`
per test class) on PHP's built-in web server, and talk to it over HTTP. They
need PHP 8.1+ with the extensions `curl`, `dom`, `fileinfo` and `libxml`.

```
composer install
composer test              # everything
composer test:unit         # fast tests of helper functions
composer test:integration  # HTTP tests, all but the SVG upload tests
composer test:server       # rules for Apache and nginx, needs Docker (see below)
composer test:browser      # scripts in a real browser, see below
composer test:svg          # SVG uploads, needs the enshrined/svg-sanitize test dependency
vendor/bin/phpunit --filter UploadTest
```

Every test starts with the initial pages, no uploads, and a new session. A test
also fails if the wiki logged PHP warnings, notices or deprecations while it ran.

## Layout

- `tests/fixtures/svg`: SVG files with scripts, event handlers, external references, entities etc. for the SVG upload tests.

- `tests/Support`: `AppServer` (runs the app), `HttpClient` (cookie-aware, doesn't follow
  redirects), `AppTestCase` (base class with helpers such as `savePage()`, `upload()`,
  `assertNoActiveContent()`).
- `tests/Unit`: tests of functions from `functions.php` and `auth_functions.php` (loaded by
  `tests/bootstrap.php` with the default `config.php`, without session or output).
  `SourceGuardTest` looks at the tokens of the PHP files and fails on dangerous constructs: functions running code or
  commands, variables in regular expressions that are not quoted with `preg_quote()`, files included by variable
  names, and request data read outside the entry points.
- `tests/Integration`: the tests. To test with another configuration, override
  `configOverrides()` in the test class; it returns values for the constants defined in
  `config.php` (or `$allowedIPs`).

## Running the tests against another version of the wiki

`W2_APP_ROOT=/path/to/checkout vendor/bin/phpunit` tests that checkout instead of this
one, which is useful to see that the tests fail without a fix. Add `W2_IGNORE_PHP_LOG=1`
for versions that log PHP warnings.

## Web server rules

The protection which depends on the web server (`.htaccess` files for Apache, the rules from
INSTALL.md for nginx: no access to page sources, `.git` and `vendor`, no scripts in the uploads,
sandboxed SVGs, no directory listings) is tested against real servers in Docker containers:

```
tests/Server/run.sh apache    # php:apache
tests/Server/run.sh nginx     # nginx + php-fpm
```

The script serves a copy of the wiki and runs the suite `server` with the environment variables
`W2_SERVER_URL` and `W2_SERVER_ROOT`; without them these tests are skipped. It uses host networking
and the ports 8080 (`W2_SERVER_PORT`) and, for nginx, 9000. `W2_NGINX_IMAGE` and `W2_PHP_VERSION`
select the images. The nginx rules tested are in `tests/Server/nginx/w2.conf.template`; a unit test
makes sure they are the same as in INSTALL.md.

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
