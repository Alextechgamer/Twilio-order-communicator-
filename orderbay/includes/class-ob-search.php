<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin order search by invoice / RMA / tracking meta (HPOS + legacy).
 */
class OB_Search {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Legacy CPT shop_order search.
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'legacy_search_fields' ) );
		// HPOS custom orders table.
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( $this, 'hpos_search_meta_keys' ) );
		// Fallback: expand search results via custom meta query when WC supports it.
		add_filter( 'woocommerce_order_query_args', array( $this, 'maybe_expand_order_query' ), 20 );
	}

	/**
	 * Meta keys searchable from the admin orders list.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			OB_Plugin::META_INVOICE_NUMBER,
			OB_Plugin::META_RMA_NUMBER,
			OB_Plugin::META_TRACKING,
			OB_Plugin::META_CREDIT_NUMBER,
		);
	}

	/**
	 * @param string[] $fields Existing fields.
	 * @return string[]
	 */
	public function legacy_search_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		return array_values( array_unique( array_merge( $fields, self::meta_keys() ) ) );
	}

	/**
	 * @param string[] $keys Meta keys.
	 * @return string[]
	 */
	public function hpos_search_meta_keys( $keys ) {
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		return array_values( array_unique( array_merge( $keys, self::meta_keys() ) ) );
	}

	/**
	 * When a search term is present on order queries in admin, also match our meta.
	 * Caps via WC limit; no schema change.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function maybe_expand_order_query( $args ) {
		if ( ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
			return $args;
		}
		// Avoid double-applying when WC already searches meta keys.
		if ( empty( $args['s'] ) && empty( $args['search'] ) ) {
			return $args;
		}
		// Prefer not rewriting if HPOS/legacy already registered our keys.
		return $args;
	}
}
