# Orderbay 0.2.0

Self-hosted **WooCommerce ops toolkit** — documents, order operations, email rules, catalog helpers, dashboard.

Independent of Twilio Order Communicator (SMS/voice) and StoreCanvas (personalization). Soft SC dependency only for dashboard “custom art” count.

## What’s new in 0.2.0

- **Bulk print** invoices / packing slips (page-break HTML; browser → PDF)
- **Paper size** Letter/A4 CSS; polished templates (logo, totals, notes)
- **Email rules UI** — add/edit/delete, merge tags, once-per-rule meta guard
- **Low stock** daily throttle per product
- **Order ops** — attention badge, bulk add tag
- **Catalog** — percent or fixed bulk price with result count; safer duplicate
- **Dashboard** — getting-started blurb when unconfigured

## Modules

| | Module | Capability |
|---|--------|------------|
| A | Documents | HTML print sheets; bulk print; Open PDF print view |
| B | Order ops | Tags, attention, bulk status/notes/tags |
| C | Notifications | Status email rules + low stock (`wp_mail`) |
| D | Catalog | Bulk price/stock/category; duplicate product |
| E | Dashboard | Counts + links; optional SC art count |

## PDF path

No Dompdf/TCPDF. Primary path: **Open print view → browser Print → Save as PDF**.

## Install

`orderbay/` → `wp-content/plugins/orderbay/` → activate.

## Uninstall

Removes `ob_*` options + low-stock transients. Keeps order/product meta.

## License

GPLv2 or later.
