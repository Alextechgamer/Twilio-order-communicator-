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
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'customer_rma_form' ), 25 );
		add_action( 'template_redirect', array( $this, 'handle_customer_request' ) );
	}

	public static function default_settings() {
		return array(
			'return_address'         => get_bloginfo( 'name' ) . "\n" . get_option( 'woocommerce_store_address', '' ),
			'attention_on_request'   => '0',
			'customer_request'       => '0', // default off
			'customer_statuses'      => array( 'completed', 'processing' ),
			'notify_customer'        => '0', // email the customer on RMA status changes (default off)
		);
	}

	/**
	 * RMA statuses that trigger a customer notification email (filterable).
	 *
	 * @return string[]
	 */
	public static function notify_states() {
		$states = array( 'approved', 'received', 'closed' );
		/**
		 * Filter which RMA statuses email the customer.
		 *
		 * @param string[] $states RMA status slugs.
		 */
		return (array) apply_filters( 'ob_rma_notify_states', $states );
	}

	/**
	 * Clamp/validate a per-line RMA quantity map against each item's ordered quantity (pure).
	 *
	 * @param mixed $raw   Map of order_item_id => requested qty.
	 * @param mixed $maxes Map of order_item_id => ordered qty (the allowed maximum).
	 * @return array<int,int> Sanitized order_item_id => qty (1..max), zero/invalid dropped.
	 */
	public static function sanitize_rma_items( $raw, $maxes ) {
		$out = array();
		if ( ! is_array( $raw ) || ! is_array( $maxes ) ) {
			return $out;
		}
		foreach ( $raw as $item_id => $qty ) {
			$item_id = (int) $item_id;
			$qty     = (int) $qty;
			if ( $item_id <= 0 || $qty <= 0 || ! isset( $maxes[ $item_id ] ) ) {
				continue;
			}
			$max = (int) $maxes[ $item_id ];
			if ( $max < 1 ) {
				continue;
			}
			$out[ $item_id ] = min( $qty, $max );
		}
		return $out;
	}

	/**
	 * Whether an RMA status transition should email the customer (pure): a real change into a
	 * notify state.
	 *
	 * @param string $prev          Previous status.
	 * @param string $next          New status.
	 * @param array  $notify_states Statuses that trigger an email.
	 * @return bool
	 */
	public static function should_email( $prev, $next, $notify_states ) {
		$next = (string) $next;
		if ( (string) $prev === $next || ! is_array( $notify_states ) ) {
			return false;
		}
		return in_array( $next, $notify_states, true );
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
		$out['customer_request']     = ! empty( $input['customer_request'] ) ? '1' : '0';
		$out['notify_customer']      = ! empty( $input['notify_customer'] ) ? '1' : '0';
		$statuses = array();
		if ( ! empty( $input['customer_statuses'] ) && is_array( $input['customer_statuses'] ) ) {
			foreach ( $input['customer_statuses'] as $st ) {
				$st = str_replace( 'wc-', '', sanitize_key( $st ) );
				if ( $st ) {
					$statuses[] = $st;
				}
			}
		}
		$out['customer_statuses'] = $statuses ? array_values( array_unique( $statuses ) ) : array( 'completed', 'processing' );
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
		echo '<tr><th>' . esc_html__( 'Customer RMA requests', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[customer_request]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[customer_request]" value="1" ' . checked( $s['customer_request'], '1', false ) . ' /> ';
		echo esc_html__( 'Allow logged-in customers to request RMA on My Account (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Customer emails', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[notify_customer]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[notify_customer]" value="1" ' . checked( $s['notify_customer'] ?? '0', '1', false ) . ' /> ';
		echo esc_html__( 'Email the customer when the RMA status becomes Approved, Received or Closed (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Eligible order statuses', 'orderbay' ) . '</th><td><fieldset>';
		$cs = is_array( $s['customer_statuses'] ?? null ) ? $s['customer_statuses'] : array( 'completed', 'processing' );
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$key = str_replace( 'wc-', '', $slug );
			echo '<label style="display:inline-block;min-width:160px;margin:2px 8px 2px 0;"><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_RMA ) . '[customer_statuses][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $cs, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr>';
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
		// Per-line item selection (optional): how many of each item are being returned.
		$rma_items = $order->get_meta( OB_Plugin::META_RMA_ITEMS );
		$rma_items = is_array( $rma_items ) ? $rma_items : array();
		$items     = $order->get_items();
		if ( $items ) {
			echo '<p><strong>' . esc_html__( 'Items to return', 'orderbay' ) . '</strong></p>';
			foreach ( $items as $item_id => $item ) {
				$ordered = (int) $item->get_quantity();
				if ( $ordered < 1 ) {
					continue;
				}
				$val = isset( $rma_items[ $item_id ] ) ? (int) $rma_items[ $item_id ] : 0;
				echo '<p style="margin:2px 0;"><label>';
				echo '<input type="number" min="0" max="' . esc_attr( (string) $ordered ) . '" name="ob_rma_items[' . esc_attr( (string) $item_id ) . ']" value="' . esc_attr( (string) $val ) . '" style="width:64px;" /> ';
				/* translators: 1: item name, 2: ordered quantity. */
				echo esc_html( sprintf( __( '%1$s (of %2$d)', 'orderbay' ), $item->get_name(), $ordered ) );
				echo '</label></p>';
			}
		}
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

		// Per-line item selection, clamped to each item's ordered quantity.
		if ( isset( $_POST['ob_rma_items'] ) && is_array( $_POST['ob_rma_items'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$maxes = array();
			foreach ( $order->get_items() as $item_id => $item ) {
				$maxes[ (int) $item_id ] = (int) $item->get_quantity();
			}
			$rma_items = self::sanitize_rma_items( wp_unslash( $_POST['ob_rma_items'] ), $maxes ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $rma_items ) {
				$order->update_meta_data( OB_Plugin::META_RMA_ITEMS, $rma_items );
			} else {
				$order->delete_meta_data( OB_Plugin::META_RMA_ITEMS );
			}
		}
		$order->save();

		$cfg = self::get_settings();

		if ( 'requested' === $status && 'requested' !== $prev ) {
			if ( '1' === (string) $cfg['attention_on_request'] && ! $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
				$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
				$order->add_order_note( __( 'Orderbay: needs attention (RMA requested).', 'orderbay' ), false, true );
				$order->save();
			}
		}

		// Customer status email — once per real transition into a notify state.
		if ( '1' === (string) ( $cfg['notify_customer'] ?? '0' )
			&& self::should_email( $prev, $status, self::notify_states() )
			&& (string) $order->get_meta( OB_Plugin::META_RMA_EMAILED ) !== $status ) {
			if ( $this->send_customer_email( $order, $status ) ) {
				$order->update_meta_data( OB_Plugin::META_RMA_EMAILED, $status );
				$order->save();
			}
		}
	}

	/**
	 * Email the customer a plain-text RMA status update. Returns true if wp_mail was attempted.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status New RMA status.
	 * @return bool
	 */
	private function send_customer_email( $order, $status ) {
		$to = $order->get_billing_email();
		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}
		$store  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$number = (string) $order->get_meta( OB_Plugin::META_RMA_NUMBER );
		$labels = array(
			'approved' => __( 'approved', 'orderbay' ),
			'received' => __( 'received', 'orderbay' ),
			'closed'   => __( 'closed', 'orderbay' ),
		);
		$label = $labels[ $status ] ?? $status;
		/* translators: 1: store name, 2: order number. */
		$subject = sprintf( __( '%1$s — return update for order #%2$s', 'orderbay' ), $store, $order->get_order_number() );
		$lines   = array();
		/* translators: 1: customer first name. */
		$lines[] = sprintf( __( 'Hello %s,', 'orderbay' ), $order->get_billing_first_name() );
		$lines[] = '';
		if ( $number ) {
			/* translators: 1: RMA number, 2: status label. */
			$lines[] = sprintf( __( 'Your return %1$s is now %2$s.', 'orderbay' ), $number, $label );
		} else {
			/* translators: 1: status label. */
			$lines[] = sprintf( __( 'Your return request is now %s.', 'orderbay' ), $label );
		}
		$lines[] = '';
		$lines[] = $store;
		$body    = implode( "\n", $lines );

		return (bool) wp_mail( $to, $subject, $body );
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
		// Atomic, race-free allocation shared with invoice/credit/proforma numbering.
		$number = OB_Invoicing::allocate_number( OB_Plugin::OPT_RMA_PREFIX, OB_Plugin::OPT_RMA_NEXT, 'RMA-' );
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
		// HPOS-safe edit URL (post.php?post= is invalid for HPOS-stored orders).
		wp_safe_redirect( $order->get_edit_order_url() );
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
		include OB_Documents::locate_template( 'rma-slip.php' );
		exit;
	}

	/**
	 * My Account view-order: request RMA (logged-in owner only).
	 *
	 * @param WC_Order $order Order.
	 */
	public function customer_rma_form( $order ) {
		if ( ! is_user_logged_in() || ! $order instanceof WC_Order ) {
			return;
		}
		$cfg = self::get_settings();
		if ( '1' !== (string) $cfg['customer_request'] ) {
			return;
		}
		if ( ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			// Reuse ownership check (same rules as invoice).
			return;
		}

		$status = $order->get_meta( OB_Plugin::META_RMA_STATUS );
		$number = $order->get_meta( OB_Plugin::META_RMA_NUMBER );
		$reason = $order->get_meta( OB_Plugin::META_RMA_REASON );

		echo '<section class="ob-customer-rma" style="margin-top:24px;">';
		echo '<h2>' . esc_html__( 'Return / RMA', 'orderbay' ) . '</h2>';

		if ( $status && 'none' !== $status ) {
			echo '<p><strong>' . esc_html__( 'Status', 'orderbay' ) . ':</strong> ' . esc_html( $status ) . '</p>';
			if ( $number ) {
				echo '<p><strong>' . esc_html__( 'RMA #', 'orderbay' ) . ':</strong> ' . esc_html( $number ) . '</p>';
			}
			if ( $reason ) {
				echo '<p><strong>' . esc_html__( 'Reason', 'orderbay' ) . ':</strong> ' . esc_html( $reason ) . '</p>';
			}
			echo '<p class="description">' . esc_html__( 'Your return request is being reviewed. You cannot change the status here.', 'orderbay' ) . '</p>';
			echo '</section>';
			return;
		}

		$eligible = is_array( $cfg['customer_statuses'] ?? null ) ? $cfg['customer_statuses'] : array( 'completed', 'processing' );
		if ( ! in_array( $order->get_status(), $eligible, true ) ) {
			echo '<p class="description">' . esc_html__( 'Returns are not available for this order status.', 'orderbay' ) . '</p>';
			echo '</section>';
			return;
		}

		$action = add_query_arg(
			array(
				'ob_customer_rma' => 1,
				'order_id'        => $order->get_id(),
			),
			wc_get_page_permalink( 'myaccount' ) ?: home_url( '/' )
		);
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( 'ob_customer_rma_' . $order->get_id() );
		echo '<p><label>' . esc_html__( 'Reason for return', 'orderbay' ) . '<br />';
		echo '<textarea name="ob_rma_reason" rows="4" style="width:100%;max-width:480px;" required></textarea></label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Request return', 'orderbay' ) . '</button></p>';
		echo '</form></section>';
	}

	/**
	 * Process customer RMA request.
	 */
	public function handle_customer_request() {
		if ( empty( $_GET['ob_customer_rma'] ) && empty( $_POST['ob_customer_rma'] ) && empty( $_REQUEST['ob_customer_rma'] ) ) { // phpcs:ignore
			// Form posts to URL with query arg; check REQUEST.
			if ( empty( $_REQUEST['ob_customer_rma'] ) ) { // phpcs:ignore
				return;
			}
		}
		if ( empty( $_REQUEST['ob_customer_rma'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		$cfg = self::get_settings();
		if ( '1' !== (string) $cfg['customer_request'] ) {
			wp_die( esc_html__( 'Customer RMA requests are disabled.', 'orderbay' ), 403 );
		}
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0; // phpcs:ignore
		if ( ! $order_id || ! wp_verify_nonce( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '', 'ob_customer_rma_' . $order_id ) ) { // phpcs:ignore
			wp_die( esc_html__( 'Invalid RMA request.', 'orderbay' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			wp_die( esc_html__( 'You cannot request a return for this order.', 'orderbay' ), 403 );
		}
		// Already requested?
		$existing = $order->get_meta( OB_Plugin::META_RMA_STATUS );
		if ( $existing && 'none' !== $existing ) {
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}
		$eligible = is_array( $cfg['customer_statuses'] ?? null ) ? $cfg['customer_statuses'] : array( 'completed', 'processing' );
		if ( ! in_array( $order->get_status(), $eligible, true ) ) {
			wp_die( esc_html__( 'Returns are not available for this order status.', 'orderbay' ), 403 );
		}
		$reason = isset( $_POST['ob_rma_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ob_rma_reason'] ) ) : ''; // phpcs:ignore
		if ( ! $reason ) {
			wp_die( esc_html__( 'Please provide a reason.', 'orderbay' ), 400 );
		}
		$order->update_meta_data( OB_Plugin::META_RMA_STATUS, 'requested' );
		$order->update_meta_data( OB_Plugin::META_RMA_REASON, $reason );
		$order->save();
		self::ensure_rma_number( $order );
		if ( '1' === (string) $cfg['attention_on_request'] && ! $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
			$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
			$order->add_order_note( __( 'Orderbay: needs attention (customer RMA request).', 'orderbay' ), false, true );
			$order->save();
		} else {
			$order->add_order_note( __( 'Orderbay: customer submitted RMA request.', 'orderbay' ), false, true );
			$order->save();
		}
		wp_safe_redirect( add_query_arg( 'ob_rma_submitted', '1', $order->get_view_order_url() ) );
		exit;
	}
}
