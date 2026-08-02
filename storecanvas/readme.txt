=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce product options, live logo/mockup placement, and print-ready exports. Self-hosted personalization for any printable product.

== Description ==

StoreCanvas is a self-hosted WooCommerce module for:

* Product options / add-ons (dropdowns, text, file upload, pricing, conditionals)
* Live mockup placement (upload logo → drag on product views)
* Print-ready validation and exports (Phase C)

Works with any printable product you configure — shirts, hats, mugs, bags, etc. No third-party mockup SaaS.

== Changelog ==

= 0.3.0 =
* Canvas: constrain artwork to active print area
* Corner resize handles + scroll-wheel scale
* Per-view placements (Front/Back independent); all saved in sc_placement
* Touch-friendly drag on the canvas

= 0.2.0 =
* Visual product admin: add/remove option fields, views (media picker), print areas
* Option pricing (flat / percent / per qty / per character) applied on the cart line
* Hidden JSON kept in sync for compatibility
* Cart/order meta: sc_options, sc_placement, sc_price_extra

= 0.1.0-scaffold =
* Initial scaffold: shared data model for options + customizer
* Product data tab JSON editors, front-end options + canvas shell
* Cart/order meta capture for options and placement
