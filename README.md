# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls via **your own Twilio account**. Order communication is driven by custom statuses (**Ready for Pickup** and **Shipped**), with consent-aware SMS, quiet hours, bulk reminders, and order chat history.

**Current version: 1.7.0**

## Install

Upload `twilio-order-communicator/` (or a release zip) to `/wp-content/plugins/`, activate, then open **WooCommerce → Order Communicator → Setup**.

Enter your Twilio Account SID, Auth Token, and From Number (or define `TOC_ACCOUNT_SID` / `TOC_AUTH_TOKEN` / `TOC_FROM_NUMBER` in `wp-config.php`). This plugin does not provide messaging services — Twilio bills you directly.

## What's in 1.7.0

| Feature | Notes |
|---------|--------|
| Shared Twilio HTTP client | `request()` used by SMS, calls, credential test |
| Admin split | Settings / Dashboard / Bulk / Tools / Ajax traits |
| Phone → order matching | Log + HPOS/CPT billing phone + broader status scan |
| REST webhooks | `toc/v1/sms`, `voice-status`, `message-status` (+ query aliases) |
| Keyword auto-reply logging | STOP / HELP / START appear in order chat |
| Capabilities | Filters `toc_manage_settings`, `toc_send_sms` |
| SMS footer + privacy helper | Optional outbound footer; copy-paste policy text |

## What's in 1.6.0

| Feature | Notes |
|---------|--------|
| Custom statuses | Registers `wc-ready-for-pickup` and `wc-shipped` (+ bulk actions) |
| Status mapping | Point Ready for Pickup / Shipped logic at any registered WC status |
| Per-status auto-notify | Independent enable / voice / SMS + message templates |
| Tracking meta | `_toc_notified_ready_for_pickup_at`, `_toc_notified_shipped_at` |
| Bulk reminders | Orders currently in Ready for Pickup |
| Local Pickup filter | Optional secondary check for Ready for Pickup (default off on new installs) |

## Plugin layout

```
twilio-order-communicator/
  twilio-order-communicator.php
  includes/
    class-toc-caps.php
    class-toc-statuses.php
    class-toc-auto.php
    class-toc-twilio.php
    class-toc-webhooks.php
    class-toc-logger.php
    class-toc-admin.php          thin orchestrator
    trait-toc-admin-*.php        settings, dashboard, bulk, tools, ajax
    class-toc-order-meta.php
    class-toc-checkout.php
    class-toc-onboarding.php
  assets/admin.{js,css}
```

See [`tasks.md`](./tasks.md) for the implementation roadmap (P0–P2).
