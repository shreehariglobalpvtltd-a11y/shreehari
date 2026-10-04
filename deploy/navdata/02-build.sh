#!/usr/bin/env bash
# SHARI NAV data, step 2: build what the navigator serves from OUR server.
#
#   nice -n 19 ionice -c3 bash tools/navdata/02-build.sh [tiles|static|places|valhalla|all]
#
# Needs step 1 (01-fetch-extract.sh) -> /srv/shg-nav/region.osm.pbf
#
# Output (all outside the webroot; nginx serves /navdata/ from $PUB):
#   $PUB = /srv/shg-nav/www/navdata
#   $PUB/tiles/{z}/{x}/{y}.pbf   OpenMapTiles-schema vector tiles (z0-14, gzip)
#   $PUB/fonts/<font>/<range>.pbf, $PUB/sprites/ofm*   glyphs + icons
#   $PUB/style/day.json, night.json                     map styles (own copies)
#   $PUB/meta.json                                      coverage + build stamp
#   $BASE/work/places.tsv                               place index -> MySQL nav_places
#   docker container shg-valhalla on 127.0.0.1:8002     routing engine
set -euo pipefail

BASE=/srv/shg-nav
PUB=$BASE/www/navdata        # nginx: location ^~ /navdata/ { root /srv/shg-nav/www; }
WORK=$BASE/work
BIN=$(cd "$(dirname "$0")" && pwd)
PBF=$BASE/region.osm.pbf
BBOX=68.0,20.0,82.1,28.45
STAGE=${1:-all}
mkdir -p "$PUB" "$WORK"
[ -s "$PBF" ] || { echo "missing $PBF - run 01-fetch-extract.sh"; exit 1; }

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
  tilemaker --input "$PBF" --output tiles.new/ --config config.json --process process.lua \
            --bbox "$BBOX" --store store/ --threads 2
  rm -rf store
  if [ -d "$PUB/tiles" ]; then mv "$PUB/tiles" "$WORK/tiles.old"; fi
  mv tiles.new "$PUB/tiles"
  rm -rf "$WORK/tiles.old"
  echo "tiles: $(find "$PUB/tiles" -name '*.pbf' | wc -l) files, $(du -sh "$PUB/tiles" | cut -f1)"
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
import json, sys, time
json.dump({
  'built': time.strftime('%Y-%m-%d %H:%M'),
  'maxzoom': 14,
  'coverage': [[68.0, 20.0, 74.6, 24.8], [81.2, 27.8, 82.1, 28.45]],
  'fallbackTiles': sys.argv[2],
}, open(sys.argv[1], 'w'))
PY
  echo "static: fonts $(find "$PUB/fonts" -name '*.pbf' | wc -l), styles ok"
}

places() {
  cd "$WORK"
  osmium tags-filter "$PBF" \
    nwr/amenity nwr/shop nwr/tourism nwr/office nwr/healthcare nwr/leisure nwr/historic \
    nwr/place nwr/railway=station,halt nwr/highway=bus_stop nwr/public_transport=station \
    w/highway=primary,secondary,tertiary,residential,unclassified,trunk,living_street \
    -o poi.osm.pbf --overwrite
  osmium export poi.osm.pbf -f geojsonseq -o poi.geojsonseq --overwrite \
    -a type,id --geometry-types=point,linestring,polygon
  python3 "$BIN/navplaces.py" poi.geojsonseq places.tsv
  rm -f poi.osm.pbf poi.geojsonseq
  echo "places: $(wc -l < places.tsv) rows"
}

valhalla() {
  mkdir -p "$BASE/valhalla"
  cp -f "$PBF" "$BASE/valhalla/region.osm.pbf"
  docker rm -f shg-valhalla >/dev/null 2>&1 || true
  rm -rf "$BASE/valhalla/valhalla_tiles" "$BASE/valhalla/valhalla_tiles.tar" \
         "$BASE/valhalla/file_hashes.txt" "$BASE/valhalla/duplicateways.txt"
  docker run -d --name shg-valhalla --restart unless-stopped \
    -p 127.0.0.1:8002:8002 -v "$BASE/valhalla:/custom_files" \
    -e serve_tiles=True -e build_tar=True -e build_admins=True -e build_time_zones=True \
    -e build_elevation=False -e use_tiles_ignore_pbf=True -e force_rebuild=False \
    -e server_threads=2 --memory=3g --cpus=1.5 \
    ghcr.io/valhalla/valhalla-scripted:latest
  echo "valhalla container started (first start builds the graph; watch: docker logs -f shg-valhalla)"
}

case "$STAGE" in
  tiles) tiles ;; static) static ;; places) places ;; valhalla) valhalla ;;
  all) static; places; tiles; valhalla ;;
  *) echo "usage: $0 [tiles|static|places|valhalla|all]"; exit 2 ;;
esac
chown -R www-data:www-data "$PUB" 2>/dev/null || true
