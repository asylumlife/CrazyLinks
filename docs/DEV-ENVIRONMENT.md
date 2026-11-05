# CrazyLinks dev environment

Set up 2026-11-05. Local only, no Docker. Everything runs through MAMP PRO 7.4.3, which was already installed.

## Site

| Item | Value |
|---|---|
| URL | http://crazylinks.test:8888 |
| Admin | http://crazylinks.test:8888/wp-admin/ (user `admin`, password in `~/Sites/.crazylinks-dev-credentials`) |
| Document root | `/Users/asylumlife/Sites/crazylinks.test` |
| WordPress | 7.1.4 (en_US), pretty permalinks `/%postname%/` |
| Database | MySQL 8.0.44, db `crazylinks`, user `root` / `root`, socket `/Applications/MAMP/tmp/mysql/mysql.sock` (TCP is disabled by `skip-networking` in MAMP's my.cnf) |
| PHP (web) | 8.5.3 via `.htaccess` override, see below |
| PHP (CLI) | 8.4.17 at `/Applications/MAMP/bin/php/php8.4.17/bin/php` |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` on, `WP_ENVIRONMENT_TYPE=local`, log at `wp-content/debug.log` |

## Starting and stopping

MAMP PRO's `cmd` command-line interface does nothing on this version. Its AppleScript interface works:

```sh
osascript -e 'tell application "MAMP PRO" to start all servers'
osascript -e 'tell application "MAMP PRO" to stop all servers'
osascript -e 'tell application "MAMP PRO" to server status "Apache"'   # stopped | starting | running | ...
osascript -e 'tell application "MAMP PRO" to list all hosts'
```

If the app is not running, `open -a "MAMP PRO"` first. Apache listens on 8888 (HTTP) and 8890 (HTTPS). MySQL is socket-only.

## PHP version override

MAMP PRO assigned PHP 7.3.33 to the new host, which WordPress 7.1 refuses. The first lines of the site's `.htaccess` switch the FastCGI handler:

```apache
Action php-fastcgi "/fcgi-bin/php8.5.3.fcgi"
```

Only PHP versions already used by some host get an `fcgi-bin` alias (7.3.33, 8.5.2, 8.5.3 today), so 8.4.17 is not selectable this way. To make it clean: in MAMP PRO, Hosts → crazylinks.test → PHP version → 8.4.17 or 8.5.3, save, then delete the `Action` line from `.htaccess`.

## Command-line tools

```sh
export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:/Applications/MAMP/Library/bin/mysql80/bin:$PATH
cd ~/Sites/crazylinks.test
wp --info
wp db check
php /Applications/MAMP/bin/php/composer --version   # Composer 2.9.2 bundled with MAMP
```

WP-CLI lives at `~/.local/bin/wp` (phar, installed 2026-11-05). Node 25.8.1 and npm 11.11.0 come from Homebrew.

## Plugin under development

The plugin source lives in this repo under `crazylinks/` (matching the repo layout in SPEC.md §15) and is symlinked into `~/Sites/crazylinks.test/wp-content/plugins/crazylinks` so edits are live without copying. (Symlink created 2026-11-05.)

## Other MAMP PRO hosts on this machine

`localhost`, `asylumlife.com`, `creator-asylumlife`. Neither of the last two currently has files at its document root, so there is no local copy of the owner's live site to inspect for AI settings.

## Plugin quality gates and tests (added after Phase 1, 2026-11-05)

```sh
export PATH=/Applications/MAMP/bin/php/php8.4.17/bin:/Applications/MAMP/Library/bin/mysql80/bin:$PATH
cd "/Users/asylumlife/Documents/Claude/Projects/Claude Code/AL-CrazyLinks/crazylinks"
vendor/bin/phpcs                      # WordPress-Extra + Docs, text-domain check
vendor/bin/phpstan analyse --no-progress   # level 6
vendor/bin/phpunit                    # integration tests on the real WP test library
```

- WordPress test library: `~/Sites/crazylinks-tests/` (WP 7.1.4), installed by `crazylinks/bin/install-wp-tests.sh` without Docker or svn. Test database `crazylinks_test`, user `root` / `root`, over the MAMP socket.
- Demo content: `cd ~/Sites/crazylinks.test && wp crazylinks demo` creates `/l/demo/`. Other WP-CLI commands: `wp crazylinks validate file.json`, `wp crazylinks rollup`.
- Dev-only header: with `WP_DEBUG` on, `/go/` responses carry `X-CrazyLinks-Event: logged | duplicate | rate-limited | bot`.
- Assets: edit `assets/src/*`, then `php bin/build-assets.php` writes `assets/build/*`.

## Screenshots

Headless Chrome on macOS clamps the window to roughly 500px wide, so `--window-size=390,…` silently renders a wider page and crops it. For a true phone-width capture, screenshot a wrapper page that centers a 390px `<iframe>` of the link page in an 800px window, then crop the middle 390px with `sips -c <h> 390`. Desktop captures work directly:

```sh
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --hide-scrollbars \
  --window-size=1280,1400 --screenshot=docs/screenshots/demo-desktop.png http://crazylinks.test:8888/l/demo/
```

Current captures live in `docs/screenshots/`.

## Editor JavaScript tooling (added with Phase 2, 2026-11-05)

```sh
cd "/Users/asylumlife/Documents/Claude/Projects/Claude Code/AL-CrazyLinks/crazylinks"
npm ci                 # exact versions from package-lock.json (@wordpress/scripts 37.0.0, TypeScript 5.9, dnd-kit, Vitest, Playwright)
npm run build          # writes assets/build/editor/* (shipped with the plugin)
npm run start          # watch mode while editing assets/src/editor/
npm run typecheck && npm run lint:js && npm run lint:css
npm run test:unit      # Vitest (what @wordpress/scripts 37 runs under test-unit-js)
E2E_PASS="$(sed -n 's/^ADMIN_PASSWORD=//p' ~/Sites/.crazylinks-dev-credentials)" \
E2E_APP_PASS="$(cd ~/Sites/crazylinks.test && wp user application-password create admin e2e --porcelain)" \
npm run test:e2e       # Playwright smoke tests on the installed Google Chrome against the dev site; delete the app password afterwards
```

`E2E_SCREENSHOTS=1` makes the e2e run save screenshots. Translations: `wp i18n make-pot . languages/crazylinks.pot --domain=crazylinks --exclude=assets/src/editor,tests,bin` scans PHP plus the built editor bundle; run `wp i18n make-json languages` once `.po` files exist.

## Core file repair (2026-11-05)

`wp core download` extracts the `.tar.gz` with PHP's PharData, which truncates paths at 100 characters; 22 files under `wp-includes/php-ai-client/` arrived with cut-off names and any real AI provider would have fataled. Fixed by syncing `wp-admin/`, `wp-includes/` and the root files from the official `wordpress-7.1.4.zip` (unzipped with `unzip`), then `wp core verify-checksums` passes. If you ever reinstall core, use the zip, not WP-CLI's tarball. The test library copy under `~/Sites/crazylinks-tests/` was extracted with system `tar` and is intact.

Other maintenance commands: `php bin/build-ai-schema.php` regenerates `schemas/ai-ops.v1.json` from `schemas/page.v1.json` (a test fails if they drift); `wp crazylinks ai status` and `wp crazylinks ai eval --offline` exercise the AI layer without a provider.
