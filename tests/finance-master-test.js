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

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
