<?php
/**
 * TOC License Server — copy to config.php and edit.
 */
return array(
	// Shared secret for admin CLI / optional HMAC (keep private).
	'admin_token'     => 'change-me-to-a-long-random-string',

	// SQLite path (writable). Relative paths are from license-server/.
	'db_path'         => __DIR__ . '/data/licenses.sqlite',

	// Product slug this server licenses.
	'item_slug'       => 'orderring',

	// Directory for zip packages referenced by releases.package_path (relative filenames).
	'releases_dir'    => __DIR__ . '/storage/releases',

	// Public base URL of this license server (no trailing slash), used to build download URLs.
	// Example: https://licenses.example.com
	'public_base_url' => '',

	// Signed download URL TTL in seconds.
	'download_ttl'    => 3600,

	// HMAC secret for signed download tokens (defaults to admin_token if empty).
	// Required: signed downloads fail closed unless this OR admin_token is set to a
	// real random value (the placeholder above does not count).
	'download_secret' => '',

	// CORS (usually leave empty; plugins call server-to-server).
	'allowed_origins' => array(),

	// Per-IP, per-endpoint rate limit for activate / validate / update-check.
	// Generous for real stores (which validate ~daily) but blunts key brute forcing.
	'rate_limit_max'    => 60,
	'rate_limit_window' => 3600,

	// ---- Purchases (Stripe Checkout → automatic license key) -----------------
	// Endpoint secret (whsec_...) for a Stripe webhook pointed at
	// POST /v1/stripe-webhook with the events checkout.session.completed and
	// checkout.session.async_payment_succeeded. Empty disables the endpoint (503).
	'stripe_webhook_secret' => '',

	// From/Reply-To for the license-key email sent to buyers. Empty = no buyer
	// email (the sale is still recorded; mint/resend manually). Use a mailbox
	// on a domain with SPF for this host or the mail will land in spam.
	'from_email'   => '',

	// Owner notification address — receives a copy of every sale and every
	// needs-review payment. Empty disables.
	'notify_email' => '',

	// Purchase tiers: item_slug => tier => license terms. Every Stripe Payment
	// Link must carry metadata item_slug + tier matching an entry here. 'days'
	// omitted or 0 = lifetime key. Tier keys match docs/launch/site/buy.js.
	// NOTE: monthly subscription products (Checkout Sentinel) renew via Stripe
	// invoices, which this endpoint does not yet extend keys for — sell those
	// tiers annual (below) or extend keys manually until an invoice.paid
	// handler exists.
	'purchase_tiers' => array(
		'orderring'         => array(
			'pro'    => array( 'sites' => 1, 'days' => 365 ),
			'agency' => array( 'sites' => 5, 'days' => 365 ),
		),
		'orderbay'          => array(
			'pro'    => array( 'sites' => 1, 'days' => 365 ),
			'agency' => array( 'sites' => 5, 'days' => 365 ),
		),
		'storecanvas'       => array(
			'pro-year'        => array( 'sites' => 1, 'days' => 365 ),
			'pro-lifetime'    => array( 'sites' => 1 ),
			'agency-lifetime' => array( 'sites' => 5 ),
		),
		'cardingdesk'       => array(
			'pro'    => array( 'sites' => 1, 'days' => 365 ),
			'agency' => array( 'sites' => 5, 'days' => 365 ),
		),
		'checkout-sentinel' => array(
			'1'  => array( 'sites' => 1, 'days' => 365 ),
			'3'  => array( 'sites' => 3, 'days' => 365 ),
			'10' => array( 'sites' => 10, 'days' => 365 ),
		),
		'bcfe'              => array(
			'pro'    => array( 'sites' => 1, 'days' => 365 ),
			'agency' => array( 'sites' => 5, 'days' => 365 ),
		),
		'layoutguard'       => array(
			'10'        => array( 'sites' => 10, 'days' => 365 ),
			'unlimited' => array( 'sites' => 999, 'days' => 365 ),
		),
		'formdrop'          => array(
			'pro'       => array( 'sites' => 1, 'days' => 365 ),
			'agency'    => array( 'sites' => 5, 'days' => 365 ),
			'agency-25' => array( 'sites' => 25, 'days' => 365 ),
		),
	),
);
