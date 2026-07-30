# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.6.0 (P0 product expansion done on branch `cursor/status-based-auto-notify-15b1` / PR #5)  
**Last updated:** 2026-07-30  
**Purpose:** Single source of truth for Cursor AI / developers. Implement in priority order.

---

## Important Product Positioning

- This is a **WordPress / WooCommerce plugin** that lets store owners connect **their own Twilio account**.
- Users must provide their own Account SID, Auth Token, and From Number.
- The plugin does **not** sell, resell, or provide any messaging, SMS, voice, or calling services.
- All message and call costs are billed directly by Twilio to the store owner.
- Always keep this clear in the UI, docs, Setup wizard, and any marketing copy.

**Product focus:** Order communication driven by custom WooCommerce order statuses (**Ready for Pickup** and **Shipped**), with independent toggles and messages per status.

---

## Priority Legend

| Priority | Meaning |
|----------|--------|
| **P0**   | Do before charging money / public launch |
| **P1**   | High value — next for a strong paid product |
| **P2**   | Nice-to-have / post-launch |

---

# P0 — Core Product Expansion — ✅ DONE (v1.6.0)

Shipped on PR #5 (`cursor/status-based-auto-notify-15b1`):

- [x] **1.** Custom statuses `wc-ready-for-pickup` / `wc-shipped` + mapping dropdowns
- [x] **2.** Per-status enable / voice / SMS + templates
- [x] **3.** Tracking meta `_toc_notified_ready_for_pickup_at` / `_toc_notified_shipped_at`
- [x] **4.** Auto-notify on mapped status change (quiet hours, consent, notes)
- [x] **5.** Bulk targets Ready for Pickup; uses `toc_message_reminder`
- [x] **6.** Local Pickup filter optional (`toc_ready_require_local_pickup`, default off); upgrade path maps 1.5 → Completed + filter on

---

# P0 — Code Quality & Hardening (NEXT)

Do these before public paid launch.

### 7. Extract shared Twilio HTTP client
**File:** `includes/class-toc-twilio.php`  
Create a private `request( $method, $path, $body = array() )` helper. Refactor `send_sms()`, `make_call()`, and `test_credentials()` to use it.

### 8. Split `class-toc-admin.php`
Break into focused classes/traits (settings, dashboard, bulk, ajax, tools). Keep a thin orchestrator. Settings grew further in 1.6.0 — this matters now.

### 9. Improve phone → order matching
Inbound SMS must attach reliably to older orders, not only recent ones.

### 10. Prefer REST routes for webhooks (keep query-string aliases)
Register `toc/v1` REST endpoints. Keep `?toc_sms=1` etc. as permanent aliases so existing Twilio configs do not break.

### 11. Log automatic STOP / HELP / START replies
Write keyword auto-replies into the communications log so they appear in order chat.

### 12. Capability filters + optional wp-config credentials
- Filterable caps: `toc_send_sms`, `toc_manage_settings` (default `manage_woocommerce`)
- Support `TOC_ACCOUNT_SID`, `TOC_AUTH_TOKEN`, `TOC_FROM_NUMBER` constants with option fallback

### 13. SMS footer + Privacy Policy helper
Optional auto-footer on outbound SMS (“Reply STOP to opt out…”) + copy-paste privacy text for the store.

---

# P1 — High-Value Product Features

### 14. License key + auto-updates
Integrate Freemius, EDD Software Licensing, or Lemon Squeezy. Required to sell and push updates.

### 15. Scheduled reminders (Ready for Pickup)
Auto-remind after X hours/days while order is still in Ready for Pickup. Respect quiet hours, consent, and `_toc_last_reminder_at`.

### 16. CSV export from Dashboard

### 17. Delivery failure alerts
Email admin (or configurable address) when SMS status becomes `undelivered` or `failed`.

### 18. “Mark as collected” action
Order action that sets `_toc_collected` and excludes the order from future auto/bulk notifications.

### 19. Role-based permissions UI
Expose the capability filters in Settings.

---

# P2 — Later Differentiators

### 20. WhatsApp channel (store’s own Twilio WhatsApp sender)
### 21. Simple open-conversation / staff claim view
### 22. Basic analytics (sent / delivered / reply rates)
### 23. Tracking number merge tag + richer shipped messages
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

- [ ] Final product name (watch “Twilio” trademark usage in the title)
- [ ] Marketing site + docs that clearly say **Bring your own Twilio account**
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [ ] Pricing (suggested $59–$79 / year per site)
- [ ] 30-day money-back, Terms, Privacy, Refund policy

---

## Suggested Implementation Order

1. ~~P0 Core Product Expansion (tasks 1–6)~~ ✅ v1.6.0  
2. **P0 Hardening (tasks 7–13)** ← you are here  
3. License system (P1 #14)  
4. Scheduled reminders + CSV + Mark as collected  
5. Remaining P1 / P2 after first sales feedback

---

## Cursor Prompt — Next Pass (P0 Hardening 7–13)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.6.0) in this repository.

Read tasks.md carefully. P0 Core Product Expansion (tasks 1–6) is already complete on the status-based model. Do not rework that unless you find a clear bug.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide messaging or calling services.
- Preserve the status-based Ready for Pickup / Shipped auto-notify model, consent, quiet hours, HPOS, security (signature validation, nonces, tokenized TwiML), manual send, and order chat history.
- Prefer small, reviewable changes. Update version/changelog when appropriate.

Implement the remaining P0 Hardening tasks 7–13 in tasks.md, in order:
7. Extract shared Twilio HTTP client in class-toc-twilio.php
8. Split class-toc-admin.php into focused classes
9. Improve phone → order matching
10. REST routes for webhooks (keep query-string aliases)
11. Log STOP/HELP/START auto-replies into communications history
12. Capability filters + optional wp-config credential constants
13. SMS footer setting + Privacy Policy helper text

For each task or logical group:
1. State what you will change and which files.
2. Implement cleanly in the existing code style.
3. Summarize what was done and what remains.

Ask before large architectural decisions if anything is ambiguous.
```

---

*End of tasks.md*
