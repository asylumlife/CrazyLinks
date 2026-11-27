# CrazyLinks developer reference

Every extension point the plugin adds: actions, filters, REST routes, public routes, abilities, WP-CLI commands, settings and stored data. All hook names carry the `crazylinks_` prefix.

- [Actions](#actions)
- [Filters](#filters)
- [Core hooks the plugin uses](#core-hooks-the-plugin-uses)
- [REST routes](#rest-routes-crazylinksv1)
- [Public routes](#public-routes-under-the-url-base)
- [Shortcode and block](#shortcode-and-block)
- [Abilities and the MCP server](#abilities-and-the-mcp-server)
- [WP-CLI](#wp-cli)
- [Settings, options and storage](#settings-options-and-storage)
- [Page document notes](#page-document-notes)
- [Editor](#editor)
- [Product block and store](#product-block-and-store)
- [Mailchimp](#mailchimp)

## Actions

| Hook | Args | Fired |
|---|---|---|
| `crazylinks_loaded` | none | After the plugin registers its hooks (`plugins_loaded`). |
| `crazylinks_event_logged` | `int $id`, `array $row` | After an analytics event row is inserted into `{prefix}crazylinks_events`. `$row` holds the stored columns (no raw IP); for a click on a social icon or a listed product, `item_id` says which one. |
| `crazylinks_page_autosaved` | `int $autosave_id`, `int $page_id` | After `POST /pages/{id}/autosave` stored or updated the user's autosave revision. Not fired for `unchanged` or for a new page saved as its first draft. |
| `crazylinks_revision_restored` | `int $page_id`, `int $revision_id` | After `POST /pages/{id}/revisions/{rev}/restore` restored a revision (core's `wp_restore_post_revision` also fires). |
| `crazylinks_subscriber_added` | `array $subscriber`, `int $page_id`, `string $block_id` | A new subscriber row was created (any status). |
| `crazylinks_subscriber_pending` | same | A row is waiting for email confirmation (new sign-up, or an unsubscribed address signing up again, with double opt-in on). |
| `crazylinks_subscriber_confirmed` | same | A row became confirmed: the visitor opened the confirmation link, double opt-in is off and the sign-up was stored, or Mailchimp reported the subscription. |
| `crazylinks_subscriber_unsubscribed` | same | The visitor used the unsubscribe link, or Mailchimp reported an unsubscribe or a cleaned address (fires once; repeat clicks do nothing). |
| `crazylinks_ai_applied` | `int $page_id`, `int\|null $revision_id`, `string $source` (`admin`\|`mcp`), `array $operations` | After an AI proposal was applied and the page saved (editor Apply, the `crazylinks/apply-changes` ability, or `crazylinks/apply-operations`). A pending change from an external agent confirmed in the editor reports `mcp`. |

`$subscriber` is an array: `id`, `page_id`, `block_id`, `email` (lowercase), `status`, `consent_text`, `consent_at`, `confirmed_at`, `created_at`, `updated_at` (UTC, MySQL format), plus the sync columns `destination`, `audience_id`, `sync_status`, `sync_attempts`, `sync_error`, `synced_at`, `external_id`. The token hash is never passed.

### Cron events

| Hook | Args | Purpose |
|---|---|---|
| `crazylinks_daily_rollup` | none | Daily at 00:15 UTC. Aggregates raw events into `{prefix}crazylinks_daily`, prunes raw events past the retention window, sweeps expired rate-limit rows and stale subscriber rows, and (priority 20) prunes `{prefix}crazylinks_ai_log` rows older than the AI log retention setting. Also runnable with `wp crazylinks rollup`. |
| `crazylinks_subscriber_sync` | `int $id` | One retry of a failed push to the email service. |
| `crazylinks_subscriber_backfill` | none | One backfill batch (25 rows); schedules the next batch 5 seconds later until done. |

## Filters

### Pages and rendering

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_base_path` | `string $base` | | URL base for link pages (`/{base}/{slug}`). Applied on top of the Settings value. Invalid or reserved values fall back to `l`. Flush rewrite rules after changing it in code. |
| `crazylinks_role_caps` | `array $map` role → caps | | Capabilities granted on activation. Default: Administrator (all), Editor (all except `manage_crazylinks`). Add `author` here to let Authors manage their own link pages. |
| `crazylinks_before_save` | `array $doc` | `int $post_id` | The validated, normalized page document right before it is written to `post_content`. Must stay schema-valid. |
| `crazylinks_render_block` | `string $html` | `array $block`, `RenderContext $ctx` | Rendered HTML of one block. Returned HTML is printed as-is, so escape anything you add. |
| `crazylinks_template_path` | `string $path` | `string $name` | Absolute path of a template partial (`page`, `blocks/link`, ...). Lets themes override partials. |
| `crazylinks_themes` | `array $themes` | | Design presets (slug → token set, optional `dark` overrides). |
| `crazylinks_icons` | `array $icons` slug → `['title' => string, 'path' => string]` | | Brand icon registry. `path` is the `d` attribute of one path in a 24x24 viewBox (rendered with `fill="currentColor"`). Slugs are `[a-z0-9_-]`; entries with other characters in the path data are dropped. Registered slugs work in a link block's `icon` prop. |
| `crazylinks_standalone_allowed_handles` | `string[] $handles` | | Style/script handles allowed to print on standalone link pages. Everything else enqueued by themes, builders and plugins is dropped. Default: admin bar handles when the admin bar shows. |
| `crazylinks_print_seo_meta` | `bool $print` | `WP_Post $post` | Return false when an SEO plugin should own description, canonical and Open Graph tags. |
| `crazylinks_footer_credit_text` | `string $text` | | Text of the "Made with CrazyLinks" line. Default `Made with CrazyLinks`. Return `''` to hide the line. |
| `crazylinks_footer_credit_url` | `string $url` | | Target of the credit link. Default `https://wordpress.org/plugins/crazylinks/`. Only http(s) is printed; the link carries `rel="nofollow noopener"`. |
| `crazylinks_embed_hosts` | `string[] $hosts` | | Hosts an `embed` or `video` (URL) block may fetch oEmbed data for (subdomains match). |
| `crazylinks_fetch_oembed` | `bool $enabled` | | Whether saving a page may fetch oEmbed data from the allow-listed providers. Default: the **Embed previews** setting (`fetch_oembed`, on). |
| `crazylinks_url_block_private_hosts` | `bool $block` | `string $host` | Return true to reject link URLs whose host is loopback, private, link-local or reserved (127.0.0.1, 10.x, 192.168.x, `[::1]`) or a local-only name (`localhost`, `*.local`, `*.internal`). Default false: owners may link to an intranet. |

The two footer-credit filters apply in every rendering mode (standalone, theme, embed, editor preview). The line only exists when the page has `settings.footer_credit` set to `true` (off by default).

### Analytics and abuse limits

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_analytics_enabled` | `bool $enabled` | | Master switch for view and click logging. |
| `crazylinks_visitor_ip` | `string $ip` | | Client IP used for the visitor hash and the abuse key. Default `REMOTE_ADDR`; filter to trust a proxy header. Never stored. |
| `crazylinks_bot_pattern` | `string $pattern` | | PCRE matched against the user agent; matches are redirected but not counted. |
| `crazylinks_view_rate_limit` | `int $limit` | | Page views one IP may log per minute across all link pages. Default 120. Over the limit the page still renders; the view is not counted. Keyed on an HMAC of the IP alone (no user agent). |
| `crazylinks_country_available` | `bool` | | Whether the analytics summary reports `country_available`. Default false. |

### Email sign-ups

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_subscribe_handle` | `null\|true\|array\|WP_Error` | `string $email`, `int $page_id`, `string $block_id`, `array $block` | Short-circuits local storage so another plugin (MailPoet, FluentCRM, ...) can take over. Return `null` to continue locally. Return `true` or `['status' => 'pending'\|'confirmed']` when the sign-up was handled: nothing is stored locally, no email is sent, and the visitor sees the matching message. Return a `WP_Error` to reject it (the visitor sees the generic error). Runs after the honeypot, rate limit, reachability, consent and email checks. |
| `crazylinks_subscribe_rate_limit` | `int $limit` | | Sign-up attempts one IP may make per hour on `/subscribe`. Default 5. Separately, at most 3 emails are sent per address per hour. |
| `crazylinks_double_opt_in` | `bool` | | Whether sign-ups need email confirmation. Default: the **Email sign-ups** setting (on). |
| `crazylinks_confirm_ttl` | `int $seconds` | | How long a confirmation link works. Default 7 days (minimum 1 hour). |
| `crazylinks_send_welcome_email` | `bool` | `array $subscriber` | With double opt-in off, whether the one welcome email (which carries the unsubscribe link) is sent. Default true. |
| `crazylinks_subscriber_adapters` | `array<string, Adapter>` | | Register more email services (Kit, beehiiv, a generic webhook): an object implementing `CrazyLinks\Subscribers\Adapters\Adapter` under its `id()`. |
| `crazylinks_sync_inline_timeout` | `int $seconds` | | Timeout of the push to the email service made while the visitor waits. Default 5. |
| `crazylinks_sync_retry_delay` | `int $seconds` | `int $attempts` | Wait before the next attempt (60 × 4^(attempts−1), at most 6 hours). |
| `crazylinks_webhook_rate_limit` | `int $limit` | | Mailchimp webhook deliveries accepted per IP per minute. Default 120. |

### Editor

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_editor_data` | `array $data` | `WP_Post $post` | Everything the editor boots with, printed as `window.crazylinks`: `post` (the `GET /pages/{id}` payload plus `isNew`, `viewUrl`, `classicUrl`, `listUrl`, `baseUrl`), `autosave`, `schema`, `themes`, `icons`, `networks`, `payments`, `blockCatalog`, `media`, `caps`, `ai`, `rest`, `settings`, `version`, `lock` (see [Post locking](#post-locking)). |
| `crazylinks_editor_block_catalog` | `array $catalog` | | One `{type, available, reason}` entry per block type in `schemas/page.v1.json`. Unavailable types stay listed in the add-block drawer, disabled, with the reason. Defaults: `html` needs `unfiltered_html`; `woo_products` needs WooCommerce. |
| `crazylinks_editor_ai_available` | `bool $available` | | Value of `window.crazylinks.ai.available`. Default: the user has `use_crazylinks_ai` and core's AI client reports a text model. The AI tab itself uses `ai.ready` / `ai.reason` to choose between the tools and a one-line pointer to the settings. |
| `crazylinks_editor_autosave_interval` | `int $seconds` | | Seconds of inactivity before the editor autosaves. Default 10, minimum 5. |

### AI editing and MCP

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_ai_enabled` | `bool $on` | | Site switch. Default: the **AI editing** setting is on *and* the consent box is ticked. |
| `crazylinks_ai_driver` | `AiDriver $driver` | | Swap the AI backend (default `Ai\WpAiClientDriver`, core's AI Client). |
| `crazylinks_ai_models` | `string[] $models` | `string $purpose` (`edit`\|`suggest`) | Model preference passed to `using_model_preference()`. Default: the setting, then Claude Sonnet 5.5 / 5 / 4.6 for edits and Claude Haiku 5.5 / 4.5 for suggestions. Core falls back to any capable model when none match. |
| `crazylinks_ai_ops_schema` | `array $schema` | | JSON schema sent to the provider through `as_json_response()`. Default: `schemas/ai-ops.v1.json` without the keywords structured-output engines reject (`minLength`, `maxLength`, `minimum`, `maximum`, `pattern`, `minItems`, `maxItems`, `uniqueItems`). Responses are always validated against the full file. |
| `crazylinks_ai_structured_output` | `bool` | | Ask the provider for schema-constrained JSON. Default false: Anthropic rejects the operations grammar as too large, so the prompt requests bare JSON and the server validates it, retrying once with the errors. |
| `crazylinks_ai_system_prompt` | `string $prompt` | `string $mode` | System instruction for proposals. Keep the TRUST BOUNDARY paragraph. |
| `crazylinks_ai_site_facts` | `array $facts` | `int $user_id` | Public facts sent for "Build from this site": site name, tagline, home URL, 10 latest published posts, up to 3 menus, the user's display name, website, bio and social profile URLs, up to 10 published WooCommerce products, and `audiences` (id and name) when Mailchimp is connected. Never add secrets, emails or visitor data. |
| `crazylinks_ai_rate_limits` | `array{user_hourly:int, site_daily:int}` | `int $user_id` | Request limits (0 = none). Default: settings, 30 per user per hour and 300 per site per day. Exceeding either returns HTTP 429. |
| `crazylinks_ai_monthly_tokens` | `int $tokens` | | Monthly token budget for the site (0 = unlimited). Default: setting, 2,000,000. Warning at 80%, hard stop (HTTP 429) at 100%. |
| `crazylinks_ability_enabled` | `bool $on` | `string $name` | Whether an ability may run and whether the MCP Adapter may expose it (`meta.public` / `meta.mcp.public` are set from it when the abilities are registered, once per request). Default: the per-ability checkboxes under CrazyLinks → Settings → AI (all off). |
| `crazylinks_mcp_server` | `bool $enabled` | | Whether CrazyLinks adds its own MCP server (`/wp-json/crazylinks/mcp`) when the MCP Adapter plugin is active. Default: the **External agents (MCP)** setting (`ai_mcp_server`, on). Enabled abilities stay reachable through the adapter's default server either way. |
| `crazylinks_mcp_default_server_off` | `bool $off` | | Whether CrazyLinks turns off the MCP Adapter's default server (it hooks `mcp_adapter_create_default_server` and returns `false`). Default: the **Turn off the MCP Adapter's default server** setting (`ai_mcp_default_server_off`, off). The adapter reads its filter in `McpAdapter::init()` on `rest_api_init` 15 (`init` 20 under WP-CLI), after plugins load, so CrazyLinks registers its callback at boot. |

### Store

| Hook | Value | Extra args | Purpose |
|---|---|---|---|
| `crazylinks_store_cache_ttl` | `int $seconds` | `string $kind` (`product`, `latest`, `search`) | How long fetched store data is served without asking the store again. Default 900. |
| `crazylinks_store_allow_http` | `bool $allow` | | Whether an `http://` store address is accepted. Default: `wp_get_environment_type() === 'local'`. |

## Core hooks the plugin uses

**Front end and saving:** `use_block_editor_for_post_type` (off for `crazylinks_page`), `wp_insert_post_data` (validates every write, including revisions and autosaves whose parent is a link page), `post_type_link`, `request`, `query_vars` (honoured only when one of the plugin's rewrite rules matched, or with plain permalinks), `redirect_canonical`, `pre_get_posts` (link pages are never listed in archives, searches or feeds), `wp_speculation_rules_href_exclude_paths` (keeps `/go/` and `/vcard` out of prefetch), `template_redirect` (password check, view logging, `/go/` and `/vcard` responses), `transition_post_status` (a public post being published or unpublished invalidates every rendered page so `latest_posts` refreshes), `admin_init` (suggested privacy-policy text via `wp_add_privacy_policy_content`), `template_include`, `print_styles_array` / `print_scripts_array` (standalone isolation), `wp_robots`, `pre_get_document_title`, `et_builder_post_types` / `et_builder_third_party_post_types` (hide from Divi), `rest_post_dispatch` (`Cache-Control: no-store` on `crazylinks/v1`).

**Editor:**

- `replace_editor`: takes over `post.php?action=edit` and `post-new.php` for `crazylinks_page` (only after `load-post.php` / `load-post-new.php`, because `WP_Screen::get()` applies the same filter earlier). `?crazylinks_classic=1` keeps the classic screen with the Page JSON meta box; the editor's `<noscript>` links there.
- `admin_body_class`: adds `crazylinks-editor-screen` on the editor screen only.
- Assets (`crazylinks-editor` script and style, `wp_enqueue_media()`) are enqueued from the `replace_editor` callback, so they never load on the front end or on other admin screens.

**AI:** `wp_supports_ai` (AI off for the request), `wp_ai_client_prevent_prompt` (a prevented prompt returns `crazylinks_ai_prevented`, 503), `wp_ai_client_default_request_timeout` (CrazyLinks sets its own timeout, default 60 s, only for its own requests), `mcp_adapter_init` and `mcp_adapter_create_default_server` (see [MCP server](#mcp-server)).

**Personal data:** `wp_privacy_personal_data_exporters` / `_erasers`, keyed `crazylinks-subscribers` (sign-ups, by email address) and `crazylinks-ai` (AI log rows of a user). The subscribers eraser also removes the address permanently from every Mailchimp audience it was sent to (`delete-permanent`; already gone counts as removed). When Mailchimp cannot be reached the local rows are still deleted and the eraser reports `items_retained` with a message naming the audience. The exporter includes Destination and "Sent to email service".

## REST routes (`crazylinks/v1`)

Authentication is the WordPress cookie with `X-WP-Nonce`, or an application password. Every response carries `Cache-Control: no-store`.

### Pages, preview and revisions

| Route | Methods | Permission | Notes |
|---|---|---|---|
| `/pages` | GET, POST | `edit_crazylinks_pages`; publishing statuses need `publish_crazylinks_pages` | |
| `/pages/{id}` | GET, PUT/PATCH, DELETE | `edit_post` / `delete_post` mapped to the custom caps | |
| `/render-preview` | POST | `edit_crazylinks_pages` (+ `edit_post` when `id` is given) | Returns `html` and `document`: a complete HTML document shaped like the standalone template (same `cl-body` / `cl-main` wrappers, inline plugin CSS and front-end JS, no `wp_head()`), which the editor loads into a sandboxed `<iframe srcdoc>`. |
| `/pages/{id}/autosave` | POST | `edit_post` | Body `{page, title?}`. Validated like a normal save. Response `{mode, autosave_id, modified, page}`. `mode` is `autosave` (stored as the user's WordPress autosave revision, one per user), `draft` (a never-saved `auto-draft` page is saved as a draft instead) or `unchanged` (matches the saved page; any stale autosave is deleted). |
| `/pages/{id}/revisions` | GET | `edit_post` | Revisions and autosaves, newest first: `{id, date, author:{id,name}, autosave, title, blocks, ai}`. Other users' autosaves are listed only for users with `edit_others_crazylinks_pages`. `ai` is `{source, label}` (`AI` or `AI (external)`) for revisions created by an AI apply, `null` otherwise. |
| `/pages/{id}/revisions/{rev}` | GET | `edit_post` | One revision with `parent` and its `page` document. 404 if it belongs to another page. |
| `/pages/{id}/revisions/{rev}/restore` | POST | `edit_post` | Validates the revision against the current page first (so the `unfiltered_html` gates on custom HTML and CSS return 400 instead of being silently dropped), then `wp_restore_post_revision()`. Core keeps the replaced content as a new revision. Response: the `GET /pages/{id}` shape plus `restored_from`. |

A document that fails validation returns `400 crazylinks_invalid_page` with `data.errors[] = {path, message}` (JSON paths such as `/blocks/3/props/url`). Successful create, update and render-preview responses carry `warnings[]` (see [Validation warnings](#validation-warnings)); plain reads do not re-validate.

### Analytics

| Route | Methods | Permission |
|---|---|---|
| `/analytics/summary?page_id=&range=7d\|30d\|90d` or `&from=YYYY-MM-DD&to=YYYY-MM-DD` (`days=` still accepted) | GET | `view_crazylinks_analytics` and `edit_post` on the page |

```json
{
  "totals": {"views": 0, "uniques": 0, "clicks": 0, "ctr": 0.0},
  "timeseries": [{"date": "YYYY-MM-DD", "views": 0, "clicks": 0}],
  "blocks": [{"block_id": "", "title": "", "type": "link", "clicks": 0, "share": 0.0, "ctr": null,
              "items": [{"item_id": "", "network": "", "title": "", "clicks": 0, "share": 0.0}]}],
  "sources": [{"source": "instagram", "label": "Instagram", "views": 0, "clicks": 0, "ctr": null, "top": []}],
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
- `breakdowns` and `sources` read raw events (the daily table has no dimensions), so they only cover the retention window; `events_available_from` tells the UI where they start. Referrers are the top 10 hosts; `(direct)` means no referrer.
- `blocks` lists every link, image, payment, socials and product block still on the page (zero clicks included), plus removed blocks that have clicks as `(removed block)` with type `unknown`. `items` is present for blocks whose clicks name an item: one row per social icon or listed product.
- `sources` attributes each click to the source of the same visitor's latest view that UTC day: the `utm_source`, else the in-app browser, else the referrer host; unmatched clicks are `unknown`. `top` holds the most-clicked links of that source.
- The timeseries has one row for every day in the range (zeros filled). Dates are UTC. Custom ranges are capped at 731 days.

### Email sign-ups

| Route | Methods | Permission | Notes |
|---|---|---|---|
| `/subscribe` | POST | public | Honeypot, 5 per hour per-IP rate limit, the same visibility, schedule and password checks as `/go/`, and one generic 404 for every unavailable page or form. Stores a sign-up and sends a double opt-in email. JSON gets JSON; an HTML form post gets a 303 back to the page (`?cl_sub=<key>#cl-ec-<block>-m-<key>`). |
| `/subscribe/confirm?token=` | GET | the emailed token | 303 to the page with `?subscribed=1` (`expired` when the link is older than 7 days). |
| `/subscribe/unsubscribe?token=` | GET | the emailed token | 303 to the page with `?unsubscribed=1`. |
| `/subscribers?page_id=&status=&sync=&search=&page=&per_page=` | GET | `manage_crazylinks` | `X-WP-Total` and `X-WP-TotalPages` headers. Items include `destination`, `sync_status`, `sync_error`, `synced_at`. |
| `/subscribers/{id}` | DELETE | `manage_crazylinks` | |
| `/integrations/audiences?refresh=` | GET | `manage_crazylinks` (401 logged out, 403 otherwise, editors included) | `{adapter, available, default, audiences: [{id, name, member_count}], error}`; cached 10 minutes. Feeds the editor's audience select (others get a text field). |
| `/integrations/mailchimp/webhook?key=<secret>` | GET | public | 200 `{ok:true}` for Mailchimp's URL check. |
| `/integrations/mailchimp/webhook?key=<secret>` | POST | public, no cookie auth; the secret (compared with `hash_equals`) | Mailchimp's form-encoded `type`, `data[email]`, `data[list_id]`. Wrong or missing secret 403 (and at most 10 per IP per hour, then 429); 120 deliveries per IP per minute (`crazylinks_webhook_rate_limit`). Always `{ok:true}` with 200, also for unknown addresses and other event types. Logs nothing. |

### Store

| Route | Methods | Permission | Notes |
|---|---|---|---|
| `/store/products?search=&per_page=&include=` | GET | `edit_crazylinks_pages` (401 logged out, 403 otherwise) | `per_page` 1-50 (default 10), `include` one product id. Returns `{available, products: [{id, name, price, url, image, on_sale}]}`; feeds the editor's product picker through `Store::search()`. |

### AI editing

| Route | Permission | Body | Returns |
|---|---|---|---|
| `POST /pages/{id}/ai/propose` | `use_crazylinks_ai` + `edit_post` | `instruction`, `mode` (`edit`\|`build_from_site`\|`build_from_description`\|`restyle`\|`analytics_qa`), `questions_answers[]`, optional `page` (editor working copy) | `proposal_id` (null when only questions), `summary`, `operations`, `questions`, `preview_document`, `change_list[] {kind, text, block_id}`, `expires_at`, `model`, `usage`, `budget` |
| `POST /pages/{id}/ai/apply` | same | `proposal_id`, optional `operations` (edited), optional `page` | `applied`, `revision_id`, `source`, `rebased`, `edited`, `change_list`, `page` (the `/pages/{id}` shape); `already_applied: true` on repeats |
| `POST /pages/{id}/ai/suggest` | same | `kind` (`titles`\|`bio`), `context` | `suggestions[]` |
| `POST /pages/{id}/ai/settings` | same | `require_confirmation` | Per-page "external agents need confirmation" flag (post meta `_crazylinks_ai_confirm`) |
| `GET /pages/{id}/ai/pending` | same | | Changes from external agents waiting for confirmation, shaped like a proposal plus `external: true`, `created_at`, `author` (display name of the agent's account). Re-validated on the stored page for the current user; ones that expired (7 days) or no longer fit are dropped. Apply one with `POST /ai/apply` and its `proposal_id` (any user who may edit the page). |
| `POST /pages/{id}/ai/discard` | same | `proposal_id` | `{discarded: bool}`; removes a pending change. |
| `GET /ai/status` | `use_crazylinks_ai` | | `available`, `reason`, `message`, `providers`, `models`, `limits`, `budget` |

Errors: 400 invalid operations (`data.errors[] {path, message}`), 403/401 permissions, 409 `crazylinks_ai_conflict` (page changed and the operations no longer fit) or `crazylinks_ai_confirmation_required`, 410 `crazylinks_ai_proposal_expired` (30 minutes, other user or page), 422 `crazylinks_ai_invalid_output` (model output invalid after one retry), 429 rate limit or budget, 503 unavailable / no provider / prevented (`crazylinks_ai_prevented`).

## Public routes (under the URL base)

| Route | Response |
|---|---|
| `/{base}/{slug}/` | The link page. Password-protected pages show only the password form, log no view and are never cached. |
| `/{base}/{slug}/go/{block_id}/` | 302 to the stored URL of a `link`, `image` or `payment` block, or to the product page of a single-product `product` block (UTM appended when enabled), logging a click. 404 when the page or block is not publicly reachable, and for a `socials` block (an icon click always names its item). |
| `/{base}/{slug}/go/{block_id}/{item_id}/` | 302 to the URL of one social icon (`socials` block item) or one listed product (`product` block in newest mode). The click is logged against `block_id` with `item_id`. 404 for an unknown item, a `mailto:`/`tel:`/`sms:` item, a block without items, or a malformed id. |
| `/{base}/{slug}/vcard/` | `text/vcard` download built from the first visible `contact` block with `props.vcard` set (profile name, phone, email, address, page URL). Same checks as `/go/`. |

With plain permalinks these are `/?crazylinks_slug={slug}`, plus `&crazylinks_go={block_id}` (and `&crazylinks_item={item_id}`) or `&crazylinks_vcard=1`.

- De-duplication and rate limiting include the item id, so a visitor tapping two different icons within ten seconds logs two clicks.
- Product redirects use the cached store data only (`Store::known_product()`: transient, else the last good copy; never a request to the store). 404 when nothing is cached, when the id is not one the block currently lists, or when a single-product block is given a product id.
- When an update changes the rewrite rules, the plugin flushes them once on the next request (`Router::maybe_flush()` compares the stored rules with the current ones; plain permalinks store none and are unaffected).

## Shortcode and block

- `[crazylinks id="123"]`
- Block `crazylinks/page` with attribute `id` (server-rendered).

## Abilities and the MCP server

Abilities are registered in the category `crazylinks` and run through core's REST routes (`wp-abilities/v1`).

| Ability | Annotations | HTTP method on `/run` | Permission and shape |
|---|---|---|---|
| `crazylinks/list-pages` | readonly | GET | `edit_crazylinks_pages` |
| `crazylinks/get-page` | readonly | GET | `edit_post` on `id` |
| `crazylinks/get-editing-guide` | readonly | GET | `edit_crazylinks_pages` (+ `edit_post` when `id` is given); returns `guide` (markdown generated from `schemas/ai-ops.v1.json` and the prompt's operations wording), `block_types[]`, `operations[]`, and with `id` the trimmed `page` and `modified` |
| `crazylinks/preview-operations` | readonly | GET | `edit_post` on `id` (404 for a missing page); `{id, operations, include_document?}` → `valid`, `errors[]`, `warnings[]`, `summary`, `change_list[]`, `changed_blocks[]` (touched blocks after the operations, trimmed form), `removed_block_ids[]`, `block_count`, `modified`, and `preview_document` (trimmed) only when `include_document` is true. Saves and stores nothing. Invalid operations are a normal `valid: false` result |
| `crazylinks/apply-operations` | destructive | POST | `edit_post` on `id`; `{id, operations, summary?, expected_modified?, idempotency_key?}` → `applied`, `revision_id`, `pending_proposal_id`, `change_list`, `changed_blocks[]`, `removed_block_ids[]`, `block_count`, `summary`, `modified`, `link`, `source: "mcp"`, `message`, `replayed` (on idempotent replays). Errors: 400 `crazylinks_ai_invalid_ops` (errors listed in the message), 409 `crazylinks_conflict` (`data.modified`), 409 `crazylinks_page_locked` (another user holds the editor lock), 429 rate limit |
| `crazylinks/propose-changes` | readonly | GET | `use_crazylinks_ai` + `edit_post`; takes `instruction` (site AI writes the operations) or `operations` (agent-written, validated, no provider needed) |
| `crazylinks/apply-changes` | destructive, idempotent | DELETE | `edit_post`; refused with 409 when the page requires confirmation |
| `crazylinks/get-analytics-summary` | readonly | GET | `view_crazylinks_analytics` + `edit_post` |
| `crazylinks/suggest-titles` | readonly | GET | `use_crazylinks_ai` |
| `crazylinks/search-products` | readonly | GET | `edit_crazylinks_pages`; `{search?, limit?}` → list of `{id, name, price, url}` |

Every ability is off until enabled under CrazyLinks → Settings → AI. Exposure meta: `meta.show_in_rest` is always true (core's REST routes), `meta.public` and `meta.mcp.public` follow the enable switch, `meta.mcp.type` is `tool`. The MCP Adapter turns names into tool names with `/` → `-` (`crazylinks-preview-operations`).

The editing guide and the operations validator know the site's Mailchimp audiences: the guide lists them under "This site" with the rule that `audience_id` must be one of them, and `OpsValidator` rejects an `audience_id` that is neither a cached audience nor the default.

### MCP server

With the MCP Adapter plugin active and the `ai_mcp_server` setting on, CrazyLinks registers server `crazylinks` on `mcp_adapter_init`: endpoint `/wp-json/crazylinks/mcp`, HTTP transport, the adapter's error-log handler, version `CRAZYLINKS_VERSION`, tools = the enabled abilities in `Abilities::NAMES` order, and the server description (sent to clients as `instructions`) points the agent to `crazylinks-get-editing-guide`. Transport permission: 401 when logged out, 403 without any of `edit_crazylinks_pages`, `use_crazylinks_ai`, `view_crazylinks_analytics`. The adapter's default server (`/wp-json/mcp/mcp-adapter-default-server`) also reaches the enabled abilities through its `mcp-adapter-execute-ability` tool, unless it is switched off (`crazylinks_mcp_default_server_off`). Connection guide: [`mcp.md`](mcp.md).

## WP-CLI

| Command | Purpose |
|---|---|
| `wp crazylinks demo [--slug=<slug>] [--theme=<theme>] [--layout=<layout>] [--porcelain]` | Creates a sample page that uses every block. `--porcelain` prints only the page id. |
| `wp crazylinks validate <file>` | Validates a JSON file (or `-` for STDIN) against the page schema; exits 1 and lists the errors when it is invalid. |
| `wp crazylinks rollup` | Runs the daily analytics rollup now. |
| `wp crazylinks subscribers export [--page=<id>] [--status=pending\|confirmed\|unsubscribed] [--sync=<state>] [--format=csv\|json]` | Writes subscribers to stdout. |
| `wp crazylinks subscribers test-connection` | Pings Mailchimp and lists the audiences (marks the default). |
| `wp crazylinks subscribers sync [--retry-failed] [--backfill]` | Pushes queued rows to the email service; `--retry-failed` also restarts failed and skipped rows; `--backfill` runs the backfill in the foreground. |
| `wp crazylinks ai status [--user=<user>]` | Client, provider, setting, models, budget, and availability for the user. |
| `wp crazylinks ai eval [--offline] [--grade] [--case=<id>] [--limit=<n>]` | Runs the eval set in `tests/fixtures/ai/` (source checkout only; the release zip has no tests). Live by default and only when a provider is configured; otherwise prints a notice and exits 0. `--offline` replays the recorded responses; `--grade` asks a Haiku-class model to grade each result against its rubric. |
| `wp crazylinks mcp status` | MCP Adapter active and version, CrazyLinks server on/off, both endpoints, enabled abilities, account advice and the Claude Desktop / Claude Code snippets (placeholders only). |
| `wp crazylinks mcp guide [--page=<id>]` | Prints the editing guide external agents receive (and the trimmed page). |
| `wp crazylinks mcp agent-role [--assign=<login>]` | Creates or resets the `crazylinks_agent` role, which can edit and publish link pages and use the CrazyLinks tools and nothing else on the site. `--assign` also gives that user the role, replacing their current roles. |

## Settings, options and storage

### Settings (`crazylinks_settings`)

| Key | Default | Meaning |
|---|---|---|
| `retention_days` | 90 | Raw events older than this are deleted by the daily cron after rollup (0 keeps them). Replaces `retention_months`; a site that only has the old key keeps `months * 30` days. Events are never deleted before their day has been rolled up. |
| `double_opt_in` | true | Require email confirmation. |
| `subscriber_retention_days` | 90 | Pending and unsubscribed rows not changed for this long are deleted by the daily cron (0 keeps them). Confirmed rows are never deleted automatically. |
| `fetch_oembed` | true | Whether saving a page may fetch oEmbed data (see `crazylinks_fetch_oembed`). |
| `store_url` | `''` | Product block: address of a separate WooCommerce store (https; http only when `wp_get_environment_type()` is `local`). Sanitized by `Woo\Store::sanitize_url()`; an invalid value keeps the previous one. The only host the plugin contacts for products. Empty uses WooCommerce on this site when active. |
| `subscriber_adapter` | `''` | `''` = local list only, or an adapter id (`mailchimp`). |
| `mailchimp_api_key` | `''` | Never sent to the browser, never in REST output, page JSON or editor data. The settings field shows 12 bullets and the last four characters; submitting the mask (or the stored key, as WordPress's second sanitizer pass on a first save does) keeps it; an empty field removes it; an invalid value keeps the previous one. |
| `mailchimp_audience` | `''` | Default audience id (`^[0-9a-z]{6,20}$`). |
| `ai_mcp_server` | true | Add the CrazyLinks MCP server when the MCP Adapter is active (see `crazylinks_mcp_server`). |
| `ai_mcp_default_server_off` | false | Turn off the MCP Adapter's default server (see `crazylinks_mcp_default_server_off`). |

### Options and transients

| Name | Meaning |
|---|---|
| option `crazylinks_salt_{Ymd}` | The daily visitor-hash salt, not autoloaded; the previous day's is deleted on rotation. |
| option `crazylinks_store_last` | Last good copy of fetched store data, not autoloaded, so an unreachable store serves stale data. |
| option `crazylinks_mailchimp_webhook_secret` | 32 random characters, generated once, not autoloaded; the `key` in the webhook URL. |
| option `crazylinks_sync_backfill` | Backfill progress (`running`, `last_id`, `total`, `sent`, `failed`, `started`, `finished`). |
| transients `crazylinks_mc_audiences_*`, `crazylinks_mc_test` | Audience list (10 minutes, per key) and the last connection test. |
| post meta `_crazylinks_ai_confirm` | Per-page "external agents need confirmation" flag. |

All are removed on uninstall when "delete data" is on; the cron hooks are always unscheduled on uninstall and deactivation.

### Tables

- `{prefix}crazylinks_events`: raw view and click events (no IP address), with `item_id` for social-icon and listed-product clicks.
- `{prefix}crazylinks_daily`: daily rollups per page, block and item.
- `{prefix}crazylinks_subscribers`: sign-ups. Sync columns: `destination` (`local`|`mailchimp`), `audience_id`, `sync_status` (`''`|`queued`|`sent`|`failed`|`skipped`), `sync_attempts`, `sync_error` (255), `synced_at`, `external_id`; keys `sync_status`, `audience_email`.
- `{prefix}crazylinks_ai_log`: AI requests, pruned by the daily cron.
- `{prefix}crazylinks_limits`: rate-limit and de-duplication state when there is no persistent object cache (hashed keys, expired rows swept opportunistically and by the daily cron). With a persistent object cache the state lives in the cache group `crazylinks_limits` instead.

Schema changes are applied by `Activator::maybe_upgrade()` when the stored `crazylinks_db_version` is older than the plugin's.

## Page document notes

The page format is defined by `schemas/page.v1.json`; changes are listed in [`schema-changes.md`](schema-changes.md). Keys that interact with the hooks and routes above:

| Where | Key | Meaning |
|---|---|---|
| `settings` | `footer_credit` (bool) | Show the credit line. |
| `profile` | `banner_focal_point` `{x,y}` 0..1 | `object-position` of the banner image. |
| `link` props | `focal_point` `{x,y}`, `tile_size` `1x1\|2x1\|2x2`, `thumbnail_alt` (string), `decorative` (bool) | Focal point of the thumbnail or featured image; bento size; alt text; decorative flag. |
| `image` props | `decorative` (bool) | Empty alt, no warning. |
| `gallery` items | `decorative` (bool) | Same, per image. |
| `socials` items | `id` (ULID) | Generated by the validator when missing. An item without an id that has the same network and URL as one in the previous document keeps that item's id, so API clients that omit ids keep stable click URLs. Duplicate ids inside a block are replaced. An item without an id (a document never saved since ids were introduced) renders as a direct link. |
| `email_capture` props | `success_text`, `pending_text` (strings, 200) | Replace the default "confirmed" and "pending" messages for this form only. |
| `email_capture` props | `destination`, `audience_id`, `tags` | Where the sign-up goes (see [Mailchimp](#mailchimp)). |

`tile_size` 2x1 and 2x2 apply in the Grid layout only: both span the full width (2x2 is also taller and uses a square thumbnail). In list and featured layouts the class is present but inert.

### Validation warnings

`ValidationResult::warnings()` returns `[ { path, message, code: "missing_alt", block_id? } ]` for a valid document: an `image` block, a `gallery` item or a `link` thumbnail whose effective alt text is empty and which is not `decorative`, and an avatar on a page without a name. The effective alt text is the block's own, else the attachment's Media Library alt text; the renderer falls back the same way. `PageValidator::warnings_for( $doc )` is the static form. Warnings never block a save. The REST create, update and render-preview responses return them as `warnings[]`, and the editor computes the same list client-side (`lib/warnings.ts`) so they show inline immediately and the Publish/Update button asks "Publish anyway".

## Editor

### Data store

`crazylinks/editor` (`@wordpress/data`). Other scripts on the editor screen can read it, for example `wp.data.select( 'crazylinks/editor' ).getDoc()`, and dispatch `replaceDoc( doc )` (undoable).

### Post locking

`CrazyLinks\PostLock` (hooked from `PostType::register_hooks()`) uses core's lock: when the editor boots it calls `wp_check_post_lock()`; if nobody else holds the page it calls `wp_set_post_lock()`. The result is added to the boot data as `lock`:

```
lock: { locked: bool, postId, nonce, activeLock: "time:user", user: {name, avatar}|null, takeOverUrl }
```

`takeOverUrl` is core's own (`post.php?post=ID&action=edit&get-post-lock=1&_wpnonce=lock-post_ID`). The editor listens to core's heartbeat (`wp-refresh-post-lock`) to keep the lock and to notice a takeover, shows a banner with "Take over", stops saving and autosaving while locked out, and releases the lock on `pagehide` through core's `wp-remove-post-lock` AJAX action. Cmd/Ctrl+S on a live page saves without the alt-text prompt (only the Publish/Update buttons ask).

### Behaviour notes

- Drag between Sections: a block dropped on another block takes its place in that block's list (down inside one list = after, otherwise before); each Section has an "end of group" drop zone that appears while a non-Section block is dragged. Sections never nest. The keyboard path is the same drag (arrow keys) or the Group selector in the block panel.
- Contrast chips (Design tab): body text, headings (3:1), muted text on the page and on cards, accent, button text (by fill), sign-up button, badge, link badges with a custom colour (white text), in light and, when the theme has one, dark mode. Mirrors `Render\Contrast::pairs()`.
- Focal point picker: click the image or use the two sliders.

## Product block and store

- Block type `product` (props in [`schema-changes.md`](schema-changes.md)); partial `templates/blocks/product.php`; data source `CrazyLinks\Woo\Store`. The store address is the `store_url` setting, never a block prop.
- Requests: `GET {store_url}/wp-json/wc/store/v1/products?...` (and `GET {store_url}/wp-json/?_fields=name` for the settings status line), 5-second timeout, no redirects, `reject_unsafe_urls` unless http is allowed. Results are cached in transients (15 minutes) and the last good copy is kept in the option `crazylinks_store_last`, so an unreachable store serves stale data. A failed request is not repeated for 2 minutes.
- Without `store_url`, WooCommerce on the same site is used through `wc_get_products()` / `wc_get_product()`.

## Mailchimp

An Email sign-up block with `destination: "mailchimp"` sends its sign-ups to the Mailchimp account connected under **Settings → Integrations**. Blocks with `destination: "local"` (the default) use the local list. Code: `Subscribers\Adapters\Adapter` (interface), `Subscribers\Adapters\Mailchimp`, `Subscribers\Adapters` (registry), `Subscribers\Integrations` (settings, key mask, webhook secret, audience cache), `Subscribers\Sync` (push, retry, backfill, webhook updates), `Rest\IntegrationsController`.

### Flow

1. Sign-up on a Mailchimp block (after the usual honeypot, per-IP rate limit, reachability and consent checks): the local row is created with `destination = mailchimp`, `status = pending`, `audience_id` (the block's `audience_id`, else the default audience) and `sync_status = queued`. No email is sent by the plugin; the visitor sees the block's `pending_text` (default "Check your email to confirm your subscription.").
2. The row is pushed **inline** during the same request with a 5-second timeout (`crazylinks_sync_inline_timeout`): `PUT /3.0/lists/{audience}/members/{md5(email)}` with `email_address`, `status_if_new: pending`, `timestamp_signup` and `tags`, then `POST …/tags` for the tags (existing members). `status` is never sent, so an existing member's status never changes; a member who had unsubscribed and signs up again gets one extra `PUT {status: pending}` (Mailchimp asks them to confirm). A member who is already `subscribed` is marked confirmed locally at once.
3. Success: `sync_status = sent`, `external_id` = Mailchimp member id, `synced_at`. Failure: `sync_status = failed`, `sync_error` (plain-language message), and a single cron event `crazylinks_subscriber_sync` (args `[id]`) is scheduled 1, 4, 16 and 64 minutes later (`crazylinks_sync_retry_delay`), 5 attempts in total. Errors another attempt cannot fix (member in compliance state, member exists, audience not found, other 400s) stop at once.
4. Mailchimp's webhook (subscribe, unsubscribe, cleaned) updates every local row with that email and audience: subscribe → `confirmed` (with `confirmed_at`), unsubscribe and cleaned → `unsubscribed`. The `crazylinks_subscriber_confirmed` / `_unsubscribed` actions fire as for local rows.
5. Mailchimp chosen but no key or no audience: the sign-up falls back to the local double opt-in (row `destination = local`, `sync_status = skipped`) so nobody is lost; Backfill sends it later once confirmed.

Requests go only to `https://{dc}.api.mailchimp.com/3.0`, `{dc}` taken from the key suffix (`…-us6`); HTTP Basic auth (`crazylinks:{key}`), `wp_remote_request`, 10-second timeout (5 inline), no redirects, every response decoded and checked, error details redacted of addresses. Endpoints: `GET /ping`, `GET /lists`, `PUT /lists/{id}/members/{hash}`, `POST /lists/{id}/members/{hash}/tags`, `POST /lists/{id}/members/{hash}/actions/delete-permanent`, `GET`/`POST /lists/{id}/webhooks`.

### Admin screens

- Settings → Integrations: service select, API key (masked), default audience (select from the API, text field when it cannot load), **Test connection** (tests the key in the field without saving it when it is new), webhook URL to paste into Mailchimp, **Register webhook in Mailchimp** (creates it for the default audience: events subscribe, unsubscribe, cleaned; sources user, admin, API; skipped when the same URL exists).
- Subscribers: a Sync column (Queued / Sent / Failed with the error / Skipped), a sync filter, row and bulk **Retry sync** (attempts start again), **Backfill to Mailchimp** (every locally confirmed row not yet sent, `status_if_new: subscribed`, the block's tags, in cron batches with a progress notice). CSV export adds `destination, audience_id, sync_status, sync_attempts, sync_error, synced_at, external_id`.
