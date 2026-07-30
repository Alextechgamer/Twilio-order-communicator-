# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls on Local Pickup orders via Twilio.

**Current version: 1.3.0**

## Install

Upload `twilio-order-communicator/` (or the release zip) to `/wp-content/plugins/`, activate, then configure under **WooCommerce → Order Communicator → Settings**.

Release zip: [`twilio-order-communicator-1.3.0.zip`](./twilio-order-communicator-1.3.0.zip)

## What’s in 1.3.0

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
  assets/admin.{js,css}
  includes/
    class-toc-twilio.php          REST SMS/calls + TwiML + merge tags
    class-toc-webhooks.php        inbound SMS + status callbacks
    class-toc-logger.php          communications table
    class-toc-order-meta.php      order chat UI
    class-toc-admin.php           settings, bulk, tools
    class-toc-auto.php            completed → auto notify
```

## Version history (short)

- **1.3.0** — merge tags, auto-once, STOP/HELP/START, consent force warn, pickup match, SMS status callbacks  
- **1.2.2** — Bulk Pickup Reminders overhaul  
- **1.2.1** — checkbox save, Twilio signature validation, tokenized TwiML, connection test, HPOS links, uninstall  

## Possible next work

Quiet hours for auto voice · dashboard pagination · packaging/release automation
