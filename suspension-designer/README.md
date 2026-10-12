# Suspension Designer — flex-stay

Browser tool for designing a flex-stay full-suspension mountain bike frame —
the first variant of a planned Suspension Designer family (the flex-stay one;
on the `dev` branch the same tool also models the other linkage families). Single
self-contained page, no build step, no dependencies.

Live: `https://creaturecycles.co.uk/apps/suspension-designer/`
(GitHub Pages, while both hosts are up:
`jgarrarduk-ui.github.io/bike-design-tools/suspension-designer/`).
`flexstay/` in this repo only redirects here.

## What it does

Solves the four-bar kinematics of a single pivot with a shock link, where the
seat stay carries the compliance instead of a pivot. Drag any pivot or type
coordinates and everything recomputes: leverage ratio, anti-squat, anti-rise,
chain shortening, pedal kickback, axle path, spring rate, static pivot loads,
derailleur cage take-up, and the bending stress in the seat stay including the
axial load acting through the offset of the bend. The shock can be mounted
either straight to the seat stay (stay-driven, the default) or to the small
link instead (rocker/bell-crank-driven) — the leverage curve and drawing are
correct in either mode; the pivot-load and stay-stress panels are not yet
rederived for the linkage-driven case and say so plainly rather than guess —
though the angle the stay has to flex through is reported either way, since
that is a kinematic demand on the tube rather than a force result.

Configurations are picked from the `config` button on the drawing. On the `dev`
branch there are 24, in six families: the two flex-stay designs (**Downtube
mounted**, where the shock and link pivot are both bracketed off the down tube,
and **Rocker**, where the link pivots off the seat tube and drives the shock),
plus single-pivot, linkage-driven single-pivot, Horst, short-link and moto-style
layouts. The non-flex ones are presets in `dev-presets/` and are still provisional:
their brake bodies await James's decision and none has been checked against a
Linkage X3 reference yet. See `LINKAGE-PLAN.md` for the status and the open
items. A configuration is more than a set of coordinates. It also decides which
controls make sense, so each preset is a delta over the shipped defaults that
carries its own topology, labels and mount choices.

The drawing is a full elevation, not a schematic: wheels, fork, cockpit,
cranks (both arms, rotating with kickback as the suspension cycles), saddle,
drivetrain, and a rear derailleur whose chain wraps tangent to the real jockey
wheel artwork rather than cutting through their centres. Every part can be
hidden with its own toolbar toggle. An optional swingarm or frame-mounted
idler can be added above or below the chainring.

Front and rear suspension cycle together. Defaults are James's Galago geometry.

## Layout

```
index.html      the tool
img/            artwork: wheel, fork lowers, cockpit stem, saddle,
                shock in four pieces, rear derailleur jockey wheel
dev-presets/    one JSON per non-flex configuration (dev branch)
test/           headless regression tests and the preset checker
CLAUDE.md       architecture, traps, and what is not done
LINKAGE-PLAN.md linkage family plan, status and open items (dev branch)
```

## Tests

```
cd test && node flexstay-tests.mjs                          # flex-stay regression, 130 checks
cd test && node linkage-tests.mjs                           # generic engine, 36 checks
cd test && node preset-check.mjs ../dev-presets/<key>.json  # one dev preset
```

Nothing to install. The engine has no DOM references so the tests extract and
run it directly. `preset-check` is a plausibility gate for presets, not a copy of
the app's model; read its note in `CLAUDE.md` before relying on a pass.

## Validated against Linkage X3

| | Linkage | Tool |
|---|---|---|
| Travel | 139 mm | 139.0 |
| Progression | 11.3% | 11.6% |
| Anti-squat | 113.5% | 113.4% |
| Anti-rise | 109.5% | 109.5% |

Those four are regression tests. If a change moves them, the change is wrong.
See `CLAUDE.md` for why Progression sits 0.3 points off Linkage rather than
0.1 — a real accuracy fix on this tool's own side, not a re-pin.

## Deploying

Copy the folder into the Pages repo and push. If the repo has no `.nojekyll` at
its root, add one — Jekyll ignores directories beginning with an underscore, and
it is easier to add the file than to remember the rule later.

Test in Safari as well as Chrome. One solver bug in this tool appeared in
JavaScriptCore only and was invisible in V8.
