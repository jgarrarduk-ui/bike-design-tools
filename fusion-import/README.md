# Fusion 360 import

Loads a Suspension Designer (`flexstay/`) JSON export straight into Autodesk
Fusion 360 as User Parameters, so a Fusion sketch or feature can reference
the linkage geometry by name instead of a hand-typed number that drifts out
of sync with the browser tool.

`import_suspension_geom.py` is a Fusion 360 **Script**, not a standalone
program — it only runs inside Fusion, against the API Fusion itself exposes
(`adsk.core`/`adsk.fusion`), so it can't be run from a terminal or tested
outside Fusion.

## Get a design file

In the Suspension Designer (`flexstay/index.html`), the "Design file" panel
has an Export button — it downloads a `.json` with the current design's
pivot points and every config field (`_meta`/`geom`/`cfg`; see
`flexstay/CLAUDE.md` under "design name / export / import" for the exact
shape).

## Install the script

1. In Fusion 360: **Utilities** tab → **Add-Ins** → **Scripts and Add-Ins**.
2. **Scripts** tab → green **+** → **Script from device** → select
   `import_suspension_geom.py`.
3. Select it in the list and click **Run** (or **Run on Startup** if you'll
   use it repeatedly).

## Use it

1. Have a Fusion design open (new or existing — parameters are added to the
   active design).
2. Run the script. A file picker asks for the exported `.json`.
3. Open **Modify → Change Parameters** to see the result.

Every value becomes a parameter named `susp_geom_<POINT>_<x|y>` (mm) or
`susp_cfg_<field>` (mm, deg, kg or unitless — see `FIELD_UNITS` comments at
the top of the script for which is which). All are prefixed `susp_` so they
group together in the parameter list and can't collide with anything else in
the design. Unit assignment is explicit, not guessed: `ANGLE_FIELDS`,
`MASS_FIELDS` and `UNITLESS_FIELDS` at the top of the script list every `cfg`
field that isn't a plain mm length.

Two are worth knowing about by name: **`susp_cfg_wheelODf`/`susp_cfg_wheelODr`**
are the finished wheel diameter (rim bead-seat diameter plus tyre height on
both sides — ~743.9mm for a 29in wheel with a 2.4in tyre), not the raw ISO
rim size (`susp_cfg_fw`/`susp_cfg_rw`, ~622mm for the same wheel). Use the
`wheelOD*` pair for anything that should track the actual rolling diameter —
wheel/tyre models, clearance checks, axle-height references.

**Re-running the script updates the same parameters in place** — by name,
not by deleting and recreating them — so anything in the Fusion model
already driven by one of these parameters (a sketch dimension set to
`susp_cfg_reach`, say) keeps working after you tweak the design in the
browser tool, re-export, and re-run the script.

## What it doesn't do

- It only writes User Parameters — it doesn't build any geometry (sketches,
  pivots, tubes) itself. That's a modelling step you do in Fusion, driven by
  these parameters.
- It's one-way (JSON → Fusion). There's no export back from Fusion to a
  Suspension Designer file.
- `_meta` (design name/designer/date/version) isn't numeric, so it can't
  become a parameter — it's stored instead as a `SuspensionDesigner`
  attribute group on the design (`design.attributes`), and folded into every
  parameter's comment field for a quick provenance check.
