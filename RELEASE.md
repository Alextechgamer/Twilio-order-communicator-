# Release & Deployment Guide

How to cut a customer release of **OrderRing** (folder `twilio-order-communicator/`), StoreCanvas, or OrderBay; run the license server; and hand a key to a customer.

> **Licensing never blocks messaging.** SMS, voice calls, WhatsApp, order chat, and status-based auto-notify all work with **no license at all** — they run on the store's own Twilio account. A license only enables **premium plugin updates**. An expired or invalid license pauses updates and shows a dismissible admin notice; it never locks a store out mid-operation.

Production license-server HTTPS: [`docs/launch/LICENSE-SERVER-DEPLOY.md`](./docs/launch/LICENSE-SERVER-DEPLOY.md). Naming: [`docs/launch/NAMING.md`](./docs/launch/NAMING.md).

---

## 1. Build the customer zip

Run from the repo root. The zip contains **only** the plugin folder — `license-server/` is seller infrastructure and must never ship to customers.

```bash
cd /path/to/repo
# Or: bash tools/build-release.sh   (builds all three plugins)

VERSION=1.20.0
rm -f orderring-${VERSION}.zip twilio-order-communicator-${VERSION}.zip

# The zip's top-level folder must remain twilio-order-communicator/ (WordPress plugin basename).
zip -r -X orderring-${VERSION}.zip twilio-order-communicator \
  -x '*/.git/*' '*/.git' \
  -x '*/node_modules/*' \
  -x '*.DS_Store' '*/.DS_Store' \
  -x '*.map' \
  -x '*/vendor/*' \
  -x '*.zip'
```

Verify before shipping:

```bash
unzip -l orderring-${VERSION}.zip | head -5   # single top-level folder
unzip -l orderring-${VERSION}.zip | grep -c license-server   # must be 0
```

WordPress requires exactly one top-level directory (`twilio-order-communicator/`) inside the archive.

**Version markers must agree before building** — `twilio-order-communicator.php` header `Version:`, the `TOC_VERSION` constant, and `readme.txt` `Stable tag:`. Same for StoreCanvas (`storecanvas.php` / `SC_VERSION`) and OrderBay (`orderbay.php` / `OB_VERSION`).

---

## 2. Deploy the license server

Requires PHP 7.4+ with PDO SQLite, HTTPS in production, and writable `data/` and `storage/releases/`.

```bash
# Upload license-server/ to your server, then:
cd /path/to/license-server
cp config.example.php config.php
```

Edit `config.php`:

| Key | Set to |
|-----|--------|
| `admin_token` | long random string |
| `download_secret` | long random string (signs download URLs) |
| `public_base_url` | public URL, no trailing slash, e.g. `https://licenses.example.com` |

Point the vhost **document root** at `license-server/public/` (an Apache `.htaccess` is included; on nginx route all requests to `public/index.php`).

Confirm it is live:

```bash
curl https://licenses.example.com/v1/health
# {"ok":true,"service":"toc-license-server"}
```

**Never** expose `config.php` or `data/licenses.sqlite` over HTTP — keeping the document root at `public/` handles this.

---

## 3. Create a key and register the release

```bash
cd /path/to/license-server

# One key per customer. Prints the key once — store it in your records.
php bin/create-key.php --email=customer@example.com --sites=1 --expires=lifetime

# Register 1.20.0 so licensed sites can update to it
php bin/add-release.php \
  --slug=orderring \
  --version=1.20.0 \
  --file=/path/to/orderring-1.20.0.zip \
  --changelog="OrderRing rename; A2P 10DLC guidance; Twilio attribution"

# Same server, other products (manual zip delivery until those plugins ship a license client):
php bin/add-release.php --slug=storecanvas --version=1.7.2 --file=/path/to/storecanvas-1.7.2.zip --changelog="…"
php bin/add-release.php --slug=orderbay --version=1.8.2 --file=/path/to/orderbay-1.8.2.zip --changelog="…"
```

Key options:

```bash
php bin/create-key.php --email=a@b.c --sites=3 --expires=2027-12-31 --notes="Agency multi-site"
php bin/create-key.php --sites=1 --expires=lifetime
```

`add-release.php` copies the zip into `storage/releases/` and upserts the row, so re-running it for the same version replaces that release.

---

## 4. Customer activation

Send the customer the zip, their key, and the server URL.

1. Install the zip: **Plugins → Add New → Upload Plugin**, then activate.
2. Add to `wp-config.php` (above `/* That's all, stop editing! */`):

```php
define( 'TOC_LICENSE_SERVER_URL', 'https://licenses.example.com' );
// optional, defaults to the plugin slug:
define( 'TOC_LICENSE_ITEM_SLUG', 'orderring' );
```

3. Go to **WooCommerce → OrderRing → License**, paste the key, click **Activate**.

Status should read **Active** with "premium updates enabled". The key is masked after saving and never re-rendered in full.

If the constant is not defined, the License tab offers a server URL field instead — the constant is preferred in production.

The customer still needs their **own Twilio** Account SID, Auth Token, and From Number under **Settings**. We never supply messaging or calling service; Twilio bills them directly.

---

## 5. Shipping an update later

```bash
# 1. Bump version in twilio-order-communicator.php (header + TOC_VERSION) and readme.txt Stable tag
# 2. Add a changelog entry in readme.txt
# 3. Rebuild the zip (section 1) with the new version in the filename
# 4. Register it:
php bin/add-release.php --slug=orderring --version=1.20.0 --file=/path/to/orderring-1.20.0.zip --changelog="..."
```

Licensed sites pick it up on their next update check. The plugin caches the result for 6 hours, so allow for that delay (or have the customer visit **Dashboard → Updates** and click **Check again**).

Download URLs handed to sites are HMAC-signed and expire after `download_ttl` (default 1 hour), so links cannot be shared.

---

## Release checklist

- [ ] Version matches in plugin header, `TOC_VERSION`, and `readme.txt` stable tag
- [ ] Changelog entry added to `readme.txt`
- [ ] Zip built and verified: one top-level folder, no `license-server`, no `.git`
- [ ] Release registered with `bin/add-release.php`
- [ ] Test activation on a clean site, then confirm the update offer
- [ ] Confirm SMS still sends on a site with **no** license
