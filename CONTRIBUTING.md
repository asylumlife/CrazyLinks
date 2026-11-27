# Contributing

Bug reports, fixes and ideas are welcome. For anything larger than a small fix, open an issue first so we can agree on the approach.

## Set up

You need PHP 8.1+, Composer, Node.js 22+ with npm, MySQL or MariaDB, WP-CLI, and a WordPress 7.0+ site to run the plugin on. [`docs/DEV-ENVIRONMENT.md`](docs/DEV-ENVIRONMENT.md) walks through the whole setup.

```sh
cd crazylinks
composer install
npm ci
```

PHPUnit runs against the WordPress test library. Install it with `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [target-dir]` (needs WP-CLI, `curl` and the `mysql` client), then set `WP_TESTS_DIR` to the printed path. The tests empty the database you name, so use a throwaway one.

## Run the gates

From `crazylinks/`:

```sh
composer check     # phpcs, PHPStan and PHPUnit (composer lint, composer analyse, composer test)
npm run check      # TypeScript, ESLint, stylelint and Vitest (npm run typecheck, npm run lint, npm test)
```

CI runs the same gates on every pull request. It does not run the Playwright tests (`npm run test:e2e`), which need a running development site.

The built files in `crazylinks/assets/build` are committed, and there are two builds:

- `npm run build` compiles the editor (`assets/src/editor`) into `assets/build/editor`. CI rebuilds it and fails if the committed bundle differs.
- `composer build:assets` (`php bin/build-assets.php`) minifies `assets/src/frontend.css` and `assets/src/frontend.js` into `assets/build/frontend.min.css` and `frontend.min.js`. CI does not check this one, so remember to run it when you change either file.

Commit the rebuilt files with the source change.

## Coding standards

- PHP follows WordPress-Extra and WordPress-Docs through `phpcs.xml.dist`, with PSR-4 class files under `src/`. PHPStan runs at level 6.
- Escape output, sanitize and validate input, check capabilities and nonces. Any callback passed to a REST `sanitize_callback` must be a wrapper that takes the three REST arguments, never a bare function such as `sanitize_title`.
- User-facing strings use the `crazylinks` text domain.
- Add or update tests with your change.
- Changes to the page JSON format also need `crazylinks/schemas/page.v1.json` and `crazylinks/docs/schema-changes.md` updated, then `composer build:ai-schema` to regenerate `schemas/ai-ops.v1.json`.
- New hooks, REST routes, abilities and WP-CLI commands are added to `crazylinks/docs/hooks.md`.

## Branches and pull requests

- Branch from `main` and keep each pull request to one change.
- Make sure the gates pass locally before opening it.
- Describe what changed and why, and mention any user-visible behaviour change. Add a line to the changelog in `crazylinks/readme.txt` for user-facing changes.
- Do not bump the plugin version in a pull request; the maintainer does that when releasing.

## Where things are documented

The specification is [`docs/SPEC.md`](docs/SPEC.md). It records decisions and the reasons for them, so check it before proposing a change to behaviour. Extension points are in [`crazylinks/docs/hooks.md`](crazylinks/docs/hooks.md).

## License

By contributing you agree that your work is released under GPL-2.0-or-later, the same license as the project.
