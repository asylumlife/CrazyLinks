# CrazyLinks — Product & Technical Specification

**Status:** v0.3 draft, owner decisions D1-D7 recorded, dev environment chosen and WordPress 7.1.4 AI/Abilities APIs verified (2026-11-05)
**Working name:** CrazyLinks (slug `crazylinks`, text domain `crazylinks`, PHP prefix `CrazyLinks\` / `crazylinks_`)
**Research basis:** [`reports/Link in bio tools landscape.md`](../reports/Link%20in%20bio%20tools%20landscape.md) (5,500 words, cited, confidence-labeled)

Items marked **DECISION** need the owner's call before build. Items marked **VERIFY** are facts the research could not confirm and must be checked before the affected code ships.

---

## 0. Decisions (recorded 2026-11-05)

| # | Decision | Outcome | Notes |
|---|---|---|---|
| D1 | Who creates pages? | **Owner mode only.** The plugin is installed on other people's WordPress sites; the site owner and any WP user they grant the capability create pages. No hosted "member pages" mode. | Removes the Phase 3 member-pages work and its abuse/moderation burden. The data model still records `post_author`, so per-user ownership and team roles work out of the box. |
| D2 | Distribution and pricing | **Free core with everything Linktree charges for. An agency/Pro tier is of interest and will be defined later.** | Candidate Pro features: multi-site/client management dashboard, white-label admin, client reporting, bulk operations, hosted-AI convenience. Never basics. See §3 v3.0. |
| D3 | AI provider strategy | **Use the WordPress core AI Client (7.0+) with provider keys in Settings → Connectors.** A direct Anthropic SDK driver is added only if a concrete gap is found in the core client. | WordPress ships the plumbing but no provider; the site owner adds their own Anthropic, Google or OpenAI key once in Connectors and every AI-aware plugin, including this one, uses it. Nothing extra to build or configure for most sites. **Verified on WP 7.1.4 (2026-11-05):** a fresh install registers zero providers; the Connectors screen offers an *Install* button for the official provider plugins (`ai-provider-for-anthropic`, Google, OpenAI), which register themselves with the AI Client and store keys in the `wp_ai_client_provider_credentials` option. **Owner decision 2026-11-05:** the owner's preferred way to edit pages with AI is from Claude Desktop (or Claude Code) over MCP, billed to their Claude subscription with no API key on the site. The Connectors/API path stays as the fallback for editors who have no Claude account. Build plan in §10.7. |
| D4 | Minimum WordPress version | **6.9 for the plugin; AI features require 7.0 and are feature-detected.** | Confirmed by owner. |
| D5 | Editor and rendering approach | **Custom React admin editor on its own screen. Front end is theme-agnostic by default, with rendering options layered on top** (standalone, inside the theme, embedded anywhere via block or shortcode, and a "match my site" style preset). | Owner likes the React editor and wants the plugin to work with any theme or builder without interfering with other plugins. Owner uses Divi today but is not committed to it, so builder-specific modules are later nice-to-haves, not v1 commitments. See §6.3 and §6.5. |
| D6 | Commerce | **Integrate WooCommerce. Add a "payment and tip links" block for Buy Me a Coffee, Ko-fi, PayPal, Venmo, Cash App, Stripe Payment Links and Patreon.** | Those services all work as plain URLs, so they are branded link presets, not payment processing. Stripe Payment Links give Stripe checkout with no Woo install. |
| D7 (note) | Owner's own site uses base `linktree` (set in Settings; pages at `/linktree/{slug}/`); the plugin default stays `l`. |
| D7 | Front-end web address | **`yoursite.com/l/your-page` by default; `yoursite.com/your-page` available per page after a collision check.** | This decision is only about the URL of the page, not its look. The research showed that bio plugins which grab root-level URLs with a catch-all rule cause 404s elsewhere on the site, so root-level is opt-in and checked against every existing post, page and term. Visual layouts are covered in §5 and §5A. |

---

## 1. Vision and positioning

**One sentence:** CrazyLinks turns any WordPress site into a Linktree-class link page builder that runs on the owner's domain, keeps their data, charges no monthly rent and no sales fee, and lets them edit pages by talking to an AI.

**What the research says the fight is about.** Nearly every tool already has links, embeds, QR codes and basic analytics. What Linktree and its rivals charge for is brand control (remove logo, fonts, backgrounds), measurement (pixels, GA4, UTM, history beyond 28 days, CSV export) and audience ownership (email capture, integrations). Those are exactly what a WordPress site owner already has for free. Users' loudest complaints are money and trust: price rises (Linktree +46-67% in Nov 2025, possibly again Oct 2026), surprise trials, 8-12% sales fees, held payouts, unresponsive support, opaque bans, and five products shut down since 2024 (Koji, Linkpop, Bento, Fingertip, kit.co). Self-hosting removes each by design.

**Where WordPress plugins have failed so far.** The top plugin has ~2,000 installs. Reviews repeat two complaints: basics locked behind Pro ("you can't even change the background color") and broad rewrite rules causing 404s elsewhere on the site. One loads React and MobX on every front-end page.

**Positioning line (draft):** "Your link page. Your domain. Your data. No rent, no fees, no shutdowns."

### Goals

1. Feature parity with Linktree Pro on day one, free.
2. A page that loads fast inside the Instagram and TikTok in-app browsers (Core Web Vitals green on a mid-range phone).
3. First-party analytics that work without cookies and without third-party scripts.
4. AI editing that is safe, previewable, undoable and runs on the owner's own API key.
5. Zero upsell nags for basics. Pro, if any, sells team/agency and convenience.

### Non-goals (v1)

- Native payments, courses, memberships, bookings (integrate, don't build).
- Social scheduling or analytics for social accounts.
- Hosting pages for people without a WordPress site (that is Phase 3 member-pages mode on a site the owner runs).

---

## 2. Users and modes

| Persona | Mode | Needs |
|---|---|---|
| Creator or small business with their own WP site | **Owner mode** (v1) | One or a few pages, fast setup, Linktree import, analytics, email capture, sell via Woo. |
| Brand or team with several people on one install | **Team mode** (v1, via WP roles) | Per-page ownership, editors who can only touch their pages, admin oversight, CSV export. |
| Agency managing link pages across many client sites | **Agency / Pro** (v3, D2, to be defined) | Cross-site overview, client reporting, white-label admin, bulk theme pushes. |
| Any of the above using an AI assistant | **AI editing** (Phase 2) | "Add a link to my new video and move it to the top," in-admin and from Claude/ChatGPT via MCP. |

Not in scope (D1): a hosted mode where the public registers for `/@handle` pages on a site we run.

Capabilities (mapped to roles on activation, editable via any role plugin):

| Capability | Default roles | Grants |
|---|---|---|
| `edit_crazylinks_pages` | Administrator, Editor (Author via the `crazylinks_role_caps` filter) | Create and edit own pages |
| `edit_others_crazylinks_pages` | Administrator, Editor | Edit any page |
| `publish_crazylinks_pages` | Administrator, Editor (Author via the `crazylinks_role_caps` filter) | Publish |
| `delete_crazylinks_pages` | Administrator, Editor (Author (own) via filter) | Delete |
| `view_crazylinks_analytics` | Administrator, Editor (Author (own pages) via filter) | Analytics screens and CSV |
| `manage_crazylinks` | Administrator | Global settings, AI provider, retention, integrations |
| `use_crazylinks_ai` | Administrator, Editor (Author via filter) | AI editing (also needs a configured provider) |
| `unfiltered_html` (core) | Administrator | Custom HTML block |

---

## 3. Scope by release

### v1.0 — Core (everything Linktree sells at Pro, free)

| Area | Included |
|---|---|
| Pages | Unlimited pages, draft/publish/schedule, duplicate, trash, revisions, export/import JSON |
| Blocks | Full catalog in §4 |
| Design | 12+ bundled themes, full color/font/background/button control, dark mode, custom CSS field (admin-only); layouts list / grid / featured; header styles hero, banner, shape masks; image, video, gradient and pattern backgrounds (§5A) |
| Links | Classic or Featured (16:9 card) display per link, unlimited; thumbnails/icons/emoji; badges; pinned/spotlight animation; schedule start/end; redirect mode (whole page 302s to one URL); nofollow/sponsored toggles; UTM auto-tagging per page |
| Analytics | Views, clicks, CTR per block, country, device, referrer, UTM source; unlimited history; CSV export; no cookies |
| Measurement | GA4 measurement ID, Meta Pixel ID (client tag, best-effort in webviews), GTM container, custom `<head>` snippets (admin) |
| Audience | Email capture block storing locally; hooks for MailPoet, FluentCRM, Mailchimp, Kit, beehiiv; CSV export of subscribers |
| SEO | Title, description, OG image, noindex toggle, canonical, JSON-LD Person/Organization |
| QR | Downloadable SVG/PNG QR per page with color and logo, scan counts via `?src=qr` |
| Sharing | Social icons row (30+ networks), share sheet button, vCard download |
| Import | Paste a Linktree URL to import links and profile (**VERIFY** legal/ToS and feasibility) |
| Platform | WP 6.9+, PHP 8.1+, multisite-aware, translation-ready, RTL, WCAG 2.1 AA |

### v1.1 — Polish

Bento grid layout, tabs block, carousel mode for links and galleries, auto-fetched favicon/Open Graph thumbnails, video avatar, animated gradients, Google Fonts picker with local hosting and `.woff2` upload, countdown block, map block, events and menu blocks, scheduled themes (holiday skin), A/B test on a single link (two URLs or titles, split by hash), Pretty Links/ThirstyAffiliates "use existing short link" picker, Burst/Independent Analytics event bridge.

### v2.0 — AI editing (§10)

In-admin prompt box, structured operations, preview/confirm/undo, abilities registered for the MCP Adapter, "build a page from this site" generator, link title and bio suggestions, analytics Q&A.

### v3.0 — Agency / Pro (D2, scope to be defined with the owner)

Candidate features, all beyond what a single site owner needs: a dashboard that connects to many client sites over the REST API and shows pages, clicks and health in one place; white-label admin (hide CrazyLinks branding in wp-admin for clients); scheduled client reports by email; push a theme or block set to many sites; team seats with per-client access; priority support. Pricing model to be decided (annual licence per agency seat is the WordPress norm). The free plugin stays complete on its own.

---

## 4. Block catalog

Every block shares: `id` (ULID), `type`, `parent_id` (null or a `group` block; max depth 1), `order`, `visible` (bool), `schedule` (`start`, `end`, nullable ISO 8601), `pinned` (bool), `animation` (none/pulse/shake/wobble), `tracking` (bool, default true).

| Type | Props | Notes |
|---|---|---|
| `profile` | avatar (attachment id), avatar_style (circle/square/hero/banner/shape), shape_mask, banner (attachment id), name, bio (280 chars), location, pronouns, verified_badge (bool), header_layout (centered/left/hero/banner) | One per page, always first. See §5A.3 **Build note (Phase 1):** implemented as the top-level `profile` object of §7.2, not as a block type; `profile` is not an allowed value of `blocks[].type`. |
| `link` | title, url, subtitle, display (classic/featured), thumbnail (attachment id, icon slug, emoji, or auto), focal_point, badge (text, color), tile_size (1x1/2x1/2x2 in bento layout), style override, open_new_tab, rel (`nofollow`, `sponsored`), deep_link (optional app URL scheme, see §6.4) | The core block. Featured display renders a 16:9 card. See §5A.2 |
| `socials` | items: [{network, url}] | 30+ networks with brand icons bundled as inline SVG |
| `heading` | text, level (h2/h3), align | **Built 2026-11-05:** `size` sm/md/lg/xl, `align`, `weight`, `color`, `uppercase`, `subtext`, `divider` none/line/dots, `spacing`, `emoji`/`icon`. |
| `text` | content (limited HTML: p, strong, em, a, br), align | Sanitized with `wp_kses` |
| `divider` | style (line/space/dots) | |
| `image` | attachment id, alt, link url, caption | LCP-aware: first image gets `fetchpriority="high"` |
| `gallery` | items: [{attachment id, url, caption}], columns (2/3), mode (grid/carousel) | Grid of linked tiles; carousel mode v1.1 |
| `latest_posts` | count, category, display (featured/classic) | Newest posts as cards with featured images; WordPress-only advantage (§5A.7) |
| `tabs` | tabs: [{label, group_id}] | v1.1; children are `group` blocks |
| `embed` | url, provider (auto via oEmbed) | WP core oEmbed covers YouTube, Spotify, Vimeo, SoundCloud, TikTok, X, Instagram; lazy-load with click-to-play facade for performance |
| `video` | attachment id or url, autoplay muted loop (background video) | |
| `email_capture` | heading, button text, consent text, destination (local / integration), tags | Honeypot + rate limit; no third-party script |
| `contact` | phone, email, address, vcard (bool) | Renders `tel:`, `mailto:`, `sms:`; vCard served as `text/vcard` |
| `map` | address or lat/lng, provider (OpenStreetMap static / Google static with key) | v1.1 |
| `countdown` | target datetime, label, link after expiry | v1.1 |
| `group` | title, collapsed (bool) | Children are blocks with `parent_id` = this id **Built 2026-11-05 as "Section":** keeps type `group`; adds `heading`, `description`, `collapsible`, `background` (shared `$defs/background`: solid/gradient/image/pattern), `padding`, `radius`, `text_color`, `layout`; its schedule hides all children; section text/background pair is contrast-checked. |
| `woo_products` | product ids or category, layout | Shown only when WooCommerce is active |
| `payment` | service (buymeacoffee, kofi, paypal, venmo, cashapp, stripe_link, patreon, custom), handle or url, label, amount hint | Branded button presets that open the service's own checkout; no money passes through the plugin (D6) |
| `html` | raw html | Admin + `unfiltered_html` only; never AI-editable |
| `spacer` | height | |

**Link URL policy:** allow `http`, `https`, `mailto`, `tel`, `sms`; everything else rejected server-side. Optional outbound link check (HEAD request on save) flags dead links in the editor.

---

## 5. Design system

**Design tokens stored per page** (JSON, see §7):

```json
{
  "theme": "midnight",
  "background": {"type": "gradient", "from": "#0f172a", "to": "#312e81", "angle": 160},
  "text": {"color": "#f8fafc", "font": "system-sans", "size": "md"},
  "button": {"shape": "pill", "fill": "soft", "color": "#ffffff", "text_color": "#0f172a", "shadow": "none", "border_width": 0},
  "accent": "#a78bfa",
  "radius": 16,
  "layout": "list",
  "dark_mode": "auto",
  "custom_css": ""
}
```

- **Themes** are named token presets (12+ at launch), applied non-destructively; users can tweak any token afterward.
- **Fonts:** system stacks by default (zero network cost, no GDPR exposure). Bundled open-license fonts (3-4 pairs) served locally. Google Fonts opt-in with local download in v1.1. Theme fonts inheritable when embedded in the theme.
- **Backgrounds:** solid, gradient, image (with overlay), video (muted loop, poster for LCP), pattern.
- **Button styles:** pill/rounded/square × fill/outline/soft/hard-shadow, hover and focus states always visible.
- **Contrast checker** in the editor warns below WCAG AA 4.5:1.
- **Dark mode:** auto (prefers-color-scheme), forced light, forced dark.
- **Layouts:** list (classic), grid (2-col tiles), featured (first link large), bento (mixed tile sizes, v1.1), tabs (v1.1), cards (swipeable, later).

## 5A. Layouts and visual features: parity map

Source: `research_notes/Link in bio tools landscape/design_layout_features.md` (official Linktree and Beacons help pages marked [O]; reviewer descriptions marked [R]). Gaps the research could not confirm are noted; our plan does not depend on them.

### 5A.1 Page layouts

| Layout | Who has it | CrazyLinks | Notes |
|---|---|---|---|
| Classic list of buttons | Everyone | **v1.0** | Default |
| Featured link cards (large 16:9 image + call to action, set per link) | Linktree "Featured Layouts" since Mar 2024: 1 on Free, unlimited on paid [O] | **v1.0, unlimited** | Each link chooses Classic or Featured display |
| Grid of tiles (2 columns) | Linktree Instagram Grid [O], Lnk.Bio [R] | **v1.0** | Page-level layout; links become square or 4:5 tiles |
| Bento grid (mixed 1×1, 2×1, 2×2 tiles with widgets) | Bento.me (closed Feb 2026), Beacons blocks [R] | **v1.1** | 4-column mobile grid; each block picks a tile size; drag to arrange in the editor |
| Tabs within a page (Links / Shop / Videos) | Beacons tabs or hamburger nav [O], Hopp multi-page with search [O] | **v1.1** | `tabs` block holds child groups; URL hash per tab; search box optional |
| Carousel of links or images | Linktree Instagram Carousel (Pro) [O] | **v1.1** | CSS scroll-snap, no JS required |
| Swipeable full-screen cards | Milkshake [R] | **Later** | Niche; revisit if users ask |
| Instagram feed as tiles (6 latest posts) | Linktree Pro, Later [O/R] | **Later, via existing feed plugins** | Meta's API needs an approved app and a professional account; we integrate with Smash Balloon and similar rather than own that dependency |
| Desktop rendering | Not documented by any tool | **v1.0** | Centered 480 px column on a blurred or solid full-bleed background; optional two-column desktop layout later |

### 5A.2 Link presentation

| Feature | Who has it | CrazyLinks | Notes |
|---|---|---|---|
| Square thumbnail (1:1, min 200×200) left of title | Linktree Starter+ [O], Beacons [O] | **v1.0** | Registered image size `cl-thumb` 400×400 |
| Featured image card (16:9, min 680×383) | Linktree [O], Beacons 16:9 crop [O] | **v1.0** | Registered size `cl-featured` 1360×765 for 2× screens; focal-point picker in the editor |
| Thumbnail sources: upload, icon library, GIF, AI image, Canva, GIPHY | Linktree [O] | **v1.0:** Media Library, bundled icon set (Tabler, MIT), emoji, GIF upload. **v1.1:** auto-fetch destination favicon or Open Graph image (server-side, cached, owner-approved). **v2:** AI image via core `generate_image()`. Canva and GIPHY need API keys: later, opt-in | |
| Subtitle under title | Beacons [R] | **v1.0** | |
| Badges ("New", "Sale", "Live", custom) | Not confirmed for any tool | **v1.0** | Cheap differentiator |
| Attention animation on one link (Spotlight / star) | Linktree Starter+ [O], Beacons star icon [O] | **v1.0** | pulse, shake, wobble, shimmer; honours `prefers-reduced-motion` |
| Collapsible link groups | Beacons [O] | **v1.0** | `group` block |
| Link outline, transparency, shadow controls | Beacons Block Style [O] | **v1.0** | Design tokens, per-link override allowed |

### 5A.3 Profile and header

| Feature | Who has it | CrazyLinks | Notes |
|---|---|---|---|
| Avatar shapes: circle, rounded square | Everyone | **v1.0** | |
| Profile image styles: Hero (full-bleed top), Banner (cover image with overlapping avatar), Cutout (subject on colour), Shape (masked) | Linktree Pro+ [O] | **v1.0:** Hero, Banner, Shape masks (circle, squircle, blob). **v2:** Cutout via core AI image tools or a background-removal service, opt-in | Registered sizes `cl-avatar` 320×320, `cl-banner` 1600×600 |
| Header layouts | Beacons: 4 types, 12 options [O] | **v1.0:** centered, left-aligned, hero, banner; each with 2-3 variants | |
| Verified badge | Linktree Starter+ [O] | **v1.0** | Owner toggles it; it is their site |
| Video or AI profile image | Linktree Starter+ [O] | **v1.1** video avatar (muted loop, poster fallback); AI in v2 | |
| Bio, location, pronouns fields | Bio everywhere; others not confirmed | **v1.0** | Bio 280 chars default, configurable; location and pronouns optional |

### 5A.4 Backgrounds

| Feature | Who has it | CrazyLinks |
|---|---|---|
| Solid, gradient | Everyone | **v1.0** (2-3 stops, angle, radial option) |
| Image with blur and colour overlay | Linktree Pro+ [O], Beacons [O] | **v1.0** |
| Video background (muted loop, poster for LCP) | Linktree Pro+ [O], Beacons [O] | **v1.0** |
| Patterns and textures | Not confirmed | **v1.0** (8 bundled SVG patterns) |
| Animated gradient | Taplink "animated themes" [R] | **v1.1** (CSS only, reduced-motion aware) |
| Glass / frosted button effect | Not confirmed | **v1.0** (backdrop-filter with solid fallback) |
| AI-generated wallpaper | Linktree Enhance [O] | **v2** via core `generate_image()` |

### 5A.5 Buttons, typography, templates

| Feature | Who has it | CrazyLinks |
|---|---|---|
| Shape pill / rounded / square; fill / outline / soft shadow / hard shadow | Linktree, Beacons [O] | **v1.0** |
| Hover and press effects | Not documented | **v1.0** (lift, darken, none) |
| Fonts | Beacons "1,024+", Lnk.Bio "44,813" [R], both Google Fonts catalogues; Carrd no upload [O] | **v1.0:** system stacks + 8 bundled open-licence fonts served locally. **v1.1:** Google Fonts picker that downloads and self-hosts the chosen family; custom `.woff2` upload |
| Colour: full picker vs fixed palette | Linktree Pro+ for custom colours [O] | **v1.0** full picker with palette suggestions and contrast checker |
| Dark mode | Not confirmed for any tool | **v1.0** auto / light / dark |
| Templates | Lnk.Bio 577 [R], Linktree lightning-bolt themes Pro+ [O] | **v1.0:** 12+ presets grouped by mood and profession; every preset stays fully editable. **v2:** "Enhance" step that proposes layout, wallpaper and colours and lets the owner accept each change (mirrors Linktree Enhance, Sep 2025) |
| Image gallery | Linktree 3 images Free, unlimited Pro+ [O] | **v1.0** unlimited, 2 or 3 columns; carousel mode v1.1 |
| Stickers / "Bio Boards" | Linktree Jun 2026 [R] | **Not planned**; revisit if demand appears |
| Styled QR (colours, centre logo) | Linktree Pro+ [O] | **v1.0** |

### 5A.6 Images: handling rules

- Media Library is the only image store; cropping uses WordPress's built-in image editor plus an in-editor focal-point picker for featured, banner and hero images.
- Registered sizes: `cl-thumb` 400×400 (1:1), `cl-featured` 1360×765 (16:9), `cl-tile` 800×1000 (4:5, grid), `cl-avatar` 320×320, `cl-banner` 1600×600. WebP/AVIF output via core.
- GIFs are served as uploaded (no resize) with a size warning above 2 MB; a "convert to looping video" helper is a v1.1 candidate.
- Every image carries `alt`; the editor blocks publish on a missing alt for the avatar and featured images.

### 5A.7 Beyond parity (WordPress-only advantages)

| Block | What it does |
|---|---|
| `latest_posts` | Shows the N newest posts (or a category) as featured cards with their featured images, updating itself. No hosted tool can do this. |
| `woo_products` | Product images, prices and sale badges straight from WooCommerce. |
| `events` | Upcoming events from The Events Calendar or Sugar Calendar as cards (v1.1). |
| `menu` | A WordPress navigation menu rendered as link buttons, so one edit updates both the site and the link page (v1.1). |

---

## 6. Front-end rendering and performance

### 6.1 Rendering strategy

- **Standalone template** served by the plugin, independent of the active theme (no theme header/footer, no theme CSS/JS enqueued). Option per page: "inherit theme" for sites that want it inside their design.
- **Server-rendered HTML**, inline critical CSS (one `<style>` block, ~6-10 KB), no framework on the front end.
- **JavaScript budget: ≤ 8 KB inline, no external scripts by default.** Contains: view beacon, in-app-browser hint, click-to-play embed facades, group collapse, share sheet. All features degrade without JS.
- **Images:** WordPress responsive `srcset`, WebP/AVIF via core, avatar and first image preloaded with `fetchpriority="high"`, everything else lazy.
- **Embeds:** facade (thumbnail + play button) until tap; the iframe loads on interaction.
- **Targets:** LCP ≤ 2.0 s, INP ≤ 100 ms, CLS ≤ 0.05 on a throttled 4G mid-range Android profile in Lighthouse. Lighthouse CI in the pipeline.

### 6.2 Caching

- Pages are static-cache-friendly (no per-visitor markup). Compatible with WP Super Cache, W3TC, LiteSpeed, Cloudflare APO.
- Click redirect endpoint and REST analytics endpoints send `Cache-Control: no-store` and are excluded from page caching via standard headers and known-plugin filters.
- Rendered HTML cached in a transient per page, invalidated on save.

### 6.3 Rendering options (theme-agnostic core, options on top)

The renderer is one PHP component that produces the same HTML everywhere. What changes is the wrapper around it.

| Option | What the visitor sees | How it is set | Release |
|---|---|---|---|
| **Standalone** (default) | Only the link page: no theme header, footer, CSS or JS. Fastest; identical on every site. | Default for every page | v1.0 |
| **Inside my theme** | The link page wrapped in the active theme's header and footer, styled by the page's own tokens. | Per-page switch | v1.0 |
| **Embedded anywhere** | The link page inside any post, page or builder layout, via the Gutenberg block "CrazyLinks Page" or `[crazylinks id="123"]`. Works in Divi, Elementor, Bricks, Beaver Builder and block themes without a dedicated module because every builder supports shortcodes and most support core blocks. | Insert block or shortcode | v1.0 |
| **Match my site** style preset | A theme preset that reads the active theme's palette and fonts (block themes via `theme.json`; Divi and Elementor via their global colour settings when present) so the link page looks like the rest of the site without inheriting its weight. | Choose "Match my site" in Design | v1.1 |
| **Builder modules** | Native Divi module and Elementor widget with live preview inside the builder. Convenience over the shortcode, not new capability. Registered only when that builder is active. | Builder's own UI | v1.1+, demand-driven |

The React editor is unaffected by any of these: it always runs on its own wp-admin screen, and the front end stays React-free for speed.

### 6.4 In-app browser handling

Visitors arrive from Instagram/TikTok webviews that strip referrers, reset cookies and break app deep links; no documented escape exists (research, Medium confidence).

- Detect client-side by UA tokens (`Instagram`, `FBAN`, `FBAV`, `FB_IAB`, `BytedanceWebview`, `musical_ly`); treat as hint, not truth.
- Optional, off by default: dismissable bar "Open in Safari/Chrome for the best experience." Attempt `x-safari-https://` on iOS and `intent://` on Android only when the owner enables "try to escape in-app browser" (**experimental**).
- `deep_link` on a link block: try the app scheme with a 1.5 s timeout fallback to the web URL. Documented as unreliable inside webviews.
- Google sign-in or anything needing OAuth in a webview is out of scope; the email-capture block never requires it.

### 6.5 Theme and page-builder compatibility contract (D5)

The plugin must work on any site and never interfere with the theme, the page builder or other plugins. These rules apply to every theme and builder (block themes, classic themes, Divi, Elementor, Bricks, Kadence, Beaver Builder):

| Rule | What it means in practice |
|---|---|
| Own admin screen | The editor lives under **CrazyLinks** in the admin menu. It never modifies the post editor, any builder, or any other plugin's screens. |
| Own post type, hidden from builders | `crazylinks_page` is excluded from builder "enable on this post type" lists and from Gutenberg. Builders cannot open it, so they cannot break it. |
| Standalone template by default | Link pages render through the plugin's template with `wp_head`/`wp_footer` kept for SEO and analytics plugins but with theme and builder stylesheets and scripts dequeued. No theme or builder front-end assets load on a standalone link page, which is what keeps it fast. |
| Opt-in theme inheritance | The "Inside my theme" option (§6.3) wraps the page in the theme's header and footer. Performance targets are then the theme's responsibility. |
| Scoped CSS | All plugin CSS is prefixed `.cl-` and scoped to the page wrapper. No global resets, no `!important` on element selectors. Builder and theme CSS cannot leak in when embedded because the wrapper carries its own tokens as CSS custom properties. |
| No front-end assets elsewhere | The plugin enqueues nothing on pages that do not contain a link page, block or shortcode. This is the opposite of the "React loaded on every page" complaint in the research. |
| Namespaced everything | Options, meta keys, REST routes, rewrite tags, cron hooks and capabilities all carry the `crazylinks` prefix. |
| Rewrite isolation | One CPT rewrite under `/l/`; root-level URLs only per page after collision checks (D7). Flushed on activation and deactivation. |
| Builder modules are additive | Native Divi and Elementor modules (v1.1+) register only when that builder is active and wrap the same renderer. Builder theme-builder templates are never modified. |
| Compatibility matrix in CI | Automated tests run against a block theme (Twenty Twenty-Five), a classic theme, and at least one builder the owner's test site uses, plus WooCommerce active and inactive. |

---

## 7. Data model

### 7.1 Post type

`crazylinks_page` (public, no archive, `show_in_rest` **false**; all REST access goes through custom routes under `crazylinks/v1` so core's revisions/autosave controllers and Gutenberg never see the type; supports `author`, `revisions`, `thumbnail`).

- `post_title` = page name (internal), `post_name` = slug, `post_author` = owner, `post_status` = draft/publish/future.
- `post_content` = **canonical JSON document** (schema-versioned). Revisions therefore work out of the box and give undo for AI edits.
- Post meta (indexed, for queries and the REST filter): `_cl_schema_version`, `_cl_redirect_url`, `_cl_noindex`, `_cl_ga4`, `_cl_meta_pixel`, `_cl_gtm`, `_cl_utm` (JSON), `_cl_seo` (JSON), `_cl_theme_slug`.

### 7.2 Page JSON (schema v1)

```json
{
  "schema": 1,
  "profile": {"name": "", "bio": "", "avatar": 0, "verified_badge": false, "layout": "classic"},
  "design": { "...see §5..." },
  "blocks": [
    {"id": "01J...", "type": "link", "parent_id": null, "order": 0, "visible": true,
     "schedule": {"start": null, "end": null}, "pinned": false, "animation": "none",
     "tracking": true, "props": {"title": "New single", "url": "https://...", "thumbnail": 0}}
  ],
  "settings": {"utm": {"source": "crazylinks", "medium": "bio", "campaign": "{slug}"}, "in_app_hint": false}
}
```

Flat `blocks` array with `parent_id` (max depth 1) so that the AI operation schema needs no recursion (Anthropic structured outputs reject recursive schemas). A JSON Schema file (`schemas/page.v1.json`) is the single source of truth, used by PHP validation, the editor, and the AI layer.

### 7.3 Custom tables

```
{prefix}crazylinks_events
  id BIGINT PK, page_id BIGINT, block_id CHAR(26) NULL, type VARCHAR(16) (view, click, qr_scan, subscribe; VARCHAR not ENUM so new types need no ALTER),
  ts DATETIME, visitor_hash CHAR(32), country CHAR(2) NULL, device VARCHAR(8) (mobile, tablet, desktop),
  os VARCHAR(16), in_app VARCHAR(16) NULL, referrer_host VARCHAR(255) NULL,
  utm_source VARCHAR(64) NULL, utm_medium VARCHAR(64) NULL, utm_campaign VARCHAR(64) NULL
  INDEX (page_id, ts), INDEX (page_id, block_id, ts)

{prefix}crazylinks_daily
  page_id, block_id NULL, day DATE, type, count INT, uniques INT   -- rolled up nightly by WP-Cron

{prefix}crazylinks_subscribers
  id, page_id, block_id, email (lowercased, unique per page), status (pending|confirmed|unsubscribed),
  consent_text, consent_at, confirmed_at, token_hash (sha256 of a 192-bit random token), created_at, updated_at
  -- no IP, no user agent, no name (built 2026-11-05; tags/synced columns deferred to the integrations phase)

{prefix}crazylinks_ai_log
  id, page_id, user_id, ts, provider, model, prompt_tokens, completion_tokens, cost_estimate,
  operations JSON, applied BOOL, revision_id NULL, source ENUM(admin, mcp)
```

- `visitor_hash` = `hash('sha256', ip + ua + daily_salt)` truncated; salt rotates daily, raw IP never stored. No cookies. **Honest limitation (security review 2026-11-05):** anyone who can read the database on the same UTC day can brute-force IPv4 addresses against that salt in minutes; this is inherent to Plausible-style hashing and is stated in the privacy snippet. Mitigations (implemented): the raw daily salt is a non-autoloaded option (`crazylinks_salt_{Ymd}`) and the effective salt is `hash_hmac(day|raw, wp_salt())`, so brute-forcing needs both the database and the site's wp-config keys; rotation uses `INSERT IGNORE` plus a re-read (core's `add_option` would let the second of two racing requests overwrite the first).
- **Abuse limiting is keyed separately** from the uniques hash: dedupe and rate limits use `hash_hmac('sha256', ip, daily_salt)` with no user agent (so rotating the UA cannot bypass them), page views are rate-limited per IP across all pages (default 120/min, filter `crazylinks_view_rate_limit`), the 30-minute view dedupe and the 60/min click limit are per IP (people behind one NAT count as one view per page per 30 minutes; uniques still use IP+UA), and limiter state lives in the object cache when `wp_using_ext_object_cache()` and otherwise in the `{prefix}crazylinks_limits` table (k CHAR(32) PK, hits, expires; swept 1-in-50 requests and by the daily cron). No transients per visitor.
- Retention settings (built 2026-11-05): raw events 90 days by default (`retention_days`, 0 = keep), daily rollups forever; `pending` and `unsubscribed` subscriber rows 90 days after their last change (`subscriber_retention_days`), confirmed rows never auto-deleted. The daily cron prunes after rollup. Breakdowns (device, OS, in-app, referrer) need raw events, so they cover only the retention window; the summary response reports `events_available_from`. Uniques are summed per day (the salt rotates daily, so cross-day de-duplication is impossible by design).
- Country: from `CF-IPCountry` / `X-Country` headers when present; optional MaxMind GeoLite2 DB upload (**VERIFY** licence terms); else null.

### 7.4 Options

`crazylinks_settings` (single autoloaded option): URL base, default theme, retention, AI provider/model/limits, integrations, in-app hint default, uninstall-data policy.

---

### 7.5 Phase 1 implementation notes (built 2026-11-05)

Accepted deviations from the text above, recorded so the spec and the code agree:

- `crazylinks_daily.block_id` is `NOT NULL DEFAULT ''` (page-level rows use the empty string) because it is part of the composite primary key used for idempotent upserts.
- Days and the visitor-hash salt rotate on UTC, not the site timezone. `country` is always NULL in v1.0 (no geolocation yet; see the MaxMind VERIFY item).
- Rate limiting (60 clicks per visitor per minute, configurable) and the 10-second dedupe only suppress *logging*; a valid `/go/` link always redirects.
- Page views are counted server-side on standalone render, de-duplicated per visitor for 30 minutes, prefetch requests skipped. Under a full-page cache this undercounts; the §6.1 JS view beacon is a v1.1 item.
- Blocks whose schedule has not started are omitted from the HTML entirely so they cannot leak early; the render cache expires at the next schedule boundary; JS only hides blocks that expired while a static cache held the page.
- **Weekly windows (built 2026-11-05, owner request):** `schedule.weekly = {days: [1..7], start: "HH:MM", end: "HH:MM"}` alongside the one-off range; both must hold. Evaluated server-side in the site timezone (`wp_timezone()`, DST follows wall-clock time; an end at or before start wraps past midnight). `/go/`, vCard and subscribe return 404 outside the window, including when the block's Section is out of window. **Full-page caches:** a page with any live schedule defines `DONOTCACHEPAGE` and sends `Cache-Control: public, max-age=N` where N is the seconds to the next boundary (60 s to 24 h); `/go/`, vCard and REST send `no-store`. Pin-to-top toggle sits in the collapsed block row next to visibility and click count (top-level blocks only, as before). Details: `crazylinks/docs/schema-changes.md`.
- Only `http(s)` links are routed through `/go/`: `link`, `image` and `payment` blocks via `/go/{block_id}/`, and `socials` items via `/go/{block_id}/{item_id}/` (items get ULIDs from the validator; built 2026-11-05). `mailto:`, `tel:` and `sms:` links stay direct and untracked. Icon clicks are logged against the socials block; per-network counts need a nullable `item_id` column on the events and daily tables (`block_id` is `CHAR(26)`), a v1.2 follow-up.
- oEmbed is fetched server-side **only on save**, only for allow-listed hosts, with discovery off, through `WP_oEmbed::get_data`, and cached in post meta; visitors never trigger a request. Documented in readme "External services"; disable with the `crazylinks_fetch_oembed` filter. Script-based providers (TikTok, X, Instagram) show their blockquote fallback for now.
- The front-end JS includes a small click-to-play embed facade so provider iframes never load on page load.
- The plugin ships its own minimal PSR-4 autoloader and never loads `vendor/` at runtime.
- `html` blocks and `custom_css` require `unfiltered_html` to add or change; an unchanged `html` block authored by someone else does not block a save; at render time the block is shown only if the page author has `unfiltered_html`.
- Background types in v1.0: solid, gradient, image, plus pattern (added in the polish pass). Video backgrounds are v1.1.
- ~~Follow-up (security): strip disallowed hrefs in text blocks~~ **Done in the post-review fix batch:** `PageValidator::police_links()` removes any `<a href>` that fails `UrlPolicy`.
- **Post-review fix batch (2026-11-05):** all review findings F1–F10 fixed with regression tests (`tests/integration/{SecurityReviewTest,AbuseLimitsTest,CoreChangesTest}.php`); `payment` blocks now route through `/go/`; `Cache::on_transition()` bumps the render cache when any public post is published or unpublished; `/l/{slug}/vcard/` serves a vCard 3.0 built from the first reachable `contact` block with `vcard` true; privacy-policy text registered via `wp_add_privacy_policy_content`; `fetch_oembed` setting (default on) inside `crazylinks_settings`; DB version 2 adds `crazylinks_limits`. Shortcode and block return an empty string for password-protected pages; only standalone and theme modes show the password form.

**Polish pass (Sonnet, 2026-11-05):**

- 14 theme presets, each contrast-tested to WCAG AA by `tests/integration/ThemeContrastTest.php`: `default` (+dark), `midnight`, `sunrise`, `paper` (+dark), `brutalist`, `pastel` (+dark), `mono` (+dark), `neon`, `forest`, `terracotta` (+dark), `glass`, `aurora`, `retro`, `blueprint`.
- Backgrounds: solid, gradient, image, and `pattern` with 6 CSS-only patterns (dots, grid, stripes, lines, checks, noise). §5A.4's 8 patterns and video backgrounds remain v1.1.
- Brand icons: 40 Simple Icons paths (CC0; LinkedIn and Amazon from v13.21.0, the rest from v16.36.0; see `LICENSE-icons.txt`) plus original glyphs for email, phone, website, pin. Extensible via the `crazylinks_icons` filter.
- Implemented block partials: `gallery` (grid; `carousel` accepted by the schema but renders as grid for now), `latest_posts` (render cache capped at 15 min plus a status-change cache bump), `video` (click-to-play facade or self-hosted `<video>`), `contact` (rows; vCard download via `/l/{slug}/vcard/`), `countdown`, `map` (link out to Google/Apple Maps, no embedded map), `payment` (presets: Buy Me a Coffee, Ko-fi, PayPal.me, Venmo, Cash App, Stripe Payment Link, Patreon, GoFundMe; URL must match the preset's host; routed through `/go/`). Only `woo_products` is still a placeholder (Phase 4).
- Link presentation: classic, thumbnail (left/right), featured card; subtitle, badge, pinned marker, NEW flag with `new_until` expiry, animation; page-level list or 2-column grid layout.

---

## 8. URL structure and rewrites (D7)

- Default base `/l/{slug}` registered as the CPT rewrite slug. Base editable (e.g. `/links/`, `/bio/`).
- Optional root-level slugs `/{slug}`: enabled per page after a collision check against existing posts, pages, terms, author slugs, reserved words and other CPTs; a single narrowly scoped rewrite rule per page, never a catch-all.
- Click redirect: `/l/{slug}/go/{block_id}` → logs → `302` with `Referrer-Policy: no-referrer-when-downgrade`, UTM appended if enabled. Works with JS disabled and inside webviews.
- Rewrite rules flushed on activation and when the base changes; a health check on the settings page detects 404s and offers a one-click flush.
- Optional "use as homepage" sets the page as the static front page with a scoped template.

---

- `/{base}/{slug}/vcard/` (added 2026-11-05): `text/vcard` download generated from the page's public `contact` block fields only, with `Content-Disposition: attachment`; same visibility, schedule and password checks as `/go/`. Three rewrite rules in total.

## 9. Admin editor (D5)

A single-screen React app (built with `@wordpress/scripts`, `@wordpress/components`, `@wordpress/data`) at **CrazyLinks → Pages → Edit**:

- **Left:** block list with drag-and-drop reorder, add-block drawer, inline editing, per-block schedule and pin controls, visibility toggles.
- **Right:** live phone-frame preview rendered from the same PHP renderer via a REST preview endpoint (what you see is what ships).
- **Tabs:** Links · Design · Settings (SEO, UTM, pixels, redirect) · Analytics · AI (Phase 2).
- **Save model:** autosave to a draft revision; explicit Publish. Revisions browsable and restorable.
- **Onboarding:** first-run wizard (name, avatar, pick a theme, paste socials, import from Linktree) producing a publishable page in under two minutes.
- **No upsell nags.** If a Pro exists, it appears once in the Pro tab and nowhere else.

Also: **Pages list** (WP list table with views, clicks, CTR columns), **Analytics** screen (date range, per-block table, country/device/referrer breakdowns, CSV), **Subscribers** screen, **Settings** screen. **Built 2026-11-05 (Phase 2b):** Analytics and Subscribers screens are plain PHP with inline SVG charts (no build step); subscribe flow is double opt-in by default with signed confirm/unsubscribe links (GET, 7-day confirm expiry, unsubscribe never expires), `List-Unsubscribe` header, at most 3 mails per address per hour, identical responses whether or not an address exists; exporter and eraser registered; CSV export escapes formula-leading characters; hooks `crazylinks_subscriber_added|pending|confirmed|unsubscribed` and the `crazylinks_subscribe_handle` short-circuit filter for CRM adapters. Not yet: per-block success wording (schema change), a `subscribe` event type, CRM adapters.


**Built 2026-11-05 (Phase 2a):** the edit screen is taken over with the `replace_editor` filter (`?crazylinks_classic=1` or no-JS falls back to the JSON meta box). Store `crazylinks/editor` holds the working document, title/slug/status, saved snapshots, 100-step undo history, selection, errors mapped by JSON path, save state, media cache and per-block clicks. Block panels and the add-block drawer are generated from `registry/blocks.ts` (one entry per schema type). Preview: 400 ms debounce → `POST /render-preview` → sandboxed `<iframe srcdoc>` using the server's full document, with postMessage click-to-select. Autosave after 10 s idle to `POST /pages/{id}/autosave` (one WP autosave per user; a never-saved page becomes a draft). History modal lists revisions with "Load into editor" (undoable) or server-side restore. Cmd/Ctrl+S saves. **v1.1 polish (built 2026-11-05):** footer credit (`settings.footer_credit`, off by default, filters `crazylinks_footer_credit_*`); alt-text warnings (never blocking: `ValidationResult::warnings()`, editor shows them inline and asks "Publish anyway"; `decorative` flag silences; library alt is the fallback); core post locking with heartbeat, lock banner and take-over; drag between groups with one nesting level; contrast chips for every text/background pair in light and dark; email-capture `success_text`/`pending_text`; focal-point picker for banner and featured images; bento `tile_size` 1x1/2x1/2x2 in grid layout. Not built: pixel and redirect settings (privacy-sensitive; decide in Phase 4).
---

## 10. AI editing (Phase 2)

### 10.1 Principles

1. AI never writes to the database directly. It proposes **operations**; the same validator and save path used by the human editor applies them.
2. Every AI change is previewed (before/after phone preview and a plain-English change list), confirmed, and reversible via post revisions.
3. The model acts with the invoking user's capabilities. Nothing the user cannot do, the AI cannot do.
4. Page content is treated as data, never as instructions (prompt-injection hardening).
5. The AI never sees secrets: no options, no API keys, no subscriber emails, no raw visitor data.
6. Runs on the site owner's own provider key by default (wordpress.org guidelines 6 and 7: documented external service, explicit opt-in).

### 10.2 Architecture

```
Editor "Ask AI" box ──┐
                      ├──> REST /crazylinks/v1/pages/{id}/ai/propose  ──> AI Driver ──> ops JSON
Claude / ChatGPT ─────┘        (capability + nonce + rate limit)            │
  via MCP Adapter                                                         validate against page.v1.json
  (abilities)                                                             + business rules
                                                                              │
                      REST /crazylinks/v1/pages/{id}/ai/apply  <──── preview/confirm ──┘
                            (creates revision, logs to crazylinks_ai_log)
```

**AI Driver interface** (D3):

```php
interface AiDriver {
    public function isAvailable(): bool;                 // provider configured + model supports text
    public function propose(ProposalRequest $r): ProposalResult|WP_Error;  // returns ops JSON
    public function suggestText(string $kind, array $ctx): array|WP_Error; // titles, bios
}
```

- `WpAiClientDriver` (the plan, D3): uses `wp_ai_client_prompt()` from WordPress 7.0; checks `is_supported_for_text_generation()`; respects the `wp_ai_client_prevent_prompt` filter; requests JSON output and validates it against the ops schema (retry once with the validation errors on invalid output). The site owner configures nothing in our plugin beyond choosing a model; the provider key lives in core's Settings → Connectors and is shared by every AI-aware plugin on the site. **Verified against WP 7.1.4 source (2026-11-05):** `WP_AI_Client_Prompt_Builder` proxies the PHP AI Client with snake_case names and `WP_Error` returns. Useful calls: `using_system_instruction()`, `using_model_preference()`, `using_max_tokens()`, `using_temperature()`, `as_json_response( $schema )` (schema-constrained JSON output, so no SDK is needed for that), `using_abilities( ...$abilities )` (lets the model call registered Abilities as tools), `generate_text()`, `generate_texts()`, `generate_image()`. With no provider configured, `is_supported_for_text_generation()` returns `false` and `generate_text()` returns `WP_Error` `prompt_invalid_argument` (status 400, "No models found that support text_generation"). There is **no streaming** in the builder. Hooks: `wp_supports_ai`, `wp_ai_client_prevent_prompt`, `wp_ai_client_default_request_timeout`, `wp_ai_client_cache_group`.
- `AnthropicDriver` (contingency only): schema-constrained output is confirmed in core, so the only remaining gap is streaming. Added only if non-streamed proposals feel too slow in the eval set or the retry-on-invalid approach proves unreliable. Would use the official `anthropic-ai/sdk` for PHP with `outputConfig: ['format' => <schema>]` and `fallbacks: 'default'`. Not built unless the gap is real.
- Default model recommendation: **Claude Sonnet 5.5** for edits (about $0.02 per edit at ~5K in / 1K out tokens), **Claude Haiku 5.5** for title and bio suggestions. Admin can choose any model the provider exposes.

### 10.3 Operation schema (`schemas/ai-ops.v1.json`)

Flat, non-recursive, `additionalProperties: false` throughout:

```json
{
  "summary": "Added a link to the new single and pinned it to the top.",
  "operations": [
    {"op": "add_block",    "after_id": null, "block": { "...block object per §4, id omitted..." }},
    {"op": "update_block", "id": "01J...", "props": {"title": "Listen on Spotify"}, "pinned": true},
    {"op": "move_block",   "id": "01J...", "after_id": "01K..."},
    {"op": "remove_block", "id": "01J..."},
    {"op": "set_design",   "design": {"accent": "#f97316", "button": {"shape": "pill"}}},
    {"op": "set_profile",  "profile": {"bio": "New album out now"}}
  ],
  "questions": []
}
```

- `html` blocks are excluded from the schema; the model cannot create or edit them.
- `questions` lets the model ask instead of guessing when a request is ambiguous ("Which Spotify link, the album or the single?").
- Business-rule validation after schema validation: URL policy, block count limits, schedule sanity, contrast warnings, image ids must belong to the site.

### 10.4 Flows

| Flow | Input | Output |
|---|---|---|
| Edit by instruction | "Move the podcast link under the merch link and make the buttons rounded" | ops → preview → apply |
| Build from this site | Site title/tagline, latest 10 posts, Woo products, user's social URLs from profile, existing menus | A complete first page (profile + links + socials + theme) |
| Build from description | "I'm a wedding photographer in Austin, I want booking, portfolio and Instagram" | Complete first page |
| Suggest | Selected link | 5 alternative titles, or a bio rewrite |
| Restyle | "Make it feel like a 90s skate brand" | `set_design` ops only |
| Analytics Q&A | "Which link did best this month and what should I pin?" | Text answer + optional `update_block` pinned op; reads rollups only |
| Import clean-up | Imported Linktree page | Dedupe, title fixes, group suggestions |

### 10.5 Abilities for external agents (MCP)

Registered through the WordPress Abilities API so the official **MCP Adapter** plugin (0.7.1 on wordpress.org, requires WP 6.9) can expose them. **Verified against WP 7.1.4 (2026-11-05):** `wp_register_ability( string $name, array $args )` with required `label`, `description`, `category`, `execute_callback`, `permission_callback`, optional `input_schema`, `output_schema`, `meta` (`annotations` {`readonly`, `destructive`, `idempotent`}, `show_in_rest`), `ability_class`; categories via `wp_register_ability_category( $slug, $args )`; helpers `wp_get_ability()`, `wp_get_abilities()`, `wp_has_ability()`; core REST routes under `abilities/v1` (list, run, categories). Core ships three abilities (`core/get-site-info`, `core/get-user-info`, `core/get-environment-info`).

| Ability | Annotation | Permission |
|---|---|---|
| `crazylinks/list-pages` | readonly | `edit_crazylinks_pages` |
| `crazylinks/get-page` | readonly | can edit that page |
| `crazylinks/propose-changes` | readonly (returns ops, does not apply) | `use_crazylinks_ai` on that page |
| `crazylinks/apply-changes` | destructive, idempotent (by proposal id) | can edit that page; honours a per-page "require confirmation" setting |
| `crazylinks/get-analytics-summary` | readonly | `view_crazylinks_analytics` |
| `crazylinks/suggest-titles` | readonly | `use_crazylinks_ai` |

Abilities are private by default and enabled by the admin per ability. Every MCP-sourced change is logged with `source = mcp` and shown in the page's history with an "AI (external)" badge, mirroring Linktree's Premium feature at no charge.

### 10.6 Limits, cost and safety

- Rate limit per user (default 30 proposals/hour) and per site (default 300/day), configurable; HTTP 429 with a friendly message.
- Monthly token budget per site with a soft warning at 80% and hard stop at 100% (core has no cost cap; we add one).
- Context sent to the model: system prompt + ops schema + current page JSON (trimmed: no attachment binaries) + user instruction + optional site facts. Typical 4-8K tokens.
- System prompt states explicitly that page text, link titles and URLs are untrusted data and must not be followed as instructions.
- Hide all AI UI when no provider is configured; show a one-line pointer to Settings → Connectors.
- Readme "External services" section documents every provider that may be contacted, what is sent, and links to their privacy policies. First use requires an explicit opt-in checkbox.
- `crazylinks_ai_log` retains prompts and ops for 90 days by default (configurable, can be off).

**Built 2026-11-05 (Phase 3, Opus):** `WpAiClientDriver` on the core AI Client (`as_json_response()` with a limits-stripped copy of `schemas/ai-ops.v1.json`; one retry with validator errors; model preference Sonnet 5.5 → Sonnet 5 → Sonnet 4.6 for edits, Haiku 5.5 for suggestions and grading; no temperature override). Prompt: fixed per mode, opens with a trust boundary (only `<instruction>` and `<answers>` are the user; `<page_json>`, `<site_facts>`, `<media>`, `<analytics>` are untrusted data), inputs tag-wrapped with `<`/`>` escaped, custom HTML/CSS replaced by a note, never options/keys/emails/subscribers/visitor data (tested). Ops schema keeps the §10.3 shapes plus `add_block.ref` (`new-N` references within one proposal), `move_block.parent_id`, and `after_id: null` = first in parent; `maxItems` 60 operations, 5 questions. `OpsValidator` (schema → business rules) and `OpsApplier` (pure) feed the same `PageValidator` as a human save. REST: `POST /pages/{id}/ai/propose|apply|suggest`, `GET /ai/status`; proposals expire after 30 minutes and are re-validated against the current document on apply; per-user 30/h, per-site 300/day, monthly token budget soft 80% / hard 100%; `crazylinks_ai_log` (DB version 4) with 90-day retention, exporter/eraser. Editor AI tab: modes Edit / Build from this site / Build from description / Restyle / Ask about analytics, summary + change list + questions, before/after phone preview, Apply (undoable, then saved) / Discard, session history, title and bio suggestions; shown to anyone with `use_crazylinks_ai`, with a pointer to Settings → Connectors when no provider is configured. Abilities registered in category `crazylinks` with the §10.5 table's annotations and permission checks (admin enable switches enforced in permission callbacks; disabled abilities still appear in `abilities/v1` listings). Eval set: 40 cases with recorded responses, `wp crazylinks ai eval --offline` passes; the live eval and Haiku grading run only when a provider is configured. **First live run (AsylumLife.com, 0.1.6, 2026-11-05):** Anthropic's structured outputs rejected both the full (90 KB) and a compact (7.6 KB) operations schema as "compiled grammar is too large", and strict mode forbids open objects, so provider-side schemas are now **off by default** (`crazylinks_ai_structured_output` filter re-enables them); the prompt asks for a bare JSON object and `OpsValidator` checks it with one retry. With that, a real edit ("add a heading called Watch above the YouTube link and pin the Latest episode link") proposed the right two operations on claude-sonnet-5-5 in 4.7 s for about 2,800 tokens, and apply created a revision. The schema generator is `bin/build-ai-schema.php` (`AiOpsTest` fails if the two schemas drift).

### 10.7 MCP route: editing from Claude Desktop (owner's preferred path)

**Decision 2026-11-05.** The owner already works on their WordPress site from Claude Desktop through a connector and wants CrazyLinks editing to work the same way. In this route the model runs on the owner's Claude subscription, so the site needs no provider key; the site's job is to expose safe, validated tools. The API route of §10.1-10.6 is kept as the fallback for sites and editors without Claude.

Scope of the next build:

1. **Mirror the existing connection.** Identify how the owner's current Claude Desktop connection reaches AsylumLife.com (which MCP plugin or service, which transport, how it authenticates) and plug CrazyLinks into that path if it exposes Abilities; otherwise install the official MCP Adapter and confirm the transport and authentication Claude Desktop's custom connectors accept (streamable HTTP; application passwords vs OAuth is the open point in §17).
2. **Tools for a model that writes the operations itself.** The §10.5 abilities assume the site calls the model. Add:
   - `crazylinks/get-editing-guide` (readonly): block types, props, the operations format of §10.3 with examples, and the page's current JSON trimmed as in §10.6, so the external model knows how to write operations.
   - `crazylinks/preview-operations` (readonly): takes raw operations, runs `OpsValidator` and `OpsApplier` without saving, returns the change list, validation errors and the resulting document summary.
   - `crazylinks/apply-operations` (destructive): takes raw operations, validates them exactly like a proposal, saves through the normal path with `source = mcp`, honours the per-page "require confirmation" setting (then it stores a proposal for the editor's AI tab instead of saving), returns the revision id.
   `propose-changes` and `apply-changes` stay for sites that also have a provider key.
3. **Same safety rails as the API route.** `PageValidator` on every save, rate limits per user and per site counted alongside API proposals, `crazylinks_ai_log` rows with `source = mcp`, "AI (external)" badge in history, abilities private by default and enabled per ability by the admin. The trust boundary still applies: page text and URLs returned to the model are data, and the editing guide says so.
4. **Docs and a connection check.** `readme.txt` and `docs/mcp.md` explain how to connect Claude Desktop (and Claude Code) to a site, with a `wp crazylinks mcp status` command that lists which abilities are enabled and whether an MCP server is reachable.

Test plan: Playwright is not applicable; test through the Abilities REST routes (`abilities/v1/.../run`) with an application password on the dev site, then a real Claude Desktop session against the dev site, then AsylumLife.com.

**Built 2026-11-05 (0.2.0, Opus):** Against MCP Adapter 0.7.1 source (`WordPress/mcp-adapter` trunk); the adapter itself was not installed on the dev site. Item 1 was settled as: the official MCP Adapter, HTTP transport, Application Passwords (Claude Code direct with a Basic header, Claude Desktop through `@automattic/mcp-wordpress-remote`); OAuth (claude.ai web) remains open. Abilities: `get-editing-guide` (readonly; markdown generated from `schemas/ai-ops.v1.json` and the prompt's own operations and rules wording, about 13.8 KB / 3.5K tokens, plus `block_types[]`, `operations[]`, and with `id` the `Context::page()` form and `modified`), `preview-operations` (readonly, GET on core's run route; `OpsValidator` → `OpsApplier` → `PageValidator` on a copy, invalid operations return `valid: false`), `apply-operations` (destructive, POST; `expected_modified` → 409 `crazylinks_conflict`, `idempotency_key` replays within 30 minutes, `Limits::hit()` shared with API proposals but no token budget, refused with 409 `crazylinks_page_locked` while another user holds the editor lock, saves through `PATCH /pages/{id}`, revision meta `mcp`, `crazylinks_ai_log` row kind `external`, model `external`, 0 tokens). Every ability carries `meta.public` and `meta.mcp {public, type: tool}` from its enable switch (evaluated at registration, once per request) on top of the permission-callback gate; `show_in_rest` stays true. Require confirmation stores the operations as a pending proposal (7-day TTL, index in post meta `_crazylinks_ai_pending`, max 10 per page) listed by `GET /pages/{id}/ai/pending`, confirmed by any user who may edit the page through `POST /ai/apply` (badged "AI (external)") or dropped with `POST /ai/discard`; the editor's AI tab shows them even without a site provider. Own server `crazylinks` at `/wp-json/crazylinks/mcp` on `mcp_adapter_init` (setting `ai_mcp_server`, filter `crazylinks_mcp_server`), tools = enabled abilities, transport permission 401 logged out / 403 without a CrazyLinks capability; the adapter's default server reaches the same enabled abilities. The adapter passes only `WP_Error` messages to clients, so agent-facing errors carry their details in the message. Settings → AI editing gained an "External agents (MCP)" box (adapter status, both endpoints, account advice, Claude Desktop and Claude Code snippets with placeholders) backed by `Ai\McpStatus`, shared with `wp crazylinks mcp status` and `wp crazylinks mcp guide [--page=<id>]`. Guide: `crazylinks/docs/mcp.md`. Tests: 21 PHPUnit cases in `AiMcpTest` (fake `WP\MCP\Core\McpAdapter` recorder), 3 Vitest cases; checked over HTTP on the dev site with an application password (get-editing-guide 200, preview valid and invalid 200, POST to a readonly ability 405, no auth 401, disabled ability 403). **Verified on the dev site with MCP Adapter 0.7.1 installed by the owner (2026-11-05):** `wp mcp-adapter list` shows server `crazylinks` 0.2.0 with 5 tools once the five abilities are enabled (0 before). HTTP handshake at `/wp-json/crazylinks/mcp` with an application password: `initialize` 200 with `Mcp-Session-Id`, `serverInfo {CrazyLinks, 0.2.0}` and our `instructions`; `notifications/initialized` 202; `tools/list` returns `crazylinks-list-pages`, `-get-page`, `-get-editing-guide`, `-preview-operations`, `-apply-operations` with the ability descriptions; `tools/call` results carry `content[0].text` (JSON) and `structuredContent`; WP_Error results come back as `isError: true` with the message. Full cycle on the demo page: preview valid with change list (6.3 KB result for 24 blocks), apply with `expected_modified` + `idempotency_key` → revision 91 badged `mcp`, replay → same revision with `replayed: true`, stale `expected_modified` → conflict message with the current time, `javascript:` URL → rejected with the path in the message, restore → revision 92; `DELETE` session 200. The adapter's stdio bridge (`wp mcp-adapter serve --server=crazylinks --user=admin`) answers the same initialize/tools/list. Not run: the `@automattic/mcp-wordpress-remote` proxy (the sandbox refuses to execute npm packages), so the Claude Desktop path is verified only up to the HTTP endpoint it talks to; the owner tests Claude Desktop itself with the snippet from Settings. **Live (AsylumLife.com, 2026-11-05):** 0.2.0 uploaded over 0.1.6 ("Plugin updated successfully"), public page `/linktree/main/` still 200, settings page renders with the MCP box reporting the adapter as not installed, the five MCP abilities enabled there (AI enabled, base `linktree` and the server switch unchanged). The MCP Adapter is not yet installed on the live site; the owner installs it, creates a dedicated user with an Application Password and connects Claude Desktop with the snippet from the settings box.

---

## 11. Integrations

| Category | v1 | Later |
|---|---|---|
| Commerce | WooCommerce products block, cart link; payment and tip link presets (Buy Me a Coffee, Ko-fi, PayPal, Venmo, Cash App, Stripe Payment Links, Patreon) | Easy Digital Downloads, SureCart, Stripe Checkout embed, Woo product quick-view |
| Email | Local store + MailPoet, FluentCRM, Mailchimp, Kit, beehiiv (REST/hook adapters) | Klaviyo, Flodesk, ActiveCampaign |
| Forms | Embed block accepts Gravity/WPForms/Fluent Forms shortcodes | Native form picker |
| Bookings | Link to Amelia/Bookly/Calendly | Inline booking embed facade |
| Analytics | GA4, Meta Pixel, GTM IDs; CSV | Burst Statistics / Independent Analytics event bridge, Plausible/Fathom |
| Short links | None needed (first-party `/go/` redirect) | Pretty Links / ThirstyAffiliates picker |
| Media | Media Library, oEmbed | Canva import (later, if API allows) |
| Hooks | `crazylinks_render_block`, `crazylinks_before_save`, `crazylinks_event_logged`, `crazylinks_subscriber_added`, `crazylinks_ai_proposal`, `crazylinks_register_block_type` for third-party blocks | |

---

## 12. Security, privacy and directory compliance

- All output escaped; all input sanitized; URL allowlist; `wp_kses` for rich text; nonces + capability checks on every REST route; prepared statements only.
- Custom HTML block gated to `unfiltered_html`; disabled on multisite for non-super-admins by default.
- Email capture: honeypot, per-IP rate limit (HMAC of IP, never UA-keyed), double opt-in on by default, one generic error message (no page/block existence oracle), same visibility/schedule/password checks as `/go/`. Stored per subscriber: email, consent text snapshot, timestamp, page and block id. Never IP or UA. Retention configurable; exporter and eraser hooks required before the endpoint stores anything.
- No tracking cookies; no PII in events; daily-rotating salt; documented in a Privacy Policy snippet the plugin adds to WP's privacy-policy guide (`wp_add_privacy_policy_content`); personal-data exporter and eraser hooks for subscribers.
- Password-protected link pages render only `get_the_password_form()` in every mode, log no view, and are never written to the render cache. Draft and private pages 404 to anonymous visitors.
- The render cache signature includes `home_url()` and `is_ssl()` (prevents Host-header cache poisoning on sites that derive `WP_HOME` from the request); `/go/` and REST URLs in cached HTML are emitted root-relative.
- Rewrite query vars are scoped: `crazylinks_go` is honoured only on a singular `crazylinks_page` request, and a bare `crazylinks_slug` query string only when permalinks are plain, so other URLs cannot be hijacked or cached as 404s.
- Rich-text links (`text` block) are held to the same URL policy as link blocks: after `wp_kses`, any `<a>` whose `href` fails `UrlPolicy` has the attribute removed entirely (WordPress alone would leave a broken relative href). `UrlPolicy` rejects userinfo (`user:pass@`), control/format/space characters, and re-validates the cleaned URL so the host cannot change between check and store.
- Distribution: `bin/` scripts are guarded with `PHP_SAPI === 'cli'`, and a `.distignore` excludes `vendor/`, `tests/`, `bin/`, Composer and tool configs from the release zip.
- Uninstall: option to delete all data or keep it.
- wordpress.org guidelines: no gating of included code (5); external services documented (6); opt-in before any external call (7); no remote JS/CSS by default (8). Google Fonts, GA4 and Meta Pixel are opt-in by the owner and documented. **oEmbed decision (2026-11-05):** fetching an embed preview on save is on by default, exactly as core does for posts; saving an `embed` block is the owner's consent, the readme says so, and a settings toggle turns it off entirely.
- **First live install (AsylumLife.com, 2026-11-05):** publishing a page that had no slug yet crashed with "Object of class WP_REST_Request could not be converted to string". Cause: a REST `sanitize_callback` set to the bare `sanitize_title`, which WordPress calls as `( $value, $request, $param )`, so the request object became the function's `$fallback_title`. Rule from now on: REST sanitize and validate callbacks are always single-argument wrappers, never bare WordPress functions with optional extra parameters. Fixed in 0.1.1 with a regression test that dispatches through `rest_get_server()`.
- **Review log:** Phase 1 independent review (Opus, 2026-11-05) found 0 critical, 1 high (password-protected pages rendered in full), 2 medium (UA-rotatable rate limits; cache not keyed by host), 7 low; all are addressed in the post-review fix batch and the fixes above are now spec requirements. MCP and public-surface review (Opus, 2026-11-06, 0.2.1 on the dev site with MCP Adapter 0.7.1): 0 critical, 0 high, 2 medium, 1 low fixed. Medium: core's `?post_type=crazylinks_page` archive, search and feed queries listed every published page's stored JSON, hidden and scheduled blocks included (now `PostType::no_listings()`: non-singular front-end main queries drop the type); the docs and settings box recommended Editor for the agent account, which carries `unfiltered_html` and every post (now a `crazylinks_agent` role, `wp crazylinks mcp agent-role`). Low: `propose-changes` with agent-written operations skipped `Limits::hit()`, so propose + apply-changes bypassed the hourly limit (now counted). Rule from now on: a link page is loaded only by a singular request; the agent account never gets a core role.
- Security review before 1.0 (ClickWhale was delisted after a SQL-injection and guideline history; AI Engine leaked keys through an MCP path that read options). Threat model includes: SSRF via oEmbed/link-check, open redirect via `/go/` (only redirect to stored URLs, never to a query parameter), XSS via block props, abuse of the email endpoint, AI prompt injection.

---

## 13. Accessibility and internationalization

- WCAG 2.1 AA on the front end: semantic landmarks, visible focus, 4.5:1 contrast enforcement with editor warnings, reduced-motion respected for animations, alt text required for images, embeds labelled.
- Editor keyboard-operable (drag-and-drop has keyboard equivalents).
- All strings translatable (`crazylinks` text domain), RTL stylesheet, locale-aware number and date formatting in analytics.

---

## 14. Import, export and migration

- **Export/import JSON** (full page, including design and settings; media referenced by URL and re-sideloaded on import).
- **Linktree import:** paste a public `linktr.ee/handle` URL; the plugin fetches the public page and extracts profile and links (**VERIFY** feasibility and ToS). Fallback: paste links one per line.
- **Other imports** (Beacons, Carrd, Stan) via the same paste-list path in v1; dedicated importers later if demand appears.
- Positioning uses the shutdown record (Koji, Linkpop, Bento, Fingertip, kit.co): "Your page can't be acquired and shut down."

---

## 15. Technical stack and quality gates

| Layer | Choice |
|---|---|
| PHP | 8.1+, PSR-4 autoloading under `CrazyLinks\`, no runtime Composer dependencies in the free plugin (Anthropic SDK scoped via PHP-Scoper only in the optional driver) |
| WordPress | 6.9+ minimum; AI features feature-detect 7.0+ |
| Admin JS | `@wordpress/scripts`, React, `@wordpress/components`, `@wordpress/data`, TypeScript |
| Front end | PHP templates, inline CSS, ≤ 8 KB vanilla JS |
| Schemas | JSON Schema 2020-12 in `/schemas`, validated in PHP (`opis/json-schema` scoped) and TS (`ajv`) |
| Tests | PHPUnit + WP test suite (integration, 260 tests), Vitest for the editor (`@wordpress/scripts` 37 runs Vitest under `test-unit-js`; 160 tests), Playwright smoke tests on the installed Chrome, PHPCS (WordPress-Extra + Docs), PHPStan level 6; Lighthouse CI budget still to add |
| Dev env | Local: MAMP PRO on the owner's Mac (no Docker installed), site `crazylinks.test`, see `docs/DEV-ENVIRONMENT.md`. CI only: `@wordpress/env` matrices for WP 6.9 and 7.x on PHP 8.1/8.3/8.4 |
| CI | GitHub Actions: lint, tests, build, plugin-check (WordPress Plugin Check), release zip |
| Docs | `readme.txt` (wp.org), `/docs` developer docs, hooks reference generated from docblocks |

Repository layout:

```
crazylinks/
  crazylinks.php            bootstrap, requirements check
  uninstall.php
  src/                      PHP (Admin, Rest, Render, Analytics, Ai, Abilities, Import, Integrations)
  schemas/                  page.v1.json, ai-ops.v1.json
  templates/                standalone page template, block partials
  assets/src/               editor (TS/React), front-end JS/CSS
  assets/build/             compiled
  languages/
  tests/                    phpunit, e2e
  docs/
```

---

## 16. Build plan and model allocation

The owner asked that each agent run on a model fit for its task.

| Phase | Work | Model | Why |
|---|---|---|---|
| 0 | Spec review and decisions (this document) | Fable 5.1 (coordinator) | Judgment and synthesis |
| 1 | Architecture skeleton: CPT, schemas, REST controllers, renderer, rewrite layer, security scaffolding | Opus 5.5 | Core correctness, security-sensitive |
| 1 | Themes, block partials, CSS, icon set, readme, i18n scaffolding | Sonnet 5.5 | Well-specified, high volume |
| 1 | Unit and e2e tests, PHPCS/PHPStan fixes | Sonnet 5.5 | Mechanical, fast |
| 1 | Security review of REST, redirect, email endpoints | Opus 5.5 (independent reviewer, not the author) | Un-anchored second read |
| 2 | Editor React app | Opus 5.5 for state model and preview bridge; Sonnet 5.5 for components | Split by difficulty |
| 2 | Analytics tables, rollups, CSV, screens | Sonnet 5.5 | Specified in §7 |
| 3 | AI driver, ops validator, preview/apply, abilities, prompt design | Opus 5.5 | Safety-critical |
| 3 | Prompt and eval set for AI edits (20-40 real instructions → expected ops) | Opus 5.5 to author, Haiku 5.5 as a cheap grader | Standard eval pattern |
| 4 | Linktree importer, integrations adapters, "match my site" preset, builder modules if wanted, payment-link presets | Sonnet 5.5 | Adapter code |
| 5 | Agency / Pro (scope per D2, later) | Opus 5.5 for multi-site auth and licensing; Sonnet 5.5 for dashboard UI | Security-sensitive cross-site access |
| All | Code review on each PR | Opus 5.5 | Catch regressions |

Suggested cadence: Phase 1 produces an installable plugin that renders a page and logs clicks (one to two sessions). Phase 2 adds the editor and analytics. Phase 3 adds AI. Each phase ends installed on the owner's test site.

**Progress log**

| Date | Phase | Outcome |
|---|---|---|
| 2026-11-05 | 1 core (Opus) | Installable plugin on the dev site: CPT + schema, REST `crazylinks/v1`, renderer in four modes, `/l/{slug}` + `/go/` click logging, settings, WP-CLI; 97 integration tests. |
| 2026-11-05 | 1 polish (Sonnet) | 14 contrast-tested themes, 40 Simple Icons brand SVGs, pattern backgrounds, 7 more block partials, link presentation variants; 158 tests. |
| 2026-11-05 | 1 review (Opus, independent) | 0 critical, 1 high, 2 medium, 7 low findings; all fixed in a follow-up Opus pass with regression tests; 206 tests. Details in §12 review log and §7.5. |
| 2026-11-05 | 2 done | Opus: React editor (§9) with live phone preview, generated block panels, autosave + revisions, wizard, 142 KB bundle (42 KB gzipped), 160 Vitest + 3 Playwright tests. Sonnet: Analytics and Subscribers screens, list columns, double opt-in sign-ups, exporter/eraser, CSV, retention. 260 PHP tests. Screenshots in `docs/screenshots/editor-*.png`. |
| 2026-11-05 | owner feedback | Opus: pin toggle in the block row, weekly visibility windows in site time with cache bypass for full-page caches, Sections (styled, schedulable containers), configurable headings; 442 PHP, 213 JS, 5 e2e tests. URL base on the dev site set to `linktree`. First site decided: AsylumLife.com. |
| 2026-11-05 | 3 done | Opus: AI editing per §10 (see §10 build notes); 39-case eval set passes offline; live model run still pending a configured provider. Sonnet: v1.1 editor polish done (see §9). Totals: 419 PHP tests, 203 Vitest tests, 5 Playwright tests. Dev-site core repaired from the official 7.1.4 zip after WP-CLI's tar extraction truncated 22 AI-client file names. |
| 2026-11-06 | check | The dev demo's "On air now" section had lost its weekly window in revision 90 (2026-11-05 18:13 site time, admin save, no AI badge), during the evening the weekly feature was built and the e2e suite ran repeatedly (last run 18:40): most likely an early run on the pre-weekly editor bundle. Verified now: `to_json_shape` → `PageValidator` → `PATCH /pages/{id}` keep `schedule.weekly`, and a real editor save (e2e "add a link block" on the installed Chrome, revision 96) keeps the group's window. Window restored on the demo. |
| 2026-11-06 | 0.2.2 + 0.2.3 live | Independent security review (Opus, §12 log): 0 critical/high, 2 medium + 1 low, all fixed with tests (472 PHP / 216 JS): link pages no longer listable through feed/archive/search (stored JSON was readable anonymously, confirmed on the live site before the fix), propose-with-operations counted against the limit, `crazylinks_agent` role (created on activation/update, `wp crazylinks mcp agent-role`). Both versions uploaded to AsylumLife.com and verified from outside. Report for the owner: `reports/security-review-2026-11-06.md` (private) and a private artifact page. CI passes since run 4 (official zip + GNU tar for the test library). |
| 2026-11-06 | 0.2.1 live | Login name pre-filled in the MCP snippets; `crazylinks-pages` Claude skill; front-end fixes measured on the owner's page at 390px: grid-Section text centered (was 20px left: list-mode badge strip), buttons with icon/emoji/thumbnail 56px like plain buttons (were 68px: 44px media + 12px padding, now 5px), emoji in the same 44px box as brand icons so rows align, equal tile heights across rows in link-only grid Sections (`grid-auto-rows:1fr` via `:has()`), collapsible Section title centered. Uploaded to AsylumLife.com and verified from outside. Owner keeps URL base `l`. |
| 2026-11-05 | 4 MCP route (Opus) | 0.2.0: get-editing-guide / preview-operations / apply-operations, own MCP server, pending confirmations in the editor, settings box, `wp crazylinks mcp`; 468 PHP / 216 JS tests. Dev handshake and apply cycle verified with MCP Adapter 0.7.1; deployed to AsylumLife.com with the five abilities enabled; adapter install and Claude Desktop test on the live site pending with the owner. |
| 2026-11-05 | live + decision | 0.1.1-0.1.6 deployed to AsylumLife.com (REST slug sanitizer, settings save, Anthropic schema limits); first live AI edit succeeded. Owner decided the preferred AI path is Claude Desktop over MCP on their subscription (D3 note, §10.7); the API path stays as the fallback. Next build: §10.7. |


---

## 16a. Considered and set aside

| Date | Idea | Outcome |
|---|---|---|
| 2026-11-06 | **Live-show block that appears only while the owner is live on a platform.** Plan discussed: check on visit only (no background polling), two-minute server-side memory, visitor-side swap so full-page caches need no purging; Twitch free, YouTube 100 quota units per check, no API for Facebook/Instagram/TikTok. | Owner declined: too many moving parts (platform keys, per-platform behaviour). Decision: use the existing weekly visibility windows (blocks and Sections, site time) to show the show's links on schedule. Revisit only if asked. |

## 17. Open questions and verification list

**For the owner (D1-D7 are decided; these remain):**

1. ~~Which WordPress environment will we install into first?~~ **Resolved 2026-11-05: no Docker on the Mac, so a local MAMP PRO site at http://crazylinks.test:8888 (WordPress 7.1.4, PHP 8.5.3 web / 8.4.17 CLI, MySQL 8.0). Details in `docs/DEV-ENVIRONMENT.md`.** **First real site: AsylumLife.com (decided 2026-11-05).** The owner reports an Anthropic connector is already configured there (Claude has access to the site), so AI should work without new setup; verify WP/PHP versions, theme/builder, and the installed provider plugin on first access. Install method (SSH, SFTP or admin zip upload) still to be agreed; recommend a staging copy first if the host provides one. **Public probe 2026-11-05:** WordPress 7.1.4, Divi (REST `divi/v1`), WP Super Cache (full-page cache: scheduled blocks and view counts need the no-cache/boundary handling), URL Shortify (check its URL prefix against our base `linktree`), Members (role editor will list our capabilities), Ninja Forms, Seriously Simple Podcasting, Site Kit, WP Mail SMTP (double opt-in mail will go through it), Metricool; `wp-abilities/v1` present; no MCP namespace visible. PHP version and the AI provider plugin can only be confirmed from inside the site.
2. ~~Should the brand be "CrazyLinks"?~~ **Confirmed: CrazyLinks (2026-11-05).** Footer credit: **built** as `settings.footer_credit`, off by default, filterable text and URL, `rel="nofollow"` (2026-11-05).
3. ~~Anthropic key for AI development?~~ **Owner wants AI to work the same way they already use it with WordPress.** Action: when we get access to the test site, inspect the existing setup (Settings → Connectors provider and key, any MCP adapter or AI plugin already installed) and mirror it rather than introducing a new configuration. **Finding 2026-11-05:** a fresh WordPress 7.1.4 has the AI Client but no provider; what feels "built in" is the Connectors screen's one-click install of the official *AI Provider for Anthropic* plugin plus the owner's API key. For the dev site, the owner installs that provider from Settings → Connectors and enters their key there; the plugin never stores or sees the key. **Owner decision 2026-11-05:** their day-to-day way is Claude Desktop over an MCP connection, so that is the path to mirror (§10.7); the key path works (first live edit done on 0.1.6) and remains the fallback.
4. Which creators or businesses are the first real users? Their needs decide which vertical blocks (music pre-save, menu, listings, giving) come first.
5. Is Linktree import a must-have for launch, given the ToS uncertainty?
6. Agency / Pro: which of the §3 v3.0 candidates matter most to you, and is the buyer an agency, a freelancer, or a brand team?

**VERIFY before the affected code ships:**

| Item | Why it matters | How |
|---|---|---|
| Linktree current USD prices, free plan, Pro fee, custom-domain policy | Marketing claims must be accurate | Open linktr.ee/s/pricing in a real browser (Chrome tool) |
| Instagram and TikTok official bio-link rules | Onboarding copy and QR/UTM guidance | Open help.instagram.com and TikTok help in a browser |
| ~~WordPress 7.0 AI Client: JSON-schema output, streaming, how Connectors stores keys; 7.1 changes~~ **Done 2026-11-05 on 7.1.4:** JSON-schema output yes (`as_json_response`), streaming no, keys in `wp_ai_client_provider_credentials`, providers are separate plugins | Decides whether `AnthropicDriver` is needed in v2.0 | Verified by reading `wp-includes/ai-client` and running `wp eval` on the dev site |
| ~~Abilities API exact function signatures~~ **Done 2026-11-05** (see §10.5). Still open: MCP Adapter 0.7.1 manifest and how it decides which abilities to expose | §10.5 registration code | First task of §10.7: install `mcp-adapter` on the dev site, read its source, and confirm which authentication Claude Desktop custom connectors accept |
| Linktree public-page import feasibility and ToS | §14 | Inspect a public page; read Linktree ToS |
| MaxMind GeoLite2 licence for bundled or downloaded DB | Country analytics | Read licence; default to header-based country if unclear |
| In-app browser escape schemes current behaviour (iOS 26 / Android 16) | §6.4 experimental flag | Device testing |

---

## Appendix A. Pain point → spec mapping

| Research pain (rank) | Spec response |
|---|---|
| 1 Billing surprises, price rises | No subscription; no trials; Pro (if any) is a one-time or clearly priced annual add-on sold outside wp-admin |
| 2 Basics behind paywall | §3 v1.0 list is free; §9 no upsell nags |
| 3 Unresponsive support | Public GitHub issues, wordpress.org forum SLA target 2 business days, in-plugin diagnostics export |
| 4 Payout delays | Never hold money: WooCommerce/Stripe pay the owner directly |
| 5 Sales fees | 0% by design |
| 6 Shutdowns and lock-in | Own domain, JSON export, GPL licence |
| 7 Opaque bans | No central authority can ban a page |
| 8 Rewrite 404s | §8 scoped rewrites, collision checks, health check |
| 9 Analytics workarounds | §7.3 first-party events, GTM/GA4 IDs built in, CSV |
| 10 Shared-domain flagging | Own domain, plain links, at most one redirect, no cloaking |
| 11 Front-end bloat | §6.1 ≤ 8 KB JS, no React on the front end |
| 12 Misleading domain ownership | The domain is already the owner's |

## Appendix B. Linktree Pro/Premium parity checklist

Thumbnails ✓ · Featured Layouts (16:9 cards), unlimited ✓ · Image gallery, unlimited ✓ · Profile image styles Hero/Banner/Shape ✓, Cutout v2 · Instagram layouts ✗ (via feed plugins later) · Enhance (AI restyle) ✓ v2 · Spotlight/animated links ✓ · Scheduling ✓ · Redirect ✓ · Profile lock (password) ✓ v1.1 · QR customization ✓ · Custom backgrounds, video, fonts ✓ · Hide branding ✓ · YouTube/Instagram/RSS embeds ✓ (oEmbed) · Code lock (age gate) ✓ v1.1 · Media kit ✗ (out of scope) · Polls ✗ (v1.1 via forms embed) · Phone capture ✓ (contact + capture block) · Subscriber CSV ✓ · Mailchimp/Klaviyo/Kit/beehiiv/Flodesk/Sheets ✓/✓/✓/✓/later/later · Meta Pixel, GA4, UTM ✓ · Custom meta ✓ · Sell digital products ✓ (Woo) · Courses ✗ (integrate) · Lifetime insights + CSV ✓ · Personalized links ✗ (v2+) · AI tools ✓ (Phase 2, free) · Admins/team ✓ (WP roles) · MCP agent editing ✓ (Phase 2, free) · Instagram AutoDM ✗ (out of scope).
