# StoreCanvas

Self-hosted WooCommerce **product options** + **live mockup placement** + **print-ready exports**.

Version: **0.2.0**

## What’s in 0.2.0

- Visual product admin: add/remove **option fields**, **views** (with media library picker), **print areas**
- Option **pricing** (flat / percent / per qty unit / per character) applied on the cart line
- Hidden JSON still saved for compatibility; UI keeps it in sync

## Modules

| Module | Status |
|--------|--------|
| Product Options (A) | Visual builder + cart pricing |
| Live Customizer (B) | Views/areas UI + canvas drag shell |
| Print-ready (C) | Stub — `validate_source()`; composite TBD |

## Meta keys

- `_sc_options` — `{ fields: [] }`
- `_sc_customizer` — `{ enabled, views[], areas[] }`
- `_sc_validation` — DPI / size rules

Cart/order: `sc_options`, `sc_placement`, `sc_attachments`, `sc_price_extra`.

## Install

1. Copy `storecanvas/` into `wp-content/plugins/`
2. Activate **StoreCanvas**
3. Edit a product → **StoreCanvas** tab

Twilio Order Communicator remains a separate plugin in this monorepo.
