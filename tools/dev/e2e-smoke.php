<?php
/**
 * E2E smoke body — run via `wp eval-file` against a site prepared by
 * tools/dev/setup-wp.sh (which installs the Twilio HTTP mock mu-plugin).
 *
 * Creates a WooCommerce order with a billing phone and SMS consent, moves it
 * to Ready for Pickup, then asserts the outbound auto-SMS was captured in
 * /tmp/toc-http.log with the expected To number and template text.
 *
 * Exits non-zero (failing the CI job) if the send path in class-toc-auto.php
 * stops firing on the status change. Not part of any shipped plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$log_file = '/tmp/toc-http.log';
$phone    = '+15055551234';

$fail = function ( $msg, $order = null ) use ( $log_file ) {
	fwrite( STDERR, "E2E FAIL: {$msg}\n" );
	if ( $order instanceof WC_Order ) {
		// Order notes carry the plugin's skip/error reasons — the fastest diagnosis.
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			fwrite( STDERR, '  order note: ' . $note->content . "\n" );
		}
	}
	if ( file_exists( $log_file ) ) {
		fwrite( STDERR, "  captured HTTP log:\n" . file_get_contents( $log_file ) );
	}
	exit( 1 );
};

if ( file_exists( $log_file ) && ! unlink( $log_file ) ) {
	$fail( "cannot remove stale {$log_file}" );
}

$order = wc_create_order();
if ( is_wp_error( $order ) ) {
	$fail( 'wc_create_order failed: ' . $order->get_error_message() );
}
$order->set_billing_first_name( 'Ada' );
$order->set_billing_last_name( 'Lovelace' );
$order->set_billing_phone( $phone );
// toc_require_sms_consent defaults to on; record consent the way checkout would.
$order->update_meta_data( '_toc_sms_consent', 'yes' );
$order->set_status( 'processing' );
$order->save();

// The transition under test: fires woocommerce_order_status_changed → TOC_Auto.
$order->update_status( 'ready-for-pickup', 'E2E smoke test.' );

if ( ! file_exists( $log_file ) ) {
	$fail( "no {$log_file} — no outbound Twilio HTTP was captured on status change", $order );
}

$sms = null;
foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	$entry = json_decode( $line, true );
	if ( is_array( $entry ) && false !== strpos( (string) ( $entry['url'] ?? '' ), 'Messages.json' ) ) {
		$sms = $entry;
		break;
	}
}
if ( null === $sms ) {
	$fail( 'no Messages.json (SMS) request captured on status change', $order );
}

$to   = (string) ( $sms['body']['To'] ?? '' );
$body = (string) ( $sms['body']['Body'] ?? '' );
if ( $to !== $phone ) {
	$fail( "SMS To mismatch: expected {$phone}, got '{$to}'", $order );
}

// Default Ready for Pickup template from TOC_Auto::get_message_template(),
// with merge tags resolved for this order.
$expected = sprintf(
	'Hello Ada. Your order #%s is ready for pickup. Please come to the store when convenient. Thank you.',
	$order->get_order_number()
);
if ( false === strpos( $body, $expected ) ) {
	$fail( "SMS body mismatch:\n  expected to contain: {$expected}\n  got: {$body}", $order );
}

// Reload: the plugin stamped the meta on its own order instance, not ours.
$order = wc_get_order( $order->get_id() );
if ( ! $order->get_meta( '_toc_notified_ready_for_pickup_at' ) ) {
	$fail( 'order was not stamped _toc_notified_ready_for_pickup_at', $order );
}

echo 'E2E PASS: order #' . $order->get_order_number() . " → ready-for-pickup → SMS captured to {$to}\n";
echo "  body: {$body}\n";
