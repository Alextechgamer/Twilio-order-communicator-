# CONTEXT — OrderRing monorepo

## Current focus
Per `GAMEPLAN.md`: **stop building, start selling OrderRing** (flagship voice+SMS+WhatsApp
WooCommerce pickup notifier). Viable at v1.22.0 but zero customers, zero production
verification. Freeze OrderBay. Timebox StoreCanvas. Everything below serves getting OrderRing purchasable.

## Active constraints (from GAMEPLAN.md)
- Feature freeze everywhere except the commercial loop; OrderBay gets NO new features.
- License gates **updates only, never messaging** — keep this pledge verbatim in all copy.
- BYO Twilio, zero markup. One paid tier, no nag ads, 30-day refund.
- Compliance is the moat: consent audit, quiet-hours defer, signature validation. Drift = existential.
- Explicit non-goals: abandoned-cart/marketing automation, AI chatbot, reselling messaging.

## Last shift outcome (2026-08-15)
- **T1 LANDED** (commit b89dffd): E2E smoke in CI — `.github/workflows/lint.yml` + `tools/dev/e2e-smoke.{php,sh}` (order → Ready-for-Pickup → assert send via Twilio mock). Clean, 3-file commit.
- ⚠️ Second commit 2b0cd71 "strip never-touch paths" churned 139 files (~30k±/30k∓, mostly `storecanvas/*`, `tasks.md`) — looks like line-ending/whitespace normalization noise; verify it's intentional and didn't clobber real content before trusting the branch.
- **T2 LANDED**: Lite↔Pro parity gate in `tests/run.php` (+shims in `tests/bootstrap.php`) — runtime
  vectors (consent truthiness, E.164 + phone normalization, Twilio signature validation, STOP/HELP/START
  keywords, SMS footer) plus a normalized-source diff over the twin methods of `class-{orl,toc}-twilio.php`,
  `class-{orl,toc}-checkout.php`, `class-{orl,toc}-webhooks.php`. Mutation-tested: a one-sided edit fails
  `php tests/run.php`. Known accepted divergences are normalized in `t2_map_lite_to_pro()` (Lite sanitizes
  REQUEST_URI, Lite uses `esc_xml`) — reconcile them when T3 dedupes.
- T3–T5 NOT executed (still queued): T3 dedup triple-copied license client + drift gate; T4 fix unconditional `OB_QR::render_for_order()` (invoice.php:65 / packing-slip.php:129); T5 StoreCanvas Journey dev-only (default off, constant-gated, menu removed, nopriv ajax).

## Cross-repo learnings to respect
- Scope each task to land in ~20 turns / one committable file — cap-busting bundles commit nothing.
- No-commit tasks must reset the working tree so the next task starts clean.
- Don't derive "tonight's work" from the branch-name date; use commit timestamps.
- Avoid whitespace/line-ending churn commits — they bury real diffs (see 2b0cd71 above).
