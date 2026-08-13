# OrderRing (and sibling plugins)

WordPress / WooCommerce plugins. **OrderRing** is SMS, voice, and WhatsApp via **your own Twilio account** — Ready for Pickup and Shipped statuses, consent-aware SMS, quiet hours, bulk reminders, and order chat.

**Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.** Bring your own Twilio account — you pay Twilio directly, zero markup.

**Current versions**

| Plugin | Path | Current |
|--------|------|---------|
| OrderRing | `twilio-order-communicator/` | 1.22.0 |
| StoreCanvas | `storecanvas/` | 1.9.0 |
| Orderbay | `orderbay/` | 1.10.0 |

Launch (naming, license-server production, pricing, legal): [`docs/launch/`](./docs/launch/).

## Local development

No Docker needed — `tools/dev/setup-wp.sh` stands up WordPress + WooCommerce + MariaDB on
PHP's built-in server with all three plugins symlinked from the repo and activated
(idempotent; Ubuntu/PHP 8.3):

```bash
bash tools/dev/setup-wp.sh          # provision (or re-sync) ~/wordpress
php -S 0.0.0.0:8080 -t ~/wordpress  # serve — admin at /wp-admin (admin/admin)
```

The script also installs two **development-only** mu-plugins from `tools/dev/mu-plugins/`:
a Twilio HTTP mock (captures outbound `api.twilio.com` requests to `/tmp/toc-http.log` and
fakes success, so send paths run without live credentials) and a `wp_mail` capture
(`/tmp/wp-mail.log`). Remove them to talk to the real services.

The license server runs standalone (needs `php8.3-sqlite3`): copy
`license-server/config.example.php` to `config.php` (git-ignored), set real secrets, then
`cd license-server/public && php -S 127.0.0.1:8081`.

Tests + lint (the CI hard gate): `php tests/run.php` and `php -l` on every PHP file.
What has actually been exercised against a live install is recorded in
[`docs/RUNTIME-VERIFICATION.md`](./docs/RUNTIME-VERIFICATION.md).

## Install

Upload `twilio-order-communicator/` to `/wp-content/plugins/`, activate, then open **OrderRing → Setup**.

Enter your Twilio Account SID, Auth Token, and From Number (or define `TOC_ACCOUNT_SID` / `TOC_AUTH_TOKEN` / `TOC_FROM_NUMBER` in `wp-config.php`). This plugin does not provide messaging services — Twilio bills you directly.

## Licensing (optional — updates only)

Premium updates use a **first-party** license server (not Freemius/EDD/Lemon Squeezy). Missing or invalid licenses **do not** disable SMS/voice.

```php
define( 'TOC_LICENSE_SERVER_URL', 'https://licenses.example.com' );
```

Then activate a key under **OrderRing → License**.

Seller docs: [`RELEASE.md`](./RELEASE.md) (build + deploy + keys) · [`license-server/README.md`](./license-server/README.md) (API reference)

## Download

Release **zips are not stored in git** (see `.gitignore`). Build with the steps in [`RELEASE.md`](./RELEASE.md), or download assets from [GitHub Releases](https://github.com/Alextechgamer/Twilio-order-communicator-/releases) when published.

Latest source is always on `main` under `twilio-order-communicator/` (OrderRing).

## What's in this line (post-1.14.2)

| Plugin | Notes |
|--------|--------|
| OrderRing 1.20.0 / StoreCanvas 1.7.2 / OrderBay 1.8.2 | **Launch pack:** TOC renamed to **OrderRing** (folder/text domain unchanged); Twilio attribution + A2P 10DLC + “zero markup” copy; StoreCanvas color/DPI disclaimer; OrderBay “not tax advice” + e-invoice export-only. License item slug `orderring`. See [`docs/launch/`](./docs/launch/) |
| Orderbay 1.4.0 | Configurable invoice/proforma/credit-note numbering: `{PREFIX}{YYYY}{MM}{DD}{SEQ}` tokens + `{SEQ:n}` zero-padding, optional yearly/monthly counter reset (period-scoped, atomic). Back-compatible default `{PREFIX}{SEQ}`; sequence token always enforced |
| StoreCanvas 1.3.1 | Pricing correctness: percent options use the selected variation's price (not the parent), qty options charge amount × quantity, and negative option totals reduce the price (floored at 0) and display consistently. Live preview mirrors the server; `price_for()` unit-tested |
| TOC 1.15.0 | Delivery analytics dashboard card (SMS sent / delivered / failed / reply rates over 30d); inbound-webhook MessageSid idempotency (no double-processing of Twilio retries); single-use tokenized TwiML URL. `compute_rates()` unit-tested |
| StoreCanvas 1.4.0 | Multi-rule conditional logic (AND/OR + operators) beyond single `show_if`; lookup-table pricing (per-choice prices, multi-select summed) with the live preview matched to the server. `evaluate_conditions()` / `rule_matches()` / `lookup_price()` unit-tested |
| Orderbay 1.5.0 | Per-tax-rate breakdown on invoices & proformas (e.g. VAT 20% / VAT 5%) from the order's tax totals + a prices include/exclude tax note, replacing the single combined Tax line. `normalize_tax_rows()` unit-tested |
| StoreCanvas 1.5.0 | One-click Fancy Product Designer importer — maps exported FPD product JSON (views, print zones → % areas, text fields) into a StoreCanvas config; view images re-uploaded on import. Pure `SC_FPD_Import::map()` unit-tested |
| Orderbay 1.6.0 | Theme template overrides (`wp-content/themes/<theme>/orderbay/<template>.php`) across all 9 document types + `ob_before_document`/`ob_after_document` hooks and an `ob_locate_template` filter. Pure `template_candidates()` unit-tested |
| i18n 1.15.1 / 1.5.1 / 1.6.1 | Translation templates (`languages/*.pot`) for all three plugins via `tools/make-pot.php`; wrapped the previously English-only TOC Twilio/AJAX strings; moved StoreCanvas customizer JS strings into the localized `i18n` table and **fixed a missing `load_plugin_textdomain`** in StoreCanvas |
| TOC 1.16.0 | `{tracking}` / `{tracking_url}` merge tags for SMS/voice/email, resolved from OrderBay meta → WooCommerce Shipment Tracking → the `toc_order_tracking` filter. Pure `tracking_from_meta()` unit-tested |
| StoreCanvas 1.6.0 | Prebuilt product templates (T-shirt / Mug / Sticker / Sign) — a "Start from a template" box seeds a working print area + option fields in one click. Pure `SC_Templates::templates()` / `apply()` unit-tested |
| Orderbay 1.7.0 | QR fix — no more silent truncation to a dead Version-3 symbol (`pick_version()` rejects over-capacity payloads); optional `chillerlan/php-qrcode` or `endroid/qr-code` renders full order-URL QR correctly. Built-in stays experimental/off. `pick_version()` / `library_available()` unit-tested |
| Docs + tests 1.6.1 / 1.7.1 | Completed the StoreCanvas & OrderBay readmes (description/install/FAQ) and added regression tests for `OB_Barcode::code128_svg`, `OB_Fulfillment::sanitize_url_template`, and `SC_Product_Options::sanitize_field_row` (harness gained common sanitizer shims) |
| TOC 1.17.0 | Performance: sargable opt-out lookups via an indexed `phone_last10` column (replaces non-indexable `RIGHT(phone_digits,10)`, added on upgrade + backfilled); bulk-tab consent batch-loads opt-outs in one query (N+1 fix). Behavior unchanged; pure `last10()` unit-tested |
| TOC 1.18.0 | WhatsApp channel via the store's own Twilio WhatsApp sender (BYO, reuses the SMS opt-out/consent/merge/log path); optional `toc_whatsapp_from` setting + a "WhatsApp only" bulk mode. Pure `whatsapp_address()` unit-tested; live delivery needs a real WhatsApp sender |
| Orderbay 1.8.0 | Item-level RMA (per-line return quantities on the panel + RMA slip) and optional customer status emails (on Approved/Received/Closed, once per transition, default off). Pure `sanitize_rma_items()` / `should_email()` unit-tested |
| Runtime verification 1.19.1 / 1.7.1 / 1.8.1 | Fixes found while exercising the suite against a real WordPress/WooCommerce install (see [`docs/RUNTIME-VERIFICATION.md`](./docs/RUNTIME-VERIFICATION.md)): **TOC** shows Settings save-time notices; **StoreCanvas** serves the admin/queue artwork preview via the signed proxy and drops an unsupported `meta_query` on the classic order datastore; **OrderBay** fixes RMA-save reentrancy. Uninstall option cleanup completed for TOC + SC |
| TOC 1.19.0 | Security hardening: order-screen SMS/call is bound to the order's own billing number (no arbitrary-number sends under an order id); the Role-permissions matrix and Twilio Auth Token changes are administrator-only, and plugin caps require a WooCommerce baseline. Pure `phones_match()` / `role_meets_baseline()` unit-tested |
| StoreCanvas 1.7.0 | Security hardening: customer artwork served via a signed, capability-checked download proxy (not a permanent public uploads URL) + one-time marker backfill; FPD-import SSRF guard on sideloaded image URLs; guest design-email no longer echoes the token in its JSON. Pure `sign_token()`/`verify_token()`/`is_sc_artwork_meta()`/`is_blocked_host()` unit-tested |
| License-server | Security hardening: `/v1/update-check` now requires an activated site (matching `site_url`+`instance_id`) before issuing a signed download URL — a bare license key can no longer mint package URLs; nginx deny-rule docs for `data/`+`storage/` |

## What's in 1.14.2

| Area | Notes |
|------|--------|
| TOC | International phone normalization: national trunk `0` (e.g. UK `07911…` → `+447911…`) and `00` international prefix handled correctly; default calling code follows `woocommerce_default_country` (filterable via `toc_default_country_code`); deterministic so opt-out keys stay consistent |

## What's in 1.14.1

Security & correctness hardening across all three plugins (see each `readme.txt` changelog).

| Area | Notes |
|------|--------|
| TOC | CSV formula-injection guard; server-derived consent IP; shop-manager Settings save; Local Pickup guard on reminders; E.164 validation; `cart_checkout_blocks` declared |
| StoreCanvas 1.2.1 | Real HTML escaper (XSS fix) + payload sanitize; layer-attachment IDOR gate; REST media exclusion; guest rate-limits; decompression-bomb guard; composite rotation |
| Orderbay 1.1.1 | Atomic gapless invoice numbering; invoice/proforma fee lines + credit-note tax; SLA-stall fix; partial-fulfillment nonce + HPOS redirects; `load_plugin_textdomain` |

## What's in 1.14.0

| Feature | Notes |
|---------|--------|
| Consent Yes | Block checkout does not overwrite explicit Yes with No when field missing |
| Bulk Ready | Filters by `date_modified`; All time (`days=0`) |
| Status emails | Prefer WooCommerce mailer `wrap_message` when available |

## What's in 1.13.0

| Feature | Notes |
|---------|--------|
| Polly voice map | `polly.joanna` → `Polly.Joanna` (and Matthew/Amy) in TwiML |
| Consent meta help | Settings copy for third-party checkbox meta keys |
| Status emails | Optional customer email on Ready/Shipped (default off, once per order) |
| Email details | wp_mail + store From; same merge tags as SMS; independent of voice/SMS; quiet hours do not apply |
| License | Messaging remains ungated |

## What's in 1.12.0

| Feature | Notes |
|---------|--------|
| Role permissions | Settings matrix for Manage plugin vs Send SMS & calls |
| Caps | `toc_manage` / `toc_send` (admin + shop_manager seeded once) |
| Safety | Administrator always keeps manage; filters still override |
| Prior in this line | Delivery alerts (1.11), CSV + collected (1.10), scheduled reminders (1.9) |

## What's in 1.11.0

| Feature | Notes |
|---------|--------|
| Delivery failure alerts | Optional email on SMS failed/undelivered StatusCallback |
| Settings | Enable (default off) + alert email (blank → admin_email) |
| Dedup | Transient per MessageSid; order notes still always written |

## What's in 1.10.0

| Feature | Notes |
|---------|--------|
| CSV export | Dashboard control; same filters as the table; streamed download |
| Mark as collected | Order action + `_toc_collected` meta; unmark available |
| Suppressions | Auto-notify skip, cancel scheduled reminders, exclude from bulk |

## What's in 1.9.0

| Feature | Notes |
|---------|--------|
| Scheduled reminders | Optional auto-remind after X hours in Ready for Pickup |
| Quiet hours / consent | Reuses TOC_Auto helpers + `_toc_last_reminder_at` |
| Action Scheduler | Single job per order; cancel on leave Ready / deactivate / uninstall |

## What's in 1.8.2

| Fix | Notes |
|-----|--------|
| Webhook URLs | Prefer WordPress `rest_url()` + `toc_webhook_base_url` origin rewrite |
| Paid statuses | Ready for Pickup / Shipped included so `is_paid()` stays true |
| Inbound phone match | Full last-10 LIKE needle, filterable limits; log-first kept |
| Docs | `phpcs.xml.dist` scope; tasks.md marks G1–G8 done |

## What's in 1.8.1

| Fix | Notes |
|-----|--------|
| License data | `last_payload` is a fixed scalar snapshot (no nesting) |
| Cron cleanup | Deactivate / uninstall clear license + deferred jobs |
| Update cache | Flushed when a license becomes usable for updates |
| Local Pickup skip | Stamps notified meta so skip notes do not repeat |

## What's in 1.8.0

| Feature | Notes |
|---------|--------|
| License tab | Activate / deactivate / re-check; masked keys |
| Update gate | Injects WP updates only when license allows |
| License server | SQLite API + CLI to create keys and register zips |
| Fail open / closed | Messaging always works; updates fail closed |

## Plugin layout

```
twilio-order-communicator/
  includes/class-toc-license.php
  includes/class-toc-updater.php
  includes/trait-toc-admin-license.php
  …
storecanvas/
  …
orderbay/
  …
license-server/
  public/index.php
  bin/create-key.php
  bin/add-release.php
  README.md
```

See [`tasks.md`](./tasks.md) for the roadmap.
