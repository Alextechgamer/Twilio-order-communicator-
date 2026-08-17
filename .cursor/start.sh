#!/usr/bin/env bash
#
# Per-boot service startup. Brings up the MariaDB daemon the WordPress site
# depends on, then hands off to the PHP dev web server, which stays attached in
# the foreground for the lifetime of the environment (site on :8080).
set -euo pipefail

WP_PATH="${WP_PATH:-$HOME/wordpress}"

echo "==> Starting MariaDB"
sudo service mariadb start >/dev/null 2>&1 || true
for _ in $(seq 1 30); do
  if sudo mariadb -e "SELECT 1;" >/dev/null 2>&1; then
    echo "==> MariaDB is accepting connections"
    break
  fi
  sleep 1
done

echo "==> Starting PHP dev server on http://localhost:8080 (docroot: $WP_PATH)"
exec php -S 0.0.0.0:8080 -t "$WP_PATH"
