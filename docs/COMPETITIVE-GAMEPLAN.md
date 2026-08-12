# Competitive Game Plan — Test, Benchmark & Paid-Launch Strategy

**Repo:** WooCommerce plugin monorepo (**OrderRing** — formerly Twilio Order Communicator, StoreCanvas, OrderBay) + first-party license server
**Prepared:** 2026-08-11
**Scope:** Full runtime + static test, live competitor benchmark, mined user reviews, and a prioritized commercial roadmap for all three products.

> **How to read the evidence labels.** Findings are tagged **[RUNTIME]** (verified on a live WordPress 7.0.3 + WooCommerce 11.0.1 + PHP 8.4.19 install with all three plugins active), **[STATIC]** (verified by reading code / running `php -l` + PHPCS), or **[WEB]** (verified from a cited external source). Every competitor fact and every review quote carries a source URL in the appendix. Where a claim could not be verified, it is marked *unverified* rather than asserted.

---

## 0. Test environment & what was actually verified

A real stack was stood up for this review — **not** a code-read only pass:

- **Stack:** WordPress 7.0.3, WooCommerce 11.0.1, PHP 8.4.19 (CLI + GD), MariaDB 10.11, HPOS disabled (classic `shop_order`), all three plugins activated cleanly with no fatals. **[RUNTIME]**
- **`php -l`:** all **74** PHP files parse clean. **[STATIC]**
- **PHPCS** (`phpcs.xml.dist`, WordPress + PHPCompatibility): TOC reports **427 errors / 147 warnings** across 21 files — but the composition is overwhelmingly *style debt*, not defects: 163 Yoda-condition, 114 missing-docblock, ~113 array/alignment. Security-relevant sniffs are far fewer and mostly false positives on already-prepared SQL (see §Testing plan). StoreCanvas (out of ruleset scope) trips **24** security-sniff hits; OrderBay is cleanest of the three. **[STATIC]**
- **CI:** there is **no `.github/workflows/` directory in the tree** — the "php -l + advisory PHPCS CI" described in the brief is **not present in this checkout**. Adding it is a Now-item below. **[STATIC]**

### Runtime results by plugin

| Flow exercised | Result |
|---|---|
| **TOC** — order → *Ready for Pickup* | Auto-notify order notes written; `_toc_notified_ready_for_pickup_at` stamped; SMS-toggle-off correctly honored ("SMS toggle is disabled"); voice call attempted and failed with fake creds ("Authentication Error – invalid username") — **exactly the documented behavior**. **[RUNTIME]** |
| **TOC** — connection test | Returns `success=false, "Twilio rejected credentials: Authentication Error"` — correct fail path. **[RUNTIME]** |
| **TOC** — webhook signature | Unsigned POST to `/toc/v1/sms` → **HTTP 403**. Correctly HMAC-SHA1-signed **STOP** → **HTTP 200** with TwiML unsubscribe reply; opt-out recorded; inbound+outbound both logged to the order chat; a subsequent `send_sms()` to that number was blocked with "Phone number has opted out (STOP)." **The consent + signature core works end-to-end.** **[RUNTIME]** |
| **StoreCanvas** — configure + composite | Configured a product (view + print area), generated a server-side print composite via GD → `sc-print-10-front-*-scaled.png`, **2560×1920, 47 KB**. GD compositing pipeline works. **[RUNTIME]** |
| **OrderBay** — documents | **7 of 8** templates (invoice, packing slip, proforma, delivery note, shipping label, credit note, RMA slip) render with order number + customer data; invoice numbering assigned `INV-1`; QR (V3, 433 modules) and Code128 barcode SVG both generate. Pick-list renders only through its real handler (which populates `$order_numbers`, `class-ob-fulfillment.php:543`); calling the template directly warns — not a shipping bug, a template-coupling note. **[RUNTIME]** |

**Could not verify at runtime (labeled static/web elsewhere):** real Twilio SMS/voice delivery (needs live BYO creds), whether the OrderBay QR actually *scans* (needs a phone/decoder — the encoder defect is a code-read finding), Dompdf/TCPDF PDF output fidelity, and e-invoice XML correctness.

---

## 1. Executive summary

This is a genuinely well-engineered monorepo whose **security fundamentals are real** — verified, not assumed: Twilio signature validation is correct HMAC-SHA1 with `hash_equals` and rejects unsigned requests at runtime; SQL is prepared throughout TOC; nonce+capability checks are present on every admin/AJAX action; HPOS is declared and implemented across all three plugins. The gap to a paid launch is **not** the core — it is (a) a handful of concrete correctness bugs, (b) three specific competitive feature gaps, (c) packaging/compliance polish, and (d) the total absence of an automated test suite and CI.

The market timing is unusually favorable on two of three fronts:

- **StoreCanvas:** the category incumbent **Fancy Product Designer is officially end-of-life** on CodeCanyon (maintenance-only, cloud export/AI services already shut off, ~23,000 licenses being herded onto a $29–199/mo SaaS successor "Chamevo"). **[WEB]** A maintained, self-hosted, no-per-order-fee designer has a clean migration pitch — *if* it closes the vector/PDF-output gap that Lumise already owns.
- **TOC:** **voice calls are a genuine moat** — the only WooCommerce SMS plugin that shipped voice (Ultimate WP SMS / Joy of Text) was closed on wordpress.org in Dec 2025 and its vendor site is down. **[WEB]** No active competitor pairs voice + two-way order chat + STOP automation + quiet hours + pickup reminders.
- **OrderBay:** the hardest field. The market leader (WP Overnight, 300k+ installs, 5.0★) already ships pan-EU e-invoicing *for free*, and OrderBay ships **zero e-invoicing** just as France's B2B mandate lands (Sept 2026). But no competitor bundles documents + tracking + RMA + SLA + alerts + dashboard in one plugin — OrderBay's real product is the **ops suite**, not the document list.

### Win thesis per product (one line each)

- **TOC → "The only WooCommerce notifier that *calls* the customer — plus two-way pickup chat, consent on autopilot, and you pay Twilio directly with zero markup."** Beat the leaders on voice + two-way + pickup workflow + honest BYO pricing; they are one-way, credit-resold, or abandoned.
- **StoreCanvas → "The self-hosted product designer that can't be sunset from a server you don't control — one-time license, 0% of your sales, with a one-click Fancy Product Designer importer."** Beat FPD on being alive and beat Zakeke/Customily on per-order fees; must reach parity on print-ready PDF/SVG output.
- **OrderBay → "Every order document *and* the whole fulfillment desk — tracking, returns, SLA, digests, alerts — in one plugin, with EU-compliant invoicing that isn't paywalled."** Beat the document-only leaders on scope and beat everyone on "legal-minimum compliance is free."

### Cross-product themes from the review mines (what buyers punish)

Three complaints recur across **all three** competitor categories and should shape positioning everywhere:

1. **Bait-and-switch / retroactive paywalling** is the single largest 1★ driver found (WP SMS gating Twilio behind Pro; zorem clawing back free tracking features; WP Overnight moving meta fields to paid). **[WEB]** → Publish a *"free-forever" feature guarantee* and never move a shipped free feature behind the paywall.
2. **Support silence kills paid conversions** — unanswered presales/compliance questions, AI-ticket-closure, "pay to renew support just to report a bug." **[WEB]** → A human support SLA is itself a marketable differentiator in these three niches.
3. **Updates that break working stores** (WP SMS post-update fatals; Print Invoice V7 revolt; FPD/Lumise update breakage). **[WEB]** → This is the direct argument for the automated test suite + CI in §Testing plan; ship stability as a feature.

---

## 2. Twilio Order Communicator (TOC) v1.14.0

### 2.1 Current-state scorecard

| Dimension | Score | Evidence |
|---|---|---|
| Functionality | **9/10** | Every readme claim is implemented and wired; docked for the settings-save capability bug and reminder/local-pickup inconsistency (below). **[STATIC/RUNTIME]** |
| Security | **7/10** | Correct Twilio HMAC-SHA1 + `hash_equals` (`class-toc-twilio.php:279-317`), verified rejecting unsigned at runtime; every AJAX/admin-post has nonce+cap; all dynamic SQL prepared. Docked: plaintext auth token in options, CSV formula injection, replayable webhooks/TwiML tokens, spoofable consent-audit IP. **[STATIC/RUNTIME]** |
| Performance/scale | **6/10** | Opt-outs in a dedicated indexed table — but lookups use non-sargable `RIGHT(phone_digits,10)`; leading-wildcard `LIKE` phone matching on postmeta; bulk tab renders ~200 rows with per-row consent queries (N+1). Chunked CSV export + Action Scheduler are good. **[STATIC]** |
| Code quality/tests | **7/10** | Clean trait-split admin, consistent docblocks, bounded filters. **Zero tests**, no namespace/autoloader, some duplicated consent/template logic. **[STATIC]** |
| UX/onboarding | **7/10** | Real 6-step wizard (creds→test→webhook→consent→auto-notify→done); Settings is one ~600-line form; wizard never validates the From number (no E.164 check anywhere). **[STATIC]** |
| i18n | **6/10** | Correct text domain where used, JS localized — but **no `.pot`**, and `class-toc-twilio.php` + `trait-toc-admin-ajax.php` have zero gettext calls (order-note + admin-alert strings ship English-only). **[STATIC]** |
| Docs | **8/10** | Strong readme (FAQ, granular changelog, documented meta keys) + in-plugin Tools & Docs tab + privacy-policy helper. **[STATIC]** |

### 2.2 Competitor matrix

Legend: ✅ present · ⚠️ partial/via 2nd paid plugin · ➖ absent · ❔ unverified. Sources in appendix.

| Feature | **TOC (ours)** | WP SMS/WSMS | YITH SMS | SkyVerge Twilio SMS | Flow Notify | SMS Alert | Orderable Pro |
|---|---|---|---|---|---|---|---|
| SMS order notifications | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Voice calls** | ✅ TwiML/Polly | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ |
| WhatsApp | ➖ (roadmap) | ❔ | ➖ | ➖ | ✅ | ❔ | ✅ |
| Custom order statuses | ✅ built-in | ❔ | ⚠️ 2nd plugin | ⚠️ 2nd plugin | ✅ | ❔ | ✅ |
| Pickup reminders (scheduled/bulk) | ✅ | ➖ | ➖ | ➖ | ❔ | ➖ | ❔ |
| Two-way chat inbox | ✅ | ✅ | ➖ | ➖ (one-way) | ➖ | ❔ | ❔ |
| Consent + STOP/START/HELP | ✅ both | ❔ | ✅ checkbox; STOP ❔ | ✅ checkbox; STOP ❔ | ❔ | ❔ | ❔ |
| Quiet hours | ✅ | ❔ | ❔ | ❔ | ❔ | ❔ | ❔ |
| CSV export of history | ✅ | ❔ | ❔ | ❔ | ❔ | ❔ | ❔ |
| BYO gateway (no markup) | ✅ | ✅ | ✅ | ✅ | ✅ | ➖ resells | ❔ |
| HPOS compatible | ✅ | ❔ | ❔ | ✅ | ❔ | ❔ | ❔ |
| Price (single site) | *TBD* | $29–199/yr + lifetime | $69.99/yr | $49/yr | $89/yr | credits | $149/yr |

**Verified market facts:** WP SMS/WSMS 7,000+ installs, 4.1★/107 (19×1★); SkyVerge Twilio SMS 2,000+ installs, 3.7★/3, one-way only, HPOS-compatible; YITH one-way, custom statuses need a second paid plugin; the only voice-capable plugin (Ultimate WP SMS) closed Dec 2025. Twilio publishes **no** first-party WP plugin. **[WEB]**

### 2.3 Voice-of-the-Customer → opportunity

| Competitor complaint (with evidence) | Freq/Severity | Our response | Priority |
|---|---|---|---|
| "Free" gates Twilio behind Pro — *"bait & switch… Twilio requires the pro version"* (WP SMS, 6 sources) | High / deal-breaker | Ship Twilio genuinely free in a Lite tier; state gating on the listing before install | **Now** |
| Silent send failures — *"showed 'success' … never reached recipient"* (WP SMS, 4 threads) | High / deal-breaker | We already log delivery StatusCallbacks + write order notes; **add E.164 validation at config time** (currently missing) and surface per-message error in admin | **Now** |
| Plugin broke checkout / lost orders (SMS Alert, WP SMS) | High / deal-breaker | Our messaging is already fail-open and never blocks checkout — **make this an explicit positioning line** | Now |
| One-way only (SkyVerge, YITH) | Med / differentiator | Two-way order chat is already our headline — lead with it | Now |
| No pickup/local workflows in mainstream plugins; requires stacking Zorem + paid SMS addon | Med / unmet niche | *This is our core.* Ready-for-Pickup + bulk "notify all ready" is already built | Now |
| No voice anywhere active | Med / moat | Voice is unique — feature it in the hero | Now |
| Support unresponsive / refund refused (8+ sources, 4 plugins) | High / deal-breaker | Human support SLA + public 30-day refund policy | Next |
| Missing/broken WhatsApp (WP SMS error 131008; YITH none) | Med / non-US | Add WhatsApp via the store's own Twilio WhatsApp sender (P2 #20) | Later |

### 2.4 Gap analysis

- **We already do better:** voice calls, two-way chat, STOP/START/HELP automation (verified at runtime), quiet hours, scheduled+bulk pickup reminders, consent capture on classic *and* block checkout, CSV export, delivery-failure alerts, role caps, fail-open messaging. No single competitor matches this set.
- **We lack vs leaders:** WhatsApp (Flow Notify, Orderable), a free/lite tier (nearly all have one), delivery-analytics dashboard (sent/delivered/reply rates), and message-template A/B. Naming exposes a **trademark risk** (below).

### 2.5 Bugs & risks found (concrete, from the code audit)

1. **[BUG] Non-admin managers cannot save Settings.** Settings post to `options.php` (needs `manage_options`); the role-matrix grants `toc_manage` but not `manage_options`, so a `shop_manager` can view but not save Settings. Fix: `option_page_capability_toc_settings` filter → `toc_manage`. `trait-toc-admin-settings.php:11-115`.
2. **[BUG] Scheduled reminders ignore the Local Pickup filter.** `TOC_Reminders::run()` lacks the local-pickup guard that `TOC_Auto::process_order` and bulk both have (`class-toc-reminders.php:181-299`) → a ship-to-home order moved to Ready gets a reminder call it should never receive.
3. **[SECURITY] CSV formula injection.** `csv_escape_field()` quotes but doesn't neutralize leading `= + - @`; inbound SMS bodies are attacker-controlled → Excel formula execution (`trait-toc-admin-dashboard.php:33-41`).
4. **[BUG] `normalize_phone` mangles non-NANP numbers.** `ltrim($phone,'0')` + default `+1` turns UK `07911…` into `+17911…` (`class-toc-logger.php:651-656`). Wrong out-of-box for international stores; also corrupts opt-out keys.
5. **[SECURITY] Consent-audit IP is spoofable** — trusts first `X-Forwarded-For` hop (`class-toc-checkout.php:415-424`); the TCPA-relevant `_toc_sms_consent_ip` can be forged.
6. **[RISK] Webhook/TwiML replay** — no MessageSid idempotency guard on `incoming_sms`; TwiML token not deleted after first fetch (customer name + order number retrievable for 15 min by URL holder).
7. **[BUG] `cart_checkout_blocks` compatibility not declared** despite real block-checkout integration → WooCommerce flags the plugin "not compatible" in the incompatibility UI.
8. Consent bypass for order-less manual SMS (`order_id=0` skips consent check); same-status double-fire if Ready and Shipped map to one status; uninstall leaves caps + `_toc_*` meta behind.

### 2.6 Prioritized roadmap

**Now (0–1 mo)**
- **[CHANGE]** Fix Settings-save capability for `toc_manage` managers — `trait-toc-admin-settings.php`. *~0.5d. Answers a real usability trap for the plugin's own headline caps feature.*
- **[CHANGE]** Add the Local Pickup guard to `TOC_Reminders::run()` — `class-toc-reminders.php`. *~0.5d. Prevents wrong-audience reminder calls.*
- **[CHANGE]** Neutralize CSV formula injection in `csv_escape_field()` — `trait-toc-admin-dashboard.php`. *~0.5d.*
- **[ADD]** E.164 validation on the From number + per-recipient number at send/config time (answers the #1 silent-failure complaint) — `class-toc-twilio.php`, settings trait. *~1d.*
- **[CHANGE]** Declare `cart_checkout_blocks` compatibility — `twilio-order-communicator.php`. *~0.5h.*
- **[ADD]** `.github/workflows/ci.yml` (php -l + PHPCS) — repo root. *~0.5d.*
- **[CHANGE]** Resolve the **"Twilio" trademark risk** by renaming (see §5 GTM). *Naming decision, not code.*

**Next (1–3 mo)**
- **[ADD]** First-party **free Lite tier** (SMS + one status + consent) for wordpress.org reach; Pro adds voice, reminders, chat, CSV, alerts. *~1–2wk packaging.*
- **[ADD]** Delivery analytics (sent/delivered/reply rates) — new dashboard card off `TOC_Logger` (P2 #22). *~1wk.*
- **[CHANGE]** International phone normalization (respect store country / libphonenumber-style tail matching) — `class-toc-logger.php`. *~2–3d.*
- **[ADD]** MessageSid replay guard + one-time TwiML token — `class-toc-webhooks.php`, `class-toc-twilio.php`. *~2d.*
- **[IMPROVE]** i18n: generate `.pot`, wrap `class-toc-twilio.php` + ajax-trait strings. *~2d.*

**Later (3–6 mo)**
- **[ADD]** WhatsApp via the store's own Twilio WhatsApp sender (P2 #20). *~2wk.*
- **[ADD]** Simple open-conversation/staff-claim inbox view (P2 #21). *~2wk.*
- **[ADD]** Tracking-number merge tag + richer shipped messages (P2 #23); Slack/Discord inbound alerts (P2 #25).

### 2.7 Differentiation wedges (TOC)

1. **Voice + SMS + two-way in one plugin** — nobody active has all three.
2. **Pickup-desk workflow** (Ready-for-Pickup, scheduled + bulk reminders, "mark collected") — an unowned niche the mainstream plugins force you to stack two products for.
3. **Consent on autopilot** — checkout capture (classic + blocks) + STOP/START/HELP auto-replies logged into order chat, verified working end-to-end.
4. **Honest BYO-Twilio pricing** — "you pay Twilio directly, zero markup," attacking the credit-resale grievance head-on.
5. **Fail-open by design** — the SMS layer can never block checkout; license state never gates messaging.

---

## 3. StoreCanvas v1.2.0

### 3.1 Current-state scorecard

| Dimension | Score | Evidence |
|---|---|---|
| Functionality | **6/10** | Broad 15-type options engine + multi-view canvas (verified generating a composite at runtime), but **PNG-only** output, image-layer rotation dropped in the composite, and extra uploaded layers silently missing from print files. **[STATIC/RUNTIME]** |
| Security | **4/10** | Nonces on every nopriv endpoint, unguessable 32-char guest tokens, author-ownership checks (good) — but arbitrary-attachment IDOR via `layers[].attachment_id`, a broken no-op `esc()` enabling stored/DOM XSS, zero rate limiting, no decompression-bomb guard, and enumerable public print files. **[STATIC]** |
| Performance/scale | **4/10** | Synchronous GD compositing **inside checkout** with no max-pixel cap; queue loads up to 280 order objects per page view; `admin.css` enqueued on the storefront. **[STATIC]** |
| Code quality/tests | **5/10** | Consistent structure, several genuinely pure functions, but ~90-line duplicated composite path, inline JS/CSS in PHP, no tests. **[STATIC]** |
| UX/onboarding | **6/10** | Good admin guardrails (status line, visual print-area editor, "almost ready" warning) — but option groups are a raw JSON textarea and clipart is "paste an attachment ID." **[STATIC]** |
| i18n | **6/10** | PHP strings wrapped; ~15 hardcoded English strings in `customizer.js`; no `.pot`. **[STATIC]** |
| Docs | **3/10** | 35-line readme, changelog only — no install/FAQ/screenshots/shortcode docs. **[STATIC]** |

### 3.2 Competitor matrix

| Capability | **StoreCanvas** | Fancy Product Designer | Woo Product Add-Ons | Extra Product Options (TC) | Lumise | Customily | Zakeke |
|---|---|---|---|---|---|---|---|
| Live design canvas | ✅ | ✅ | ➖ | ➖ | ✅ | ✅ | ✅ 2D+3D |
| Multi-view | ✅ | ❔ | ➖ | ➖ | ❔ | ❔ | ❔ |
| Text/image/clipart layers | ✅ | ✅ | ⚠️ upload field | ⚠️ upload field | ✅ 120k clipart | ✅ | ✅ |
| Print-ready output + format | ⚠️ **PNG only** | ❔ (export service EOL) | ➖ | ➖ | ✅ **PDF/SVG/PNG/JSON** | ✅ print files | ✅ incl. engraving/laser |
| Options/pricing engine | ✅ | ⚠️ | ✅ | ✅ **deepest** | ✅ | ✅ | ⚠️ |
| Conditional logic | ⚠️ basic (1 parent, equality) | ❔ | ➖ none | ✅ full | ❔ | ❔ | ❔ |
| Guest design save | ✅ | ❔ | n/a | n/a | ❔ | ❔ | ❔ |
| Print queue / bulk ZIP | ✅ | ❔ | ➖ | ➖ | ❔ | ⚠️ | ⚠️ |
| Proof/approval email | ✅ | ❔ | ➖ | ➖ | ❔ | ❔ | ⚠️ |
| 3D preview | ➖ | ➖ (Chamevo SaaS) | ➖ | ➖ | ❔ | ❔ | ✅ 3D+AR |
| Self-hosted vs SaaS | **Self-hosted** | Self-hosted (**EOL**) | Self-hosted | Self-hosted | Self-hosted | **SaaS** | **SaaS** |
| Per-order fees | ➖ none | ➖ | ➖ | ➖ | ➖ | ✅ $0.10–1.00/item | ✅ 1.5–1.9% |
| Price | *TBD* | $99 one-time (EOL) | $79/yr | $129 one-time | **$69 one-time** | $49/mo + fees | from $29.90/mo + fees |

**Verified:** FPD 23,425 sales / 4.47★, now maintenance-only; Lumise 13,456 sales / 4.78★, actively updated, PDF/SVG/PNG/JSON output; EPO 36,551 sales / 4.87★ (options depth benchmark); Zakeke 2,000+ WP installs / 4.7★; Customily $49/mo + $0.10–1.00 per-item fees. **[WEB]**

### 3.3 Voice-of-the-Customer → opportunity

| Competitor complaint (with evidence) | Freq/Severity | Our response | Priority |
|---|---|---|---|
| Unresponsive/paywalled support (9+ sources, every product) | Critical | Human support SLA + public issue tracker as a differentiator | Next |
| Print-file quality — *"images limited to 72 DPI"*, *"dimensions completely wrong… PDF"* (FPD) | Critical | Ship true-to-size 300-DPI output + **PDF/SVG vector export**; write DPI (pHYs) into PNGs; add bleed/safe-area guides | **Now/Next** |
| SaaS per-order fees — *"nickel and dime"*, *"borderline crazy"* (Zakeke/Customily, 6 sources) | High | "One-time license, 0% of your sales, forever" positioning | Now |
| FPD vendor abandonment / forced SaaS migration (~23k licenses) | Critical market event | **One-click FPD importer** + "cannot be remotely sunset" pledge | Next |
| Slow rendering — *"20 minutes to render a file"* (Customily) | High | Move compositing off the checkout request into an async queue; publish benchmarks | **Now** |
| Mobile canvas broken (FPD/Zakeke, 4 sources) | High | Mobile-first touch canvas as a headline (bigger hit-targets, bottom-sheet tools) | Later |
| Security vulns / `shell_exec` (FPD, Lumise SQLi) | Critical (trust) | Security-audited, no shell_exec — **but first fix our own IDOR/XSS below** | **Now** |
| Setup complexity / learning curve (6 sources) | High | Onboarding wizard + prebuilt product templates (tee/mug/sticker/sign) | Next |

### 3.4 Gap analysis

- **We already do better:** production workflow (print queue, bulk ZIP, proof emails, guest save) — a staff-side toolset no verified self-hosted competitor advertises; and self-hosted + no per-order fees vs the SaaS players.
- **We lack vs leaders:** **vector/PDF print output** (Lumise's concrete advantage), **deep conditional logic + formula/lookup pricing** (EPO's benchmark), clipart scale, 3D preview (Zakeke), and POD integrations (Printful) that Zakeke/Customily use to win POD sellers.

### 3.5 Bugs & risks found (must-fix before charging)

1. **[SECURITY-HIGH] Arbitrary-attachment IDOR.** `sc_layers_json[].attachment_id`/`clipart_id` is composited with no ownership/type check (`class-sc-print-ready.php:668-694`) → any customer can pull *any* site attachment (other customers' art, media-library files) into their own print file and exfiltrate it via the proof email. **Sell-blocking.**
2. **[SECURITY-HIGH] Stored/DOM XSS via no-op `esc()`.** `customizer.js:311-317` maps every char to itself; saved-design layer id/label/content is injected into product-page DOM and auto-loads from an emailed `?sc_design=` link (`class-sc-designs.php:130-133` stores payload unsanitized). **Sell-blocking.**
3. **[SECURITY-HIGH] Enumerable print files.** Composites are registered as public `inherit` attachments → fetchable via `/wp-json/wp/v2/media` by any visitor (`class-sc-print-ready.php:430-453`). Customer artwork/privacy leak.
4. **[DoS-HIGH] No max-pixel guard + synchronous checkout compositing.** A decompression-bomb PNG OOMs/timeouts checkout (`class-sc-print-ready.php:73-84, 758-826`).
5. **[ABUSE-HIGH] Guest save: no rate limit / no payload-size cap** → `wp_options` flooding by any visitor (`class-sc-designs.php:122-165`); unauthenticated unthrottled email-to-any-address relay (`:245-284`).
6. **[FUNCTIONAL] Multi-image layers silently dropped from print files** (serialize as `blob:` URLs the server can't fetch) and **image rotation ignored in the composite** — print ≠ preview (`customizer.js:239-244,600-609`; `class-sc-print-ready.php:651-722`).
7. **[PRICING] `percent` uses parent price on variations; `qty` == `flat`; negative extras shown but not charged** (`class-sc-cart-order.php:317-342` vs `live-price.js`).
8. **[FUNCTIONAL] SVG advertised in the upload accept-list but always rejected downstream** — user-facing dead end.

### 3.6 Prioritized roadmap

**Now (0–1 mo) — security & correctness gate (do NOT charge until 1–5 are done)**
- **[CHANGE]** Authorize `attachment_id`/`clipart_id` layer references (restrict to the customer's own uploads + the plugin's clipart library) — `class-sc-print-ready.php`, `class-sc-cart-order.php`. *~2d. Fixes IDOR #1.*
- **[CHANGE]** Replace the broken `esc()` with a real HTML-escaper and sanitize stored design payloads — `customizer.js`, `class-sc-designs.php`. *~1d. Fixes XSS #2.*
- **[CHANGE]** Gate print-file access (private storage + capability-checked download proxy; don't register as public media) — `class-sc-print-ready.php`. *~2d. Fixes #3.*
- **[ADD]** Max-pixel/upload guards + move compositing to an Action Scheduler job off the checkout request — `class-sc-print-ready.php`, `class-sc-cart-order.php`. *~3d. Fixes DoS #4 and the "20-minute render" complaint.*
- **[ADD]** Rate-limit + payload-size cap on guest save and the email endpoint — `class-sc-designs.php`. *~1d. Fixes #5.*
- **[CHANGE]** Fix multi-image-layer print inclusion + apply rotation in the GD composite — `class-sc-print-ready.php`, `customizer.js`. *~2–3d. Fixes print≠preview #6.*

**Next (1–3 mo) — competitive parity**
- **[ADD]** **Vector/PDF + true-DPI print output** (PDF/SVG export, write pHYs DPI into PNG, bleed/safe-area export) — `class-sc-print-ready.php`, new export class. *~2wk. Closes the Lumise gap and the #1 print-quality complaint.*
- **[ADD]** **One-click Fancy Product Designer importer** (map FPD product/view JSON → StoreCanvas config) — new migration class. *~1–2wk. Captures ~23k stranded FPD licenses.*
- **[IMPROVE]** Deepen conditional logic (AND/OR, operators) + add formula/lookup pricing to approach EPO — `class-sc-product-options.php`, `class-sc-cart-order.php`. *~2wk.*
- **[ADD]** Onboarding wizard + prebuilt product templates (tee/mug/sticker/sign). *~1wk.*
- **[CHANGE]** Fix `percent`-on-variation base, `qty` pricing, negative-extra parity — `class-sc-cart-order.php`. *~2d.*

**Later (3–6 mo)**
- **[ADD]** Mobile-first canvas rework; **[ADD]** POD integration (Printful) for POD sellers; **[ADD]** curved text / image filters / undo-redo; **[IMPROVE]** Store API blocks integration so add-to-cart validation isn't bypassable.

### 3.7 Differentiation wedges (StoreCanvas)

1. **"Alive and self-hosted"** — the anti-FPD, anti-SaaS pitch: one-time license, 0% of sales, can't be sunset from a server you don't own.
2. **Staff production suite** — print queue + bulk ZIP + proof approval + guest save, which the SaaS players automate but the self-hosted rivals don't ship.
3. **One-click FPD migration** — a concrete switching path for a 23k-license installed base at exactly the moment it's stranded.
4. **True print-ready output** (once vector/PDF lands) — directly answers the loudest, most-refund-driving complaint in the category.
5. **No per-order fees, no cloud lock-in** — you own your artwork and pipeline.

---

## 4. OrderBay v1.1.0

### 4.1 Current-state scorecard

| Dimension | Score | Evidence |
|---|---|---|
| Functionality | **5/10** | All 8 document types are real print views (7 rendered cleanly at runtime) — but numbering is race-prone with no format/yearly-reset config, credit notes omit refund tax, invoices omit fee lines, and there is **zero template customization**. **[STATIC/RUNTIME]** |
| Security | **7/10** | Consistent caps+nonce+ownership discipline; customer-document access is login+nonce+ownership (**IDOR not exploitable**); no SQL surface; strong escaping of attacker-influenced checkout fields in documents. Docked: CSV formula injection, nonce-less partial-fulfillment save. **[STATIC]** |
| Performance/scale | **5/10** | Bounded bulk ops, good autoload discipline — but SLA cron stalls after 50 flagged orders, low-stock scans silently cap at 100–150 products, dashboard count fallback loads *all* order IDs. **[STATIC]** |
| Code quality/tests | **4/10** | Clean structure, but the **QR encoder is broken for real payloads** (below), no tests, heavy `echo` UI. Barcode is a genuine correct Code128B. **[STATIC/RUNTIME]** |
| UX/onboarding | **5/10** | Good safe-defaults messaging and dashboard nudges — but no document preview, `window.prompt()`-driven bulk inputs, duplicate order-screen panels. **[STATIC]** |
| i18n | **5/10** | Strings wrapped in the `orderbay` domain — but **no `load_plugin_textdomain()` call anywhere**, so custom `.mo` files never load. **[STATIC]** |
| Docs | **2/10** | 23-line readme, changelog only — no description/install/FAQ/screenshots. **[STATIC]** |

### 4.2 Competitor matrix

| Feature | **OrderBay** | WP Overnight (free+Pro) | Tyche Print Inv. | SkyVerge Print Inv. | Challan | YITH PDF Inv. | Flexible PDF Inv. |
|---|---|---|---|---|---|---|---|
| Invoice / packing slip | ✅ / ✅ | ✅ / ✅ free | ✅ / ✅ | ✅ / ✅ | ✅ / ✅ | ✅ / ✅ | ✅ / ➖ |
| Proforma | ✅ | ✅ Pro | ➖ | ➖ | ✅ Pro | ✅ | ✅ Pro |
| Credit note | ✅ | ✅ Pro (auto on refund) | ✅ | ➖ | ✅ Pro | ✅ (own seq) | ✅ |
| Delivery note | ✅ | ➖ | ✅ | ➖ | ✅ | ➖ | ➖ |
| Shipping label | ✅ | ➖ | ➖ | ➖ | ✅ | ➖ | ➖ |
| RMA docs | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ |
| Pick list | ✅ | ➖ | ➖ | ✅ multi-order | ➖ | ➖ | ➖ |
| PDF engine | Browser + opt. Dompdf/TCPDF | Dompdf | Browser | Browser | mPDF | undisclosed | undisclosed |
| Template designer | ⚠️ limited | HTML/CSS free; drag-drop (€99 bundle) | basic | live preview | Pro 10+ | Gutenberg builder | Gutenberg |
| Sequential numbering | ⚠️ basic (prefix+counter) | ✅ per-doc-type (Pro) | ❔ | ➖ | ✅ | ✅ **yearly reset/prefix/min-len** | ✅ + EU VAT |
| e-invoice / UBL | ➖ **gap** | ✅ **UBL/Peppol/Factur-X/ZUGFeRD free** | ➖ | ➖ | ZATCA/GST | Italian SDI | Polish KSeF |
| Bulk print/export | ✅ | ✅ Pro ZIP/cloud | ✅ | ✅ bulk print+email | ✅ ZIP/merged | ✅ table+CSV | ✅ |
| Tracking / fulfillment | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ |
| Returns/RMA flow | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ |
| Digests / SLA / dashboard | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ | ➖ |
| Pricing | *TBD* | Free + €69–279/yr | Free | $79/yr | Free + ~$29–49 | ~$79.99/yr | Free + Pro |

**Verified:** WP Overnight 300k+ installs, 5.0★/1,859, ships UBL/Peppol/Factur-X/ZUGFeRD **in the free plugin**, Dompdf engine with documented memory/CJK limits; the official woocommerce.com "PDF Invoices" is 2.4★ (48% 1★) and effectively abandoned; only WP Overnight covers pan-EU e-invoicing — everyone else is regional. **No competitor bundles documents + tracking + RMA + alerts + dashboard.** **[WEB]**

### 4.3 Voice-of-the-Customer → opportunity

| Competitor complaint (with evidence) | Freq/Severity | Our response | Priority |
|---|---|---|---|
| Free tiers produce **legally non-compliant** invoices (VAT-per-line, shop VAT number paywalled) | Critical | Make legal-minimum compliance (VAT lines, seller tax IDs, gapless numbering, credit notes) **free** | Next |
| **E-invoicing** unanswered — French Sept-2026 mandate question got 0 replies (Factur-X/UBL/PDP) | Critical / regulatory | Add Factur-X + UBL/Peppol export; compliance-checklist UI | **Next** |
| **Invoice numbering gaps/duplicates** — *"54 assigned… 3 seconds later reassigned 55"*; *"stuck at 1"* | Critical / legal | **Atomic DB-locked numbering** + gap-audit report; per-doc-type + yearly-reset formats | **Now** |
| PDF can't render Bengali/CJK/RTL — leader admits Dompdf *"beyond our control"* | High | Our browser-print path uses the OS font stack (no Dompdf font-subsetting trap) — **position this**; ship bundled Noto for the optional PDF engine | Now/Next |
| Major-version rewrites break stores (Print Invoice V7 revolt) | High | Template-stability pledge + versioned templates + theme-override support | Next |
| Template customization needs code / breaks on update | High | Theme-override lookup (`wc_locate_template`) + template hooks + a no-code header/logo/column editor | Next |
| Memory exhaustion on bulk print (leader's own docs) | Med/High | Our browser-print path sidesteps server rendering; document it as an advantage | Now |
| Add-on stacking resentment (Professional + Premium Templates, "requires 2 plugins") | High | One paid tier contains everything — "no add-on maze" | Now |
| Retroactive paywalling / dashboard nag-ads (zorem, Challan) | High | Free-forever guarantee + zero admin nags | Now |

### 4.4 Gap analysis

- **We already do better:** the **ops breadth** — tracking/fulfillment, RMA, SLA aging, staff digests, low-stock alerts, catalog bulk tools, and an ops dashboard — none of which the document-only leaders ship, and none of which the tracking/ops tools pair with documents. That integrated desk is the actual product.
- **We lack vs leaders:** **e-invoicing (UBL/Factur-X/XRechnung/Peppol)** — a hard, time-boxed regulatory gap; **gapless legally-safe numbering**; **template customization / theme overrides**; per-rate tax breakdown and correct credit-note tax; a scannable QR; and mature docs.

### 4.5 Bugs & risks found

1. **[BUG-CRITICAL] Duplicate/gap invoice numbers under concurrency.** `allocate_number()` is a non-atomic read-modify-write on an option; two parallel prints both read `5`, both write `6`, both return `INV-5`; the "race detection" is ineffective (`class-ob-invoicing.php:159-186`). RMA counter has the same race with no mitigation. **Legally significant for tax invoices.** Fix: `UPDATE … SET value = value+1` atomic allocation or a dedicated table with a unique constraint.
2. **[BUG-CRITICAL] QR codes unscannable for real payloads.** The encoder is a genuine attempt (correct Reed-Solomon/GF(256), correct data walk) but two placement bugs defeat it: mask-0 corrupts the V2/V3 alignment pattern (`class-ob-qr.php:402-440`), format-info copy B is bit-scrambled (`:459-464`), and payloads >42 bytes are silently truncated (`:126-128`). The My-Account order URL is ~47 chars → always V3 → **dead QR** (confirmed generating a 433-module V3 symbol at runtime; scannability is the code-read defect). Fix: add alignment-pattern awareness to the mask + correct copy-B bit order + auto-select version by payload, or swap in a vetted QR library.
3. **[BUG] Credit-note figures don't reconcile and omit tax** — net per-line vs gross total; refunded tax never itemized (`templates/credit-note.php:73,95`). Non-compliant as an EU/VAT credit note.
4. **[BUG] Invoice omits fee lines** — item table iterates products only; `get_fees()` never rendered, so totals don't sum for orders with fees (`templates/invoice.php:87-123`, same in proforma).
5. **[BUG] SLA aging permanently stalls after 50 flagged orders** — the 50-row window keeps re-selecting already-flagged orders with no meta exclusion (`class-ob-sla.php:129-151`).
6. **[BUG] Low-stock alerting silently incomplete >100/150 products** (no pagination); dashboard `count_orders` fallback loads all order IDs (`limit=-1`).
7. **[SECURITY] CSV formula injection** (`class-ob-export.php:155-169`); **[BUG]** `OB_Partial` save lacks its own nonce (`:76-103`).
8. **[BUG] HPOS-broken redirects** — four handlers fall back to `post.php?post={id}`, invalid for HPOS orders (`class-ob-invoicing.php:244`, `class-ob-rma.php:325`, `class-ob-notes.php:157`, `class-ob-partial.php:184`). Use `$order->get_edit_order_url()`.
9. **[BUG] "Today" uses UTC midnight not store timezone** (dashboard + digest); Dompdf output hardcodes `letter` ignoring A4; TCPDF can't render the templates' flexbox layout → mangled PDFs.

### 4.6 Prioritized roadmap

**Now (0–1 mo)**
- **[CHANGE]** Atomic, gapless invoice/credit/proforma/RMA numbering (DB-locked) — `class-ob-invoicing.php`, `class-ob-rma.php`. *~3d. Fixes bug #1/#2; a legal must before selling.*
- **[CHANGE]** Fix credit-note tax reconciliation + render invoice fee lines — `templates/credit-note.php`, `templates/invoice.php`, `templates/proforma.php`. *~2d.*
- **[CHANGE]** Fix SLA 50-order stall + paginate low-stock scans + dashboard count fallback — `class-ob-sla.php`, `class-ob-digest.php`, `class-ob-notifications.php`, `class-ob-dashboard.php`. *~2d.*
- **[CHANGE]** CSV formula-injection guard + `OB_Partial` nonce + HPOS redirect fixes — `class-ob-export.php`, `class-ob-partial.php`, four handlers. *~1.5d.*
- **[CHANGE]** Fix or replace the QR encoder (or default it off with a clear note until fixed) — `class-ob-qr.php`. *~2–3d.*
- **[ADD]** `load_plugin_textdomain()` + store-timezone "today" — `orderbay.php`, dashboard/digest. *~0.5d.*

**Next (1–3 mo)**
- **[ADD]** **E-invoicing: Factur-X (PDF/A-3 + embedded XML) and UBL/Peppol export**, plus a compliance-checklist UI — new e-invoice class, template pipeline. *~3–4wk. Directly answers the regulatory gap and the market leader's own free-tier strength.*
- **[ADD]** Configurable numbering formats (`{YYYY}`, zero-padding, yearly reset, per-doc-type sequences) — `class-ob-invoicing.php`. *~1wk.*
- **[ADD]** Template customization: theme-override lookup + template hooks + no-code logo/columns/header editor — `class-ob-documents.php`, templates. *~2wk.*
- **[IMPROVE]** Per-rate tax breakdown on invoices (`get_tax_totals()`), inclusive/exclusive labeling — templates. *~3d.*

**Later (3–6 mo)**
- **[ADD]** XRechnung profile + Peppol network delivery; **[ADD]** item-level RMA + refund linkage + customer RMA status emails; **[IMPROVE]** replace `window.prompt()` bulk inputs with proper modals + media picker for logo; **[ADD]** cloud/PrintNode auto-print parity.

### 4.7 Differentiation wedges (OrderBay)

1. **The whole fulfillment desk in one plugin** — documents + tracking + RMA + SLA + digests + alerts + dashboard, which no competitor bundles.
2. **Legal-minimum compliance is free** — VAT-per-line, seller tax IDs, gapless numbering, credit notes not paywalled (once §4.6 lands), attacking the loudest invoice-plugin grievance.
3. **No add-on maze, no nag-ads, free-forever guarantee** — the anti-zorem/anti-WP-Overnight-stack positioning.
4. **Print-first PDF that dodges Dompdf's font/RTL/memory traps** — browser rendering uses the OS font stack; bundle Noto for the optional server engine.
5. **EU e-invoicing without a second subscription** — once Factur-X/UBL ship, match the leader's headline feature inside one tier at the exact moment the French/German mandates bite.

---

## 5. Go-to-market

### 5.1 Naming (resolve the "Twilio" trademark risk)

**[WEB]** Twilio's trademark policy explicitly says *"Don't use Twilio Trademarks as your own product or service name"* and prohibits composite marks. **"Twilio Order Communicator" leads with the Twilio mark and reads as a composite** — squarely against the guidelines. Compliant competitors use relational naming ("Twilio SMS Notifications *for WooCommerce*", "ShopMagic – Twilio SMS").

- **Recommended (done):** **OrderRing** — distinct brand; subtitle “SMS & Voice for WooCommerce (for Twilio).” See [`docs/launch/NAMING.md`](./launch/NAMING.md). Keep “Bring your own Twilio account”; required attribution line is in the plugin admin footer and readme. Consider emailing trademark@twilio.com for written permission before paid ads.
- StoreCanvas and OrderBay are already clean, distinct brands — no trademark exposure.

### 5.2 Pricing & packaging

Anchored to verified category norms (**[WEB]**): SMS plugins $49–99/yr single-site with common free tiers; product designers $69–129 one-time (Lumise $69, EPO $129) with SaaS at $29–199/mo + fees; invoice plugins free-core + €69–99/yr single-site.

| Product | Free / Lite (wordpress.org reach) | Pro (single site) | Agency / multi-site |
|---|---|---|---|
| **TOC (renamed)** | SMS + 1 status + consent checkbox + STOP | **$69/yr**: voice, two-way chat, reminders, CSV, alerts, roles | $149/yr (5 sites) |
| **StoreCanvas** | *(optional)* basic single-view canvas + PNG | **$79/yr or $129 one-time**: multi-view, vector/PDF, queue, proof, FPD importer | $249 one-time (5 sites) |
| **OrderBay** | invoice + packing slip + gapless numbering (legal minimum) | **$79/yr**: all 8 docs, e-invoice, tracking, RMA, SLA, digests | $199/yr (5 sites) |

Guardrails baked into pricing (each answers a mined grievance): **free-forever guarantee** (never claw back a shipped free feature), **one tier contains everything** (no add-on maze), **public 30-day money-back**, **zero admin nag-ads**, and — for TOC — **"you pay Twilio directly, 0% markup."**

### 5.3 Launch checklist

- [ ] **License server → production HTTPS host** — runbook in [`docs/launch/LICENSE-SERVER-DEPLOY.md`](./launch/LICENSE-SERVER-DEPLOY.md). Local smoke is done (`docs/RUNTIME-VERIFICATION.md` A1–A5); a public DNS name is still required.
- [x] Rename TOC to **OrderRing** + Twilio attribution notice (`docs/launch/NAMING.md`). Register the brand domain separately.
- [x] Marketing copy per product in [`docs/launch/site/`](./launch/site/) (host on a real domain). Screenshots + 60–90s demos still outstanding.
- [x] Complete each `readme.txt` (StoreCanvas + OrderBay description/install/FAQ).
- [x] Twilio cost / A2P disclaimer (OrderRing), print-fidelity disclaimer (StoreCanvas), tax/legal + export-only e-invoice (OrderBay).
- [ ] Tag releases + register zips with `license-server/bin/add-release.php` (after production host is live). `tools/build-release.sh` cuts the zips.

---

## 6. Compliance & risk

- **TOC — SMS consent / TCPA / GDPR:** consent capture (classic + block checkout) + STOP/START/HELP + quiet hours are implemented and verified. **Fix before launch:** the consent-audit IP is spoofable (§2.5 #5) — store a server-derived IP; add A2P 10DLC registration guidance in-product (a repeated US pain point); document Twilio-as-processor in the privacy helper (already present). Voice calls: respect quiet hours (they do) and consent.
- **StoreCanvas — print/output fidelity & data:** print ≠ preview today (rotation dropped, multi-image layers missing, PNG-only) is both a quality and a *refund/chargeback* risk — fix before charging (§3.5). Guest-artwork privacy (enumerable print files, IDOR) is a GDPR exposure — fix before launch. Add a color/DPI fidelity disclaimer (raster PNG isn't CMYK).
- **OrderBay — invoice/tax/PDF correctness:** gapless sequential numbering is a statutory requirement in most of the EU — the concurrency race (§4.5 #1) is the top legal risk and a Now-item. Credit-note tax must reconcile (#3). E-invoicing mandates (France Sept 2026, Germany phased) make the UBL/Factur-X gap a time-boxed compliance risk, not just a feature gap. Add a "consult your accountant / not tax advice" disclaimer.

---

## 7. Testing & quality plan

**The absence of tests + CI is the through-line behind the "updates break my store" complaints in every category.** Ship stability as a feature.

**Immediate:** add `.github/workflows/ci.yml` running `php -l` (all files) + `composer run lint` (PHPCS) on push/PR — the brief describes this but it is **not in the tree**. Gate merges on `php -l` (hard) and treat PHPCS as advisory until the style debt is paid down (163 Yoda + 114 docblock findings are noise, not risk).

**First PHPUnit suite — reuse the pure logic already in the codebase** (highest value, lowest effort; each also pins a bug fixed above):

*TOC:*
1. `TOC_Logger::normalize_phone()` — pins the non-NANP mangling fix (bug #4).
2. `TOC_Twilio::validate_twilio_signature()` via superglobal fixtures — the single highest-value security test (runtime-proven correct; lock it).
3. `TOC_Twilio::is_truthy_consent()` / `is_falsy_consent()` — consent truthiness table.
4. `TOC_Admin_Dashboard::csv_escape_field()` — where the formula-injection fix lands (bug #3).
5. `TOC_Twilio::merge_tags()` — merge-tag substitution.
6. `TOC_License_Helpers::sign_download()` / `verify_download()` — HMAC license signing.

*StoreCanvas:*
7. `SC_Cart_Order::field_price()` (make testable) — pricing core; pins the percent/qty/negative fixes.
8. `SC_Product_Options::sanitize_field_row()` + `field_is_visible()` — options + conditional-logic truth table.
9. `SC_Print_Ready::check_placement_bleed()` / `scale_text_for_print()` — pure geometry / print≈preview contract.

*OrderBay:*
10. `OB_Invoicing::allocate_number()` (with an options fake) — **the test that would have caught the numbering race** (bug #1).
11. `OB_Barcode::code128_svg()` — verify checksum/patterns (runtime-proven correct).
12. `OB_QR` RS stack + placement via a decoder round-trip — **would have caught the unscannable-QR bugs** (bug #2).
13. `OB_Fulfillment::sanitize_url_template()` — `{tracking}` preservation.

**Integration smoke (WP-CLI, reusing this review's harness):** activate each plugin; TOC order→Ready writes notes + signed-STOP webhook flips opt-out; StoreCanvas composite generates; OrderBay renders all 8 documents + assigns a gapless number. These already pass today except the OrderBay numbering race and QR scannability — wire them into CI so regressions surface before release.

---

## 8. Sources appendix

All competitor facts and review quotes were verified from these primary sources (August 2026). Items that could not be fetched are flagged in the body as *unverified*; Reddit/G2/Trustpilot were blocked to the research crawler and are noted where used.

### TOC — SMS/voice competitors & reviews
- WP SMS/WSMS: https://wordpress.org/plugins/wp-sms/ · https://wordpress.org/plugins/wp-sms/advanced/ · https://wpsms.io/pricing/ · reviews https://wordpress.org/support/plugin/wp-sms/reviews/?filter=1 · https://wordpress.org/support/topic/bait-switch-11/ · https://wordpress.org/support/topic/sms-wont-send/ · https://wordpress.org/support/topic/misses-the-simple-stuff/ · https://wordpress.org/support/topic/useless-in-the-us/ · https://wordpress.org/support/topic/woocommerce-issue-after-last-update/ · https://wordpress.org/support/topic/whatsapp-otp-via-meta-cloud-api-failing-url-button-parameter-error-131008/
- YITH SMS: https://yithemes.com/themes/plugins/yith-woocommerce-sms-notifications/ · https://yithemes.com/refund-policy/
- SkyVerge Twilio SMS: https://woocommerce.com/products/twilio-sms-notifications/ · https://woocommerce.com/document/twilio-sms-notifications/ · https://www.skyverge.com/blog/woocommerce-checkout-block-support-in-twilio-sms-notifications/
- NotifSMS/WP Twilio Core: https://wordpress.org/plugins/wp-twilio-core/ · https://wordpress.org/support/plugin/wp-twilio-core/reviews/ · https://wordpress.org/support/topic/no-support-or-refund/
- ShopMagic Twilio: https://wordpress.org/plugins/shopmagic-for-twilio/ · Flow Notify: https://flownotify.com/pricing/ · SMS Alert: https://wordpress.org/plugins/sms-alert/ · https://wordpress.org/support/topic/frustrated-lost-orders/ · https://wordpress.org/support/topic/trial-10/
- Orderable: https://orderable.com/pricing/ · https://wordpress.org/plugins/orderable/ · Local Pickup Plus: https://woocommerce.com/products/local-pickup-plus/
- Joy of Text (closed): https://wordpress.org/plugins/joy-of-text/ · Ultimate WP SMS (closed): https://wordpress.org/plugins/ultimate-wp-sms/
- Omnisend: https://wordpress.org/plugins/omnisend/ · https://www.omnisend.com/pricing/ · Klaviyo: https://wordpress.org/plugins/klaviyo/
- Zorem pickup SMS demand: https://docs.zorem.com/docs/sms-for-woocommerce/compatibility/advanced-local-pickup/ · Shopify community: https://community.shopify.com/t/how-can-i-bulk-notify-customers-about-ready-for-pickup-orders/80671
- Twilio trademark policy: https://www.twilio.com/en-us/legal/trademark · Twilio order-notifications glossary: https://www.twilio.com/docs/glossary/order-notifications

### StoreCanvas — product-designer competitors & reviews
- Fancy Product Designer: https://codecanyon.net/item/fancy-product-designerwoocommercewordpress/6318393 · reviews https://codecanyon.net/item/fancy-product-designer-woocommercewordpress/reviews/6318393 · comments https://codecanyon.net/item/fancy-product-designer-woocommercewordpress/6318393/comments · Shopify https://apps.shopify.com/fancy-product-design/reviews · EOL/Chamevo: https://chamevo.com/blog/the-future-of-fancy-product-designer-your-path-to-chamevo/ · vuln history: https://www.bleepingcomputer.com/news/security/unpatched-critical-flaws-impact-fancy-product-designer-wordpress-plugin/
- Lumise: https://codecanyon.net/item/lumise-product-designer-woocommerce-wordpress/21222684 · comments .../21222684/comments · Shopify https://apps.shopify.com/lumise-product-design-tool/reviews
- Woo Product Add-Ons: https://woocommerce.com/products/product-add-ons/ · conflict thread https://wordpress.org/support/topic/possible-conflict-with-woocommerce-product-add-ons-plugin/
- Extra Product Options (ThemeComplete): https://codecanyon.net/item/woocommerce-extra-product-options/7908619 · comments .../7908619/comments · roundup https://barn2.com/blog/best-woocommerce-product-options-plugins/
- Zakeke: https://wordpress.org/plugins/zakeke-interactive-product-designer/ · Shopify https://apps.shopify.com/zakeke-interactive-product-designer/reviews · Capterra https://www.capterra.com/p/162400/Zakeke/reviews/ · GetApp https://www.getapp.co.uk/software/2046756/zakeke-1 · pricing analysis https://fibbl.com/zakeke-pricing/
- Customily: https://www.customily.com/pricing · https://www.customily.com/post/customily-is-now-available-for-woocommerce · Shopify reviews https://apps.shopify.com/customily-product-personalizer/reviews
- PixMagix: https://wordpress.org/plugins/pixmagix/ · CodeCanyon designer category: https://codecanyon.net/category/wordpress/ecommerce/woocommerce?term=product%20designer

### OrderBay — invoice/ops competitors & reviews
- WP Overnight PDF Invoices & Packing Slips: https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/ · Professional https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-professional/ · Premium Templates https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-premium-templates/ · memory docs https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/solving-memory-issues/ · fonts docs https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/fixing-fonts-issues/
- Reviews/threads: https://wordpress.org/support/plugin/woocommerce-pdf-invoices-packing-slips/reviews/?filter=1 · https://wordpress.org/support/topic/unicode-font-not-supported/ · https://wordpress.org/support/topic/asian-characters-fail-to-render/ · https://wordpress.org/support/topic/invoice-numbers-occasionally-skipped-and-reassigned/ · https://wordpress.org/support/topic/compliance-with-french-b2b-e-invoicing-regulations/ · https://wordpress.org/support/topic/factur-x-format/ · https://wordpress.org/support/topic/free-plugin-is-worthless/ · https://wordpress.org/support/topic/facture-non-conforme-au-droit-francais-en-version-gratuite/
- Tyche Print Invoice & Delivery Notes: https://wordpress.org/plugins/woocommerce-delivery-notes/ · V7 revolt https://wordpress.org/support/topic/the-v7-update-is-terrible/ · https://wordpress.org/support/topic/overriding-issues-with-v7/
- Official marketplace: https://woocommerce.com/products/pdf-invoices/ (2.4★) · https://woocommerce.com/products/print-invoices-packing-lists/
- Challan: https://wordpress.org/plugins/webappick-pdf-invoice-for-woocommerce/ · YITH PDF Invoice: https://yithemes.com/themes/plugins/yith-woocommerce-pdf-invoice/ · https://wordpress.org/support/plugin/yith-woocommerce-pdf-invoice/reviews/
- Flexible PDF Invoices (WP Desk): https://wordpress.org/plugins/flexible-invoices/ · https://wordpress.org/support/topic/far-too-many-add-on-plugins-required/
- Advanced Shipment Tracking (zorem): https://wordpress.org/plugins/woo-advanced-shipment-tracking/ · bait-and-switch https://wordpress.org/support/topic/bait-and-switch-cant-add-custom-providers-anymore/ · https://wordpress.org/support/topic/shipped-order-mail-body-text-not-editable/
- Fulfillment/returns: https://woocommerce.com/products/shipstation-integration/ · https://www.shipstation.com/pricing/ · https://woocommerce.com/products/shipment-tracking/ · https://wordpress.org/plugins/woo-refund-and-exchange-lite/ · https://yithemes.com/themes/plugins/yith-advanced-refund-system-for-woocommerce/

**Full per-agent research dossiers** (benchmarks + Voice-of-Customer tables with every quote) were produced during this review and are available on request; this report synthesizes and prioritizes them.

---

*Prepared from a live WordPress 7.0.3 + WooCommerce 11.0.1 + PHP 8.4.19 runtime, `php -l` + PHPCS static analysis, and live web research. Runtime-verified findings are labeled [RUNTIME]; everything else is [STATIC] or [WEB]-sourced with URLs above.*
