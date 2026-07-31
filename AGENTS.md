# AGENTS.md

## Cursor Cloud specific instructions

This repo is a single **WordPress / WooCommerce plugin** (`twilio-order-communicator/`, "Twilio Order Communicator"). There is no build step and no automated test suite. The "application" you run to test changes is a local WordPress site with WooCommerce active and this plugin activated.

### What's already provisioned in the VM snapshot

- PHP 8.3 (CLI + extensions: mysqli, curl, gd, mbstring, xml, zip, intl, bcmath, soap, imagick), Composer, WP-CLI (`wp`), and MariaDB.
- A ready WordPress install at `~/wordpress` (WordPress + WooCommerce active) with this repo's plugin **symlinked** into it: `~/wordpress/wp-content/plugins/twilio-order-communicator -> /workspace/twilio-order-communicator`. Editing plugin files in `/workspace` is picked up live — no reinstall needed.
- PHPCS + WordPress Coding Standards + PHPCompatibility installed under `~/php-tools`.
- WP admin login: user `admin` / password `admin`. Site URL: `http://localhost:8080`.

### Starting services (not done by the update script)

Run these each session before browser/manual testing:

- Start the database: `sudo service mariadb start`
- Start the dev web server (use tmux so it persists): `php -S 0.0.0.0:8080 -t ~/wordpress`
- Site: `http://localhost:8080` — admin: `http://localhost:8080/wp-admin` (`admin` / `admin`).

### Lint

From `/workspace`: `~/php-tools/vendor/bin/phpcs --standard=phpcs.xml.dist -p` (auto-fix a subset with `phpcbf`). The existing code reports pre-existing WPCS style warnings/errors — that is expected, not a setup failure.

### Non-obvious gotchas

- **DB host must be `127.0.0.1`, not `localhost`.** PHP's mysqli cannot find the MariaDB unix socket via `localhost` in this environment (`No such file or directory`); TCP on `127.0.0.1` works. `~/wordpress/wp-config.php` is already set to `127.0.0.1`.
- **HPOS is disabled**, so orders are classic `shop_order` posts. Edit an order at `wp-admin/post.php?post=<ID>&action=edit` (not the HPOS `wc-orders` screen). The plugin's "Customer Communications" meta box appears there.
- **Twilio is bring-your-own.** Placeholder/fake credentials are stored in options so the code paths run, but real SMS/voice cannot send without valid Twilio creds. With fake creds, the Connection Test and auto voice call correctly report `Twilio rejected credentials: Authenticate`. Set real creds under **WooCommerce → Order Communicator → Settings** to actually send.
- **Auto-notify runs once per status.** To re-test, clear the order meta `_toc_notified_ready_for_pickup_at` / `_toc_notified_shipped_at`, then move the order into that status again.
- Use WP-CLI as `wp <cmd> --allow-root` from `~/wordpress`. Outbound email is not configured (`sendmail` missing) — order-email warnings during CLI order operations are harmless.

### Hello-world sanity check

Create a WooCommerce order with a billing phone, move it to the **Ready for Pickup** status, and confirm the plugin writes auto-notify order notes and stamps `_toc_notified_ready_for_pickup_at`.
