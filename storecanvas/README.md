# StoreCanvas 1.0.0

Self-hosted WooCommerce **product personalization**: options with pricing, live mockup placement, print-ready composites, clip-art library, guest design save, live price updates, and admin print tools.

## Features (1.0)

| Area | Capability |
|------|------------|
| Options | Select/radio/checkbox/text/… with flat, percent, qty, per-char pricing and `show_if` conditionals |
| Live mockup | Multi-view canvas, constrain to print area, resize/rotate, multi-layer art + text layers |
| Print-ready | DPI/bleed/RGB checks, GD composites per view, admin downloads, bulk ZIP, print sheet |
| Library | Self-hosted clip-art CPT; product allow-list; front Library panel |
| Designs | Logged-in CPT save; **guest** save via token/cookie (14 days) + email link |
| Live price | Client-side total updates from option extras (no reload) |
| Blocks | HPOS + cart/checkout blocks compatibility; shortcodes for block themes |
| Orders admin | **SC Art** column + “Has StoreCanvas art” filter |

## Requirements

- WordPress **6.0+**
- WooCommerce **7.0+**
- PHP **7.4+**
- **PHP GD** (recommended FreeType for quality text) — without GD, options/mockup still work; composites skip with order notes / admin notice

## Install

1. Upload the `storecanvas/` folder to `wp-content/plugins/`
2. Activate **StoreCanvas** under Plugins
3. Edit a product → **StoreCanvas** tab: enable mockup, add views/areas/options
4. Optional: WooCommerce → **StoreCanvas library** (clip-art), **SC Proof Email**, **SC Journey**

### From release zip

Unzip so the path is `wp-content/plugins/storecanvas/storecanvas.php` (root folder must be `storecanvas/`).

## Shortcodes (block themes)

| Shortcode | Purpose |
|-----------|---------|
| `[storecanvas_options]` | Product option fields on single product |
| `[storecanvas_customizer]` | Live mockup canvas when enabled for the product |

Classic themes use `woocommerce_before_add_to_cart_button` automatically.

## Cart / order meta keys

Defined on `SC_Plugin`:

| Constant | Key | Meaning |
|----------|-----|---------|
| `CART_OPTIONS` | `sc_options` | Selected option values |
| `CART_PLACEMENT` | `sc_placement` | Active placement JSON |
| `CART_LAYERS` | `sc_layers` | Layer stack (image/text/clipart) |
| `CART_ATTACHMENTS` | `sc_attachments` | Sideloaded artwork attachment ids |
| — | `sc_price_extra` | Unit price extras from options |
| — | `sc_print_files` | Composite attachment ids per view |
| — | `_sc_artwork_id` | Original artwork attachment |
| — | `_sc_has_custom_art` | Order-level stamp for admin filter |

Product meta: `_sc_options`, `_sc_customizer`, `_sc_validation`, `_sc_clipart_ids`.

## AJAX (public / nopriv)

All use nonces. Nopriv is intentional for:

- `sc_library_items` / `sc_list_library` — product library thumbnails
- `sc_save_design`, `sc_load_design`, `sc_email_design_link` — guest designs
- `sc_journey_log` — optional front debug log (when enabled)

Admin-only: print generate, bulk ZIP, print sheet, design list (logged-in), clipart save.

## Uninstall

Deleting the plugin runs `uninstall.php`, which removes:

- Options: `sc_proof_email_*`, `sc_journey_enabled`
- Guest design transients (`sc_gdesign_*`)
- Journey debug table `{prefix}sc_journey`

**Does not** delete order/product meta, media attachments, or CPT posts (`sc_clipart`, `sc_design`). Library and saved designs remain as content.

## License

GPLv2 or later.
