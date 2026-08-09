# Orderbay 1.0.0

Self-hosted **WooCommerce ops toolkit** — invoices, packing slips, fulfillment, RMA, digests, catalog helpers, and dashboard.

**Independent of** [Twilio Order Communicator](../twilio-order-communicator/) (SMS/voice) and [StoreCanvas](../storecanvas/) (product personalization). Soft detection only for StoreCanvas custom-art counts on the dashboard.

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 7.0+ |
| PHP | 7.4+ |
| PDF | Browser Print → Save as PDF (no Dompdf) |

HPOS and cart/checkout blocks compatibility are declared.

## Install

1. Copy `orderbay/` into `wp-content/plugins/orderbay/`
2. Activate **Orderbay**
3. WooCommerce → Orderbay (or Orderbay menu) → Documents / Fulfillment

## Feature matrix (0.1 → 1.0)

| Ver | Highlights |
|-----|------------|
| 0.1 | Scaffold A–E: documents, order ops, notifications, catalog, dashboard |
| 0.2 | Bulk print, email rules UI, ops/catalog polish |
| 0.3 | Staff digests, CSV export, print polish |
| 0.4 | Invoice numbers, pick list, tracking meta, credit notes, auto-attention |
| 0.5 | RMA slips, SLA aging, VAT/thumbs/gift polish, note templates |
| 0.6 | Customer RMA, Code 128 barcodes, bin locations, partial fulfill |
| 0.7 | Multi-carrier tracking URLs, bulk invoices, attention digest, customer packing, meta search |
| **1.0** | Stable polish: docs, uninstall audit, admin empty-states |

## Safe defaults (customer-facing OFF)

- Customer RMA requests — off  
- Document barcodes — off  
- Staff digest — off  
- Tracking email — off  
- Customer packing slip — off  

Bulk print / export / pick list require **`edit_shop_orders`**. Document settings require **`manage_woocommerce`**.

## Order / product meta keys (preserved on uninstall)

| Meta | Purpose |
|------|---------|
| `_ob_invoice_number` | Immutable invoice # |
| `_ob_credit_note_number` | Credit note # |
| `_ob_tracking_number` | Tracking number |
| `_ob_tracking_url` | Manual track URL override |
| `_ob_tracking_carrier` | Carrier id for URL templates |
| `_ob_tracking_emailed_at` | Tracking email once-guard |
| `_ob_rma_status` / `_ob_rma_number` / `_ob_rma_reason` | RMA |
| `_ob_needs_attention` | Attention flag |
| `_ob_order_tags` | Order tags |
| `_ob_sla_aged_at` | SLA once-guard |
| `_ob_bin_location` | Product bin (product meta) |
| `_ob_qty_fulfilled` | Line qty fulfilled |
| `_ob_fulfillment_status` | open\|partial\|complete |

## Uninstall

Deletes `ob_*` **options** and unschedules crons. **Does not** delete order/product meta or media.

## Capabilities

| Action | Cap |
|--------|-----|
| Dashboard, bulk print, pick list, export | `edit_shop_orders` |
| Settings (docs, fulfillment, rules, digest) | `manage_woocommerce` |
| Customer invoice / packing | Logged-in order owner + nonce |

## License

GPLv2 or later.
