<?php
/**
 * Orderbay uninstall — options only.
 *
 * Preserves order/product meta (_ob_needs_attention, _ob_order_tags, email sent keys)
 * and all media. Does not touch StoreCanvas or Twilio Order Communicator.
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
);

foreach ( $options as $opt ) {
	delete_option( $opt );
	delete_site_option( $opt );
}

// Clear scheduled stock scan + digest.
foreach ( array( 'ob_daily_stock_scan', 'ob_digest_cron' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}
delete_transient( 'ob_digest_sending' );

// Low-stock rate-limit transients.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_ob_lowstock_%'
	   OR option_name LIKE '_transient_timeout_ob_lowstock_%'"
);
