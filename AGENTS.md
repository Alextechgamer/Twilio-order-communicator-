# Night Shift — Shared Agent Rules

You are part of an overnight dev team working while the owner sleeps. Grok Build is the manager; Claude Code is the primary developer; cursor-agent handles small mechanical edits.

## Hard rules (all agents)
1. Work ONLY on the current `night/*` branch. Never checkout, merge to, or push `main`.
2. Never deploy, publish, spend money, rotate/read secrets, call external paid services, or delete data or branches.
3. Commit small and often with `[TASK-ID] description` messages.
4. Blocked or unsure → append your question to `DECISIONS.md` (see its format) and STOP that task. Guessing on product decisions is a failure, not initiative.
5. Run the test suite before your final commit if one exists. A red test suite means the task is not done.
6. No new dependencies without a note in the commit message explaining why.

## Project context
<!-- Fill this in per project: stack, conventions, where things live, how to run it. Copy this file (or symlink it) into the repo root as AGENTS.md — Claude Code and Grok Build both auto-read it there. -->

- Stack: PHP 7.4+ WordPress/WooCommerce plugin monorepo (OrderRing / Twilio Order Communicator, OrderRing Lite, StoreCanvas, OrderBay, plus a PHP license-server). No Node runtime required for the plugins.
- Run locally: WordPress + WooCommerce; see `tools/dev/setup-wp.sh`. Plugins live in sibling folders: `twilio-order-communicator/`, `orderring-lite/`, `storecanvas/`, `orderbay/`, `license-server/`.
- Test command: `php tests/run.php` (dependency-free unit tests). CI also runs `php -l` on every PHP file and `php tools/release/check-versions.php`. PHPCS (`composer lint`) is advisory only.
- Code style: WordPress Coding Standards / PHPCompatibility via `phpcs.xml.dist`. Do not mass-fix historical style debt unless the task says so.
- Files/dirs never to touch: `.github/` secrets, `vendor/`, release zip artifacts, license keys, production deploy scripts that publish or spend money.

## Night Shift verification
Night Shift reads this block. Fill it in the next time you plan work here; an
empty PREVIEW_CMD means this repo is never previewed.

<!-- night-shift:verify -->
VERIFY=none
PREVIEW_CMD=
PREVIEW_PORT=auto
PREVIEW_PATH=/
PREVIEW_READY_MS=90000
PREVIEW_KIND=node
<!-- /night-shift:verify -->

- `VERIFY` — `none` (backend only), `preview` (start the dev server), or
  `full` (preview plus a Playwright pass with screenshots on every UI task).
- `PREVIEW_CMD` — how to start the dev server in the foreground. `$PREVIEW_PORT`
  is substituted. Bind `0.0.0.0`, not `localhost`.
- `PREVIEW_PORT` — `auto` (assigned from 5170-5189) or a fixed port in that range.
- `PREVIEW_PATH` — path health-checked once the port opens.
- `PREVIEW_READY_MS` — how long to wait for the first good response.
- `PREVIEW_KIND` — `node`, `static`, or `wordpress` (WooCommerce plugin repos
  preview against the shared WordPress stack; leave PREVIEW_CMD empty).
