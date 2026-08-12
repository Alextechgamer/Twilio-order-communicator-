<?php
/**
 * Plugin Name: Dev Mail Capture
 * Description: Development-only mu-plugin. Short-circuits wp_mail() (no sendmail in the
 *              sandbox) and logs each attempted send — recipient, subject, attachment
 *              paths + whether each attachment file exists, and a body excerpt — as JSON
 *              lines to /tmp/wp-mail.log so email behavior can be asserted.
 *
 * NOT part of any shipped plugin. Copy into wp-content/mu-plugins/ for local testing only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		$attachments = array();
		$raw         = isset( $atts['attachments'] ) ? (array) $atts['attachments'] : array();
		foreach ( $raw as $path ) {
			$attachments[] = array(
				'path'   => (string) $path,
				'exists' => is_string( $path ) && file_exists( $path ),
			);
		}
		$line = array(
			'time'         => gmdate( 'c' ),
			'to'           => isset( $atts['to'] ) ? $atts['to'] : '',
			'subject'      => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'attachments'  => $attachments,
			'body_excerpt' => substr( wp_strip_all_tags( (string) ( $atts['message'] ?? '' ) ), 0, 300 ),
		);
		file_put_contents( '/tmp/wp-mail.log', wp_json_encode( $line ) . "\n", FILE_APPEND );
		return true; // pretend the mail was sent
	},
	10,
	2
);
