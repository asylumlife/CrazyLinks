# Contributing

Bug reports, fixes and ideas are welcome. For anything larger than a small fix, open an issue first so we can agree on the approach.

## Set up

You need PHP 8.1+, Composer, Node.js with npm, and a WordPress site to run the plugin on. [`docs/DEV-ENVIRONMENT.md`](docs/DEV-ENVIRONMENT.md) describes the maintainer's setup (MAMP, no Docker); adapt the paths to yours.

```sh
cd crazylinks
composer install
npm ci
```

PHPUnit runs against the WordPress test library. Install it with `crazylinks/bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [target-dir]` (needs WP-CLI, `curl` and MySQL), then set `WP_TESTS_DIR` to the printed path. The database named in the script is emptied by the tests, so use a throwaway one.

## Run the gates

From `crazylinks/`:

```sh
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
npm run typecheck
npm run lint:js
npm run lint:css
npm run test:unit
npm run build
```

CI runs all of these except Playwright (`npm run test:e2e`, which needs a running dev site). The compiled editor bundle in `crazylinks/assets/build` is committed: if you change `crazylinks/assets/src`, run `npm run build` and commit the result, or CI fails.

## Coding standards

- PHP follows WordPress-Extra and WordPress-Docs through `phpcs.xml.dist`, with PSR-4 class files under `src/`. PHPStan runs at level 6.
- Escape output, sanitize and validate input, check capabilities and nonces. Any callback passed to a REST `sanitize_callback` must be a wrapper that takes the three REST arguments, never a bare function such as `sanitize_title`.
- User-facing strings use the `crazylinks` text domain.
- Add or update tests with your change.
- Changes to the page JSON format also need `crazylinks/schemas/` and `crazylinks/docs/schema-changes.md` updated.

## Branches and pull requests

- Branch from `main` and keep each pull request to one change.
- Make sure the gates pass locally before opening it.
- Describe what changed and why, and mention any user-visible behaviour change. Add a line to the changelog in `crazylinks/readme.txt` for user-facing changes.
- Do not bump the plugin version in a pull request; the maintainer does that when releasing.

## Where things are documented

The specification is [`docs/SPEC.md`](docs/SPEC.md). It records decisions and the reasons for them, so check it before proposing a change to behaviour. Extension points are in [`crazylinks/docs/hooks.md`](crazylinks/docs/hooks.md).

## License

By contributing you agree that your work is released under GPL-2.0-or-later, the same license as the project.
