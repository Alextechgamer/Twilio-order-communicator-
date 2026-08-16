=== OrderRing Lite ===
Contributors: alextechgamer
Tags: woocommerce, sms, twilio, pickup, notifications
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0

Ready-for-pickup SMS for WooCommerce via your own Twilio account. Consent-aware. You pay Twilio directly, zero markup.

== Description ==

OrderRing Lite texts customers when an order is ready for pickup, using **your** Twilio account.

* Send SMS from the order screen
* Auto SMS when an order enters **Ready for Pickup** (or any status you map)
* Checkout consent checkbox (classic + block checkout)
* STOP / HELP / START keyword handling
* Optional Local Pickup filter
* Compatible with WooCommerce HPOS

**Bring your own Twilio account.** The plugin does not sell or provide messaging services. Message costs are billed by Twilio to you — OrderRing adds zero markup.

Twilio and all related logos are trademarks of Twilio Inc. or its affiliates. OrderRing is not affiliated with, endorsed, or sponsored by Twilio Inc.

A paid **OrderRing** plugin (voice, WhatsApp, two-way chat, bulk reminders) is sold separately on the author’s site. It is a different plugin. This Lite plugin is complete and is never locked.

== Installation ==

1. Upload the `orderring-lite` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu (WooCommerce must be active)
3. Open **OrderRing Lite** and enter your Twilio Account SID, Auth Token, and From Number
4. Map Ready for Pickup (the plugin registers that status by default)
5. In the Twilio Console, set the Incoming SMS webhook to the URL shown on the settings screen

== Frequently Asked Questions ==

= Do I need a Twilio account? =
Yes. You provide Account SID, Auth Token, and From Number. You pay Twilio directly.

= Do I need A2P 10DLC? =
If you text US mobile numbers, carriers require A2P 10DLC (or a verified toll-free number). Register that in the Twilio Console. This plugin does not file it for you.

= Is there a license key or trial? =
No. Every feature listed above works without a key, trial, or phone-home to the author.

= What if I also install OrderRing (the paid plugin)? =
Lite steps aside so you do not send duplicate SMS. You can deactivate Lite.

= Can I set credentials in wp-config.php? =
Yes. Define `ORL_ACCOUNT_SID`, `ORL_AUTH_TOKEN`, and/or `ORL_FROM_NUMBER`.

== Changelog ==



= 1.0.2 =
* Use $wpdb->prepare() %i for table names (Plugin Check InterpolatedNotPrepared)
* Requires WordPress 6.2+ (%i identifiers)
= 1.0.1 =
* Plugin Check: translators comment, TwiML escaping, drop unused logger queries
* readme: Tested up to 7.0
* Restore failed-SMS order notes; stop calling a missing Shipped helper

= 1.0.0 =
* First public release: Ready-for-pickup SMS, checkout consent, STOP/HELP/START
