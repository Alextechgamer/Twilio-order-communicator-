#!/usr/bin/env bash
#
# Idempotent repository bootstrap for the Twilio Order Communicator monorepo.
#
# System packages (PHP 8.3 + extensions, MariaDB, Composer, WP-CLI) and the base
# WordPress install ship in the environment's base snapshot, so this script only
# reconciles repository-derived state:
#   * the PHPCS/WPCS lint toolchain under ~/php-tools
#   * the plugin symlink into the WordPress plugins directory
#   * WordPress + WooCommerce app state (guarded; recreated only if missing)
#
# Every step is guarded so re-running from the snapshot is a fast no-op, and the
# script still fully reconstructs the site when run against a bare base image.
set -euo pipefail

WORKSPACE="${WORKSPACE:-/workspace}"
WP_PATH="${WP_PATH:-$HOME/wordpress}"
PHP_TOOLS="${PHP_TOOLS:-$HOME/php-tools}"
PLUGIN_SRC="$WORKSPACE/twilio-order-communicator"
SITE_URL="http://localhost:8080"

wp() { command wp --path="$WP_PATH" --allow-root "$@"; }

echo "==> Refreshing lint toolchain in $PHP_TOOLS"
mkdir -p "$PHP_TOOLS"
cp "$WORKSPACE/composer.json" "$PHP_TOOLS/composer.json"
( cd "$PHP_TOOLS" && composer install --no-interaction --no-progress )

echo "==> Ensuring MariaDB is running (needed for WordPress bootstrap)"
sudo service mariadb start >/dev/null 2>&1 || true
for _ in $(seq 1 30); do
  if sudo mariadb -e "SELECT 1;" >/dev/null 2>&1; then break; fi
  sleep 1
done

echo "==> Ensuring WordPress database and user exist"
sudo mariadb <<'SQL' || true
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY 'wp';
CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wp'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wp'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ ! -f "$WP_PATH/wp-load.php" ]; then
  echo "==> Downloading WordPress core into $WP_PATH"
  mkdir -p "$WP_PATH"
  wp core download
fi

if [ ! -f "$WP_PATH/wp-config.php" ]; then
  echo "==> Creating wp-config.php (DB host 127.0.0.1)"
  # PHP mysqli cannot reach the MariaDB unix socket via 'localhost' in this
  # environment; TCP on 127.0.0.1 works.
  wp config create \
    --dbname=wordpress --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 \
    --skip-check --force
fi

if ! wp core is-installed >/dev/null 2>&1; then
  echo "==> Installing WordPress"
  wp core install \
    --url="$SITE_URL" --title="TOC Dev" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com \
    --skip-email
fi

echo "==> Symlinking the plugin under test into WordPress"
ln -sfn "$PLUGIN_SRC" "$WP_PATH/wp-content/plugins/twilio-order-communicator"

if ! wp plugin is-installed woocommerce >/dev/null 2>&1; then
  echo "==> Installing WooCommerce"
  wp plugin install woocommerce
fi
wp plugin activate woocommerce >/dev/null 2>&1 || true
wp plugin activate twilio-order-communicator >/dev/null 2>&1 || true

echo "==> Disabling HPOS (orders stay classic shop_order posts)"
wp option update woocommerce_custom_orders_table_enabled no >/dev/null 2>&1 || true
wp option update woocommerce_feature_custom_order_tables_enabled no >/dev/null 2>&1 || true

# Placeholder Twilio credentials so the plugin's send code paths execute.
# Real SMS/voice require valid credentials set under
# WooCommerce -> Order Communicator -> Settings.
if [ -z "$(wp option get toc_account_sid 2>/dev/null || true)" ]; then
  echo "==> Seeding placeholder Twilio credentials"
  wp option update toc_account_sid "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" >/dev/null 2>&1 || true
  wp option update toc_auth_token "0123456789abcdef0123456789abcdef" >/dev/null 2>&1 || true
  wp option update toc_from_number "+15005550006" >/dev/null 2>&1 || true
fi

echo "==> Install complete"
