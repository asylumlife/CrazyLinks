# Linktree (linktr.ee) feature, plan, pricing and business model inventory (as of 2026-11-05)

Method note: ~17 tool calls. Linktree's own pricing page does not show USD in fetch (regional pricing), so prices come from third-party blogs. Linktree help-center pages are primary for feature gating.

## What is the feature matrix by tier, and current prices?

### Takeaway
Four tiers: Free, Starter, Pro, Premium. Third-party sources disagree on exact prices; the most recent claim (Nov 3-4, 2026) is a late-Oct 2026 increase to Starter $9 / Pro $17. Older consensus (Nov 2025 to Sep 2026): Starter $8, Pro $15, Premium $35 monthly. Linktree's help-center "paid features" article is the best primary source for gating.

### Cited Findings
- Pricing page itself lists tiers but shows local-currency prices, no USD figures; annual is discounted — [Linktree pricing](https://linktr.ee/s/pricing)
- Prices (checked 2026-09-27; page updated 2026-11-01): Free $0; Starter $8/mo ($6/mo annual); Pro $15/mo ($12 annual); Premium $35/mo ($30 annual). Competitor-published (SocialsLink) — [SocialsLink](https://socialslink.com/blog/linktree-pricing-2026)
- Newer claim (observed via search summary 2026-11-04): Starter $9 mo / $7 annual; Pro $17 mo / $14 annual; Premium $35 mo / $30 annual; Starter and Pro monthly raised in late Oct 2026 from $8/$15 — [search aggregate incl. u2l.ai](https://u2l.ai/blog/linktree-pricing) (UNVERIFIED against Linktree directly; single-source)
- Pro rose from ~$9 to $15 in Nov 2025 (older prices: Starter ~$5, Pro $9-10, Premium $24 — pre-Nov 2025, outdated) — [BrandID via search](https://brandid.app/reviews/linktree/)
- Annual discount ~20%; no prorated refunds on annual — [Unilink/u2l](https://u2l.ai/blog/linktree-pricing)
- Seller fee on digital products/courses: 12% Free, 9% Starter and Pro, 0% Premium (most sources); plus Stripe processing and $0.25 payout fee on Free per one source. Conflicting: a newer source says Pro and Premium both 0% Linktree fee and Free/Starter only free products — [SocialsLink](https://socialslink.com/blog/linktree-pricing-2026); contradicted by [Creator Hero/search summary](https://www.creator-hero.com/blog/linktree-review-and-pricing)
- ALL paid tiers (Starter+): no ads, verified badge application, thumbnails, prioritize link (Spotlight/animation), schedule links, Redirect (send all visitors to one link), Profile Lock (private page), video and AI profile images, books affiliate commission, QR scan counts, per-link Insights custom range up to 365 days — [Linktree help: paid features](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- Pro and Premium (not Starter): custom background image/video, more themes (lightning bolt), fonts and font color, profile image styles (Hero, Banner, Cutout, Shape), logo title, hide Linktree logo (footer and QR), QR customization (colors, logo), YouTube playback and latest-upload sync, Instagram layouts (Carousel, Profile, Single), RSS feed previews, Mobile App links (app-store routing by device), Music Pre-save (multiple services), Code Lock, Media Kit (reorder, PDF), extra form options (polls, surveys, contact), phone number collection, subscriber CSV export, audience integrations (Mailchimp, Klaviyo, Kit, beehiiv, Flodesk, Google Sheets), Meta Pixel, Google Analytics, UTM parameters, custom meta title/description, Instagram auto-reply options (all posts / next post / Smart match), sell digital products, paid courses, custom terms — [Linktree help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- Premium only: Lifetime Insights with CSV download, Personalized links (different versions per visitor), AI tools, Admins (add others to manage) — [Linktree help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- Tier limits: Insights history Starter 90d / Pro 365d / Premium lifetime; Featured Layout links unlimited paid (Free 1); Image Gallery Starter 3, Pro/Premium unlimited; FAQ questions Starter 5, Pro/Premium more; IG auto-reply DMs/month Free 1,000, Starter 1,500, Pro 2,500, Premium unlimited — [Linktree help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree); Free 1,000 from [search summary of linktr.ee/features/instagram-auto-reply](https://linktr.ee/features/instagram-auto-reply)
- Social Planner (from Plann) / social scheduling listed as Premium by one source — [SocialsLink](https://socialslink.com/blog/linktree-pricing-2026); selling digital products "only in certain countries" — Linktree help above

### Inferences
- Linktree gates nearly all customization depth (fonts, backgrounds, logo removal) and all tracking/integration at Pro; Premium is differentiated by lifetime data, AI, admins/team, personalization, scheduler, 0% fees. A WordPress plugin gets all of these free structurally (self-hosted), so edge is custom domains, no fees, no branding.
- Some sources disagree on whether Pro/Free tiers lose branding (e.g., SocialsLink says Starter still shows branding; Linktree help says hiding the logo is Pro+). Trust Linktree help.

### Gaps
- Exact current USD prices from Linktree itself; whether Oct 2026 increase is real.
- Team seat counts for Premium Admins not found.

## What link apps / content block types are supported?

### Takeaway
Linktree supports classic links plus many embeds and "link apps". No single official list was retrievable; assembled from help-center, scraper docs and reviews.

### Cited Findings
- Help center "Links and content" has 61 articles; includes Featured Layouts (large visual preview) — [Linktree help](https://linktr.ee/help/en/)
- Free accounts get unlimited links, social icons, videos, music, embeds, forms and supported Link Apps — [search summary of Linktree sources](https://linktr.ee/features/instagram-auto-reply)
- Types referenced in help: YouTube (play, latest-upload sync), Instagram feed (Carousel/Profile/Single), RSS, Mobile App links, Music Pre-save, Code Lock, Media Kit, Image Gallery, FAQ, forms (contact, poll, survey), email/phone capture, Redirect, Profile Lock, thumbnails, Spotlight/animation — [Linktree help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- Block types in data model (scraper docs): PAYMENT_REQUEST, DONATION_LINK / TIP_JAR, SHOPIFY embedded store, GUMROAD product embed — [Apify scraper](https://apify.com/vsekar91/linktree-products-scraper)
- Integrations named on help home: Shopify, Spotify, Mailchimp, Calendly — [Linktree help](https://linktr.ee/help/en/)
- 2026 additions: Sticker Bio Boards (June 19, 2026; stickers free, 3 new header layouts Pro) — [Linke.ro](https://linke.ro/blog/linktree-2026-playbook-decoded) (competitor blog)

### Inferences
- A minimal competitive block set: link, header, text, video embed, music, social feed, form, email/phone capture, gallery, FAQ, media kit, tip/payment, product, countdown/lock, app-store router.

### Gaps
- Complete official Link Apps catalog (maps, GIF, PDF, calendar apps) could not be verified.

## What does analytics report, and which pixels are supported?

### Takeaway
Views and clicks, per-link, with country/city/device/referrer in CSV; Meta Pixel (+CAPI), GA4, UTM on Pro+. TikTok pixel unverified officially. No native conversion tracking.

### Cited Findings
- Insights help: views and clicks; per-link performance; CSV export of activity, cities, countries, devices, referrers — [Linktree Insights help](https://linktr.ee/help/collections/7058283-understanding-your-analytics)
- Meta Pixel on Pro/Premium; Pixel ID in Settings > Analytics; Conversions API via access token; pageView and linkClick events for audiences — [Linktree Meta Pixel help](https://linktr.ee/help/en/articles/5434194-facebook-pixel-integration)
- GA4 via Measurement ID, UTM on Pro+, UTM campaign auto-set from link title — [Linktree UTM help](https://linktr.ee/help/articles/5434179-how-to-add-utm-parameters)
- TikTok Pixel claimed by third party only — [u2l.ai](https://u2l.ai/blog/linktree-pricing)
- Free: 28 days analytics + lifetime totals (one source) — [search aggregate](https://u2l.ai/blog/linktree-pricing); another says lifetime totals only, no per-link — same
- Conversion tracking not native — [Linke.ro guide](https://linke.ro/blog/link-in-bio-tracking-pixels-guide)
- QR scan counts on paid plans; QR download PNG/SVG, color and logo customization (Pro+) — [Linktree help](https://linktr.ee/help/en/)

### Gaps
- Exact Free-tier analytics window conflicts between sources; Linktree's per-link range article not fetched.

## What monetization features exist?

### Takeaway
Earn suite expanded Apr 2025: digital products, courses (Kajabi-powered), Bookings, Linktree Shops affiliate (US), sponsored links (US, max 3 live), tip jar, rewards bonuses; Payment Lock (2022) older.

### Cited Findings
- Products page: own digital downloads, Bookings, courses, bundles plus affiliate products; courses powered by Kajabi — [search of Linktree help](https://linktr.ee/help/en/articles/ca043516-manage-products)
- Shops affiliate is US-only; commission appears in Earnings, not orders — [Linktree Shops](https://linktr.ee/features/shops)
- Sponsored links: US Linkers only, up to 3 live — [Linktree help](https://linktr.ee/help/articles/11006101-how-to-earn-with-sponsored-links-on-your-linktree)
- Earnings: Your Products vs Commissions (affiliate, sponsored, rewards program bonuses); payout setup required — [Linktree help](https://help.linktr.ee/en/articles/11195009-how-to-track-your-earnings-and-set-up-a-payout-account)
- Payment Lock beta Nov 2022: $1-$150, PayPal/Square, one-time, classic links only — [TechCrunch](https://techcrunch.com/2022/11/16/linktree-intros-payment-lock-paywalls-for-individual-pieces-of-music-playlists-videos-newsletters-meeting-slots-and-more/) (2022; current status unverified, Code Lock is Pro today)
- Late April 2025 monetization launch (digital products, affiliate shops, courses, sponsored links) — [SmartCompany](https://www.smartcompany.com.au/technology/linktree-new-ai-features-deeper-canva-integration/)
- Spring storefront / Bonfire integration: only a 2022 tutorial mention of Spring/Shopify; current status unverified — [Apify/tutorial summary](https://www.swiftprinting.com/swift-times/how-to-set-up-a-linktree)

### Gaps
- Rewards program details, Spring/Bonfire current status, tip jar help article not found.

## What AI features exist?

### Takeaway
AI profile restyle/layout suggestions with Canva integration, AI profile images, Insights Chat, Creator Index, AI caption generator, Premium "AI tools", ChatGPT app.

### Cited Findings
- AI analyzes/restyles profile; suggests layouts, wallpapers, colors; deeper Canva design editing; announced Sep 25-26, 2025 — [SmartCompany](https://www.smartcompany.com.au/technology/linktree-new-ai-features-deeper-canva-integration/)
- Creator Index quiz (archetype) and standalone AI Insights Chat — [Influencer Marketing Hub](https://influencermarketinghub.com/linktree-ai-powered-creator-index/)
- AI profile images (paid); Premium AI tools — [Linktree help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- ChatGPT/OpenAI integration, Generative AI TOS section, July 5, 2026 policy effective date, training on creator data — [Linke.ro](https://linke.ro/blog/linktree-2026-playbook-decoded) (competitor; verify at linktr.ee/s/terms)
- Instagram auto-reply keyword DM automation — [Linktree](https://linktr.ee/features/instagram-auto-reply)

## What limits do free users hit?

### Cited Findings
- Branding on page; locked linktr.ee/name; no custom domain on any plan (help center), though NiftySite/BrandID claim otherwise — [Linkie summary](https://linkie.bio/blog/linktree-custom-domain)
- Limited themes/fonts/backgrounds, 1 Featured Layout, 12% seller fee, no pixels/UTM/integrations/phone capture, short analytics — [help](https://linktr.ee/help/articles/5434140-an-overview-of-paid-features-available-on-linktree), [SocialsLink](https://socialslink.com/blog/linktree-pricing-2026)
- Reviewers cite billing/support/account-access complaints (Trustpilot/Reddit/G2) — [BrandID via search](https://brandid.app/reviews/linktree/) (Trustpilot rating not found)

## Notable launches, acquisitions, strategy shifts

### Cited Findings
- Acquisitions: Bento (June 2023; shut down Feb 13, 2026, redirects to Linktree) — [Own.page](https://own.page/blog/bento-alternatives); Koji (announced Dec 14, 2023; shutdown Jan 31, 2024) — [AlternativeTo](https://alternativeto.net/news/2024/1/linktree-acquires-competitor-koji-which-will-shut-down-its-platform-on-january-31); Plann (social scheduler); Fingertip (shuts end of April 2026; fifth acquisition) — [SmartCompany](https://www.smartcompany.com.au/startupsmart/linktree-acquires-leibovich-brothers-backed-fingertip-to-shut-it-down/)
- 27% ANZ staff cut with US shift (announced with Bento) — search summary
- 70M+ users in 2026 (vs 50M May 2024) — [Built In via search](https://builtin.com/company/linktree/faq/stability-growth)
- Pricing: Nov 2025 increase; possible Oct 2026 increase (see above)
- Business model: freemium subscriptions + commerce seller fees + affiliate/sponsored commissions

### Gaps
- Team/Admin details, mobile app feature list, Marketplace, verification process, Rewards program terms, SEO controls beyond custom meta title/description (Pro+) not verified.
