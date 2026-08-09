# Orderbay 0.7.0

Self-hosted WooCommerce ops toolkit (independent of Twilio Order Communicator and StoreCanvas).

## 0.7.0

1. **Multi-carrier tracking URLs** — UPS/USPS/FedEx/DHL/Custom templates with `{tracking}`; carrier select on order; links on packing slip, invoice, admin, My Account
2. **Bulk print invoices** — orders list (HPOS + legacy); page-break HTML; empty selection notice
3. **Staff attention digest** — when digest enabled, lists open `_ob_needs_attention` orders (number, status, age, RMA/SLA hint)
4. **Customer packing slip** — My Account (default **off**); owner + nonce
5. **Admin order search** — matches `_ob_invoice_number`, `_ob_rma_number`, `_ob_tracking_number` (HPOS + legacy)

## Defaults

New customer-facing options default **off**. Tracking email remains default off.

## Uninstall

Removes `ob_*` options only. Keeps order/product meta.

## License

GPLv2 or later.
