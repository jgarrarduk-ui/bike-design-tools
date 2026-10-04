# Fusion 360 import

Loads a design export from this repo's browser tools straight into Autodesk
Fusion 360 as User Parameters, so a Fusion sketch or feature can reference
the design by name instead of a hand-typed number that drifts out of sync
with the browser tool. Two scripts, one per tool:

| Script | Source tool | Prefix |
|---|---|---|
| `import_suspension_geom.py` | Suspension Designer (`suspension-designer/index.html`) | `susp_` |
| `import_frame_params.py` | Frame Designer (`frame-designer.html`) | `frame_` |

Both are Fusion 360 **Scripts**, not standalone programs — they only run
inside Fusion, against the API Fusion itself exposes (`adsk.core`/
`adsk.fusion`), so neither can be run from a terminal or tested outside
Fusion.

## Get a design file

- **Suspension Designer**: the "Design file" panel has an Export button —
  downloads a `.json` with the current design's pivot points and every
  config field (`_meta`/`geom`/`cfg`; see `suspension-designer/CLAUDE.md`
  under "design name / export / import" for the exact shape).
- **Frame Designer**: its own export button downloads a flat `.json` of
  every parameter (`reach`, `ht_angle`, `dt_OD`, ... — no nesting).

## Install a script

1. In Fusion 360: **Utilities** tab → **Add-Ins** → **Scripts and Add-Ins**.
2. **Scripts** tab → green **+** → **Script from device** → select the
   script file.
3. Select it in the list and click **Run** (or **Run on Startup** if you'll
   use it repeatedly).

## Use it

1. Have a Fusion design open (new or existing — parameters are added to the
   active design).
2. Run the script. A file picker asks for the exported `.json`.
3. Open **Modify → Change Parameters** to see the result.

**Re-running a script updates the same parameters in place** — by name, not
by deleting and recreating them — so anything in the Fusion model already
driven by one of these parameters (a sketch dimension set to
`susp_cfg_reach`, say) keeps working after you tweak the design in the
browser tool, re-export, and re-run the script.

### Suspension Designer parameters

Every value becomes a parameter named `susp_geom_<POINT>_<x|y>` (mm) or
`susp_cfg_<field>` (mm, deg, kg or unitless — see `ANGLE_FIELDS`,
`MASS_FIELDS` and `UNITLESS_FIELDS` at the top of the script for which is
which). All are prefixed `susp_` so they group together and can't collide
with anything else in the design.

### Frame Designer parameters

Every numeric value becomes a parameter named `frame_<field>` (mm, deg or
unitless — see `ANGLE_FIELDS`/`UNITLESS_FIELDS` at the top of the script).
Text fields (`frame_material`, the `"29\""`-style wheel size labels) aren't
numeric and can't become parameters — they're stored instead as a
`FrameDesigner` attribute group on the design (`design.attributes`).

### One vocabulary across both tools

The two source tools use different field names for the same quantities
(`ht_angle` vs `ha`, `bb_drop` vs `drop`, `front_wheel_dia` vs `fw`, ...).
`import_frame_params.py`'s `RENAME_MAP` translates Frame Designer's names to
the Suspension Designer's wherever both tools have a field for the same
physical thing, so e.g. head tube angle is `frame_ha` from one tool and
`susp_cfg_ha` from the other — same suffix, so both read as the same
quantity if you ever import both into one Fusion document. `reach` and
`stack` already share a name in both tools' own exports and need no
translation. A field with no equivalent in the other tool (Frame Designer's
`bb_bore`, `crank_q_factor`, the per-tube wall thicknesses it tracks that
the other tool doesn't, ...) keeps its own name, unrenamed.

**`wheelODf`/`wheelODr`** — finished wheel diameter, rim bead-seat diameter
plus tyre height on both sides (~744mm for a 29in wheel with a 2.4in tyre on
the Suspension Designer's own defaults; ~736mm on the Frame Designer's) —
is computed by both importers under this same name, even though only the
Suspension Designer's JSON carries it as an actual exported field
(`susp_cfg_wheelODf`); Frame Designer's own export has no such field at all,
so `import_frame_params.py` computes `frame_wheelODf`/`frame_wheelODr`
itself from `front_wheel_dia`/`rear_wheel_dia` and the single `tyre_width`
that tool uses for both wheels. Neither tool's raw rim size field
(`susp_cfg_fw`/`susp_cfg_rw`, `frame_fw`/`frame_rw`) is the number anyone
actually means by "29in wheel" — use the `wheelOD*` pair for anything that
should track the actual rolling diameter: wheel/tyre models, clearance
checks, axle-height references.

## What it doesn't do

- Either script only writes User Parameters — it doesn't build any geometry
  (sketches, pivots, tubes) itself. That's a modelling step you do in
  Fusion, driven by these parameters.
- Both are one-way (JSON → Fusion). There's no export back from Fusion to
  either browser tool's file format.
- Non-numeric fields (Suspension Designer's `_meta`; Frame Designer's
  `frame_material` and wheel-size labels) are stored as design attributes
  rather than parameters, and folded into every parameter's comment field
  for a quick provenance check.
