<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracking (multi-carrier), pick lists, auto-attention rules.
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
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'tracking_fields' ), 20 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_legacy' ), 45, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'save_tracking_hpos' ), 25 );

		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_attention' ), 25, 4 );

		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_ob_pick_list', array( $this, 'handle_pick_list' ) );

		// Customer My Account packing slip (default off).
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'account_packing_action' ), 25, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_packing_link' ), 22 );
		add_action( 'template_redirect', array( $this, 'handle_customer_packing' ) );
		// Show tracking on My Account when present.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_tracking_block' ), 18 );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default multi-carrier URL templates ({tracking} placeholder).
	 *
	 * @return array[]
	 */
	public static function default_carriers() {
		return array(
			array(
				'id'           => 'ups',
				'label'        => 'UPS',
				'url_template' => 'https://www.ups.com/track?tracknum={tracking}',
			),
			array(
				'id'           => 'usps',
				'label'        => 'USPS',
				'url_template' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking}',
			),
			array(
				'id'           => 'fedex',
				'label'        => 'FedEx',
				'url_template' => 'https://www.fedex.com/fedextrack/?trknbr={tracking}',
			),
			array(
				'id'           => 'dhl',
				'label'        => 'DHL',
				'url_template' => 'https://www.dhl.com/en/express/tracking.html?AWB={tracking}',
			),
			array(
				'id'           => 'custom',
				'label'        => 'Custom',
				'url_template' => '',
			),
		);
	}

	/**
	 * @return array[]
	 */
	public static function get_carriers() {
		$raw = get_option( OB_Plugin::OPT_TRACKING_CARRIERS, null );
		if ( null === $raw || false === $raw || ! is_array( $raw ) || ! $raw ) {
			return self::default_carriers();
		}
		return $raw;
	}

	/**
	 * Preserve {tracking} token through sanitization.
	 *
	 * @param string $tpl Template.
	 * @return string
	 */
	public static function sanitize_url_template( $tpl ) {
		$tpl = trim( (string) $tpl );
		if ( '' === $tpl ) {
			return '';
		}
		$marker = 'OBTRACKINGTOKEN';
		$tmp    = str_replace( array( '{tracking}', '{TRACKING}' ), $marker, $tpl );
		// Allow only http(s) templates.
		if ( ! preg_match( '#^https?://#i', $tmp ) ) {
			return '';
		}
		// Strip tags / control chars but keep path/query.
		$tmp = preg_replace( '/[<>"\'\\\\]/', '', $tmp );
		return str_replace( $marker, '{tracking}', $tmp );
	}

	/**
	 * @param mixed $input Input.
	 * @return array[]
	 */
	public function sanitize_carriers( $input ) {
		if ( ! is_array( $input ) ) {
			return self::default_carriers();
		}
		$out = array();
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( ! $id ) {
				continue;
			}
			$out[] = array(
				'id'           => $id,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $id,
				'url_template' => self::sanitize_url_template( isset( $row['url_template'] ) ? $row['url_template'] : '' ),
			);
		}
		return $out ? $out : self::default_carriers();
	}

	/**
	 * Build public track URL for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty if cannot build (no broken links).
	 */
	public static function build_tracking_url( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		$explicit = (string) $order->get_meta( OB_Plugin::META_TRACKING_URL );
		if ( $explicit ) {
			return $explicit;
		}
		$num = (string) $order->get_meta( OB_Plugin::META_TRACKING );
		if ( ! $num ) {
			return '';
		}
		$carrier_id = (string) $order->get_meta( OB_Plugin::META_TRACKING_CARRIER );
		if ( ! $carrier_id ) {
			return '';
		}
		foreach ( self::get_carriers() as $c ) {
			if ( ( $c['id'] ?? '' ) !== $carrier_id ) {
				continue;
			}
			$tpl = (string) ( $c['url_template'] ?? '' );
			if ( '' === $tpl || false === strpos( $tpl, '{tracking}' ) ) {
				return '';
			}
			return str_replace( '{tracking}', rawurlencode( $num ), $tpl );
		}
		return '';
	}

	/**
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function carrier_label( $order ) {
		$id = (string) $order->get_meta( OB_Plugin::META_TRACKING_CARRIER );
		if ( ! $id ) {
			return '';
		}
		foreach ( self::get_carriers() as $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				return (string) ( $c['label'] ?? $id );
			}
		}
		return $id;
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
		register_setting(
			'ob_fulfillment',
			OB_Plugin::OPT_TRACKING_CARRIERS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_carriers' ),
				'default'           => self::default_carriers(),
			)
		);
		register_setting(
			'ob_fulfillment',
			OB_Plugin::OPT_CUSTOMER_PACKING,
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return ! empty( $v ) && '0' !== (string) $v ? '1' : '0';
				},
				'default'           => '0',
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
		$te       = self::get_tracking_email();
		$aa       = self::get_auto_attention_statuses();
		$carriers = self::get_carriers();
		$statuses = wc_get_order_statuses();
		$pack_on  = get_option( OB_Plugin::OPT_CUSTOMER_PACKING, '0' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay fulfillment', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Tracking emails use wp_mail only and are independent of OrderRing (no SMS/voice). Default tracking email is off.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_fulfillment' );

		echo '<h2>' . esc_html__( 'Carrier tracking URL templates', 'orderbay' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use {tracking} as the placeholder. Leave blank to skip auto-links for that carrier (no broken links). Manual Tracking URL on the order always wins.', 'orderbay' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'ID', 'orderbay' ) . '</th><th>' . esc_html__( 'Label', 'orderbay' ) . '</th><th>' . esc_html__( 'URL template', 'orderbay' ) . '</th></tr></thead><tbody>';
		foreach ( $carriers as $i => $c ) {
			echo '<tr>';
			echo '<td><input type="text" name="' . esc_attr( OB_Plugin::OPT_TRACKING_CARRIERS ) . '[' . (int) $i . '][id]" value="' . esc_attr( $c['id'] ?? '' ) . '" style="width:90px" /></td>';
			echo '<td><input type="text" name="' . esc_attr( OB_Plugin::OPT_TRACKING_CARRIERS ) . '[' . (int) $i . '][label]" value="' . esc_attr( $c['label'] ?? '' ) . '" /></td>';
			echo '<td><input type="text" class="large-text" name="' . esc_attr( OB_Plugin::OPT_TRACKING_CARRIERS ) . '[' . (int) $i . '][url_template]" value="' . esc_attr( $c['url_template'] ?? '' ) . '" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

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

		echo '<h2>' . esc_html__( 'Customer packing slip', 'orderbay' ) . '</h2>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'My Account packing slip', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_CUSTOMER_PACKING ) . '" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_CUSTOMER_PACKING ) . '" value="1" ' . checked( (string) $pack_on, '1', false ) . ' /> ';
		echo esc_html__( 'Allow customers to open a packing slip for their own orders (default off)', 'orderbay' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Owner-only + nonce. Independent of OrderRing.', 'orderbay' ) . '</p></td></tr></table>';

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
		$num     = $order->get_meta( OB_Plugin::META_TRACKING );
		$url     = $order->get_meta( OB_Plugin::META_TRACKING_URL );
		$carrier = $order->get_meta( OB_Plugin::META_TRACKING_CARRIER );
		$built   = self::build_tracking_url( $order );
		echo '<div class="ob-tracking address" style="clear:both;padding-top:12px;">';
		echo '<h3>' . esc_html__( 'Orderbay tracking', 'orderbay' ) . '</h3>';
		echo '<p class="form-field"><label>' . esc_html__( 'Carrier', 'orderbay' ) . '</label>';
		echo '<select name="ob_tracking_carrier" style="width:100%;">';
		echo '<option value="">' . esc_html__( '— Select —', 'orderbay' ) . '</option>';
		foreach ( self::get_carriers() as $c ) {
			echo '<option value="' . esc_attr( $c['id'] ) . '" ' . selected( (string) $carrier, (string) $c['id'], false ) . '>' . esc_html( $c['label'] ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="form-field"><label>' . esc_html__( 'Tracking number', 'orderbay' ) . '</label>';
		echo '<input type="text" name="ob_tracking_number" value="' . esc_attr( (string) $num ) . '" style="width:100%;" /></p>';
		echo '<p class="form-field"><label>' . esc_html__( 'Tracking URL (optional override)', 'orderbay' ) . '</label>';
		echo '<input type="url" name="ob_tracking_url" value="' . esc_attr( (string) $url ) . '" style="width:100%;" /></p>';
		if ( $built ) {
			echo '<p><a href="' . esc_url( $built ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open track link', 'orderbay' ) . '</a></p>';
		}
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
		$prev    = (string) $order->get_meta( OB_Plugin::META_TRACKING );
		$num     = isset( $_POST['ob_tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['ob_tracking_number'] ) ) : ''; // phpcs:ignore
		$url     = isset( $_POST['ob_tracking_url'] ) ? esc_url_raw( wp_unslash( $_POST['ob_tracking_url'] ) ) : ''; // phpcs:ignore
		$carrier = isset( $_POST['ob_tracking_carrier'] ) ? sanitize_key( wp_unslash( $_POST['ob_tracking_carrier'] ) ) : ''; // phpcs:ignore

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
		if ( $carrier ) {
			$order->update_meta_data( OB_Plugin::META_TRACKING_CARRIER, $carrier );
		} else {
			$order->delete_meta_data( OB_Plugin::META_TRACKING_CARRIER );
		}
		$order->save();

		$link = self::build_tracking_url( $order );
		if ( $num && '' === $prev ) {
			$this->maybe_send_tracking_email( $order, $num, $link ? $link : $url );
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
			return;
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
			return add_query_arg( 'ob_bulk_empty', '1', $redirect );
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
		$by_sku        = array();
		$order_numbers = array();
		$any_bin       = false;
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
		include OB_Documents::locate_template( 'pick-list.php' );
		exit;
	}

	/* ─── Customer packing slip + tracking (My Account) ─────────────── */

	/**
	 * @return bool
	 */
	public static function customer_packing_enabled() {
		return '1' === (string) get_option( OB_Plugin::OPT_CUSTOMER_PACKING, '0' );
	}

	/**
	 * @param array    $actions Actions.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public function account_packing_action( $actions, $order ) {
		if ( ! self::customer_packing_enabled() || ! $order || ! is_user_logged_in() ) {
			return $actions;
		}
		if ( ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			return $actions;
		}
		$actions['ob_packing'] = array(
			'url'  => self::customer_packing_url( $order->get_id() ),
			'name' => __( 'Packing slip', 'orderbay' ),
		);
		return $actions;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function account_packing_link( $order ) {
		if ( ! self::customer_packing_enabled() || ! is_user_logged_in() || ! $order ) {
			return;
		}
		if ( ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			return;
		}
		echo '<p class="ob-account-packing"><a class="button" target="_blank" href="' . esc_url( self::customer_packing_url( $order->get_id() ) ) . '">' . esc_html__( 'Download / print packing slip', 'orderbay' ) . '</a></p>';
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function customer_packing_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'ob_customer_packing' => 1,
					'order_id'            => absint( $order_id ),
				),
				wc_get_page_permalink( 'myaccount' ) ?: home_url( '/' )
			),
			'ob_customer_packing_' . absint( $order_id )
		);
	}

	public function handle_customer_packing() {
		if ( empty( $_GET['ob_customer_packing'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! self::customer_packing_enabled() ) {
			wp_die( esc_html__( 'Customer packing slips are disabled.', 'orderbay' ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		if ( ! $order_id || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'ob_customer_packing_' . $order_id ) ) { // phpcs:ignore
			wp_die( esc_html__( 'Invalid packing slip request.', 'orderbay' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			wp_die( esc_html__( 'You cannot view this packing slip.', 'orderbay' ), 403 );
		}
		$settings       = OB_Plugin::get_doc_settings();
		$orders         = array( $order );
		$ob_customer_view = true; // Template flag: hide staff-only bits if checked.
		include OB_Documents::locate_template( 'packing-slip.php' );
		exit;
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public function account_tracking_block( $order ) {
		if ( ! $order || ! is_user_logged_in() ) {
			return;
		}
		if ( ! class_exists( 'OB_Invoicing' ) || ! OB_Invoicing::customer_can_view_invoice( $order ) ) {
			return;
		}
		$num = (string) $order->get_meta( OB_Plugin::META_TRACKING );
		if ( ! $num ) {
			return;
		}
		$url  = self::build_tracking_url( $order );
		$lab  = self::carrier_label( $order );
		echo '<section class="ob-account-tracking" style="margin-top:16px;">';
		echo '<h2>' . esc_html__( 'Tracking', 'orderbay' ) . '</h2>';
		if ( $lab ) {
			echo '<p><strong>' . esc_html__( 'Carrier', 'orderbay' ) . ':</strong> ' . esc_html( $lab ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Tracking number', 'orderbay' ) . ':</strong> ' . esc_html( $num ) . '</p>';
		if ( $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Track shipment', 'orderbay' ) . '</a></p>';
		}
		echo '</section>';
	}
}
