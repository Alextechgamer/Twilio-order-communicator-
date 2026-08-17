<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * D) Product bulk helpers + duplicate.
 */
class OB_Catalog {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 20, 2 );
		add_action( 'admin_post_ob_duplicate_product', array( $this, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notices' ) );
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'product_bin_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_bin' ) );
	}

	public function bulk_actions( $actions ) {
		$actions['ob_price_pct']    = __( 'Orderbay: adjust price by %…', 'orderbay' );
		$actions['ob_price_fixed']  = __( 'Orderbay: set regular price…', 'orderbay' );
		$actions['ob_set_stock']    = __( 'Orderbay: set stock qty…', 'orderbay' );
		$actions['ob_assign_cat']   = __( 'Orderbay: assign category…', 'orderbay' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $post_ids ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			return $redirect;
		}
		$post_ids = array_map( 'absint', (array) $post_ids );
		$count    = 0;

		switch ( $action ) {
			case 'ob_price_pct':
				$pct = isset( $_REQUEST['ob_price_pct'] ) ? (float) wp_unslash( $_REQUEST['ob_price_pct'] ) : 0; // phpcs:ignore
				if ( 0.0 === $pct ) {
					return add_query_arg( 'ob_cat_msg', rawurlencode( __( 'Percent was 0 — no products changed.', 'orderbay' ) ), $redirect );
				}
				$count = $this->adjust_prices( $post_ids, $pct );
				break;
			case 'ob_price_fixed':
				$price = isset( $_REQUEST['ob_fixed_price'] ) ? wc_format_decimal( wp_unslash( $_REQUEST['ob_fixed_price'] ) ) : ''; // phpcs:ignore
				if ( '' === $price || null === $price ) {
					// Prompt fallback: no change without value.
					return add_query_arg( 'ob_cat_msg', rawurlencode( __( 'Provide ob_fixed_price in the request (use Orderbay admin JS or re-run with price).', 'orderbay' ) ), $redirect );
				}
				foreach ( $post_ids as $id ) {
					$p = wc_get_product( $id );
					if ( ! $p ) {
						continue;
					}
					$p->set_regular_price( $price );
					$p->save();
					$count++;
				}
				break;
			case 'ob_set_stock':
				$qty = isset( $_REQUEST['ob_stock_qty'] ) ? absint( $_REQUEST['ob_stock_qty'] ) : null; // phpcs:ignore
				if ( null === $qty && ! isset( $_REQUEST['ob_stock_qty'] ) ) { // phpcs:ignore
					return add_query_arg( 'ob_cat_msg', rawurlencode( __( 'Provide ob_stock_qty.', 'orderbay' ) ), $redirect );
				}
				foreach ( $post_ids as $id ) {
					$p = wc_get_product( $id );
					if ( ! $p ) {
						continue;
					}
					$p->set_manage_stock( true );
					$p->set_stock_quantity( (int) $qty );
					$p->save();
					$count++;
				}
				break;
			case 'ob_assign_cat':
				$cat = isset( $_REQUEST['ob_cat_id'] ) ? absint( $_REQUEST['ob_cat_id'] ) : 0; // phpcs:ignore
				if ( ! $cat ) {
					return add_query_arg( 'ob_cat_msg', rawurlencode( __( 'Provide ob_cat_id (product_cat term ID).', 'orderbay' ) ), $redirect );
				}
				foreach ( $post_ids as $id ) {
					wp_set_object_terms( $id, array( $cat ), 'product_cat', true );
					$count++;
				}
				break;
			default:
				return $redirect;
		}

		return add_query_arg( 'ob_cat_done', $count, $redirect );
	}

	/**
	 * @param int[] $ids Product IDs.
	 * @param float $pct Percent delta.
	 * @return int
	 */
	private function adjust_prices( $ids, $pct ) {
		$count = 0;
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$reg = (float) $p->get_regular_price();
			if ( $reg <= 0 ) {
				continue;
			}
			$new = round( $reg * ( 1 + ( $pct / 100 ) ), wc_get_price_decimals() );
			$p->set_regular_price( (string) $new );
			$p->save();
			$count++;
		}
		return $count;
	}

	public function row_actions( $actions, $post ) {
		if ( 'product' !== $post->post_type || ! current_user_can( 'edit_products' ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_duplicate_product&product_id=' . $post->ID ),
			'ob_duplicate_product_' . $post->ID
		);
		$actions['ob_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate (Orderbay)', 'orderbay' ) . '</a>';
		return $actions;
	}

	public function handle_duplicate() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_duplicate_product_' . $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_die( esc_html__( 'Product not found', 'orderbay' ) );
		}
		$new_id = $this->duplicate_product( $product );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}
		wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
		exit;
	}

	/**
	 * Copy product + basic meta; skip StoreCanvas keys.
	 *
	 * @param WC_Product $product Source.
	 * @return int|\WP_Error
	 */
	public function duplicate_product( $product ) {
		$type = $product->get_type();
		// Prefer simple/variable/external/grouped via WC factory; fall back to simple.
		$clone = null;
		if ( function_exists( 'wc_get_product_object' ) ) {
			try {
				$clone = wc_get_product_object( $type );
			} catch ( Exception $e ) {
				$clone = null;
			}
		}
		if ( ! $clone ) {
			$cls = 'WC_Product_Simple';
			if ( class_exists( get_class( $product ) ) && is_subclass_of( get_class( $product ), 'WC_Product' ) ) {
				$cls = get_class( $product );
			}
			try {
				$clone = new $cls();
			} catch ( Exception $e ) {
				return new WP_Error( 'ob_dup_type', __( 'Unsupported product type for duplicate.', 'orderbay' ) );
			}
		}
		/** @var WC_Product $clone */
		$clone->set_name( $product->get_name() . ' ' . __( '(Copy)', 'orderbay' ) );
		$clone->set_status( 'draft' );
		$clone->set_catalog_visibility( $product->get_catalog_visibility() );
		$clone->set_description( $product->get_description() );
		$clone->set_short_description( $product->get_short_description() );
		$clone->set_sku( '' ); // Avoid unique SKU clash.
		$clone->set_regular_price( $product->get_regular_price() );
		$clone->set_sale_price( $product->get_sale_price() );
		$clone->set_manage_stock( $product->get_manage_stock() );
		$clone->set_stock_quantity( $product->get_stock_quantity() );
		$clone->set_stock_status( $product->get_stock_status() );
		$clone->set_weight( $product->get_weight() );
		$clone->set_length( $product->get_length() );
		$clone->set_width( $product->get_width() );
		$clone->set_height( $product->get_height() );
		$clone->set_category_ids( $product->get_category_ids() );
		$clone->set_tag_ids( $product->get_tag_ids() );
		$clone->set_image_id( $product->get_image_id() );
		$clone->set_gallery_image_ids( $product->get_gallery_image_ids() );
		$new_id = $clone->save();
		if ( ! $new_id ) {
			return new WP_Error( 'ob_dup', __( 'Could not duplicate product.', 'orderbay' ) );
		}
		// Copy non-SC product meta lightly.
		$skip = array( '_edit_lock', '_edit_last', '_sc_options', '_sc_customizer', '_sc_validation', '_sc_clipart_ids' );
		$meta = get_post_meta( $product->get_id() );
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_sc_' ) ) {
				continue;
			}
			// Skip core WC props already set via setters.
			if ( 0 === strpos( $key, '_' ) && in_array( $key, array( '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status', '_manage_stock', '_thumbnail_id' ), true ) ) {
				continue;
			}
			foreach ( $values as $v ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
			}
		}
		return (int) $new_id;
	}

	public function bulk_notices() {
		if ( isset( $_GET['ob_cat_done'] ) ) { // phpcs:ignore
			$n = absint( $_GET['ob_cat_done'] ); // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Orderbay: applied bulk change to %d product(s).', 'orderbay' ), $n ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['ob_cat_msg'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ob_cat_msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	/**
	 * Product inventory: bin / location field.
	 */
	public function product_bin_field() {
		woocommerce_wp_text_input(
			array(
				'id'          => 'ob_bin_location',
				'label'       => __( 'Bin / location (Orderbay)', 'orderbay' ),
				'desc_tip'    => true,
				'description' => __( 'Shown on warehouse pick lists.', 'orderbay' ),
				'value'       => get_post_meta( get_the_ID(), OB_Plugin::META_BIN, true ),
			)
		);
	}

	/**
	 * @param int $product_id Product ID.
	 */
	public function save_product_bin( $product_id ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		if ( isset( $_POST['ob_bin_location'] ) ) { // phpcs:ignore
			$bin = sanitize_text_field( wp_unslash( $_POST['ob_bin_location'] ) ); // phpcs:ignore
			if ( $bin ) {
				update_post_meta( $product_id, OB_Plugin::META_BIN, $bin );
			} else {
				delete_post_meta( $product_id, OB_Plugin::META_BIN );
			}
		}
	}
}
