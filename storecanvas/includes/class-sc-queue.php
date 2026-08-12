<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production queue admin page (1.1.0).
 * Lists orders with StoreCanvas art; mark printed; ZIP download.
 */
class SC_Queue {

	const META_PRINTED = '_sc_printed_at';
	const PAGE_SLUG    = 'sc-production-queue';
	const PER_PAGE     = 20;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_post_sc_mark_printed', array( $this, 'handle_mark_printed' ) );
		add_action( 'admin_post_sc_mark_unprinted', array( $this, 'handle_mark_unprinted' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'StoreCanvas Queue', 'storecanvas' ),
			__( 'StoreCanvas Queue', 'storecanvas' ),
			'edit_shop_orders',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_has_sc_art( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( $order->get_meta( SC_Orders_List::ORDER_META ) || $order->get_meta( '_sc_has_custom_art' ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			foreach ( array( 'sc_layers', 'sc_placement', 'sc_attachments', 'sc_print_files', '_sc_artwork_id', 'sc_preview_id' ) as $key ) {
				$val = $item->get_meta( $key );
				if ( ! empty( $val ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * HPOS-safe: query recent orders with SC art meta, then filter.
	 *
	 * @param string $tab all|unprinted|printed
	 * @param int    $page Page.
	 * @return array{orders:WC_Order[],total:int}
	 */
	/**
	 * Whether WooCommerce is using the High-Performance Order Storage (custom tables)
	 * datastore, which supports meta_query in wc_get_orders(). The classic CPT datastore
	 * does not.
	 *
	 * @return bool
	 */
	private static function hpos_active() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public function query_orders( $tab = 'all', $page = 1 ) {
		$page = max( 1, (int) $page );
		// Pull a wider window then filter — reliable across HPOS/legacy without raw SQL joins.
		$args = array(
			'limit'    => 200,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
			'paginate' => false,
		);
		// Prefer stamped meta when present — but meta_query is only supported by the HPOS
		// order datastore. On the classic (CPT) datastore wc_get_orders() emits a
		// "called incorrectly" notice for it (WC 9.2+), so only add it under HPOS. Either
		// way every result is re-checked with order_has_sc_art() below, and the
		// recent-orders fallback covers the classic path, so the outcome is identical.
		if ( self::hpos_active() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'   => '_sc_has_custom_art',
					'value' => '1',
				),
				array(
					'key'     => self::META_PRINTED,
					'compare' => 'EXISTS',
				),
			);
		}

		$orders = wc_get_orders( $args );
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		// Also scan recent orders without meta stamp (fallback).
		if ( count( $orders ) < 50 ) {
			$recent = wc_get_orders(
				array(
					'limit'  => 80,
					'orderby'=> 'date',
					'order'  => 'DESC',
					'return' => 'objects',
				)
			);
			$seen = array();
			foreach ( $orders as $o ) {
				$seen[ $o->get_id() ] = true;
			}
			foreach ( (array) $recent as $o ) {
				if ( isset( $seen[ $o->get_id() ] ) ) {
					continue;
				}
				if ( self::order_has_sc_art( $o ) ) {
					$orders[] = $o;
					$seen[ $o->get_id() ] = true;
				}
			}
		}

		$filtered = array();
		foreach ( $orders as $order ) {
			if ( ! self::order_has_sc_art( $order ) ) {
				continue;
			}
			$printed = (bool) $order->get_meta( self::META_PRINTED );
			if ( 'unprinted' === $tab && $printed ) {
				continue;
			}
			if ( 'printed' === $tab && ! $printed ) {
				continue;
			}
			$filtered[] = $order;
		}

		// Sort by date desc.
		usort(
			$filtered,
			function ( $a, $b ) {
				$da = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$db = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
				return $db - $da;
			}
		);

		$total = count( $filtered );
		$slice = array_slice( $filtered, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		return array( 'orders' => $slice, 'total' => $total );
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$tab  = isset( $_GET['sc_tab'] ) ? sanitize_key( wp_unslash( $_GET['sc_tab'] ) ) : 'all'; // phpcs:ignore
		if ( ! in_array( $tab, array( 'all', 'unprinted', 'printed' ), true ) ) {
			$tab = 'all';
		}
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
		$result = $this->query_orders( $tab, $page );
		$orders = $result['orders'];
		$total  = $result['total'];
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'StoreCanvas production queue', 'storecanvas' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Print files are RGB (PDFs are flattened RGB, not CMYK or PDF-X). Confirm color and DPI with your print provider before production.', 'storecanvas' ) . '</p>';

		echo '<ul class="subsubsub">';
		$tabs = array(
			'all'       => __( 'All', 'storecanvas' ),
			'unprinted' => __( 'Unprinted', 'storecanvas' ),
			'printed'   => __( 'Printed', 'storecanvas' ),
		);
		$i = 0;
		foreach ( $tabs as $key => $label ) {
			$i++;
			$url = add_query_arg( 'sc_tab', $key, $base );
			$cls = $tab === $key ? ' class="current"' : '';
			echo '<li><a href="' . esc_url( $url ) . '"' . $cls . '>' . esc_html( $label ) . '</a>'; // phpcs:ignore
			if ( $i < count( $tabs ) ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul><br class="clear" />';

		if ( ! empty( $_GET['sc_msg'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['sc_msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Items', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'SC Art', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Printed', 'storecanvas' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'storecanvas' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $orders ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No matching orders.', 'storecanvas' ) . '</td></tr>';
		}

		foreach ( $orders as $order ) {
			$oid   = $order->get_id();
			$link  = $order->get_edit_order_url();
			$date  = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '—';
			$cust  = $order->get_formatted_billing_full_name();
			$items = array();
			$preview_id = 0;
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' × ' . $item->get_quantity();
				if ( ! $preview_id ) {
					$preview_id = (int) $item->get_meta( 'sc_preview_id' );
				}
				if ( ! $preview_id ) {
					$prints = $item->get_meta( 'sc_print_files' );
					if ( is_array( $prints ) && $prints ) {
						$preview_id = (int) reset( $prints );
					}
				}
			}
			$printed_at = $order->get_meta( self::META_PRINTED );
			$zip_url    = SC_Bulk_Download::instance()->download_url( $oid );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $link ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a></td>';
			echo '<td>' . esc_html( $date ) . '</td>';
			echo '<td>' . esc_html( $cust ) . '</td>';
			echo '<td>' . esc_html( implode( '; ', $items ) ) . '</td>';
			echo '<td>';
			if ( $preview_id ) {
				// Customer-artwork preview: always via the signed proxy, never the raw
				// uploads URL (see docs/storecanvas-artwork-privacy.md).
				$url = class_exists( 'SC_Print_Ready' ) ? SC_Print_Ready::instance()->proxy_url( $preview_id ) : '';
				if ( $url ) {
					echo '<img src="' . esc_url( $url ) . '" alt="" style="max-width:64px;height:auto;border:1px solid #ddd;" />';
				} else {
					echo '—';
				}
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td><span style="color:#007017;font-weight:600;">' . esc_html__( 'Yes', 'storecanvas' ) . '</span></td>';
			echo '<td>' . ( $printed_at ? esc_html( $printed_at ) : '—' ) . '</td>';
			echo '<td style="white-space:nowrap;">';
			echo '<a class="button button-small" href="' . esc_url( $link ) . '">' . esc_html__( 'Open', 'storecanvas' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( $zip_url ) . '">' . esc_html__( 'ZIP', 'storecanvas' ) . '</a> ';
			if ( $printed_at ) {
				$u = wp_nonce_url( admin_url( 'admin-post.php?action=sc_mark_unprinted&order_id=' . $oid ), 'sc_mark_unprinted_' . $oid );
				echo '<a class="button button-small" href="' . esc_url( $u ) . '">' . esc_html__( 'Mark unprinted', 'storecanvas' ) . '</a>';
			} else {
				$u = wp_nonce_url( admin_url( 'admin-post.php?action=sc_mark_printed&order_id=' . $oid ), 'sc_mark_printed_' . $oid );
				echo '<a class="button button-small button-primary" href="' . esc_url( $u ) . '">' . esc_html__( 'Mark printed', 'storecanvas' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'      => add_query_arg( array( 'sc_tab' => $tab, 'paged' => '%#%' ), $base ),
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			);
			echo '</div></div>';
		}

		echo '<p class="description">' . esc_html__( 'Orders with custom art (layers, placement, composites, or preview). Mark printed sets order meta _sc_printed_at.', 'storecanvas' ) . '</p>';
		echo '</div>';
	}

	public function handle_mark_printed() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'sc_mark_printed_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'storecanvas' ) );
		}
		$order->update_meta_data( self::META_PRINTED, current_time( 'mysql' ) );
		$order->save();
		$order->add_order_note( __( 'StoreCanvas: marked printed from production queue.', 'storecanvas' ), false, true );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'sc_msg' => rawurlencode( __( 'Marked printed.', 'storecanvas' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_mark_unprinted() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		check_admin_referer( 'sc_mark_unprinted_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found', 'storecanvas' ) );
		}
		$order->delete_meta_data( self::META_PRINTED );
		$order->save();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'sc_msg' => rawurlencode( __( 'Marked unprinted.', 'storecanvas' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
