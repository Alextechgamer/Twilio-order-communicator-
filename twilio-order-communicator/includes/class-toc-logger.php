<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TOC_Logger {

	private static $instance = null;
	private $table;
	private $opt_outs_table;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table          = $wpdb->prefix . 'toc_communications';
		$this->opt_outs_table = $wpdb->prefix . 'toc_sms_opt_outs';
	}

	public function get_table() {
		return $this->table;
	}

	public function get_opt_outs_table() {
		return $this->opt_outs_table;
	}

	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'toc_communications';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned DEFAULT 0,
			phone varchar(30) NOT NULL DEFAULT '',
			direction varchar(10) NOT NULL DEFAULT 'outbound',
			type varchar(10) NOT NULL DEFAULT 'sms',
			body text,
			twilio_sid varchar(64) DEFAULT '',
			status varchar(40) DEFAULT '',
			resolved tinyint(1) NOT NULL DEFAULT 0,
			resolved_at datetime DEFAULT NULL,
			resolved_by bigint(20) unsigned DEFAULT 0,
			admin_user_id bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY phone (phone),
			KEY twilio_sid (twilio_sid),
			KEY created_at (created_at),
			KEY resolved (resolved)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Scalable STOP / opt-out list (replaces serialized toc_sms_opt_outs option).
	 */
	public static function create_opt_outs_table() {
		global $wpdb;
		$table   = $wpdb->prefix . 'toc_sms_opt_outs';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(30) NOT NULL DEFAULT '',
			phone_digits varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone_digits (phone_digits),
			KEY phone (phone)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * One-time migrate from legacy option array into the opt-outs table.
	 */
	public static function migrate_opt_outs_from_option() {
		$list = get_option( 'toc_sms_opt_outs', null );
		if ( null === $list ) {
			return;
		}

		if ( is_array( $list ) ) {
			$logger = self::instance();
			foreach ( $list as $entry ) {
				$logger->add_opt_out_phone( (string) $entry );
			}
		}

		delete_option( 'toc_sms_opt_outs' );
	}

	public function log( $args ) {
		global $wpdb;

		$defaults = array(
			'order_id'      => 0,
			'phone'         => '',
			'direction'     => 'outbound',
			'type'          => 'sms',
			'body'          => '',
			'twilio_sid'    => '',
			'status'        => '',
			'resolved'      => 0,
			'admin_user_id' => get_current_user_id(),
		);
		$data = wp_parse_args( $args, $defaults );

		$wpdb->insert(
			$this->table,
			array(
				'order_id'      => absint( $data['order_id'] ),
				'phone'         => sanitize_text_field( $data['phone'] ),
				'direction'     => in_array( $data['direction'], array( 'outbound', 'inbound' ), true ) ? $data['direction'] : 'outbound',
				'type'          => in_array( $data['type'], array( 'sms', 'voice' ), true ) ? $data['type'] : 'sms',
				'body'          => wp_kses_post( $data['body'] ),
				'twilio_sid'    => sanitize_text_field( $data['twilio_sid'] ),
				'status'        => sanitize_text_field( $data['status'] ),
				'resolved'      => absint( $data['resolved'] ),
				'admin_user_id' => absint( $data['admin_user_id'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return $wpdb->insert_id;
	}

	public function get_order_history( $order_id, $limit = 150 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY created_at ASC LIMIT %d",
				absint( $order_id ),
				absint( $limit )
			)
		);
	}

	public function get_filtered( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'     => 50,
			'offset'    => 0,
			'type'      => '',
			'direction' => '',
			'resolved'  => '',
			'order_id'  => 0,
			'phone'     => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where_sql, $vals ) = $this->build_filter_sql( $args );

		$orderby = in_array( $args['orderby'], array( 'created_at', 'id', 'order_id' ), true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$sql    = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$vals[] = absint( $args['limit'] );
		$vals[] = absint( $args['offset'] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) );
	}

	public function count_filtered( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'type'      => '',
			'direction' => '',
			'resolved'  => '',
			'order_id'  => 0,
			'phone'     => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where_sql, $vals ) = $this->build_filter_sql( $args );
		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";

		return empty( $vals )
			? (int) $wpdb->get_var( $sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $sql, $vals ) );
	}

	private function build_filter_sql( $args ) {
		global $wpdb;

		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['type'] ) && in_array( $args['type'], array( 'sms', 'voice' ), true ) ) {
			$where[] = 'type = %s';
			$vals[]  = $args['type'];
		}
		if ( ! empty( $args['direction'] ) && in_array( $args['direction'], array( 'inbound', 'outbound' ), true ) ) {
			$where[] = 'direction = %s';
			$vals[]  = $args['direction'];
		}
		if ( isset( $args['resolved'] ) && $args['resolved'] !== '' ) {
			$where[] = 'resolved = %d';
			$vals[]  = absint( $args['resolved'] );
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where[] = 'order_id = %d';
			$vals[]  = absint( $args['order_id'] );
		}
		if ( ! empty( $args['phone'] ) ) {
			$where[] = 'phone LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $args['phone'] ) . '%';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[] = '(body LIKE %s OR phone LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$vals[]  = $like;
			$vals[]  = $like;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $args['date_to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $vals );
	}

	public function mark_resolved( $id, $resolved = true ) {
		global $wpdb;
		return $wpdb->update(
			$this->table,
			array(
				'resolved'    => $resolved ? 1 : 0,
				'resolved_at' => $resolved ? current_time( 'mysql' ) : null,
				'resolved_by' => $resolved ? get_current_user_id() : 0,
			),
			array( 'id' => absint( $id ) ),
			array( '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	public function mark_order_resolved( $order_id, $resolved = true ) {
		global $wpdb;
		return $wpdb->update(
			$this->table,
			array(
				'resolved'    => $resolved ? 1 : 0,
				'resolved_at' => $resolved ? current_time( 'mysql' ) : null,
				'resolved_by' => $resolved ? get_current_user_id() : 0,
			),
			array( 'order_id' => absint( $order_id ) ),
			array( '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	public function update_status_by_sid( $sid, $status ) {
		global $wpdb;
		$wpdb->update(
			$this->table,
			array( 'status' => sanitize_text_field( $status ) ),
			array( 'twilio_sid' => sanitize_text_field( $sid ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Resolve order_id for a Twilio SID (call or message).
	 *
	 * @param string $sid Twilio SID.
	 * @return int
	 */
	public function get_order_id_by_sid( $sid ) {
		global $wpdb;
		$sid = sanitize_text_field( (string) $sid );
		if ( $sid === '' ) {
			return 0;
		}

		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT order_id FROM {$this->table} WHERE twilio_sid = %s LIMIT 1",
				$sid
			)
		);

		return absint( $order_id );
	}

	public function phone_is_opted_out( $phone ) {
		global $wpdb;
		$digits = $this->digits_only( $phone );
		if ( strlen( $digits ) < 7 ) {
			return false;
		}

		$last10 = strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->opt_outs_table}
				 WHERE phone_digits = %s
				    OR phone_digits = %s
				    OR RIGHT(phone_digits, 10) = %s
				 LIMIT 1",
				$digits,
				$last10,
				$last10
			)
		);

		return ! empty( $found );
	}

	public function add_opt_out_phone( $phone ) {
		global $wpdb;
		$norm = $this->normalize_phone( $phone );
		if ( $norm === '' ) {
			return false;
		}

		$digits = $this->digits_only( $norm );
		if ( $digits === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional upsert.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->opt_outs_table} WHERE phone_digits = %s OR RIGHT(phone_digits, 10) = %s LIMIT 1",
				$digits,
				strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits
			)
		);

		if ( $existing ) {
			return true;
		}

		return false !== $wpdb->insert(
			$this->opt_outs_table,
			array(
				'phone'        => $norm,
				'phone_digits' => $digits,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	public function remove_opt_out_phone( $phone ) {
		global $wpdb;
		$digits = $this->digits_only( $phone );
		if ( $digits === '' ) {
			return;
		}

		$last10 = strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->opt_outs_table}
				 WHERE phone_digits = %s
				    OR phone_digits = %s
				    OR RIGHT(phone_digits, 10) = %s",
				$digits,
				$last10,
				$last10
			)
		);
	}

	/**
	 * Find the most relevant order for an inbound phone number.
	 * Prefers communications-log matches, then billing-phone SQL (HPOS + CPT),
	 * then a broader recent-orders scan. Matches on last-10 digits.
	 *
	 * @param string $phone Incoming phone.
	 * @return int Order ID or 0.
	 */
	public function find_order_by_phone( $phone ) {
		$digits = $this->digits_only( $phone );
		if ( strlen( $digits ) < 7 ) {
			return 0;
		}

		$tail = substr( $digits, -10 );

		// 1) Prior communications with this phone (covers older orders reliably).
		$from_log = $this->find_order_id_from_communications( $tail );
		if ( $from_log ) {
			return $from_log;
		}

		// 2) Direct billing-phone lookup (HPOS addresses table or postmeta).
		$from_billing = $this->find_order_id_from_billing_phone( $tail );
		if ( $from_billing ) {
			return $from_billing;
		}

		// 3) Broader recent-order scan including custom TOC statuses.
		// Soft fallback only — keep the hydrate count modest (unindexed phone walk).
		$statuses = array( 'processing', 'completed', 'on-hold', 'pending', 'ready-for-pickup', 'shipped' );
		if ( class_exists( 'TOC_Statuses' ) ) {
			$statuses[] = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );
			$statuses[] = TOC_Statuses::bare_status( TOC_Statuses::mapped_shipped_status() );
		}
		$statuses = array_values( array_unique( $statuses ) );

		$scan_limit = (int) apply_filters( 'toc_inbound_phone_scan_limit', 80 );
		if ( $scan_limit < 10 ) {
			$scan_limit = 10;
		}
		if ( $scan_limit > 150 ) {
			$scan_limit = 150;
		}

		$orders = wc_get_orders(
			array(
				'limit'   => $scan_limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => $statuses,
				'return'  => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			$billing = $this->digits_only( $order->get_billing_phone() );
			if ( $billing !== '' && substr( $billing, -10 ) === $tail ) {
				return $order->get_id();
			}
		}

		return 0;
	}

	/**
	 * @param string $tail Last 10 digits.
	 * @return int
	 */
	private function find_order_id_from_communications( $tail ) {
		global $wpdb;

		$like  = $this->phone_like_needle( $tail );
		$limit = $this->inbound_phone_lookup_limit();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, phone FROM {$this->table}
				 WHERE order_id > 0 AND phone LIKE %s
				 ORDER BY created_at DESC
				 LIMIT %d",
				$like,
				$limit
			)
		);

		if ( ! $rows ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$row_digits = $this->digits_only( $row->phone );
			if ( $row_digits !== '' && substr( $row_digits, -10 ) === $tail ) {
				return (int) $row->order_id;
			}
		}

		return 0;
	}

	/**
	 * Query WooCommerce billing phones (HPOS or legacy CPT meta).
	 *
	 * @param string $tail Last 10 digits.
	 * @return int
	 */
	private function find_order_id_from_billing_phone( $tail ) {
		global $wpdb;

		$like  = $this->phone_like_needle( $tail );
		$limit = $this->inbound_phone_lookup_limit();
		$ids   = array();

		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
		) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		if ( $hpos ) {
			$addresses = $wpdb->prefix . 'wc_order_addresses';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_id FROM {$addresses}
					 WHERE address_type = 'billing' AND phone LIKE %s
					 ORDER BY order_id DESC
					 LIMIT %d",
					$like,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_billing_phone' AND meta_value LIKE %s
					 ORDER BY post_id DESC
					 LIMIT %d",
					$like,
					$limit
				)
			);
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}
			$billing = $this->digits_only( $order->get_billing_phone() );
			if ( $billing !== '' && substr( $billing, -10 ) === $tail ) {
				return (int) $order_id;
			}
		}

		return 0;
	}

	/**
	 * LIKE needle for inbound phone SQL.
	 * Prefer the full last-10 digit tail when available — last-4 alone is too broad
	 * on busy stores (unindexed LIKE '%1234' returns many false candidates).
	 *
	 * @param string $tail Last up-to-10 digits of the inbound number.
	 * @return string
	 */
	private function phone_like_needle( $tail ) {
		$tail   = (string) $tail;
		$needle = strlen( $tail ) >= 10 ? $tail : substr( $tail, -4 );
		if ( $needle === '' ) {
			$needle = $tail;
		}
		global $wpdb;
		return '%' . $wpdb->esc_like( $needle );
	}

	/**
	 * Max candidate rows to hydrate when matching inbound SMS to phones.
	 * Bounded because billing-phone / log LIKE queries are unindexed.
	 * Filter: toc_inbound_phone_lookup_limit (clamped 5–100).
	 *
	 * @return int
	 */
	private function inbound_phone_lookup_limit() {
		/**
		 * Max SQL candidate rows for inbound phone → order matching.
		 *
		 * @param int $limit Default 40.
		 */
		$limit = (int) apply_filters( 'toc_inbound_phone_lookup_limit', 40 );
		if ( $limit < 5 ) {
			$limit = 5;
		}
		if ( $limit > 100 ) {
			$limit = 100;
		}
		return $limit;
	}

	/**
	 * Normalize to E.164-ish. Default country code is filterable (US +1 by default).
	 */
	public function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( $phone === '' || $phone === '+' ) {
			return '';
		}

		if ( strpos( $phone, '+' ) === 0 ) {
			return $phone;
		}

		/**
		 * Default country calling code used when a number has no leading +.
		 *
		 * @param string $code Digits only, e.g. '1' for US/CA.
		 */
		$cc = apply_filters( 'toc_default_country_code', '1' );
		$cc = preg_replace( '/\D/', '', (string) $cc );
		if ( $cc === '' ) {
			$cc = '1';
		}

		$digits = ltrim( $phone, '0' );
		if ( strpos( $digits, $cc ) === 0 && strlen( $digits ) >= strlen( $cc ) + 7 ) {
			return '+' . $digits;
		}

		return '+' . $cc . $digits;
	}

	public function digits_only( $phone ) {
		return preg_replace( '/\D/', '', (string) $phone );
	}

	public function get_stats() {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		return array(
			'today_sms'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE type='sms' AND created_at >= %s",
					$today . ' 00:00:00'
				)
			),
			'today_calls' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE type='voice' AND created_at >= %s",
					$today . ' 00:00:00'
				)
			),
			'unresolved'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$this->table} WHERE resolved=0 AND direction='inbound'"
			),
			'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ),
		);
	}

	/**
	 * Orders in the mapped Ready for Pickup status for bulk reminders.
	 *
	 * @param array $args {
	 *     @type int  $days               Lookback window (default 30).
	 *     @type int  $limit              Max orders to return (default 200).
	 *     @type int  $skip_recent_hours  Hide orders reminded within this many hours (0 = show all).
	 *     @type bool $require_phone      Require billing phone (default true).
	 * }
	 * @return WC_Order[]
	 */
	public function get_bulk_pickup_orders( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'days'              => 30,
				'limit'             => 200,
				'skip_recent_hours' => 0,
				'require_phone'     => true,
			)
		);

		$days  = max( 1, min( 365, absint( $args['days'] ) ) );
		$limit = max( 1, min( 500, absint( $args['limit'] ) ) );
		$skip  = max( 0, absint( $args['skip_recent_hours'] ) );

		$date_limit = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$status     = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );

		$orders = wc_get_orders(
			array(
				'limit'        => $limit * 3,
				'status'       => $status,
				'date_created' => '>=' . $date_limit,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$candidates = array();
		$cutoff     = $skip > 0 ? ( time() - ( $skip * HOUR_IN_SECONDS ) ) : 0;

		foreach ( $orders as $order ) {
			if ( (int) get_option( 'toc_ready_require_local_pickup', 0 ) === 1 && ! TOC_Auto::is_local_pickup( $order ) ) {
				continue;
			}
			if ( ! empty( $args['require_phone'] ) && empty( $order->get_billing_phone() ) ) {
				continue;
			}

			if ( $cutoff > 0 ) {
				$last = $order->get_meta( '_toc_last_reminder_at' );
				if ( $last ) {
					$ts = is_numeric( $last ) ? (int) $last : strtotime( (string) $last );
					if ( $ts && $ts > $cutoff ) {
						continue;
					}
				}
			}

			$candidates[] = $order;
			if ( count( $candidates ) >= $limit ) {
				break;
			}
		}

		return $candidates;
	}

	/**
	 * @deprecated 1.2.2 Use get_bulk_pickup_orders().
	 */
	public function get_reminder_candidates( $days = 10 ) {
		return $this->get_bulk_pickup_orders(
			array(
				'days'              => $days,
				'skip_recent_hours' => 48,
				'limit'             => 100,
			)
		);
	}
}
