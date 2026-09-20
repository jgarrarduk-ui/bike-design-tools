# Suspension Designer — flex-stay

Browser tool for designing a flex-stay full-suspension mountain bike frame —
the first variant of a planned Suspension Designer family (other linkage
types are on the roadmap; this folder is the flex-stay one). Single
self-contained page, no build step, no dependencies.

Live: `jgarrarduk-ui.github.io/bike-design-tools/flexstay/`

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
rederived for the linkage-driven case and say so plainly rather than guess.

The drawing is a full elevation, not a schematic: wheels, fork, cockpit,
cranks (both arms, rotating with kickback as the suspension cycles), saddle,
drivetrain, and a rear derailleur whose chain wraps tangent to the real jockey
wheel artwork rather than cutting through their centres. Every part can be
hidden with its own toolbar toggle. An optional swingarm or frame-mounted
idler can be added above or below the chainring.

Front and rear suspension cycle together. Defaults are James's Galago geometry.

## Layout

```
index.html    the tool
img/          artwork: wheel, fork lowers, cockpit stem, saddle,
              shock in four pieces, rear derailleur jockey wheel
test/         headless regression tests
CLAUDE.md     architecture, traps, and what is not done
```

## Tests

```
cd test && node flexstay-tests.mjs
```

57 checks, nothing to install. The engine has no DOM references so the tests
extract and run it directly.

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
