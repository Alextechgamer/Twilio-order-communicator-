<?php
/**
 * StoreCanvas uninstall — options + guest design transients only.
 *
 * Preserves:
 * - Order/product meta (sc_options, sc_placement, sc_layers, print files, etc.)
 * - Media attachments / composites
 * - CPT content (sc_clipart library items, sc_design saved designs, sc_option_group global option groups)
 *
 * Removes:
 * - Plugin options (sc_proof_email_*, sc_journey_enabled)
 * - Does not remove order meta (_sc_printed_at, sc_preview_id, etc.) or media
 * - Guest design transients (sc_gdesign_*)
 * - Optional journey debug table (plugin-owned; not shop orders)
 *
 * @package StoreCanvas
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---- Options ----
$options = array(
	'sc_proof_email_enabled',
	'sc_proof_email_subject',
	'sc_proof_email_body',
	'sc_proof_email_status',
	'sc_journey_enabled',
	// Artwork-privacy proxy (H1): per-site signing secret fallback + one-time backfill flag.
	'sc_dl_secret',
	'sc_artwork_backfilled',
	'sc_license_key',
	'sc_license_status',
	'sc_license_data',
	'sc_license_last_check',
	'sc_license_server_url',
	'sc_trial_started_at',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Site options (multisite network-wide not used; still clear common variants).
foreach ( $options as $opt ) {
	delete_site_option( $opt );
}

// ---- Guest design transients (prefix sc_gdesign_) ----
global $wpdb;
// Transient API stores as _transient_{name} and _transient_timeout_{name}.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_sc_gdesign_%'
	   OR option_name LIKE '_transient_timeout_sc_gdesign_%'"
);
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->sitemeta}
		WHERE meta_key LIKE '_site_transient_sc_gdesign_%'
		   OR meta_key LIKE '_site_transient_timeout_sc_gdesign_%'"
	);
}

// ---- Journey debug table (plugin-owned logging, not shop data) ----
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sc_journey" );

// Clear one-shot admin notice transient.
delete_transient( 'sc_gd_notice_shown' );
