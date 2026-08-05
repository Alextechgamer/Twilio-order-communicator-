=== Orderbay ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WooCommerce ops toolkit: invoices/packing slips, order ops, email rules, catalog helpers, dashboard.

== Description ==

Orderbay is a self-hosted operations toolkit for WooCommerce. It does not send SMS/voice (see Twilio Order Communicator) and does not personalize products (see StoreCanvas).

Features:
* Print-friendly invoices and packing slips (HTML → browser PDF)
* Order tags, needs-attention flag, bulk status/notes
* Status email rules + low-stock alerts via wp_mail
* Product bulk price/stock/category + duplicate product
* Ops dashboard with optional StoreCanvas art count

== Installation ==

1. Upload the `orderbay` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Open Orderbay in the admin menu

== Changelog ==

= 0.1.0 =
* Scaffold: documents, order ops, notifications, catalog helpers, dashboard
* HPOS + cart_checkout_blocks compatibility declarations
