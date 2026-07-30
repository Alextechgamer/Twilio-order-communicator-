<?php
/**
 * Uninstall cleanup for Twilio Order Communicator.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'toc_communications',
	$wpdb->prefix . 'toc_sms_opt_outs',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'toc_account_sid',
	'toc_auth_token',
	'toc_from_number',
	'toc_voice',
	'toc_auto_on_completed',
	'toc_auto_voice',
	'toc_auto_sms',
	'toc_auto_ready_enabled',
	'toc_auto_ready_voice',
	'toc_auto_ready_sms',
	'toc_auto_shipped_enabled',
	'toc_auto_shipped_voice',
	'toc_auto_shipped_sms',
	'toc_ready_require_local_pickup',
	'toc_status_ready_for_pickup',
	'toc_status_shipped',
	'toc_require_sms_consent',
	'toc_sms_consent_meta',
	'toc_default_pickup_message',
	'toc_default_reminder_message',
	'toc_default_issue_message',
	'toc_message_ready_for_pickup',
	'toc_message_shipped',
	'toc_message_reminder',
	'toc_message_issue',
	'toc_db_version',
	'toc_bulk_delay_seconds',
	'toc_pickup_match',
	'toc_webhook_base_url',
	'toc_stop_reply',
	'toc_help_reply',
	'toc_start_reply',
	'toc_sms_opt_outs',
	'toc_checkout_consent_enabled',
	'toc_checkout_consent_required',
	'toc_checkout_consent_label',
	'toc_quiet_hours_enabled',
	'toc_quiet_hours_start',
	'toc_quiet_hours_end',
	'toc_onboarding_done',
	'toc_onboarding_step',
	'toc_migrated_auto_v160',
	'toc_sms_footer_enabled',
	'toc_sms_footer_text',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
