#!/usr/bin/env node
/*
 * build-gid-font.js — makes assets/fonts/NotoSansDevanagari-gid.ttf
 * (24 Sep 2026).
 *
 * WHY: GD draws text through FreeType, which maps characters to glyphs one
 * by one and never applies the font's OpenType rules. Devanagari needs those
 * rules (conjuncts, reph, the i-matra reordering, mark positions), so every
 * PNG ticket printed यात्‌रु / जम्‌मा instead of यात्रु / जम्मा. HarfBuzz
 * (libharfbuzz0b, shaped from PHP through FFI — includes/devshape.php) now
 * does the shaping and hands back GLYPH IDs. GD cannot draw a glyph by its
 * id, only by a character, so this file is the same font with one extra
 * mapping: character U+E000 + id draws glyph id (the BMP Private Use Area,
 * which no text we print contains; libgd decodes only 1-3 byte UTF-8, so the
 * supplementary planes are out of reach). Nothing else changes — the outlines,
 * metrics and every original character mapping are byte-for-byte the same.
 *
 * The cmap is rebuilt as a single (3,10) format-12 subtable holding the
 * original mappings plus the private ones, so whichever Unicode charmap a
 * renderer picks, it gets both.
 *
 *   node deploy/build-gid-font.js            (from the repo root)
 *
 * Re-run it whenever assets/fonts/NotoSansDevanagari.ttf changes;
 * tests/devshape-test.php fails if the two fonts disagree.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SRC = path.join(ROOT, 'assets/fonts/NotoSansDevanagari.ttf');
const DST = path.join(ROOT, 'assets/fonts/NotoSansDevanagari-gid.ttf');
const PUA = 0xE000;

const src = fs.readFileSync(SRC);
const numTables = src.readUInt16BE(4);
const tables = [];
for (let i = 0; i < numTables; i++) {
  const o = 12 + 16 * i;
  tables.push({
    tag: src.toString('latin1', o, o + 4),
    off: src.readUInt32BE(o + 8),
    len: src.readUInt32BE(o + 12),
  });
}
const T = tag => {
  const t = tables.find(x => x.tag === tag);
  if (!t) throw new Error('missing table ' + tag);
  return t;
};
const numGlyphs = src.readUInt16BE(T('maxp').off + 4);

/* --- read every original mapping (format 4 and format 12 subtables) --- */
const map = new Map(); // codepoint -> glyph id
{
  const base = T('cmap').off;
  const n = src.readUInt16BE(base + 2);
  for (let i = 0; i < n; i++) {
    const rec = base + 4 + 8 * i;
    const pid = src.readUInt16BE(rec), eid = src.readUInt16BE(rec + 2);
    const st = base + src.readUInt32BE(rec + 4);
    const fmt = src.readUInt16BE(st);
    const unicode = pid === 0 || (pid === 3 && (eid === 1 || eid === 10));
    if (!unicode) continue;
    if (fmt === 4) {
      const segX2 = src.readUInt16BE(st + 6);
      const ends = st + 14, starts = ends + segX2 + 2, deltas = starts + segX2, ros = deltas + segX2;
      for (let s = 0; s < segX2 / 2; s++) {
        const end = src.readUInt16BE(ends + 2 * s), start = src.readUInt16BE(starts + 2 * s);
        const delta = src.readInt16BE(deltas + 2 * s), ro = src.readUInt16BE(ros + 2 * s);
        for (let c = start; c <= end && c !== 0xFFFF; c++) {
          let g;
          if (ro === 0) g = (c + delta) & 0xFFFF;
          else {
            const gi = ros + 2 * s + ro + 2 * (c - start);
            g = src.readUInt16BE(gi);
            if (g !== 0) g = (g + delta) & 0xFFFF;
          }
          if (g !== 0 && !map.has(c)) map.set(c, g);
        }
      }
    } else if (fmt === 12) {
      const groups = src.readUInt32BE(st + 12);
      for (let gI = 0; gI < groups; gI++) {
        const r = st + 16 + 12 * gI;
        const a = src.readUInt32BE(r), b = src.readUInt32BE(r + 4), g0 = src.readUInt32BE(r + 8);
        for (let c = a; c <= b; c++) if (!map.has(c)) map.set(c, g0 + (c - a));
      }
    }
  }
}
if (numGlyphs > 0xF8FF - PUA + 1) throw new Error('too many glyphs for the BMP private use area');
for (const c of map.keys()) {
  if (c >= PUA && c < PUA + numGlyphs) throw new Error('font already maps U+' + c.toString(16) + ' - pick another base');
}

/* --- new cmap: one (3,10) format-12 subtable --- */
const codes = [...map.keys()].sort((a, b) => a - b);
const groups = [];
for (const c of codes) {
  const g = map.get(c);
  const last = groups[groups.length - 1];
  if (last && c === last[1] + 1 && g === last[2] + (c - last[0])) last[1] = c;
  else groups.push([c, c, g]);
}
groups.push([PUA, PUA + numGlyphs - 1, 0]);
groups.sort((x, y) => x[0] - y[0]); // format 12 is binary-searched: groups must ascend
const subLen = 16 + 12 * groups.length;
const cmap = Buffer.alloc(4 + 8 + subLen);
cmap.writeUInt16BE(0, 0);           // version
cmap.writeUInt16BE(1, 2);           // numTables
cmap.writeUInt16BE(3, 4);           // platform: Windows
cmap.writeUInt16BE(10, 6);          // encoding: UCS-4
cmap.writeUInt32BE(12, 8);          // offset of the subtable
let p = 12;
cmap.writeUInt16BE(12, p); cmap.writeUInt16BE(0, p + 2);
cmap.writeUInt32BE(subLen, p + 4); cmap.writeUInt32BE(0, p + 8);
cmap.writeUInt32BE(groups.length, p + 12);
p += 16;
for (const [a, b, g] of groups) {
  cmap.writeUInt32BE(a, p); cmap.writeUInt32BE(b, p + 4); cmap.writeUInt32BE(g, p + 8); p += 12;
}

/* --- reassemble: same tables, same order, new cmap, fresh checksums --- */
const pad4 = n => (n + 3) & ~3;
const sum = buf => {
  let s = 0;
  const b = Buffer.concat([buf, Buffer.alloc(pad4(buf.length) - buf.length)]);
  for (let i = 0; i < b.length; i += 4) s = (s + b.readUInt32BE(i)) >>> 0;
  return s;
};
const bodies = tables.map(t => {
  let body = t.tag === 'cmap' ? cmap : Buffer.from(src.subarray(t.off, t.off + t.len));
  if (t.tag === 'head') { body = Buffer.from(body); body.writeUInt32BE(0, 8); } // checkSumAdjustment
  return { tag: t.tag, body };
});
const headerLen = 12 + 16 * numTables;
const out = Buffer.alloc(headerLen + bodies.reduce((n, b) => n + pad4(b.body.length), 0));
src.copy(out, 0, 0, 12); // sfnt version, numTables, searchRange, entrySelector, rangeShift
let off = headerLen;
const order = bodies.map((b, i) => i).sort((a, b) => (bodies[a].tag < bodies[b].tag ? -1 : 1));
order.forEach((i, k) => {
  const b = bodies[i];
  const rec = 12 + 16 * k;
  out.write(b.tag, rec, 4, 'latin1');
  out.writeUInt32BE(sum(b.body), rec + 4);
  out.writeUInt32BE(off, rec + 8);
  out.writeUInt32BE(b.body.length, rec + 12);
  b.body.copy(out, off);
  b.at = off;
  off += pad4(b.body.length);
});
const head = bodies.find(b => b.tag === 'head');
out.writeUInt32BE((0xB1B0AFBA - sum(out)) >>> 0, head.at + 8);

fs.writeFileSync(DST, out);
console.log(`wrote ${path.relative(ROOT, DST)}: ${out.length} bytes, ${numGlyphs} glyphs at U+${PUA.toString(16).toUpperCase()}.., ${map.size} original mappings in ${groups.length - 1} groups`);
