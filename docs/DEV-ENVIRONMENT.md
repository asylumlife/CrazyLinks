# CrazyLinks dev environment

Set up 2026-11-05, revised 2026-11-26. Local only, no Docker. PHP and MySQL come from MAMP, which was reinstalled on 2026-11-23; see [Starting and stopping](#starting-and-stopping) for what changed.

## Site

| Item | Value |
|---|---|
| URL | http://crazylinks.test:8888 (link pages under `/linktree/`) |
| Admin | http://crazylinks.test:8888/wp-admin/ (user `admin`, password in `~/Sites/.crazylinks-dev-credentials`) |
| Document root | `/Users/asylumlife/Sites/crazylinks.test` |
| WordPress | 7.1.4 (en_US), pretty permalinks `/%postname%/` |
| Database | MySQL 8.0.44, db `crazylinks`, user `root` / `root`, socket `/Applications/MAMP/tmp/mysql/mysql.sock` (TCP is disabled by `skip-networking` in MAMP's my.cnf) |
| PHP (web) | 8.5.3 via `.htaccess` override, see below |
| PHP (CLI) | 8.4.27 at `/Applications/MAMP/bin/php/php8.4.27/bin/php` |
| Debug | `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` on, `WP_ENVIRONMENT_TYPE=local`, log at `wp-content/debug.log` |

## Starting and stopping

Since the MAMP reinstall, the AppleScript commands (`tell application "MAMP PRO" to start all servers`) no longer work, and `crazylinks.test` has no `/etc/hosts` entry unless MAMP PRO is running and managing it. Tests and screenshots need neither: start MySQL by hand and serve the site with PHP's built-in server.

```sh
export PATH=/Applications/MAMP/bin/php/php8.4.27/bin:/Applications/MAMP/Library/bin/mysql80/bin:$HOME/.local/bin:$PATH

# MySQL (socket only). Run from /tmp.
cd /tmp && nohup mysqld_safe --defaults-file=/Applications/MAMP/tmp/mysql/my.cnf \
  --datadir="/Library/Application Support/appsolute/MAMP PRO/db/mysql80" \
  --socket=/Applications/MAMP/tmp/mysql/mysql.sock --pid-file=/Applications/MAMP/tmp/mysql/mysql.pid \
  --log-error=/Applications/MAMP/logs/mysql_error.log >/dev/null 2>&1 &

# The site, on the port WordPress expects.
cd ~/Sites/crazylinks.test && wp server --host=127.0.0.1 --port=8888

# Stop.
mysqladmin -uroot -proot -S /Applications/MAMP/tmp/mysql/mysql.sock shutdown
```

Without a hosts entry, send the host name yourself: `curl -H 'Host: crazylinks.test:8888' http://127.0.0.1:8888/linktree/demo/`, or start Chrome with `--host-resolver-rules="MAP crazylinks.test 127.0.0.1"` (the screenshot script below does this).

If MAMP PRO is running, Apache listens on 8888 (HTTP) and 8890 (HTTPS) and the hosts entry exists, so the `wp server` step is not needed.

## PHP version override

MAMP PRO assigned PHP 7.3.33 to the new host, which WordPress 7.1 refuses. The first lines of the site's `.htaccess` switch the FastCGI handler:

```apache
Action php-fastcgi "/fcgi-bin/php8.5.3.fcgi"
```

This applies only when Apache serves the site, and the versions named here predate the reinstall, so check which `fcgi-bin` aliases exist first. To make it clean: in MAMP PRO, Hosts → crazylinks.test → PHP version → a current 8.4 or 8.5, save, then delete the `Action` line from `.htaccess`.

## Command-line tools

```sh
export PATH=/Applications/MAMP/bin/php/php8.4.27/bin:/Applications/MAMP/Library/bin/mysql80/bin:$PATH
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
export PATH=/Applications/MAMP/bin/php/php8.4.27/bin:/Applications/MAMP/Library/bin/mysql80/bin:$PATH
cd "/Users/asylumlife/Documents/Claude/Projects/Claude Code/AL-CrazyLinks/crazylinks"
vendor/bin/phpcs                      # WordPress-Extra + Docs, text-domain check
vendor/bin/phpstan analyse --no-progress   # level 6
vendor/bin/phpunit                    # integration tests on the real WP test library
```

- WordPress test library: `~/Sites/crazylinks-tests/` (WP 7.1.4), installed by `crazylinks/bin/install-wp-tests.sh` without Docker or svn. Test database `crazylinks_test`, user `root` / `root`, over the MAMP socket.
- Demo content: `cd ~/Sites/crazylinks.test && wp crazylinks demo` creates `/linktree/demo/`. Other WP-CLI commands: `wp crazylinks validate file.json`, `wp crazylinks rollup`.
- Dev-only header: with `WP_DEBUG` on, `/go/` responses carry `X-CrazyLinks-Event: logged | duplicate | rate-limited | bot`.
- Assets: edit `assets/src/*`, then `php bin/build-assets.php` writes `assets/build/*`.

## Screenshots

Headless Chrome on macOS clamps the window to roughly 500px wide, so `--window-size=390,…` silently renders a wider page and crops it. For a true phone-width capture, screenshot a wrapper page that centers a 390px `<iframe>` of the link page in an 800px window, then crop the middle 390px with `sips -c <h> 390`. Desktop captures work directly:

```sh
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --hide-scrollbars \
  --window-size=1280,1400 --screenshot=docs/screenshots/demo-desktop.png http://crazylinks.test:8888/linktree/demo/
```

Current captures live in `docs/screenshots/`.

### Feature tour screenshots

The images in [`FEATURES.md`](FEATURES.md) live in `docs/screenshots/tour/` and are taken by a Playwright script that drives the installed Chrome at a true 390px phone width. With MySQL and the site running:

```sh
cd ~/Sites/crazylinks.test
wp crazylinks demo --slug=tour                                         # prints the page id
wp eval-file wp-content/plugins/crazylinks/tests/tour-seed.php         # extra blocks and made-up analytics; once per fresh page
for t in $(wp eval 'echo implode(" ", array_keys(\CrazyLinks\Render\Themes::all()));' | tr ' ' '\n'); do
  wp crazylinks demo --slug="tt-${t}" --theme="${t}" --porcelain; done  # one page per theme, for the themes sheet

cd "/Users/asylumlife/Documents/Claude/Projects/Claude Code/AL-CrazyLinks/crazylinks"
TOUR_PAGE_ID=<id> TOUR_THEME_SLUGS="default:tt-default,midnight:tt-midnight,..." \
TOUR_PASS="$(sed -n 's/^ADMIN_PASSWORD=//p' ~/Sites/.crazylinks-dev-credentials)" \
node bin/tour-screenshots.mjs            # or only some groups: public, themes, admin
```

Deactivate other plugins that add admin notices or banners (URL Shortify did) while taking the admin group, and delete the `tt-*` pages afterwards. The product blocks show whatever store is set under CrazyLinks → Settings → Store.

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
