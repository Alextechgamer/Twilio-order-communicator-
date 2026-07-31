<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom WooCommerce order statuses + mapping helpers.
 */
class TOC_Statuses {

	const READY_FOR_PICKUP = 'wc-ready-for-pickup';
	const SHIPPED          = 'wc-shipped';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_statuses' ), 5 );
		add_filter( 'wc_order_statuses', array( $this, 'add_to_order_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		// Fulfillment statuses after payment — keep WC_Order::is_paid() true.
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'paid_statuses' ) );
	}

	/**
	 * Treat Ready for Pickup / Shipped as paid.
	 *
	 * These are post-payment fulfillment states (pickup desk / carrier). Orders
	 * typically arrive here from Processing/Completed; leaving them unpaid would
	 * make is_paid() flip false mid-fulfillment and break stock/reporting helpers.
	 *
	 * @param string[] $statuses Bare status slugs (no wc- prefix).
	 * @return string[]
	 */
	public function paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		$statuses[] = 'ready-for-pickup';
		$statuses[] = 'shipped';
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Register post statuses if missing (does not migrate existing orders).
	 */
	public function register_post_statuses() {
		$statuses = array(
			self::READY_FOR_PICKUP => array(
				'label'       => _x( 'Ready for Pickup', 'Order status', 'twilio-order-communicator' ),
				/* translators: %s: number of orders */
				'label_count' => _n_noop(
					'Ready for Pickup <span class="count">(%s)</span>',
					'Ready for Pickup <span class="count">(%s)</span>',
					'twilio-order-communicator'
				),
			),
			self::SHIPPED          => array(
				'label'       => _x( 'Shipped', 'Order status', 'twilio-order-communicator' ),
				/* translators: %s: number of orders */
				'label_count' => _n_noop(
					'Shipped <span class="count">(%s)</span>',
					'Shipped <span class="count">(%s)</span>',
					'twilio-order-communicator'
				),
			),
		);

		foreach ( $statuses as $slug => $args ) {
			if ( get_post_status_object( $slug ) ) {
				continue;
			}
			register_post_status(
				$slug,
				array(
					'label'                     => $args['label'],
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $args['label_count'],
				)
			);
		}
	}

	/**
	 * @param array $order_statuses Existing WC statuses (keys include wc- prefix).
	 * @return array
	 */
	public function add_to_order_statuses( $order_statuses ) {
		$new = array();
		foreach ( $order_statuses as $key => $label ) {
			$new[ $key ] = $label;
			// Insert Ready for Pickup after Processing when present.
			if ( $key === 'wc-processing' ) {
				$new[ self::READY_FOR_PICKUP ] = _x( 'Ready for Pickup', 'Order status', 'twilio-order-communicator' );
				$new[ self::SHIPPED ]          = _x( 'Shipped', 'Order status', 'twilio-order-communicator' );
			}
		}
		if ( ! isset( $new[ self::READY_FOR_PICKUP ] ) ) {
			$new[ self::READY_FOR_PICKUP ] = _x( 'Ready for Pickup', 'Order status', 'twilio-order-communicator' );
		}
		if ( ! isset( $new[ self::SHIPPED ] ) ) {
			$new[ self::SHIPPED ] = _x( 'Shipped', 'Order status', 'twilio-order-communicator' );
		}
		return $new;
	}

	/**
	 * Add bulk "mark as" actions for our custom statuses.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		$actions['mark_ready-for-pickup'] = __( 'Change status to Ready for Pickup', 'twilio-order-communicator' );
		$actions['mark_shipped']          = __( 'Change status to Shipped', 'twilio-order-communicator' );
		return $actions;
	}

	/**
	 * Mapped Ready for Pickup status option (wc-prefixed).
	 *
	 * @return string
	 */
	public static function mapped_ready_status() {
		$status = (string) get_option( 'toc_status_ready_for_pickup', self::READY_FOR_PICKUP );
		return self::normalize_wc_status( $status, self::READY_FOR_PICKUP );
	}

	/**
	 * Mapped Shipped status option (wc-prefixed).
	 *
	 * @return string
	 */
	public static function mapped_shipped_status() {
		$status = (string) get_option( 'toc_status_shipped', self::SHIPPED );
		return self::normalize_wc_status( $status, self::SHIPPED );
	}

	/**
	 * Status slug without wc- prefix (matches WC_Order::get_status()).
	 *
	 * @param string $wc_status Status with or without wc- prefix.
	 * @return string
	 */
	public static function bare_status( $wc_status ) {
		$wc_status = (string) $wc_status;
		if ( strpos( $wc_status, 'wc-' ) === 0 ) {
			return substr( $wc_status, 3 );
		}
		return $wc_status;
	}

	/**
	 * Ensure a status key is a known wc-prefixed order status.
	 *
	 * @param string $status  Candidate status.
	 * @param string $fallback Fallback wc- status.
	 * @return string
	 */
	public static function normalize_wc_status( $status, $fallback ) {
		$status = sanitize_key( $status );
		if ( $status === '' ) {
			return $fallback;
		}
		if ( strpos( $status, 'wc-' ) !== 0 ) {
			$status = 'wc-' . $status;
		}
		$registered = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( ! empty( $registered ) && ! isset( $registered[ $status ] ) ) {
			return $fallback;
		}
		return $status;
	}

	/**
	 * All registered order statuses for settings dropdowns.
	 *
	 * @return array slug => label
	 */
	public static function all_order_statuses() {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}
		return array(
			self::READY_FOR_PICKUP => _x( 'Ready for Pickup', 'Order status', 'twilio-order-communicator' ),
			self::SHIPPED          => _x( 'Shipped', 'Order status', 'twilio-order-communicator' ),
		);
	}
}
