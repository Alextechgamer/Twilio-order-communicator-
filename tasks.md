# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.5.0  
**Last updated:** 2026-07-30  
**Purpose:** Prioritized list of code improvements, product features, and packaging work so Cursor AI (or a developer) can implement them systematically.

---

## Important Product Positioning (Do Not Change)

- This is a **WordPress / WooCommerce plugin** that lets store owners connect **their own Twilio account**.
- Users must provide their own Account SID, Auth Token, and From Number.
- The plugin does **not** sell, resell, or provide any messaging, SMS, voice, or calling services.
- All message and call costs are billed directly by Twilio to the store owner.
- Always keep this clear in the UI, docs, Setup wizard, and any marketing copy.

---

## Priority Legend

| Priority | Meaning |
|----------|--------|
| **P0**   | Do before charging money / public launch |
| **P1**   | High value — do next for a strong paid product |
| **P2**   | Nice-to-have / post-launch |

---

## P0 — Code Quality & Hardening (Before Sale)

### 1. Extract shared Twilio HTTP client
**File:** `includes/class-toc-twilio.php`  
`send_sms()`, `make_call()`, and `test_credentials()` all duplicate Basic auth + `wp_remote_*` + JSON parsing.  
**Task:** Create a private `request( $method, $path, $body = array() )` helper that returns a consistent success/error array. Refactor the three methods to use it. Makes future mocking and error handling much easier.

### 2. Split `class-toc-admin.php`
**File:** `includes/class-toc-admin.php` (~43k)  
**Task:** Break into smaller focused classes or traits, for example:
- `class-toc-admin-settings.php`
- `class-toc-admin-dashboard.php`
- `class-toc-admin-bulk.php`
- `class-toc-admin-ajax.php`
- `class-toc-admin-tools.php`  
Keep a thin `TOC_Admin` that wires them together. This prepares the codebase for licensing hooks and future REST controllers.

### 3. Improve phone → order matching
**File:** `includes/class-toc-logger.php` (and any callers)  
Current logic scans a limited number of recent orders.  
**Task:** Improve `find_order_by_phone()` so inbound SMS from older Local Pickup orders still attach correctly. Prefer normalized digit matching against billing phone via the WooCommerce data store or a lightweight index/meta. Document any remaining limitations.

### 4. Optimize bulk Local Pickup query
**File:** `includes/class-toc-logger.php` → `get_bulk_pickup_orders()`  
Currently over-fetches then filters in PHP.  
**Task:** On order creation / status change, store a meta flag such as `_toc_is_local_pickup`. Use that flag (or proper shipping method meta queries) so the bulk list scales better. Keep the existing 200-order safety limit visible in the UI.

### 5. Prefer REST routes for webhooks (keep aliases)
**File:** `includes/class-toc-webhooks.php`  
**Task:** Register clean REST routes under `toc/v1` (e.g. `/sms`, `/status`, `/msg-status`). Keep the current `?toc_sms=1` style query-string endpoints as permanent aliases so existing Twilio console configurations do not break.

### 6. Log automatic keyword replies
**File:** `includes/class-toc-webhooks.php`  
STOP / HELP / START replies are sent via TwiML but not written to the communications log.  
**Task:** After sending the auto-reply, also call `TOC_Logger::log()` so the bot response appears in the order chat history.

### 7. Capability filters
**Task:** Introduce filterable capabilities such as `toc_send_sms` and `toc_manage_settings` (defaulting to `manage_woocommerce`). Allow larger teams to give shop managers messaging rights without full WooCommerce admin access.

### 8. Optional wp-config credentials
**Task:** Support constants `TOC_ACCOUNT_SID`, `TOC_AUTH_TOKEN`, and optionally `TOC_FROM_NUMBER` in `wp-config.php`. Fall back to the database options when the constants are not defined. Useful for agencies and version-controlled environments.

### 9. SMS footer / compliance helper
**Task:** Add a setting that automatically appends a short footer (e.g. “Reply STOP to opt out. Msg & data rates may apply.”) to outbound SMS when enabled. Respect character limits and document the behavior. Also add a short Privacy Policy helper paragraph the store can copy into their own policy.

---

## P1 — High-Value Product Features

### 10. License key + auto-updates
**Task:** Integrate a licensing system (Freemius, Easy Digital Downloads Software Licensing, or Lemon Squeezy).  
- License activation / deactivation UI  
- Automatic plugin updates for valid licenses  
- Graceful handling of expired / invalid licenses (core still works, updates blocked)  
This is how the product gets paid and stays maintained.

### 11. Scheduled reminders
**Task:** Let stores configure automatic “still waiting for pickup” reminders after X hours/days. Use Action Scheduler (preferred) or WP-Cron. Respect quiet hours, consent, and the existing once-per-order / last-reminder meta. Add a clear UI under Settings or a dedicated Reminders tab.

### 12. CSV export from dashboard
**Task:** Add an “Export CSV” button on the Dashboard that exports the currently filtered communications (date, order, phone, type, direction, body, status, resolved). Useful for managers and compliance records.

### 13. Delivery failure alerts
**Task:** When an SMS status callback returns `undelivered` or `failed`, optionally email the store admin (or a configurable address) with order link and error details. Setting to enable/disable + recipient.

### 14. “Mark as collected” action
**Task:** Add an order action / button that sets a meta flag (e.g. `_toc_collected`) so the order is permanently excluded from bulk reminders and auto-notify logic. Show the flag clearly in the order UI and bulk list.

### 15. Multi-location / per-shipping-method templates
**Task:** Allow different ready-for-pickup and reminder messages per shipping method or store location. Simple mapping UI is enough for v1 (method title/ID → message override).

### 16. Role-based permissions polish
**Task:** Once capability filters exist, expose simple checkboxes or a roles matrix in Settings so store owners can grant “Send messages” vs “Manage settings” to different WordPress roles.

---

## P2 — Differentiators & Later

### 17. WhatsApp channel (Twilio)
**Task:** Optional WhatsApp sending via the same Twilio account (Messaging Service / WhatsApp-enabled number). Keep SMS + Voice as the default path; WhatsApp as an additional channel when the store has it configured. Respect the same consent model.

### 18. Simple open-conversation / staff assignment view
**Task:** A lightweight “Open conversations” list that shows unresolved inbound threads and lets staff claim or assign them. Does not need to become a full shared inbox.

### 19. Basic analytics
**Task:** Simple stats beyond today’s counts: messages sent this week/month, delivery rate, reply rate. Can live on the Dashboard or a new Analytics tab.

### 20. Customer-facing “text us about this order” shortcode
**Task:** Optional shortcode or order-received page block that lets the customer start an SMS conversation about a specific order number.

### 21. Integrations
**Task:** Hooks / compatibility for popular Local Pickup extensions (e.g. Local Pickup Plus) and “ready for pickup” events from shipping tools when they exist.

### 22. Slack / Discord inbound alerts (optional)
**Task:** Webhook setting so new inbound SMS can also post to a team channel.

---

## Explicitly Out of Scope (Do Not Build)

- Full marketing automation or abandoned-cart sequences (Klaviyo territory)
- AI chatbot as the core product
- Providing or reselling any Twilio / SMS / voice service ourselves
- Replacing Twilio with another gateway on day one

---

## Packaging & Go-to-Market Notes (Not Code)

These are product / business tasks, not pure code:

- [ ] Choose final product name (consider trademark risk of leading with “Twilio”)
- [ ] Simple marketing site (Home, Features, Pricing, Docs, Changelog, Support, Legal)
- [ ] Screenshots + 60–90s demo video
- [ ] Clear “Bring your own Twilio account — you pay Twilio directly” messaging everywhere
- [ ] Pricing decision (suggested: $59–$79 / year per site, optional lifetime)
- [ ] 30-day money-back policy
- [ ] Privacy Policy + Terms + Refund policy pages

---

## Suggested Implementation Order for Cursor

1. P0 items 1–9 (especially HTTP client, admin split, keyword logging, SMS footer)
2. License system (P1 #10) — required to sell
3. Scheduled reminders + CSV + Mark as collected (P1 #11, #12, #14)
4. Delivery failure alerts + multi-location templates
5. WhatsApp and other P2 items after launch feedback

---

## Cursor Prompt (copy-paste)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.5.0) in this repository.

Read tasks.md carefully. It is the single source of truth for what needs to be done.

Key constraints:
- This plugin only connects to the store owner’s own Twilio account. Users supply their own Account SID, Auth Token, and From Number. We never provide messaging or calling services ourselves.
- Stay focused on Local Pickup order communication (voice + SMS). Do not turn this into a generic marketing SMS tool.
- Preserve existing functionality, consent model, quiet hours, HPOS compatibility, and security (signature validation, nonces, tokenized TwiML).
- Prefer small, reviewable changes. Update version numbers and changelog when appropriate.

Start with the highest-priority incomplete P0 tasks in tasks.md. For each task:
1. Briefly state what you are about to change and which files.
2. Implement the change cleanly.
3. Keep the code style consistent with the existing plugin (WordPress coding standards, existing class patterns).
4. After finishing a logical group of tasks, summarize what was done and what remains.

If a task is ambiguous, ask before making large architectural decisions.
```

---

*End of tasks.md*
