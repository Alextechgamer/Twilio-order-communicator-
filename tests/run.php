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
require $root . '/orderbay/includes/class-ob-einvoice.php';
require $root . '/storecanvas/includes/class-sc-print-ready.php';
require $root . '/storecanvas/includes/class-sc-export.php';

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

/* ---- license-server rate limiter (in-memory SQLite; skipped if pdo_sqlite absent) ---- */
if ( extension_loaded( 'pdo_sqlite' ) ) {
	require $root . '/license-server/src/Database.php';
	$db = new TOC_License_DB( ':memory:' );
	// Allow 3 per window, 4th is blocked; a different bucket is independent.
	check( 'rate 1st allowed', $db->rate_hit( 'activate|1.1.1.1', 3, 3600 ), true );
	check( 'rate 2nd allowed', $db->rate_hit( 'activate|1.1.1.1', 3, 3600 ), true );
	check( 'rate 3rd allowed', $db->rate_hit( 'activate|1.1.1.1', 3, 3600 ), true );
	check( 'rate 4th blocked', $db->rate_hit( 'activate|1.1.1.1', 3, 3600 ), false );
	check( 'rate other bucket ok', $db->rate_hit( 'activate|2.2.2.2', 3, 3600 ), true );
} else {
	echo "SKIP: pdo_sqlite not loaded — license-server rate-limit test skipped\n";
}

/* ---- OrderBay e-invoice XML builders (pure; skipped if ext-dom absent) ---- */
if ( extension_loaded( 'dom' ) ) {
	$einv = array(
		'invoice_number' => 'INV-42',
		'issue_date'     => '2026-08-11',
		'due_date'       => '',
		'currency'       => 'EUR',
		'type_code'      => '380',
		'note'           => 'Thanks',
		'seller'         => array( 'name' => 'Acme SARL', 'street' => '1 Rue X', 'street2' => '', 'city' => 'Paris', 'postcode' => '75001', 'country' => 'FR', 'vat' => 'FR12345678901', 'email' => 'a@x.fr' ),
		'buyer'          => array( 'name' => 'John Buyer', 'street' => '2 Main', 'street2' => '', 'city' => 'Berlin', 'postcode' => '10115', 'country' => 'DE', 'vat' => '', 'email' => 'j@x.de' ),
		'lines'          => array(
			array( 'id' => '1', 'name' => 'Widget', 'qty' => 2.0, 'unit' => 'C62', 'unit_price' => 10.0, 'line_net' => 20.0, 'tax_percent' => 20.0, 'tax_category' => 'S' ),
			array( 'id' => '2', 'name' => 'Sticker', 'qty' => 1.0, 'unit' => 'C62', 'unit_price' => 5.0, 'line_net' => 5.0, 'tax_percent' => 0.0, 'tax_category' => 'Z' ),
		),
		'tax_subtotals'  => array(
			array( 'category' => 'S', 'percent' => 20.0, 'taxable' => 20.0, 'tax' => 4.0 ),
			array( 'category' => 'Z', 'percent' => 0.0, 'taxable' => 5.0, 'tax' => 0.0 ),
		),
		'totals'         => array( 'line_extension' => 25.0, 'tax_exclusive' => 25.0, 'tax_total' => 4.0, 'tax_inclusive' => 29.0, 'payable' => 29.0, 'prepaid' => 0.0 ),
	);

	$ubl = OB_EInvoice::build_ubl( $einv );
	check( 'ubl well-formed', false !== @simplexml_load_string( $ubl ), true );
	check( 'ubl has invoice id', strpos( $ubl, '<cbc:ID>INV-42</cbc:ID>' ) !== false, true );
	check( 'ubl seller name', strpos( $ubl, 'Acme SARL' ) !== false, true );
	check( 'ubl buyer country', strpos( $ubl, '<cbc:IdentificationCode>DE</cbc:IdentificationCode>' ) !== false, true );
	check( 'ubl payable reconciles', strpos( $ubl, 'PayableAmount currencyID="EUR">29.00' ) !== false, true );
	check( 'ubl two tax subtotals', substr_count( $ubl, '<cac:TaxSubtotal>' ), 2 );

	$cii = OB_EInvoice::build_cii( $einv );
	check( 'cii well-formed', false !== @simplexml_load_string( $cii ), true );
	check( 'cii root', strpos( $cii, 'rsm:CrossIndustryInvoice' ) !== false, true );
	check( 'cii grand total', strpos( $cii, '<ram:GrandTotalAmount>29.00</ram:GrandTotalAmount>' ) !== false, true );
	check( 'cii en16931 guideline', strpos( $cii, 'urn:cen.eu:en16931:2017' ) !== false, true );

	// Factur-X degrades gracefully when the optional library is absent.
	check( 'facturx unavailable without library', OB_EInvoice::facturx_available(), false );

	// Compliance.
	check( 'compliance complete', OB_EInvoice::compliance_issues( $einv ), array() );
	$bad          = $einv;
	$bad['seller']['vat'] = '';
	$issues       = OB_EInvoice::compliance_issues( $bad );
	check( 'compliance flags missing VAT', count( $issues ) >= 1, true );
} else {
	echo "SKIP: ext-dom not loaded — e-invoice XML tests skipped\n";
}

/* ---- StoreCanvas print output: pHYs DPI + SVG + minimal PDF (pure) ---- */
$png_1x1 = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC' );
$dpi_png = SC_Print_Ready::png_with_dpi( $png_1x1, 300 );
check( 'phys inserted', strpos( $dpi_png, 'pHYs' ) !== false, true );
check( 'png signature intact', strncmp( $dpi_png, "\x89PNG\r\n\x1a\n", 8 ) === 0, true );
check( 'png iend present', strpos( $dpi_png, 'IEND' ) !== false, true );
$ppu = 0;
$pp  = strpos( $dpi_png, 'pHYs' );
if ( false !== $pp ) {
	$u   = unpack( 'Nx/Ny/Cunit', substr( $dpi_png, $pp + 4, 9 ) );
	$ppu = $u['x'];
}
check( 'phys decodes to 300 dpi', (int) round( $ppu * 0.0254 ), 300 );
check( 'png_with_dpi rejects non-png', SC_Print_Ready::png_with_dpi( 'not a png', 300 ), '' );

if ( extension_loaded( 'dom' ) ) {
	$svg = SC_Export::svg_wrap( $png_1x1, 'image/png', 900, 600, 300, array( 'bleed_mm' => 3.0 ) );
	check( 'svg well-formed', false !== @simplexml_load_string( $svg ), true );
	check( 'svg embeds image', strpos( $svg, '<image' ) !== false, true );
	check( 'svg physical width', strpos( $svg, 'width="76.2mm"' ) !== false, true );
	check( 'svg has bleed rect', strpos( $svg, '<rect' ) !== false, true );
}

$pdf = SC_Export::pdf_single_image_jpeg( 'JPEGBYTES', 900, 600, 300 );
check( 'pdf header', strncmp( $pdf, '%PDF-', 5 ) === 0, true );
check( 'pdf xref', strpos( $pdf, "\nxref\n" ) !== false, true );
check( 'pdf trailer', strpos( $pdf, 'trailer' ) !== false, true );
check( 'pdf xobject image', strpos( $pdf, '/XObject' ) !== false, true );
check( 'pdf eof', substr( trim( $pdf ), -5 ) === '%%EOF', true );

echo "PASS: {$pass}  FAIL: {$fail}\n";
foreach ( $fails as $f ) {
	echo "  FAIL {$f}\n";
}
exit( $fail > 0 ? 1 : 0 );
