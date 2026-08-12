#!/usr/bin/env bash
#
# Per-boot service reconciliation. Starts the MariaDB daemon that the WordPress
# site depends on. The PHP dev web server runs as a visible terminal (see the
# "terminals" entry in environment.json), not here.
set -euo pipefail

echo "==> Starting MariaDB"
sudo service mariadb start >/dev/null 2>&1 || true

for _ in $(seq 1 30); do
  if sudo mariadb -e "SELECT 1;" >/dev/null 2>&1; then
    echo "==> MariaDB is accepting connections"
    exit 0
  fi
  sleep 1
done

echo "!! MariaDB did not become ready in time" >&2
exit 1
