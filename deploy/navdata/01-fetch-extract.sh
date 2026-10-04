#!/usr/bin/env bash
# SHARI NAV data, step 1: tools + OpenStreetMap source data -> one regional extract.
#
#   nice -n 19 ionice -c3 bash tools/navdata/01-fetch-extract.sh
#
# Everything lives under /srv/shg-nav (outside the webroot, so a code deploy
# never touches it). Re-running refreshes the OSM data (Geofabrik updates daily).
#
# Region = where our passengers walk/ride to a bus: all of Gujarat (every
# pickup town + office) and the Nepalgunj / Kohalpur / Rupaidiha border area.
set -euo pipefail

BASE=/srv/shg-nav
SRC=$BASE/src
mkdir -p "$SRC" "$BASE/work"

export DEBIAN_FRONTEND=noninteractive
if ! command -v osmium >/dev/null || ! command -v tilemaker >/dev/null; then
  apt-get update -qq
  apt-get install -y -qq osmium-tool tilemaker unzip >/dev/null
fi

fetch() { # $1 = geofabrik path without -latest.osm.pbf
  local out="$SRC/$(basename "$1").osm.pbf"
  curl -sSfL --retry 3 -o "$out.part" "https://download.geofabrik.de/$1-latest.osm.pbf"
  mv "$out.part" "$out"
  echo "fetched $1 ($(du -h "$out" | cut -f1))"
}
fetch asia/india/western-zone
fetch asia/india/central-zone
fetch asia/india/northern-zone
fetch asia/nepal

# Gujarat (lon 68.0-74.6, lat 20.0-24.8) and the Nepalgunj border area.
GUJ=68.0,20.0,74.6,24.8
NPJ=81.2,27.8,82.1,28.45
cd "$BASE/work"
rm -f part-*.osm.pbf
osmium extract -s smart -b $GUJ "$SRC/western-zone.osm.pbf" -o part-guj-w.osm.pbf --overwrite
osmium extract -s smart -b $GUJ "$SRC/central-zone.osm.pbf" -o part-guj-c.osm.pbf --overwrite
osmium extract -s smart -b $GUJ "$SRC/northern-zone.osm.pbf" -o part-guj-n.osm.pbf --overwrite
osmium extract -s smart -b $NPJ "$SRC/central-zone.osm.pbf" -o part-npj-c.osm.pbf --overwrite
osmium extract -s smart -b $NPJ "$SRC/northern-zone.osm.pbf" -o part-npj-n.osm.pbf --overwrite
osmium extract -s smart -b $NPJ "$SRC/nepal.osm.pbf" -o part-npj-np.osm.pbf --overwrite
osmium merge part-*.osm.pbf -o "$BASE/region.osm.pbf" --overwrite
rm -f part-*.osm.pbf
osmium fileinfo -e "$BASE/region.osm.pbf" | grep -E "Number of (nodes|ways)|Bounding" || true
echo "region ready: $(du -h "$BASE/region.osm.pbf" | cut -f1)"
