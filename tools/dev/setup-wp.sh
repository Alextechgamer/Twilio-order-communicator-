#!/usr/bin/env bash
#
# Local development environment for the plugin suite.
#
# Stands up WordPress + WooCommerce + MariaDB with all three plugins in this repo
# symlinked and activated, using PHP's built-in web server (no Docker required).
# Idempotent: safe to re-run. Tested on Ubuntu 24.04 / PHP 8.3.
#
# Usage:  REPO_DIR=/workspace WP_DIR=$HOME/wordpress bash tools/dev/setup-wp.sh
#
set -euo pipefail

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
WP_DIR="${WP_DIR:-$HOME/wordpress}"
SITE_URL="${SITE_URL:-http://localhost:8080}"
DB_NAME="${DB_NAME:-wordpress}"
DB_USER="${DB_USER:-wp}"
DB_PASS="${DB_PASS:-wp}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

# ── 1. System packages ────────────────────────────────────────────────────────
# php8.3-sqlite3 is required by license-server/ (PDO SQLite) and by the sqlite
# assertions in tests/run.php; the rest is the standard WP stack.
sudo apt-get update -qq
sudo apt-get install -y -qq php8.3-cli php8.3-mysql php8.3-curl php8.3-gd \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl php8.3-sqlite3 \
  mariadb-server curl unzip

# ── 2. WP-CLI ─────────────────────────────────────────────────────────────────
if ! command -v wp >/dev/null 2>&1; then
  curl -sSLo /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x /tmp/wp-cli.phar && sudo mv /tmp/wp-cli.phar /usr/local/bin/wp
fi

# ── 3. Database ───────────────────────────────────────────────────────────────
sudo service mariadb start
sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME};
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# ── 4. WordPress core + config ────────────────────────────────────────────────
# NOTE: DB host must be 127.0.0.1, not localhost — PHP's mysqli cannot resolve the
# MariaDB unix socket via "localhost" in this sandbox; TCP works.
mkdir -p "$WP_DIR"
cd "$WP_DIR"
[ -f wp-load.php ] || wp core download --allow-root
[ -f wp-config.php ] || wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" \
  --dbpass="$DB_PASS" --dbhost=127.0.0.1 --allow-root
wp core is-installed --allow-root 2>/dev/null || wp core install --url="$SITE_URL" \
  --title="Dev" --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
  --admin_email=admin@example.com --skip-email --allow-root

# Debug logging on (wp-content/debug.log) — E2 of the verification matrix.
wp config set WP_DEBUG true --raw --allow-root
wp config set WP_DEBUG_LOG true --raw --allow-root
wp config set WP_DEBUG_DISPLAY false --raw --allow-root

# ── 5. WooCommerce + the three plugins from this repo ─────────────────────────
wp plugin is-installed woocommerce --allow-root || wp plugin install woocommerce --allow-root
wp plugin activate woocommerce --allow-root
for p in twilio-order-communicator storecanvas orderbay; do
  ln -sfn "$REPO_DIR/$p" "$WP_DIR/wp-content/plugins/$p"
done
wp plugin activate twilio-order-communicator storecanvas orderbay --allow-root

# ── 6. Test-support mu-plugins (Twilio HTTP mock + wp_mail capture) ───────────
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cp "$REPO_DIR"/tools/dev/mu-plugins/*.php "$WP_DIR/wp-content/mu-plugins/"

# ── 7. Serve ──────────────────────────────────────────────────────────────────
echo
echo "Done. Start the site with:"
echo "  php -S 0.0.0.0:8080 -t $WP_DIR"
echo "Admin: $SITE_URL/wp-admin ($ADMIN_USER/$ADMIN_PASS)"
echo
echo "License server (separate terminal):"
echo "  cp $REPO_DIR/license-server/config.example.php $REPO_DIR/license-server/config.php  # then edit secrets"
echo "  cd $REPO_DIR/license-server/public && php -S 127.0.0.1:8081"
