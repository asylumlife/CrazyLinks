=== CrazyLinks ===
Contributors: asylumlife
Tags: link in bio, linktree, links, bio link, landing page
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted link-in-bio page builder. Your link page, your domain, your data. No rent, no fees, no shutdowns.

== Description ==

CrazyLinks adds link-in-bio pages to your own WordPress site, the kind of page Linktree sells. It is free, runs on your server and keeps your data in your database. Pages live at `yoursite.com/l/your-page`, load fast in the Instagram and TikTok in-app browsers, and count views and clicks without cookies.

**Developer preview.** Pages are edited as JSON for now; the visual editor comes in a later release.

= Features =

* Link pages as their own post type, with revisions and a schema-checked JSON format.
* Links as a classic button, a button with a thumbnail on the left or right, or a large image card. Each link can have a subtitle, a badge, a NEW flag that expires on a date you choose, an attention animation and a pin.
* Page layouts: list, two-column grid, or a large first link.
* Blocks: profile header, links, social icons, heading, text, divider, image, gallery, latest posts, video, email capture form (sign-ups are not stored yet), contact card, countdown, map link, payment buttons, collapsible group, spacer, and custom HTML for administrators. WooCommerce products are planned.
* Social icons for 37 networks and contact types, drawn as inline SVG from the Simple Icons set. Other plugins can add icons with a filter.
* Payment buttons for Buy Me a Coffee, Ko-fi, PayPal.me, Venmo, Cash App, Stripe Payment Links, Patreon and GoFundMe. The button opens the service itself; no money passes through your site.
* 14 design presets (light, dark, gradient, bold, pastel, mono, neon, earthy, frosted glass, patterned). Every preset meets WCAG AA contrast, and some switch to a dark variant when the visitor's device prefers it. You can override any colour, font, button shape, radius or background (solid, gradient, image or pattern).
* Three ways to show a page: standalone with no theme CSS or JavaScript, inside your theme's header and footer, or anywhere with the "CrazyLinks Page" block or the `[crazylinks id="123"]` shortcode.
* A click redirect at `/l/your-page/go/{block}` for links, images and payment buttons, with optional UTM tags, bot filtering and rate limiting.
* Contact cards can offer a "Save contact" vCard download at `/l/your-page/vcard/`.
* Password-protected link pages show only WordPress's password form until the visitor enters the password.
* A REST API under `crazylinks/v1`.
* Front end with no remote scripts, styles or fonts. Styles and a small script are printed inline.

= Privacy =

Analytics are first-party and cookie-free. Each event stores a visitor hash made from the IP address, user agent and a salt that changes every day (UTC) and is then discarded; the raw IP is never written to the database. The daily salt is stored outside the autoloaded options and combined with your site's secret keys. Rate limits and duplicate detection use a separate short-lived keyed hash of the IP alone. Referrer host, device class, operating system and in-app browser name are stored. Raw events are kept for 90 days by default (configurable) and daily totals are kept until the plugin's data is deleted. Analytics can be turned off in CrazyLinks → Settings.

Honest limitation: someone who can read both your database and your site configuration on the same day could test candidate IP addresses against that day's hashes. CrazyLinks adds suggested text covering this, and everything else it stores, to Settings → Privacy → Policy Guide.

Embed previews (the oEmbed request described below) can be switched off in CrazyLinks → Settings.

Email sign-ups (the Email capture block) store the address, the consent text shown at the time, timestamps and the page and block it came from. No IP address or browser details are stored with a sign-up. Confirmation is double opt-in by default, every email carries an unsubscribe link, and sign-ups appear in the WordPress personal-data export and erasure tools. Unconfirmed and unsubscribed addresses are removed after 90 days by default.

= External services =

CrazyLinks makes no requests to external services on its own. It loads no remote scripts, styles or fonts. Sign-up confirmation emails are sent with your site's own mail function (`wp_mail`), so no third-party email service is contacted unless you have configured one for WordPress as a whole.

The only outbound request is optional and author-initiated: when someone saves a page that contains an **Embed** block or a **Video** block with a video URL, WordPress's built-in oEmbed client asks the provider of that URL for embed details (title, thumbnail, player HTML). Only these providers are allowed: YouTube, Vimeo, Spotify, SoundCloud, TikTok, X/Twitter and Instagram. What is sent is the URL the author entered. The response is cached on the page, so visitors never trigger the request. The provider's player loads in the visitor's browser only after they tap the embed. Provider terms and privacy policies:

* YouTube: https://www.youtube.com/t/terms, https://policies.google.com/privacy
* Vimeo: https://vimeo.com/terms, https://vimeo.com/privacy
* Spotify: https://www.spotify.com/legal/end-user-agreement/, https://www.spotify.com/legal/privacy-policy/
* SoundCloud: https://soundcloud.com/terms-of-use, https://soundcloud.com/pages/privacy
* TikTok: https://www.tiktok.com/legal/terms-of-service, https://www.tiktok.com/legal/privacy-policy
* X/Twitter: https://x.com/tos, https://x.com/privacy
* Instagram: https://help.instagram.com/581066165581870, https://privacycenter.instagram.com/policy

Gallery, latest posts, countdown, map and payment blocks make no requests. Map blocks are plain links to Google Maps or Apple Maps that open only when a visitor taps them.

Developers can turn this off with `add_filter( 'crazylinks_fetch_oembed', '__return_false' );`.

**AI providers (optional, off by default).** If the site owner turns on AI editing under CrazyLinks → Settings and ticks the consent box, CrazyLinks sends requests through the WordPress AI Client (WordPress 7.0+) to whichever AI provider the owner connected in Settings → Connectors, for example Anthropic (Claude), Google (Gemini) or OpenAI. A request is sent only when a signed-in user with the "Use CrazyLinks AI" capability presses "Propose changes" or asks for suggestions in the page editor, or when an AI agent the owner connected through the MCP Adapter uses an AI ability the owner enabled. Each request contains what the user typed and the link page being edited (profile, design and blocks, without custom HTML or custom CSS). "Build from this site" adds the site title and tagline, recent published post titles and links, menu links and the user's public profile links; "Ask about analytics" adds click totals per link for the last 30 days. Provider keys, email addresses, subscribers and visitor data are never sent. The provider's terms and privacy policy apply: Anthropic https://www.anthropic.com/legal/privacy, Google https://policies.google.com/privacy, OpenAI https://openai.com/policies/privacy-policy. CrazyLinks keeps a log of AI requests (who, when, what was asked, the proposed changes, model and token counts) for 90 days by default; the retention and the logging of request text can be changed in settings.

== Installation ==

1. Upload the `crazylinks` folder to `/wp-content/plugins/` and activate it.
2. Go to **CrazyLinks → Add Link Page**, edit the page JSON and publish.
3. Visit `yoursite.com/l/your-page/`.

WP-CLI: `wp crazylinks demo` creates a sample page; `wp crazylinks validate page.json` checks a document; `wp crazylinks rollup` runs the analytics rollup now.

== Frequently Asked Questions ==

= Is it really free? =

Yes. There is no paid tier, no account and no service to sign up for. The plugin runs entirely on your own site.

= How do I build a page? =

In this preview, go to CrazyLinks > Add Link Page and edit the page JSON. Run `wp crazylinks demo` to create a sample page that uses every block, then copy it. `wp crazylinks validate page.json` checks a document.

= Can I change the /l/ part of the address? =

Yes, in CrazyLinks > Settings. The rewrite rules are rebuilt automatically. Existing links using the old base stop working.

= Will it break other URLs on my site? =

No. CrazyLinks adds three rewrite rules under its base (the page, its click redirect and its vCard download). If no link page has the requested slug, WordPress resolves the URL normally, so a real page at `/l/something` keeps working. The settings screen warns if existing content uses the base slug.

= Does it slow down the rest of my site? =

No. Nothing is loaded on pages that don't show a link page.

= Does it work with my theme? =

The standalone mode ignores your theme completely. Theme mode prints the page inside your theme's header and footer, and the block and shortcode place it in any content.

= Does it handle payments? =

No. Payment blocks are links to the payment service. The service takes the payment and you deal with it directly.

= How quickly does the latest posts block update? =

Rendered pages are cached. Publishing or unpublishing a post clears the cache straight away, and a page with a latest posts block is rebuilt at least every 15 minutes in any case.

= Can I add my own social icons? =

Yes. Use the `crazylinks_icons` filter to register a slug, a title and the `d` attribute of a 24x24 path. See docs/hooks.md.

= Can visitors subscribe by email? =

Yes. Add an Email capture block. Sign-ups are stored on your site, confirmed by email (double opt-in, which you can switch off), and listed under CrazyLinks → Subscribers with CSV export. Hooks let a newsletter plugin take over storage.

= How does the AI editing work? =

It is off until you switch it on. Connect an AI provider in Settings → Connectors (WordPress 7.0 or newer), then enable AI under CrazyLinks → Settings. In the page editor's AI tab you describe a change in plain words; CrazyLinks shows a before/after preview and a list of the proposed changes, and nothing is saved until you press Apply. Every applied change is a normal revision you can undo. AI agents connected through the MCP Adapter plugin can use the same abilities if you enable them.

= Can I edit pages from Claude Desktop? =

Yes, and the site needs no AI provider key for it: the model runs on your own Claude account. Install and activate the MCP Adapter plugin (by WordPress.org), enable the CrazyLinks abilities you want under CrazyLinks → Settings → AI editing (List pages, Read a page, Editing guide, Preview changes, Save changes), create a dedicated WordPress user with an Application Password, and add the connection snippet shown in the "External agents (MCP)" box to Claude Desktop or Claude Code. Claude reads the editing guide, previews its changes and saves them through the same validation as the editor; each save is a revision marked "AI (external)". You can require that such changes wait for your confirmation in the page editor. Full steps: docs/mcp.md in the plugin folder.

= Does it add a "Made with CrazyLinks" line? =

Only if you switch it on. The footer credit is off by default; turning it on in the page's Settings tab adds one small line linking to the plugin's wordpress.org page with a nofollow link.

= Where do the icons come from? =

Simple Icons, released under CC0. See LICENSE-icons.txt. The logos remain trademarks of their owners.

== Screenshots ==

1. A demo page in the Midnight preset: profile header, a featured link card, thumbnail and icon links.
2. Countdown, map link, video facade and gallery blocks.
3. Latest posts, payment buttons, contact card and social icons.
4. The same page in the Brutalist, Pastel and Glass presets.
5. The page JSON in the editor screen.

== Changelog ==

= 0.2.0 =
* New: edit link pages from Claude Desktop and Claude Code through the MCP Adapter plugin. Three new abilities for assistants that write the changes themselves: get-editing-guide, preview-operations and apply-operations (expected_modified conflict check, idempotency keys, rate limits shared with AI proposals, "AI (external)" revisions, AI history rows with source mcp).
* New: when the MCP Adapter is active, CrazyLinks offers its own MCP server at /wp-json/crazylinks/mcp listing only the abilities you enabled (setting and `crazylinks_mcp_server` filter). Abilities also carry the MCP exposure flags, so the adapter's default server reaches exactly the enabled ones.
* New: with "require confirmation", changes from external agents wait in the page editor's AI tab with a preview, Apply and Discard, also on sites without an AI provider.
* New: "External agents (MCP)" box in Settings with the adapter status, endpoints and Claude Desktop / Claude Code snippets; `wp crazylinks mcp status` and `wp crazylinks mcp guide`.
* Change: saves from external agents are refused (409) while another user has the page open in the editor.

= 0.1.6 =
* Change: AI requests no longer ask the provider for schema-constrained output (Anthropic rejects the operations grammar as too large). The model is asked for a bare JSON object, which the server validates against the full schema with one retry. The `crazylinks_ai_structured_output` filter turns provider-side schemas back on.

= 0.1.5 =
* Fix: Anthropic's strict structured output rejects open objects, so block props now travel as a JSON string inside the response and are decoded before validation.

= 0.1.4 =
* Fix: Anthropic rejected the full operations schema as too large a grammar ("compiled grammar is too large"). The provider now receives a compact envelope (about 7 KB instead of 90 KB) and the full validation runs on the server as before.

= 0.1.3 =
* Fix: on a site where settings had never been saved, the first save silently dropped the AI section (WordPress sanitizes twice when it creates the option). The option is now created on activation and the sanitizer is safe to run twice.

= 0.1.2 =
* Fix: the "Turn on AI editing" box unticked itself on save when the separate consent box was left empty. There is now one opt-in box that carries the consent statement.

= 0.1.1 =
* Fix: publishing or saving a page that had no slug yet crashed the request (the slug sanitizer received the REST request object). Found on the first live install.

= 0.1.0 =
* Phase 1 developer preview.
* Security hardening after independent review.
* Analytics screen with daily chart, per-block table, breakdowns and CSV export; Views, Clicks and CTR columns on the pages list.
* Email sign-ups: double opt-in, unsubscribe links, Subscribers screen, CSV export, personal-data exporter and eraser, retention settings.
* Editor: alt-text and contrast warnings, post locking, drag between groups, focal-point picker, bento tile sizes, optional footer credit; social icon clicks are tracked.
* AI editing (WordPress 7.0+): propose and apply changes from plain-language instructions with before/after preview, title and bio suggestions, abilities for MCP agents, usage limits and an AI log.
