# Orderbay 0.1.0

Self-hosted **WooCommerce ops toolkit** — documents, order operations, email rules, catalog helpers, and an ops dashboard.

Independent of:

- **Twilio Order Communicator** (SMS/voice)
- **StoreCanvas** (product personalization) — soft dependency only for dashboard “custom art” count

## Modules

| | Module | Capability |
|---|--------|------------|
| A | Documents | Invoice + packing slip HTML print sheets; logo/from/footer settings |
| B | Order ops | Tags, needs attention, list columns/filters, bulk status/notes/attention |
| C | Notifications | Status → email rules (wp_mail); low-stock threshold alerts |
| D | Catalog | Bulk price/stock/category; duplicate product (skips StoreCanvas meta) |
| E | Dashboard | Today / processing / attention counts; optional SC art count |

## Requirements

WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

## Install

Copy `orderbay/` → `wp-content/plugins/orderbay/` and activate.

## Uninstall

Removes `ob_*` options and low-stock transients only. Order/product meta is kept.

## License

GPLv2 or later.
