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

**The toolbar (`#bar`) sits above the canvas, not below it.** `#stage`'s
children are plain flex-column siblings with no order-dependent CSS anywhere
(checked — no `:first-child`/sibling combinators touch `#canvas`/`#bar`/
`#charts`), so this was a pure HTML reorder: `#bar` moved before `#canvas`,
`#msg` (the error banner, normally `display:none`) stayed put right after
`#canvas`. `#bar`'s rule flipped from `border-top` to `border-bottom` so it
still visually separates the toolbar from what's below it instead of sitting
uselessly against the page edge.

**The canvas got shorter on purpose, to leave more of the chart carousel
visible without scrolling.** `#canvas{flex:1;min-height:230px;max-height:56vh}`
— the `max-height` is what actually does it: without a cap, `flex:1` lets the
canvas claim every pixel `#stage` has spare, however tall that leaves the
carousel. This interacts with `fitView`'s own "crop rather than shrink"
behaviour (below): making the box shorter effectively widens its aspect ratio,
which makes the height-driven vertical crop trigger more readily at extreme
geometries. Checked across `ha` 45–75° and it's clean; **at `ha`≈80° (unrealistic
for a real bike — nobody runs a head angle that slack) the stem art itself
starts clipping at the top edge**, confirmed by rendering the identical
geometry with the `max-height` cap removed, which fixes it — so this is a real,
known trade-off of asking for a shorter box, not a bug, and not worth chasing
into geometries no actual bike would use.

**Wheels touch the bottom edge on purpose; the top edge is a tuned pad, not
zero.** `draw()`'s content box (`index.html:1160`, the `ys` array building `lastBox`)
is `[ground, F0.steerTop.y+62, post.y+80]`. `ground` already needs no pad — the
wheel sits exactly on it by construction. The stem (`STEMART`) and saddle
(`SADDLEART`) pads aren't guesses: measured each art's own transformed bounding
box above its anchor point (`steerTop`/`post`) across a spread of head and seat
angles (down to unrealistic extremes, 45° head angle, 68-80° seat angle) and
took the worst case plus a small margin — 62mm and 80mm respectively. The
previous flat pads (70/90) were already in that neighbourhood; this only
trimmed what was provably spare. `fitView`'s own uniform margin
(`index.html:1099`, "so the ground line clears the edge") also came down
slightly, from ×1.07 to ×1.03, for a matching trim on all four edges together.

One chart at a time, full strip width, paged by the arrows and the dots —
`#cdots` is hand-written in the HTML with one `<i>` per `CHART_VIEWS` entry, so
adding a chart means adding a dot alongside it or paging silently runs one page
further than the dots show. Axis ticks are rounded to 1, 2 or 5 times a power
of ten (`niceAxis`) and the decimals come from the step, not from the quantity
— a 0.5 step under a whole-number format printed 109, 110, 110, 111 on the
anti-rise axis.

**One chart, `axlepath`, plots two paths instead of one value against travel.**
`chart()` dispatches any view carrying `type:'xy'` to `chartAxlePath` instead of
the normal travel-vs-value renderer. It draws the rear axle's swept path (`f.AX`
per frame, the same points the `axpath` overlay draws on the bike) against the
front axle's — which, since the fork has no rear-linkage kinematics of its own,
is built by sweeping fork travel over the same fraction of stroke as each
rear-sweep frame, `frame(C.forkTravel*i/(n-1)).FA` — the same 1:1 coupling
`draw()` already uses to pose the fork on screen when not holding sag, just run
across the whole sweep instead of one frame. `var(--rear)` against a new
`var(--front)` purple, with a text legend since a chart, unlike the overlay, has
no bike around it to place a colour by proximity to.

**Magnified, not absolute — each path is plotted relative to its own top-out
point, not the frame's real coordinates.** On the real frame the two starting
points are a wheelbase apart, which is a fact about the bike and has nothing to
do with the shape either curve traces — the only thing worth comparing here —
so `rear[0]`/`front[0]` are subtracted off before anything else touches the
data, landing both curves on a shared origin (drawn as a small grey dot) exactly
the way the reference "magnified axle path" chart this was modelled on does it.
That also changes what there is to autoscale to: fitting the box to two small
relative curves instead of a wheelbase-plus-two-specks is what makes the paths
fill the window instead of sitting lost in a mostly empty plot. Sag markers are
translated by the same per-series offset so they still land on their own curve.
Both axes still share one mm scale (picked from whichever of x or y is tighter
for the combined bounding box, via the same `niceAxis` step so the grid reads
as one ladder, not two) — unlike every other chart here, x and y are the same
kind of quantity, and stretching them differently would bend a path that is,
physically, a specific shape.

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

**`f.LP` is a solved four-bar point, not a point on the drawn stay curve, so it
gets its own short mount brace rather than being folded into the stay's own
line.** `stayPath(f.FP,f.SE)` runs the whole way to the shock eye already, so
the drawn stay and the solved geometry can only ever disagree about where LP
sits relative to that curve — and on this linkage they do, since LP is body
B's own rigid point, not something `stayPath` places for you. The old drawing
papered over that gap with two extra lines, `SE→LP` and `LP→(86% up the
stay)`, at the stay's own full width — which is a straight line standing in
for "near enough", and at the stay's own 30/23 it read as one oversized,
ambiguously-shaped tube rather than a stay with a small part bolted to it.
`closestOnPath`, next to the general-purpose `segDist` in the engine block,
finds where LP actually sits closest to the curve that is actually on screen,
and a
single short `tubes()` brace — the same idea as the shock mount brace on the
down tube, `frontTriangle`'s `foot` — runs from there to `f.LP`. Perpendicular
falls out of "closest point on a straight segment" for free: it's exactly the
foot of the perpendicular from `f.LP` to whichever segment it lands nearest,
so there was no separate angle to solve. About 20mm at the shipped default;
scales with whatever the real geometry does since it is read fresh off
`path.pts` every frame, not fitted once and left to drift.

**The flex-zone red overlay is gone, along with `polyHead`.** `recompute()`
sets `C.zone` to the seat stay's own full length on every call — "bending
always spans the whole seat stay" is already the comment on that line — so the
overlay was always drawing the entire visible stay in red, doubled up on top
of the tube already there. `C.zone` itself is untouched (still computed, still
part of the saved/exported shape), since the stress panel's own workings read
`stayGeo.total`, not this field; only the now-pointless highlight and the
polyline-head helper that built it are gone.

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

**The standoff box used to be live when locked and greyed when not** — the
reverse of `a2cAuto`, on the reasoning that the lock turns a derived readout
into an input. Reported back as backwards and inconsistent with the rest of
this tool's locks (the points-panel lock right below, `ptLocked`, is
unlocked-live/locked-disabled, and so is every other coordinate lock a user
would compare it to) — a fair complaint even though the old wiring was
internally consistent on its own terms, since "consistent with itself" isn't
the bar when every other lock in the same tool reads the opposite way.
Reversed: `refreshDerived` now disables the box exactly when `C[lock]` is
set, so locked reads as fixed-and-uneditable and unlocked as free-to-type,
matching `ptLocked`.

That flip changes what "live" has to mean, though: unlocked used to make the
box a pure readout (typing did nothing, since `recompute()`'s own unlocked
branch — `C[off]=standoffOf(dtU,G[k])` — overwrote whatever was typed on the
very next recompute anyway, which is *why* it was disabled). Making it
genuinely editable when unlocked needed the box to actually move the point,
not just accept a value that would be discarded a moment later — so
`sgStand`/`spStand` were pulled out of `fillCfg()`'s generic
value-goes-in-`C`-and-nothing-else loop (next to `sgLock`/`spLock`, already
excluded there for their own reasons) and given their own `onchange`: while
unlocked, typing calls the exact `onTube(dtU, alongOf(dtU,G[k]), v)` recompute
would use *if* locked, so the typed number lands the point there directly,
and the very next `recompute()` reads that same position straight back as an
identical standoff — a round trip, not a fight. Locked, typing cannot happen
at all (the box is disabled), so there is nothing for the box's own handler
to do differently there; `recompute()`'s existing locked branch is unchanged.

**SP's mount matches SG's.** `frontTriangle()`'s draw call used to follow the
tube-coloured brace tube at `[boss,G.SP,30]` with an extra `r:34` disc, plain
tan-and-brown like the tubes but large enough, and different enough from the
brace tube next to it, to read as its own thing rather than as a boss on the
down tube — a beige-warm colour under any colour-shifted display reads close
enough to gold to be mistaken for one of the actual gold pivot rings (`SE`/`SG`,
`var(--shock)`), so "the yellow circle on the linkage pivot" was a fair
description even though nothing in this file is literally yellow. Removed; SP
now renders exactly like SG, a brace tube and nothing else.

**Both locks default on, at 55mm.** `DEF.geom.SG`/`DEF.geom.SP` are left at their
old shipped coordinates — the lock glue in `recompute()` re-derives both from
their current along-tube distance every time it runs, including the very first
one at page load, so simply flipping the two flags is enough; no coordinate had
to change by hand. This does move the shock mount from where it originally sat
(the old standoff was 67.1mm, not 55) and the drawn eye-to-eye comes out around
237.5mm against the typed 230mm spec — enough to trip the existing warn styling
on "Shock eye to eye, drawn" on a fresh load. That is the honest consequence of
locking the mount to 55mm rather than a bug; nothing was tuned to hide it.

**"Reset points & mounts" resets the standoff config, not just the coordinate,
because the two can genuinely disagree.** `DEF.geom.SG`'s own raw coordinate is
67.1mm off the down tube, not 55 — it only ever reads as 55 on a fresh load
because `sgLock` defaults on and `recompute()`'s glue forces it there, per the
paragraph above. So `resetPoints` copying `G.SG=DEF.geom.SG` and stopping there
used to leave the *displayed* standoff wherever it happened to be before the
click: locked, the glue would immediately re-derive `G.SG` from the just-reset
point's own along-tube coordinate but the OLD (unreset) `C.sgStand`, silently
re-imposing whatever standoff the mount had before, not 55; unlocked, nothing
touched `C.sgStand`/`C.spStand` or the boxes at all — `resetPoints` never called
`fillCfg()`, only `fillPoints()`. Fixed by resetting `C.sgStand`/`spStand` and
`sgLock`/`spLock` to `DEF.cfg` first — matching how `resetGeom` already resets
`a2cAuto` alongside `a2c` — then placing `G.SG`/`G.SP` with the same `onTube`
call the lock glue itself uses, at the default standoff. That makes the result
correct and idempotent whichever way the lock ends up: locked, `recompute()`
re-derives the identical point (a no-op); unlocked, its readout branch reads
the standoff back off a point already sitting at exactly 55mm, instead of
`DEF.geom.SG`'s own real 67.1mm.

**The eye-to-eye length lock (`lockLen`) and the down-tube standoff lock
(`sgLock`) both claim `G.SG`, and used to fight over it mid-drag.** Dragging
`SE` or `SG` with `lockLen` on (the default) repositions the *other* one to
hold `C.eye` — correct in isolation, verified by hand: fresh `unit()` vector
every event, no stale caching, exact eye-to-eye immediately after it runs. But
with `sgLock` also on (also the default), `recompute()` runs a frame later on
the very same drag event and unconditionally snaps `G.SG` back onto the down
tube at the fixed `C.sgStand`, discarding whatever the length-lock block just
set — the mount-lock always wins because it runs last, and neither mechanism
knows the other exists. Dragging `SE` therefore moved `SG` to a length-correct
position that recompute() immediately un-did; dragging `SG` had the mirror
problem, anchoring `SE` on the raw pre-recompute position rather than where
`SG` was actually about to end up. Either way "Shock eye to eye, drawn" came
out wrong, generically longer, while the *other* point had visibly moved —
exactly the reported symptom.

Fixed by solving the two constraints together instead of independently, inside
the same `lockLen` block, whenever `sgLock` is also on:
- **Dragging `SG`** only ever slides it along the tube anyway (that's the lock
  working as designed), so the fix snaps `G.SG` there itself —
  `onTube(dtU, alongOf(dtU,G.SG), C.sgStand)` — before repositioning `SE`, so
  the eye length is held against where `SG` will actually end up, not a
  position `recompute()` is about to throw away.
- **Dragging `SE`** leaves `SG` with exactly one degree of freedom (where along
  the tube), so the fix intersects the tube's offset line with the circle of
  radius `C.eye` around the new `SE` — a small quadratic in the along-tube
  parameter `t`, using the identity `onTube(u,t,off) = onTube(u,0,off) + t·u`
  (affine in `t` for fixed `off`) and `dtU·dtU=1`. Two roots, one, or none
  depending on whether the eye can reach the tube from there at all; the root
  nearer the current `G.SG` is kept, so the mount doesn't flip to the far side
  mid-drag. No solution (the eye genuinely can't reach) leaves `SG` where it
  was rather than guess — `recompute()` still holds it on the tube, just not
  yet at the requested length until the drag comes back in range.

Either branch lands `G.SG` exactly on the offset line at exactly the along-tube
coordinate it already has, which is precisely what `recompute()`'s own glue
would compute from it — `alongOf(u, onTube(u,t,off)) === t` for unit `u`,
regardless of `off` — so the glue's later pass becomes a no-op confirmation
instead of a clobber. Nothing in `recompute()` itself changed; the drag handler
just stopped handing it a position it was always going to override. Verified
with `sgLock` on dragging both `SE` and `SG`: eye-to-eye holds to within
rounding and the mount stays exactly on the tube in both cases. The unlocked
path (`sgLock` off) is untouched — there was nothing for it to fight, and it
already worked.

### Locks, as icon buttons

Three different locks in this tool now share one small glyph-only button
(`.iconbtn`, 22×22, no colour rules of its own — it inherits the base
`button`/`button[aria-pressed=true]` recolour every other button already
uses) — 🔓 unlocked, 🔒 locked, `aria-pressed` carrying the real state either
way. They are NOT the same lock, deliberately, even the two that land on the
same point (SG/SP):

- **`sgLock`/`spLock`** (down-tube standoff, "Down tube mounts" panel) —
  **locked by default.** Real `C` fields `recompute()`'s mount-lock glue reads
  directly (`index.html:949`) — the button is a reskin, not a new mechanism.
  They used to be separate checkbox `<label>` rows above their standoff field;
  now the button sits inside that field's own row instead, and `fillCfg()`'s
  generic `Object.keys(C)` loop explicitly skips `sgLock`/`spLock`
  (`index.html:2083`) since they're `<button>`s now, not
  `<input type=checkbox>` — a `<button>`'s `.type` is never `'checkbox'`, so
  without that exclusion they'd fall into the generic value/`onchange` branch
  and try to read `.value` off a button. They get their own tiny wiring block
  right after that loop instead, doing exactly what the checkbox's `onchange`
  used to: flip `C[k]`, repaint the glyph, run
  `syncGeom(k); refreshDerived(); fillPoints(); recompute(false);`.
  `sgStand`/`spStand` themselves are excluded from `fillCfg()`'s generic loop
  too now, alongside the lock buttons — see "Standoffs" above for why they
  need their own `onchange` rather than the generic one.
- **`lockLen`** (shock eye to eye) — **locked by default**, moved from a
  toolbar text button to an icon next to `eye` (see "Lock shock" above).
- **The points-panel lock, `ptLocked`** — new, **unlocked by default**, on
  every row (`PTS`: MP/SP/LP/SE/SG/AX/ID, no exceptions — including SG/SP,
  which already have the standoff lock above, in a different panel, doing a
  different job). This one is a plain UI convenience — "don't let this point
  get dragged or typed into by accident" — not a physical constraint, so it's
  a bare runtime object (`let ptLocked={}`, unset = unlocked) next to
  `showWheels` etc., not part of `C`/`G`: not exported, not imported, not
  touched by any reset button, exactly like every other `show*` flag already
  isn't. Enforced in two places: the pointerdown handler checks
  `ptLocked[k]` before it will set `dragKey` (`index.html:1613`, right
  where `k` is resolved from the hit target — this also covers a locked
  point's "ghost" marker off top-out, since a ghost shares the same
  `dataset.key`), and the row's own click handler sets `.disabled` on both
  number inputs directly rather than routing back through `fillPoints()`'s
  full render path.

### Clearance

A pivot is a boss, not a point: `pivotOD` (22mm) gives it a body, and clearance to a
tube is `segDist(centre,a,b) - tubeOD/2 - pivotOD/2`, so negative means the boss is
inside the tube. Checked for the pivots that are frame features — MP, SP, SG, and
the idler when frame-mounted. The readout runs before the no-solution bail-out in
`readouts()`, because it is pure frame geometry and is most wanted precisely when
the linkage will not solve.

**A negative number here is known, and real, not a bug to chase.** The tool
flags an overlap rather than hiding it — either the pivot moves or the housing
has to interrupt the tube. It reads about -1mm off the seat tube at the current
shipped defaults, close enough to flush that it is more a reminder to check
than a finding; it read -15mm against the geometry shipped before that (a much
lower main pivot), so the number moves with whatever design DEF holds and is
worth a fresh look after any change there.

**`DEF` is a real, saved design, not a made-up placeholder.** It has been swapped
wholesale more than once for a design worth shipping as the thing people see on
first open — most recently for "lowpivot mx", `rw`/`fw` a genuine 584/622 mullet,
idler off by default, every pivot a solved coordinate rather than something
round-tripped through 1dp. `resetGeom`, `resetPoints` and the whole-tool reset
all read straight from it, so swapping it is enough — no coordinate elsewhere
needs hand-updating, and `test/flexstay-tests.mjs` re-extracts `DEF` from the
source at test time rather than pinning old numbers, so the shipped-defaults
checks (below) track it automatically.

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

**The front axle's path lives in the chart strip, not this overlay.** First
tried adding it here too, on the same `axpath` toggle — worked, but the request
was actually for a side-by-side comparison chart, which this overlay is the
wrong shape for: it is drawn over the bike at whatever geometry is currently
being edited, sharing the drawing's own scale and origin, and a straight
~140mm fork line next to a tight rear arc reads very differently stretched
across that than it does on its own axes. `chartAxlePath` (in the charts
section below) is the dedicated version instead.

## The toolbar: two fixed rows, not one that wraps

`#bar` used to be a single flex container with `flex-wrap:wrap`, and
`fitBar()` measured the whole thing to decide whether it fit on one line
(space-between) or had wrapped (plain left-aligned). That meant which row a
button ended up on was never actually fixed — it fell out of how much total
width everything needed that moment. Reported as a real bug, not a hypothetical:
the Cycle/Stop button changes width with its own label (`Cycle suspension` vs
`Stop`), so pressing it changed how much space row one needed, which changed
how many buttons fit before the wrap point, which moved a button from the
second row up onto the first — the layout reshuffled itself from user input
that had nothing to do with layout.

Fixed by making the two rows actually two boxes: `#bar` is now
`flex-direction:column` holding two `.barrow` children (`#bar-top`,
`#bar-bottom`), each its own `flex-wrap:nowrap` flex container. A button
changing width can make its own row tighter or looser, but it cannot move a
button onto the *other* row — there is no shared wrap point between them
anymore, because there is no shared flex context between them anymore.
`fitBar()` toggles `.spread` (the space-between styling) per row instead of
once for the whole bar, so a row that doesn't fit its own width falls back to
left-aligned for itself without affecting the other row's judgement. On a
desktop-width window too narrow for the bottom row's full button count, that
row now overflows/clips at its own right edge rather than wrapping — a
tradeoff, but the alternative is exactly the spillover this was fixed to
stop. The mobile breakpoint (`max-width:900px`) gets its wrapping back,
`.barrow{flex-wrap:wrap}`, since eleven buttons forced onto one unbreakable
row would just run off a phone screen.

**`#play` gets a fixed width and centred text for the same reason its own
label change caused the bug in the first place.** `width:118px;flex:none`
sized to fit "Cycle suspension" (the longer of its two labels) plus the
button's own padding — `Stop` centres inside the same box rather than
shrinking it.

Row order changed too: `Sag` and `axle path` moved from the top row into the
bottom row, ahead of `anti-squat` — the top row is now purely playback (the
Cycle/Stop button, the slider, the position label), and everything that
changes what's drawn, rather than where in the cycle it's drawn, lives on the
row below.

**`Static`, next to `Sag`, is a one-shot reset to the fully extended
position** (`posT=0`, `holdSag=false`) rather than a toggle — there is no
meaningful "un-static" state to hold, unlike `Sag`, which can be turned back
off to return to wherever the slider already was.

**Both `Sag` and `Static` now stop the animation loop, not just override what
gets drawn.** `holdSag` used to only change what `currentFrame()` reads
(`C.sag` instead of `posT`) — pressing Sag mid-animation froze the *picture*
correctly, but the `raf` loop driving `posT` back and forth every frame kept
running underneath it, so the slider kept sliding on its own even though the
bike on screen had stopped following it — exactly the reported symptom.
Fixed with a shared `stopAnimation()` (cancels `raf`, clears `playing`, resets
the button's own label) that both `Sag` and `Static` call before doing
anything else, and `Sag` additionally sets `posT=C.sag/100` so the slider
itself lands on the sag position instead of stopping wherever the animation
happened to be — "hold it at sag point" means the control that represents
position, not only the drawing, has to agree. Getting the animation moving
again needs `Cycle suspension` pressed again deliberately, same as it already
required after manually dragging the slider (which has cleared `holdSag` on
its own since before this change).

## Parts toggles

Seven buttons — wheels, drivetrain, cockpit, saddle, shock, fork, cranks — hide
one piece of artwork each, all on by default (`showWheels` etc.). They share
the bar's one toggle cluster with the overlays (axle path, anti-squat,
anti-rise, forces) rather than getting a second divider: `.partbtn` gives them
`--link` teal against the overlays' `--rear` blue, so the two kinds of pressed
button read apart by colour instead of by a text label or a second border. The
frame itself (front triangle, rear stay, shock link) is never one of them; only
bolt-on product artwork is.

**Cranks got their own toggle rather than folding into `drivetrain`** — the
opposite of what an earlier note here predicted ("a future crank image
replaces the chainring/cog rings under the drivetrain flag"). Turned out wrong
once there was an actual reason to draw one: a crank isn't just more drivetrain
artwork, it's the one piece of artwork on the whole bike that has to visibly
animate as the suspension cycles (see "Cranks" below), which is a different
enough concern from "hide the chain/rings" to earn its own flag rather than be
silently swept into an unrelated one — turning off the chainring shouldn't also
kill the one thing demonstrating pedal kickback, and vice versa.

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

## Cranks

Two arms, one toggle (`showCranks`), one shared rotation — a crank is one rigid
part, not two independent props that happen to look alike, so both arms read
the identical `f.kick` every frame and can only ever move together. No artwork
asset: `CRANK_LEN` (165mm, a plain constant next to `P`/`GDX` — there's no
config field for it, nothing asked for tuning it) plus a `line()` rod, in
`CRANK_COL` (`#45505a`, defined right after `// ==ENGINE-END==` since it's a
drawing colour, not geometry) — deliberately darker than the shared `#5c6b78`
used elsewhere for the chainring/cog/BB dot, so the crank reads as its own
part rather than more drivetrain metal, even sitting right next to it.

**No pedal-body circle any more.** The original draw also stamped a filled
circle at the pedal end (`r:30,fill:'#5c6b78'`) to suggest a pedal; it read as
an oversized grey blob rather than a pedal, so it's gone. `line()` already
defaults to `'stroke-linecap':'round'` (see "Tube shape" below), so the rod
alone already draws as a capsule — a rectangle with rounded ends — with
nothing extra needed at either end.

**The BB axle dot is drawn once, inside the visible-arm block, not the BB
shell.** A small light circle, `r:12` (24mm diameter) at `(0,0)`, `#cfd5da`
(the same light metal tone as the fork stanchion outline / seatpost fill
family) — concentric with the BB, standing in for the axle spindle the
cranks actually turn on. It belongs to the *crank's* paint order, not the
frame's: added inside the visible (drive-side) arm's `if(showCranks)` block,
which is the crank's own last, top-most draw call, so it lands above both
crank rods and the (separate, frame-coloured) BB shell dot beneath them —
and, living inside `if(showCranks)`, disappears along with the rest of the
crank artwork when the toggle is off rather than becoming a permanent BB
feature.

**`crankPedal(sign)`**, defined once right after `const BB={x:0,y:0}` near the
top of `draw()` (so both draw sites below can share it), is the whole feature:
```js
const crankPedal=sign=>rot({x:sign*CRANK_LEN,y:0}, BB, (f.kick||0)*Math.PI/180);
```
`sign=1` is the arm resting at 0° (pointing +x, "the front of the bike," i.e.
"right"); `sign=-1` is the same arm 180° round, "left." `rot()` (already used
for the swingarm idler) does the actual rotating — this needed no new geometry,
just handing it the one number (`f.kick`) that was already being computed and
already meant "how far the pedal has been forced round from top-out."

**Painted in two places, not layered with `z-index` or anything clever,**
because paint order already does the job:
- The **far-side (hidden) arm** is drawn *first* in this whole region of
  `draw()` — before even the chainring — so the chainring ring, the frame
  tubes and the BB shell, all painted afterward, correctly occlude it exactly
  where they cross it, the same way a real photo's far-side arm disappears
  behind the chainring and the down tube but is still visible in the gaps.
  It is not invisible outright — nothing asked for that, and a real one isn't
  either — only *behind* everything real bike parts would actually be behind.
- The **near-side (visible) arm** is drawn *last*, right after the saddle,
  on the same "drawn last so it sits on top of everything" precedent the
  saddle itself already uses.

Both draw calls are otherwise identical (`line(BB,pedal,'#5c6b78',14)` plus a
`r:10` pedal-body circle) — only which `crankPedal` sign, and where in the
function the two calls sit, differ.

**Sign convention, checked, not just derived.** `kick` is positive when the
top-run chain path *grows* on compression (`sweep()`), which is what forces the
pedals backward against normal pedaling — that's the whole phenomenon. In this
tool's x-forward/y-up frame, rolling forward is clockwise (a wheel or crank
spinning clockwise is what makes the bike move in +x), so forward pedaling is a
negative angle in `rot()`'s CCW-positive convention, and kickback — backward
against that — is positive. `crankPedal` uses `+kick` with no negation, which
falls out of that reasoning, but this is exactly the kind of thing worth
actually looking at rather than trusting the algebra: rendered at top-out
(0°, dead horizontal) against full travel (~17° at the shipped default), and
confirmed the pedal end visibly swings up and back — counter-clockwise, away
from the forward-pedaling direction — as the suspension compresses, not
forward with it.

`(f.kick||0)` is the same "never blank the canvas" guard as everywhere else in
`draw()` — `topFrame()`'s `kick:null` (no valid sweep) rests the crank
horizontal instead of throwing.

**Sized against the chainstay, not picked freestanding.** `CRANK_LEN` is
165mm (a real crank length); the rod itself is drawn at width 42 — a hair over
the chainstay's own 40 (`line(G.MP,f.AX,...)`'s outline width), since that's
the nearest real reference for "how thick does a tube this size actually
read." (The pedal-body circle that originally scaled alongside it, `r:30`, is
gone — see "No pedal-body circle any more," above.)

## Lock shock

`lockLen` defaults **on**. Eye to eye is a real product spec, not a free variable,
so dragging one shock mount moves the frame around a fixed shock length by
default rather than silently stretching it; the drag handler already had this
logic (it moves the far eye to hold `C.eye`), it just used to start disarmed.

**Its own toolbar button is gone — `#locklen` now sits next to the `eye`
field it actually governs**, as the same small icon button the pivot and
down-tube locks use (see "Locks, as icon buttons" below). Same `id`, same
click handler (with a glyph/`title` flip added), same `C`-level reader in the
drag handler (`index.html:1637-1657`) — moving it was a location and
appearance change only, not a behaviour change.

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

**The exposed seatpost's square base is not a re-run of that experiment** —
it's a single bolt-on part (`tubes([[seatTop,post,26,'butt']],...)`, same
system as the fork stanchion above, not the frame-tube system this section is
about), one segment, one joint, not a multi-tube convergence, so none of the
seam problems above apply. `'butt'` squares both ends of that one line
(`stroke-linecap` is one value per element — confirmed against the current
`tubes()`/`line()`, `index.html:1179-1181, 1202-1205`), which is exactly
right for the frame-meeting end (`seatTop`) but wrong for the exposed/saddle
end (`post`), so the round look there is put back the same way the BB shell
caps a square-cut frame tube end: two concentric flat circles, outline colour
then fill colour, radii matching the tube's own outline/fill widths (13 and
9.5, for a 26mm outline / 19mm fill post).

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

(The engine's own name for this, `kick`, is unchanged — only the UI label moved,
from "Chain shortening" to "Pedal kickback": what the number measures is chain
growth turning into a rotation forced onto the pedals through a fixed-length
chain, and that is the name riders actually know it by.)

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

**The exposed stanchion's width (36mm) is a standalone number, with nothing
else riding on it.** It's a plain `tubes()` stroke width on the procedurally
drawn segment above — checked `FORKART`'s own placement matrix
(`index.html:981-985, 1285-1291`): fixed `mmPerPx` scale, built purely from
the fork axle/crown anchors, no reference anywhere to the stanchion line's
width. Changing it is cosmetic only, same as it always was — it just used to
read thinner (27) than the real stock it's meant to represent.

**The exposed stanchion has to satisfy two constraints that took three tries
to get right together: parallel to the head tube, AND landing on the real
drawn casting, not just one of the two.** The stanchion is `tubes()`-drawn
between two points, `crown` (top, invented — there's no crown in the fork
lowers artwork, only the lower assembly) and `topW` (bottom, where it meets
the lower casting). Both have to sit on the same line for the drawn stanchion
to be straight and correctly placed at all.

1. **First attempt fixed only `crown`.** It used to be `raceSeat + axis*46` —
   on the *steerer* axis, no `perp*offset` rake term at all — while `topW` was
   already on the *fork's* axis (`FA`'s line, offset sideways by `C.offset`
   plus the casting artwork's own lateral jog, below). Those only coincide at
   `offset=0`, so the shipped default (`offset:44`) already kinked the drawn
   stanchion. Giving `crown` the same `perp*offset` term as `FA` made the two
   *parallel* — but not yet collinear, because `topW` carried an extra term
   `crown` still didn't.
2. **Second attempt over-corrected by deleting that extra term from `topW`
   instead of adding it to `crown`.** `topW` was built from *both* components
   of `K.top-K.axle`, the fork-lowers artwork's own pixel anchors, which sit
   ~80px apart laterally (74.5 vs 154.4px) — that looked like measurement
   noise unrelated to rake, so it got dropped, leaving `topW=FA + fu*(axial
   distance only)`. That made the segment parallel to the head tube (checked:
   exactly 0° at every offset and compression tried) — but wrong, because it
   is now on the *axle's* line, not the *leg's* line. Opened
   `img/fork-lowers.png` and measured it directly (Python/Pillow, scanning
   non-transparent pixel spans per row): the plain leg tube the stanchion
   telescopes into sits at a rock-steady x≈75px from row 400 to row 800, then
   the casting flares out to a dropout/axle boss centred at x≈154 by row 895
   — the same ~80px gap the "noise" theory had just deleted. Real forks often
   do exactly this (the dropout doesn't sit on the leg's own centreline), and
   this artwork draws it deliberately, so removing it was actively wrong, not
   just incomplete — it made the stanchion parallel to the head tube while
   visibly missing the actual blue casting drawn beside it.
3. **Fixed by keeping `topW` on the artwork's true line and moving `crown` to
   match, instead of the other way round.** `topW` is now exactly the affine
   map `fm` already places the rest of the casting image with —
   `world = FA + ks*mir*fp*(imgX−K.axle[0]) + ks*fu*(imgY−K.axle[1])` —
   evaluated at `K.top`, no terms dropped. `crown`, since it isn't part of the
   artwork, is placed by hand on that same line instead: `raceSeat +
   fp*(C.offset+jog) + fu*46`, where `jog = mir*ks*(K.top[0]-K.axle[0])` is
   the casting's own lateral offset carried over explicitly rather than
   assumed away. Both points are now `raceSeat + fp*(C.offset+jog) + fu*t`
   for their own `t` — collinear by construction, whatever `C.offset`, the
   casting's `jog`, or compression happen to be, which is what keeps the
   drawn stanchion parallel to the head tube *and* landing on the actual blue
   casting together, rather than trading one for the other.

`frame()` no longer returns `crown` at all: it depends on `FORKART`'s pixel
calibration, which is a drawing concern, not a geometry one, so it's computed
in `draw()` where `K`/`mir`/`fu`/`fp` already live — the same separation
`frontTriangle()` already keeps between tube geometry and how it's drawn.
`raceSeat` **is** still returned from `frame()` (it was a local before): it's
pure geometry, used by both the stanchion/crown line above and the
lower-headset block below.

Verified by reading the live SVG rather than re-deriving the maths a third
time to check it: took the actual `<line>` the stanchion renders as and the
actual `matrix(...)` transform on the fork-lowers `<image>`, mapped `K.top`
through that real matrix, and measured its distance to the real drawn line —
~0.00005mm at offset 0/44/90, i.e. exactly on it, to floating-point precision.

**Two new hardware blocks, both drawn as `tubes()` segments with a `'butt'`
cap so they come out as literal rectangles, not tubes** — the same technique
the square-cut front-triangle tubes already use, not a new drawing primitive:
- The **fork crown**, `showFork`-gated, centred on `crown` (above) and drawn
  *after* the exposed stanchion so it caps the stanchion's top the way a real
  crown casting does. 48mm wide against the stanchion's 36mm — "slightly
  bigger diameter" — and in line with the stanchion because it's built from
  the same `fu` (=`F.axis`) the stanchion and fork-lower artwork already use.
  `crown` sits 21mm up the axis from `raceSeat` — exactly the block's own
  half-height (it spans `crown ± 21` along `fu`) — so its near edge lands
  right at `raceSeat`, closing the block against the lower headset below
  instead of floating clear of it with the stanchion showing through the
  gap. (It was 46mm before this: correct for keeping `crown` and `topW`
  collinear, but far enough from `raceSeat` to leave a visible gap once the
  block itself existed to show it.) The height (42mm) doesn't change, only
  where it sits along the line.
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

`CHAINCAL` in the engine is a 9.8mm fudge calibrating the simplified chain wrap
model so a nominal chain count lands mid-range on this bike. It does not affect
how far the cage swings, which is what the drawing depends on.

## Not done

- Beam solve for the stay, letting it find its own deflected shape rather than
  being handed a curvature distribution. Would settle the end-condition question.
- Chain wrap geometry done properly — real tangents and arc angles including the
  S-wrap through the jockeys — so the link count is usable for building.
- Anti-squat referenced to the fully extended front axle rather than the current
  one. Linkage recalculates it; this does not.
- Save/load and URL sharing.
