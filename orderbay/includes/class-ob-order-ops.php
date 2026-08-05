<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B) Order tags, needs attention, bulk status/notes.
 */
class OB_Order_Ops {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Meta box on order.
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_meta' ), 40, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'save_hpos_fields' ), 20 );

		// List columns.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'column_legacy' ), 25 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 25, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'column_hpos' ), 25 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos' ), 25, 2 );

		// Filters.
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown_legacy' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query_legacy' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'filter_dropdown_hpos' ), 20, 2 );
		add_filter( 'woocommerce_order_query_args', array( $this, 'filter_query_hpos' ), 20 );

		// Bulk.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk' ), 10, 3 );

		// Order screen fields for HPOS (side panel style via order data).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_side_fields' ) );
	}

	public function meta_boxes() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			? wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order'
			: 'shop_order';
		// Prefer after_order_details for reliability; meta box as fallback for classic.
		add_meta_box(
			'ob_order_ops',
			__( 'Orderbay', 'orderbay' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$this->render_fields( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function order_side_fields( $order ) {
		// Avoid double UI if meta box already shown; still ok as compact fields.
		echo '<div class="ob-order-side" style="clear:both;padding:12px 0;">';
		echo '<h3>' . esc_html__( 'Orderbay', 'orderbay' ) . '</h3>';
		$this->render_fields( $order );
		echo '</div>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function render_fields( $order ) {
		wp_nonce_field( 'ob_order_ops_save', 'ob_order_ops_nonce' );
		$attention = $order->get_meta( OB_Plugin::META_ATTENTION );
		$tags      = $order->get_meta( OB_Plugin::META_TAGS );
		if ( is_array( $tags ) ) {
			$tags = implode( ', ', $tags );
		}
		if ( $attention ) {
			echo '<p class="ob-attention-badge" style="margin:0 0 8px;"><span style="display:inline-block;background:#b32d2e;color:#fff;padding:2px 8px;border-radius:3px;font-weight:600;font-size:12px;">' . esc_html__( 'Needs attention', 'orderbay' ) . '</span></p>';
		}
		echo '<p><label><input type="checkbox" name="ob_needs_attention" value="1" ' . checked( $attention, '1', false ) . ' /> ';
		echo esc_html__( 'Needs attention', 'orderbay' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Tags (comma-separated)', 'orderbay' ) . '<br />';
		echo '<input type="text" class="widefat" name="ob_order_tags" value="' . esc_attr( (string) $tags ) . '" /></label></p>';
	}

	public function save_order_meta( $order_id, $post = null ) {
		if ( ! isset( $_POST['ob_order_ops_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_order_ops_nonce'] ) ), 'ob_order_ops_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->apply_posted_fields( $order );
		$order->save();
	}

	/**
	 * HPOS save path.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_hpos_fields( $order_id ) {
		if ( ! is_admin() || ! isset( $_POST['ob_order_ops_nonce'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_order_ops_nonce'] ) ), 'ob_order_ops_save' ) ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->apply_posted_fields( $order );
		// Avoid recursion: update meta without nested save if possible.
		$order->save();
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function apply_posted_fields( $order ) {
		$attention = ! empty( $_POST['ob_needs_attention'] ) ? '1' : ''; // phpcs:ignore
		if ( $attention ) {
			$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
		} else {
			$order->delete_meta_data( OB_Plugin::META_ATTENTION );
		}
		$raw  = isset( $_POST['ob_order_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['ob_order_tags'] ) ) : ''; // phpcs:ignore
		$tags = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( $tags ) {
			$order->update_meta_data( OB_Plugin::META_TAGS, $tags );
		} else {
			$order->delete_meta_data( OB_Plugin::META_TAGS );
		}
	}

	public function column_legacy( $columns ) {
		$new = array();
		foreach ( $columns as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['ob_attention'] = __( 'Attention', 'orderbay' );
				$new['ob_tags']      = __( 'Tags', 'orderbay' );
			}
		}
		if ( ! isset( $new['ob_attention'] ) ) {
			$new['ob_attention'] = __( 'Attention', 'orderbay' );
			$new['ob_tags']      = __( 'Tags', 'orderbay' );
		}
		return $new;
	}

	public function column_hpos( $columns ) {
		return $this->column_legacy( $columns );
	}

	public function render_legacy( $column, $post_id ) {
		$order = wc_get_order( $post_id );
		$this->render_column( $column, $order );
	}

	public function render_hpos( $column, $order ) {
		$this->render_column( $column, $order );
	}

	private function render_column( $column, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'ob_attention' === $column ) {
			echo $order->get_meta( OB_Plugin::META_ATTENTION ) ? '<span style="color:#b32d2e;font-weight:600;">!</span>' : '—';
		}
		if ( 'ob_tags' === $column ) {
			$tags = $order->get_meta( OB_Plugin::META_TAGS );
			if ( is_array( $tags ) && $tags ) {
				echo esc_html( implode( ', ', $tags ) );
			} else {
				echo '—';
			}
		}
	}

	public function filter_dropdown_legacy() {
		global $typenow;
		if ( 'shop_order' !== $typenow ) {
			return;
		}
		$this->render_filter();
	}

	public function filter_dropdown_hpos( $order_type = '', $which = '' ) {
		if ( $order_type && 'shop_order' !== $order_type ) {
			return;
		}
		$this->render_filter();
	}

	private function render_filter() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$sel = isset( $_GET['ob_attention'] ) ? sanitize_text_field( wp_unslash( $_GET['ob_attention'] ) ) : ''; // phpcs:ignore
		echo '<select name="ob_attention">';
		echo '<option value="">' . esc_html__( 'Attention (all)', 'orderbay' ) . '</option>';
		echo '<option value="1"' . selected( $sel, '1', false ) . '>' . esc_html__( 'Needs attention', 'orderbay' ) . '</option>';
		echo '<option value="0"' . selected( $sel, '0', false ) . '>' . esc_html__( 'Not flagged', 'orderbay' ) . '</option>';
		echo '</select>';
	}

	public function filter_query_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! isset( $_GET['ob_attention'] ) || '' === $_GET['ob_attention'] ) { // phpcs:ignore
			return;
		}
		$want = sanitize_text_field( wp_unslash( $_GET['ob_attention'] ) ); // phpcs:ignore
		if ( '1' === $want ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => OB_Plugin::META_ATTENTION,
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
						'key'     => OB_Plugin::META_ATTENTION,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => OB_Plugin::META_ATTENTION,
						'value' => '',
					),
				)
			);
		}
	}

	public function filter_query_hpos( $args ) {
		if ( ! is_admin() || ! isset( $_GET['ob_attention'] ) || '' === $_GET['ob_attention'] ) { // phpcs:ignore
			return $args;
		}
		$want = sanitize_text_field( wp_unslash( $_GET['ob_attention'] ) ); // phpcs:ignore
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array();
		}
		if ( '1' === $want ) {
			$args['meta_query'][] = array(
				'key'   => OB_Plugin::META_ATTENTION,
				'value' => '1',
			);
		} elseif ( '0' === $want ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => OB_Plugin::META_ATTENTION,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => OB_Plugin::META_ATTENTION,
					'value' => '',
				),
			);
		}
		return $args;
	}

	public function bulk_actions( $actions ) {
		$actions['ob_set_attention']   = __( 'Orderbay: mark needs attention', 'orderbay' );
		$actions['ob_clear_attention'] = __( 'Orderbay: clear attention', 'orderbay' );
		$actions['ob_add_note']        = __( 'Orderbay: add private note…', 'orderbay' );
		$actions['ob_add_tag']         = __( 'Orderbay: add tag…', 'orderbay' );
		$actions['ob_status_processing'] = __( 'Orderbay: set status → Processing', 'orderbay' );
		$actions['ob_status_completed']  = __( 'Orderbay: set status → Completed', 'orderbay' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$ids = array_map( 'absint', (array) $ids );
		$count = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			switch ( $action ) {
				case 'ob_set_attention':
					$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
					$order->save();
					$count++;
					break;
				case 'ob_clear_attention':
					$order->delete_meta_data( OB_Plugin::META_ATTENTION );
					$order->save();
					$count++;
					break;
				case 'ob_status_processing':
					$order->update_status( 'processing', __( 'Orderbay bulk status change.', 'orderbay' ) );
					$count++;
					break;
				case 'ob_status_completed':
					$order->update_status( 'completed', __( 'Orderbay bulk status change.', 'orderbay' ) );
					$count++;
					break;
				case 'ob_add_note':
					$note = isset( $_REQUEST['ob_bulk_note'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ob_bulk_note'] ) ) : ''; // phpcs:ignore
					if ( ! $note ) {
						$note = __( 'Orderbay bulk note.', 'orderbay' );
					}
					$order->add_order_note( $note, false, true );
					$count++;
					break;
				case 'ob_add_tag':
					$tag = isset( $_REQUEST['ob_bulk_tag'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ob_bulk_tag'] ) ) : ''; // phpcs:ignore
					if ( $tag ) {
						$existing = $order->get_meta( OB_Plugin::META_TAGS );
						if ( ! is_array( $existing ) ) {
							$existing = $existing ? array( (string) $existing ) : array();
						}
						if ( ! in_array( $tag, $existing, true ) ) {
							$existing[] = $tag;
						}
						$order->update_meta_data( OB_Plugin::META_TAGS, array_values( $existing ) );
						$order->save();
						$count++;
					}
					break;
			}
		}
		return add_query_arg( 'ob_bulk_done', $count, $redirect );
	}
}
