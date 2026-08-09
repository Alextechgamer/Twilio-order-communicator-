<?php
/**
 * Orderbay uninstall — options only.
 *
 * Keeps order/product meta (invoice/credit/tracking/RMA/bins/fulfillment/attention/tags).
 *
 * @package Orderbay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'ob_documents_settings',
	'ob_email_rules',
	'ob_low_stock_settings',
	'ob_digest_settings',
	'ob_digest_last_sent',
	'ob_invoice_prefix',
	'ob_invoice_next',
	'ob_credit_prefix',
	'ob_credit_next',
	'ob_tracking_email_settings',
	'ob_auto_attention_statuses',
	'ob_rma_settings',
	'ob_rma_prefix',
	'ob_rma_next',
	'ob_sla_settings',
	'ob_note_templates',
	'ob_tracking_carriers',
	'ob_customer_packing_slip_enabled',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
	delete_site_option( $opt );
}

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
