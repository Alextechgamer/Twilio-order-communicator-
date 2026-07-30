# Twilio Order Communicator — Tasks & Roadmap

**Plugin version baseline:** 1.5.0  
**Last updated:** 2026-07-30  
**Purpose:** Single source of truth for Cursor AI / developers. Implement in priority order.

---

## Important Product Positioning

- This is a **WordPress / WooCommerce plugin** that lets store owners connect **their own Twilio account**.
- Users must provide their own Account SID, Auth Token, and From Number.
- The plugin does **not** sell, resell, or provide any messaging, SMS, voice, or calling services.
- All message and call costs are billed directly by Twilio to the store owner.
- Always keep this clear in the UI, docs, Setup wizard, and any marketing copy.

**Product focus (selling version):**  
Order communication driven by **custom WooCommerce order statuses**:
- **Ready for Pickup**
- **Shipped**

Independent enable toggles, voice/SMS choices, and custom messages for each status.  
No longer limited to Local Pickup shipping method only.

---

## Priority Legend

| Priority | Meaning |
|----------|--------|
| **P0**   | Do before charging money / public launch |
| **P1**   | High value — next for a strong paid product |
| **P2**   | Nice-to-have / post-launch |

---

# P0 — Core Product Expansion (DO FIRST)

Implement tasks 1–6 completely before moving on. This is the new product model.

---

## 1. Register custom WooCommerce order statuses

**Register these if they do not already exist:**

| Internal slug           | Admin label        |
|-------------------------|--------------------|
| `wc-ready-for-pickup`   | Ready for Pickup   |
| `wc-shipped`            | Shipped            |

**Requirements:**
- Use standard WooCommerce registration (`register_post_status` + `wc_order_statuses` filter).
- Show them in the order status dropdown and bulk actions.
- Do **not** force existing orders into these statuses on activation.

**Mapping settings** (so stores can point to different existing statuses if needed):

| Option key                     | Default value            | Purpose |
|--------------------------------|--------------------------|--------|
| `toc_status_ready_for_pickup`  | `wc-ready-for-pickup`    | Status that triggers Ready for Pickup logic |
| `toc_status_shipped`           | `wc-shipped`             | Status that triggers Shipped logic |

UI: two dropdowns listing all registered order statuses.

---

## 2. Independent auto-notify controls + custom messages

### Settings section: Automatic Notifications

**Ready for Pickup**
| Option key                      | Type     | Default | Purpose |
|---------------------------------|----------|---------|--------|
| `toc_auto_ready_enabled`        | checkbox | `1`     | Enable auto notifications for this status |
| `toc_auto_ready_voice`          | checkbox | `1`     | Send voice call |
| `toc_auto_ready_sms`            | checkbox | `0`     | Also send SMS (consent required) |
| `toc_message_ready_for_pickup`  | textarea | see below | Message template |

**Shipped**
| Option key                      | Type     | Default | Purpose |
|---------------------------------|----------|---------|--------|
| `toc_auto_shipped_enabled`      | checkbox | `0`     | Enable auto notifications for this status |
| `toc_auto_shipped_voice`        | checkbox | `0`     | Send voice call |
| `toc_auto_shipped_sms`          | checkbox | `0`     | Also send SMS (consent required) |
| `toc_message_shipped`           | textarea | see below | Message template |

**Other templates**
| Option key               | Purpose |
|--------------------------|--------|
| `toc_message_reminder`   | Bulk + scheduled reminders (orders still in Ready for Pickup) |
| `toc_message_issue`      | Manual “Issue / Contact” quick template (keep existing behavior) |

**Shared settings that still apply to both statuses:**
- Quiet hours (`toc_quiet_hours_*`)
- Require SMS consent (`toc_require_sms_consent`)
- Checkout consent settings
- Voice selection (`toc_voice`)

### Default message copy

```
Ready for Pickup:
Hello {customer_first_name}. Your order #{order_number} is ready for pickup. Please come to the store when convenient. Thank you.

Shipped:
Hello {customer_first_name}. Your order #{order_number} has shipped. Thank you for your order.

Reminder:
Hello {customer_first_name}. This is a reminder that your order #{order_number} is still waiting for pickup. Please stop by at your earliest convenience. Thank you.
```

All templates must support existing merge tags:  
`{order_number}` `{order_id}` `{customer_first_name}` `{customer_last_name}` `{customer_full_name}` `{store_name}` `{phone}` `{order_total}` `{billing_email}`

---

## 3. Meta keys for notification tracking

| Meta key                            | Purpose |
|-------------------------------------|--------|
| `_toc_notified_ready_for_pickup_at` | Timestamp when Ready for Pickup auto-notify ran |
| `_toc_notified_shipped_at`          | Timestamp when Shipped auto-notify ran |
| `_toc_last_reminder_at`             | Last bulk / scheduled reminder (keep existing) |
| `_toc_collected`                    | Later: mark as done / exclude from future notifies |

- Use a consistent timestamp format matching the rest of the plugin.
- Clearing the relevant meta must allow a re-send (document this in Tools/Docs).

---

## 4. Auto-notify logic (exact behavior)

On order status change:

1. Read the new status slug.
2. **If** it matches the mapped Ready for Pickup status **and** `toc_auto_ready_enabled` is on:
   - Skip if `_toc_notified_ready_for_pickup_at` is already set.
   - Respect quiet hours (defer with Action Scheduler / WP-Cron when needed).
   - If voice enabled → place call using `toc_message_ready_for_pickup`.
   - If SMS enabled → send only if consent rules pass; use the same template.
   - Set `_toc_notified_ready_for_pickup_at` and write clear order notes (sent or skipped + reason).
3. **If** it matches the mapped Shipped status **and** `toc_auto_shipped_enabled` is on:
   - Same pattern with Shipped settings and `_toc_notified_shipped_at`.

**Rules that stay the same:**
- An order **can** receive both notifications (Ready for Pickup first, later Shipped).
- SMS still requires consent + respects STOP list.
- Voice never requires SMS consent.
- Manual Send SMS / Place Call + order chat history stay unchanged.

---

## 5. Update Bulk Reminders

- Primary target: orders currently in the mapped **Ready for Pickup** status.
- Use `toc_message_reminder`.
- Keep existing UX: lookback days, hide recently reminded, consent column, sequential send with delay, progress log.
- Update **all** UI labels and help text that still say “Local Pickup only”.

Optional later: bulk for Shipped (not required for first paid release).

---

## 6. Soften old Local Pickup shipping-method restriction

- Primary trigger is now **order status**.
- Old `toc_pickup_match` setting becomes **optional / secondary**.
- Add an optional checkbox, default **off**:  
  “Only auto-notify Ready for Pickup if shipping method looks like Local Pickup”
- Replace the old single “Auto on Completed + Auto Voice / Auto SMS” controls with the per-status controls above.
- Update Setup wizard, Tools & Docs tab, order notes, README, and `readme.txt` so nothing still claims the plugin is Local Pickup only.

---

## Files that need the most work for P0 1–6

- `includes/class-toc-auto.php` — status triggers + per-status logic
- Admin settings UI (currently in `class-toc-admin.php`) — new fields and layout
- Main plugin file / activation — register statuses + seed new options
- Bulk logic (admin + logger) — query by status
- `readme.txt`, Setup wizard, Tools & Docs copy

---

# P0 — Code Quality & Hardening (Still Required)

Do these after (or carefully alongside) the product expansion.

### 7. Extract shared Twilio HTTP client
**File:** `includes/class-toc-twilio.php`  
Create a private `request( $method, $path, $body = array() )` helper. Refactor `send_sms()`, `make_call()`, and `test_credentials()` to use it.

### 8. Split `class-toc-admin.php`
Break into focused classes/traits (settings, dashboard, bulk, ajax, tools). Keep a thin orchestrator. Settings are growing — this matters now.

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
**Status: Done (v1.8.0)** — custom first-party license server + plugin client (not Freemius/EDD/Lemon Squeezy).  
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
- [ ] Marketing site + docs that clearly say **Bring your own Twilio account**
- [ ] Screenshots of Ready for Pickup + Shipped flows
- [ ] Pricing (suggested $59–$79 / year per site)
- [ ] 30-day money-back, Terms, Privacy, Refund policy

---

## Suggested Implementation Order

1. **P0 Core Product Expansion (tasks 1–6)** — statuses, per-status toggles & messages, auto + bulk logic, copy updates  
2. **P0 Hardening (tasks 7–13)** — HTTP client, admin split, webhooks, logging, footer  
3. **Custom license system (P1 #14)** — first-party license server + plugin client (done in v1.8.0)  
4. Scheduled reminders + CSV + Mark as collected  
5. Remaining P1 / P2 after first sales feedback

---

## Cursor Prompt (copy-paste this entire block)

```
You are working on the WooCommerce plugin “Twilio Order Communicator” (baseline version 1.5.0) in this repository.

Read tasks.md carefully. It is the single source of truth for what to build.

Key constraints:
- Users always bring their own Twilio Account SID, Auth Token, and From Number. We never provide messaging or calling services.
- Expand the product from “Local Pickup only” to a status-based model:
  - Custom statuses: Ready for Pickup (`wc-ready-for-pickup`) and Shipped (`wc-shipped`)
  - Independent enable / voice / SMS toggles and custom message templates for each status
  - Mapping settings so stores can point at different existing statuses if needed
- Preserve existing consent handling, quiet hours, HPOS compatibility, security (Twilio signature validation, nonces, tokenized TwiML), manual send, and order chat history.
- Prefer small, reviewable changes. Update version number, changelog, and all user-facing copy when behavior changes.

Start with P0 Core Product Expansion (tasks 1–6 in tasks.md). Follow the exact option keys, meta keys, default messages, and auto-notify logic defined in that file.

For each task or logical group:
1. Briefly state what you will change and which files.
2. Implement cleanly in the existing code style (WordPress / WooCommerce patterns already used in the plugin).
3. After the group, summarize what was done and what remains.

Ask before making large architectural decisions if anything is ambiguous.
Do not start P0 hardening (tasks 7+) or licensing until tasks 1–6 are complete and working.
```

---

*End of tasks.md*
