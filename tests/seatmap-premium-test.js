/* =====================================================================
 *  SEAT MAP PREMIUM (8 Oct 2026) — the shipped seat-page functions,
 *  lifted out of the bundle and run in node (no browser, no database):
 *
 *    • seatHoldExpiryMs — a hold is 30 minutes on ANY phone time zone:
 *      expiresIn (relative) wins; an expiresAt wall-clock is read as IST
 *    • holdExpiryPick — once the server's hold has passed, the countdown
 *      still reaches its "expired" branch while berths are on screen,
 *      and stays silent once nothing is picked (D5-01)
 *    • seatAriaText — floor, women-only, cabin lock, state, in en / ne
 *    • seatFitsGender — "Man" never fits a women-only berth / cabin
 *    • pickSeatsSmart — a patient / elderly passenger is seated in the
 *      FRONT rows of the lower deck whatever seat_ai_row_order says, the
 *      gender filter still wins, non-patient picks are unchanged
 *
 *      node tests/seatmap-premium-test.js
 *  (tests/seatmap-premium-test.php runs it under three TZs.)
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

const JS = (f) => fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', f), 'utf8');
function lift(src, name, file) {
  const start = src.search(new RegExp('^function ' + name + '\\(', 'm'));
  if (start < 0) throw new Error('function ' + name + ' not found in ' + file);
  let depth = 0, i = src.indexOf('{', start);
  for (; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}' && --depth === 0) break;
  }
  return src.slice(start, i + 1);
}
const R5 = JS('05-router.js'), R6 = JS('06-results.js'), I18 = JS('04-i18n.js');

/* the I18N object + t()/tf(), exactly as shipped */
const i0 = I18.indexOf('const I18N = {');
let d = 0, j = I18.indexOf('{', i0), inStr = null;
for (; j < I18.length; j++) {
  const ch = I18[j], prev = I18[j - 1];
  if (inStr) { if (ch === inStr && prev !== '\\') inStr = null; continue; }
  if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
  if (ch === '{') d++; else if (ch === '}' && --d === 0) break;
}
const CODE = 'var I18N = ' + I18.slice(I18.indexOf('{', i0), j + 1) + ';\n'
  + lift(I18, 't', '04-i18n.js') + '\n' + lift(I18, 'tf', '04-i18n.js') + '\n'
  + ['seatHoldExpiryMs', 'holdExpiryPick'].map(n => lift(R5, n, '05-router.js')).join('\n') + '\n'
  + ['seatModeRuleJS', 'bedsPerLabelJS', 'cabinOfBedJS', 'unitKeyJS', 'seatAiCfg', 'seatFitsGender', 'pickSeatsSmart', 'seatAriaText']
    .map(n => lift(R6, n, '06-results.js')).join('\n');

function sandbox(settings) {
  const ctx = { window: { SHG_BOOT: { settings: settings || {} } }, Flow: { route: { type: 'sleeper' }, seats: [] }, LANG: 'en', Node: { DOCUMENT_POSITION_PRECEDING: 2 } };
  vm.createContext(ctx);
  vm.runInContext(CODE, ctx);
  return ctx;
}

/* A minimal stand-in for the seat grid pickSeatsSmart walks: two decks, six
   rows of 4 + aisle + 2 berths, the women-only berths, the emergency pair. */
function coach(opts) {
  opts = opts || {};
  const mk = (id, cls, dis) => {
    const set = new Set(cls);
    return { id, disabled: !!dis, classList: { contains: (c) => set.has(c), add: (c) => set.add(c), remove: (c) => set.delete(c) }, getAttribute: (a) => (a === 'data-id' ? id : null) };
  };
  return ['L', 'U'].map((deck) => {
    const rows = [];
    for (let r = 0; r < 6; r++) {
      const btns = [];
      for (let k = 1; k <= 6; k++) {
        const id = deck + (r * 6 + k);
        const cls = ['seat', 'slpr'];
        let dis = false;
        if (deck === 'L' && ['L1', 'L2', 'L3'].includes(id)) cls.push('fem');
        if (id === 'L3') cls.push('easy');
        if (['L5', 'L6'].includes(id)) { cls.push('reserved', 'bkd'); dis = true; }
        if ((opts.booked || []).includes(id)) { cls.push('bkd'); dis = true; }
        if ((opts.uMale || []).includes(id)) cls.push('u-male');
        btns.push(mk(id, cls, dis));
      }
      const row = { btns, querySelectorAll: (s) => (s === '.seat' ? btns : []), querySelector: (s) => (s === '.aisle' ? aisle : null) };
      const aisle = { compareDocumentPosition: (x) => (btns.indexOf(x) < 4 ? 2 : 4) };
      rows.push(row);
    }
    return { getAttribute: (a) => (a === 'data-deck' ? deck : null), querySelectorAll: (s) => (s === '.seat-row' ? rows : []) };
  });
}
const ids = (bs) => bs.map(b => b.id).join(',');
const rowOf = (id) => 'ABCDEF'[Math.floor((parseInt(id.slice(1), 10) - 1) / 6)];

console.log('\n=== Seat map premium (hold clock, labels, gender, patient) ===\n');

const js = sandbox({ seat_ai_row_order: 'rear', seat_ai_on: true, seat_ai_party_together: true });

console.log('-- 1. Hold expiry (D5-03) --');
const NOW = Date.UTC(2026, 9, 8, 6, 30, 0);           // 12:00 IST
check('expiresIn (seconds) wins: now + 1800 s', js.seatHoldExpiryMs({ expiresIn: 1800, expiresAt: '2026-10-08 12:30:00' }, NOW) === NOW + 1800000);
/* The phone's zone is switched for real (node re-reads process.env.TZ): a
   naive parse moves with it, the shipped one must not. */
const zoneWas = process.env.TZ;
['Asia/Kolkata', 'Asia/Kathmandu', 'Asia/Dubai'].forEach(function (z) {
  process.env.TZ = z;
  const naive = Date.parse('2026-10-08T12:30:00');
  check('expiresAt alone is read as IST on a ' + z + ' phone (a naive parse gives '
    + Math.round((naive - NOW) / 60000) + ' min)', js.seatHoldExpiryMs({ expiresAt: '2026-10-08 12:30:00' }, NOW) === Date.UTC(2026, 9, 8, 7, 0, 0));
});
if (zoneWas === undefined) delete process.env.TZ; else process.env.TZ = zoneWas;
check('an expiresAt with its own offset is respected', js.seatHoldExpiryMs({ expiresAt: '2026-10-08T07:00:00Z' }, NOW) === Date.UTC(2026, 9, 8, 7, 0, 0));
check('no expiry at all -> NaN (the hold is not adopted)', isNaN(js.seatHoldExpiryMs({}, NOW)));

console.log('-- 2. The expiry branch is reachable (D5-01) --');
const T = 1000000;
check('live own rows -> the server expiry', js.holdExpiryPick([T + 50], T + 40, true) === T + 40);
check('live own rows, no server answer -> earliest local expiry', js.holdExpiryPick([T + 70, T + 50], 0, true) === T + 50);
check('rows already dropped + berths still on screen -> the PAST server expiry (expired branch runs)', js.holdExpiryPick([], T - 5, true) === T - 5);
check('rows dropped, nothing picked -> 0 (no countdown, no toast)', js.holdExpiryPick([], T - 5, false) === 0);
check('nothing ever held -> 0', js.holdExpiryPick([], 0, true) === 0);

console.log('-- 3. Screen-reader labels (D5-05 / D2-03) --');
const a1 = js.seatAriaText({ disp: 'A1', floor: 'L', fem: true, state: 'avail' });
check('en: label, floor, women only, state', a1 === 'Seat A1, Lower Floor (1F), women only, Available', a1);
check('selected says Selected (never a stale "available")', js.seatAriaText({ disp: 'C1', floor: 'L', state: 'sel' }) === 'Seat C1, Lower Floor (1F), Selected');
check('upper floor named', /Upper Floor \(2F\)/.test(js.seatAriaText({ disp: 'A7', floor: 'U', state: 'avail' })));
check('cabin lock + emergency + reason are spoken',
  /men-only cabin/.test(js.seatAriaText({ disp: 'B5', floor: 'L', cab: 'm', state: 'avail' }))
  && /emergency seat, cannot be booked/.test(js.seatAriaText({ disp: 'A5', floor: 'L', state: 'emerg' }))
  && /choose/.test(js.seatAriaText({ disp: 'A1-2', floor: 'L', pvt: true, state: 'avail', why: 'Double cabin (2 people) — choose “Double cabin” above to book it.' })));
js.LANG = 'ne';
const ne = js.seatAriaText({ disp: 'A1', floor: 'L', fem: true, state: 'avail' });
check('ne: Nepali words, Roman seat code', ne === 'सिट A1, तल्लो तला (1F), महिला मात्र, उपलब्ध', ne);
js.LANG = 'en';

console.log('-- 4. Gender rule for a tapped berth (D5-M1) --');
const [L] = coach();
const byId = (id) => L.querySelectorAll('.seat-row').flatMap(r => r.btns).find(b => b.id === id);
check('Man never fits a women-only berth', js.seatFitsGender(byId('L1'), 'Male') === false);
check('Woman fits it', js.seatFitsGender(byId('L1'), 'Female') === true);
check('gender unknown never gets a women-only berth', js.seatFitsGender(byId('L2'), '') === false);
check('a plain berth fits Man', js.seatFitsGender(byId('L7'), 'Male') === true);

console.log('-- 5. Patient / elderly seats (D5-07) --');
js.Flow.patient = true;
const pm1 = ids(js.pickSeatsSmart(coach(), 1, true, 'Male'));
check('patient Man -> front of the lower deck (row B), not the rear', /^L\d+$/.test(pm1) && rowOf(pm1) === 'B', pm1);
const pm2 = ids(js.pickSeatsSmart(coach(), 2, true, 'Male')).split(',');
check('patient pair stays in the front two rows, lower deck', pm2.length === 2 && pm2.every(x => x[0] === 'L' && 'AB'.includes(rowOf(x))), pm2.join(','));
const pu = ids(js.pickSeatsSmart(coach(), 1, true, ''));
check('patient, gender unknown -> front, never a women-only berth', 'AB'.includes(rowOf(pu)) && !['L1', 'L2', 'L3'].includes(pu), pu);
const pf = ids(js.pickSeatsSmart(coach(), 1, true, 'Female'));
check('patient Woman -> the front easy-access berth A3', pf === 'L3', pf);
const pf2 = ids(js.pickSeatsSmart(coach({ booked: ['L1', 'L2', 'L3', 'L4'], uMale: ['L7', 'L8'] }), 1, true, 'Female'));
check('gender filter still wins: a woman is never put in a men-only cabin', !['L7', 'L8'].includes(pf2) && 'AB'.includes(rowOf(pf2)), pf2);
js.Flow.patient = false;
const n2 = ids(js.pickSeatsSmart(coach(), 2, false, '')).split(',');
check('non-patient picks unchanged: rear row first (seat_ai_row_order=rear)', n2.every(x => x[0] === 'L' && rowOf(x) === 'F'), n2.join(','));
const front = sandbox({ seat_ai_row_order: 'front' });
const fr = ids(front.pickSeatsSmart(coach(), 1, false, 'Male'));
check("non-patient with the 'front' setting walks front-first", 'AB'.includes(rowOf(fr)), fr);

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed\n');
process.exit(FAIL ? 1 : 0);
