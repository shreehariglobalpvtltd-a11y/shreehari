/* =====================================================================
 *  SEAT LABEL PARITY — the browser's seatLabel() must print exactly what
 *  the server's Seats::displayLabel() prints (23 Sep 2026).
 *
 *      node tests/seat-label-parity.js
 *
 *  The customer app, Quick Ticket, the agent/counter desk and the client
 *  PDF ticket label seats with seatLabel() in assets/js/06-results.js; the
 *  PNG ticket, the WhatsApp picture, the manifest and the admin seat map use
 *  Seats::displayLabel(). tests/seat-labels.json is what PHP prints (made by
 *  `php tests/seat-label-test.php --dump`, which also fails if PHP drifts
 *  from it) — this file holds the JS side to the same table, with the boot
 *  payload present AND absent (the fallback a cached bundle runs on).
 * ===================================================================== */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0;
let FAIL = 0;
function check(label, ok, extra) {
  if (ok) { PASS++; console.log('  \x1b[32mPASS\x1b[0m  ' + label + (extra ? ' — ' + extra : '')); }
  else    { FAIL++; console.log('  \x1b[31mFAIL\x1b[0m  ' + label + (extra ? ' — ' + extra : '')); }
}

/* Lift the named top-level function declarations out of the bundle (brace
   matched), so the test runs the shipped code rather than a copy of it. */
const SRC = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', '06-results.js'), 'utf8');
function lift(name) {
  const start = SRC.search(new RegExp('^function ' + name + '\\(', 'm'));
  if (start < 0) throw new Error('function ' + name + ' not found in 06-results.js');
  let depth = 0, i = SRC.indexOf('{', start);
  for (; i < SRC.length; i++) {
    if (SRC[i] === '{') depth++;
    else if (SRC[i] === '}' && --depth === 0) break;
  }
  return SRC.slice(start, i + 1);
}
const NAMES = ['seatModeRuleJS', 'bedsPerLabelJS', 'seatRowLetterJS', 'seatModeSpecJS',
  'seatBedsOfLabelJS', 'seatBedLabelJS', 'seatJoinBedLabelsJS', 'seatLabel', 'seatLabelJoin'];
const CODE = NAMES.map(lift).join('\n');

function sandbox(boot) {
  const ctx = { window: {} };
  if (boot) { ctx.window.SHG_BOOT = boot; ctx.SHG_BOOT = boot; }
  vm.createContext(ctx);
  vm.runInContext(CODE, ctx);
  return ctx;
}

const FIX = JSON.parse(fs.readFileSync(path.join(__dirname, 'seat-labels.json'), 'utf8'));

console.log('\n=== Seat label parity (JS seatLabel vs PHP Seats::displayLabel) ===\n');

[['with the boot rule set', { settings: { seat_mode_map: FIX.seat_mode_map } }], ['with NO boot payload (fallback)', null]]
  .forEach(function (c) {
    const js = sandbox(c[1]);
    ['sharing', 'private'].forEach(function (mode) {
      const want = FIX.labels[mode];
      const bad = Object.keys(want).filter(function (id) { return js.seatLabel(id, 'sleeper', mode) !== want[id]; });
      check(mode + ' — all ' + Object.keys(want).length + ' labels match PHP, ' + c[0], bad.length === 0,
        bad.slice(0, 4).map(function (id) { return id + ': js ' + js.seatLabel(id, 'sleeper', mode) + ' / php ' + want[id]; }).join('; '));
    });
    const seat = FIX.labels.seater;
    const badS = Object.keys(seat).filter(function (id) { return js.seatLabel(id, 'seater', 'sharing') !== seat[id]; });
    check('seater labels unchanged, ' + c[0], badS.length === 0, badS.slice(0, 4).join(','));
    check('floor ranges ' + FIX.floorRange.L + ' / ' + FIX.floorRange.U + ', ' + c[0],
      js.seatBedLabelJS('L1', 'sleeper') + '–' + js.seatBedLabelJS('L36', 'sleeper') === FIX.floorRange.L
      && js.seatBedLabelJS('U1', 'sleeper') + '–' + js.seatBedLabelJS('U36', 'sleeper') === FIX.floorRange.U);
  });

const js = sandbox({ settings: { seat_mode_map: FIX.seat_mode_map } });
const all = Object.keys(FIX.labels.sharing).map(function (id) { return js.seatLabel(id, 'sleeper', 'sharing'); });
check('72 distinct labels in the browser', new Set(all).size === 72 && all.length === 72, new Set(all).size + ' distinct');
check('seatLabelJoin prints the ticket line', js.seatLabelJoin(['L1', 'U1', 'U36'], 'sleeper', 'sharing') === 'A1, A7, F12');
check('lower-case and odd input tolerated', js.seatLabel('u4', 'sleeper', 'sharing') === 'A10' && js.seatLabel('', 'sleeper') === '' && js.seatLabel(null) === '');

console.log('\n  ' + PASS + ' passed, ' + FAIL + ' failed\n');
process.exit(FAIL === 0 ? 0 : 1);
