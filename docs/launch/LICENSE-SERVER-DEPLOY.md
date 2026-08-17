# License-server production deploy

The plugin code is launch-ready. **Charging money still requires this service on a real host with TLS.** There is no production domain in this repo — fill `DOMAIN` and SSH into your VPS, then run the steps below.

**Never commit `config.php`.** Real `admin_token` / `download_secret` live only on the host.

Invalid licenses must never disable SMS/voice/chat/WhatsApp — that is enforced in the plugin, not here. This server only mints update URLs.

---

## 0. What you are deploying

Upload the `license-server/` tree (not the WordPress plugins) to the host.

| Path | Public? |
|------|---------|
| `public/` | **Document root** — only this is web-accessible |
| `public/index.php` | Front controller |
| `config.php` | **Private** (parent of `public/`) |
| `data/licenses.sqlite` | **Private** — Apache `.htaccess` deny + nginx deny |
| `storage/releases/*.zip` | **Private** — served only via signed `/v1/download` |

---

## 1. Host requirements

- Ubuntu 22.04/24.04 (or equivalent) with a public IPv4/IPv6
- DNS: `A`/`AAAA` for `DOMAIN` (example: `licenses.yourdomain.com`) pointing at the host
- PHP 8.1+ with `pdo_sqlite`, `json`, `openssl`
- nginx **or** Apache
- Certbot (Let’s Encrypt)

```bash
# Ubuntu
sudo apt-get update
sudo apt-get install -y php-fpm php-sqlite3 php-json php-mbstring unzip
# plus one of:
sudo apt-get install -y nginx certbot python3-certbot-nginx
# or:
sudo apt-get install -y apache2 libapache2-mod-php certbot python3-certbot-apache
```

---

## 2. Install the app

```bash
DOMAIN=licenses.example.com          # <-- your hostname
APP_ROOT=/var/www/license-server     # <-- on-host path

sudo mkdir -p "$APP_ROOT"
# from your laptop, after cloning this repo:
rsync -a --delete \
  --exclude config.php \
  --exclude 'data/*.sqlite*' \
  --exclude 'storage/releases/*.zip' \
  ./license-server/ "root@${DOMAIN}:${APP_ROOT}/"

ssh root@"$DOMAIN" bash -s <<EOF
set -euo pipefail
cd $APP_ROOT
if [ ! -f config.php ]; then
  cp config.example.php config.php
  php bin/generate-secrets.php --write
fi
# If generate-secrets.php --write is not used, edit config.php by hand:
#   admin_token     = output of: openssl rand -hex 32
#   download_secret = output of: openssl rand -hex 32
#   public_base_url = https://$DOMAIN
#   item_slug       = orderring
mkdir -p data storage/releases
chown -R www-data:www-data data storage
chmod 750 data storage
chmod 640 config.php
EOF
```

`config.php` must **not** keep the placeholder `change-me-to-a-long-random-string`. Signed downloads fail closed if both secrets are empty/placeholder (verified in `docs/RUNTIME-VERIFICATION.md` A5).

---

## 3a. nginx vhost

Copy `license-server/deploy/nginx.conf.example`, replace `licenses.example.com` and `/var/www/license-server`, then:

```bash
sudo cp /var/www/license-server/deploy/nginx.conf.example /etc/nginx/sites-available/license-server
sudo ln -sf /etc/nginx/sites-available/license-server /etc/nginx/sites-enabled/license-server
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d "$DOMAIN"
```

Document root **must** be `$APP_ROOT/public`. The deny block in the example returns 404 for `/data/` and `/storage/` even if someone later points the root at the parent.

PHP-FPM socket path varies (`/run/php/php8.3-fpm.sock` on Ubuntu 24.04). Confirm with `ls /run/php/`.

## 3b. Apache vhost

```bash
sudo a2enmod rewrite ssl headers
sudo cp /var/www/license-server/deploy/apache.conf.example /etc/apache2/sites-available/license-server.conf
sudo a2ensite license-server
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo certbot --apache -d "$DOMAIN"
```

`public/.htaccess` already rewrites to `index.php`. `data/.htaccess` and `storage/.htaccess` are deny-all (Apache 2.2 + 2.4). **nginx ignores `.htaccess`** — use 3a.

---

## 4. Smoke test (must pass before selling)

Replace `https://licenses.example.com` with your `public_base_url`.

```bash
BASE=https://licenses.example.com

# Health
curl -fsS "$BASE/v1/health"
# {"ok":true,"service":"toc-license-server"}

# Deny rules — must be 403 or 404, never the SQLite file or a zip
curl -sI "$BASE/data/licenses.sqlite" | head -1          # 404/403
curl -sI "$BASE/storage/releases/orderring-1.20.0.zip" | head -1   # 404/403

# Create a key (on the host)
cd /var/www/license-server
php bin/create-key.php --email=smoke@example.com --sites=1 --expires=lifetime
# prints TOC-…. Store it; it is shown once.

# Register a release (zip built per RELEASE.md, copied to the host)
php bin/add-release.php \
  --slug=orderring \
  --version=1.20.0 \
  --file=/path/to/orderring-1.20.0.zip \
  --changelog="OrderRing rename; A2P 10DLC guidance"

# Activate
curl -fsS -X POST "$BASE/v1/activate" \
  -H 'Content-Type: application/json' \
  -d '{"license_key":"TOC-…","site_url":"https://store.example","instance_id":"inst-smoke-001","plugin_version":"1.20.0"}'
# {"success":true,"status":"active",…}

# Update-check WITH site binding → 200 + signed package_url
curl -fsS "$BASE/v1/update-check?slug=orderring&version=1.19.1&site_url=https://store.example&instance_id=inst-smoke-001" \
  -H 'X-TOC-License: TOC-…'
# {"update":true,…"package_url":"https://…/v1/download?…&sig=…"}

# Signed download
PACKAGE=$(curl -fsS "$BASE/v1/update-check?slug=orderring&version=1.19.1&site_url=https://store.example&instance_id=inst-smoke-001" \
  -H 'X-TOC-License: TOC-…' | php -r 'echo json_decode(stream_get_contents(STDIN))->package_url;')
curl -fsS -o /tmp/orderring-dl.zip "$PACKAGE"
unzip -t /tmp/orderring-dl.zip
```

### H2 still holds (bare key must not mint a package URL)

```bash
# No site_url / instance_id → 403, no package_url
curl -sS -o /tmp/h2.json -w '%{http_code}\n' \
  "$BASE/v1/update-check?slug=orderring&version=1.19.1" \
  -H 'X-TOC-License: TOC-…'
# 403
cat /tmp/h2.json
# {"success":false,"status":"inactive","error":"Site not activated. …"}
```

A never-activated `site_url` must also 403.

---

## 5. Point the plugins at the server

On each customer WordPress (and on the seller’s own test site):

```php
define( 'TOC_LICENSE_SERVER_URL', 'https://licenses.example.com' );
define( 'TOC_LICENSE_ITEM_SLUG', 'orderring' ); // optional; this is the plugin default as of 1.20.0
```

Then **WooCommerce → OrderRing → License** → paste key → Activate.

StoreCanvas and OrderBay zips can be registered with `--slug=storecanvas` / `--slug=orderbay` on the same server. Those plugins do not yet ship a license client; the zips are for manual delivery until that lands.

---

## 6. Backups

- Daily copy of `data/licenses.sqlite` (and `-wal`/`-shm` if present) off-box
- `config.php` in a secrets manager, not in git
- `storage/releases/` is reproducible from `tools/build-release.sh` + `add-release.php`

---

## Blocked without a host

This runbook is complete; applying it needs a DNS name and SSH to a VPS you control. Local smoke (PHP built-in server on `:8081`) is already recorded in `docs/RUNTIME-VERIFICATION.md` A1–A5 and is **not** a production substitute.
