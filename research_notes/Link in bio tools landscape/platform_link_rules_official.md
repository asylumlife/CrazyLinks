# Official platform bio-link rules and in-app browser behavior (observed 2026-11-05)

Method note: most social help centers (help.instagram.com, TikTok support, facebook.com/help) are JavaScript-rendered and returned only a page title to the fetch tool. Only YouTube Help, Bluesky's blog, web.dev, developer.android.com, developer.chrome.com and krausefx.com returned readable first-party text. Everything labeled "secondary" comes from search-result summaries of third-party pages. All observation dates are 2026-11-05.

## 1. Official bio link rules per platform

### Takeaway
Only YouTube (14) is confirmed from first-party text I could read. Instagram "5 links" and TikTok "1,000 followers or Registered/Verified Business Account" are widely reported but I could not read the official pages, so they stay unconfirmed. Pinterest "1" was not checked. Bluesky now has multi-link profile links with no published cap.

### Cited Findings
- YouTube (OFFICIAL, confirmed): "You can showcase up to 14 links on your channel Home tab." and "Your first link will be prominently displayed in the profile section near the subscribe button." Prior claim "YouTube 14" is confirmed. Android help variant reportedly says the first link is "above the subscribe button" (secondary summary). — [YouTube Help: Manage your channel's profile](https://support.google.com/youtube/answer/2657964?hl=en&co=GENIE.Platform%3DDesktop&hl=en)
- Bluesky (OFFICIAL, confirmed): announced "Profile Links" on 22 Oct 2026. Steps: "Open your Bluesky profile and select Edit Profile", find the Links section, add a URL, optional custom title, drag to reorder. The post states NO maximum number and lists no restrictions. Links to payment platforms such as Patreon and Ko-fi show as prominent green buttons. — [Bluesky: Introducing Profile Links](https://bsky.social/about/blog/10-22-2026-introducing-profile-links)
- Instagram "up to 5 links" (SECONDARY only): third-party guides state personal and business accounts can add up to five links via Edit profile > Links > Add external link, first link shown plus "and N others", feature rolled out around April 2023, no follower threshold. Not confirmed from help.instagram.com text. The relevant official page exists at [help.instagram.com/362497417173378](https://help.instagram.com/362497417173378) ("Add a website to your Instagram profile") and a reorder-links page at [help.instagram.com/1128232494579073](https://help.instagram.com/1128232494579073/), but both rendered empty to my fetcher. Secondary: [SocialBee](https://socialbee.com/blog/how-to-add-a-link-to-instagram-bio/), [Linktree](https://linktr.ee/blog/how-to-add-multiple-links-to-instagram-bio)
- TikTok (SECONDARY only): search summaries quote TikTok Support as allowing a website link "if you have 1000 followers or more, or a Registered Business Account", noting TikTok's Business Help Center now says "Verified Business Account"; third-party guides disagree on whether an unverified switch to Business unlocks the field. Official page redirects to [tiktok.com/support/faq_detail?id=7581821550328896012](https://www.tiktok.com/support/faq_detail?id=7581821550328896012) (title "Link a website or social media account") but content did not render. A search summary claimed an "22 October 2026 re-read" of the help center, which I cannot verify. Secondary: [Brillay](https://brillay.com/resources/tiktok-says-i-have-1000-followers-but-still-cant-add-a-link), [SocialRails](https://socialrails.com/blog/how-to-add-link-tiktok-bio-complete-guide)
- Instagram URL as plain text in bio is not clickable on TikTok (secondary, same TikTok guides).

### Inferences
- Treat "Instagram 5, YouTube 14" as safe. Treat "TikTok 1 link, 1,000 followers or verified business account" as probable but re-verify in-app. Design the link page assuming a single URL slot on TikTok and Pinterest, and multi-slot on Instagram, Threads, YouTube, Bluesky.

### Gaps
- Could not retrieve official text for: Instagram, Threads (prior claim 5 unverified), TikTok, X, Pinterest (prior claim 1 unverified), Facebook Pages, LinkedIn, Snapchat, Twitch. Search with domain filter returned no official pages for these; threads.net is blocked to the search tool.
- Link stickers (Stories) eligibility not verified from official text.

## 2. In-app browser behavior (Meta, TikTok), deep links, detection, "open in browser"

### Takeaway
Instagram and Facebook on iOS use their own WKWebView-style browser and, per Felix Krause (Aug 2022), inject Meta's pcm.js; Meta called it intentional. There is no official, documented way for a page to force an exit to Safari/Chrome; x-safari-https:// and intent:// are undocumented/inconsistent, so a visible manual "Open in browser" instruction must be the fallback.

### Cited Findings
- Krause, published 10 Aug 2022: Instagram iOS in-app browser loads `connect.facebook.net/en_US/pcm.js` into third-party pages, first checking for an element with ID `iab-pcm-sdk`, inserting a script before the first script; it adds a `selectionchange` listener sending selected text to a Meta message handler and queries iframes. He did not confirm what data is actually sent; he says it could expose taps, text selections, form input. A later update added a listener firing on every tap. — [krausefx.com](https://krausefx.com/blog/ios-privacy-instagram-and-facebook-can-track-anything-you-do-on-any-website-in-their-in-app-browser)
- Meta's statements (as relayed by Krause): pcm.js aggregates events (e.g. purchases) before use for targeted ads and measurement, helps honor ATT choices, is not the Meta Pixel; SFSafariViewController doesn't support this; text-selection code is old and unused; bug bounty ticket resolved as "intentional and not a privacy concern." Follow-up post a week later covered other apps including TikTok (I did not retrieve it). — same source
- inappbrowser.com: "Some iOS and Android apps make use of a custom in-app browser" which "causes potential security and privacy risks"; the detection tool works by overriding common JS functions; since iOS 14.3 Apple's isolated-world JS means a page cannot verify what code runs; it can't detect gesture recognition, screenshot detection or web request tracking. — [inappbrowser.com](https://inappbrowser.com)
- Later changes after 2022: I found no official Meta statement of a changed design. Secondary: Instagram and Threads have no setting to open links externally; Facebook has a "Links open externally" toggle on Android, not on iPhone; per-link "Open in browser"/"external browser" is via the three-dot menu. — [Linkly](https://linklyhq.com/blog/open-instragram-link-in-browser), [u2l.ai](https://u2l.ai/blog/how-to-disable-in-app-browser)
- Android official: WebViews "don't share state with the browser"; Custom Tabs use the user's preferred browser and share its sessions, saved passwords and payment methods; Google recommends Custom Tabs for external domains and WebView when hosting own content or injecting JS. — [Chrome for Developers: Custom Tabs](https://developer.chrome.com/docs/android/custom-tabs)
- Android App Links: verified via assetlinks.json; "When the user clicks a matching link, the system routes them to your app directly"; Android 15 adds Dynamic App Links with exclusions; Google notes a Sept 2026 Play services fix for dynamic rules. This describes system routing, and says nothing about third-party WebViews. — [developer.android.com App Links](https://developer.android.com/training/app-links/about)
- iOS Universal Links: Apple page itself did not render; the fetch tool's summary came from general knowledge (UNVERIFIED): links tapped inside a host app's WKWebView don't open the app automatically; the host app must route them (e.g. `UIApplication.open` with `.universalLinksOnly`). — [Apple docs](https://developer.apple.com/documentation/xcode/supporting-universal-links-in-your-app)
- UA markers (SECONDARY, not guaranteed by the apps): Instagram appends "Instagram <ver>" (often `iabmv`); Meta: FBAN, FBAV, FB_IAB, FBIOS, FB4A; check Instagram/Threads before generic FB tokens; TikTok: `BytedanceWebview`, `musical_ly_<ver>`, `trill_<ver>`; Android generic `wv`; iPhone UA missing `Safari/`. One source says some Facebook builds on iOS 26 omit FBAN/FBAV. — [gotoapp.store](https://gotoapp.store/blog/detect-in-app-browser), [useragent.in](https://useragent.in/in-app-browser-user-agents), [corbado](https://www.corbado.com/blog/passkeys-in-app-browsers)
- Escape schemes (SECONDARY, undocumented): `x-safari-https://` was macOS-only and reportedly works on iOS (reports of iOS 17+; mixed results on iOS 16; Instagram may show a prompt); `instagram://extbrowser` reported as Instagram-specific; Android `intent://host/path#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=...;end`, reliability on Instagram reported mixed. Apple guidance cited by secondary source: rely only on documented schemes. — [plugwith.me](https://plugwith.me/blog/what-escapes-instagram-in-app-browser-in-2026/), [appy.to](https://appy.to/blog/in-app-browser-deep-links), [Apple dev forum](https://developer.apple.com/forums/thread/757541)
- Secondary: Google sign-in returns 403 `disallowed_useragent` in embedded webviews (so avoid OAuth on the link page); App Store links often fail to launch from these webviews. — [truelink-group](https://truelink-group.com/en/blog/why-google-login-fails-in-line-facebook-in-app-browsers-2026/), [appy.to](https://appy.to/blog/app-store-links-not-opening-in-instagram)

### Inferences
- Design as if the page runs inside a WKWebView/Android WebView: no reliance on Universal Links/App Links auto-launching; use plain https links, with `intent://`/`x-safari-https://` as best-effort enhancements behind UA detection, always paired with visible "Open in browser" instructions.
- Because UA tokens are undocumented, detect conservatively and degrade gracefully.

### Gaps
- Could not read Facebook's help page on changing the link-opening browser, Krause's TikTok follow-up, or any Meta/TikTok developer doc on in-app browser JS. No official TikTok statement about its webview injection found.
- No verification of present-day (late 2026) Instagram injection behavior.

## 3. Official statements on throttling, warnings, or blocking of shorteners / link-in-bio domains

### Takeaway
I found no official Meta, TikTok or Instagram statement naming link shorteners or link-in-bio domains as throttled or blocked. All claims found are third-party.

### Cited Findings
- UNOFFICIAL: Short.io claims Facebook blocks generic shortener domains (bit.ly, ow.ly) with the message "Your content couldn't be shared because this link goes against our Community Standards"; vendor has a commercial interest. — [Short.io](https://blog.short.io/links-on-facebook/)
- UNOFFICIAL: sources say Meta's Spam policy covers cloaking, misleading links, deceptive redirects and landing pages, and that Meta doesn't publish how links are scored; redirect chains raise risk. — [AwareXone](https://www.awarexone.com/research/instagram-review-flagged-links-warning), [AtomicURL](https://atomicurl.com/blog/short-links-blocked-on-facebook)

### Inferences
- Use a custom first-party domain, a single redirect at most, and no cloaking.

### Gaps
- Meta Transparency Center Spam/Community Standards pages, TikTok community guidelines were not retrieved.

## 4. Web performance expectations and Google lightweight-page guidance

### Takeaway
Pass Core Web Vitals at the 75th percentile: LCP 2.5 s, INP 200 ms, CLS 0.1. For a landing page the biggest lever is fast TTFB and an early-discoverable LCP resource.

### Cited Findings
- LCP "within 2.5 seconds", INP "200 milliseconds or less", CLS "0.1 or less", measured at the 75th percentile segmented by mobile and desktop. — [web.dev Web Vitals](https://web.dev/articles/vitals)
- Optimize LCP guidance: target 2.5 s at 75th percentile; suggested subpart budget about 40% TTFB, under 10% load delay, about 40% load duration, under 10% render delay; make LCP resource discoverable in initial HTML, `fetchpriority="high"` on the LCP image, never `loading="lazy"` on it; shrink render-blocking CSS; use modern image formats. — [web.dev Optimize LCP](https://web.dev/articles/optimize-lcp) (via search summary, page not fetched directly)
- Set explicit width/height to prevent CLS; avoid overusing fetchpriority. — [Chrome Modern Web Guidance](https://developer.chrome.com/docs/modern-web-guidance/guides/performance/performance) (search summary)

### Inferences
- A static/SSR page with inlined critical CSS, minimal JS and one prioritized image is the right model. Webviews lack browser prefetch/pre-warming that Custom Tabs provide (per Custom Tabs doc), so first-load cost matters more.

### Gaps
- No official "typical mobile network conditions" figure retrieved (Lighthouse's mobile throttling profile not fetched).

## 5. Analytics inside in-app browsers

### Takeaway
Expect cold, sandboxed sessions: new `_ga` ID per visit, missing/stripped referrer, possibly unavailable localStorage. Use UTMs and server-side click logging; official docs on this specific behavior were not found.

### Cited Findings
- Android official: WebView doesn't share state with the browser. — [Chrome Custom Tabs](https://developer.chrome.com/docs/android/custom-tabs)
- SECONDARY: in-app browsers start with no cookies, strip the referrer, may block localStorage, so GA4 shows Direct traffic and inflated new users; UTM parameters fix attribution; GA uses first-party JS cookies; Safari ITP caps JS cookies at 7 days. — [Rule Analytics](https://www.ruleranalytics.com/blog/social-media/track-instagram-traffic/), [JulianS4K PR #91](https://github.com/JulianS4K/EXP/pull/91)
- Meta developers: no official page on Pixel behavior in Instagram/Facebook IAB found. The only webview doc is a 2018 hybrid-web-app post (fbq calls forwarded to app events via Meta SDK for apps you build). Meta Pixel Helper is now "Meta Ads Data Advisor", a desktop Chrome/Edge extension, so it can't inspect mobile IABs. — [Meta hybrid web apps](https://developers.facebook.com/ads/blog/post/v2/2018/07/31/hybrid-web-apps/), [Meta Ads Data Advisor](https://developers.facebook.com/docs/meta-pixel/support/pixel-helper/)

### Inferences
- Server-side redirect/click logging (first-party endpoint, with UTM and platform detection from UA) is the robust measure; client tags are best-effort. Wrap storage in try/catch.

### Gaps
- No official GA4 documentation on webview behavior; no verification that GA4/Meta Pixel load reliably in Instagram or TikTok webviews.

## 6. QR codes, NFC, vCard for offline-to-online

### Takeaway
Web NFC is Android Chrome only, so NFC tags should simply hold a plain https URL. I found no official source on QR codes or vCard in this pass.

### Cited Findings
- Web NFC: read/write NDEF tags, ~5-10 cm range, requires user gesture and permission, Chrome for Android only (stable since Chrome 89). — [Chrome for Developers: Web NFC](https://developer.chrome.com/docs/capabilities/nfc)

### Inferences
- Pre-generate QR as inline SVG; serve vCard as a static `text/vcard` file download from a plain link. Note that sandboxed webviews may restrict downloads (secondary source above says in-app browsers restrict downloads), so offer an "Open in browser" path for the vCard. These are engineering inferences, not sourced.

### Gaps
- No official source retrieved for QR code guidance, vCard (RFC 6350), iOS tag reading, or contact-card behavior in webviews.
