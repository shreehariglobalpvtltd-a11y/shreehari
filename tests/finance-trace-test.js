/*
 * tests/finance-trace-test.js — checks "every transaction traceable" (SHGF.trace).
 *
 *   node tests/finance-trace-test.js [built.html | engine.js]
 *
 * Like tests/finance-master-test.js this pulls <script id="shg-engine"> out of
 * the built finance app and runs it in a Node sandbox. Every scenario uses a
 * fresh in-memory test book (and synthetic website feed rows in the SPEC §2
 * shape) — never real company data.
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
if (!E || !E.trace) { console.error('SHGF.trace (engine-trace.js) is missing from ' + file); process.exit(1); }
const T = E.trace;

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
const FIN = { id: 'u3', name: 'Test Finance', role: 'finance' };
const L = (n) => Math.round(n * 100);                 // rupees -> paise
function book() { const st = E.newBook('test', TODAY); if (E.web) E.web.ensure(st); return st; }
function money(st, user, d) {
  const b = E.buildMoneyJournal(st, Object.assign({ entity: 'IN', date: TODAY, account: '1110' }, d));
  if (b.errors) throw new Error(b.errors.join(' '));
  return E.commit(st, user, b.journal, { level: b.level });
}
const ids = (list) => list.map(j => j.id);
const rel = (tl, j) => (tl.related.find(r => r.journal.id === j.id) || {}).relation;

/* ---------- website feed fixtures (SPEC §2 shapes, as in finance-web-test.js) ---------- */
const META = {
  ok: true, part: 'meta', feedVersion: 1, generatedAt: '2026-10-08 12:00:00', tz: 'Asia/Kolkata', dbTimeZone: 'SYSTEM', dbSystemTimeZone: 'UTC', dbUtcOffset: '+00:00',
  company: { name: 'S Hari Global Pvt Ltd', cin: 'U52291GJ2026PTC174029' }, logo: { url: '/assets/img/logo.png' }, peg: { constant: 1.6, setting: 1.6 },
  user: { id: 1, name: 'Admin', role: 'superadmin' }, counts: {}, serverDate: TODAY, walletEnabled: true,
  commission: { mode: 'flat_per_seat', flatDirect: '200.00', flatJoint: '400.00', percent: '5.00' }, rows: [], next: null, count: 0
};
const AGENTS = [{ sellerId: 11, code: 'SHG-0011', name: 'Quick Agent', username: 's11', role: 'agent', active: true, kind: 'org', tier: 'direct', overrideMode: null,
  overrideFlat: null, overridePercent: null, salary: null, depositRequired: '0.00', depositPaid: '0.00' }];
const SCHEDS = [{ scheduleId: 196, travelDate: '2026-10-12', slot: 1, depTime: '13:00:00', status: 'scheduled', routeId: 2, routeCode: 'r5', fromCity: 'Surat',
  toCity: 'Rupaidiha', direction: 'up', busId: 2, busNumber: 'GJ-02-T-6022', busName: 'SHG Bheri Express', seats: 40, liveSeats: 3, liveBookings: 2 }];
function bk(id, o) {
  return Object.assign({
    bookingId: id, pnr: 'SHG-2026-' + String(id).padStart(5, '0'), ticketNo: 'TKT-' + id, status: 'confirmed', isCod: false, source: 'online',
    currency: 'INR', totalAmount: '1500.00', discount: '0.00', bookingFee: '0.00', taxAmount: '0.00', refundAmount: '0.00', refundStatus: 'none',
    refundRef: null, refundApprovedAt: null, sellerId: 11, referralCode: null, createdAt: '2026-10-01 04:00:00', confirmedAt: '2026-10-01 10:00:00',
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
function feed(o) { return Object.assign({ meta: META, agents: AGENTS, schedules: SCHEDS, bookings: [], bookingIds: null, ledger: null, paper: null, deposits: null }, o || {}); }
function sync(st, f) {
  const plan = E.web.plan(st, f, { today: TODAY });
  const res = E.web.apply(st, plan, CEO);
  ok(res.errors.length === 0, 'web apply errors: ' + res.errors.join(' | '));
  return res;
}
/** confirmed → cancelled with pending refund → refund processed → hard deleted (+ a commission ledger row) */
function webStory() {
  const st = book();
  sync(st, feed({ bookings: [bk(9)], bookingIds: [[9, 'SHG-2026-00009'], [10, 'SHG-2026-00010']], ledger: [lg(501, { bookingId: 9, pnr: 'SHG-2026-00009' })] }));
  sync(st, feed({ bookings: [bk(9, { status: 'cancelled', cancelledAt: '2026-10-03 09:00:00', refundAmount: '1500.00', refundStatus: 'pending', moneyState: 'cancelled_after_collection', wm: '2026-10-03 09:00:00' })] }));
  sync(st, feed({ bookings: [bk(9, { status: 'cancelled', cancelledAt: '2026-10-03 09:00:00', refundAmount: '1500.00', refundStatus: 'processed', refundApprovedAt: '2026-10-04 08:00:00', refundRef: 'RF-77', moneyState: 'cancelled_after_collection', wm: '2026-10-04 08:00:00' })] }));
  const before = st.journals.length;
  sync(st, feed({ bookings: [], bookingIds: [[10, 'SHG-2026-00010']] }));
  return { st, before };
}

console.log('SHG Finance Master — trace engine tests');

/* ================= find ================= */
t('parseAmount: "1250", "1,250.50", "₹1,250", "Rs. 1250", Devanagari digits; rejects text', () => {
  eq(T.parseAmount('1250'), 125000);
  eq(T.parseAmount('1,250.50'), 125050);
  eq(T.parseAmount('₹1,250'), 125000);
  eq(T.parseAmount('Rs. 1250'), 125000);
  eq(T.parseAmount('रु १,२५०'), 125000);
  eq(T.parseAmount('1,00,000'), 10000000, 'Indian grouping');
  ok(isNaN(T.parseAmount('SHG-2026-00009')) && isNaN(T.parseAmount('12a')) && isNaN(T.parseAmount('')) && isNaN(T.parseAmount('0')), 'non-amounts');
});

t('find: journal no (IN-00002 / "in 2"), journal id, empty query', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(100000), memo: 'opening cash' });
  const r = money(st, CEO, { cat: 'out_rent', amount: L(12500), memo: 'office rent' });
  let h = T.find(st, 'IN-00002');
  eq(h[0].type, 'journal'); eq(h[0].id, r.journal.id); ok(h[0].score >= 1000);
  h = T.find(st, 'in 2'); eq(h[0].id, r.journal.id, 'loose journal no');
  h = T.find(st, r.journal.id); eq(h[0].id, r.journal.id, 'journal id');
  eq(T.find(st, '   ').length, 0); eq(T.find(st, null).length, 0);
});

t('find: website PNR exact beats partial; UTR, bk:9 / booking 9 / bare 9, amount formats', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(9), bk(19, { utr: 'UTR19X' })], bookingIds: null }));
  let h = T.find(st, 'SHG-2026-00009');
  eq(h[0].type, 'pnr'); eq(h[0].id, 'SHG-2026-00009'); ok(h[0].journals.length >= 1);
  ok(!h.some(x => x.type === 'pnr' && x.id === 'SHG-2026-00019' && x.score > h[0].score), 'partial never above exact');
  h = T.find(st, 'shg-2026-00009'); eq(h[0].id, 'SHG-2026-00009', 'case-insensitive');
  h = T.find(st, '00019'); ok(h.some(x => x.type === 'pnr' && x.id === 'SHG-2026-00019'), 'partial PNR');
  h = T.find(st, 'UTR9'); eq(h[0].type, 'ref'); eq(h[0].id, 'UTR9');
  h = T.find(st, 'bk:9'); eq(h[0].type, 'web'); eq(h[0].id, 'booking:9');
  h = T.find(st, 'booking 9'); eq(h[0].id, 'booking:9', 'spaced prefix');
  h = T.find(st, '9'); ok(h.some(x => x.type === 'web' && x.id === 'booking:9'), 'bare number finds website booking id');
  const j9 = st.journals.find(j => j.source && j.source.bookingId === 9);
  ['1500', '1,500.00', '₹1,500', 'Rs 1500'].forEach(q => {
    h = T.find(st, q);
    ok(h.some(x => x.type === 'amount' && x.journals[0] === j9.id), 'amount ' + q);
  });
});

t('find: agent code / name / website seller id, party name, pending id, bank line ref', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(500000) });
  const ag = E.addAgent(st, { code: 'SHG-0007', name: 'Ramesh Travels', tier: 'direct' }, CEO).agent;
  ag.extId = 'web:77';
  E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 2, amount: L(4000), entity: 'IN', collectedBy: 'agent', pnr: 'AGP-1' }, ACC);
  const emp = E.addEmployee(st, { name: 'Sita Driver', cat: 'driver', base: L(20000) }, CEO);
  money(st, CEO, { cat: 'out_advance', amount: L(1000), party: emp.id });
  let h = T.find(st, 'SHG-0007');
  eq(h[0].type, 'agent'); eq(h[0].id, ag.id); ok(h[0].journals.length >= 1);
  h = T.find(st, 'ramesh'); eq(h[0].type, 'agent', 'name prefix');
  h = T.find(st, 'travels'); ok(h.some(x => x.type === 'agent' && x.id === ag.id), 'name contains');
  h = T.find(st, 'seller 77'); eq(h[0].type, 'agent', 'website seller id'); eq(h[0].sellerId, 77);
  h = T.find(st, 'sita'); ok(h.some(x => x.type === 'party' && x.id === emp.id && x.journals.length === 1), 'employee name');
  h = T.find(st, 'AGP-1'); eq(h[0].type, 'pnr', 'agent-sale PNR'); eq(h[0].journals.length, 1);
  // a big payment by an accountant waits for approval
  const q = money(st, ACC, { cat: 'out_rent', amount: L(90000), memo: 'warehouse rent' });
  ok(q.queued, 'queued');
  h = T.find(st, q.pending.id); eq(h[0].type, 'pending'); eq(h[0].journals.length, 0);
  E.importStatement(st, '1110', [{ date: TODAY, desc: 'NEFT RAMESH', amount: L(4000), ref: 'NEFTREF123' }], CEO);
  h = T.find(st, 'NEFTREF123'); eq(h[0].type, 'bank'); eq(h[0].bankLine, st.bankLines[0].id);
  h = T.find(st, 'neft ramesh'); ok(h.some(x => x.type === 'bank'), 'bank description');
  h = T.find(st, 'warehouse'); ok(h.some(x => x.type === 'pending'), 'pending label');
});

t('find: ranking order is exact > id-like > amount > names > memo text', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(1250), memo: 'cash 1250 counted', ref: 'R1250X' });
  const h = T.find(st, '1250');
  ok(h.length >= 2, 'several hits');
  for (let i = 1; i < h.length; i++) ok(h[i - 1].score >= h[i].score, 'sorted by score');
  eq(h[0].type, 'amount');
  ok(T.find(st, 'counted')[0].type === 'journal' && T.find(st, 'counted')[0].score < 300, 'memo text is the weakest');
});

/* ================= timeline ================= */
t('timeline: web booking confirmed → refund pending → processed → hard deleted (merged by PNR)', () => {
  const { st, before } = webStory();
  ok(st.journals.length > before, 'delete posted a reversal delta');
  const bkJ = st.journals.filter(j => j.source && j.source.sys === 'web' && j.source.kind === 'booking' && j.source.bookingId === 9);
  ok(bkJ.length === 4, 'four booking deltas, got ' + bkJ.length);
  const tl = T.timeline(st, bkJ[0].id);
  eq(tl.journal.id, bkJ[0].id);
  bkJ.slice(1).forEach(j => eq(rel(tl, j), 'web:booking', 'same booking relation'));
  const lgJ = st.journals.find(j => j.source && j.source.kind === 'ledger' && j.source.ledgerId === 501);
  eq(rel(tl, lgJ), 'pnr', 'commission ledger row joined through the PNR');
  const titles = tl.events.map(e => e.title).join(' | ');
  ok(/बुकिङ पक्का/.test(titles) && /रद्द भयो/.test(titles) && /रिफन्ड तिर्न बाँकी/.test(titles) && /रिफन्ड तिरिएको/.test(titles) && /मेटाइएको/.test(titles), titles);
  for (let i = 1; i < tl.events.length; i++) ok(String(tl.events[i - 1].t) <= String(tl.events[i].t), 'events in time order');
  const hrefs = tl.links.map(l => l.href);
  ok(hrefs.includes('/admin/booking-view.php?pnr=SHG-2026-00009'), hrefs.join());
  ok(hrefs.includes('/admin/payments.php?tab=all&q=SHG-2026-00009'));
  ok(hrefs.some(h => /^\/admin\/refunds\.php\?tab=(pending|processed)&pnr=SHG-2026-00009$/.test(h)));
  ok(hrefs.includes('/admin/agent.php?agent=11'));
  ok(hrefs.includes('/admin/agent-receipt.php?id=501'));
  ok(hrefs.includes('/admin/manifest.php?date=2026-10-12&sid=196'), 'manifest needs the travel date of the schedule');
  ok(hrefs.every(h => h.indexOf('/admin/') === 0), 'root-relative admin links only');
  ok(tl.integrity.ok && tl.integrity.all && tl.integrity.seq === bkJ[0].seq && tl.integrity.h === bkJ[0].h, 'integrity of a clean book');
  // net money path of the whole booking story is zero for revenue (all undone) — accounts with zero net are dropped
  const p = T.pnr(st, 'shg-2026-00009');
  eq(p.query, 'SHG-2026-00009');
  eq(p.journals.length, 5, 'four booking deltas + one ledger row');
  ok(!p.flow.accounts.some(a => a.acc === '4010'), 'revenue nets to zero after the delete');
  ok(p.events.length >= tl.events.length - 1);
  // find() through the deep-link query lands on the same story
  const h = T.find(st, 'SHG-2026-00009');
  eq(h[0].type, 'pnr'); eq(h[0].journals.length, 5);
});

t('timeline: website PNR known but never posted (pending payment) still traces', () => {
  const st = book();
  sync(st, feed({ bookings: [bk(30, { status: 'pending', confirmedAt: null, paymentStatus: 'pending', moneyState: 'awaiting_payment' })] }));
  ok(!st.journals.some(j => j.source && j.source.bookingId === 30), 'nothing posted');
  const h = T.find(st, 'SHG-2026-00030');
  eq(h[0].type, 'pnr'); eq(h[0].journals.length, 0);
  const p = T.pnr(st, 'SHG-2026-00030');
  ok(p && p.events.length && /हिसाबमा अझै लेखिएको छैन/.test(p.events[0].title));
  ok(p.links.some(l => l.href === '/admin/booking-view.php?pnr=SHG-2026-00030'));
  eq(T.pnr(st, 'NOPE-0000-000'), null, 'unknown PNR');
});

t('timeline: agent sale → partial cancel → reverse cancel → reverse sale', () => {
  const st = book();
  const ag = E.addAgent(st, { code: 'SHG-0008', name: 'Eight', tier: 'direct' }, CEO).agent;
  const s = E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 4, amount: L(8000), entity: 'IN', collectedBy: 'agent', pnr: 'PNR-AS-1' }, CEO);
  ok(s.ok, (s.errors || []).join());
  const c = E.cancelAgentSale(st, s.sale.id, { amount: L(2000), pax: 1, date: TODAY, reason: 'one seat' }, CEO);
  ok(c.ok, (c.errors || []).join());
  const cj = c.journal;
  const rc = E.reverseJournal(st, cj.id, { reason: 'wrong cancel' }, CEO); ok(rc.ok, (rc.errors || []).join());
  const rs = E.reverseJournal(st, s.journal.id, { reason: 'wrong sale' }, CEO); ok(rs.ok, (rs.errors || []).join());
  const tl = T.timeline(st, s.journal.id);
  eq(rel(tl, cj), 'sale', 'cancel is the same sale');
  eq(rel(tl, rs.journal), 'reversal', 'mirror of the sale');
  eq(rel(tl, rc.journal), 'sale', 'reversal of the cancel is in the same family');
  ok(tl.events.filter(e => e.kind === 'reverse').length === 2, 'two reverse events');
  ok(tl.flow.zero, 'everything undone → zero money path');
  ok(tl.source.label.indexOf('PNR-AS-1') >= 0 && tl.source.icon === '🎫');
  const tr = T.timeline(st, rs.journal.id);
  eq(rel(tr, s.journal), 'original', 'from the mirror back to the original');
  eq(tr.links.length, 0, 'no website links for app entries');
  const p = T.pnr(st, 'pnr-as-1'); eq(p.journals.length, 4);
});

t('timeline: payroll accrue → pay are one run; pending approval history shows', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  E.addEmployee(st, { name: 'Driver', cat: 'driver', base: L(25000) }, CEO);
  const r = E.postPayroll(st, 'IN', '2026-09', E.payrollDraft(st, 'IN', '2026-09'), FIN);
  ok(r.ok, (r.errors || []).join());
  const p = E.payPayroll(st, r.run.id, '1110', TODAY, CEO);
  ok(p.ok, (p.errors || []).join());
  const acc = E.journal(st, r.run.journalId), paid = E.journal(st, r.run.paidJournalId);
  const tl = T.timeline(st, acc.id);
  eq(rel(tl, paid), 'run');
  eq(T.timeline(st, paid.id).journal.id, paid.id);
  ok(tl.flow.cash.some(c => c.acc === '1110' && c.net === -L(25000)), 'money path: bank paid the salary');
  ok(tl.flow.accounts.some(a => a.acc === '6010' && a.net === L(25000)) && !tl.flow.accounts.some(a => a.acc === '2030'), 'salary payable nets out');
  // maker-checker: accountant submits, CEO approves → submit + approve events, no duplicate audit rows
  const q = money(st, ACC, { cat: 'out_rent', amount: L(60000), memo: 'rent Q' });
  ok(q.queued);
  const a = E.approve(st, q.pending.id, CEO, 'looks fine');
  ok(a.ok, (a.errors || []).join());
  const tq = T.timeline(st, a.journal.id);
  const kinds = tq.events.map(e => e.kind);
  ok(kinds.includes('submit') && kinds.includes('approve') && kinds.includes('post'), kinds.join());
  ok(!tq.events.some(e => e.kind === 'audit' && /^approval\./.test(e.action || '')), 'approval audit rows folded into the history');
  ok(tq.events.some(e => e.kind === 'approve' && e.detail === 'looks fine' && e.user === CEO.name));
});

t('timeline: intercompany pair, loan family, bank match + unmatch, attachments, audit by journal no', () => {
  const st = book();
  st.entities[1].enabled = true;
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  const r = E.intercompany(st, { from: 'IN', to: 'NP', amount: L(10000), date: TODAY, agreement: 'IC-1', fromAccount: '1110', toAccount: '1110' }, CEO);
  ok(r.ok, (r.errors || []).join());
  const tl = T.timeline(st, r.journals[0].id);
  eq(rel(tl, r.journals[1]), 'ic');
  // a receipt with an attachment, matched to a bank line, then unmatched
  const b = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'in_other', amount: L(777), ref: 'UTRBANK1', attach: 'receipt.jpg' });
  const pj = E.commit(st, CEO, b.journal, { level: b.level }).journal;
  E.importStatement(st, '1110', [{ date: TODAY, desc: 'UPI IN', amount: L(777), ref: 'UTRBANK1' }], CEO);
  eq(E.autoMatch(st, 'IN', '1110'), 1);
  E.audit(st, CEO, 'bank.match', TODAY + ' ₹777');
  let tp = T.timeline(st, pj.id);
  ok(tp.events.some(e => e.kind === 'bank' && e.bankLine === st.bankLines[0].id), 'bank match event');
  ok(tp.events.some(e => e.kind === 'attach' && e.detail === 'receipt.jpg'), 'attachment event');
  E.unmatchLine(st, st.bankLines[0].id, CEO);
  E.audit(st, CEO, 'note', 'checked ' + pj.no + ' with the bank');
  E.audit(st, CEO, 'note', 'unrelated ' + pj.no + '0');
  tp = T.timeline(st, pj.id);
  ok(!tp.events.some(e => e.kind === 'bank'), 'no longer matched');
  ok(tp.events.some(e => e.kind === 'audit' && e.action === 'bank.unmatch'), 'unmatch audit row (refs.before.matched)');
  eq(tp.events.filter(e => e.kind === 'audit' && e.action === 'note').length, 1, 'audit detail naming the journal no (word-exact)');
  // loans: receipt and later repayment share the loan
  const lr = E.addLoan(st, { entity: 'IN', type: 'director', lender: { name: 'Director L' }, principal: L(50000), rate: 12, start: TODAY, account: '1110', receiveNow: true }, CEO);
  ok(lr.ok && lr.receipt && lr.receipt.ok, JSON.stringify(lr.errors || ''));
  {
    const rp = money(st, CEO, { cat: 'out_loanrepay', amount: L(5000), loan: lr.loan.id });
    ok(rp.ok, (rp.errors || []).join());
    const tlo = T.timeline(st, rp.journal.id);
    ok(tlo.related.some(x => x.relation === 'loan' && x.journal.id === lr.receipt.journal.id), 'repayment linked to the loan receipt');
  }
});

/* ================= integrity ================= */
t('integrity: clean book ok; a tampered memo is caught by the timeline and verifyChain (cached, force re-check)', () => {
  const st = book();
  for (let i = 0; i < 5; i++) money(st, CEO, { cat: 'in_other', amount: L(100 + i) });
  let v = T.integrity(st);
  ok(v.ok && v.journals.count === 5 && v.checkedAt);
  eq(T.integrity(st), v, 'cached while nothing changed');
  const j = st.journals[2];
  j.memo = 'edited after posting';
  eq(T.integrity(st), v, 'cache keyed on lengths + heads (in-place edit needs force)');
  v = T.integrity(st, true);
  ok(!v.ok); eq(v.journals.brokenAt, 3, 'broken at seq 3');
  const tl = T.timeline(st, j.id);
  ok(!tl.integrity.ok && /बदलिएको/.test(tl.integrity.why), 'per-journal seal fails');
  ok(T.timeline(st, st.journals[1].id).integrity.ok, 'earlier journal still sealed');
  // deleting a row breaks the link of the next one
  const st2 = book();
  for (let i = 0; i < 4; i++) money(st2, CEO, { cat: 'in_other', amount: L(10 + i) });
  st2.journals.splice(1, 1); E.invalidate(st2);
  ok(!T.integrity(st2, true).ok);
  ok(!T.timeline(st2, st2.journals[1].id).integrity.ok, 'slot/prev mismatch after a delete');
});

t('integrity: incremental on append (tail sealed), full re-check on delete / force; peek never computes', () => {
  const st = book();
  eq(T.integrity(st, false, true), null, 'peek before any check');
  for (let i = 0; i < 3; i++) money(st, CEO, { cat: 'in_other', amount: L(50 + i) });
  let v = T.integrity(st);
  ok(v.ok && v.incremental === false && v.fullAt, 'first check is full');
  eq(T.integrity(st, false, true), v, 'peek returns the cache');
  money(st, CEO, { cat: 'in_other', amount: L(60) });
  eq(T.integrity(st, false, true), null, 'peek after a change');
  v = T.integrity(st);
  ok(v.ok && v.incremental === true && v.journals.count === 4, 'append → only the tail is checked');
  // a new row forged without a valid seal is caught by the tail check
  money(st, CEO, { cat: 'in_other', amount: L(61) });
  st.journals[4].lines[0].dr = st.journals[4].lines[1].cr = L(9999);
  v = T.integrity(st);
  ok(!v.ok && v.journals.brokenAt === 5 && v.incremental, 'tampered tail row');
  // a deleted row is never treated as an append
  const st2 = book();
  for (let i = 0; i < 4; i++) money(st2, CEO, { cat: 'in_other', amount: L(70 + i) });
  ok(T.integrity(st2).ok);
  st2.journals.splice(3, 1); E.invalidate(st2);
  v = T.integrity(st2);
  ok(!v.ok && v.incremental === false, 'deleted newest journal → full check finds it');
});

/* ================= index + performance ================= */
t('index: built once, extended on append, rebuilt when the array is replaced', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(10) });
  T.find(st, 'x1'); const b1 = T.index(st).builds;
  money(st, CEO, { cat: 'in_other', amount: L(11), ref: 'NEWREF99' });
  eq(T.find(st, 'NEWREF99')[0].type, 'ref', 'appended journal is searchable');
  eq(T.index(st).builds, b1, 'append extends, no rebuild');
  st.journals = st.journals.slice(); E.invalidate(st);
  T.find(st, 'x'); eq(T.index(st).builds, b1 + 1, 'replaced array → rebuild');
});

t('perf: find() over 20,000 journals < 200 ms (index once per journals.length)', () => {
  const st = book();
  const ag = E.addAgent(st, { code: 'SHG-0100', name: 'Perf Agent', tier: 'direct' }, CEO).agent;
  // build 20,000 sealed journals fast (direct chain via postJournal)
  for (let i = 0; i < 20000; i++) {
    const amt = 1000 + (i % 997) * 100;
    const r = E.postJournal(st, { entity: 'IN', date: TODAY, memo: 'perf ' + i, ref: 'UTRP' + i, cat: 'in_other',
      source: i % 3 === 0 ? { sys: 'web', kind: 'booking', bookingId: i, pnr: 'SHG-2026-' + String(i).padStart(5, '0') } : null,
      lines: [{ acc: '1210', party: ag.id, dr: amt, cr: 0 }, { acc: '4010', dr: 0, cr: amt }] }, CEO);
    if (!r.ok) throw new Error(r.errors.join());
  }
  eq(st.journals.length, 20000);
  let t0 = process.hrtime.bigint();
  let h = T.find(st, 'SHG-2026-12345');
  const first = Number(process.hrtime.bigint() - t0) / 1e6;
  eq(h[0].type, 'pnr');
  const qs = ['UTRP19999', 'IN-15000', '₹1,000.00', 'perf agent', 'SHG-0100', 'bk:300', '2026-123', 'perf 1999'];
  t0 = process.hrtime.bigint();
  qs.forEach(q => { h = T.find(st, q); ok(h.length, 'hit for ' + q); });
  const per = Number(process.hrtime.bigint() - t0) / 1e6 / qs.length;
  console.log('       first find (index build) ' + first.toFixed(1) + ' ms · later finds ' + per.toFixed(1) + ' ms avg');
  ok(first < 600, 'first find incl. index build ' + first + ' ms (target < 200 ms on a normal machine)');
  ok(per < 200, 'find ' + per + ' ms');
  t0 = process.hrtime.bigint();
  const tl = T.timeline(st, st.journals[15000].id);
  const tt = Number(process.hrtime.bigint() - t0) / 1e6;
  ok(tl && tt < 300, 'timeline ' + tt + ' ms');
});

/* ================= safety ================= */
t('links: only for website sources, encoded, never for markup-ish PNRs or non-numeric ids', () => {
  eq(T.links({ sys: 'app', pnr: 'X' }).length, 0);
  const l = T.links({ sys: 'web', kind: 'booking', pnr: '"><img src=x>', sellerId: '1 or 1', scheduleId: 'x', refundStatus: 'pending' });
  eq(l.length, 0, JSON.stringify(l));
  const l2 = T.links({ sys: 'web', kind: 'booking', pnr: 'SHG-2026-00001', sellerId: 5, scheduleId: 7, refundStatus: 'none', status: 'confirmed' });
  eq(l2.map(x => x.href).join(' '), '/admin/booking-view.php?pnr=SHG-2026-00001 /admin/payments.php?tab=all&q=SHG-2026-00001 /admin/agent.php?agent=5 /admin/manifest.php?sid=7');
});

t('poisoned memos / refs are searchable as text and returned raw (UI escapes)', () => {
  const st = book();
  const P = '<img src=x onerror=alert(1)>';
  money(st, CEO, { cat: 'in_other', amount: L(5), memo: 'memo ' + P, ref: 'REF' + P });
  const h = T.find(st, 'onerror');
  const hj = h.find(x => x.type === 'journal');
  ok(hj && hj.label.indexOf(P) >= 0, 'raw text kept');
  ok(h.some(x => x.type === 'ref'), 'ref text searchable');
  ok(T.timeline(st, hj.journals[0]).events.length >= 1);
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
