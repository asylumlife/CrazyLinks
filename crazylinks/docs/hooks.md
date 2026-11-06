# CrazyLinks hooks reference

Every action and filter the plugin adds (Phase 1). All names carry the `crazylinks_` prefix.

## Actions

| Hook | Args | Fired |
|---|---|---|
| `crazylinks_loaded` | none | After the plugin registers its hooks (`plugins_loaded`). |
| `crazylinks_event_logged` | `int $id`, `array $row` | After an analytics event row is inserted into `{prefix}crazylinks_events`. `$row` holds the stored columns (no raw IP). |
| `crazylinks_daily_rollup` | none | WP-Cron event (daily, 00:15 UTC). Aggregates raw events into `{prefix}crazylinks_daily` and prunes raw events past the retention window. Also runnable with `wp crazylinks rollup`. |

## Filters

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_base_path` | `string $base` | | URL base for link pages (`/{base}/{slug}`). Applied on top of the Settings value. Invalid or reserved values fall back to `l`. Flush rewrite rules after changing it in code. |
| `crazylinks_analytics_enabled` | `bool $enabled` | | Master switch for view and click logging. |
| `crazylinks_role_caps` | `array $map` role → caps | | Capabilities granted on activation. Default: Administrator (all), Editor (all except `manage_crazylinks`). Add `author` here to follow SPEC §2's Author column. |
| `crazylinks_before_save` | `array $doc` | `int $post_id` | The validated, normalized page document right before it is written to `post_content`. Must stay schema-valid. |
| `crazylinks_render_block` | `string $html` | `array $block`, `RenderContext $ctx` | Rendered HTML of one block. Returned HTML is printed as-is, so escape anything you add. |
| `crazylinks_template_path` | `string $path` | `string $name` | Absolute path of a template partial (`page`, `blocks/link`, ...). Lets themes override partials. |
| `crazylinks_themes` | `array $themes` | | Design presets (slug → token set, optional `dark` overrides). |
| `crazylinks_standalone_allowed_handles` | `string[] $handles` | | Style/script handles allowed to print on standalone link pages. Everything else enqueued by themes, builders and plugins is dropped. Default: admin bar handles when the admin bar shows. |
| `crazylinks_print_seo_meta` | `bool $print` | `WP_Post $post` | Return false when an SEO plugin should own description, canonical and Open Graph tags. |
| `crazylinks_icons` | `array $icons` slug → `['title' => string, 'path' => string]` | | Brand icon registry. `path` is the `d` attribute of one path in a 24x24 viewBox (rendered with `fill="currentColor"`). Slugs are `[a-z0-9_-]`; entries with other characters in the path data are dropped. Registered slugs work in a link block's `icon` prop. |
| `crazylinks_embed_hosts` | `string[] $hosts` | | Hosts an `embed` or `video` (URL) block may fetch oEmbed data for (subdomains match). |
| `crazylinks_fetch_oembed` | `bool $enabled` | | Whether saving a page may fetch oEmbed data from the allow-listed providers. The only outbound request the plugin can make. Default: the **Embed previews** setting (`crazylinks_settings['fetch_oembed']`, on). |
| `crazylinks_visitor_ip` | `string $ip` | | Client IP used for the visitor hash and the abuse key. Default `REMOTE_ADDR`; filter to trust a proxy header. Never stored. |
| `crazylinks_view_rate_limit` | `int $limit` | | Page views one IP may log per minute across all link pages. Default 120. Over the limit the page still renders; the view is not counted. Keyed on an HMAC of the IP alone (no user agent). |
| `crazylinks_subscribe_rate_limit` | `int $limit` | | Sign-up attempts one IP may make per hour on `/subscribe`. Default 5. |
| `crazylinks_url_block_private_hosts` | `bool $block` | `string $host` | Return true to reject link URLs whose host is loopback, private, link-local or reserved (127.0.0.1, 10.x, 192.168.x, `[::1]`) or a local-only name (`localhost`, `*.local`, `*.internal`). Default false: owners may link to an intranet. |
| `crazylinks_bot_pattern` | `string $pattern` | | PCRE matched against the user agent; matches are redirected but not counted. |

## Core hooks the plugin filters

`use_block_editor_for_post_type` (off for `crazylinks_page`), `wp_insert_post_data` (validates every write, including revisions and autosaves whose parent is a link page), `post_type_link`, `request`, `query_vars` (honoured only when one of the plugin's rewrite rules matched, or with plain permalinks), `redirect_canonical`, `wp_speculation_rules_href_exclude_paths` (keeps `/go/` and `/vcard` out of prefetch), `template_redirect` (password check, view logging, `/go/` and `/vcard` responses), `transition_post_status` (a public post being published or unpublished invalidates every rendered page so `latest_posts` refreshes), `admin_init` (suggested privacy-policy text via `wp_add_privacy_policy_content`), `template_include`, `print_styles_array` / `print_scripts_array` (standalone isolation), `wp_robots`, `pre_get_document_title`, `et_builder_post_types` / `et_builder_third_party_post_types` (hide from Divi), `rest_post_dispatch` (`Cache-Control: no-store` on `crazylinks/v1`).

## Shortcode and block

- `[crazylinks id="123"]`
- Block `crazylinks/page` with attribute `id` (server-rendered).

## REST routes (`crazylinks/v1`)

| Route | Methods | Permission |
|---|---|---|
| `/pages` | GET, POST | `edit_crazylinks_pages`; publishing statuses need `publish_crazylinks_pages` |
| `/pages/{id}` | GET, PUT/PATCH, DELETE | `edit_post` / `delete_post` mapped to the custom caps |
| `/render-preview` | POST | `edit_crazylinks_pages` (+ `edit_post` when `id` is given) |
| `/analytics/summary?page_id=&range=7d\|30d\|90d` (or `from=&to=`; `days=` still accepted) | GET | `view_crazylinks_analytics` and `edit_post` on the page; response shape below under *Analytics and subscribers* |
| `/subscribe` | POST | public; honeypot, 5/hour per-IP rate limit, same visibility/schedule/password checks as `/go/`, one generic 404 for every unavailable page or form; stores a sign-up and sends a double opt-in email (see *Analytics and subscribers*) |

## Public routes (under the URL base)

| Route | Response |
|---|---|
| `/{base}/{slug}/` | The link page. Password-protected pages show only the password form, log no view and are never cached. |
| `/{base}/{slug}/go/{block_id}/` | 302 to the stored URL of a `link`, `image` or `payment` block (UTM appended when enabled), logging a click. 404 when the page or block is not publicly reachable. |
| `/{base}/{slug}/vcard/` | `text/vcard` download built from the first visible `contact` block with `props.vcard` set (profile name, phone, email, address, page URL). Same checks as `/go/`. |

With plain permalinks these are `/?crazylinks_slug={slug}`, plus `&crazylinks_go={block_id}` or `&crazylinks_vcard=1`.

## Storage

Rate-limit and de-duplication state lives in the persistent object cache (group `crazylinks_limits`) when there is one, otherwise in the `{prefix}crazylinks_limits` table (hashed keys, expired rows swept opportunistically and by the daily cron). The daily salt is one non-autoloaded option, `crazylinks_salt_{Ymd}`; the previous day's is deleted on rotation.

---

## Analytics and subscribers (Phase 2b)
## Actions

| Hook | Args | Fired |
|---|---|---|
| `crazylinks_subscriber_added` | `array $subscriber`, `int $page_id`, `string $block_id` | A new subscriber row was created (any status). |
| `crazylinks_subscriber_pending` | same | A row is waiting for email confirmation (new sign-up, or an unsubscribed address signing up again, with double opt-in on). |
| `crazylinks_subscriber_confirmed` | same | A row became confirmed: the visitor opened the confirmation link, or double opt-in is off and the sign-up was stored. |
| `crazylinks_subscriber_unsubscribed` | same | The visitor used the unsubscribe link (fires once; repeat clicks do nothing). |

`$subscriber` is an array: `id`, `page_id`, `block_id`, `email` (lowercase), `status`, `consent_text`, `consent_at`, `confirmed_at`, `created_at`, `updated_at` (UTC, MySQL format). The token hash is never passed.

## Filters

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_subscribe_handle` | `null\|true\|array\|WP_Error` | `string $email`, `int $page_id`, `string $block_id`, `array $block` | Short-circuits local storage so an adapter (MailPoet, FluentCRM, ...) can take over. Return `null` to continue locally. Return `true` or `['status' => 'pending'\|'confirmed']` when the sign-up was handled: nothing is stored locally, no email is sent, and the visitor sees the matching message. Return a `WP_Error` to reject it (the visitor sees the generic error). Runs after the honeypot, rate limit, reachability, consent and email checks. |
| `crazylinks_double_opt_in` | `bool` | | Whether sign-ups need email confirmation. Default: the **Email sign-ups** setting (on). |
| `crazylinks_confirm_ttl` | `int $seconds` | | How long a confirmation link works. Default 7 days (minimum 1 hour). |
| `crazylinks_send_welcome_email` | `bool` | `array $subscriber` | With double opt-in off, whether the one welcome email (which carries the unsubscribe link) is sent. Default true. |
| `crazylinks_country_available` | `bool` | | Whether the analytics summary reports `country_available`. Default false. |
| `crazylinks_subscribe_rate_limit` | `int` | | Existing: sign-up attempts per IP per hour (default 5). Separately, at most 3 emails per address per hour. |

## Settings (`crazylinks_settings`)

| Key | Default | Meaning |
|---|---|---|
| `retention_days` | 90 | Raw events older than this are deleted by the daily cron after rollup (0 keeps them). Replaces `retention_months`; a site that only has the old key keeps `months * 30` days. Events are never deleted before their day has been rolled up. |
| `double_opt_in` | true | Require email confirmation. |
| `subscriber_retention_days` | 90 | Pending and unsubscribed rows not changed for this long are deleted by the daily cron (0 keeps them). Confirmed rows are never deleted automatically. |

## REST routes (`crazylinks/v1`)

| Route | Methods | Permission |
|---|---|---|
| `/analytics/summary?page_id=&range=7d\|30d\|90d` or `&from=YYYY-MM-DD&to=YYYY-MM-DD` (`days=` still accepted) | GET | `view_crazylinks_analytics` and `edit_post` on the page |
| `/subscribe` | POST | public; JSON gets JSON, an HTML form post gets a 303 back to the page (`?cl_sub=<key>#cl-ec-<block>-m-<key>`) |
| `/subscribe/confirm?token=` | GET | the emailed token; 303 to the page with `?subscribed=1` (`expired` when older than 7 days) |
| `/subscribe/unsubscribe?token=` | GET | the emailed token; 303 to the page with `?unsubscribed=1` |
| `/subscribers?page_id=&status=&search=&page=&per_page=` | GET | `manage_crazylinks`; `X-WP-Total` and `X-WP-TotalPages` headers |
| `/subscribers/{id}` | DELETE | `manage_crazylinks` |

### Analytics summary response

```json
{
  "totals": {"views": 0, "uniques": 0, "clicks": 0, "ctr": 0.0},
  "timeseries": [{"date": "YYYY-MM-DD", "views": 0, "clicks": 0}],
  "blocks": [{"block_id": "", "title": "", "type": "link", "clicks": 0, "share": 0.0, "ctr": null}],
  "breakdowns": {
    "device": {"mobile": 0, "tablet": 0, "desktop": 0},
    "os": {"ios": 0},
    "in_app": {"none": 0, "instagram": 0},
    "referrer": [{"host": "l.instagram.com", "count": 0}]
  },
  "country_available": false,
  "range": {"from": "", "to": "", "days": 30},
  "events_available_from": "YYYY-MM-DD or null"
}
```

- `views`, `uniques`, `clicks`, `ctr` (null without views), `daily` and `page_id` are also returned at the top level for older callers.
- Days up to the last rollup come from `crazylinks_daily`; later days (today) come from `crazylinks_events`.
- `uniques` is the sum of each day's distinct visitors. The visitor hash salt rotates daily, so a person visiting on two days counts twice. This is the same with or without raw events.
- `breakdowns` read raw events (the daily table has no dimensions), so they only cover the retention window; `events_available_from` tells the UI where they start. Referrers are the top 10 hosts; `(direct)` means no referrer.
- `blocks` lists every link, image and payment block still on the page (zero clicks included), plus removed blocks that have clicks as `(removed block)` with type `unknown`.
- The timeseries has one row for every day in the range (zeros filled). Dates are UTC. Custom ranges are capped at 731 days.

## WP-CLI

`wp crazylinks subscribers export [--page=<id>] [--status=pending|confirmed|unsubscribed] [--format=csv|json]` writes to stdout.

## Personal data

Exporter and eraser keyed `crazylinks-subscribers` (`wp_privacy_personal_data_exporters` / `_erasers`), by email address.

---

## Editor (Phase 2a)
Hooks and REST routes added by the React page editor. The Phase 1 reference is `docs/hooks.md`.

## Actions

| Hook | Args | Fired |
|---|---|---|
| `crazylinks_page_autosaved` | `int $autosave_id`, `int $page_id` | After `POST /pages/{id}/autosave` stored or updated the user's autosave revision. Not fired for `unchanged` or for a new page saved as its first draft. |
| `crazylinks_revision_restored` | `int $page_id`, `int $revision_id` | After `POST /pages/{id}/revisions/{rev}/restore` restored a revision (core's `wp_restore_post_revision` also fires). |

## Filters

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_editor_data` | `array $data` | `WP_Post $post` | Everything the editor boots with, printed as `window.crazylinks`: `post` (the `GET /pages/{id}` payload plus `isNew`, `viewUrl`, `classicUrl`, `listUrl`, `baseUrl`), `autosave`, `schema`, `themes`, `icons`, `networks`, `payments`, `blockCatalog`, `media`, `caps`, `ai`, `rest`, `settings`, `version`. |
| `crazylinks_editor_block_catalog` | `array $catalog` | | One `{type, available, reason}` entry per block type in `schemas/page.v1.json`. Unavailable types stay listed in the add-block drawer, disabled, with the reason. Defaults: `html` needs `unfiltered_html`; `woo_products` needs WooCommerce. |
| `crazylinks_editor_ai_available` | `bool $available` | | Whether the AI tab shows (`window.crazylinks.ai.available`). Default: the user has `use_crazylinks_ai` and core's AI client reports a text model. Phase 3 fills the tab. |
| `crazylinks_editor_autosave_interval` | `int $seconds` | | Seconds of inactivity before the editor autosaves. Default 10, minimum 5. |

## Core hooks the editor uses

- `replace_editor`: takes over `post.php?action=edit` and `post-new.php` for `crazylinks_page` (only after `load-post.php` / `load-post-new.php`, because `WP_Screen::get()` applies the same filter earlier). `?crazylinks_classic=1` keeps the classic screen with the Page JSON meta box; the editor's `<noscript>` links there.
- `admin_body_class`: adds `crazylinks-editor-screen` on the editor screen only.
- Assets (`crazylinks-editor` script and style, `wp_enqueue_media()`) are enqueued from the `replace_editor` callback, so they never load on the front end or on other admin screens.

## REST routes (`crazylinks/v1`)

All routes need `edit_post` on the page (mapped to the CrazyLinks capabilities) and send `Cache-Control: no-store`.

| Route | Method | Body / response |
|---|---|---|
| `/pages/{id}/autosave` | POST | Body `{page, title?}`. Validated by `PageValidator` like a normal save; `400 crazylinks_invalid_page` with `data.errors[] = {path, message}` (JSON paths such as `/blocks/3/props/url`) on failure. Response `{mode, autosave_id, modified, page}`. `mode` is `autosave` (stored as the user's WordPress autosave revision, one per user), `draft` (a never-saved `auto-draft` page is saved as a draft instead) or `unchanged` (matches the saved page; any stale autosave is deleted). |
| `/pages/{id}/revisions` | GET | Revisions and autosaves, newest first: `{id, date, author:{id,name}, autosave, title, blocks}`. Other users' autosaves are listed only for users with `edit_others_crazylinks_pages`. |
| `/pages/{id}/revisions/{rev}` | GET | One revision with `parent` and its `page` document. 404 if it belongs to another page. |
| `/pages/{id}/revisions/{rev}/restore` | POST | Validates the revision against the current page first (so the `unfiltered_html` gates on custom HTML and CSS return 400 instead of being silently dropped), then `wp_restore_post_revision()`. Core keeps the replaced content as a new revision. Response: the `GET /pages/{id}` shape plus `restored_from`. |
| `/render-preview` | POST | Unchanged input. The response now also has `document`: a complete HTML document shaped like the standalone template (same `cl-body` / `cl-main` wrappers, inline plugin CSS and front-end JS, no `wp_head()`), which the editor loads into a sandboxed `<iframe srcdoc>`. |

## Editor data store

`crazylinks/editor` (`@wordpress/data`). Other scripts on the editor screen can read it, e.g. `wp.data.select( 'crazylinks/editor' ).getDoc()`, and dispatch `replaceDoc( doc )` (undoable). Phase 3 (AI) is expected to apply proposals through `replaceDoc`.

---

## v1.1 polish (footer credit, social item clicks, warnings, post lock)
Everything below was added by the v1.1 polish pass. `docs/hooks.md` itself is untouched; merge these rows when the AI work lands.

## Filters

| Hook | Value | Purpose |
|---|---|---|
| `crazylinks_footer_credit_text` | `string $text` | Text of the "Made with CrazyLinks" line. Default `Made with CrazyLinks`. Return `''` to hide the line. |
| `crazylinks_footer_credit_url` | `string $url` | Target of the credit link. Default `https://wordpress.org/plugins/crazylinks/`. Only http(s) is printed; the link carries `rel="nofollow noopener"`. |

Both apply in every rendering mode (standalone, theme, embed, editor preview). The line only exists when the page has `settings.footer_credit` set to `true` (off by default, SPEC §17 Q2).

`crazylinks_editor_data` (existing) now also carries `lock` (see Post locking).

## Public routes

| Route | Response |
|---|---|
| `/{base}/{slug}/go/{block_id}/{item_id}/` | 302 to the URL of one social icon (`socials` block item). The click is logged against `block_id`. 404 for an unknown item, a `mailto:`/`tel:`/`sms:` item, a block without items, or a malformed id. |

`/{base}/{slug}/go/{block_id}/` is unchanged for `link`, `image` and `payment`. For a `socials` block it is a 404 (an icon click always names its item). The rewrite regex changed, so the plugin flushes rewrite rules once on the first request after the update (`Router::maybe_flush()` compares the stored rules with the current ones; plain permalinks store none and are unaffected). Query-string form: `?crazylinks_slug=..&crazylinks_go=..&crazylinks_item=..`.

`ClickRedirect::TYPES` now includes `socials`, so the analytics per-block table lists a socials block with its clicks.

### Why social clicks are per block, not per network

The events table stores `block_id CHAR(26)`. Encoding `{block_id}:{item_id}` needs 53 characters, so the column is too narrow, and this pass may not change `Activator`. Decision: icon clicks are logged against the socials block id only. The analytics screen therefore shows one row for the whole icon row, and per-network counts are "not yet available". A follow-up that adds a nullable `item_id CHAR(26)` column to `crazylinks_events` and `crazylinks_daily` (and widens the daily primary key) would unlock per-network numbers; the redirect already knows the item and `EventLogger::insert()` is the one place to pass it.

De-duplication and rate limiting include the item id, so a visitor tapping two different icons within ten seconds logs two clicks.

## Page document additions (schema `page.v1.json`, still `schema: 1`)

| Where | Key | Meaning |
|---|---|---|
| `settings` | `footer_credit` (bool) | Show the credit line. |
| `profile` | `banner_focal_point` `{x,y}` 0..1 | `object-position` of the banner image. |
| `link` props | `focal_point` `{x,y}` (already in schema, now rendered), `tile_size` `1x1|2x1|2x2` (already in schema, now rendered), `thumbnail_alt` (string), `decorative` (bool) | Focal point of the thumbnail or featured image; bento size; alt text; decorative flag. |
| `image` props | `decorative` (bool) | Empty alt, no warning. |
| `gallery` items | `decorative` (bool) | Same, per image. |
| `socials` items | `id` (ULID) | Generated by the validator when missing. An item without an id in the previous document with the same network and URL keeps its old id, so API clients that omit ids keep stable click URLs. Duplicate ids inside a block are replaced. Documents saved before this change render their icons as direct links until they are saved once. |
| `email_capture` props | `success_text`, `pending_text` (strings, 200) | Replace the default "confirmed" and "pending" messages for this form only. |

`tile_size` 2x1 and 2x2 apply in the Grid layout only: both span the full width (2x2 is also taller and uses a square thumbnail). In list and featured layouts the class is present but inert.

## Alt-text warnings (SPEC §5A.6)

`ValidationResult::warnings()` returns `[ { path, message, code: "missing_alt", block_id? } ]` for a valid document: an `image` block, a `gallery` item or a `link` thumbnail whose effective alt text is empty and which is not `decorative`, and an avatar on a page without a name. The effective alt text is the block's own, else the attachment's Media Library alt text; the renderer falls back the same way. `PageValidator::warnings_for( $doc )` is the static form. Warnings never block a save. The editor computes the same list client-side (`lib/warnings.ts`) so they show inline immediately and the Publish/Update button asks "Publish anyway".

**REST (not done here, `src/Rest/**` is out of scope):** `PagesController` and `PreviewController` should add `'warnings' => $result->warnings()` to their success responses (create, update, render-preview) and `warnings` to the item schema. The editor does not depend on it.

## Post locking

`CrazyLinks\PostLock` (hooked from `PostType::register_hooks()`) uses core's lock: when the editor boots it calls `wp_check_post_lock()`; if nobody else holds the page it calls `wp_set_post_lock()`. The result is added to the boot data as `lock`:

```
lock: { locked: bool, postId, nonce, activeLock: "time:user", user: {name, avatar}|null, takeOverUrl }
```

`takeOverUrl` is core's own (`post.php?post=ID&action=edit&get-post-lock=1&_wpnonce=lock-post_ID`). The editor listens to core's heartbeat (`wp-refresh-post-lock`) to keep the lock and to notice a takeover, shows a banner with "Take over", stops saving and autosaving while locked out, and releases the lock on `pagehide` through core's `wp-remove-post-lock` AJAX action. Cmd/Ctrl+S on a live page saves without the alt-text prompt (only the Publish/Update buttons ask).

## Editor behaviour notes

- Drag between groups: a block dropped on another block takes its place in that block's list (down inside one list = after, otherwise before); each group has an "end of group" drop zone that appears while a non-group block is dragged. Groups never nest. The keyboard path is the same drag (arrow keys) or the Group selector in the block panel.
- Contrast chips (Design tab): body text, headings (3:1), muted text on the page and on cards, accent, button text (by fill), sign-up button, badge, link badges with a custom colour (white text), in light and, when the theme has one, dark mode. Mirrors `Render\Contrast::pairs()`.
- Focal point picker: click the image or use the two sliders.

## SPEC lines that are now inaccurate

- §7.5 "social icons are rendered direct and not click-tracked. Follow-up (v1.1): per-item ids" -> done for http(s) icons; `mailto:`/`tel:`/`sms:` stay direct; per-network counts still pending (see above).
- §9 "Not yet (v1.1)" list: footer credit, alt text before publish, post locking, drag between groups, contrast warnings, focal point and bento tile sizes are now built. Still not built: pixel and redirect settings.
- §17 Q2: footer credit is built.

---

## AI editing (Phase 3)
Hooks, REST routes, abilities and CLI commands added by AI editing (SPEC §10). Merge into `docs/hooks.md` when convenient.

## Actions

| Hook | Args | Fired |
|---|---|---|
| `crazylinks_ai_applied` | `int $page_id`, `int\|null $revision_id`, `string $source` (`admin`\|`mcp`), `array $operations` | After an AI proposal was applied and the page saved (editor Apply or the `crazylinks/apply-changes` ability). |
| `crazylinks_daily_rollup` | none | Existing cron event; AI also hooks it (priority 20) to prune `{prefix}crazylinks_ai_log` rows older than the retention setting. |

## Filters

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_ai_enabled` | `bool $on` | | Site switch. Default: the **AI editing** setting is on *and* the owner ticked the consent box. |
| `crazylinks_ai_driver` | `AiDriver $driver` | | Swap the AI backend (default `Ai\WpAiClientDriver`, core's AI Client). |
| `crazylinks_ai_models` | `string[] $models` | `string $purpose` (`edit`\|`suggest`) | Model preference passed to `using_model_preference()`. Default: the setting, then Claude Sonnet 5.5 / 5 / 4.6 for edits and Claude Haiku 5.5 / 4.5 for suggestions. Core falls back to any capable model when none match. |
| `crazylinks_ai_ops_schema` | `array $schema` | | JSON schema sent to the provider through `as_json_response()`. Default: `schemas/ai-ops.v1.json` without the keywords structured-output engines reject (`minLength`, `maxLength`, `minimum`, `maximum`, `pattern`, `minItems`, `maxItems`, `uniqueItems`). Responses are always validated against the full file. |
| `crazylinks_ai_system_prompt` | `string $prompt` | `string $mode` | System instruction for proposals. Keep the TRUST BOUNDARY paragraph. |
| `crazylinks_ai_site_facts` | `array $facts` | `int $user_id` | Public facts sent for "Build from this site": site name, tagline, home URL, 10 latest published posts, up to 3 menus, the user's display name, website, bio and social profile URLs, and up to 10 published WooCommerce products. Never add secrets, emails or visitor data. |
| `crazylinks_ai_rate_limits` | `array{user_hourly:int, site_daily:int}` | `int $user_id` | Request limits (0 = none). Default: settings, 30 per user per hour and 300 per site per day. Exceeding either returns HTTP 429. |
| `crazylinks_ai_monthly_tokens` | `int $tokens` | | Monthly token budget for the site (0 = unlimited). Default: setting, 2,000,000. Warning at 80%, hard stop (HTTP 429) at 100%. |
| `crazylinks_ability_enabled` | `bool $on` | `string $name` | Whether an ability may run. Default: the per-ability checkboxes under CrazyLinks → Settings → AI (all off). |
| `crazylinks_editor_ai_available` | `bool $available` | | Existing filter: `window.crazylinks.ai.available`. The AI tab itself uses `ai.ready` / `ai.reason` to choose between the tools and the one-line pointer. |

Core hooks honoured: `wp_supports_ai` (AI off for the request), `wp_ai_client_prevent_prompt` (a prevented prompt returns `crazylinks_ai_prevented`, 503), `wp_ai_client_default_request_timeout` (CrazyLinks sets its own timeout, default 60 s, only for its own requests).

## REST routes (`crazylinks/v1`, cookie auth + `X-WP-Nonce`, or application passwords)

| Route | Permission | Body | Returns |
|---|---|---|---|
| `POST /pages/{id}/ai/propose` | `use_crazylinks_ai` + `edit_post` | `instruction`, `mode` (`edit`\|`build_from_site`\|`build_from_description`\|`restyle`\|`analytics_qa`), `questions_answers[]`, optional `page` (editor working copy) | `proposal_id` (null when only questions), `summary`, `operations`, `questions`, `preview_document`, `change_list[] {kind, text, block_id}`, `expires_at`, `model`, `usage`, `budget` |
| `POST /pages/{id}/ai/apply` | same | `proposal_id`, optional `operations` (edited), optional `page` | `applied`, `revision_id`, `source`, `rebased`, `edited`, `change_list`, `page` (PagesController shape); `already_applied: true` on repeats |
| `POST /pages/{id}/ai/suggest` | same | `kind` (`titles`\|`bio`), `context` | `suggestions[]` |
| `POST /pages/{id}/ai/settings` | same | `require_confirmation` | Per-page "external agents need confirmation" flag (post meta `_crazylinks_ai_confirm`) |
| `GET /ai/status` | `use_crazylinks_ai` | | `available`, `reason`, `message`, `providers`, `models`, `limits`, `budget` |

Errors: 400 invalid operations (`data.errors[] {path, message}`), 403/401 permissions, 409 `crazylinks_ai_conflict` (page changed and the operations no longer fit) or `crazylinks_ai_confirmation_required`, 410 `crazylinks_ai_proposal_expired` (30 minutes, other user or page), 422 `crazylinks_ai_invalid_output` (model output invalid after one retry), 429 rate limit or budget, 503 unavailable / no provider / prevented.

`GET /pages/{id}/revisions` (and `/revisions/{rev}`) items gain `ai: {source, label}` (`AI` or `AI (external)`) for revisions created by an AI apply, `null` otherwise.

## Abilities (category `crazylinks`, REST `wp-abilities/v1`)

| Ability | Annotations | HTTP method on `/run` | Permission |
|---|---|---|---|
| `crazylinks/list-pages` | readonly | GET | `edit_crazylinks_pages` |
| `crazylinks/get-page` | readonly | GET | `edit_post` on `id` |
| `crazylinks/propose-changes` | readonly | GET | `use_crazylinks_ai` + `edit_post`; takes `instruction` (site AI writes the operations) or `operations` (agent-written, validated, no provider needed) |
| `crazylinks/apply-changes` | destructive, idempotent | DELETE | `edit_post`; refused with 409 when the page requires confirmation |
| `crazylinks/get-analytics-summary` | readonly | GET | `view_crazylinks_analytics` + `edit_post` |
| `crazylinks/suggest-titles` | readonly | GET | `use_crazylinks_ai` |

Every ability is off until enabled under CrazyLinks → Settings → AI.

## WP-CLI

- `wp crazylinks ai status [--user=<user>]`: client, provider, setting, models, budget, availability for the user.
- `wp crazylinks ai eval [--offline] [--grade] [--case=<id>] [--limit=<n>]`: the eval set in `tests/fixtures/ai/` (dev repository only). Live by default and only when a provider is configured; otherwise prints a notice and exits 0. `--offline` replays the recorded responses; `--grade` asks a Haiku-class model to grade each result against its rubric.

| `crazylinks_ai_structured_output` | `bool` | | Ask the provider for schema-constrained JSON (default false: Anthropic rejects the operations grammar as too large; the prompt requests bare JSON and the server validates it, retrying once with the errors). |
