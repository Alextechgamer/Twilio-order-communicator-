# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls via **your own Twilio account**. Order communication is driven by custom statuses (**Ready for Pickup** and **Shipped**), with consent-aware SMS, quiet hours, bulk reminders, and order chat history.

**Current version: 1.6.0**

## Install

Upload `twilio-order-communicator/` (or a release zip) to `/wp-content/plugins/`, activate, then open **WooCommerce → Order Communicator → Setup**.

Enter your Twilio Account SID, Auth Token, and From Number. This plugin does not provide messaging services — Twilio bills you directly.

Release zips:

- [`twilio-order-communicator-1.5.0.zip`](./twilio-order-communicator-1.5.0.zip)
- [`twilio-order-communicator-1.4.0.zip`](./twilio-order-communicator-1.4.0.zip)
- [`twilio-order-communicator-1.3.0.zip`](./twilio-order-communicator-1.3.0.zip)

## What's in 1.6.0

| Feature | Notes |
|---------|--------|
| Custom statuses | Registers `wc-ready-for-pickup` and `wc-shipped` (+ bulk actions) |
| Status mapping | Point Ready for Pickup / Shipped logic at any registered WC status |
| Per-status auto-notify | Independent enable / voice / SMS + message templates |
| Tracking meta | `_toc_notified_ready_for_pickup_at`, `_toc_notified_shipped_at` |
| Bulk reminders | Orders currently in Ready for Pickup |
| Local Pickup filter | Optional secondary check for Ready for Pickup (default off on new installs) |

## What's in 1.5.0

| Feature | Notes |
|---------|--------|
| Checkout SMS consent | Built-in checkbox for classic + block checkout; stores `_toc_sms_consent` + timestamp/IP |
| Quiet hours | Defers auto voice/SMS until the window ends (store timezone) |
| Setup wizard | Credentials → connection test → webhook → consent → auto notify |

## Auto SMS troubleshooting

Per-status **SMS** toggles default to **off**. Enable them under Settings, confirm consent, clear `_toc_notified_ready_for_pickup_at` or `_toc_notified_shipped_at` to re-test. Quiet hours may defer sends — check order notes.

## Plugin layout

```
twilio-order-communicator/
  twilio-order-communicator.php
  includes/
    class-toc-checkout.php      checkout consent
    class-toc-onboarding.php    setup wizard
    class-toc-statuses.php      custom WC statuses + mapping
    class-toc-auto.php          status triggers + quiet hours
    class-toc-twilio.php
    class-toc-webhooks.php
    class-toc-logger.php
    class-toc-order-meta.php
    class-toc-admin.php
  assets/admin.{js,css}
```

## Product analysis (sell / website)

See [`docs/PRODUCT-ANALYSIS.md`](./docs/PRODUCT-ANALYSIS.md) for cleanup priorities, commercial feature roadmap, and website/licensing notes.

See [`tasks.md`](./tasks.md) for the implementation roadmap (P0–P2).

## Next

P0 hardening (HTTP client, admin split, webhooks REST) · license keys · scheduled reminders · CSV/analytics
