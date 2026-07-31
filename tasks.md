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

### G1. License data option bloat — `class-toc-license.php:392`
`persist()` does `$data['last_payload'] = $data`, nesting the array inside itself. Repeated saves grow `toc_license_data` each time. Store a trimmed payload (or drop `last_payload`).

### G2. Scheduled license check is never unscheduled
No `register_deactivation_hook`, `wp_clear_scheduled_hook`, or `as_unschedule_all_actions` anywhere. `toc_license_validate_cron` keeps firing after plugin deactivate/uninstall. Add a deactivation hook and clean up in `uninstall.php`.

### G3. Update cache not invalidated on license change — `class-toc-updater.php:115`
`toc_update_check_*` site transient lives 6 hours and is never deleted on activate / deactivate. After activating a license, a pending update can stay hidden for up to 6 hours. Delete the transient in `TOC_License::persist()` and `deactivate()`.

### G4. Local Pickup skip re-processes forever — `class-toc-auto.php:288`
When the optional Local Pickup filter rejects an order, the code returns **without** stamping `_toc_notified_ready_for_pickup_at`, so every re-save adds another order note. Stamp the meta (or a separate skip meta) like the no-phone path does.

### G5. Webhook URLs built by string concat — `class-toc-webhooks.php:30`
`rest_url()` is hand-built as `{base}/wp-json/...`. Plain permalinks, `index.php` URLs, or subdirectory installs can produce a Tools-tab URL that differs from the URL Twilio actually hits. Prefer WordPress `rest_url()` with the existing base override applied.

### G6. Custom statuses are not in WooCommerce's paid list
`wc-ready-for-pickup` / `wc-shipped` are registered but not added via `woocommerce_order_is_paid_statuses`, so `WC_Order::is_paid()` is false while an order sits in them. Decide intentionally; add the filter if orders can reach these statuses from unpaid states.

### G7. Inbound phone lookup performance — `class-toc-logger.php:506`
HPOS lookup uses `phone LIKE '%1234'` (no index) then loads up to 100 orders per inbound SMS. Fine for small stores; revisit with a normalized phone column if volume grows.

### G8. No automated tests; PHPCS not passing
No PHPUnit or `tests/` directory. `phpcs.xml.dist` covers only `twilio-order-communicator/` (not `license-server/`) and the code currently reports several hundred WPCS errors (mostly docblocks / Yoda / alignment). Decide whether to enforce in CI or relax the ruleset.

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
4. **P1.5 gap fixes (G1–G4 at minimum)** ← recommended next  
5. Scheduled reminders + CSV + Mark as collected (15, 16, 18)  
6. Delivery alerts + role UI (17, 19)  
7. P2 after first sales feedback

---

## Cursor Prompt — Next Pass (P1.5 fixes + P1 features)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.8.0).

Read tasks.md. P0 (1–13) and P1 #14 (custom licensing) are complete and merged. Do not rework
status-based auto-notify, admin traits, the Twilio HTTP client, REST webhooks, consent, quiet hours,
or the license system unless fixing a listed gap.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide
  messaging or calling services.
- Licensing stays custom (our own license-server/). Do not add Freemius / EDD / Lemon Squeezy.
- Invalid/expired license must never disable SMS, voice, or chat — only premium updates.
- Preserve HPOS, security (signature validation, nonces, tokenized TwiML), and capability filters.
- Prefer small, reviewable changes. Bump version and changelog when shipping a feature set.

First: fix the P1.5 gaps G1–G4 (license option bloat, cron cleanup on deactivate/uninstall,
update transient invalidation, Local Pickup skip re-processing). Keep each fix small.

Then continue P1 in this order: 15 scheduled reminders, 16 CSV export, 18 mark as collected,
17 delivery failure alerts, 19 role permissions UI.

For each task:
1. State what you will change and which files.
2. Implement cleanly in the existing style.
3. Summarize what was done and what remains.
```

---

*End of tasks.md*
