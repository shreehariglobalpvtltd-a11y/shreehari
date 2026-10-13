/*
 * tests/finance-master-test.js — checks the SHG Finance Master engine.
 *
 *   node tests/finance-master-test.js
 *
 * The finance app is ONE html file (includes/finance/shg-finance-master.html).
 * Its maths lives in <script id="shg-engine">, which has no DOM code, so this
 * test pulls that script out and runs it in a Node sandbox. Every scenario
 * uses a fresh in-memory demo/test book — never real company data.
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

// The data and UI scripts need a browser to run, but they must at least compile.
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
  catch (e) { fail++; console.log('  FAIL ' + name + '\n       ' + (e && e.message)); }
}
function eq(a, b, msg) { if (a !== b) throw new Error((msg || '') + ' expected ' + JSON.stringify(b) + ' got ' + JSON.stringify(a)); }
function ok(v, msg) { if (!v) throw new Error(msg || 'expected truthy'); }
function near(a, b, tol, msg) { if (Math.abs(a - b) > tol) throw new Error((msg || '') + ' expected ~' + b + ' got ' + a); }

const TODAY = '2026-10-08';
const CEO = { id: 'u1', name: 'Test CEO', role: 'ceo' };
const ACC = { id: 'u2', name: 'Test Accountant', role: 'accountant' };
const FIN = { id: 'u3', name: 'Test Finance', role: 'finance' };
const AGENT = { id: 'u4', name: 'Test Agent', role: 'agent' };
const L = (n) => n * 100;                     // rupees -> paise
function book() { return E.newBook('test', TODAY); }
function money(st, user, d) {
  const b = E.buildMoneyJournal(st, Object.assign({ entity: 'IN', date: TODAY, account: '1110' }, d));
  if (b.errors) throw new Error(b.errors.join(' '));
  return E.commit(st, user, b.journal, { level: b.level });
}
function balanced(st, entity, asOf) {
  const tb = E.trialBalance(st, entity, asOf), bs = E.balanceSheet(st, entity, asOf);
  ok(tb.balanced, 'trial balance must balance (' + tb.dr + ' vs ' + tb.cr + ')');
  ok(bs.balanced, 'balance sheet must balance');
}

console.log('SHG Finance Master — engine tests');

t('money parsing, Indian grouping and rounding', () => {
  eq(E.parseMoney('1,23,456.78'), 12345678);
  eq(E.parseMoney('१२००'), 120000, 'Devanagari digits');
  eq(E.parseMoney('₹ 50,00,000'), 500000000);
  eq(E.parseMoney('1.005'), 101, 'round half up at 3rd decimal');
  ok(isNaN(E.parseMoney('abc')));
  eq(E.fmt(500000000), '₹50,00,000');
  eq(E.fmt(12345678), '₹1,23,456.78');
  eq(E.fmt(1000000000, 'NPR'), 'रु 1,00,00,000');
  eq(E.fmt(-250000), '−₹2,500');
  eq(E.pctOf(33333, 7.5), 2500, '7.5% of ₹333.33 = ₹25.00 (half-up)');
});

t('sha256 matches the standard test vector', () => {
  eq(E.sha256('abc'), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  eq(E.sha256(''), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

t('BS calendar conversion and date filters', () => {
  const b = E.toBS('2026-10-08');
  eq(b.y, 2083); eq(b.m, 6); eq(b.d, 22);
  eq(E.toBS('2026-04-14').d, 1, '1 Baishakh 2083');
  eq(E.toBS('1999-01-01'), null, 'outside verified table -> null, never a wrong date');
  const r = E.rangeFor('month', TODAY); eq(r.from, '2026-10-01'); eq(r.to, TODAY);
  eq(E.rangeFor('yesterday', TODAY).from, '2026-10-07');
  eq(E.fyOf(TODAY).from, '2026-04-01'); eq(E.fyOf('2027-02-01').to, '2027-03-31');
  eq(E.monthEnd('2026-02-10'), '2026-02-28');
});

t('₹50 lakh onboarding: authorized capital is NOT paid-up capital or bank money', () => {
  const st = book();
  st.settings.capital.classification = 'authorized';
  st.settings.capital.authorized = L(5000000);
  const s = E.capitalSummary(st, 'IN', TODAY);
  eq(s.authorized, L(5000000));
  eq(s.paidUp, 0, 'nothing paid up until allotments + receipts exist');
  eq(s.bank, 0, 'no bank balance is invented');
  eq(s.authorizedVerified, false, 'unverified until documents are checked');
});

let shareBook;
t('share ownership 25,000 / 15,000 / 10,000 = 50% / 30% / 20%', () => {
  const st = book();
  st.settings.capital.authorized = L(10000000);
  const A = E.addPerson(st, { name: 'A', kind: 'equity' }, CEO), B = E.addPerson(st, { name: 'B', kind: 'equity' }, CEO), C = E.addPerson(st, { name: 'C', kind: 'equity' }, CEO);
  ok(E.allotShares(st, { date: TODAY, holderId: A.id, shares: 25000, issuePrice: L(100), docRef: 'BR-1', paidNow: L(2500000), account: '1110' }, CEO).ok);
  ok(E.allotShares(st, { date: TODAY, holderId: B.id, shares: 15000, issuePrice: L(100), docRef: 'BR-1', paidNow: L(1500000), account: '1110' }, CEO).ok);
  ok(E.allotShares(st, { date: TODAY, holderId: C.id, shares: 10000, issuePrice: L(100), docRef: 'BR-1', paidNow: L(800000), account: '1110' }, CEO).ok);
  const ct = E.capTable(st, TODAY);
  eq(ct.totalEq, 50000);
  eq(ct.nominal, L(5000000), 'issued nominal capital ₹50,00,000');
  near(ct.rows.find(r => r.name === 'A').pct, 50, 1e-9);
  near(ct.rows.find(r => r.name === 'B').pct, 30, 1e-9);
  near(ct.rows.find(r => r.name === 'C').pct, 20, 1e-9);
  eq(ct.rows.find(r => r.name === 'C').unpaid, L(200000), 'C still owes ₹2,00,000 (partly paid)');
  eq(E.balance(st, 'IN', '3010', { to: TODAY }), L(4800000), 'paid-up = money actually received');
  balanced(st, 'IN', TODAY);
  shareBook = st;
});

t('share dilution preview for 5,000 new shares (nothing saved)', () => {
  const before = shareBook.allotments.length;
  const cur = E.capTable(shareBook, TODAY).rows.map(r => ({ name: r.name, shares: r.eq }));
  const d = E.dilution(cur, 5000, L(150), L(100), L(10000000), L(5000000));
  near(d.rows.find(r => r.isNew).after, 5000 * 100 / 55000, 1e-9);
  near(d.rows.find(r => r.name === 'A').after, 25000 * 100 / 55000, 1e-9);
  eq(d.nominalUp, L(500000)); eq(d.premium, L(250000)); eq(d.headroom, L(4500000));
  eq(shareBook.allotments.length, before, 'preview must not touch the register');
});

t('share premium is split from face value; discount issue refused; authorized limit enforced', () => {
  const st = book(); st.settings.capital.authorized = L(1000000);
  const P = E.addPerson(st, { name: 'P' }, CEO);
  const r = E.allotShares(st, { date: TODAY, holderId: P.id, shares: 1000, issuePrice: L(150), docRef: 'BR-2', paidNow: L(150000), account: '1110' }, CEO);
  ok(r.ok && r.payment.ok, 'allot + pay');
  eq(E.balance(st, 'IN', '3010', { to: TODAY }), L(100000));
  eq(E.balance(st, 'IN', '3020', { to: TODAY }), L(50000));
  ok(!E.allotShares(st, { date: TODAY, holderId: P.id, shares: 10, issuePrice: L(90), docRef: 'x' }, CEO).ok, 'below face value');
  ok(!E.allotShares(st, { date: TODAY, holderId: P.id, shares: 10000, issuePrice: L(100), docRef: 'x' }, CEO).ok, 'exceeds authorized capital');
  ok(!E.allotShares(st, { date: TODAY, holderId: P.id, shares: 10, issuePrice: L(100), docRef: 'x' }, ACC).ok, 'accountant cannot create shares');
  const s = E.shareMaths(1000, L(100), L(150), L(100000));
  eq(s.premium, L(50000)); eq(s.outstanding, L(50000));
});

t('share transfer keeps total shares and needs enough shares', () => {
  const st = E.clone(shareBook);
  const ids = E.capTable(st, TODAY).rows.map(r => r.id);
  ok(E.transferShares(st, { date: TODAY, fromId: ids[0], toId: ids[2], shares: 5000, docRef: 'SH4-1' }, CEO).ok);
  const ct = E.capTable(st, TODAY);
  eq(ct.totalEq, 50000);
  near(ct.rows.find(r => r.id === ids[0]).pct, 40, 1e-9);
  ok(!E.transferShares(st, { date: TODAY, fromId: ids[2], toId: ids[1], shares: 999999, docRef: 'x' }, CEO).ok);
});

t('loan receipt raises a liability, not capital or profit; interest accrues on actual balance', () => {
  const st = book();
  const D = E.addPerson(st, { name: 'Director', kind: 'director_lender' }, CEO);
  const r = E.addLoan(st, { lenderId: D.id, type: 'director', principal: L(500000), rate: 9, start: '2025-10-08', receiveNow: true, account: '1110', docRef: 'LA-1' }, CEO);
  ok(r.receipt.ok);
  eq(E.balance(st, 'IN', '2100', { to: TODAY }), L(500000), 'director loan liability');
  eq(E.balance(st, 'IN', '3010', { to: TODAY }), 0, 'share capital untouched');
  eq(E.profitLoss(st, 'IN', '2025-01-01', TODAY).net, 0, 'a loan is not income');
  const i = E.loanInterest(st, r.loan, TODAY);
  eq(i.accrued, L(45000), '₹5,00,000 × 9% × 365/365');
  // part repayment after 6 months halves the balance for the rest of the year
  const st2 = book(); const D2 = E.addPerson(st2, { name: 'D2' }, CEO);
  const r2 = E.addLoan(st2, { lenderId: D2.id, principal: L(100000), rate: 10, start: '2026-01-01', receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  money(st2, CEO, { cat: 'out_loanrepay', amount: L(50000), party: D2.id, sub: r2.loan.id, loanType: 'director', date: '2026-07-02' });
  eq(E.loanOutstanding(st2, r2.loan, TODAY), L(50000));
  const exp = Math.round(L(100000) * 0.10 * 182 / 365 + L(50000) * 0.10 * E.daysBetween('2026-07-02', TODAY) / 365);
  eq(E.loanInterest(st2, r2.loan, TODAY).accrued, exp);
  eq(E.profitLoss(st2, 'IN', '2026-01-01', TODAY).net, 0, 'principal repayment is not an expense');
  ok(E.accrueInterest(st2, r2.loan.id, TODAY, CEO).ok);
  eq(E.balance(st2, 'IN', '7110', { to: TODAY }), exp, 'interest booked separately as expense');
  balanced(st2, 'IN', TODAY);
});

t('EMI, simple and compound interest calculators', () => {
  eq(E.emi(L(100000), 12, 12), 888488, '₹1,00,000 @12% for 12 months = ₹8,884.88');
  const sch = E.emiSchedule(L(100000), 12, 12);
  eq(sch[sch.length - 1].balance, 0, 'schedule ends at zero');
  eq(E.simpleInterest(L(100000), 10, 365), L(10000));
  eq(E.compoundInterest(L(100000), 12, 1, 12), 1268250, 'monthly compounding');
});

t('dividend preview splits by shares and declaration is a liability, not an expense', () => {
  const pv = E.dividendPreview(shareBook, L(200000), TODAY, 0);
  eq(pv.rows.find(r => r.name === 'A').gross, L(100000));
  eq(pv.rows.find(r => r.name === 'B').gross, L(60000));
  eq(pv.rows.find(r => r.name === 'C').gross, L(40000));
  const st = E.clone(shareBook);
  ok(!E.declareDividend(st, { date: TODAY, recordDate: TODAY, pool: L(200000), resolution: 'R1' }, CEO).ok, 'CA confirmation required');
  ok(!E.declareDividend(st, { date: TODAY, recordDate: TODAY, pool: L(200000), resolution: 'R1', caConfirmed: true }, ACC).ok, 'accountant cannot declare');
  ok(E.declareDividend(st, { date: TODAY, recordDate: TODAY, pool: L(200000), resolution: 'R1', caConfirmed: true }, CEO).ok);
  eq(E.balance(st, 'IN', '2080', { to: TODAY }), L(200000));
  eq(E.profitLoss(st, 'IN', '2000-01-01', TODAY).net, 0, 'dividend is not an expense');
  balanced(st, 'IN', TODAY);
});

let agentBook, agent7;
t('agent ₹200 commission: SHG-0007 sells 12 tickets for ₹24,000', () => {
  const st = book();
  agent7 = E.addAgent(st, { code: 'SHG-0007', name: 'Seven', tier: 'direct' }, CEO).agent;
  const r = E.recordAgentSale(st, { agentId: agent7.id, date: TODAY, pax: 12, amount: L(24000), entity: 'IN', collectedBy: 'agent', pnr: 'PNR1' }, ACC);
  ok(r.ok, (r.errors || []).join());
  eq(r.commission.amount, L(2400));
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), L(24000), 'gross revenue is ₹24,000, not ₹21,600');
  eq(E.balance(st, 'IN', '2020', { to: TODAY }), L(2400), 'commission payable');
  eq(E.balance(st, 'IN', '1210', { to: TODAY }), L(24000), 'agent holds the cash');
  const dup = E.recordAgentSale(st, { agentId: agent7.id, date: TODAY, pax: 12, amount: L(24000), entity: 'IN', collectedBy: 'agent', pnr: 'PNR1' }, ACC);
  ok(!dup.ok, 'no second commission on the same booking');
  agentBook = st;
});

t('commission is editable: ₹200 → ₹400 from a date, old sales keep their rate', () => {
  const st = E.clone(agentBook);
  ok(E.setCommissionRule(st, { from: '2026-10-09', mode: 'flat', direct: L(400), team: L(600), percent: 5 }, CEO).ok);
  const ag = st.agents[0];
  eq(E.commissionFor(st, ag, 1, L(2000), TODAY).amount, L(200), 'today still ₹200');
  eq(E.commissionFor(st, ag, 1, L(2000), '2026-10-09').amount, L(400), 'from the new date ₹400');
  eq(st.agentSales[0].commission, L(2400), 'past sale unchanged');
  ag.override = { mode: 'percent', value: 7.5 };
  eq(E.commissionFor(st, ag, 3, L(6000), '2026-10-10').amount, L(450), 'per-agent override 7.5%');
  ag.override = { mode: '', value: 0 }; ag.tier = 'team';
  eq(E.commissionFor(st, ag, 2, L(4000), '2026-10-10').amount, L(1200), 'team tier rate');
});

t('agent net settlement: remits ₹21,600 and keeps ₹2,400 — both balances clear', () => {
  const st = E.clone(agentBook);
  money(st, ACC, { cat: 'in_agent', amount: L(21600), party: agent7.id, ref: 'UPI-1' });
  ok(E.netSettle(st, 'IN', agent7.id, TODAY, ACC).ok);
  const p = E.agentPosition(st, 'IN', agent7.id, TODAY);
  eq(p.held, 0); eq(p.payable, 0);
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), L(24000), 'revenue still gross');
  const s = E.agentStatement(st, 'IN', agent7.id, TODAY, TODAY);
  eq(s.closing, 0);
});

t('agent gross settlement: full remittance + separate commission payout', () => {
  const st = E.clone(agentBook);
  money(st, ACC, { cat: 'in_agent', amount: L(24000), party: agent7.id, ref: 'UPI-2' });
  money(st, ACC, { cat: 'out_commission', amount: L(2400), party: agent7.id, ref: 'UTR-C' });
  const p = E.agentPosition(st, 'IN', agent7.id, TODAY);
  eq(p.held, 0); eq(p.payable, 0);
  eq(E.cashPosition(st, 'IN', TODAY).total, L(21600));
});

t('cancellation reverses revenue and (by policy) the commission', () => {
  const st = E.clone(agentBook);
  ok(E.cancelAgentSale(st, st.agentSales[0].id, { date: TODAY }, ACC).ok);
  eq(E.balance(st, 'IN', '2020', { to: TODAY }), 0, 'commission reversed');
  eq(E.profitLoss(st, 'IN', TODAY, TODAY).revenue, 0, 'net revenue 0 after refund');
  eq(E.balance(st, 'IN', '4015', { to: TODAY }), -L(24000), 'shown as refunds (contra revenue)');
  ok(!E.cancelAgentSale(st, st.agentSales[0].id, {}, ACC).ok, 'cannot cancel twice');
  const st2 = E.clone(agentBook); st2.settings.reverseCommissionOnCancel = false;
  E.cancelAgentSale(st2, st2.agentSales[0].id, { date: TODAY }, ACC);
  eq(E.balance(st2, 'IN', '2020', { to: TODAY }), L(2400), 'policy off: commission kept');
});

t('payroll: ₹25,000 + ₹5,000 bonus − ₹2,000 advance = ₹28,000 payable, then paid', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  const e = E.addEmployee(st, { name: 'Driver', cat: 'driver', base: L(25000) }, CEO);
  money(st, CEO, { cat: 'out_advance', amount: L(5000), party: e.id, date: '2026-09-05' });
  eq(E.profitLoss(st, 'IN', '2026-09-01', '2026-09-30').totalExpense, 0, 'an advance is not an expense');
  const items = E.payrollDraft(st, 'IN', '2026-09');
  items[0].bonus = L(5000); items[0].advance = L(2000);
  eq(E.payrollCalc(items[0]).net, L(28000));
  const r = E.postPayroll(st, 'IN', '2026-09', items, FIN);
  ok(r.ok, (r.errors || []).join());
  eq(E.balance(st, 'IN', '2030', { to: TODAY }), L(28000));
  eq(E.balance(st, 'IN', '1230', { to: TODAY }), L(3000), 'advance balance reduced');
  eq(E.balance(st, 'IN', '6010', { to: TODAY }), L(30000), 'salary expense is gross');
  ok(!E.postPayroll(st, 'IN', '2026-09', items, FIN).ok, 'same month cannot be posted twice');
  const before = E.cashPosition(st, 'IN', TODAY).bank;
  ok(E.payPayroll(st, r.run.id, '1110', TODAY, CEO).ok);
  eq(E.balance(st, 'IN', '2030', { to: TODAY }), 0);
  eq(E.cashPosition(st, 'IN', TODAY).bank, before - L(28000));
  ok(E.settleAdvance(st, { entity: 'IN', empId: e.id, used: L(2000), returned: L(1000), expenseAcc: '5010', account: '1010', date: TODAY }, CEO).ok);
  eq(E.balance(st, 'IN', '1230', { to: TODAY }), 0);
  balanced(st, 'IN', TODAY);
});

t('current-month payroll is booked no later than today and cannot be paid before it is booked', () => {
  const st = book(), now = E.today(), m = now.slice(0, 7);
  money(st, CEO, { cat: 'in_other', amount: L(100000), date: now });
  E.addEmployee(st, { name: 'Clerk', cat: 'counter', base: L(10000) }, CEO);
  const r = E.postPayroll(st, 'IN', m, E.payrollDraft(st, 'IN', m), FIN);
  ok(r.ok, (r.errors || []).join());
  ok(E.find(st.journals, r.run.journalId).date <= now, 'not dated in the future');
  ok(!E.payPayroll(st, r.run.id, '1110', E.addDays(m + '-01', -1), CEO).ok, 'payment dated before the salary entry is refused');
  ok(E.payPayroll(st, r.run.id, '1110', now, CEO).ok);
  eq(E.balance(st, 'IN', '2030', { to: now }), 0, 'never a negative salary payable');
  ok(!E.runDepreciation(st, 'IN', E.addMonths(now, 1).slice(0, 7), CEO).ok, 'a month is depreciated only after it ends');
});

t('general ledger refuses unbalanced, one-sided and party-less entries', () => {
  const st = book();
  ok(!E.postJournal(st, { entity: 'IN', date: TODAY, lines: [{ acc: '1110', dr: 100, cr: 0 }, { acc: '4010', dr: 0, cr: 90 }] }, CEO).ok, 'unbalanced');
  ok(!E.postJournal(st, { entity: 'IN', date: TODAY, lines: [{ acc: '1110', dr: 100, cr: 100 }, { acc: '4010', dr: 0, cr: 0 }] }, CEO).ok, 'both sides on a line');
  ok(!E.postJournal(st, { entity: 'IN', date: TODAY, lines: [{ acc: '1110', dr: 10.5, cr: 0 }, { acc: '4010', dr: 0, cr: 10.5 }] }, CEO).ok, 'fractional paise');
  ok(!E.postJournal(st, { entity: 'IN', date: TODAY, lines: [{ acc: '2020', dr: 0, cr: 100 }, { acc: '5040', dr: 100, cr: 0 }] }, CEO).ok, 'agent accounts need an agent');
  ok(!E.postJournal(st, { entity: 'NP', date: TODAY, lines: [{ acc: '1110', dr: 100, cr: 0 }, { acc: '4010', dr: 0, cr: 100 }] }, CEO).ok, 'disabled entity');
  eq(st.journals.length, 0);
});

t('journal reversal posts a mirror, never edits; cannot reverse twice', () => {
  const st = book();
  const r = money(st, CEO, { cat: 'out_diesel', amount: L(10000) });
  ok(E.reverseJournal(st, r.journal.id, { reason: 'wrong amount', date: TODAY }, CEO).ok);
  eq(E.balance(st, 'IN', '5010', { to: TODAY }), 0);
  eq(st.journals.length, 2, 'original kept');
  ok(!E.reverseJournal(st, r.journal.id, {}, CEO).ok);
});

t('internal bank transfer does not change total cash or profit', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(50000) });
  const before = E.cashPosition(st, 'IN', TODAY).total;
  const b = E.buildTransferJournal(st, { entity: 'IN', date: TODAY, from: '1110', to: '1120', amount: L(20000) });
  ok(E.commit(st, CEO, b.journal, { level: b.level }).ok);
  eq(E.cashPosition(st, 'IN', TODAY).total, before);
  eq(E.balance(st, 'IN', '1120', { to: TODAY }), L(20000));
  eq(E.profitLoss(st, 'IN', TODAY, TODAY).net, L(50000));
  const cf = E.cashFlow(st, 'IN', TODAY, TODAY);
  eq(cf.net, L(50000), 'transfer is not a cash flow'); ok(cf.ties);
  ok(E.explain(st, b.journal).summary.join(' ').indexOf('उत्तिकै') >= 0, 'explained as unchanged total');
});

t('Nepali explanations match the accounting effect', () => {
  const st = book();
  const d = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_diesel', amount: L(10000) });
  const x = E.explain(st, d.journal);
  eq(x.cash, -L(10000)); eq(x.profit, -L(10000));
  ok(x.summary[0].indexOf('घट्छ') >= 0);
  const l = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'in_loan', amount: L(500000), party: 'p', loanType: 'director' });
  const y = E.explain(st, l.journal);
  eq(y.profit, 0); eq(y.liability, L(500000));
  ok(y.summary.join(' ').indexOf('नाफा मानिँदैन') >= 0);
  eq(l.level, 3, 'loans always need CEO-level approval');
});

t('maker–checker: thresholds, segregation of duties, agent cannot post', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  const small = money(st, ACC, { cat: 'out_office', amount: L(4000) });
  ok(small.ok && !small.queued, '≤ ₹5,000 posts directly for accountant');
  const mid = money(st, ACC, { cat: 'out_office', amount: L(20000) });
  ok(mid.queued, '₹20,000 waits for manager');
  const big = money(st, FIN, { cat: 'out_office', amount: L(30000) });
  ok(big.queued, '> ₹25,000 waits for CEO');
  ok(!E.approve(st, big.pending.id, FIN).ok, 'finance cannot approve own / above level');
  ok(!E.approve(st, big.pending.id, ACC).ok, 'accountant cannot approve');
  ok(E.approve(st, mid.pending.id, FIN).ok, 'finance approves the mid one');
  ok(E.approve(st, big.pending.id, CEO).ok, 'CEO approves the big one');
  const self = money(st, FIN, { cat: 'out_office', amount: L(26000) });
  const ceoSelf = { id: FIN.id, name: 'same id', role: 'ceo' };
  ok(!E.approve(st, self.pending.id, ceoSelf).ok, 'segregation: creator cannot approve');
  const ag = money(st, AGENT, { cat: 'out_office', amount: L(100) });
  ok(!ag.ok, 'agent role cannot post or submit');
  ok(!E.can('agent', 'view.company'), 'agent cannot see company-wide finances');
  ok(E.can('agent', 'view.own.agent'));
  ok(!E.can('investor', 'view.company') && E.can('investor', 'view.own.investor'));
  ok(E.can('auditor', 'view.reports') && !E.can('auditor', 'post'), 'auditor read-only');
  balanced(st, 'IN', TODAY);
});

t('duplicate protection: idempotency key and same-UTR warning', () => {
  const st = book();
  const j = { entity: 'IN', date: TODAY, idem: 'form-123', cat: 'in_other', ref: 'UTR9', lines: [{ acc: '1110', dr: 500, cr: 0 }, { acc: '4090', dr: 0, cr: 500 }] };
  E.postJournal(st, j, CEO);
  const again = E.postJournal(st, j, CEO);
  ok(again.duplicate, 'double tap posts once');
  eq(st.journals.length, 1);
  eq(E.findDuplicates(st, { entity: 'IN', ref: 'utr9', date: TODAY }).length, 1, 'same UTR flagged');
});

t('period lock blocks back-dated entries; reversal lands after the lock', () => {
  const st = book();
  const r = money(st, CEO, { cat: 'out_rent', amount: L(1000), date: '2026-09-15' });
  st.settings.lockDate = '2026-09-30';
  const late = money(st, CEO, { cat: 'out_rent', amount: L(1000), date: '2026-09-20' }).ok;
  ok(!late, 'locked');
  const rev = E.reverseJournal(st, r.journal.id, { date: '2026-09-15' }, CEO);
  ok(rev.ok); eq(rev.journal.date, '2026-10-01');
});

t('multi-entity isolation and intercompany with FX', () => {
  const st = book();
  st.entities[1].enabled = true;
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  const r = E.intercompany(st, { from: 'IN', to: 'NP', amount: L(10000), date: TODAY, agreement: 'IC-1', fromAccount: '1110', toAccount: '1110' }, CEO);
  ok(r.ok, (r.errors || []).join());
  eq(r.toAmount, L(16000), '₹10,000 × 1.6 = रु 16,000');
  eq(E.cashPosition(st, 'IN', TODAY).bank, L(90000));
  eq(E.cashPosition(st, 'NP', TODAY).bank, L(16000), 'NPR book separate');
  eq(E.profitLoss(st, 'NP', '2000-01-01', TODAY).net, 0);
  balanced(st, 'IN', TODAY); balanced(st, 'NP', TODAY);
  st.settings.fx.push({ from: '2026-10-01', rate: 1.62 });
  eq(E.fxAt(st, TODAY), 1.62); eq(E.fxAt(st, '2026-09-01'), 1.6, 'effective-dated');
});

t('bank statement import, duplicate rows and auto-match', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(5000), ref: 'NEFT77', date: '2026-10-05' });
  money(st, CEO, { cat: 'out_net', amount: L(1200), date: '2026-10-06' });
  const csv = 'Date,Narration,Debit,Credit,Ref\n06/10/2026,NEFT IN,,"5,000.00",NEFT77\n07/10/2026,HOSTING,1200,,\n08/10/2026,CHARGES,59,,\n';
  const p = E.statementFromCSV(E.parseCSV(csv));
  eq(p.lines.length, 3); eq(p.lines[0].date, '2026-10-06'); eq(p.lines[0].amount, L(5000)); eq(p.lines[2].amount, -5900);
  eq(E.importStatement(st, '1110', p.lines, CEO).added, 3);
  eq(E.importStatement(st, '1110', p.lines, CEO).duplicates, 3, 're-import ignored');
  eq(E.autoMatch(st, 'IN', '1110'), 2);
  const s = E.reconSummary(st, 'IN', '1110', TODAY);
  eq(s.unmatchedStatement.length, 1, 'bank charge still to record');
});

t('vendor bill → payable → part payment → FIFO aging', () => {
  const st = book();
  const v = E.addPerson(st, { name: 'Pump', kind: 'vendor' }, CEO);
  money(st, CEO, { cat: 'in_other', amount: L(100000) });
  ok(E.recordBill(st, { vendorId: v.id, date: '2026-09-01', due: '2026-09-15', billNo: 'B1', acc: '5010', amount: L(30000), entity: 'IN' }, CEO).ok);
  ok(E.recordBill(st, { vendorId: v.id, date: '2026-10-01', due: '2026-10-20', billNo: 'B2', acc: '5010', amount: L(20000), entity: 'IN' }, CEO).ok);
  ok(E.recordBill(st, { vendorId: v.id, date: '2026-10-01', billNo: 'B2', acc: '5010', amount: L(20000), entity: 'IN' }, CEO).duplicate, 'same bill number not booked twice');
  money(st, CEO, { cat: 'out_vendor', amount: L(35000), party: v.id });
  eq(E.balance(st, 'IN', '2010', { to: TODAY }), L(15000));
  const ag = E.vendorAging(st, 'IN', TODAY);
  eq(ag.length, 1); eq(ag[0].bill.billNo, 'B2'); eq(ag[0].open, L(15000));
});

t('fixed asset depreciation runs once per month', () => {
  const st = book();
  money(st, CEO, { cat: 'in_other', amount: L(1000000) });
  ok(E.addAsset(st, { name: 'Bus', cost: L(1200000), date: '2026-09-01', lifeMonths: 120, account: '1110' }, CEO).ok);
  ok(E.runDepreciation(st, 'IN', '2026-09', CEO).ok);
  ok(!E.runDepreciation(st, 'IN', '2026-09', CEO).ok);
  eq(E.balance(st, 'IN', '6080', { to: TODAY }), L(10000));
  eq(E.accumulatedDep(st, st.assets[0], TODAY), L(10000));
});

t('trip contribution example: ₹1,40,000 revenue → ₹70,000 contribution', () => {
  const st = book();
  const v = { id: 'v1', no: 'GJ-1', seats: 36, entity: 'IN' }; st.vehicles.push(v);
  const trip = { id: 't1', vehicleId: 'v1', date: TODAY, dir: 'up', capacity: 36, pax: 35, entity: 'IN' }; st.trips.push(trip);
  money(st, CEO, { cat: 'in_ticket', amount: L(140000), trip: 't1' });
  ok(E.tripExpenses(st, trip, [{ acc: '5010', amount: L(35000) }, { acc: '5020', amount: L(8000) }, { acc: '5030', amount: L(9000) }, { acc: '5090', amount: L(8000) }], '1110', TODAY, CEO).ok);
  const ag = E.addAgent(st, { name: 'x' }, CEO).agent;
  ok(E.postJournal(st, { entity: 'IN', date: TODAY, memo: 'agent commissions on this trip', lines: [
    { acc: '5040', party: ag.id, trip: 't1', dr: L(10000), cr: 0 }, { acc: '2020', party: ag.id, dr: 0, cr: L(10000) }] }, CEO).ok);
  const r = E.tripResult(st, trip);
  eq(r.net, L(140000));
  eq(r.direct, L(70000), 'diesel + toll + crew + other + commissions');
  eq(r.contribution, L(70000));
  near(r.occupancy, 35 * 100 / 36, 1e-9);
});

t('break-even and cash runway', () => {
  eq(E.breakEven(L(300000), L(600)), 500);
  eq(E.breakEven(L(300000), 0), null);
  eq(E.runway(L(600000), L(200000)), 3);
  eq(E.runway(L(600000), 0), null, 'not meaningful when burn is zero');
  eq(E.runway(L(600000), -5), null, 'not meaningful when cash grows');
});

t('demo book: every report reconciles', () => {
  const st = E.buildDemo(TODAY);
  balanced(st, 'IN', TODAY);
  const fy = E.fyOf(TODAY);
  const cf = E.cashFlow(st, 'IN', fy.from, TODAY);
  ok(cf.ties, 'cash flow opening + flows = closing');
  eq(cf.closing, E.cashPosition(st, 'IN', TODAY).total);
  const pl = E.profitLoss(st, 'IN', fy.from, TODAY);
  eq(pl.net, E.balanceSheet(st, 'IN', TODAY).currentEarnings, 'P&L ties to balance sheet');
  const ct = E.capTable(st, TODAY);
  near(ct.rows[0].pct, 50, 1e-9);
  const s7 = st.agentSales.find(s => E.find(st.agents, s.agentId).code === 'SHG-0007');
  eq(s7.commission, L(2400));
  eq(st.payrollRuns[0].items[0].net, L(28000));
  ok(st.payrollRuns[0].status === 'paid');
  ok(E.insights(st, 'IN', TODAY).length > 3, 'insights have sources');
  ok(E.ask(st, 'IN', 'आज एजेन्टलाई कति दिन बाँकी छ?', TODAY).title.indexOf('कमिसन') >= 0);
  ok(E.ask(st, 'IN', 'मेरो कम्पनीको पैसा किन घट्यो?', TODAY).lines.length > 0);
  E.cashPosition(st, 'IN', TODAY).rows.forEach(r => ok(r.amount >= 0, r.name + ' not negative in demo'));
  ok(st.journals.every(j => E.journalTotal(j) === E.sum(j.lines, l => l.cr)), 'every journal balanced');
});

t('backup round trip keeps the books identical', () => {
  const st = E.buildDemo(TODAY);
  const back = JSON.parse(JSON.stringify(st));
  eq(JSON.stringify(E.trialBalance(back, 'IN', TODAY)), JSON.stringify(E.trialBalance(st, 'IN', TODAY)));
  eq(E.sha256(JSON.stringify(back)), E.sha256(JSON.stringify(st)));
  eq(E.unsafeId(back), '', 'a genuine book passes the restore check');
  back.settings.customCats.push({ id: 'c_abc123', dir: 'out', ne: 'होटल', acc: '6090', trip: false, special: false, icon: '🏷️' });
  eq(E.unsafeId(back), '', 'flags like trip:false are not ids');
});

t('a tampered backup with markup in an id is refused; agent codes stay plain', () => {
  const bad = E.buildDemo(TODAY);
  bad.trips[0].id = 'x" onmouseover="alert(1)';
  ok(E.unsafeId(bad).indexOf('id=') === 0, 'trip id with markup caught');
  const bad2 = E.buildDemo(TODAY);
  bad2.journals[0].lines[0].party = '<img src=x>';
  ok(E.unsafeId(bad2).indexOf('party=') === 0, 'party with markup caught');
  const bad3 = E.buildDemo(TODAY);
  bad3.accounts[0].code = '1010"><b>';
  ok(E.unsafeId(bad3).indexOf('account code') === 0, 'account code with markup caught');
  const st = book();
  ok(!E.addAgent(st, { code: 'SHG"><x', name: 'x' }, CEO).ok, 'agent code with markup refused');
  ok(E.addAgent(st, { code: 'shg-0012', name: 'ok' }, CEO).ok, 'normal code accepted (upper-cased)');
  eq(st.agents[0].code, 'SHG-0012');
});

t('exports: CSV escaping and a valid .xlsx zip', () => {
  const c = E.csv([['a', 'b,c', 'say "hi"'], [1, 2, 3]]);
  ok(c.charCodeAt(0) === 0xFEFF, 'BOM for Excel');
  ok(c.indexOf('"b,c"') > 0 && c.indexOf('"say ""hi"""') > 0);
  const inj = E.csv([['=HYPERLINK("x")', '-2+3', '-125.50', 125]]);
  ok(inj.indexOf("'=HYPERLINK") > 0 && inj.indexOf("'-2+3") > 0, 'typed formulas are neutralised');
  ok(inj.indexOf(',-125.50,125') > 0, 'negative numbers stay numbers');
  const x = E.xlsx([['नाम', 'रकम'], ['डिजेल', 350]], 'Test');
  eq(x[0], 0x50); eq(x[1], 0x4b); eq(x[2], 3); eq(x[3], 4);
  const tail = x.length - 22;
  eq(x[tail], 0x50); eq(x[tail + 1], 0x4b); eq(x[tail + 2], 5); eq(x[tail + 3], 6);
  eq(x[tail + 10], 6, 'six files in the workbook');
  eq(E.crc32(new Uint8Array([0x61, 0x62, 0x63])), 0x352441C2, 'crc32("abc")');
});

// ======================================================================
// v2 engine: integrity chain, migration, caches, and the engine bug fixes
// (bug numbers = SCRATCH/bugs-full.txt, 0-based). Each test is a regression
// test: it fails on the v1 engine and passes on v2.
// ======================================================================
console.log('\nv2 — integrity, migration, performance and bug regressions');
const crypto = require('node:crypto');
const FIN2 = { id: 'u5', name: 'Second Finance', role: 'finance' };
function post(st, d, user) {
  const r = E.postJournal(st, Object.assign({ entity: 'IN', date: TODAY }, d), user || CEO);
  if (!r.ok) throw new Error((r.errors || []).join(' '));
  return r.journal;
}
function cash(st, amt, date) { return post(st, { date: date || '2026-04-01', memo: 'funding', cat: 'in_other', lines: [{ acc: '1110', dr: amt, cr: 0 }, { acc: '4090', dr: 0, cr: amt }] }); }
function lastAudit(st, action) { for (let i = st.audit.length - 1; i >= 0; i--) if (st.audit[i].a === action) return st.audit[i]; return null; }
function bruteLines(st, f) {
  const out = [];
  st.journals.forEach((j, ji) => {
    if (f.entity && j.entity !== f.entity) return;
    if (f.from && j.date < f.from) return;
    if (f.to && j.date > f.to) return;
    j.lines.forEach((l, li) => {
      if (f.acc && (Array.isArray(f.acc) ? f.acc.indexOf(l.acc) < 0 : l.acc !== f.acc)) return;
      if (f.party && l.party !== f.party) return;
      if (f.trip && l.trip !== f.trip) return;
      if (f.sub && l.sub !== f.sub) return;
      out.push({ j, l, ji, li });
    });
  });
  out.sort((a, b) => a.j.date < b.j.date ? -1 : a.j.date > b.j.date ? 1 : a.j.createdAt < b.j.createdAt ? -1 : a.j.createdAt > b.j.createdAt ? 1 : (a.ji - b.ji) || (a.li - b.li));
  return out;
}
function sameLines(a, b, msg) {
  eq(a.length, b.length, msg + ' count');
  for (let i = 0; i < a.length; i++) if (a[i].j !== b[i].j || a[i].l !== b[i].l) throw new Error(msg + ' differs at ' + i);
}

t('sha256 agrees with node:crypto on random Unicode (incl. Devanagari) input', () => {
  for (let n = 0; n < 120; n++) {
    let s = ''; for (let k = 0; k < n * 7; k++) s += String.fromCharCode(32 + ((n * 131 + k * 977) % 2400));
    eq(E.sha256(s), crypto.createHash('sha256').update(s, 'utf8').digest('hex'), 'len ' + s.length);
  }
});

t('canonical JSON sorts keys recursively and drops undefined like JSON.stringify', () => {
  eq(E.canonical({ b: 1, a: { d: 2, c: [{ z: 1, y: 2 }, null] }, u: undefined }), '{"a":{"c":[{"y":2,"z":1},null],"d":2},"b":1}');
  eq(E.canonical('रु'), '"रु"');
});

t('journal + audit hash chain: seq, prev, h = sha256(prev|canonical(core))', () => {
  const st = book();
  const j1 = cash(st, L(1000)), j2 = cash(st, L(2000)), j3 = cash(st, L(3000));
  eq(j1.seq, 1); eq(j2.seq, 2); eq(j3.seq, 3);
  eq(j1.prev, '0'.repeat(64)); eq(j2.prev, j1.h); eq(j3.prev, j2.h);
  const keys = ['seq', 'id', 'no', 'entity', 'date', 'memo', 'ref', 'method', 'cat', 'source', 'idem', 'lines', 'createdAt', 'createdBy', 'approvedBy', 'reversalOf'];
  const core = {}; keys.forEach(k => { core[k] = j2[k] === undefined ? null : j2[k]; });
  eq(j2.h, E.sha256(j2.prev + '|' + E.canonical(core)), 'hash formula of the contract');
  const a = st.audit[st.audit.length - 1];
  const acore = {}; ['t', 'u', 'r', 'a', 'd', 'refs'].forEach(k => { acore[k] = a[k] === undefined ? null : a[k]; });
  eq(a.h, E.sha256(a.prev + '|' + E.canonical(acore)), 'audit hash formula');
  eq(a.seq, st.audit.length); eq(a.prev, st.audit[st.audit.length - 2].h);
  const v = E.verifyChain(st);
  ok(v.ok); eq(v.journals.count, 3); eq(v.journals.brokenAt, null); eq(v.head.journal, j3.h); eq(v.head.audit, a.h);
  // posting stays chained after a reversal and after an approval
  ok(E.reverseJournal(st, j2.id, { reason: 'x' }, CEO).ok);
  const q = money(st, ACC, { cat: 'out_office', amount: L(9000) }); ok(q.queued);
  ok(E.approve(st, q.pending.id, FIN).ok);
  ok(E.verifyChain(st).ok, 'still intact');
});

t('verifyChain detects a tampered line amount, a tampered audit row and deleted journals', () => {
  const mk = () => { const st = book(); for (let i = 1; i <= 5; i++) cash(st, L(100 * i)); return st; };
  let st = mk();
  st.journals[2].lines[0].dr = L(999); st.journals[2].lines[1].cr = L(999);   // still balanced, but edited
  let v = E.verifyChain(st);
  ok(!v.ok); eq(v.journals.brokenAt, 3, 'edited journal #3'); ok(/changed/.test(v.journals.reason));
  st = mk(); st.audit[1].d = 'हेरफेर गरिएको';
  v = E.verifyChain(st); ok(!v.ok); eq(v.audit.brokenAt, 2, 'edited audit row #2'); eq(v.journals.brokenAt, null);
  st = mk(); st.audit.splice(3, 1);
  eq(E.verifyChain(st).audit.brokenAt, 4, 'deleted audit row');
  st = mk(); st.journals.splice(1, 1);
  v = E.verifyChain(st); eq(v.journals.brokenAt, 2, 'deleted middle journal'); ok(/sequence|deleted/.test(v.journals.reason));
  st = mk(); st.journals.pop();
  v = E.verifyChain(st); ok(!v.ok, 'deleting the newest journal is caught too'); eq(v.journals.brokenAt, 5); ok(/missing/.test(v.journals.reason));
  st = mk(); const t2 = st.journals[1]; st.journals[1] = st.journals[2]; st.journals[2] = t2;
  eq(E.verifyChain(st).journals.brokenAt, 2, 're-ordered');
  st = mk(); st.journals[4].approvedBy = 'someone else';
  eq(E.verifyChain(st).journals.brokenAt, 5, 'approver is part of the sealed core');
});

t('E.migrate upgrades a v1 book (retro-chains journals and audit) and is idempotent', () => {
  const st = E.buildDemo(TODAY);
  const v1 = JSON.parse(JSON.stringify(st));
  v1.v = 1; delete v1.web; delete v1.settings.reverseCommissionOnCancel; delete v1.settings.approval.segregation;
  v1.journals.forEach(j => { delete j.seq; delete j.prev; delete j.h; });
  v1.audit.forEach(a => { delete a.seq; delete a.prev; delete a.h; delete a.d8; delete a.refs; });
  const sale = v1.agentSales.find(s => s.status === 'cancelled'); delete sale.cancelled; delete sale.cancels;
  v1.depRuns = [{ entity: 'IN', month: '2026-08', journalId: 'jx', amount: 1 }];
  v1.loans.forEach(l => delete l.status);
  v1.accounts = v1.accounts.filter(a => a.code !== '1160');
  eq(E.verifyChain(v1).journals.brokenAt, 1, 'an unmigrated book is reported, not trusted');
  const r = E.migrate(v1);
  ok(r === v1, 'returns the same object');
  eq(v1.v, 2); ok(v1.web && typeof v1.web === 'object');
  eq(v1.settings.reverseCommissionOnCancel, true); eq(v1.settings.approval.segregation, true);
  ok(E.account(v1, '1160'), 'missing standard account added');
  ok(E.verifyChain(v1).ok, 'retro-chained');
  ok(v1.audit.every(a => /^\d{4}-\d{2}-\d{2}$/.test(a.d8)), 'audit rows get a local date');
  eq(sale.cancelled.amount, sale.amount); eq(sale.cancels.length, 1); eq(sale.cancels[0].journalId, sale.cancelJournalId);
  ok(sale.cancelled.commission > 0, 'reversed commission recovered from the cancel journal');
  eq(v1.depRuns[0].status, 'posted'); ok(v1.loans.every(l => l.status === 'active'));
  const once = JSON.stringify(v1);
  E.migrate(v1);
  eq(JSON.stringify(v1), once, 'second migrate changes nothing');
  eq(E.validateBook(v1).ok, true);
  // a v2 book that lost the hash of a MIDDLE row is not silently re-sealed
  const v2 = E.buildDemo(TODAY); delete v2.journals[5].h;
  E.migrate(v2);
  eq(E.verifyChain(v2).journals.brokenAt, 6);
  // journals written later by an old engine (no chain) are chained on the next post
  const v3 = book(); cash(v3, L(1)); v3.journals.push(Object.assign(JSON.parse(JSON.stringify(v3.journals[0])), { id: 'jold', no: 'IN-00002', seq: undefined, prev: undefined, h: undefined })); v3.seq.IN = 2;
  cash(v3, L(2));
  ok(E.verifyChain(v3).ok, 'tail chained before the new journal');
});

t('structured audit: refs on post / reverse / approve / reject and a local date (bug #26)', () => {
  const st = book(); cash(st, L(50000));
  const j = money(st, CEO, { cat: 'out_rent', amount: L(1000) }).journal;
  const ap = lastAudit(st, 'journal.post');
  eq(ap.refs.jid, j.id); eq(ap.d8, E.today()); ok(/^\d{4}-\d{2}-\d{2}T/.test(ap.t), 't stays UTC ISO');
  const rv = E.reverseJournal(st, j.id, { reason: 'test' }, CEO);
  const ar = lastAudit(st, 'journal.reverse');
  eq(ar.refs.jid, rv.journal.id); eq(ar.refs.obj, 'journal'); eq(ar.refs.id, j.id);
  const q = money(st, ACC, { cat: 'out_office', amount: L(20000) });
  eq(lastAudit(st, 'approval.submit').refs.pid, q.pending.id);
  const r = E.approve(st, q.pending.id, FIN);
  const aa = lastAudit(st, 'approval.approve'); eq(aa.refs.pid, q.pending.id); eq(aa.refs.jid, r.journal.id);
  const q2 = money(st, ACC, { cat: 'out_office', amount: L(21000) });
  ok(E.reject(st, q2.pending.id, FIN, 'no').ok);
  eq(lastAudit(st, 'approval.reject').refs.pid, q2.pending.id);
  E.audit(st, CEO, 'x.edit', 'd', { obj: 'person', id: 'p1', before: { a: 1 }, after: { a: 2 }, junk: 'ignored' });
  const ax = lastAudit(st, 'x.edit'); eq(ax.refs.before.a, 1); eq(ax.refs.after.a, 2); eq(ax.refs.junk, undefined);
  ok(E.verifyChain(st).ok);
});

t('idempotency (bug #11): a reversed key is still a duplicate unless allowRepost; O(1) index', () => {
  const st = book();
  const j = { entity: 'IN', date: TODAY, idem: 'web:booking:7', cat: 'in_ticket', lines: [{ acc: '1110', dr: L(1000), cr: 0 }, { acc: '4010', dr: 0, cr: L(1000) }] };
  const first = E.postJournal(st, j, CEO); ok(first.ok && !first.duplicate);
  ok(E.reverseJournal(st, first.journal.id, { reason: 'rejected booking' }, CEO).ok);
  const again = E.postJournal(st, j, CEO);
  ok(again.ok && again.duplicate, 'still a duplicate'); eq(again.reversed, true); eq(again.journal.id, first.journal.id);
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), 0, 'not re-posted');
  const viaCommit = E.commit(st, CEO, j, { level: 1 }); ok(viaCommit.duplicate && viaCommit.reversed, 'commit path too');
  const re = E.postJournal(st, Object.assign({}, j, { allowRepost: true }), CEO);
  ok(re.ok && !re.duplicate, 'explicit re-post after a reversal');
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), L(1000));
  const twice = E.postJournal(st, Object.assign({}, j, { allowRepost: true }), CEO);
  ok(twice.duplicate && !twice.reversed, 'allowRepost never duplicates a live journal');
  eq(E.byIdem(st, 'web:booking:7').length, 2);
  // a duplicate is reported before the lock check, so an idempotent re-import of a closed month is quiet
  st.settings.lockDate = TODAY;
  ok(E.commit(st, CEO, j, { level: 1 }).duplicate);
  st.settings.lockDate = '';
  // the same key waiting in the approval queue is not queued twice
  cash(st, L(100000));
  const big = { entity: 'IN', date: TODAY, idem: 'form-9', cat: 'out_office', lines: [{ acc: '6060', dr: L(20000), cr: 0 }, { acc: '1110', dr: 0, cr: L(20000) }] };
  const p1 = E.commit(st, ACC, big, { level: 2 }), p2 = E.commit(st, ACC, big, { level: 2 });
  ok(p1.queued && p2.queued && p2.duplicate); eq(p2.pending.id, p1.pending.id); eq(st.pending.length, 1);
});

t('derived line index: E.lines / balance / totals / partyBalances match a brute-force scan', () => {
  const st = E.buildDemo(TODAY);
  const filters = [{}, { entity: 'IN' }, { entity: 'IN', acc: '1110' }, { entity: 'IN', acc: ['1210', '2020'] }, { entity: 'IN', acc: ['1210', '2020'], party: st.agents[0].id },
    { entity: 'IN', trip: st.trips[0].id }, { entity: 'IN', from: E.addDays(TODAY, -30), to: E.addDays(TODAY, -5) }, { entity: 'IN', acc: '1590', sub: st.assets[0].id },
    { acc: ['3010', '3020'] }, { entity: 'IN', acc: '2100', party: st.loans[0].lenderId, sub: st.loans[0].id, to: TODAY }];
  const check = (label) => filters.forEach((f, i) => sameLines(E.lines(st, f), bruteLines(st, f), label + ' filter ' + i));
  check('demo');
  // incremental: append, and back-date a journal before existing ones
  cash(st, L(10), E.addDays(TODAY, -45));
  post(st, { date: E.addDays(TODAY, -50), memo: 'backdated', lines: [{ acc: '1110', dr: L(7), cr: 0 }, { acc: '4090', dr: 0, cr: L(7) }] });
  check('after posting');
  const brute = (acc, f) => { let d = 0, c = 0; bruteLines(st, Object.assign({ entity: 'IN', acc }, f)).forEach(x => { d += x.l.dr; c += x.l.cr; }); return E.natural(st, acc, d, c); };
  ['1110', '1010', '4010', '2020', '1210', '6080'].forEach(a => {
    eq(E.balance(st, 'IN', a, { to: TODAY }), brute(a, { to: TODAY }), a);
    eq(E.balance(st, 'IN', a, { from: E.addDays(TODAY, -20), to: E.addDays(TODAY, -2) }), brute(a, { from: E.addDays(TODAY, -20), to: E.addDays(TODAY, -2) }), a + ' range');
  });
  eq(E.balance(st, 'IN', '1210', { party: st.agents[1].id, to: TODAY }), brute('1210', { party: st.agents[1].id, to: TODAY }));
  const tot = E.totals(st, 'IN', { to: TODAY }); tot['1110'].dr = -1;
  ok(E.totals(st, 'IN', { to: TODAY })['1110'].dr > 0, 'cached totals are handed out as copies');
  const pb = E.partyBalances(st, 'IN', '1210', TODAY);
  st.agents.forEach(a => eq(pb[a.id] || 0, brute('1210', { party: a.id, to: TODAY })));
  balanced(st, 'IN', TODAY);
  // accounts added by the UI (accounts.push) are found at once
  st.accounts.push({ code: '1125', name: 'नयाँ बैंक', en: '', type: 'asset', kind: 'bank', cf: 'op', party: '' });
  ok(E.account(st, '1125'), 'new account visible'); eq(E.account(st, '9999'), null);
  // a tool that edits a posted journal in place must call E.invalidate
  E.invalidate(st); check('after invalidate');
});

t('bug #0: interest accrued then paid is expensed once (Dr 2070 first, 7110 only the rest)', () => {
  const st = book();
  const D = E.addPerson(st, { name: 'Lender' }, CEO);
  const r = E.addLoan(st, { lenderId: D.id, principal: L(100000), rate: 12, start: '2026-01-01', receiveNow: true, account: '1110', docRef: 'L1' }, CEO);
  ok(r.ok && r.receipt.ok);
  ok(E.accrueInterest(st, r.loan.id, '2026-06-30', CEO).ok);
  const accrued = E.balance(st, 'IN', '7110', { to: TODAY });
  eq(accrued, 591781, '₹1,00,000 × 12% × 180/365 = ₹5,917.81');
  const b = E.buildMoneyJournal(st, { entity: 'IN', date: '2026-07-01', account: '1110', cat: 'out_interest', amount: accrued, loan: r.loan.id });
  eq(b.journal.lines[0].acc, '2070'); eq(b.journal.lines[0].sub, r.loan.id); eq(b.journal.lines[0].party, D.id);
  eq(b.journal.lines.filter(l => l.acc === '7110').length, 0, 'no second expense');
  eq(b.warnings.length, 0); eq(b.open.acc, '2070');
  ok(E.commit(st, CEO, b.journal, { level: b.level }).ok);
  eq(E.balance(st, 'IN', '7110', { to: TODAY }), accrued, '7110 == accrued (not double)');
  eq(E.balance(st, 'IN', '2070', { to: TODAY }), 0, '2070 cleared');
  eq(E.profitLoss(st, 'IN', '2026-01-01', TODAY).net, -accrued);
  // the current UI passes the loan as d.sub — same result; paying more than is accrued splits
  const st2 = book(); const D2 = E.addPerson(st2, { name: 'L2' }, CEO);
  const r2 = E.addLoan(st2, { lenderId: D2.id, principal: L(100000), rate: 12, start: '2026-01-01', receiveNow: true, account: '1110', docRef: 'L2' }, CEO);
  E.accrueInterest(st2, r2.loan.id, '2026-06-30', CEO);
  const b2 = E.buildMoneyJournal(st2, { entity: 'IN', date: '2026-09-30', account: '1110', cat: 'out_interest', amount: L(9000), party: D2.id, sub: r2.loan.id, loanType: 'director' });
  eq(b2.journal.lines.find(l => l.acc === '2070').dr, 591781); eq(b2.journal.lines.find(l => l.acc === '7110').dr, L(9000) - 591781);
  ok(E.commit(st2, CEO, b2.journal, { level: b2.level }).ok);
  const li = E.loanInterest(st2, r2.loan, '2026-09-30');
  eq(li.toExpense, Math.max(0, li.accrued - L(9000)), 'nothing left to expense twice');
  balanced(st2, 'IN', TODAY);
  const b3 = E.buildMoneyJournal(st2, { entity: 'IN', date: '2026-09-30', account: '1110', cat: 'out_interest', amount: L(5000), loan: r2.loan.id });
  ok(b3.warnings.length === 1 && b3.journal, 'paying interest that is not due warns but does not block');
});

t('bug #18: over-payment warnings with the open balance (loan, commission, dividend, salary, agent cash)', () => {
  const st = book(); cash(st, L(500000));
  const D = E.addPerson(st, { name: 'D' }, CEO);
  const r = E.addLoan(st, { lenderId: D.id, principal: L(1000), start: '2026-04-01', receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  const rep = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_loanrepay', amount: L(9000), loan: r.loan.id });
  eq(rep.open.amount, L(1000)); eq(rep.open.acc, '2100'); eq(rep.warnings.length, 1); ok(rep.journal, 'never blocks by itself');
  eq(E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_loanrepay', amount: L(1000), loan: r.loan.id }).warnings.length, 0);
  const ag = E.addAgent(st, { name: 'A' }, CEO).agent;
  E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 2, amount: L(4000), entity: 'IN', collectedBy: 'agent' }, CEO);
  const com = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_commission', amount: L(500), party: ag.id });
  eq(com.open.amount, L(400)); eq(com.warnings.length, 1);
  const rem = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'in_agent', amount: L(5000), party: ag.id });
  eq(rem.open.amount, L(4000)); eq(rem.warnings.length, 1);
  eq(E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'in_agent', amount: L(4000), party: ag.id }).warnings.length, 0);
  const e = E.addEmployee(st, { name: 'E', base: L(1000) }, CEO);
  const sal = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_salary', amount: L(1000), party: e.id });
  eq(sal.open.amount, 0); eq(sal.warnings.length, 1, 'no salary booked yet');
  const P = E.addPerson(st, { name: 'SH', kind: 'equity' }, CEO);
  const dv = E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_dividend', amount: L(10), party: P.id });
  eq(dv.warnings.length, 1);
  eq(E.buildMoneyJournal(st, { entity: 'IN', date: TODAY, account: '1110', cat: 'out_rent', amount: L(10) }).open, null, 'plain expenses have no open balance');
  eq(E.openBalance(st, 'IN', '2100', D.id, r.loan.id, TODAY), L(1000));
});

t('bugs #20/#4 (C2): partial cancel with fee, refund payable, re-selling a PNR', () => {
  const st = book(); cash(st, L(100000));
  const ag = E.addAgent(st, { code: 'SHG-0001', name: 'A' }, CEO).agent;
  const s = E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 4, amount: L(8000), entity: 'IN', collectedBy: 'company', account: '1110', pnr: 'PNR9' }, ACC).sale;
  eq(s.commission, L(800));
  const c1 = E.cancelAgentSale(st, s.id, { amount: L(4000), pax: 2, fee: L(500), date: TODAY, reason: '2 seats' }, ACC);
  ok(c1.ok, (c1.errors || []).join());
  eq(c1.journal.idem, 'cancel:' + s.id + ':1');
  eq(E.balance(st, 'IN', '4015', { to: TODAY }), -L(3500), 'refund = amount − fee');
  eq(E.balance(st, 'IN', '2040', { to: TODAY }), L(3500), 'company sale: refund payable by default');
  eq(E.balance(st, 'IN', '2020', { to: TODAY }), L(400), 'half the commission reversed (2 of 4 pax)');
  eq(s.status, 'active'); eq(s.partial, true); eq(s.cancelled.amount, L(4000)); eq(s.cancelled.pax, 2); eq(s.cancelled.fee, L(500));
  ok(!E.cancelAgentSale(st, s.id, { amount: L(4001) }, ACC).ok, 'cannot cancel more than remains');
  ok(!E.cancelAgentSale(st, s.id, { fee: L(9000) }, ACC).ok, 'fee above amount refused');
  ok(!E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 1, amount: L(2000), entity: 'IN', pnr: 'PNR9' }, ACC).ok, 'PNR still live (partly cancelled)');
  const pr = E.payRefund(st, s.id, '1110', TODAY, ACC);
  ok(pr.ok, (pr.errors || []).join()); eq(E.balance(st, 'IN', '2040', { to: TODAY }), 0);
  const c2 = E.cancelAgentSale(st, s.id, { refund: 'paid', date: TODAY }, ACC);
  ok(c2.ok); eq(c2.journal.idem, 'cancel:' + s.id + ':2');
  eq(s.status, 'cancelled'); eq(s.partial, false); eq(s.cancelled.amount, L(8000));
  eq(E.balance(st, 'IN', '2020', { to: TODAY }), 0, 'all commission reversed');
  eq(E.profitLoss(st, 'IN', TODAY, TODAY).revenue, L(500), 'the fee stays as revenue');
  balanced(st, 'IN', TODAY);
  // bug #4: re-selling the PNR after cancellation posts a real sale
  const again = E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 4, amount: L(8000), entity: 'IN', collectedBy: 'agent', pnr: 'PNR9' }, ACC);
  ok(again.ok && !again.duplicate, (again.errors || []).join()); eq(again.sale.status, 'active'); ok(again.journal);
  ok(again.journal.idem.indexOf(again.sale.id) > 0, 'idem key includes the sale id');
  eq(E.balance(st, 'IN', '1210', { party: ag.id, to: TODAY }), L(8000));
  // agent-collected cancel credits the agent, refund mode ignored
  const c3 = E.cancelAgentSale(st, again.sale.id, { amount: L(2000), refund: 'paid', date: TODAY }, ACC);
  ok(c3.ok); eq(E.balance(st, 'IN', '1210', { party: ag.id, to: TODAY }), L(6000)); eq(c3.sale.cancelled.pax, 1, 'pax pro-rata by amount');
  // reversing a cancel journal undoes that cancel (bug #3e)
  ok(E.reverseJournal(st, c2.journal.id, { reason: 'wrong' }, CEO).ok);
  eq(s.status, 'active'); eq(s.cancelled.amount, L(4000)); ok(s.cancels[1].reversed);
  // a sale with live cancels cannot be reversed underneath them
  ok(!E.reverseJournal(st, s.journalId, {}, CEO).ok, 'reverse the cancels first');
});

t('bug #3/#4: reversing a sale frees the PNR and re-entering it posts (C2 hooks)', () => {
  const st = book();
  const ag = E.addAgent(st, { name: 'A' }, CEO).agent;
  const r = E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 1, amount: L(2000), entity: 'IN', collectedBy: 'agent', pnr: 'P1' }, ACC);
  ok(E.HOOKS_REVERSAL === true);
  ok(E.reverseJournal(st, r.journal.id, { reason: 'wrong fare' }, CEO).ok);
  eq(r.sale.status, 'reversed', 'engine updates the sale itself');
  const r2 = E.recordAgentSale(st, { agentId: ag.id, date: TODAY, pax: 1, amount: L(2500), entity: 'IN', collectedBy: 'agent', pnr: 'P1' }, ACC);
  ok(r2.ok && r2.journal, 'corrected sale accepted');
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), L(2500));
});

t('bug #3: reversal hooks for payroll, payment, depreciation, bill, dividend, loan, asset', () => {
  const st = book(); cash(st, L(10000000));
  const e = E.addEmployee(st, { name: 'Driver', base: L(20000), from: '2026-01-01' }, CEO);
  const run = E.postPayroll(st, 'IN', '2026-08', E.payrollDraft(st, 'IN', '2026-08'), CEO);
  ok(run.ok);
  const pay = E.payPayroll(st, run.run.id, '1110', '2026-09-01', CEO); ok(pay.ok);
  eq(E.reverseJournal(st, run.journal.id, {}, CEO).ok, false, 'paid accrual: reverse the payment first');
  ok(E.reverseJournal(st, pay.journal.id, { reason: 'bank bounced' }, CEO).ok);
  eq(run.run.status, 'posted', 'payment reversed → run back to posted');
  ok(E.reverseJournal(st, run.journal.id, { reason: 'wrong month' }, CEO).ok);
  eq(run.run.status, 'reversed');
  ok(!E.payPayroll(st, run.run.id, '1110', '2026-09-02', CEO).ok, 'a reversed run cannot be paid');
  const again = E.postPayroll(st, 'IN', '2026-08', E.payrollDraft(st, 'IN', '2026-08'), CEO);
  ok(again.ok && !again.duplicate, 'month can be posted again after the reversal');
  ok(E.payPayroll(st, again.run.id, '1110', '2026-09-03', CEO).ok);
  eq(E.balance(st, 'IN', '2030', { to: TODAY }), 0);
  // depreciation
  ok(E.addAsset(st, { name: 'Bus', cost: L(1200000), date: '2026-07-01', lifeMonths: 120, account: '1110' }, CEO).ok);
  const dep = E.runDepreciation(st, 'IN', '2026-08', CEO); ok(dep.ok);
  ok(E.reverseJournal(st, dep.journal.id, { reason: 'x' }, CEO).ok);
  ok(st.depRuns[0].reversed, 'depRun marked reversed');
  ok(E.runDepreciation(st, 'IN', '2026-08', CEO).ok, 'month can be re-run');
  eq(E.balance(st, 'IN', '6080', { to: TODAY }), L(10000));
  // bill
  const v = E.addPerson(st, { name: 'Pump', kind: 'vendor' }, CEO);
  const b1 = E.recordBill(st, { vendorId: v.id, date: '2026-09-01', billNo: 'B1', acc: '5010', amount: L(3000), entity: 'IN' }, CEO);
  E.recordBill(st, { vendorId: v.id, date: '2026-09-05', billNo: 'B2', acc: '5010', amount: L(2000), entity: 'IN' }, CEO);
  ok(E.reverseJournal(st, b1.journal.id, { reason: 'duplicate bill' }, CEO).ok);
  eq(st.bills.find(b => b.billNo === 'B1').status, 'reversed');
  const aging = E.vendorAging(st, 'IN', TODAY); eq(aging.length, 1); eq(aging[0].bill.billNo, 'B2');
  ok(E.recordBill(st, { vendorId: v.id, date: '2026-09-01', billNo: 'B1', acc: '5010', amount: L(3100), entity: 'IN' }, CEO).ok, 'corrected bill re-entered');
  // dividend
  const P = E.addPerson(st, { name: 'SH', kind: 'equity' }, CEO);
  E.allotShares(st, { date: '2026-04-01', holderId: P.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(10000), account: '1110' }, CEO);
  const dv = E.declareDividend(st, { date: TODAY, recordDate: TODAY, pool: L(1000), resolution: 'R', caConfirmed: true }, CEO);
  ok(dv.ok); ok(E.reverseJournal(st, dv.journal.id, {}, CEO).ok); eq(st.dividends[0].status, 'reversed');
  // loan receipt
  const lr = E.addLoan(st, { lenderId: P.id, principal: L(5000), start: TODAY, receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  ok(E.reverseJournal(st, lr.receipt.journal.id, {}, CEO).ok); eq(lr.loan.status, 'cancelled');
  // asset purchase reversed → no more depreciation
  const as = E.addAsset(st, { name: 'PC', cost: L(36000), date: '2026-09-01', lifeMonths: 36, account: '1110' }, CEO);
  ok(E.reverseJournal(st, as.journal.id, {}, CEO).ok); eq(as.asset.status, 'reversed');
  const d9 = E.runDepreciation(st, 'IN', '2026-09', CEO);
  ok(d9.ok); eq(d9.journal.lines.filter(l => l.sub === as.asset.id).length, 0);
  balanced(st, 'IN', TODAY);
  ok(E.verifyChain(st).ok);
});

t('bug #12: reversals go through maker–checker at max(original level, 2); requestReversal queues', () => {
  const st = book(); cash(st, L(1000000));
  const small = money(st, CEO, { cat: 'out_office', amount: L(1000) }).journal;
  eq(E.reversalLevel(st, small), 2);
  const D = E.addPerson(st, { name: 'D' }, CEO);
  const lr = E.addLoan(st, { lenderId: D.id, principal: L(1000), start: TODAY, receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  eq(E.reversalLevel(st, lr.receipt.journal), 3, 'special (CEO-only) entries need CEO to reverse');
  const nope = E.reverseJournal(st, lr.receipt.journal.id, {}, FIN);
  ok(!nope.ok); eq(nope.needLevel, 3);
  ok(!E.reverseJournal(st, small.id, {}, ACC).ok, 'accountant cannot reverse directly');
  const q = E.requestReversal(st, small.id, { reason: 'typo' }, ACC);
  ok(q.ok && q.queued); eq(q.pending.level, 2);
  ok(!E.requestReversal(st, small.id, {}, ACC).ok, 'one request at a time');
  ok(!small.reversedBy, 'nothing happens before approval');
  const ap = E.approve(st, q.pending.id, FIN);
  ok(ap.ok); eq(small.reversedBy, ap.journal.id); eq(ap.journal.reversalOf, small.id);
  eq(E.balance(st, 'IN', '6060', { to: TODAY }), 0);
  const q2 = E.requestReversal(st, lr.receipt.journal.id, { reason: 'x' }, FIN);
  ok(q2.queued); eq(q2.pending.level, 3);
  ok(E.approve(st, q2.pending.id, CEO).ok); eq(lr.loan.status, 'cancelled', 'hooks run after approval too');
  const direct = E.requestReversal(st, money(st, CEO, { cat: 'out_office', amount: L(200) }).journal.id, {}, CEO);
  ok(direct.ok && !direct.queued && direct.journal.reversalOf, 'CEO request posts at once');
  // a queued reversal whose original got reversed meanwhile is refused on approval
  const j3 = money(st, CEO, { cat: 'out_office', amount: L(300) }).journal;
  const q3 = E.requestReversal(st, j3.id, {}, ACC);
  ok(q3.queued);
  ok(E.reverseJournal(st, j3.id, {}, CEO).ok === false, 'pending request blocks a second path');
  ok(E.verifyChain(st).ok); balanced(st, 'IN', TODAY);
});

t('bug #12: opening balances need CEO level (direct and through commit)', () => {
  const st = book();
  const ob = { entity: 'IN', date: TODAY, memo: 'opening', cat: 'opening', lines: [{ acc: '1110', dr: L(5000000), cr: 0 }, { acc: '3050', dr: 0, cr: L(5000000) }] };
  ok(!E.postJournal(st, ob, ACC).ok, 'accountant cannot post an opening balance directly');
  const q = E.commit(st, ACC, ob, { level: 1 });
  ok(q.queued); eq(q.pending.level, 3, 'raised to CEO level');
  ok(!E.approve(st, q.pending.id, FIN).ok);
  ok(E.approve(st, q.pending.id, CEO).ok);
  eq(E.balance(st, 'IN', '3050', { to: TODAY }), L(5000000));
  ok(E.postJournal(st, Object.assign({}, ob, { idem: 'opening:2' }), CEO).ok, 'CEO (setup wizard) posts directly');
});

t('bug #12: intercompany is a linked pair through maker–checker; reversing one leg reverses both', () => {
  const st = book(); st.entities[1].enabled = true;
  cash(st, L(1000000));
  const q = E.intercompany(st, { from: 'IN', to: 'NP', amount: L(10000), date: TODAY, agreement: 'IC-9', fromAccount: '1110', toAccount: '1110' }, ACC);
  ok(q.ok && q.queued, 'accountant above t1 is queued, not posted'); eq(q.pending.level, 2);
  eq(E.cashPosition(st, 'NP', TODAY).bank, 0);
  const ap = E.approve(st, q.pending.id, FIN);
  ok(ap.ok);
  const legs = E.icLegs(st, ap.journal.source.icId);
  eq(legs.length, 2); eq(legs[1].entity, 'NP'); eq(legs[1].approvedBy, FIN.name);
  eq(E.cashPosition(st, 'NP', TODAY).bank, L(16000));
  balanced(st, 'IN', TODAY); balanced(st, 'NP', TODAY);
  ok(E.reverseJournal(st, legs[1].id, { reason: 'wrong amount' }, CEO).ok);
  ok(legs[0].reversedBy && legs[1].reversedBy, 'both legs reversed together');
  eq(E.balance(st, 'IN', '1900', { party: 'NP', to: TODAY }), 0); eq(E.balance(st, 'NP', '2900', { party: 'IN', to: TODAY }), 0);
  eq(E.cashPosition(st, 'NP', TODAY).bank, 0);
  const d = E.intercompany(st, { from: 'IN', to: 'NP', amount: L(100), date: TODAY, agreement: 'IC-10', fromAccount: '1110', toAccount: '1110' }, CEO);
  ok(d.ok && d.journals.length === 2 && d.journals[0].source.icId === d.journals[1].source.icId);
  const bad = E.intercompany(st, { from: 'IN', to: 'NP', amount: L(100), date: TODAY, agreement: 'IC-11', fromAccount: '1110', toAccount: '9999' }, CEO);
  ok(!bad.ok); eq(st.journals.filter(j => j.memo.indexOf('IC-11') >= 0).length, 0, 'invalid second leg: nothing posted');
});

t('bug #13: allotShares validates paidNow first and is atomic', () => {
  const st = book();
  const P = E.addPerson(st, { name: 'P' }, CEO);
  const over = E.allotShares(st, { date: TODAY, holderId: P.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(20000), account: '1110' }, CEO);
  ok(!over.ok); ok(over.payment && !over.payment.ok && over.payment.errors.length);
  eq(st.allotments.length, 0, 'nothing allotted'); eq(P.kind, 'pending');
  st.settings.lockDate = TODAY;
  const locked = E.allotShares(st, { date: TODAY, holderId: P.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(5000), account: '1110' }, CEO);
  ok(!locked.ok); eq(st.allotments.length, 0, 'payment failure removes the allotment'); eq(P.kind, 'pending', 'person kind restored');
  st.settings.lockDate = '';
  const fine = E.allotShares(st, { date: TODAY, holderId: P.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(5000), account: '1110' }, CEO);
  ok(fine.ok && fine.payment.ok); eq(st.allotments.length, 1); eq(P.kind, 'equity');
  const noPay = E.allotShares(st, { date: TODAY, holderId: P.id, shares: 10, issuePrice: L(100), docRef: 'B' }, CEO);
  ok(noPay.ok && !noPay.payment, 'allotment without money still fine');
  const pend = E.addPerson(st, { name: 'X' }, CEO);
  money(st, CEO, { cat: 'in_pending', amount: L(1000), party: pend.id });
  const fp = E.allotShares(st, { date: TODAY, holderId: pend.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(2000), fromPending: true }, CEO);
  ok(!fp.ok, 'cannot classify more than the person paid in (2990)');
  ok(E.allotShares(st, { date: TODAY, holderId: pend.id, shares: 100, issuePrice: L(100), docRef: 'B', paidNow: L(1000), fromPending: true }, CEO).ok);
  eq(E.balance(st, 'IN', '2990', { to: TODAY }), 0);
  balanced(st, 'IN', TODAY);
});

t('bug #6: payroll is never paid twice (Money Out salary first) + payrollPreview', () => {
  const st = book(); cash(st, L(500000));
  const a = E.addEmployee(st, { name: 'A', base: L(20000), from: '2026-01-01' }, CEO);
  const b = E.addEmployee(st, { name: 'B', base: L(10000), from: '2026-01-01' }, CEO);
  const r = E.postPayroll(st, 'IN', '2026-09', E.payrollDraft(st, 'IN', '2026-09'), CEO); ok(r.ok);
  money(st, CEO, { cat: 'out_salary', amount: L(20000), party: a.id, date: '2026-10-01' });
  money(st, CEO, { cat: 'out_salary', amount: L(4000), party: b.id, date: '2026-10-01' });
  const pv = E.payrollPreview(st, r.run.id);
  eq(pv.find(x => x.empId === a.id).toPay, 0); eq(pv.find(x => x.empId === a.id).paidAlready, L(20000));
  eq(pv.find(x => x.empId === b.id).toPay, L(6000));
  const p = E.payPayroll(st, r.run.id, '1110', '2026-10-02', CEO);
  ok(p.ok); eq(p.total, L(6000));
  eq(p.skipped.length, 2); ok(p.skipped.some(s => s.empId === a.id && !s.partial));
  eq(E.balance(st, 'IN', '2030', { party: a.id, to: TODAY }), 0, 'A not paid twice');
  eq(E.balance(st, 'IN', '2030', { to: TODAY }), 0, 'never negative');
  const r2 = E.postPayroll(st, 'IN', '2026-08', E.payrollDraft(st, 'IN', '2026-08'), CEO);
  money(st, CEO, { cat: 'out_salary', amount: L(30000), party: a.id, date: '2026-09-02' });
  money(st, CEO, { cat: 'out_salary', amount: L(10000), party: b.id, date: '2026-09-02' });
  const p2 = E.payPayroll(st, r2.run.id, '1110', '2026-09-03', CEO);
  ok(!p2.ok, 'everything already paid'); eq(p2.skipped.length, 2);
});

t('bug #7: compound interest does not compound on interest already paid', () => {
  const st = book();
  const D = E.addPerson(st, { name: 'D' }, CEO);
  const r = E.addLoan(st, { lenderId: D.id, principal: L(100000), rate: 12, method: 'compound', perYear: 12, start: '2025-01-01', receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  eq(E.loanInterest(st, r.loan, '2026-01-01').accrued, 1268250, 'year 1 = ₹12,682.50');
  money(st, CEO, { cat: 'out_interest', amount: 1268250, loan: r.loan.id, date: '2026-01-01' });
  const y2 = E.loanInterest(st, r.loan, '2027-01-01');
  near(y2.accrued - 1268250, 1268250, 2, 'year 2 accrues ₹12,682.50 again, not ₹14,286');
  eq(y2.paid, 1268250); near(y2.due, 1268250, 2);
});

t('bug #8: bank CSV — header priority, identical rows, DD-Mon-YYYY, (250.00), "1,000.00 Dr", Dr/Cr column', () => {
  const sbi = 'Txn Date,Value Date,Description,Ref No./Cheque No.,Debit,Credit,Balance\n' +
    '09 Oct 2026,09 Oct 2026,UPI CR,UTR111,,500.00,1500.00\n' +
    '09 Oct 2026,09 Oct 2026,UPI CR,UTR222,,500.00,2000.00\n' +
    '09-Oct-2026,10-Oct-2026,BANK CHARGES,,59.00,,1941.00\n' +
    '09-Oct-2026,10-Oct-2026,BANK CHARGES,,59.00,,1882.00\n';
  const p = E.statementFromCSV(E.parseCSV(sbi));
  eq(p.errors.length, 0); eq(p.lines.length, 4);
  eq(p.lines[0].date, '2026-10-09'); eq(p.lines[0].ref, 'UTR111', 'ref is the Ref column, not "Txn Date"'); eq(p.lines[0].amount, L(500));
  eq(p.lines[2].amount, -5900); eq(p.columns.date, 0);
  const st = book();
  const im = E.importStatement(st, '1110', p.lines, CEO);
  eq(im.added, 4, 'two genuine ₹500 credits and two identical charges all import'); eq(im.duplicates, 0);
  const again = E.importStatement(st, '1110', p.lines, CEO);
  eq(again.added, 0); eq(again.duplicates, 4, 're-import adds nothing');
  const amt = 'Date,Narration,Amount,Dr/Cr\n05/10/2026,NEFT,"1,000.00",Cr\n06/10/2026,ATM,"1,000.00",Dr\n07/10/2026,FEE,(250.00),\n08/10/2026,CHQ,"1,000.00 Dr",\n08/10/2026,IMPS,500.00 Cr,\n';
  const q = E.statementFromCSV(E.parseCSV(amt));
  eq(q.lines.length, 5, q.errors.join());
  eq(q.lines.map(l => l.amount).join(), [L(1000), -L(1000), -L(250), -L(1000), L(500)].join());
  eq(E.normDate('9-Sept-26'), '2026-09-09'); eq(E.normDate('Oct 9, 2026'), '2026-10-09'); eq(E.normDate('31/02/2026'), '', 'impossible dates refused');
  eq(E.bankAmount('Dr 20'), -2000);
  // a line imported by v1 (old key format) is still recognised as already imported
  const st2 = book();
  st2.bankLines.push({ id: 'bl1', key: '1110|2026-10-09|50000|UTR111', acc: '1110', date: '2026-10-09', desc: 'UPI CR', amount: 50000, ref: 'UTR111', matched: '' });
  eq(E.importStatement(st2, '1110', p.lines.slice(0, 1), CEO).duplicates, 1);
});

t('bug #9: matching ignores reversed pairs, reversal clears a match, unmatchLine', () => {
  const st = book();
  const good = money(st, CEO, { cat: 'in_other', amount: L(700), date: '2026-10-05', ref: 'R1' }).journal;
  const bad = money(st, CEO, { cat: 'in_other', amount: L(500), date: '2026-10-05' }).journal;
  ok(E.reverseJournal(st, bad.id, { reason: 'wrong', date: '2026-10-05' }, CEO).ok);
  E.importStatement(st, '1110', [{ date: '2026-10-06', desc: 'NEFT', amount: L(500), ref: '' }, { date: '2026-10-06', desc: 'NEFT', amount: L(700), ref: 'R1' }], CEO);
  eq(E.autoMatch(st, 'IN', '1110'), 1, 'only the live journal matches');
  const s = E.reconSummary(st, 'IN', '1110', TODAY);
  eq(s.unmatchedLedger.length, 0, 'reversed pair is not "unmatched ledger"');
  eq(s.unmatchedStatement.length, 1);
  const line = st.bankLines.find(b => b.matched);
  eq(E.bankMatches(st, '1110')[0].j.id, good.id);
  ok(E.reverseJournal(st, good.id, { reason: 'x' }, CEO).ok);
  eq(line.matched, '', 'match cleared when its journal is reversed');
  const g2 = money(st, CEO, { cat: 'in_other', amount: L(700), date: '2026-10-06' }).journal;
  eq(E.autoMatch(st, 'IN', '1110'), 1);
  const u = E.unmatchLine(st, line.id, CEO);
  ok(u.ok); eq(line.matched, ''); eq(lastAudit(st, 'bank.unmatch').refs.id, line.id);
  ok(!E.unmatchLine(st, line.id, CEO).ok, 'already unmatched');
  ok(g2);
});

t('bug #10: opening balances are opening cash, not financing inflow', () => {
  const st = book();
  post(st, { date: '2026-10-01', memo: 'सुरुको मौज्दात', cat: 'opening', lines: [{ acc: '1110', dr: L(5000000), cr: 0 }, { acc: '3050', dr: 0, cr: L(5000000) }] });
  money(st, CEO, { cat: 'in_other', amount: L(1000), date: '2026-10-02' });
  const cf = E.cashFlow(st, 'IN', '2026-04-01', TODAY);
  eq(cf.fin, 0, 'no financing inflow'); eq(cf.opening, L(5000000)); eq(cf.op, L(1000));
  eq(cf.closing, L(5001000)); ok(cf.ties);
  ok(!cf.rows.some(r => r.code === '3050'));
});

t('bug #14: performance — 6,000 journals; agentSummary for 120 agents < 150 ms; posting < 2 s', () => {
  const st = book();
  for (let i = 0; i < 120; i++) E.addAgent(st, { name: 'Agent ' + i }, CEO);
  const v = { id: 'v1', no: 'GJ-1', seats: 36, entity: 'IN' }; st.vehicles.push(v);
  for (let i = 0; i < 400; i++) st.trips.push({ id: 't' + i, vehicleId: 'v1', date: E.addDays('2026-01-01', i % 270), dir: i % 2 ? 'up' : 'down', capacity: 36, pax: 30, entity: 'IN' });
  let t0 = Date.now();
  for (let i = 0; i < 6000; i++) {
    const r = E.recordAgentSale(st, { agentId: st.agents[i % 120].id, date: E.addDays('2026-01-01', i % 270), pax: 2, amount: L(4000), tripId: 't' + (i % 400), entity: 'IN', collectedBy: 'agent', pnr: 'PNR' + i }, CEO);
    if (!r.ok) throw new Error(r.errors.join());
  }
  const postMs = Date.now() - t0;
  console.log('       (6,000 agent sales incl. hash chain: ' + postMs + ' ms)');
  ok(postMs < 2000, 'posting took ' + postMs + ' ms');
  t0 = Date.now();
  const sum = E.agentSummary(st, 'IN', '2026-01-01', TODAY);
  const ms = Date.now() - t0;
  console.log('       (agentSummary 120 agents: ' + ms + ' ms)');
  ok(ms < 150, 'agentSummary took ' + ms + ' ms');
  eq(sum.length, 120); eq(sum[0].pax, 100); eq(sum[0].held, L(4000) * 50); eq(sum[0].payable, L(400) * 50);
  t0 = Date.now();
  const rows = E.tripRows(st, 'IN', '2026-01-01', TODAY);
  const tms = Date.now() - t0;
  console.log('       (tripRows 400 trips: ' + tms + ' ms)');
  ok(tms < 150, 'tripRows took ' + tms + ' ms'); eq(rows.length, 400);
  eq(E.sum(rows, r => r.net), E.balance(st, 'IN', '4010', { to: TODAY }));
  t0 = Date.now();
  for (let i = 0; i < 2000; i++) E.balance(st, 'IN', '1210', { party: st.agents[i % 120].id, to: TODAY });
  ok(Date.now() - t0 < 150, '2,000 party balances fast');
  const plain = book(); t0 = Date.now();
  for (let i = 0; i < 6000; i++) E.postJournal(plain, { entity: 'IN', date: '2026-10-01', memo: 'x' + i, idem: 'k' + i, lines: [{ acc: '1110', dr: 100, cr: 0 }, { acc: '4010', dr: 0, cr: 100 }] }, CEO);
  const pms = Date.now() - t0;
  console.log('       (6,000 postJournal: ' + pms + ' ms)');
  ok(pms < 2000); ok(E.verifyChain(plain).ok);
});

t('bug #15: payrollDraft uses the salary effective at month end; advance default ≤ gross', () => {
  const st = book();
  const e = E.addEmployee(st, { name: 'E', base: L(20000), from: '2026-01-01' }, CEO);
  e.salaryHistory.push({ from: '2026-09-15', base: L(25000), allowance: L(1000) }); e.base = L(25000); e.allowance = L(1000);
  eq(E.payrollDraft(st, 'IN', '2026-08')[0].base, L(20000), 'August keeps the old salary');
  eq(E.payrollDraft(st, 'IN', '2026-08')[0].allowance, 0);
  eq(E.payrollDraft(st, 'IN', '2026-09')[0].base, L(25000), 'effective at 30 Sep');
  money(st, CEO, { cat: 'in_other', amount: L(100000), date: '2026-07-01' });
  money(st, CEO, { cat: 'out_advance', amount: L(30000), party: e.id, date: '2026-07-05' });
  const d = E.payrollDraft(st, 'IN', '2026-08')[0];
  eq(d.advanceBal, L(30000)); eq(d.advance, L(20000), 'min(advance balance, gross)');
  ok(E.postPayroll(st, 'IN', '2026-08', [d], CEO).ok, 'default draft can be posted (net 0, not negative)');
  const late = E.addEmployee(st, { name: 'Late', base: L(5000), from: '2026-12-01' }, CEO);
  eq(E.payrollDraft(st, 'IN', '2026-09').find(x => x.empId === late.id).base, L(5000), 'no effective row yet → earliest');
});

t('bug #19: cashAccounts with {clearing:true} includes UPI / in-transit clearing', () => {
  const st = book();
  const plain = E.cashAccounts(st, 'IN').map(a => a.code);
  ok(plain.indexOf('1150') < 0, 'default unchanged');
  const all = E.cashAccounts(st, 'IN', { clearing: true }).map(a => a.code);
  ok(all.indexOf('1150') >= 0 && all.indexOf('1160') >= 0 && all.indexOf('1110') >= 0);
});

t('bug #21: addLoan validates first and leaves no orphan loan or person', () => {
  const st = book(); st.settings.lockDate = '2026-09-30';
  const D = E.addPerson(st, { name: 'D' }, CEO);
  const bad = E.addLoan(st, { lenderId: D.id, principal: L(1000), start: '2026-09-01', receiveNow: true, account: '1110' }, CEO);
  ok(!bad.ok); ok(bad.receipt && !bad.receipt.ok, 'receipt result always present'); eq(st.loans.length, 0);
  const people = st.people.length;
  const bad2 = E.addLoan(st, { lender: { name: 'New Lender' }, principal: L(1000), start: '2026-09-01', receiveNow: true, account: '1110' }, CEO);
  ok(!bad2.ok); eq(st.people.length, people, 'no orphan person');
  const good = E.addLoan(st, { lender: { name: 'New Lender' }, type: 'other', principal: L(1000), start: TODAY, receiveNow: true, account: '1110' }, CEO);
  ok(good.ok); eq(st.people.length, people + 1); eq(E.find(st.people, good.loan.lenderId).kind, 'lender');
  const q = E.addLoan(st, { lenderId: D.id, principal: L(2000), start: TODAY, receiveNow: true, account: '1110' }, ACC);
  ok(q.ok && q.receipt.queued); eq(q.loan.status, 'pending');
  ok(E.reject(st, q.receipt.pending.id, CEO, 'no agreement').ok); eq(q.loan.status, 'cancelled', 'rejected receipt cancels the loan');
  const q2 = E.addLoan(st, { lenderId: D.id, principal: L(3000), start: TODAY, receiveNow: true, account: '1110' }, ACC);
  ok(E.approve(st, q2.receipt.pending.id, CEO).ok); eq(q2.loan.status, 'active'); ok(q2.loan.receiptJournalId);
  const tmp = E.addPerson(st, { name: 'tmp' }, CEO);
  ok(E.dropPersonIfUnused(st, tmp.id)); ok(!E.dropPersonIfUnused(st, D.id), 'a lender in use is kept');
});

t('bug #22: validateBook — full schema check for restore', () => {
  const demo = E.buildDemo(TODAY);
  const ok0 = E.validateBook(demo, { calcIds: [] .concat(demo.drafts.map(d => d.calc)) });
  ok(ok0.ok, ok0.errors.join('; '));
  const tamper = (fn) => { const b = JSON.parse(JSON.stringify(demo)); fn(b); return E.validateBook(b, { calcIds: ['emi', 'runway'] }); };
  ok(!tamper(b => { b.users[0].role = 'god'; }).ok, 'unknown role');
  ok(!tamper(b => { b.drafts.push({ id: 'd1', calc: 'x" onerror="alert(1)' }); }).ok, 'calc id markup');
  ok(!tamper(b => { b.drafts.push({ id: 'd1', calc: 'notacalc' }); }).ok, 'calc id not in the list');
  ok(!tamper(b => { b.settings.customCats.push({ id: 'c1', dir: 'out', ne: 'x', acc: '6090', icon: '<img src=x onerror=alert(1)>' }); }).ok, 'icon markup');
  ok(!tamper(b => { b.settings.recurring = [{ name: 'r', cat: 'out_rent', amount: 100, day: '<b>5</b>' }]; }).ok, 'day must be a number');
  ok(!tamper(b => { b.assets[0].lifeMonths = '96<script>'; }).ok, 'lifeMonths must be a number');
  ok(!tamper(b => { b.journals[3].lines[0].dr += 1; }).ok, 'unbalanced journal');
  ok(!tamper(b => { b.journals[3].lines[0].acc = '0000'; }).ok, 'unknown account');
  ok(!tamper(b => { b.agentSales[0].status = 'paid'; }).ok, 'status enum');
  ok(!tamper(b => { b.entities[0].id = 'IN"><x'; }).ok, 'unsafe id still caught');
  ok(!tamper(b => { b.pending.push({ id: 'p', status: 'submitted', level: 2, journal: { lines: [] }, hook: { name: 'evil' } }); }).ok, 'unknown hook');
  const w = tamper(b => { b.settings.recurring = [{ name: 'r', cat: 'out_rent', amount: null, day: 5 }]; });
  ok(w.ok && w.warnings.length === 1, 'a NaN amount saved by an old UI is only a warning');
  ok(!E.validateBook(null).ok && !E.validateBook([]).ok);
});

t('bug #23: dividend holders that round to ₹0 (or 100% TDS) do not break the declaration', () => {
  const st = book();
  const A = E.addPerson(st, { name: 'Big' }, CEO), B = E.addPerson(st, { name: 'Tiny' }, CEO);
  E.allotShares(st, { date: '2026-04-01', holderId: A.id, shares: 999999, issuePrice: L(100), docRef: 'B' }, CEO);
  E.allotShares(st, { date: '2026-04-01', holderId: B.id, shares: 1, issuePrice: L(100), docRef: 'B' }, CEO);
  const pv = E.dividendPreview(st, L(500), TODAY, 0);
  eq(pv.rows.find(r => r.name === 'Tiny').gross, 0, 'shown as ₹0 in the preview');
  const r = E.declareDividend(st, { date: TODAY, recordDate: TODAY, pool: L(500), resolution: 'R1', caConfirmed: true }, CEO);
  ok(r.ok, (r.errors || []).join()); ok(r.journal.lines.every(l => l.dr > 0 || l.cr > 0));
  const st2 = E.clone(st);
  const r2 = E.declareDividend(st2, { date: TODAY, recordDate: TODAY, pool: L(1000), tdsPct: 100, resolution: 'R2', caConfirmed: true }, CEO);
  ok(r2.ok, (r2.errors || []).join());
  eq(E.balance(st2, 'IN', '2080', { to: TODAY }), L(500), 'nothing net payable from the 100% TDS declaration'); eq(E.balance(st2, 'IN', '2050', { to: TODAY }), L(1000));
});

t('bug #24: depreciation is queued once, recorded as pending, and the hook finishes it', () => {
  const st = book(); cash(st, L(10000000));
  ok(E.addAsset(st, { name: 'Bus', cost: L(1200000), date: '2026-07-01', lifeMonths: 120, account: '1110' }, CEO).ok);
  const q = E.runDepreciation(st, 'IN', '2026-08', ACC);
  ok(q.queued); eq(st.depRuns.length, 1); eq(st.depRuns[0].status, 'pending');
  const q2 = E.runDepreciation(st, 'IN', '2026-08', ACC);
  ok(!q2.ok, 'second click refused'); eq(st.pending.filter(p => p.status === 'submitted').length, 1);
  const ap = E.approve(st, q.pending.id, FIN); ok(ap.ok);
  eq(st.depRuns[0].status, 'posted'); eq(st.depRuns[0].journalId, ap.journal.id);
  ok(!E.runDepreciation(st, 'IN', '2026-08', CEO).ok);
  const q3 = E.runDepreciation(st, 'IN', '2026-09', ACC);
  ok(E.reject(st, q3.pending.id, FIN, 'later').ok); eq(st.depRuns[1].status, 'rejected');
  ok(E.runDepreciation(st, 'IN', '2026-09', CEO).ok, 'a rejected month can be run again');
});

t('bug #25: insights and the assistant ignore bank lines marked "ignored"', () => {
  const st = book();
  st.bankLines.push({ id: 'b1', key: 'k1', acc: '1110', date: TODAY, desc: 'X', amount: 100, ref: '', matched: '', ignored: 'personal' });
  ok(!E.insights(st, 'IN', TODAY).some(i => /बैंक स्टेटमेन्ट/.test(i.t)), 'not counted as unreconciled');
  eq(E.ask(st, 'IN', 'मिलान', TODAY).lines.length, 0);
  st.bankLines.push({ id: 'b2', key: 'k2', acc: '1110', date: TODAY, desc: 'Y', amount: 200, ref: '', matched: '' });
  ok(E.insights(st, 'IN', TODAY).some(i => /बैंक स्टेटमेन्टका 1 /.test(i.t)));
  eq(E.ask(st, 'IN', 'मिलान', TODAY).lines.length, 1);
});

t('bug #27: fiscal year is entity-aware — Nepal runs 1 Shrawan to the end of Asar', () => {
  const np = { id: 'NP', country: 'Nepal', currency: 'NPR' };
  const f = E.fyOf(TODAY, np);
  eq(E.toBS(f.from).m, 4); eq(E.toBS(f.from).d, 1, 'starts 1 Shrawan'); eq(E.toBS(f.from).y, 2083);
  const after = E.toBS(E.addDays(f.to, 1)); eq(after.m, 4); eq(after.d, 1); eq(after.y, 2084, 'ends the day before the next 1 Shrawan');
  eq(f.from, '2026-07-17'); eq(f.to, '2027-07-16'); ok(f.label.indexOf('२०८३/८४') >= 0);
  eq(E.fyOf('2026-07-01', 'NP').from, '2025-07-16', 'Asar 2083 belongs to FY 2082/83');
  eq(E.rangeFor('fy', TODAY, np).from, '2026-07-17');
  eq(E.rangeFor('fy', TODAY, { id: 'IN', country: 'India' }).from, '2026-04-01', 'India unchanged');
  eq(E.rangeFor('fy', TODAY).from, '2026-04-01', 'no entity → India (old callers)');
  eq(E.rangeFor('lastfy', TODAY, np).to, '2026-07-16');
  ok(E.fyOf('2029-01-01', np).approx, 'outside the verified BS table the result says it is approximate');
});

t('extension contract (C6): approvedBy "website" is kept; HOOKS[source.type] gets what="reversed"', () => {
  const st = book();
  const calls = [];
  E.HOOKS.webTest = function (st2, args, j, what, user) { calls.push([args.id, what, j && j.id, user && user.name]); };
  try {
    const r = E.commit(st, CEO, { entity: 'IN', date: TODAY, memo: 'web booking', cat: 'web_sale', approvedBy: 'website', idem: 'web:b:1', source: { type: 'webTest', id: 'B1' },
      lines: [{ acc: '1150', dr: L(100), cr: 0 }, { acc: '4010', dr: 0, cr: L(100) }] }, { level: 1 });
    ok(r.ok && !r.duplicate); eq(r.journal.approvedBy, 'website'); eq(r.journal.source.id, 'B1');
    ok(E.reverseJournal(st, r.journal.id, { reason: 'refund' }, CEO).ok);
    eq(calls.length, 1); eq(calls[0][0], 'B1'); eq(calls[0][1], 'reversed'); eq(calls[0][2], r.journal.id); eq(calls[0][3], CEO.name);
    const d = E.commit(st, CEO, { entity: 'IN', date: TODAY, idem: 'web:b:1', lines: [{ acc: '1150', dr: L(100), cr: 0 }, { acc: '4010', dr: 0, cr: L(100) }] }, { level: 1 });
    ok(d.ok && d.duplicate && d.reversed, 're-import of a reversed booking is a duplicate');
  } finally { delete E.HOOKS.webTest; }
});

t('E.find keeps an id map for big arrays that stays right when they change', () => {
  const arr = []; for (let i = 0; i < 200; i++) arr.push({ id: 'x' + i, n: i });
  eq(E.find(arr, 'x150').n, 150);
  arr.push({ id: 'x200', n: 200 }); eq(E.find(arr, 'x200').n, 200, 'appended');
  arr.splice(10, 1); eq(E.find(arr, 'x150').n, 150, 'after a splice'); eq(E.find(arr, 'x10'), null);
  arr[20] = { id: 'new', n: -1 }; eq(E.find(arr, 'new').n, -1, 'replaced in place (length unchanged)');
  arr.unshift({ id: 'first', n: 0 }); eq(E.find(arr, 'first').n, 0);
  eq(E.find(arr, 'nope'), null); eq(E.find(null, 'x'), null);
});

/* ---- engine-money regressions (queued approvals re-check their business preconditions) ---- */
function fundBook() {
  const st = book();
  E.postJournal(st, { entity: 'IN', date: '2026-09-01', memo: 'fund', lines: [{ acc: '1110', dr: L(10000000), cr: 0 }, { acc: '4090', dr: 0, cr: L(10000000) }] }, CEO);
  return st;
}
function hidePending(p, fn) { p.status = 'hold'; try { return fn(); } finally { p.status = 'submitted'; } }
function companySale(st, amt) {
  const ag = E.addAgent(st, { name: 'A' + Math.random() }, CEO).agent;
  const r = E.recordAgentSale(st, { agentId: ag.id, pax: 2, amount: amt, date: '2026-10-01', entity: 'IN', collectedBy: 'company', account: '1110' }, CEO);
  ok(r.ok, 'sale posted'); return r;
}

t('money: queued salary payment cannot be paid after its accrual is reversed', () => {
  const st = fundBook();
  E.addEmployee(st, { name: 'Ram', base: L(30000), from: '2026-01-01' }, CEO);
  const r = E.postPayroll(st, 'IN', '2026-09', E.payrollDraft(st, 'IN', '2026-09'), CEO); ok(r.ok);
  const cash0 = E.balance(st, 'IN', '1110', { to: TODAY });
  const pay = E.payPayroll(st, r.run.id, '1110', TODAY, ACC); ok(pay.queued, 'payment queued');
  const rv = E.reverseJournal(st, r.journal.id, { reason: 'x' }, CEO);
  ok(!rv.ok, 'accrual reversal refused while a payment waits for approval');
  ok(!E.requestReversal(st, r.journal.id, {}, FIN).ok, 'reversal request refused too');
  // even if the accrual got reversed by some other route, approval re-checks the run
  ok(hidePending(pay.pending, () => E.reverseJournal(st, r.journal.id, { reason: 'x' }, CEO).ok));
  eq(r.run.status, 'reversed');
  const ap = E.approve(st, pay.pending.id, CEO);
  ok(!ap.ok, 'approval refused'); eq(r.run.status, 'reversed', 'status kept');
  eq(E.balance(st, 'IN', '1110', { to: TODAY }), cash0, 'no cash left');
  eq(E.balance(st, 'IN', '2030', { to: TODAY }), 0);
  E.reject(st, pay.pending.id, CEO, 'x');
  ok(E.postPayroll(st, 'IN', '2026-09', E.payrollDraft(st, 'IN', '2026-09'), CEO).ok, 'month can be posted again');
  // hook: a 'posted' payment never overwrites 'reversed'; reversing it restores 'reversed'
  const run = { id: 'prx', status: 'reversed', journalId: r.journal.id, paidJournalId: '' }; st.payrollRuns.push(run);
  E.HOOKS.payrollPaid(st, { id: 'prx' }, { id: 'jp' }, 'posted');
  eq(run.status, 'reversed'); eq(run.paidJournalId, 'jp');
  E.HOOKS.payrollPaid(st, { id: 'prx' }, { id: 'jp' }, 'reversed');
  eq(run.status, 'reversed'); eq(run.paidJournalId, '');
  // a queued accrual reversal cannot be approved after the month was paid
  const st2 = fundBook(); E.addEmployee(st2, { name: 'Sita', base: L(30000), from: '2026-01-01' }, CEO);
  const r2 = E.postPayroll(st2, 'IN', '2026-09', E.payrollDraft(st2, 'IN', '2026-09'), CEO);
  const rq = E.requestReversal(st2, r2.journal.id, {}, FIN); ok(rq.queued);
  ok(!E.payPayroll(st2, r2.run.id, '1110', TODAY, CEO).ok, 'nothing to pay while the accrual reversal waits');
  ok(hidePending(rq.pending, () => E.payPayroll(st2, r2.run.id, '1110', TODAY, CEO).ok));
  ok(!E.approve(st2, rq.pending.id, CEO).ok, 'reversal approval refused after payment');
  eq(E.balance(st2, 'IN', '2030', { to: TODAY }), 0); eq(r2.run.status, 'paid');
  balanced(st, 'IN', TODAY); balanced(st2, 'IN', TODAY);
});

t('money: queued sale reversal cannot be approved after a partial cancel', () => {
  const st = fundBook(); const s = companySale(st, L(10000));
  const rq = E.requestReversal(st, s.journal.id, { reason: 'x' }, ACC); ok(rq.queued);
  const c = E.cancelAgentSale(st, s.sale.id, { amount: L(5000), refund: 'payable', date: TODAY }, ACC);
  ok(!c.ok, 'cancel refused while a reversal of the sale waits');
  ok(hidePending(rq.pending, () => E.cancelAgentSale(st, s.sale.id, { amount: L(5000), refund: 'payable', date: TODAY }, ACC).ok));
  const ap = E.approve(st, rq.pending.id, CEO);
  ok(!ap.ok, 'approval refused: the cancel must be reversed first');
  eq(s.sale.status, 'active'); eq(E.balance(st, 'IN', '4015', { to: TODAY }), -L(5000), 'one live cancel');
  eq(E.balance(st, 'IN', '4010', { to: TODAY }), L(10000));
  balanced(st, 'IN', TODAY);
});

t('money: duplicate queued refund payments / interest accruals are refused', () => {
  const st = fundBook(); const s = companySale(st, L(10000));
  ok(E.cancelAgentSale(st, s.sale.id, { refund: 'payable', date: TODAY }, CEO).ok);
  const p1 = E.payRefund(st, s.sale.id, '1110', TODAY, ACC); ok(p1.queued);
  ok(!E.payRefund(st, s.sale.id, '1110', TODAY, ACC).ok, 'second request refused (already waiting)');
  const p2 = hidePending(p1.pending, () => E.payRefund(st, s.sale.id, '1110', TODAY, ACC)); ok(p2.queued);
  ok(E.approve(st, p1.pending.id, FIN).ok);
  ok(!E.approve(st, p2.pending.id, FIN).ok, 'second approval refused');
  eq(E.balance(st, 'IN', '2040', { to: TODAY }), 0, 'refund paid once');
  // interest
  const D = E.addPerson(st, { name: 'D', kind: 'director_lender' }, CEO);
  const ln = E.addLoan(st, { lenderId: D.id, type: 'director', principal: L(100000), rate: 12, method: 'simple', start: '2026-07-15', receiveNow: true, account: '1110', docRef: 'x' }, CEO);
  const due = E.loanInterest(st, ln.loan, TODAY).toExpense; ok(due > 0);
  const a1 = E.accrueInterest(st, ln.loan.id, TODAY, ACC); ok(a1.queued);
  ok(!E.accrueInterest(st, ln.loan.id, TODAY, ACC).ok, 'second accrual refused');
  const a2 = hidePending(a1.pending, () => E.accrueInterest(st, ln.loan.id, TODAY, ACC)); ok(a2.queued);
  ok(E.approve(st, a1.pending.id, FIN).ok);
  ok(!E.approve(st, a2.pending.id, FIN).ok, 'second accrual approval refused');
  eq(E.balance(st, 'IN', '7110', { to: TODAY }), due, 'interest booked once');
  balanced(st, 'IN', TODAY);
});

t('money: a cash refund on cancel needs the out-payment approval level and a cash account', () => {
  const st = fundBook(); const s = companySale(st, L(9000000));
  const cash0 = E.balance(st, 'IN', '1110', { to: TODAY });
  const c = E.cancelAgentSale(st, s.sale.id, { refund: 'paid', refundAccount: '1110', date: TODAY }, ACC);
  ok(c.ok && c.queued, 'big cash refund queued'); eq(c.pending.level, 3);
  eq(E.balance(st, 'IN', '1110', { to: TODAY }), cash0, 'no cash left yet');
  ok(E.approve(st, c.pending.id, CEO).ok); eq(s.sale.status, 'cancelled');
  const s2 = companySale(st, L(100));
  ok(!E.cancelAgentSale(st, s2.sale.id, { refund: 'paid', refundAccount: '4090', date: TODAY }, ACC).ok, 'non-cash account refused');
  const c2 = E.cancelAgentSale(st, s2.sale.id, { refund: 'paid', refundAccount: '1110', date: TODAY }, ACC);
  ok(c2.ok && !c2.queued, 'small cash refund still posts at once');
  balanced(st, 'IN', TODAY);
});

t('money: a cancel whose refund was paid (or is waiting) cannot be reversed', () => {
  const st = fundBook(); const s = companySale(st, L(10000));
  const c = E.cancelAgentSale(st, s.sale.id, { refund: 'payable', date: TODAY }, CEO); ok(c.ok);
  const q = E.payRefund(st, s.sale.id, '1110', TODAY, ACC); ok(q.queued);
  ok(!E.reverseJournal(st, c.journal.id, {}, CEO).ok, 'blocked while a refund waits');
  E.reject(st, q.pending.id, CEO, 'x');
  const pr = E.payRefund(st, s.sale.id, '1110', TODAY, CEO); ok(pr.ok && !pr.queued);
  const rv = E.reverseJournal(st, c.journal.id, {}, CEO);
  ok(!rv.ok, 'blocked after the refund was paid');
  eq(E.balance(st, 'IN', '2040', { to: TODAY }), 0); eq(s.sale.status, 'cancelled');
  ok(E.reverseJournal(st, pr.journal.id, {}, CEO).ok, 'refund payment reversed first');
  ok(E.reverseJournal(st, c.journal.id, {}, CEO).ok, 'then the cancel');
  eq(E.balance(st, 'IN', '2040', { to: TODAY }), 0); eq(s.sale.status, 'active');
  balanced(st, 'IN', TODAY);
});

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
