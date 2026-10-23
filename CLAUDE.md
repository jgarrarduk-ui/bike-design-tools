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

No root `package.json`, no CI config, no `railway.toml`. Static files have no build step. There is no root `.gitignore`; `server/.gitignore` ignores `.env`, `node_modules/`, `data/`, and `*.log`.

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
- Never read, print, or commit secrets or `.env` files. `server/.env.example` is the only env reference.
- Don't change prices, totals, order creation, or the rear-end discount unless the task explicitly says so. The discount is `Creature_Rear_End_Discount::DISCOUNT` (`24.0`), fee name "Whole rear end discount": −£24 per complete set of 8634, 8635, and 8636 that share a `design_id`. Frame Designer prices come live from WooCommerce via `GET /api/prices`. The page keeps no catalogue prices. 8634 BB yoke, 8635 SS yoke, 8636 dropouts (shown, not sold), 8637 is not a checkout choice.
- No live requests to WooCommerce, Railway, Resend, or the live site. Tests stay local and mocked.
- CORS: exact-origin allowlist of `FRONTEND_URL` (trailing slash stripped; scheme and host compared case-insensitively; the reflected header is the normalised origin). A longer host does not match. Same-origin is only an `Origin` equal to `BASE_URL` (the API's public origin, no path). That path sets no `Access-Control-Allow-Origin`. If `BASE_URL` is unset, nothing is same-origin. `Host` and `X-Forwarded-Proto` are not used. Local origins `http://localhost:3000`, `http://localhost:5173`, and `http://127.0.0.1:5173` are added only when `NODE_ENV` is `development` or `dev`. A refused `GET` or `HEAD` has no allow-origin header; any other refused method, including preflight, is 403. Do not loosen this.
- Run the relevant test suites before finishing and report the counts.
- Ask before scope creep. Discuss architecture before a large change.

## Claude Code files

`.claude/settings.json` allows read-only git inspection (`status`, `diff`, `log`, `show`) and the test commands above. It denies Read and Edit of `.env` and `.env.*`, then carves `.env.example` back out so that file stays readable, and it denies `git push`. Install, edit, and commit still ask. No MCP servers.

`.claude/hooks/block-secrets.mjs` runs before Bash. It exits 2 when the command is `git push`, including `git -C … push` and `sh -c 'git push'`, or when the command names a `.env` file other than `.env.example` (`cat server/.env`, a `readFileSync` of that path). Exit 0 leaves the normal permission prompt. Unparseable hook input exits 0. The hook does not approve anything. `Bash(git push *)` in settings does not match `git -C … push`; the hook covers that form. A push invoked as `/usr/bin/git push` without going through `sh -c` is outside the hook.
