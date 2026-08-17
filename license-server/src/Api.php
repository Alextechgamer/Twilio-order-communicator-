<?php
/**
 * API route handlers.
 */

class TOC_License_API {

	/** @var TOC_License_DB */
	private $db;

	/** @var array */
	private $config;

	public function __construct( TOC_License_DB $db, array $config ) {
		$this->db     = $db;
		$this->config = $config;
	}

	public function handle( $method, $path ) {
		$path = '/' . trim( $path, '/' );

		if ( $method === 'GET' && $path === '/v1/health' ) {
			TOC_License_Helpers::respond( 200, array( 'ok' => true, 'service' => 'toc-license-server' ) );
		}

		if ( $method === 'POST' && $path === '/v1/activate' ) {
			$this->activate();
		}
		if ( $method === 'POST' && $path === '/v1/deactivate' ) {
			$this->deactivate();
		}
		if ( $method === 'POST' && $path === '/v1/validate' ) {
			$this->validate();
		}
		if ( $method === 'GET' && $path === '/v1/update-check' ) {
			$this->update_check();
		}
		if ( $method === 'GET' && $path === '/v1/download' ) {
			$this->download();
		}
		if ( $method === 'POST' && $path === '/v1/stripe-webhook' ) {
			( new TOC_License_Purchases( $this->db, $this->config ) )->handle();
		}

		TOC_License_Helpers::respond( 404, array( 'success' => false, 'error' => 'Not found' ) );
	}

	private function activate() {
		$this->rate_guard( 'activate' );
		$body = TOC_License_Helpers::json_input();
		$key  = trim( (string) ( $body['license_key'] ?? $body['key'] ?? '' ) );
		$site = TOC_License_Helpers::display_site_url( $body['site_url'] ?? '' );
		$inst = trim( (string) ( $body['instance_id'] ?? '' ) );
		$ver  = trim( (string) ( $body['plugin_version'] ?? '' ) );

		if ( $key === '' || $site === '' || $inst === '' ) {
			TOC_License_Helpers::respond( 400, array( 'success' => false, 'error' => 'Missing license_key, site_url, or instance_id.' ) );
		}

		$license = $this->db->get_license( $key );
		if ( ! $license ) {
			TOC_License_Helpers::respond( 404, array( 'success' => false, 'status' => 'invalid', 'error' => 'Invalid license key.' ) );
		}

		list( $ok, $status, $msg ) = TOC_License_Helpers::license_is_usable( $license );
		if ( ! $ok ) {
			TOC_License_Helpers::respond( 403, array( 'success' => false, 'status' => $status, 'error' => $msg ) );
		}

		$existing = $this->db->find_activation( $key, $site, $inst );
		if ( ! $existing ) {
			$count = $this->db->count_activations( $key );
			$max   = max( 1, (int) $license['max_sites'] );
			if ( $count >= $max ) {
				TOC_License_Helpers::respond(
					403,
					array(
						'success' => false,
						'status'  => 'limit',
						'error'   => 'Activation limit reached for this license.',
						'data'    => array(
							'max_sites'   => $max,
							'activations' => $count,
						),
					)
				);
			}
		}

		$act = $this->db->upsert_activation( $key, $site, $inst, $ver );
		TOC_License_Helpers::respond(
			200,
			array(
				'success' => true,
				'status'  => 'active',
				'message' => 'License activated.',
				'data'    => $this->payload( $license, $act ),
			)
		);
	}

	private function deactivate() {
		$body = TOC_License_Helpers::json_input();
		$key  = trim( (string) ( $body['license_key'] ?? $body['key'] ?? '' ) );
		$site = TOC_License_Helpers::display_site_url( $body['site_url'] ?? '' );
		$inst = trim( (string) ( $body['instance_id'] ?? '' ) );

		if ( $key === '' || $site === '' || $inst === '' ) {
			TOC_License_Helpers::respond( 400, array( 'success' => false, 'error' => 'Missing license_key, site_url, or instance_id.' ) );
		}

		$deleted = $this->db->delete_activation( $key, $site, $inst );
		TOC_License_Helpers::respond(
			200,
			array(
				'success' => true,
				'status'  => 'inactive',
				'message' => $deleted ? 'License deactivated for this site.' : 'No activation found (already inactive).',
			)
		);
	}

	private function validate() {
		$this->rate_guard( 'validate' );
		$body = TOC_License_Helpers::json_input();
		$key  = trim( (string) ( $body['license_key'] ?? $body['key'] ?? '' ) );
		$site = TOC_License_Helpers::display_site_url( $body['site_url'] ?? '' );
		$inst = trim( (string) ( $body['instance_id'] ?? '' ) );
		$ver  = trim( (string) ( $body['plugin_version'] ?? '' ) );

		if ( $key === '' || $site === '' || $inst === '' ) {
			TOC_License_Helpers::respond( 400, array( 'success' => false, 'error' => 'Missing license_key, site_url, or instance_id.' ) );
		}

		$license = $this->db->get_license( $key );
		if ( ! $license ) {
			TOC_License_Helpers::respond( 404, array( 'success' => false, 'status' => 'invalid', 'error' => 'Invalid license key.' ) );
		}

		list( $ok, $status, $msg ) = TOC_License_Helpers::license_is_usable( $license );
		if ( ! $ok ) {
			TOC_License_Helpers::respond( 403, array( 'success' => false, 'status' => $status, 'error' => $msg ) );
		}

		$act = $this->db->touch_activation( $key, $site, $inst, $ver );
		if ( ! $act ) {
			TOC_License_Helpers::respond(
				403,
				array(
					'success' => false,
					'status'  => 'inactive',
					'error'   => 'This site is not activated for this license.',
				)
			);
		}

		TOC_License_Helpers::respond(
			200,
			array(
				'success' => true,
				'status'  => 'active',
				'data'    => $this->payload( $license, $act ),
			)
		);
	}

	private function update_check() {
		$this->rate_guard( 'update-check' );
		$slug    = trim( (string) ( $_GET['slug'] ?? $this->config['item_slug'] ) ); // phpcs:ignore
		$version = trim( (string) ( $_GET['version'] ?? '' ) ); // phpcs:ignore
		$key     = $this->license_from_request();

		if ( $key === '' ) {
			TOC_License_Helpers::respond( 401, array( 'success' => false, 'error' => 'License required.' ) );
		}

		$license = $this->db->get_license( $key );
		if ( ! $license ) {
			TOC_License_Helpers::respond( 404, array( 'success' => false, 'status' => 'invalid', 'error' => 'Invalid license key.' ) );
		}

		list( $ok, $status, $msg ) = TOC_License_Helpers::license_is_usable( $license );
		if ( ! $ok ) {
			TOC_License_Helpers::respond( 403, array( 'success' => false, 'status' => $status, 'error' => $msg ) );
		}

		// Product binding: a key minted for one product must never serve another
		// product's updates. Legacy keys (no item_slug) serve the server default only.
		$want = $slug !== '' ? $slug : (string) ( $this->config['item_slug'] ?? '' );
		if ( ! self::slug_allowed( (string) ( $license['item_slug'] ?? '' ), $want, (string) ( $this->config['item_slug'] ?? '' ) ) ) {
			TOC_License_Helpers::respond(
				403,
				array(
					'success' => false,
					'status'  => 'wrong_product',
					'error'   => 'This license key is for a different product.',
				)
			);
		}

		// A signed download URL is bound to an activated site: require site_url +
		// instance_id AND a matching activation before issuing one. A bare license key
		// (no site binding) must never mint a package URL — that would let a stolen or
		// shared key redistribute the plugin for the whole TTL window.
		$site = TOC_License_Helpers::display_site_url( $_GET['site_url'] ?? '' ); // phpcs:ignore
		$inst = trim( (string) ( $_GET['instance_id'] ?? '' ) ); // phpcs:ignore
		$act  = ( $site !== '' && $inst !== '' ) ? $this->db->find_activation( $key, $site, $inst ) : null;
		if ( ! self::download_allowed( $site, $inst, (bool) $act ) ) {
			TOC_License_Helpers::respond(
				403,
				array(
					'success' => false,
					'status'  => 'inactive',
					'error'   => 'Site not activated. Activate this site for the license before checking for updates.',
				)
			);
		}
		$this->db->touch_activation( $key, $site, $inst, $version );

		$release = $this->db->latest_release( $slug !== '' ? $slug : $this->config['item_slug'] );
		if ( ! $release ) {
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'update' => false ) );
		}

		$has_update = $version === '' || version_compare( $release['version'], $version, '>' );
		if ( ! $has_update ) {
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'update' => false, 'version' => $release['version'] ) );
		}

		$ttl     = max( 300, (int) ( $this->config['download_ttl'] ?? 3600 ) );
		$expires = time() + $ttl;
		$secret  = $this->download_secret();
		$sig     = TOC_License_Helpers::sign_download( $secret, $release['slug'], $release['version'], $expires );
		$base    = rtrim( (string) ( $this->config['public_base_url'] ?? '' ), '/' );
		$package = $base . '/v1/download?slug=' . rawurlencode( $release['slug'] )
			. '&version=' . rawurlencode( $release['version'] )
			. '&expires=' . $expires
			. '&sig=' . rawurlencode( $sig );

		TOC_License_Helpers::respond(
			200,
			array(
				'success' => true,
				'update'  => true,
				'data'    => array(
					'version'       => $release['version'],
					'required_php'  => $release['required_php'],
					'required_wp'   => $release['required_wp'],
					'package_url'   => $package,
					'changelog'     => $release['changelog'],
					'released_at'   => $release['released_at'],
					'expires'       => $expires,
				),
			)
		);
	}

	/**
	 * Whether an update-check request may be issued a signed download URL.
	 *
	 * The only way to earn a package URL is an activated site: both the site_url and
	 * instance_id must be present AND resolve to a known activation for the license.
	 * Pure decision (no I/O) so the invariant can be unit-tested without a running server.
	 *
	 * @param string $site             Normalized site URL from the request ('' if absent).
	 * @param string $inst             Instance id from the request ('' if absent).
	 * @param bool   $activation_found Whether the DB found a matching activation.
	 * @return bool
	 */
	public static function download_allowed( $site, $inst, $activation_found ) {
		return $site !== '' && $inst !== '' && (bool) $activation_found;
	}

	/**
	 * Whether a license may serve updates for the requested product slug.
	 *
	 * A key bound to a product (item_slug set) serves only that product. A legacy
	 * key (no item_slug) serves only the server's default product. Pure (no I/O)
	 * so the invariant can be unit-tested without a running server.
	 *
	 * @param string $license_slug item_slug stored on the license ('' if unbound).
	 * @param string $requested    Slug the client is asking about.
	 * @param string $default_slug Server default product slug from config.
	 * @return bool
	 */
	public static function slug_allowed( $license_slug, $requested, $default_slug ) {
		$license_slug = trim( (string) $license_slug );
		if ( $license_slug !== '' ) {
			return $requested === $license_slug;
		}
		return $requested === $default_slug;
	}

	private function download() {
		$slug    = trim( (string) ( $_GET['slug'] ?? '' ) ); // phpcs:ignore
		$version = trim( (string) ( $_GET['version'] ?? '' ) ); // phpcs:ignore
		$expires = (int) ( $_GET['expires'] ?? 0 ); // phpcs:ignore
		$sig     = (string) ( $_GET['sig'] ?? '' ); // phpcs:ignore

		if ( $slug === '' || $version === '' || ! TOC_License_Helpers::verify_download( $this->download_secret(), $slug, $version, $expires, $sig ) ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Invalid or expired download link.';
			exit;
		}

		$release = $this->db->get_release( $slug, $version );
		if ( ! $release ) {
			http_response_code( 404 );
			echo 'Release not found.';
			exit;
		}

		$dir  = rtrim( (string) $this->config['releases_dir'], '/' );
		$file = $dir . '/' . basename( $release['package_path'] );
		if ( ! is_file( $file ) ) {
			http_response_code( 404 );
			echo 'Package file missing on server.';
			exit;
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Cache-Control: no-store' );
		readfile( $file );
		exit;
	}

	private function license_from_request() {
		$header = $_SERVER['HTTP_X_TOC_LICENSE'] ?? '';
		if ( $header !== '' ) {
			return trim( (string) $header );
		}
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_AUTHORIZATION'] ?? '' );
		if ( stripos( $auth, 'Bearer ' ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		// Intentionally no ?license_key= query fallback: the plugin sends the key via the
		// X-TOC-License header, and query strings leak keys into web-server access logs.
		return '';
	}

	/**
	 * Best-effort connecting IP for rate-limit buckets.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0';
	}

	/**
	 * Throttle an endpoint per client IP; respond 429 and exit when the window is
	 * exhausted. Bounds license-key brute forcing. Tunable via config
	 * rate_limit_max / rate_limit_window (per IP, per endpoint).
	 *
	 * @param string $name Endpoint name for the bucket.
	 * @return void
	 */
	private function rate_guard( $name ) {
		$max    = max( 1, (int) ( $this->config['rate_limit_max'] ?? 60 ) );
		$window = max( 1, (int) ( $this->config['rate_limit_window'] ?? 3600 ) );
		if ( ! $this->db->rate_hit( $name . '|' . $this->client_ip(), $max, $window ) ) {
			header( 'Retry-After: ' . $window );
			TOC_License_Helpers::respond( 429, array( 'success' => false, 'error' => 'Too many requests. Please try again later.' ) );
		}
	}

	private function download_secret() {
		$secret = (string) ( $this->config['download_secret'] ?? '' );
		if ( $secret === '' ) {
			$secret = (string) ( $this->config['admin_token'] ?? '' );
		}
		// Fail closed: never sign or verify with an empty or placeholder secret.
		if ( $secret === '' || $secret === 'change-me-to-a-long-random-string' ) {
			throw new RuntimeException( 'Download secret is not configured. Set download_secret (or a non-default admin_token) in config.php.' );
		}
		return $secret;
	}

	private function payload( array $license, $activation = null ) {
		return array(
			'expires_at'     => $license['expires_at'],
			'max_sites'      => (int) $license['max_sites'],
			'activations'    => $this->db->count_activations( $license['license_key'] ),
			'customer_email' => $license['customer_email'],
			'site_url'       => $activation['site_url'] ?? '',
			'instance_id'    => $activation['instance_id'] ?? '',
			'activated_at'   => $activation['activated_at'] ?? '',
			'last_seen_at'   => $activation['last_seen_at'] ?? '',
			'item_slug'      => ( isset( $license['item_slug'] ) && '' !== (string) $license['item_slug'] )
				? $license['item_slug']
				: ( $this->config['item_slug'] ?? 'orderring' ),
		);
	}
}
