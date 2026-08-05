=== Orderbay ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WooCommerce ops toolkit: invoices/packing slips, order ops, email rules, catalog helpers, dashboard.

== Description ==

Orderbay is a self-hosted operations toolkit for WooCommerce. It does not send SMS/voice (Twilio Order Communicator) and does not personalize products (StoreCanvas).

PDF export uses the browser print dialog (no Dompdf dependency).

== Installation ==

1. Upload `orderbay` to `/wp-content/plugins/`
2. Activate through Plugins
3. Open Orderbay in the admin menu

== Changelog ==

= 0.2.0 =
* Bulk print invoices / packing slips (HTML page-breaks)
* Document settings: paper size Letter/A4; polished templates
* Email rules CRUD UI with rule ids + once-guard meta
* More merge tags; low-stock daily throttle
* Order attention badge; bulk add tag
* Catalog percent/fixed price bulk; safer product duplicate
* Dashboard getting-started blurb

= 0.1.0 =
* Scaffold: documents, order ops, notifications, catalog, dashboard
* HPOS + cart_checkout_blocks compatibility
