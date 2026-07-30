# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.8.0 (custom licensing)  
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

- [x] **7.** Shared `request()` HTTP client
- [x] **8.** Admin split (orchestrator + traits)
- [x] **9.** Improved phone → order matching
- [x] **10.** REST webhooks + query aliases
- [x] **11.** STOP/HELP/START auto-replies logged
- [x] **12.** Capability filters + `TOC_*` constants
- [x] **13.** SMS footer + Privacy Policy helper

---

# P1 — High-Value Product Features

### 14. License key + auto-updates — ✅ DONE (v1.8.0, PR #7)
Custom first-party license server + plugin client (not Freemius/EDD/Lemon Squeezy).  
See `license-server/README.md`. Invalid/expired license does **not** disable SMS/voice; it only gates premium updates.

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
- [ ] Marketing site + docs: **Bring your own Twilio account**
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [ ] Pricing (suggested $59–$79 / year per site)
- [ ] 30-day money-back, Terms, Privacy, Refund policy
- [x] Merge PR #5, #6 into `main`
- [ ] Merge PR #7 (this branch) into `main`
- [ ] Deploy license-server, create keys, register release zip

---

## Suggested Implementation Order

1. ~~P0 Core Product Expansion (1–6)~~ ✅ v1.6.0  
2. ~~P0 Hardening (7–13)~~ ✅ v1.7.0  
3. ~~Custom license system (P1 #14)~~ ✅ v1.8.0  
4. Scheduled reminders + CSV + Mark as collected (15, 16, 18)  
5. Delivery alerts + role UI  
6. P2 after first sales feedback

---

*End of tasks.md*
