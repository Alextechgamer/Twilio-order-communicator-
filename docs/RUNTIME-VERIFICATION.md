# Runtime verification — plugin suite against a real WordPress/WooCommerce install

**Branch:** `cursor/runtime-verification` (cut from `claude/security-review-batch5`, which
holds the Batch-5 security fixes — they are not on `main` yet).
**Date:** 2026-08-12.

Every prior batch of this project was written without a WordPress/WooCommerce/MySQL/Twilio
runtime. This document records what was **actually run** on a live install, with the
commands used and the observed output. Every item is one of:

- **PASS** — exercised on the live runtime, observed behaving as specified.
- **FAIL → fixed** — observed broken, fixed in this branch, re-run, now passing.
- **BLOCKED** — could not be exercised in this sandbox, with the concrete reason.

Nothing below is assumed or inferred from code alone.

## Environment

| Component | Detail |
|-----------|--------|
| OS / PHP | Ubuntu 24.04, PHP 8.3.6 (CLI + `php -S` dev server), `php8.3-sqlite3` installed for the license server |
| WordPress | 6.x at `~/wordpress`, `http://localhost:8080`, admin `admin`/`admin` |
| WooCommerce | 11.0.1, HPOS **disabled** by default (E3 toggles it) |
| Database | MariaDB 10.11 on `127.0.0.1` |
| Plugins | `twilio-order-communicator/`, `storecanvas/`, `orderbay/` symlinked from the repo into `wp-content/plugins/` and activated |
| Twilio | No live credentials (bring-your-own). Outbound Twilio HTTP captured/mocked via a `pre_http_request` mu-plugin; payloads asserted instead of live delivery |
| Mail | No sendmail. `wp_mail` captured via a `pre_wp_mail` mu-plugin (recipient/subject/attachments logged) |

Reproduce with `tools/dev/setup-wp.sh` (see the "Local development" section of the root README).

## Verdict matrix

| # | Item | Verdict |
|---|------|---------|
| A1 | license-server /v1/health | — |
| A2 | create-key + add-release CLIs | — |
| A3 | activate → update-check → signed download | — |
| A4 | H2 regression: update-check without site binding → 403 | — |
| A5 | rate limit 429 + download_secret fails closed | — |
| B1 | opt-out table upgrade: phone_last10 column, backfill, EXPLAIN uses index | — |
| B2 | opt-out across formatting variants (STOP/START/YES) | — |
| B3 | bulk tab: one batched opt-out query, not N | — |
| B4 | role matrix: admin saves; shop_manager refused; subscriber floor; admin never locked out | — |
| B5 | auth token: shop_manager can't change; admin can; TOC_AUTH_TOKEN constant wins | — |
| B6 | order screen SMS/call bound to billing phone; force bypasses consent not number | — |
| B7 | WhatsApp: clean degradation without sender; whatsapp: prefixes in payload | — |
| C1 | customized order creates artwork attachments with _sc_* markers | — |
| C2 | admin Print files links go through sc_dl proxy and download | — |
| C3 | sc_dl signature matrix (no/valid/expired/tampered sig, non-SC id) | — |
| C4 | anonymous REST media hides SC artwork; shop manager sees it | — |
| C5 | backfill re-marks pre-marker artwork | — |
| C6 | webserver deny rules: direct uploads 403, proxy still serves | — |
| C7 | proof email attaches files by path | — |
| C8 | FPD import: public https OK; loopback/metadata/private/IPv6 URLs skipped | — |
| D1 | RMA per-line quantities clamped; bogus ids dropped | — |
| D2 | RMA slip renders Return-qty column | — |
| D3 | RMA status emails once per transition; default off | — |
| D4 | document endpoints enforce login + order ownership | — |
| E1 | activate → deactivate → uninstall clean (options/tables/cron) | — |
| E2 | WP_DEBUG log clean across main flows | — |
| E3 | HPOS enabled AND disabled both work | — |

## Evidence

### A. license-server

_(pending)_

### B. Twilio Order Communicator

_(pending)_

### C. StoreCanvas

_(pending)_

### D. OrderBay

_(pending)_

### E. Cross-cutting

_(pending)_
