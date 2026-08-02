# StoreCanvas

Self-hosted WooCommerce **product options** + **live mockup placement** + **print-ready exports**.

Version: **0.1.0-scaffold** (A+B data model and shells; not production-complete).

## Modules

| Module | Status |
|--------|--------|
| Product Options (A) | Scaffold — field render + JSON admin + cart meta |
| Live Customizer (B) | Scaffold — views/areas config + canvas drag shell |
| Print-ready (C) | Stub — validate_source() partial; composite TBD |

## Meta keys

- `_sc_options` — `{ fields: [] }`
- `_sc_customizer` — `{ enabled, views[], areas[] }`
- `_sc_validation` — `{ min_dpi, max_upload_mb, allowed_mimes, min_source_px, safe_margin_pct }`

Cart/order item: `sc_options`, `sc_placement`, `sc_attachments`.

## Install (dev)

1. Copy `storecanvas/` into `wp-content/plugins/`
2. Activate **StoreCanvas**
3. Edit a product → **StoreCanvas** tab → enable mockup, paste views/areas JSON, add option fields JSON

Example view:

```json
[{ "id": "front", "label": "Front", "image_id": 123 }]
```

Example area (percent of image):

```json
[{ "id": "chest", "view_id": "front", "label": "Chest", "x": 30, "y": 25, "w": 40, "h": 35 }]
```

## Roadmap

- **0.2** Visual field builder + media picker for views; conditionals; option pricing into cart totals
- **0.3** Canvas polish (constrain to area, resize handles, multi-view art)
- **0.4** Upload to Media Library on add-to-cart; order preview thumbnails
- **1.0 / Phase C** High-res composite (Imagick/GD), DPI-at-print checks, admin download

Twilio Order Communicator remains a separate plugin in this monorepo until an optional Communications module is ported.

