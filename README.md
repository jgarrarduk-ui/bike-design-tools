# bike design tools

Creature Cycles' bike design tools. Four static, single-file browser tools
plus a small backend that turns a finished design into a paid order.

Live: `jgarrarduk-ui.github.io/bike-design-tools/`

## Tools

| | File | What it does |
|---|---|---|
| Frame Designer | `frame-designer.html` | Parametric bicycle frame geometry: angles, lengths, stack/reach, drawn live. |
| Spoke Length Calculator | `spoke-calculator.html` | J-bend spoke lengths for 1×, 2× and 3× lacing, with rim and hub presets. |
| Spring Rate Calculator | `spring-calculator.html` | Rear coil spring rate from sag target, rider weight and shock travel. |
| Suspension Designer | `flexstay/index.html` | Four-bar suspension kinematics solver. Currently the flex-stay variant — leverage ratio, anti-squat/anti-rise, pedal kickback, stay bending stress, derailleur cage take-up, validated against Linkage X3 — with other linkage types planned. |

Each is a single HTML file: no build step, no dependencies, open it directly
or serve the folder. `index.html` at the repo root is the landing page that
links to all four.

Suspension Designer is the most actively developed and the most heavily
documented — see `flexstay/README.md` for what it does and `flexstay/CLAUDE.md`
for the architecture, every non-obvious fix and why, and what's deliberately
not done. It also has the only test suite in the repo (`flexstay/test/`). The
folder is still called `flexstay/` because that's the only variant that
exists yet; see the recommendations on restructuring once a second one is
built.

## Layout

```
index.html               tools landing page
frame-designer.html      frame geometry tool
spoke-calculator.html    spoke length tool
spring-calculator.html   spring rate tool
flexstay/                suspension kinematics tool, its docs and tests
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

## Deploying

The frontend tools are static files — copy the repo (minus `server/`, which
doesn't belong on a static host) into wherever GitHub Pages serves from and
push. Keep `.nojekyll` at the repo root: Jekyll ignores directories starting
with an underscore, and it's easier to keep the file than to rediscover the
rule later.

The backend is a normal Node service — deploy it wherever, point
`FRONTEND_URL`/`BASE_URL` at each other, and set `WC_WEBHOOK_SECRET` to
whatever WooCommerce is configured to send.

Test in Safari as well as Chrome for anything solver-related — Flex-Stay had
a bug that only showed up in JavaScriptCore, invisible in V8. See
`flexstay/CLAUDE.md` for the story.
