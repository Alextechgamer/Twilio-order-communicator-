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
		// Menu registered by OB_Plugin (top-level OrderBay section).
	}

	public static function render_page_static() {
		self::instance()->render_page();
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}

		// Store-local midnight (as an unambiguous epoch), not UTC midnight.
		$today_start = OB_Plugin::day_start_ts( time(), wp_timezone_string() );
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

		$docs = OB_Plugin::get_doc_settings();
		$from_missing = empty( trim( (string) ( $docs['from_lines'] ?? '' ) ) );
		$logo_missing = empty( $docs['logo_url'] );

		$groups = array(
			array(
				'title' => __( 'Documents', 'orderbay' ),
				'items' => array(
					array( 'orderbay-documents', 'dashicons-media-document', __( 'Invoice & packing', 'orderbay' ), __( 'Logo, From address, numbering, paper.', 'orderbay' ) ),
					array( 'orderbay-export', 'dashicons-download', __( 'Export CSV', 'orderbay' ), __( 'Download orders for accounting.', 'orderbay' ) ),
				),
			),
			array(
				'title' => __( 'Fulfillment', 'orderbay' ),
				'items' => array(
					array( 'orderbay-fulfillment', 'dashicons-car', __( 'Tracking & pick list', 'orderbay' ), __( 'Carriers, auto-attention, bin locations.', 'orderbay' ) ),
					array( 'orderbay-rma', 'dashicons-image-rotate', __( 'Returns / RMA', 'orderbay' ), __( 'Line-level returns and customer emails.', 'orderbay' ) ),
					array( 'orderbay-sla', 'dashicons-clock', __( 'SLA aging', 'orderbay' ), __( 'Flag orders that sit too long.', 'orderbay' ) ),
				),
			),
			array(
				'title' => __( 'Team', 'orderbay' ),
				'items' => array(
					array( 'orderbay-notes', 'dashicons-edit', __( 'Note templates', 'orderbay' ), __( 'Reusable staff notes on orders.', 'orderbay' ) ),
					array( 'orderbay-notifications', 'dashicons-email', __( 'Email rules', 'orderbay' ), __( 'Status emails and low-stock alerts.', 'orderbay' ) ),
					array( 'orderbay-digest', 'dashicons-groups', __( 'Staff digest', 'orderbay' ), __( 'Daily attention summary for the team.', 'orderbay' ) ),
					array( 'ob-license', 'dashicons-unlock', __( 'License', 'orderbay' ), __( 'Activate premium updates for this site.', 'orderbay' ) ),
				),
			),
		);

		echo '<div class="wrap ob-dash">';
		echo '<div class="ob-dash-hero">';
		echo '<div>';
		echo '<p class="ob-dash-kicker">' . esc_html__( 'Operations', 'orderbay' ) . '</p>';
		echo '<h1>' . esc_html__( 'OrderBay', 'orderbay' ) . '</h1>';
		echo '<p class="ob-dash-lead">' . esc_html__( 'Documents, fulfillment, returns, and SLA — self-hosted, no per-order fee. SMS and voice live in OrderRing.', 'orderbay' ) . '</p>';
		echo '</div>';
		echo '<div class="ob-dash-hero-actions">';
		echo '<a class="button button-primary" href="' . esc_url( $orders_url ) . '">' . esc_html__( 'Open orders', 'orderbay' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products', 'orderbay' ) . '</a>';
		echo '</div></div>';

		echo '<div class="ob-dash-stats">';
		$this->stat_card( __( 'Orders today', 'orderbay' ), (string) $today_count, $orders_url, 'today', __( 'Created since midnight UTC', 'orderbay' ) );
		$this->stat_card( __( 'Processing', 'orderbay' ), (string) $processing, add_query_arg( 'status', 'wc-processing', $orders_url ), 'queue', __( 'Processing and on-hold', 'orderbay' ) );
		$this->stat_card( __( 'Needs attention', 'orderbay' ), (string) $attention, add_query_arg( 'ob_attention', '1', $orders_url ), $attention > 0 ? 'alert' : 'ok', __( 'Flagged by staff or rules', 'orderbay' ) );
		if ( null !== $sc_count ) {
			$this->stat_card( __( 'Custom art', 'orderbay' ), (string) $sc_count, $orders_url, 'art', __( 'StoreCanvas orders', 'orderbay' ) );
		}
		echo '</div>';

		if ( $logo_missing || $from_missing ) {
			echo '<div class="ob-dash-setup">';
			echo '<div class="ob-dash-setup-icon dashicons dashicons-admin-appearance"></div>';
			echo '<div>';
			echo '<strong>' . esc_html__( 'Finish document branding', 'orderbay' ) . '</strong>';
			echo '<p>';
			if ( $logo_missing && $from_missing ) {
				echo esc_html__( 'Add a logo and From name/address so invoices look complete.', 'orderbay' );
			} elseif ( $logo_missing ) {
				echo esc_html__( 'Optional: add a logo URL for branded invoices.', 'orderbay' );
			} else {
				echo esc_html__( 'Set From name/address used on invoices and packing slips.', 'orderbay' );
			}
			echo '</p></div>';
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=orderbay-documents' ) ) . '">' . esc_html__( 'Documents', 'orderbay' ) . '</a>';
			echo '</div>';
		}

		echo '<div class="ob-dash-sections">';
		foreach ( $groups as $group ) {
			echo '<section class="ob-dash-section">';
			echo '<h2>' . esc_html( $group['title'] ) . '</h2>';
			echo '<div class="ob-dash-tiles">';
			foreach ( $group['items'] as $item ) {
				echo '<a class="ob-dash-tile" href="' . esc_url( admin_url( 'admin.php?page=' . $item[0] ) ) . '">';
				echo '<span class="dashicons ' . esc_attr( $item[1] ) . '"></span>';
				echo '<strong>' . esc_html( $item[2] ) . '</strong>';
				echo '<span>' . esc_html( $item[3] ) . '</span>';
				echo '</a>';
			}
			echo '</div></section>';
		}
		echo '</div>';

		echo '<p class="ob-dash-foot">' . esc_html__( 'Safe defaults: customer RMA, barcodes, staff digests, tracking email, and customer packing slips stay off until you enable them.', 'orderbay' ) . '</p>';
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
		// Fallback without paginate — bound the scan so a large store cannot OOM the
		// dashboard (was limit=-1, loading every matching order ID into memory).
		unset( $args['paginate'] );
		$args['limit']  = 1000;
		$args['return'] = 'ids';
		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * @param string $title Title.
	 * @param string $value Value.
	 * @param string $url   URL.
	 * @param string $tone  today|queue|alert|ok|art
	 * @param string $hint  Small caption.
	 */
	private function stat_card( $title, $value, $url, $tone, $hint ) {
		echo '<a class="ob-stat ob-stat--' . esc_attr( $tone ) . '" href="' . esc_url( $url ) . '">';
		echo '<span class="ob-stat-value">' . esc_html( $value ) . '</span>';
		echo '<span class="ob-stat-title">' . esc_html( $title ) . '</span>';
		echo '<span class="ob-stat-hint">' . esc_html( $hint ) . '</span>';
		echo '</a>';
	}
}
