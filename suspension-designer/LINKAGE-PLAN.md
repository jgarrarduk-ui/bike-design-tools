# Linkage family — dev plan and engine spec

Working branch: `dev`. Nothing here ships to `main` until each family has been
checked against a Linkage X3 reference model supplied by James.

## Status

As of 2026-10-14, all committed on `dev`.

**Done**

- Engine generalised to four bodies (F/A/C/R) through `bodiesOf`; flex-stay output
  unchanged (`flexstay-tests` and `linkage-tests` both pass).
- 17 non-flex presets in `dev-presets/`, written into `PRESETS` by
  `test/sync-presets.mjs`; 19 menu entries in six families. Every one passes
  `test/preset-check.mjs`.
- **One Single pivot**, not four (James, 2026-10-14). Swingarm drawn as a closed
  triangle (axle to shock eye as well as main pivot to each).
- **No high-pivot configurations.** A high pivot is just where MP sits; the idler
  is a drivetrain option on every family, with the flex-stay rules (frame or
  swingarm mount, lock to the main pivot), and is kept on a config switch.
- **Down-tube mounts 40 mm off the centreline** on the shock side, never bored
  through, so they can be moved by hand afterwards. Enforced by `preset-check`,
  along with: the shock never crosses the down tube. Seat- and top-tube mounts may
  still be cross-tube bosses (centred within 6 mm).
- Keep-frame switching, `axleRel`, mounts `dt`/`st`/`tt`/`free`, general pivot
  loads and bearing rotation, force arrows capped at 250 mm on non-flex.

**Open**

1. **Linkage X3 references** for one bike per family, from James. Until then the
   presets are plausible starting points found by search, not designs.
2. **Brake bodies** are provisional (table below).
3. Main pivots on several presets overlap a tube by the app's readout (allowed, as
   on flex-stay, since the MP housing ties ST to DT).
4. A frame idler on flex-stay has no bracket (kept so flex drawing is unchanged);
   a swingarm idler has none on any family.
5. Seat-stay stress still gated on the flex rocker config. Six-bar not modelled.

**Provisional brake bodies (for James to confirm)**

| Preset | Family | brakeBody |
|---|---|---|
| sp | single | A |
| ldsp-ss, ldsp-tt | ldsp | A |
| split, split-float | ldsp | C |
| horst-rocker, horst-stay, horst-float | horst | C |
| vpp-upper, vpp-lower, dw-upper, dw-lower, sl-float | shortlink | C |
| moto-unitrak, moto-prolink, moto-floater, moto-unit | moto | A |

The flex configs keep `A` and are not provisional. The preset table further down
is the original plan and still lists the variants that were later folded away.

## The idea

Every configuration in scope (single pivot, linkage-driven single pivot, split
pivot, Horst, short-link VPP/DW, moto-style, and the existing flex-stay) is the
same one-degree-of-freedom planar four-bar, or a degenerate one with no link.
What differs is **which body carries what**:

| body tag | what it is | how a point on it moves at swingarm angle `phi` |
|---|---|---|
| `F` | frame (ground) | identity |
| `A` | swingarm / chainstay / lower link, pinned to frame at `MP` | `rot(p, g.MP, phi)` |
| `C` | coupler, pinned to `A` at `FP` and to `R` at `LP` | `place(g.FP, g.LP, FP, LP, p)` |
| `R` | rocker / upper link / dogbone, pinned to frame at `SP` | `place(g.SP, g.LP, g.SP, LP, p)` |

Internal point names stay exactly as they are (`MP SP FP LP SE SG AX ID`) so
saved files and the Fusion importer's `susp_geom_*` parameters keep working.
Only the on-screen labels change per configuration.

## New `cfg` fields (all optional; absent = today's flex-stay behaviour)

| field | values | default when absent |
|---|---|---|
| `axleBody` | `'A'` or `'C'` | `'A'` |
| `brakeBody` | `'A'` or `'C'` | `'A'` |
| `seBody` | `'F' 'A' 'C' 'R'` — body carrying the shock eye `SE` | `cfg.shockMount ? 'R' : 'C'` |
| `sgBody` | `'F' 'A' 'C' 'R'` — body carrying the other shock end `SG` | `'F'` |
| `linkless` | `0/1` — pure single pivot, no coupler/rocker; `SP`/`LP`/`FP` unused | `0` |
| `flexStay` | `0/1` — FP is locked to AX, stay stress panel applies | `1` |
| `family` | string, for grouping the config menu only | `'flex'` |

Engine helper (inside the ENGINE block): `bodiesOf(cfg)` returns
`{axle, brake, se, sg, linkless, flex}` with the defaults above applied. `cfg`
may be `undefined`, `null`, a boolean or a number (legacy `linkageMode`
argument): a truthy non-object means `{se:'R'}`, falsy non-object means
defaults.

## Engine changes

1. **`solve(g, phi, prevLP, bod)`** — `bod` is a `bodiesOf()` result, or the
   legacy boolean (convert via `bodiesOf`). Returns everything it does today
   plus `SG` (moving if `sgBody` is not `F`), `IC` (instant centre of the axle
   body relative to frame, or `null` if at infinity), `ICdir` (unit direction
   of the parallel links when `IC` is null), `ICb`/`ICbdir` (same for the brake
   body). `AX` is placed on the axle body. `shock = dist(SE, SG)`.
   `rise = AX.y - g.AX.y` as today. `stayAngle` computed when LP exists, else 0.
   - `IC` of body `A` is `g.MP`. `IC` of body `C` is the crossing of the infinite
     lines `MP–FP(solved)` and `SP–LP(solved)` (use `lineHit`).
   - `linkless`: no circle intersection; `LP=null`, coupler/rocker points
     undefined. `SE`/`SG` must then be on `F` or `A`.
   - At `phi=0` every body map is the identity, so all points equal their rest
     values. Keep that property — the flex-stay tests rely on it.
2. **`atCompression(g, comp, guess, phiHint, bod)`**
   - Choose the sign of `phi` that shortens the shock: evaluate shock length at
     `±0.01` rad and drive in the direction where it decreases. Cache the sign
     per sweep (pass it in via `bod.sign` or compute once in `sweep`).
   - Bracket range widened from 0.85 to 1.6 rad (N=200 coarse steps), so short
     lower links that rotate a lot still reach full stroke. **Must not change
     the flex-stay numbers** — they were found inside 0.85 and still will be.
3. **`sweep(g, cfg, n)`**
   - Pass `bodiesOf(cfg)` down instead of `cfg.shockMount`.
   - Anti-squat: chain force line against the line through `AX` and `IC` (or
     through `AX` along `ICdir` when `IC` is null).
   - Anti-rise: line from contact patch through the **brake** body's IC (or
     along `ICbdir`). Today this is hardcoded `g.MP`; for `brakeBody 'A'` that
     is unchanged.
   - `guide` (derailleur hanger) rides the axle body (use the same placement
     helper as `AX`), not always `rot(..., g.MP, phi)`.
   - `idlerAt` swingarm mount stays on body `A`.
   - Each frame also records `rot` angles of A, C, R relative to their top-out
     angle in degrees: `rotA`, `rotC`, `rotR` (bearing rotation readout).
4. **`pivotForces(g, f, Fw, cfg)`** — general static solve. Moving bodies
   `A`, `C`, `R` (just `A` when linkless). Unknowns: shock force scalar (along
   `SG→SE`, equal and opposite on the two shock bodies; a shock end on `F`
   contributes nothing to the moving-body equations), and 2D reactions at
   `MP` (frame→A), `FP` (A→C), `LP` (R→C), `SP` (frame→R). 9×9 (3×3 linkless).
   Ground reaction: vertical `Fw` at contact patch `f.CP`, applied to the axle
   body (same sign convention as today). Return the same keys as today
   (`shock, link, flexPivot, mainPivot, fp, vec`) plus `secondPivot` (|SP|) and
   `linkPivot` (|LP|). `link` stays = |LP| reaction (the rocker is a two-force
   member when nothing else attaches to it, so this equals today's link force).
   **For the shipped flex-stay (`seBody 'C'`, axle on A, sg on F) results must
   match the old function to 1e-6.**
5. Keep **no DOM references** in the ENGINE block, and export any new helpers
   the tests need by adding them to the `return {...}` list in
   `test/flexstay-tests.mjs` only if that test needs them — the new test file
   builds its own export list.

## App changes (Phase 1+)

- `recompute()`: `G.FP={...G.AX}` only when `bodiesOf(C).flex`.
- FP becomes a draggable, editable point when not flex-stay (it is in `PTS`
  already; check `fillPoints`/`initDrag` for any FP exclusion).
- Point labels per configuration: each preset may carry `labels:{MP:'…',…}`,
  falling back to today's `PTS` labels. Hide `SP`/`LP`/`FP` rows when linkless.
- `drawRearAssembly()` keeps today's code path for flex-stay. For every other
  configuration draw each moving body generically as tubes between its own
  points (A: MP→FP and MP→AX or AX→FP; C: FP→LP plus FP→AX or LP→AX when it
  carries the axle, plus a brace to SE if `seBody 'C'`; R: SP→LP plus SP→SE if
  `seBody 'R'`), and the shock between the solved SE and SG.
- Stay section tab and stay stress panel: shown only for flex-stay. Pivot
  loads panel: uses the general `pivotForces` for every config (closes the old
  "not modeled" gap).
- `mountAxis()` gains a top tube option and a free option (no lock) — preset
  field `mounts:{SG:'dt'|'st'|'tt'|'free', SP:...}`.
- **Switching configuration keeps the frame.** `applyPreset` keeps every
  `GEOM_KEYS` field plus rider (`mass bias cogh sag fsag`), drivetrain
  (`ring cog cage links chainAuto idler*`), wheels and shock (`eye stroke`)
  — it replaces only the kinematic points and the topology fields. Points the
  preset lists in `axleRel:[...]` are shifted by `G.AX - DEF.geom.AX` so a
  Horst pivot stays the same distance ahead of the axle whatever rear centre
  you have. A full reset still loads the preset whole.
- Config menu grouped by `family`.

## Presets to build

Brake body values are provisional — James decides these.

| key | family | name | axle | brake | seBody | sgBody | linkless |
|---|---|---|---|---|---|---|---|
| sp-direct | single | Single pivot — shock to down tube | A | A | A | F | 1 |
| sp-tt | single | Single pivot — shock under top tube | A | A | A | F | 1 |
| sp-yoke | single | Single pivot — swingarm extension drives shock | A | A | A | F | 1 |
| sp-high | single | Single pivot — high pivot + idler | A | A | A | F | 1 |
| ldsp-ss | ldsp | Linkage-driven SP — seat-stay pivot, rocker on seat tube | A | A | R | F | 0 |
| ldsp-tt | ldsp | Linkage-driven SP — link under top tube | A | A | R | F | 0 |
| ldsp-high | ldsp | Linkage-driven SP — high pivot + idler | A | A | R | F | 0 |
| split | ldsp | Split pivot / ABP | A | C | R | F | 0 |
| split-float | ldsp | Split pivot — floating shock | A | C | R | A | 0 |
| horst-rocker | horst | Horst link — rocker drives shock | C | C | R | F | 0 |
| horst-stay | horst | Horst link — seat stay drives shock | C | C | C | F | 0 |
| horst-float | horst | Horst link — floating shock | C | C | R | A | 0 |
| horst-high | horst | Horst link — high pivot + idler | C | C | R | F | 0 |
| vpp-upper | shortlink | Short link, counter-rotating — upper link drives | C | C | R | F | 0 |
| vpp-lower | shortlink | Short link, counter-rotating — lower link drives | C | C | A | F | 0 |
| dw-upper | shortlink | Short link, co-rotating — upper link drives | C | C | R | F | 0 |
| dw-lower | shortlink | Short link, co-rotating — lower link drives | C | C | A | F | 0 |
| sl-float | shortlink | Short link — shock between the links | C | C | R | A | 0 |
| moto-unitrak | moto | Moto — rocker on frame, pull rod (Uni-Trak) | A | A | R | F | 0 |
| moto-prolink | moto | Moto — rocker on swingarm, dogbone (Pro-Link) | A | A | C | F | 0 |
| moto-floater | moto | Moto — Full-Floater | A | A | R | A | 0 |
| moto-unit | moto | Moto — Unit Pro-Link | A | A | C | A | 0 |

For the short-link presets body `A` is the lower link (pinned at `MP`) and `R`
the upper link (pinned at `SP`). For Horst, `A` is the chainstay and `FP` the
Horst pivot ahead of the axle. For moto Pro-Link, `C` is the rocker (pinned to
the swingarm at `FP`) and `R` the dogbone to the frame at `SP`.
