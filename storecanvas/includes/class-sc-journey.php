<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer journey / click debugging (0.5.0).
 * Logs product customizer events so you can see where flows fail.
 */
class SC_Journey {

	const TABLE_SUFFIX = 'sc_journey';
	const OPTION_ENABLED = 'sc_journey_enabled';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_sc_journey_log', array( $this, 'ajax_log' ) );
		add_action( 'wp_ajax_nopriv_sc_journey_log', array( $this, 'ajax_log' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'localize' ), 30 );
	}

	public function maybe_create_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			return;
		}
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			session_key varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(64) NOT NULL DEFAULT '',
			detail longtext NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY product_id (product_id),
			KEY event (event)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION_ENABLED, '1' );
	}

	public function localize() {
		if ( ! is_product() ) {
			return;
		}
		wp_localize_script(
			'sc-customizer',
			'scJourney',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'enabled' => get_option( self::OPTION_ENABLED, '1' ) === '1',
				'nonce'   => wp_create_nonce( 'sc_journey' ),
			)
		);
	}

	public function ajax_log() {
		if ( get_option( self::OPTION_ENABLED, '1' ) !== '1' ) {
			wp_send_json_success( array( 'skipped' => true ) );
		}
		check_ajax_referer( 'sc_journey', 'nonce' );
		$event   = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$detail  = isset( $_POST['detail'] ) ? sanitize_text_field( wp_unslash( $_POST['detail'] ) ) : '';
		$product = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $event ) {
			wp_send_json_error( array( 'message' => 'no event' ), 400 );
		}
		$session = '';
		if ( ! session_id() && ! headers_sent() ) {
			@session_start();
		}
		if ( session_id() ) {
			$session = substr( session_id(), 0, 64 );
		} else {
			$session = isset( $_COOKIE['sc_sid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['sc_sid'] ) ) : wp_generate_password( 16, false );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_SUFFIX,
			array(
				'created_at'  => current_time( 'mysql' ),
				'session_key' => $session,
				'user_id'     => get_current_user_id(),
				'product_id'  => $product,
				'event'       => $event,
				'detail'      => $detail,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		wp_send_json_success( array( 'id' => $wpdb->insert_id ) );
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'StoreCanvas Journey', 'storecanvas' ),
			__( 'SC Journey', 'storecanvas' ),
			'manage_woocommerce',
			'sc-journey',
			array( $this, 'render_admin' )
		);
	}

	public function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore
		echo '<div class="wrap"><h1>' . esc_html__( 'StoreCanvas customer journey', 'storecanvas' ) . '</h1>';
		echo '<p>' . esc_html__( 'Recent customizer events (upload, drag, add-to-cart, errors). Use this to see where customers drop off.', 'storecanvas' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>Time</th><th>Product</th><th>Event</th><th>Detail</th><th>User</th><th>Session</th>';
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No events yet.', 'storecanvas' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['id'] ) . '</td>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['product_id'] ) . '</td>';
			echo '<td><code>' . esc_html( $r['event'] ) . '</code></td>';
			echo '<td>' . esc_html( $r['detail'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['user_id'] ) . '</td>';
			echo '<td><code>' . esc_html( substr( $r['session_key'], 0, 12 ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
