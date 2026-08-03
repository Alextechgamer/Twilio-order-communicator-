=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce product options, live logo/mockup placement, and print-ready exports.

== Description ==

StoreCanvas is a self-hosted WooCommerce module for product options, live mockup placement, and print-ready validation/exports.

== Changelog ==

= 0.7.0 =
* Text layers on canvas (content, font size, fill, family)
* Clip-art / design library (CPT + product allow-list)
* Guest save design (token/transient, optional email link)
* Text rasterization in print composites (bundled TTF or GD fallback)

= 0.6.0 =
* Visual print-area editor on product admin (drag/resize overlay, % coords)
* Bleed + color-mode validation (bleed_pct, require_rgb, strict_bleed)
* Bulk download all order print files as ZIP
* Optional customer proof email (once per order, default off)

= 0.5.0 =
* Rotation UI (buttons + canvas handle)
* Option conditionals (show_if)
* Multi-layer artwork with reorder
* Order print sheet (HTML to PDF via browser)
* Customer journey debug log (WooCommerce - SC Journey)
* Safe-margin guides on canvas
* Saved designs for logged-in users

= 0.4.0 =
* Print-ready validation with estimated DPI
* Artwork sideload on add-to-cart
* GD composite PNG per view on order
* Admin download links

= 0.3.0 =
* Constrain artwork to print area
* Corner resize handles + wheel scale
* Per-view placements

= 0.2.0 =
* Visual product admin + option cart pricing

= 0.1.0-scaffold =
* Initial scaffold
