# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.8.0 (P0 1–13 + P1 #14 custom licensing merged to `main`)  
**Last updated:** 2026-07-31  
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

# P1 — High-Value Product Features

### 14. License key + auto-updates — ✅ DONE (v1.8.0, PR #7)
**Custom first-party system** (chosen over Freemius / EDD / Lemon Squeezy).
- [x] License tab: activate / deactivate / re-check, masked key, status + activations + expiry
- [x] Daily re-validation (Action Scheduler → WP-Cron) with 14-day grace on network errors
- [x] Update gate: `pre_set_site_transient_update_plugins` + `plugins_api`, fails closed
- [x] Invalid/expired never blocks SMS/voice/chat — only pauses premium updates
- [x] `license-server/` PHP + SQLite API with key / release CLIs (`license-server/README.md`)
- [x] Constants `TOC_LICENSE_SERVER_URL`, optional `TOC_LICENSE_ITEM_SLUG`

### 15. Scheduled reminders (Ready for Pickup) ← **next**
Auto-remind after X hours/days while order is still in Ready for Pickup. Respect quiet hours, consent, and `_toc_last_reminder_at`.

### 16. CSV export from Dashboard

### 17. Delivery failure alerts
Email admin (or configurable address) when SMS status becomes `undelivered` or `failed`.

### 18. “Mark as collected” action
Order action that sets `_toc_collected` and excludes the order from future auto/bulk notifications.

### 19. Role-based permissions UI
Expose the capability filters in Settings (simple role checkboxes or matrix).  
*Partial:* `toc_manage_settings` / `toc_send_sms` filters exist in `class-toc-caps.php`; no Settings UI.

---

# P1.5 — Known Gaps & Fixes (audit 2026-07-31)

Found by code audit of v1.8.0. Fix before or alongside the next feature pass.

### G1. License data option bloat — ✅ DONE (v1.8.1)
`persist()` nested `$data` inside itself as `last_payload`. Fixed with a fixed scalar snapshot; existing nested data self-heals on the next save.

### G2. Scheduled license check is never unscheduled — ✅ DONE (v1.8.1)
Deactivation hook + uninstall clear `toc_license_validate_cron` (and deferred auto-notify on uninstall) from Action Scheduler and WP-Cron.

### G3. Update cache not invalidated on license change — ✅ DONE (v1.8.1)
`TOC_Updater::flush_update_cache()` runs when `allows_updates()` becomes true and on license deactivate. Steady-state 6-hour cache kept.

### G4. Local Pickup skip re-processes forever — ✅ DONE (v1.8.1)
Local Pickup filter skip path stamps `_toc_notified_ready_for_pickup_at` like the no-phone skip.

### G5. Webhook URLs built by string concat — ✅ DONE (v1.8.2)
`TOC_Webhooks::rest_url()` now uses WordPress `\rest_url()` and rewrites the origin when `toc_webhook_base_url` is set. Legacy `?toc_sms=1` aliases unchanged.

### G6. Custom statuses are not in WooCommerce's paid list — ✅ DONE (v1.8.2)
`woocommerce_order_is_paid_statuses` includes `ready-for-pickup` and `shipped` so `WC_Order::is_paid()` stays true in those fulfillment states.

### G7. Inbound phone lookup performance — ✅ DONE (v1.8.2, incremental)
Still prefers communications-log matches first. Billing/log SQL now uses a full last-10 LIKE needle (not last-4 alone), with a filterable hard limit (`toc_inbound_phone_lookup_limit`, default 40, max 100). No schema/migration in this pass.

### G8. No automated tests; PHPCS not passing — ✅ DOCUMENTED (v1.8.2)
No PHPUnit suite added (deferred). `phpcs.xml.dist` documents that only `twilio-order-communicator/` is in scope (`license-server/` excluded) and that a full WPCS clean pass is not a hard CI gate yet.

---

# Testing Checklist (nothing below is verified on a production-like store)

**Done so far:** license server API smoke test (health / activate / validate / deactivate / update-check) and a local WP+WooCommerce install proving license activation works and messaging is never license-gated.

### Licensing
- [ ] Activate → deactivate → activate with a newer release registered; update appears promptly (see G3)
- [ ] Expired key and disabled key → notice shown, messaging still works
- [ ] Activation limit reached on a second site
- [ ] License server unreachable → 14-day grace, then updates stop
- [ ] Real WP update install from the signed package URL
- [ ] Plugin deactivate / uninstall leaves no scheduled jobs (see G2)

### Order statuses
- [ ] Bulk "Change status to Ready for Pickup" / "Shipped" on **HPOS** order screen
- [ ] Same bulk actions on **legacy CPT** order screen
- [ ] Remapped statuses (e.g. point Ready at `wc-processing`) still trigger auto-notify and bulk

### Auto-notify
- [ ] Quiet-hours deferral runs once after the window and clears deferred meta
- [ ] Voice + SMS both enabled → exactly one call and one SMS per transition
- [ ] Order receives Ready for Pickup, then later Shipped
- [ ] 1.5.x upgrade path: legacy `_toc_auto_notified_at` respected
- [ ] Site with WP-Cron disabled and no real cron

### Webhooks (real Twilio)
- [ ] Inbound SMS to REST route `/wp-json/toc/v1/sms` with valid signature
- [ ] Inbound SMS to legacy alias `?toc_sms=1`
- [ ] Plain permalinks and reverse-proxy / `toc_webhook_base_url` cases (see G5)
- [ ] Delivery status callbacks update log + order notes
- [ ] STOP / HELP / START replies logged into order chat

### Messaging
- [ ] Real Twilio credentials: manual SMS, manual call, tokenized TwiML playback
- [ ] Consent enforcement and force-send confirmation
- [ ] SMS footer appends once and is skipped when text already has opt-out wording
- [ ] Inbound SMS attaches to an **older** order (not just recent)
- [ ] Bulk reminders sequential send + stop button

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
- [ ] Deploy `license-server/` to a real HTTPS host (currently only smoke-tested locally)
- [ ] Tag a release and register the zip with `bin/add-release.php`

---

## Suggested Implementation Order

1. ~~P0 Core Product Expansion (1–6)~~ ✅ v1.6.0  
2. ~~P0 Hardening (7–13)~~ ✅ v1.7.0  
3. ~~License system (P1 #14, custom)~~ ✅ v1.8.0  
4. ~~P1.5 gap fixes G1–G4~~ ✅ v1.8.1  
5. ~~P1.5 gap fixes G5–G8~~ ✅ v1.8.2  
6. Scheduled reminders + CSV + Mark as collected (15, 16, 18)  
7. Delivery alerts + role UI (17, 19)  
8. P2 after first sales feedback

---

## Cursor Prompt — Next Pass (P1 features)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.8.2).

Read tasks.md. P0 (1–13), P1 #14 (custom licensing), and P1.5 gaps G1–G8 are complete.
Do not rework status-based auto-notify, admin traits, the Twilio HTTP client, REST webhooks,
consent, quiet hours, or the license system unless fixing a clear bug.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide
  messaging or calling services.
- Licensing stays custom (our own license-server/). Do not add Freemius / EDD / Lemon Squeezy.
- Invalid/expired license must never disable SMS, voice, or chat — only premium updates.
- Preserve HPOS, security (signature validation, nonces, tokenized TwiML), and capability filters.
- Prefer small, reviewable changes. Bump version and changelog when shipping a feature set.

Continue P1 in this order: 15 scheduled reminders, 16 CSV export, 18 mark as collected,
17 delivery failure alerts, 19 role permissions UI.

For each task:
1. State what you will change and which files.
2. Implement cleanly in the existing style.
3. Summarize what was done and what remains.
```

---

*End of tasks.md*
