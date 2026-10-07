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
Resend's HTTPS API for email, `archiver` for zipping deliverables. Has its own
admin panel (`server/public/admin.html`).

```
cd server
npm install
cp .env.example .env    # fill in WooCommerce, the Resend API key, and admin credentials
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
| BB yoke | 8634 | £58 | selectable |
| SS yoke | 8635 | £42 | selectable |
| Dropouts | 8636 | £64 | shown, Coming soon, not sent |

BB yoke and SS yoke start checked. Together they list at **£100**. Dropouts stay visible, grayed, and untickable, and `productIds` never includes 8636. The full-set total is not shown while dropouts are unavailable. Product 8637 is not a checkout choice. Printed 316L stays enquire/quote only.

The response `designId` (also accepted as `design_id`) is kept in
`sessionStorage` and in `?design=` on the page. JSON download is the existing
client-side export; the API does not return a file. Continue to shop follows
`checkoutUrl` when it is a `https://creaturecycles.co.uk/.../checkout/order-pay/...`
link. The same pending order is reused until the geometry, email, or parts
change, so a second click does not open a second order. Products stay draft;
this page only creates an order through the tools-api.

Deploy `frame-designer.html`, `frame-shop.js`, and `config.js` together under
`/apps/`. The host caches `/apps/frame-shop.js` for a year
(`Cache-Control: max-age=31536000`) and does not cache the HTML that long, so
the page loads `frame-shop.js?v=…`. Bump that query when the script changes,
or a phone keeps the old script beside the new page and the part list stays
empty. `config.js` is only the public tools-api origin. The tools-api
already accepts `productIds` (Phase 2) and prices those lines from WooCommerce.
This page only sends the buyable design files.

Save design still posts `POST /api/designs` and leaves Continue to shop on the
modal. On success the page also downloads the same JSON as Download JSON.
The optional frame name on that form is sent as `designName` and used in the
save email. The API emails the customer (server-side only) with two links:

- Edit design → `https://creaturecycles.co.uk/apps/frame-designer.html?design={designId}&resume={resume_token}`
- Take me to checkout → `{BASE_URL}/api/designs/{designId}/checkout?resume={resume_token}`

That checkout URL is a 302 to the stored Woo order-pay link. If the pending
order is missing or cancelled, the API creates a new one and redirects to that.
`GET /api/designs/{designId}?resume={resume_token}` returns
`designId`, `params`, `productIds`, `customerName`, `customerEmail`, and
`checkoutUrl` only when the token matches an unpaid design (`pending` or
`checkout_created`) and the token is under 90 days old
(`RESUME_TOKEN_TTL_DAYS`). Anything else is 404, including a bare `?design=`
with no `resume`, a paid design, or a token past that window. The page still
restores a bare `?design=` from `sessionStorage` and does not ask the API for it.

Coming back and changing geometry, email, or parts posts a new design and a
new pending order. The previous unpaid order is left for cleanup.

`resume_token` is minted on create and is not returned in the POST body.
`product_ids` is the JSON array from `resolveProductIds`, stored so a later
visit can tick the same parts. Dropouts stay Coming soon: 8636 is never one
of those ids. Orders also store `creature_design_id` next to `design_id`.

Cleanup of those scrapped pending orders is two hooks, both defaulting to 90
days. Set `UNPAID_CLEANUP_INTERVAL_HOURS` (for example `24`) and the tools-api
expires the resume token, sets the design to `expired` with `deleted_at` (the
row stays), and cancels the Woo order when it is still pending.
`POST /api/admin/cleanup-unpaid` runs the same job. On the shop, copy
`wordpress-plugins/mu-plugins/creature-unpaid-design-orders.php` into
`wp-content/mu-plugins/` so WP-Cron cancels the same pending orders if the
API timer is off. Layout installs that file. Paid orders are not cancelled.
Copy `wordpress-plugins/mu-plugins/creature-fd-only-purchase.php` into the
same folder so the BB yoke, SS yoke, and dropouts cannot be added from the
catalogue without a `design_id`. Frame Designer checkout is still the
tools-api order and the order-pay link; that path is not a basket add.
Copy `creature-fd-order-experience.php` as well: it replaces the order-pay
guest warning on those orders, hides `design_id` from customers, and prints
the delivery lead time. On those order-pay pages it also requires the
straight-away cancellation checkbox before payment, and the customer
processing email confirms that consent. Filter `creature_fd_lead_time` (default
`5 working days`). Filter `creature_fd_cancellation_waiver_text` to follow
the final T&Cs. The save-design email and the payment email use
`FD_LEAD_TIME` with the same default. If `REVIEW_LEAD_TIME_DAYS` is still
`7` on Railway and `FD_LEAD_TIME` is unset, those emails say “7 working
days”; delete the old variable or set it to `5`.

The save email is Cadence's draft, which James approved: subject "Your Creature Cycles design is
saved", buttons "Edit design" and "Take me to checkout", dropouts still
coming soon, no prices. An empty first name is "Hi,".

Mail goes out through the Resend HTTPS API (`POST https://api.resend.com/emails`).
There is no SMTP client and no Resend SDK — Railway blocks outbound SMTP, so
`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, and `SMTP_SECURE` are not used.
`SMTP_PASS` is the Resend API key and is sent as `Authorization: Bearer`.
`RESEND_API_KEY` is accepted when `SMTP_PASS` is unset; you do not need to
rename the existing secret. `EMAIL_FROM` is
`Creature Cycles <info@creaturecycles.co.uk>` and `EMAIL_REPLY_TO` is
`info@creaturecycles.co.uk`. Until one of those keys is set the send is
skipped and the save still succeeds. The save response does not wait on
Resend. Verifying the domain in Resend is separate from this code. A live
send waits on that secret and DNS.
`FRAME_DESIGNER_URL` overrides the edit link if the page is not on
`/apps/frame-designer.html`. `FRONTEND_URL` must allow the Frame Designer
origin so the hydrate `GET` is not blocked by CORS.

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
