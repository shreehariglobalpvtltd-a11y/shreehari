#!/usr/bin/env python3
"""Split the map area into tile-aligned chunks small enough for tilemaker.

    python3 navchunks.py coverage.json [zoom=6] > chunks.tsv

One whole India + Nepal run needs ~8.5 GB of tilemaker memory (5 Oct 2026:
3 GB resident + 5.4 GB swapped on the 8 GB VPS -> 37 % iowait, a few tiles a
minute). Built per z6 tile (~5.6 deg) instead, each run fits in RAM. Every
tile of zoom >= 6 lies inside exactly one chunk, so the runs never write the
same file; the chunk box is shrunk by 1e-4 deg so a run never "touches" a
neighbour's edge tiles.

Output: name, west, south, east, north (the exact z-tile box) - one line per
z-tile that overlaps a coverage box.
"""
import json
import math
import sys


def tile_lon(x, n):
    return x / n * 360.0 - 180.0


def tile_lat(y, n):
    return math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * y / n))))


def main(path, z=6):
    boxes = json.load(open(path))
    n = 2 ** z
    seen = set()
    for w, s, e, nn in boxes:
        x0 = int((w + 180.0) / 360.0 * n)
        x1 = int((e + 180.0) / 360.0 * n - 1e-9)
        y0 = int((1 - math.asinh(math.tan(math.radians(nn))) / math.pi) / 2 * n)
        y1 = int((1 - math.asinh(math.tan(math.radians(s))) / math.pi) / 2 * n - 1e-9)
        for x in range(x0, x1 + 1):
            for y in range(y0, y1 + 1):
                seen.add((x, y))
    for x, y in sorted(seen):
        print('\t'.join(['z%d_%d_%d' % (z, x, y), '%.6f' % tile_lon(x, n), '%.6f' % tile_lat(y + 1, n),
                         '%.6f' % tile_lon(x + 1, n), '%.6f' % tile_lat(y, n)]))
    sys.stderr.write('%d chunks\n' % len(seen))


if __name__ == '__main__':
    main(sys.argv[1], int(sys.argv[2]) if len(sys.argv) > 2 else 6)
