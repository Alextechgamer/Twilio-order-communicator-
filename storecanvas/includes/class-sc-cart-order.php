<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart item data + order item meta + option pricing + validation (1.2.0).
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
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'to_order_item' ), 10, 4 );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_order_preview' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'set_cart_item_prices' ), 20, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'from_session' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'decrement_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_option_stock' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_option_stock' ), 20, 1 );
	}

	/**
	 * Validate artwork + required options + limits + stock + variation targeting.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		// Artwork path.
		if ( ! empty( $_FILES['sc_artwork']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$rules  = SC_Customizer::get_validation( $product_id );
			$config = SC_Customizer::get_config( $product_id );
			$area   = ! empty( $config['areas'][0] ) ? $config['areas'][0] : array();
			if ( ! empty( $_POST['sc_placement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$pl = json_decode( wp_unslash( $_POST['sc_placement'] ), true ); // phpcs:ignore
				if ( is_array( $pl ) ) {
					$rules['_placement'] = $pl;
					if ( ! empty( $pl['area_id'] ) ) {
						foreach ( (array) ( $config['areas'] ?? array() ) as $a ) {
							if ( ( $a['id'] ?? '' ) === $pl['area_id'] ) {
								$area = $a;
								break;
							}
						}
					}
				}
			}
			$check = SC_Print_Ready::instance()->validate_source( $_FILES['sc_artwork']['tmp_name'], $rules, $area ); // phpcs:ignore
			if ( ! $check['ok'] ) {
				foreach ( $check['errors'] as $err ) {
					wc_add_notice( $err, 'error' );
				}
				$passed = false;
			} else {
				foreach ( $check['warnings'] as $w ) {
					wc_add_notice( $w, 'notice' );
				}
			}
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore
		$options      = array();
		if ( isset( $_POST['sc_option'] ) && is_array( $_POST['sc_option'] ) ) { // phpcs:ignore
			foreach ( wp_unslash( $_POST['sc_option'] ) as $fid => $val ) { // phpcs:ignore
				$fid             = sanitize_key( $fid );
				$options[ $fid ] = is_array( $val )
					? array_map( 'sanitize_text_field', $val )
					: sanitize_text_field( $val );
			}
		}

		$config = SC_Product_Options::get_config( $product_id );
		foreach ( (array) ( $config['fields'] ?? array() ) as $field ) {
			if ( empty( $field['id'] ) || 'heading' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( ! SC_Product_Options::user_can_see_field( $field ) ) {
				continue;
			}
			if ( ! SC_Product_Options::field_matches_variation( $field, $variation_id ) ) {
				continue;
			}
			// Build temp options for show_if with this field's submitted value.
			if ( ! SC_Product_Options::field_is_visible( $field, $options ) ) {
				continue;
			}

			$fid = $field['id'];
			$val = $options[ $fid ] ?? '';
			$lab = $field['label'] ?? $fid;
			$type = $field['type'] ?? 'text';

			// Required.
			if ( ! empty( $field['required'] ) ) {
				$empty = ( is_array( $val ) && ! array_filter( $val, 'strlen' ) ) || ( ! is_array( $val ) && '' === (string) $val );
				if ( 'checkbox' === $type ) {
					$empty = empty( $val );
				}
				if ( $empty ) {
					/* translators: %s field label */
					wc_add_notice( sprintf( __( '“%s” is required.', 'storecanvas' ), $lab ), 'error' );
					$passed = false;
					continue;
				}
			}

			// Char limits.
			if ( in_array( $type, SC_Product_Options::text_like_types(), true ) && is_string( $val ) && $val !== '' ) {
				$len = strlen( $val );
				if ( isset( $field['min_chars'] ) && $len < (int) $field['min_chars'] ) {
					wc_add_notice( sprintf( __( '“%1$s” must be at least %2$d characters.', 'storecanvas' ), $lab, (int) $field['min_chars'] ), 'error' );
					$passed = false;
				}
				if ( isset( $field['max_chars'] ) && $len > (int) $field['max_chars'] ) {
					wc_add_notice( sprintf( __( '“%1$s” must be at most %2$d characters.', 'storecanvas' ), $lab, (int) $field['max_chars'] ), 'error' );
					$passed = false;
				}
			}

			// Number min/max.
			if ( 'number' === $type && '' !== (string) $val && is_numeric( $val ) ) {
				$n = (float) $val;
				if ( isset( $field['min'] ) && $n < (float) $field['min'] ) {
					wc_add_notice( sprintf( __( '“%1$s” must be at least %2$s.', 'storecanvas' ), $lab, $field['min'] ), 'error' );
					$passed = false;
				}
				if ( isset( $field['max'] ) && $n > (float) $field['max'] ) {
					wc_add_notice( sprintf( __( '“%1$s” must be at most %2$s.', 'storecanvas' ), $lab, $field['max'] ), 'error' );
					$passed = false;
				}
			}

			// Email.
			if ( 'email' === $type && $val && ! is_email( $val ) ) {
				wc_add_notice( sprintf( __( '“%s” must be a valid email.', 'storecanvas' ), $lab ), 'error' );
				$passed = false;
			}

			// Color hex.
			if ( 'color' === $type && $val && ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $val ) ) {
				wc_add_notice( sprintf( __( '“%s” must be a valid hex color.', 'storecanvas' ), $lab ), 'error' );
				$passed = false;
			}

			// Date Y-m-d.
			if ( 'date' === $type && $val && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
				wc_add_notice( sprintf( __( '“%s” must be a valid date.', 'storecanvas' ), $lab ), 'error' );
				$passed = false;
			}

			// Per-option stock.
			$vals = is_array( $val ) ? $val : array( $val );
			foreach ( $vals as $one ) {
				if ( '' === (string) $one ) {
					continue;
				}
				$stock = SC_Product_Options::choice_stock( $field, $one );
				if ( null === $stock ) {
					continue;
				}
				$need = max( 1, (int) $quantity );
				if ( $stock < $need ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: field 2: choice 3: remaining */
							__( '“%1$s” option “%2$s” has insufficient stock (remaining: %3$d).', 'storecanvas' ),
							$lab,
							SC_Product_Options::format_value_label( $field, $one ),
							$stock
						),
						'error'
					);
					$passed = false;
				}
			}
		}

		return $passed;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$config    = SC_Product_Options::get_config( $product_id );
		$field_map = array();
		foreach ( (array) ( $config['fields'] ?? array() ) as $f ) {
			if ( ! empty( $f['id'] ) ) {
				$field_map[ $f['id'] ] = $f;
			}
		}

		if ( isset( $_POST['sc_option'] ) && is_array( $_POST['sc_option'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$options = array();
			$labels  = array();
			$extra   = 0.0;
			foreach ( wp_unslash( $_POST['sc_option'] ) as $fid => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$fid = sanitize_key( $fid );
				if ( ! isset( $field_map[ $fid ] ) ) {
					continue;
				}
				$field = $field_map[ $fid ];
				if ( ! SC_Product_Options::user_can_see_field( $field ) ) {
					continue;
				}
				if ( ! SC_Product_Options::field_matches_variation( $field, $variation_id ) ) {
					continue;
				}

				$type = $field['type'] ?? 'text';
				if ( is_array( $val ) ) {
					$val = array_map( 'sanitize_text_field', $val );
				} else {
					$val = sanitize_text_field( $val );
					if ( 'color' === $type && $val ) {
						if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $val ) ) {
							$val =  ( function_exists( 'sanitize_hex_color' ) ? ( sanitize_hex_color( $val ) ?: '' ) : '' );
						}
					}
					if ( 'date' === $type && $val && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
						// ok Y-m-d
					} elseif ( 'date' === $type ) {
						$val = '';
					}
				}

				// Skip hidden by show_if (using options collected so far + raw post).
				$probe = $options;
				$probe[ $fid ] = $val;
				// Rebuild probe from all post for show_if parents.
				$probe_all = array();
				foreach ( wp_unslash( $_POST['sc_option'] ) as $pf => $pv ) { // phpcs:ignore
					$probe_all[ sanitize_key( $pf ) ] = is_array( $pv ) ? array_map( 'sanitize_text_field', $pv ) : sanitize_text_field( $pv );
				}
				if ( ! SC_Product_Options::field_is_visible( $field, $probe_all ) ) {
					continue;
				}

				$options[ $fid ] = $val;
				$labels[ $fid ]  = array(
					'label' => $field['label'] ?? $fid,
					'value' => SC_Product_Options::format_value_label( $field, $val ),
				);
				$extra          += $this->field_price( $field, $val, $product_id );
			}
			if ( $options ) {
				$cart_item_data[ SC_Plugin::CART_OPTIONS ] = $options;
				$cart_item_data['sc_option_labels']       = $labels;
				$cart_item_data['sc_price_extra']         = $extra;
			}
		}

		if ( ! empty( $_POST['sc_placement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw     = wp_unslash( $_POST['sc_placement'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cart_item_data[ SC_Plugin::CART_PLACEMENT ] = $decoded;
			}
		}

		if ( ! empty( $_POST['sc_layers_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw     = wp_unslash( $_POST['sc_layers_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cart_item_data[ SC_Plugin::CART_LAYERS ] = $decoded;
			}
		}

		if ( ! empty( $_FILES['sc_artwork']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$rules  = SC_Customizer::get_validation( $product_id );
			$config = SC_Customizer::get_config( $product_id );
			$area   = ! empty( $config['areas'][0] ) ? $config['areas'][0] : array();
			if ( ! empty( $cart_item_data[ SC_Plugin::CART_PLACEMENT ] ) && is_array( $cart_item_data[ SC_Plugin::CART_PLACEMENT ] ) ) {
				$rules['_placement'] = $cart_item_data[ SC_Plugin::CART_PLACEMENT ];
			}
			$check = SC_Print_Ready::instance()->validate_source( $_FILES['sc_artwork']['tmp_name'], $rules, $area ); // phpcs:ignore
			if ( ! $check['ok'] ) {
				$cart_item_data['sc_validation_errors'] = $check['errors'];
			} else {
				$att = SC_Print_Ready::instance()->sideload_upload( $_FILES['sc_artwork'] ); // phpcs:ignore
				if ( ! is_wp_error( $att ) ) {
					$cart_item_data[ SC_Plugin::CART_ATTACHMENTS ] = array( 'artwork' => (int) $att );
					if ( ! empty( $check['meta'] ) ) {
						$cart_item_data['sc_art_meta'] = $check['meta'];
					}
				} else {
					$cart_item_data['sc_validation_errors'] = array( $att->get_error_message() );
				}
			}
		}

		if ( ! empty( $cart_item_data[ SC_Plugin::CART_OPTIONS ] ) || ! empty( $cart_item_data[ SC_Plugin::CART_PLACEMENT ] ) || ! empty( $cart_item_data[ SC_Plugin::CART_ATTACHMENTS ] ) || ! empty( $cart_item_data[ SC_Plugin::CART_LAYERS ] ) ) {
			$cart_item_data['unique_key'] = md5( microtime() . wp_rand() );
		}

		return $cart_item_data;
	}

	private function field_price( $field, $value, $product_id ) {
		$type = $field['price_type'] ?? 'none';
		if ( 'none' === $type || '' === $value || null === $value ) {
			return 0.0;
		}
		if ( is_array( $value ) && ! array_filter( $value, 'strlen' ) ) {
			return 0.0;
		}
		if ( 'checkbox' === ( $field['type'] ?? '' ) && ! $value ) {
			return 0.0;
		}

		$amount  = (float) ( $field['price'] ?? 0 );
		$product = wc_get_product( $product_id );
		$base    = $product ? (float) $product->get_price() : 0.0;

		switch ( $type ) {
			case 'flat':
				return $amount;
			case 'percent':
				return $base * ( $amount / 100 );
			case 'qty':
				return $amount;
			case 'per_char':
				$text = is_array( $value ) ? implode( '', $value ) : (string) $value;
				return $amount * strlen( $text );
			default:
				return 0.0;
		}
	}

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

	public function from_session( $cart_item, $values ) {
		if ( isset( $values['sc_price_extra'] ) ) {
			$cart_item['sc_price_extra'] = (float) $values['sc_price_extra'];
		}
		if ( isset( $values[ SC_Plugin::CART_OPTIONS ] ) ) {
			$cart_item[ SC_Plugin::CART_OPTIONS ] = $values[ SC_Plugin::CART_OPTIONS ];
		}
		if ( isset( $values['sc_option_labels'] ) ) {
			$cart_item['sc_option_labels'] = $values['sc_option_labels'];
		}
		if ( isset( $values[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$cart_item[ SC_Plugin::CART_PLACEMENT ] = $values[ SC_Plugin::CART_PLACEMENT ];
		}
		if ( isset( $values[ SC_Plugin::CART_ATTACHMENTS ] ) ) {
			$cart_item[ SC_Plugin::CART_ATTACHMENTS ] = $values[ SC_Plugin::CART_ATTACHMENTS ];
		}
		if ( isset( $values[ SC_Plugin::CART_LAYERS ] ) ) {
			$cart_item[ SC_Plugin::CART_LAYERS ] = $values[ SC_Plugin::CART_LAYERS ];
		}
		return $this->set_cart_item_prices( $cart_item );
	}

	public function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['sc_option_labels'] ) && is_array( $cart_item['sc_option_labels'] ) ) {
			foreach ( $cart_item['sc_option_labels'] as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$item_data[] = array(
					'key'   => $meta['label'] ?? '',
					'value' => $meta['value'] ?? '',
				);
			}
		} elseif ( ! empty( $cart_item[ SC_Plugin::CART_OPTIONS ] ) && is_array( $cart_item[ SC_Plugin::CART_OPTIONS ] ) ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$labels     = array();
			if ( $product_id ) {
				foreach ( (array) ( SC_Product_Options::get_config( $product_id )['fields'] ?? array() ) as $f ) {
					if ( ! empty( $f['id'] ) ) {
						$labels[ $f['id'] ] = $f;
					}
				}
			}
			foreach ( $cart_item[ SC_Plugin::CART_OPTIONS ] as $key => $val ) {
				$field = $labels[ $key ] ?? array( 'id' => $key, 'label' => $key );
				$item_data[] = array(
					'key'   => $field['label'] ?? $key,
					'value' => SC_Product_Options::format_value_label( $field, $val ),
				);
			}
		}
		if ( ! empty( $cart_item[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Custom placement', 'storecanvas' ),
				'value' => __( 'Yes', 'storecanvas' ),
			);
		}
		if ( ! empty( $cart_item[ SC_Plugin::CART_LAYERS ] ) && is_array( $cart_item[ SC_Plugin::CART_LAYERS ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Art layers', 'storecanvas' ),
				'value' => (string) count( $cart_item[ SC_Plugin::CART_LAYERS ] ),
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
		if ( ! empty( $values['sc_option_labels'] ) ) {
			$item->add_meta_data( 'sc_option_labels', $values['sc_option_labels'], true );
		}
		if ( ! empty( $values[ SC_Plugin::CART_PLACEMENT ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_PLACEMENT, $values[ SC_Plugin::CART_PLACEMENT ], true );
		}
		if ( ! empty( $values[ SC_Plugin::CART_ATTACHMENTS ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_ATTACHMENTS, $values[ SC_Plugin::CART_ATTACHMENTS ], true );
		}
		if ( ! empty( $values[ SC_Plugin::CART_LAYERS ] ) ) {
			$item->add_meta_data( SC_Plugin::CART_LAYERS, $values[ SC_Plugin::CART_LAYERS ], true );
		}
		if ( ! empty( $values['sc_price_extra'] ) ) {
			$item->add_meta_data( 'sc_price_extra', (float) $values['sc_price_extra'], true );
		}
	}

	/**
	 * Best-effort decrement of choice stock on order create.
	 *
	 * @param int $order_id Order ID.
	 */
	public function decrement_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_sc_option_stock_applied' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$opts = $item->get_meta( SC_Plugin::CART_OPTIONS );
			if ( ! is_array( $opts ) || ! $opts ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$qty        = max( 1, (int) $item->get_quantity() );
			$this->adjust_product_choice_stock( $product_id, $opts, -$qty );
		}
		$order->update_meta_data( '_sc_option_stock_applied', 1 );
		$order->save();
	}

	/**
	 * Best-effort restore on cancel/failed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function restore_option_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_sc_option_stock_applied' ) ) {
			return;
		}
		if ( $order->get_meta( '_sc_option_stock_restored' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$opts = $item->get_meta( SC_Plugin::CART_OPTIONS );
			if ( ! is_array( $opts ) || ! $opts ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$qty        = max( 1, (int) $item->get_quantity() );
			$this->adjust_product_choice_stock( $product_id, $opts, +$qty );
		}
		$order->update_meta_data( '_sc_option_stock_restored', 1 );
		$order->save();
	}

	/**
	 * Adjust local product field choice stock_qty (not group fields — product-local only).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $options    Chosen options.
	 * @param int   $delta      Negative to decrement.
	 */
	private function adjust_product_choice_stock( $product_id, $options, $delta ) {
		$raw = get_post_meta( $product_id, SC_Plugin::META_OPTIONS, true );
		if ( ! is_array( $raw ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			return;
		}
		$changed = false;
		foreach ( $raw['fields'] as &$field ) {
			$fid = $field['id'] ?? '';
			if ( ! $fid || ! isset( $options[ $fid ] ) ) {
				continue;
			}
			$vals = is_array( $options[ $fid ] ) ? $options[ $fid ] : array( $options[ $fid ] );
			if ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
				continue;
			}
			foreach ( $field['choices'] as &$choice ) {
				if ( ! is_array( $choice ) || ! array_key_exists( 'stock_qty', $choice ) || null === $choice['stock_qty'] || '' === $choice['stock_qty'] ) {
					continue;
				}
				$cv = (string) ( $choice['value'] ?? '' );
				foreach ( $vals as $v ) {
					if ( (string) $v === $cv ) {
						$choice['stock_qty'] = max( 0, (int) $choice['stock_qty'] + (int) $delta );
						$changed            = true;
					}
				}
			}
			unset( $choice );
		}
		unset( $field );
		if ( $changed ) {
			update_post_meta( $product_id, SC_Plugin::META_OPTIONS, $raw );
		}
	}

	public function admin_order_preview( $item_id, $item, $product ) {
		$options   = $item->get_meta( SC_Plugin::CART_OPTIONS );
		$labels    = $item->get_meta( 'sc_option_labels' );
		$placement = $item->get_meta( SC_Plugin::CART_PLACEMENT );
		$layers    = $item->get_meta( SC_Plugin::CART_LAYERS );
		$extra     = $item->get_meta( 'sc_price_extra' );
		$preview   = (int) $item->get_meta( 'sc_preview_id' );
		if ( ! $preview ) {
			$prints = $item->get_meta( SC_Print_Ready::META_PRINT_FILES );
			if ( is_array( $prints ) && $prints ) {
				$preview = (int) reset( $prints );
			}
		}
		if ( ! $options && ! $placement && ! $layers && ! $extra && ! $preview ) {
			return;
		}
		echo '<div class="sc-order-item-meta" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #c3c4c7;">';
		echo '<strong>' . esc_html__( 'StoreCanvas', 'storecanvas' ) . '</strong>';
		if ( $preview ) {
			$url = wp_get_attachment_image_url( $preview, 'thumbnail' );
			if ( $url ) {
				echo '<p style="margin:6px 0 0;"><img src="' . esc_url( $url ) . '" alt="" style="max-width:120px;height:auto;border:1px solid #ddd;" /></p>';
			}
		}
		if ( is_array( $labels ) && $labels ) {
			echo '<ul style="margin:4px 0 0 1em;">';
			foreach ( $labels as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				echo '<li>' . esc_html( ( $meta['label'] ?? '' ) . ': ' . ( $meta['value'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		} elseif ( is_array( $options ) ) {
			echo '<ul style="margin:4px 0 0 1em;">';
			foreach ( $options as $k => $v ) {
				echo '<li>' . esc_html( $k ) . ': ' . esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $placement ) {
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Placement data saved.', 'storecanvas' ) . '</p>';
		}
		if ( is_array( $layers ) && $layers ) {
			echo '<p style="margin:4px 0 0;">' . esc_html( sprintf( __( 'Art layers: %d', 'storecanvas' ), count( $layers ) ) ) . '</p>';
		}
		if ( $extra ) {
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Options extra:', 'storecanvas' ) . ' ' . wp_kses_post( wc_price( (float) $extra ) ) . '</p>';
		}
		echo '</div>';
	}
}
