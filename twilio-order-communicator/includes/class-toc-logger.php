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
			phone_last10 varchar(10) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone_digits (phone_digits),
			KEY phone (phone),
			KEY phone_last10 (phone_last10)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Last 10 digits of a phone (or the whole digit string if shorter). Pure.
	 * Backs the indexed phone_last10 column so opt-out lookups are sargable.
	 *
	 * @param string $phone Phone or digit string.
	 * @return string
	 */
	public static function last10( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : $digits;
	}

	/**
	 * Whether two phone numbers refer to the same subscriber, tolerant of formatting
	 * (spaces, punctuation, country-code prefix). Compares the last-10-digit needle so
	 * "+1 (505) 555-1234" and "5055551234" match. Empty inputs never match. Pure.
	 *
	 * @param string $a First phone.
	 * @param string $b Second phone.
	 * @return bool
	 */
	public static function phones_match( $a, $b ) {
		$na = self::last10( $a );
		$nb = self::last10( $b );
		if ( '' === $na || '' === $nb ) {
			return false;
		}
		return $na === $nb;
	}

	/**
	 * One-time backfill of phone_last10 for rows created before the indexed column existed.
	 * RIGHT() here is a single maintenance pass (not a hot path) run on upgrade.
	 */
	public static function backfill_opt_out_last10() {
		global $wpdb;
		$table = $wpdb->prefix . 'toc_sms_opt_outs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET phone_last10 = RIGHT(phone_digits, 10) WHERE phone_last10 = '' AND phone_digits <> ''" );
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
	 * Full communications row for a Twilio SID (SMS or call).
	 *
	 * @param string $sid Twilio SID.
	 * @return object|null
	 */
	public function get_row_by_sid( $sid ) {
		global $wpdb;
		$sid = sanitize_text_field( (string) $sid );
		if ( $sid === '' ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE twilio_sid = %s LIMIT 1",
				$sid
			)
		);

		return $row ? $row : null;
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

		$last10 = self::last10( $digits );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->opt_outs_table}
				 WHERE phone_digits = %s
				    OR phone_digits = %s
				    OR phone_last10 = %s
				 LIMIT 1",
				$digits,
				$last10,
				$last10
			)
		);

		return ! empty( $found );
	}

	/**
	 * Batch opt-out lookup: given many phones, return the set of opted-out last-10 values in ONE
	 * query (avoids the per-row phone_is_opted_out N+1 on the bulk tab). Membership on last-10 is
	 * equivalent to phone_is_opted_out because phone_last10 subsumes its other match conditions.
	 *
	 * @param array $phones Phone strings.
	 * @return string[] Opted-out last-10 digit strings.
	 */
	public function opted_out_last10_set( $phones ) {
		global $wpdb;
		$keys = array();
		foreach ( (array) $phones as $p ) {
			$l = self::last10( $p );
			if ( strlen( $l ) >= 7 ) {
				$keys[ $l ] = true;
			}
		}
		$keys = array_keys( $keys );
		if ( ! $keys ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are %s only.
		$sql  = "SELECT phone_last10 FROM {$this->opt_outs_table} WHERE phone_last10 IN ($placeholders)";
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? array_values( array_unique( $rows ) ) : array();
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
				"SELECT id FROM {$this->opt_outs_table} WHERE phone_digits = %s OR phone_last10 = %s LIMIT 1",
				$digits,
				self::last10( $digits )
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
				'phone_last10' => self::last10( $digits ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function remove_opt_out_phone( $phone ) {
		global $wpdb;
		$digits = $this->digits_only( $phone );
		if ( $digits === '' ) {
			return;
		}

		$last10 = self::last10( $digits );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->opt_outs_table}
				 WHERE phone_digits = %s
				    OR phone_digits = %s
				    OR phone_last10 = %s",
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
	 * Normalize to E.164. Handles the "+", "00" international prefix and a single
	 * national trunk "0"; the default country code follows the WooCommerce store
	 * base country and is filterable. Returns '' when there is nothing to dial.
	 *
	 * Because this value is also used to build opt-out / consent storage keys, it
	 * must be deterministic: the same input always maps to the same E.164 string.
	 */
	public function normalize_phone( $phone ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( $phone === '' || $phone === '+' ) {
			return '';
		}

		// Already international.
		if ( strpos( $phone, '+' ) === 0 ) {
			return $phone;
		}

		// "00" is the international access prefix in most of the world → same as "+".
		if ( strpos( $phone, '00' ) === 0 ) {
			$rest = ltrim( substr( $phone, 2 ), '0' );
			return '' === $rest ? '' : '+' . $rest;
		}

		$cc = $this->default_country_code();

		// A single leading "0" is the national trunk prefix (e.g. UK 07911 123456):
		// drop exactly the leading zero(s) and prepend the country calling code.
		if ( strpos( $phone, '0' ) === 0 ) {
			$national = ltrim( $phone, '0' );
			return '' === $national ? '' : '+' . $cc . $national;
		}

		// No leading zero. If it already begins with the country code and is long
		// enough to be a full national number, treat it as already qualified.
		if ( strpos( $phone, $cc ) === 0 && strlen( $phone ) >= strlen( $cc ) + 7 ) {
			return '+' . $phone;
		}

		return '+' . $cc . $phone;
	}

	/**
	 * Default country calling code (digits only) used when a number has no leading "+".
	 * Derives from the WooCommerce store base country, overridable via filter.
	 *
	 * @return string Digits only, e.g. '1' for US/CA, '44' for the UK. Never empty.
	 */
	public function default_country_code() {
		$store = (string) get_option( 'woocommerce_default_country', '' );
		$iso   = strtoupper( substr( $store, 0, 2 ) );
		$map   = $this->calling_code_map();
		$cc    = isset( $map[ $iso ] ) ? $map[ $iso ] : '1';

		/**
		 * Default country calling code used when a number has no leading "+".
		 *
		 * @param string $code Digits only, e.g. '1' for US/CA.
		 */
		$cc = apply_filters( 'toc_default_country_code', $cc );
		$cc = preg_replace( '/\D/', '', (string) $cc );
		return '' === $cc ? '1' : $cc;
	}

	/**
	 * ISO 3166-1 alpha-2 → E.164 country calling code (digits only).
	 * Covers common e-commerce markets; extend via the `toc_default_country_code`
	 * filter for anything not listed.
	 *
	 * @return array<string,string>
	 */
	public function calling_code_map() {
		return array(
			'US' => '1',  'CA' => '1',  'PR' => '1',  'DO' => '1',
			'GB' => '44', 'IE' => '353','FR' => '33', 'DE' => '49', 'ES' => '34',
			'IT' => '39', 'NL' => '31', 'BE' => '32', 'LU' => '352','PT' => '351',
			'AT' => '43', 'CH' => '41', 'DK' => '45', 'SE' => '46', 'NO' => '47',
			'FI' => '358','IS' => '354','PL' => '48', 'CZ' => '420','SK' => '421',
			'HU' => '36', 'RO' => '40', 'BG' => '359','GR' => '30', 'HR' => '385',
			'SI' => '386','RS' => '381','EE' => '372','LV' => '371','LT' => '370',
			'UA' => '380','RU' => '7',  'TR' => '90', 'IL' => '972','SA' => '966',
			'AE' => '971','QA' => '974','KW' => '965','BH' => '973','OM' => '968',
			'JO' => '962','LB' => '961','EG' => '20', 'MA' => '212','DZ' => '213',
			'TN' => '216','ZA' => '27', 'NG' => '234','KE' => '254','GH' => '233',
			'AU' => '61', 'NZ' => '64', 'JP' => '81', 'KR' => '82', 'CN' => '86',
			'HK' => '852','TW' => '886','SG' => '65', 'MY' => '60', 'TH' => '66',
			'ID' => '62', 'PH' => '63', 'VN' => '84', 'IN' => '91', 'PK' => '92',
			'BD' => '880','LK' => '94', 'MX' => '52', 'BR' => '55', 'AR' => '54',
			'CL' => '56', 'CO' => '57', 'PE' => '51', 'VE' => '58', 'EC' => '593',
			'UY' => '598','PY' => '595','BO' => '591','CR' => '506','PA' => '507',
			'GT' => '502',
		);
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
	 * Aggregate SMS delivery outcomes + inbound replies over a window, for the analytics card.
	 * Delivery statuses come from Twilio StatusCallbacks recorded via update_status_by_sid().
	 *
	 * @param int $days Lookback in days (0 = all time; otherwise clamped 1–365).
	 * @return array{sent:int,delivered:int,failed:int,replies:int,window_days:int}
	 */
	public function delivery_stats( $days = 30 ) {
		global $wpdb;

		$days_raw = absint( $days );
		$all_time = ( 0 === $days_raw );
		$days     = $all_time ? 0 : max( 1, min( 365, $days_raw ) );

		// Single pass with conditional sums. created_at is stored in store-local time (see
		// get_stats), so bound the window with a store-local timestamp too.
		$sql = "SELECT
			SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN direction='outbound' AND status='delivered' THEN 1 ELSE 0 END) AS delivered,
			SUM(CASE WHEN direction='outbound' AND status IN ('failed','undelivered') THEN 1 ELSE 0 END) AS failed,
			SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) AS replies
			FROM {$this->table} WHERE type='sms'";

		if ( $all_time ) {
			$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$since = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$row   = $wpdb->get_row( $wpdb->prepare( $sql . ' AND created_at >= %s', $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$row = is_array( $row ) ? $row : array();
		return array(
			'sent'        => isset( $row['sent'] ) ? (int) $row['sent'] : 0,
			'delivered'   => isset( $row['delivered'] ) ? (int) $row['delivered'] : 0,
			'failed'      => isset( $row['failed'] ) ? (int) $row['failed'] : 0,
			'replies'     => isset( $row['replies'] ) ? (int) $row['replies'] : 0,
			'window_days' => $days,
		);
	}

	/**
	 * Delivered / failed / reply rates (percent, 1 dp) from a delivery_stats() count array (pure).
	 * Rates are relative to outbound SMS sent; a zero denominator yields 0.0 (no division).
	 *
	 * @param array $counts Count array with sent/delivered/failed/replies keys.
	 * @return array{delivered_rate:float,failed_rate:float,reply_rate:float}
	 */
	public static function compute_rates( $counts ) {
		$sent      = isset( $counts['sent'] ) ? (int) $counts['sent'] : 0;
		$delivered = isset( $counts['delivered'] ) ? (int) $counts['delivered'] : 0;
		$failed    = isset( $counts['failed'] ) ? (int) $counts['failed'] : 0;
		$replies   = isset( $counts['replies'] ) ? (int) $counts['replies'] : 0;

		$pct = function ( $num, $den ) {
			return $den > 0 ? round( $num / $den * 100, 1 ) : 0.0;
		};

		return array(
			'delivered_rate' => $pct( $delivered, $sent ),
			'failed_rate'    => $pct( $failed, $sent ),
			'reply_rate'     => $pct( $replies, $sent ),
		);
	}

	/**
	 * Orders in the mapped Ready for Pickup status for bulk reminders.
	 *
	 * @param array $args {
	 *     @type int  $days               Lookback by date_modified (default 30). 0 = all time.
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

		// days=0 → all time (no date window). Otherwise clamp 1–365.
		$days_raw = absint( $args['days'] );
		$all_time = ( 0 === $days_raw );
		$days     = $all_time ? 0 : max( 1, min( 365, $days_raw ) );
		$limit    = max( 1, min( 500, absint( $args['limit'] ) ) );
		$skip     = max( 0, absint( $args['skip_recent_hours'] ) );

		$status = TOC_Statuses::bare_status( TOC_Statuses::mapped_ready_status() );

		// Prefer date_modified so older orders recently moved to Ready still appear.
		$query = array(
			'limit'   => $limit * 3,
			'status'  => $status,
			'orderby' => 'modified',
			'order'   => 'DESC',
		);
		if ( ! $all_time ) {
			$date_limit              = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$query['date_modified']  = '>=' . $date_limit;
		}

		$orders = wc_get_orders( $query );

		$candidates = array();
		$cutoff     = $skip > 0 ? ( time() - ( $skip * HOUR_IN_SECONDS ) ) : 0;

		foreach ( $orders as $order ) {
			if ( class_exists( 'TOC_Order_Meta' ) && TOC_Order_Meta::is_collected( $order ) ) {
				continue;
			}
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
