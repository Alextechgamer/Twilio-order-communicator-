<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable capability helpers.
 *
 * Defaults to manage_woocommerce. Override with:
 *   add_filter( 'toc_manage_settings', fn() => 'edit_shop_orders' );
 *   add_filter( 'toc_send_sms', fn() => 'edit_shop_orders' );
 */
class TOC_Caps {

	/**
	 * Capability for Settings / Tools / Setup / Dashboard / Bulk admin pages.
	 *
	 * @return string
	 */
	public static function manage() {
		/**
		 * Filter the capability required to manage Order Communicator settings.
		 *
		 * @param string $cap Default manage_woocommerce.
		 */
		$cap = apply_filters( 'toc_manage_settings', 'manage_woocommerce' );
		return is_string( $cap ) && $cap !== '' ? $cap : 'manage_woocommerce';
	}

	/**
	 * Capability for sending SMS / placing calls (order meta box + related AJAX).
	 *
	 * @return string
	 */
	public static function send() {
		/**
		 * Filter the capability required to send SMS or place calls.
		 *
		 * @param string $cap Default manage_woocommerce.
		 */
		$cap = apply_filters( 'toc_send_sms', 'manage_woocommerce' );
		return is_string( $cap ) && $cap !== '' ? $cap : 'manage_woocommerce';
	}
}
