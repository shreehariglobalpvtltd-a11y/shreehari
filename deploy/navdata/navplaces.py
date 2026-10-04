#!/usr/bin/env python3
"""geojsonseq (osmium export) -> places.tsv for the SHARI NAV search index.

Columns: osm_ref, name, name_en, kind, lat, lng
One row per named place/POI; streets are kept once per ~1 km so "Station Road"
does not come back 40 times.
"""
import json
import sys

KEYS = ('amenity', 'shop', 'tourism', 'office', 'healthcare', 'leisure', 'historic',
        'place', 'railway', 'public_transport', 'highway')


def point(geom):
    t, c = geom['type'], geom['coordinates']
    if t == 'Point':
        return c
    if t == 'LineString':
        return c[len(c) // 2]
    if t == 'MultiLineString':
        return c[0][len(c[0]) // 2]
    ring = c[0] if t == 'Polygon' else c[0][0]          # outer ring of the (first) polygon
    xs = [p[0] for p in ring]
    ys = [p[1] for p in ring]
    return [(min(xs) + max(xs)) / 2, (min(ys) + max(ys)) / 2]


def clean(s):
    return (s or '').replace('\t', ' ').replace('\n', ' ').replace('\r', ' ').strip()[:150]


def main(src, dst):
    seen = set()
    n = 0
    with open(src, encoding='utf-8') as f, open(dst, 'w', encoding='utf-8') as out:
        for line in f:
            line = line.lstrip('\x1e').strip()
            if not line:
                continue
            try:
                feat = json.loads(line)
            except ValueError:
                continue
            p = feat.get('properties') or {}
            name = clean(p.get('name'))
            name_en = clean(p.get('name:en'))
            if not name and not name_en:
                continue
            kind = ''
            for k in KEYS:
                if p.get(k):
                    kind = k + ':' + clean(p[k])[:40]
                    break
            if not kind:
                continue
            try:
                lng, lat = point(feat['geometry'])[:2]
            except (KeyError, IndexError, TypeError, ValueError):
                continue
            if kind.startswith('highway:') and kind != 'highway:bus_stop':
                key = (name.lower(), round(lat, 2), round(lng, 2))
                if key in seen:
                    continue
                seen.add(key)
                kind = 'road:' + kind[8:]
            ref = (p.get('@type') or '?')[:1] + str(p.get('@id') or '')
            out.write('\t'.join([ref, name or name_en, name_en, kind, '%.6f' % lat, '%.6f' % lng]) + '\n')
            n += 1
    print('wrote', n, 'places')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
