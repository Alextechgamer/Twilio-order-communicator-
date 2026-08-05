# Orderbay 0.4.0

Self-hosted **WooCommerce ops + fulfillment** toolkit.

Independent of Twilio Order Communicator (SMS/voice) and StoreCanvas.

## 0.4.0

1. **Sequential invoice numbers** — `INV-` + counter; immutable `_ob_invoice_number`
2. **Customer My Account invoice** — logged-in owner only
3. **Warehouse pick list** — bulk HTML by SKU
4. **Tracking meta + optional email** — default off; once-guard; not Twilio
5. **Credit notes** — refund-aware print template + `CN-` numbers
6. **Auto-attention** — optional status list sets `_ob_needs_attention` (never auto-clears)

## Modules

Documents · Fulfillment · Order ops · Notifications · Digest · Export · Catalog · Dashboard

## PDF

Browser Print → Save as PDF (no Dompdf).

## Install

`orderbay/` → `wp-content/plugins/orderbay/` → activate.

## Uninstall

Deletes `ob_*` options and counters. **Keeps** order meta (invoice/tracking/etc.).

## License

GPLv2 or later.
