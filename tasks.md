# OrderRing — Tasks & Roadmap

**Plugin version baseline:** 1.13.0 (Polly voice map + Ready/Shipped customer emails)  
**Last updated:** 2026-08-02  
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

### 15. Scheduled reminders (Ready for Pickup) — ✅ DONE (v1.9.0)
Auto-remind after X hours while order is still in Ready for Pickup.
- [x] Settings: `toc_scheduled_reminder_enabled` (default off), `toc_scheduled_reminder_delay_hours` (default 24)
- [x] Schedule single AS action when order enters mapped Ready status (`class-toc-reminders.php`)
- [x] On fire: still Ready, quiet hours re-defer, phone, consent (SMS), `_toc_last_reminder_at` cooldown
- [x] Send via Twilio paths + stamp `_toc_last_reminder_at` + order notes
- [x] Cancel on leave Ready / deactivate / uninstall
- [x] Messaging ungated by license

### 16. CSV export from Dashboard — ✅ DONE (v1.10.0)
- [x] Export CSV control on Dashboard (filters: type, direction, resolved, search, dates)
- [x] `admin_post_toc_export_csv` + nonce + `TOC_Caps::manage()`
- [x] Streamed CSV via `TOC_Logger::get_filtered()` in chunks; proper cell escaping

### 17. Delivery failure alerts — ✅ DONE (v1.11.0)
- [x] Optional email when Twilio SMS StatusCallback is `failed` / `undelivered` (`TOC_Webhooks::message_status`)
- [x] Settings: `toc_delivery_alert_enabled` (default off), `toc_delivery_alert_email` (empty → admin_email)
- [x] Dedup per MessageSid via transient; keep order notes; SMS-only scope

### 18. “Mark as collected” action — ✅ DONE (v1.10.0)
- [x] Order action Mark / Unmark collected → `_toc_collected` timestamp (HPOS CRUD meta)
- [x] Auto-notify skips + stamps; scheduled reminders cancel/skip; bulk list excludes
- [x] Badge on order communications meta box + order notes

### 19. Role-based permissions UI — ✅ DONE (v1.12.0)
- [x] Caps `toc_manage` / `toc_send`; defaults to admin + shop_manager (seed once)
- [x] Settings role matrix; `TOC_Caps::manage()` / `send()` use new caps (filters kept)
- [x] Administrator always retains manage; messaging still license-free

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

- [x] Final product name: **OrderRing** (see `docs/launch/NAMING.md`; Twilio attribution in-plugin)
- [ ] Marketing site + docs: **Bring your own Twilio account** (`docs/launch/site/` is the copy; host it)
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [x] Pricing decision recorded (`docs/launch/PRICING.md`)
- [x] 30-day money-back, Terms, Privacy, Refund policy (`docs/launch/legal/`)
- [ ] Deploy `license-server/` to a real HTTPS host (`docs/launch/LICENSE-SERVER-DEPLOY.md` — needs a domain)
- [ ] Tag a release and register the zip with `bin/add-release.php`

---

## Suggested Implementation Order

1. ~~P0 Core Product Expansion (1–6)~~ ✅ v1.6.0  
2. ~~P0 Hardening (7–13)~~ ✅ v1.7.0  
3. ~~License system (P1 #14, custom)~~ ✅ v1.8.0  
4. ~~P1.5 gap fixes G1–G4~~ ✅ v1.8.1  
5. ~~P1.5 gap fixes G5–G8~~ ✅ v1.8.2  
6. ~~Scheduled reminders (15)~~ ✅ v1.9.0  
7. ~~CSV + Mark as collected (16, 18)~~ ✅ v1.10.0  
8. ~~Delivery failure alerts (17)~~ ✅ v1.11.0  
9. ~~Role permissions UI (19)~~ ✅ v1.12.0  
10. ~~Polly voice map + Ready/Shipped customer emails~~ ✅ v1.13.0  
11. P2 after first sales feedback

---

### 1.13.0 (2026-08-02) — ✅ DONE
- [x] Polly TwiML voice mapping (polly.* → Polly.*)
- [x] Consent meta Settings help text for third-party checkboxes
- [x] Optional customer emails on Ready for Pickup / Shipped (wp_mail, once-per-order meta)
- [x] Independent of voice/SMS toggles; quiet hours do not apply; messaging ungated by license

---

## Cursor Prompt — Next Pass (P1 features)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (current version 1.13.0).

Read tasks.md. P0 and P1 items 14–19 plus 1.13.0 Polly/status emails are complete.
Do not rework licensing, webhooks, auto-notify, reminders, CSV, mark-as-collected,
delivery alerts, role caps, or status emails unless fixing a clear bug.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number.
- Licensing stays custom; invalid/expired license must never disable SMS/voice/chat.
- Preserve HPOS, security, and capability filters.
- Prefer small, reviewable changes. Bump version and changelog when shipping.

Continue with P2 items from tasks.md as prioritized by sales feedback.
```

---

*End of tasks.md*
