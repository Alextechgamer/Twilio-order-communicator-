#!/usr/bin/env bash
#
# E2E smoke test: WooCommerce order → Ready for Pickup → outbound SMS captured
# by the Twilio HTTP mock mu-plugin (tools/dev/mu-plugins/dev-http-mock.php).
#
# Requires a WordPress install prepared by tools/dev/setup-wp.sh. No live Twilio
# credentials needed: placeholder creds satisfy TOC_Twilio::is_configured() and
# the mock intercepts the request before any network call, logging it to
# /tmp/toc-http.log. Exits non-zero if the auto-send path stops firing.
#
# Usage:  WP_DIR=$HOME/wordpress bash tools/dev/e2e-smoke.sh
#
set -euo pipefail

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
WP_DIR="${WP_DIR:-$HOME/wordpress}"

wpc() { wp --path="$WP_DIR" --allow-root "$@"; }

# Placeholder Twilio credentials — never sent anywhere, the mock preempts the
# HTTP request. They only need to be non-empty so send_sms() does not bail.
wpc option update toc_account_sid 'ACe2e00000000000000000000000000000'
wpc option update toc_auth_token 'e2e-placeholder-token'
wpc option update toc_from_number '+15005550006'

# Auto-notify on Ready for Pickup via SMS (voice off keeps the log to one entry).
wpc option update toc_auto_ready_enabled 1
wpc option update toc_auto_ready_sms 1
wpc option update toc_auto_ready_voice 0

# Make sure nothing left the mock disabled or a quiet-hours window active.
wpc option delete dev_http_mock_disabled 2>/dev/null || true
wpc option update toc_quiet_hours_enabled 0

wpc eval-file "$REPO_DIR/tools/dev/e2e-smoke.php"
