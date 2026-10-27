#!/usr/bin/env bash
# Download the third-party browser libraries and font into public/vendor/,
# so the site loads nothing from other servers (no CDN, no Google Fonts).
# Run by .github/workflows/vendor.yml; npm verifies each package against
# the registry's published integrity hash.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/public/vendor"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

LEAFLET=1.9.4
CLUSTER=1.5.3
FONT_PKG=@fontsource/alegreya-sans
FONT="$(npm view "$FONT_PKG@5" version | tail -1 | awk '{print $NF}' | tr -d "'")"

cd "$TMP"
npm pack --silent "leaflet@$LEAFLET" "leaflet.markercluster@$CLUSTER" "$FONT_PKG@$FONT" > packed.txt
for f in $(cat packed.txt); do mkdir -p "x-$f" && tar xzf "$f" -C "x-$f"; done
L="x-leaflet-$LEAFLET.tgz/package"
C="x-leaflet.markercluster-$CLUSTER.tgz/package"
F="$(ls -d x-fontsource-alegreya-sans-*.tgz)/package"

rm -rf "$OUT" && mkdir -p "$OUT/leaflet" "$OUT/markercluster" "$OUT/fonts"
cp "$L/dist/leaflet.js" "$L/dist/leaflet.css" "$OUT/leaflet/"
cp "$L/LICENSE" "$OUT/leaflet/LICENSE.txt"
cp "$C/dist/leaflet.markercluster.js" "$C/dist/MarkerCluster.css" "$OUT/markercluster/"
cp "$C/MIT-LICENCE.txt" "$OUT/markercluster/LICENSE.txt"
for w in 400-normal 400-italic 500-normal 700-normal 800-normal; do
  cp "$F/files/alegreya-sans-latin-$w.woff2" "$OUT/fonts/"
done
cp "$F/LICENSE" "$OUT/fonts/LICENSE.txt"

{
  echo "Third-party files served by NIMBY. Regenerate with: Actions > Update vendored libraries."
  echo "leaflet $LEAFLET (BSD-2-Clause) https://leafletjs.com"
  echo "leaflet.markercluster $CLUSTER (MIT) https://github.com/Leaflet/Leaflet.markercluster"
  echo "$FONT_PKG $FONT (SIL Open Font License 1.1) Alegreya Sans by Juan Pablo del Peral, Huerta Tipografica"
  echo
  (cd "$OUT" && find . -type f ! -name VERSIONS.txt | sort | xargs sha256sum)
} > "$OUT/VERSIONS.txt"

cat "$OUT/VERSIONS.txt"
