/**
 * feature-story-check.js — the brand film is hand-written SVG inside a
 * JavaScript string, which is exactly the shape of thing a typo
 * survives. Two got through while the first version was being built:
 *
 *     fill="#081densité"     fill="#2B4membrane"
 *
 * Neither is a syntax error, neither throws, and neither shows up in a
 * screenshot as anything more than a shape quietly not being painted.
 * A browser drops an invalid paint value and carries on. So this reads
 * the stage the way a browser would and refuses what it would have
 * silently ignored.
 *
 * It also checks the promises the feature rests on: the eight acts of
 * the script exist in all three languages, every class the stylesheet
 * animates is actually used, and close() removes the panel rather than
 * hiding it — a hidden panel would keep every animation running for as
 * long as the tab is open.
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

console.log('\n=== the brand film ===\n');

/* ---- 1. every colour is a colour ---------------------------------- */
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

/* ---- 2. the stage is one balanced document ------------------------ */
const stageStart = js.indexOf("'<svg viewBox=\"0 0 360 220\"");
const stageEnd = js.indexOf("'</svg>'", stageStart);
if (stageStart < 0 || stageEnd < 0) {
  fail('the stage is found');
} else {
  const body = js.slice(stageStart, stageEnd);
  const opens = (body.match(/<g\b/g) || []).length;
  const closes = (body.match(/<\/g>/g) || []).length;
  if (opens !== closes) { fail('<g> tags balance across the stage', `${opens} open, ${closes} close`); }
  else { pass('<g> tags balance across the stage', opens + ' groups'); }
  const acts = (body.match(/class="act act-[a-z]+"/g) || []).length;
  if (acts < 7) { fail('every act is drawn', 'found ' + acts + ' act groups'); }
  else { pass('every act is drawn', acts + ' act groups'); }
  if (!/__IND__|__NP__|__RT__/.test(body)) { pass('the map paths are real coordinates, not placeholders'); }
  else { fail('the map paths are real coordinates, not placeholders', 'a __IND__/__NP__/__RT__ marker is still in the file'); }
}

/* ---- 3. nothing is animated that does not exist -------------------- */
/* For each CSS rule that animates, take the LAST .fs-* class in its
   selector — `.fs-stage[data-act="5"] .fs-fill-wire{animation…}` is
   animating fs-fill-wire, not fs-stage. */
const animated = new Set();
for (const rule of css.matchAll(/([^{}]+)\{([^}]*)\}/g)) {
  if (!/\banimation\s*:/.test(rule[2])) { continue; }
  for (const sel of rule[1].split(',')) {
    const cls = [...sel.matchAll(/\.(fs-[a-z0-9-]+)/g)].map((m) => m[1]);
    if (cls.length) { animated.add(cls[cls.length - 1]); }
  }
}
const used = new Set([...js.matchAll(/class=\\?"([^"\\]+)/g)].flatMap((m) => m[1].split(/\s+/)));
/* classes the JS toggles rather than writes into markup */
['fs-caption', 'fs-card', 'fs-cta', 'fs-stage'].forEach((c) => used.add(c));
const orphans = [...animated].filter((c) => !used.has(c));
if (orphans.length) { fail('every animated class is used', orphans.join(', ')); }
else { pass('every animated class is used', animated.size + ' animated'); }

/* ---- 4. the panel cannot outlive its close ------------------------- */
if (!/removeChild\(w\)/.test(js)) { fail('close() removes the panel from the DOM', 'it must not merely hide it'); }
else { pass('close() removes the panel from the DOM'); }
if (!/bed\(false\)/.test(js)) { fail('close() stops the music bed'); } else { pass('close() stops the music bed'); }

/* ---- 5. the script: eight acts, three languages, every field ------ */
const scriptStart = js.indexOf('var SCRIPT = [');
const scriptEnd = js.indexOf('];', scriptStart);
const script = js.slice(scriptStart, scriptEnd);
const acts = (script.match(/\bat:\s*[\d.]+/g) || []).length;
if (acts !== 8) { fail('the script has eight acts', 'found ' + acts); } else { pass('the script has eight acts'); }
for (const l of ['ne', 'hi', 'en']) {
  const n = (script.match(new RegExp(`\\b${l}:\\s*'`, 'g')) || []).length;
  if (n < acts * 3) { fail(`${l}: head, sub and voice on every act`, `${n} of ${acts * 3}`); }
  else { pass(`${l}: head, sub and voice on every act`); }
}
if (!/आजै आफ्नो यात्रा बुक गर्नुहोस्/.test(script)) { fail('the closing line is the briefed CTA'); }
else { pass('the closing line is the briefed CTA'); }

console.log(`\n${bad === 0 ? '  OK — nothing a browser would silently drop.' : '  ' + bad + ' problem(s).'}\n`);
process.exit(bad === 0 ? 0 : 1);
