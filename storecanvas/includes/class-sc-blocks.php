<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block theme / cart compatibility helpers (0.8.0).
 * Shortcodes for product options + customizer when classic hooks are missing.
 */
class SC_Blocks {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'storecanvas_options', array( $this, 'shortcode_options' ) );
		add_shortcode( 'storecanvas_customizer', array( $this, 'shortcode_customizer' ) );
		// Ensure cart item data filter remains available for block cart (same filter WC uses).
		// SC_Cart_Order already registers woocommerce_get_item_data.
	}

	/**
	 * [storecanvas_options]
	 *
	 * @return string
	 */
	public function shortcode_options() {
		if ( ! is_product() && ! is_singular( 'product' ) ) {
			return '';
		}
		ob_start();
		SC_Product_Options::instance()->render_fields();
		return (string) ob_get_clean();
	}

	/**
	 * [storecanvas_customizer]
	 *
	 * @return string
	 */
	public function shortcode_customizer() {
		if ( ! is_product() && ! is_singular( 'product' ) ) {
			return '';
		}
		ob_start();
		SC_Customizer::instance()->render_panel();
		return (string) ob_get_clean();
	}
}
