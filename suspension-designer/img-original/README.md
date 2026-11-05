# Original artwork (before compression)

Untouched copies of `img/*.png` and `img/dark/*.png` as they were before the
2026-11-06 size reduction. The page never loads anything from this folder.

The live files were reduced to 256-colour palettes with libimagequant
(quality 70-95, dithering 0.6), and the two `wheel.png` files were also scaled
from 1390 px to 1024 px wide. The page draws every image at its original pixel
size (the `w`/`h` in the `*ART` constants), so a smaller file just stretches
to fit: no constant or anchor needed to change.

To revert, copy these back over the live files:

    cp img-original/*.png img/ && cp img-original/dark/*.png img/dark/
