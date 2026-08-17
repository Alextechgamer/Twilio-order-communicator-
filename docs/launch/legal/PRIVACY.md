# Privacy Policy

**Last updated:** 12 August 2026  
**Seller:** the plugin author publishing OrderRing, StoreCanvas, and OrderBay. Replace with the legal entity and a contact email before taking payment.

This policy covers the **seller’s** marketing site, checkout, and license server — not your WooCommerce store. Stores that install the plugins are independent controllers of their own customer data.

## 1. Data we collect (seller)

If you buy a license or contact support we may store:

- Name, email, site URL, and license key metadata
- Payment references from the payment provider (we do not store full card numbers)
- License activations: site URL, instance ID, plugin version, timestamps (see `license-server/`)

The license server is hosted on the seller’s HTTPS host. Download URLs are HMAC-signed and time-limited.

## 2. Data the plugins process on *your* store

**OrderRing** stores order communication logs, consent meta, and opt-outs on *your* WordPress database and sends message content to **your** Twilio account. Twilio is your processor. Copy the in-plugin Privacy Policy helper (WooCommerce → OrderRing → Tools & Docs) into your store policy.

**StoreCanvas** stores customer artwork and print composites on *your* media library (served through a signed proxy). You are responsible for retention and deletion.

**OrderBay** stores invoice numbers, tracking, and RMA meta on *your* site.

We do not receive your customers’ SMS bodies, artwork, or invoices unless you attach them to a support request.

## 3. Legal bases (GDPR)

Contract (license delivery), legitimate interests (abuse prevention, update integrity), and consent where you opt into marketing email.

## 4. Retention

License and activation records are kept while the license is valid and for a reasonable period afterward for accounting and fraud prevention. You may ask for deletion of support mail; we may retain what law requires.

## 5. Processors

Typical processors: payment provider, email host, VPS for the license server. List the live vendors here before launch.

## 6. Your rights

Email the published support address to access, correct, or delete seller-side personal data, or to object to marketing. Stores in the EEA/UK should also document Twilio (OrderRing) in their own processor list.

## 7. Children

The plugins and this site are not directed at children.

## 8. Changes

The date above will change when this policy changes.
