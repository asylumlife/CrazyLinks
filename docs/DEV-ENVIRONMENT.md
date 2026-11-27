# Local setup

How to run CrazyLinks from source, run its tests and regenerate the documentation screenshots. Commands are run from `crazylinks/` unless a step says otherwise. Replace `<wp-root>` with the directory of your WordPress site and `<site-url>` with its address, for example `http://localhost:8888`.

## Requirements

| Tool | Version | Used for |
|---|---|---|
| PHP | 8.1 or newer | The plugin, phpcs, PHPStan, PHPUnit |
| Composer | 2 | PHP development tools |
| Node.js and npm | Node 22 or newer | Editor build, ESLint, stylelint, Vitest, Playwright |
| MySQL or MariaDB | Any version WordPress supports | The site and the test database |
| WP-CLI | 2.x | Test library install, demo content, translations |
| WordPress | 7.0 or newer | A local site to run the plugin on |
| Google Chrome | Current | End-to-end tests and screenshots only |

Any local stack works (a packaged one, Docker, or PHP's built-in server through `wp server`). Nothing in the repository depends on a particular one.

## Install

```sh
cd crazylinks
composer install
npm ci
```

Make the plugin available to your site by linking the folder into it, then activate it:

```sh
ln -s "$(pwd)" <wp-root>/wp-content/plugins/crazylinks
wp --path=<wp-root> plugin activate crazylinks
wp --path=<wp-root> rewrite structure '/%postname%/'
```

Link pages are served under `/l/` by default (`<site-url>/l/<slug>/`). The base can be changed under CrazyLinks → Settings.

Useful while developing:

- `wp crazylinks demo` creates a sample page that uses every block and prints its address. `--slug=<slug>`, `--theme=<theme>` and `--layout=<layout>` vary it; `--porcelain` prints only the page id.
- `wp crazylinks validate <file.json>` checks a page document against the schema.
- `wp crazylinks rollup` runs the daily analytics rollup now.
- With `WP_DEBUG` on, `/go/` responses carry an `X-CrazyLinks-Event` header that says whether the click was counted (`logged`, `duplicate`, `rate-limited`, ...).

## Quality gates

```sh
composer check     # phpcs, PHPStan and PHPUnit
npm run check      # TypeScript, ESLint, stylelint and Vitest
```

| Gate | Command | Notes |
|---|---|---|
| PHP coding standards | `composer lint` | WordPress-Extra and WordPress-Docs, configured in `phpcs.xml.dist` |
| PHP static analysis | `composer analyse` | PHPStan level 6, `phpstan.neon.dist` |
| PHP integration tests | `composer test` | PHPUnit on the WordPress test library, see below |
| TypeScript | `npm run typecheck` | |
| JavaScript and SCSS lint | `npm run lint` | `npm run lint:js` and `npm run lint:css` |
| Editor unit tests | `npm test` | Vitest, `tests/js` |
| End-to-end tests | `npm run test:e2e` | Playwright against a running site, see below |

CI (`.github/workflows/ci.yml`) runs every gate except the end-to-end tests.

To override a configuration file locally, copy `phpunit.xml.dist`, `phpcs.xml.dist` or `phpstan.neon.dist` to the same name without `.dist`. Those copies are ignored by git.

## PHPUnit and the WordPress test library

The integration tests run inside WordPress's own test framework, which needs a copy of WordPress, the test library and an empty database. `bin/install-wp-tests.sh` sets all three up:

```sh
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [target-dir]
```

- The tests empty `<db-name>`. Use a database created for this purpose, never a site's database.
- `db-host` defaults to `localhost`. To connect through a socket, pass `localhost:/path/to/mysql.sock`.
- `target-dir` defaults to `$TMPDIR/crazylinks-tests`.
- The script currently relies on BSD `tar` (macOS). On Linux, follow the "Install WordPress test library" step in `.github/workflows/ci.yml`, which prepares the same directories with GNU `tar`.

The script ends by printing the test library path. Pass it to PHPUnit:

```sh
WP_TESTS_DIR=<target-dir>/develop/tests/phpunit composer test
```

To avoid typing it every time, copy `phpunit.xml.dist` to `phpunit.xml` and add this inside `<phpunit>`:

```xml
<php>
	<env name="WP_TESTS_DIR" value="<target-dir>/develop/tests/phpunit" force="false"/>
</php>
```

Without `WP_TESTS_DIR`, the bootstrap looks in `/tmp/crazylinks-tests/develop/tests/phpunit`.

A single file or test: `vendor/bin/phpunit tests/integration/RewriteRulesTest.php`, or `vendor/bin/phpunit --filter test_unknown_slug_is_404`.

## Building assets

The files in `assets/build` are committed, so a checkout works without a build. Rebuild them when their source changes and commit the result.

| Source | Command | Output |
|---|---|---|
| `assets/src/editor` (TypeScript, React, SCSS) | `npm run build` | `assets/build/editor/*` |
| `assets/src/frontend.css`, `assets/src/frontend.js` | `composer build:assets` | `assets/build/frontend.min.css`, `frontend.min.js` |
| `schemas/page.v1.json` | `composer build:ai-schema` | `schemas/ai-ops.v1.json` |

`npm run start` rebuilds the editor on every change. CI fails when the committed editor bundle differs from a fresh build, and a PHPUnit test fails when `ai-ops.v1.json` is out of date. The front-end build is not checked automatically.

`assets/src/admin-analytics.{css,js}` and `assets/src/block-editor.js` are loaded as they are and have no build step.

## End-to-end tests

The Playwright tests in `tests/e2e` drive the page editor in the installed Google Chrome against a running site. They change one existing link page and restore it afterwards, so point them at a development site only.

| Variable | Default | Meaning |
|---|---|---|
| `E2E_BASE_URL` | none; set it | Site address, `<site-url>` |
| `E2E_PAGE_ID` | none; set it | Post id of an existing link page to edit, for example the one `wp crazylinks demo --porcelain` prints |
| `E2E_USER` | `admin` | An administrator's user name |
| `E2E_PASS` | none | That user's password, for the login form. The tests are skipped without it |
| `E2E_APP_PASS` | none | An application password of the same user, for the REST calls. The tests are skipped without it |
| `E2E_CHANNEL` | `chrome` | Playwright browser channel |
| `E2E_SCREENSHOTS` | off | A directory to save screenshots into during the run |

The defaults compiled into the tests for `E2E_BASE_URL` and `E2E_PAGE_ID` belong to the maintainer's site, so always set both.

```sh
E2E_BASE_URL=<site-url> \
E2E_PAGE_ID="$(wp --path=<wp-root> crazylinks demo --slug=e2e --porcelain)" \
E2E_PASS=<admin password> \
E2E_APP_PASS="$(wp --path=<wp-root> user application-password create admin e2e --porcelain)" \
npm run test:e2e
```

Delete the application password when you are done (`wp user application-password list admin`, then `delete`).

## Translations

```sh
composer make-pot      # writes languages/crazylinks.pot
```

This is the command releases use. It needs WP-CLI on the `PATH`.

## Screenshots for the feature tour

The images in [`FEATURES.md`](FEATURES.md) live in `docs/screenshots/tour/`. They are taken by `bin/tour-screenshots.mjs` from a demo page that `bin/tour-seed.php` extends with extra blocks and made-up analytics. Use a development site: the seed script replaces the analytics events of that page.

1. Create and seed the tour page, and one page per theme for the themes sheet (from `<wp-root>`):

   ```sh
   wp crazylinks demo --slug=tour --porcelain          # prints the page id
   wp eval-file wp-content/plugins/crazylinks/bin/tour-seed.php
   for t in $(wp eval 'echo implode( " ", array_keys( \CrazyLinks\Render\Themes::all() ) );'); do
     wp crazylinks demo --slug="tt-${t}" --theme="${t}" --porcelain
   done
   ```

   Run the seed script once per fresh page; a second run adds the extra blocks again.

2. Take the screenshots (from `crazylinks/`):

   ```sh
   TOUR_BASE=<site-url> TOUR_PAGE_ID=<id> TOUR_PASS=<admin password> \
   TOUR_THEME_SLUGS="default:tt-default,midnight:tt-midnight,..." \
   node bin/tour-screenshots.mjs            # or only some groups: public, themes, admin
   ```

   The variables are described at the top of the script. Set `TOUR_URL_BASE` if the site does not use the default `/l/` base, and `TOUR_RESOLVE=127.0.0.1` if the site's host name has no DNS or hosts-file entry.

3. Delete the `tt-*` pages afterwards.

Notes:

- The public and theme captures are taken at a true 390px phone width with a device scale factor of 2.
- Other plugins' admin notices and banners end up in the admin captures. Deactivate them first.
- The product blocks show whatever store is set under CrazyLinks → Settings → Store.
- `docs/screenshots/` also holds a few hand-taken captures used by the README. Headless Chrome on macOS will not open a window narrower than about 500px, so `--window-size=390,...` renders a wider page and crops it; use Playwright with a 390px viewport (as the tour script does) for phone-width images.
