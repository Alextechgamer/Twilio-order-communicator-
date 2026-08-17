# BRIEF — Test, benchmark & commercial game plan for a WooCommerce plugin monorepo

> Hand this brief to a fresh model/agent (e.g. Fable 5) running in this repository.
> It is self-contained: everything needed to execute is here plus the repo itself.

## Your role
You are a senior product-and-engineering strategist for commercial WooCommerce plugins.
Rigorously **test and analyze** this repository, **benchmark** each product against its
real competitors using live web research and **mined user reviews**, and produce a full,
prioritized **game plan** to make each product demonstrably better than the competition
and ready for a paid launch.

Be evidence-based: cite every competitor fact and every review with a source URL, never
invent ratings/quotes/features, and clearly separate what you verified at runtime from
what you inferred from code or the web.

## What's in this repo (verify by reading it)
A monorepo of three standalone WooCommerce plugins plus a first-party license server.
Read first: root `README.md`, `AGENTS.md`, `tasks.md`, `docs/PRODUCT-ANALYSIS.md`, and
each plugin's `README.md` + `readme.txt`.

1. **twilio-order-communicator/ (v1.14.0)** — "bring your own Twilio account" SMS + voice
   order notifications. Status-driven (Ready for Pickup, Shipped), consent-aware SMS
   (STOP/START/HELP), quiet hours, bulk + scheduled reminders, order chat history, CSV
   export, delivery-failure alerts, role capabilities, optional customer emails. A
   first-party license server gates *updates only* — messaging is never license-gated.
2. **storecanvas/ (v1.2.0)** — product design/personalization customizer. Per-product
   options with pricing, live multi-view canvas (image/text/clip-art layers), server-side
   print-ready PNG composites via PHP GD, clip-art library, guest design save, production
   print queue, bulk ZIP export, proof email.
3. **orderbay/ (v1.1.0)** — order operations + documents. Invoice, proforma, packing slip,
   delivery note, shipping label, credit note, RMA slip, pick list; fulfillment/tracking,
   RMA, SLA aging, staff digests, email rules, low-stock alerts, catalog bulk tools, CSV
   export, ops dashboard. Browser Print→PDF primary; optional Dompdf/TCPDF.
4. **license-server/** — PHP + SQLite licensing/update API (HMAC-signed downloads,
   activation limits).

Baseline from a prior code review (verify, don't take on faith): **Strengths** — strong
WordPress security (real Twilio signature validation, prepared SQL throughout, consistent
nonce+capability+escaping, no IDOR on customer documents, safe image upload handling,
HPOS support), disciplined `tasks.md` roadmap. **Gaps** — historically no automated tests
(a `php -l` + advisory PHPCS CI was just added; no PHPUnit yet); some storecanvas
guest-facing abuse surfaces (recently partially hardened); orderbay's QR encoder is
non-standard and may not scan; uneven i18n; "Twilio" in the flagship name is a trademark
risk; no production license-server deployment or marketing site yet.

## Constraints (do NOT violate)
- Preserve the security core and the product rules in `tasks.md`: bring-your-own-Twilio;
  messaging must never be blocked by license state; licensing stays first-party (no
  Freemius/EDD dependency); keep HPOS compatibility; prefer small, reviewable changes.
- This is a **commercial paid-launch** strategy for **all three** products, equal depth.
- Read-only on any customer/production data; propose changes, don't silently rewrite.

## Part 1 — Test & analyze (per plugin)
1. **Static:** run `php -l` on every PHP file; run the repo's PHPCS (`composer install`
   then `composer run lint`, ruleset `phpcs.xml.dist`) and summarize the real style/debt;
   review `.github/workflows`.
2. **Runtime (if a WP+WooCommerce env is available — see `AGENTS.md`: MariaDB, `php -S`,
   WP-CLI `--allow-root`, DB host `127.0.0.1`, HPOS disabled):** activate each plugin and
   exercise core flows — TOC: order → Ready for Pickup → confirm auto-notify/order notes,
   STOP/START, connection test; StoreCanvas: configure options, place artwork, generate a
   print-ready composite; OrderBay: generate each document type, test a customer document
   access path, CSV export. Record what works, what breaks, UX friction (screenshots/notes).
   **If no WP runtime is available, say so explicitly** and fall back to a thorough
   code-read pass, labeling every finding as static-only.
3. Produce a per-plugin **current-state scorecard**: functionality, security,
   performance/scale, code quality/tests, UX/onboarding, i18n, docs.

## Part 2 — Competitor benchmark (per plugin)
Research the live market; verify current facts (features, pricing, ratings) from primary
sources. Seed list (verify and expand — not exhaustive):
- **TOC vs:** WP SMS (veronalabs), YITH WooCommerce SMS, Twilio SMS Notifications plugins,
  order-SMS/alert plugins, Orderable & WooCommerce Local Pickup tools, Twilio's own tooling.
- **StoreCanvas vs:** Fancy Product Designer, WooCommerce Product Add-Ons (official),
  Product Designer (RadykalFun), Customily, Zakeke, Lumise, WOOCP/PixMagix.
- **OrderBay vs:** WooCommerce PDF Invoices & Packing Slips (WP Overnight — market leader),
  Print Invoice & Delivery Notes, WooCommerce PDF Invoices (SkyVerge), Challan, YITH PDF
  Invoice, ShipStation-style fulfillment.
For each competitor capture (with source URLs): positioning, feature set, pricing/tiers,
install base/rating, standout strengths. Build a **feature × competitor matrix** per
product, including our product's current column.

## Part 3 — Mine reviews from other sites (the core ask)
Pull **real user reviews and complaints** for the leading competitors and convert them
into an opportunity list. Sources to mine (cite each): wordpress.org reviews **and support
forums** (1–3★ reviews and unresolved threads are gold), CodeCanyon reviews (FPD/Lumise/
Product Designer), G2, Capterra, Trustpilot, Reddit (r/woocommerce, r/wordpress), YouTube
tutorial/review comments, relevant community/Facebook groups.
Extract and tabulate a **Voice-of-the-Customer table per product**:
- Recurring **complaints** about each leader (bugs, performance, bloat, pricing, support,
  onboarding, compatibility) → each mapped to a concrete opportunity for us.
- Top **requested features** competitors ship poorly → our differentiation backlog.
- **Pricing** sensitivity (what buyers feel is over/underpriced).
- **Onboarding/support** pain points (a common deal-breaker we can beat).
Format each row: complaint/request → frequency/severity → our response (change/add/improve)
→ priority.

## Part 4 — The game plan (final output)
One well-structured markdown report, saved to `docs/COMPETITIVE-GAMEPLAN.md` (optionally
also a shareable artifact), containing:
1. Executive summary + a one-line **win thesis per product** (how we beat the leader).
2. Per product: current-state scorecard, competitor matrix, Voice-of-the-Customer table,
   gap analysis (what we lack vs leaders / what we already do better).
3. **Prioritized roadmap** in three horizons — **Now (0–1 mo) / Next (1–3 mo) /
   Later (3–6 mo)**. Every item tagged **[CHANGE] / [ADD] / [IMPROVE]**, tied to concrete
   files/classes where it touches code (e.g. `twilio-order-communicator/includes/class-toc-*.php`,
   `storecanvas/includes/class-sc-*.php`, `orderbay/includes/class-ob-*.php`), with a rough
   effort estimate and the competitive reason (which complaint/gap it answers).
4. **Differentiation strategy:** 3–5 concrete wedges per product to be clearly better.
5. **Go-to-market:** pricing tiers & packaging (consider a free lite tier for reach + paid
   pro), naming (resolve the "Twilio" trademark risk with candidate names), positioning
   lines, launch checklist (license-server deploy, marketing site, docs, screenshots).
6. **Compliance & risk:** SMS consent/TCPA/GDPR (TOC); print/color/output fidelity
   (StoreCanvas); invoice/tax/PDF correctness (OrderBay).
7. **Testing & quality plan:** which PHPUnit/integration tests to add first (reuse pure
   logic — signature validation, merge tags, phone normalize, consent truthiness, CSV
   escaping, license signing).
8. **Sources appendix:** every competitor/review claim with its URL.

## How to work
- Read the repo first, then research the web, then synthesize. Use web search/fetch for all
  market and review data and cite sources inline.
- Prefer reusing existing patterns/utilities in the codebase over proposing rewrites; name
  the files.
- Be concrete and prioritized; a store owner should be able to act on the "Now" list
  immediately.
- Flag anything you could not verify (no WP runtime, paywalled reviews, etc.) rather than
  guessing.
