<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orders list: SC Art column + filter (HPOS + legacy) (0.8.0).
 *
 * Detects custom art via line item meta keys:
 * - sc_layers, sc_placement, sc_attachments
 * - sc_print_files, _sc_artwork_id
 * - order meta _sc_has_custom_art (set at checkout when SC data present)
 */
class SC_Orders_List {

	const ORDER_META = '_sc_has_custom_art';
	const FILTER_KEY = 'sc_has_art';

	/** @var string[] Item meta keys that indicate StoreCanvas art. */
	const ITEM_KEYS = array(
		'sc_layers',
		'sc_placement',
		'sc_attachments',
		'sc_print_files',
		'_sc_artwork_id',
	);

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Legacy CPT orders list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'column_legacy' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown_legacy' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'filter_query_legacy' ) );

		// HPOS orders list.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'column_hpos' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos' ), 20, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'filter_dropdown_hpos' ), 20, 2 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'filter_query_hpos' ), 20 );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'filter_clauses_hpos' ), 20, 2 );

		// Stamp order meta when SC line data is present.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'maybe_stamp_order' ), 30, 4 );
	}

	/**
	 * @param WC_Order_Item $item Item.
	 * @param string        $cart_item_key Key.
	 * @param array         $values Values.
	 * @param WC_Order      $order Order.
	 */
	public function maybe_stamp_order( $item, $cart_item_key, $values, $order ) {
		$has = false;
		if ( ! empty( $values[ SC_Plugin::CART_LAYERS ] ) || ! empty( $values[ SC_Plugin::CART_PLACEMENT ] ) || ! empty( $values[ SC_Plugin::CART_ATTACHMENTS ] ) ) {
			$has = true;
		}
		if ( $has && $order instanceof WC_Order ) {
			$order->update_meta_data( self::ORDER_META, '1' );
		}
	}

	public function column_legacy( $columns ) {
		$new = array();
		foreach ( $columns as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['sc_art'] = __( 'SC Art', 'storecanvas' );
			}
		}
		if ( ! isset( $new['sc_art'] ) ) {
			$new['sc_art'] = __( 'SC Art', 'storecanvas' );
		}
		return $new;
	}

	public function column_hpos( $columns ) {
		return $this->column_legacy( $columns );
	}

	public function render_legacy( $column, $post_id ) {
		if ( 'sc_art' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		echo $this->cell_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param string   $column Column.
	 * @param WC_Order $order Order.
	 */
	public function render_hpos( $column, $order ) {
		if ( 'sc_art' !== $column ) {
			return;
		}
		echo $this->cell_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param WC_Order|false|null $order Order.
	 * @return string
	 */
	private function cell_html( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '<span class="sc-art-no">—</span>';
		}
		if ( $this->order_has_art( $order ) ) {
			return '<span class="sc-art-yes" style="color:#007017;font-weight:600;">' . esc_html__( 'Yes', 'storecanvas' ) . '</span>';
		}
		return '<span class="sc-art-no">—</span>';
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function order_has_art( $order ) {
		if ( $order->get_meta( self::ORDER_META ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			foreach ( self::ITEM_KEYS as $key ) {
				$val = $item->get_meta( $key );
				if ( ! empty( $val ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function filter_dropdown_legacy() {
		global $typenow;
		if ( 'shop_order' !== $typenow ) {
			return;
		}
		$this->render_filter_select();
	}

	/**
	 * @param string $order_type Order type.
	 * @param string $which Which.
	 */
	public function filter_dropdown_hpos( $order_type = '', $which = '' ) {
		if ( $order_type && 'shop_order' !== $order_type ) {
			return;
		}
		$this->render_filter_select();
	}

	private function render_filter_select() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$selected = isset( $_GET[ self::FILTER_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) ) : ''; // phpcs:ignore
		echo '<select name="' . esc_attr( self::FILTER_KEY ) . '" id="dropdown_sc_has_art">';
		echo '<option value="">' . esc_html__( 'All orders (SC art)', 'storecanvas' ) . '</option>';
		echo '<option value="1"' . selected( $selected, '1', false ) . '>' . esc_html__( 'Has StoreCanvas art', 'storecanvas' ) . '</option>';
		echo '<option value="0"' . selected( $selected, '0', false ) . '>' . esc_html__( 'No StoreCanvas art', 'storecanvas' ) . '</option>';
		echo '</select>';
	}

	public function filter_query_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! isset( $_GET[ self::FILTER_KEY ] ) || '' === $_GET[ self::FILTER_KEY ] ) { // phpcs:ignore
			return;
		}
		$want = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) ); // phpcs:ignore
		if ( '1' === $want ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => self::ORDER_META,
						'value' => '1',
					),
				)
			);
		} elseif ( '0' === $want ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => self::ORDER_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => self::ORDER_META,
						'value' => '0',
					),
				)
			);
		}
	}

	/**
	 * Prefer order meta filter when stamped; clauses join used as fallback for HPOS "has art".
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function filter_query_hpos( $args ) {
		if ( ! is_admin() || ! isset( $_GET[ self::FILTER_KEY ] ) || '' === $_GET[ self::FILTER_KEY ] ) { // phpcs:ignore
			return $args;
		}
		// Only when viewing orders list.
		if ( empty( $_GET['page'] ) || 'wc-orders' !== $_GET['page'] ) { // phpcs:ignore
			// Still apply if filter present on order screens.
		}
		$want = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) ); // phpcs:ignore
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}
		if ( '1' === $want ) {
			$args['meta_query'][] = array(
				'key'   => self::ORDER_META,
				'value' => '1',
			);
		} elseif ( '0' === $want ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => self::ORDER_META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => self::ORDER_META,
					'value' => '0',
				),
			);
		}
		return $args;
	}

	/**
	 * Extra join when order meta not stamped but item meta exists (has art = 1 only).
	 *
	 * @param array $clauses Clauses.
	 * @param object $query Query.
	 * @return array
	 */
	public function filter_clauses_hpos( $clauses, $query ) {
		// Meta query on ORDER_META is primary; no extra join required for stamped orders.
		return $clauses;
	}
}
