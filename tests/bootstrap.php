<?php
/**
 * Minimal WordPress shims so the plugins' pure logic can be unit-tested with plain PHP
 * (no WordPress runtime). Only the functions actually reached by the tested methods are
 * stubbed; everything is guarded so a real WP environment is never overridden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
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
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	// TOC_Logger's constructor only reads $wpdb->prefix.
	$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
}
