=== Orderbay ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WooCommerce ops toolkit: invoices, fulfillment, RMA, digests, CSV, search. Independent of Twilio Order Communicator and StoreCanvas.

== Description ==

Orderbay is a self-hosted ops toolkit for WooCommerce.

* HTML invoices / packing slips (browser PDF)
* Multi-carrier tracking URLs, pick lists, partial fulfill
* RMA meta + slips; customer request optional (default off)
* Staff digests, email rules, CSV export
* Admin search by invoice / RMA / tracking
* Needs-attention queue + SLA aging

Does not send SMS/voice (use Twilio Order Communicator separately). Does not personalize products (use StoreCanvas separately).

== Installation ==

1. Upload `orderbay` to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Configure Orderbay → Documents and Fulfillment

== Frequently Asked Questions ==

= Does uninstall delete invoice numbers? =
No. Order and product meta are preserved. Only plugin options and cron events are removed.

= Do customers get packing slips by default? =
No. My Account packing slip is opt-in (default off).

== Changelog ==

= 1.0.0 =
* Stable release polish: full docs, uninstall audit, admin empty-state / getting-started
* Confirmed safe defaults; HPOS + blocks declarations; no new product features

= 0.7.0 =
* Multi-carrier tracking URLs; bulk invoices; attention digest; customer packing; meta search

= 0.6.0 =
* Customer RMA; barcodes; bins; partial fulfill

= 0.5.0 =
* RMA slips; SLA aging; doc polish; note templates

= 0.4.0 =
* Invoice numbers; pick list; tracking; credit notes; auto-attention

= 0.3.0 =
* Digests; CSV export

= 0.2.0 =
* Bulk print; email rules UI

= 0.1.0 =
* Scaffold A–E
