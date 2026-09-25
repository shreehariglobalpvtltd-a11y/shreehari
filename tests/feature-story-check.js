/**
 * feature-story-check.js — the four facility scenes are hand-written SVG
 * inside a JavaScript string, which is exactly the shape of thing a typo
 * survives. Two got through while this was being built:
 *
 *     fill="#081densité"     fill="#2B4membrane"
 *
 * Neither is a syntax error, neither throws, and neither shows up in a
 * screenshot as anything more than a shape quietly not being painted.
 * A browser drops an invalid paint value and carries on. So this reads
 * the scenes the way a browser would and refuses the ones it would have
 * silently ignored.
 *
 * It also checks the promise the whole feature rests on: every element
 * that is animated must exist in the markup, and every class the CSS
 * animates must be used by a scene — a rule aimed at nothing is a
 * feature that was renamed and half-finished.
 *
 *   node tests/feature-story-check.js
 *
 * Exit 0 = clean, 1 = something a browser would drop.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const JS = path.join(__dirname, '..', 'assets', 'js', '20-feature-story.js');
const CSS = path.join(__dirname, '..', 'assets', 'css', 'feature-story.css');

const js = fs.readFileSync(JS, 'utf8');
const css = fs.readFileSync(CSS, 'utf8');

let bad = 0;
const fail = (what, detail) => { bad++; console.log(`  FAIL  ${what}${detail ? '  —  ' + detail : ''}`); };
const pass = (what, detail) => console.log(`  PASS  ${what}${detail ? '  —  ' + detail : ''}`);

console.log('\n=== the four facility scenes ===\n');

/* ---- 1. every colour is a colour ---------------------------------- */
/* A paint value is either #rgb / #rrggbb / #rrggbbaa, a url(#id)
   reference to a gradient or clip path defined in the same scene, or
   one of the few keywords used here. Anything else, a browser drops. */
const paints = [...js.matchAll(/(?:fill|stroke|stop-color)\s*=\s*\\?"([^"\\]+)/g)].map(m => m[1]);
const hexOk = /^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/;
const keywords = new Set(['none', 'currentColor', 'transparent', 'white', 'black']);
const refs = new Set([...js.matchAll(/id="([A-Za-z0-9_-]+)"/g)].map(m => m[1]));

const badPaints = paints.filter((p) => {
  if (hexOk.test(p) || keywords.has(p)) { return false; }
  if (/^rgba?\(/.test(p)) { return false; }
  const url = /^url\(#([A-Za-z0-9_-]+)\)$/.exec(p);
  if (url) { return !refs.has(url[1]); }
  return true;
});
if (badPaints.length) { fail('every fill / stroke is a valid paint', badPaints.join(', ')); }
else { pass('every fill / stroke is a valid paint', paints.length + ' checked'); }

/* ---- 2. the tags balance ------------------------------------------ */
/* The scenes are concatenated strings; an unclosed <g> silently swallows
   everything after it into the wrong transform. */
const scenes = [...js.matchAll(/(\w+):\s*'<svg([\s\S]*?)<\/svg>'/g)];
if (scenes.length !== 4) {
  fail('four scenes are defined', 'found ' + scenes.length);
} else {
  pass('four scenes are defined');
  for (const [, name, body] of scenes) {
    const opens = (body.match(/<g\b/g) || []).length;
    const closes = (body.match(/<\/g>/g) || []).length;
    if (opens !== closes) { fail(`${name}: <g> tags balance`, `${opens} open, ${closes} close`); }
    else { pass(`${name}: <g> tags balance`, opens + ' groups'); }
  }
}

/* ---- 3. nothing is animated that does not exist -------------------- */
const animated = new Set(
  [...css.matchAll(/\.(fs-[a-z0-9-]+)[^{]*\{[^}]*animation:/gi)].map((m) => m[1])
);
const used = new Set([...js.matchAll(/class=\\?"([^"\\]+)/g)].flatMap((m) => m[1].split(/\s+/)));
const orphans = [...animated].filter((c) => !used.has(c));
if (orphans.length) { fail('every animated class is used by a scene', orphans.join(', ')); }
else { pass('every animated class is used by a scene', animated.size + ' animated'); }

/* ---- 4. the panel cannot outlive its close ------------------------- */
/* The one promise this feature makes to the home page: closing removes
   the element, so the animations stop instead of running out of sight. */
if (!/removeChild\(w\)/.test(js)) {
  fail('close() removes the panel from the DOM', 'it must not merely hide it');
} else {
  pass('close() removes the panel from the DOM');
}

/* ---- 5. all three languages, all four scenes ----------------------- */
for (const key of ['gps', 'charge', 'ac', 'safe']) {
  const block = new RegExp(`${key}:\\s*\\{([\\s\\S]*?)\\n    \\}`).exec(js);
  if (!block) { fail(`${key}: copy block found`); continue; }
  const langs = ['ne', 'hi', 'en'].filter((l) => new RegExp(`\\b${l}:\\s*\\{`).test(block[1]));
  if (langs.length !== 3) { fail(`${key}: written in all three languages`, 'has ' + langs.join(', ')); }
  else { pass(`${key}: written in all three languages`); }
}

console.log(`\n${bad === 0 ? '  OK — nothing a browser would silently drop.' : '  ' + bad + ' problem(s).'}\n`);
process.exit(bad === 0 ? 0 : 1);
