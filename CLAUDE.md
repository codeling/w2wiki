## Git workflow
- Before your first commit, rename the branch to describe the change:
  `git branch -m claude/<short-description-of-change>`

## Tests
- Install the test dependencies with `composer install`, then run `vendor/bin/phpunit`
  (or `php vendor/phpunit/phpunit/phpunit` if `vendor/bin` is missing); see `tests/README.md`.
- The integration tests run a private copy of the wiki on PHP's built-in server. Options such as
  `git`, `gitRemote` and `pagesFolder` (see `AppServer::get()`) set up a git repository in the pages folder.
- PHP warnings logged by the wiki fail a test, so check that a path exists before using it.
