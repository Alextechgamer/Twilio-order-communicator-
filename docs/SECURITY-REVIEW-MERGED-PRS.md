# Security Review of Merged PRs — Findings Report

**Scope:** All 48 merged PRs (#1–#50, excluding missing numbers), reviewed at each merge commit.  
**Method:** Detached checkout of each merge SHA; diff-based triage + deep read of auth, webhooks, uploads, exports, license-server, and public endpoints.  
**No code was changed.**

---

## Executive summary

Several real issues exist across Twilio Order Communicator (TOC), StoreCanvas (SC), OrderBay (OB), and the license-server. The most serious open issues at tip (`#50` / `bcbb77b`) are:

1. **StoreCanvas customer artwork remains publicly fetchable by direct uploads URL** (REST listing was only partially mitigated in #46; no private download proxy).
2. **License-server `/v1/update-check` can mint signed package URLs with only a license key** (site activation optional).
3. **StoreCanvas guest “email me a link” is an unauthenticated mail-sending endpoint** (abuse / spam / token leak in JSON).
4. **TOC role matrix + lowered `options.php` capability** lets any `toc_manage` holder rewrite Twilio secrets and grant caps to low-privilege roles.

Historical issues introduced then later mitigated are also documented (TwiML replay, YES→START, REST media enumeration, license query-string keys, exception detail leak, weak download secret default).

---

## Severity legend

| Severity | Meaning |
|----------|---------|
| **Critical** | Unauthenticated or trivial remote exploit with high impact |
| **High** | Significant data leak or privilege abuse with realistic path |
| **Medium** | Real vulnerability; requires some privilege, misconfig, or stolen secret |
| **Low** | Defense-in-depth / limited impact / admin-only hygiene |
| **Info** | Expected tradeoff or residual risk after a fix |

---

## Findings that remain at tip (after #50)

### H1 — StoreCanvas customer artwork publicly accessible via uploads URL
- **Severity:** High  
- **Introduced:** #27 (print composites / uploads into media library); present through tip  
- **Partial mitigation:** #46 adds `filter_rest_media_query` + `_sc_uploaded` / `_sc_generated` meta to hide new files from `/wp-json/wp/v2/media` for anonymous users  
- **Residual:** Files still live under `wp-content/uploads/…` with normal public URLs. Anyone with a URL (order email, referrer, guessed path, shared link, CDN logs) can fetch customer artwork. Comments in #46 explicitly call this incomplete.  
- **Gap:** Attachments created **before #46** never received `_sc_uploaded` / `_sc_generated` (no backfill), so they may still appear in REST media listings.  
- **Impact:** Confidential customer designs / logos exposed.  
- **PRs:** #27–#45 (full REST enum), #46–#50 (REST mitigated; direct URL remains).

### H2 — License-server update-check does not require site activation
- **Severity:** High (given a leaked/shared license key)  
- **Introduced:** #7; still present after hardening in #47  
- **Details:** `update_check()` only enforces `find_activation` when both `site_url` and `instance_id` are non-empty. Omitting them still returns a signed `/v1/download` URL for the latest zip.  
- **Impact:** Stolen license key → unlimited package redistribution for the TTL window (default 1h), without binding to an activated site.  
- **PRs:** #7 (intro), #47 (rate limit / no query-key / secret required — does **not** close this), #50 (still open).

### H3 — StoreCanvas guest design email endpoint (mail abuse + token disclosure)
- **Severity:** High  
- **Introduced:** #30; still at tip  
- **Details:** `wp_ajax_nopriv_sc_email_design_link` accepts arbitrary `email`, sends mail from the store identity, and returns `{ url: …sc_design=<token>… }` in the JSON response. No rate limit. Nonce is a normal product-page nonce (available to any visitor).  
- **Impact:** Spam/phishing relay; design-token leakage; guest design payload disclosure if token known.  
- **Related:** Guest design tokens in query strings (`sc_design`) leak via Referer/logs/history.  
- **PRs:** #30–#50.

### H4 — TOC capability model enables privilege expansion to Twilio secrets / PII exports
- **Severity:** High (multi-user stores)  
- **Introduced:** #21 (role matrix granting `toc_manage` / `toc_send` to any editable role); amplified by #46 (`option_page_capability_toc_settings` → `TOC_Caps::manage()` so settings save no longer needs `manage_options`); onboarding AJAX (#4, later `TOC_Caps::manage`) can write `toc_auth_token`  
- **Details:** A user with `toc_manage` (default: shop_manager) can:
  - Grant `toc_manage` / `toc_send` to `subscriber` (or other editable roles) via the matrix — persistence / backdoor within plugin blast radius  
  - Read/change Twilio Account SID / Auth Token / From number  
  - Export communications CSV (phones + full message bodies)  
  - Activate/deactivate licenses; view license customer email in UI state  
- **Impact:** Credential theft, SMS abuse on store’s Twilio account, mass PII export.  
- **PRs:** #21, #46 (and tip).

### M1 — TwiML / voice message content was replayable until #49
- **Severity:** Medium (historical); **Info/fixed at tip**  
- **Introduced:** #1 — 32-char token in URL, transient TTL 15 minutes, **not** deleted on first fetch  
- **Fixed:** #49 deletes transient on first successful read (“single-use”)  
- **Impact while open:** Anyone capturing the Twilio request URL (proxy logs, Referer, browser) could re-fetch TwiML containing customer name / order number for up to 15 minutes.

### M2 — License-server historical weaknesses (mostly fixed in #47)
| Issue | Introduced | Fixed | Residual |
|-------|------------|-------|----------|
| Exception `detail` = `$e->getMessage()` | #7 | #47 | None at tip |
| `?license_key=` accepted (access-log leak) | #7 | #47 | None at tip |
| `download_secret` fallback `'toc-download'` | #7 | #47 throws if unset/default | Misconfigured old deploys |
| No rate limit on activate/validate | #7 | #47 (60/hour/IP) | Still coarse; distributed bypass |
| No `.htaccess` on `data/` / `storage/` | #7 | #47 (Apache only) | **Nginx/mis-set docroot can still expose SQLite/zips** |

### M3 — Inbound SMS phone→order matching can attach messages to the wrong order
- **Severity:** Medium (integrity / PII cross-contamination)  
- **Introduced:** #1 (last-10 among recent orders); worsened #6 (SQL `LIKE '%last4'`)  
- **Improved:** #15 prefers full last-10 needle and lower limits — still best-effort  
- **Impact:** STOP/START/consent and inbound notes applied to wrong order; potential privacy mix-up between customers sharing last digits / formatting variance.

### M4 — TOC AJAX SMS/call accepts arbitrary `phone` from POST
- **Severity:** Medium (staff abuse / compromised staff session)  
- **Introduced:** #1; still at tip  
- **Details:** `ajax_sms` / `ajax_call` use client-supplied `phone`, not the order’s billing phone. With `force`, consent/opt-out can be bypassed after UI confirm.  
- **Impact:** Staff with `toc_send` can SMS/call any number on the store Twilio account and attribute it to an order_id.

### M5 — StoreCanvas journey logger + guest design save (DoS / storage abuse)
- **Severity:** Medium  
- **Introduced:** #28 (journey, enabled by default), #30 (guest save)  
- **Details:** Unauthenticated `sc_journey_log` inserts DB rows (nonce from product page, no rate limit). Logged-in users can `wp_insert_post` designs without an explicit capability check beyond being logged in. Guests can fill transients.  
- **Impact:** DB/options growth; noisy analytics with session identifiers.

### M6 — StoreCanvas export by attachment ID (staff IDOR-ish)
- **Severity:** Medium / Low  
- **Introduced:** #48  
- **Details:** `sc_export_svg` / `sc_export_pdf` authorize with `edit_shop_orders` + nonce for `sc_export_{att}` but do **not** verify the attachment is a StoreCanvas composite for an accessible order. A shop manager can export any readable attachment ID.  
- **Impact:** Broader media access than intended for print export.

### M7 — FPD import SSRF via `media_sideload_image`
- **Severity:** Medium (requires `edit_product`)  
- **Introduced:** #49  
- **Details:** Admin-pasted FPD JSON view image URLs are fetched server-side. Classic WP sideload SSRF to internal network if the host can reach it.  
- **Impact:** Internal port scan / metadata service access from a compromised product editor.

### M8 — Consent IP stored from `X-Forwarded-For` without trusted-proxy validation
- **Severity:** Low–Medium (audit integrity)  
- **Introduced:** #4  
- **Details:** `_toc_sms_consent_ip` prefers first XFF hop. Spoofable without a trusted proxy config.  
- **Impact:** Unreliable consent audit trail (compliance), not direct remote RCE.

### L1 — Auth tokens / license keys in plaintext `wp_options`
- **Severity:** Low (industry-common for WP; still a data-leak risk via DB backups, XSS→options, SQL dumps)  
- **PRs:** #1 (Twilio token), #7 (license key).

### L2 — Customer email returned in license activate/validate payload
- **Severity:** Low  
- **PR:** #7 — expected for licensee UI; amplifies impact of key theft.

### L3 — Bulk ZIP fallback lists absolute server paths
- **Severity:** Low (admin-only)  
- **PR:** #29 — when ZipArchive missing.

### L4 — Release zips historically tracked in git (#3–#22 era)
- **Severity:** Info / Low  
- **Fixed hygiene:** #45 stops tracking zips. No live Twilio secrets found in example configs; placeholder `change-me-to-a-long-random-string` only.

---

## Historical issues introduced then fixed (by later PRs)

| Issue | Introduced | Fixed | Notes |
|-------|------------|-------|-------|
| START keywords included `YES` (accidental re-opt-in) | #1 | #3 | Compliance / consent integrity |
| TwiML token replay | #1 | #49 | Single-use delete |
| License exception detail leak | #7 | #47 | |
| License key in query string | #7 | #47 | |
| Weak download secret default | #7 | #47 | |
| REST media enumeration of SC artwork | #27–#45 | #46 (partial) | Direct URL still open — see H1 |
| Phone LIKE last-4 too broad | #6 | #15 (partial) | Still fuzzy matching |
| Nested license option payload growth | #7 | #11 | Availability / options bloat |

---

## PR-by-PR review notes (security-relevant)

| PR | Title (short) | Security-relevant outcome |
|----|---------------|---------------------------|
| **#1** | Import TOC 1.3.0 | Baseline: signed webhooks OK; plaintext Twilio token; TwiML replay; YES keyword; arbitrary AJAX phone; last-10 phone match |
| **#2** | Product analysis docs | Docs only — no code risk |
| **#3** | v1.4.0 P0 cleanup | **Fixes YES→START**; opt-out table; i18n/escaping polish |
| **#4** | Checkout consent, quiet hours, wizard | Stores consent IP (XFF); onboarding AJAX can write auth token; AJAX capped |
| **#5** | Status auto-notify | Status hooks; no new public surface |
| **#6** | Hardening 1.7.0 | REST webhooks (`permission_callback` true + Twilio sig — OK); Caps filters; **broader phone LIKE last-4** |
| **#7** | License client + license-server | **New attack surface:** update-check without site bind; exception detail; query license key; weak secret default; returns customer_email |
| **#8** | AGENTS.md | Docs only |
| **#9–#10, #13, #16, #18, #22** | Audit notes / release zips | Packaging; zips in repo until #45 |
| **#11** | G1–G4 fixes | License data nesting / cron / update cache — integrity, not new vulns |
| **#15** | G5–G8 | Phone match tightening; REST URL building — improvements |
| **#17** | Scheduled reminders | Cron/AS — admin settings only |
| **#19** | CSV export + collected | Cap+nonce guarded; exports PII by design for managers |
| **#20** | Delivery failure emails | SID dedupe; `is_email` on override — OK |
| **#21** | Role permissions UI | **Role matrix privilege expansion** (H4) |
| **#23** | Polly + emails | Template merge tags; admin-only |
| **#24–#26** | SC scaffold → multi-view | Admin product config; low public risk |
| **#27** | Print-ready + composites | **Uploads customer art to public media** (H1 starts) |
| **#28** | Journey, layers, saved designs (logged-in) | Journey nopriv logger (M5) |
| **#29** | Bulk ZIP, proof email | Admin ZIP; path leak fallback (L3) |
| **#30** | Text layers, clipart, **guest designs** | **H3 email/token**; nopriv library list (intentional) |
| **#31–#33** | Live price, 1.0, 1.1 | Polish; production queue admin |
| **#34–#38** | OrderBay scaffold→docs/RMA | Admin print endpoints nonce+cap; customer invoice owner checks look sound |
| **#39** | Customer RMA, barcodes | Customer RMA nonce+ownership; SVG barcode from encoder (admin/customer doc context) |
| **#40** | SMS consent yes fix, emails | Consent truthiness; low risk |
| **#41** | Tracking URLs, customer packing | Packing default off; ownership reuse; `esc_url` on track links |
| **#42** | OB 1.0 polish / uninstall | Cleanup |
| **#43** | SC options roles/variations/stock | Role gates enforced server-side on cart — good |
| **#44** | Proforma/labels/QR/PDF | QR/SVG echo from encoder; admin docs |
| **#45** | Stop tracking release zips | Hygiene win |
| **#46** | Now-tier competitive fixes | REST media filter (partial H1 fix); **options.php cap lowered** (H4 amp); TOC constants for secrets |
| **#47** | License-server hardening + e-invoice | Rate limit; no query key; secret required; htaccess; **does not fix H2** |
| **#48** | SC DPI/SVG/PDF export; TOC phone | Export att IDOR-ish (M6); intl phone |
| **#49** | OB templates/tax; SC FPD; TOC analytics/replay | **Fixes TwiML replay**; template `basename` traversal-safe; FPD SSRF (M7) |
| **#50** | i18n, tracking tags, SC templates, QR capacity | Tracking merge tags from meta; QR no longer truncates silently; no new Critical |

---

## Areas reviewed and found acceptably guarded

- Twilio webhook signature validation (`hash_equals`, reject if no token) — #1 onward  
- TOC admin AJAX: nonce + capability (evolved to `TOC_Caps`)  
- OrderBay admin document/print/export/RMA admin routes: `edit_shop_orders` / `manage_woocommerce` + nonces  
- OrderBay customer invoice/packing/RMA: login + ownership (`customer_id` / careful billing-email rules) + per-order nonces  
- OrderBay theme template override: `basename()` strip — #49  
- TOC CSV export: manage cap + admin referer  
- License download path: `basename(package_path)` under configured releases dir  
- StoreCanvas clipart listing: public by design; nonce required  
- SC role-restricted product options: enforced in cart validation/pricing (#43)

---

## Recommended fix priority (for a future execution phase — not done here)

1. **H1:** Serve SC artwork/composites only via capability-checked download proxy; deny direct uploads URL; backfill `_sc_*` meta on existing attachments.  
2. **H2:** Require activated `site_url` + `instance_id` on `/v1/update-check` before issuing signed download URLs; bind signature to license/site.  
3. **H3:** Rate-limit + CAPTCHA guest email; do not return raw design URL/token in JSON; consider signed short-lived links.  
4. **H4:** Restrict who can edit the role matrix (`manage_options` only); prevent granting `toc_manage` to roles lacking `manage_woocommerce` / `edit_shop_orders`; keep Twilio secret writes at `manage_options` or constant-only.  
5. Tighten SMS AJAX to order billing phone (or explicit allow-list); finish phone-match determinism; nginx deny rules for license-server `data/` + `storage/`.

---

## Testing performed

- Enumerated all merged PRs via `gh pr list --state merged`  
- Checked out each merge commit (detached HEAD)  
- Generated per-PR PHP/JS diffs under `/tmp/pr-sec-review/`  
- Manually read license-server, TOC webhooks/TwiML/AJAX/caps/export/checkout, SC designs/journey/uploads/export/FPD, OB invoice/RMA/export/templates  
- Confirmed tip (`bcbb77b`) still exhibits H1 residual, H2, H3, H4  

**No application runtime exploit attempts were run** (no payload crafting against live services), per environment constraints and review-only request.
