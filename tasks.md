# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.5.0  
**Last updated:** 2026-07-30  
**Purpose:** Prioritized list of code improvements, product features, and packaging work so Cursor AI (or a developer) can implement them systematically.

---

## Important Product Positioning

- This is a **WordPress / WooCommerce plugin** that lets store owners connect **their own Twilio account**.
- Users must provide their own Account SID, Auth Token, and From Number.
- The plugin does **not** sell, resell, or provide any messaging, SMS, voice, or calling services.
- All message and call costs are billed directly by Twilio to the store owner.
- Always keep this clear in the UI, docs, Setup wizard, and any marketing copy.

**Updated product focus (selling version):**  
Order communication for **Ready for Pickup** and **Shipped** workflows using custom WooCommerce order statuses, with independent toggles and custom messages for each. No longer limited to Local Pickup shipping method only.

---

## Priority Legend

| Priority | Meaning |
|----------|--------|
| **P0**   | Do before charging money / public launch |
| **P1**   | High value — do next for a strong paid product |
| **P2**   | Nice-to-have / post-launch |

---

## P0 — Core Product Expansion (Do First)

These changes redefine how automatic notifications work. Implement them before or alongside the older hardening tasks.

### 1. Register custom WooCommerce order statuses
**Goal:** Simplify the workflow with clear statuses instead of relying mainly on shipping method detection.

**Task:**
- On plugin init / activation, register two custom order statuses (if they do not already exist):
  - `wc-ready-for-pickup` → label **Ready for Pickup**
  - `wc-shipped` → label **Shipped**
- Make them available in the order status dropdown and bulk actions.
- Use standard WooCommerce registration (`register_post_status` + `wc_order_statuses` filter).
- Add a setting (or simple UI) that lets the store owner **map** which status means “Ready for Pickup” and which means “Shipped” in case they already use different custom statuses from another plugin. Default to the two we register.
- Document that stores can still use core statuses if they prefer, via the mapping setting.

### 2. Independent auto-notify controls for each status
**Files:** Settings UI + `class-toc-auto.php` + related logic

**Task:**
Replace the current “Local Pickup only + Completed” model with clear per-status controls:

**Settings section: Automatic Notifications**

- **Ready for Pickup**
  - [ ] Enable automatic notifications when order enters this status
  - [ ] Send voice call
  - [ ] Send SMS (requires consent)
  - Message template (textarea with merge tags)

- **Shipped**
  - [ ] Enable automatic notifications when order enters this status
  - [ ] Send voice call
  - [ ] Send SMS (requires consent)
  - Message template (textarea with merge tags)

- Global options that still apply to both:
  - Quiet hours
  - Require SMS consent
  - Once-per-status (or once-per-order) tracking via order meta so we don’t spam

**Behavior:**
- When an order status changes **to** the mapped Ready for Pickup status → run the Ready for Pickup rules.
- When an order status changes **to** the mapped Shipped status → run the Shipped rules.
- Keep the existing quiet-hours deferral and consent checks.
- Write clear order notes explaining what was sent or why it was skipped.

### 3. Custom messages per status + reminder message
**Task:**
Provide separate default + editable templates:

| Template | Suggested default purpose |
|----------|---------------------------|
| Ready for Pickup | “Your order #{order_number} is ready for pickup…” |
| Shipped | “Your order #{order_number} has shipped…” (optionally include tracking later) |
| Pickup Reminder | Used by bulk + scheduled reminders for orders still in Ready for Pickup |
| Issue / Contact | Manual quick template (keep existing) |

All templates must support the existing merge tags.

### 4. Update bulk reminders for the new model
**Task:**
- Bulk Reminders should primarily target orders in the **Ready for Pickup** status (not only Local Pickup shipping method).
- Add filters: status, date range, “hide recently reminded”, consent column (keep existing good UX).
- Optionally allow bulk actions for Shipped orders later; v1 can focus bulk on Ready for Pickup + reminder message.
- Update labels and help text everywhere that still say “Local Pickup only”.

### 5. Soften / replace Local Pickup shipping-method restriction
**Task:**
- The old `toc_pickup_match` setting (method_id / local_title / any_pickup) should become secondary or optional.
- Primary trigger is now **order status**.
- You may keep an optional “Only notify if shipping method looks like Local Pickup” checkbox for Ready for Pickup if useful, but it must not be required.
- Update Setup wizard, Tools & Docs, order notes, and README to match the new model.

### 6. Meta tracking for “already notified”
**Task:**
Track notifications per status so an order can receive:
- One Ready for Pickup notification when it enters that status
- One Shipped notification when it enters that status

Suggested meta keys (or equivalent):
- `_toc_notified_ready_for_pickup_at`
- `_toc_notified_shipped_at`
- Keep `_toc_last_reminder_at` for bulk/scheduled reminders

Clearing the relevant meta should allow a re-send (document this for support).

---

## P0 — Code Quality & Hardening (Still Required)

### 7. Extract shared Twilio HTTP client
**File:** `includes/class-toc-twilio.php`  
Create a private `request( $method, $path, $body = array() )` helper and refactor `send_sms()`, `make_call()`, and `test_credentials()` to use it.

### 8. Split `class-toc-admin.php`
Break the large admin class into focused pieces (settings, dashboard, bulk, ajax, tools) while keeping a thin orchestrator. This is especially important now that settings are growing.

### 9. Improve phone → order matching
Make inbound SMS attach reliably to older orders, not just recent ones.

### 10. Prefer REST routes for webhooks (keep query-string aliases)
Register `toc/v1` REST endpoints; keep `?toc_sms=1` etc. as permanent aliases.

### 11. Log automatic STOP / HELP / START replies
Write keyword auto-replies into the communications log so they appear in order chat.

### 12. Capability filters + optional wp-config credentials
- Filterable caps: `toc_send_sms`, `toc_manage_settings`
- Support `TOC_ACCOUNT_SID`, `TOC_AUTH_TOKEN`, `TOC_FROM_NUMBER` constants

### 13. SMS footer + Privacy Policy helper
Optional auto-footer on outbound SMS (“Reply STOP to opt out…”) + copy-paste privacy text for the store.

---

## P1 — High-Value Product Features

### 14. License key + auto-updates
Integrate Freemius, EDD Software Licensing, or Lemon Squeezy. Required to sell and push updates.

### 15. Scheduled reminders (Ready for Pickup)
Auto-remind after X hours/days if the order is still in Ready for Pickup. Respect quiet hours, consent, and last-reminder meta.

### 16. CSV export from Dashboard

### 17. Delivery failure alerts (email admin on undelivered/failed SMS)

### 18. “Mark as collected” / “Mark as done” action
Order action that excludes the order from future auto/bulk notifications.

### 19. Role-based permissions UI
Expose the capability filters in Settings.

---

## P2 — Later Differentiators

### 20. WhatsApp channel (via the store’s own Twilio WhatsApp sender)

### 21. Simple open-conversation / staff claim view

### 22. Basic analytics (sent / delivered / reply rates)

### 23. Tracking number merge tag + shipped message enhancements

### 24. Integrations with popular pickup / shipping plugins

### 25. Slack / Discord inbound alerts

---

## Explicitly Out of Scope

- Providing or reselling any Twilio / SMS / voice service
- Full marketing automation or abandoned-cart sequences
- AI chatbot as the core product
- Replacing Twilio with another gateway on day one

---

## Packaging & Go-to-Market Notes

- [ ] Final product name (watch “Twilio” trademark usage)
- [ ] Marketing site + docs that clearly say **Bring your own Twilio account**
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [ ] Pricing (suggested $59–$79/year)
- [ ] 30-day money-back, Terms, Privacy, Refund policy

---

## Suggested Implementation Order for Cursor

1. **P0 Core Product Expansion (tasks 1–6)** — custom statuses, per-status toggles & messages, update auto + bulk logic  
2. P0 Hardening (tasks 7–13) — HTTP client, admin split, webhooks, logging, footer  
3. License system (P1 #14)  
4. Scheduled reminders + CSV + Mark as collected  
5. Remaining P1 / P2 after first sales feedback

---

## Cursor Prompt (copy-paste)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (baseline 1.5.0) in this repository.

Read tasks.md carefully. It is the single source of truth.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide messaging or calling services.
- The product is expanding from “Local Pickup only” to a clearer model based on custom order statuses:
  - Ready for Pickup
  - Shipped
  with independent enable toggles, voice/SMS choices, and custom messages for each.
- Preserve consent handling, quiet hours, HPOS compatibility, security (Twilio signature validation, nonces, tokenized TwiML), and existing manual send / chat history features.
- Prefer small, reviewable changes. Update version, changelog, and user-facing docs/text when behavior changes.

Start with P0 Core Product Expansion (tasks 1–6 in tasks.md):
1. Register (and allow mapping of) custom order statuses.
2. Add per-status automatic notification settings and templates.
3. Update the auto-notify logic and bulk reminders to use statuses instead of (or in addition to) shipping-method detection.
4. Update all UI copy, Setup wizard, and Tools/Docs that still say “Local Pickup only”.

For each task:
- State what you will change and which files.
- Implement cleanly in the existing code style.
- After a logical group, summarize what was done and what remains.

Ask before large architectural decisions if anything is ambiguous.
```

---

*End of tasks.md*
