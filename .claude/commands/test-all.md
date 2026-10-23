---
description: Run every local test suite and report the counts
allowed-tools: Bash(npm test), Bash(npm test *), Bash(node --test *), Bash(node test/frame-shop.test.mjs), Bash(node test/notch-tests.mjs), Bash(node suspension-designer/test/flexstay-tests.mjs), Bash(php wordpress-plugins/mu-plugins/tests/*)
---

Run the local suites from the repo root and report each pass/fail count. Do not call WooCommerce, Railway, Resend, or the live site, and do not read a `.env` file.

If `server/node_modules` is missing, run `npm install` in `server/` first (that install still asks for permission). Then:

```
cd server && npm test
node test/frame-shop.test.mjs
node test/notch-tests.mjs
node suspension-designer/test/flexstay-tests.mjs
php wordpress-plugins/mu-plugins/tests/creature-fd-only-purchase-test.php
php wordpress-plugins/mu-plugins/tests/creature-fd-order-experience-test.php
```

`npm test` must run in `server/`. Server and frame-shop print a `node:test` summary (`# tests`, `# pass`, `# fail`). Notch prints `all passed` or `N failed`. Flex-stay prints `all checks pass` or `N FAILURES`. The PHP scripts print `N checks passed` and `N passed, N failed`.

Report those counts. If a suite fails, stop and quote the failing names. Do not change code unless you were already asked to fix a failure.
