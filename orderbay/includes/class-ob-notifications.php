<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C) Email rules CRUD + low-stock (wp_mail only; independent of TOC).
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
		add_action( 'admin_post_ob_save_email_rules', array( $this, 'handle_save_rules' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 10, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_low_stock' ), 10, 1 );
		add_action( 'ob_daily_stock_scan', array( $this, 'daily_stock_scan' ) );
		if ( ! wp_next_scheduled( 'ob_daily_stock_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ob_daily_stock_scan' );
		}
	}

	public static function get_rules() {
		$rules = get_option( OB_Plugin::OPT_EMAIL_RULES, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
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
			OB_Plugin::OPT_LOW_STOCK,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_low_stock' ),
				'default'           => self::default_low_stock(),
			)
		);
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

	/**
	 * Normalize rule rows with stable ids.
	 *
	 * @param array $input Raw POST rows.
	 * @return array
	 */
	public function sanitize_rules( $input ) {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Allow explicit delete.
			if ( ! empty( $row['delete'] ) ) {
				continue;
			}
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			if ( ! $status ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( ! $id ) {
				$id = 'r' . substr( md5( wp_json_encode( $row ) . microtime( true ) . wp_rand() ), 0, 10 );
			}
			$out[] = array(
				'id'           => $id,
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

	public function handle_save_rules() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		check_admin_referer( 'ob_save_email_rules' );
		$raw   = isset( $_POST['ob_rules'] ) ? wp_unslash( $_POST['ob_rules'] ) : array(); // phpcs:ignore
		$rules = $this->sanitize_rules( is_array( $raw ) ? $raw : array() );
		update_option( OB_Plugin::OPT_EMAIL_RULES, $rules, false );

		// Low stock via same form if present.
		if ( isset( $_POST[ OB_Plugin::OPT_LOW_STOCK ] ) ) { // phpcs:ignore
			$low = $this->sanitize_low_stock( wp_unslash( $_POST[ OB_Plugin::OPT_LOW_STOCK ] ) ); // phpcs:ignore
			update_option( OB_Plugin::OPT_LOW_STOCK, $low, false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'orderbay-notifications',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$rules = self::get_rules();
		$low   = self::get_low_stock();
		$statuses = wc_get_order_statuses();

		// Ensure at least one empty add row in UI (not saved unless status set).
		$display = $rules;
		$display[] = array(
			'id'           => '',
			'enabled'      => '0',
			'status'       => '',
			'recipient'    => 'customer',
			'custom_email' => '',
			'subject'      => '',
			'body'         => '',
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay email rules', 'orderbay' ) . '</h1>';
		if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'orderbay' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'wp_mail only — independent of Twilio Order Communicator. All rules default off. Merge tags: {order_number} {customer_first_name} {customer_email} {store_name} {order_status} {order_total}', 'orderbay' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ob_save_email_rules" />';
		wp_nonce_field( 'ob_save_email_rules' );

		echo '<h2>' . esc_html__( 'Status email rules', 'orderbay' ) . '</h2>';
		echo '<table class="widefat striped ob-email-rules"><thead><tr>';
		echo '<th>' . esc_html__( 'On', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Trigger status', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Custom email', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Body', 'orderbay' ) . '</th>';
		echo '<th>' . esc_html__( 'Delete', 'orderbay' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $display as $i => $row ) {
			$id = $row['id'] ?? '';
			echo '<tr>';
			echo '<td>';
			if ( $id ) {
				echo '<input type="hidden" name="ob_rules[' . (int) $i . '][id]" value="' . esc_attr( $id ) . '" />';
			}
			echo '<input type="checkbox" name="ob_rules[' . (int) $i . '][enabled]" value="1" ' . checked( ! empty( $row['enabled'] ) && '1' === (string) $row['enabled'], true, false ) . ' /></td>';
			echo '<td><select name="ob_rules[' . (int) $i . '][status]"><option value="">— ' . esc_html__( 'Add rule…', 'orderbay' ) . ' —</option>';
			foreach ( $statuses as $slug => $label ) {
				$key = str_replace( 'wc-', '', $slug );
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row['status'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><select name="ob_rules[' . (int) $i . '][recipient]">';
			foreach ( array(
				'customer' => __( 'Customer', 'orderbay' ),
				'admin'    => __( 'Admin', 'orderbay' ),
				'custom'   => __( 'Custom email', 'orderbay' ),
			) as $k => $lab ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $row['recipient'] ?? 'admin', $k, false ) . '>' . esc_html( $lab ) . '</option>';
			}
			echo '</select></td>';
			echo '<td><input type="email" name="ob_rules[' . (int) $i . '][custom_email]" value="' . esc_attr( $row['custom_email'] ?? '' ) . '" placeholder="you@example.com" /></td>';
			echo '<td><input type="text" class="large-text" name="ob_rules[' . (int) $i . '][subject]" value="' . esc_attr( $row['subject'] ?? '' ) . '" /></td>';
			echo '<td><textarea rows="3" class="large-text" name="ob_rules[' . (int) $i . '][body]">' . esc_textarea( $row['body'] ?? '' ) . '</textarea></td>';
			echo '<td>';
			if ( $id ) {
				echo '<label><input type="checkbox" name="ob_rules[' . (int) $i . '][delete]" value="1" /> ' . esc_html__( 'Remove', 'orderbay' ) . '</label>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Leave the last row status empty to skip. Check Remove to delete a rule on save. Once-per-rule-per-order guard prevents re-send spam.', 'orderbay' ) . '</p>';

		echo '<h2>' . esc_html__( 'Low stock alerts', 'orderbay' ) . '</h2>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Enable', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[enabled]" value="1" ' . checked( $low['enabled'], '1', false ) . ' /> ';
		echo esc_html__( 'Email when stock hits threshold (daily throttle per product)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Threshold', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="0" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[threshold]" value="' . esc_attr( (string) $low['threshold'] ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Notify email', 'orderbay' ) . '</th><td>';
		echo '<input type="email" class="regular-text" name="' . esc_attr( OB_Plugin::OPT_LOW_STOCK ) . '[email]" value="' . esc_attr( $low['email'] ) . '" /></td></tr>';
		echo '</table>';

		submit_button( __( 'Save notification settings', 'orderbay' ) );
		echo '</form></div>';
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from From.
	 * @param string   $to To.
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
		$rule_id = isset( $rule['id'] ) ? sanitize_key( $rule['id'] ) : md5( ( $rule['status'] ?? '' ) . ( $rule['subject'] ?? '' ) );
		$guard   = '_ob_emailed_rule_' . $rule_id . '_at';
		if ( $order->get_meta( $guard ) ) {
			return;
		}

		$tags = array(
			'{order_number}'        => $order->get_order_number(),
			'{customer_first_name}' => $order->get_billing_first_name(),
			'{customer_email}'      => $order->get_billing_email(),
			'{store_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{order_status}'        => wc_get_order_status_name( $order->get_status() ),
			'{order_total}'         => wp_strip_all_tags( $order->get_formatted_order_total() ),
		);
		$subject = strtr( $rule['subject'] ?? '', $tags );
		$body    = strtr( $rule['body'] ?? '', $tags );
		if ( ! $subject ) {
			return;
		}

		$to    = '';
		$recip = $rule['recipient'] ?? 'admin';
		if ( 'customer' === $recip ) {
			$to = $order->get_billing_email();
		} elseif ( 'custom' === $recip ) {
			$to = $rule['custom_email'] ?? '';
		} else {
			$to = get_option( 'admin_email' );
		}
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$sent    = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			$order->update_meta_data( $guard, current_time( 'mysql' ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1 email 2 rule id */
					__( 'Orderbay email sent to %1$s (rule %2$s).', 'orderbay' ),
					$to,
					$rule_id
				),
				false,
				true
			);
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
				'limit'  => 150,
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
		// Daily throttle per product (transient) — no spam on every page load / hook fire.
		$tkey = 'ob_lowstock_' . $product->get_id();
		if ( get_transient( $tkey ) ) {
			return;
		}
		$qty     = $product->get_stock_quantity();
		$subject = sprintf(
			/* translators: 1 store 2 product 3 qty */
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
