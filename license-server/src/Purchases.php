<?php
/**
 * Purchase intake: Stripe Checkout webhook → license key + email.
 *
 * Point a Stripe webhook (checkout.session.completed and
 * checkout.session.async_payment_succeeded) at POST /v1/stripe-webhook.
 * Every Payment Link / Checkout Session must carry metadata:
 *   item_slug — product slug matching config purchase_tiers (e.g. "orderring")
 *   tier      — tier key within that product (e.g. "pro", "agency")
 * A paid session with resolvable metadata mints a license bound to the
 * product and emails it to the buyer. A paid session with missing or
 * unknown metadata is still recorded (status "needs-review") so no
 * payment is ever lost.
 */

class TOC_License_Purchases {

	/** @var TOC_License_DB */
	private $db;

	/** @var array */
	private $config;

	public function __construct( TOC_License_DB $db, array $config ) {
		$this->db     = $db;
		$this->config = $config;
	}

	/**
	 * Parse a Stripe-Signature header into its timestamp and v1 signatures.
	 *
	 * @param string $header Raw header value ("t=...,v1=...,v1=...").
	 * @return array{0:int,1:string[]}
	 */
	public static function parse_signature_header( $header ) {
		$t    = 0;
		$sigs = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( count( $kv ) !== 2 ) {
				continue;
			}
			if ( $kv[0] === 't' ) {
				$t = (int) $kv[1];
			} elseif ( $kv[0] === 'v1' ) {
				$sigs[] = $kv[1];
			}
		}
		return array( $t, $sigs );
	}

	/**
	 * Verify a Stripe webhook signature (v1 scheme, no SDK needed):
	 * HMAC-SHA256 of "{timestamp}.{payload}" with the endpoint secret.
	 *
	 * @param string   $payload   Raw request body.
	 * @param string   $header    Stripe-Signature header value.
	 * @param string   $secret    Endpoint secret (whsec_...).
	 * @param int      $tolerance Max clock skew in seconds.
	 * @param int|null $now       Injectable clock for tests.
	 * @return bool
	 */
	public static function verify_stripe_signature( $payload, $header, $secret, $tolerance = 300, $now = null ) {
		if ( (string) $secret === '' ) {
			return false;
		}
		list( $t, $sigs ) = self::parse_signature_header( $header );
		$now = null === $now ? time() : (int) $now;
		if ( $t <= 0 || abs( $now - $t ) > (int) $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, (string) $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve purchase metadata to license terms. Pure.
	 *
	 * @param array    $tiers     config purchase_tiers map.
	 * @param string   $item_slug Product slug from session metadata.
	 * @param string   $tier      Tier key from session metadata.
	 * @param int|null $now       Injectable clock for tests.
	 * @return array{0:int,1:?string}|null [max_sites, expires_at ISO or null for lifetime], or null when unknown.
	 */
	public static function resolve_tier( array $tiers, $item_slug, $tier, $now = null ) {
		$def = isset( $tiers[ $item_slug ][ $tier ] ) && is_array( $tiers[ $item_slug ][ $tier ] )
			? $tiers[ $item_slug ][ $tier ]
			: null;
		if ( ! $def ) {
			return null;
		}
		$sites   = max( 1, (int) ( $def['sites'] ?? 1 ) );
		$days    = isset( $def['days'] ) ? (int) $def['days'] : 0;
		$expires = $days > 0 ? gmdate( 'c', ( null === $now ? time() : (int) $now ) + $days * 86400 ) : null;
		return array( $sites, $expires );
	}

	/**
	 * Handle POST /v1/stripe-webhook. Always exits via respond().
	 */
	public function handle() {
		$secret = (string) ( $this->config['stripe_webhook_secret'] ?? '' );
		if ( $secret === '' ) {
			TOC_License_Helpers::respond( 503, array( 'success' => false, 'error' => 'Stripe webhook not configured.' ) );
		}

		$payload = (string) file_get_contents( 'php://input' );
		$header  = (string) ( $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '' );
		if ( ! self::verify_stripe_signature( $payload, $header, $secret ) ) {
			TOC_License_Helpers::respond( 400, array( 'success' => false, 'error' => 'Invalid signature.' ) );
		}

		$event = json_decode( $payload, true );
		$type  = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
		if ( 'checkout.session.completed' !== $type && 'checkout.session.async_payment_succeeded' !== $type ) {
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'ignored' => $type ) );
		}

		$session = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		$ref     = (string) ( $session['id'] ?? '' );
		$paid    = ( $session['payment_status'] ?? '' ) === 'paid';
		if ( '' === $ref || ! $paid ) {
			// Unpaid completed sessions (async payment methods) finish later via
			// checkout.session.async_payment_succeeded — nothing to do yet.
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'ignored' => $paid ? 'no-session-id' : 'not-paid' ) );
		}

		$meta   = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$slug   = trim( (string) ( $meta['item_slug'] ?? '' ) );
		$tier   = trim( (string) ( $meta['tier'] ?? '' ) );
		$email  = trim( (string) ( $session['customer_details']['email'] ?? ( $session['customer_email'] ?? '' ) ) );
		$amount = (int) ( $session['amount_total'] ?? 0 );
		$curr   = (string) ( $session['currency'] ?? '' );

		$tiers    = isset( $this->config['purchase_tiers'] ) && is_array( $this->config['purchase_tiers'] ) ? $this->config['purchase_tiers'] : array();
		$resolved = ( '' !== $slug && '' !== $tier ) ? self::resolve_tier( $tiers, $slug, $tier ) : null;
		$status   = $resolved ? 'processing' : 'needs-review';

		// The UNIQUE provider_ref insert is the idempotency lock: a Stripe retry or a
		// concurrent duplicate delivery loses the insert and returns here.
		if ( ! $this->db->insert_purchase( 'stripe', $ref, $email, $slug, $tier, $amount, $curr, $status ) ) {
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'duplicate' => true ) );
		}

		if ( ! $resolved ) {
			error_log( 'TOC License Server: paid Stripe session ' . $ref . ' has unresolvable metadata (item_slug=' . $slug . ', tier=' . $tier . ') — recorded as needs-review, no key minted.' );
			$this->notify_owner( 'NEEDS REVIEW: paid Stripe session ' . $ref, "A paid checkout arrived with unknown product metadata.\n\nSession: {$ref}\nEmail: {$email}\nitem_slug: {$slug}\ntier: {$tier}\nAmount: {$amount} {$curr}\n\nMint a key manually with bin/create-key.php and email the buyer." );
			TOC_License_Helpers::respond( 200, array( 'success' => true, 'needs_review' => true ) );
		}

		list( $sites, $expires ) = $resolved;
		$key = TOC_License_Helpers::generate_key();
		while ( $this->db->get_license( $key ) ) {
			$key = TOC_License_Helpers::generate_key();
		}
		$this->db->create_license( $key, $slug, $sites, $expires, $email !== '' ? $email : null, 'stripe:' . $ref );

		$emailed = '' !== $email && $this->send_key_email( $email, $slug, $key, $sites, $expires );
		$this->db->finish_purchase( 'stripe', $ref, $key, $emailed ? 'ok' : 'ok-no-email' );
		if ( ! $emailed ) {
			error_log( 'TOC License Server: license ' . $key . ' minted for Stripe session ' . $ref . ' but the buyer email could not be sent — resend manually to ' . ( $email !== '' ? $email : '(no email on session)' ) . '.' );
		}
		$this->notify_owner(
			'Sale: ' . $slug . ' ' . $tier . ' (' . $amount . ' ' . strtoupper( $curr ) . ')',
			"Product: {$slug} ({$tier})\nBuyer: {$email}\nKey: {$key}\nSites: {$sites}\nExpires: " . ( $expires ?: 'lifetime' ) . "\nStripe session: {$ref}\nBuyer emailed: " . ( $emailed ? 'yes' : 'NO — resend manually' )
		);

		TOC_License_Helpers::respond( 200, array( 'success' => true, 'licensed' => true, 'emailed' => $emailed ) );
	}

	/**
	 * Email the buyer their key. Best-effort; failure is recorded, never fatal.
	 *
	 * @return bool Whether mail() accepted the message.
	 */
	private function send_key_email( $to, $slug, $key, $sites, $expires ) {
		$from = (string) ( $this->config['from_email'] ?? '' );
		if ( '' === $from ) {
			return false;
		}
		$subject = 'Your ' . $slug . ' license key';
		$body    = "Thanks for your purchase!\n\n"
			. "Product:  {$slug}\n"
			. "Key:      {$key}\n"
			. "Sites:    {$sites}\n"
			. 'Updates:  ' . ( $expires ? 'until ' . substr( $expires, 0, 10 ) : 'lifetime' ) . "\n\n"
			. "Activate it in wp-admin under the plugin's License tab. The plugin keeps\n"
			. "working even if the key ever lapses — a key only gates premium updates.\n\n"
			. "Keep this email; the key is not shown again. Reply to this address for help.\n";
		$headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $from . "\r\n";
		return @mail( $to, $subject, $body, $headers ); // phpcs:ignore
	}

	/**
	 * Notify the store owner (config notify_email). Best-effort.
	 */
	private function notify_owner( $subject, $body ) {
		$to   = (string) ( $this->config['notify_email'] ?? '' );
		$from = (string) ( $this->config['from_email'] ?? '' );
		if ( '' === $to ) {
			return;
		}
		$headers = $from !== '' ? 'From: ' . $from . "\r\n" : '';
		@mail( $to, '[license-server] ' . $subject, $body, $headers ); // phpcs:ignore
	}
}
