# StoreCanvas

Self-hosted WooCommerce **product options** + **live mockup placement** + **print-ready exports**.

Version: **0.4.0**

## What’s in 0.4.0 (Phase C)

- **Artwork upload** on add-to-cart (`sc_artwork`) → media library
- **Validation**: file size, MIME, min pixels, **estimated DPI** vs target print width
- **Print composite** (GD): base view + placed art → PNG attachment on the order line
- **Admin downloads**: original artwork + per-view composites on the order screen

## Requirements

- PHP **GD** extension for composite generation

## Modules

| Module | Status |
|--------|--------|
| Product Options (A) | 0.2 visual + pricing |
| Live Customizer (B) | 0.3 canvas polish |
| Print-ready (C) | **0.4** validation + composite |

## Install

Copy `storecanvas/` → `wp-content/plugins/`, activate, configure product **StoreCanvas** tab.
