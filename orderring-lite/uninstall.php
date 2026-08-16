<?php
/**
 * Uninstall OrderRing Lite.
 *
 * Does not touch OrderRing Pro (twilio-order-communicator) options, caps, or tables.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'TOC_VERSION' ) || class_exists( 'Twilio_Order_Communicator', false ) ) {
	return;
}

$options = array(
	'orl_account_sid',
	'orl_auth_token',
	'orl_from_number',
	'orl_webhook_base_url',
	'orl_db_version',
	'orl_status_ready_for_pickup',
	'orl_auto_ready_enabled',
	'orl_auto_ready_sms',
	'orl_message_ready_for_pickup',
	'orl_ready_require_local_pickup',
	'orl_pickup_match',
	'orl_checkout_consent_enabled',
	'orl_checkout_consent_required',
	'orl_checkout_consent_label',
	'orl_require_sms_consent',
	'orl_sms_consent_meta',
	'orl_stop_reply',
	'orl_help_reply',
	'orl_start_reply',
	'orl_sms_footer_enabled',
	'orl_sms_footer_text',
	'orl_caps_seeded',
);

foreach ( $options as $key ) {
	delete_option( $key );
}

global $wpdb;
if ( isset( $wpdb ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orl_communications' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orl_sms_opt_outs' );
}

foreach ( array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor', 'subscriber' ) as $role_key ) {
	$role = get_role( $role_key );
	if ( ! $role ) {
		continue;
	}
	$role->remove_cap( 'orl_manage' );
	$role->remove_cap( 'orl_send' );
}
