# CONTEXT — OrderRing monorepo

## Current focus
Per `GAMEPLAN.md`: **stop building, start selling OrderRing** (the flagship voice+SMS+WhatsApp
WooCommerce pickup notifier). It's viable at v1.22.0 but has zero customers, zero production
verification. Freeze OrderBay. Timebox StoreCanvas. Everything below serves getting OrderRing purchasable.

## Active constraints (from GAMEPLAN.md)
- Feature freeze everywhere except the commercial loop; OrderBay gets NO new features.
- License gates **updates only, never messaging** — keep this pledge verbatim in all copy.
- BYO Twilio, zero markup. One paid tier, no nag ads, 30-day refund.
- Compliance is the moat: consent audit, quiet-hours defer, signature validation. Drift = existential.
- Explicit non-goals: abandoned-cart/marketing automation, AI chatbot, reselling messaging.

## Near-term queued work (report 2026-08-15, not yet landed)
- T1: E2E smoke in CI via existing Twilio HTTP mock (order → Ready-for-Pickup → assert send).
- T2: Lite↔Pro parity tests for the ~27 duplicated Twilio methods (consent/signature/STOP/footer).
- T3: Dedup triple-copied license client (sc/ob/orderring) + CI drift gate.
- T4: Fix unconditional `OB_QR::render_for_order()` in invoice.php:65 / packing-slip.php:129.
- T5: Make StoreCanvas Journey dev-only (default off, constant-gated, menu removed, nopriv ajax).

## Last shift outcome (2026-08-15)
No work landed for this repo: `night/2026-08-15` has **0 commits ahead of main**, report work log is
empty. Shift hit an ASAP wrap-up; planning proposals came back empty (verbatim-queue fallback, 45 tasks).
5 tasks (T1–T5 above) were queued for this repo but none executed.

## Cross-repo learnings to respect
- Scope each task to land in ~20 turns / one committable file — cap-busting bundles commit nothing.
- No-commit tasks must reset the working tree so the next task starts clean.
- Don't derive "tonight's work" from the branch-name date; use commit timestamps.
