<?php
/**
 * Shared helpers for TOC License Server.
 */

class TOC_License_Helpers {

	public static function json_input() {
		$raw = file_get_contents( 'php://input' );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			$data = $_POST; // phpcs:ignore
		}
		return is_array( $data ) ? $data : array();
	}

	public static function respond( $code, $payload ) {
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode_compat( $payload );
		exit;
	}

	public static function normalize_site_url( $url ) {
		$url = trim( (string) $url );
		$url = preg_replace( '#^https?://#i', '', $url );
		$url = preg_replace( '#^www\.#i', '', $url );
		$url = rtrim( strtolower( $url ), '/' );
		return $url;
	}

	public static function display_site_url( $url ) {
		$url = trim( (string) $url );
		return untrailingslashit_compat( $url );
	}

	public static function license_is_usable( array $license ) {
		if ( ( $license['status'] ?? '' ) !== 'active' ) {
			return array( false, 'disabled', 'License is disabled.' );
		}
		$expires = $license['expires_at'] ?? null;
		if ( $expires && strtotime( $expires ) < time() ) {
			return array( false, 'expired', 'License has expired.' );
		}
		return array( true, 'active', '' );
	}

	public static function generate_key() {
		$chunk = static function () {
			return strtoupper( bin2hex( random_bytes( 2 ) ) );
		};
		return 'TOC-' . $chunk() . $chunk() . '-' . $chunk() . $chunk() . '-' . $chunk() . $chunk() . '-' . $chunk() . $chunk();
	}

	public static function sign_download( $secret, $slug, $version, $expires ) {
		$payload = $slug . '|' . $version . '|' . $expires;
		return hash_hmac( 'sha256', $payload, $secret );
	}

	public static function verify_download( $secret, $slug, $version, $expires, $sig ) {
		if ( (int) $expires < time() ) {
			return false;
		}
		$expected = self::sign_download( $secret, $slug, $version, $expires );
		return hash_equals( $expected, (string) $sig );
	}
}

/** Polyfills so the server does not depend on WordPress. */
function wp_json_encode_compat( $data ) {
	return json_encode( $data, JSON_UNESCAPED_SLASHES );
}

function untrailingslashit_compat( $url ) {
	return rtrim( (string) $url, '/\\' );
}
