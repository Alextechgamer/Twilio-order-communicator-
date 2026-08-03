# StoreCanvas

Self-hosted WooCommerce **product options** + **live mockup placement** + **print-ready exports**.

Version: **0.6.0**

## What's in 0.6.0 (production path)

1. **Visual print-area editor** — product admin overlay to drag/resize areas on the view image
2. **Bleed + color-mode checks** — bleed_pct, require_rgb, strict_bleed on upload/cart
3. **Bulk ZIP download** — all order print files + manifest
4. **Customer proof email** — optional, default off, once-per-order

## Also included (0.2–0.5)

- Visual product options + pricing
- Live canvas customizer (constrain, resize, rotate, multi-layer, safe margins)
- Print composites (GD), print sheet, journey log, saved designs

## Requirements

- WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+
- **PHP GD** for composites
- **ZipArchive** recommended for bulk download

## Install

Copy `storecanvas/` → `wp-content/plugins/`, activate, configure product **StoreCanvas** tab.
