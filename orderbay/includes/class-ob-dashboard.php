<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * E) Ops dashboard.
 */
class OB_Dashboard {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Menu registered by OB_Plugin.
		// Also under WooCommerce for discoverability.
		add_action( 'admin_menu', array( $this, 'wc_submenu' ), 60 );
	}

	public function wc_submenu() {
		add_submenu_page(
			'woocommerce',
			__( 'Orderbay', 'orderbay' ),
			__( 'Orderbay', 'orderbay' ),
			'edit_shop_orders',
			'orderbay-wc',
			array( __CLASS__, 'render_page_static' )
		);
	}

	public static function render_page_static() {
		self::instance()->render_page();
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}

		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$today_count = $this->count_orders(
			array(
				'date_created' => '>=' . $today_start,
			)
		);
		$processing = $this->count_orders( array( 'status' => array( 'processing', 'on-hold' ) ) );
		$attention  = $this->count_orders(
			array(
				'meta_key'   => OB_Plugin::META_ATTENTION,
				'meta_value' => '1',
			)
		);

		$sc_count = null;
		if ( class_exists( 'SC_Plugin' ) || defined( 'SC_VERSION' ) || class_exists( 'SC_Orders_List' ) ) {
			$sc_count = $this->count_orders(
				array(
					'meta_key'   => '_sc_has_custom_art',
					'meta_value' => '1',
				)
			);
		}

		$orders_url = admin_url( 'edit.php?post_type=shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			try {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
				if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
					$orders_url = admin_url( 'admin.php?page=wc-orders' );
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// keep legacy URL
			}
		}

		echo '<div class="wrap ob-dashboard">';
		echo '<h1>' . esc_html__( 'Orderbay dashboard', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Self-hosted ops overview. Documents, order flags, email rules, and catalog tools live under Orderbay.', 'orderbay' ) . '</p>';

		echo '<div class="ob-cards" style="display:flex;flex-wrap:wrap;gap:16px;margin:16px 0;">';
		$this->card( __( 'Orders today', 'orderbay' ), (string) $today_count, $orders_url );
		$this->card( __( 'Processing / on-hold', 'orderbay' ), (string) $processing, add_query_arg( 'status', 'processing', $orders_url ) );
		$this->card( __( 'Needs attention', 'orderbay' ), (string) $attention, add_query_arg( 'ob_attention', '1', $orders_url ) );
		if ( null !== $sc_count ) {
			$this->card( __( 'Custom art (StoreCanvas)', 'orderbay' ), (string) $sc_count, $orders_url );
		}
		echo '</div>';

		$docs = OB_Plugin::get_doc_settings();
		$rules = OB_Notifications::get_rules();
		$any_rule_on = false;
		foreach ( $rules as $r ) {
			if ( ! empty( $r['enabled'] ) && '1' === (string) $r['enabled'] ) {
				$any_rule_on = true;
				break;
			}
		}
		if ( empty( $docs['logo_url'] ) && ! $any_rule_on ) {
			echo '<div class="notice notice-info inline" style="margin:12px 0;"><p><strong>' . esc_html__( 'Getting started', 'orderbay' ) . '</strong> — ';
			echo esc_html__( 'Set a logo under Documents, add an email rule (optional, defaults off), and try Print invoice on an order. Flag orders with Needs attention for the queue card above.', 'orderbay' );
			echo '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Quick links', 'orderbay' ) . '</h2><ul>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=orderbay-documents' ) ) . '">' . esc_html__( 'Document settings (invoice / packing slip)', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=orderbay-fulfillment' ) ) . '">' . esc_html__( 'Fulfillment (tracking / auto-attention / pick list)', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=orderbay-notifications' ) ) . '">' . esc_html__( 'Email rules & low stock', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=orderbay-digest' ) ) . '">' . esc_html__( 'Staff digest', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=orderbay-export' ) ) . '">' . esc_html__( 'Export orders CSV', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( $orders_url ) . '">' . esc_html__( 'Orders list', 'orderbay' ) . '</a></li>';
		echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products (bulk price/stock/duplicate)', 'orderbay' ) . '</a></li>';
		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'Orderbay does not send SMS or voice — that is Twilio Order Communicator. StoreCanvas art count appears only when StoreCanvas is active.', 'orderbay' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param array $args wc_get_orders args.
	 * @return int
	 */
	private function count_orders( $args ) {
		$args = array_merge(
			array(
				'limit'  => 1,
				'return' => 'ids',
				'paginate' => true,
			),
			$args
		);
		$result = wc_get_orders( $args );
		if ( is_object( $result ) && isset( $result->total ) ) {
			return (int) $result->total;
		}
		// Fallback without paginate.
		unset( $args['paginate'] );
		$args['limit'] = -1;
		$args['return'] = 'ids';
		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * @param string $title Title.
	 * @param string $value Value.
	 * @param string $url URL.
	 */
	private function card( $title, $value, $url ) {
		echo '<a class="ob-card" href="' . esc_url( $url ) . '" style="display:block;min-width:160px;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;text-decoration:none;color:inherit;">';
		echo '<div style="font-size:28px;font-weight:600;line-height:1.2;">' . esc_html( $value ) . '</div>';
		echo '<div style="color:#50575e;margin-top:4px;">' . esc_html( $title ) . '</div>';
		echo '</a>';
	}
}
