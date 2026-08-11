=== StoreCanvas ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later

Self-hosted WooCommerce personalization: product options, live mockup, print-ready, library, guest save.

== Description ==

Product options parity with common add-ons plugins plus live mockup and print composites (PHP GD).

Independent of Twilio Order Communicator and Orderbay.

== Changelog ==

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
