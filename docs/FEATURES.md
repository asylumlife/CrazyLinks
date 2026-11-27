# CrazyLinks feature tour

Everything CrazyLinks can do, with an example of each. The screenshots come from a demo page on a local test site, and the analytics numbers in them are made up. They were taken at version 0.3.5.

- [The page](#the-page)
- [Links](#links)
- [Blocks](#blocks)
- [Sections](#sections)
- [Schedules](#schedules)
- [Themes and design](#themes-and-design)
- [The editor](#the-editor)
- [Analytics](#analytics)
- [Email sign-ups](#email-sign-ups)
- [AI editing and Claude Desktop](#ai-editing-and-claude-desktop)
- [Page settings and site settings](#page-settings-and-site-settings)

## The page

A link page lives at `yoursite.com/l/your-page/` (the `/l/` part can be changed). It starts with a profile header: avatar, name, optional verified badge, location and a short bio.

<img src="screenshots/tour/profile.png" width="340" alt="Profile header with avatar, name, verified badge, location and bio">

A page can be shown three ways: on its own with none of your theme's CSS or JavaScript, inside your theme's header and footer, or placed in any post or page with the "CrazyLinks Page" block or the `[crazylinks id="123"]` shortcode. The standalone page loads no remote scripts, styles or fonts.

## Links

A link can be a plain button, a button with a thumbnail on the left or right, a button with an icon or emoji, or a large image card. Each link can also carry a subtitle, a badge, a NEW flag that expires on a date you choose, a pin that keeps it at the top, and an attention animation (pulse, shake, wobble or shimmer).

<img src="screenshots/tour/links.png" width="340" alt="Six links: a pinned link with a NEW flag, a large image card with a LIVE badge, a thumbnail link, an emoji link, an icon link and a plain link">

The whole page can lay links out as a list, a two-column grid, or with a large first link.

## Blocks

### Social icons

A row of icons for 37 networks and contact types. Each icon's clicks are counted separately (see [Analytics](#analytics)).

<img src="screenshots/tour/socials.png" width="340" alt="A row of social icons">

### Heading, text, divider and spacer

Headings have sizes, weights, an optional emoji, a sub-line and a divider line. Text blocks take a short paragraph with bold, italics and links. Dividers come as a line, dots or space, and a spacer adds a gap of a chosen height.

<img src="screenshots/tour/heading.png" width="340" alt="A large heading with an emoji and a sub-line">
<img src="screenshots/tour/text-divider.png" width="340" alt="A text block followed by a dotted divider">

### Image and gallery

A single image with an optional caption and link, or a gallery in columns where each picture can link somewhere.

<img src="screenshots/tour/image.png" width="340" alt="An image block with a caption">
<img src="screenshots/tour/gallery.png" width="340" alt="A three-column gallery">

### Video and embed

Paste a YouTube, Vimeo, Spotify, SoundCloud, TikTok, X or Instagram address. The page shows a preview picture, and the provider's player loads only after a visitor taps it.

<img src="screenshots/tour/video.png" width="340" alt="A video block showing a preview picture with a play button">

### Countdown

Counts down to a date and time, then shows your own text and an optional link. It comes in three sizes: large, medium and small.

<img src="screenshots/tour/countdown.png" width="340" alt="A large countdown labelled Doors open in">

A countdown can also repeat every week, for example for a Friday live stream. You pick the days and how long the "happening now" text stays up before it starts counting down to the next one. The first one below is a medium weekly countdown, the second a small one-off.

<img src="screenshots/tour/countdown-sizes.png" width="340" alt="A medium weekly countdown and a small one-off countdown">

### Latest posts

The newest posts from the same WordPress site, updated when you publish.

<img src="screenshots/tour/latest-posts.png" width="340" alt="Three latest posts with titles and dates">

### Product

Shows a product from WooCommerce on the same site or from a separate WooCommerce store. Pick one product, or show the newest few and let the block keep itself up to date.

<img src="screenshots/tour/product.png" width="340" alt="A single product card with picture, name, price and Buy button">
<img src="screenshots/tour/product-newest.png" width="340" alt="The three newest products, each with a Buy button">

### Payment buttons

Buttons for Buy Me a Coffee, Ko-fi, PayPal.me, Venmo, Cash App, Stripe Payment Links, Patreon and GoFundMe. The button opens the service itself; no money passes through your site.

<img src="screenshots/tour/payment.png" width="340" alt="Ko-fi and Buy Me a Coffee buttons">

### Email sign-up

A sign-up form with a consent checkbox. See [Email sign-ups](#email-sign-ups) for where the addresses go.

<img src="screenshots/tour/email-capture.png" width="340" alt="An email sign-up form with a consent checkbox">

### Contact card and map link

A contact card lists phone, email and address and can offer a "Save contact" download. A map link opens the address in Google Maps or Apple Maps.

<img src="screenshots/tour/contact.png" width="340" alt="A contact card with phone, email, address and a vCard download">
<img src="screenshots/tour/map.png" width="340" alt="A map link with Google Maps and Apple Maps buttons">

### Custom HTML

Administrators can add a block of their own HTML.

## Sections

A Section groups blocks inside a styled container with its own heading, description, background, padding and corner radius.

<img src="screenshots/tour/section.png" width="340" alt="A Section with a gradient background holding two links">

A Section can be collapsible, and can lay its links out in a two-column grid.

<img src="screenshots/tour/section-grid.png" width="340" alt="A collapsible Section with four links in a two-column grid">

## Schedules

Any block or Section can be shown only between two dates, or on chosen days and times every week in the site's timezone. The "On air now" Section above, for example, could appear only Monday to Thursday from 12:30 to 2 pm.

## Themes and design

There are 14 themes, and every one meets WCAG AA contrast. On top of a theme you can change any colour, the fonts, the button shape and fill, and the background (solid, gradient, image or pattern).

![The same page in all 14 themes](screenshots/tour/themes.png)

![The Design tab of the editor](screenshots/tour/editor-design.png)

## The editor

Pages are built in a visual editor with a live phone or desktop preview beside it. Blocks are dragged into order, and each row shows its click count, a pin and a show/hide switch. There is undo and redo, a revision history, and a setup wizard for new pages.

![The Links tab of the editor with the live preview](screenshots/tour/editor-links.png)

Selecting a block opens its settings. Here is the weekly countdown, with its size, repeat days and how long it stays live:

![The Countdown block's settings](screenshots/tour/editor-countdown.png)

A new page starts with a three-step wizard for the name, the theme and your profile links, which it recognises as you paste them:

![The last step of the setup wizard, with pasted profile links recognised](screenshots/editor-wizard.png)

## Analytics

Views and clicks are counted without cookies. The visitor hash uses a salt that changes every day, the raw IP address is never stored, and raw events are deleted after 90 days by default while daily totals are kept.

![Totals and the views and clicks chart](screenshots/tour/analytics-overview.png)

Clicks are listed per link. Social icons are broken down per platform, and a newest-products block per product.

![Clicks per link, with social icons per platform and products per product](screenshots/tour/analytics-links.png)

"What each source clicks" shows, for each place visitors came from, how many viewed, how many clicked, and what they clicked most. A source is named from your campaign tag (`utm_source`), the app the page was opened in, or the referring site.

![Views, clicks and most-clicked links per traffic source](screenshots/tour/analytics-sources.png)

Further breakdowns cover referrers, devices, operating systems and in-app browsers. Everything can be downloaded as CSV.

![Where visitors come from](screenshots/tour/analytics-visitors.png)

"Time of day" adds up views and clicks by the hour they happened, in the site's timezone, so you can see when your audience is around.

![Views and clicks per hour of the day](screenshots/tour/analytics-hours.png)

"Activity" lists visits in time order, newest first: when each one started, where the visitor came from, their device, and every page view and click with its time. A visit is one visitor on one day; nobody is identified, and no cookie is used.

![Visits in time order with what each visitor clicked](screenshots/tour/analytics-activity.png)

The totals, the per-link clicks and the per-source table are also available in the editor's Analytics tab while you work on a page.

![The Analytics tab of the editor](screenshots/tour/editor-analytics.png)

## Email sign-ups

Sign-ups are stored on your own site, confirmed by email (double opt-in, which can be switched off), and listed on a Subscribers screen with CSV export. Every email carries an unsubscribe link, and sign-ups are covered by WordPress's personal-data export and erasure tools.

![The Subscribers screen](screenshots/admin-subscribers.png)

A sign-up block can instead send its addresses to a Mailchimp audience, with tags per block. Other email services can be added through an adapter interface (see [`crazylinks/docs/hooks.md`](../crazylinks/docs/hooks.md)).

## AI editing and Claude Desktop

Both are optional and off by default.

**In the editor.** Describe a change in plain words. CrazyLinks shows a before/after preview and a list of the proposed changes, and saves nothing until you press Apply. It uses the AI provider you connect under Settings → Connectors.

![The AI tab of the editor](screenshots/editor-ai-tab.png)

**From Claude Desktop or Claude Code.** Through the MCP Adapter plugin, an assistant can list your pages, preview changes and save them through the same checks as the editor, using your own Claude account. Setup is in [`crazylinks/docs/mcp.md`](../crazylinks/docs/mcp.md).

## Page settings and site settings

Each page has its own address, search title and description, sharing image, a "hide from search engines" switch, UTM tags added to outbound links, and display options. Pages can also be password-protected.

![The Settings tab of the editor](screenshots/tour/editor-settings.png)

Site-wide settings cover the URL base, analytics and retention, the store address for product blocks, Mailchimp, and AI editing.

![The site settings screen](screenshots/admin-settings.png)

## For developers

- Hooks, REST routes and WP-CLI commands: [`crazylinks/docs/hooks.md`](../crazylinks/docs/hooks.md)
- `wp crazylinks demo` creates a sample page that uses most blocks.
- These screenshots are regenerated with `crazylinks/bin/tour-screenshots.mjs`; see [DEV-ENVIRONMENT.md](DEV-ENVIRONMENT.md#feature-tour-screenshots).
