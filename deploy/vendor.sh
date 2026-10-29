#!/usr/bin/env bash
# Download third-party code into the repository:
#  - browser libraries and font into public/vendor/, so the site loads nothing
#    from other servers (no CDN, no Google Fonts); npm verifies each package
#    against the registry's published integrity hash
#  - PHPMailer (for sending email) into lib/phpmailer/, outside the web root,
#    from the project's GitHub release
# Run by .github/workflows/vendor.yml.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/public/vendor"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

LEAFLET=1.9.4
CLUSTER=1.5.3
FONT_PKG=@fontsource/alegreya-sans
FONT=5.3.0
LIB="$ROOT/lib/phpmailer"
# Latest PHPMailer release (not a pre-release); recorded in lib/phpmailer/VERSION.txt.
PHPMAILER="$(curl -fsSL https://api.github.com/repos/PHPMailer/PHPMailer/releases/latest | python3 -c 'import json,sys; print(json.load(sys.stdin)["tag_name"])')"

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

curl -fsSL "https://github.com/PHPMailer/PHPMailer/archive/refs/tags/$PHPMAILER.tar.gz" -o phpmailer.tgz
mkdir -p x-phpmailer && tar xzf phpmailer.tgz -C x-phpmailer --strip-components=1
rm -rf "$LIB" && mkdir -p "$LIB"
cp x-phpmailer/src/PHPMailer.php x-phpmailer/src/SMTP.php x-phpmailer/src/Exception.php "$LIB/"
cp x-phpmailer/LICENSE "$LIB/LICENSE.txt"
{
  echo "PHPMailer $PHPMAILER (LGPL-2.1) https://github.com/PHPMailer/PHPMailer"
  echo "Source: https://github.com/PHPMailer/PHPMailer/archive/refs/tags/$PHPMAILER.tar.gz"
  echo "Archive sha256: $(sha256sum phpmailer.tgz | awk '{print $1}')"
  echo
  (cd "$LIB" && find . -type f ! -name VERSION.txt | sort | xargs sha256sum)
} > "$LIB/VERSION.txt"
cat "$LIB/VERSION.txt"
