/*
 * tests/finance-statements-test.js — checks the STATEMENTS engine (E.stmt) of SHG Finance Master.
 *
 *   node tests/finance-statements-test.js [built.html | engine.js]
 *
 * Same loader as finance-master-test.js: pulls <script id="shg-engine"> (engine.js +
 * engine-*.js, including engine-statements.js) out of the built single file and runs it
 * in a Node sandbox. Every scenario uses a fresh in-memory demo/test book.
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

if (!file.endsWith('.js')) {
  for (const id of ['shg-data', 'shg-ui']) {
    const m = new RegExp('<script id="' + id + '">\\n([\\s\\S]*?)<\\/script>').exec(src);
    if (!m) { console.error('<script id="' + id + '"> not found'); process.exit(1); }
    try { new vm.Script(m[1], { filename: id + '.js' }); }
    catch (e) { console.error(id + ' does not compile: ' + e.message); process.exit(1); }
  }
}

let pass = 0, fail = 0;
function t(name, fn) {
  try { fn(); pass++; console.log('  ok   ' + name); }
  catch (e) { fail++; console.log('  FAIL ' + name + '\n       ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join('\n       ') : e)); }
}
function eq(a, b, msg) { if (a !== b) throw new Error((msg || '') + ' expected ' + JSON.stringify(b) + ' got ' + JSON.stringify(a)); }
function ok(v, msg) { if (!v) throw new Error(msg || 'expected truthy'); }

const TODAY = '2026-10-08';
const CEO = { id: 'u1', name: 'Test CEO', role: 'ceo' };
const R = (n) => Math.round(n * 100);   // rupees -> paise

if (!E.stmt) { console.error('E.stmt missing — engine-statements.js not in the engine block'); process.exit(1); }
const S = E.stmt;

console.log('Amount in words — Nepali');
const NE_CASES = [
  [0, 'शून्य रुपैयाँ मात्र'],
  [1, 'एक रुपैयाँ मात्र'],
  [15, 'पन्ध्र रुपैयाँ मात्र'],
  [100, 'एक सय रुपैयाँ मात्र'],
  [1000, 'एक हजार रुपैयाँ मात्र'],
  [100000, 'एक लाख रुपैयाँ मात्र'],
  [125300.50, 'एक लाख पच्चीस हजार तीन सय रुपैयाँ पचास पैसा मात्र'],
  [1234567.89, 'बाह्र लाख चौँतीस हजार पाँच सय सतसट्ठी रुपैयाँ उनान्नब्बे पैसा मात्र'],
  [10000000, 'एक करोड रुपैयाँ मात्र'],
  [999999999.99, 'उनान्सय करोड उनान्सय लाख उनान्सय हजार नौ सय उनान्सय रुपैयाँ उनान्सय पैसा मात्र'],
  [0.05, 'शून्य रुपैयाँ पाँच पैसा मात्र'],
  [2500000000, 'दुई अर्ब पचास करोड रुपैयाँ मात्र'],
  [21, 'एक्काइस रुपैयाँ मात्र'], [79, 'उनासी रुपैयाँ मात्र'], [45, 'पैँतालीस रुपैयाँ मात्र']
];
NE_CASES.forEach(([rs, want]) => t('ne ' + rs, () => eq(S.words.ne(R(rs)), want)));
t('ne NPR says नेपाली रुपैयाँ', () => eq(S.words.ne(R(125300.5), 'NPR'), 'एक लाख पच्चीस हजार तीन सय नेपाली रुपैयाँ पचास पैसा मात्र'));
t('ne ignores the sign', () => eq(S.words.ne(-R(15)), 'पन्ध्र रुपैयाँ मात्र'));
t('ne table has 0..99', () => { for (let i = 1; i < 100; i++) { const w = S.words.ne(R(i)); ok(!/undefined/.test(w), 'n=' + i + ' ' + w); } });

console.log('Amount in words — English (Indian)');
const EN_CASES = [
  [0, 'Rupees Zero Only'],
  [1, 'Rupees One Only'],
  [15, 'Rupees Fifteen Only'],
  [100, 'Rupees One Hundred Only'],
  [1000, 'Rupees One Thousand Only'],
  [100000, 'Rupees One Lakh Only'],
  [125300.50, 'Rupees One Lakh Twenty-Five Thousand Three Hundred and Fifty Paise Only'],
  [1234567.89, 'Rupees Twelve Lakh Thirty-Four Thousand Five Hundred Sixty-Seven and Eighty-Nine Paise Only'],
  [10000000, 'Rupees One Crore Only'],
  [999999999.99, 'Rupees Ninety-Nine Crore Ninety-Nine Lakh Ninety-Nine Thousand Nine Hundred Ninety-Nine and Ninety-Nine Paise Only'],
  [12345678900, 'Rupees One Thousand Two Hundred Thirty-Four Crore Fifty-Six Lakh Seventy-Eight Thousand Nine Hundred Only'],
  [0.01, 'Rupees Zero and One Paise Only']
];
EN_CASES.forEach(([rs, want]) => t('en ' + rs, () => eq(S.words.en(R(rs)), want)));
t('en NPR says Nepalese Rupees', () => eq(S.words.en(R(1000), 'NPR'), 'Nepalese Rupees One Thousand Only'));

console.log('Statements on the demo book');
const st = E.buildDemo(TODAY);
E.migrate(st);
const ent = 'IN';
const FROM = E.addDays(TODAY, -30), TO = TODAY;
function sumRows(s) { return s.rows.reduce((a, r) => a + r.dr - r.cr, 0); }
function checkShape(s, label) {
  ok(s.ok, label + ' build ok: ' + JSON.stringify(s.errors));
  eq(s.closing, s.opening + s.totals.dr - s.totals.cr, label + ' closing = opening + dr − cr');
  eq(sumRows(s), s.totals.dr - s.totals.cr, label + ' rows sum');
  if (s.rows.length) eq(s.rows[s.rows.length - 1].balance, s.closing, label + ' last running balance');
  for (let i = 1; i < s.rows.length; i++) ok(s.rows[i - 1].date <= s.rows[i].date, label + ' rows sorted by date');
  eq(s.byAcc.reduce((a, b) => a + b.closing, 0), s.closing - (s.type === 'pnr' ? s.opening : 0), label + ' per-account closings add up');
  ok(/^[0-9a-f]{12}$/.test(s.code), label + ' code is 12 hex');
  ok(/^ST-IN-\d{8}-\d{3,}$/.test(s.no), label + ' number format ' + s.no);
  eq(s.journalHashes.length, s.rows.length, label + ' one hash per row');
}
// generic: opening/closing vs E.balance on the same accounts (Dr-positive = natural for assets, −natural for liabilities/equity)
function drBal(code, f) { const a = E.account(st, code), v = E.balance(st, ent, code, f); return a.type === 'asset' || a.type === 'expense' ? v : -v; }

st.agents.forEach((ag, i) => t('agent ' + ag.code + ' = agentPosition (1210 held − 2020 payable)', () => {
  const s = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'agent');
  eq(s.closing, E.agentPosition(st, ent, ag.id, TO).net, 'closing = agentPosition.net');
  eq(s.opening, E.agentPosition(st, ent, ag.id, E.addDays(FROM, -1)).net, 'opening = agentPosition(from−1).net');
  const old = E.agentStatement(st, ent, ag.id, FROM, TO);
  eq(s.closing, old.closing, 'matches the old E.agentStatement'); eq(s.opening, old.opening);
  ok(/एजेन्टले कम्पनीलाई तिर्नुपर्ने/.test(s.nature.ne), 'explains + = agent owes');
}));
t('agent all-time statement: opening 0, closing = position', () => {
  const ag = st.agents[0], s = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s, 'agent-all'); eq(s.opening, 0); eq(s.closing, E.agentPosition(st, ent, ag.id, TO).net);
  ok(s.period.allTime);
});
st.employees.forEach((em) => t('employee ' + em.name, () => {
  const s = S.build(st, { type: 'employee', id: em.id, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'employee');
  eq(s.closing, drBal('1230', { party: em.id, to: TO }) + drBal('2030', { party: em.id, to: TO }));
}));
st.people.filter((p) => p.kind === 'vendor').forEach((p) => t('vendor ' + p.name, () => {
  const s = S.build(st, { type: 'vendor', id: p.id, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'vendor');
  eq(s.closing, drBal('2010', { party: p.id, to: TO }));
  eq(s.opening, drBal('2010', { party: p.id, to: E.addDays(FROM, -1) }));
  ok(s.closing < 0 || s.rows.length, 'vendor has activity');
}));
t('lender (director loan) = loan outstanding + interest payable, Cr', () => {
  const loan = st.loans[0], s = S.build(st, { type: 'lender', id: loan.lenderId, entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s, 'lender');
  eq(-s.closing, E.loanOutstanding(st, loan, TO) + E.balance(st, ent, '2070', { party: loan.lenderId, to: TO }));
  ok(s.closing < 0, 'Cr balance');
  const one = S.build(st, { type: 'lender', id: loan.lenderId, sub: loan.id, entity: ent, from: '', to: TO, today: TODAY });
  checkShape(one, 'lender-sub'); ok(one.party.key !== s.party.key, 'sub in the party key');
});
st.people.filter((p) => p.kind === 'equity').forEach((p) => t('shareholder ' + p.name, () => {
  const s = S.build(st, { type: 'shareholder', id: p.id, entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s, 'shareholder');
  eq(-s.closing, E.balance(st, ent, '3010', { party: p.id, to: TO }) + E.balance(st, ent, '3020', { party: p.id, to: TO }) + E.balance(st, ent, '2080', { party: p.id, to: TO }) + E.balance(st, ent, '2990', { party: p.id, to: TO }));
  ok(s.closing < 0, 'capital is a Cr balance');
}));
t('customer/person (pending contributor on 2990)', () => {
  const p = st.people.filter((x) => x.kind === 'pending')[0], s = S.build(st, { type: 'customer', id: p.id, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'customer'); eq(s.closing, drBal('2990', { party: p.id, to: TO })); ok(s.closing < 0);
});
['1110', '1010', '1150', '4010', '5010', '2010', '3010'].forEach((c) => t('account ' + c + ' = E.balance', () => {
  const s = S.build(st, { type: 'account', id: c, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'account ' + c);
  eq(s.closing, drBal(c, { to: TO })); eq(s.opening, drBal(c, { to: E.addDays(FROM, -1) }));
}));
t('account statement with party filter (1210 of one agent)', () => {
  const ag = st.agents[1], s = S.build(st, { type: 'account', id: '1210', party: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'acc+party'); eq(s.closing, E.balance(st, ent, '1210', { party: ag.id, to: TO }));
});
['1110', '1120', '1010', '1150'].forEach((c) => t('bank/cash book ' + c, () => {
  const s = S.build(st, { type: 'bank', id: c, entity: ent, from: FROM, to: TO, today: TODAY });
  checkShape(s, 'bank'); eq(s.closing, E.balance(st, ent, c, { to: TO }));
  s.rows.forEach((r) => ok(r.contra || r.memo, 'particulars shown'));
}));
st.trips.forEach((tr, i) => t('trip ' + (i + 1) + ' Cr closing = tripResult.contribution', () => {
  const s = S.build(st, { type: 'trip', id: tr.id, entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s, 'trip'); eq(-s.closing, E.tripResult(st, tr).contribution);
  ok(s.rows.length > 0);
}));
t('pnr statement: every journal of a PNR (sale + cancel)', () => {
  const sale = st.agentSales.filter((x) => x.status === 'active')[0];
  sale.pnr = 'SHG-2026-00009';   // the demo sales have no PNR — give one (before any journal: the memo/ref are not used)
  const s0 = S.build(st, { type: 'pnr', id: 'shg-2026-00009', entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s0, 'pnr');
  ok(s0.rows.length >= 1, 'sale journal found through agentSales.journalId');
  eq(s0.rows[0].jid, sale.journalId);
  // P&L of the sale = revenue − commission
  eq(-s0.closing, sale.amount - sale.commission);
  const r = E.cancelAgentSale(st, sale.id, { date: TODAY, reason: 'test' }, CEO);
  ok(r.ok, 'cancel ok ' + JSON.stringify(r.errors));
  const s1 = S.build(st, { type: 'pnr', id: 'SHG-2026-00009', entity: ent, from: '', to: TO, today: TODAY });
  checkShape(s1, 'pnr+cancel');
  eq(s1.rows.length, s0.rows.length + 1, 'cancel journal added');
  eq(s1.closing, 0, 'fully cancelled booking nets to zero');
  ok(s1.facts.length > 0, 'money path facts');
});
t('pnr from website source.pnr / ref', () => {
  const b = E.newBook('test', TODAY);
  E.postJournal(b, { entity: 'IN', date: TODAY, memo: 'वेबसाइट SHG-2026-00100 — बिक्री (UPI)', ref: 'UTR123', source: { sys: 'web', kind: 'booking', bookingId: 100, pnr: 'SHG-2026-00100' }, lines: [{ acc: '1150', dr: R(1500), cr: 0 }, { acc: '4010', dr: 0, cr: R(1500) }] }, CEO);
  E.postJournal(b, { entity: 'IN', date: TODAY, memo: 'refund', ref: 'SHG-2026-00100', lines: [{ acc: '4015', dr: R(500), cr: 0 }, { acc: '2040', party: 'p1', dr: 0, cr: R(500) }] }, CEO);
  E.postJournal(b, { entity: 'IN', date: TODAY, memo: 'unrelated SHG-2026-001000', lines: [{ acc: '1150', dr: R(5), cr: 0 }, { acc: '4010', dr: 0, cr: R(5) }] }, CEO);
  const s = S.build(b, { type: 'pnr', id: 'SHG-2026-00100', entity: 'IN', today: TODAY });
  checkShape(s, 'pnr-web'); eq(s.rows.length, 2); eq(s.closing, -R(1000));
  ok(S.pnrs(b).some((p) => p.id === 'SHG-2026-00100'), 'pnr list');
});

console.log('Numbers, codes, register, verify');
t('number sequence per entity and day', () => {
  const b = E.newBook('test', TODAY);
  eq(S.number(b, 'IN', '2026-10-12'), 'ST-IN-20261012-001');
  b.statements = [{ no: 'ST-IN-20261012-001' }, { no: 'ST-IN-20261012-007' }, { no: 'ST-NP-20261012-020' }, { no: 'ST-IN-20261011-050' }];
  eq(S.number(b, 'IN', '2026-10-12'), 'ST-IN-20261012-008');
  eq(S.number(b, 'NP', '2026-10-12'), 'ST-NP-20261012-021');
  eq(S.number(b, 'IN', '2026-10-13'), 'ST-IN-20261013-001');
});
t('code is deterministic and = first 12 hex of sha256(no|party|from|to|closing|hashes)', () => {
  const ag = st.agents[2];
  const a = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  const b = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  eq(a.code, b.code); eq(a.no, b.no);
  eq(a.code, E.sha256([a.no, a.party.key, FROM, TO, String(a.closing), a.journalHashes.join(',')].join('|')).slice(0, 12));
  const c = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: E.addDays(FROM, 1), to: TO, today: TODAY });
  ok(c.code !== a.code, 'different period → different code');
  eq(S.fmtCode('a1b2c3d4e5f6'), 'A1B2-C3D4-E5F6'); eq(S.normCode(' A1B2-c3d4 e5f6 '), 'a1b2c3d4e5f6');
});
t('register is idempotent; verify valid; unknown; invalid', () => {
  const ag = st.agents[0];
  const s = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  ok(!s.issued, 'not issued yet');
  const n0 = (st.statements || []).length;
  const e1 = S.register(st, s, CEO);
  eq(st.statements.length, n0 + 1); eq(e1.no, s.no); eq(e1.code, s.code); eq(e1.closing, s.closing); eq(e1.by, 'Test CEO');
  ok(st.audit.some((a) => a.a === 'statement.issue'), 'audit row');
  const s2 = S.build(st, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  ok(s2.issued && s2.issued.no === e1.no, 'rebuild finds the issued entry'); eq(s2.code, e1.code);
  const e2 = S.register(st, s2, CEO); eq(e2, e1); eq(st.statements.length, n0 + 1, 'no duplicate entry');
  // a second, different statement gets the next number
  const other = S.build(st, { type: 'agent', id: st.agents[1].id, entity: ent, from: FROM, to: TO, today: TODAY });
  eq(other.no, S.number(st, ent, TODAY)); const e3 = S.register(st, other, CEO);
  ok(e3.no !== e1.no, 'new number'); eq(+e3.no.slice(-3), +e1.no.slice(-3) + 1);
  const v = S.verify(st, S.fmtCode(e1.code).toLowerCase());
  eq(v.status, 'valid'); ok(v.ok); eq(v.entry.no, e1.no);
  eq(S.verify(st, '000000000000').status, 'unknown');
  eq(S.verify(st, 'xyz').status, 'invalid');
  ok(E.verifyChain(st).ok, 'register keeps the integrity chain intact');
});
t('tamper detection: editing a journal behind an issued statement → changed', () => {
  const b = E.buildDemo(TODAY); E.migrate(b);
  const ag = b.agents[0];
  const s = S.build(b, { type: 'agent', id: ag.id, entity: ent, from: '', to: TO, today: TODAY });
  const e = S.register(b, s, CEO);
  eq(S.verify(b, e.code).status, 'valid');
  const row = s.rows[0], j = E.journal(b, row.jid);
  j.lines.forEach((l) => { if (l.dr) l.dr += 100; if (l.cr) l.cr += 100; });   // edit in place (no re-hash)
  E.invalidate(b);
  const v = S.verify(b, e.code);
  eq(v.status, 'changed'); ok(!v.ok); ok(v.now.code !== e.code);
  ok(!E.verifyChain(b).ok, 'the hash chain also sees the edit');
});
t('a new posting inside the period changes the statement (not the issued code)', () => {
  const b = E.buildDemo(TODAY); E.migrate(b);
  const ag = b.agents[1];
  const s = S.build(b, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY }), e = S.register(b, s, CEO);
  const bj = E.buildMoneyJournal(b, { cat: 'in_agent', amount: R(100), account: '1110', date: TODAY, entity: 'IN', party: ag.id });
  ok(E.postJournal(b, bj.journal, CEO).ok);
  const s2 = S.build(b, { type: 'agent', id: ag.id, entity: ent, from: FROM, to: TO, today: TODAY });
  ok(!s2.issued, 'new content → draft again'); ok(s2.no !== e.no, 'next number'); eq(s2.closing, s.closing - R(100));
  eq(S.verify(b, e.code).status, 'changed');
});
t('resolve: id / code / website seller (extId and map) / not imported', () => {
  const b = E.buildDemo(TODAY);
  const ag = b.agents[4];
  eq(S.resolve(b, { type: 'agent', id: ag.id }).id, ag.id);
  eq(S.resolve(b, { type: 'agent', code: 'shg-0007' }).id, ag.id);
  const nf = S.resolve(b, { type: 'agent', seller: '42' }); ok(!nf.ok); eq(nf.reason, 'seller'); eq(nf.seller, '42');
  ag.extId = 'web:42'; eq(S.resolve(b, { type: 'agent', seller: '42' }).id, ag.id);
  b.web = { map: { sellers: { '77': b.agents[0].id } } }; eq(S.resolve(b, { type: 'agent', seller: '77' }).id, b.agents[0].id);
  eq(S.resolve(b, { type: 'agent', seller: '1 OR 1' }).reason, 'seller');
  eq(S.resolve(b, { type: 'bank', acc: '1110' }).id, '1110');
  eq(S.resolve(b, { type: 'bank', acc: '4010' }).reason, 'notbank');
  eq(S.resolve(b, { type: 'pnr', pnr: 'shg-1' }).id, 'SHG-1');
  eq(S.resolve(b, { type: 'pnr', pnr: '<x>' }).reason, 'id');
  eq(S.resolve(b, { type: 'nope' }).reason, 'type');
  eq(S.resolve(b, { type: 'trip', id: b.trips[0].id }).id, b.trips[0].id);
  eq(S.resolve(b, { type: 'employee', id: 'zzz' }).reason, 'id');
});
t('parties lists for every type', () => {
  S.TYPES.forEach((ty) => { const L = S.parties(st, ty.id, 'IN'); ok(Array.isArray(L), ty.id); if (ty.id !== 'customer' || st.people.length) ok(L.length > 0, ty.id + ' has parties'); });
});
t('letterhead: website company merged → legal name, address, web; NP entity uses its own', () => {
  const b = E.buildDemo(TODAY);
  let lh = S.letterhead(b, 'IN'); ok(/S HARI GLOBAL/.test(lh.name)); eq(lh.web, 'shreehariglobal.in');
  b.settings.brand = { company: { name: 'S Hari Global Pvt Ltd', legal: 'S Hari Global Private Limited', legalNe: 'एस हरि ग्लोबल', address: 'Mehsana', phone: '+91 1', email: 'a@b.c', web: 'shreehariglobal.in', cin: 'U1', gstin: 'G1', nepalOffice: 'Nepalgunj' } };
  lh = S.letterhead(b, 'IN'); eq(lh.name, 'S Hari Global Private Limited'); eq(lh.nameNe, 'एस हरि ग्लोबल'); eq(lh.gstin, 'G1'); eq(lh.nepal.office, 'Nepalgunj');
  lh = S.letterhead(b, 'NP'); eq(lh.currency, 'NPR'); eq(lh.nepal, null); eq(lh.gstin, '');
});
t('csv/xlsx rows and WhatsApp text', () => {
  const s = S.build(st, { type: 'bank', id: '1110', entity: ent, from: FROM, to: TO, today: TODAY });
  const rows = S.csvRows(s);
  eq(rows.length, s.rows.length + 4 + 5);
  const csv = E.csv(rows); ok(csv.indexOf(s.no) > 0);
  const x = E.xlsx(rows, 'Statement'); ok(x.length > 500 && x[0] === 0x50, 'xlsx zip');
  const txt = S.text(s, 'SHG'); ok(txt.indexOf(S.fmtCode(s.code)) > 0); ok(/मात्र/.test(txt));
});
t('NPR entity statement uses NPR words', () => {
  const b = E.newBook('test', TODAY); b.entities[1].enabled = true;
  E.postJournal(b, { entity: 'NP', date: TODAY, memo: 'np cash', lines: [{ acc: '1010', dr: R(1000), cr: 0 }, { acc: '3050', dr: 0, cr: R(1000) }] }, CEO);
  const s = S.build(b, { type: 'bank', id: '1010', entity: 'NP', today: TODAY });
  eq(s.currency, 'NPR'); eq(s.closing, R(1000)); ok(/^ST-NP-/.test(s.no));
  ok(/नेपाली रुपैयाँ/.test(S.words.ne(s.closing, s.currency)));
});
t('errors: bad type / missing id; build never mutates the book', () => {
  ok(!S.build(st, { type: 'x', id: '1' }).ok); ok(!S.build(st, { type: 'agent', id: '' }).ok);
  const before = JSON.stringify(st);
  S.build(st, { type: 'account', id: '1110', entity: ent, from: FROM, to: TO, today: TODAY });
  eq(JSON.stringify(st), before);
});
t('performance: 3000-journal book, account statement < 1.5 s', () => {
  const b = E.newBook('test', TODAY);
  for (let i = 0; i < 3000; i++) E.postJournal(b, { entity: 'IN', date: E.addDays(TODAY, -(i % 300)), memo: 'x' + i, lines: [{ acc: '1110', dr: 100 + i, cr: 0 }, { acc: '4010', dr: 0, cr: 100 + i }] }, CEO);
  const t0 = Date.now(), s = S.build(b, { type: 'bank', id: '1110', entity: 'IN', from: E.addDays(TODAY, -100), to: TODAY, today: TODAY });
  ok(Date.now() - t0 < 1500, 'took ' + (Date.now() - t0) + ' ms'); eq(s.closing, E.balance(b, 'IN', '1110', { to: TODAY }));
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
