# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.7.0 (P0 complete on `cursor/p0-hardening-15b1` / PR #6, stacked on 1.6.0 status PR #5)  
**Last updated:** 2026-07-30  
**Purpose:** Single source of truth for Cursor AI / developers.

---

## Important Product Positioning

- Users bring **their own Twilio account** (Account SID, Auth Token, From Number).
- The plugin does **not** sell or provide messaging/calling services. Twilio bills the store directly.
- Focus: status-based **Ready for Pickup** and **Shipped** notifications, consent-aware SMS, voice, bulk reminders, order chat.

---

## Priority Legend

| Priority | Meaning |
|----------|--------|
| **P0**   | Before charging money / public launch |
| **P1**   | High value for a strong paid product |
| **P2**   | Nice-to-have / post-launch |

---

# P0 — Core Product Expansion — ✅ DONE (v1.6.0, PR #5)

- [x] Custom statuses + mapping
- [x] Per-status enable / voice / SMS + templates
- [x] Tracking meta
- [x] Auto-notify on status change
- [x] Bulk → Ready for Pickup
- [x] Optional Local Pickup filter + 1.5 upgrade path

---

# P0 — Code Quality & Hardening — ✅ DONE (v1.7.0, PR #6)

- [x] **7.** Shared `request()` HTTP client in `class-toc-twilio.php`
- [x] **8.** Admin split: thin orchestrator + traits (settings, dashboard, bulk, tools, ajax)
- [x] **9.** Improved phone → order matching (log → HPOS/CPT → broader scan)
- [x] **10.** REST `toc/v1/{sms,voice-status,message-status}` + query aliases kept
- [x] **11.** STOP/HELP/START auto-replies logged + documented
- [x] **12.** `toc_manage_settings` / `toc_send_sms` filters; `TOC_*` wp-config constants
- [x] **13.** SMS footer settings + Privacy Policy helper on Tools & Docs

**All P0 work is complete.** Plugin is structurally ready to productize for sale.

---

# P1 — High-Value Product Features (NEXT)

### 14. License key + auto-updates  ← **highest priority for selling**
Integrate Freemius, EDD Software Licensing, or Lemon Squeezy.
- License activation / deactivation UI
- Automatic plugin updates for valid licenses
- Graceful handling when license is expired/invalid (core features still work; updates blocked)
- Keep BYO Twilio messaging clear in any license/account screens

### 15. Scheduled reminders (Ready for Pickup)
Auto-remind after X hours/days while order is still in Ready for Pickup. Respect quiet hours, consent, and `_toc_last_reminder_at`.

### 16. CSV export from Dashboard

### 17. Delivery failure alerts
Email admin (or configurable address) when SMS status becomes `undelivered` or `failed`.

### 18. “Mark as collected” action
Order action that sets `_toc_collected` and excludes the order from future auto/bulk notifications.

### 19. Role-based permissions UI
Expose the capability filters in Settings (simple role checkboxes or matrix).

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
- [ ] Marketing site + docs: **Bring your own Twilio account**
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [ ] Pricing (suggested $59–$79 / year per site)
- [ ] 30-day money-back, Terms, Privacy, Refund policy
- [ ] Merge PR #5 then PR #6 (or rebase #6 onto main after #5 merges)

---

## Suggested Implementation Order

1. ~~P0 Core Product Expansion (1–6)~~ ✅ v1.6.0  
2. ~~P0 Hardening (7–13)~~ ✅ v1.7.0  
3. **License system (P1 #14)** ← you are here  
4. Scheduled reminders + CSV + Mark as collected (15, 16, 18)  
5. Delivery alerts + role UI  
6. P2 after first sales feedback

---

## Merge order

1. Merge PR #5 (status-based v1.6.0) into `main`
2. Retarget or rebase PR #6 onto `main`, then merge (v1.7.0)
3. Tag release / build zip from `main`

---

## Cursor Prompt — Next Pass (P1 Licensing)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.7.0 after P0 hardening).

Read tasks.md carefully. All P0 tasks (1–13) are complete. Do not rework status-based auto-notify, admin traits, HTTP client, REST webhooks, or consent/quiet-hours unless you find a clear bug.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide messaging or calling services.
- Preserve HPOS, security (signature validation, nonces, tokenized TwiML), capability filters, and existing UX.
- Prefer small, reviewable changes. Bump version and changelog when shipping a feature set.

Implement P1 task 14 first: License key + auto-updates.
- Recommend and implement one approach suitable for a solo commercial WooCommerce plugin (Freemius is often fastest; EDD Software Licensing if the marketing site will be WordPress; Lemon Squeezy if you want simple checkout without heavy WP admin UI).
- If you need a decision on which vendor, ask once with a short comparison, then proceed after the user chooses.
- License UI should live under WooCommerce → Order Communicator (e.g. License or Account tab).
- Invalid/expired license: block updates, keep core messaging features working so stores are not locked out mid-operation.
- Keep “bring your own Twilio” messaging clear.

After licensing is solid, continue with P1 tasks 15–18 if time allows (scheduled reminders, CSV export, delivery failure alerts, mark as collected).

For each task:
1. State what you will change and which files.
2. Implement cleanly.
3. Summarize what was done and what remains.
```

---

*End of tasks.md*
