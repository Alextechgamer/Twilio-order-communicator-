<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracking, pick lists, auto-attention rules.
 */
class OB_Fulfillment {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Tracking fields on order.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'tracking_fields' ), 20 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_legacy' ), 45, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'save_tracking_hpos' ), 25 );

		// Auto-attention.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_attention' ), 25, 4 );

		// Pick list bulk.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_ob_pick_list', array( $this, 'handle_pick_list' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function default_tracking_email() {
		return array(
			'enabled' => '0',
			'subject' => __( 'Your order {order_number} has shipped', 'orderbay' ),
			'body'    => __( "Hi {customer_first_name},\n\nYour order {order_number} from {store_name} is on its way.\n\nTracking number: {tracking_number}\nTracking link: {tracking_url}\n\nThank you!", 'orderbay' ),
		);
	}

	public static function get_tracking_email() {
		$raw = get_option( OB_Plugin::OPT_TRACKING_EMAIL, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::default_tracking_email() );
	}

	/**
	 * @return string[] Status slugs without wc- prefix.
	 */
	public static function get_auto_attention_statuses() {
		$raw = get_option( OB_Plugin::OPT_AUTO_ATTENTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
	}

	public function register_settings() {
		register_setting(
			'ob_fulfillment',
			OB_Plugin::OPT_TRACKING_EMAIL,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_tracking_email' ),
				'default'           => self::default_tracking_email(),
			)
		);
		register_setting(
			'ob_fulfillment',
			OB_Plugin::OPT_AUTO_ATTENTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_auto_attention' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_tracking_email( $input ) {
		$out = self::default_tracking_email();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['enabled'] = ! empty( $input['enabled'] ) ? '1' : '0';
		$out['subject'] = isset( $input['subject'] ) ? sanitize_text_field( $input['subject'] ) : $out['subject'];
		$out['body']    = isset( $input['body'] ) ? sanitize_textarea_field( $input['body'] ) : $out['body'];
		return $out;
	}

	public function sanitize_auto_attention( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$out = array();
		foreach ( $input as $st ) {
			$st = sanitize_key( $st );
			$st = str_replace( 'wc-', '', $st );
			if ( $st ) {
				$out[] = $st;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$te = self::get_tracking_email();
		$aa = self::get_auto_attention_statuses();
		$statuses = wc_get_order_statuses();

		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay fulfillment', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Tracking emails use wp_mail only and are independent of Twilio Order Communicator (no SMS/voice). Default tracking email is off.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_fulfillment' );

		echo '<h2>' . esc_html__( 'Tracking email', 'orderbay' ) . '</h2>';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Email customer when tracking is first saved', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_TRACKING_EMAIL ) . '[enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_TRACKING_EMAIL ) . '[enabled]" value="1" ' . checked( $te['enabled'], '1', false ) . ' /> ';
		echo esc_html__( 'Send once (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Subject', 'orderbay' ) . '</th><td>';
		echo '<input type="text" class="large-text" name="' . esc_attr( OB_Plugin::OPT_TRACKING_EMAIL ) . '[subject]" value="' . esc_attr( $te['subject'] ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Body', 'orderbay' ) . '</th><td>';
		echo '<textarea class="large-text" rows="6" name="' . esc_attr( OB_Plugin::OPT_TRACKING_EMAIL ) . '[body]">' . esc_textarea( $te['body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Merge tags: {order_number} {customer_first_name} {store_name} {tracking_number} {tracking_url}', 'orderbay' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Auto needs-attention', 'orderbay' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'When an order enters one of these statuses, set Needs attention. Empty / none selected = off. Attention is never auto-cleared.', 'orderbay' ) . '</p>';
		echo '<fieldset>';
		foreach ( $statuses as $slug => $label ) {
			$key = str_replace( 'wc-', '', $slug );
			echo '<label style="display:inline-block;min-width:180px;margin:2px 8px 2px 0;"><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_AUTO_ATTENTION ) . '[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $aa, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';

		submit_button( __( 'Save fulfillment settings', 'orderbay' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Pick list', 'orderbay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Select orders on the Orders list → bulk action “Orderbay: warehouse pick list”. Groups lines by SKU for the selection.', 'orderbay' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function tracking_fields( $order ) {
		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		wp_nonce_field( 'ob_tracking_save', 'ob_tracking_nonce' );
		$num = $order->get_meta( OB_Plugin::META_TRACKING );
		$url = $order->get_meta( OB_Plugin::META_TRACKING_URL );
		echo '<div class="ob-tracking address" style="clear:both;padding-top:12px;">';
		echo '<h3>' . esc_html__( 'Orderbay tracking', 'orderbay' ) . '</h3>';
		echo '<p class="form-field"><label>' . esc_html__( 'Tracking number', 'orderbay' ) . '</label>';
		echo '<input type="text" name="ob_tracking_number" value="' . esc_attr( (string) $num ) . '" style="width:100%;" /></p>';
		echo '<p class="form-field"><label>' . esc_html__( 'Tracking URL', 'orderbay' ) . '</label>';
		echo '<input type="url" name="ob_tracking_url" value="' . esc_attr( (string) $url ) . '" style="width:100%;" /></p>';
		echo '</div>';
	}

	public function save_tracking_legacy( $order_id, $post = null ) {
		if ( ! isset( $_POST['ob_tracking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_tracking_nonce'] ) ), 'ob_tracking_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_tracking( $order );
		}
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public function save_tracking_hpos( $order_id ) {
		if ( ! is_admin() || ! isset( $_POST['ob_tracking_nonce'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ob_tracking_nonce'] ) ), 'ob_tracking_save' ) ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->apply_tracking( $order );
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private function apply_tracking( $order ) {
		$prev = (string) $order->get_meta( OB_Plugin::META_TRACKING );
		$num  = isset( $_POST['ob_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ob_tracking_number'] ) ) : ''; // phpcs:ignore
		$url  = isset( $_POST['ob_tracking_url'] ) ? esc_url_raw( wp_unslash( $_POST['ob_tracking_url'] ) ) : ''; // phpcs:ignore

		if ( $num ) {
			$order->update_meta_data( OB_Plugin::META_TRACKING, $num );
		} else {
			$order->delete_meta_data( OB_Plugin::META_TRACKING );
		}
		if ( $url ) {
			$order->update_meta_data( OB_Plugin::META_TRACKING_URL, $url );
		} else {
			$order->delete_meta_data( OB_Plugin::META_TRACKING_URL );
		}
		$order->save();

		// First non-empty save → optional email (once).
		if ( $num && '' === $prev ) {
			$this->maybe_send_tracking_email( $order, $num, $url );
		}
	}

	/**
	 * @param WC_Order $order Order.
	 * @param string   $num Tracking number.
	 * @param string   $url Tracking URL.
	 */
	private function maybe_send_tracking_email( $order, $num, $url ) {
		$cfg = self::get_tracking_email();
		if ( '1' !== (string) $cfg['enabled'] ) {
			return;
		}
		if ( $order->get_meta( OB_Plugin::META_TRACKING_EMAIL ) ) {
			return;
		}
		$to = $order->get_billing_email();
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}
		$tags = array(
			'{order_number}'        => $order->get_order_number(),
			'{customer_first_name}' => $order->get_billing_first_name(),
			'{store_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{tracking_number}'     => $num,
			'{tracking_url}'        => $url,
		);
		$subject = strtr( $cfg['subject'], $tags );
		$body    = strtr( $cfg['body'], $tags );
		if ( ! $subject ) {
			return;
		}
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( $sent ) {
			$order->update_meta_data( OB_Plugin::META_TRACKING_EMAIL, current_time( 'mysql' ) );
			$order->add_order_note( sprintf( __( 'Orderbay tracking email sent to %s (not Twilio).', 'orderbay' ), $to ), false, true );
			$order->save();
		}
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from From status.
	 * @param string   $to To status.
	 * @param WC_Order $order Order.
	 */
	public function maybe_auto_attention( $order_id, $from, $to, $order ) {
		$statuses = self::get_auto_attention_statuses();
		if ( ! $statuses ) {
			return; // Empty = off.
		}
		if ( ! in_array( $to, $statuses, true ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
			return;
		}
		$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
		$order->add_order_note( sprintf( __( 'Orderbay auto-attention: status %s.', 'orderbay' ), $to ), false, true );
		$order->save();
	}

	public function bulk_actions( $actions ) {
		$actions['ob_pick_list'] = __( 'Orderbay: warehouse pick list', 'orderbay' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'ob_pick_list' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return $redirect;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ob_pick_list&ids=' . implode( ',', $ids ) ),
			'ob_pick_list'
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_pick_list() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		check_admin_referer( 'ob_pick_list' );
		$raw = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : ''; // phpcs:ignore
		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		$by_sku = array();
		$order_numbers = array();
		$any_bin = false;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$order_numbers[] = $order->get_order_number();
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$product = $item->get_product();
				$sku     = $product ? (string) $product->get_sku() : '';
				if ( '' === $sku ) {
					$sku = 'NO-SKU-' . ( $product ? $product->get_id() : $item->get_id() );
				}
				$bin = '';
				if ( $product ) {
					$bin = (string) $product->get_meta( OB_Plugin::META_BIN );
					if ( ! $bin ) {
						$bin = (string) get_post_meta( $product->get_id(), OB_Plugin::META_BIN, true );
					}
				}
				if ( $bin ) {
					$any_bin = true;
				}
				$name = $item->get_name();
				$qty  = (int) $item->get_quantity();
				$key  = $sku . '|' . $bin;
				if ( ! isset( $by_sku[ $key ] ) ) {
					$by_sku[ $key ] = array(
						'sku'    => $sku,
						'bin'    => $bin,
						'name'   => $name,
						'qty'    => 0,
						'orders' => array(),
					);
				}
				$by_sku[ $key ]['qty'] += $qty;
				$by_sku[ $key ]['orders'][ $order->get_order_number() ] = true;
			}
		}
		$lines = array_values( $by_sku );
		if ( $any_bin ) {
			usort(
				$lines,
				function ( $a, $b ) {
					$c = strnatcasecmp( (string) ( $a['bin'] ?? '' ), (string) ( $b['bin'] ?? '' ) );
					if ( 0 !== $c ) {
						return $c;
					}
					return strnatcasecmp( (string) $a['sku'], (string) $b['sku'] );
				}
			);
		} else {
			usort(
				$lines,
				function ( $a, $b ) {
					return strnatcasecmp( (string) $a['sku'], (string) $b['sku'] );
				}
			);
		}
		$settings = OB_Plugin::get_doc_settings();
		include OB_PLUGIN_DIR . 'templates/pick-list.php';
		exit;
	}
}
