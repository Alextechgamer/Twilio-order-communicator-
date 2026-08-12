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
);
