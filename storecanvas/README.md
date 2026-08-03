# StoreCanvas

Self-hosted WooCommerce **product options** + **live mockup placement** + **print-ready exports**.

Version: **0.7.0**

## What's in 0.7.0

1. **Text layers** — add text on the canvas; place/scale/rotate like image layers; GD composite with bundled TTF
2. **Clip-art library** — self-hosted media library CPT; product allow-list; front thumbnails add layers
3. **Guest save design** — save without login via signed token/transient (≥14 days); email me a link; logged-in CPT unchanged

## Requirements

- WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+
- PHP GD (+ FreeType recommended for text quality)

## Install

Copy `storecanvas/` → `wp-content/plugins/`, activate, configure product **StoreCanvas** tab.
