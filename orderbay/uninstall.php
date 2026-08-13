<?php
/**
 * Orderbay uninstall — options + crons only.
 *
 * Does NOT delete order/product meta:
 * _ob_invoice_number, _ob_proforma_number, _ob_credit_note_number, _ob_tracking_*, _ob_rma_*,
 * _ob_bin_location, _ob_qty_fulfilled, _ob_fulfillment_status, _ob_needs_attention,
 * _ob_order_tags, _ob_sla_aged_at, email once-guards, etc.
 *
 * @package Orderbay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	// Documents / numbering.
	'ob_documents_settings',
	'ob_invoice_prefix',
	'ob_invoice_next',
	'ob_invoice_format',
	'ob_invoice_reset',
	'ob_credit_prefix',
	'ob_credit_next',
	'ob_credit_format',
	'ob_credit_reset',
	'ob_proforma_next',
	'ob_proforma_prefix',
	'ob_proforma_format',
	'ob_proforma_reset',
	// Notifications.
	'ob_email_rules',
	'ob_low_stock_settings',
	// Digest.
	'ob_digest_settings',
	'ob_digest_last_sent',
	// Fulfillment / tracking.
	'ob_tracking_email_settings',
	'ob_tracking_carriers',
	'ob_auto_attention_statuses',
	'ob_customer_packing_slip_enabled',
	// RMA.
	'ob_rma_settings',
	'ob_rma_prefix',
	'ob_rma_next',
	// SLA / notes.
	'ob_sla_settings',
	'ob_note_templates',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
	delete_site_option( $opt );
}

// Match deactivate: unschedule digest, SLA, stock scan.
foreach ( array( 'ob_daily_stock_scan', 'ob_digest_cron', 'ob_sla_aging_cron' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

delete_transient( 'ob_digest_sending' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_ob_lowstock_%'
	   OR option_name LIKE '_transient_timeout_ob_lowstock_%'"
);

// Period-scoped numbering counters written by OB_Invoicing::allocate_number()
// when a yearly/monthly reset is configured (e.g. ob_invoice_next_2026,
// ob_invoice_next_202608) — dynamic names, so a LIKE sweep is required.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE 'ob\\_invoice\\_next\\_%'
	   OR option_name LIKE 'ob\\_credit\\_next\\_%'
	   OR option_name LIKE 'ob\\_proforma\\_next\\_%'
	   OR option_name LIKE 'ob\\_rma\\_next\\_%'"
);
