<?php
/**
 * Plugin Name: Dev HTTP Mock (Twilio capture)
 * Description: Development-only mu-plugin. Intercepts outbound HTTP to api.twilio.com,
 *              logs the request (method, URL, body) as JSON lines to /tmp/toc-http.log and
 *              returns a fake successful Twilio response, so send paths can be verified
 *              end-to-end without live credentials. Toggle off by setting the
 *              `dev_http_mock_disabled` option to 1 (real requests then go out and fail
 *              against Twilio with the placeholder credentials).
 *
 * NOT part of any shipped plugin. Copy into wp-content/mu-plugins/ for local testing only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'api.twilio.com' ) ) {
			return $preempt;
		}
		if ( get_option( 'dev_http_mock_disabled' ) ) {
			return $preempt;
		}

		$body = isset( $args['body'] ) ? $args['body'] : array();
		$line = array(
			'time'   => gmdate( 'c' ),
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'url'    => (string) $url,
			'body'   => $body,
		);
		file_put_contents( '/tmp/toc-http.log', wp_json_encode( $line ) . "\n", FILE_APPEND );

		$to   = is_array( $body ) && isset( $body['To'] ) ? (string) $body['To'] : '';
		$from = is_array( $body ) && isset( $body['From'] ) ? (string) $body['From'] : '';
		$sid  = 'SM' . substr( md5( uniqid( '', true ) ), 0, 32 );
		if ( false !== strpos( (string) $url, '/Calls' ) ) {
			$sid = 'CA' . substr( $sid, 2 );
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'sid'    => $sid,
					'status' => 'queued',
					'to'     => $to,
					'from'   => $from,
				)
			),
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
