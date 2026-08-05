# Orderbay 0.5.0

Self-hosted WooCommerce ops + fulfillment + light returns toolkit.

Independent of Twilio Order Communicator and StoreCanvas.

## 0.5.0

1. **RMA / returns** — status, reason, sequential RMA numbers, print slip; optional attention on request
2. **SLA aging** — hourly cron (default off) flags old processing/on-hold orders
3. **Doc polish** — VAT/Tax ID on invoice; optional packing thumbnails; gift message soft-detect
4. **Note templates** — up to 8 canned private order notes

## Modules

Documents · Fulfillment · RMA · SLA · Notes · Order ops · Notifications · Digest · Export · Catalog · Dashboard

## Install

`orderbay/` → `wp-content/plugins/orderbay/` → activate.

## Uninstall

Removes options + crons. **Keeps** all order meta (including RMA).

## License

GPLv2 or later.
