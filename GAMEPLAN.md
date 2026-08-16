# GAMEPLAN — OrderRing monorepo (`Twilio-order-communicator-`)

*Written 2026-08-13 from a full codebase pass plus fresh market research. Supersedes the market read in `docs/COMPETITIVE-GAMEPLAN.md` (scored v1.14.0; repo is at v1.22.0) and `docs/PRODUCT-ANALYSIS.md` (v1.3.0). Those stay as historical records.*

## Viability verdict

**OrderRing: VIABLE — ship it. The rest of the repo: focus risk, not asset.**

The wedge is real: OrderRing is the only actively maintained WooCommerce notifier that can **call** the customer (the one voice-capable competitor, Ultimate WP SMS / Joy of Text, was closed on wordpress.org in Dec 2025), and the only one that bundles voice + WhatsApp + two-way order chat + STOP/HELP/START automation + quiet hours + a pickup-counter workflow (Ready for Pickup status, scheduled reminders, mark-as-collected). The direct incumbent — the official Twilio SMS Notifications extension at $49/yr — is SMS-only, sits at **3.7★ on 3 reviews**, and its top review is a rant about unreachable support. That is a beatable market leader.

But be honest about where this business actually is:

- **v1.22.0 and zero customers.** The license server has no production host, no release zip is registered, the marketing site is unhosted HTML, and OrderRing Lite has never been submitted to wordpress.org. Every engineering priority in `tasks.md` is checked; every commercial one is unchecked.
- **Zero production verification.** The testing checklist in `tasks.md` is 0/~25. Real-Twilio webhooks, the entire licensing lifecycle, and quiet-hours deferral have never been exercised on a production-like store.
- **Product sprawl is the #1 threat.** OrderBay (~8.5k LOC) competes with WP Overnight's PDF Invoices — 300k+ installs, 5.0★, pan-EU e-invoicing **free**. That is a near-unwinnable fight and every hour spent there is taken from OrderRing. StoreCanvas has a genuine window (Fancy Product Designer is EOL, ~23k licenses being herded to a $29–199/mo SaaS), but its own audit scored it 4/10 on security with a "do NOT charge" flag — it's a timeboxed bet, not a second flagship.

**Recommendation: not pivot, not archive — *stop building and start selling* OrderRing. Freeze OrderBay. Timebox StoreCanvas.** If OrderRing can't find 10 paying customers in 90 days with this feature set against a 3.7★ incumbent, no additional feature will fix that, and the honest next step would be archiving the commercial ambition and leaving Lite on wordpress.org as a portfolio piece.

## Product summary

**What it is:** a WooCommerce plugin monorepo. The flagship, **OrderRing** (`twilio-order-communicator/`, v1.22.0, ~8,200 lines PHP), sends SMS, voice calls, and WhatsApp messages through the merchant's **own Twilio account** (zero markup, we never touch message revenue). Core loop: order hits *Ready for Pickup* or *Shipped* → customer gets a consent-checked, quiet-hours-aware text or call → replies land in a per-order chat with STOP/HELP/START handled automatically → staff mark collected, with scheduled and bulk reminders for stragglers. Compliance is first-class: checkout consent with timestamp/IP audit meta, Twilio signature validation, SMS footer, delivery analytics, CSV export, role matrix.

**Who it's for:** stores with a pickup counter or local-fulfillment desk — bakeries, pharmacies, garden centers, butchers, click-and-collect retail — where "your order is ready, come get it" is the highest-value message the store sends and a missed pickup costs shelf space and spoilage. Staff, not marketers, are the users.

**Siblings:** OrderRing Lite (`orderring-lite/`, wordpress.org-ready free funnel: Ready-for-Pickup SMS only, no phone-home), StoreCanvas (product customizer, FPD-refugee play), OrderBay (order docs/fulfillment desk), and a first-party 932-line license server that gates **updates only — never messaging**.

## Competitive landscape

| Competitor | Pricing | Headline features | Weaknesses (from reviews/complaints) |
|---|---|---|---|
| **Twilio SMS Notifications** (SkyVerge/GoDaddy, official WooCommerce Marketplace) | $49/yr | Auto SMS on status change, admin new-order SMS, message templates, custom statuses, Blocks checkout | **3.7★ (3 reviews)**; support unreachable ("can only leave a message with the reprobate AI garbage"); SMS-only — no voice, WhatsApp, two-way, consent capture, quiet hours, or pickup workflow |
| **YITH WooCommerce SMS Notifications** | ~$70/yr | 40+ SMS gateways, placeholders, checkout opt-in checkbox, URL shortening, YITH custom statuses | 4.5★ but narrow scope: weak two-way messaging and WhatsApp, no voice; value tied to being in the YITH ecosystem; gateway fees always extra |
| **WP SMS Pro / WSMS** (VeronaLabs) | from $59/yr | 300+ gateways, OTP/2FA login, WooCommerce + forms integrations, MMS | WhatsApp Cloud API template failures reported despite correct setup; support email landing in spam, refund chased via Twitter; free-tier 2FA claims don't work with Twilio |
| **SMS for WooCommerce** (Zorem) | $119/yr | 19 gateways + WhatsApp Business, two-way SMS, timezone-aware Do Not Disturb, AST tracking integration | Closest feature rival but ~1.7× our planned price; no voice; no pickup-counter workflow (statuses, reminders, collected); transactional-only by their own positioning |
| **Klaviyo / Omnisend / TxtCart** (SaaS SMS) | $29–$999/mo + per-message credits | Marketing automation, segmentation, abandoned cart, lifecycle campaigns | Pricing and support are the top two complaint categories in review analysis; credit model creates surprise bills; transactional order SMS is an afterthought; massive overkill for a pickup counter |

Cross-market grievances worth weaponizing (consistent across wordpress.org 1★ reviews in this category): (1) retroactive paywalling / bait-and-switch, (2) support silence after purchase, (3) updates breaking working stores. Our pricing doc already commits to the antidotes: one paid tier, no nag ads, license failure never disables messaging, 30-day refund.

## KEEP — what differentiates us

- **Voice calls** (`class-toc-twilio.php` `make_call`, tokenized TwiML, Polly voices). No active competitor has this. It is *the* headline.
- **The pickup-counter workflow**: `wc-ready-for-pickup`/`wc-shipped` statuses, scheduled + bulk reminders, mark-as-collected, per-status templates (`class-toc-statuses.php`, `class-toc-reminders.php`, `trait-toc-admin-bulk.php`). Competitors send notifications; we run the counter.
- **Two-way order chat + STOP/HELP/START automation** with MessageSid idempotency (`class-toc-webhooks.php`, `class-toc-order-meta.php`).
- **Compliance stack**: checkout consent with timestamp/IP/source audit, quiet hours that defer rather than drop, SMS footer, signature validation, opt-out table. This is TCPA/GDPR homework competitors leave to the merchant.
- **BYO Twilio, zero markup** + **license gates updates only, never messaging**. Directly answers the market's #1 and #2 grievances; keep the pledge verbatim in all copy.
- **OrderRing Lite as the wordpress.org funnel** — genuinely useful free product, no phone-home, steps aside when Pro is active. This is the entire discovery channel; it must ship.
- **First-party license server** (932 lines, fails closed, signed downloads). Small, done, aligned with the no-Freemius decision in `tasks.md` #14. Keep — but deploy it.
- **StoreCanvas FPD importer** (`class-sc-fpd-import.php`) — the one StoreCanvas asset with a clock on it; the EOL migration window is why StoreCanvas gets a timebox at all.

## ADD — gaps competitors expose (effort vs impact)

| # | Gap | Effort | Impact | Why |
|---|---|---|---|---|
| 1 | **Ship the commercial loop**: domain, deploy `license-server/`, tag a release, register the zip (`bin/add-release.php`), host `docs/launch/site/` | Days (human/ops, not code) | **Existential** | Every competitor's actual advantage over us today is that they are purchasable |
| 2 | **Submit OrderRing Lite to wordpress.org** + screenshots of the pickup flow | Days | Existential | Only free-tier funnel in a market where the official extension has 3 reviews; SEO for "pickup SMS WooCommerce" is uncontested |
| 3 | **E2E smoke in CI** using the existing Twilio HTTP mock (`tools/dev/setup-wp.sh` + mu-plugins): order → Ready for Pickup → assert send captured | Low (harness exists) | High | Testing checklist is 0/25; "updates break working stores" is the market's #3 grievance — CI is the guardrail |
| 4 | **Lite↔Pro parity tests** for the 27 duplicated Twilio methods (consent truthiness, signature validation, STOP handling, footer) | Low | High | A consent/security bug currently needs fixing twice with no drift alarm — compliance is the moat, so drift is existential |
| 5 | **Runtime-verify licensing + webhooks against real Twilio** and record in `docs/RUNTIME-VERIFICATION.md` | Medium | High | Expired keys, activation limits, grace period, real update install: all unverified; a broken paid-update path after launch = 1★ reviews |
| 6 | **Support SLA as a marketed feature** (e.g. "human reply in 1 business day" on pricing page, plus docs) | Low | Medium | Support silence is the incumbent's most visible 1★ complaint; cheap to promise at zero-customer scale |
| 7 | **Pickup/delivery-slot plugin integrations** (Local Pickup Plus, delivery-slot plugins — `tasks.md` #24) | Medium | Medium | Deepens the counter wedge; do only after first customers confirm demand |
| 8 | Staff inbox / open-conversation claiming (`tasks.md` #21), Slack/Discord alerts (#25) | High | Low-Med | Post-revenue only; don't build ahead of sales feedback |

Explicit non-adds (unchanged from `tasks.md` out-of-scope): abandoned cart / marketing automation (Klaviyo territory and their users' complaints prove it's a different buyer), AI chatbot, reselling messaging.

## CUT — things that don't serve the wedge

- **OrderBay feature growth — freeze.** Especially `class-ob-einvoice.php` (660 lines, second-largest class): full UBL 2.1/Factur-X assembly that by its own readme can't reach Peppol and needs external validation, in a category where the 300k-install incumbent ships e-invoicing free. Keep what exists for any current users; build nothing more.
- **Hand-rolled QR encoder** (`class-ob-qr.php`, 540 lines of Reed-Solomon the readme calls "experimental… validate with a real scanner"). Make library-backed QR (`chillerlan/php-qrcode`) the only supported path, and fix the latent bug: `templates/invoice.php:65` and `templates/packing-slip.php:129` call `OB_QR::render_for_order()` unconditionally while `class-ob-documents.php:141` gates on availability.
- **StoreCanvas Journey click-tracker as a product surface** (`class-sc-journey.php`): own DB table, top-level merchant menu, unauthenticated `wp_ajax_nopriv_sc_journey_log`, **enabled by default**. It's internal debug tooling — default off, constant-gated, menu removed.
- **Triple-copied license client (~2,600 lines).** `class-sc-license.php` and `class-ob-license.php` are byte-identical modulo prefix; `license.js` is fully identical; OrderRing carries a third variant. Single canonical source generating the copies + a CI drift gate (same pattern as `tools/release/check-versions.php`).
- **Confirmed dead code:** `class-toc-logger.php:963–974` `get_reminder_candidates()` (deprecated 1.2.2, zero callers); `class-sc-customizer.php` still self-describes as "Scaffold" at v1.9.0.
- **Stale strategy surface:** `docs/PRODUCT-ANALYSIS.md` (reviews v1.3.0, recommends the rejected Freemius path), `docs/COMPETITIVE-GAMEPLAN.md` scorecards (v1.14.0-era, most bugs since fixed), and `tasks.md` P2 items #20/#22/#23 listed open but shipped in 1.15.0–1.18.0. Banner the docs as historical, fix the task list — agents keep re-reading these as current.
- **Branch sprawl:** 50+ remote `cursor/*`/`claude/*` branches against a single `main`. Prune merged/abandoned ones.

## Positioning

**"OrderRing is the only WooCommerce notifier that can *call* your customer — pickup-ready texts, voice, and WhatsApp on your own Twilio account with zero markup, and a license that can never switch your messaging off."**

(Against the $49 official extension specifically: they send a text when an order status changes; OrderRing runs your pickup counter — and when Grandma doesn't read texts, it rings her phone.)

## 90-day roadmap

**Days 1–14 — Make it purchasable (feature freeze everywhere).**
- Buy domain; deploy `license-server/` per `docs/launch/LICENSE-SERVER-DEPLOY.md`; real secrets via `bin/generate-secrets.php`; smoke `/v1/health` → `activate` → `update-check` → signed `download` from a real site.
- Tag OrderRing 1.22.x, register the zip with `bin/add-release.php`, verify the in-plugin updater installs it.
- Host `docs/launch/site/`; wire pricing → checkout → key delivery (even if key delivery is manual email at first).

**Days 15–30 — Distribution + trust.**
- Submit `orderring-lite/` to wordpress.org (Plugin Check already clean); prepare assets/screenshots of the Ready-for-Pickup and chat flows.
- Land CI E2E smoke (ADD #3) and Lite↔Pro parity tests (ADD #4).
- Run the `tasks.md` testing checklist against real Twilio on a staging store; record results in `docs/RUNTIME-VERIFICATION.md`; fix what breaks.
- Publish support SLA; set up support inbox that a human actually answers.

**Days 31–60 — Launch and first customers.**
- Announce (WooCommerce communities, local-retail/pharmacy/bakery forums, "Twilio SMS WooCommerce" SEO content). Target: **10 paying customers**.
- Ship CUT items: QR gating fix, Journey default-off, license-client dedup + drift gate, dead-code removal, doc banners, branch prune.
- Weekly: answer every support thread and wordpress.org review; ask each customer what almost stopped them buying.

**Days 61–90 — Double down or decide.**
- If OrderRing has paying customers: build the top requested integration (likely ADD #7), keep shipping.
- StoreCanvas decision point: only if OrderRing is launched and stable, spend a strict 2-week timebox closing its audit's security items 1–5 and print-ready parity vs Lumise; otherwise park it and keep only the FPD-import landing page as a lead magnet.
- OrderBay stays frozen. If zero OrderRing sales despite launch + funnel + outreach: write the post-mortem, leave Lite on wordpress.org, archive the commercial track.
