<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orders CSV export (capability + nonce guarded).
 */
class OB_Export {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_ob_export_orders_csv', array( $this, 'handle_export' ) );
		add_action( 'restrict_manage_posts', array( $this, 'export_button_legacy' ), 50 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'export_button_hpos' ), 50, 2 );
	}

	public function export_button_legacy() {
		global $typenow;
		if ( 'shop_order' !== $typenow || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$this->render_export_link();
	}

	public function export_button_hpos( $order_type = '', $which = '' ) {
		if ( $order_type && 'shop_order' !== $order_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$this->render_export_link();
	}

	private function render_export_link() {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_export_orders_csv' ),
			'ob_export_orders_csv'
		);
		// Preserve common filters if present.
		$args = array();
		if ( ! empty( $_GET['ob_attention'] ) ) { // phpcs:ignore
			$args['ob_attention'] = sanitize_text_field( wp_unslash( $_GET['ob_attention'] ) ); // phpcs:ignore
		}
		if ( ! empty( $_GET['post_status'] ) && 'all' !== $_GET['post_status'] ) { // phpcs:ignore
			$args['status'] = sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore
		}
		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore
			$args['status'] = sanitize_key( wp_unslash( $_GET['status'] ) ); // phpcs:ignore
		}
		if ( $args ) {
			$url = add_query_arg( $args, $url );
		}
		echo ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export Orderbay CSV', 'orderbay' ) . '</a> ';
	}

	/**
	 * Tools page export form.
	 */
	public static function render_tools_static() {
		self::instance()->render_tools();
	}

	public function render_tools() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=ob_export_orders_csv' ), 'ob_export_orders_csv' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay export', 'orderbay' ) . '</h1>';
		echo '<p>' . esc_html__( 'Download recent orders as CSV (order_id, number, date, status, total, customer, email, phone, needs_attention, tags).', 'orderbay' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ob_export_orders_csv" />';
		wp_nonce_field( 'ob_export_orders_csv' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Status', 'orderbay' ) . '</th><td><select name="status"><option value="">' . esc_html__( 'Any', 'orderbay' ) . '</option>';
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$key = str_replace( 'wc-', '', $slug );
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Needs attention only', 'orderbay' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ob_attention" value="1" /> ' . esc_html__( 'Yes', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Days back', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="1" max="365" name="days" value="30" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Limit', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="1" max="2000" name="limit" value="200" /></td></tr>';
		echo '</table>';
		submit_button( __( 'Download CSV', 'orderbay' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	public function handle_export() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		check_admin_referer( 'ob_export_orders_csv' );

		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore
		$status = str_replace( 'wc-', '', $status );
		$attention = isset( $_REQUEST['ob_attention'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ob_attention'] ) ) : ''; // phpcs:ignore
		$days  = isset( $_REQUEST['days'] ) ? max( 1, min( 365, absint( $_REQUEST['days'] ) ) ) : 30; // phpcs:ignore
		$limit = isset( $_REQUEST['limit'] ) ? max( 1, min( 2000, absint( $_REQUEST['limit'] ) ) ) : 200; // phpcs:ignore

		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
			'date_created' => '>=' . gmdate( 'Y-m-d 00:00:00', time() - $days * DAY_IN_SECONDS ),
		);
		if ( $status && 'all' !== $status && 'trash' !== $status ) {
			$args['status'] = $status;
		}
		if ( '1' === $attention ) {
			$args['meta_key']   = OB_Plugin::META_ATTENTION;
			$args['meta_value'] = '1';
		}

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$filename = 'orderbay-orders-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open output stream.', 'orderbay' ) );
		}
		// UTF-8 BOM for Excel.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'order_id', 'number', 'date', 'status', 'total', 'customer', 'email', 'phone', 'needs_attention', 'tags' ) );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$tags = $order->get_meta( OB_Plugin::META_TAGS );
			if ( is_array( $tags ) ) {
				$tags = implode( '|', $tags );
			}
			fputcsv(
				$out,
				array(
					$order->get_id(),
					$order->get_order_number(),
					$order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
					$order->get_status(),
					$order->get_total(),
					$order->get_formatted_billing_full_name(),
					$order->get_billing_email(),
					$order->get_billing_phone(),
					$order->get_meta( OB_Plugin::META_ATTENTION ) ? '1' : '0',
					(string) $tags,
				)
			);
		}
		fclose( $out );
		exit;
	}
}
