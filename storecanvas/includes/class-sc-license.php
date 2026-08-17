<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-party license client + updater for StoreCanvas.
 * Features stay available without a key; only premium updates are gated.
 */
class SC_License {

	const CRON_HOOK      = 'sc_license_validate_cron';
	const GRACE_SECONDS  = 14 * DAY_IN_SECONDS;
	const CHECK_EVERY    = DAY_IN_SECONDS;
	const DEFAULT_SERVER = 'https://licenses.alextechgamer.com';
	const ITEM_SLUG      = 'storecanvas';

	const STATUS_INACTIVE = 'inactive';
	const STATUS_ACTIVE   = 'active';
	const STATUS_EXPIRED  = 'expired';
	const STATUS_INVALID  = 'invalid';
	const STATUS_ERROR    = 'error';
	const STATUS_TRIAL    = 'trial';
	const TRIAL_DAYS      = 30;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'cron_validate' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );
		add_action( 'admin_init', array( $this, 'maybe_start_trial' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'wp_ajax_sc_license_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_sc_license_deactivate', array( $this, 'ajax_deactivate' ) );
		add_action( 'wp_ajax_sc_license_refresh', array( $this, 'ajax_refresh' ) );
		add_action( 'wp_ajax_sc_license_save_server', array( $this, 'ajax_save_server' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	public function server_url() {
		if ( defined( 'SC_LICENSE_SERVER_URL' ) && SC_LICENSE_SERVER_URL ) {
			return untrailingslashit( (string) SC_LICENSE_SERVER_URL );
		}
		if ( defined( 'TOC_LICENSE_SERVER_URL' ) && TOC_LICENSE_SERVER_URL ) {
			return untrailingslashit( (string) TOC_LICENSE_SERVER_URL );
		}
		foreach ( array( 'sc_license_server_url', 'toc_license_server_url' ) as $opt ) {
			$url = (string) get_option( $opt, '' );
			if ( $url !== '' ) {
				return untrailingslashit( $url );
			}
		}
		return self::DEFAULT_SERVER;
	}

	public function item_slug() {
		if ( defined( 'SC_LICENSE_ITEM_SLUG' ) && SC_LICENSE_ITEM_SLUG ) {
			return (string) SC_LICENSE_ITEM_SLUG;
		}
		return self::ITEM_SLUG;
	}

	public function instance_id() {
		$id = (string) get_option( 'atg_site_instance_id', '' );
		if ( $id === '' ) {
			$id = (string) get_option( 'toc_license_instance_id', '' );
		}
		if ( $id === '' ) {
			$id = wp_generate_password( 32, false, false );
		}
		if ( (string) get_option( 'atg_site_instance_id', '' ) !== $id ) {
			update_option( 'atg_site_instance_id', $id, false );
		}
		return $id;
	}

	public function get_key() {
		return (string) get_option( 'sc_license_key', '' );
	}

	public function get_status() {
		$status  = (string) get_option( 'sc_license_status', self::STATUS_INACTIVE );
		$allowed = array( self::STATUS_INACTIVE, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_INVALID, self::STATUS_ERROR );
		return in_array( $status, $allowed, true ) ? $status : self::STATUS_INACTIVE;
	}

	public function get_data() {
		$raw = get_option( 'sc_license_data', array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	public function last_check() {
		return (int) get_option( 'sc_license_last_check', 0 );
	}

	public static function trial_length_days() {
		if ( defined( 'SC_TRIAL_DAYS' ) ) {
			return max( 0, (int) SC_TRIAL_DAYS );
		}
		return self::TRIAL_DAYS;
	}

	public static function trial_days_remaining( $started_at, $now, $days = 30 ) {
		$started_at = (int) $started_at;
		$now        = (int) $now;
		$days       = max( 0, (int) $days );
		if ( $days === 0 || $started_at <= 0 ) {
			return 0;
		}
		$ends = $started_at + ( $days * 86400 );
		return max( 0, (int) ceil( ( $ends - $now ) / 86400 ) );
	}

	public function trial_started_at() {
		return (int) get_option( 'sc_trial_started_at', 0 );
	}

	public function maybe_start_trial() {
		if ( $this->trial_started_at() > 0 ) {
			return;
		}
		if ( $this->get_key() !== '' ) {
			return;
		}
		if ( self::trial_length_days() <= 0 ) {
			return;
		}
		if ( ! is_admin() || ! $this->can_manage() ) {
			return;
		}
		update_option( 'sc_trial_started_at', time(), false );
	}

	public function is_on_trial() {
		if ( $this->get_key() !== '' ) {
			return false;
		}
		return self::trial_days_remaining( $this->trial_started_at(), time(), self::trial_length_days() ) > 0;
	}

	public function allows_updates() {
		if ( $this->server_url() === '' ) {
			return false;
		}
		if ( $this->is_on_trial() ) {
			return true;
		}
		$status = $this->get_status();
		if ( $status === self::STATUS_ACTIVE ) {
			return true;
		}
		if ( $status === self::STATUS_ERROR ) {
			$data = $this->get_data();
			if ( ! empty( $data['was_active'] ) ) {
				$last = $this->last_check();
				if ( $last && ( time() - $last ) < self::GRACE_SECONDS ) {
					return true;
				}
			}
		}
		return false;
	}

	public function is_configured() {
		return $this->server_url() !== '';
	}

	public function server_is_locked() {
		return ( defined( 'SC_LICENSE_SERVER_URL' ) && SC_LICENSE_SERVER_URL )
			|| ( defined( 'TOC_LICENSE_SERVER_URL' ) && TOC_LICENSE_SERVER_URL );
	}

	public function mask_key( $key = null ) {
		$key = $key === null ? $this->get_key() : (string) $key;
		$key = trim( $key );
		if ( $key === '' ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', max( 4, $len - 8 ) ) . substr( $key, -4 );
	}

	public function maybe_schedule_cron() {
		if ( ! $this->is_configured() || $this->get_key() === '' ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::CRON_HOOK, array(), 'sc' ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, self::CHECK_EVERY, self::CRON_HOOK, array(), 'sc' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, null, 'sc' );
		}
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::CRON_HOOK );
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function cron_validate() {
		if ( ! $this->is_configured() || $this->get_key() === '' ) {
			return;
		}
		$this->validate( false );
	}

	public function activate( $key ) {
		$key = strtoupper( trim( (string) $key ) );
		if ( $key === '' ) {
			return array( 'success' => false, 'status' => self::STATUS_INVALID, 'message' => __( 'Enter a license key.', 'storecanvas' ) );
		}
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'status' => self::STATUS_ERROR, 'message' => __( 'License server URL is not configured.', 'storecanvas' ) );
		}

		$result = $this->request(
			'POST',
			'/v1/activate',
			array(
				'license_key'    => $key,
				'site_url'       => home_url( '/' ),
				'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'instance_id'    => $this->instance_id(),
				'plugin_version' => SC_VERSION,
				'item_slug'      => $this->item_slug(),
			)
		);

		if ( empty( $result['success'] ) ) {
			$status = $this->map_remote_status( $result );
			$this->persist( $key, $status, $result['data'] ?? array(), false );
			return array(
				'success' => false,
				'status'  => $status,
				'message' => $result['error'] ?? __( 'Activation failed.', 'storecanvas' ),
				'data'    => $result['data'] ?? array(),
			);
		}

		$data               = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$data['was_active'] = true;
		$this->persist( $key, self::STATUS_ACTIVE, $data, true );
		return array(
			'success' => true,
			'status'  => self::STATUS_ACTIVE,
			'message' => $result['message'] ?? __( 'License activated.', 'storecanvas' ),
			'data'    => $data,
		);
	}

	public function deactivate( $clear_key = false ) {
		$key = $this->get_key();
		if ( $key !== '' && $this->is_configured() ) {
			$this->request(
				'POST',
				'/v1/deactivate',
				array(
					'license_key' => $key,
					'site_url'    => home_url( '/' ),
					'instance_id' => $this->instance_id(),
				)
			);
		}

		if ( $clear_key ) {
			delete_option( 'sc_license_key' );
		}
		update_option( 'sc_license_status', self::STATUS_INACTIVE, false );
		$data = $this->get_data();
		unset( $data['last_payload'] );
		$data['was_active'] = false;
		update_option( 'sc_license_data', $data, false );
		update_option( 'sc_license_last_check', time(), false );
		self::flush_update_cache( array( $key ) );

		return array(
			'success' => true,
			'status'  => self::STATUS_INACTIVE,
			'message' => __( 'License deactivated for this site.', 'storecanvas' ),
		);
	}

	public function validate( $hard_on_invalid = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$key = $this->get_key();
		if ( $key === '' ) {
			update_option( 'sc_license_status', self::STATUS_INACTIVE, false );
			return array( 'success' => false, 'status' => self::STATUS_INACTIVE, 'message' => __( 'No license key saved.', 'storecanvas' ) );
		}
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'status' => self::STATUS_ERROR, 'message' => __( 'License server URL is not configured.', 'storecanvas' ) );
		}

		$result = $this->request(
			'POST',
			'/v1/validate',
			array(
				'license_key'    => $key,
				'site_url'       => home_url( '/' ),
				'instance_id'    => $this->instance_id(),
				'plugin_version' => SC_VERSION,
				'item_slug'      => $this->item_slug(),
			)
		);

		if ( ! empty( $result['network_error'] ) ) {
			$data = $this->get_data();
			if ( $this->get_status() === self::STATUS_ACTIVE ) {
				$data['was_active'] = true;
			}
			update_option( 'sc_license_data', $data, false );
			update_option( 'sc_license_status', self::STATUS_ERROR, false );
			update_option( 'sc_license_last_check', time(), false );
			return array(
				'success' => false,
				'status'  => self::STATUS_ERROR,
				'message' => $result['error'] ?? __( 'Could not reach license server.', 'storecanvas' ),
			);
		}

		if ( empty( $result['success'] ) ) {
			$status             = $this->map_remote_status( $result );
			$data               = is_array( $result['data'] ?? null ) ? $result['data'] : $this->get_data();
			$data['was_active'] = false;
			$this->persist( $key, $status, $data, false );
			return array(
				'success' => false,
				'status'  => $status,
				'message' => $result['error'] ?? __( 'License is not valid.', 'storecanvas' ),
				'data'    => $data,
			);
		}

		$data               = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$data['was_active'] = true;
		$this->persist( $key, self::STATUS_ACTIVE, $data, true );
		return array(
			'success' => true,
			'status'  => self::STATUS_ACTIVE,
			'message' => __( 'License is active.', 'storecanvas' ),
			'data'    => $data,
		);
	}

	public function request( $method, $path, $body = array(), $headers = array() ) {
		$base = $this->server_url();
		if ( $base === '' ) {
			return array( 'success' => false, 'error' => 'License server not configured.', 'network_error' => true );
		}

		$url  = $base . $path;
		$args = array(
			'method'    => strtoupper( $method ),
			'timeout'   => 20,
			'headers'   => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
			'sslverify' => true,
		);

		if ( strtoupper( $method ) === 'POST' ) {
			$args['body'] = wp_json_encode( $body );
		} elseif ( ! empty( $body ) && strtoupper( $method ) === 'GET' ) {
			$url = add_query_arg( $body, $url );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'success'       => false,
				'error'         => $response->get_error_message(),
				'network_error' => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code >= 200 && $code < 300 && ! empty( $data['success'] ) ) {
			return $data;
		}

		return array(
			'success' => false,
			'status'  => $data['status'] ?? '',
			'error'   => $data['error'] ?? ( 'HTTP ' . $code ),
			'data'    => $data['data'] ?? array(),
			'code'    => $code,
		);
	}

	private function persist( $key, $status, $data, $was_active_flag ) {
		$previous_key = (string) get_option( 'sc_license_key', '' );
		update_option( 'sc_license_key', (string) $key, false );
		update_option( 'sc_license_status', $status, false );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		unset( $data['last_payload'] );
		$data['was_active']   = (bool) $was_active_flag;
		$data['last_payload'] = array(
			'status'      => (string) $status,
			'expires_at'  => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
			'max_sites'   => isset( $data['max_sites'] ) ? (int) $data['max_sites'] : 0,
			'activations' => isset( $data['activations'] ) ? (int) $data['activations'] : 0,
			'site_url'    => isset( $data['site_url'] ) ? (string) $data['site_url'] : '',
			'saved_at'    => time(),
		);
		update_option( 'sc_license_data', $data, false );
		update_option( 'sc_license_last_check', time(), false );
		if ( $this->allows_updates() ) {
			self::flush_update_cache( array( $previous_key, (string) $key ) );
		}
	}

	private function map_remote_status( $result ) {
		$remote = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( $remote === self::STATUS_EXPIRED || $remote === 'expired' ) {
			return self::STATUS_EXPIRED;
		}
		if ( in_array( $remote, array( self::STATUS_INVALID, 'invalid', 'disabled', 'limit', 'inactive' ), true ) ) {
			return self::STATUS_INVALID;
		}
		if ( ! empty( $result['network_error'] ) ) {
			return self::STATUS_ERROR;
		}
		return self::STATUS_INVALID;
	}

	private function can_manage() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public function ajax_activate() {
		check_ajax_referer( 'sc_license', 'nonce' );
		if ( ! $this->can_manage() ) {
			wp_send_json_error( __( 'Permission denied', 'storecanvas' ) );
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( $key === '' || strpos( $key, '•' ) !== false ) {
			$key = $this->get_key();
		}
		$result = $this->activate( $key );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $this->ui_state( $result['message'] ) );
	}

	public function ajax_deactivate() {
		check_ajax_referer( 'sc_license', 'nonce' );
		if ( ! $this->can_manage() ) {
			wp_send_json_error( __( 'Permission denied', 'storecanvas' ) );
		}
		$result = $this->deactivate( ! empty( $_POST['clear_key'] ) );
		wp_send_json_success( $this->ui_state( $result['message'] ) );
	}

	public function ajax_refresh() {
		check_ajax_referer( 'sc_license', 'nonce' );
		if ( ! $this->can_manage() ) {
			wp_send_json_error( __( 'Permission denied', 'storecanvas' ) );
		}
		$result = $this->validate( true );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array_merge( $this->ui_state( $result['message'] ), array( 'message' => $result['message'] ) ) );
		}
		wp_send_json_success( $this->ui_state( $result['message'] ) );
	}

	public function ajax_save_server() {
		check_ajax_referer( 'sc_license', 'nonce' );
		if ( ! $this->can_manage() ) {
			wp_send_json_error( __( 'Permission denied', 'storecanvas' ) );
		}
		if ( $this->server_is_locked() ) {
			wp_send_json_error( __( 'Server URL is locked by a wp-config constant.', 'storecanvas' ) );
		}
		$url = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '';
		$url = $url ? untrailingslashit( $url ) : '';
		update_option( 'sc_license_server_url', $url, false );
		wp_send_json_success(
			array(
				'message'           => __( 'License server URL saved.', 'storecanvas' ),
				'server_configured' => $this->is_configured(),
			)
		);
	}

	public function ui_state( $message = '' ) {
		$data      = $this->get_data();
		$status    = $this->get_status();
		$last      = $this->last_check();
		$on_trial  = $this->is_on_trial();
		$days_left = self::trial_days_remaining( $this->trial_started_at(), time(), self::trial_length_days() );
		if ( $on_trial ) {
			$status = self::STATUS_TRIAL;
		}
		$ends_label = '';
		$started    = $this->trial_started_at();
		if ( $started > 0 ) {
			$ends_label = function_exists( 'wp_date' ) ? wp_date( 'M j, Y', $started + ( self::trial_length_days() * DAY_IN_SECONDS ) ) : gmdate( 'M j, Y', $started + ( self::trial_length_days() * 86400 ) );
		}
		return array(
			'message'           => $message,
			'status'            => $status,
			'status_label'      => $this->status_label( $status, $days_left ),
			'masked_key'        => $this->mask_key(),
			'has_key'           => $this->get_key() !== '',
			'server_configured' => $this->is_configured(),
			'allows_updates'    => $this->allows_updates(),
			'on_trial'          => $on_trial,
			'trial_days_left'   => $days_left,
			'trial_ends_at'     => $ends_label,
			'site_url'          => isset( $data['site_url'] ) ? (string) $data['site_url'] : home_url( '/' ),
			'activations'       => isset( $data['activations'] ) ? (int) $data['activations'] : null,
			'max_sites'         => isset( $data['max_sites'] ) ? (int) $data['max_sites'] : null,
			'expires_at'        => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
			'customer_email'    => isset( $data['customer_email'] ) ? (string) $data['customer_email'] : '',
			'last_check'        => $last ? wp_date( 'M j, Y g:i a', $last ) : '',
			'instance_id'       => $this->instance_id(),
		);
	}

	public function status_label( $status, $trial_days_left = 0 ) {
		if ( $status === self::STATUS_TRIAL ) {
			return sprintf(
				/* translators: %d: days remaining */
				_n( 'Trial — %d day left', 'Trial — %d days left', (int) $trial_days_left, 'storecanvas' ),
				(int) $trial_days_left
			);
		}
		$map = array(
			self::STATUS_INACTIVE => __( 'Inactive', 'storecanvas' ),
			self::STATUS_ACTIVE   => __( 'Active', 'storecanvas' ),
			self::STATUS_EXPIRED  => __( 'Expired', 'storecanvas' ),
			self::STATUS_INVALID  => __( 'Invalid', 'storecanvas' ),
			self::STATUS_ERROR    => __( 'Error / unreachable', 'storecanvas' ),
		);
		return $map[ $status ] ?? $status;
	}

	public function admin_notice() {
		if ( ! $this->can_manage() ) {
			return;
		}
		$url = admin_url( 'admin.php?page=sc-license' );
		if ( $this->is_on_trial() ) {
			$days = self::trial_days_remaining( $this->trial_started_at(), time(), self::trial_length_days() );
			if ( $days > 7 ) {
				return;
			}
			echo '<div class="notice notice-info is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: days remaining */
					_n( 'StoreCanvas trial: %d day left. Enter a license key to keep premium updates after the trial.', 'StoreCanvas trial: %d days left. Enter a license key to keep premium updates after the trial.', $days, 'storecanvas' ),
					$days
				)
			);
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage license', 'storecanvas' ) . '</a>';
			echo '</p></div>';
			return;
		}
		if ( $this->get_key() === '' && $this->trial_started_at() > 0 ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Your StoreCanvas trial has ended. The plugin still works. Enter a license key to receive premium updates.', 'storecanvas' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Enter license key', 'storecanvas' ) . '</a>';
			echo '</p></div>';
			return;
		}
		$status = $this->get_status();
		if ( ! in_array( $status, array( self::STATUS_EXPIRED, self::STATUS_INVALID ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: license status */
				__( 'StoreCanvas license status: %s. The plugin still works; premium updates are paused until the license is active.', 'storecanvas' ),
				$this->status_label( $status )
			)
		);
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage license', 'storecanvas' ) . '</a>';
		echo '</p></div>';
	}

	public function render_page() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'Forbidden', 'storecanvas' ) );
		}
		$state  = $this->ui_state();
		$status = $state['status'];
		echo '<div class="wrap sc-dash sc-license-wrap">';
		echo '<div class="sc-dash-hero sc-license-hero">';
		echo '<div><p class="sc-dash-kicker">' . esc_html__( 'Account', 'storecanvas' ) . '</p>';
		echo '<h1>' . esc_html__( 'License', 'storecanvas' ) . '</h1>';
		echo '<p class="sc-dash-lead">' . esc_html__( 'New installs include a 30-day trial. After that, a license key unlocks premium updates. StoreCanvas itself keeps working either way.', 'storecanvas' ) . '</p></div></div>';

		echo '<div class="sc-license-card" id="sc-license-panel" data-status="' . esc_attr( $status ) . '">';
		echo '<p><span class="toc-license-status toc-license-status--' . esc_attr( $status ) . '" id="sc-license-status-label">' . esc_html( $state['status_label'] ) . '</span> ';
		echo '<span class="description" id="sc-license-updates-note"> — ' . esc_html( $state['allows_updates'] ? __( 'premium updates enabled', 'storecanvas' ) : __( 'premium updates paused', 'storecanvas' ) ) . '</span></p>';

		echo '<p><label for="sc-license-key"><strong>' . esc_html__( 'License key', 'storecanvas' ) . '</strong></label><br />';
		echo '<input type="text" id="sc-license-key" class="regular-text" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( $state['has_key'] ? $state['masked_key'] : 'XXXX-XXXX-XXXX-XXXX' ) . '" value="" /></p>';

		if ( ! $this->server_is_locked() ) {
			echo '<p><label for="sc-license-server"><strong>' . esc_html__( 'License server URL', 'storecanvas' ) . '</strong></label><br />';
			echo '<input type="url" id="sc-license-server" class="regular-text" value="' . esc_attr( get_option( 'sc_license_server_url', $this->server_url() ) ) . '" placeholder="https://licenses.example.com" /> ';
			echo '<button type="button" class="button" id="sc-license-save-server">' . esc_html__( 'Save server URL', 'storecanvas' ) . '</button></p>';
		} else {
			echo '<p>' . esc_html__( 'License server', 'storecanvas' ) . ': <code>' . esc_html( $this->server_url() ) . '</code></p>';
		}

		echo '<ul class="toc-license-meta" id="sc-license-meta">';
		echo '<li>' . esc_html__( 'Licensed site:', 'storecanvas' ) . ' <strong id="sc-lic-site">' . esc_html( $state['site_url'] ?: '—' ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Activations:', 'storecanvas' ) . ' <strong id="sc-lic-acts">';
		if ( $state['activations'] !== null && $state['max_sites'] !== null ) {
			echo esc_html( $state['activations'] . ' / ' . $state['max_sites'] );
		} else {
			echo '—';
		}
		echo '</strong></li>';
		echo '<li>' . esc_html__( 'Expires:', 'storecanvas' ) . ' <strong id="sc-lic-exp">' . esc_html( $state['expires_at'] ? $state['expires_at'] : __( 'Lifetime / none set', 'storecanvas' ) ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Customer:', 'storecanvas' ) . ' <strong id="sc-lic-email">' . esc_html( $state['customer_email'] !== '' ? $state['customer_email'] : '—' ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Last check:', 'storecanvas' ) . ' <strong id="sc-lic-check">' . esc_html( $state['last_check'] !== '' ? $state['last_check'] : '—' ) . '</strong></li>';
		if ( ! empty( $state['on_trial'] ) || ( ! empty( $state['trial_ends_at'] ) && empty( $state['has_key'] ) ) ) {
			echo '<li>' . esc_html__( 'Trial ends:', 'storecanvas' ) . ' <strong>' . esc_html( $state['trial_ends_at'] !== '' ? $state['trial_ends_at'] : '—' ) . '</strong></li>';
		}
		echo '<li>' . esc_html__( 'Instance ID:', 'storecanvas' ) . ' <code id="sc-lic-instance">' . esc_html( $state['instance_id'] ) . '</code></li>';
		echo '</ul>';

		echo '<p class="toc-license-actions">';
		echo '<button type="button" class="button button-primary" id="sc-license-activate">' . esc_html__( 'Activate', 'storecanvas' ) . '</button> ';
		echo '<button type="button" class="button" id="sc-license-refresh">' . esc_html__( 'Re-check', 'storecanvas' ) . '</button> ';
		echo '<button type="button" class="button" id="sc-license-deactivate">' . esc_html__( 'Deactivate', 'storecanvas' ) . '</button> ';
		echo '<label style="margin-left:12px;"><input type="checkbox" id="sc-license-clear-key" value="1" /> ' . esc_html__( 'Also clear saved key on deactivate', 'storecanvas' ) . '</label>';
		echo ' <span id="sc-license-msg" style="margin-left:12px;"></span></p>';
		echo '</div></div>';
	}

	private static function cache_key( $key ) {
		return 'sc_update_check_' . md5( (string) $key . SC_VERSION );
	}

	public static function flush_update_cache( $keys = array() ) {
		$keys   = array_map( 'strval', (array) $keys );
		$keys[] = (string) get_option( 'sc_license_key', '' );
		foreach ( array_unique( array_filter( $keys ) ) as $key ) {
			delete_site_transient( self::cache_key( $key ) );
		}
		delete_site_transient( 'update_plugins' );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! $this->is_configured() || ! $this->allows_updates() ) {
			return $transient;
		}
		$info = $this->fetch_update_info();
		if ( ! $info || empty( $info->new_version ) || version_compare( SC_VERSION, $info->new_version, '>=' ) ) {
			return $transient;
		}
		$transient->response[ SC_PLUGIN_BASENAME ] = $info;
		unset( $transient->no_update[ SC_PLUGIN_BASENAME ] );
		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		$slug = isset( $args->slug ) ? (string) $args->slug : '';
		if ( $slug !== 'storecanvas' && $slug !== dirname( SC_PLUGIN_BASENAME ) ) {
			return $result;
		}
		if ( ! $this->is_configured() || ! $this->allows_updates() ) {
			return $result;
		}
		$info = $this->fetch_update_info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'StoreCanvas',
			'slug'          => 'storecanvas',
			'version'       => $info->new_version,
			'author'        => '<a href="https://github.com/Alextechgamer">Alextechgamer</a>',
			'homepage'      => 'https://github.com/Alextechgamer/Twilio-order-communicator-',
			'requires'      => $info->requires ?? '6.0',
			'requires_php'  => $info->requires_php ?? '7.4',
			'download_link' => $info->package ?? '',
			'sections'      => array(
				'description' => 'Self-hosted WooCommerce product designer.',
				'changelog'   => isset( $info->sections['changelog'] ) ? $info->sections['changelog'] : '',
			),
		);
	}

	private function fetch_update_info() {
		$key = $this->get_key();
		if ( $key === '' ) {
			return null;
		}
		$cache_key = self::cache_key( $key );
		$cached    = get_site_transient( $cache_key );
		if ( is_object( $cached ) ) {
			return ! empty( $cached->new_version ) && $cached->new_version !== SC_VERSION ? $cached : null;
		}

		$result = $this->request(
			'GET',
			'/v1/update-check',
			array(
				'slug'        => $this->item_slug(),
				'version'     => SC_VERSION,
				'site_url'    => home_url( '/' ),
				'instance_id' => $this->instance_id(),
			),
			array( 'X-TOC-License' => $key )
		);

		if ( empty( $result['success'] ) || empty( $result['update'] ) || empty( $result['data']['version'] ) ) {
			set_site_transient( $cache_key, (object) array( 'new_version' => SC_VERSION ), 6 * HOUR_IN_SECONDS );
			return null;
		}

		$data = $result['data'];
		$info = (object) array(
			'id'           => 'storecanvas',
			'slug'         => 'storecanvas',
			'plugin'       => SC_PLUGIN_BASENAME,
			'new_version'  => (string) $data['version'],
			'url'          => 'https://github.com/Alextechgamer/Twilio-order-communicator-',
			'package'      => (string) ( $data['package_url'] ?? '' ),
			'requires'     => (string) ( $data['required_wp'] ?? '6.0' ),
			'requires_php' => (string) ( $data['required_php'] ?? '7.4' ),
			'tested'       => '',
			'sections'     => array(
				'changelog' => wp_kses_post( nl2br( (string) ( $data['changelog'] ?? '' ) ) ),
			),
		);
		set_site_transient( $cache_key, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}
}
