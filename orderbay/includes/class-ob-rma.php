<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Light RMA / returns — meta + slip only (no refund automation).
 */
class OB_RMA {

	private static $instance = null;

	const STATUSES = array( 'none', 'requested', 'approved', 'received', 'closed' );

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_legacy' ), 50, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'save_hpos' ), 30 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_panel' ), 35 );
		add_action( 'admin_post_ob_print_rma', array( $this, 'handle_print' ) );
		add_action( 'admin_post_ob_issue_rma', array( $this, 'handle_issue' ) );
	}

	public static function default_settings() {
		return array(
			'return_address'       => get_bloginfo( 'name' ) . "\n" . get_option( 'woocommerce_store_address', '' ),
			'attention_on_request' => '0',
		);
	}

	public static function get_settings() {
		$raw = get_option( OB_Plugin::OPT_RMA, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::default_settings() );
	}

	public function register_settings() {
		register_setting(
			'ob_rma',
			OB_Plugin::OPT_RMA,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
		register_setting(
			'ob_rma',
			OB_Plugin::OPT_RMA_PREFIX,
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					$v = is_string( $v ) ? sanitize_text_field( $v ) : 'RMA-';
					return $v ? $v : 'RMA-';
				},
				'default'           => 'RMA-',
			)
		);
		register_setting(
			'ob_rma',
			OB_Plugin::OPT_RMA_NEXT,
			array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					$n = absint( $v );
					return $n > 0 ? $n : 1;
				},
				'default'           => 1,
			)
		);
	}

	public function sanitize_settings( $input ) {
		$out = self::default_settings();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['return_address']       = isset( $input['return_address'] ) ? sanitize_textarea_field( $input['return_address'] ) : '';
		$out['attention_on_request'] = ! empty( $input['attention_on_request'] ) ? '1' : '0';
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$s = self::get_settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay returns / RMA', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Self-hosted RMA meta and print slips only — no payment or refund automation. Use credit notes for refunds. Independent of Twilio Order Communicator.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_rma' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Return address', 'orderbay' ) . '</th><td>';
		echo '<textarea class="large-text" rows="4" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[return_address]">' . esc_textarea( $s['return_address'] ) . '</textarea></td></tr>';
		echo '<tr><th>' . esc_html__( 'RMA prefix', 'orderbay' ) . '</th><td>';
		echo '<input type="text" name="' . esc_attr( OB_Plugin::OPT_RMA_PREFIX ) . '" value="' . esc_attr( get_option( OB_Plugin::OPT_RMA_PREFIX, 'RMA-' ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Next RMA sequence', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="1" name="' . esc_attr( OB_Plugin::OPT_RMA_NEXT ) . '" value="' . esc_attr( (string) max( 1, (int) get_option( OB_Plugin::OPT_RMA_NEXT, 1 ) ) ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Auto attention', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[attention_on_request]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[attention_on_request]" value="1" ' . checked( $s['attention_on_request'], '1', false ) . ' /> ';
		echo esc_html__( 'Set needs-attention when RMA status becomes requested', 'orderbay' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Save RMA settings', 'orderbay' ) );
		echo '</form></div>';
	}

	public function meta_boxes() {
		$screen = 'shop_order';
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			try {
				$screen = wc_get_page_screen_id( 'shop-order' );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$screen = 'shop_order';
			}
		}
		add_meta_box( 'ob_rma', __( 'Orderbay RMA', 'orderbay' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( $order ) {
			$this->render_fields( $order );
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function order_panel( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		echo '<div class="ob-rma-panel" style="clear:both;padding:8px 0;border-top:1px solid #eee;">';
		echo '<h3>' . esc_html__( 'Orderbay RMA', 'orderbay' ) . '</h3>';
		$this->render_fields( $order );
		echo '</div>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function render_fields( $order ) {
		wp_nonce_field( 'ob_rma_save', 'ob_rma_nonce' );
		$status = $order->get_meta( OB_Plugin::META_RMA_STATUS );
		if ( ! $status ) {
			$status = 'none';
		}
		$number = $order->get_meta( OB_Plugin::META_RMA_NUMBER );
		$reason = $order->get_meta( OB_Plugin::META_RMA_REASON );
		echo '<p><label>' . esc_html__( 'RMA status', 'orderbay' ) . '<br />';
		echo '<select name="ob_rma_status" style="width:100%;">';
		$labels = array(
			'none'      => __( 'None', 'orderbay' ),
			'requested' => __( 'Requested', 'orderbay' ),
			'approved'  => __( 'Approved', 'orderbay' ),
			'received'  => __( 'Received', 'orderbay' ),
			'closed'    => __( 'Closed', 'orderbay' ),
		);
		foreach ( $labels as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status, $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Reason', 'orderbay' ) . '<br />';
		echo '<textarea name="ob_rma_reason" rows="3" style="width:100%;">' . esc_textarea( (string) $reason ) . '</textarea></label></p>';
		echo '<p><strong>' . esc_html__( 'RMA #', 'orderbay' ) . ':</strong> ';
		echo $number ? esc_html( $number ) : '<em>' . esc_html__( 'Not issued', 'orderbay' ) . '</em>';
		echo '</p>';
		$issue = wp_nonce_url( admin_url( 'admin-post.php?action=ob_issue_rma&order_id=' . $order->get_id() ), 'ob_issue_rma_' . $order->get_id() );
		$print = wp_nonce_url( admin_url( 'admin-post.php?action=ob_print_rma&order_id=' . $order->get_id() ), 'ob_print_rma_' . $order->get_id() );
		echo '<p><a class="button" href="' . esc_url( $issue ) . '">' . esc_html__( 'Issue RMA number', 'orderbay' ) . '</a> ';
		echo '<a class="button" target="_blank" href="' . esc_url( $print ) . '">' . esc_html__( 'Print RMA slip', 'orderbay' ) . '</a></p>';
	}

	public function save_legacy( $order_id, $post = null ) {
		if ( ! $this->verify_save() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_posted( $order );
		}
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public function save_hpos( $order_id ) {
		if ( ! is_admin() || ! $this->verify_save() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_posted( $order );
		}
	}

	private function verify_save() {
		if ( ! isset( $_POST['ob_rma_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_rma_nonce'] ) ), 'ob_rma_save' ) ) {
			return false;
		}
		return current_user_can( 'edit_shop_orders' );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function apply_posted( $order ) {
		$prev   = $order->get_meta( OB_Plugin::META_RMA_STATUS );
		$status = isset( $_POST['ob_rma_status'] ) ? sanitize_key( wp_unslash( $_POST['ob_rma_status'] ) ) : 'none'; // phpcs:ignore
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'none';
		}
		$reason = isset( $_POST['ob_rma_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ob_rma_reason'] ) ) : ''; // phpcs:ignore

		if ( 'none' === $status ) {
			$order->delete_meta_data( OB_Plugin::META_RMA_STATUS );
		} else {
			$order->update_meta_data( OB_Plugin::META_RMA_STATUS, $status );
		}
		if ( $reason ) {
			$order->update_meta_data( OB_Plugin::META_RMA_REASON, $reason );
		} else {
			$order->delete_meta_data( OB_Plugin::META_RMA_REASON );
		}
		$order->save();

		if ( 'requested' === $status && 'requested' !== $prev ) {
			$cfg = self::get_settings();
			if ( '1' === (string) $cfg['attention_on_request'] && ! $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
				$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
				$order->add_order_note( __( 'Orderbay: needs attention (RMA requested).', 'orderbay' ), false, true );
				$order->save();
			}
		}
	}

	/**
	 * Assign immutable RMA number once.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_rma_number( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = $order->get_meta( OB_Plugin::META_RMA_NUMBER );
		if ( $existing ) {
			return (string) $existing;
		}
		$prefix = get_option( OB_Plugin::OPT_RMA_PREFIX, 'RMA-' );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$prefix = 'RMA-';
		}
		$current = (int) get_option( OB_Plugin::OPT_RMA_NEXT, 1 );
		if ( $current < 1 ) {
			$current = 1;
		}
		update_option( OB_Plugin::OPT_RMA_NEXT, $current + 1, false );
		$number = $prefix . $current;
		$order->update_meta_data( OB_Plugin::META_RMA_NUMBER, $number );
		if ( ! $order->get_meta( OB_Plugin::META_RMA_STATUS ) || 'none' === $order->get_meta( OB_Plugin::META_RMA_STATUS ) ) {
			$order->update_meta_data( OB_Plugin::META_RMA_STATUS, 'requested' );
		}
		$order->add_order_note( sprintf( __( 'Orderbay RMA number issued: %s', 'orderbay' ), $number ), false, true );
		$order->save();
		return $number;
	}

	public function handle_issue() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_issue_rma_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		self::ensure_rma_number( $order );
		$edit = get_edit_post_link( $order_id, 'raw' );
		wp_safe_redirect( $edit ? $edit : admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	public function handle_print() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_print_rma_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		// Issue number if missing so slip is usable.
		self::ensure_rma_number( $order );
		$settings = OB_Plugin::get_doc_settings();
		$rma      = self::get_settings();
		$orders   = array( $order );
		include OB_PLUGIN_DIR . 'templates/rma-slip.php';
		exit;
	}
}
