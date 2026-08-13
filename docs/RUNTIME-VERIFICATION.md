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
| B6 | order screen SMS/call bound to billing phone; force bypasses consent not number | **PASS** |
| B7 | WhatsApp: clean degradation without sender; whatsapp: prefixes in payload | **PASS** (live delivery **BLOCKED** — no real approved WhatsApp sender) |
| C1 | customized order creates artwork attachments with _sc_* markers | **PASS** |
| C2 | admin Print files links go through sc_dl proxy and download | **FAIL → fixed** (preview `<img>` on the order screen and print queue leaked raw uploads URLs) |
| C3 | sc_dl signature matrix (no/valid/expired/tampered sig, non-SC id) | **PASS** |
| C4 | anonymous REST media hides SC artwork; shop manager sees it | **PASS** |
| C5 | backfill re-marks pre-marker artwork | **PASS** |
| C6 | webserver deny rules: direct uploads 403, proxy still serves | **PASS** |
| C7 | proof email attaches files by path | **PASS** |
| C8 | FPD import: public https OK; loopback/metadata/private/IPv6 URLs skipped | **PASS** |
| D1 | RMA per-line quantities clamped; bogus ids dropped | **FAIL → fixed** (save recursed 23,502× — reentrancy; data itself was correct) |
| D2 | RMA slip renders Return-qty column | **PASS** |
| D3 | RMA status emails once per transition; default off | **PASS** |
| D4 | document endpoints enforce login + order ownership | **PASS** |
| E1 | activate → deactivate → uninstall clean (options/tables/cron) | **FAIL → fixed** (TOC + SC uninstall left a few options behind) |
| E2 | WP_DEBUG log clean across main flows | **FAIL → fixed** (SC queue passed unsupported meta_query on the CPT datastore) |
| E3 | HPOS enabled AND disabled both work | **PASS** |

## Phase 3 — launch readiness

- **Version consistency (all 5 places each).** After the runtime fixes, each plugin was
  bumped a patch level and verified across plugin header, `*_VERSION` constant,
  `readme.txt` Stable tag, `readme.txt` changelog top entry, and the root README table:
  **TOC 1.19.1**, **StoreCanvas 1.7.1**, **OrderBay 1.8.1**.
- **Release zips (per RELEASE.md).** Built all three; each has exactly **one** top-level
  folder, **0** `license-server` entries, and no `.git` directory (only a `languages/.gitkeep`).
- **Install-from-zip smoke test.** Removed the TOC symlink, `wp plugin install
  twilio-order-communicator-1.19.1.zip --activate` on the live site → installed as a real
  directory, `TOC_VERSION` reports 1.19.1, the opt-out/comms tables were recreated, the
  Settings and Dashboard tabs load (HTTP 200), and `debug.log` stayed empty. The symlinked
  dev setup was then restored.
- **Translations.** `php tools/make-pot.php` produced only line-number/date churn — none of
  the runtime fixes added or changed a translatable string — so the committed `.pot` files
  were left unchanged (churn discarded), per the release process.

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

**B6 — PASS.** All requests were real `admin-ajax.php` POSTs from a logged-in
administrator session with the page's `toc_nonce`; outbound Twilio HTTP was captured by
the dev mock (`pre_http_request`) so payloads could be asserted without live credentials.
Order #14, billing phone `+15055551000`:
- `toc_send_sms` with `phone=+1 (505) 555-1000` (formatting variant of the billing number)
  → `{"success":true}`; captured payload: `Messages.json`, `To=+15055551000`,
  `From=+15005550006`, `Body=Your order is ready`.
- Tampered `phone=+15055559999` → `{"success":false,"data":"A message is required, and
  the phone must match the order's billing number."}` — **zero** outbound HTTP.
- Consent: after a STOP webhook for the order's phone, un-forced send →
  `{"code":"needs_force","message":"Phone number has opted out (STOP)."}`;
  with `force=1` → sends (payload captured). `force=1` **plus** the tampered number →
  still refused, zero outbound HTTP. (Force bypasses consent, never the number binding.)
- `toc_send_call`: bare `5055551000` variant → success, payload `Calls.json`
  `To=+15055551000`; tampered number → refused, no HTTP.
- Bonus observed: creating the 10 Ready-for-Pickup orders fired status-based auto-notify —
  the mock captured one `Calls.json` POST per order with the tokenized TwiML URL
  (`?toc_twiml=1&token=…`) and REST status callbacks.

**B7 — PASS (payloads); live delivery BLOCKED.**
Note: the provisioned site had `toc_require_sms_consent=0`; it was set to `1` for these checks.
- Consent gate: `toc_bulk_reminder` `mode=whatsapp` on an order without consent meta →
  `"detail":"WhatsApp skipped (no consent)"`, zero outbound HTTP.
- No `toc_whatsapp_from` configured (falls back to the SMS From): consented order →
  captured payload `To=whatsapp:+15055551001`, `From=whatsapp:+15005550006` — `whatsapp:`
  prefix on **both** addresses, same Messages API.
- `toc_whatsapp_from=+14155238886` → `From=whatsapp:+14155238886`.
- Degradation with no sender at all (`toc_whatsapp_from` and `toc_from_number` both empty)
  → clean `"WhatsApp failed: Twilio credentials not configured."`, no fatal, zero outbound
  HTTP. Options restored afterwards.
- **BLOCKED:** actual WhatsApp delivery — requires a real, Twilio-approved WhatsApp sender,
  which this sandbox does not have. The request payloads above are the verifiable surface.

### C. StoreCanvas

**C1 — PASS.** Full guest purchase, end to end over HTTP:
- Created product #25 ("SC Custom Mug") with a real GD-generated 800×600 base view image
  and a `_sc_customizer` config (view `front`, print area 25/25/50/50%); enabled COD.
- Guest (fresh cookie jar) multipart POST to the product page:
  `add-to-cart=25` + `sc_artwork=@…png` + `sc_placement` JSON + a text layer in
  `sc_layers_json`. First attempt with a 400×300 PNG was **correctly refused by artwork
  validation** ("minimum 500 px on the long edge", "~66.7 DPI, need at least 150"), which
  is itself a live confirmation of `validate_source()`. Retried with a 2000×1500 PNG →
  item added with `sc_attachments` populated.
- Checkout completed as a guest via the Store API (`/wc/store/v1/checkout`, COD) →
  order #29 `processing`.
- Order item meta: `sc_print_files={"front":27}`, `_sc_artwork_id=26`, `sc_preview_id=28`.
  Attachment 26 (uploaded artwork) carries `_sc_uploaded=1`; 27 (2560×1920 print
  composite) and 28 (preview) carry `_sc_generated=1`; all three files exist on disk.

**C2 — FAIL → fixed.**
- The item-meta "Print files" links on the shop-manager order screen were already
  `admin-post.php?action=sc_dl&id=…&exp=…&sig=…` and downloaded correctly
  (HTTP 200, `Content-Type: image/png`, correct 2000×1500 file) — that part passed.
- **Observed bug:** the StoreCanvas preview `<img>` on the same order screen — and the
  StoreCanvas production-queue page — still rendered the customer preview via
  `wp_get_attachment_image_url()`, i.e. a raw
  `wp-content/uploads/2026/08/sc-preview-…png` URL, contradicting the H1 guarantee that
  artwork links never expose the raw path (and it would break under the C6 deny rules).
  **Fix:** both render sites now use `SC_Print_Ready::proxy_url()`
  (`class-sc-cart-order.php::admin_order_preview`, `class-sc-queue.php` table). Re-ran:
  0 raw `sc-*` uploads URLs on either page, 3 proxy links on the order screen, and the
  proxied preview downloads as a shop manager (HTTP 200, PNG 600×450).

**C3 — PASS** (all as a logged-out visitor):
| Request | Result |
|---|---|
| `sc_dl&id=27` with **no** signature | **403** |
| valid unexpired signature (minted via `proxy_url(27)`) | **200**, PNG 2560×1920 streamed |
| **expired** signature (correctly signed, `exp` in the past) | **403** |
| **tampered** signature | **403** |
| valid signature for a **non-SC** attachment (id 24, merchant base image, no `_sc_*` markers) | **404** |

**C4 — PASS.** Anonymous `GET /wp/v2/media?per_page=100` lists only ids `[24, 4]`
(merchant/base imagery) — SC artwork 26/27/28 absent. The same request as the logged-in
shop manager (cookie + `X-WP-Nonce`) lists `[28, 27, 26, 24, 4]`.

**C5 — PASS.** Deleted `_sc_uploaded`/`_sc_generated` from 26/27/28 and the
`sc_artwork_backfilled` option to simulate pre-marker artwork. Demonstrably exposed
state: anonymous REST **listed all three** and the proxy 404'd even with a valid
signature. One administrator wp-admin page load ran the `admin_init` backfill:
all three re-marked `_sc_generated=1`, flag set, anonymous REST hides them again, and the
signed proxy serves id 27 with HTTP 200.

**C6 — PASS.** Installed nginx + php8.3-fpm (run as `ubuntu` so they can read `~/wordpress`)
and served the same WP root on `:8082` with the `docs/storecanvas-artwork-privacy.md`
deny block adapted to the actual uploads path
(`location ~* ^/wp-content/uploads/2026/08/sc- { deny all; return 403; }`):
- Direct hit on the composite `…/uploads/2026/08/sc-print-25-front-…png` via nginx → **403**
  (same URL via the plain `php -S :8080` control, with no deny rule → 200, confirming the
  file is otherwise reachable).
- The uploaded artwork and preview files direct URLs → **403** too.
- The signed proxy URL (`admin-post.php?action=sc_dl…`) through nginx → **200** and streamed
  the 2560×1920 PNG. So the deny closes the raw path while the proxy still serves.

**C7 — PASS.** Enabled `sc_proof_email_enabled=1`; the proof email fired on the
processing transition. The `wp_mail` capture recorded:
`to=cara@example.com`, subject "Your print proof for order 29",
`attachments=[{"path":"…/wp-content/uploads/2026/08/sc-print-25-front-…png","exists":true}]`
— a real server file path (not a URL), and the file exists. (Re-invoking `maybe_send()`
did not send again — `_sc_proof_emailed_at` gates it once per order.)

**C8 — PASS.** Ran the real importer (`admin-post.php?action=sc_fpd_import`, valid nonce,
as admin) with an FPD JSON of five views whose base-image sources were, respectively, a
public `https://s.w.org/…png`, `http://127.0.0.1/…`, `http://169.254.169.254/latest/meta-data/`,
`http://10.0.0.1/…`, and `http://[::1]/…`:
- The POST returned **302** with an "Imported 5 view(s)" notice — the import **completed,
  not aborted**.
- Only **one** view got an `image_id` (View0, the public https image → attachment 30,
  `WordPress-logotype-wmark.png`); the loopback, cloud-metadata, RFC1918 and IPv6-loopback
  URLs were all skipped. `wp-content/debug.log` had **zero** entries.
- `SC_FPD_Import::is_safe_sideload_url()` independently returns `true` only for the public
  URL and `false` for all four blocked hosts.

### D. OrderBay

Test order #33 (classic `shop_order`, HPOS off) with two line items — item 3 (RMA Widget,
qty 3), item 4 (RMA Gadget, qty 2). All saves were real admin `post.php?action=editpost`
submissions with the WordPress edit nonce, `woocommerce_meta_nonce`, and the RMA nonce.

**D1 — FAIL → fixed.**
- The per-line sanitization is correct: posting `ob_rma_items[3]=5` (over the ordered 3),
  `[4]=1`, plus a bogus id `[999]=2` and a negative `[-7]=3` → stored
  `{"3":3,"4":1}` — item 3 clamped to its ordered qty, item 4 kept, bogus/negative dropped.
- **Observed bug:** the save took **53 seconds** and `debug.log` recorded a
  `Maximum execution time exceeded`. Tracing showed `woocommerce_update_order` firing
  **23,502 times** for a single admin save: `OB_RMA::save_hpos()` (hooked to
  `woocommerce_update_order`) calls `apply_posted()`, which calls `$order->save()`, which
  re-fires `woocommerce_update_order` → `save_hpos()` again (the RMA nonce is still in
  `$_POST`, so `verify_save()` keeps passing) — unbounded reentrancy on every RMA save.
  **Fix:** added a static `$applying` reentrancy guard around `apply_posted()`
  (`class-ob-rma.php`). Re-ran the identical save: completes in **0.30 s**, HTTP 302,
  `debug.log` empty, stored data still `{"3":3,"4":1}`.

**D2 — PASS.** `admin-post.php?action=ob_print_rma&order_id=33` (session nonce) → HTTP 200.
The slip's item table has a **Return qty** column and shows the clamped values:
`RMA Widget | 3 | 3` and `RMA Gadget | 2 | 1` (columns: Item, SKU, Qty, Return qty).

**D3 — PASS** (wp_mail captured by the dev mail mu-plugin):
- Default settings (`notify_customer=0`): transition into `approved` → **0** emails.
- After enabling `notify_customer=1`:
  - `requested → approved` → **1** email ("TOC Dev — return update for order #33").
  - re-saving `approved` (no transition) → **0** emails (gated by `_ob_rma_emailed`).
  - `approved → received` → **1**; `received → closed` → **1**.

**D4 — PASS.** Two real customers (custa #4, custb #5) each with their own completed order
(A=#34, B=#35). Nonces minted inside custa's real browser session:
- Invoice: custa opens own `#34` → **200** (invoice renders); custa opens B's `#35` with a
  valid custa-session nonce → **403** "You cannot view this invoice"; logged-out → **302**
  to `wp-login.php`.
- Packing slip (after enabling `ob_customer_packing_slip_enabled`): own `#34` → **200**;
  B's `#35` → **403** "You cannot view this packing slip". Both paths gate on
  `OB_Invoicing::customer_can_view_invoice()` (customer id / billing-email match, staff
  bypass), so a swapped order id is refused even with a valid nonce.

### E. Cross-cutting

Uninstall was exercised **without** `wp plugin uninstall` (which would delete the symlinked
source in `/workspace`). Instead each plugin's `uninstall.php` was run with
`WP_UNINSTALL_PLUGIN` defined via `wp eval-file`, then options/tables/cron were inspected,
then the plugin was re-activated.

**E1 — FAIL → fixed.**
- TOC: after deactivate, no `toc_*` cron/Action Scheduler jobs remained. Running
  `uninstall.php` dropped both tables (`wp_toc_communications`, `wp_toc_sms_opt_outs`),
  cleared the `_site_transient_toc_update_check_*` rows, and removed the `toc_*` options —
  **except `toc_whatsapp_from`** (the Batch-4 WhatsApp sender setting was never added to the
  uninstall list). **Fix:** added `toc_whatsapp_from` to `uninstall.php`. Re-ran → `toc_*`
  option count 0.
- StoreCanvas: `uninstall.php` dropped `wp_sc_journey` and removed `sc_proof_email_*` /
  `sc_journey_enabled`, **but left `sc_artwork_backfilled`** (and would leave `sc_dl_secret`
  on hosts without `wp_salt`) — both introduced by the Batch-5 artwork proxy. **Fix:** added
  `sc_dl_secret` + `sc_artwork_backfilled` to `uninstall.php`. Re-ran → only ActionScheduler's
  own `schema-*` options remain (not StoreCanvas).
- OrderBay: `uninstall.php` removed all real `ob_*` options and left no `ob_*` cron. (Clean;
  the one leftover seen during testing, `ob_fulfillment_customer_packing`, was a typo option
  I had set by hand — not written by the plugin, confirmed by grep.)
  **Addendum (2026-08-12, OB 1.8.3):** a later code audit showed this "clean" verdict held
  only because the test never configured a numbering format/reset — with those configured,
  the six `ob_*_format`/`ob_*_reset` options and the period-scoped counter rows
  (`ob_invoice_next_2026`, `ob_invoice_next_202608`, …) written by
  `OB_Invoicing::allocate_number()` were left behind. Fixed in 1.8.3: the options were added
  to the uninstall list and a `LIKE 'ob\_*\_next\_%'` sweep removes the period counters.
  This fix is code-reviewed + `php -l`/unit-tested, not yet re-verified on a live install.
- Re-activating all three: front page + wp-admin both HTTP 200, TOC tables recreated, and the
  only debug-log line was WordPress core's `_load_textdomain_just_in_time` nag for the
  **woocommerce** domain during WP-CLI activation (not emitted on web requests and not from
  these three plugins).

**E2 — FAIL → fixed.** With `WP_DEBUG=true` + `WP_DEBUG_LOG`, truncated `debug.log` and
exercised the main flows over HTTP (front page; the TOC dashboard/bulk/settings/tools/license
tabs; the SC production queue; SC and OB order-edit screens; the OB documents settings; TOC
auto-notify to Ready/Shipped; the customer invoice). The **only** plugin-originated entry was:
> `WC_Order_Data_Store_CPT::query was called incorrectly. Order query argument (meta_query)
> is not supported on the current order datastore … SC_Queue->query_orders`

i.e. `SC_Queue::query_orders()` passed `meta_query` to `wc_get_orders()`, which the classic
(CPT) datastore rejects with a doing-it-wrong notice (WC 9.2+). **Fix:** `query_orders()` now
adds `meta_query` only when HPOS is active (`hpos_active()` helper); on the CPT datastore it
relies on the existing recent-orders scan + `order_has_sc_art()` PHP filter, so results are
identical. Re-ran all three queue tabs + the sweep → the queue still lists the customized
order #29 and `debug.log` is **empty** (0 lines).

**E3 — PASS.** Enabled HPOS (`wp wc hpos sync` then `enable`; confirmed
`custom_orders_table_usage_is_enabled()` true) and re-ran a representative subset:
- TOC auto-notify: moving order #23 into Ready-for-Pickup fired the notify hook, stamped
  `_toc_notified_ready_for_pickup_at`, wrote order notes, and the Twilio mock captured one
  `…/Calls.json` POST with `To=+15055551009` (the order's phone).
- SC: production queue (now on the `meta_query` path) rendered HTTP 200 and still listed
  order #29; the signed proxy served attachment 27 (HTTP 200).
- OB RMA: saving via the HPOS order editor (`page=wc-orders&action=edit_order`) stored the
  clamped `{"3":3,"4":2}` (9→3, bogus 999 dropped), completed in 0.34 s (reentrancy guard
  holds under HPOS too), and the status email fired once on the `approved → received`
  transition. `debug.log` empty throughout.
- Reverted to HPOS disabled (the provisioned default) and re-swept the SC queue + order
  screens — `debug.log` still empty. Both datastores verified.
