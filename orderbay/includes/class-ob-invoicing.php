<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequential invoice / credit note numbers + customer My Account invoice.
 */
class OB_Invoicing {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ob_print_credit_note', array( $this, 'handle_print_credit' ) );
		add_action( 'admin_post_ob_issue_credit_note', array( $this, 'handle_issue_credit' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_screen_invoice_meta' ), 15 );
		add_action( 'woocommerce_order_actions', array( $this, 'order_actions' ) );
		add_action( 'woocommerce_order_action_ob_issue_credit_note', array( $this, 'action_issue_credit' ) );
		add_action( 'woocommerce_order_action_ob_print_credit_note', array( $this, 'action_print_credit' ) );

		// Customer My Account — logged-in only.
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'account_orders_action' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_view_order_link' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_customer_invoice' ) );
	}

	public function register_settings() {
		register_setting( 'ob_documents', OB_Plugin::OPT_INVOICE_PREFIX, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_prefix' ),
			'default'           => 'INV-',
		) );
		register_setting( 'ob_documents', OB_Plugin::OPT_INVOICE_NEXT, array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_next' ),
			'default'           => 1,
		) );
		register_setting( 'ob_documents', OB_Plugin::OPT_CREDIT_PREFIX, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_credit_prefix' ),
			'default'           => 'CN-',
		) );
		register_setting( 'ob_documents', OB_Plugin::OPT_CREDIT_NEXT, array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_next' ),
			'default'           => 1,
		) );
	}

	public function sanitize_prefix( $v ) {
		$v = is_string( $v ) ? sanitize_text_field( $v ) : 'INV-';
		return $v;
	}

	public function sanitize_credit_prefix( $v ) {
		$v = is_string( $v ) ? sanitize_text_field( $v ) : 'CN-';
		return $v ? $v : 'CN-';
	}

	public function sanitize_next( $v ) {
		$n = absint( $v );
		return $n > 0 ? $n : 1;
	}

	/**
	 * Assign immutable invoice number once.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_invoice_number( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = $order->get_meta( OB_Plugin::META_INVOICE_NUMBER );
		if ( $existing ) {
			return (string) $existing;
		}
		$number = self::allocate_number( OB_Plugin::OPT_INVOICE_PREFIX, OB_Plugin::OPT_INVOICE_NEXT, 'INV-' );
		$order->update_meta_data( OB_Plugin::META_INVOICE_NUMBER, $number );
		$order->add_order_note( sprintf( __( 'Orderbay invoice number assigned: %s', 'orderbay' ), $number ), false, true );
		$order->save();
		return $number;
	}

	/**
	 * Issue credit note number (optional even without refunds if staff forces).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function ensure_credit_number( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$existing = $order->get_meta( OB_Plugin::META_CREDIT_NUMBER );
		if ( $existing ) {
			return (string) $existing;
		}
		$number = self::allocate_number( OB_Plugin::OPT_CREDIT_PREFIX, OB_Plugin::OPT_CREDIT_NEXT, 'CN-' );
		$order->update_meta_data( OB_Plugin::META_CREDIT_NUMBER, $number );
		$order->add_order_note( sprintf( __( 'Orderbay credit note number issued: %s', 'orderbay' ), $number ), false, true );
		$order->save();
		return $number;
	}

	/**
	 * Atomic-ish counter allocate: lock via option update loop.
	 *
	 * @param string $opt_prefix Option key for prefix.
	 * @param string $opt_next Option key for next int.
	 * @param string $default_prefix Fallback prefix.
	 * @return string
	 */
	private static function allocate_number( $opt_prefix, $opt_next, $default_prefix ) {
		$prefix = get_option( $opt_prefix, $default_prefix );
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$prefix = $default_prefix;
		}
		// Simple atomic increment with retries.
		$attempts = 0;
		$seq      = 1;
		while ( $attempts < 8 ) {
			$attempts++;
			$current = (int) get_option( $opt_next, 1 );
			if ( $current < 1 ) {
				$current = 1;
			}
			$seq = $current;
			// Prefer autoload false for counters.
			if ( false === get_option( $opt_next, false ) && 1 === $current ) {
				add_option( $opt_next, $current + 1, '', 'no' );
				break;
			}
			$updated = update_option( $opt_next, $current + 1, false );
			// Re-read to detect races; if value moved past our write, retry.
			$after = (int) get_option( $opt_next, $current + 1 );
			if ( $after === $current + 1 || $updated ) {
				break;
			}
		}
		return $prefix . $seq;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function order_screen_invoice_meta( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$inv = $order->get_meta( OB_Plugin::META_INVOICE_NUMBER );
		$cn  = $order->get_meta( OB_Plugin::META_CREDIT_NUMBER );
		echo '<div class="ob-invoice-meta" style="clear:both;padding:8px 0;">';
		echo '<p><strong>' . esc_html__( 'Invoice #', 'orderbay' ) . ':</strong> ';
		echo $inv ? esc_html( $inv ) : '<em>' . esc_html__( 'Not assigned yet (assigned on first print)', 'orderbay' ) . '</em>';
		echo '</p>';
		if ( $cn ) {
			echo '<p><strong>' . esc_html__( 'Credit note #', 'orderbay' ) . ':</strong> ' . esc_html( $cn ) . '</p>';
		}
		$has_refunds = $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0;
		if ( $has_refunds || $cn ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=ob_print_credit_note&order_id=' . $order->get_id() ),
				'ob_print_credit_note_' . $order->get_id()
			);
			echo '<p><a class="button" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'Print credit note', 'orderbay' ) . '</a></p>';
		}
		echo '</div>';
	}

	public function order_actions( $actions ) {
		$actions['ob_issue_credit_note'] = __( 'Orderbay: issue credit note number', 'orderbay' );
		$actions['ob_print_credit_note'] = __( 'Orderbay: print credit note', 'orderbay' );
		return $actions;
	}

	public function action_issue_credit( $order ) {
		self::ensure_credit_number( $order );
	}

	public function action_print_credit( $order ) {
		// Number may be issued on print if refunds exist.
		if ( $order->get_total_refunded() > 0 || $order->get_meta( OB_Plugin::META_CREDIT_NUMBER ) ) {
			self::ensure_credit_number( $order );
		}
	}

	public function handle_issue_credit() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_issue_credit_note_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		self::ensure_credit_number( $order );
		wp_safe_redirect( get_edit_post_link( $order_id, 'raw' ) ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	public function handle_print_credit() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'ob_print_credit_note_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'orderbay' ) );
		}
		$has_refunds = $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0;
		$has_number  = (bool) $order->get_meta( OB_Plugin::META_CREDIT_NUMBER );
		if ( ! $has_refunds && ! $has_number ) {
			wp_die( esc_html__( 'No refunds and no credit note number on this order.', 'orderbay' ) );
		}
		if ( $has_refunds || $has_number ) {
			self::ensure_credit_number( $order );
		}
		$settings = OB_Plugin::get_doc_settings();
		$orders   = array( $order );
		include OB_PLUGIN_DIR . 'templates/credit-note.php';
		exit;
	}

	/* ─── Customer My Account ─────────────────────────────────────── */

	public function add_endpoints() {
		// Query-arg based; no rewrite required for 0.4.
	}

	/**
	 * Whether current user may view invoice for order (owner only).
	 *
	 * @param WC_Order $order Order.
	 * @param int|null $user_id User ID.
	 * @return bool
	 */
	public static function customer_can_view_invoice( $order, $user_id = null ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return false;
		}
		// Staff can always view via admin routes; this is customer path only.
		if ( user_can( $user_id, 'edit_shop_orders' ) ) {
			return true;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id && $customer_id === (int) $user_id ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( $user && $user->user_email && strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ) {
			// Only match billing email if order has no customer_id (guest later linked) or matches.
			if ( ! $customer_id || $customer_id === (int) $user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array    $actions Actions.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function account_orders_action( $actions, $order ) {
		if ( ! $order || ! is_user_logged_in() ) {
			return $actions;
		}
		if ( ! self::customer_can_view_invoice( $order ) ) {
			return $actions;
		}
		$actions['ob_invoice'] = array(
			'url'  => self::customer_invoice_url( $order->get_id() ),
			'name' => __( 'Invoice', 'orderbay' ),
		);
		return $actions;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function account_view_order_link( $order ) {
		if ( ! is_user_logged_in() || ! $order || ! self::customer_can_view_invoice( $order ) ) {
			return;
		}
		echo '<p class="ob-account-invoice"><a class="button" target="_blank" href="' . esc_url( self::customer_invoice_url( $order->get_id() ) ) . '">' . esc_html__( 'Download / print invoice', 'orderbay' ) . '</a></p>';
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function customer_invoice_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'ob_customer_invoice' => 1,
					'order_id'            => absint( $order_id ),
				),
				wc_get_page_permalink( 'myaccount' ) ?: home_url( '/' )
			),
			'ob_customer_invoice_' . absint( $order_id )
		);
	}

	public function handle_customer_invoice() {
		if ( empty( $_GET['ob_customer_invoice'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		if ( ! $order_id || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'ob_customer_invoice_' . $order_id ) ) { // phpcs:ignore
			wp_die( esc_html__( 'Invalid invoice request.', 'orderbay' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::customer_can_view_invoice( $order ) ) {
			wp_die( esc_html__( 'You cannot view this invoice.', 'orderbay' ), 403 );
		}
		self::ensure_invoice_number( $order );
		$settings = OB_Plugin::get_doc_settings();
		$orders   = array( $order );
		include OB_PLUGIN_DIR . 'templates/invoice.php';
		exit;
	}
}
