# Orderbay 0.6.0

Self-hosted WooCommerce ops + fulfillment + returns toolkit.

Independent of Twilio Order Communicator and StoreCanvas.

## 0.6.0

1. **Customer RMA request** on My Account (default **off**); owner + nonce; staff still approves
2. **Code 128 barcodes** (pure PHP → SVG; default off) on invoice / packing / RMA
3. **Bin / location** product meta for pick lists (sort bin then SKU)
4. **Partial fulfillment** — per-line `_ob_qty_fulfilled`; packing shows done/left

### Barcode approach

No Composer. Pure-PHP Code 128B encoder renders inline SVG bars. If encoding fails, falls back to monospace `*ORDER*` style text.

## Install

`orderbay/` → `wp-content/plugins/orderbay/` → activate.

## Uninstall

Removes options. **Keeps** order/product meta (RMA, bins, fulfillment).

## License

GPLv2 or later.
