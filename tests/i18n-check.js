/**
 * V7 — i18n key-diff pre-release check.
 *
 * Every user-facing string must ship in all three languages (en / hi / ne).
 * Nothing enforced that before, so a Hindi or Nepali key could silently go
 * missing and the UI would fall back to English for those users.
 *
 * This extracts the I18N object out of assets/js/04-i18n.js (brace matching
 * from `const I18N = {`, then evaluated in a sandbox) and diffs the key
 * sets. It also lists data-i18n attributes used in app.template.html that no
 * language defines at all.
 *
 * The strings used to live in app.template.html; the 2026-08-23 split moved
 * them to their own module and this check silently stopped running (it
 * exited 1 with "Could not find const I18N"). It reads the module now.
 *
 *   node tests/i18n-check.js
 *
 * Exit code 0 = every key present in all three languages; 1 = gaps found.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const I18N_FILE = path.join(__dirname, '..', 'assets', 'js', '04-i18n.js');
const MARKUP_FILE = path.join(__dirname, '..', 'app.template.html');
const src = fs.readFileSync(I18N_FILE, 'utf8');
const markup = fs.readFileSync(MARKUP_FILE, 'utf8');

/* ---- pull the I18N object out of the single-file app ------------------ */
const start = src.indexOf('const I18N = {');
if (start === -1) {
  console.error('Could not find `const I18N = {` in assets/js/04-i18n.js');
  process.exit(1);
}

const braceStart = src.indexOf('{', start);
let depth = 0;
let end = -1;
let inStr = null;
for (let i = braceStart; i < src.length; i++) {
  const ch = src[i];
  const prev = src[i - 1];
  if (inStr) {
    if (ch === inStr && prev !== '\\') inStr = null;
    continue;
  }
  if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
  if (ch === '{') depth++;
  else if (ch === '}') { depth--; if (depth === 0) { end = i; break; } }
}
if (end === -1) {
  console.error('Could not find the end of the I18N object (unbalanced braces).');
  process.exit(1);
}

let I18N;
try {
  I18N = vm.runInNewContext('(' + src.slice(braceStart, end + 1) + ')');
} catch (e) {
  console.error('I18N object failed to evaluate: ' + e.message);
  process.exit(1);
}

const LANGS = ['en', 'hi', 'ne'];
for (const l of LANGS) {
  if (!I18N[l] || typeof I18N[l] !== 'object') {
    console.error(`I18N.${l} is missing or not an object.`);
    process.exit(1);
  }
}

/* ---- diff the key sets ------------------------------------------------ */
const keys = {};
LANGS.forEach(l => { keys[l] = new Set(Object.keys(I18N[l])); });

const union = new Set();
LANGS.forEach(l => keys[l].forEach(k => union.add(k)));

let problems = 0;
console.log('\n=== i18n key-diff ===\n');
LANGS.forEach(l => console.log(`  ${l}: ${keys[l].size} keys`));
console.log(`  union: ${union.size} keys\n`);

LANGS.forEach(l => {
  const missing = [...union].filter(k => !keys[l].has(k)).sort();
  if (missing.length) {
    problems += missing.length;
    console.log(`  MISSING in "${l}" (${missing.length}):`);
    missing.forEach(k => console.log(`    - ${k}`));
    console.log('');
  }
});

/* ---- data-i18n attributes with no translation anywhere ----------------
   Comments are stripped first: the file documents its own convention with
   a literal data-i18n="key" example, which would otherwise be reported as
   a missing key on every run and train people to ignore this check. */
const scannable = markup
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(/\/\*[\s\S]*?\*\//g, '');

const used = new Set();
const re = /data-i18n(?:-[a-z]+)?\s*=\s*"([^"]+)"/g;
let m;
while ((m = re.exec(scannable)) !== null) {
  m[1].split(/[;,\s]+/).forEach(tok => {
    const key = tok.includes(':') ? tok.split(':').pop() : tok;
    if (key && /^[A-Za-z][\w.]*$/.test(key)) used.add(key);
  });
}
const undefinedKeys = [...used].filter(k => !union.has(k)).sort();
if (undefinedKeys.length) {
  console.log(`  data-i18n keys used in markup but defined in NO language (${undefinedKeys.length}):`);
  undefinedKeys.forEach(k => console.log(`    - ${k}`));
  console.log('');
  problems += undefinedKeys.length;
}

console.log('----------------------------------------');
console.log(problems === 0
  ? '  OK — every key present in en, hi and ne.'
  : `  ${problems} problem(s) found.`);
console.log('----------------------------------------\n');

process.exit(problems === 0 ? 0 : 1);
