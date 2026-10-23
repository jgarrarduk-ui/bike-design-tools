# Creature Cycles bike design tools

James runs Claude Code locally against this repo. Four static browser tools live at `https://creaturecycles.co.uk/apps/`. `server/` is the tools-api (Express on Railway). `config.js` is the public API origin (`CREATURE_TOOLS_API_BASE`), not a secret.

`suspension-designer/CLAUDE.md` is the architecture note for that tool. Read it before changing the solver or the drawing.

## What each part is

| Path | Role |
|---|---|
| `index.html` | Landing page for the four tools |
| `frame-designer.html` | Parametric frame geometry, plus notch templates between `// ==NOTCH-START==` and `// ==NOTCH-END==` |
| `frame-shop.js` | Save, part picker, checkout. The page loads `frame-shop.js?v=…` |
| `config.js` | Public tools-api origin |
| `spoke-calculator.html` | J-bend spoke lengths. No tests |
| `spring-calculator.html` | Rear coil spring rate. No tests |
| `suspension-designer/` | Flex-stay kinematics. Engine is `// ==ENGINE-START==` … `// ==ENGINE-END==` in `index.html` |
| `flexstay/` | Redirect to `suspension-designer/` |
| `test/` | Notch and frame-shop checks |
| `fusion-import/` | Fusion 360 scripts. They run only inside Fusion |
| `server/` | Designs, WooCommerce checkout, `GET /api/prices`, Resend email, SQLite, `/admin` |
| `wordpress-plugins/mu-plugins/` | Must-use PHP. Copy each plugin file into `wp-content/mu-plugins/` (not a subfolder) |
| `.nojekyll` | Stops GitHub Pages/Jekyll ignoring `_` directories |

No root `package.json`, no CI config, no `railway.toml`. Static files have no build step. The root `.gitignore` ignores `.env` and `.env.*` in any directory and keeps `.env.example` tracked. `server/.gitignore` also ignores `node_modules/`, `data/`, `*.log`, and `.env`.

## Install and run

Static tools: open the HTML file, or serve the repo root. Frame Designer needs `config.js` and `frame-shop.js` beside it.

tools-api, from `server/package.json` (Node `>=18`):

```
cd server
npm install
cp .env.example .env
npm start
```

`npm run dev` is nodemon. Blank WooCommerce variables use the placeholder checkout. For local `/admin` Send Review Email, set `BASE_URL` to the origin you open, for example `http://127.0.0.1:3001`.

Fusion: Utilities → Add-Ins → Scripts, then Run. See `fusion-import/README.md`.

mu-plugins: copy the PHP file only. WordPress loads it with no activate step. WooCommerce must already be active.

## Tests

Local and mocked only. Server tests stub `global.fetch` and listen on `127.0.0.1`. PHP tests stub WordPress. Do not point them at WooCommerce, Railway, Resend, or the live site.

```
cd server && npm test
node test/frame-shop.test.mjs
node test/notch-tests.mjs
node suspension-designer/test/flexstay-tests.mjs
php wordpress-plugins/mu-plugins/tests/creature-fd-only-purchase-test.php
php wordpress-plugins/mu-plugins/tests/creature-fd-order-experience-test.php
node .claude/hooks/block-secrets.test.mjs
```

`npm test` is `node --test test/*.test.mjs` and must be run from `server/`. `server/test/retention-case.mjs` is a worker, not its own suite. Frame shop uses `node:test`. Notch and flex-stay use an `ok()` counter and exit 1 on failure; a clean flex-stay run ends `all checks pass`. Do not edit the frozen `REF` geometry in `suspension-designer/test/flexstay-tests.mjs` to make a check pass.

PHP tests are plain scripts, not PHPUnit. There is no `composer.json`. `creature-unpaid-design-orders.php` and `creature-rear-end-discount.php` have no test file of their own; the only-purchase test loads the discount class. Needs the `php` CLI.

Solver or drawing changes: check Safari as well as Chrome. One flex-stay bug showed up only in JavaScriptCore.

Run the suites the change can affect, and report the counts before finishing. `/test-all` runs this list.

## Conventions

- Server code is CommonJS and `'use strict'`. Routes are `server/routes/`, services are `server/services/`.
- `frame-shop.js` is an IIFE that also sets `module.exports`. `/apps/frame-shop.js` is cached for a year, so bump the `?v=` query in `frame-designer.html` when that script changes.
- Keep the notch block and the suspension engine free of page globals and the DOM. Tests extract them with `new Function`.
- PHP uses WordPress style (tabs, space inside parentheses). Plugins are GPL-2.0-or-later, prefixed `creature_` / `Creature_`.
- The phrase `5 working days` is copied in `frame-shop.js` (`FD_LEAD_TIME`), the filter `creature_fd_lead_time`, and the API env `FD_LEAD_TIME`. The API cannot read the filter. Leave the three in step.

## Hard rules

- Work on a branch only. Never push, merge, or deploy, and never force-push.
- Never read or commit secrets or `.env` files. `server/.env.example` is the only env reference.
- Never log, print, or echo secret values (env vars, tokens, keys), including in test output or debug lines. Refer to them by name.
- Keep `RETENTION_PURGE_DRY_RUN` defaulting to a dry run. `dryRun()` in `server/services/retention-purge.js` writes only when the value is exactly `0`. Unset, empty, and `1` (the value in `server/.env.example`) are dry runs. Never change that default and never set the variable to `0`.
- Never blank or clear `ADMIN_PASSWORD`, `WORKER_TOKEN`, `DOWNLOAD_SIGNING_SECRET`, or any other token, and never add a code path that runs with one of them empty. `WORKER_TOKEN` and `DOWNLOAD_SIGNING_SECRET` are not named in this repo; the rule still applies.
- No test orders, form submissions, or customer emails against live systems, ever. WooCommerce and email stay stubbed in tests.
- No live requests to WooCommerce, Railway, Resend, or the live site. Tests stay local and mocked.
- PR reviews are COMMENT only. Never approve or request changes.
- Deploys are done by Layout via `railway up`. Don't change the Dockerfile, `railway.json`, `railway.toml`, or other deploy config unless the task explicitly says so.
- Don't change prices, totals, order creation, or the rear-end discount unless the task explicitly says so. The discount is `Creature_Rear_End_Discount::DISCOUNT` (`24.0`), fee name "Whole rear end discount": −£24 per complete set of 8634, 8635, and 8636 that share a `design_id`. Frame Designer prices come live from WooCommerce via `GET /api/prices`. The page keeps no catalogue prices. 8634 BB yoke, 8635 SS yoke, 8636 dropouts (shown, not sold), 8637 is not a checkout choice.
- CORS: exact-origin allowlist of `FRONTEND_URL` (trailing slash stripped; scheme and host compared case-insensitively; the reflected header is the normalised origin). A longer host does not match. Same-origin is only an `Origin` equal to `BASE_URL` (the API's public origin, no path). That path sets no `Access-Control-Allow-Origin`. If `BASE_URL` is unset, nothing is same-origin. `Host` and `X-Forwarded-Proto` are not used. Local origins `http://localhost:3000`, `http://localhost:5173`, and `http://127.0.0.1:5173` are added only when `NODE_ENV` is `development` or `dev`. A refused `GET` or `HEAD` has no allow-origin header; any other refused method, including preflight, is 403. Do not loosen this.
- Run the relevant test suites before finishing and report the counts.
- Ask before scope creep. Discuss architecture before a large change.

## Claude Code files

`.claude/settings.json` allows `npm test`, `node --test` only for `test/*.test.mjs` and `server/test/*.test.mjs`, and the named frame-shop, notch, flex-stay, PHP, and hook-check commands. It denies Read and Edit of `.env` and `.env.*`, then carves `.env.example` back out, and it denies `git push`. Read-only git (`status`, `diff`, `log`, `show`) is not allow-listed: Claude Code already runs those without a prompt, and a `git log *` allow would also permit `--output=` writes. Install, edit, and commit still ask. No MCP servers.

`.claude/hooks/block-secrets.mjs` is a guard rail, not a security boundary. It runs before Bash as `node` on `PATH`. If `node` is missing, any exit other than 2 fails open and the normal permission prompt still applies. It exits 2 for `git push`, including `git -C … push`, `/usr/bin/git push`, `sh -c 'git push'`, and the same command after `env`, `exec`, `eval`, `npx`, `npm exec`, `nice`, `nohup`, or `time`. It also exits 2 for a path that is `.env` or `.env.<suffix>` other than `.env.example`. `.envrc` is not matched. Exit 0 does not approve the command. Globs (`cat server/.e*`), quote-splitting (`cat server/.e''nv`), and a program that opens the file itself are outside the hook. The deny rules have the same limit. `node .claude/hooks/block-secrets.test.mjs` checks example commands.
