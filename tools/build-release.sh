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
  python3 - "$zipname" "$folder" <<'PY'
import sys, zipfile
path, folder = sys.argv[1], sys.argv[2]
names = zipfile.ZipFile(path).namelist()
if not names:
    sys.exit("empty zip: " + path)
top = names[0]
if top != folder + "/":
    sys.exit("ERROR: %s top-level is %r (want %s/)" % (path, top, folder))
if any("license-server" in n for n in names):
    sys.exit("ERROR: %s contains license-server" % path)
if any(n == ".git" or n.endswith("/.git") or "/.git/" in n or n.startswith(".git/") for n in names):
    sys.exit("ERROR: %s contains .git" % path)
print("OK", path, "(%d files)" % len(names))
PY
}

build twilio-order-communicator "orderring-${toc_ver}.zip"
build storecanvas "storecanvas-${sc_ver}.zip"
build orderbay "orderbay-${ob_ver}.zip"

echo
echo "Register on the license host:"
echo "  php bin/add-release.php --slug=orderring --version=${toc_ver} --file=$ROOT/orderring-${toc_ver}.zip --changelog='…'"
echo "  php bin/add-release.php --slug=storecanvas --version=${sc_ver} --file=$ROOT/storecanvas-${sc_ver}.zip --changelog='…'"
echo "  php bin/add-release.php --slug=orderbay --version=${ob_ver} --file=$ROOT/orderbay-${ob_ver}.zip --changelog='…'"
