=== Orderbay ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Self-hosted WooCommerce ops toolkit: documents, fulfillment, RMA, digests, search.

== Changelog ==

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
