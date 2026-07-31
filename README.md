# Twilio Order Communicator

WordPress / WooCommerce plugin for SMS and voice calls via **your own Twilio account**. Order communication is driven by custom statuses (**Ready for Pickup** and **Shipped**), with consent-aware SMS, quiet hours, bulk reminders, and order chat history.

**Current version: 1.8.1**

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

- [`twilio-order-communicator-1.8.1.zip`](./twilio-order-communicator-1.8.1.zip) — current release (G1–G4 audit fixes)
- [`twilio-order-communicator-1.8.0.zip`](./twilio-order-communicator-1.8.0.zip) — licensing release

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
license-server/
  public/index.php
  bin/create-key.php
  bin/add-release.php
  README.md
```

See [`tasks.md`](./tasks.md) for the roadmap.
