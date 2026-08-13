<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom license client — activation, validation, storage.
 * Never blocks core SMS/voice/chat; only gates premium updates via TOC_Updater.
 */
class TOC_License {

	const CRON_HOOK     = 'toc_license_validate_cron';
	const GRACE_SECONDS = 14 * DAY_IN_SECONDS;
	const CHECK_EVERY   = DAY_IN_SECONDS;

	const STATUS_INACTIVE = 'inactive';
	const STATUS_ACTIVE   = 'active';
	const STATUS_EXPIRED  = 'expired';
	const STATUS_INVALID  = 'invalid';
	const STATUS_ERROR    = 'error';

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
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'wp_ajax_toc_license_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_toc_license_deactivate', array( $this, 'ajax_deactivate' ) );
		add_action( 'wp_ajax_toc_license_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * License API base URL (no trailing slash). Empty = licensing disabled.
	 *
	 * @return string
	 */
	public function server_url() {
		if ( defined( 'TOC_LICENSE_SERVER_URL' ) && TOC_LICENSE_SERVER_URL ) {
			return untrailingslashit( (string) TOC_LICENSE_SERVER_URL );
		}
		$url = (string) get_option( 'toc_license_server_url', '' );
		if ( $url !== '' ) {
			return untrailingslashit( $url );
		}
		return 'https://licenses.alextechgamer.com';
	}

	/**
	 * Product slug sent to the license server.
	 *
	 * @return string
	 */
	public function item_slug() {
		if ( defined( 'TOC_LICENSE_ITEM_SLUG' ) && TOC_LICENSE_ITEM_SLUG ) {
			return (string) TOC_LICENSE_ITEM_SLUG;
		}
		return 'orderring';
	}

	/**
	 * Stable instance ID for this WordPress install.
	 *
	 * @return string
	 */
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
		if ( (string) get_option( 'toc_license_instance_id', '' ) === '' ) {
			update_option( 'toc_license_instance_id', $id, false );
		}
		return $id;
	}

	public function get_key() {
		return (string) get_option( 'toc_license_key', '' );
	}

	public function get_status() {
		$status = (string) get_option( 'toc_license_status', self::STATUS_INACTIVE );
		$allowed = array( self::STATUS_INACTIVE, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_INVALID, self::STATUS_ERROR );
		return in_array( $status, $allowed, true ) ? $status : self::STATUS_INACTIVE;
	}

	/**
	 * @return array
	 */
	public function get_data() {
		$raw = get_option( 'toc_license_data', array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	public function last_check() {
		return (int) get_option( 'toc_license_last_check', 0 );
	}

	/**
	 * Whether licensed updates are allowed right now (active, or grace after soft error).
	 *
	 * @return bool
	 */
	public function allows_updates() {
		if ( $this->server_url() === '' ) {
			return false;
		}
		$status = $this->get_status();
		if ( $status === self::STATUS_ACTIVE ) {
			return true;
		}
		// Soft network error: keep last known good within grace period.
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

	/**
	 * Masked key for UI (never echo full key after save).
	 *
	 * @param string $key License key.
	 * @return string
	 */
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
			if ( ! as_has_scheduled_action( self::CRON_HOOK, array(), 'toc' ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, self::CHECK_EVERY, self::CRON_HOOK, array(), 'toc' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the recurring validation job from Action Scheduler and WP-Cron.
	 * Called on plugin deactivation so no orphan job keeps firing.
	 */
	public static function unschedule_cron() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, null, 'toc' );
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

	/**
	 * @param string $key License key.
	 * @return array{success:bool,status:string,message:string,data?:array}
	 */
	public function activate( $key ) {
		$key = strtoupper( trim( (string) $key ) );
		if ( $key === '' ) {
			return array( 'success' => false, 'status' => self::STATUS_INVALID, 'message' => __( 'Enter a license key.', 'twilio-order-communicator' ) );
		}
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'status' => self::STATUS_ERROR, 'message' => __( 'License server URL is not configured (TOC_LICENSE_SERVER_URL).', 'twilio-order-communicator' ) );
		}

		$result = $this->request(
			'POST',
			'/v1/activate',
			array(
				'license_key'    => $key,
				'site_url'       => home_url( '/' ),
				'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'instance_id'    => $this->instance_id(),
				'plugin_version' => TOC_VERSION,
				'item_slug'      => $this->item_slug(),
			)
		);

		if ( empty( $result['success'] ) ) {
			$status = $this->map_remote_status( $result );
			$this->persist( $key, $status, $result['data'] ?? array(), false );
			return array(
				'success' => false,
				'status'  => $status,
				'message' => $result['error'] ?? __( 'Activation failed.', 'twilio-order-communicator' ),
				'data'    => $result['data'] ?? array(),
			);
		}

		$data = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$data['was_active'] = true;
		$this->persist( $key, self::STATUS_ACTIVE, $data, true );
		return array(
			'success' => true,
			'status'  => self::STATUS_ACTIVE,
			'message' => $result['message'] ?? __( 'License activated.', 'twilio-order-communicator' ),
			'data'    => $data,
		);
	}

	/**
	 * @param bool $clear_key Whether to wipe the stored key.
	 * @return array
	 */
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
			delete_option( 'toc_license_key' );
		}
		update_option( 'toc_license_status', self::STATUS_INACTIVE, false );
		$data = $this->get_data();
		unset( $data['last_payload'] );
		$data['was_active'] = false;
		update_option( 'toc_license_data', $data, false );
		update_option( 'toc_license_last_check', time(), false );

		// Drop any cached "update available" answer that was fetched while licensed.
		if ( class_exists( 'TOC_Updater' ) ) {
			TOC_Updater::flush_update_cache( array( $key ) );
		}

		return array(
			'success' => true,
			'status'  => self::STATUS_INACTIVE,
			'message' => __( 'License deactivated for this site.', 'twilio-order-communicator' ),
		);
	}

	/**
	 * @param bool $hard_on_invalid If true, network errors stay as soft error (grace).
	 * @return array
	 */
	public function validate( $hard_on_invalid = true ) {
		$key = $this->get_key();
		if ( $key === '' ) {
			update_option( 'toc_license_status', self::STATUS_INACTIVE, false );
			return array( 'success' => false, 'status' => self::STATUS_INACTIVE, 'message' => __( 'No license key saved.', 'twilio-order-communicator' ) );
		}
		if ( ! $this->is_configured() ) {
			return array( 'success' => false, 'status' => self::STATUS_ERROR, 'message' => __( 'License server URL is not configured.', 'twilio-order-communicator' ) );
		}

		$result = $this->request(
			'POST',
			'/v1/validate',
			array(
				'license_key'    => $key,
				'site_url'       => home_url( '/' ),
				'instance_id'    => $this->instance_id(),
				'plugin_version' => TOC_VERSION,
				'item_slug'      => $this->item_slug(),
			)
		);

		if ( ! empty( $result['network_error'] ) ) {
			// Soft failure — keep previous status if it was active (grace via allows_updates).
			$data = $this->get_data();
			if ( $this->get_status() === self::STATUS_ACTIVE ) {
				$data['was_active'] = true;
			}
			update_option( 'toc_license_data', $data, false );
			update_option( 'toc_license_status', self::STATUS_ERROR, false );
			update_option( 'toc_license_last_check', time(), false );
			return array(
				'success' => false,
				'status'  => self::STATUS_ERROR,
				'message' => $result['error'] ?? __( 'Could not reach license server.', 'twilio-order-communicator' ),
			);
		}

		if ( empty( $result['success'] ) ) {
			$status = $this->map_remote_status( $result );
			$data   = is_array( $result['data'] ?? null ) ? $result['data'] : $this->get_data();
			$data['was_active'] = false;
			$this->persist( $key, $status, $data, false );
			return array(
				'success' => false,
				'status'  => $status,
				'message' => $result['error'] ?? __( 'License is not valid.', 'twilio-order-communicator' ),
				'data'    => $data,
			);
		}

		$data = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$data['was_active'] = true;
		$this->persist( $key, self::STATUS_ACTIVE, $data, true );
		return array(
			'success' => true,
			'status'  => self::STATUS_ACTIVE,
			'message' => __( 'License is active.', 'twilio-order-communicator' ),
			'data'    => $data,
		);
	}

	/**
	 * @param string $method GET|POST.
	 * @param string $path   API path.
	 * @param array  $body   JSON body.
	 * @param array  $headers Extra headers.
	 * @return array
	 */
	public function request( $method, $path, $body = array(), $headers = array() ) {
		$base = $this->server_url();
		if ( $base === '' ) {
			return array( 'success' => false, 'error' => 'License server not configured.', 'network_error' => true );
		}

		$url = $base . $path;
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => array_merge(
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
		$previous_key = (string) get_option( 'toc_license_key', '' );

		update_option( 'toc_license_key', (string) $key, false );
		update_option( 'toc_license_status', $status, false );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		// Drop any previously stored snapshot before rebuilding, so nothing accumulates.
		unset( $data['last_payload'] );
		$data['was_active']   = (bool) $was_active_flag;
		$data['last_payload'] = self::payload_snapshot( $data, $status );
		update_option( 'toc_license_data', $data, false );
		update_option( 'toc_license_last_check', time(), false );

		// Let WordPress see a licensed update now instead of waiting out the update cache.
		if ( $this->allows_updates() && class_exists( 'TOC_Updater' ) ) {
			TOC_Updater::flush_update_cache( array( $previous_key, (string) $key ) );
		}
	}

	/**
	 * Fixed-size summary of the last server payload.
	 * Never contains another snapshot, so repeated saves cannot grow the option.
	 *
	 * @param array  $data   Payload from the license server.
	 * @param string $status Resolved local status.
	 * @return array
	 */
	private static function payload_snapshot( array $data, $status ) {
		return array(
			'status'      => (string) $status,
			'expires_at'  => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
			'max_sites'   => isset( $data['max_sites'] ) ? (int) $data['max_sites'] : 0,
			'activations' => isset( $data['activations'] ) ? (int) $data['activations'] : 0,
			'site_url'    => isset( $data['site_url'] ) ? (string) $data['site_url'] : '',
			'saved_at'    => time(),
		);
	}

	private function map_remote_status( $result ) {
		$remote = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( $remote === self::STATUS_EXPIRED || $remote === 'expired' ) {
			return self::STATUS_EXPIRED;
		}
		if ( $remote === self::STATUS_INVALID || $remote === 'invalid' ) {
			return self::STATUS_INVALID;
		}
		if ( $remote === 'disabled' || $remote === 'limit' || $remote === 'inactive' ) {
			return self::STATUS_INVALID;
		}
		if ( ! empty( $result['network_error'] ) ) {
			return self::STATUS_ERROR;
		}
		return self::STATUS_INVALID;
	}

	public function ajax_activate() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		// Allow blank POST key to mean "use saved key" when masked field left unchanged.
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
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		$clear = ! empty( $_POST['clear_key'] );
		$result = $this->deactivate( $clear );
		wp_send_json_success( $this->ui_state( $result['message'] ) );
	}

	public function ajax_refresh() {
		check_ajax_referer( 'toc_nonce', 'nonce' );
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			wp_send_json_error( __( 'Permission denied', 'twilio-order-communicator' ) );
		}
		$result = $this->validate( true );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array_merge( $this->ui_state( $result['message'] ), array( 'message' => $result['message'] ) ) );
		}
		wp_send_json_success( $this->ui_state( $result['message'] ) );
	}

	/**
	 * Public UI snapshot (never includes full key).
	 *
	 * @param string $message Optional flash message.
	 * @return array
	 */
	public function ui_state( $message = '' ) {
		$data   = $this->get_data();
		$status = $this->get_status();
		$last   = $this->last_check();
		return array(
			'message'         => $message,
			'status'          => $status,
			'status_label'    => $this->status_label( $status ),
			'masked_key'      => $this->mask_key(),
			'has_key'         => $this->get_key() !== '',
			'server_configured' => $this->is_configured(),
			'allows_updates'  => $this->allows_updates(),
			'site_url'        => isset( $data['site_url'] ) ? (string) $data['site_url'] : home_url( '/' ),
			'activations'     => isset( $data['activations'] ) ? (int) $data['activations'] : null,
			'max_sites'       => isset( $data['max_sites'] ) ? (int) $data['max_sites'] : null,
			'expires_at'      => isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '',
			'customer_email'  => isset( $data['customer_email'] ) ? (string) $data['customer_email'] : '',
			'last_check'      => $last ? wp_date( 'M j, Y g:i a', $last ) : '',
			'instance_id'     => $this->instance_id(),
		);
	}

	public function status_label( $status ) {
		$map = array(
			self::STATUS_INACTIVE => __( 'Inactive', 'twilio-order-communicator' ),
			self::STATUS_ACTIVE   => __( 'Active', 'twilio-order-communicator' ),
			self::STATUS_EXPIRED  => __( 'Expired', 'twilio-order-communicator' ),
			self::STATUS_INVALID  => __( 'Invalid', 'twilio-order-communicator' ),
			self::STATUS_ERROR    => __( 'Error / unreachable', 'twilio-order-communicator' ),
		);
		return $map[ $status ] ?? $status;
	}

	public function admin_notice() {
		if ( ! current_user_can( TOC_Caps::manage() ) ) {
			return;
		}
		if ( ! $this->is_configured() ) {
			return;
		}
		$status = $this->get_status();
		if ( ! in_array( $status, array( self::STATUS_EXPIRED, self::STATUS_INVALID ), true ) ) {
			return;
		}

		$url = TOC_Admin::tab_url( 'license' );
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: license status label */
				__( 'OrderRing license status: %s. Messaging still works; premium plugin updates are paused until the license is active.', 'twilio-order-communicator' ),
				$this->status_label( $status )
			)
		);
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage license', 'twilio-order-communicator' ) . '</a>';
		echo '</p></div>';
	}
}
