=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

Self-hosted WooCommerce personalization: product options, live mockup, print-ready, library, guest save.

== Description ==

Product options parity with common add-ons plugins plus live mockup and print composites (PHP GD).

Independent of Twilio Order Communicator and Orderbay.

== Changelog ==

= 1.5.0 =
* New: one-click Fancy Product Designer (FPD) importer — paste an exported FPD product JSON on the product screen to import its views, print areas (converted to stage-relative percentages) and text fields into StoreCanvas
* View images are re-uploaded to the media library; unmapped image/clip-art elements and missing print boxes are reported so you can finish the setup
* Note: FPD's export schema varies by version — review the imported result before selling. The mapping core is unit-tested; image sideload runs on import

= 1.4.0 =
* New: multi-rule conditional logic on option fields — AND/OR with operators (is, is_not, contains, not_contains, gt, gte, lt, lte, empty, not_empty, in), extending the previous single show_if equality
* New: lookup-table pricing (price_type "lookup") — each choice carries its own price; multi-selects sum the selected choices; the live price preview mirrors the server
* Note: advanced conditions and per-choice prices are authored via the field JSON config and are fully sanitized server-side

= 1.3.1 =
* Fix: percent-priced options now use the selected variation's price as the base instead of the parent product's, so variable products with per-variation prices are charged correctly
* Fix: qty-priced options now charge the amount times the quantity entered in the field (previously behaved like a flat fee)
* Fix: a negative options total (a discount) now actually reduces the price (floored at zero) and is shown in the cart, so the displayed price matches what is charged; the live price preview mirrors the server rules
* Pure pricing helper SC_Cart_Order::price_for() is unit-tested

= 1.3.0 =
* New: true print resolution — generated PNG composites now carry a pHYs DPI chunk (default 300, filter sc_output_dpi) instead of defaulting to 72
* New: per-composite SVG export (print-dimensioned, embeds the image, bleed guide) and a minimal single-image PDF export from the order screen
* Note: PDF export is RGB/flattened (not CMYK/PDF-X); true vector-text export is a later step

= 1.2.1 =
* Security: fix a no-op client-side escaper that allowed stored/DOM XSS from saved-design content; add server-side design-payload sanitization
* Security: restrict design-layer attachment references to the customer's own uploads / plugin clip-art / staff (closes an arbitrary-attachment inclusion IDOR)
* Security: exclude uploaded artwork and generated print files from the public REST media endpoint
* Abuse: rate-limit and size-cap the guest design-save and email-link endpoints
* Hardening: reject decompression-bomb images via a megapixel ceiling on upload validation and GD load
* Fix: apply image-layer rotation in the generated print composite

= 1.2.0 =
* Expanded field types: date, color, number, email, phone, multi_select, image_choice
* Global option groups (CPT sc_option_group) by product/category; local id override
* Required, min/max chars, number min/max/step, defaults
* Role visibility, variation targeting, optional per-choice stock
* Cart/order human-readable option labels

= 1.1.0 =
* Design rehydrate, text print quality, production queue, admin UX

= 1.0.0 =
* Stable polish

== Upgrade Notice ==

= 1.2.0 =
Option field JSON remains backward compatible; new keys are optional.
