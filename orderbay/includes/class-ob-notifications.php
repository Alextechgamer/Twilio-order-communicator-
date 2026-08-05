<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C) Status email rules + low-stock (wp_mail only; not Twilio).
 */
class OB_Notifications {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 10, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_low_stock' ), 10, 1 );
		// Daily scan fallback.
		add_action( 'ob_daily_stock_scan', array( $this, 'daily_stock_scan' ) );
		if ( ! wp_next_scheduled( 'ob_daily_stock_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ob_daily_stock_scan' );
		}
	}

	public static function default_rules() {
		return array(); // Empty = all off.
	}

	public static function get_rules() {
		$rules = get_option( OB_Plugin::OPT_EMAIL_RULES, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function default_low_stock() {
		return array(
			'enabled'   => '0',
			'threshold' => 5,
			'email'     => get_option( 'admin_email' ),
		);
	}

	public static function get_low_stock() {
		$raw = get_option( OB_Plugin::OPT_LOW_STOCK, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::default_low_stock() );
	}

	public function register_settings() {
		register_setting(
			'ob_notifications',
			OB_Plugin::OPT_EMAIL_RULES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_rules' ),
				'default'           => array(),
			)
		);
		register_setting(
			'ob_notifications',
			OB_Plugin::OPT_LOW_STOCK,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_low_stock' ),
				'default'           => self::default_low_stock(),
			)
		);
	}

	public function sanitize_rules( $input ) {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			if ( ! $status ) {
				continue;
			}
			$out[] = array(
				'enabled'      => ! empty( $row['enabled'] ) ? '1' : '0',
				'status'       => $status,
				'recipient'    => isset( $row['recipient'] ) ? sanitize_key( $row['recipient'] ) : 'admin',
				'custom_email' => isset( $row['custom_email'] ) ? sanitize_email( $row['custom_email'] ) : '',
				'subject'      => isset( $row['subject'] ) ? sanitize_text_field( $row['subject'] ) : '',
				'body'         => isset( $row['body'] ) ? sanitize_textarea_field( $row['body'] ) : '',
			);
		}
		return $out;
	}

	public function sanitize_low_stock( $input ) {
		$out = self::default_low_stock();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['enabled']   = ! empty( $input['enabled'] ) ? '1' : '0';
		$out['threshold'] = isset( $input['threshold'] ) ? max( 0, absint( $input['threshold'] ) ) : 5;
		$out['email']     = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : get_option( 'admin_email' );
		return $out;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$rules = self::get_rules();
		if ( ! $rules ) {
			$rules = array(
				array(
					'enabled'      => '0',
					'status'       => 'processing',
					'recipient'    => 'customer',
					'custom_email' => '',
					'subject'      => __( 'Order {order_number} is now processing', 'orderbay' ),
					'body'         => __( "Hi {customer_first_name},\n\nYour order {order_number} at {store_name} is processing.\n\nThank you!", 'orderbay' ),
				),
			);
		}
		$low = self::get_low_stock();
		$statuses = wc_get_order_statuses();

		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay email rules', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Uses wp_mail only. Independent of Twilio Order Communicator. Rules default off.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_notifications' );

		echo '<h2>' . esc_html__( 'Status email rules', 'orderbay' ) . '</h2>';
		echo '<table class="widefat striped" id="ob-email-rules"><thead><tr>';
		echo '<th>' . esc_html__( 'On', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Custom email', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Body', 'orderbay' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rules as $i => $row ) {
			$this->rule_row( $i, $row, $statuses );
		}
		// Extra empty row for adding.
		$this->rule_row( count( $rules ), array(
			'enabled'      => '0',
			'status'       => '',
			'recipient'    => 'admin',
			'custom_email' => '',
			'subject'      => '',
			'body'         => '',
		), $statuses );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Merge tags: {order_number} {customer_first_name} {store_name}', 'orderbay' ) . '</p>';

		echo '<h2>' . esc_html__( 'Low stock', 'orderbay' ) . '</h2>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Enable alerts', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[enabled]" value="1" ' . checked( $low['enabled'], '1', false ) . ' /> ';
		echo esc_html__( 'Email when stock hits threshold', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Threshold', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="0" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[threshold]" value="' . esc_attr( (string) $low['threshold'] ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Notify email', 'orderbay' ) . '</th><td>';
		echo '<input type="email" class="regular-text" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[email]" value="' . esc_attr( $low['email'] ) . '" /></td></tr>';
		echo '</table>';

		submit_button( __( 'Save notification settings', 'orderbay' ) );
		echo '</form></div>';
	}

	private function rule_row( $i, $row, $statuses ) {
		$opt = OB_Plugin::OPT_EMAIL_RULES;
		echo '<tr>';
		echo '<td><input type="checkbox" name="' . esc_attr( $opt ) . '[' . (int) $i . '][enabled]" value="1" ' . checked( ! empty( $row['enabled'] ) && '1' === (string) $row['enabled'], true, false ) . ' /></td>';
		echo '<td><select name="' . esc_attr( $opt ) . '[' . (int) $i . '][status]">';
		echo '<option value="">—</option>';
		foreach ( $statuses as $slug => $label ) {
			$key = str_replace( 'wc-', '', $slug );
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['status'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><select name="' . esc_attr( $opt ) . '[' . (int) $i . '][recipient]">';
		foreach ( array( 'customer' => __( 'Customer', 'orderbay' ), 'admin' => __( 'Admin', 'orderbay' ), 'custom' => __( 'Custom', 'orderbay' ) ) as $k => $lab ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $row['recipient'] ?? 'admin', $k, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="email" name="' . esc_attr( $opt ) . '[' . (int) $i . '][custom_email]" value="' . esc_attr( $row['custom_email'] ?? '' ) . '" /></td>';
		echo '<td><input type="text" class="large-text" name="' . esc_attr( $opt ) . '[' . (int) $i . '][subject]" value="' . esc_attr( $row['subject'] ?? '' ) . '" /></td>';
		echo '<td><textarea rows="3" class="large-text" name="' . esc_attr( $opt ) . '[' . (int) $i . '][body]">' . esc_textarea( $row['body'] ?? '' ) . '</textarea></td>';
		echo '</tr>';
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from From status.
	 * @param string   $to To status.
	 * @param WC_Order $order Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		foreach ( self::get_rules() as $rule ) {
			if ( empty( $rule['enabled'] ) || '1' !== (string) $rule['enabled'] ) {
				continue;
			}
			if ( ( $rule['status'] ?? '' ) !== $to ) {
				continue;
			}
			$this->send_rule_email( $order, $rule );
		}
	}

	/**
	 * @param WC_Order $order Order.
	 * @param array    $rule Rule.
	 */
	private function send_rule_email( $order, $rule ) {
		$tags = array(
			'{order_number}'         => $order->get_order_number(),
			'{customer_first_name}'  => $order->get_billing_first_name(),
			'{store_name}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$subject = strtr( $rule['subject'] ?? '', $tags );
		$body    = strtr( $rule['body'] ?? '', $tags );
		$to      = '';
		$recip   = $rule['recipient'] ?? 'admin';
		if ( 'customer' === $recip ) {
			$to = $order->get_billing_email();
		} elseif ( 'custom' === $recip ) {
			$to = $rule['custom_email'] ?? '';
		} else {
			$to = get_option( 'admin_email' );
		}
		if ( ! $to || ! is_email( $to ) || ! $subject ) {
			return;
		}
		// Once-per-rule-per-order soft guard.
		$key = '_ob_emailed_' . md5( ( $rule['status'] ?? '' ) . '|' . $recip . '|' . $subject );
		if ( $order->get_meta( $key ) ) {
			return;
		}
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			$order->update_meta_data( $key, current_time( 'mysql' ) );
			$order->add_order_note( sprintf( __( 'Orderbay email sent to %s (%s).', 'orderbay' ), $to, $rule['status'] ?? '' ), false, true );
			$order->save();
		}
	}

	/**
	 * @param WC_Product $product Product.
	 */
	public function on_low_stock( $product ) {
		$cfg = self::get_low_stock();
		if ( '1' !== (string) $cfg['enabled'] || ! $product instanceof WC_Product ) {
			return;
		}
		$this->maybe_notify_stock( $product, $cfg );
	}

	public function daily_stock_scan() {
		$cfg = self::get_low_stock();
		if ( '1' !== (string) $cfg['enabled'] ) {
			return;
		}
		$threshold = (int) $cfg['threshold'];
		$ids       = wc_get_products(
			array(
				'limit'  => 100,
				'status' => 'publish',
				'return' => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->managing_stock() ) {
				continue;
			}
			if ( $p->get_stock_quantity() !== null && (int) $p->get_stock_quantity() <= $threshold ) {
				$this->maybe_notify_stock( $p, $cfg );
			}
		}
	}

	/**
	 * @param WC_Product $product Product.
	 * @param array      $cfg Config.
	 */
	private function maybe_notify_stock( $product, $cfg ) {
		$email = $cfg['email'] ?? get_option( 'admin_email' );
		if ( ! is_email( $email ) ) {
			return;
		}
		$qty = $product->get_stock_quantity();
		// Rate-limit: one email per product per day.
		$tkey = 'ob_lowstock_' . $product->get_id();
		if ( get_transient( $tkey ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1 product name 2 qty */
			__( '[%1$s] Low stock: %2$s (%3$s left)', 'orderbay' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$product->get_name(),
			null === $qty ? '?' : (string) $qty
		);
		$body = sprintf(
			__( "Product: %1\$s\nSKU: %2\$s\nStock: %3\$s\nEdit: %4\$s\n", 'orderbay' ),
			$product->get_name(),
			$product->get_sku(),
			null === $qty ? 'n/a' : (string) $qty,
			get_edit_post_link( $product->get_id(), 'raw' )
		);
		wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		set_transient( $tkey, 1, DAY_IN_SECONDS );
	}
}
