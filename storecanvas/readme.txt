=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later

Self-hosted WooCommerce personalization: product options, live mockup, print-ready, library, guest save.

== Description ==

StoreCanvas is a self-hosted product personalizer and options engine for WooCommerce — a live design canvas plus a deep options/pricing engine, with a one-time license, no per-order fees, and nothing that can be sunset from a server you don't control.

* Live multi-view design canvas: text, image and clip-art layers with placement, scale and rotation
* Deep product options: 15 field types, per-choice/lookup pricing, percent/qty/per-character pricing, multi-rule conditional logic (AND/OR + operators)
* Print-ready output: true-DPI PNG (pHYs), per-composite SVG and a minimal single-image PDF at physical size with bleed guides
* Production workflow: print queue, bulk ZIP download, proof-approval email, guest design save
* One-click Fancy Product Designer importer (map an exported FPD product into a StoreCanvas config)
* Prebuilt product templates (T-shirt, Mug, Sticker, Sign) to start from
* Security-first: per-customer artwork authorization, private print files, upload/decompression guards, guest rate limits
* HPOS (custom order tables) compatible

Independent of OrderRing and Orderbay.

== Installation ==

1. Upload the `storecanvas` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active; PHP GD is recommended for print composites)
3. Edit a product → use the StoreCanvas boxes to enable the customizer (views + print areas) and add option fields, or click "Start from a template"
4. Optionally paste a Fancy Product Designer export to import an existing product

== Frequently Asked Questions ==

= Do I need PHP GD? =
GD is used to generate server-side print composites. Product options and the live mockup work without it; if GD is unavailable, composite generation is skipped with a notice.

= Is there a per-order or per-seat fee? =
No. StoreCanvas is self-hosted with no per-order fees and no cloud dependency — you own your artwork and pipeline.

= Can I migrate from Fancy Product Designer? =
Yes. Paste an exported FPD product JSON on the product screen and StoreCanvas maps its views, print zones and text fields into a config. Review the result and set the product view image before selling.

= What print output does it produce? =
True-to-size PNG with a DPI (pHYs) chunk, an SVG at physical millimetre size with bleed guides, and a minimal single-image PDF. **Color/DPI disclaimer:** output is RGB (PDFs are flattened RGB, not CMYK or PDF-X). Estimated DPI is pixel size vs target print width — confirm color and resolution with your print provider before production.

= Are customer artwork files private? =
Uploaded artwork and generated print composites are marked and kept out of the public REST media listing, and StoreCanvas surfaces them through a signed, time-limited, capability-checked download proxy instead of a permanent public URL. To also block direct access to the underlying wp-content/uploads path (defense in depth), apply the Apache/nginx deny rules in docs/storecanvas-artwork-privacy.md.

= Is it compatible with HPOS? =
Yes, StoreCanvas declares and supports WooCommerce High-Performance Order Storage.

= Do I need a license key? =
New installs include a 30-day trial of premium updates. After the trial, a key is required only for those updates. The designer, options, and production tools keep working without a license — we do not disable built-in features.

== Changelog ==


= 1.9.0 =
* New: 30-day trial of premium updates starts on first admin visit (override with SC_TRIAL_DAYS)
* License screen shows days remaining / trial end date; a nag appears in the last week and after expiry
* Designer, options, and production tools stay available after the trial — only premium updates pause without a key
= 1.8.0 =
* Admin: StoreCanvas is now its own top-level menu (Overview, Production queue, Option groups, Saved designs, Library, Journey, Proof email, License)
* Admin: modern Overview dashboard with stats
* License: activate a key for premium StoreCanvas updates (same server as OrderRing)
* Removed the extra WooCommerce submenu items that were cluttering the WooCommerce menu

= 1.7.2 =
* Docs: color/DPI fidelity disclaimer on the product Print validation box and the production queue (RGB / flattened, not CMYK/PDF-X; confirm with your print provider)

= 1.7.1 =
* Fix: the customer artwork preview on the admin order screen and the production queue now goes through the signed download proxy too, instead of emitting a raw wp-content/uploads URL (completes the 1.7.0 artwork-privacy work)
* Fix: the production queue no longer triggers a "meta_query is not supported" notice on the classic (non-HPOS) order datastore; the meta filter is used only under HPOS
* Fix: uninstall now also removes the sc_dl_secret and sc_artwork_backfilled options

= 1.7.0 =
* Security: customer artwork and print composites are served through a signed, capability-checked download proxy instead of a permanent public uploads URL; a one-time backfill marks pre-existing artwork so the REST media filter and proxy cover it too (see docs/storecanvas-artwork-privacy.md for the host-level deny step)
* Security: the FPD importer validates each view image URL before sideloading it server-side, blocking SSRF to private/reserved hosts (loopback, RFC1918, link-local incl. cloud metadata, IPv6 ::1)
* Security: the guest "email me a design link" AJAX no longer echoes the design URL/token back in its JSON response (the link goes only to the emailed address)

= 1.6.1 =
* Docs: complete the readme (description, installation, FAQ, feature list)
* Tests: pin the option field-row sanitizer with unit tests (no code change)

= 1.6.0 =
* New: prebuilt product templates (T-shirt, Mug, Sticker, Sign/Poster) — a "Start from a template" box on the product screen seeds a working print area + option fields with one click
* Templates seed structure (views, print areas, options), not artwork — set the product's view image to finish
* Reuses the option sanitizer so imported fields are validated on save

= 1.5.1 =
* Fix: load_plugin_textdomain was missing, so bundled translations never loaded — now registered on init
* i18n: move the hardcoded English strings in the customizer JS into the localized scCustomizer.i18n table (English fallback preserved)
* i18n: ship a translation template (languages/storecanvas.pot)

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
