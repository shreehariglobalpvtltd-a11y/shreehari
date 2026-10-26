#!/usr/bin/env bash
# SHARI NAV data, step 1: tools + OpenStreetMap source data -> one region file.
#
#   systemd-run --unit=shg-nav-fetch --collect -p Nice=19 -p IOSchedulingClass=idle \
#     -p CPUQuota=80% -p MemoryMax=3G bash -c 'bash 01-fetch-extract.sh > /srv/shg-nav/log/01.log 2>&1'
#
# Everything lives under /srv/shg-nav (outside the webroot, so a code deploy
# never touches it). Re-running refreshes the OSM data (Geofabrik updates daily).
#
# Region = all of India + all of Nepal (5 Oct 2026; before that only Gujarat
# and the Nepalgunj border area). Geofabrik cuts each country along a .poly
# a little wider than the border; the .poly files are kept next to the data
# because navcover.py turns them into meta.json's coverage boxes.
set -euo pipefail

BASE=/srv/shg-nav
SRC=${SRC:-$BASE/src}
OUT=${OUT:-$BASE/region.osm.pbf}
mkdir -p "$SRC" "$BASE/work"

export DEBIAN_FRONTEND=noninteractive
if ! command -v osmium >/dev/null || ! command -v tilemaker >/dev/null; then
  apt-get update -qq
  apt-get install -y -qq osmium-tool tilemaker unzip >/dev/null
fi

fetch() { # $1 = geofabrik path without -latest.osm.pbf
  local f="$SRC/$(basename "$1")"
  curl -sSfL --retry 5 --limit-rate 40M -o "$f.osm.pbf.part" "https://download.geofabrik.de/$1-latest.osm.pbf"
  mv "$f.osm.pbf.part" "$f.osm.pbf"
  curl -sSfL --retry 3 -o "$f.poly" "https://download.geofabrik.de/$1.poly"
  echo "$(date +%T) fetched $1 ($(du -h "$f.osm.pbf" | cut -f1))"
}
fetch asia/india     # ~1.7 GB
fetch asia/nepal     # ~0.4 GB

osmium merge "$SRC/india.osm.pbf" "$SRC/nepal.osm.pbf" -o "$OUT.part" -f pbf --overwrite
mv "$OUT.part" "$OUT"
osmium fileinfo -e "$OUT" | grep -E "Number of (nodes|ways|relations)|Bounding box:" || true
echo "$(date +%T) region ready: $(du -h "$OUT" | cut -f1)"
