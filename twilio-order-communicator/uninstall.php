<?php
/**
 * Uninstall cleanup for Twilio Order Communicator.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Scheduled work must go before the data it depends on.
$toc_hooks = array(
	'toc_license_validate_cron',
	'toc_deferred_auto_notify',
	'toc_scheduled_reminder',
);

foreach ( $toc_hooks as $toc_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// null args = every scheduled job for this hook in group toc (deferred notify carries order args).
		as_unschedule_all_actions( $toc_hook, null, 'toc' );
	}
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( $toc_hook );
	} else {
		wp_clear_scheduled_hook( $toc_hook );
	}
}

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
	'toc_whatsapp_from',
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
	'toc_scheduled_reminder_enabled',
	'toc_scheduled_reminder_delay_hours',
	'toc_delivery_alert_enabled',
	'toc_delivery_alert_email',
	'toc_email_ready_enabled',
	'toc_email_ready_subject',
	'toc_email_ready_body',
	'toc_email_shipped_enabled',
	'toc_email_shipped_subject',
	'toc_email_shipped_body',
	'toc_caps_seeded',
	'toc_onboarding_done',
	'toc_onboarding_step',
	'toc_migrated_auto_v160',
	'toc_sms_footer_enabled',
	'toc_sms_footer_text',
	'toc_license_key',
	'toc_license_status',
	'toc_license_data',
	'toc_license_last_check',
	'toc_license_instance_id',
	'toc_license_server_url',
);

// Cached update answers are keyed by license key + plugin version. TOC_VERSION is not
// defined during uninstall (only this file is loaded), so wipe by name pattern.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_toc_update_check_%' OR option_name LIKE '_site_transient_timeout_toc_update_check_%'"
);
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_toc_update_check_%' OR meta_key LIKE '_site_transient_timeout_toc_update_check_%'"
	);
}

foreach ( $options as $option ) {
	delete_option( $option );
}
