"""Import a Suspension Designer (flex-stay) JSON export as Fusion 360 User Parameters.

What this is
-------------
The Suspension Designer (`flexstay/index.html`) exports a design as a single
JSON file via its "Export" button:

    { "_meta": {name, designer, date, version},
      "geom":  {MP, SP, FP, LP, SE, SG, AX, ID}   # each {x, y} in mm
      "cfg":   {eye, stroke, reach, ha, sa, ...}  # ~50 scalar design fields
    }

`geom` is millimetre coordinates, origin at the bottom bracket, x forward,
y up (see flexstay/CLAUDE.md, "The model"). `cfg` is the same flat object
the browser tool itself reads into its config object `C`.

This script reads that file and creates (or updates) one Fusion 360 User
Parameter per value, under Fusion's own units, so a Fusion sketch or feature
can reference the suspension geometry directly by parameter name instead of
a hand-typed number that silently drifts out of sync with the design tool.

Run it again after re-exporting an updated design: existing parameters are
updated in place by name, not deleted and recreated, so anything in the
Fusion model already driven by one of these parameters keeps working.

How to run it
--------------
Fusion 360 -> Utilities tab -> Add-Ins -> "Scripts and Add-Ins" -> Scripts
tab -> green "+" -> "Script from device" -> select this file -> Run.
A file picker then asks for the exported .json. Fusion needs an active
document (Modify > Change Parameters shows the result afterward).

Naming
------
Every parameter is prefixed `susp_` so it groups together and can't collide
with anything already in the design:
    susp_geom_MP_x, susp_geom_MP_y, ...   (mm)
    susp_cfg_reach, susp_cfg_ha, ...      (unit per FIELD_UNITS below)

Units
-----
Fusion parameters are unit-typed, not just numbers, so each field needs the
right one or a dimension in the model will read the value as the wrong
quantity. Angles and the handful of dimensionless fields (locks, mode
switches, tooth/link counts, percentages) are listed explicitly below;
everything else in `cfg`, and every geom coordinate, is a length in mm.
Edit ANGLE_FIELDS / UNITLESS_FIELDS / MASS_FIELDS if the source tool grows a
field that doesn't fit this split.
"""

import json
import re
import traceback

import adsk.core
import adsk.fusion

PREFIX = 'susp_'

# cfg fields that are angles in degrees.
ANGLE_FIELDS = {'ha', 'sa', 'bendA', 'leanA'}

# cfg fields that are mass.
MASS_FIELDS = {'mass'}

# cfg fields with no physical unit: booleans/mode switches (0/1), tooth and
# link counts, and percentages (bias, sag, fsag are all % of something, not
# a length or angle Fusion has a unit for).
UNITLESS_FIELDS = {
    'shockMount', 'config', 'a2cAuto', 'sgLock', 'spLock', 'chainAuto',
    'idlerOn', 'idlerMount', 'idlerLock',
    'ring', 'cog', 'links', 'idlerTeeth',
    'bias', 'sag', 'fsag',
}

GEOM_POINTS = ['MP', 'SP', 'FP', 'LP', 'SE', 'SG', 'AX', 'ID']


def unit_for_cfg_field(key):
    if key in ANGLE_FIELDS:
        return 'deg'
    if key in MASS_FIELDS:
        return 'kg'
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
    meta = data.get('_meta', {}) or {}
    comment = f'Suspension Designer: {source_name}'
    if meta.get('name'):
        comment += f' — "{meta["name"]}"'
    if meta.get('date'):
        comment += f' ({meta["date"]})'

    geom = data.get('geom', {}) or {}
    count = 0
    for pt in GEOM_POINTS:
        coords = geom.get(pt)
        if not coords:
            continue
        for axis in ('x', 'y'):
            if axis not in coords:
                continue
            upsert_param(
                user_params, f'{PREFIX}geom_{pt}_{axis}',
                coords[axis], 'mm', comment,
            )
            count += 1

    cfg = data.get('cfg', {}) or {}
    for key, value in cfg.items():
        if not isinstance(value, (int, float)) or isinstance(value, bool):
            continue
        upsert_param(
            user_params, f'{PREFIX}cfg_{key}',
            value, unit_for_cfg_field(key), comment,
        )
        count += 1

    if meta:
        attrs = design.attributes
        for k in ('name', 'designer', 'date', 'version'):
            if meta.get(k) is not None:
                attrs.add('SuspensionDesigner', k, str(meta[k]))

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
        file_dlg.title = 'Select Suspension Designer JSON export'
        file_dlg.filter = 'Suspension Designer JSON (*.json);;All files (*.*)'
        file_dlg.isMultiSelectEnabled = False
        if file_dlg.showOpen() != adsk.core.DialogResults.DialogOK:
            return

        with open(file_dlg.filename, 'r', encoding='utf-8') as f:
            data = json.load(f)

        if 'geom' not in data or 'cfg' not in data:
            ui.messageBox('That file has no geom/cfg — is it a Suspension Designer export?')
            return

        count = import_design(design, data, file_dlg.filename)
        ui.messageBox(
            f'Imported {count} parameters (prefixed "{PREFIX}").\n'
            'See Modify > Change Parameters.'
        )

    except Exception:
        ui.messageBox(f'Import failed:\n{traceback.format_exc()}')
