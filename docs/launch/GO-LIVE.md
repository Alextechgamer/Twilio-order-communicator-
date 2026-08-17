# Go-live: the store site + automatic license sales

The catalog site in `docs/launch/site/` now covers all nine plugins (plus
link-out cards for Batchideo and OmniPulse), and the license server can turn a
Stripe payment into an emailed license key with no manual step. This is the
ordered checklist to take it live.

**Buy buttons work from day one:** until a checkout URL is configured, every
buy button opens an email to you. You reply with a Stripe invoice and mint a
key by hand (`php bin/create-key.php --email=... --slug=... --sites=... --expires=...`).
Connecting Stripe below removes the manual step — it does not block launch.

---

## 1. Host the site (static — any host works)

Upload the contents of `docs/launch/site/` to your web root (Hostinger, the
license-server VPS, GitHub Pages, anything that serves HTML). There is no
build step. Suggested DNS: site at `alextechgamer.com`, license server at
`licenses.alextechgamer.com` (its `DEFAULT_SERVER` in the plugins).

## 2. Replace the placeholders (before taking money)

- `support@alextechgamer.com` — appears on every buy button and the support
  page. Create the mailbox or search-and-replace to the real one.
- Seller entity in `site/terms.html` / `docs/launch/legal/TERMS.md` (the page
  itself says "Replace the seller entity before taking payment").
- "Alextech Plugins" brand name in every page header — keep it or rename it.
- Product-page "Source and changelog" links point at the GitHub repos.
  CardingDesk, Checkout Sentinel, FormDrop, Blocks Checkout Field Editor, and
  LayoutGuard are currently **private** repos — those links 404 for visitors.
  Make each repo public, or drop the links from those pages.

## 3. Deploy the license server

Follow `docs/launch/LICENSE-SERVER-DEPLOY.md` (VPS + TLS + PHP). New since
that doc was written: `config.php` gains the purchase settings — copy the new
keys from `config.example.php`:

- `stripe_webhook_secret` — from step 4
- `from_email` — the From/Reply-To for license emails (use a mailbox on a
  domain with SPF/DKIM for this host, or keys land in spam)
- `notify_email` — you; gets a copy of every sale and every needs-review event
- `purchase_tiers` — already matches the site's `buy.js` slugs; edit only if
  pricing/tiers change

## 4. Connect Stripe (checkout → key, no manual step)

1. In Stripe, create a **Payment Link** per tier you want to sell (prices from
   `docs/launch/PRICING.md` / the site's pricing page).
2. On **every** Payment Link set two metadata entries — this is what the
   webhook uses to mint the right key:
   - `item_slug`: `orderring` · `orderbay` · `storecanvas` · `cardingdesk` ·
     `checkout-sentinel` · `bcfe` · `layoutguard` · `formdrop`
   - `tier`: the tier key for that product in `config.example.php`
     `purchase_tiers` (e.g. `pro`, `agency`, `pro-lifetime`, `10`, `agency-25`)
3. Add a **webhook endpoint** pointed at
   `https://licenses.YOURDOMAIN/v1/stripe-webhook` with events
   `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
   Copy its signing secret (`whsec_...`) into `stripe_webhook_secret`.
4. Paste each Payment Link URL into `site/buy.js` (`BUY_LINKS`). Buttons with
   a URL switch from email-to-buy to direct checkout automatically.
5. Test end to end with a Stripe **test-mode** link: pay with 4242…, confirm
   the key email arrives and the row shows in the `purchases` table with
   status `ok`.

What the webhook guarantees:

- Signature-verified (HMAC v1), 5-minute tolerance, idempotent per session —
  Stripe retries and duplicate deliveries cannot double-mint.
- A paid session with missing/unknown metadata is **never lost**: recorded as
  `needs-review` and emailed to `notify_email` so you can mint manually.
- Keys are product-bound (`licenses.item_slug`): an OrderRing key cannot fetch
  StoreCanvas updates. Legacy keys (minted before this) keep working for the
  server's default product.

**Monthly subscriptions (Checkout Sentinel):** the webhook mints a key on the
first payment but does not yet extend expiry on Stripe invoice renewals. Sell
the annual variants for now, or extend keys manually; an `invoice.paid`
handler is the follow-up when monthly matters.

## 5. Register releases so keys deliver updates

For each product you sell, upload its zip and register it:

```
php bin/add-release.php --slug=orderring --version=1.22.0 --file=orderring-1.22.0.zip
```

**Reality check per product:** OrderRing, OrderBay, and StoreCanvas ship with
the license/updater client and are sellable today. CardingDesk, Checkout
Sentinel, FormDrop, Blocks Checkout Field Editor, and LayoutGuard do **not yet
embed the license client** — a sold key is recorded and valid, but their Pro
zips can't phone home for updates until the shared client
(`tools/license-client/generate.php`) is added to each. Until then, either
leave their `buy.js` entries empty (email-to-buy keeps working and you deliver
zips manually) or add the client first.

## 6. Smoke test before announcing

- Every nav link and product page loads; no console errors.
- A test purchase per connected product mints a key, emails it, and the key
  activates in the plugin's License tab.
- `GET /v1/health` returns ok; `/v1/stripe-webhook` without a signature
  returns 400 (503 until the secret is configured).
- Refund path: `docs/launch/legal/REFUND.md` — refund in Stripe, then disable
  the key (`UPDATE licenses SET status='disabled' WHERE license_key='...'`).
