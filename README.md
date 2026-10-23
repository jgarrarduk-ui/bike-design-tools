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
paid.

`GET /api/prices` is public and read-only. It returns the Frame Designer
products only (8634, 8635, 8636) as
`{ currency, products: { "<id>": { price, name, available } }, fetched_at }`.
`price` is the amount an order created with explicit `productIds` charges
for one unit, as a decimal string. That create sends no line total, so
WooCommerce sets the line from `wc_get_price_excluding_tax()` on the product
`price` field (`WC_Product::get_price()`, the active price) and
`calculate_totals()` adds tax at the shop base address, because the order
has no billing country. When catalogue prices include tax, or the product
is not taxable, or taxes are off, that charge is the stored `price`. When
prices exclude tax and the product is taxable, the charge is `price` plus
base-rate tax. The result is cached in memory for five minutes. Each catalogue read
aborts after `WC_PRICE_TIMEOUT_MS` (default 8000, clamped to 1000–15000). A timeout is treated
as any other WooCommerce error: the last good result is served while it
is under an hour old, and otherwise the route returns `503`
`{ "error": "prices_unavailable" }`. After a failed read, WooCommerce is
not called again for `WC_PRICE_BACKOFF_MS` (default 45000, clamped to
30–60 seconds), counted from the failure. During that backoff the same stale-or-503 rule applies.
A successful read clears the backoff. A product whose status is not
`publish`, or whose `stock_status` is `outofstock`, is `available: false`
with no `price`. The route does not invent a price and does not write to
WooCommerce. The success response sends
`Cache-Control: public, max-age=60`. CORS is the same policy as the other
routes. In WordPress, add a webhook with topic Order updated and delivery URL
`{BASE_URL}/api/webhooks/woocommerce/order-updated`. Set the webhook secret to
`WC_WEBHOOK_SECRET`. Catalogue products are only referenced by id; this server
does not publish them.

## Frame Designer checkout

Save design and Continue to shop in Frame Designer call the live tools-api.
The origin is `config.js` (`CREATURE_TOOLS_API_BASE`), not a secret and not
written into the page.

Save posts `POST /api/designs` with the customer's name, email, the current
geometry as `params`, and `productIds` for the design files they picked:

| Design file | Product |
|---|---|
| BB yoke | 8634 |
| SS yoke | 8635 |
| Dropouts | 8636 |

Frame Designer prices are set in WooCommerce and are read live via `GET /api/prices`. The page does not keep its own catalogue prices. Until that response arrives, or if it fails, the page hides each price and the total and says “Price shown at checkout”. BB yoke and SS yoke start checked. Dropouts stay visible, grayed, and untickable, and `productIds` never includes 8636. The full-set total is not shown while dropouts are unavailable. Product 8637 is not a checkout choice. Printed 316L stays enquire/quote only.

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
`POST /api/admin/cleanup-unpaid` runs the same job. A separate retention
purge anonymises the design row itself once it is past the window below.
On the shop, copy
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
straight-away cancellation checkbox before payment, and a separate terms
checkbox for the Terms & Conditions, Design File Licence, and Required
Build Specification. The customer processing email confirms that consent.
The processing and on-hold emails also include a Request a change link
for 24 hours after payment (`creature_fd_change_window_hours`), or until
the order is marked In design.
Filter `creature_fd_lead_time` (default
`5 working days`). Filter `creature_fd_cancellation_waiver_text` to follow
the final T&Cs. The save-design email and the payment email use
`FD_LEAD_TIME` with the same default. If `REVIEW_LEAD_TIME_DAYS` is still
`7` on Railway and `FD_LEAD_TIME` is unset, those emails say “7 working
days”; delete the old variable or set it to `5`.

### Data retention

The tools-api anonymises a design in place. It does not delete the row.
`customer_name` becomes `redacted` and `customer_email` becomes
`redacted+<id>@invalid` (the columns are `NOT NULL`, and rewriting the table
would copy every stored PDF). `design_name`, `pdf_base64`, the review PDF, `resume_token`,
`download_token`, `review_token`, their expiries, and `wc_checkout_url` are
cleared. Geometry `params` and `design_revisions` stay. `anonymised_at` is
stamped so a later run skips the row. Resume, checkout, and download then
answer 404, the same as an unknown token. SQLite `secure_delete` is on. A
live run that anonymised a row checkpoints the WAL
(`wal_checkpoint(TRUNCATE)`) when the database is in WAL mode, which drops
the old frames. It does not `VACUUM`: that would rewrite every stored PDF
and lock the database. The linked Woo order still
holds the customer's billing details. Before the one-year wipe of an order
that is still `pending`, `on-hold`, or `failed`, the purge fetches that
order again and cancels it only when the status is still one of those
three, it has no `date_paid` or `date_paid_gmt`, and the design id on the
order is this row. The row is anonymised only after the read-back is
`cancelled` and still has no paid date. A paid date on that read-back
skips the row and emails info@ once, with a masked address and no links.
A failed cancel skips the row, logs a masked email and no links, and
counts toward the same error breaker. A dry run cancels nothing and logs
`would-cancel`. The order-updated webhook from that cancel ignores only
`cancelled`. A payment status in the same window is processed normally.

A design is kept for `RETENTION_PAID_DAYS` (default `2557`, seven years)
when any paid signal is set: `paid_at`, `accepted_at`, `delivered_at`,
`review_sent_at`, a review PDF or `review_token`, a `download_token` or
`download_expires_at`, a paid-family status (`paid`, `in_review`, `accepted`,
`delivered`, `refunded`), or `date_paid_gmt` / `date_paid` on the linked Woo
order. The clock is the latest of `paid_at`, `review_sent_at`, `accepted_at`,
`delivered_at`, and Woo `date_paid_gmt`. It is not `created_at`. A paid
signal with no timestamp is kept for `RETENTION_PAID_DAYS` from `created_at`,
then anonymised. A design marked `failed` after accept, and
a paid order later cancelled or refunded, stay on that clock. `paid_at` is
stamped when the order-updated webhook marks a design paid, and cancel or
refund does not clear it. 2557 days is seven years from payment. A setting
below 2557 is raised to 2557. Woo `date_paid` or `date_paid_gmt` means the
row is paid and is not wiped by the one-year cutoff.

An unpaid design is anonymised when the resume link has expired and it is
past `RETENTION_UNPAID_DAYS` (default `90`) with a Woo order of exactly
`cancelled` or `trash`, or it never had a `wc_order_id`. A resume
link that is still valid (`resume_expires_at` in the future, or `created_at`
plus the resume TTL when that column is empty) keeps the row even if the
Woo order is `cancelled` or `trash`. Past `RETENTION_UNRESOLVED_DAYS`
(default `365`, never shorter than `RETENTION_UNPAID_DAYS`) a row with no
paid signal is anonymised whatever the Woo status. That includes `pending`,
`on-hold`, and `failed` orders, a confirmed invalid order id, and an orphan.
A value below 365, or below `RETENTION_UNPAID_DAYS`, is raised, with a
warning. A Woo 404 is not "missing" unless the code is
`woocommerce_rest_shop_order_invalid_id` and `GET /system_status`
succeeded. Before the unresolved cutoff that row is kept.

Each run anonymises at most `RETENTION_PURGE_MAX` eligible rows (default
20) and leaves the rest for the next run. The cap does not abort the run.
A large set of `cancelled` orders is the normal result of the unpaid
cleanup, so that backlog drains over later runs. The run aborts with no
writes when `GET /system_status` fails, when Woo returns 401 or 403, when
more than three lookups fail in a row, or when more than 20% of the lookups
fail (and at least two fail). The same rule applies to invalid order ids
(`404` with `woocommerce_rest_shop_order_invalid_id`): more than
`RETENTION_INVALID_ID_STREAK` in a row (default 3) or more than
`RETENTION_INVALID_ID_FRACTION` of the Woo lookups in that run (default
0.2, and at least two) aborts with no writes. That is the guard for a
wrong `WC_URL`. Cancel failures use the same streak and fraction against
the orders this run tried to cancel. Any other single-order failure,
including a timeout or a 404 that is not an invalid id, skips that row
only.

The anonymise statement repeats the decision: same status, same
`wc_order_id`, resume link still expired, and the paid clock uses the
latest of `paid_at`, `review_sent_at`, `accepted_at`, and `delivered_at`.
`paid_at` filled in by that backfill is the latest of those local signals,
compared as SQLite datetime text. The timer and
`POST /api/admin/retention-purge` share a database lock with an owner id.
The owner refreshes it while the run works. A lock older than 30 minutes
can be taken over, and a run clears only its own lock.

An order-updated webhook for an anonymised design does not change the row
and does not email the redacted address. It logs the design id and Woo
order id. A payment status (`processing`, `completed`, or `paid`) emails
info@creaturecycles.co.uk once per design, including a late payment for an
on-hold order wiped at the one-year cutoff. A `cancelled` event also alerts
once, except when that order id is the one this purge cancelled
(`purge_cancelled_order_id`). Later webhooks for that design are logged only.

Values of `RETENTION_UNPAID_DAYS` below 30 are raised to 30, and values of
`RETENTION_PAID_DAYS` below 2557 are raised to 2557, each with a warning.
A value that is not a number logs an error and uses 90 or 2557. Unset stays
90 and 2557.

The job starts about 30 seconds after boot and then every
`RETENTION_PURGE_INTERVAL_HOURS` (default `24`, clamped to 1–168; `0` turns
the timer off). The purge writes only when `RETENTION_PURGE_DRY_RUN` is
exactly `0`. Unset, empty, and any other value are a dry run. A dry run
logs a breakdown and the design ids and writes nothing, including the
`paid_at` backfill and any Woo cancel: would-anonymise unpaid,
would-anonymise paid, would-cancel, deferred, unresolved past 365d,
skipped as paid-signals, skipped as Woo unknown, skipped as Woo error,
and skipped as still payable. Logs do
not include names, email addresses, or resume, checkout, accept, or
download links. `POST /api/admin/retention-purge` runs the same job.

On the first Railway deploy, leave `RETENTION_PURGE_DRY_RUN` unset or `1`,
deploy, and read the `[retention] dry-run` lines. Set it to exactly `0`
only after those counts look right. A failed API check or a Woo auth
failure aborts that run. One bad order does not. A backlog larger than
`RETENTION_PURGE_MAX` drains over later runs.

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
`/apps/frame-designer.html`. `FRONTEND_URL` must be the Frame Designer origin exactly, with no path,
so the hydrate `GET` and `GET /api/prices` are not blocked by CORS. A longer host such as
`https://creaturecycles.co.uk.evil.com` does not match, and scheme and host
are compared case-insensitively. A request whose Origin is this API — `BASE_URL`,
or the Host header with the connection protocol — is served with no
`Access-Control-Allow-Origin` header. That is the `/admin` Send Review Email
post. A refused `GET` or `HEAD` gets no `Access-Control-Allow-Origin` header
and the response itself is unchanged for non-browser clients. Any other
method from a refused origin, including a preflight, is 403 with no
`Access-Control-Allow-Origin` header. Every response varies on `Origin`.
`http://localhost:3000`, `http://localhost:5173`, and `http://127.0.0.1:5173`
are allowed only when `NODE_ENV` is `development` or `dev`.

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
