# Flex-stay kinematics tool — working notes

Single-file browser tool for a flex-stay full-suspension MTB. No build step, no
dependencies. `index.html` plus five PNGs in `img/`.

## Run it

Open `index.html`, or serve the folder. It is deployed to GitHub Pages alongside
`frame-designer.html`.

## Test it

```
cd test && node flexstay-tests.mjs
```

51 checks, no install required. The engine sits between the `// ==ENGINE-START==`
and `// ==ENGINE-END==` markers and contains **no DOM references**, so the test
file extracts that block with `new Function()` and runs it headlessly. Keep it
that way — if DOM code leaks into the engine block the tests stop working.

For anything touching the drawing, render it and look at it before shipping.
`jsdom` + `cairosvg` will rasterise the SVG offline; several bugs in this tool
were only visible in a rendered image.

## Layout

Three columns: inputs left, drawing and graph in the middle, outputs right.

The left rail is ordered by how often you touch it. Pivots and shock sit open at
the top because they are what you drag while watching the graph; frame geometry,
stay section, transmission, rider and the design file are `<details class="grp">`
folds, because geometry is normally set once, first, and then left alone. The
right rail leads with travel, balance and spring — the things a pivot move
changes — and folds the pivot loads and the stay stress calculation away, with
the twelve intermediate workings behind a second fold inside the stress panel.

Collapsed, both rails fit a 950px viewport without scrolling; before this they
scrolled 2191px and 1441px. Mobile went from 4237px of document to 1900px.

One chart at a time, full strip width, paged by the arrows and the four dots.
Axis ticks are rounded to 1, 2 or 5 times a power of ten (`niceAxis`) and the
decimals come from the step, not from the quantity — a 0.5 step under a
whole-number format printed 109, 110, 110, 111 on the anti-rise axis.

**The canvas has to stay landscape.** `fitView` crops the width to fill the box,
which is harmless on a wide canvas and takes the wheels clean off a square one.
The allowed crop now tapers to zero as the box gets square, and the mobile canvas
is `min(46vh,66vw)` so it stays roughly 1.6 wide.

## The model

Four-bar, one degree of freedom.

| Body | Contains | Constraint |
|---|---|---|
| A | chainstay, rear axle, flex pivot | pinned to frame at main pivot |
| B | upper stay, link pivot, shock eye | pinned to A at flex pivot |
| shock link | link pivot to second pivot | pinned to frame at second pivot |

The flex pivot is fixed at the rear axle. The seat stay is drawn from its real
bend geometry: a straight run out of the dropout, a fixed radius bend, then a
straight run to the yoke, with the launch angle solved so both ends stay on
their pivots.

Coordinates are millimetres, origin at the bottom bracket, x forward, y up. The
drawing group applies `scale(1,-1)` so the SVG is y-down inside a y-up model.

## The geometry chain

Built from the ground up, in `syncGeom()`. The ground plane is the datum and both
wheels sit on it, so each axle height is just its own radius above the ground —
the bike is level by construction and never pitches. The bottom bracket is the x
datum at `bbh` above the ground. The head tube position follows from head angle,
fork offset, axle to crown, lower headset stack and reach, which makes **stack an
output**.

Two pairs are written both ways, and `syncGeom(driver)` takes the name of the box
just typed so it knows which way to solve:

| type this | and this solves back |
|---|---|
| bottom bracket height | drop (`Rr − bbh`) |
| drop | bottom bracket height |
| head tube length | stack |
| stack | head tube length |

Consequences worth knowing. Head and seat angles are absolute against the ground
and stay exactly as typed whatever the wheels do — swapping the rear wheel moves
**drop**, not the angles. Anti-squat turns out to be almost completely insensitive
to rear wheel diameter at fixed bottom bracket height (112.8 → 113.0 → 112.3 across
26in to 32in), because the contact patch, chainring and main pivot all stay put and
only the small cog moves. That is not the same comparison as a real mullet
conversion, where the frame is fixed and the bottom bracket drops instead.

Axle to crown is measured to the crown race seat, so `hsLower` sits between it and
the bottom of the head tube: external cup 12-13mm, zero stack a few mm for the
crown race alone. With `a2cAuto` ticked, axle to crown tracks fork travel a
millimetre for a millimetre off the 160mm/571mm reference and its box is disabled;
untick it to enter a length no catalogue fork has.

The seat stay spacings are entered as full widths — rear dropout spacing and shock
mount width — and halved at the call, because `stayLoads` works in half widths.

Tyre section height isn't typed directly. The select offers width in inches —
what you'd actually buy — and `fillTyreWidths()` turns that into the `tyreR`/
`tyreF` millimetre figure the engine reads, on the approximation that an MTB
tyre's section is close to square: height = width x 25.4. 2.4in reproduces the
60.96mm this tool has always defaulted to. A width read back from an odd
`tyreR` (an older export, a hand-edited file) that doesn't match one of the
listed sizes gets its own option appended rather than silently snapping to the
nearest listed one.

Frame geometry has its own reset (`resetGeom`, `GEOM_KEYS`), separate from the
whole-tool reset in the Design file panel — everything in the Frame geometry
panel, reach through the fork, without touching pivots, shock, stay section,
drivetrain, rider or the design file. Geometry is normally set once, first,
and separately from the kinematics, so it gets its own way back to the default.

Pivots and mounts get the same treatment (`resetPoints`, `POINT_KEYS` —
MP/SP/LP/SE/SG, the four-bar's own points). **AX and FP are deliberately left
out of `POINT_KEYS`.** AX isn't a free point at all — `syncGeom()` derives it
from rear centre and BB height every time, so resetting it here would just be
overwritten on the next sync regardless. FP is set to wherever AX *currently*
is (`G.FP={...G.AX}`) rather than to the frozen default coordinate, restoring
the concentric flex pivot this bike is meant to have even if the frame
geometry — and therefore the axle position — has since moved from default.

## Frame tubes and the mounts on them

`frontTriangle()` is the single definition of where a tube is — centreline from,
to, and outside diameter for the down, top, seat and head tubes — shared by `draw()`
and the clearance readout so the picture and the numbers cannot disagree.

**Both centrelines already start at the bottom bracket centre and need no
parameter.** The down tube runs BB to the bottom of the head tube and the seat tube
is placed by seat angle alone; `frame-designer.html` assumes exactly the same
(`dt_ax = unit(ht_bot)`, line 1790), so the two tools agree. Do not "fix" this.
What the tubes gained is real stock: `dtOD` 38.1, `stOD` 34.95, `ttOD` 32, `htOD`
46.5, taken from frame-designer's `DEFAULTS` — they used to be hard-coded drawing
widths of 40/38/32/52 with no relation to anything you could buy.

`dtWeld` (12mm) and `ttWeld` (14mm) replace an `inset=0.10*C.htl` fudge that made
the down tube move whenever head tube length changed. They are measured along the
head tube axis and are **cosmetic only** — they move where a tube is drawn *to*,
never its direction, so they cannot disturb the kinematics.

**The front triangle is cut square, and the head tube is drawn last.** These two
go together and neither works alone.

A head tube and a seat tube have to show their end faces — a real tube is sawn
off, and the cut is a feature you design to. A round cap reaches a full radius
past the endpoint in every direction, which on the 46.5mm head tube is 23.25mm,
nearly twice the shipped `dtWeld`. So all four front-triangle tubes pass
`'butt'` as the optional 4th element of their `tubes()` segment. Round stays the
default and the rear assembly, the shock link, the seatpost and the fork
stanchion keep it: those are multi-segment runs and knuckles, where a cap that
only extends along the tube's own axis leaves seams. That was the finding of the
earlier all-square experiment, recorded in the comment on `line()` in `draw()`,
and it still stands for the parts it was about — what it could not solve, and
what killed it, was the bottom bracket, which now has a shell drawn over it.

Once the head tube is square it no longer overhangs its own ends, and drawing it
LAST is what makes the joint right. The down and top tubes run on past the head
tube wall to `dtTip`/`ttTip` so their own square cuts are buried under it; what
you see them stop against is the head tube's outer face, which IS the mitre.
Full mitre join, no clipping, no polygon work — `frame-designer.html` gets the
same result with `clipHalfPlane` against `ht_face_pt` because it draws real
polygons and cannot lean on paint order.

The previous arrangement was the exact opposite — head tube first, underneath,
round caps — and it was the best that could be done while the caps were round:
drawn last, the head tube's own cap buried the down tube's endpoint and editing
`dtWeld` appeared to do nothing. Drawn first it at least showed a gap, but the
down tube's own round cap then bulged out over the head tube and past its bottom
end, so the joint was still not the flat-cut junction a frame has. Square caps
fix the cause rather than the symptom, so the paint order flips back.

`dtTip`/`ttTip` stop on the head tube's CENTRELINE. Far enough that the buried
cut is never exposed, near enough that it cannot poke out the far side at any
head angle, tube diameter or clearance — checked from `ha` 60 to 75, `htl` 90 to
200, `dtOD` 25 to 50, `htOD` 34 to 60 and `dtWeld` 0 to 60, and the tip lands
between 29mm and 91mm along a 130mm head tube throughout. The reported segment
in `FT.dt`/`FT.tt` is still the real tube, BB to the wall: the buried overrun is
a drawing detail and has no business in a clearance readout.

The one thing square caps did break is the bottom bracket, where the down tube
and seat tube both stop dead on the BB centre and leave a notch between them.
That is what the **BB shell** is for: a 38.1mm circle on the BB, drawn above the
tubes in the same outline-and-fill colours. 38.1 is the shell, and also exactly
the down tube's diameter, so the circle is tangent to the down tube's sides and
flush with them, stands 1.6mm proud of the narrower seat tube, and covers every
part of the notch, since the notch is inside a 19.05mm radius by construction.

**Weld clearance slides the meeting point along the head tube; it never opens a
gap.** This took two attempts to get right, and the reasoning from both attempts
is worth keeping so it isn't relitigated a third time.

Originally `frontTriangle()` slid the down/top tube's drawn endpoint along
`F.axis` — the head tube's own axis, `sub(F.htBot, mul(F.axis,C.dtWeld))` — which
is correct: a bigger tube's mitre reaches further along the head tube, it doesn't
detach from it. But `recompute()`'s mount lock computed its own, separate `dtU`
straight from `frame(0).htBot`, fixed and blind to `C.dtWeld`. Since the head tube
and down tube axes are roughly 108° apart at the shipped geometry, the *drawn*
tube (correctly sliding along the head tube) diverged from that *fixed* reference
axis as clearance grew — 11.4mm apart already at the shipped default, 57.1mm at
60mm — so a mount correctly held at 55mm off the fixed axis looked like it was
drifting off the tube actually on screen.

The first fix attempt solved that divergence by making the drawn tube retract
along its *own* fixed axis instead of the head tube's — eliminating the kink, but
at the cost of reintroducing a gap between the tube and the head tube that grows
with clearance, which is not what weld clearance means and was reported back
immediately.

**The actual fix: derive the reference axis from the live drawn point, instead of
giving the drawing and the lock two different axes to agree on.** `dtU` is now
`unit(dtMeet-BB)`, where `dtMeet=sub(F.htBot, mul(F.axis,C.dtWeld))` is the same
sliding point the tube is drawn to — so there is only one axis, and both the
drawing and `recompute()`'s mount lock (`const dtU=frontTriangle().dtU;`) read it.
The down tube's own axis rotates slightly as clearance changes (0.85° at the
shipped default, 4.16° at 60mm) — small, physically sensible, and exactly what
should happen when the far end of a fixed-length tube slides along a wall it's
mitred into — and a locked mount rotates with it, staying exactly on the tube
because it is measured against the same live value the tube is drawn from, not a
frozen snapshot of it. Confirmed the meeting point stays on the actual head-tube
segment (`distFromBot+distFromTop == C.htl`) through the realistic range, and that
a locked standoff reads exactly its set value at every clearance tested.

This is where the first attempt at borrowing `frame-designer.html`'s approach was
half right, not entirely wrong: it does slide along the head tube axis, which was
worth keeping, but it *also* clips the result against the head tube's cylindrical
surface — a full mitre join, `frame-designer.html`:2262-2268 — which was dropped
too early. The `dt_ax` (its down-tube axis) is kept fixed throughout, which works
for *its* tube because a true-width polygon with a small mitre clip at the tip is
barely visible at any distance. It only became clear this tool couldn't get away
with the same fixed axis once the meeting point actually moved as clearance
changed — a fixed axis and a moving meeting point disagree by construction,
whatever the reason the point moves. Deriving `dtU` live, from `frontTriangle()`,
is what makes the two agree in this tool's line-and-stroke-width rendering
instead. That clipping is no longer missing, incidentally — drawing the head tube
last over buried tube ends is the same mitre by another route.

**Measured from the head tube's outer surface, not its centreline.** The other
half of `Q_dt` that was missing at first: `frame-designer.html` pushes the
meeting point out from the head tube's centreline by `ht_od/2`, on whichever side
faces BB, *before* the along-axis slide is applied — `dtWeld`/`ttWeld` are meant
to be clearance from the wall the tube actually welds to, not from a line running
down the middle of the head tube. Without that push, the shipped default
(`dtWeld=12` against a 46.5mm `htOD`, so a 23.25mm radius) put the meeting point
nowhere near the tube's real edge — barely a third of the way out from the
centreline. Added the same push here: `side` is computed once (whichever
perpendicular to the head tube's axis points toward BB) and reused for both
tubes, matching `ht_tt_side = ht_dt_side` — a down tube and top tube meet the
head tube on the same face.

**And the second offset, by the tube's own half-width, is needed here after
all.** That was written off as a polygon-only detail — "the stroke width already
draws the right thickness once the centreline itself ends in the right place" —
and it is wrong. It is the difference between the CENTRELINE ending at the weld
point and the tube's EDGE ending there, which on a 38.1mm down tube is 19.05mm:
more than the clearance being measured. Sending the centreline to the weld point
put the real lower edge a full half-diameter further down the head tube, so the
gap you saw was nothing like the number in the box.

So the reference point is a corner, and it is defined as a corner, exactly as
`frame-designer.html` defines it: the intersection of the head tube's outer face
with the down tube's outer LOWER edge (`dt_ht = Q_dt - dt_bot_perp*dt_OD/2`, with
`dt_bot_perp` the down tube perpendicular pointing down the head tube), and for
the top tube its outer UPPER edge (`tt_top_perp`, the one pointing up). `dtWeld`
is then measured along the head tube's outer face from its own bottom corner —
the corner the square end cut creates — to that weld root. `dtMeet`/`ttMeet` hold
the weld root; `pinEdge()` converts it to the centreline endpoint the drawing
needs.

`pinEdge()` iterates, because the offset is perpendicular to the tube's own axis
and that axis depends on the endpoint being solved for. Four passes; the
correction is a fixed half-diameter and only its direction moves, so it settles
immediately. `dtU` is then taken from the settled endpoint, keeping the invariant
above intact — one live axis, shared by the drawing, the mount lock and the
readout. Verified across the same parameter sweep as `dtTip`: the weld root sits
on the head tube's face to 1e-14, on the down tube's lower edge exactly, and
`dtWeld` from the bottom corner to 1e-3.

**The outline stroke carries the diameter; the fill sits 7mm inside it.** This
was the other half of the 12mm reading short. `tubes()` used to draw the outline
at `w+7` over a fill of `w`, so every segment it drew had a silhouette 7mm fatter
than the diameter it was handed — 3.5mm of head tube and 3.5mm of down tube both
eating into the same joint, which is why a correctly placed 12mm clearance still
measured about 8.6mm on screen. It now draws the outline at `w` and the fill at
`w-7`, which is the convention the rear assembly has always drawn to by hand
(40/33 for the chainstay, 30/23 for the stay and the link) and makes the drawn
edge the real wall. Every `tubes()` segment is 7mm narrower than it used to be;
that is the correction, not a loss. Diameters here are real stock and a design
tool has no business overstating them.

### Standoffs, and why only two pivots get one

The three frame pivots are not alike:

| pivot | what it is | what the tool does |
|---|---|---|
| shock mount `SG` | welded-on bracket | `sgStand` / `sgLock` |
| link to frame `SP` | welded-on bracket | `spStand` / `spLock` |
| main pivot `MP` | printed housing tying ST to DT | **no lock, by design** |

A welded-on bracket owns one dimension: its perpendicular standoff from the down
tube centreline. That belongs to the part, not to the bike, so when you scale a
design up a size and the down tube swings, the standoff has to survive. Locked, the
mount slides along the tube keeping that one number — `recompute` re-places it with
`onTube(dtU, alongOf(dtU,G[k]), C[off])`, which preserves the along-distance
implicitly because it reads it back from wherever the point already is. Unlocked,
the number simply reports.

**The main pivot is deliberately excluded.** Its printed housing is unique to each
frame — the down tube to seat tube angle changes with every size — so pinning it to
a tube would be wrong. It gets the clearance check and a readout of the three
numbers the part is actually made to instead: its offset from each tube and that
included angle.

**The standoff box is live when locked and greyed when not** — the reverse of
`a2cAuto`, because here the lock turns a derived readout into an input. Easy to
wire backwards; both the value and the `.disabled` flag live in `refreshDerived`.

**Both locks default on, at 55mm.** `DEF.geom.SG`/`DEF.geom.SP` are left at their
old shipped coordinates — the lock glue in `recompute()` re-derives both from
their current along-tube distance every time it runs, including the very first
one at page load, so simply flipping the two flags is enough; no coordinate had
to change by hand. This does move the shock mount from where it originally sat
(the old standoff was 67.1mm, not 55) and the drawn eye-to-eye comes out around
237.5mm against the typed 230mm spec — enough to trip the existing warn styling
on "Shock eye to eye, drawn" on a fresh load. That is the honest consequence of
locking the mount to 55mm rather than a bug; nothing was tuned to hide it.

### Clearance

A pivot is a boss, not a point: `pivotOD` (22mm) gives it a body, and clearance to a
tube is `segDist(centre,a,b) - tubeOD/2 - pivotOD/2`, so negative means the boss is
inside the tube. Checked for the pivots that are frame features — MP, SP, SG, and
the idler when frame-mounted. The readout runs before the no-solution bail-out in
`readouts()`, because it is pure frame geometry and is most wanted precisely when
the linkage will not solve.

**Known, and real: at the shipped defaults the main pivot boss overlaps the seat
tube by about 15mm.** The tool flags it rather than hiding it. Either the pivot
moves or the housing has to interrupt the tube.

## Save / load

Design name and designer are free text, so they live in `META`, outside `C` —
`fillCfg()` runs every field in `C` through `parseFloat` when it wires up its
input, which would permanently reject a name the moment it looked at the box.
Export writes `{_meta:{name, designer, date, version}, geom:G, cfg:C}`; import
parses that same shape and then runs the exact startup sequence the reset button
uses — `syncGeom(); fillPoints(); fillCfg();` — so a file with an old or partial
`cfg` still comes out through the same derivation the app applies on every other
input change.

**Import layers the file over the defaults** (`Object.assign` onto a clone of
`DEF`) rather than replacing `G`/`C` wholesale, so a design saved before a field
existed keeps that field's default instead of losing it. It used to replace them
outright, which meant an older file came back with no `G.ID` at all and
`fillPoints` threw on the idler row. Any field added from here on is safe for the
same reason.

## Validated against Linkage X3

The validation belongs to the **solver**, not to whatever the app ships as its
defaults. `test/flexstay-tests.mjs` holds a frozen `REF` geometry — the one these
numbers were measured on — and the Linkage assertions run against that, so the
defaults can be changed without quietly invalidating them. Do not edit `REF` to
make a test pass. The shipped defaults get their own check that they still solve.

These are regression tests — if a change moves them, the change is wrong.

**Keep `process.exit` at the very bottom of the test file.** It used to sit just
after the cage checks, which left the shipped-defaults block below it as dead code
that never ran — two checks that silently did nothing. Anything appended after an
early exit has the same problem.

| | Linkage | Tool |
|---|---|---|
| Travel | 139 | 139.0 |
| Progression | 11.3% | 11.4% |
| Anti-squat | 113.5% | 113.4% |
| Anti-rise | 109.5% | 109.5% |

## Traps

**Floating point noise at zero compression.** At top-out the target shock length
*is* the current length, so the bracketing residual should be zero but comes out
as noise of either sign — the rigid-body transform round-trips through `atan2`.
Treating that as a real sign meant the solver reported a jam on the first frame.
V8 lands positive, JavaScriptCore lands negative, so it worked in Chrome and
failed in Safari. Anything inside 1e-6 is now treated as a root. The jitter test
catches regressions: with the old code 176 of 400 jittered geometries jammed.

**Test in Safari as well as Chrome.** See above.

**Anti-squat is read at the front axle vertical**, not the centre-of-mass
vertical, and heights are measured from the ground, not from y=0. Getting either
wrong makes a low main pivot look like it produces no anti-squat.

**The contact patch stays on the ground through the travel.** It used to be
`AX.y - Rr`, one radius below the *current* axle, so it climbed into the air as
the suspension compressed and the anti-squat datum went with it. The wheel rolls
on the ground and the frame moves down onto it, so `CP.y` is fixed at
`g.AX.y - Rr`, the un-compressed contact height. Only `CP.x` tracks the axle.
The two definitions agree at top out, which is why the Linkage numbers did not
move when this was fixed — but at sag anti-squat went 100 to 112 and anti-rise
98 to 109.

**Wheel size is rim bead diameter plus tyre height.** Treating "29 inches" as an
outer diameter and adding tyre on top gives a 427mm radius.

**`P` is the chain pitch constant** in the engine. Do not shadow it inside
`draw()` — a local `const P` puts the earlier drivetrain code in the temporal
dead zone and the whole frame silently vanishes.

**Partial sweeps.** A jammed linkage returns a partial frame list. Leverage needs
a neighbour each side, so the first frame's `lr` is undefined. Readouts bail to
dashes below three frames.

**Never blank the canvas.** `draw`, `readouts` and `charts` each run in their own
try/catch, and `topFrame()` supplies the geometry as drawn when the linkage will
not solve. A failure in one panel must not take the drawing with it.

**Number inputs and scroll wheels.** A wheel over a focused number input silently
edits it in most browsers. They blur on wheel.

**The canvas SVG is absolutely positioned.** With `height:100%` in normal flow it
resolves its height from its own viewBox aspect ratio instead of from the flex
row, which pushed the page 38px past the viewport. `position:absolute` inside the
relative `#canvas` breaks that loop.

**Both panels fit themselves to their own pixel box**, so the first paint can land
before the flex layout settles. A `ResizeObserver` on `#canvas` and `#charts`
refits; the window resize listener alone is not enough.

**Pivot hit targets are sized in screen pixels**, `HIT_PX * mmPerPx`, so they stay
grabbable at any zoom. The decorative ring and dot carry `pointer-events:none` and
the handler uses `closest('.drag')` — before that, a click on the exact centre of a
pivot hit the decorative dot, which has no `dataset.key`, and silently did nothing.

**The rear axle is derived from rear centre and bottom bracket height.**
`syncGeom()` rewrites `G.AX` from them on every input change and carries `G.FP`
with it while the two are concentric, so a drag of that point has to write back
into `C.rc` and `C.bbh` or the next sync silently undoes it. The drag handler
does exactly that — but only for `C.rc`. **`bbh` is never written from an axle
drag, because it is the ground-plane datum** (`ground=-C.bbh` in `draw()`), and
`syncGeom()` builds the front wheel's height from it too, via `C.stack`. A drag
used to back-solve `bbh` straight from the pointer's raw y and skip calling
`syncGeom()` afterward, so a vertical drag silently moved the ground plane while
leaving the front wheel's height stale — the two wheels would visibly desync.
The axle marker is horizontal-drag-only now: the handler sets `C.rc` from the
pointer's x and calls `syncGeom()`, which snaps `G.AX.y` back onto the unchanged
ground and re-derives everything hung off it in the same pass. Vertical axle
position only ever changes by typing bottom bracket height or drop.

**Anti-squat reads `cfg.fax`, which `recompute()` overwrites** with
`frame(0).FA.x`. Anything calling `sweep()` directly — the tests do — supplies its
own `fax` and never gets that overwrite, so a stale value there means the tests and
the app measure at different places. `DEF.cfg.fax` is kept in step with the default
geometry for the same reason.

## Stay structure

Two effects on the same tube, which may add or cancel:

1. Imposed end rotation from the suspension. Propped cantilever, so peak
   curvature is twice the mean over the developed length.
2. Axial load acting through the offset of the bend, amplified by P-delta.

Whether they oppose is computed from the sign of the flex rotation against the
bend direction — it is not hard-coded. On the current geometry they oppose.

Out-of-plane bending comes from the inward lean: the stay leaves the dropout
already leaning in and the bend near the yoke takes the lean back out, so the
worst offset from the chord is at that bend. Nothing cancels it, so the two
planes are combined as a resultant moment.

Diameter changes the bending stress; **wall thickness does not**. Curvature is
imposed, so the outer fibre travels the same distance whatever the wall. Wall
changes the moment and the force, which are reported separately.

Both stays share the axial load.

## Overlays

Four toggles in the bar. Axle path is on by default, the rest off. Force vectors resolve `pivotForces`
for the frame on screen. Anti-squat and anti-rise are separate toggles that share
one construction block: the front axle vertical and the 100% of centre-of-mass
-height mark are drawn for either, the chain run and axle-to-instant-centre lines
only for anti-squat since braking does not involve the chain.

**The framing is fixed.** `fitView` takes its content box from the static geometry
— `frame(0)` and the top-out axle — so cycling the suspension, holding at sag or
turning an overlay on cannot make the view breathe. Only the geometry inputs, the
canvas size and the zoom control move it. That means the anti-squat rays can leave
the top of the view; their labels are clamped back inside and stacked rather than
zooming out to chase them. Labels inside the drawing need their own `scale(1,-1)`
because the group they sit in is y-flipped.

**The axle path needs a casing.** It runs up out of the flex pivot along the seat
stay and over the spokes, so as a thin `#134463` line it was invisible against a
30mm-wide stay of exactly that colour. It is a pale halo under a contrasting dash.

## Parts toggles

Six buttons — wheels, drivetrain, cockpit, saddle, shock, fork — hide one piece
of artwork each, all on by default (`showWheels` etc.). They share the bar's one
toggle cluster with the overlays (axle path, anti-squat, anti-rise, forces)
rather than getting a second divider: `.partbtn` gives them `--link` teal against
the overlays' `--rear` blue, so the two kinds of pressed button read apart by
colour instead of by a text label or a second border. The frame itself (front
triangle, rear stay, shock link) is never one of them; only bolt-on product
artwork is. This is where a future crank image replaces the chainring/cog rings
under the drivetrain flag, without touching anything else.

The position readout shows current alongside total for both numbers — wheel
travel and shock stroke — so the slider reads as a fraction of travel, not a
bare position: "70/139mm wheel · 30.9/65.0mm shock". Total wheel travel is the
last swept frame's `rise`; total shock stroke is the typed spec, `C.stroke`.
Full wording is still in the `title` for a hover.

**`#bar` spreads its controls only when they actually fit one line.**
`justify-content:space-between` looks right full-width, but on a wrapped line
it stretches whatever's left over that line's own width too — a short trailing
row of leftover buttons ends up spread edge to edge with huge gaps, which reads
as broken rather than tidy. `fitBar()` measures the real content width (walking
into `.bargroup`, which is `display:contents` so its own `getBoundingClientRect`
is empty) against the bar's, and only then adds the `spread` class; otherwise
the bar falls back to plain left-aligned wrapping. Sixteen buttons plus a
220px slider need roughly 1890px of window before they fit one line — call it
a wide monitor, not a laptop — so most sessions will see it wrap, evenly
spread only within whatever line it lands on.

`drivetrain` covers the chainring and cog rings, the chain line, and the rear
derailleur — `guide`/`tension`/`up` are still computed unconditionally because the
derailleur draw call, further down in `draw()`, needs them whether or not the
toggle is on. `cockpit` covers both the stem art and the headset/steerer stack
tube, drawn in two separate places. The exposed seatpost and the shock link
(the actual rocker, teal) are frame, not toggled by anything.

## Lock shock

`lockLen` defaults **on**. Eye to eye is a real product spec, not a free variable,
so dragging one shock mount moves the frame around a fixed shock length by
default rather than silently stretching it; the drag handler already had this
logic (it moves the far eye to hold `C.eye`), it just used to start disarmed.

## Tube shape

Frame tubes use `'stroke-linecap':'round'`. **Tried `'square'` once** (flat,
square-cut ends like the frame designer tool) and reverted it: a round cap
extends a full radius in every direction, so it blends two tubes into one
silhouette whatever angle they happen to meet at, but a square cap only
extends along the tube's own axis — no sideways forgiveness. That held up fine
at simple two-tube joints (head tube, seat top, shock link) but left visible
seams at the bottom bracket, where down tube and seat tube converge from two
different angles, and along the rear stay's multi-segment path. Don't retry
this without also solving the BB and rear-stay joints — e.g. drawing them as
one path so linejoin can round the internal corners, or overlaying a circle
at the BB the width of a real bottom bracket shell.

## Idler

Off by default. `G.ID` holds the **top-out** position, `C.idlerOn` / `C.idlerTeeth`
/ `C.idlerMount` the rest (mount 0 = main frame, 1 = swingarm, as a numeric-valued
`<select>` so `fillCfg` picks it up on its ordinary `parseFloat` path). A swingarm
idler is carried through the travel by `rot(g.ID, g.MP, s.phi)`, exactly as the
derailleur guide pulley is; the rotated position is never written back.

**The force line is whichever run crosses from the frame to the swingarm**, because
that is the only segment that can carry chain tension across the suspension:

| idler | force line | the other run |
|---|---|---|
| off | chainring → cog | — |
| frame mount | idler → cog | chainring → idler, frame to frame |
| swingarm mount | chainring → idler | idler → cog, rides the stay |

The run that is *not* the force line is constant through the travel either way, so
`kick` needs no special case — measuring the force run is enough. Two exact-zero
results pin this down and are worth keeping as tests: an idler concentric with the
main pivot gives **exactly** zero chain growth, on either mount.

**Tangent selection is the whole difficulty.** `chainRun` picks between its two
candidates by "whichever normal has the greater y", which is fine for a roughly
horizontal chainring-to-cog run and wrong for an idler. With the idler directly
above the chainring — which is where the shipped default (0,125) puts it — both
candidates have the *same* normal y, so the pick is a coin flip that can route the
chain over the front of the chainring. `beltRun` therefore computes both tangent
families (external for pulleys wrapped the same way, crossed for opposite) and
`routeIdler` picks among the four candidates using two hard constraints:

1. the chain must wrap the idler the same way going in as coming out, and
2. the chainring and the cog must turn the same way as each other — a chain that
   wrapped them oppositely would have to cross itself.

Together those leave exactly one candidate. `chainRun` itself is deliberately
untouched so the Linkage numbers cannot move.

**A high idler on a low pivot is genuinely pro-squat.** The shipped default idler
position on the shipped (low) main pivot gives about −96% anti-squat. That is not a
bug: raising the idler steepens the chain force line until it out-climbs the
axle-to-pivot line, and the force centre flips behind the axle. Real high-pivot
bikes put the main pivot high and the idler *below* it — pivot at 150, idler around
115 gives a sane ~110%, and lowering the idler from the pivot raises anti-squat
monotonically.

**An idler needs a longer chain** — about 12 links more. That used to mean
switching it on immediately tripped the clamp warning; the chain is now fitted
instead (see below), so it just goes from ~122 links to ~134.

**The idler's chain has to be drawn last.** An idler above the bottom bracket puts
the chainring-to-idler run straight through the seat and down tubes, which are
painted later and bury it. `drawTop()` is therefore deferred to the rear-mech stage
when there is an idler, and left where it was when there is not — so the no-idler
drawing is unchanged, and the idler chain sits on top like the mech, which is the
correct side of the frame for it anyway.

## Chain length

`C.chainAuto` (on by default) makes the link count a **derived** field, on the
`a2cAuto` pattern: `refreshDerived` disables the box, and `sweep` returns the
fitted count as `result.links` for `recompute` to write back into `C.links`.
`fitChain` picks the length that leaves the cage furthest from either stop —
every frame can absorb a total between its `chainPath` at the two cage limits, so
it takes the highest floor and the lowest ceiling across the travel and aims at
the middle, rounded to an even number of links. Untick it to type your own count
and get the clamp warning back.

The cage is therefore solved in a **second pass** inside `sweep`, after the frame
loop, because fitting needs every frame before it can choose a length. Chain
length only ever feeds the cage, never the linkage, so nothing above that line
depends on it — which is what makes the two-pass split safe.

**The mech's capacity is set by cage length, not by the angular stops.** The
window of link counts that does not clamp is only five or six wide, and that is
honest: a 62mm cage is worth about 90mm of chain. Widening `CAGE_HI` does not
help — past about 90 degrees the tension pulley swings past its furthest point
from the chainring, `chainPath` starts *falling* with cage angle, and `solveCage`'s
bisection silently breaks because it assumes the opposite. `CAGE_LO`/`CAGE_HI` are
already at the widest monotonic bracket, and a test asserts take-up rises across
all of it. If you want more capacity, lengthen the cage.

**The clamp warning is only judged on a full sweep.** Mid-drag the coarse 15-step
sweep steps straight over the frames that clamp, so the warning blinked on and off
as a point was dragged — noise, about a number the user was not even editing.
`recompute(quick)` now passes `null` for the clamp when `quick`, so the verdict
lands on release. A solver jam still reports immediately: that one is about the
linkage itself.

## Idler on the main pivot axis

`C.idlerLock` makes the idler and the main pivot one point — the concentric layout
that gives exactly zero chain growth.

**The main pivot never moves on its own.** It is what the whole linkage hangs off,
so relocating it silently rewrites travel, leverage and anti-squat all at once.
The idler is therefore the one that moves in both directions: onto the pivot when
the lock goes on, and straight up off it by `IDLER_SPLIT` (40mm) when the lock
comes off. Both transitions live in `syncGeom` under `driver==='idlerLock'`, which
is the hook `fillCfg`'s checkbox branch gives you.

The standing constraint follows the same authority: `recompute` slaves `G.ID` to
`G.MP`, next to the line that already slaves `G.FP` to `G.AX`, which catches every
write path at once — drag, typed coordinate, reset and import. The drag and typed
handlers write both points so whichever you grab carries the other; that is an
explicit action and is allowed to move the pivot.

While locked the pair get **one** marker, the main pivot's, because two coincident
markers fight over the hit target. The idler is still plainly visible as its pulley
ring with the chain wrapped over it. The 40mm split comes from `hitFor`: the target
has a 14mm floor, so anything under 28mm apart leaves one of the pair unreachable
behind the other, and the visible ring is 16.5mm of artwork.

## Artwork

Placed by an affine matrix built from two anchor points. The shock is sliced four
ways so only the spring section stretches — the reservoir rides with the body,
and the tail and body keep their proportions. The fork lowers never stretch: the
casting is rigid and slides up a procedurally drawn stanchion, which is the real
mechanism.

Anchors were recovered by pixel analysis (transparent bores for the shock
eyelets, largest dark blob for the fork axle). For new artwork, ask for marked
`anchor-a` / `anchor-b` circles and a `stretch-y` band instead.

`CHAINCAL` in the engine is a 9.8mm fudge calibrating the simplified chain wrap
model so a nominal chain count lands mid-range on this bike. It does not affect
how far the cage swings, which is what the drawing depends on.

**`frame()`'s `crown` used to sit on the steerer axis, not the fork's own
axis — so the exposed stanchion tilted away from parallel with the fork
lowers as soon as `C.offset` left zero.** `FA` (the front axle) is
`raceSeat + axis*along + perp*offset`: on the fork's own axis, which runs
parallel to the steerer but displaced sideways by the rake. `crown` used to
be `raceSeat + axis*46` — no `perp*offset` term — so it sat on the *steerer*
axis instead. The two axes only coincide at `offset=0`, which is why the bug
hid until someone actually looked at a non-zero-rake fork. Fixed by giving
`crown` the same `perp*offset` term as `FA`, so both points sit on
`raceSeat + perp*offset + axis*t` for their own `t` — literally the same line,
not just parallel to it. `raceSeat` is now returned from `frame()` (it was a
local before) since the new lower-headset block needs it too.

**Two new hardware blocks, both drawn as `tubes()` segments with a `'butt'`
cap so they come out as literal rectangles, not tubes** — the same technique
the square-cut front-triangle tubes use, not a new drawing primitive:
- The **fork crown**, `showFork`-gated, centred on the (now-fixed) `F.crown`
  point and drawn *after* the exposed stanchion so it caps the stanchion's top
  the way a real crown casting does. Wider than the stanchion — "slightly
  bigger diameter" — and in line with the stanchion because it's built from
  the same `fu` (=`F.axis`) the stanchion and fork-lower artwork already use.
- The **lower headset**, `showCockpit`-gated, running `F.htBot` → `F.raceSeat`
  — that distance is exactly `C.hsLower` by construction, so the block's
  length tracks the lower-headset-stack field live, the same way the existing
  upper steerer-stack tube (`F.htTop`→`F.steerTop`) already tracks `stemH`.
  Drawn at `C.htOD` so it reads as a continuation of the head tube rather than
  a new tube of its own.

Both use the same fill/stroke pair as the pre-existing upper steerer-stack
tube (`#b9c0c6`/`#7b848c`) rather than a new colour — they're the same kind of
part (headset/crown hardware, not a frame tube or the fork casting itself), so
they share its colour instead of introducing a third.

**The crown fix above was necessary but not sufficient — `topW`, the
stanchion's other endpoint, had its own, separate source of the same
symptom.** Reported back as still wrong after the crown fix shipped: the
stanchion still wasn't parallel to the head tube. `topW` (where the stanchion
meets the top of the lower casting) was built from *both* components of
`K.top-K.axle`, the artwork's own top/axle pixel anchors:
```
const topW=add(add(F.FA, mul(fp, ks*mir*(K.top[0]-K.axle[0]))),
                        mul(fu, ks*(K.top[1]-K.axle[1])));
```
`K.top` and `K.axle` sit at pixel x 74.5 and 154.4 — an ~80px lateral (`fp`)
gap in the source image, unrelated to rake, that doesn't exist on a real fork
(the stanchion telescopes straight into the same leg the axle bolts to, no
extra sideways step). Baked into `topW`, that gap put the stanchion's own
*bottom* end on a line further sideways than `F.crown`'s line — a second,
independent tilt, present even at `offset=0`, that got worse under
compression as `along` (and so the segment it's spread across) shortens while
the fixed pixel-derived gap doesn't. Numerically confirmed before and after:
11.3°–20.0° off-parallel across offset 0/44/90 and compression 0/40/80mm
before touching `topW`; exactly 0° after. Fixed by dropping the `fp` term
entirely — `topW=add(F.FA, mul(fu, ks*(K.top[1]-K.axle[1])))` — so it only
ever inherits the artwork's *axial* top/axle distance, landing it on the exact
same `raceSeat + perp*offset + axis*t` line `FA` and `crown` are already on,
by construction rather than by the source image's anchors happening to agree.

## Not done

- Beam solve for the stay, letting it find its own deflected shape rather than
  being handed a curvature distribution. Would settle the end-condition question.
- Chain wrap geometry done properly — real tangents and arc angles including the
  S-wrap through the jockeys — so the link count is usable for building.
- Anti-squat referenced to the fully extended front axle rather than the current
  one. Linkage recalculates it; this does not.
- Save/load and URL sharing.
