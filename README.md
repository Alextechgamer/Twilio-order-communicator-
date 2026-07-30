# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls on Local Pickup orders via Twilio.

**Current version: 1.4.0**

## Install

Upload `twilio-order-communicator/` (or a release zip) to `/wp-content/plugins/`, activate, then configure under **WooCommerce → Order Communicator → Settings**.

Release zips:

- [`twilio-order-communicator-1.4.0.zip`](./twilio-order-communicator-1.4.0.zip)
- [`twilio-order-communicator-1.3.0.zip`](./twilio-order-communicator-1.3.0.zip) (previous)

## What's in 1.4.0 (P0 cleanup)

| Item | Notes |
|------|--------|
| HPOS declare | Compatible with WooCommerce custom order tables |
| Brand headers | GitHub Plugin URI / Author / Domain Path |
| i18n | Text domain loaded; admin, order UI, notes, JS strings wrapped |
| Dashboard pagination | 40 per page |
| START keywords | `START` / `UNSTOP` only (not `YES`) |
| Opt-out table | `wp_toc_sms_opt_outs` (migrates legacy option) |
| Activation defaults | Templates and toggles seeded when missing |
| Tooling | `.editorconfig`, `phpcs.xml.dist`, LF line endings |

## What's in 1.3.0

| Feature | Notes |
|---------|--------|
| Merge tags | `{order_number}`, `{customer_first_name}`, `{customer_last_name}`, `{customer_full_name}`, `{store_name}`, `{phone}`, `{order_total}`, `{billing_email}`, `{order_id}` |
| Auto-notify once | Order meta `_toc_auto_notified_at` — clear to re-send |
| STOP / HELP / START | Inbound keywords + phone opt-out list |
| Manual SMS consent warn | Confirm before force-send |
| Local Pickup match | Setting: `method_id` / `local_title` (default) / `any_pickup` |
| SMS StatusCallback | Delivery status updates; notes on failed/undelivered |
| Auto SMS skip notes | Order notes always explain why SMS was skipped |

Checkout consent UI is intentionally not bundled — use your existing snippet and set **Consent meta key** (default `_toc_sms_consent`).

## Auto SMS troubleshooting

**Also send an SMS** defaults to **off** (separate from Auto Voice). If a completed Local Pickup order only gets a call:

1. Enable **Also send an SMS** and Save  
2. Confirm consent meta matches your checkout snippet  
3. Clear `_toc_auto_notified_at` on the order (or use a new order)  
4. Check order notes for skip reasons  

## Plugin layout

```
twilio-order-communicator/
  twilio-order-communicator.php   bootstrap
  uninstall.php
  readme.txt
  languages/
  assets/admin.{js,css}
  includes/
    class-toc-twilio.php          REST SMS/calls + TwiML + merge tags
    class-toc-webhooks.php        inbound SMS + status callbacks
    class-toc-logger.php          communications + opt-outs tables
    class-toc-order-meta.php      order chat UI
    class-toc-admin.php           settings, bulk, tools
    class-toc-auto.php            completed → auto notify
```

## Product analysis (sell / website)

See [`docs/PRODUCT-ANALYSIS.md`](./docs/PRODUCT-ANALYSIS.md) for cleanup priorities, commercial feature roadmap, and website/licensing notes.

## Possible next work (1.5+)

Built-in checkout SMS opt-in · quiet hours · onboarding wizard · licensing / auto-updates · marketing site
