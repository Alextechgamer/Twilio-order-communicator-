<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order print sheet (0.5.0) — HTML sheet printable to PDF.
 */
class SC_Print_Sheet {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_order_actions', array( $this, 'order_action' ) );
		add_action( 'woocommerce_order_action_sc_print_sheet', array( $this, 'handle_action' ) );
		add_action( 'admin_post_sc_print_sheet', array( $this, 'render_sheet' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_button' ) );
	}

	public function order_action( $actions ) {
		$actions['sc_print_sheet'] = __( 'StoreCanvas: open print sheet', 'storecanvas' );
		return $actions;
	}

	public function handle_action( $order ) {
		$url = admin_url( 'admin-post.php?action=sc_print_sheet&order_id=' . $order->get_id() . '&_wpnonce=' . wp_create_nonce( 'sc_print_sheet_' . $order->get_id() ) );
		$order->add_order_note( sprintf( __( 'Print sheet: %s', 'storecanvas' ), $url ), false, true );
	}

	public function order_button( $order ) {
		$url = admin_url( 'admin-post.php?action=sc_print_sheet&order_id=' . $order->get_id() . '&_wpnonce=' . wp_create_nonce( 'sc_print_sheet_' . $order->get_id() ) );
		echo '<p class="form-field" style="clear:both;padding-left:0;">';
		echo '<a class="button button-primary" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'StoreCanvas print sheet', 'storecanvas' ) . '</a>';
		echo '</p>';
	}

	public function render_sheet() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		check_admin_referer( 'sc_print_sheet_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'storecanvas' ) );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>StoreCanvas Print Sheet #' . esc_html( (string) $order_id ) . '</title>';
		echo '<style>
			body{font-family:system-ui,sans-serif;margin:24px;color:#111}
			h1{font-size:20px;margin:0 0 8px}
			.meta{color:#555;margin-bottom:24px}
			.item{page-break-inside:avoid;border:1px solid #ccc;padding:16px;margin-bottom:20px}
			.item h2{font-size:16px;margin:0 0 8px}
			.grid{display:flex;flex-wrap:wrap;gap:12px}
			.grid img{max-width:280px;max-height:280px;border:1px solid #ddd}
			table{border-collapse:collapse;width:100%;margin-top:8px}
			td,th{border:1px solid #ddd;padding:6px 8px;text-align:left;font-size:13px}
			@media print{body{margin:12px}.no-print{display:none}}
		</style></head><body>';
		echo '<p class="no-print"><button onclick="window.print()">' . esc_html__( 'Print / Save as PDF', 'storecanvas' ) . '</button></p>';
		echo '<h1>' . esc_html( sprintf( __( 'StoreCanvas print sheet — Order #%s', 'storecanvas' ), $order->get_order_number() ) ) . '</h1>';
		echo '<div class="meta">' . esc_html( $order->get_formatted_billing_full_name() ) . ' · ' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ) . '</div>';

		foreach ( $order->get_items() as $item_id => $item ) {
			$files  = $item->get_meta( SC_Print_Ready::META_PRINT_FILES );
			$art_id = (int) $item->get_meta( '_sc_artwork_id' );
			$opts   = $item->get_meta( SC_Plugin::CART_OPTIONS );
			$place  = $item->get_meta( SC_Plugin::CART_PLACEMENT );
			$layers = $item->get_meta( SC_Plugin::CART_LAYERS );

			echo '<div class="item">';
			echo '<h2>' . esc_html( $item->get_name() ) . ' × ' . esc_html( (string) $item->get_quantity() ) . '</h2>';
			if ( is_array( $opts ) && $opts ) {
				echo '<table><thead><tr><th>' . esc_html__( 'Option', 'storecanvas' ) . '</th><th>' . esc_html__( 'Value', 'storecanvas' ) . '</th></tr></thead><tbody>';
				foreach ( $opts as $k => $v ) {
					echo '<tr><td>' . esc_html( (string) $k ) . '</td><td>' . esc_html( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<div class="grid">';
			if ( $art_id ) {
				$url = SC_Print_Ready::instance()->proxy_url( $art_id );
				if ( $url ) {
					echo '<div><div>' . esc_html__( 'Original artwork', 'storecanvas' ) . '</div><img src="' . esc_url( $url ) . '" alt="" /></div>';
				}
			}
			if ( is_array( $files ) ) {
				foreach ( $files as $vid => $fid ) {
					$url = SC_Print_Ready::instance()->proxy_url( (int) $fid );
					if ( $url ) {
						echo '<div><div>' . esc_html( sprintf( __( 'Composite: %s', 'storecanvas' ), $vid ) ) . '</div><img src="' . esc_url( $url ) . '" alt="" /></div>';
					}
				}
			}
			echo '</div>';
			if ( $place || $layers ) {
				echo '<details style="margin-top:8px;"><summary>' . esc_html__( 'Placement JSON', 'storecanvas' ) . '</summary><pre style="font-size:11px;overflow:auto;">';
				echo esc_html( wp_json_encode( array( 'placement' => $place, 'layers' => $layers ), JSON_PRETTY_PRINT ) );
				echo '</pre></details>';
			}
			echo '</div>';
		}
		echo '</body></html>';
		exit;
	}
}
