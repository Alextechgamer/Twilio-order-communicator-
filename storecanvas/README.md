# StoreCanvas 1.1.0

Self-hosted WooCommerce **product personalization**: options with pricing, live mockup placement, print-ready composites, clip-art library, guest design save, live price updates, production queue, and admin print tools.

## Features

| Area | Capability |
|------|------------|
| Options | Select/radio/checkbox/text/… with flat, percent, qty, per-char pricing and `show_if` conditionals |
| Live mockup | Multi-view canvas, multi-layer art + text (stroke optional), constrain/resize/rotate |
| Design save | Guest token (14d) + logged-in CPT; **full rehydrate** from `src` / `attachment_id` |
| Print-ready | DPI/bleed/RGB checks, GD composites, font mapping, scaled text, admin downloads, bulk ZIP |
| Library | Self-hosted clip-art CPT; product allow-list |
| Live price | Client-side total updates from option extras |
| Blocks | HPOS + cart/checkout blocks; shortcodes `[storecanvas_options]` / `[storecanvas_customizer]` |
| Orders | **SC Art** column + filter; **StoreCanvas Queue** (print workflow) |
| Preview | `sc_preview_id` thumbnail on order items + queue |

## Requirements

- WordPress **6.0+**, WooCommerce **7.0+**, PHP **7.4+**
- **PHP GD** (+ FreeType recommended for quality text)

## Install

1. Upload `storecanvas/` → `wp-content/plugins/`
2. Activate **StoreCanvas**
3. Product → **StoreCanvas** tab: enable mockup, add views/areas/options
4. WooCommerce → **StoreCanvas Queue** for production

## Shortcodes

- `[storecanvas_options]`
- `[storecanvas_customizer]`

## Cart / order meta keys

| Key | Meaning |
|-----|---------|
| `sc_options` | Option values |
| `sc_placement` | Placement JSON |
| `sc_layers` | Layer stack (image/text/clipart; include `src` / `attachment_id` for rehydrate) |
| `sc_attachments` | Sideloaded artwork |
| `sc_price_extra` | Unit extras |
| `sc_print_files` | Composite attachment ids per view |
| `sc_preview_id` | Small PNG preview attachment |
| `_sc_artwork_id` | Original artwork |
| `_sc_has_custom_art` | Order stamp |
| `_sc_printed_at` | Marked printed (queue) |

## Design payload (rehydrate)

Image/clipart layers should include:

```json
{ "type": "image", "src": "https://…", "attachment_id": 123, "placements": { … } }
```

Text layers:

```json
{ "type": "text", "content": "…", "fontSize": 28, "fill": "#111", "fontFamily": "Arial…",
  "strokeColor": "#000", "strokeWidth": 0, "placements": { … } }
```

## Fonts

Bundled under `assets/fonts/`:

- `sc-sans.ttf` — default sans
- `sc-sans-bold.ttf` — bold/Impact mapping
- `sc-serif.ttf` — Georgia/Times mapping

## Uninstall

Removes options, guest design transients, journey table. Does **not** delete order/product meta, media, or CPT posts.

## License

GPLv2 or later.
