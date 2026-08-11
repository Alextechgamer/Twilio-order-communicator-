<?php
/**
 * Dependency-free unit tests for the plugins' pure logic. Run: `php tests/run.php`.
 *
 * These pin several of the fixes in this branch (E.164 validation, phone normalization,
 * consent truthiness, license signing) without needing a WordPress/WooCommerce runtime.
 * A composer/PHPUnit suite can grow from here in environments that have it.
 */

require __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );
require $root . '/twilio-order-communicator/includes/class-toc-twilio.php';
require $root . '/twilio-order-communicator/includes/class-toc-logger.php';
require $root . '/license-server/src/Helpers.php';

$pass  = 0;
$fail  = 0;
$fails = array();

/**
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function check( $label, $got, $want ) {
	global $pass, $fail, $fails;
	if ( $got === $want ) {
		$pass++;
	} else {
		$fail++;
		$fails[] = $label . ': got ' . var_export( $got, true ) . ' want ' . var_export( $want, true );
	}
}

/* ---- TOC_Twilio::is_e164 (pins T4 E.164 validation) ---- */
check( 'e164 US', TOC_Twilio::is_e164( '+15055551234' ), true );
check( 'e164 UK', TOC_Twilio::is_e164( '+447911123456' ), true );
check( 'e164 missing plus', TOC_Twilio::is_e164( '15055551234' ), false );
check( 'e164 leading zero', TOC_Twilio::is_e164( '+05055551234' ), false );
check( 'e164 too short', TOC_Twilio::is_e164( '+1234' ), false );
check( 'e164 has letters', TOC_Twilio::is_e164( '+1505555abcd' ), false );
check( 'e164 empty', TOC_Twilio::is_e164( '' ), false );

/* ---- TOC_Twilio::twiml_voice_attribute ---- */
check( 'voice polly map', TOC_Twilio::twiml_voice_attribute( 'polly.joanna' ), 'Polly.Joanna' );
check( 'voice passthrough', TOC_Twilio::twiml_voice_attribute( 'alice' ), 'alice' );
check( 'voice fallback', TOC_Twilio::twiml_voice_attribute( 'bogus' ), 'alice' );

/* ---- consent truthiness ---- */
$twilio = TOC_Twilio::instance();
check( 'consent yes', $twilio->is_truthy_consent( 'yes' ), true );
check( 'consent 1', $twilio->is_truthy_consent( '1' ), true );
check( 'consent checked', $twilio->is_truthy_consent( 'checked' ), true );
check( 'consent no', $twilio->is_truthy_consent( 'no' ), false );
check( 'falsy no', $twilio->is_falsy_consent( 'no' ), true );
check( 'falsy yes', $twilio->is_falsy_consent( 'yes' ), false );
check( 'falsy empty', $twilio->is_falsy_consent( '' ), false );

/* ---- TOC_Logger::normalize_phone ---- */
$logger = TOC_Logger::instance();
check( 'phone US formatted', $logger->normalize_phone( '(505) 555-1234' ), '+15055551234' );
check( 'phone already e164', $logger->normalize_phone( '+447911123456' ), '+447911123456' );
check( 'phone junk', $logger->normalize_phone( 'not a phone' ), '' );

/* ---- license signed downloads ---- */
$secret = 'unit-test-secret';
$future = time() + 3600;
$sig    = TOC_License_Helpers::sign_download( $secret, 'toc', '1.2.3', $future );
check( 'license verify ok', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.3', $future, $sig ), true );
check( 'license verify tampered version', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.4', $future, $sig ), false );
check( 'license verify wrong secret', TOC_License_Helpers::verify_download( 'other', 'toc', '1.2.3', $future, $sig ), false );
check( 'license verify expired', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.3', time() - 10, $sig ), false );

echo "PASS: {$pass}  FAIL: {$fail}\n";
foreach ( $fails as $f ) {
	echo "  FAIL {$f}\n";
}
exit( $fail > 0 ? 1 : 0 );
