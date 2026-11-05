# CrazyLinks

A self-hosted WordPress plugin that replaces Linktree and similar link-in-bio services. Your link page lives on your own domain, with your own analytics, email sign-ups and AI-assisted editing, and no monthly fee.

## Layout

| Path | What it is |
|---|---|
| `crazylinks/` | The plugin. Install this folder (or a zip built from it) into `wp-content/plugins/`. |
| `docs/SPEC.md` | Product and technical specification, with decisions, build notes and a progress log. |
| `docs/DEV-ENVIRONMENT.md` | How the local development site, tests and tooling are set up. |
| `docs/screenshots/` | Current screenshots of the front end, editor and admin screens. |
| `reports/` | The competitor and ecosystem research the spec is based on. |
| `research_notes/` | Raw research notes behind the report. |

## Requirements

WordPress 6.9 or newer, PHP 8.1 or newer. AI editing needs WordPress 7.0 or newer plus an AI provider connected under Settings → Connectors.

## Developing

```sh
cd crazylinks
php /path/to/composer install        # PHP dev tools (PHPCS, PHPStan, PHPUnit)
npm ci && npm run build              # editor bundle
vendor/bin/phpcs && vendor/bin/phpstan analyse && vendor/bin/phpunit
npm run typecheck && npm run lint:js && npm run test:unit
```

See `docs/DEV-ENVIRONMENT.md` for the WordPress test library setup, Playwright end-to-end tests, and the demo content command (`wp crazylinks demo`).

## Hooks and extension points

`crazylinks/docs/hooks.md` lists every action, filter, REST route and WP-CLI command.

## License

GPL-2.0-or-later. See `LICENSE`. The plugin headers in `crazylinks/crazylinks.php` and `crazylinks/readme.txt` declare the same license. Bundled brand icons are CC0 (see `crazylinks/LICENSE-icons.txt`).
