"""Import a Frame Designer JSON export as Fusion 360 User Parameters.

What this is
-------------
The Frame Designer (`frame-designer.html`) exports its current parameter set
as a single flat JSON file — no nesting, unlike the Suspension Designer's
`{_meta, geom, cfg}` shape:

    { "reach": 450, "fork_atc": 455, "bb_drop": 70, "ht_angle": 68, ... }

Every key here is one of `frame-designer.html`'s own internal field names
(`DEFAULTS`/`params` in that file) — there is no separate export-time
renaming in that tool, so the JSON keys are exactly its source code's own
field names (snake_case, unlike the Suspension Designer's camelCase).

This script reads that file and creates (or updates) one Fusion 360 User
Parameter per numeric value, the same way `import_suspension_geom.py` does
for a Suspension Designer export. Run it again after re-exporting an
updated design: existing parameters are updated in place by name, not
deleted and recreated, so anything in the Fusion model already driven by
one of these parameters keeps working.

How to run it
--------------
Fusion 360 -> Utilities tab -> Add-Ins -> "Scripts and Add-Ins" -> Scripts
tab -> green "+" -> "Script from device" -> select this file -> Run.
A file picker then asks for the exported .json. Fusion needs an active
document (Modify > Change Parameters shows the result afterward).

Naming
------
Every parameter is prefixed `frame_` so it groups together and can't
collide with anything already in the design, including the Suspension
Designer's own `susp_*` parameters if both scripts are run against the same
Fusion document:
    frame_reach, frame_stack, frame_ha, frame_dtOD, ...

Where a field means the same physical thing as a field the Suspension
Designer already exports, it gets the SAME name after the prefix, so the
two tools' outputs read as one vocabulary rather than two — `reach` and
`stack` already share a name in both tools' own JSON and need no change;
everything else that overlaps goes through RENAME_MAP below (`ht_angle` ->
`ha`, `bb_drop` -> `drop`, `front_wheel_dia` -> `fw`, and so on — see the
map for the full list and the reasoning tying each pair together). A field
with no Suspension Designer equivalent (`bb_bore`, `crank_q_factor`, tube
wall thicknesses this tool tracks that the other doesn't, ...) keeps its
own Frame Designer name, unrenamed.

Two fields are added that don't exist in the source JSON at all:
`wheelODf`/`wheelODr`, the finished wheel diameter (rim bead-seat diameter
plus tyre height on both sides — see suspension-designer/CLAUDE.md, "wheelODf/
wheelODr"), computed from `front_wheel_dia`/`rear_wheel_dia` and the single
shared `tyre_width` this tool uses for both wheels. Named to match the
Suspension Designer's own `wheelODf`/`wheelODr` exactly, for the same reason
everything in RENAME_MAP is renamed: one name per physical quantity, however
many tools compute it.

Units
-----
Angles (`ht_angle`, `st_angle`, `cs_ds_drop_angle`, `stem_angle`) are
degrees. `max_chainring` (a tooth count) and `cable_guides` (a true/false
toggle, imported as 0/1 so a Fusion feature can suppress on it) are
dimensionless. Everything else numeric is a length in mm. `front_wheel_key`/
`rear_wheel_key` (wheel size labels like `29"`) and `frame_material` are
text, not numbers, so they can't become parameters — they're stored instead
as a `FrameDesigner` attribute group on the design, the same way the
Suspension Designer importer stores `_meta`.
"""

import json
import re
import traceback

import adsk.core
import adsk.fusion

PREFIX = 'frame_'

# Frame Designer field -> Suspension Designer field, wherever both tools
# have one for the same physical quantity. `reach`/`stack` already share a
# name in both and so aren't listed; everything else keeps its own Frame
# Designer name (just sanitized) and isn't in this map.
RENAME_MAP = {
    'fork_atc': 'a2c',                   # axle-to-crown
    'bb_drop': 'drop',                   # bottom bracket drop
    'ht_angle': 'ha',                    # head tube angle
    'ht_length': 'htl',                  # head tube length
    'ht_OD': 'htOD',                     # head tube OD
    'dt_weld_clearance': 'dtWeld',
    'tt_weld_clearance': 'ttWeld',
    'fork_offset': 'offset',             # fork rake
    'lower_headset_height': 'hsLower',
    'st_angle': 'sa',                    # seat tube angle
    'st_length': 'st',                   # seat tube length
    'st_OD': 'stOD',
    'tt_OD': 'ttOD',
    'dt_OD': 'dtOD',
    'chainstay_length': 'csl',
    'ss_OD': 'od',                       # seat stay tube OD
    'ss_WT': 'wall',                     # seat stay wall thickness
    'front_wheel_dia': 'fw',             # rim bead-seat diameter, front
    'rear_wheel_dia': 'rw',              # rim bead-seat diameter, rear
    'stem_length': 'stemL',
    'steerer_height': 'stemH',           # stem/steerer rise above the head tube
    'rear_hub_spacing': 'dropW',         # rear dropout/hub spacing
}

ANGLE_FIELDS = {'ht_angle', 'st_angle', 'cs_ds_drop_angle', 'stem_angle'}

# Tooth counts and true/false toggles: no physical unit.
UNITLESS_FIELDS = {'max_chainring', 'cable_guides'}

# Not numbers at all -- stored as design attributes instead of parameters.
TEXT_FIELDS = {'front_wheel_key', 'rear_wheel_key', 'frame_material'}


def unit_for_field(key):
    if key in ANGLE_FIELDS:
        return 'deg'
    if key in UNITLESS_FIELDS:
        return ''
    return 'mm'


def sanitize(name):
    """Fusion parameter names must start with a letter and contain only
    letters, digits and underscores."""
    name = re.sub(r'[^A-Za-z0-9_]', '_', name)
    if not name or not name[0].isalpha():
        name = 'p_' + name
    return name


def upsert_param(user_params, name, value, unit, comment):
    name = sanitize(name)
    expr = f'{value} {unit}'.strip() if unit else str(value)
    existing = user_params.itemByName(name)
    if existing:
        existing.expression = expr
        existing.comment = comment
        return existing
    value_input = adsk.core.ValueInput.createByString(expr)
    return user_params.add(name, value_input, unit, comment)


def import_design(design, data, source_name):
    user_params = design.userParameters
    comment = f'Frame Designer: {source_name}'

    count = 0
    for key, value in data.items():
        if key in TEXT_FIELDS:
            continue
        if isinstance(value, bool):
            value = 1 if value else 0
        elif not isinstance(value, (int, float)):
            continue
        name = RENAME_MAP.get(key, key)
        upsert_param(user_params, f'{PREFIX}{name}', value, unit_for_field(key), comment)
        count += 1

    # Finished wheel diameter -- rim bead-seat diameter plus tyre height on
    # both sides, same formula and the same name as the Suspension
    # Designer's own wheelODf/wheelODr (see suspension-designer/CLAUDE.md). This tool
    # uses one tyre_width for both wheels, unlike the Suspension Designer's
    # separate tyreR/tyreF, so both derived values share that one input.
    fw, rw, tyre = data.get('front_wheel_dia'), data.get('rear_wheel_dia'), data.get('tyre_width')
    if isinstance(fw, (int, float)) and isinstance(tyre, (int, float)):
        upsert_param(user_params, f'{PREFIX}wheelODf', fw + 2 * tyre, 'mm', comment)
        count += 1
    if isinstance(rw, (int, float)) and isinstance(tyre, (int, float)):
        upsert_param(user_params, f'{PREFIX}wheelODr', rw + 2 * tyre, 'mm', comment)
        count += 1

    attrs = design.attributes
    for k in TEXT_FIELDS:
        if data.get(k) is not None:
            attrs.add('FrameDesigner', k, str(data[k]))

    return count


def run(context):
    app = adsk.core.Application.get()
    ui = app.userInterface
    try:
        design = adsk.fusion.Design.cast(app.activeProduct)
        if not design:
            ui.messageBox('Open or create a Fusion design first, then run this script.')
            return

        file_dlg = ui.createFileDialog()
        file_dlg.title = 'Select Frame Designer JSON export'
        file_dlg.filter = 'Frame Designer JSON (*.json);;All files (*.*)'
        file_dlg.isMultiSelectEnabled = False
        if file_dlg.showOpen() != adsk.core.DialogResults.DialogOK:
            return

        with open(file_dlg.filename, 'r', encoding='utf-8') as f:
            data = json.load(f)

        if 'reach' not in data or 'ht_angle' not in data:
            ui.messageBox('That file has no reach/ht_angle — is it a Frame Designer export?')
            return

        count = import_design(design, data, file_dlg.filename)
        ui.messageBox(
            f'Imported {count} parameters (prefixed "{PREFIX}").\n'
            'See Modify > Change Parameters.'
        )

    except Exception:
        ui.messageBox(f'Import failed:\n{traceback.format_exc()}')
