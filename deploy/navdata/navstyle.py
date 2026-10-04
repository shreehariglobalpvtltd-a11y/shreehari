#!/usr/bin/env python3
"""SHARI NAV style helper.

  navstyle.py fonts a.json [b.json ...]        -> print every fontstack the styles use
  navstyle.py style in.json out.json SPRITE    -> rewrite a style to our own server

Our tiles come from tilemaker (OpenMapTiles schema). tilemaker writes the label
into `name:latin` (English when OSM has name:en) and the local name into
`name_int`; it never writes a plain `name`, so every label lookup is widened.
"""
import json
import sys

LABEL = ["coalesce", ["get", "name:latin"], ["get", "name:en"], ["get", "name_int"], ["get", "name"]]


def fontstacks(node, out):
    if isinstance(node, dict):
        for k, v in node.items():
            if k == 'text-font':
                collect_fonts(v, out)
            else:
                fontstacks(v, out)
    elif isinstance(node, list):
        for v in node:
            fontstacks(v, out)


def collect_fonts(v, out):
    if isinstance(v, list) and v and all(isinstance(x, str) for x in v) and v[0] not in ('literal', 'get', 'step', 'case', 'match', 'coalesce', 'interpolate'):
        out.add(','.join(v))
    elif isinstance(v, list):
        for x in v:
            collect_fonts(x, out)
    elif isinstance(v, dict) and 'stops' in v:
        for stop in v['stops']:
            collect_fonts(stop[1], out)


def widen(node):
    """Replace label lookups so tilemaker's attribute names are found."""
    if isinstance(node, list):
        if len(node) == 2 and node[0] == 'get' and node[1] in ('name', 'name_en', 'name:en', 'name:latin', 'name_int'):
            return LABEL
        return [widen(x) for x in node]
    if isinstance(node, dict):
        return {k: widen(v) for k, v in node.items()}
    if isinstance(node, str) and node.startswith('{name') and node.endswith('}') and '\n' not in node:
        return LABEL
    return node


def rewrite(src, dst, sprite):
    st = json.load(open(src, encoding='utf-8'))
    drop = set()
    for sid, s in list(st.get('sources', {}).items()):
        if s.get('type') == 'vector':
            st['sources'][sid] = {
                'type': 'vector',
                'tiles': ['/navdata/tiles/{z}/{x}/{y}.pbf'],
                'minzoom': 0,
                'maxzoom': 14,
                'attribution': '© OpenStreetMap contributors · SHARI NAV',
            }
        else:
            drop.add(sid)                      # raster relief etc. lives elsewhere
            del st['sources'][sid]
    st['layers'] = [l for l in st['layers'] if l.get('source') not in drop]
    for l in st['layers']:
        lay = l.get('layout') or {}
        if 'text-field' in lay:
            lay['text-field'] = widen(lay['text-field'])
    st['glyphs'] = '/navdata/fonts/{fontstack}/{range}.pbf'
    st['sprite'] = '/navdata/sprites/' + sprite
    st['name'] = 'SHARI NAV ' + st.get('name', '')
    json.dump(st, open(dst, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))


if __name__ == '__main__':
    if sys.argv[1] == 'fonts':
        out = set()
        for f in sys.argv[2:]:
            fontstacks(json.load(open(f, encoding='utf-8')).get('layers', []), out)
        print('\n'.join(sorted(out)))
    elif sys.argv[1] == 'style':
        rewrite(sys.argv[2], sys.argv[3], sys.argv[4])
    else:
        sys.exit('usage: navstyle.py fonts|style ...')
