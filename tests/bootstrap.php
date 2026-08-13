<?php
/**
 * Minimal WordPress shims so the plugins' pure logic can be unit-tested with plain PHP
 * (no WordPress runtime). Only the functions actually reached by the tested methods are
 * stubbed; everything is guarded so a real WP environment is never overridden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value = null ) {
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	// Reads from $GLOBALS['toc_test_options']; tests set this to steer store-derived logic.
	function get_option( $name, $default = false ) {
		if ( isset( $GLOBALS['toc_test_options'] ) && array_key_exists( $name, $GLOBALS['toc_test_options'] ) ) {
			return $GLOBALS['toc_test_options'][ $name ];
		}
		return $default;
	}
}
// Common sanitizer/escaper shims (reasonable approximations of WP behavior for pure-logic tests).
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $s ) {
		return trim( (string) $s );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( wp_strip_tags_compat( (string) $s ) );
	}
}
if ( ! function_exists( 'wp_strip_tags_compat' ) ) {
	function wp_strip_tags_compat( $s ) {
		return preg_replace( '/<[^>]*>/', '', (string) $s );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_tags_compat( (string) $s ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) {
		return trim( wp_strip_tags_compat( (string) $s ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 16 ) ) ), 0, max( 1, (int) $length ) );
	}
}
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	// TOC_Logger's constructor only reads $wpdb->prefix.
	$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
}
