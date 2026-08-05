<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SLA / aging auto-attention via WP-Cron.
 */
class OB_SLA {

	const CRON_HOOK = 'ob_sla_aging_cron';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_aging' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . OB_Plugin::OPT_SLA, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_ensure_schedule' ), 45 );
	}

	public static function defaults() {
		return array(
			'enabled'    => '0',
			'hours'      => 48,
			'statuses'   => array( 'processing', 'on-hold' ),
			'add_note'   => '1',
		);
	}

	public static function get_settings() {
		$raw = get_option( OB_Plugin::OPT_SLA, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = wp_parse_args( $raw, self::defaults() );
		if ( ! is_array( $out['statuses'] ) ) {
			$out['statuses'] = array( 'processing', 'on-hold' );
		}
		return $out;
	}

	public function register_settings() {
		register_setting(
			'ob_sla',
			OB_Plugin::OPT_SLA,
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
		$out['enabled']  = ! empty( $input['enabled'] ) ? '1' : '0';
		$out['hours']    = isset( $input['hours'] ) ? max( 1, absint( $input['hours'] ) ) : 48;
		$out['add_note'] = ! empty( $input['add_note'] ) ? '1' : '0';
		$statuses        = array();
		if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
			foreach ( $input['statuses'] as $st ) {
				$st = str_replace( 'wc-', '', sanitize_key( $st ) );
				if ( $st ) {
					$statuses[] = $st;
				}
			}
		}
		$out['statuses'] = $statuses ? array_values( array_unique( $statuses ) ) : array();
		return $out;
	}

	public function on_settings_updated( $old, $new ) {
		self::unschedule();
		if ( is_array( $new ) && ! empty( $new['enabled'] ) && '1' === (string) $new['enabled'] ) {
			self::schedule();
		}
	}

	public function maybe_ensure_schedule() {
		$s = self::get_settings();
		if ( '1' !== (string) $s['enabled'] ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		self::unschedule();
		// Hourly is fine; Action Scheduler not required.
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Age orders past threshold → needs attention (once).
	 */
	public function run_aging() {
		$s = self::get_settings();
		if ( '1' !== (string) $s['enabled'] ) {
			return;
		}
		$statuses = $s['statuses'];
		if ( ! $statuses ) {
			return;
		}
		$hours = max( 1, (int) $s['hours'] );
		$before = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		$orders = wc_get_orders(
			array(
				'status'       => $statuses,
				'limit'        => 50,
				'date_created' => '<=' . $before,
				'return'       => 'objects',
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);
		if ( ! is_array( $orders ) ) {
			return;
		}
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// Once-guard: already attention OR already SLA-aged.
			if ( $order->get_meta( OB_Plugin::META_ATTENTION ) ) {
				continue;
			}
			if ( $order->get_meta( OB_Plugin::META_SLA_AGED ) ) {
				continue;
			}
			$order->update_meta_data( OB_Plugin::META_ATTENTION, '1' );
			$order->update_meta_data( OB_Plugin::META_SLA_AGED, current_time( 'mysql' ) );
			if ( '1' === (string) $s['add_note'] ) {
				$order->add_order_note(
					sprintf(
						/* translators: %d hours */
						__( 'Orderbay SLA: order older than %d hours in watched status — marked needs attention.', 'orderbay' ),
						$hours
					),
					false,
					true
				);
			}
			$order->save();
		}
	}

	public static function render_settings_static() {
		self::instance()->render_settings();
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Forbidden', 'orderbay' ) );
		}
		$s = self::get_settings();
		$next = wp_next_scheduled( self::CRON_HOOK );
		echo '<div class="wrap"><h1>' . esc_html__( 'Orderbay SLA aging', 'orderbay' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Hourly WP-Cron job. Default off. Flags aging orders with needs-attention once (does not clear).', 'orderbay' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ob_sla' );
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Enable', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[enabled]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[enabled]" value="1" ' . checked( $s['enabled'], '1', false ) . ' /> ';
		echo esc_html__( 'Run SLA aging (default off)', 'orderbay' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Hours threshold', 'orderbay' ) . '</th><td>';
		echo '<input type="number" min="1" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[hours]" value="' . esc_attr( (string) $s['hours'] ) . '" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Watched statuses', 'orderbay' ) . '</th><td><fieldset>';
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$key = str_replace( 'wc-', '', $slug );
			echo '<label style="display:inline-block;min-width:160px;margin:2px 8px 2px 0;"><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[statuses][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $s['statuses'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th>' . esc_html__( 'Order note', 'orderbay' ) . '</th><td>';
		echo '<input type="hidden" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[add_note]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( OB_Plugin::OPT_SLA ) . '[add_note]" value="1" ' . checked( $s['add_note'], '1', false ) . ' /> ';
		echo esc_html__( 'Add private note when flagging (once)', 'orderbay' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Save SLA settings', 'orderbay' ) );
		echo '</form>';
		if ( $next ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Next run (UTC): %s', 'orderbay' ), gmdate( 'Y-m-d H:i:s', $next ) ) ) . '</p>';
		}
		echo '</div>';
	}
}
