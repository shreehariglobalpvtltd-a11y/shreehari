#!/usr/bin/env python3
"""Coverage boxes for meta.json: where the navigator asks OUR tiles first.

    python3 navcover.py india.poly nepal.poly > coverage.json

Input: Geofabrik .poly files of the built region (the extract is cut along
them, so inside them every tile is complete). Output: a JSON list of
[west, south, east, north] boxes, each made of whole z10 tiles that lie fully
inside the union of the polygons (border strips: whole z12 tiles). 15-nav.js serves a tile (z8+) from us when
it lies inside ONE box, otherwise from the planet tiles first; so a border
tile (half India, half Bangladesh) always comes complete from the planet.

Pure Python (no shapely): the polygons are scan-filled into a fine grid,
z10 tiles are tested cell by cell, then merged greedily into the largest
rectangles.
"""
import json
import math
import sys

STEP = 0.01     # fill grid resolution in degrees (~1 km)


def read_poly(path):
    """-> list of (is_hole, [(lon, lat), ...])"""
    rings, cur, hole = [], None, False
    with open(path, encoding='utf-8') as f:
        lines = [l.strip() for l in f if l.strip()]
    for l in lines[1:]:
        if cur is None:
            if l == 'END':
                break
            hole, cur = l.startswith('!'), []
            continue
        if l == 'END':
            rings.append((hole, cur))
            cur = None
            continue
        x, y = l.split()[:2]
        cur.append((float(x), float(y)))
    return rings


def tile_lon(x, n):
    return x / n * 360.0 - 180.0


def tile_lat(y, n):
    return math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * y / n))))


def lon_tile(lon, n):
    return int((lon + 180.0) / 360.0 * n)


def lat_tile(lat, n):
    r = math.radians(lat)
    return int((1 - math.asinh(math.tan(r)) / math.pi) / 2 * n)


def main(paths):
    polys = [read_poly(p) for p in paths]
    pts = [pt for rings in polys for _, r in rings for pt in r]
    W = math.floor(min(p[0] for p in pts)); E = math.ceil(max(p[0] for p in pts))
    S = math.floor(min(p[1] for p in pts)); N = math.ceil(max(p[1] for p in pts))
    cols, rows = int(round((E - W) / STEP)), int(round((N - S) / STEP))
    union = bytearray(cols * rows)

    # scan-fill: cell (i, j) centre = (W + (i+.5)*STEP, S + (j+.5)*STEP)
    for rings in polys:
        outer = bytearray(cols * rows)
        holes = bytearray(cols * rows)
        for is_hole, ring in rings:
            dst = holes if is_hole else outer
            edges = list(zip(ring, ring[1:] + ring[:1]))
            for j in range(rows):
                y = S + (j + 0.5) * STEP
                xs = sorted(x1 + (y - y1) * (x2 - x1) / (y2 - y1)
                            for (x1, y1), (x2, y2) in edges if (y1 <= y < y2) or (y2 <= y < y1))
                base = j * cols
                for a, b in zip(xs[0::2], xs[1::2]):
                    i0 = max(0, int(math.ceil((a - W) / STEP - 0.5)))
                    i1 = min(cols - 1, int(math.floor((b - W) / STEP - 0.5)))
                    for i in range(i0, i1 + 1):
                        dst[base + i] ^= 1           # even-odd inside one ring
        for k in range(cols * rows):
            if outer[k] and not holes[k]:
                union[k] = 1

    def cell_ok(lo, la):
        i, j = int((lo - W) / STEP), int((la - S) / STEP)
        return 0 <= i < cols and 0 <= j < rows and union[j * cols + i]

    def inside_tiles(z):
        """z-tiles lying fully inside: every grid cell touching the tile is inside"""
        n = 2 ** z
        tx0, tx1 = lon_tile(W, n), lon_tile(E, n)
        ty0, ty1 = lat_tile(N, n), lat_tile(S, n)
        good = set()
        for ty in range(ty0, ty1 + 1):
            top, bot = tile_lat(ty, n), tile_lat(ty + 1, n)
            for tx in range(tx0, tx1 + 1):
                west, east = tile_lon(tx, n), tile_lon(tx + 1, n)
                ok = True
                la = bot - STEP
                while ok and la <= top + STEP:
                    lo = west - STEP
                    while lo <= east + STEP:
                        if not cell_ok(lo, la):
                            ok = False
                            break
                        lo += STEP
                    la += STEP
                if ok:
                    good.add((tx, ty))
        return good

    def box(z, ax, ay, bx, by):
        n, k = 2 ** z, 1e5                          # 5 decimals, rounded outwards (~1 m)
        lo = lambda v: math.floor(v * k) / k
        hi = lambda v: math.ceil(v * k) / k
        return [lo(tile_lon(ax, n)), lo(tile_lat(by + 1, n)), hi(tile_lon(bx + 1, n)), hi(tile_lat(ay, n))]

    def largest_rects(z, good):
        """greedy: take the largest all-inside rectangle, repeat"""
        good = set(good)
        xs = [t[0] for t in good]; ys = [t[1] for t in good]
        gx0, gx1, gy0, gy1 = min(xs), max(xs), min(ys), max(ys)
        out = []
        while good:
            best, hist = None, {}
            for ty in range(gy0, gy1 + 1):
                for tx in range(gx0, gx1 + 1):
                    hist[tx] = hist.get(tx, 0) + 1 if (tx, ty) in good else 0
                stack = []                              # largest rectangle in a histogram
                for tx in range(gx0, gx1 + 2):
                    h = hist.get(tx, 0) if tx <= gx1 else 0
                    start = tx
                    while stack and stack[-1][1] >= h:
                        sx, sh = stack.pop()
                        if sh and (best is None or sh * (tx - sx) > best[0]):
                            best = (sh * (tx - sx), sx, tx - 1, ty - sh + 1, ty)
                        start = sx
                    stack.append((start, h))
            _, ax, bx, ay, by = best
            for ty in range(ay, by + 1):
                for tx in range(ax, bx + 1):
                    good.discard((tx, ty))
            out.append(box(z, ax, ay, bx, by))
        return out

    def strip_rects(z, good):
        """row runs, merged downwards while the run is the same"""
        rows = {}
        for tx, ty in good:
            rows.setdefault(ty, []).append(tx)
        open_, out = {}, []
        for ty in sorted(rows):
            xs = sorted(rows[ty])
            runs, a = [], xs[0]
            for p, q in zip(xs, xs[1:] + [None]):
                if q != p + 1:
                    runs.append((a, p))
                    a = q
            nxt = {}
            for r in runs:
                if r in open_ and open_[r][1] == ty - 1:
                    nxt[r] = (open_.pop(r)[0], ty)
                else:
                    nxt[r] = (ty, ty)
            for r, (ay, by) in open_.items():
                out.append(box(z, r[0], ay, r[1], by))
            open_ = nxt
        for r, (ay, by) in open_.items():
            out.append(box(z, r[0], ay, r[1], by))
        return out

    # big boxes from z10 tiles, then the border strips from z12 tiles (~9 km)
    big = inside_tiles(10)
    boxes = largest_rects(10, big)
    fine = {(tx, ty) for tx, ty in inside_tiles(12) if (tx >> 2, ty >> 2) not in big}
    boxes += strip_rects(12, fine)
    json.dump(boxes, sys.stdout, separators=(',', ':'))
    sys.stderr.write('%d boxes\n' % len(boxes))


if __name__ == '__main__':
    main(sys.argv[1:])
