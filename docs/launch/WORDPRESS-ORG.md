# WordPress.org plugin directory

These three plugins are **sold from your own site**. They are **not** suitable for hosting on WordPress.org as submitted.

The directory rules you asked about ([detailed guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)) include:

| Rule | What it means here |
|------|--------------------|
| **1 — GPL** | Already GPLv2 or later. Human-readable PHP ships in every zip. |
| **5 — No trialware / paywalls** | The directory forbids time-limited trials and license gates that lock **built-in features**. Our 30-day trial only covers **premium updates**. SMS, the canvas, invoices, and other built-in tools stay on after day 30. |
| **7 — No phone-home without consent** | The license client calls `licenses.alextechgamer.com` to activate keys and check updates. That is normal for a sold plugin and is a rejection reason on .org. |
| **8 — No third-party update servers** | Updates come from your license server, not WordPress.org SVN. Automatic rejection. |
| **10 — No public “powered by” links** | None of the plugins print a public credit/link. Do not add one. |
| **17 — Trademarks** | OrderRing’s Twilio attribution is required. Do not slug a .org plugin as `twilio-*` without Twilio’s permission. |

## What we did instead of a “free version”

A WordPress.org listing would have to be **fully unlocked, no trial, no private update server**. That is giving the product away.

Commercial zips now:

1. Start a **30-day trial** on first admin visit (per plugin).
2. During the trial the License screen shows days remaining.
3. After the trial, a key is needed for **premium updates** only.
4. Built-in functionality is never switched off (directory rule 5 + existing product rule: messaging never locks).

Sell the zips from your site. Do not submit this tree to WordPress.org unless you first strip the license client, the update injector, and the trial.

## If you later want a .org listing

Ship a **separate** plugin: no license PHP, no update-check, no trial, no phone-home. Keep the paid product off-directory.
