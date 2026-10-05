#!/usr/bin/env bash
# SHARI NAV data, step 2: build what the navigator serves from OUR server.
#
#   bash deploy/navdata/02-build.sh [tiles|static|cover|places|valhalla|all]
#
# Run it inside the guarded unit (1 CPU, capped RAM, frozen by watchdog.sh
# whenever the website gets slow), never bare:
#
#   systemd-run --unit=shg-nav-build --collect -p CPUQuota=100% -p Nice=19 \
#     -p IOSchedulingClass=idle -p MemoryHigh=4500M -p MemoryMax=5500M \
#     -p OOMScoreAdjust=900 bash -c 'bash 02-build.sh tiles > /srv/shg-nav/log/02.log 2>&1'
#
# Needs step 1 (01-fetch-extract.sh) -> $PBF + the region's .poly files in $SRC.
# Region = all of India + Nepal (since 5 Oct 2026; was Gujarat + Nepalgunj).
#
# Output (all outside the webroot; nginx serves /navdata/ from $PUB):
#   $PUB = /srv/shg-nav/www/navdata
#   $PUB/tiles/{z}/{x}/{y}.pbf   OpenMapTiles-schema vector tiles (z0-14, gzip)
#   $PUB/fonts/<font>/<range>.pbf, $PUB/sprites/ofm*   glyphs + icons
#   $PUB/style/day.json, night.json                     map styles (own copies)
#   $PUB/meta.json                                      coverage boxes + build stamp
#   $WORK/places.tsv                                    place index -> MySQL nav_places
#   docker container $VAL_NAME on 127.0.0.1:$VAL_PORT   routing engine
#                                                       (setting nav_engine_url points at it)
# Every value below can be overridden from the environment.
set -euo pipefail

BASE=/srv/shg-nav
PUB=$BASE/www/navdata        # nginx: location ^~ /navdata/ { root /srv/shg-nav/www; }
WORK=${WORK:-$BASE/work}
SRC=${SRC:-$BASE/src}
BIN=$(cd "$(dirname "$0")" && pwd)
PBF=${PBF:-$BASE/region.osm.pbf}
BBOX=${BBOX:-68.0,6.5,97.6,37.1}               # India (with the islands) + Nepal
THREADS=${THREADS:-1}                          # 2 vCPU box: one is the website's
KEEP_OLD=${KEEP_OLD:-0}                        # 1 = keep the previous tiles in $WORK/tiles.old
VAL_NAME=${VAL_NAME:-shg-valhalla-full}
VAL_DIR=${VAL_DIR:-$BASE/valhalla-full}
VAL_PORT=${VAL_PORT:-8003}
VAL_BUILD_MEM=${VAL_BUILD_MEM:-5g}             # only while the graph is built
STAGE=${1:-all}
mkdir -p "$PUB" "$WORK"
[ -s "$PBF" ] || { echo "missing $PBF - run 01-fetch-extract.sh"; exit 1; }
log() { echo "$(date '+%F %T') $*"; }

tiles() {
  cd "$WORK"
  if [ ! -s coastline/water_polygons.shp ]; then
    curl -sSfL --retry 3 -o water.zip https://osmdata.openstreetmap.de/download/water-polygons-split-4326.zip
    rm -rf water-polygons-split-4326 && unzip -q -o water.zip && rm -f water.zip
    mkdir -p coastline && mv -f water-polygons-split-4326/* coastline/ && rmdir water-polygons-split-4326
  fi
  python3 - <<'PY'
import json
c = json.load(open('/usr/share/doc/tilemaker/examples/config-openmaptiles.json'))
for k in ('urban_areas', 'ice_shelf', 'glacier'):   # Natural Earth files we do not ship
    c['layers'].pop(k, None)
c['settings']['name'] = 'SHARI NAV'
c['settings']['description'] = 'S Hari Global own map tiles (OpenStreetMap data)'
c['settings']['filemetadata']['tiles'] = ['/navdata/tiles/{z}/{x}/{y}.pbf']
json.dump(c, open('config.json', 'w'), indent=1)
PY
  sed -e 's/^preferred_language = nil/preferred_language = "en"/' \
      -e 's/^additional_languages = { }/additional_languages = { "en", "hi", "ne", "gu" }/' \
      /usr/share/doc/tilemaker/examples/process-openmaptiles.lua > process.lua
  rm -rf tiles.new store && mkdir -p store
  log "tilemaker start ($PBF, bbox $BBOX, $THREADS thread(s))"
  /usr/bin/time -v tilemaker --input "$PBF" --output tiles.new/ --config config.json --process process.lua \
            --bbox "$BBOX" --store store/ --threads "$THREADS"
  rm -rf store
  log "tiles built: $(find tiles.new -name '*.pbf' | wc -l) files, $(du -sh tiles.new | cut -f1)"
  rm -rf "$WORK/tiles.old"
  if [ -d "$PUB/tiles" ]; then mv "$PUB/tiles" "$WORK/tiles.old"; fi
  mv tiles.new "$PUB/tiles"
  [ "$KEEP_OLD" = 1 ] || rm -rf "$WORK/tiles.old"
  cover
  log "tiles live: $(du -sh "$PUB/tiles" | cut -f1)"
}

cover() {
  # meta.json coverage = boxes of whole tiles inside the region's .poly files
  # (navcover.py); 15-nav.js asks our tiles first only inside them.
  ls "$SRC"/*.poly >/dev/null 2>&1 || { echo "no .poly in $SRC - coverage unchanged"; return 0; }
  python3 "$BIN/navcover.py" "$SRC"/*.poly > "$WORK/coverage.json"
  python3 - "$PUB/meta.json" "$WORK/coverage.json" <<'PY'
import json, os, sys, time
p = sys.argv[1]
m = json.load(open(p)) if os.path.exists(p) else {'maxzoom': 14}
m['coverage'] = json.load(open(sys.argv[2]))
m['built'] = time.strftime('%Y-%m-%d %H:%M')
json.dump(m, open(p + '.tmp', 'w'), separators=(',', ':'))
os.replace(p + '.tmp', p)
PY
  log "coverage: $(python3 -c "import json,sys;print(len(json.load(open(sys.argv[1]))['coverage']))" "$PUB/meta.json") boxes"
}

static() {
  # Fonts + sprites copied ONCE from OpenFreeMap (same OpenMapTiles schema),
  # then served by us. Styles are rewritten by navstyle.py to our own URLs.
  cd "$WORK"
  mkdir -p "$PUB/sprites" "$PUB/style"
  curl -sSfL -o liberty.json https://tiles.openfreemap.org/styles/liberty
  curl -sSfL -o dark.json https://tiles.openfreemap.org/styles/dark
  SPRITE=$(python3 -c "import json;print(json.load(open('liberty.json'))['sprite'])")
  for s in "" "@2x"; do
    for e in json png; do curl -sSfL -o "$PUB/sprites/ofm$s.$e" "$SPRITE$s.$e"; done
  done
  DSPRITE=$(python3 -c "import json;s=json.load(open('dark.json')).get('sprite','');print(s if isinstance(s,str) else '')")
  if [ -n "$DSPRITE" ] && [ "$DSPRITE" != "$SPRITE" ]; then
    for s in "" "@2x"; do
      for e in json png; do curl -sSfL -o "$PUB/sprites/dark$s.$e" "$DSPRITE$s.$e"; done
    done
  fi
  python3 "$BIN/navstyle.py" fonts liberty.json dark.json > fonts.txt
  while IFS= read -r font; do
    d="$PUB/fonts/$font"; mkdir -p "$d"
    enc=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$font")
    for i in $(seq 0 255); do
      r="$((i*256))-$((i*256+255))"
      [ -s "$d/$r.pbf" ] || curl -sSfL --retry 2 -o "$d/$r.pbf" "https://tiles.openfreemap.org/fonts/$enc/$r.pbf" || rm -f "$d/$r.pbf"
    done
  done < fonts.txt
  # store glyphs plain (nginx adds no Content-Encoding for fonts)
  find "$PUB/fonts" -name '*.pbf' | while IFS= read -r f; do
    if [ "$(od -An -tx1 -N2 "$f" | tr -d ' ')" = "1f8b" ]; then gunzip -c "$f" > "$f.tmp" && mv "$f.tmp" "$f"; fi
  done
  python3 "$BIN/navstyle.py" style liberty.json "$PUB/style/day.json" ofm
  if [ -s "$PUB/sprites/dark.png" ]; then SP=dark; else SP=ofm; fi
  python3 "$BIN/navstyle.py" style dark.json "$PUB/style/night.json" $SP
  FALLBACK=$(curl -sSfL https://tiles.openfreemap.org/planet | python3 -c "import json,sys;print(json.load(sys.stdin)['tiles'][0])")
  python3 - "$PUB/meta.json" "$FALLBACK" <<'PY'
import json, os, sys, time
p = sys.argv[1]
m = json.load(open(p)) if os.path.exists(p) else {}
m.update({'built': time.strftime('%Y-%m-%d %H:%M'), 'maxzoom': 14, 'fallbackTiles': sys.argv[2]})
m.setdefault('coverage', [])
json.dump(m, open(p, 'w'), separators=(',', ':'))
PY
  echo "static: fonts $(find "$PUB/fonts" -name '*.pbf' | wc -l), styles ok"
}

places() {
  cd "$WORK"
  log "places: filter"
  osmium tags-filter "$PBF" \
    nwr/amenity nwr/shop nwr/tourism nwr/office nwr/healthcare nwr/leisure nwr/historic \
    nwr/place nwr/railway=station,halt nwr/highway=bus_stop nwr/public_transport=station \
    w/highway=primary,secondary,tertiary,residential,unclassified,trunk,living_street \
    -o poi-all.osm.pbf --overwrite
  # only named objects (and the nodes/ways they are built from): most Indian
  # residential streets have no name, and navplaces.py skips those anyway
  osmium tags-filter poi-all.osm.pbf nwr/name nwr/name:en -o poi.osm.pbf --overwrite
  rm -f poi-all.osm.pbf
  log "places: export"
  osmium export poi.osm.pbf -f geojsonseq -o poi.geojsonseq --overwrite \
    -a type,id --geometry-types=point,linestring,polygon
  python3 "$BIN/navplaces.py" poi.geojsonseq places.tsv.new
  mv places.tsv.new places.tsv
  rm -f poi.osm.pbf poi.geojsonseq
  log "places: $(wc -l < places.tsv) rows -> import with import-places.php"
}

valhalla() {
  # Builds the graph in its OWN dir + container + port, so the engine in use
  # keeps answering; switch the setting nav_engine_url once the new one routes.
  mkdir -p "$VAL_DIR"
  cp -f "$PBF" "$VAL_DIR/region.osm.pbf"
  docker rm -f "$VAL_NAME" >/dev/null 2>&1 || true
  rm -rf "$VAL_DIR/valhalla_tiles" "$VAL_DIR/valhalla_tiles.tar" \
         "$VAL_DIR/file_hashes.txt" "$VAL_DIR/duplicateways.txt"
  docker run -d --name "$VAL_NAME" --restart unless-stopped \
    -p 127.0.0.1:$VAL_PORT:8002 -v "$VAL_DIR:/custom_files" \
    -e serve_tiles=True -e build_tar=True -e build_admins=True -e build_time_zones=True \
    -e build_elevation=False -e use_tiles_ignore_pbf=True -e force_rebuild=False \
    -e server_threads=1 --memory="$VAL_BUILD_MEM" --memory-swap=12g --cpus=1 --cpu-shares=128 \
    --oom-score-adj=900 ghcr.io/valhalla/valhalla-scripted:latest
  log "$VAL_NAME started on 127.0.0.1:$VAL_PORT (first start builds the graph; watch: docker logs -f $VAL_NAME)"
  log "after it routes: docker update --memory=2g --memory-swap=4g $VAL_NAME"
}

case "$STAGE" in
  tiles) tiles ;; static) static ;; cover) cover ;; places) places ;; valhalla) valhalla ;;
  all) static; places; tiles; valhalla ;;
  *) echo "usage: $0 [tiles|static|cover|places|valhalla|all]"; exit 2 ;;
esac
chown -R www-data:www-data "$PUB" 2>/dev/null || true
