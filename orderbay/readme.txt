=== OrderBay ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later

Self-hosted WooCommerce ops toolkit: documents, fulfillment, RMA, digests, search.

== Description ==

Orderbay is a self-hosted operations desk for WooCommerce — every order document plus the whole back-office workflow in one plugin, with no per-order fees and no second add-on to buy.

* Documents: invoice, proforma, credit note, packing slip, delivery note, shipping label, RMA slip, pick list
* Configurable, atomic gapless numbering ({PREFIX}{YYYY}{MM}{DD}{SEQ} tokens, {SEQ:n} padding, optional yearly/monthly reset)
* Per-tax-rate breakdown on invoices/proformas (EU VAT compliant) with an include/exclude note
* E-invoicing export: UBL 2.1 (Peppol BIS Billing 3.0) and CII (Factur-X EN16931); optional Factur-X PDF/A-3 assembly when a PDF engine + horstoeko/zugferd are present
* Fulfillment: tracking numbers + carrier URL templates, partial fulfillment, bin locations
* Returns/RMA workflow with per-line selection and optional customer status emails
* SLA aging, staff attention digests, low-stock alerts, an ops dashboard, and meta search
* Optional host PDF (Dompdf/TCPDF) when installed; the default is browser Print → Save as PDF (OS font stack, no font-subsetting traps)
* Theme template overrides (wp-content/themes/your-theme/orderbay/*.php) + document hooks
* HPOS (custom order tables) compatible

Independent of OrderRing and StoreCanvas.

== Installation ==

1. Upload the `orderbay` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active)
3. Open **OrderBay → Documents** to set your invoice prefix/format, seller details and options
4. Print documents from the order screen; assign tracking and manage returns from the order panels

== Frequently Asked Questions ==

= Do I need Dompdf or TCPDF? =
No. The default path is browser Print → Save as PDF, which uses the operating-system font stack (so Bengali/CJK/RTL render). If Dompdf or TCPDF is installed on the host, an optional server-side PDF download is offered.

= Is the invoice numbering safe for tax/legal use? =
Numbering is database-atomic (LAST_INSERT_ID) so concurrent prints never collide or skip. Formats always include a sequence token, and yearly/monthly reset is optional. **This is not tax advice** — confirm legal requirements with your accountant before using invoices in production.

= Does it do EU e-invoicing? =
It **exports** UBL 2.1 (Peppol BIS Billing 3.0) and CII (Factur-X EN16931), and can assemble a Factur-X PDF/A-3 when a PDF engine and the horstoeko/zugferd library are installed. It does **not** connect to the Peppol network or any PDP/access point — export the file and hand it to your access point. Validate output against an official validator before production.

= Can I customize the documents? =
Yes — copy any template from the plugin's `templates/` folder into `wp-content/themes/your-theme/orderbay/` and edit your copy; it survives updates. `ob_before_document` / `ob_after_document` actions and an `ob_locate_template` filter are also available.

= Is it compatible with HPOS? =
Yes, Orderbay declares and supports WooCommerce High-Performance Order Storage.

== Changelog ==

= 1.9.0 =
* Admin: OrderBay lives only in its own top-level menu (removed the extra WooCommerce submenu)
* Dashboard: new ops console — hero, colored stat cards, setup prompt, and grouped tiles
* License: activate a key for premium OrderBay updates (same server as OrderRing)

= 1.8.3 =
* Fix: the dashboard "Today" count and the staff digest now use the store timezone for "start of day" instead of UTC midnight, so daily numbers no longer shift across the date boundary for non-UTC stores.
* Fix: low-stock alerts and the digest low-stock count now paginate through the whole catalog — previously products beyond the first 100–150 were silently skipped. New `ob_low_stock_scan_max_pages` filter bounds the sweep (default 200 pages of 100).
* Fix: the PDF download endpoint (Dompdf) now respects the configured A4/Letter paper size; it was hardcoded to Letter on that path.
* Fix: uninstall now removes the numbering format/reset options (`ob_*_format`, `ob_*_reset`) and sweeps the period-scoped counter rows (`ob_invoice_next_2026` etc.) created when a yearly/monthly reset is configured.

= 1.8.2 =
* Docs: “not tax advice / consult your accountant” on the Documents settings screen
* Docs: e-invoice copy states export-only (no Peppol network / PDP delivery)
= 1.8.1 =
* Fix: saving the RMA panel no longer re-enters its own save handler (the woocommerce_update_order hook re-fired $order->save() recursively); a single admin save now applies the RMA meta exactly once instead of thousands of nested saves

= 1.8.0 =
* New: item-level RMA — record how many of each line item are being returned; shown on the RMA slip (Return qty column)
* New: optional customer RMA status emails — when enabled, the customer is emailed once when the RMA becomes Approved, Received or Closed (default off; wp_mail, store From)
* The notify statuses are filterable (ob_rma_notify_states); existing whole-order RMA behavior is unchanged

= 1.7.1 =
* Docs: complete the readme (description, installation, FAQ, feature list)
* Tests: pin the Code 128 barcode encoder and the tracking-URL template sanitizer with unit tests (no code change)

= 1.7.0 =
* Fix: order QR is no longer silently truncated to a fixed Version 3 — the built-in encoder now rejects payloads over its ~42-byte capacity (a long order URL is skipped, not rendered as an unscannable code)
* New: optional vetted QR library support — install chillerlan/php-qrcode or endroid/qr-code and order QR (incl. full order URLs) renders through it, correct by construction
* Settings now report whether a QR library is active; the built-in remains experimental and off by default. Validate the built-in with a real scanner before relying on it

= 1.6.1 =
* i18n: ship a translation template (languages/orderbay.pot) covering all document, settings and admin strings

= 1.6.0 =
* New: theme template overrides — copy any document template into wp-content/themes/your-theme/orderbay/ (e.g. orderbay/invoice.php) to customize it without editing the plugin; your copy survives updates
* New: ob_before_document / ob_after_document action hooks and an ob_locate_template filter for programmatic customization
* All nine document render paths (invoice, proforma, credit note, packing slip, delivery note, shipping label, RMA slip, pick list) now resolve through the override lookup

= 1.5.0 =
* New: per-tax-rate breakdown on invoices and proformas (e.g. "VAT (20%)", "VAT (5%)") from the order's tax totals, instead of a single combined Tax line — required for EU VAT-compliant invoices with mixed rates
* New: "Prices include/exclude tax" basis note under the tax rows
* Falls back to the single combined Tax line when no per-rate data is present

= 1.4.0 =
* New: configurable numbering formats for invoice, proforma and credit note — template tokens {PREFIX} {YYYY} {YY} {MM} {DD} {SEQ} {SEQ:n} (zero-padded)
* New: optional yearly / monthly counter reset per document type (period-scoped, atomic — restarts at 1 each period)
* Back-compatible: the default {PREFIX}{SEQ} template with no reset reproduces existing numbers exactly; a sequence token is always enforced so numbers can never collide
* Builds on the atomic gapless numbering from 1.1.1 (LAST_INSERT_ID); the format expander is unit-tested

= 1.3.0 =
* New: optional Factur-X PDF assembly — when a PDF engine (Dompdf/TCPDF) and the horstoeko/zugferd library are present, a "Factur-X PDF" button embeds the CII XML into the invoice PDF
* Reuses the CII builder from 1.2.0; output must pass a Factur-X/ZUGFeRD validator before production
* Peppol network transmission is out of scope (export the XML/PDF for upload to a PDP/AP)

= 1.2.0 =
* New (beta): e-invoice XML export — UBL 2.1 (Peppol BIS Billing 3.0) and CII (Factur-X EN16931) from the order screen
* E-invoicing seller-readiness checklist in Documents settings + per-order compliance hints
* EN16931 baseline: validate against an official validator before production. Factur-X PDF/A-3 embedding and Peppol transmission are not included yet

= 1.1.1 =
* Critical: database-atomic (LAST_INSERT_ID) gapless numbering for invoice/credit/proforma/RMA, replacing a race that could assign duplicate invoice numbers
* Fix: render fee lines on invoice and proforma; itemize refunded tax and shipping on the credit note so totals reconcile
* Fix: SLA aging no longer stalls after 50 flagged orders; bound the dashboard order-count fallback
* Security: explicit nonce on the partial-fulfillment quantity save; HPOS-safe edit redirects across handlers
* Security: neutralize CSV formula injection in the orders export
* i18n: load_plugin_textdomain so bundled translations load; store-timezone-aware dashboard/digest
* Note: the optional QR encoder is flagged experimental (may not scan); Code 128 barcode is production-ready

= 1.1.0 =
* Proforma invoices (PRO- sequence), delivery notes, shipping address labels
* Optional pure-PHP QR on invoice/packing (default off)
* Optional host Dompdf/TCPDF PDF download when present (not bundled)
* Bulk print for proformas, delivery notes, shipping labels

= 1.0.0 =
* Stable polish

= 0.7.0 =
* Tracking URLs, bulk invoices, attention digest, customer packing, meta search
