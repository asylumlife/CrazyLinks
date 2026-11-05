# AI features in link-in-bio/site builders (Part A) and AI integration in WordPress plugins (Part B), as of 2026-11-05

Method note: about 22 web calls. Many Part A facts come from third-party blogs (several are competitor/affiliate sites) and are flagged. Part B core facts mostly come from make.wordpress.org / developer.wordpress.org (via search summaries and fetched pages). Rows marked UNVERIFIED were not confirmed.

## Q1 (Part A). What AI features have link-in-bio and site-builder products shipped?

### Takeaway
Linktree has moved toward AI-agent control (MCP connector, Premium) plus AI analytics chat. Beacons is the strongest "build my page from my socials" AI product. Wix and Squarespace sell full AI site generators (Harmony, Blueprint AI) with credit metering. Stan Store and Carrd show little or no real AI in the sources found.

### Cited Findings
- Linktree lets users connect Claude or ChatGPT via MCP (server URL https://mcp.linktr.ee/mcp, added through Settings > Connectors). In plain language the agent can add/edit/reorder/delete links, update bio and appearance, read Insights, manage social links, and update multiple Linktrees per session. Labeled "Available on Premium plans". It acts with the user's own permissions, cannot access payment or visitor data, Linktree says it does not train on this data, and AI-made changes are logged separately from Admin changes. — [Linktree help](https://linktr.ee/help/articles/15551042-manage-linktree-ai-tools)
- Linktree other AI: AI Insights Chat (conversational analytics; one third party says launched April 2026), Creator Index quiz with AI recommendations, AI content and caption generator, AI-created/restyled profile video images on paid plans. Sources disagree on tier (Pro vs Premium) and Pro price ($15 vs $17/mo). — [linke.ro](https://linke.ro/blog/linktree-2026-playbook-decoded), [Influencer Marketing Hub](https://influencermarketinghub.com/linktree-ai-powered-creator-index/), [Linktree paid features](https://linktr.ee/help/en/articles/5434140-an-overview-of-paid-features-available-on-linktree)
- Linktree terms update dated 5 July 2026 added a generative-AI section: inputs/outputs including conversation history may be stored and shared with AI model providers (third-party report; verify against linktr.ee terms). — [linke.ro](https://linke.ro/blog/linktree-ai-training-policy-2026-explained)
- Beacons AI: setup wizard that pulls data from social handles and generates a mobile page with profile picture, background image and AI tagline; AI media kit with live follower counts; AI tools for captions, outreach, personalization; Instagram DM automation. — [autogpt.net summary via search](https://autogpt.net/ai-tool/beacons-ai/), [Taplink](https://taplink.at/en/blog/what-is-beacons-ai.html)
- Stan Store: sources show no real generative AI; its own comparison page says Beacons has the AI tools. Carrd: no AI features found in sources. — [Stan blog](https://stan.store/blog/stan-store-vs-beacons/), [Fueler roundup](https://fueler.io/blog/ai-link-in-bio-generator-tools-for-creators)
- Hopp by Wix: only AI feature found is "Create a block with AI" for custom Hopp Blocks (interactive widgets). Pricing is not public. — [Wix Support](https://support.wix.com/en/article/hopp-by-wix-getting-to-know-hopp-actions), [linke.ro review](https://linke.ro/blog/hopp-by-wix-review)
- Wix Harmony launched 21 Jan 2026: natural-language prompts plus drag-and-drop, driven by an agent called Aria. Wix also owns Base44 (bought for about $80M in 2025; reported ARR $150M by May 2026). — [PetaPixel](https://petapixel.com/2026/01/21/wix-harmony-lets-you-build-a-website-using-natural-language-prompts/), [nxcode comparison](https://www.nxcode.io/resources/news/ai-website-builder-comparison-2026)
- Squarespace: Blueprint AI (launched March 2025, expanded through March 2026) asks a few business questions and generates a multi-page site; part of "Design Intelligence" with AI copy, AI image editing (Image Studio), an on-page assistant, and a business assistant; can launch a site via ChatGPT. September 2026 update added Image Studio. — [Squarespace blog](https://www.squarespace.com/blog/blueprint-ai-website-builder), [WebsiteBuilderExpert](https://www.websitebuilderexpert.com/website-builders/how-use-squarespace-ai-tools/), [weblumbaai](https://weblumbaai.com/blog/squarespace-blueprint-ai-september-2026)
- Framer: AI credits included on every plan. Hostinger: Horizons (AI app/site builder) and a separate cheaper AI website builder. 10Web: AI front end on a WordPress backend. Durable: pure AI builder. — [Stork](https://www.stork.ai/blog/best-ai-website-builders-2026), [aggregated roundups](https://superframeworks.com/articles/best-ai-website-builders)
- Review sentiment found: Squarespace Blueprint praised for looks but export is content-only (3.7/5 review); Base44 users on Reddit say most credits go to fixing AI mistakes (secondhand). — [mehdiaoussiad](https://www.mehdiaoussiad.com/blog/squarespace-blueprint-ai-review), [preuve.ai](https://preuve.ai/blog/base44-review)

### Inferences
- The three UI patterns seen: (1) onboarding wizard that generates a whole page from a handle or questions (Beacons, Blueprint), (2) external-agent control via MCP (Linktree) rather than an in-product chat, (3) point tools (caption generator, AI block, insights chat). A WordPress plugin can offer the same by exposing abilities/MCP for external agents and a prompt box for in-admin use.
- Linktree's separate audit log for AI-made changes and per-user permission scoping are good patterns to copy.

### Gaps
- Squarespace "Bio Sites": no source found; could not verify it exists as a product.
- Chat sidebar vs prompt-box UI details per product, and user praise/complaints specific to Linktree or Beacons AI: only thin third-party material; not verified from primary sources.
- Hopp, Carrd, Stan Store changelogs not checked directly. Durable features not detailed.

## Q2 (Part A). How is AI priced or metered?

### Takeaway
Mixed: daily/monthly credits on lower tiers (Beacons, Framer, Hostinger, Squarespace, Wix Base44), AI as a higher-tier perk (Linktree MCP on Premium). Third-party price data conflicts; treat as indicative.

### Cited Findings
- Beacons (Nov 2026 reviews): Free $0, Creator Pro $10/mo, Store Pro $30/mo, Business Pro $90/mo; 9% seller fee on Free and Creator Pro. One source: AI credits 30/day Free, 300/day Creator, unlimited on top tier (single source, unconfirmed). Older sources list other tiers. — [brandID via search](https://brandid.app/blog/beacons-ai/), [elev8or](https://www.elev8or.io/blog/bio/beacons-ai), [niftysite](https://niftysite.co/resources/beacons-pricing)
- Linktree: MCP connector on Premium; tier for Insights Chat disputed. — [Linktree help](https://linktr.ee/help/articles/15551042-manage-linktree-ai-tools)
- Squarespace: as of Sept 2026 AI builder on every plan incl. 14-day trial, no free plan, Basic includes only 10 AI credits (single secondary source). — [weblumbaai](https://weblumbaai.com/blog/squarespace-blueprint-ai-september-2026)
- Base44 (Sept 2026 listing): free 25 message credits; Starter $16, Builder $40, Pro $80, Elite $160/mo annual; separate integration-credit meter. Wix light plan about $17/mo. — [nxcode](https://www.nxcode.io/resources/news/ai-website-builder-comparison-2026)
- Framer: free, Basic $10, Pro $30 per month with monthly AI credits (Basic 1,000). Hostinger Horizons from $6.99/mo (30 credits, 5 prompts/day trial). 10Web AI Starter $10, Premium $24, Ultimate $45 (sources conflict). Durable $12 to $22 (sources conflict). — [Stork](https://www.stork.ai/blog/best-ai-website-builders-2026), [roundups](https://superframeworks.com/articles/best-ai-website-builders)
- Elementor One: 25,000 credits/mo, €180/yr; text prompt 1 credit, image 33, container layout 40. Angie plugin free with daily-renewing credits, requires an Elementor account. — [Elementor blog](https://elementor.com/blog/ai-wordpress-plugin/), [wpwhichplugin](https://wpwhichplugin.com/elementor-angie-ai-review-what-it-can-and-cant-do-in-2026/)
- Jetpack AI Assistant: 20 free requests; paid price reports range $4.95 intro to $14.95/mo (conflicting). — [Elementor roundup](https://elementor.com/blog/wordpress-ai-plugin/), [themeisle](https://themeisle.com/blog/best-wordpress-ai-plugins/)

### Inferences
- A free WordPress.org plugin cannot lock features behind credits it controls in-plugin (see Q8), so BYO key via Connectors is the clean default.

### Gaps
- Official pricing pages not fetched for any Part A vendor; all prices are secondary and dated Sept-Nov 2026 at best.

## Q3 (Part B). Official WordPress AI initiatives: status and versions

### Takeaway
WordPress 7.0 "Armstrong" (released 20 May 2026) put the provider-agnostic AI Client and Connectors in core; the Abilities API PHP half shipped in 6.9, the JS client half in 7.0. The MCP Adapter is a pre-1.0 plugin (v0.7.1, 16 Oct 2026). The AI plugin (formerly AI Experiments) is optional.

### Cited Findings
- WP 7.0 released 20 May 2026 (slipped from 9 Apr target); includes AI Client, Abilities API, Connectors hub (admins authenticate providers; Anthropic, Google, OpenAI defaults), optional official AI plugin, MCP Adapter availability. Real-time collaboration was pulled. — [InfoQ](https://www.infoq.com/news/2026/07/wordpress-7-ai/), [DreamHost](https://www.dreamhost.com/blog/wordpress-7-0/)
- Conflict: InfoQ says minimum PHP rises to 7.4 (as fetched). Verify against core requirements page. — [InfoQ](https://www.infoq.com/news/2026/07/wordpress-7-ai/)
- AI Client dev note (Felix Arntz, 24 Mar 2026): entry point `wp_ai_client_prompt()` returns `WP_AI_Client_Prompt_Builder` (snake_case fluent methods: `using_temperature()`, `using_model_preference()`, `generate_text()`, `generate_image()`); failures return `WP_Error`; `is_supported_for_text_generation()` etc. check support without API calls; core bundles no providers; Connectors API handles key storage/validation and Settings > Connectors screen; `wp_ai_client_prevent_prompt` filter can block prompts (e.g. non-admin users). — [Make Core dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- JS API/REST (`wp-ai-client` package, endpoints /wp-ai/v1/generate and /is-supported) are not in core, admin-only via `prompt_ai` capability; dev note recommends building a dedicated REST endpoint per feature with granular permission checks. Package expected to be discontinued, moving to Gutenberg. No built-in throttle or cost controls (issue opened). — [Make Core dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/), [wpshout via search](https://wpshout.com/wordpress-ai-connectors-api/)
- Pre-7.0 package: `composer require wordpress/wp-ai-client`, `AI_Client::prompt()`; on 7.0+ replace with `wp_ai_client_prompt()` and avoid loading php-ai-client twice (class redeclaration). — [AI Client SDK intro, 21 Nov 2025](https://make.wordpress.org/ai/2025/11/21/introducing-the-wordpress-ai-client-sdk/), [UPGRADE.md](https://github.com/WordPress/wp-ai-client/blob/trunk/UPGRADE.md)
- php-ai-client releases: 1.3.1 bundled in core in March 2026; 1.4.0 (15 Jul 2026); 1.5.0 (16 Sep 2026); 1.5.1 (28 Oct 2026; tool parameter schema hook, stricter File construction). — [GitHub releases](https://github.com/WordPress/php-ai-client/releases)
- Abilities API: PHP registry in core since WP 6.9; JS split into `@wordpress/abilities` and `@wordpress/core-abilities` in 7.0; standalone WordPress/abilities-api repo archived. Some blogs wrongly attribute the API to 7.0. — [Make Core client-side post](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/), [nexterwp](https://nexterwp.com/blog/wordpress-abilities-api/)
- MCP Adapter: v0.7.0 first on wordpress.org directory (7 Oct 2026 post), v0.7.1 on 16 Oct 2026; requires WP 6.9+; ~60K installs reported; default server at /wp-json/mcp/mcp-adapter-default-server with tools `discover-abilities`, `get-ability-info`, `execute-ability`; abilities private unless `meta.public`/`meta.mcp.public` true; maps readonly/destructive/idempotent annotations to MCP hints; supports MCP revisions 2025-11-25 and 2026-07-28; bundling as a library is deprecated (install plugin). Automattic/wordpress-mcp archived. — [Make AI](https://make.wordpress.org/ai/2026/10/07/the-mcp-adapter-plugin-is-now-available-on-wordpress-org/), [wordpress.org](https://wordpress.org/plugins/mcp-adapter/), [Developer blog Feb 2026](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/), [InstaWP](https://instawp.com/wordpress-mcp-adapter-review/)
- AI Experiments plugin: 0.1.0 (27 Nov 2025, title generation), 0.2.0 (20 Jan 2026, excerpts, Abilities Explorer), 0.4.0/0.4.1 (5 Mar 2026: image generation, Review Notes). Roadmap: rename to "WordPress AI". Later versions (post-March) not found. — [Make AI 0.4.0](https://make.wordpress.org/ai/2026/03/06/whats-new-in-ai-experiments-0-4-0/), [SEJ](https://www.searchenginejournal.com/wordpress-releases-updated-ai-experiments-plugin/569139/)
- WP 7.1: RC1 5 Aug 2026, final due 19 Aug 2026 per one blog (secondary; not confirmed it shipped). — [nexterwp](https://nexterwp.com/blog/wordpress-7-ai/)

### Inferences
- Design target: require/detect WP 7.0+, call `wp_ai_client_prompt()` behind your own REST endpoint, fall back to "AI unavailable" UI if `is_supported_*` is false. Register page-edit operations as abilities (with `meta.public` false by default) so MCP agents can also edit link pages.
- Provider plugins (Anthropic/OpenAI/Google) are separate installs; the plugin should not assume one.

### Gaps
- Streaming is not documented in the 7.0 dev note or sources found; unverified whether php-ai-client exposes streaming. Plan for non-streaming REST plus polling, or test the lib.
- How Connectors stores keys (DB option vs env/constants) not found.
- Whether 7.1 shipped and what it changed for AI: not confirmed.
- Exact ability registration code (`wp_register_ability` args) not fetched this session.

## Q4 (Part B). How leading WordPress plugins integrate LLMs

### Takeaway
Two models: BYO key (AI Engine) and vendor-proxied credits (Elementor, Jetpack). Only AI Engine, Elementor and Jetpack were verified; others unchecked.

### Cited Findings
- AI Engine (Meow Apps), v3.9.0 on 2 Nov 2026: BYO keys, built-in connectors for OpenAI, Anthropic, Google, xAI, Mistral, Perplexity, OpenRouter, Replicate, Azure; custom OpenAI-compatible endpoint for Ollama/LM Studio/vLLM; Ollama add-on v1.0.7; MCP server with OAuth for Claude/ChatGPT; MCP approvals for deletions/changes or draft-only; edits Gutenberg blocks block-by-block; supports Abilities API tools; filters `mwai_openrouter_body`, `mwai_anthropic_body`; cached tokens priced at cached rate for usage tracking; fixed MCP returning API keys via option reads. Free tier covers chatbot, copilot, MCP; Pro (about $69) adds forms, embeddings etc. — [eesel review](https://www.eesel.ai/blog/meow-ai-engine-review), [wordpress.org](https://wordpress.org/plugins/ai-engine/), [Meow Apps](https://meowapps.com/ai-engine/)
- Elementor: Elementor AI is credit-metered via Elementor One account (vendor-proxied); Angie is a separate agentic plugin. — [Elementor](https://elementor.com/blog/ai-wordpress-plugin/)
- Jetpack AI Assistant: vendor-proxied, request quota (20 free). — [themeisle](https://themeisle.com/blog/best-wordpress-ai-plugins/)

### Inferences
- The AI Engine MCP-key-leak fix illustrates a real risk: any generic "read option" tool can expose stored secrets. Keep keys out of wp_options readable via abilities.

### Gaps
- Not researched or verified: Rank Math Content AI, Divi AI, Kadence AI, Bertha, GetGenie, Yoast AI, WPForms AI, Gravity Forms AI (providers, key storage, metering, streaming). Streaming approaches in wp-admin (SSE vs polling) were not found for any plugin.

## Q5 (Part B). WordPress.org plugin directory guidelines for external AI APIs

### Takeaway
No AI-specific guideline found; the generic guidelines on SaaS, trialware and consent apply. Disclose each service in the readme and get explicit consent before sending site/user data.

### Cited Findings
- Guideline 5: no functionality locked in the plugin behind payment/upgrade; no trial/quota lock-outs in plugin code; paid features in external services are fine if plugin code is fully available. — [Detailed guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- Guideline 6: SaaS must provide functionality of substance and be clearly documented in readme (link Terms of Use). Not allowed: service that only checks licenses/keys. — same source
- Guideline 7: plugins may not contact external servers without explicit authorized consent (opt-in); document data collection/use in readme with privacy policy link; consent counts as granted for SaaS once user registers and configures. — same source
- Guideline 8: no loading non-service JS/CSS from external hosts (CDNs only for fonts); no updates from non-WordPress.org servers. Guideline 2: API/library terms must be validatable. — same source
- Audits cite missing "External services" readme section (what is sent, when, endpoint, Terms/Privacy links); a vendor account was reportedly closed for Guideline 5 plus undisclosed services. — [GitHub issue](https://github.com/WPDevelopers/disable-comments/issues/164), [PR #27](https://github.com/WPDevelopers/simple-301-redirects/pull/27)
- Plugins using Connectors can state they store no provider keys; WordPress routes requests via provider plugins. — [AIActify readme](https://wordpress.org/plugins/aiactify-compliance/)

### Inferences
- With Connectors, the plugin itself contacts no vendor: it only calls the core AI Client. Still disclose in readme that page content is sent to whichever provider the admin configured, and add a first-use consent notice in the editor UI.
- A proprietary credit-metered proxy as the only AI path would risk Guideline 5/6 problems; make it optional.

### Gaps
- No official WordPress.org statement on AI features found. Exact "External services" readme template wording not fetched from the Plugin Handbook.

## Q6 (Part B). UX patterns for AI editing of structured content

### Takeaway
Little verified material in this pass. Verified building blocks: Elementor Angie (agentic), AI Engine block-by-block MCP editing with approval gate, Linktree separate AI change log, WP 7.0 content-only pattern editing and revisions panel for templates/patterns.

### Cited Findings
- AI Engine offers approval gating for MCP changes and draft-only mode. — [eesel](https://www.eesel.ai/blog/meow-ai-engine-review)
- WP 7.0 added content-only pattern editing and a revisions panel for templates, template parts and patterns. — [InfoQ](https://www.infoq.com/news/2026/07/wordpress-7-ai/)
- AI Experiments "Review Notes" analyzes whole posts or individual blocks. — [Make AI](https://make.wordpress.org/ai/2026/03/06/whats-new-in-ai-experiments-0-4-0/)

### Inferences
- Recommended design (my inference, not sourced): store link page as JSON; send model schema + current JSON + instruction; require structured output of a list of operations (add/update/remove/reorder with ids) validated server-side; render diff preview; apply only on user confirm; keep prior version for undo (own revision table or post revisions).

### Gaps
- Block-editor AI assistant UX research, theme.json style-variation generation by AI: not researched.

## Q7 (Part B). Security considerations

### Takeaway
Verified items are limited to capability gating and key-exposure; the rest is standard practice flagged as inference.

### Cited Findings
- Dev note: do not expose generic prompt endpoints to non-admins; use `wp_ai_client_prevent_prompt`, build narrow REST endpoints with permission checks; core AI client has no built-in rate limit or cost cap. — [Make Core dev note](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- MCP Adapter: abilities private by default; unexposed abilities return same `ability_not_found` as unknown. — [Make AI](https://make.wordpress.org/ai/2026/10/07/the-mcp-adapter-plugin-is-now-available-on-wordpress-org/)
- AI Engine 3.9.0 fixed MCP leaking API keys through option reads. — [wordpress.org](https://wordpress.org/plugins/ai-engine/)

### Inferences
- Treat all page content (link titles, bios, imported social text) as untrusted: never let model output change capabilities, call arbitrary URLs, or write raw HTML; validate URLs (allow http/https only), sanitize with wp_kses; whitelist operation types. Use REST permission_callback with current_user_can, nonce via wp_rest, per-user/day rate limits and monthly token caps (transients/options), log prompts/ops/tokens/user. Mark destructive abilities with annotations.

### Gaps
- No primary source on prompt-injection mitigations specific to WordPress.

## Q8 (Part B). Anthropic models, pricing, structured output (as of 5 Nov 2026)

### Takeaway
Official pricing page lists Haiku 5.5, Sonnet 5.5 and Opus 5.5 as current. For JSON-edit workflows Sonnet 5.5 or Haiku 5.5 with structured outputs (schema-constrained JSON) is the fit.

### Cited Findings (per MTok, input / output, from the official page)
- Claude Fable 5.1 $10 / $50 (cache read $0.25); Mythos 5.1 same price, limited availability. — [Pricing](https://platform.claude.com/docs/en/about-claude/pricing)
- Claude Opus 5.5 $4 / $20; Opus 5 and 4.x $5 / $25. — same
- Claude Sonnet 5.5 $2 / $10 (cache read $0.10); Sonnet 4.6 $3 / $15. — same
- Claude Haiku 5.5 $0.10 / $0.50 for prompts up to 100K tokens, $0.50 / $2.50 above (cache read $0.01); Haiku 4.5 $1 / $5. — same
- Batch API 50% discount (secondary source; verify) — [aipricing.guru via search](https://www.aipricing.guru/anthropic-pricing/)
- API model ID format seen in docs: `claude-opus-5-5`; this environment identifies Sonnet 5.5 as `claude-sonnet-5-5`. Haiku 5.5 ID not seen (UNVERIFIED; likely `claude-haiku-5-5`). — [Structured outputs doc](https://platform.claude.com/docs/en/build-with-claude/structured-outputs)
- Structured outputs: `output_config.format` with `type: json_schema` guarantees schema-valid JSON; strict tool use (`strict: true`) guarantees tool inputs match schema. Supported on Fable/Mythos 5.x, Opus 5.5/5/4.x, Sonnet 5.5/5/4.x, Haiku 5.5/4.5. Limits: no recursive schemas, no min/max numeric or string-length constraints, `additionalProperties` must be false, max 20 strict tools, 24 optional params, 16 union params per request; first-use grammar compile latency, cached 24 h; `stop_reason` of refusal or max_tokens can yield invalid output; ZDR-eligible. API version header shown: 2026-10-21. — [Structured outputs doc](https://platform.claude.com/docs/en/build-with-claude/structured-outputs)
- WP Anthropic provider is supported through the Connectors and AI Client; the 2025 SDK article example used `claude-sonnet-4-5` (outdated). — [AI Client SDK intro](https://make.wordpress.org/ai/2025/11/21/introducing-the-wordpress-ai-client-sdk/)

### Inferences
- Rough cost: a 4K-token page JSON plus 1K instruction in, 1K out on Sonnet 5.5 is about $0.02 per edit; Haiku 5.5 about $0.001 (my arithmetic from the listed prices). Recursive page trees are not allowed in strict schemas, so use a flat list of blocks with parent ids.
- Whether the WP AI Client's JSON-schema helper maps to Anthropic's native structured outputs, or uses prompt-only JSON, was not verified.

### Gaps
- OpenAI and Google current model names/prices: not researched (brief mention requested). Prompt-caching minimum sizes and tool-use docs not read beyond structured outputs.
