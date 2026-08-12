# Runtime verification — plugin suite against a real WordPress/WooCommerce install

**Branch:** `cursor/runtime-verification` (cut from `claude/security-review-batch5`, which
holds the Batch-5 security fixes — they are not on `main` yet).
**Date:** 2026-08-12.

Every prior batch of this project was written without a WordPress/WooCommerce/MySQL/Twilio
runtime. This document records what was **actually run** on a live install, with the
commands used and the observed output. Every item is one of:

- **PASS** — exercised on the live runtime, observed behaving as specified.
- **FAIL → fixed** — observed broken, fixed in this branch, re-run, now passing.
- **BLOCKED** — could not be exercised in this sandbox, with the concrete reason.

Nothing below is assumed or inferred from code alone.

## Environment

| Component | Detail |
|-----------|--------|
| OS / PHP | Ubuntu 24.04, PHP 8.3.6 (CLI + `php -S` dev server), `php8.3-sqlite3` installed for the license server |
| WordPress | 6.x at `~/wordpress`, `http://localhost:8080`, admin `admin`/`admin` |
| WooCommerce | 11.0.1, HPOS **disabled** by default (E3 toggles it) |
| Database | MariaDB 10.11 on `127.0.0.1` |
| Plugins | `twilio-order-communicator/`, `storecanvas/`, `orderbay/` symlinked from the repo into `wp-content/plugins/` and activated |
| Twilio | No live credentials (bring-your-own). Outbound Twilio HTTP captured/mocked via a `pre_http_request` mu-plugin; payloads asserted instead of live delivery |
| Mail | No sendmail. `wp_mail` captured via a `pre_wp_mail` mu-plugin (recipient/subject/attachments logged) |

Reproduce with `tools/dev/setup-wp.sh` (see the "Local development" section of the root README).

## Verdict matrix

| # | Item | Verdict |
|---|------|---------|
| A1 | license-server /v1/health | **PASS** |
| A2 | create-key + add-release CLIs | **PASS** |
| A3 | activate → update-check → signed download | **PASS** |
| A4 | H2 regression: update-check without site binding → 403 | **PASS** |
| A5 | rate limit 429 + download_secret fails closed | **PASS** |
| B1 | opt-out table upgrade: phone_last10 column, backfill, EXPLAIN uses index | **PASS** |
| B2 | opt-out across formatting variants (STOP/START/YES) | **PASS** |
| B3 | bulk tab: one batched opt-out query, not N | **PASS** |
| B4 | role matrix: admin saves; shop_manager refused; subscriber floor; admin never locked out | **PASS** |
| B5 | auth token: shop_manager can't change; admin can; TOC_AUTH_TOKEN constant wins | **FAIL → fixed** (token was protected, but the settings error was never displayed) |
| B6 | order screen SMS/call bound to billing phone; force bypasses consent not number | — |
| B7 | WhatsApp: clean degradation without sender; whatsapp: prefixes in payload | — |
| C1 | customized order creates artwork attachments with _sc_* markers | — |
| C2 | admin Print files links go through sc_dl proxy and download | — |
| C3 | sc_dl signature matrix (no/valid/expired/tampered sig, non-SC id) | — |
| C4 | anonymous REST media hides SC artwork; shop manager sees it | — |
| C5 | backfill re-marks pre-marker artwork | — |
| C6 | webserver deny rules: direct uploads 403, proxy still serves | — |
| C7 | proof email attaches files by path | — |
| C8 | FPD import: public https OK; loopback/metadata/private/IPv6 URLs skipped | — |
| D1 | RMA per-line quantities clamped; bogus ids dropped | — |
| D2 | RMA slip renders Return-qty column | — |
| D3 | RMA status emails once per transition; default off | — |
| D4 | document endpoints enforce login + order ownership | — |
| E1 | activate → deactivate → uninstall clean (options/tables/cron) | — |
| E2 | WP_DEBUG log clean across main flows | — |
| E3 | HPOS enabled AND disabled both work | — |

## Evidence

### A. license-server

Setup: `php8.3-sqlite3` installed (`pdo_sqlite` was missing from the VM); a git-ignored
`license-server/config.php` with random `admin_token`/`download_secret` and
`public_base_url=http://127.0.0.1:8081`; served with
`cd license-server/public && php -S 127.0.0.1:8081`.

**A1 — PASS.** `curl http://127.0.0.1:8081/v1/health` →
`{"ok":true,"service":"toc-license-server"}` HTTP 200.

**A2 — PASS.**
`php bin/create-key.php --email=test@example.com --sites=1 --expires=lifetime` printed key
`TOC-5A0D…009B`. Built `twilio-order-communicator-1.19.0.zip` per RELEASE.md (verified:
single top-level folder, `grep -c license-server` = 0) and registered it with
`php bin/add-release.php --version=1.19.0 --file=…` → row upserted, zip copied to
`storage/releases/`.

**A3 — PASS.**
- `POST /v1/activate` (key + `site_url=http://localhost:8080` + `instance_id=inst-verify-001`) →
  200 `{"success":true,"status":"active",…"activations":1}`.
- `GET /v1/update-check?…&version=1.18.0&site_url=…&instance_id=inst-verify-001` with
  `X-TOC-License` header → 200 `{"update":true,…"package_url":"http://127.0.0.1:8081/v1/download?slug=…&expires=…&sig=<hmac>"}`.
- `curl` on that `package_url` → HTTP 200, `unzip -t` on the body: "No errors detected in
  compressed data".

**A4 (H2 regression) — PASS.**
- Same valid key, **no** `site_url`/`instance_id` →
  HTTP **403** `{"success":false,"status":"inactive","error":"Site not activated. Activate this site for the license before checking for updates."}` — no package URL minted.
- Valid key with a never-activated `site_url=http://evil.example.com&instance_id=inst-neverseen`
  → HTTP **403**, same body.
- Bonus: `/v1/download` with a tampered `sig` → HTTP **403** "Invalid or expired download link."

**A5 — PASS.**
- Rate limit: with `rate_limit_max=5` (config edit, restored after), 8× `POST /v1/validate`
  from one IP → requests 1–5 HTTP 200, requests 6–8 HTTP **429**
  `{"success":false,"error":"Too many requests. Please try again later."}` (with `Retry-After`).
- Fail closed: with `download_secret=''` and the placeholder `admin_token`
  (`change-me-to-a-long-random-string`), the same fully-activated update-check returned
  HTTP **500** `{"success":false,"error":"Server error"}` and the server log shows
  `TOC License Server error: Download secret is not configured. Set download_secret (or a
  non-default admin_token) in config.php.` — no URL was signed. Config restored afterwards.

### B. Twilio Order Communicator

**B1 — PASS.** Simulated a pre-1.17.0 install:
`ALTER TABLE wp_toc_sms_opt_outs DROP KEY phone_last10, DROP COLUMN phone_last10`, inserted
two legacy rows (`+1 (505) 555-1234` / digits `15055551234`, and UK-style `07911123456`),
set `toc_db_version=1.16.0`. One ordinary front-page load (HTTP 200) ran the `init` upgrade:
- `SHOW CREATE TABLE` again lists `phone_last10 varchar(10)` + `KEY phone_last10`.
- Backfill populated both rows: `5055551234` and `7911123456` (correct last-10 truncation).
- `EXPLAIN SELECT id FROM wp_toc_sms_opt_outs WHERE phone_last10 = '5055551234'` →
  `type=ref, key=phone_last10, rows=1, Extra=Using where; Using index` — index used, no full scan.

**B2 — PASS.** Drove the real inbound webhook (`/index.php?rest_route=/toc/v1/sms`) with a
**valid computed `X-Twilio-Signature`** (HMAC-SHA1 over URL+sorted params with the stored
token), one unique `MessageSid` per request:
- `STOP` from `+1 (505) 555-1234` → 200 + TwiML "You have been unsubscribed…";
  `phone_is_opted_out()` returns `true` for `5055551234`, `+15055551234`, **and**
  `+1 (505) 555-1234`.
- `YES` from the opted-out number → TwiML `<Response></Response>`, still opted out.
  `YES` from a fresh number → not opted out (bare YES never toggles consent).
- `START` from bare `5055551234` → "You have been re-subscribed…"; all formatting
  variants now report opted-in; opt-out table row count back to 0.

**B3 — PASS.** Created 10 Ready-for-Pickup orders with distinct billing phones; fetched
`admin.php?page=toc-communicator&tab=bulk` as a logged-in administrator with a temporary
`query`-filter logger capturing any SQL touching `toc_sms_opt_outs`. The page listed
"11 order(s)" and issued **exactly one** opt-out query:
`SELECT phone_last10 FROM wp_toc_sms_opt_outs WHERE phone_last10 IN ('5055551000',…,'5005550001')` —
a single batched `IN` list, not one query per row.

**B4 — PASS.**
- Administrator: Settings shows the Role permissions form; POST to
  `admin-post.php?action=toc_save_role_caps` (valid nonce) → 302 `…&toc_roles_saved=1`.
- Baseline floor: the same admin POST asked for `subscriber[manage]=1`,
  `subscriber[send]=1` and `editor[manage]=1` → after save `subscriber manage=0 send=0`,
  `editor manage=0 send=0` (no WooCommerce baseline), while `shop_manager manage=1 send=1`.
- Lockout: the POST deliberately omitted `administrator[manage]` → administrator still has
  `toc_manage` (forced server-side).
- shop_manager (real second user): the rendered Settings page contains **0** occurrences of
  the role form; a direct POST to `admin-post.php?action=toc_save_role_caps` from that
  session → HTTP **403**, role caps unchanged.

**B5 — FAIL → fixed.**
- The protection itself worked as designed on the live install: a shop_manager POST to
  `options.php` (own valid nonce — shop managers may save the rest of Settings) with
  `toc_auth_token=HACKED…` → 302, `toc_auth_token` unchanged.
- **Observed bug:** the promised "previous value was kept" settings error never rendered.
  The plugin page lives under the WooCommerce menu, so WordPress never loads
  `options-head.php` and `add_settings_error()` notices were silently dropped.
  **Fix:** `render_settings()` now calls `settings_errors()`
  (`trait-toc-admin-settings.php`). Re-ran the same POST: the redirect target page now
  shows "Only administrators can change the Twilio Auth Token. Your previous value was
  kept." and the token is still unchanged.
- Administrator with the same form → token actually changes (verified changed, then restored).
- `TOC_AUTH_TOKEN` constant defined in wp-config: effective credentials report the constant
  value, `credential_is_constant('token')` is true, and an admin save attempt neither
  changes the option nor the effective token. Constant removed afterwards; option value wins again.

### C. StoreCanvas

_(pending)_

### D. OrderBay

_(pending)_

### E. Cross-cutting

_(pending)_
