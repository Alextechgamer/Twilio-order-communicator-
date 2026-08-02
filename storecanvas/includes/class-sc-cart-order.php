<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart item data + order item meta + option pricing (0.2.0).
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
		add_filter( 'woocommerce_add_cart_item', array( $this, 'set_cart_item_prices' ), 20, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'from_session' ), 20, 2 );
	}

	/**
	 * Capture options + placement from add-to-cart.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$config = SC_Product_Options::get_config( $product_id );
		$field_map = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$field_map[ $f['id'] ] = $f;
			}
		}

		if ( isset( $_POST['sc_option'] ) && is_array( $_POST['sc_option'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$options = array();
			$extra   = 0.0;
			foreach ( wp_unslash( $_POST['sc_option'] ) as $fid => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$fid = sanitize_key( $fid );
				$val = is_array( $val )
					? array_map( 'sanitize_text_field', $val )
					: sanitize_text_field( $val );
				$options[ $fid ] = $val;

				if ( isset( $field_map[ $fid ] ) ) {
					$extra += $this->field_price( $field_map[ $fid ], $val, $product_id );
				}
			}
			if ( $options ) {
				$cart_item_data[ SC_Plugin::CART_OPTIONS ] = $options;
				$cart_item_data['sc_price_extra']          = $extra;
			}
		}

		if ( ! empty( $_POST['sc_placement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw     = wp_unslash( $_POST['sc_placement'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cart_item_data[ SC_Plugin::CART_PLACEMENT ] = $decoded;
			}
		}

		if ( ! empty( $cart_item_data[ SC_Plugin::CART_OPTIONS ] ) || ! empty( $cart_item_data[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$cart_item_data['unique_key'] = md5( microtime() . wp_rand() );
		}

		return $cart_item_data;
	}

	/**
	 * Calculate extra price for one field value.
	 *
	 * @param array      $field      Field config.
	 * @param string|array $value    Submitted value.
	 * @param int        $product_id Product ID.
	 * @return float
	 */
	private function field_price( $field, $value, $product_id ) {
		$type = $field['price_type'] ?? 'none';
		if ( 'none' === $type || '' === $value || null === $value ) {
			return 0.0;
		}
		// Empty checkbox.
		if ( 'checkbox' === ( $field['type'] ?? '' ) && ! $value ) {
			return 0.0;
		}

		$amount = (float) ( $field['price'] ?? 0 );
		$product = wc_get_product( $product_id );
		$base    = $product ? (float) $product->get_price() : 0.0;

		switch ( $type ) {
			case 'flat':
				return $amount;
			case 'percent':
				return $base * ( $amount / 100 );
			case 'qty':
				// Applied per unit in set_cart_item_prices via line qty — store unit extra only.
				return $amount;
			case 'per_char':
				$text = is_array( $value ) ? implode( '', $value ) : (string) $value;
				return $amount * strlen( $text );
			default:
				return 0.0;
		}
	}

	/**
	 * Apply stored extra onto the cart item product price.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function set_cart_item_prices( $cart_item ) {
		if ( empty( $cart_item['sc_price_extra'] ) || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			return $cart_item;
		}
		$extra = (float) $cart_item['sc_price_extra'];
		if ( $extra <= 0 ) {
			return $cart_item;
		}
		$base = (float) $cart_item['data']->get_price( 'edit' );
		$cart_item['data']->set_price( $base + $extra );
		return $cart_item;
	}

	/**
	 * Restore price extra from session.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 * @return array
	 */
	public function from_session( $cart_item, $values ) {
		if ( isset( $values['sc_price_extra'] ) ) {
			$cart_item['sc_price_extra'] = (float) $values['sc_price_extra'];
		}
		if ( isset( $values[ SC_Plugin::CART_OPTIONS ] ) ) {
			$cart_item[ SC_Plugin::CART_OPTIONS ] = $values[ SC_Plugin::CART_OPTIONS ];
		}
		if ( isset( $values[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$cart_item[ SC_Plugin::CART_PLACEMENT ] = $values[ SC_Plugin::CART_PLACEMENT ];
		}
		return $this->set_cart_item_prices( $cart_item );
	}

	public function display_item_data( $item_data, $cart_item ) {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$labels     = array();
		if ( $product_id ) {
			foreach ( (array) ( SC_Product_Options::get_config( $product_id )['fields'] ?? array() ) as $f ) {
				if ( ! empty( $f['id'] ) ) {
					$labels[ $f['id'] ] = $f['label'] ?? $f['id'];
				}
			}
		}

		if ( ! empty( $cart_item[ SC_Plugin::CART_OPTIONS ] ) && is_array( $cart_item[ SC_Plugin::CART_OPTIONS ] ) ) {
			foreach ( $cart_item[ SC_Plugin::CART_OPTIONS ] as $key => $val ) {
				$item_data[] = array(
					'key'   => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
					'value' => is_array( $val ) ? implode( ', ', $val ) : $val,
				);
			}
		}
		if ( ! empty( $cart_item[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Custom placement', 'storecanvas' ),
				'value' => __( 'Yes', 'storecanvas' ),
			);
		}
		if ( ! empty( $cart_item['sc_price_extra'] ) && (float) $cart_item['sc_price_extra'] > 0 ) {
			$item_data[] = array(
				'key'   => __( 'Options total', 'storecanvas' ),
				'value' => wp_strip_all_tags( wc_price( (float) $cart_item['sc_price_extra'] ) ),
			);
		}
		return $item_data;
	}

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
		if ( ! empty( $values['sc_price_extra'] ) ) {
			$item->add_meta_data( 'sc_price_extra', (float) $values['sc_price_extra'], true );
		}
	}

	public function admin_order_preview( $item_id, $item, $product ) {
		$options   = $item->get_meta( SC_Plugin::CART_OPTIONS );
		$placement = $item->get_meta( SC_Plugin::CART_PLACEMENT );
		$extra     = $item->get_meta( 'sc_price_extra' );
		if ( ! $options && ! $placement && ! $extra ) {
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
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Placement data saved.', 'storecanvas' ) . '</p>';
		}
		if ( $extra ) {
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Options extra:', 'storecanvas' ) . ' ' . wp_kses_post( wc_price( (float) $extra ) ) . '</p>';
		}
		echo '</div>';
	}
}
