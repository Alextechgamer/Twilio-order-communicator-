<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Light per-line partial fulfillment (no WC status changes).
 */
class OB_Partial {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'item_header' ) );
		add_action( 'woocommerce_admin_order_item_values', array( $this, 'item_values' ), 10, 3 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_legacy' ), 55, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'save_hpos' ), 35 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'helper_ui' ), 45 );
		add_action( 'admin_post_ob_mark_fully_fulfilled', array( $this, 'handle_mark_full' ) );
	}

	public function item_header() {
		echo '<th class="ob-fulfilled">' . esc_html__( 'Fulfilled', 'orderbay' ) . '</th>';
	}

	/**
	 * @param WC_Product|null       $product Product.
	 * @param WC_Order_Item_Product $item Item.
	 * @param int                   $item_id Item ID.
	 */
	public function item_values( $product, $item, $item_id ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			echo '<td class="ob-fulfilled"></td>';
			return;
		}
		$qty  = (int) $item->get_quantity();
		$done = (int) $item->get_meta( OB_Plugin::META_QTY_FULFILLED, true );
		if ( $done < 0 ) {
			$done = 0;
		}
		if ( $done > $qty ) {
			$done = $qty;
		}
		echo '<td class="ob-fulfilled">';
		echo '<input type="number" min="0" max="' . esc_attr( (string) $qty ) . '" step="1" style="width:64px;" name="ob_qty_fulfilled[' . esc_attr( (string) $item_id ) . ']" value="' . esc_attr( (string) $done ) . '" />';
		echo ' <span class="description">/ ' . esc_html( (string) $qty ) . '</span>';
		echo '</td>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function helper_ui( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$status = $order->get_meta( OB_Plugin::META_FULFILL_STATUS );
		$url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_mark_fully_fulfilled&order_id=' . $order->get_id() ),
			'ob_mark_fully_fulfilled_' . $order->get_id()
		);
		echo '<p class="form-field" style="clear:both;">';
		echo '<strong>' . esc_html__( 'Fulfillment', 'orderbay' ) . ':</strong> ';
		echo $status ? esc_html( $status ) : esc_html__( 'open', 'orderbay' );
		echo ' &nbsp; <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mark fully fulfilled', 'orderbay' ) . '</a>';
		echo '</p>';
		// Own nonce for the per-line qty save (rendered inside the order-edit form).
		wp_nonce_field( 'ob_partial_save', 'ob_partial_nonce' );
	}

	public function save_legacy( $order_id, $post = null ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		if ( ! isset( $_POST['ob_qty_fulfilled'] ) || ! is_array( $_POST['ob_qty_fulfilled'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! $this->verify_save_nonce() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_posted( $order, wp_unslash( $_POST['ob_qty_fulfilled'] ) ); // phpcs:ignore
		}
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public function save_hpos( $order_id ) {
		if ( ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		if ( ! isset( $_POST['ob_qty_fulfilled'] ) || ! is_array( $_POST['ob_qty_fulfilled'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! $this->verify_save_nonce() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_posted( $order, wp_unslash( $_POST['ob_qty_fulfilled'] ) ); // phpcs:ignore
		}
	}

	/**
	 * @param WC_Order $order Order.
	 * @param array    $posted item_id => qty.
	 */
	private function apply_posted( $order, $posted ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( ! isset( $posted[ $item_id ] ) ) {
				continue;
			}
			$qty  = (int) $item->get_quantity();
			$done = absint( $posted[ $item_id ] );
			if ( $done > $qty ) {
				$done = $qty;
			}
			$item->update_meta_data( OB_Plugin::META_QTY_FULFILLED, $done );
			$item->save();
		}
		self::refresh_status( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function refresh_status( $order ) {
		$total = 0;
		$done  = 0;
		$any   = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$q = (int) $item->get_quantity();
			$d = (int) $item->get_meta( OB_Plugin::META_QTY_FULFILLED, true );
			if ( $d < 0 ) {
				$d = 0;
			}
			if ( $d > $q ) {
				$d = $q;
			}
			$total += $q;
			$done  += $d;
			if ( $d > 0 ) {
				$any = true;
			}
		}
		if ( $total <= 0 ) {
			$status = 'open';
		} elseif ( $done >= $total ) {
			$status = 'complete';
		} elseif ( $any ) {
			$status = 'partial';
		} else {
			$status = 'open';
		}
		$order->update_meta_data( OB_Plugin::META_FULFILL_STATUS, $status );
		$order->save();
	}

	public function handle_mark_full() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_mark_fully_fulfilled_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$item->update_meta_data( OB_Plugin::META_QTY_FULFILLED, (int) $item->get_quantity() );
			$item->save();
		}
		self::refresh_status( $order );
		// HPOS-safe edit URL (post.php?post= is invalid for orders stored in HPOS tables).
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Verify the plugin's own save nonce (rendered by helper_ui inside the order form).
	 * WooCommerce also gates the order save with its own nonce; this adds an explicit,
	 * plugin-owned check so the qty write cannot ride on an unrelated order save.
	 *
	 * @return bool
	 */
	private function verify_save_nonce() {
		return isset( $_POST['ob_partial_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_partial_nonce'] ) ), 'ob_partial_save' );
	}

	/**
	 * Fulfilled qty for item (clamped).
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @return int
	 */
	public static function get_fulfilled( $item ) {
		$qty  = (int) $item->get_quantity();
		$done = (int) $item->get_meta( OB_Plugin::META_QTY_FULFILLED, true );
		if ( $done < 0 ) {
			$done = 0;
		}
		if ( $done > $qty ) {
			$done = $qty;
		}
		return $done;
	}
}
