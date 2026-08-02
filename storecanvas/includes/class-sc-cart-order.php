<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart item data + order item meta for options and placements.
 */
class SC_Cart_Order {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'to_order_item' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_order_preview' ), 10, 3 );
	}

	/**
	 * Capture sc_option[] and sc_placement from the add-to-cart request.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id    Product ID.
	 * @param int   $variation_id  Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// Options (non-file for scaffold; file upload needs multipart handling in next pass).
		if ( isset( $_POST['sc_option'] ) && is_array( $_POST['sc_option'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$options = array();
			foreach ( wp_unslash( $_POST['sc_option'] ) as $fid => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$options[ sanitize_key( $fid ) ] = is_array( $val )
					? array_map( 'sanitize_text_field', $val )
					: sanitize_text_field( $val );
			}
			if ( $options ) {
				$cart_item_data[ SC_Plugin::CART_OPTIONS ] = $options;
			}
		}

		// Placement JSON from customizer (hidden input).
		if ( ! empty( $_POST['sc_placement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['sc_placement'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cart_item_data[ SC_Plugin::CART_PLACEMENT ] = $decoded;
			}
		}

		// Unique key so customized lines do not merge.
		if ( ! empty( $cart_item_data[ SC_Plugin::CART_OPTIONS ] ) || ! empty( $cart_item_data[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$cart_item_data['unique_key'] = md5( microtime() . wp_rand() );
		}

		return $cart_item_data;
	}

	/**
	 * Show options / placement summary in cart and checkout.
	 *
	 * @param array $item_data Item data rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item[ SC_Plugin::CART_OPTIONS ] ) && is_array( $cart_item[ SC_Plugin::CART_OPTIONS ] ) ) {
			foreach ( $cart_item[ SC_Plugin::CART_OPTIONS ] as $key => $val ) {
				$item_data[] = array(
					'key'   => $key,
					'value' => is_array( $val ) ? implode( ', ', $val ) : $val,
				);
			}
		}
		if ( ! empty( $cart_item[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Custom placement', 'storecanvas' ),
				'value' => __( 'Yes (see order for preview)', 'storecanvas' ),
			);
		}
		return $item_data;
	}

	/**
	 * Persist to order line item meta.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart values.
	 * @param WC_Order              $order         Order.
	 */
	public function to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values[ SC_Plugin::CART_OPTIONS ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_OPTIONS, $values[ SC_Plugin::CART_OPTIONS ], true );
		}
		if ( ! empty( $values[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_PLACEMENT, $values[ SC_Plugin::CART_PLACEMENT ], true );
		}
		if ( ! empty( $values[ SC_Plugin::CART_ATTACHMENTS ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_ATTACHMENTS, $values[ SC_Plugin::CART_ATTACHMENTS ], true );
		}
	}

	/**
	 * Admin order screen: show placement / options summary.
	 *
	 * @param int                   $item_id Item ID.
	 * @param WC_Order_Item_Product $item    Item.
	 * @param WC_Product|bool       $product Product.
	 */
	public function admin_order_preview( $item_id, $item, $product ) {
		$options   = $item->get_meta( SC_Plugin::CART_OPTIONS );
		$placement = $item->get_meta( SC_Plugin::CART_PLACEMENT );
		if ( ! $options && ! $placement ) {
			return;
		}
		echo '<div class="sc-order-item-meta" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #c3c4c7;">';
		echo '<strong>' . esc_html__( 'StoreCanvas', 'storecanvas' ) . '</strong>';
		if ( is_array( $options ) ) {
			echo '<ul style="margin:4px 0 0 1em;">';
			foreach ( $options as $k => $v ) {
				echo '<li>' . esc_html( $k ) . ': ' . esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $placement ) {
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Placement data saved (preview composite in Phase B UI pass).', 'storecanvas' ) . '</p>';
		}
		echo '</div>';
	}
}
