=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WooCommerce personalization: product options, live mockup, print-ready exports, library, guest save.

== Description ==

StoreCanvas is a self-hosted WooCommerce module for product options (with pricing), live mockup placement (images, text, clip-art), print-ready validation and GD composites, guest design save, live price updates, and admin print tools.

Requires PHP GD for print composites. Works with classic and block-friendly setups via shortcodes.

== Installation ==

1. Upload the `storecanvas` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Configure products on the StoreCanvas product data tab

== Shortcodes ==

* `[storecanvas_options]` — option fields on the product page
* `[storecanvas_customizer]` — live mockup panel

== Uninstall ==

Removes plugin options, guest design transients, and the journey debug table. Does not remove order/product meta, media, or library/design CPT posts.

== Changelog ==

= 1.0.0 =
* First stable release
* Docs, uninstall.php, GD soft-fail notice, AJAX/meta key documentation
* Packaging for WordPress install (storecanvas/ root)

= 0.8.0 =
* Live product-page price updates from option extras
* Block cart/checkout compatibility + shortcodes
* Orders list SC Art column and filter (HPOS + legacy)

= 0.7.0 =
* Text layers; clip-art library; guest save design
* Text rasterization in print composites (bundled TTF or GD fallback)

= 0.6.0 =
* Visual print-area editor; bleed/color validation
* Bulk order print ZIP; optional customer proof email

= 0.5.0 =
* Rotation; show_if conditionals; multi-layer art
* Print sheet; journey log; safe margins; saved designs (logged-in)

= 0.4.0 =
* Print-ready DPI validation; artwork sideload; GD composites; admin downloads

= 0.3.0 =
* Constrain art to print area; corner resize; wheel scale; multi-view placements

= 0.2.0 =
* Visual product admin builders; option cart pricing

= 0.1.0-scaffold =
* Initial scaffold
