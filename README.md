# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls via **your own Twilio account**. Order communication is driven by custom statuses (**Ready for Pickup** and **Shipped**), with consent-aware SMS, quiet hours, bulk reminders, and order chat history.

**Current version: 1.14.1** (plugin header / `TOC_VERSION`)

This monorepo also contains independent plugins:

| Plugin | Path | Current |
|--------|------|---------|
| Twilio Order Communicator | `twilio-order-communicator/` | 1.14.1 |
| StoreCanvas | `storecanvas/` | 1.3.0 |
| Orderbay | `orderbay/` | 1.3.0 |

## Install

Upload `twilio-order-communicator/` to `/wp-content/plugins/`, activate, then open **WooCommerce → Order Communicator → Setup**.

Enter your Twilio Account SID, Auth Token, and From Number (or define `TOC_ACCOUNT_SID` / `TOC_AUTH_TOKEN` / `TOC_FROM_NUMBER` in `wp-config.php`). This plugin does not provide messaging services — Twilio bills you directly.

## Licensing (optional — updates only)

Premium updates use a **first-party** license server (not Freemius/EDD/Lemon Squeezy). Missing or invalid licenses **do not** disable SMS/voice.

```php
define( 'TOC_LICENSE_SERVER_URL', 'https://licenses.example.com' );
```

Then activate a key under **Order Communicator → License**.

Seller docs: [`RELEASE.md`](./RELEASE.md) (build + deploy + keys) · [`license-server/README.md`](./license-server/README.md) (API reference)

## Download

Release **zips are not stored in git** (see `.gitignore`). Build with the steps in [`RELEASE.md`](./RELEASE.md), or download assets from [GitHub Releases](https://github.com/Alextechgamer/Twilio-order-communicator-/releases) when published.

Latest source is always on `main` under `twilio-order-communicator/`.

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
