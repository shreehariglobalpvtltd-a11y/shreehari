/*
 * agreements-build-check.js — the committed /agreements/*.html are exactly what
 * tools/agreements/build.js makes from tools/agreements/content.js (27 Sep 2026).
 *
 * The pages are generated; a hand edit to agreements/*.html, or a content.js
 * change committed without re-running the build, shows up here as a
 * difference. Builds into a temporary folder and compares byte for byte.
 *
 *   node tests/agreements-build-check.js
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const site = path.join(root, 'agreements');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'shg-agr-'));
let pass = 0, fail = 0;
const check = (label, ok, extra) => {
  if (ok) pass++; else fail++;
  console.log(`  ${ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${label}${extra ? ' — ' + extra : ''}`);
};

console.log('\n=== /agreements/: committed pages = build.js output ===\n');
let built = true;
try {
  execFileSync(process.execPath, [path.join(root, 'tools', 'agreements', 'build.js'), '--out=' + tmp], { stdio: 'pipe' });
} catch (e) {
  built = false;
  console.log(String(e.stderr || e.message));
}
check('build.js runs (structure check passes)', built);
if (built) {
  const made = fs.readdirSync(tmp).filter((f) => f.endsWith('.html')).sort();
  check('9 pages generated (8 agreements in ne / hi / en / gu + index)', made.length === 9, made.join(' '));
  for (const f of made) {
    const want = fs.readFileSync(path.join(tmp, f));
    const p = path.join(site, f);
    const have = fs.existsSync(p) ? fs.readFileSync(p) : null;
    check(`agreements/${f} is up to date`, have !== null && want.equals(have),
      have === null ? 'missing — run node tools/agreements/build.js' : (want.equals(have) ? '' : 'differs — run node tools/agreements/build.js'));
  }
}
fs.rmSync(tmp, { recursive: true, force: true });
console.log(`\n  ${pass} passed, ${fail} failed\n`);
process.exit(fail ? 1 : 0);
