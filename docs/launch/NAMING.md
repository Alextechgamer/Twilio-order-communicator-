# TOC rename — shortlist and decision

**Decision (this launch):** the product brand is **OrderRing**.
Plugin header, admin H1, WooCommerce menu, updater name, and license-server `item_slug` use that brand.
The install folder and text domain stay `twilio-order-communicator` so existing installs, options, REST routes (`toc/v1`), and translations do not break.

Twilio attribution (required by [Twilio Trademark Usage Guidelines](https://www.twilio.com/en-us/legal/trademark)):

> Twilio and all related logos are trademarks of Twilio Inc. or its affiliates.

Plus a non-affiliation line and the BYO tagline: *Bring your own Twilio account — you pay Twilio directly, zero markup.*

Consider emailing trademark@twilio.com for written permission before paid ads that use the Twilio name.

---

## Shortlist (3)

### 1. OrderRing — **chosen**

**Form:** distinct coined brand + relational subtitle *SMS & Voice for WooCommerce (for Twilio)*.

| | |
|---|---|
| **Trademark** | Does not lead with “Twilio”; not a composite mark. “for Twilio” is a fair-use product reference, the pattern Twilio’s own policy contrasts with banned composite names. |
| **SEO** | Brand must be built. Subtitle carries “SMS”, “voice”, “WooCommerce”, “Twilio”. |
| **Pros** | Ownable; first recommendation in COMPETITIVE-GAMEPLAN §5.1; covers pickup *and* shipped, not only local pickup; short enough for wp-admin menus. |
| **Cons** | No existing search demand; “ring” can read as phone *or* jewelry (StoreCanvas adjacency is accidental). |

### 2. PickupPing

**Form:** distinct coined brand + *for Twilio*.

| | |
|---|---|
| **Trademark** | Clean — no Twilio in the product name. |
| **SEO** | Strong for “pickup notification” / local-pickup queries; weak for shipped/voice/WhatsApp. |
| **Pros** | Memorable; matches the Ready-for-Pickup wedge. |
| **Cons** | Undersells Shipped, voice, WhatsApp, and two-way chat; we would outgrow the name. |

### 3. Order Communicator for Twilio

**Form:** relational rename of the current title (Twilio at the end, not the start).

| | |
|---|---|
| **Trademark** | Compliant *if* “for Twilio” stays descriptive and the Twilio mark is not styled as part of a logo lockup. Still closer to a composite than a coined brand. |
| **SEO** | Best keyword match today (“order communicator”, “Twilio”, “WooCommerce”). |
| **Pros** | Least disruption to docs and muscle memory; matches SkyVerge/ShopMagic-style naming. |
| **Cons** | Generic; hard to own; still leans on Twilio’s mark in the product title, which is the risk we are retiring. |

---

## What did not change

| Surface | Why it stays |
|---------|----------------|
| Folder `twilio-order-communicator/` | WordPress plugin basename; renaming it breaks updates and the live symlink. |
| Text domain `twilio-order-communicator` | Must match the folder; “only if required”. |
| PHP class `Twilio_Order_Communicator`, `TOC_*` constants, `toc_*` options, REST `toc/v1` | Internal identifiers, not customer-facing marks. |
| License item slug | **Did change** → `orderring` (see `TOC_License::item_slug()` and `license-server/config.example.php`). Override with `TOC_LICENSE_ITEM_SLUG` if a host must keep the old slug. |
