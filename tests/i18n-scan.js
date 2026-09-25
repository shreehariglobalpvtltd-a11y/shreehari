/**
 * i18n-scan — English sentences that never reach applyLang().
 *
 * tests/i18n-check.js proves every KEY exists in en / hi / ne. It cannot see
 * the other half of the problem: a message written as an English literal at
 * the call site, which no language switch can touch. The app is Nepali-first
 * (04-i18n.js defaults LANG to 'ne'), so each of those is a Nepali visitor
 * reading English. Thirteen were found and fixed on 25 Sep 2026; this keeps
 * them from coming back.
 *
 * What it looks at: the CUSTOMER bundle only. The admin and agent panels are
 * English on purpose (12-admin-panel.js says so in its header), so they are
 * not scanned.
 *
 * What counts as a hit: a literal of three or more English words handed to a
 * sink a human reads — toast / alert / confirm / prompt, .textContent =,
 * .title = , .placeholder = , or setAttribute('aria-label' | 'title', …).
 * A literal containing Devanagari is already localised in place and passes.
 *
 *   node tests/i18n-scan.js
 *
 * Exit 0 = clean; 1 = at least one untranslated sentence.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const JS_DIR = path.join(__dirname, '..', 'assets', 'js');

/* The customer bundle. 04-i18n.js is the dictionary itself, 09-agent.js and
   12/13-admin-* are the English staff panels, terms-data.js is legal copy
   that ships per-language elsewhere. */
const FILES = [
  '01-boot.js', '02-config.js', '03-accounts.js', '05-router.js', '06-results.js',
  '07-checkout.js', '08-signin.js', '10-track.js', '11-pdf-ticket.js',
  '14-counter.js', '15-nav.js', '16-lazy.js', '17-pwa.js', '18-journey.js',
  '19-premium.js'
];

/* One capture group for the quote, one for the body. Kept to a single line:
   a sentence a user reads is not split across lines in this codebase, and
   line-anchoring is what keeps the false-positive rate at zero. */
const SINKS = [
  /(?:toast|alert|confirm|prompt)\s*\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1/g,
  /\.(?:textContent|title|placeholder)\s*=\s*(['"])((?:\\.|(?!\1)[^\\])*)\1/g,
  /setAttribute\s*\(\s*['"](?:aria-label|title|placeholder)['"]\s*,\s*(['"])((?:\\.|(?!\1)[^\\])*)\1/g
];

/* Words that make a literal a developer string rather than a sentence for a
   passenger: they name code, not something to read. */
const CODEY = /https?:|\/\/|[{}<>]|^[A-Za-z-]+$|^#|\.js\b|\.php\b/;

const hits = [];
for (const file of FILES) {
  const full = path.join(JS_DIR, file);
  if (!fs.existsSync(full)) continue;
  const lines = fs.readFileSync(full, 'utf8').split(/\r?\n/);
  lines.forEach((line, i) => {
    /* A commented-out call is not shipped to anyone. */
    if (/^\s*(\/\/|\*|\/\*)/.test(line)) return;
    for (const re of SINKS) {
      re.lastIndex = 0;
      let m;
      while ((m = re.exec(line))) {
        const s = m[2];
        if (/[ऀ-ॿ]/.test(s)) continue;      // already localised
        if (CODEY.test(s)) continue;
        const words = s.replace(/[^A-Za-z' ]/g, ' ').trim().split(/\s+/).filter(w => w.length > 1);
        if (words.length < 3) continue;
        hits.push({ file, line: i + 1, text: s.slice(0, 120) });
      }
    }
  });
}

console.log('=== i18n literal scan (customer bundle) ===\n');
if (hits.length === 0) {
  console.log('  OK — every message a customer reads goes through t() / tf().');
  process.exit(0);
}
hits.forEach(h => console.log(`  ${h.file}:${h.line}\n      ${h.text}`));
console.log(`\n  ${hits.length} English sentence(s) a Nepali visitor would read in English.`);
console.log('  Add a key to all three languages in assets/js/04-i18n.js and call t() / tf().');
process.exit(1);
