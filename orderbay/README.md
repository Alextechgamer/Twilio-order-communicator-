# Orderbay 0.3.0

Self-hosted **WooCommerce ops toolkit** — documents, order ops, email rules, staff digests, CSV export, catalog helpers, dashboard.

Independent of Twilio Order Communicator and StoreCanvas.

## 0.3.0

- **Staff digest** — optional daily/weekly email (default off); WP-Cron `ob_digest_cron`; unschedules on deactivate/uninstall
- **CSV export** — orders list button + Orderbay → Export CSV tools (capability + nonce)
- **Print polish** — stronger page-breaks / print CSS; browser Save as PDF remains primary (no Dompdf)
- **Email rules** — validation (subject/body/custom email); rule IDs; TOC independence documented

## Modules

| Module | Capability |
|--------|------------|
| Documents | Invoice + packing slip HTML print / bulk print |
| Order ops | Tags, needs attention, bulk status/notes |
| Notifications | Status email rules + low stock (`wp_mail`) |
| Digest | Daily/weekly staff summary |
| Export | Orders CSV stream |
| Catalog | Bulk price/stock/category; duplicate |
| Dashboard | Ops counts + links |

## PDF

Open print view → browser **Print → Save as PDF**. No Composer PDF library.

## Install

`orderbay/` → `wp-content/plugins/orderbay/` → activate.

## Uninstall

Removes `ob_*` options, digest/stock crons, low-stock transients. Keeps order/product meta.

## License

GPLv2 or later.
