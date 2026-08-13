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
require $root . '/twilio-order-communicator/includes/class-toc-caps.php';
require $root . '/license-server/src/Helpers.php';
require $root . '/license-server/src/Api.php';
require $root . '/twilio-order-communicator/includes/class-toc-license.php';
require $root . '/orderbay/includes/class-ob-plugin.php';
require $root . '/orderbay/includes/class-ob-einvoice.php';
require $root . '/orderbay/includes/class-ob-invoicing.php';
require $root . '/orderbay/includes/class-ob-documents.php';
require $root . '/orderbay/includes/class-ob-barcode.php';
require $root . '/orderbay/includes/class-ob-fulfillment.php';
require $root . '/orderbay/includes/class-ob-rma.php';
require $root . '/orderbay/includes/class-ob-qr.php';
require $root . '/storecanvas/includes/class-sc-print-ready.php';
require $root . '/storecanvas/includes/class-sc-export.php';
require $root . '/storecanvas/includes/class-sc-cart-order.php';
require $root . '/storecanvas/includes/class-sc-product-options.php';
require $root . '/storecanvas/includes/class-sc-fpd-import.php';
require $root . '/storecanvas/includes/class-sc-templates.php';

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

/* ---- TOC_Twilio::whatsapp_address (pins WhatsApp channel addressing) ---- */
check( 'wa address from e164', TOC_Twilio::whatsapp_address( '+15055551234' ), 'whatsapp:+15055551234' );
check( 'wa address idempotent', TOC_Twilio::whatsapp_address( 'whatsapp:+447911123456' ), 'whatsapp:+447911123456' );
check( 'wa address rejects non-e164', TOC_Twilio::whatsapp_address( '5055551234' ), '' );
check( 'wa address rejects empty', TOC_Twilio::whatsapp_address( '' ), '' );
check( 'wa address rejects letters', TOC_Twilio::whatsapp_address( '+1505abc1234' ), '' );

/* ---- TOC_Logger::last10 (pins the sargable opt-out index derivation) ---- */
check( 'last10 full', TOC_Logger::last10( '+1 (505) 555-1234' ), '5055551234' );
check( 'last10 long intl', TOC_Logger::last10( '+447911123456' ), '7911123456' );
check( 'last10 short kept', TOC_Logger::last10( '5551234' ), '5551234' );
check( 'last10 strips non-digits', TOC_Logger::last10( 'abc12-34' ), '1234' );
check( 'last10 empty', TOC_Logger::last10( '' ), '' );

/* ---- TOC_Logger::phones_match (pins M4: order-bound send target) ---- */
check( 'match formatted vs bare', TOC_Logger::phones_match( '+1 (505) 555-1234', '5055551234' ), true );
check( 'match cc vs national', TOC_Logger::phones_match( '+15055551234', '(505) 555-1234' ), true );
check( 'match different numbers', TOC_Logger::phones_match( '5055551234', '5055559999' ), false );
check( 'match empty a', TOC_Logger::phones_match( '', '5055551234' ), false );
check( 'match empty b', TOC_Logger::phones_match( '5055551234', '' ), false );
check( 'match both empty', TOC_Logger::phones_match( '', '' ), false );

/* ---- TOC_Twilio::tracking_from_meta (pins the {tracking} merge tag precedence) ---- */
check( 'tracking OB wins', TOC_Twilio::tracking_from_meta( '1Z999', 'https://t/1Z999', array( array( 'tracking_number' => 'WC1' ) ) ), array( 'number' => '1Z999', 'url' => 'https://t/1Z999' ) );
check( 'tracking WC fallback', TOC_Twilio::tracking_from_meta( '', '', array( array( 'tracking_number' => 'WC1', 'custom_tracking_link' => 'https://c/WC1' ) ) ), array( 'number' => 'WC1', 'url' => 'https://c/WC1' ) );
check( 'tracking WC formatted link', TOC_Twilio::tracking_from_meta( '', '', array( array( 'tracking_number' => 'WC2', 'formatted_tracking_link' => 'https://f/WC2' ) ) ), array( 'number' => 'WC2', 'url' => 'https://f/WC2' ) );
check( 'tracking OB number keeps OB url empty ok', TOC_Twilio::tracking_from_meta( 'OB9', '', 'not-an-array' ), array( 'number' => 'OB9', 'url' => '' ) );
check( 'tracking none', TOC_Twilio::tracking_from_meta( '', '', array() ), array( 'number' => '', 'url' => '' ) );
check( 'tracking skips empty WC items', TOC_Twilio::tracking_from_meta( '', '', array( array( 'tracking_number' => '' ), array( 'tracking_number' => 'WC3' ) ) ), array( 'number' => 'WC3', 'url' => '' ) );

/* ---- TOC_Logger::normalize_phone (pins the international-normalization fix) ---- */
$logger = TOC_Logger::instance();
// Default store country (US, +1).
$GLOBALS['toc_test_options'] = array();
check( 'phone US formatted', $logger->normalize_phone( '(505) 555-1234' ), '+15055551234' );
check( 'phone US with cc', $logger->normalize_phone( '1 (505) 555-1234' ), '+15055551234' );
check( 'phone already e164', $logger->normalize_phone( '+447911123456' ), '+447911123456' );
check( 'phone junk', $logger->normalize_phone( 'not a phone' ), '' );
check( 'phone bare plus', $logger->normalize_phone( '+' ), '' );
// "00" international access prefix behaves like "+" regardless of store country.
check( 'phone 00 intl prefix', $logger->normalize_phone( '00 44 7911 123456' ), '+447911123456' );
// Deterministic: the same input always maps to the same key (opt-out safety).
check( 'phone deterministic', $logger->normalize_phone( '07911 123456' ), $logger->normalize_phone( '07911123456' ) );

// UK store (+44): a single leading "0" is the national trunk prefix, not a NANP number.
$GLOBALS['toc_test_options'] = array( 'woocommerce_default_country' => 'GB' );
check( 'phone UK mobile trunk-0', $logger->normalize_phone( '07911 123456' ), '+447911123456' );
check( 'phone UK landline trunk-0', $logger->normalize_phone( '020 7946 0018' ), '+442079460018' );
check( 'phone UK national no-trunk', $logger->normalize_phone( '7911123456' ), '+447911123456' );
check( 'phone UK country default', $logger->default_country_code(), '44' );

// Store base country "US:CA" (country:state form) still resolves to +1.
$GLOBALS['toc_test_options'] = array( 'woocommerce_default_country' => 'US:CA' );
check( 'phone US:state formatted', $logger->normalize_phone( '(505) 555-1234' ), '+15055551234' );
$GLOBALS['toc_test_options'] = array();

/* ---- license signed downloads ---- */
$secret = 'unit-test-secret';
$future = time() + 3600;
$sig    = TOC_License_Helpers::sign_download( $secret, 'toc', '1.2.3', $future );
check( 'license verify ok', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.3', $future, $sig ), true );
check( 'license verify tampered version', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.4', $future, $sig ), false );
check( 'license verify wrong secret', TOC_License_Helpers::verify_download( 'other', 'toc', '1.2.3', $future, $sig ), false );
check( 'license verify expired', TOC_License_Helpers::verify_download( $secret, 'toc', '1.2.3', time() - 10, $sig ), false );

/* ---- license update-check download gate (pins H2: activated-site binding) ---- */
check( 'dl allowed when activated', TOC_License_API::download_allowed( 'shop.example', 'inst-1', true ), true );
check( 'dl blocked without activation', TOC_License_API::download_allowed( 'shop.example', 'inst-1', false ), false );
check( 'dl blocked missing site', TOC_License_API::download_allowed( '', 'inst-1', true ), false );
check( 'dl blocked missing instance', TOC_License_API::download_allowed( 'shop.example', '', true ), false );
check( 'dl blocked bare key', TOC_License_API::download_allowed( '', '', false ), false );

/* ---- 30-day trial math (features stay unlocked; trial only tracks licensed-updates window) ---- */
$now = 1_700_000_000;
check( 'trial not started', TOC_License::trial_days_remaining( 0, $now, 30 ), 0 );
check( 'trial day 1', TOC_License::trial_days_remaining( $now, $now, 30 ), 30 );
check( 'trial day 15', TOC_License::trial_days_remaining( $now, $now + ( 15 * 86400 ), 30 ), 15 );
check( 'trial last day', TOC_License::trial_days_remaining( $now, $now + ( 29 * 86400 ) + 1, 30 ), 1 );
check( 'trial expired', TOC_License::trial_days_remaining( $now, $now + ( 30 * 86400 ), 30 ), 0 );
check( 'trial disabled length', TOC_License::trial_days_remaining( $now, $now, 0 ), 0 );

/* ---- TOC_Caps::role_meets_baseline (pins H4: cap-grant floor) ---- */
check( 'baseline admin always', TOC_Caps::role_meets_baseline( 'administrator', false, false ), true );
check( 'baseline via manage_woocommerce', TOC_Caps::role_meets_baseline( 'shop_manager', true, false ), true );
check( 'baseline via edit_shop_orders', TOC_Caps::role_meets_baseline( 'fulfillment', false, true ), true );
check( 'baseline subscriber blocked', TOC_Caps::role_meets_baseline( 'subscriber', false, false ), false );
check( 'baseline editor blocked', TOC_Caps::role_meets_baseline( 'editor', false, false ), false );

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

/* ---- StoreCanvas prebuilt templates (pure) ---- */
$sc_tpls = SC_Templates::templates();
check( 'tpl has 4 presets', count( $sc_tpls ), 4 );
check( 'tpl keys', array_keys( $sc_tpls ) === array( 'tee', 'mug', 'sticker', 'sign' ), true );
$tpl_wellformed = true;
foreach ( $sc_tpls as $k => $tpl ) {
	if ( 1 !== ( $tpl['customizer']['enabled'] ?? 0 ) ) { $tpl_wellformed = false; }
	if ( count( $tpl['customizer']['views'] ) < 1 ) { $tpl_wellformed = false; }
	if ( count( $tpl['customizer']['areas'] ) < 1 ) { $tpl_wellformed = false; }
	foreach ( $tpl['customizer']['areas'] as $a ) {
		foreach ( array( 'x', 'y', 'w', 'h' ) as $dim ) {
			if ( $a[ $dim ] < 0 || $a[ $dim ] > 100 ) { $tpl_wellformed = false; }
		}
		// every area references a real view id
		$view_ids = array_map( function ( $v ) { return $v['id']; }, $tpl['customizer']['views'] );
		if ( ! in_array( $a['view_id'], $view_ids, true ) ) { $tpl_wellformed = false; }
	}
	foreach ( $tpl['options']['fields'] as $f ) {
		if ( empty( $f['id'] ) || empty( $f['type'] ) ) { $tpl_wellformed = false; }
		if ( 'select' === $f['type'] && empty( $f['choices'] ) ) { $tpl_wellformed = false; }
	}
}
check( 'tpl all well-formed', $tpl_wellformed, true );
check( 'tpl apply tee', SC_Templates::apply( 'tee' )['customizer']['enabled'], 1 );
check( 'tpl apply tee has options', count( SC_Templates::apply( 'tee' )['options']['fields'] ) >= 1, true );
check( 'tpl apply unknown null', SC_Templates::apply( 'bogus' ), null );

/* ---- StoreCanvas FPD importer mapping (pure; modeled FPD schema) ---- */
$fpd = array(
	'title' => 'Classic Tee',
	'views' => array(
		array(
			'title'       => 'Front',
			'thumbnail'   => 'https://example.com/front.png',
			'options'     => array( 'stageWidth' => 1000, 'stageHeight' => 1200 ),
			'printingBox' => array( 'left' => 250, 'top' => 300, 'width' => 500, 'height' => 600 ),
			'elements'    => array(
				array( 'type' => 'image', 'source' => 'https://example.com/front.png', 'parameters' => array( 'width' => 1000, 'height' => 1200 ) ),
				array( 'type' => 'text', 'title' => 'Your Name', 'parameters' => array( 'text' => 'Type here' ) ),
				array( 'type' => 'text', 'title' => 'Your Name' ),
				array( 'type' => 'image', 'source' => 'https://example.com/logo.png' ),
			),
		),
		array(
			'title'    => 'Back',
			'elements' => array(),
		),
	),
);
$mapped = SC_FPD_Import::map( $fpd );
check( 'fpd title', $mapped['title'], 'Classic Tee' );
check( 'fpd enabled', $mapped['customizer']['enabled'], 1 );
check( 'fpd two views', count( $mapped['customizer']['views'] ), 2 );
check( 'fpd view label', $mapped['customizer']['views'][0]['label'], 'Front' );
check( 'fpd view image url kept', $mapped['customizer']['views'][0]['image_url'], 'https://example.com/front.png' );
check( 'fpd area x pct', $mapped['customizer']['areas'][0]['x'], 25.0 );
check( 'fpd area w pct', $mapped['customizer']['areas'][0]['w'], 50.0 );
check( 'fpd back default area', $mapped['customizer']['areas'][1]['w'], 60.0 );
check( 'fpd one text field (deduped)', count( $mapped['options']['fields'] ), 1 );
check( 'fpd text field id slug', $mapped['options']['fields'][0]['id'], 'your_name' );
check( 'fpd text field type', $mapped['options']['fields'][0]['type'], 'text' );
check( 'fpd notes present', count( $mapped['notes'] ) >= 1, true );
check( 'fpd bare list of views', count( SC_FPD_Import::map( array( array( 'title' => 'Only', 'elements' => array() ) ) )['customizer']['views'] ), 1 );
check( 'fpd empty input', SC_FPD_Import::map( 'nope' )['customizer']['enabled'], 0 );

/* ---- SC_FPD_Import::is_blocked_host (pins M7: SSRF host guard) ---- */
check( 'ssrf blocks loopback', SC_FPD_Import::is_blocked_host( '127.0.0.1' ), true );
check( 'ssrf blocks localhost', SC_FPD_Import::is_blocked_host( 'localhost' ), true );
check( 'ssrf blocks sub localhost', SC_FPD_Import::is_blocked_host( 'db.localhost' ), true );
check( 'ssrf blocks cloud metadata', SC_FPD_Import::is_blocked_host( '169.254.169.254' ), true );
check( 'ssrf blocks rfc1918 10', SC_FPD_Import::is_blocked_host( '10.1.2.3' ), true );
check( 'ssrf blocks rfc1918 192', SC_FPD_Import::is_blocked_host( '192.168.0.5' ), true );
check( 'ssrf blocks ipv6 loopback', SC_FPD_Import::is_blocked_host( '::1' ), true );
check( 'ssrf blocks ipv6 bracketed', SC_FPD_Import::is_blocked_host( '[::1]' ), true );
check( 'ssrf blocks empty host', SC_FPD_Import::is_blocked_host( '' ), true );
check( 'ssrf allows public ip', SC_FPD_Import::is_blocked_host( '8.8.8.8' ), false );
check( 'ssrf allows public host', SC_FPD_Import::is_blocked_host( 'cdn.example.com' ), false );

/* ---- StoreCanvas conditional logic (pure; AND/OR + operators) ---- */
$opts = array( 'size' => 'L', 'color' => 'red', 'qty' => '5', 'name' => 'Bob', 'tags' => array( 'a', 'b' ) );
check( 'rule is', SC_Product_Options::rule_matches( 'is', 'L', 'L' ), true );
check( 'rule is_not', SC_Product_Options::rule_matches( 'is_not', 'L', 'M' ), true );
check( 'rule contains', SC_Product_Options::rule_matches( 'contains', 'ob', 'Bob' ), true );
check( 'rule not_contains', SC_Product_Options::rule_matches( 'not_contains', 'zz', 'Bob' ), true );
check( 'rule gt', SC_Product_Options::rule_matches( 'gt', '3', '5' ), true );
check( 'rule lt false', SC_Product_Options::rule_matches( 'lt', '3', '5' ), false );
check( 'rule gt non-numeric', SC_Product_Options::rule_matches( 'gt', '3', 'abc' ), false );
check( 'rule empty', SC_Product_Options::rule_matches( 'empty', '', '' ), true );
check( 'rule not_empty', SC_Product_Options::rule_matches( 'not_empty', '', 'x' ), true );
check( 'rule in', SC_Product_Options::rule_matches( 'in', 'red,blue', 'red' ), true );
check( 'rule is array multi', SC_Product_Options::rule_matches( 'is', 'b', array( 'a', 'b' ) ), true );
check( 'cond AND all true', SC_Product_Options::evaluate_conditions( array( 'logic' => 'and', 'rules' => array( array( 'field' => 'size', 'op' => 'is', 'value' => 'L' ), array( 'field' => 'qty', 'op' => 'gte', 'value' => '5' ) ) ), $opts ), true );
check( 'cond AND one false', SC_Product_Options::evaluate_conditions( array( 'logic' => 'and', 'rules' => array( array( 'field' => 'size', 'op' => 'is', 'value' => 'M' ), array( 'field' => 'qty', 'op' => 'gte', 'value' => '5' ) ) ), $opts ), false );
check( 'cond OR one true', SC_Product_Options::evaluate_conditions( array( 'logic' => 'or', 'rules' => array( array( 'field' => 'size', 'op' => 'is', 'value' => 'M' ), array( 'field' => 'color', 'op' => 'is', 'value' => 'red' ) ) ), $opts ), true );
check( 'cond empty rules visible', SC_Product_Options::evaluate_conditions( array( 'rules' => array() ), $opts ), true );

/* ---- StoreCanvas lookup-table pricing (pure) ---- */
$lchoices = array(
	array( 'value' => 's', 'label' => 'Small', 'price' => 0.0 ),
	array( 'value' => 'm', 'label' => 'Medium', 'price' => 3.5 ),
	array( 'value' => 'l', 'label' => 'Large', 'price' => 7.0 ),
);
check( 'lookup single', SC_Cart_Order::lookup_price( $lchoices, 'm' ), 3.5 );
check( 'lookup multi sum', SC_Cart_Order::lookup_price( $lchoices, array( 'm', 'l' ) ), 10.5 );
check( 'lookup unknown zero', SC_Cart_Order::lookup_price( $lchoices, 'xl' ), 0.0 );

/* ---- TOC delivery-rate math (pure; pins the analytics card) ---- */
check( 'rates zero sent', TOC_Logger::compute_rates( array() ), array( 'delivered_rate' => 0.0, 'failed_rate' => 0.0, 'reply_rate' => 0.0 ) );
check( 'rates all delivered', TOC_Logger::compute_rates( array( 'sent' => 10, 'delivered' => 10, 'failed' => 0, 'replies' => 0 ) ), array( 'delivered_rate' => 100.0, 'failed_rate' => 0.0, 'reply_rate' => 0.0 ) );
check( 'rates mixed', TOC_Logger::compute_rates( array( 'sent' => 8, 'delivered' => 6, 'failed' => 2, 'replies' => 2 ) ), array( 'delivered_rate' => 75.0, 'failed_rate' => 25.0, 'reply_rate' => 25.0 ) );
check( 'rates rounding 1dp', TOC_Logger::compute_rates( array( 'sent' => 3, 'delivered' => 1, 'failed' => 0, 'replies' => 0 ) ), array( 'delivered_rate' => 33.3, 'failed_rate' => 0.0, 'reply_rate' => 0.0 ) );

/* ---- StoreCanvas option pricing (pure; pins the percent/qty/negative fixes) ---- */
check( 'price flat', SC_Cart_Order::price_for( 'flat', 5.0, 20.0, '1' ), 5.0 );
check( 'price percent of base', SC_Cart_Order::price_for( 'percent', 10.0, 20.0, '1' ), 2.0 );
check( 'price percent uses given base (variation)', SC_Cart_Order::price_for( 'percent', 10.0, 50.0, '1' ), 5.0 );
check( 'price qty multiplies value', SC_Cart_Order::price_for( 'qty', 3.0, 0.0, '4' ), 12.0 );
check( 'price qty not flat', SC_Cart_Order::price_for( 'qty', 3.0, 0.0, '1' ), 3.0 );
check( 'price qty array is zero', SC_Cart_Order::price_for( 'qty', 3.0, 0.0, array( 'x' ) ), 0.0 );
check( 'price per_char', SC_Cart_Order::price_for( 'per_char', 0.5, 0.0, 'abcd' ), 2.0 );
check( 'price negative flat (discount)', SC_Cart_Order::price_for( 'flat', -4.0, 20.0, '1' ), -4.0 );
check( 'price unknown type zero', SC_Cart_Order::price_for( 'bogus', 5.0, 20.0, '1' ), 0.0 );

/* ---- OrderBay template override candidates (pure; theme → plugin lookup) ---- */
check( 'tpl child theme first', OB_Documents::template_candidates( 'invoice.php', '/th/child', '/th/parent', '/plug/templates' ), array(
	'/th/child/orderbay/invoice.php',
	'/th/parent/orderbay/invoice.php',
	'/plug/templates/invoice.php',
) );
check( 'tpl same child/parent dedupes', OB_Documents::template_candidates( 'invoice.php', '/th/x', '/th/x', '/plug/templates' ), array(
	'/th/x/orderbay/invoice.php',
	'/plug/templates/invoice.php',
) );
check( 'tpl no theme falls to plugin', OB_Documents::template_candidates( 'rma-slip.php', '', '', '/plug/templates' ), array( '/plug/templates/rma-slip.php' ) );
check( 'tpl strips traversal', OB_Documents::template_candidates( '../../evil.php', '/th/child', '', '/plug/templates' ), array(
	'/th/child/orderbay/evil.php',
	'/plug/templates/evil.php',
) );

/* ---- OrderBay barcode (Code 128B) — regression pins ---- */
$bc = OB_Barcode::code128_svg( 'INV-42' );
check( 'barcode svg well-formed', false !== @simplexml_load_string( $bc ), true );
check( 'barcode has bars', strpos( $bc, '<rect' ) !== false, true );
check( 'barcode aria-label', strpos( $bc, 'aria-label="INV-42"' ) !== false, true );
check( 'barcode rejects non-ascii', OB_Barcode::code128_svg( "bad\xC3\xA9" ), '' );
check( 'barcode empty is start+check+stop', strpos( OB_Barcode::code128_svg( '' ), '<svg' ) === 0, true );

/* ---- OrderBay tracking-URL template sanitizer — regression pins ---- */
check( 'url tpl keeps token', OB_Fulfillment::sanitize_url_template( 'https://track.example.com/{tracking}' ), 'https://track.example.com/{tracking}' );
check( 'url tpl uppercase token normalized', OB_Fulfillment::sanitize_url_template( 'https://t/x?c={TRACKING}' ), 'https://t/x?c={tracking}' );
check( 'url tpl rejects non-http', OB_Fulfillment::sanitize_url_template( 'javascript:alert(1){tracking}' ), '' );
check( 'url tpl strips quotes/brackets', OB_Fulfillment::sanitize_url_template( 'https://t/<x>"q\'/{tracking}' ), 'https://t/xq/{tracking}' );
check( 'url tpl empty', OB_Fulfillment::sanitize_url_template( '' ), '' );

/* ---- StoreCanvas field-row sanitizer — regression pins ---- */
$scf = SC_Product_Options::sanitize_field_row( array( 'id' => 'My Field!', 'type' => 'Select', 'label' => ' Pick ', 'price_type' => 'flat', 'price' => '3.50', 'choices' => array( array( 'value' => 'a', 'label' => 'A', 'price' => '2' ), 'b' ) ) );
check( 'field id sanitized', $scf['id'], 'myfield' );
check( 'field type sanitized', $scf['type'], 'select' );
check( 'field label trimmed', $scf['label'], 'Pick' );
check( 'field price float', $scf['price'], 3.5 );
check( 'field choices normalized', count( $scf['choices'] ), 2 );
check( 'field choice per-price', $scf['choices'][0]['price'], 2.0 );
check( 'field per_char downgrades on non-text', SC_Product_Options::sanitize_field_row( array( 'id' => 'x', 'type' => 'select', 'price_type' => 'per_char' ) )['price_type'], 'flat' );

/* ---- OrderBay RMA item-level + status-email predicates (pure) ---- */
$maxes = array( 10 => 3, 11 => 1, 12 => 5 );
check( 'rma items clamp to max', OB_RMA::sanitize_rma_items( array( 10 => 5, 11 => 1, 12 => 2 ), $maxes ), array( 10 => 3, 11 => 1, 12 => 2 ) );
check( 'rma items drop zero/neg', OB_RMA::sanitize_rma_items( array( 10 => 0, 11 => -2, 12 => 4 ), $maxes ), array( 12 => 4 ) );
check( 'rma items drop unknown', OB_RMA::sanitize_rma_items( array( 99 => 2, 10 => 1 ), $maxes ), array( 10 => 1 ) );
check( 'rma items non-array', OB_RMA::sanitize_rma_items( 'x', $maxes ), array() );
$notify = array( 'approved', 'received', 'closed' );
check( 'rma email on approve', OB_RMA::should_email( 'requested', 'approved', $notify ), true );
check( 'rma email not on requested', OB_RMA::should_email( 'none', 'requested', $notify ), false );
check( 'rma email not on same', OB_RMA::should_email( 'approved', 'approved', $notify ), false );
check( 'rma email on closed', OB_RMA::should_email( 'received', 'closed', $notify ), true );

/* ---- OrderBay QR version selection (pure; pins the no-truncation fix) ---- */
check( 'qr v1 boundary', OB_QR::pick_version( 14 ), 1 );
check( 'qr v2 boundary', OB_QR::pick_version( 26 ), 2 );
check( 'qr v3 boundary', OB_QR::pick_version( 42 ), 3 );
check( 'qr v2 mid', OB_QR::pick_version( 20 ), 2 );
check( 'qr over capacity skips', OB_QR::pick_version( 43 ), 0 );
check( 'qr order-url length skips built-in', OB_QR::pick_version( 47 ), 0 );
check( 'qr empty skips', OB_QR::pick_version( 0 ), 0 );
check( 'qr library absent here', OB_QR::library_available(), false );
check( 'qr svg empty payload', OB_QR::svg( '' ), '' );
check( 'qr svg long payload no library skips', OB_QR::svg( str_repeat( 'x', 47 ) ), '' );

/* ---- OrderBay per-rate tax rows (pure; pins the tax-breakdown feature) ---- */
$tax_obj = array(
	(object) array( 'label' => 'VAT (20%)', 'amount' => 4.0 ),
	(object) array( 'label' => 'VAT (5%)', 'amount' => 1.0 ),
);
check( 'tax rows from objects', OB_Documents::normalize_tax_rows( $tax_obj ), array(
	array( 'label' => 'VAT (20%)', 'amount' => 4.0 ),
	array( 'label' => 'VAT (5%)', 'amount' => 1.0 ),
) );
check( 'tax rows from arrays', OB_Documents::normalize_tax_rows( array( array( 'label' => 'GST', 'amount' => 2.5 ) ) ), array( array( 'label' => 'GST', 'amount' => 2.5 ) ) );
check( 'tax rows empty label defaults', OB_Documents::normalize_tax_rows( array( array( 'amount' => 3.0 ) ) ), array( array( 'label' => 'Tax', 'amount' => 3.0 ) ) );
check( 'tax rows non-array', OB_Documents::normalize_tax_rows( null ), array() );

/* ---- OrderBay numbering-format expander (pure; pins the configurable-numbering feature) ---- */
$fts = gmmktime( 12, 0, 0, 8, 11, 2026 ); // 2026-08-11 UTC, deterministic
check( 'fmt back-compat default', OB_Invoicing::format_number( '{PREFIX}{SEQ}', 5, $fts, 'INV-' ), 'INV-5' );
check( 'fmt empty template defaults', OB_Invoicing::format_number( '', 5, $fts, 'INV-' ), 'INV-5' );
check( 'fmt year + padded seq', OB_Invoicing::format_number( '{PREFIX}{YYYY}-{SEQ:5}', 42, $fts, 'INV-' ), 'INV-2026-00042' );
check( 'fmt short year + month', OB_Invoicing::format_number( '{YY}{MM}-{SEQ}', 7, $fts, '' ), '2608-7' );
check( 'fmt day token', OB_Invoicing::format_number( '{DD}', 1, $fts, '' ), '11' );
check( 'fmt pad never truncates', OB_Invoicing::format_number( '{SEQ:3}', 1234, $fts, '' ), '1234' );
check( 'fmt unknown token kept', OB_Invoicing::format_number( 'X{FOO}{SEQ}', 3, $fts, '' ), 'X{FOO}3' );
check( 'fmt all tokens', OB_Invoicing::format_number( '{PREFIX}{YYYY}{MM}{DD}-{SEQ:4}', 9, $fts, 'AC/' ), 'AC/20260811-0009' );

/* ---- OB_Plugin::day_start_ts (pure; pins the store-timezone "today" fix) ----
 * Fixed epochs: 1786503600 = 2026-08-12 03:00 UTC; 1786566600 = 2026-08-12 20:30 UTC;
 * 1786536000 = 2026-08-12 12:00 UTC. Expected values are independently computed
 * local midnights, hardcoded so the test cannot mirror the implementation. */
check( 'day start UTC', OB_Plugin::day_start_ts( 1786503600, 'UTC' ), 1786492800 ); // 2026-08-12 00:00 UTC
check( 'day start NY (local date behind UTC)', OB_Plugin::day_start_ts( 1786503600, 'America/New_York' ), 1786420800 ); // 2026-08-11 00:00 EDT
check( 'day start Kolkata (local date ahead of UTC)', OB_Plugin::day_start_ts( 1786566600, 'Asia/Kolkata' ), 1786559400 ); // 2026-08-13 00:00 IST
check( 'day start Berlin', OB_Plugin::day_start_ts( 1786536000, 'Europe/Berlin' ), 1786485600 ); // 2026-08-12 00:00 CEST
check( 'day start Berlin minus 7 days', OB_Plugin::day_start_ts( 1786536000, 'Europe/Berlin', 7 ), 1785880800 ); // 2026-08-05 00:00 CEST
check( 'day start offset tz string', OB_Plugin::day_start_ts( 1786536000, '+02:00' ), 1786485600 ); // wp_timezone_string() can return raw offsets
check( 'day start empty tz falls back to UTC', OB_Plugin::day_start_ts( 1786503600, '' ), 1786492800 );
check( 'day start invalid tz falls back to UTC', OB_Plugin::day_start_ts( 1786503600, 'Not/AZone' ), 1786492800 );

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

/* ---- SC_Print_Ready artwork download proxy (pins H1: signed token + marker classifier) ---- */
$sc_secret = 'sc-unit-secret';
$sc_future = time() + 3600;
$sc_sig    = SC_Print_Ready::sign_token( $sc_secret, 123, $sc_future );
check( 'sc token verify ok', SC_Print_Ready::verify_token( $sc_secret, 123, $sc_future, $sc_sig ), true );
check( 'sc token wrong id', SC_Print_Ready::verify_token( $sc_secret, 124, $sc_future, $sc_sig ), false );
check( 'sc token wrong secret', SC_Print_Ready::verify_token( 'other', 123, $sc_future, $sc_sig ), false );
check( 'sc token expired', SC_Print_Ready::verify_token( $sc_secret, 123, time() - 5, $sc_sig ), false );
check( 'sc marker uploaded (get_post_meta shape)', SC_Print_Ready::is_sc_artwork_meta( array( '_sc_uploaded' => array( '1' ) ) ), true );
check( 'sc marker generated (flat)', SC_Print_Ready::is_sc_artwork_meta( array( '_sc_generated' => 1 ) ), true );
check( 'sc marker plain attachment', SC_Print_Ready::is_sc_artwork_meta( array( '_wp_attached_file' => array( 'x.png' ) ) ), false );
check( 'sc marker empty', SC_Print_Ready::is_sc_artwork_meta( array() ), false );
check( 'sc marker non-array', SC_Print_Ready::is_sc_artwork_meta( 'nope' ), false );

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
