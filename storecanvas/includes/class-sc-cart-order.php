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
				$extra          += $this->field_price( $field, $val, $product_id, $variation_id );
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
			$raw       = wp_unslash( $_POST['sc_layers_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$max_bytes = (int) apply_filters( 'sc_max_layers_json_bytes', 512 * 1024 );
			if ( is_string( $raw ) && strlen( $raw ) <= $max_bytes ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$layers = $this->sanitize_layers( $decoded );
					if ( $layers ) {
						$cart_item_data[ SC_Plugin::CART_LAYERS ] = $layers;
					}
				}
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
			} elseif ( ! $this->upload_rate_ok() ) {
				$cart_item_data['sc_validation_errors'] = array(
					__( 'Too many artwork uploads from your connection. Please wait a few minutes and try again.', 'storecanvas' ),
				);
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

	private function field_price( $field, $value, $product_id, $variation_id = 0 ) {
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

		// Lookup-table pricing: the price comes from the selected choice(s), not a single amount.
		if ( 'lookup' === $type ) {
			return self::lookup_price( $field['choices'] ?? array(), $value );
		}

		$amount = (float) ( $field['price'] ?? 0 );

		// Base for percent pricing: the selected variation's own price when a variation is
		// chosen, else the product price. Using the parent price for a variation overcharged or
		// undercharged whenever variations were priced differently from the parent.
		$base = 0.0;
		if ( 'percent' === $type ) {
			$product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
			if ( ! $product && $variation_id ) {
				$product = wc_get_product( $product_id );
			}
			$base = $product ? (float) $product->get_price() : 0.0;
		}

		return self::price_for( $type, $amount, $base, $value );
	}

	/**
	 * Pure price contribution for one option field. Extracted from field_price() so the
	 * pricing rules are unit-testable without a WooCommerce runtime.
	 *
	 * - flat:     the configured amount (may be negative for a discount).
	 * - percent:  amount% of the base product/variation price.
	 * - qty:      amount multiplied by the numeric value the customer entered (per-unit),
	 *             where it previously behaved identically to flat.
	 * - per_char: amount per character of the entered text.
	 *
	 * @param string $type   Price type.
	 * @param float  $amount Configured amount.
	 * @param float  $base   Base price for percent pricing.
	 * @param mixed  $value  Submitted field value.
	 * @return float
	 */
	/**
	 * Sum the per-choice prices for the selected value(s) of a lookup-priced field (pure).
	 *
	 * @param array $choices Field choices, each optionally carrying a numeric 'price'.
	 * @param mixed $value   Selected value (scalar or array for multi-selects).
	 * @return float
	 */
	public static function lookup_price( $choices, $value ) {
		if ( ! is_array( $choices ) ) {
			return 0.0;
		}
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
		$total    = 0.0;
		foreach ( $choices as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['price'] ) ) {
				continue;
			}
			if ( in_array( (string) ( $c['value'] ?? '' ), $selected, true ) ) {
				$total += (float) $c['price'];
			}
		}
		return $total;
	}

	public static function price_for( $type, $amount, $base, $value ) {
		$amount = (float) $amount;
		switch ( $type ) {
			case 'flat':
				return $amount;
			case 'percent':
				return (float) $base * ( $amount / 100 );
			case 'qty':
				$qty = is_array( $value ) ? 0.0 : (float) $value;
				return $amount * $qty;
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
		if ( 0.0 === $extra ) {
			return $cart_item;
		}
		$base  = (float) $cart_item['data']->get_price( 'edit' );
		$price = $base + $extra;
		// A negative options total (a discount) may reduce the price but never below zero.
		if ( $price < 0 ) {
			$price = 0.0;
		}
		$cart_item['data']->set_price( $price );
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
		if ( ! empty( $cart_item['sc_price_extra'] ) ) {
			// Show any non-zero options total, including a negative (discount) so the cart line
			// matches what is actually charged.
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

	/**
	 * Bound and whitelist customer-supplied design-layer JSON before it is stored to
	 * cart/order meta. Untrusted input previously reached order meta and the GD
	 * compositor verbatim, letting a guest reference arbitrary attachment IDs and post
	 * unbounded structures. Here we cap the layer count, coerce IDs/placements, gate
	 * attachment references (see layer_attachment_allowed), and keep only bounded scalars.
	 *
	 * @param array $layers Decoded layer list.
	 * @return array
	 */
	private function sanitize_layers( $layers ) {
		if ( ! is_array( $layers ) ) {
			return array();
		}
		$max = max( 1, (int) apply_filters( 'sc_max_layers', 100 ) );
		$out = array();
		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			$out[] = $this->sanitize_layer( $layer );
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array $layer One raw layer.
	 * @return array Sanitized layer.
	 */
	private function sanitize_layer( $layer ) {
		$out  = array();
		$type = isset( $layer['type'] ) ? sanitize_key( (string) $layer['type'] ) : 'image';
		$out['type'] = $type;

		// Image reference — the security-sensitive fields.
		if ( ! empty( $layer['attachment_id'] ) ) {
			$aid = (int) $layer['attachment_id'];
			if ( $aid > 0 && self::layer_attachment_allowed( $aid ) ) {
				$out['attachment_id'] = $aid;
			}
		}
		if ( ! empty( $layer['clipart_id'] ) ) {
			$out['clipart_id'] = max( 0, (int) $layer['clipart_id'] );
		}

		// Placement maps: view_id => {x,y,scale,rotation}. Clamp scale like the compositor.
		foreach ( array( 'placements', 'placementByView' ) as $pk ) {
			if ( empty( $layer[ $pk ] ) || ! is_array( $layer[ $pk ] ) ) {
				continue;
			}
			$clean = array();
			$views = 0;
			foreach ( $layer[ $pk ] as $view => $pl ) {
				if ( $views++ >= 30 || ! is_array( $pl ) ) {
					continue;
				}
				$clean[ sanitize_key( (string) $view ) ] = array(
					'x'        => isset( $pl['x'] ) ? (float) $pl['x'] : 50,
					'y'        => isset( $pl['y'] ) ? (float) $pl['y'] : 50,
					'scale'    => isset( $pl['scale'] ) ? max( 0.1, min( 3.0, (float) $pl['scale'] ) ) : 1.0,
					'rotation' => isset( $pl['rotation'] ) ? (float) $pl['rotation'] : 0.0,
				);
			}
			if ( $clean ) {
				$out[ $pk ] = $clean;
			}
		}

		// Preserve remaining scalar props (e.g. text-layer fields) as bounded strings;
		// drop nested/unknown structures to keep stored meta well-shaped.
		$reserved = array( 'type', 'attachment_id', 'clipart_id', 'placements', 'placementByView' );
		$kept     = 0;
		foreach ( $layer as $k => $v ) {
			if ( in_array( $k, $reserved, true ) || ! is_scalar( $v ) || $kept >= 40 ) {
				continue;
			}
			$key = sanitize_key( (string) $k );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = substr( wp_strip_all_tags( (string) $v ), 0, 2000 );
			$kept++;
		}

		return $out;
	}

	/**
	 * Whether a design layer may reference a given attachment ID.
	 *
	 * Closes the arbitrary-attachment-inclusion IDOR: guest-supplied layer JSON could
	 * name any attachment on the site and have it composited into a print file. We allow
	 * only image attachments that are StoreCanvas-originated uploads, owned by the current
	 * logged-in user, or (for admin regeneration) referenced by a shop manager. Stores can
	 * widen/narrow this with the `sc_layer_attachment_allowed` filter.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function layer_attachment_allowed( $id ) {
		$id      = (int) $id;
		$allowed = false;
		if ( $id > 0 && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) {
			if ( get_post_meta( $id, '_sc_uploaded', true ) ) {
				$allowed = true;
			} elseif ( is_user_logged_in() ) {
				$post = get_post( $id );
				if ( $post && (int) $post->post_author === get_current_user_id() && get_current_user_id() > 0 ) {
					$allowed = true;
				}
			}
			if ( ! $allowed && current_user_can( 'edit_shop_orders' ) ) {
				$allowed = true;
			}
		}
		/**
		 * Filter whether a design layer may reference this attachment.
		 *
		 * @param bool $allowed Current decision.
		 * @param int  $id      Attachment ID.
		 */
		return (bool) apply_filters( 'sc_layer_attachment_allowed', $allowed, $id );
	}

	/**
	 * Simple per-IP throttle for the unauthenticated add-to-cart artwork upload, so a
	 * visitor cannot flood the media library with orphaned uploads. Filter the cap with
	 * `sc_max_uploads_per_hour` (0 disables the throttle).
	 *
	 * @return bool True if another upload is allowed right now.
	 */
	private function upload_rate_ok() {
		$max = (int) apply_filters( 'sc_max_uploads_per_hour', 30 );
		if ( $max <= 0 ) {
			return true;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'sc_up_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
