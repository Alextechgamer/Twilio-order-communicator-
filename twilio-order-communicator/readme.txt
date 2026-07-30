=== Twilio Order Communicator ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send SMS and place voice calls from WooCommerce orders. Full history, bulk reminders, consent-aware messaging, and automatic Local Pickup notifications.

== Description ==

Twilio Order Communicator turns every WooCommerce order into a communication hub.

* Chat-style history of SMS and voice calls (sent + received)
* Send custom SMS or place voice calls directly from the order screen
* One-click templates with merge tags (order number, customer name, …)
* Automatic voice/SMS for Local Pickup orders when marked Completed (once per order)
* SMS only sent when the customer has consented; STOP/HELP/START keyword handling
* Shipped / flat-rate orders are never contacted automatically
* Built-in tokenized TwiML endpoint – no extra pages or snippets needed
* Bulk reminder tool for outstanding Local Pickup orders
* SMS delivery status callbacks
* Mark messages or whole conversations as resolved
* Dashboard with filters, pagination, and live stats
* Incoming customer replies are logged and attached to the matching order
* Twilio request signature validation on inbound webhooks
* Declared compatible with WooCommerce HPOS (custom order tables)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to WooCommerce → Order Communicator → Settings and enter your Twilio credentials
4. Enable **Also send an SMS** if you want automatic SMS on Completed (off by default)
5. Set Consent meta key to match your checkout checkbox snippet
6. Configure the Incoming SMS webhook (instructions on the Tools & Docs tab)

== Frequently Asked Questions ==

= Do voice calls require SMS consent? =
No. Only SMS respects the consent setting. Voice calls can always be placed for Local Pickup orders.

= Will shipped orders receive automatic notifications? =
No. Automatic calls and SMS only run for Local Pickup orders.

= Why did I only get a call and not an SMS? =
Auto SMS is a separate setting and defaults to off. Enable "Also send an SMS" under Settings. Also confirm the order has SMS consent and that Require consent is configured for your meta key. Order notes now explain skips.

= Do I need a separate TwiML page? =
No. The plugin includes its own tokenized TwiML endpoint.

= How do customers re-subscribe after STOP? =
They text START or UNSTOP. Plain "YES" is intentionally not treated as re-subscribe.

== Changelog ==

= 1.4.0 =
* Declare WooCommerce HPOS (custom order tables) compatibility
* Brand headers: Plugin URI / Author / Domain Path (GitHub)
* i18n: load text domain; wrap admin, order UI, notes, and JS strings
* Dashboard pagination (40 per page) with Previous / Next
* START keywords narrowed to START / UNSTOP (removed YES)
* SMS opt-outs moved to dedicated DB table (migrates legacy option)
* Seed default settings on activation / upgrade when missing
* Log STOP/HELP/START auto-replies in communications history
* DRY webhook SID to order note lookup
* .editorconfig + phpcs.xml.dist; normalize LF line endings

= 1.3.0 =
* Merge tags in templates and bulk: {order_number}, {customer_first_name}, {store_name}, etc.
* Auto-notify once per order (_toc_auto_notified_at); clear meta to re-send
* STOP / HELP / START inbound keyword handling + phone opt-out list
* Manual Send SMS warns when no consent / STOP and can force after confirm
* Stricter Local Pickup match setting (method_id / local_title / any_pickup)
* SMS delivery StatusCallback updates log status; notes on failed/undelivered
* Auto SMS skip always written as order note (setting off, no consent, errors)
* Broader consent value detection (yes/1/on/true/…) + common meta key fallbacks
* Optional webhook base URL for reverse-proxy signature mismatches
* Consent badge on order chat UI

= 1.2.2 =
* Bulk Reminders: list completed Local Pickup orders with lookback filter (7–180 days)
* Bulk Reminders: SMS consent column; SMS only with consent; calls always allowed
* Bulk Reminders: configurable delay between each order (sequential send + Stop)
* Bulk Reminders: live progress log and per-row status

= 1.2.1 =
* Fix: Auto SMS / other checkboxes no longer reset after Save (hidden 0 + value 1)
* Fix: Auth token field no longer wiped when left blank on save
* Security: Validate Twilio X-Twilio-Signature on inbound SMS and call status webhooks
* Security: Voice TwiML uses short-lived tokens instead of putting the full message in the URL
* Connection test now verifies credentials against the Twilio Account API
* HPOS-safe order edit links on the dashboard
* Better inbound phone to order matching (last-10-digit compare)
* Bulk reminders stamp `_toc_last_reminder_at` meta
* Call status notes only on terminal statuses (less order-note spam)
* uninstall.php removes table + options
* Settings sanitize callbacks

= 1.2.0 =
* Built-in TwiML endpoint with selectable voice
* SMS consent enforcement (automatic & bulk)
* Local Pickup only for automatic notifications
* Connection test tool
* Fully generic (no hard-coded business names)
* Improved settings documentation

= 1.1.0 =
* Bulk reminders, filters, resolve system, stats

= 1.0.0 =
* Initial release
