# OrderRing License Server

Minimal PHP license + update API for **OrderRing** (and optional StoreCanvas / OrderBay zip registry).  
Host this on your own domain (e.g. `https://licenses.example.com`). Payments stay on your marketing site — this only activates keys and serves licensed zip updates.

**Production HTTPS:** [`docs/launch/LICENSE-SERVER-DEPLOY.md`](../docs/launch/LICENSE-SERVER-DEPLOY.md) (nginx + Apache vhosts, secrets, smoke + H2).

## Requirements

- PHP 7.4+ with PDO SQLite
- HTTPS in production
- Writable `data/` and `storage/releases/`

## Quick setup

```bash
cd license-server
cp config.example.php config.php
# Edit config.php: admin_token, download_secret, public_base_url
# or: php bin/generate-secrets.php --write

# Create a license key (1 site, lifetime)
php bin/create-key.php --email=customer@example.com --sites=1 --expires=lifetime

# Register a release package
php bin/add-release.php --slug=orderring --version=1.20.0 --file=/path/to/orderring-1.20.0.zip --changelog="OrderRing rename"
```

Point your vhost **document root** at `license-server/public/` (Apache `.htaccess` included).

Local smoke test:

```bash
cd license-server/public
php -S 127.0.0.1:8080
curl http://127.0.0.1:8080/v1/health
```

Set `public_base_url` in `config.php` to the public URL (no trailing slash), e.g. `http://127.0.0.1:8080` for local tests.

## API

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/v1/health` | Liveness |
| POST | `/v1/activate` | Activate key for site + instance |
| POST | `/v1/deactivate` | Remove activation |
| POST | `/v1/validate` | Periodic re-check |
| GET | `/v1/update-check` | Latest release if license allows — requires `X-TOC-License` header **and** an activated `site_url`+`instance_id` |
| GET | `/v1/download` | Signed zip download |

### Activate body (JSON)

```json
{
  "license_key": "TOC-....",
  "site_url": "https://store.example",
  "instance_id": "random-install-id",
  "plugin_version": "1.8.0"
}
```

## Plugin configuration

In the WordPress site’s `wp-config.php`:

```php
define( 'TOC_LICENSE_SERVER_URL', 'https://licenses.example.com' );
// optional:
define( 'TOC_LICENSE_ITEM_SLUG', 'orderring' );
```

Then: **WooCommerce → OrderRing → License** → paste key → Activate.

Invalid/expired licenses **do not** disable SMS/voice — they only pause premium updates.

## Security notes

- Keep `config.php` and `data/licenses.sqlite` outside public git / backups of customer keys carefully
- Use a long random `admin_token` / `download_secret`
- Prefer HTTPS; the plugin verifies SSL when calling the server
- Download URLs are HMAC-signed and time-limited
- `/v1/update-check` only issues a signed download URL to an **activated** site (matching `site_url` + `instance_id`); a bare license key with no activation cannot mint package URLs
- `data/` and `storage/` ship a deny-all Apache `.htaccess`. **nginx ignores `.htaccess`** — if you serve behind nginx, keep the document root at `public/` (so `data/` and `storage/` are outside it) and add explicit deny blocks:

  ```nginx
  # Never serve the SQLite database or release zips directly.
  location ~* /(data|storage)/ {
      deny all;
      return 404;
  }
  ```

  Confirm a direct request to `/data/licenses.sqlite` and `/storage/releases/<file>.zip` returns 404/403; downloads must go through the signed `/v1/download` endpoint.

## Create keys options

```bash
php bin/create-key.php --email=a@b.c --sites=3 --expires=2027-12-31 --notes="Agency multi-site"
php bin/create-key.php --expires=lifetime
```
