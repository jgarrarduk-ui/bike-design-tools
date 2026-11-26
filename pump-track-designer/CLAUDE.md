# Pump Track Designer — working notes

Single-file browser tool for laying out a pump track or a dirt jump line.
No build step, no dependencies. `index.html` only; the wordmark comes from
`../assets/` and is dropped silently if missing, so the file also works
copied anywhere on its own (or in an iframe on a WordPress page).

## Run and test

Open `index.html`, or serve the repo root and open `/pump-track-designer/`.

```
node pump-track-designer/test/pumptrack-tests.mjs
```

Ends `all checks pass`, exits 1 on any failure. The engine sits between
`// ==ENGINE-START==` and `// ==ENGINE-END==` and has no DOM or page
globals; the test lifts it out with `new Function`. Keep it that way.

## Model

- **Track** = corner circles joined by straights (`buildTrack`). Each corner
  node `{x,y}` is the **centre** of a circle of radius `r`; `turn` is +1 if
  the track goes round it to the left, -1 right. Straights are the tangent
  lines from one circle to the next, like a belt round pulleys. Ends, hips
  and `r = 0` corners are points the track passes through. Leg N runs from
  node N to node N+1; a loop has as many legs as nodes (two round corners is
  enough: an oval), a line has one fewer.
- **Dragging a corner moves only that corner.** The other circles stay put;
  their arcs just get longer or shorter. The handle is drawn at the centre
  (the selected corner shows its circle, dashed). `turn` is remembered on the
  node (`recompute` writes it back) so a corner never flips side mid-drag,
  unless it is pulled right through and would wrap more than `FLIP_DEG`
  (290°). Tested on every template corner.
- Only overlapping circles with no tangent between them (an S-bend pushed
  together, or one circle inside a neighbour) are shrunk to fit (`tight`),
  reported, and outlined red.
- A corner added with + or a double click is a point on the straight
  (`r 0`, `rWant 4`), so nothing moves; its radius grows to `rWant` as it is
  first dragged out.
- **A berm is a corner**, not an object. `node.berm` is `berm`, `flat`,
  `auto` (banked above 35° of turn) or `hip` (see Hips below). `bh` is berm
  height, `rh` a roller in the middle of the berm (0 = none). Changing the
  radius keeps the centre, so the berm grows outwards.
- `setCornerKind` switches corner types. Round to hip moves the node to
  where the straights meet (`cornerPoint`); hip to round fits the circle back
  in (`filletCentre`). Either way the straights stay on their lines.
- **Version 2 files.** Before this, a node was the point where its straights
  met, filleted inside. `normGeom` converts anything without `v:2`
  (`toCentres`), and `mergeShared` joins two old 90° corners that share one
  circle into a single 180° corner. Templates are still written as corner
  points and converted. The CSV marks centres with `ref=centres` on the
  track row and has a `turn` column; an old CSV is converted.
- **Features** sit on legs: `{type, leg, t, p}`. `t` is the fraction of the
  leg where the feature's centre is, so stretching a straight spaces its
  features with it. `insertNode`/`removeNode` remap `t` so nothing moves on
  the ground when a corner is added or removed (tested).
- Each feature type in `FEATURES` has a param list (with ranges) and a
  `build()` that returns profile segments along its own axis. Segment kind
  drives colour everywhere: `up` amber, `down` green, `flat` pale, `gap`
  near-black (faces you fly over), `wood` brown. `flare` widens the plan
  footprint (a hip's landing).
- `zAt` is the centreline height: the max of the feature profiles, plus berm
  rollers. Berm banking is lateral and is not in the centreline profile.
- **Ground line** (`geom.ground = {z0, z1, pts}`): heights along the track,
  straight between points. `z0` at the start, `z1` at the end of a line (a
  loop ends at `z0`). A point is `{leg,t,z}` or `{corner,t,z}`, pinned like a
  feature; `insertNode`/`removeNode` keep points in place (tested).
  `buildTrack` stores the sorted line as `T.ground`; `groundAt` reads it and
  `zTop` = ground + `zAt`. Features sit on the slope, so their angles are
  from the ground. Edited in the profile strip (`gAct`: drag, tap the dashed
  line to add, hold to remove) and as start/end in the track panel. GPX
  heights, the profile CSV (`ground_m, feature_m, z_m`) and the dimensions
  CSV (`kind=ground` rows) carry it.
- Dirt is a rough builder's quantity: feature body across the width plus
  1:1 batters, berms as a bank rising to `bh` with a 1:1 back. Labelled
  "rough" on purpose.

## Lines

A design holds `D.lines = [{id, name, color, geom}]` and `D.active`. `D.geom`
is a getter/setter for the active line's track (`makeD`), so every editing
path works on one track as before; the engine is unchanged and single track.
- The other lines are drawn first, faded, in their own colours
  (`otherLines`, cached on their JSON; `drawBody` inside `drawPlan`). A tap
  on one makes it active (`setActive`). `color: null` means the theme's edge.
- The lines list sits at the top of the track panel (`syncLines`): swatch
  (`<input type=color>`), name, length, delete. `New track` adds an
  empty loop where the view is (`placeGeom`); Loop/Line switches it.
- Undo snapshots `D.lines`; which line is active is not an undo step.
- Files: `lines` and `active` in the JSON, with `geom` (the active line) kept
  for older readers; an old file opens as one line (`linesOf`). GPX writes a
  `<trk>` per line. Dimensions CSV, profile CSV and the build sheet are the
  active line, named in the file name when there are several.

## Defaults and research

Sizes come from common build guidance, not a standard (there isn't one):
roller crest spacing about 10× height (UCI pump track spec: 0.3–0.8 m
rollers, 3–10 m apart), berm radius about 3 m for a rideable 180 (bigger than
~3.7 m gets hard to pump), lips 25–45°, landings 25–30°. Wooden drop
(`woodendrop`, formerly the boner log) is a flat timber deck jutting off a
hill: it sends you out, not up, so its `kick` is small. The whale tail was
removed; old files that had one simply lose it. S/M/L/XL scale every length and height of the default; angles stay.

## Hips

A hip is a corner type (`node.berm==='hip'`, settings in `node.hip`, always
through `normHip`). It is a jump that turns the track:

- No fillet: the corner is a sharp kink. The takeoff ends on the straight in,
  the landing starts on the straight out, and the flight line cuts the
  corner. `gap` is the straight-line plan distance lip centre to knuckle
  centre, so lip and knuckle each sit `gap / (2 cos(theta/2))` from the
  corner point (`hipOf`). The turn `theta` is the corner's own angle.
- `style` is `table` (deck across the corner, one height) or `double`
  (pit between, takeoff and landing heights). `side` is +1 left, -1 right.
  `w` is the takeoff width; the landing is `w * flare`.
- `setHipTurn(geom,i,deg,side)` sets the angle by swinging the next straight
  round the hip, keeping its length (the next corner's circle moves with it). The angle slider and the Left/Right
  switch both use it; dragging the corner still works. Over 90° is a warning.
- Dropped on a corner it takes that corner's turn; dropped on a straight it
  inserts a corner there and turns 45° towards the side it was dropped on.
- `T.hips` holds each hip's position, its centreline profile (unrolled round
  the corner, so the middle is 2 x half long, not `gap`) and the lengths of
  straight it uses either side (`zones`). `findSlot` keeps features off those
  zones; `issuesOf` reports a hip that doesn't fit, overlaps, or turns past 90°.
- In plan, `drawHip` draws it; the corner handle is amber. It is saved in the
  JSON, the dimensions CSV (`corner=hip` plus `hip_style`, `hip_side`,
  `width_m` and the param columns) and as a GPX waypoint.

## Files in and out

All read by the one **Open** button (by extension, then by first character).

- **Zip bundle** (what Save writes): `design.json`, `photo/<original name>`,
  `gpx/<original name>` and `README.txt`, stored uncompressed (`packDesign`,
  `zipStore`). In the bundle the photo is a file (`cfg.bg.file`), not a data
  URL; a trace with no original file is written out from its points. Open
  reads any zip (`unzip`, `readBundle`), inflating method 8 entries with
  `DecompressionStream`. The originals are kept in `ORIG` and IndexedDB
  (`creature-pump-track` / `files`), tied to their layer by `cfg.bg.id` /
  `cfg.gpx.id`, so a reload keeps them and a different design never gets them.
- **JSON** design file (Export, "Design only"): `{_meta:{name,designer,date,version,tool}, geom, cfg}`,
  same `_meta` shape as the suspension designer. Loaded through `normGeom`,
  so an older or hand-edited file is clamped, not rejected. The site photo
  is embedded (shrunk to 2400 px JPEG).
- **GPX**: centreline every 0.5 m with heights, one waypoint per feature,
  and the design JSON inside `<extensions><ptd:design>`, so a GPX written
  here reopens exactly. Any other GPX is traced: RDP to corners, each corner
  given the radius that uses half its shorter chord. Needs a site lat/lon.
- **Dimensions CSV**: track row, one row per node, one per feature. Reads
  back what it writes; columns may be missing in a hand-made file.
- **Profile CSV**: chainage, x, y, z every 0.25 m (plus lat/lon if set).
- **Build sheet PNG**: A4 landscape at 300 dpi, always drawn in the light
  theme, with plan, dimensions, profile and legend. Drawn at 2.6× so screen
  sized text reads on paper.

## Layers

A small Illustrator-style panel, opened by the stacked-squares icon above the
zoom buttons (`setLayersOpen`); top of the list draws on top:
**Labels**, **GPX trace**, **Design**, **Photo**. **Labels** is the corner
numbers, start/end tags and + handles; hidden, they are neither drawn nor
hit-tested (a corner can still be dragged by its berm). Each has an eye toggle; visibility is
saved in `cfg.layers` and respected by the build sheet.

- **GPX trace** (`cfg.gpx = {name, pts:[{lat,lon}]}`) is a reference line,
  not the design. It is stored as lat/lon so it is fixed to the ground at true
  scale; only the site location (`cfg.geo`) decides where it lands. It cannot
  be dragged. If no site location is set when it loads, `anchorGeo` sets one
  so the trace's middle sits over the track; from then on it is fixed. Traces
  over 3000 points are thinned to 8 cm (RDP). Loading a GPX with **Open**
  still replaces the design; the layer's **Load** never does.
- Trace points keep their GPX height (`ele`). The profile strip plots the
  ones within 5 m of the active line (`traceOnTrack`) as dots, relative to
  `geo.ele`, shown with the layer's eye. No fitting: the user drags ground
  points to match. `traceDatum` sets `geo.ele` from the trace point nearest
  the line start if it was 0; `fillTraceEle` recovers heights from the kept
  original file for traces loaded before heights were stored.
- It draws above the track surface (so you can see the design against it)
  and below handles and labels (so editing is never blocked).
- Hiding **Design** also stops it being edited: no hit tests, no drops.
- **Photo** is the old site-photo feature; its toolbar button moved here.

## Problems and deleting corners

- `issuesOf` results show as a badge in the plan's top-right: red when any
  is an error (a feature that overflows or overlaps), amber when all are
  warnings (a corner shrunk to fit). It opens a floating log; an entry
  selects the item. There is no list in the side panel any more.
- Hold a corner or a feature (550 ms, 9 px tolerance on touch) and a red X
  appears next to it (`delTarget`, set by `holdToDelete`). A hip is a corner,
  so holding it deletes the corner and the hip with it. Tapping the X deletes the corner; any other tap, on
  or off the plan, clears it. Undo restores. A drag cancels the hold.
- The phone selection sheet has `overscroll-behavior: contain` so scrolling
  it never hands the gesture to the page.
- All file pickers go through `fileInput()`. On iPhone/iPad it drops the
  `accept` filter (except images): iOS greys out `.gpx` and `.csv` in Files
  because they have no system file type. Files are checked after reading.
  The input is attached to the page until used; WebKit can drop a detached
  one before `change` fires.

Autosave writes the same JSON to `localStorage` (key
`creature-pump-track-v1`), without the photo if quota is short.

## UI notes

- Chrome, type and controls match the suspension designer: Inter for words,
  JetBrains Mono for numbers, sizes 11/12/14, weights 400/600, 3 px corners.
  Templates, Open, Save, Export, undo/redo and Dims sit in the top bar after
  the title; theme and all tools on the right. Canvas controls (layers, zoom,
  fit) and the help **?** are the `.iconctl` outline set (`--icon-*` tokens).
- **?** (bottom right; at the foot of the page on a phone) opens `#help`, the
  read me. Keep it in step when behaviour changes.

- Canvas 2D, not SVG: the plan, profile, icons, template thumbnails and
  build sheet all share `drawPlan`/`drawProfile`. Only a call with
  `main:true` records hit-test handles and the profile's x mapping, so a
  thumbnail or the sheet can't corrupt the live view.
- Palette tiles: drag onto the plan, or tap to arm then tap the track
  (phone friendly). Tiles use `touch-action: pan-x` on phones so the strip
  still scrolls sideways and a vertical drag picks the tile up.
- Under 900 px the selection opens as a bottom sheet over the plan.
- Profile height is exaggerated to fit and capped at 10×; the factor is
  printed in the strip header.
- Checked in Chromium (desktop, iPhone 13 emulation). Not yet checked in
  Safari/WebKit.
