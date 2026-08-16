<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff digest emails (daily/weekly) via WP-Cron. Default off.
 */
class OB_Digest {

	const OPT       = 'ob_digest_settings';
	const CRON_HOOK = 'ob_digest_cron';
	const OPT_LAST  = 'ob_digest_last_sent';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'send_digest' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPT, array( $this, 'on_settings_updated' ), 10, 2 );
		// Reschedule if enabled but missing event (e.g. after deploy).
		add_action( 'admin_init', array( $this, 'maybe_ensure_schedule' ), 40 );
	}

	public static function defaults() {
		return array(
			'enabled'   => '0',
			'frequency' => 'daily', // daily|weekly
			'email'     => get_option( 'admin_email' ),
		);
	}

	public static function get_settings() {
		$raw = get_option( self::OPT, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args( $raw, self::defaults() );
	}

	public function register_settings() {
		register_setting(
			'ob_digest',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$out = self::defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$out['enabled']   = ! empty( $input['enabled'] ) ? '1' : '0';
		$freq             = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : 'daily';
		$out['frequency'] = in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'daily';
		$email            = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$out['email']     = is_email( $email ) ? $email : get_option( 'admin_email' );
		return $out;
	}

	public function on_settings_updated( $old, $new ) {
		self::unschedule();
		if ( is_array( $new ) && ! empty( $new['enabled'] ) && '1' === (string) $new['enabled'] ) {
			self::schedule( $new['frequency'] ?? 'daily' );
		}
	}

	public function maybe_ensure_schedule() {
		$s = self::get_settings();
		if ( '1' !== (string) $s['enabled'] ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule( $s['frequency'] );
		}
	}

	/**
	 * @param string $frequency daily|weekly.
	 */
	public static function schedule( $frequency = 'daily' ) {
		self::unschedule();
		$recurrence = ( 'weekly' === $frequency ) ? 'weekly' : 'daily';
		// First run ~next hour to avoid immediate spam on enable.
		$start = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $start, $recurrence, self::CRON_HOOK );
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Build + send plain-text digest.
	 */
	public function send_digest() {
		$s = self::get_settings();
		if ( '1' !== (string) $s['enabled'] ) {
			return;
		}
		$email = $s['email'];
		if ( ! is_email( $email ) ) {
			return;
		}

		// Once per window guard.
		$window = ( 'weekly' === ( $s['frequency'] ?? 'daily' ) ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		$last   = (int) get_option( self::OPT_LAST, 0 );
		if ( $last && ( time() - $last ) < ( $window - HOUR_IN_SECONDS ) ) {
			return;
		}
		// Transient double-lock.
		if ( get_transient( 'ob_digest_sending' ) ) {
			return;
		}
		set_transient( 'ob_digest_sending', 1, 10 * MINUTE_IN_SECONDS );

		$is_week = ( 'weekly' === ( $s['frequency'] ?? 'daily' ) );
		// Store-local midnight (as an unambiguous epoch), not UTC midnight.
		$since_ts = OB_Plugin::day_start_ts( time(), wp_timezone_string(), $is_week ? 7 : 0 );

		$new_orders = $this->count_orders( array( 'date_created' => '>=' . $since_ts ) );
		$processing = $this->count_orders( array( 'status' => array( 'processing', 'on-hold' ) ) );
		$attention  = $this->count_orders(
			array(
				'meta_key'   => OB_Plugin::META_ATTENTION,
				'meta_value' => '1',
			)
		);

		$low_stock_hits = 0;
		$low_cfg        = class_exists( 'OB_Notifications' ) ? OB_Notifications::get_low_stock() : array( 'threshold' => 5 );
		$threshold      = (int) ( $low_cfg['threshold'] ?? 5 );
		if ( function_exists( 'wc_get_products' ) && class_exists( 'OB_Notifications' ) ) {
			OB_Notifications::for_each_low_stock(
				$threshold,
				function ( $p ) use ( &$low_stock_hits ) {
					$low_stock_hits++;
				}
			);
		}

		$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$period = $is_week ? __( 'week', 'orderbay' ) : __( 'day', 'orderbay' );
		$subject = sprintf(
			/* translators: 1 store 2 period */
			__( '[%1$s] Orderbay %2$s digest', 'orderbay' ),
			$store,
			$period
		);
		$body  = sprintf( __( "Orderbay staff digest (%s)\n", 'orderbay' ), $period );
		$body .= sprintf( __( "New orders (since %s): %d\n", 'orderbay' ), wp_date( 'Y-m-d H:i', $since_ts ), $new_orders );
		$body .= sprintf( __( "Processing / on-hold: %d\n", 'orderbay' ), $processing );
		$body .= sprintf( __( "Needs attention: %d\n", 'orderbay' ), $attention );
		$body .= sprintf( __( "Low-stock products (≤%d): %d\n", 'orderbay' ), $threshold, $low_stock_hits );
		$body .= $this->attention_section();
		$body .= "\n" . admin_url( 'admin.php?page=orderbay' ) . "\n";
		$body .= __( "This email is independent of OrderRing.\n", 'orderbay' );

		wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		update_option( self::OPT_LAST, time(), false );
		delete_transient( 'ob_digest_sending' );
	}


	/**
	 * Orders needing attention (not completed/cancelled) for digest body.
	 *
	 * @return string
	 */
	private function attention_section() {
		$exclude = array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' );
		$all     = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$want    = array();
		foreach ( array_keys( $all ) as $slug ) {
			$bare = str_replace( 'wc-', '', $slug );
			if ( ! in_array( $bare, $exclude, true ) ) {
				$want[] = $bare;
			}
		}
		if ( ! $want ) {
			$want = array( 'processing', 'on-hold', 'pending' );
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 40,
				'status'     => $want,
				'meta_key'   => OB_Plugin::META_ATTENTION,
				'meta_value' => '1',
				'orderby'    => 'date',
				'order'      => 'ASC',
				'return'     => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}
		$lines = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( ! $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
				continue;
			}
			$st = $order->get_status();
			if ( in_array( $st, $exclude, true ) ) {
				continue;
			}
			$created = $order->get_date_created();
			$age     = ( $created && function_exists( 'human_time_diff' ) )
				? human_time_diff( $created->getTimestamp(), time() )
				: '—';
			$reason  = '';
			$rma     = $order->get_meta( OB_Plugin::META_RMA_STATUS );
			if ( $rma && 'none' !== $rma ) {
				$reason = 'RMA:' . $rma;
			}
			if ( $order->get_meta( OB_Plugin::META_SLA_AGED ) ) {
				$reason = $reason ? ( $reason . '; SLA' ) : 'SLA aged';
			}
			$lines[] = sprintf(
				'#%s | %s | age %s%s',
				$order->get_order_number(),
				$st,
				$age,
				$reason ? ( ' | ' . $reason ) : ''
			);
		}
		$out = "\n" . __( '--- Needs attention (open) ---', 'orderbay' ) . "\n";
		if ( ! $lines ) {
			$out .= __( 'None', 'orderbay' ) . "\n";
		} else {
			foreach ( $lines as $line ) {
				$out .= $line . "\n";
			}
		}
		return $out;
	}

	/**
	 * @param array $args Order query args.
	 * @return int
	 */
	private function count_orders( $args ) {
		$args = array_merge(
			array(
				'limit'    => 1,
				'return'   => 'ids',
				'paginate' => true,
			),
			$args
		);
		$result = wc_get_orders( $args );
		if ( is_object( $result ) && isset( $result->total ) ) {
			return (int) $result->total;
		}
		unset( $args['paginate'] );
		$args['limit']  = 100;
		$args['return'] = 'ids';
		$ids = wc_get_orders( $args );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Settings fields block (embedded on digest submenu or tools).
	 */
	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$s = self::get_settings();
		$next = wp_next_scheduled( self::CRON_HOOK );
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay staff digest', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Optional daily/weekly summary via WP-Cron (default off). Plain text wp_mail. Uses Action Scheduler if WC provides it for cron, otherwise WP-Cron. Time-of-day follows site timezone / cron tick — not a precise wall-clock scheduler.', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_digest' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Enable digest', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( self::OPT ) . '[enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPT ) . '[enabled]" value="1" ' . checked( $s['enabled'], '1', false ) . ' /> ';
		echo esc_html__( 'Send staff digest emails', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Frequency', 'orderbay' ) . '</th><td><select name="' . esc_attr( self::OPT ) . '[frequency]">';
		echo '<option value="daily"' . selected( $s['frequency'], 'daily', false ) . '>' . esc_html__( 'Daily', 'orderbay' ) . '</option>';
		echo '<option value="weekly"' . selected( $s['frequency'], 'weekly', false ) . '>' . esc_html__( 'Weekly', 'orderbay' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Recipient', 'orderbay' ) . '</th><td>';
		echo '<input type="email" class="regular-text" name="' . esc_attr( self::OPT ) . '[email]" value="' . esc_attr( $s['email'] ) . '" required /></td></tr>';
		echo '</table>';
		submit_button( __( 'Save digest settings', 'orderbay' ) );
		echo '</form>';
		if ( $next ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Next scheduled run (UTC timestamp): %s', 'orderbay' ), gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' ) ) . '</p>';
		} elseif ( '1' === (string) $s['enabled'] ) {
			echo '<p class="description">' . esc_html__( 'Enabled but not yet scheduled — save again or wait for admin_init.', 'orderbay' ) . '</p>';
		}
		$last = (int) get_option( self::OPT_LAST, 0 );
		if ( $last ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Last sent: %s', 'orderbay' ), gmdate( 'Y-m-d H:i:s', $last ) . ' UTC' ) ) . '</p>';
		}
		echo '</div>';
	}
}
