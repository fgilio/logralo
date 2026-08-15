#!/usr/bin/env bash
# Rasterises the PWA icon and splash set, and the share card's flame, from the
# SVG sources in resources/branding/. Re-run after changing the mark; the PNGs
# are committed so a deploy never needs a rasteriser.
#
# Requires: rsvg-convert (brew install librsvg).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if ! command -v rsvg-convert >/dev/null 2>&1; then
    echo "error: rsvg-convert not found (brew install librsvg)" >&2
    exit 69
fi

mkdir -p public/icons public/splash resources/images

rsvg-convert -w 192 -h 192 resources/branding/icon.svg -o public/icons/icon-192.png
rsvg-convert -w 512 -h 512 resources/branding/icon.svg -o public/icons/icon-512.png
rsvg-convert -w 512 -h 512 resources/branding/maskable.svg -o public/icons/maskable-512.png
rsvg-convert -w 180 -h 180 resources/branding/icon.svg -o public/icons/apple-touch-icon-180.png

# The share card's flame — the mark with no ground behind it, and the one PNG
# here that is not served to a browser: ShareCardComposer draws it after the
# streak, because GD can neither rasterise an SVG nor draw a colour emoji. Both
# dimensions are given so a re-run cannot round the aspect differently and
# quietly resize the flame on every card.
rsvg-convert -w 117 -h 128 resources/branding/flame.svg -o resources/images/flame.png

# iOS shows a blank screen while launching unless a startup image matches the
# device exactly, so one PNG per screen class.
for size in 1290x2796 1179x2556 1170x2532 1125x2436; do
    width=${size%x*}
    height=${size#*x}
    # No --keep-aspect-ratio: every iPhone class here is within 0.2% of the
    # source ratio, so stretching to the exact pixel size is invisible and
    # avoids letterboxing.
    rsvg-convert -w "$width" -h "$height" \
        resources/branding/splash.svg -o "public/splash/${size}.png"
done

echo "wrote public/icons/*.png, public/splash/*.png and resources/images/flame.png"
