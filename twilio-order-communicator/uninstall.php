<?php
/**
 * Uninstall cleanup for Twilio Order Communicator.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'toc_communications';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

$options = array(
	'toc_account_sid',
	'toc_auth_token',
	'toc_from_number',
	'toc_voice',
	'toc_auto_on_completed',
	'toc_auto_voice',
	'toc_auto_sms',
	'toc_require_sms_consent',
	'toc_sms_consent_meta',
	'toc_default_pickup_message',
	'toc_default_reminder_message',
	'toc_default_issue_message',
	'toc_db_version',
	'toc_bulk_delay_seconds',
	'toc_pickup_match',
	'toc_webhook_base_url',
	'toc_stop_reply',
	'toc_help_reply',
	'toc_start_reply',
	'toc_sms_opt_outs',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
