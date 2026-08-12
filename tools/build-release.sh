#!/usr/bin/env bash
# Build customer zips for OrderRing, StoreCanvas, and OrderBay.
# Run from the repo root. Zips are gitignored.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

toc_ver="$(grep -E "define\( 'TOC_VERSION'" twilio-order-communicator/twilio-order-communicator.php | sed -E "s/.*'([0-9.]+)'.*/\1/")"
sc_ver="$(grep -E "define\( 'SC_VERSION'" storecanvas/storecanvas.php | sed -E "s/.*'([0-9.]+)'.*/\1/")"
ob_ver="$(grep -E "define\( 'OB_VERSION'" orderbay/orderbay.php | sed -E "s/.*'([0-9.]+)'.*/\1/")"

build() {
  local folder="$1" zipname="$2"
  rm -f "$zipname"
  zip -r -X "$zipname" "$folder" \
    -x '*/.git/*' '*/.git' \
    -x '*/node_modules/*' \
    -x '*.DS_Store' '*/.DS_Store' \
    -x '*.map' \
    -x '*/vendor/*' \
    -x '*.zip' >/dev/null
  local top
  top="$(unzip -Z -1 "$zipname" | head -1)"
  if [[ "$top" != "$folder/" ]]; then
    echo "ERROR: $zipname top-level is '$top' (want $folder/)" >&2
    exit 1
  fi
  if unzip -l "$zipname" | grep -q license-server; then
    echo "ERROR: $zipname contains license-server" >&2
    exit 1
  fi
  if unzip -Z -1 "$zipname" | grep -qE '(^|/)\.git(/|$)'; then
    echo "ERROR: $zipname contains .git" >&2
    exit 1
  fi
  echo "OK $zipname  ($(unzip -l "$zipname" | tail -1))"
}

build twilio-order-communicator "orderring-${toc_ver}.zip"
build storecanvas "storecanvas-${sc_ver}.zip"
build orderbay "orderbay-${ob_ver}.zip"

echo
echo "Register on the license host:"
echo "  php bin/add-release.php --slug=orderring --version=${toc_ver} --file=$ROOT/orderring-${toc_ver}.zip --changelog='…'"
echo "  php bin/add-release.php --slug=storecanvas --version=${sc_ver} --file=$ROOT/storecanvas-${sc_ver}.zip --changelog='…'"
echo "  php bin/add-release.php --slug=orderbay --version=${ob_ver} --file=$ROOT/orderbay-${ob_ver}.zip --changelog='…'"
