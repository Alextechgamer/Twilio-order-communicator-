# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls on Local Pickup orders via Twilio.

**Current version: 1.5.0**

## Install

Upload `twilio-order-communicator/` (or a release zip) to `/wp-content/plugins/`, activate, then open **WooCommerce → Order Communicator → Setup**.

Release zips:

- [`twilio-order-communicator-1.5.0.zip`](./twilio-order-communicator-1.5.0.zip)
- [`twilio-order-communicator-1.4.0.zip`](./twilio-order-communicator-1.4.0.zip)
- [`twilio-order-communicator-1.3.0.zip`](./twilio-order-communicator-1.3.0.zip)

## What's in 1.5.0

| Feature | Notes |
|---------|--------|
| Checkout SMS consent | Built-in checkbox for classic + block checkout; stores `_toc_sms_consent` + timestamp/IP |
| Quiet hours | Defers auto voice/SMS until the window ends (store timezone) |
| Setup wizard | Credentials → connection test → webhook → consent → auto notify |

## What's in 1.4.0

HPOS declare, brand headers, i18n, dashboard pagination, START/UNSTOP only, opt-out DB table, activation defaults, PHPCS tooling.

## Auto SMS troubleshooting

**Also send an SMS** defaults to **off**. Enable it under Settings, confirm consent, clear `_toc_auto_notified_at` to re-test. Quiet hours may defer sends — check order notes.

## Plugin layout

```
twilio-order-communicator/
  twilio-order-communicator.php
  includes/
    class-toc-checkout.php      checkout consent
    class-toc-onboarding.php    setup wizard
    class-toc-auto.php          completed + quiet hours
    class-toc-twilio.php
    class-toc-webhooks.php
    class-toc-logger.php
    class-toc-order-meta.php
    class-toc-admin.php
  assets/admin.{js,css}
```

## Next (2.0 / commercial)

License keys + auto-updates · scheduled reminders · CSV/analytics · marketing site
