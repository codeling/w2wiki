# Tests

The tests start a private copy of the wiki (with an individual `config.php`
per test class) on PHP's built-in web server, and talk to it over HTTP. They
need PHP 8.1+ with the extensions `curl`, `dom`, `fileinfo` and `libxml`.

```
composer install
composer test              # everything
composer test:unit         # fast tests of helper functions
composer test:integration  # HTTP tests, all but the SVG upload tests
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
- `tests/Integration`: the tests. To test with another configuration, override
  `configOverrides()` in the test class; it returns values for the constants defined in
  `config.php` (or `$allowedIPs`).

## Running the tests against another version of the wiki

`W2_APP_ROOT=/path/to/checkout vendor/bin/phpunit` tests that checkout instead of this
one, which is useful to see that the tests fail without a fix. Add `W2_IGNORE_PHP_LOG=1`
for versions that log PHP warnings.
