<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licensed plugin updates — fail closed (no update injection without valid license).
 * Core SMS/voice features are never gated here.
 */
class TOC_Updater {

	private static $instance = null;

	/** @var object|null Cached remote update payload */
	private $plugin_info = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	/**
	 * Transient name for a license key's cached update answer.
	 *
	 * @param string $key License key.
	 * @return string
	 */
	private static function cache_key( $key ) {
		return 'toc_update_check_' . md5( (string) $key . TOC_VERSION );
	}

	/**
	 * Drop cached update answers so the next check hits the license server.
	 * Called when license state changes; steady-state checks keep the 6-hour cache.
	 *
	 * @param array $keys License keys to clear in addition to the stored one.
	 */
	public static function flush_update_cache( $keys = array() ) {
		$keys   = array_map( 'strval', (array) $keys );
		$keys[] = (string) get_option( 'toc_license_key', '' );

		foreach ( array_unique( array_filter( $keys ) ) as $key ) {
			delete_site_transient( self::cache_key( $key ) );
		}

		if ( self::$instance instanceof self ) {
			self::$instance->plugin_info = null;
		}

		// Force WordPress to rebuild its plugin update list on the next request.
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$license = TOC_License::instance();
		if ( ! $license->is_configured() || ! $license->allows_updates() ) {
			return $transient;
		}

		$info = $this->fetch_update_info();
		if ( ! $info || empty( $info->new_version ) ) {
			return $transient;
		}

		if ( version_compare( TOC_VERSION, $info->new_version, '>=' ) ) {
			return $transient;
		}

		$plugin_file = TOC_PLUGIN_BASENAME;
		$transient->response[ $plugin_file ] = $info;
		unset( $transient->no_update[ $plugin_file ] );

		return $transient;
	}

	/**
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		$slug = isset( $args->slug ) ? (string) $args->slug : '';
		if ( $slug !== 'twilio-order-communicator' && $slug !== dirname( TOC_PLUGIN_BASENAME ) ) {
			return $result;
		}

		$license = TOC_License::instance();
		if ( ! $license->is_configured() || ! $license->allows_updates() ) {
			return $result;
		}

		$info = $this->fetch_update_info();
		if ( ! $info ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Twilio Order Communicator',
			'slug'          => 'twilio-order-communicator',
			'version'       => $info->new_version,
			'author'        => '<a href="https://github.com/Alextechgamer">Alextechgamer</a>',
			'homepage'      => 'https://github.com/Alextechgamer/Twilio-order-communicator-',
			'requires'      => $info->requires ?? '6.0',
			'requires_php'  => $info->requires_php ?? '7.4',
			'download_link' => $info->package ?? '',
			'sections'      => array(
				'description' => __( 'Order SMS and voice via your own Twilio account.', 'twilio-order-communicator' ),
				'changelog'   => isset( $info->sections['changelog'] ) ? $info->sections['changelog'] : '',
			),
		);
	}

	/**
	 * @return object|null
	 */
	private function fetch_update_info() {
		if ( $this->plugin_info !== null ) {
			return $this->plugin_info;
		}

		$license = TOC_License::instance();
		$key     = $license->get_key();
		if ( $key === '' ) {
			$this->plugin_info = false;
			return null;
		}

		$cache_key = self::cache_key( $key );
		$cached    = get_site_transient( $cache_key );
		if ( is_object( $cached ) ) {
			$this->plugin_info = $cached;
			return $cached;
		}

		$result = $license->request(
			'GET',
			'/v1/update-check',
			array(
				'slug'        => $license->item_slug(),
				'version'     => TOC_VERSION,
				'site_url'    => home_url( '/' ),
				'instance_id' => $license->instance_id(),
			),
			array(
				'X-TOC-License' => $key,
			)
		);

		if ( empty( $result['success'] ) || empty( $result['update'] ) || empty( $result['data']['version'] ) ) {
			$this->plugin_info = false;
			set_site_transient( $cache_key, (object) array( 'new_version' => TOC_VERSION ), 6 * HOUR_IN_SECONDS );
			return null;
		}

		$data = $result['data'];
		$info = (object) array(
			'id'           => 'twilio-order-communicator',
			'slug'         => 'twilio-order-communicator',
			'plugin'       => TOC_PLUGIN_BASENAME,
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
		$this->plugin_info = $info;
		return $info;
	}
}
