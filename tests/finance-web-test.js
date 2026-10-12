/*
 * tests/finance-web-test.js — checks the website-import engine (SHGF.web).
 *
 *   node tests/finance-web-test.js [built.html | engine.js] [feed-sample.json]
 *
 * Like tests/finance-master-test.js this pulls <script id="shg-engine"> out of
 * the built finance app and runs it in a Node sandbox. Every scenario uses a
 * fresh in-memory test book and synthetic website feed rows (SPEC §2 shapes) —
 * never real company data. The optional second argument (or SHG_FEED_SAMPLE)
 * points at a real feed sample (no PII) captured from the test database.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const file = process.argv[2] || path.join(__dirname, '..', 'includes', 'finance', 'shg-finance-master.html');
const src = fs.readFileSync(file, 'utf8');
const code = file.endsWith('.js') ? src : (/<script id="shg-engine">\n([\s\S]*?)<\/script>/.exec(src) || [])[1];
if (!code) { console.error('Engine <script id="shg-engine"> not found in ' + file); process.exit(1); }
const sandbox = { console };
vm.createContext(sandbox);
vm.runInContext(code, sandbox, { filename: 'shg-engine.js' });
const E = sandbox.SHGF;
if (!E || !E.web) { console.error('SHGF.web (engine-web.js) is missing from ' + file); process.exit(1); }
const W = E.web;

let pass = 0, fail = 0;
function t(name, fn) {
  try { fn(); pass++; console.log('  ok   ' + name); }
  catch (e) { fail++; console.log('  FAIL ' + name + '\n       ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join('\n       ') : e)); }
}
function eq(a, b, msg) { if (a !== b) throw new Error((msg || '') + ' expected ' + JSON.stringify(b) + ' got ' + JSON.stringify(a)); }
function ok(v, msg) { if (!v) throw new Error(msg || 'expected truthy'); }

const TODAY = '2026-10-08';
const CEO = { id: 'u1', name: 'Test CEO', role: 'ceo' };
const ACC = { id: 'u2', name: 'Test Accountant', role: 'accountant' };
const MGMT = { id: 'u5', name: 'Viewer', role: 'management' };
const AGENT = { id: 'u4', name: 'Test Agent', role: 'agent' };
const L = (n) => Math.round(n * 100);                 // rupees -> paise

/* ---------- fixtures (SPEC §2 shapes) ---------- */
const META = {
  ok: true, part: 'meta', feedVersion: 1, generatedAt: '2026-10-08 12:00:00', tz: 'Asia/Kolkata', dbTimeZone: 'SYSTEM',
  dbSystemTimeZone: 'UTC', dbUtcOffset: '+00:00',
  company: { name: 'S Hari Global Pvt Ltd', legal: 'S Hari Global Private Limited', legalNe: 'एस हरि ग्लोबल', address: 'Mehsana, Gujarat',
    addressNe: 'मेहसाणा', phone: '+91 91048 01507', email: 'office@example.com', web: 'shreehariglobal.in', cin: 'U52291GJ2026PTC174029',
    gstin: '24ABCDE1234F1Z5', ceo: '', tagline: '', operator: 'Operator', upiId: '' },
  logo: { url: '/assets/img/logo.png' }, peg: { constant: 1.6, setting: 1.6 }, user: { id: 1, name: 'Admin', role: 'superadmin' },
  counts: {}, serverDate: TODAY, walletEnabled: true, commission: { mode: 'flat_per_seat', flatDirect: '200.00', flatJoint: '400.00', percent: '5.00' },
  rows: [], next: null, count: 0
};
function agentRow(id, o) {
  return Object.assign({ sellerId: id, code: null, name: 'Seller ' + id, username: 's' + id, role: 'agent', active: true, kind: 'org', tier: 'direct',
    overrideMode: null, overrideFlat: null, overridePercent: null, salary: null, depositRequired: '0.00', depositPaid: '0.00' }, o || {});
}
const AGENTS = [agentRow(11, { code: 'SHG-0011', name: 'Quick Agent' }), agentRow(12, { role: 'counter', name: 'Counter Desk', tier: 'joint' }),
  agentRow(36, { role: 'superadmin', name: 'Boss' })];
function sched(id, o) {
  return Object.assign({ scheduleId: id, travelDate: '2026-10-12', slot: 1, depTime: '13:00:00', status: 'scheduled', routeId: 2, routeCode: 'r5',
    fromCity: 'Surat', toCity: 'Rupaidiha', direction: 'up', busId: 2, busNumber: 'GJ-02-T-6022', busName: 'SHG Bheri Express', seats: 40,
    liveSeats: 3, liveBookings: 2 }, o || {});
}
const SCHEDS = [sched(196), sched(197, { travelDate: '2026-10-13', direction: 'down', fromCity: 'Rupaidiha', toCity: 'Surat' })];
function bk(id, o) {
  return Object.assign({
    bookingId: id, pnr: 'SHG-2026-' + String(id).padStart(5, '0'), ticketNo: 'TKT-' + id, status: 'confirmed', isCod: false, source: 'online',
    currency: 'INR', totalAmount: '1500.00', discount: '0.00', bookingFee: '0.00', taxAmount: '0.00', refundAmount: '0.00', refundStatus: 'none',
    refundRef: null, refundApprovedAt: null, sellerId: null, referralCode: null, createdAt: '2026-10-01 04:00:00', confirmedAt: '2026-10-01 10:00:00',
    cancelledAt: null, paymentId: id, paymentRef: 'PAY-' + id, paymentMethod: 'upi', paymentMode: 'online', paymentAmount: '1500.00',
    paymentStatus: 'verified', utr: 'UTR' + id, verifiedBy: 1, verifiedAt: '2026-10-01 10:00:00', paymentRows: 1, scheduleId: 196,
    travelDate: '2026-10-12', seats: 1, routeCode: 'r5', moneyState: 'collected', wm: '2026-10-01 04:30:00'
  }, o || {});
}
function lg(id, o) {
  return Object.assign({ ledgerId: id, sellerId: 11, code: 'SHG-0011', account: 'commission', entryType: 'commission', kind: 'commission', amount: '200.00',
    bookingId: null, pnr: null, bookingMissing: false, bookingStatus: null, ref: null, loanId: null, note: null, writeOff: false, createdBy: 1,
    createdAt: '2026-10-02 06:00:00' }, o || {});
}
function feed(o) {
  return Object.assign({ meta: META, agents: AGENTS, schedules: SCHEDS, bookings: [], bookingIds: null, ledger: null, paper: null, deposits: null }, o || {});
}

/* ---------- helpers ---------- */
function book() { const st = E.newBook('test', TODAY); W.ensure(st); return st; }
function journalsBalanced(st) {
  st.journals.forEach(j => {
    const dr = j.lines.reduce((a, l) => a + l.dr, 0), cr = j.lines.reduce((a, l) => a + l.cr, 0);
    ok(dr === cr && dr > 0, 'journal ' + j.no + ' unbalanced ' + dr + '/' + cr);
    j.lines.forEach(l => ok(Number.isInteger(l.dr) && Number.isInteger(l.cr) && l.dr >= 0 && l.cr >= 0 && (l.dr > 0) !== (l.cr > 0), 'bad line in ' + j.no));
  });
}
function balanced(st) {
  journalsBalanced(st);
  const tb = E.trialBalance(st, 'IN', '2099-12-31'), bs = E.balanceSheet(st, 'IN', '2099-12-31');
  ok(tb.balanced, 'trial balance must balance (' + tb.dr + ' vs ' + tb.cr + ')');
  ok(bs.balanced, 'balance sheet must balance');
}
function sync(st, f, user, opts) {
  const plan = W.plan(st, f, Object.assign({ today: TODAY }, opts || {}));
  plan.items.forEach(it => {
    if (!it.journal) return;
    const dr = it.journal.lines.reduce((a, l) => a + l.dr, 0), cr = it.journal.lines.reduce((a, l) => a + l.cr, 0);
    ok(dr === cr, 'plan item ' + it.key + ' unbalanced');
  });
  const res = W.apply(st, plan, user || CEO);
  balanced(st);
  return { plan, res };
}
const bal = (st, acc, f) => E.balance(st, 'IN', acc, f || {});
const refundsOf = (st, f) => -E.balance(st, 'IN', '4015', f || {});   // 4015 is a contra-revenue account: natural sign is negative
const webJ = (st, kind, id) => st.journals.filter(j => j.source && j.source.sys === 'web' && j.source.kind === kind &&
  (id === undefined || j.source[kind === 'booking' ? 'bookingId' : kind + 'Id'] === id));
function net(st, kind, id, acc) {   // natural-sign net of one account over one web object's journals
  let v = 0; webJ(st, kind, id).forEach(j => j.lines.forEach(l => { if (l.acc === acc) v += l.dr - l.cr; })); return v;
}

console.log('SHG Finance Master — website import engine tests');

t('accounts 1155/2015/2995/6012: in COA, in new books, added to old books once, sorted', () => {
  ['1155', '2015', '2995', '6012'].forEach(c => ok(E.COA.some(a => a.code === c), c + ' in COA'));
  const a = E.COA.find(x => x.code === '2015');
  eq(a.type, 'liability'); eq(a.party, 'agent'); eq(E.COA.find(x => x.code === '1155').kind, 'clearing');
  eq(E.COA.find(x => x.code === '6012').type, 'expense'); eq(E.COA.find(x => x.code === '2995').party, 'agent');
  const nb = E.newBook('test', TODAY);
  ok(E.account(nb, '2995'), 'new books get the accounts');
  const old = E.newBook('test', TODAY);
  old.accounts = old.accounts.filter(x => ['1155', '2015', '2995', '6012'].indexOf(x.code) < 0);
  delete old.web;
  W.ensure(old); W.ensure(old);
  eq(old.accounts.filter(x => x.code === '2015').length, 1, 'added once');
  const codes = old.accounts.map(x => x.code);
  ok(codes.join() === codes.slice().sort().join(), 'accounts stay sorted by code');
  ok(old.web.reg.bk && old.web.map.sellers && Array.isArray(old.web.sync.runs) && old.web.sync.bookings, 'st.web structure');
});

t('settings: defaults merged with saved overrides, never mutating the book', () => {
  const st = book();
  eq(W.settings(st).cutover, '2026-04-01');
  eq(W.settings(st).methodAccounts.esewa, '1155');
  st.web.settings = { cutover: '2026-05-01', methodAccounts: { upi: '1110' }, includeNonAgentSellers: false };
  const s = W.settings(st);
  eq(s.cutover, '2026-05-01'); eq(s.methodAccounts.upi, '1110'); eq(s.methodAccounts.cash, '@seller'); eq(s.includeNonAgentSellers, false);
  eq(s.refundAccount, '1110');
  eq(JSON.stringify(st.web.settings), JSON.stringify({ cutover: '2026-05-01', methodAccounts: { upi: '1110' }, includeNonAgentSellers: false }));
  eq(W.settings({}).entity, 'IN', 'works without st.web');
});

t('fast sha256 = E.sha256 (ASCII, Devanagari, long input) and IST date conversion', () => {
  ['', 'abc', 'वेबसाइट SHG-2026-00009 — बिक्री', 'x'.repeat(1000), '{"a":1}'].forEach(s => eq(W.sha256(s), E.sha256(s), 'sha of ' + s.slice(0, 10)));
  eq(W.istDate('2026-10-05 20:00:00', true, '+00:00'), '2026-10-06', 'UTC TIMESTAMP 20:00 = IST 01:30 next day');
  eq(W.istDate('2026-10-05 20:00:00', true, '+05:30'), '2026-10-05', 'DB already in IST');
  eq(W.istDate('2026-10-05 23:50:00', false, '+00:00'), '2026-10-05', 'DATETIME columns are IST already');
  eq(W.istDate('2026-10-05T18:29:59', true, '+00:00'), '2026-10-05');
  eq(W.istDate('2026-10-05T18:30:00', true, '+00:00'), '2026-10-06');
  eq(W.istDate(null, true, '+00:00'), '');
});

t('plan() is pure: the book is byte-identical after planning', () => {
  const st = book();
  const before = JSON.stringify(st);
  const p = W.plan(st, feed({ bookings: [bk(1), bk(2, { paymentMethod: 'cash', sellerId: 11 })], ledger: [lg(1, { bookingId: 2, pnr: 'SHG-2026-00002' })], paper: [], deposits: [] }), { today: TODAY });
  ok(p.items.length >= 3, 'has items');
  eq(JSON.stringify(st), before, 'plan must not mutate the book');
  // also on a book that already holds imported data, a code-matched agent and app-side changes
  E.addAgent(st, { code: 'SHG-0011', name: 'App agent' }, CEO);
  W.apply(st, W.plan(st, feed({ bookings: [bk(1)], ledger: [], paper: [], deposits: [] }), { today: TODAY }), CEO);
  const before2 = JSON.stringify(st);
  const p2 = W.plan(st, feed({ agents: AGENTS.map(a => Object.assign({}, a, { name: a.name + '!' })), schedules: [sched(196, { liveSeats: 7 })],
    bookings: [bk(1, { status: 'cancelled', refundAmount: '100.00', refundStatus: 'pending', cancelledAt: '2026-10-02 10:00:00' }), bk(3, { paymentMethod: 'cash', sellerId: 36 })],
    bookingIds: [[3, 'x']], ledger: [lg(5, { sellerId: 36 })], paper: [], deposits: [{ depositId: 1, sellerId: 12, amount: '10.00', ref: '', createdAt: '2026-01-01 00:00:00' }] }), { today: TODAY });
  ok(p2.items.length >= 3 && p2.updates.agents.length && p2.updates.trips.length, 'second plan has work to do');
  eq(JSON.stringify(st), before2, 'plan must not mutate a populated book either');
});

t('collected UPI sale → Dr 1150 / Cr 4010 tagged to the trip; masters created with plan ids; source + idem as SPEC', () => {
  const st = book();
  const { plan, res } = sync(st, feed({ bookings: [bk(1)] }));
  ok(res.ok, res.errors.join(' '));
  eq(res.posted, 1);
  eq(plan.summary.bookings.new, 1);
  const j = webJ(st, 'booking', 1)[0];
  eq(j.approvedBy, 'website');
  eq(j.date, '2026-10-01');
  ok(/^web:bk:1:[0-9a-f]{16}$/.test(j.idem), 'idem web:bk:<id>:<hash> got ' + j.idem);
  eq(j.ref, 'UTR1');
  ok(j.memo.indexOf('वेबसाइट SHG-2026-00001 — बिक्री (UPI)') === 0, j.memo);
  eq(j.source.sys, 'web'); eq(j.source.kind, 'booking'); eq(j.source.bookingId, 1); eq(j.source.pnr, 'SHG-2026-00001');
  eq(j.source.paymentRef, 'PAY-1'); eq(j.source.utr, 'UTR1'); eq(j.source.ticketNo, 'TKT-1'); eq(j.source.scheduleId, 196);
  eq(j.source.state, 'collected'); eq(j.source.hash, j.idem.split(':')[3]);
  eq(bal(st, '1150'), L(1500)); eq(bal(st, '4010'), L(1500));
  const trip = st.trips.find(x => x.id === 'wt_196');
  ok(trip, 'trip wt_196 created');
  eq(trip.extId, 'web:sched:196'); eq(trip.capacity, 40); eq(trip.pax, 3); eq(trip.dir, 'up'); eq(trip.vehicleId, 'wv_2');
  eq(st.vehicles.find(v => v.id === 'wv_2').extId, 'web:bus:2');
  eq(bal(st, '4010', { trip: 'wt_196' }), L(1500), 'revenue tagged to the trip');
  eq(E.tripResult(st, trip).net, L(1500));
  eq(st.web.map.schedules[196], 'wt_196'); eq(st.web.map.buses[2], 'wv_2');
  ok(st.agents.some(a => a.id === 'wa_11' && a.code === 'SHG-0011' && a.extId === 'web:11'), 'website agents become app agents');
  ok(st.agents.some(a => a.id === 'wa_12' && a.code === 'WEB-12' && a.tier === 'team'), 'counter role, joint → team');
  ok(!st.agents.some(a => a.id === 'wa_36'), 'non-agent sellers only when they have activity');
  eq(st.web.reg.bk[1].pnr, 'SHG-2026-00001');
  eq(st.web.sync.bookings.since, '2026-10-01 04:30:00'); eq(st.web.sync.bookings.afterId, 1);
  eq(st.web.sync.runs.length, 1);
  ok(st.audit.some(a => a.a === 'web.sync'), 'audit row');
  const again = W.plan(st, feed({ bookings: [bk(1)] }), { today: TODAY });
  eq(again.items.length, 0, 're-plan → nothing'); eq(again.summary.bookings.unchanged, 1);
  eq(again.create.agents.length + again.create.trips.length + again.create.vehicles.length, 0, 'no masters twice');
});

t('agent cash sale → 1210 for the seller agent; non-agent cash seller flagged; eSewa → 1155', () => {
  const st = book();
  const { plan, res } = sync(st, feed({ bookings: [bk(2, { paymentMethod: 'cash', sellerId: 11, utr: null, source: 'agent' }),
    bk(3, { paymentMethod: 'cash', sellerId: 36, utr: null }), bk(4, { paymentMethod: 'esewa' })] }));
  ok(res.ok, res.errors.join(' '));
  eq(bal(st, '1210', { party: 'wa_11' }), L(1500));
  eq(bal(st, '1210', { party: 'wa_36' }), L(1500), 'superadmin seller included by default');
  ok(plan.warnings.some(w => w.indexOf('गैर-एजेन्ट') >= 0), 'non-agent seller warning');
  eq(bal(st, '1155'), L(1500));
  eq(webJ(st, 'booking', 2)[0].ref, 'SHG-2026-00002', 'ref falls back to the PNR');
  ok(webJ(st, 'booking', 2)[0].memo.indexOf('बिक्री (नगद)') > 0);
  const st2 = book();
  st2.web.settings.includeNonAgentSellers = false;
  const r2 = sync(st2, feed({ bookings: [bk(3, { paymentMethod: 'cash', sellerId: 36 })] }));
  ok(r2.res.ok);
  eq(bal(st2, '1030'), L(1500), 'excluded seller → counter cash account');
  ok(!st2.agents.some(a => a.extId === 'web:36'));
  const r3 = W.plan(st2, feed({ ledger: [lg(9, { sellerId: 36 })] }), { today: TODAY });
  eq(r3.items.length, 0); eq(r3.summary.ledger.skipped, 1, 'excluded seller ledger rows skipped');
});

t('COD: due → Dr 1220 web-customers; settled later → Dr 1210 / Cr 1220, dated the settle day, revenue untouched', () => {
  const st = book();
  const due = bk(5, { isCod: true, paymentMethod: 'cod', paymentStatus: 'cod_pending', moneyState: 'cod_due', sellerId: 11, verifiedAt: null, utr: null });
  let r = sync(st, feed({ bookings: [due] }));
  ok(r.res.ok);
  const cust = st.people.find(p => p.extId === 'web:customers');
  ok(cust && cust.id === 'wp_customers' && cust.kind === 'customer', 'web-customers person');
  eq(bal(st, '1220', { party: 'wp_customers' }), L(1500)); eq(bal(st, '4010'), L(1500));
  ok(webJ(st, 'booking', 5)[0].memo.indexOf('COD') > 0);
  const settled = Object.assign({}, due, { paymentStatus: 'verified', moneyState: 'collected', verifiedAt: '2026-10-04 18:00:00', wm: '2026-10-04 12:30:00' });
  r = sync(st, feed({ bookings: [settled] }));
  ok(r.res.ok); eq(r.res.posted, 1); eq(r.plan.summary.bookings.changed, 1);
  const js = webJ(st, 'booking', 5);
  eq(js.length, 2);
  eq(js[1].date, '2026-10-04');
  eq(js[1].lines.length, 2, 'only the receivable moves');
  eq(bal(st, '1220', { party: 'wp_customers' }), 0); eq(bal(st, '1210', { party: 'wa_11' }), L(1500)); eq(bal(st, '4010'), L(1500));
  ok(js[1].memo.indexOf('COD नगद उठ्यो') > 0, js[1].memo);
  const cancelledUncollected = Object.assign({}, due, { bookingId: 6, pnr: 'SHG-2026-00006', status: 'cancelled', moneyState: 'cancelled_uncollected', cancelledAt: '2026-10-02 10:00:00' });
  r = sync(st, feed({ bookings: [cancelledUncollected] }));
  eq(r.plan.items.length, 0, 'cancelled before the cash was collected → nothing');
});

t('cancel with pending refund → Dr 4015 / Cr 2040; processed later → Dr 2040 / Cr refund account (TIMESTAMP date in IST)', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(7, { totalAmount: '2000.00', paymentAmount: '2000.00' })] }));
  const canc = bk(7, { totalAmount: '2000.00', paymentAmount: '2000.00', status: 'cancelled', moneyState: 'cancelled_after_collection',
    cancelledAt: '2026-10-03 09:00:00', refundAmount: '1500.00', refundStatus: 'pending', wm: '2026-10-03 03:30:00' });
  let r = sync(st, feed({ bookings: [canc] }));
  ok(r.res.ok);
  let js = webJ(st, 'booking', 7);
  eq(js.length, 2); eq(js[1].date, '2026-10-03');
  ok(js[1].memo.indexOf('रद्द/रिफन्ड') > 0, js[1].memo);
  eq(refundsOf(st), L(1500)); eq(bal(st, '2040', { party: 'wp_customers' }), L(1500)); eq(bal(st, '1150'), L(2000));
  eq(refundsOf(st, { trip: 'wt_196' }), L(1500), 'refund tagged to the trip');
  const proc = Object.assign({}, canc, { refundStatus: 'processed', refundRef: 'NEFT-1', refundApprovedAt: '2026-10-05 20:00:00', wm: '2026-10-05 20:00:00' });
  r = sync(st, feed({ bookings: [proc] }));
  ok(r.res.ok);
  js = webJ(st, 'booking', 7);
  eq(js.length, 3); eq(js[2].date, '2026-10-06', 'refund.approve 20:00 UTC = 01:30 IST next day');
  ok(js[2].memo.indexOf('रिफन्ड भुक्तानी') > 0, js[2].memo);
  eq(bal(st, '2040', { party: 'wp_customers' }), 0); eq(bal(st, '1110'), -L(1500)); eq(refundsOf(st), L(1500));
  eq(E.profitLoss(st, 'IN', '2026-01-01', '2026-12-31').revenue, L(500), 'net revenue 2000 − 1500');
});

t('denied refund: nothing booked; a pending refund later denied is reversed', () => {
  const st = book();
  const den = bk(8, { status: 'cancelled', moneyState: 'cancelled_after_collection', cancelledAt: '2026-10-03 09:00:00', refundAmount: '1000.00', refundStatus: 'denied' });
  let r = sync(st, feed({ bookings: [den] }));
  ok(r.res.ok);
  eq(refundsOf(st), 0); eq(bal(st, '4010'), L(1500), 'company keeps the fare');
  const st2 = book();
  sync(st2, feed({ bookings: [Object.assign({}, den, { refundStatus: 'pending' })] }));
  eq(bal(st2, '2040', { party: 'wp_customers' }), L(1000));
  r = sync(st2, feed({ bookings: [den] }));
  ok(r.res.ok); eq(r.res.posted, 1);
  eq(bal(st2, '2040', { party: 'wp_customers' }), 0); eq(refundsOf(st2), 0);
});

t('per-seat cancel: payment ₹3,000, refund ₹750 pending ⇒ net revenue ₹2,250', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(9, { totalAmount: '3000.00', paymentAmount: '3000.00', seats: 3 })] }));
  const r = sync(st, feed({ bookings: [bk(9, { totalAmount: '2000.00', paymentAmount: '3000.00', seats: 2, refundAmount: '750.00', refundStatus: 'pending', wm: '2026-10-02 04:30:00' })] }));
  ok(r.res.ok);
  eq(bal(st, '4010') - refundsOf(st), L(2250));
  eq(E.profitLoss(st, 'IN', '2026-01-01', '2026-12-31').revenue, L(2250), 'P&L revenue is net of the refund');
  eq(bal(st, '1150'), L(3000), 'collected cash is the payment, not the reduced total');
  eq(bal(st, '2040', { party: 'wp_customers' }), L(750));
  eq(webJ(st, 'booking', 9)[1].date, '2026-10-02', 'no cancelledAt → wm date (IST)');
});

t('reject after confirm reverses; re-approve re-posts exactly once; same plan applied twice is a duplicate', () => {
  const st = book();
  const conf = bk(10, { paymentMethod: 'cash', sellerId: 11 });
  sync(st, feed({ bookings: [conf] }));
  const rej = Object.assign({}, conf, { status: 'rejected', paymentStatus: 'rejected', moneyState: 'rejected', wm: '2026-10-02 05:00:00' });
  let r = sync(st, feed({ bookings: [rej] }));
  ok(r.res.ok);
  eq(bal(st, '4010'), 0); eq(bal(st, '1210', { party: 'wa_11' }), 0);
  ok(webJ(st, 'booking', 10)[1].memo.indexOf('उल्टाइयो') > 0);
  eq(webJ(st, 'booking', 10)[1].date, '2026-10-02');
  const re = Object.assign({}, conf, { wm: '2026-10-03 05:00:00' });
  const plan = W.plan(st, feed({ bookings: [re] }), { today: TODAY });
  eq(plan.items.length, 1);
  r = { res: W.apply(st, plan, CEO) };
  ok(r.res.ok); eq(r.res.posted, 1);
  eq(bal(st, '4010'), L(1500)); eq(bal(st, '1210', { party: 'wa_11' }), L(1500));
  const ids = webJ(st, 'booking', 10).map(j => j.idem);
  eq(new Set(ids).size, ids.length, 'every delta has its own idem key (hash repeats get a suffix)');
  const again = W.apply(st, plan, CEO);
  ok(again.ok); eq(again.posted, 0); eq(again.duplicates, 1, 'same plan twice → duplicate');
  eq(bal(st, '4010'), L(1500));
  eq(W.plan(st, feed({ bookings: [re] }), { today: TODAY }).items.length, 0);
  balanced(st);
});

t('hard delete (missing from booking_ids) reverses everything; registry entry removed', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(11), bk(12, { paymentMethod: 'cash', sellerId: 11 })], bookingIds: [[11, 'SHG-2026-00011'], [12, 'SHG-2026-00012']] }));
  eq(bal(st, '4010'), L(3000));
  const r = sync(st, feed({ bookings: [], bookingIds: [[11, 'SHG-2026-00011']] }));
  ok(r.res.ok); eq(r.plan.summary.bookings.deleted, 1);
  const j = webJ(st, 'booking', 12).pop();
  ok(j.memo.indexOf('हटाइएको') > 0, j.memo); eq(j.source.state, 'deleted'); eq(j.date, TODAY);
  eq(bal(st, '4010'), L(1500)); eq(bal(st, '1210', { party: 'wa_11' }), 0);
  ok(!st.web.reg.bk[12] && st.web.reg.bk[11], 'registry updated');
  eq(W.plan(st, feed({ bookings: [], bookingIds: [[11, 'SHG-2026-00011']] }), { today: TODAY }).items.length, 0);
  const noIds = W.plan(st, feed({ bookings: [] }), { today: TODAY });
  eq(noIds.summary.bookings.deleted, 0, 'without booking_ids nothing is treated as deleted');
});

t('re-sync with overlap (same rows, duplicated pages) → no duplicates', () => {
  const st = book();
  const rows = [bk(13), bk(14, { paymentMethod: 'cash', sellerId: 12 }), bk(15, { status: 'pending', paymentStatus: 'pending', moneyState: 'awaiting_payment', confirmedAt: null })];
  sync(st, feed({ bookings: rows.concat([bk(13)]) }));
  const n = st.journals.length;
  const r = sync(st, feed({ bookings: rows.concat(rows) }));
  eq(r.plan.items.length, 0); eq(st.journals.length, n);
  eq(r.plan.summary.bookings.unchanged, 3);
  const older = bk(13, { status: 'cancelled', wm: '2026-09-01 00:00:00' });
  const p = W.plan(st, feed({ bookings: [bk(13), older] }), { today: TODAY });
  eq(p.items.length, 0, 'of two copies the newest wm wins');
});

t('agent ledger: commission/void/payout/handover/salary/advance/repay/write-off/paper/adjustments', () => {
  const st = book();
  const b = bk(20, { paymentMethod: 'cash', sellerId: 11, scheduleId: 197 });
  const L1 = [
    lg(101, { bookingId: 20, pnr: b.pnr, ref: b.pnr, amount: '200.00' }),
    lg(102, { bookingId: 20, pnr: b.pnr, account: 'cash', entryType: 'cash_due', kind: 'cash_due', amount: '1500.00' }),
    lg(103, { bookingId: 20, pnr: b.pnr, entryType: 'commission_void', kind: 'commission_void', amount: '-200.00' }),
    lg(104, { entryType: 'commission', kind: 'commission', amount: '300.00', ref: 'PB-7' }),
    lg(105, { kind: 'paper_ticket_commission', amount: '100.00', ref: 'P-55' }),
    lg(106, { account: 'cash', entryType: 'cash_due', kind: 'paper_ticket_cash', amount: '900.00', ref: 'P-55' }),
    lg(107, { entryType: 'payout', kind: 'payout', amount: '-250.00', ref: 'SV-261003-107' }),
    lg(108, { account: 'cash', entryType: 'cash_handover', kind: 'cash_handover', amount: '-1000.00', ref: 'SV-261003-108' }),
    lg(109, { entryType: 'adjustment', kind: 'salary', amount: '5000.00', ref: 'SALARY 2026-09' }),
    lg(110, { entryType: 'adjustment', kind: 'loan', amount: '-2000.00', ref: 'ADVANCE L3', loanId: 3 }),
    lg(111, { entryType: 'adjustment', kind: 'loan', amount: '500.00', ref: 'ADVANCE L3', loanId: 3 }),
    lg(112, { entryType: 'adjustment', kind: 'loan', amount: '1500.00', ref: 'ADVANCE L3', loanId: 3, writeOff: true }),
    lg(113, { entryType: 'adjustment', kind: 'adjustment', amount: '50.00', ref: 'fix' }),
    lg(114, { account: 'cash', entryType: 'adjustment', kind: 'adjustment', amount: '-20.00', ref: 'short' }),
    lg(115, { sellerId: 12, code: null, amount: '400.00' })
  ];
  const paper = [{ paperId: 1, sellerId: 11, paperTicketNo: 'P-55', bookingId: null, pnr: null, shape: 'register_only', routeCode: 'r5',
    travelDate: '2026-10-05', pax: 1, amount: '900.00', commission: '100.00', paymentMode: 'cash', status: 'recorded', createdAt: '2026-10-02 06:00:00' },
  { paperId: 2, sellerId: 11, paperTicketNo: 'P-56', bookingId: 20, pnr: b.pnr, shape: 'seated_booking', routeCode: 'r5', travelDate: '2026-10-05',
    pax: 1, amount: '1500.00', commission: '200.00', paymentMode: 'cash', status: 'recorded', createdAt: '2026-10-02 06:00:00' },
  { paperId: 3, sellerId: 11, paperTicketNo: 'P-57', bookingId: null, pnr: null, shape: 'register_only', routeCode: 'r5', travelDate: '2026-10-05',
    pax: 1, amount: '700.00', commission: '0.00', paymentMode: 'upi', status: 'recorded', createdAt: '2026-10-02 06:00:00' }];
  const { plan, res } = sync(st, feed({ bookings: [b], ledger: L1, paper }));
  ok(res.ok, res.errors.join(' '));
  eq(plan.summary.ledger.unchanged, 2, 'cash_due with booking + paper_ticket_cash post nothing');
  eq(plan.summary.paper.new, 2); eq(plan.summary.paper.unchanged, 1, 'seated paper ticket = the booking');
  // commission: 200 − 200 + 300 + 100 + 50(adj) = 450 on 5040 (seller 11) and 400 (seller 12)
  eq(bal(st, '5040', { party: 'wa_11' }), L(450));
  eq(bal(st, '5040', { party: 'wa_12' }), L(400));
  eq(bal(st, '5040', { trip: 'wt_197' }), 0, 'commission + void on the same trip net to 0');
  eq(net(st, 'ledger', 101, '5040'), L(200));
  ok(webJ(st, 'ledger', 101)[0].lines.some(l => l.acc === '5040' && l.trip === 'wt_197'), 'booking commission trip-tagged');
  eq(bal(st, '6012', { party: 'wa_11' }), L(5000), 'salary');
  eq(bal(st, '6090'), L(1500), 'write-off');
  eq(bal(st, '1010'), L(-2000 + 500), 'advance out, repayment in');
  eq(bal(st, '1110'), L(-250 + 1000), 'payout out, handover in');
  eq(bal(st, '1150'), L(700), 'upi paper ticket');
  eq(bal(st, '2995', { party: 'wa_11' }), L(-20), 'cash adjustment parked for review');
  ok(webJ(st, 'ledger', 114)[0].memo.indexOf('जाँच बाँकी') > 0);
  // website view of the agent: commission balance and cash held must match the app
  const comW = 200 - 200 + 300 + 100 - 250 + 5000 - 2000 + 500 + 1500 + 50;
  const cashW = 1500 + 900 - 1000 - 20;
  eq(bal(st, '2020', { party: 'wa_11' }), L(comW), 'commission payable = website commission account');
  eq(bal(st, '1210', { party: 'wa_11' }), L(cashW), 'cash held = website cash account');
  const pos = E.agentPosition(st, 'IN', 'wa_11', '2099-12-31');
  eq(pos.net, L(cashW - comW), 'agent net position = website cash − commission');
  const j = webJ(st, 'ledger', 107)[0];
  eq(j.source.ledgerId, 107); eq(j.source.sellerId, 11); eq(j.source.entryType, 'payout'); eq(j.source.ref, 'SV-261003-107');
  ok(/^web:lg:107:[0-9a-f]{16}$/.test(j.idem)); eq(j.date, '2026-10-02');
  const pj = webJ(st, 'paper', 1)[0];
  eq(pj.source.ref, 'P-55'); ok(/^web:pp:1:/.test(pj.idem));
  ok(st.web.sync.ledgerFullAt, 'full ledger stamp');
  eq(W.plan(st, feed({ bookings: [b], ledger: L1, paper }), { today: TODAY }).items.length, 0, 're-plan → nothing');
  // a deleted ledger row (missing from the full list) is reversed; a voided paper ticket too
  const paper2 = paper.map(p => p.paperId === 3 ? Object.assign({}, p, { status: 'void' }) : p);
  const r2 = sync(st, feed({ ledger: L1.filter(x => x.ledgerId !== 109), paper: paper2 }));
  ok(r2.res.ok); eq(r2.plan.summary.ledger.deleted, 1);
  eq(bal(st, '6012', { party: 'wa_11' }), 0); eq(bal(st, '1150'), 0);
  ok(webJ(st, 'ledger', 109).pop().memo.indexOf('हटाइएको') > 0);
  ok(!(109 in st.web.reg.lg));
  // ledger === null → ledger untouched
  eq(W.plan(st, feed({ ledger: null }), { today: TODAY }).summary.ledger.deleted, 0);
});

t('orphan cash_due (booking hard-deleted) keeps the agent owing via 2995; booking reversal + orphan agree', () => {
  const st = book();
  const b = bk(30, { paymentMethod: 'cash', sellerId: 11 });
  const cd = lg(301, { bookingId: 30, pnr: b.pnr, account: 'cash', entryType: 'cash_due', kind: 'cash_due', amount: '1500.00' });
  sync(st, feed({ bookings: [b], bookingIds: [[30, b.pnr]], ledger: [cd] }));
  eq(bal(st, '1210', { party: 'wa_11' }), L(1500));
  const r = sync(st, feed({ bookings: [], bookingIds: [], ledger: [Object.assign({}, cd, { bookingMissing: true })] }));
  ok(r.res.ok);
  eq(bal(st, '4010'), 0, 'revenue reversed');
  eq(bal(st, '1210', { party: 'wa_11' }), L(1500), 'website still says the agent holds the cash');
  eq(bal(st, '2995', { party: 'wa_11' }), L(1500), 'parked for review');
});

t('deposits: received Dr bank / Cr 2015, refunded the other way; deleted deposit reversed', () => {
  const st = book();
  const deps = [{ depositId: 1, sellerId: 11, amount: '5000.00', ref: 'DEP-1', createdAt: '2026-10-02 06:00:00' },
    { depositId: 2, sellerId: 11, amount: '-2000.00', ref: 'DEP-R', createdAt: '2026-10-03 06:00:00' }];
  const r = sync(st, feed({ deposits: deps }));
  ok(r.res.ok);
  eq(bal(st, '2015', { party: 'wa_11' }), L(3000)); eq(bal(st, '1110'), L(3000));
  eq(webJ(st, 'deposit', 1)[0].source.depositId, 1);
  ok(/^web:dp:1:/.test(webJ(st, 'deposit', 1)[0].idem));
  eq(W.plan(st, feed({ deposits: deps }), { today: TODAY }).items.length, 0);
  const r2 = sync(st, feed({ deposits: deps.slice(0, 1) }));
  eq(r2.plan.summary.deposits.deleted, 1);
  eq(bal(st, '2015', { party: 'wa_11' }), L(5000));
});

t('cutover: older bookings skipped; older ledger/deposit rows → ONE opening per seller dated cutover−1', () => {
  const st = book();
  const old = bk(40, { confirmedAt: '2026-03-20 10:00:00', paymentMethod: 'cash', sellerId: 11 });
  const rows = [
    lg(401, { amount: '600.00', createdAt: '2026-03-10 06:00:00' }),
    lg(402, { account: 'cash', entryType: 'cash_due', kind: 'cash_due', bookingId: 40, pnr: old.pnr, amount: '4000.00', createdAt: '2026-03-20 06:00:00' }),
    lg(403, { amount: '-100.00', entryType: 'payout', kind: 'payout', createdAt: '2026-03-25 06:00:00' }),
    lg(404, { amount: '200.00', createdAt: '2026-04-02 06:00:00' }),
    lg(405, { sellerId: 12, code: null, account: 'cash', entryType: 'cash_due', kind: 'cash_due', bookingId: 41, pnr: 'SHG-2026-00041', amount: '800.00', createdAt: '2026-03-31 18:45:00' }),
    lg(406, { sellerId: 12, code: null, account: 'cash', entryType: 'cash_due', kind: 'cash_due', bookingId: 40, pnr: old.pnr, amount: '300.00', createdAt: '2026-05-02 06:00:00' })
  ];
  const deps = [{ depositId: 9, sellerId: 11, amount: '1000.00', ref: 'D', createdAt: '2026-01-05 06:00:00' }];
  const { plan, res } = sync(st, feed({ bookings: [old], ledger: rows, deposits: deps }));
  ok(res.ok, res.errors.join(' '));
  eq(plan.summary.bookings.skipped, 1); eq(webJ(st, 'booking').length, 0, 'pre-cutover booking never posted');
  eq(plan.summary.ledger.skipped, 3, '401-403 (405 is 2026-04-01 00:15 IST → after cutover)');
  const op = webJ(st, 'opening');
  eq(op.length, 2, 'ledger part + deposits part for seller 11');
  op.forEach(j => { eq(j.date, '2026-03-31'); eq(j.source.sellerId, 11); ok(/^web:open:11:/.test(j.idem)); });
  eq(bal(st, '2020', { party: 'wa_11' }), L(600 - 100 + 200), 'opening commission 500 + new 200');
  eq(bal(st, '1210', { party: 'wa_11' }), L(4000), 'opening cash');
  eq(bal(st, '2015', { party: 'wa_11' }), L(1000), 'opening deposit');
  eq(bal(st, '3050'), L(4000 - 500 - 1000 + 300), 'opening equity balances it (+300 = cash of a pre-cutover booking collected later)');
  eq(bal(st, '1210', { party: 'wa_12' }), L(300), 'post-cutover cash_due of a pre-cutover booking → 1210 vs 3050 (405: its booking import carries the cash)');
  ok(webJ(st, 'ledger', 406)[0].lines.some(l => l.acc === '3050'));
  eq(W.plan(st, feed({ bookings: [old], ledger: rows, deposits: deps }), { today: TODAY }).items.length, 0, 're-plan → nothing');
  const r2 = sync(st, feed({ ledger: rows.filter(x => x.ledgerId !== 403), deposits: deps }));
  ok(r2.res.ok); eq(r2.plan.summary.opening.items, 1, 'opening adjusts when a pre-cutover row disappears');
  eq(bal(st, '2020', { party: 'wa_11' }), L(800));
  eq(webJ(st, 'opening').length, 3);
});

t('app-PNR conflict: an app agent sale with the same PNR blocks the website booking and its commission', () => {
  const st = book();
  const ag = E.addAgent(st, { code: 'SHG-0099', name: 'App agent' }, CEO).agent;
  ok(E.recordAgentSale(st, { agentId: ag.id, date: '2026-10-01', pax: 1, amount: L(1500), entity: 'IN', collectedBy: 'agent', pnr: 'SHG-2026-00050' }, CEO).ok);
  const b = bk(50, { paymentMethod: 'cash', sellerId: 11 });
  const { plan, res } = sync(st, feed({ bookings: [b], ledger: [lg(501, { bookingId: 50, pnr: b.pnr })] }));
  ok(res.ok);
  eq(plan.summary.bookings.conflicts, 1); eq(plan.conflicts.length, 1); eq(plan.conflicts[0].pnr, 'SHG-2026-00050');
  ok(plan.warnings.some(w => w.indexOf('टकराव') === 0 && w.indexOf('SHG-0099') > 0), 'warning lists both');
  eq(webJ(st, 'booking').length, 0); eq(webJ(st, 'ledger').length, 0, 'commission not doubled');
  eq(bal(st, '4010'), L(1500), 'only the app sale');
});

t('agent matched by code is linked, not duplicated; website edits update imported agents and trip pax', () => {
  const st = book();
  const ag = E.addAgent(st, { code: 'SHG-0011', name: 'Existing app agent' }, CEO).agent;
  sync(st, feed({ bookings: [bk(60, { paymentMethod: 'cash', sellerId: 11 })] }));
  eq(st.agents.filter(a => a.code === 'SHG-0011').length, 1);
  eq(ag.extId, 'web:11'); eq(st.web.map.sellers[11], ag.id);
  eq(bal(st, '1210', { party: ag.id }), L(1500));
  eq(ag.name, 'Existing app agent', 'linked app agents keep their app name');
  const agents2 = AGENTS.map(a => a.sellerId === 12 ? Object.assign({}, a, { name: 'Counter Desk 2', active: false }) : a);
  const p = W.plan(st, feed({ agents: agents2, schedules: [sched(196, { liveSeats: 9 })] }), { today: TODAY });
  eq(p.updates.agents.length, 1); eq(p.updates.trips.length, 1);
  W.apply(st, p, CEO);
  eq(st.agents.find(a => a.id === 'wa_12').name, 'Counter Desk 2'); eq(st.agents.find(a => a.id === 'wa_12').active, false);
  eq(st.trips.find(x => x.id === 'wt_196').pax, 9);
  const p2 = W.plan(st, feed({ agents: agents2, schedules: [sched(196, { liveSeats: 9 })] }), { today: TODAY });
  eq(p2.updates.agents.length + p2.updates.trips.length + p2.items.length, 0);
});

t('reschedule moves revenue to the new trip; NPR booking converted at the peg; lock date respected', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(70)] }));
  sync(st, feed({ bookings: [bk(70, { scheduleId: 197, wm: '2026-10-02 04:30:00' })] }));
  eq(bal(st, '4010', { trip: 'wt_196' }), 0); eq(bal(st, '4010', { trip: 'wt_197' }), L(1500)); eq(bal(st, '4010'), L(1500));
  const r = sync(st, feed({ bookings: [bk(71, { currency: 'NPR', totalAmount: '1600.00', paymentAmount: '1600.00', paymentMethod: 'esewa' })] }));
  ok(r.res.ok);
  eq(net(st, 'booking', 71, '1155'), L(1000), 'NPR 1600 / 1.6 = INR 1000');
  eq(webJ(st, 'booking', 71)[0].source.currency, 'NPR');
  st.settings.lockDate = '2026-10-05';
  sync(st, feed({ bookings: [bk(72)] }));
  eq(webJ(st, 'booking', 72)[0].date, '2026-10-06', 'never inside the locked period');
});

t('apply needs the post permission; partial failure posts the rest and retries later', () => {
  const st = book();
  const plan = W.plan(st, feed({ bookings: [bk(80)] }), { today: TODAY });
  const r0 = W.apply(st, plan, MGMT);
  ok(!r0.ok && r0.errors.length && st.journals.length === 0, 'management cannot import');
  ok(!W.apply(st, plan, AGENT).ok, 'agent cannot import');
  ok(W.apply(st, plan, ACC).ok, 'accountant can');
  eq(st.journals[0].approvedBy, 'website');
  const st2 = book();
  st2.web.settings.methodAccounts = { esewa: '9999' };
  const p2 = W.plan(st2, feed({ bookings: [bk(81), bk(82, { paymentMethod: 'esewa', wm: '2026-10-05 00:00:00' })] }), { today: TODAY });
  ok(p2.items.find(i => i.id === 82).warnings.some(w => w.indexOf('9999') >= 0), 'unknown account warned in the plan');
  const r2 = W.apply(st2, p2, CEO);
  ok(!r2.ok); eq(r2.posted, 1); eq(r2.errors.length, 1);
  ok(!st2.web.reg.bk[82] && st2.web.reg.bk[81], 'registry only for posted items');
  eq(st2.web.sync.bookings.since, '', 'watermark not advanced while a booking failed');
  eq(st2.web.sync.runs[0].counts.errors, 1);
  balanced(st2);
  st2.web.settings.methodAccounts = {};
  const p3 = W.plan(st2, feed({ bookings: [bk(81), bk(82, { paymentMethod: 'esewa', wm: '2026-10-05 00:00:00' })] }), { today: TODAY });
  eq(p3.items.length, 1, 'only the failed one is retried');
  ok(W.apply(st2, p3, CEO).ok);
  eq(st2.web.sync.bookings.since, '2026-10-05 00:00:00');
});

t('stale plan refused; a manual reversal in the app is re-imported with a fresh idem key', () => {
  const st = book();
  const p1 = W.plan(st, feed({ bookings: [bk(90)] }), { today: TODAY });
  const p2 = W.plan(st, feed({ bookings: [bk(90, { paymentAmount: '1600.00', totalAmount: '1600.00' })] }), { today: TODAY });
  ok(W.apply(st, p1, CEO).ok);
  const r = W.apply(st, p2, CEO);
  ok(!r.ok && r.errors[0].indexOf('फेरि सिंक') > 0, 'stale item refused');
  eq(bal(st, '4010'), L(1500));
  const j = webJ(st, 'booking', 90)[0];
  ok(E.reverseJournal(st, j.id, { reason: 'test' }, CEO).ok);
  eq(bal(st, '4010'), 0);
  const p3 = W.plan(st, feed({ bookings: [bk(90)] }), { today: TODAY });
  eq(p3.items.length, 1, 'website still says sold → re-post');
  ok(p3.items[0].journal.idem !== j.idem && p3.items[0].journal.idem.indexOf(j.idem) === 0, 'suffixed idem ' + p3.items[0].journal.idem);
  ok(W.apply(st, p3, CEO).ok);
  eq(bal(st, '4010'), L(1500));
  balanced(st);
});

t('reconcile: day × bucket site vs app, cancelled/deleted excluded, refunds and commission totals', () => {
  const st = book();
  const rows = [bk(100), bk(101, { paymentMethod: 'cash', sellerId: 11 }), bk(102, { paymentMethod: 'cod', sellerId: 11 }),
    bk(103, { isCod: true, paymentMethod: 'cod', paymentStatus: 'cod_pending', moneyState: 'cod_due', sellerId: 11 }),
    bk(104, { status: 'cancelled', cancelledAt: '2026-10-01 12:00:00', refundAmount: '1000.00', refundStatus: 'pending' }),
    bk(105, { totalAmount: '1000.00', paymentAmount: '1500.00', refundAmount: '400.00', refundStatus: 'pending', confirmedAt: '2026-10-02 09:00:00' })];
  sync(st, feed({ bookings: rows, ledger: [lg(1001, { amount: '200.00', createdAt: '2026-10-01 06:00:00' }), lg(1002, { amount: '-200.00', entryType: 'commission_void', kind: 'commission_void', createdAt: '2026-10-01 07:00:00' }), lg(1003, { amount: '200.00', createdAt: '2026-10-01 08:00:00' })] }));
  const daybook = { from: '2026-10-01', to: '2026-10-02', rows: [
    { day: '2026-10-01', bucket: 'upi', bookings: 1, amount: '1500.00' },
    { day: '2026-10-01', bucket: 'cash', bookings: 2, amount: '3000.00' },
    { day: '2026-10-01', bucket: 'cod_pending', bookings: 1, amount: '1500.00' },
    { day: '2026-10-02', bucket: 'upi', bookings: 2, amount: '2500.00' },
    { day: '2026-03-01', bucket: 'upi', bookings: 5, amount: '100.00' }],
  totals: { commissionAccrued: '200.00', refundsProcessed: '0.00', refundsPending: '1000.00', refundsAll: '1000.00' } };
  const rc = W.reconcile(st, daybook, '2026-10-01', '2026-10-02');
  const row = (d, b) => rc.rows.find(r => r.day === d && r.bucket === b);
  ok(row('2026-10-01', 'upi').ok, 'upi matches');
  ok(row('2026-10-01', 'cash').ok, 'cash + settled cod in the cash bucket');
  ok(row('2026-10-01', 'cod_pending').ok, 'cod still to collect');
  const r2 = row('2026-10-02', 'upi');
  eq(r2.app.count, 1); eq(r2.diff.count, -1); eq(r2.diff.amount, L(1000) - L(2500), 'site has one booking the app does not');
  eq(r2.app.amount, L(1000), 'app side uses the booking total like the site');
  ok(!rc.rows.some(r => r.day === '2026-03-01'), 'outside the range ignored');
  eq(rc.totals.site.count, 6); eq(rc.totals.app.count, 5); ok(!rc.ok);
  eq(rc.totals.refunds.app.pending, L(1000)); eq(rc.totals.refunds.diff.pending, 0);
  eq(rc.totals.commission.app, L(200)); eq(rc.totals.commission.diff, 0);
  const all = W.reconcile(st, daybook.rows.filter(r => r.day === '2026-10-01'), '2026-10-01', '2026-10-01');
  ok(all.ok, 'a fully imported day reconciles');
});

t('mergeCompany: entity details + brand (logo kept), audit row', () => {
  const st = book();
  st.settings.brand = { logo: 'data:image/png;base64,AAAA' };
  const r = W.mergeCompany(st, META, CEO);
  ok(r.ok);
  const en = st.entities[0];
  eq(en.name, 'S Hari Global Pvt Ltd'); eq(en.cin, 'U52291GJ2026PTC174029'); eq(en.gstin, '24ABCDE1234F1Z5');
  eq(en.phone, '+91 91048 01507'); eq(en.email, 'office@example.com'); eq(en.web, 'shreehariglobal.in'); eq(en.address, 'Mehsana, Gujarat');
  eq(st.settings.brand.company.legalNe, 'एस हरि ग्लोबल'); eq(st.settings.brand.logoUrl, '/assets/img/logo.png');
  eq(st.settings.brand.logo, 'data:image/png;base64,AAAA', 'stored logo preserved');
  ok(st.settings.brand.updatedAt); eq(st.web.company.cin, 'U52291GJ2026PTC174029');
  ok(st.audit.some(a => a.a === 'web.company'));
  const before = en.cin;
  W.mergeCompany(st, { company: { cin: '', name: 'X' }, logo: { url: 'javascript:alert(1)' } });
  eq(en.cin, before, 'empty website values never blank app data'); eq(st.settings.brand.logoUrl, '', 'only root-relative logo paths');
  ok(!W.mergeCompany(st, {}).ok);
});

t('every web journal is safe for E.unsafeId (ids, parties, trips)', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(110), bk(111, { paymentMethod: 'cash', sellerId: 36 })], ledger: [lg(1101, { bookingId: 110, pnr: 'SHG-2026-00110' })], deposits: [] }));
  eq(E.unsafeId(st), '', 'imported book passes the restore check');
  if (typeof E.validateBook === 'function') {           // ENGINE-CORE v2 schema check (feature-detected)
    const v = E.validateBook(st);
    ok(v.ok, 'validateBook: ' + (v.errors || []).slice(0, 3).join(' | '));
  }
});

/* ---------- the real feed sample (FEED part, shg_test, no PII) ---------- */
const SAMPLE = process.argv[3] || process.env.SHG_FEED_SAMPLE ||
  '/tmp/claude-0/-home-user-shreehari/ed00eaf7-1512-590f-9788-dda89ca81664/scratchpad/work-feed/feed-sample.json';
t('real feed sample: plan + apply balances, cancelled cash sales keep cash with the agent, re-plan → nothing', () => {
  let s;
  try { s = JSON.parse(fs.readFileSync(SAMPLE, 'utf8')); } catch (e) { console.log('       (sample not found — skipped: ' + SAMPLE + ')'); return; }
  const st = book();
  const f = { meta: s.meta, agents: s.agents, schedules: s.schedules, bookings: s.bookings, bookingIds: s.booking_ids,
    ledger: s.ledger, paper: s.paper, deposits: s.deposits };
  const plan = W.plan(st, f, { today: '2026-10-09' });
  const res = W.apply(st, plan, CEO);
  ok(res.ok, res.errors.join(' | '));
  balanced(st);
  const bookings = s.bookings.rows;
  eq(plan.summary.bookings.new, bookings.length);
  const gross = bookings.reduce((a, b) => a + E.parseMoney(b.paymentAmount), 0);
  const refunds = bookings.reduce((a, b) => a + (b.refundStatus === 'pending' ? E.parseMoney(b.refundAmount) : 0), 0);
  eq(bal(st, '4010'), gross); eq(refundsOf(st), refunds); eq(bal(st, '2040'), refunds);
  // website agent position = SUM(amount) per account, for every seller
  const per = {};
  s.ledger.rows.forEach(r => { const p = per[r.sellerId] || (per[r.sellerId] = { commission: 0, cash: 0 }); p[r.account] += E.parseMoney(r.amount); });
  Object.keys(per).forEach(sid => {
    const aid = st.web.map.sellers[sid];
    ok(aid, 'seller ' + sid + ' mapped');
    eq(bal(st, '2020', { party: aid }), per[sid].commission, 'commission payable seller ' + sid);
    eq(bal(st, '1210', { party: aid }), per[sid].cash, 'cash held seller ' + sid);
  });
  ok(plan.warnings.length > 0, 'orphan cash rows flagged');
  const again = W.plan(st, f, { today: '2026-10-09' });
  eq(again.items.length, 0, 're-plan → nothing');
  const rc = W.reconcile(st, s.daybook, s.daybook.from, s.daybook.to);
  ok(rc.ok, 'sample day-book (all cancelled) reconciles');
  eq(rc.totals.refunds.app.pending, E.parseMoney(s.daybook.totals.refundsPending), 'pending refunds match the site');
  eq(rc.totals.commission.diff, 0, 'commission accrued matches the site');
});

/* ---------- volume ---------- */
t('volume: 5,000 bookings + 5,000 ledger rows plan+apply < 5 s; re-plan → zero items', () => {
  const st = book();
  const B = [], LG = [], ids = [];
  const methods = ['upi', 'cash', 'esewa', 'bank', 'cod'];
  for (let i = 1; i <= 5000; i++) {
    const m = methods[i % 5], seller = m === 'cash' || m === 'cod' ? 11 + (i % 2) : null;
    const o = { paymentMethod: m, sellerId: seller, scheduleId: 196 + (i % 2), totalAmount: '1500.00', paymentAmount: '1500.00',
      confirmedAt: '2026-0' + (5 + (i % 5)) + '-1' + (i % 9) + ' 10:00:00', wm: '2026-10-01 ' + String(10 + (i % 10)) + ':00:00' };
    if (i % 7 === 0) Object.assign(o, { status: 'cancelled', cancelledAt: '2026-10-02 10:00:00', refundAmount: '1000.00', refundStatus: i % 14 === 0 ? 'processed' : 'pending', refundApprovedAt: i % 14 === 0 ? '2026-10-03 10:00:00' : null });
    if (i % 11 === 0) Object.assign(o, { status: 'pending', paymentStatus: 'pending', moneyState: 'awaiting_payment', confirmedAt: null });
    B.push(bk(i, o)); ids.push([i, 'x']);
    LG.push(lg(i, { sellerId: 11 + (i % 2), bookingId: i, pnr: 'SHG-2026-' + String(i).padStart(5, '0'), amount: i % 13 === 0 ? '-200.00' : '200.00',
      entryType: i % 13 === 0 ? 'commission_void' : 'commission', kind: i % 13 === 0 ? 'commission_void' : 'commission', createdAt: '2026-0' + (5 + (i % 5)) + '-1' + (i % 9) + ' 06:00:00' }));
  }
  const f = feed({ bookings: B, bookingIds: ids, ledger: LG, paper: [], deposits: [] });
  const t0 = Date.now();
  const plan = W.plan(st, f, { today: TODAY });
  const t1 = Date.now();
  const res = W.apply(st, plan, CEO);
  const t2 = Date.now();
  console.log('       plan ' + (t1 - t0) + ' ms, apply ' + (t2 - t1) + ' ms, items ' + plan.items.length + ', journals ' + st.journals.length);
  ok(res.ok, res.errors.slice(0, 3).join(' | '));
  ok(t2 - t0 < 5000, 'plan+apply took ' + (t2 - t0) + ' ms');
  balanced(st);
  const t3 = Date.now();
  const again = W.plan(st, f, { today: TODAY });
  console.log('       re-plan ' + (Date.now() - t3) + ' ms');
  eq(again.items.length, 0, 're-plan → zero items');
  eq(again.summary.bookings.unchanged, 5000);
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
