# bike design tools

Creature Cycles' bike design tools. Four static, single-file browser tools
plus a small backend that turns a finished design into a paid order.

Self-hosted at `https://creaturecycles.co.uk/apps/`. The same static files
also stay on GitHub Pages (`jgarrarduk-ui.github.io/bike-design-tools/`)
during the dual-host period.

## Tools

| | File | What it does |
|---|---|---|
| Frame Designer | `frame-designer.html` | Parametric bicycle frame geometry: angles, lengths, stack/reach, drawn live. |
| Spoke Length Calculator | `spoke-calculator.html` | J-bend spoke lengths for 1×, 2× and 3× lacing, with rim and hub presets. |
| Spring Rate Calculator | `spring-calculator.html` | Rear coil spring rate from sag target, rider weight and shock travel. |
| Suspension Designer | `suspension-designer/index.html` | Four-bar suspension kinematics solver. Currently the flex-stay variant — leverage ratio, anti-squat/anti-rise, pedal kickback, stay bending stress, derailleur cage take-up, validated against Linkage X3 — with other linkage types planned. |

Each tool is a static page: no build step, open it directly or serve the
folder. `index.html` at the repo root is the landing page that links to all
four. Frame Designer also loads `config.js` (the tools-api origin) and
`frame-shop.js` (save and checkout).

Frame Designer's export screen also writes **notch templates**: a 1:1 A4 PDF
of wrap-around cope templates for the front-triangle joints (TT and DT at the
head tube, TT at the seat tube, DT and ST at the BB shell), cut so the full
wall thickness clears the receiving tube. Seat and chain
stay joints are left out because they are compound angles. The maths and the
PDF writer sit between `// ==NOTCH-START==` and `// ==NOTCH-END==` in
`frame-designer.html`, free of frame-designer globals, so they can be lifted
into a standalone tool. Checks: `node test/notch-tests.mjs`.

`fusion-import/` has two Fusion 360 scripts that load a Suspension Designer
or Frame Designer JSON export as User Parameters in an open Fusion design —
see `fusion-import/README.md`.

Suspension Designer is the most actively developed and the most heavily
documented — see `suspension-designer/README.md` for what it does and
`suspension-designer/CLAUDE.md` for the architecture, every non-obvious fix
and why, and what's deliberately not done. It also has the main test suite
(`suspension-designer/test/`). The public path is
`/suspension-designer/`. `flexstay/` is only a redirect to that path, so
older `/flexstay/` bookmarks (including GitHub Pages) still open the tool.

## Layout

```
index.html               tools landing page
config.js                public tools-api origin for Frame Designer
frame-designer.html      frame geometry tool
frame-shop.js            Frame Designer save / part picker / checkout
spoke-calculator.html    spoke length tool
spring-calculator.html   spring rate tool
suspension-designer/     suspension kinematics tool, its docs and tests
flexstay/                redirect to suspension-designer/ (old URL)
test/                    frame-designer notch template checks (node)
fusion-import/           Fusion 360 scripts: import a design JSON as parameters
server/                  backend: design storage, checkout, email delivery
.nojekyll                required for GitHub Pages — see Deploying below
```

## Backend (`server/`)

Node/Express service behind the "buy this design" flow: stores a submitted
design, hands off to WooCommerce for checkout, and emails the finished files
once a design is reviewed and approved. SQLite for storage (`better-sqlite3`),
`nodemailer` for email, `archiver` for zipping deliverables. Has its own
admin panel (`server/public/admin.html`).

```
cd server
npm install
cp .env.example .env    # fill in WooCommerce, SMTP and admin credentials
npm start                # or: npm run dev
```

Leaving the WooCommerce variables blank in `.env` falls back to a placeholder
checkout flow instead of a real WooCommerce site — see `.env.example` for
every variable and what it's for. This talks to real payment and email
infrastructure, so treat `.env` as a secret and keep it out of version
control (it already is, via `server/.gitignore`).

`POST /api/designs` takes an optional `productIds` array so one design can be
several WooCommerce line items. The same `design_id` and geometry summary are
written to the order and to each line. Allowed ids come from `WC_PRODUCT_IDS`.
If the body omits `productIds`, the order is the single `WC_PRODUCT_ID`, as
before, and that is the only case that applies `WC_PRODUCT_PRICE`. An explicit
`productIds` list uses each product's own WooCommerce price. A paid order (`processing` or `completed`) still marks that one design
paid. In WordPress, add a webhook with topic Order updated and delivery URL
`{BASE_URL}/api/webhooks/woocommerce/order-updated`. Set the webhook secret to
`WC_WEBHOOK_SECRET`. Catalogue products are only referenced by id; this server
does not publish them.

## Frame Designer checkout

Save design and Continue to shop in Frame Designer call the live tools-api.
The origin is `config.js` (`CREATURE_TOOLS_API_BASE`), not a secret and not
written into the page.

Save posts `POST /api/designs` with the customer's name, email, the current
geometry as `params`, and `productIds` for the design files they picked:

| Design file | Product | Price |
|---|---|---|
| BB yoke | 8634 | £58 |
| SS yoke | 8635 | £35 |
| Dropouts | 8636 | £70 |

Bought separately those files list at **£163**. When all three are checked, Frame Designer shows **£150** and **save £13**. A shorter selection stays the list sum of the files that are checked. Product 8637 is not a checkout choice. Printed 316L stays enquire/quote only. The Woo order applies the £13 reduction when those three lines share a `design_id`; that fee is not calculated in this page.

The response `designId` (also accepted as `design_id`) is kept in
`sessionStorage` and in `?design=` on the page. JSON download is the existing
client-side export; the API does not return a file. Continue to shop follows
`checkoutUrl` when it is a `https://creaturecycles.co.uk/.../checkout/order-pay/...`
link. The same pending order is reused until the geometry, email, or parts
change, so a second click does not open a second order. Products stay draft;
this page only creates an order through the tools-api.

Deploy `frame-designer.html`, `frame-shop.js`, and `config.js` together under
`/apps/`. `config.js` is only the public tools-api origin. The tools-api
already accepts `productIds` (Phase 2) and prices those lines from WooCommerce.
This page does not apply the £13 reduction itself.

## Deploying

The frontend tools are static files. On Creature Cycles they are served
under `/apps/`: the landing page, `frame-designer.html`,
`spoke-calculator.html`, `spring-calculator.html`, and
`suspension-designer/`. Each in-app "all tools" link is an absolute URL to
`https://creaturecycles.co.uk/apps/`, so it returns to that hub from either
host. GitHub Pages can keep serving the same tree from the repo root during
the dual-host window — copy the repo (minus `server/`, which doesn't belong
on a static host) into wherever Pages serves from and push. Keep
`.nojekyll` at the repo root: Jekyll ignores directories starting with an
underscore, and it's easier to keep the file than to rediscover the rule
later.

The backend is a normal Node service — deploy it wherever, point
`FRONTEND_URL`/`BASE_URL` at each other, and set `WC_WEBHOOK_SECRET` to
whatever WooCommerce is configured to send.

Test in Safari as well as Chrome for anything solver-related — Flex-Stay had
a bug that only showed up in JavaScriptCore, invisible in V8. See
`suspension-designer/CLAUDE.md` for the story.
