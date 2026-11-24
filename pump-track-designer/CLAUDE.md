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

- **Track** = a polygon of corner nodes, each corner filleted with a true
  circular arc of the node's radius (`buildTrack`). Straights are "legs":
  leg N runs from node N to node N+1. A loop has as many legs as nodes; a
  line has one fewer and its end nodes have no arc.
- **A berm is a corner**, not an object. `node.berm` is `berm`, `flat` or
  `auto` (banked above 35° of turn). `bh` is berm height, `rh` a roller in
  the middle of the berm (0 = none). Changing the radius changes the plan.
- Radii too big for their straights are shrunk to fit (`tight`), reported
  as an issue, and the handle is outlined red. Nothing jams.
- **Features** sit on legs: `{type, leg, t, p}`. `t` is the fraction of the
  leg where the feature's centre is, so stretching a straight spaces its
  features with it. `insertNode`/`removeNode` remap `t` so nothing moves on
  the ground when a corner is added or removed (tested).
- Each feature type in `FEATURES` has a param list (with ranges) and a
  `build()` that returns profile segments along its own axis. Segment kind
  drives colour everywhere: `up` amber, `down` green, `flat` pale, `gap`
  near-black (faces you fly over), `wood` brown. `flare` widens the plan
  footprint (whale tail).
- `zAt` is the centreline height: the max of the feature profiles, plus berm
  rollers. Berm banking is lateral and is not in the centreline profile.
- Dirt is a rough builder's quantity: feature body across the width plus
  1:1 batters, berms as a bank rising to `bh` with a 1:1 back. Labelled
  "rough" on purpose.

## Defaults and research

Sizes come from common build guidance, not a standard (there isn't one):
roller crest spacing about 10× height (UCI pump track spec: 0.3–0.8 m
rollers, 3–10 m apart), berm radius about 3 m for a rideable 180 (bigger than
~3.7 m gets hard to pump), lips 25–45°, landings 25–30°. Whale tail is a
timber kicker that fans to 2–3× its takeoff width. Boner log is a flat
timber deck jutting off a hill: it sends you out, not up, so its `kick` is
small. S/M/L/XL scale every length and height of the default; angles stay.

## Files in and out

All read by the one **Open** button (by extension, then by first character).

- **JSON** design file: `{_meta:{name,designer,date,version,tool}, geom, cfg}`,
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

A small Illustrator-style panel on the plan, top of the list draws on top:
**GPX trace**, **Design**, **Photo**. Each has an eye toggle; visibility is
saved in `cfg.layers` and respected by the build sheet.

- **GPX trace** (`cfg.gpx = {name, pts:[{lat,lon}]}`) is a reference line,
  not the design. It is stored as lat/lon so it is fixed to the ground at true
  scale; only the site location (`cfg.geo`) decides where it lands. It cannot
  be dragged. If no site location is set when it loads, `anchorGeo` sets one
  so the trace's middle sits over the track; from then on it is fixed. Traces
  over 3000 points are thinned to 8 cm (RDP). Loading a GPX with **Open**
  still replaces the design; the layer's **Load** never does.
- It draws above the track surface (so you can see the design against it)
  and below handles and labels (so editing is never blocked).
- Hiding **Design** also stops it being edited: no hit tests, no drops.
- **Photo** is the old site-photo feature; its toolbar button moved here.

Autosave writes the same JSON to `localStorage` (key
`creature-pump-track-v1`), without the photo if quota is short.

## UI notes

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
