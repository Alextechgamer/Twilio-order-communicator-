=== Twilio Order Communicator ===
Contributors: alextechgamer
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send SMS and place voice calls from WooCommerce orders using your own Twilio account. Status-based Ready for Pickup and Shipped notifications, chat history, bulk reminders, and consent-aware messaging.

== Description ==

Twilio Order Communicator turns every WooCommerce order into a communication hub. **You bring your own Twilio account** (Account SID, Auth Token, From Number). The plugin does not sell or provide messaging services — Twilio bills you directly.

* Chat-style history of SMS and voice calls (sent + received)
* Send custom SMS or place voice calls directly from the order screen
* One-click templates with merge tags (order number, customer name, …)
* Custom order statuses: **Ready for Pickup** and **Shipped**
* Independent auto-notify toggles (enable / voice / SMS) and message templates per status
* Status mapping so you can point at existing WooCommerce statuses if needed
* Optional Local Pickup shipping-method filter for Ready for Pickup (off by default)
* SMS only sent when the customer has consented; STOP/HELP/START keyword handling (auto-replies logged)
* Built-in tokenized TwiML endpoint – no extra pages or snippets needed
* REST webhook routes (`toc/v1`) with permanent query-string aliases
* Bulk reminder tool for orders still in Ready for Pickup
* Optional scheduled pickup reminders after a configurable delay (Action Scheduler)
* Dashboard CSV export of filtered communications
* Mark order as collected (suppresses auto-notify / bulk / scheduled reminders)
* Optional email alerts when Twilio reports SMS failed or undelivered
* Role permissions UI for who can manage the plugin vs send SMS/calls
* Optional customer emails when order enters Ready for Pickup / Shipped
* Polly voice options mapped correctly for Twilio TwiML
* Optional SMS compliance footer + Privacy Policy helper text
* Filterable capabilities and optional wp-config credential constants
* Optional first-party license key for premium updates (messaging works without a license)
* SMS delivery status callbacks
* Mark messages or whole conversations as resolved
* Dashboard with filters, pagination, and live stats
* Incoming customer replies are logged and attached to the matching order (improved phone matching)
* Twilio request signature validation on inbound webhooks
* Declared compatible with WooCommerce HPOS (custom order tables)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to WooCommerce → Order Communicator → Setup (or Settings) and enter **your** Twilio credentials
4. Map Ready for Pickup / Shipped statuses (defaults are registered by the plugin)
5. Enable voice and/or SMS per status as needed (SMS defaults off)
6. Configure the Incoming SMS webhook (preferred REST URL on the Tools & Docs tab)
7. (Optional) Set `TOC_LICENSE_SERVER_URL` and activate a license under the License tab for premium updates

== Frequently Asked Questions ==

= Do I need a Twilio account? =
Yes. This plugin connects to **your** Twilio account. You provide Account SID, Auth Token, and From Number. Message and call costs are billed by Twilio.

= Do I need a license key to send SMS? =
No. A license only unlocks premium plugin updates from the seller’s update server. Core SMS, voice, chat, and auto-notify keep working without a license.

= Can I set credentials in wp-config.php? =
Yes. Define `TOC_ACCOUNT_SID`, `TOC_AUTH_TOKEN`, and/or `TOC_FROM_NUMBER`. Constants override Settings fields. For licensing, define `TOC_LICENSE_SERVER_URL`.

= Do voice calls require SMS consent? =
No. Only SMS respects the consent setting. Voice calls can always be placed.

= Will shipped orders receive automatic notifications? =
Only if you enable Shipped auto-notify in Settings. Ready for Pickup and Shipped are independent.

= Is this Local Pickup only? =
No. The primary trigger is order status. An optional Local Pickup shipping-method filter is available for Ready for Pickup auto-notify (off by default on new installs).

= Why did I only get a call and not an SMS? =
Auto SMS is a separate per-status setting and defaults to off. Enable SMS under Settings for that status. Also confirm consent and that Require consent is configured. Order notes explain skips.

= How do I re-send an auto notification? =
Clear `_toc_notified_ready_for_pickup_at` or `_toc_notified_shipped_at` on the order, then move the order into that status again (or clear meta and re-trigger).

= Do I need a separate TwiML page? =
No. The plugin includes its own tokenized TwiML endpoint.

= How do customers re-subscribe after STOP? =
They text START or UNSTOP. Plain "YES" is intentionally not treated as re-subscribe.

== Changelog ==

= 1.13.0 =
* Fix: map Polly voice options (polly.joanna → Polly.Joanna, etc.) in TwiML <Say>
* Settings: clearer Consent meta key help for third-party SMS consent checkboxes
* Feature: optional customer emails on Ready for Pickup / Shipped (default off, once per order)
* Emails use wp_mail + store From; same merge tags as SMS; independent of voice/SMS toggles; quiet hours do not apply
* Messaging remains ungated by license

= 1.12.0 =
* Feature: custom capabilities toc_manage / toc_send with Settings role matrix
* Defaults: administrator and shop_manager get both caps (seeded once; never wiped)
* Administrator always keeps manage access (cannot lock yourself out)
* Filters toc_manage_settings / toc_send_sms still override the required capability string
* Messaging remains ungated by license

= 1.11.0 =
* Feature: optional staff email when Twilio SMS StatusCallback reports failed or undelivered
* Settings: enable toggle (default off) + alert email (falls back to WordPress admin email)
* Deduplicated per MessageSid (transient) so Twilio retries do not spam; order notes unchanged
* Messaging remains ungated by license

= 1.10.0 =
* Feature: Export CSV from the Dashboard (respects current filters; nonce-protected; paginated fetch)
* Feature: Mark as collected order action (and Unmark) — sets `_toc_collected` meta (HPOS-safe)
* Collected orders skip auto-notify, cancel scheduled reminders, and are excluded from bulk reminders
* Collected badge on the order communications meta box; messaging remains ungated by license

= 1.9.0 =
* Feature: scheduled Ready for Pickup reminders after a configurable delay (default off, 24h)
* Uses Action Scheduler when available (WP-Cron fallback); cancelled when the order leaves Ready
* Respects quiet hours (re-defer), SMS consent, `_toc_last_reminder_at` cooldown, and the Pickup Reminder template
* Channels follow Ready for Pickup voice/SMS toggles; messaging remains ungated by license

= 1.8.2 =
* Fix: webhook REST URLs use WordPress rest_url() (plain permalinks, subdirectory, reverse-proxy override)
* Fix: Ready for Pickup and Shipped are included in WooCommerce paid statuses (is_paid() stays true)
* Perf: tighter inbound phone → order matching (full last-10 LIKE needle, lower bounded limit, filterable)
* Docs: phpcs.xml.dist notes license-server/ is out of scope; tasks.md marks G1–G8 closed

= 1.8.1 =
* Fix: license data option no longer nests its own payload on every activate/validate
* Fix: unschedule the daily license check on plugin deactivation and uninstall
* Fix: clear cached update checks when a license becomes active, so updates appear right away
* Fix: Ready for Pickup orders skipped by the optional Local Pickup filter no longer add a repeat order note on every save

= 1.8.0 =
* Custom first-party licensing client (activate / deactivate / validate) — does not lock SMS/voice
* License tab under Order Communicator; masked keys; dismissible notice when expired/invalid
* Premium update checks via `pre_set_site_transient_update_plugins` + `plugins_api` (fail closed without license)
* Grace period for license server network errors; Action Scheduler / WP-Cron re-validation
* Companion `license-server/` PHP API (SQLite) with key creation and release registration CLIs
* Constants: `TOC_LICENSE_SERVER_URL`, optional `TOC_LICENSE_ITEM_SLUG`

= 1.7.0 =
* Shared Twilio HTTP `request()` helper for SMS, calls, and credential tests
* Split admin UI into focused traits (settings, dashboard, bulk, tools, ajax)
* Improved inbound phone → order matching (communications log, HPOS/CPT billing phone, broader status scan)
* REST webhook routes under `toc/v1` (query-string aliases kept permanently)
* STOP / HELP / START auto-replies logged in communications history
* Filterable capabilities `toc_manage_settings` / `toc_send_sms`
* Optional wp-config credentials: TOC_ACCOUNT_SID, TOC_AUTH_TOKEN, TOC_FROM_NUMBER
* Optional outbound SMS compliance footer + Privacy Policy helper on Tools & Docs

= 1.6.0 =
* Custom WooCommerce statuses: Ready for Pickup (`wc-ready-for-pickup`) and Shipped (`wc-shipped`)
* Status mapping settings + per-status enable / voice / SMS toggles and message templates
* Auto-notify on mapped status change (quiet hours, consent, and order notes preserved)
* Tracking meta: `_toc_notified_ready_for_pickup_at`, `_toc_notified_shipped_at`
* Bulk Reminders target orders in Ready for Pickup status
* Local Pickup shipping-method check is now an optional secondary filter (default off for new installs)
* Setup wizard, Tools & Docs, and copy updated for bring-your-own-Twilio + status model
* Migrates 1.5.x Completed / Local Pickup auto settings to Ready for Pickup controls

= 1.5.0 =
* Built-in checkout SMS consent checkbox (classic + block checkout 8.9+)
* Consent audit meta: timestamp, IP, and source on the order
* Quiet hours: defer auto notify until window ends (Action Scheduler / WP-Cron)
* Setup wizard: credentials → test → webhook → consent → auto notify
* Admin Setup tab + first-run notice

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
