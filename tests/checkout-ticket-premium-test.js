/* =====================================================================
 *  CHECKOUT + TICKET PREMIUM (wave 2, FD) — the customer screens tell the
 *  truth (8 Oct 2026).
 *
 *      node tests/checkout-ticket-premium-test.js [server-pickups.json]
 *
 *  Runs the SHIPPED functions (lifted out of the asset files by brace
 *  matching, like seat-label-parity.js), not copies of them:
 *    • Trip Companion counts to the passenger's OWN pickup — the client
 *      tcPickupTs() gives the same answer as the server's Boarding::pickupTs()
 *      (21:00 / 23:00 / past-midnight pickups on a 13:00 coach), and the
 *      phase follows it (no more fixed 08:15 from Surat).
 *    • the fallback ticket never prints PAID on unpaid cash (fbPayBand)
 *    • the keyed ticket link survives (dlKeyFrom), +977 numbers print as +977
 *    • the COD stepper says "Pay ₹X at counter", a missing proof says so
 *    • the refund chip is the live refund_slabs
 *    • the sign-in / checkout / ticket identity rules are in the source
 *      (a guest is never signed in, q.signedIn vs user.verified, the
 *      needs_otp cancel door, no 40 s skip timer, keyed Track button)
 *  When a JSON file of server pickup times is given (written by
 *  checkout-ticket-premium-test.php from Boarding::pickupTs), every case is
 *  compared with the server too.
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

const ROOT = path.join(__dirname, '..');
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');
const SRC = {
  cfg: read('assets/js/02-config.js'),
  co: read('assets/js/07-checkout.js'),
  si: read('assets/js/08-signin.js'),
  pdf: read('assets/js/11-pdf-ticket.js'),
  lazy: read('assets/js/16-lazy.js'),
  router: read('assets/js/05-router.js'),
  i18n: read('assets/js/04-i18n.js'),
  tpl: read('app.template.html'),
  css: read('assets/css/depth.css'),
};

/* Lift a top-level `function name(` declaration (brace matched; quotes and
   comments skipped so a "}" inside a string never ends it early). */
function lift(src, name) {
  const start = src.search(new RegExp('^function ' + name + '\\(', 'm'));
  if (start < 0) throw new Error('function ' + name + ' not found');
  let depth = 0, i = src.indexOf('{', start), q = null;
  for (; i < src.length; i++) {
    const c = src[i];
    if (q) { if (c === '\\') { i++; continue; } if (c === q) q = null; continue; }
    if (c === '/' && src[i + 1] === '/') { i = src.indexOf('\n', i); continue; }
    if (c === '/' && src[i + 1] === '*') { i = src.indexOf('*/', i) + 1; continue; }
    if (c === '"' || c === "'" || c === '`') { q = c; continue; }
    if (c === '{') depth++;
    else if (c === '}' && --depth === 0) break;
  }
  return src.slice(start, i + 1);
}
function liftVar(src, name) {
  const m = src.match(new RegExp('^var ' + name + ' = \\[[\\s\\S]*?^\\];', 'm'));
  if (!m) throw new Error('var ' + name + ' not found');
  return m[0];
}

/* ---- shared helpers the lifted code calls (same as 02-config.js) ------ */
const HELPERS = [
  "const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN');",
  "const digits = (s) => String(s || '').replace(/\\D/g, '');",
  "const esc = (s) => String(s == null ? '' : s).replace(/[&<>\"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', \"'\": '&#39;' }[c]));",
  liftVar(SRC.cfg, 'STOP_CODES'),
  lift(SRC.cfg, 'parseBP'), lift(SRC.cfg, 'stopTown'), lift(SRC.cfg, 'stopDisplay'),
  /* 15 Oct 2026 (D8): the refund chip reads the one policy source */
  "window.FACTS = (typeof SHG_BOOT !== 'undefined' && SHG_BOOT && SHG_BOOT.facts) || null;",
  lift(SRC.cfg, 'fact'), lift(SRC.cfg, 'liveRefundSlabs'),
].join('\n');

const ROUTES = {
  r2: { id: 'r2', from: 'Surat', to: 'Rupaidiha', depTime: '13:00', arrTime: '00:39', dayOffset: 2 },
  r7: { id: 'r7', from: 'Rupaidiha', to: 'Surat', depTime: '18:00', arrTime: '00:54', dayOffset: 2 },
};
function sandbox(extra, names, src) {
  const ctx = {
    window: { SHG_BOOT: extra && extra.boot }, location: { origin: 'https://www.shreehariglobal.in', hash: '' },
    URL: URL, console: console,
    routeById: (id) => ROUTES[id] || null,
    t: (k) => 'T:' + k, tf: (k, v) => 'T:' + k + JSON.stringify(v || {}),
    CONFIG: { booking: { refundSlabs: [{ minHrs: 48, pct: 75 }, { minHrs: 0, pct: 0 }] } },
    TC: { booking: null, phase: 0 },
    Date: Date,
  };
  if (extra && extra.boot) ctx.SHG_BOOT = extra.boot;
  vm.createContext(ctx);
  vm.runInContext(HELPERS + '\n' + names.map((n) => lift(src, n)).join('\n'), ctx);
  return ctx;
}
const IST = (y, m, d, hh, mm) => Date.UTC(y, m - 1, d, hh, mm) - 330 * 60000;

console.log('\n=== Checkout + ticket premium (FD, 8 Oct 2026) ===\n');

/* ---------------------------------------------------------------------- */
console.log('-- 1. Trip Companion: the passenger\'s own pickup time (D6-M1 / D7-04) --');
const lazy = sandbox(null, ['tcHm', 'tcPickupTs', 'tcArriveTs', 'tcIst', 'tcDeterminePhase'], SRC.lazy);
const CASES = [
  { key: 'surat13', b: { routeId: 'r2', date: '2026-10-20', boarding: 'Surat · Departure @ 13:00 [21.1702,72.8311]' }, want: IST(2026, 10, 20, 13, 0) },
  { key: 'chiloda21', b: { routeId: 'r2', date: '2026-10-20', boarding: 'Hari Pvt. Ltd. Parking - Nana Chiloda @ 21:00' }, want: IST(2026, 10, 20, 21, 0) },
  { key: 'mehsana23', b: { routeId: 'r2', date: '2026-10-20', boarding: 'Mehsana Head Office - Silver Complex @ 23:00 [23.588,72.369]' }, want: IST(2026, 10, 20, 23, 0) },
  { key: 'pastMidnight', b: { routeId: 'r2', date: '2026-10-20', boarding: 'Vadodara @ 00:30' }, want: IST(2026, 10, 21, 0, 30) },
  { key: 'noTime', b: { routeId: 'r2', date: '2026-10-20', boarding: 'Kamrej' }, want: IST(2026, 10, 20, 13, 0) },
  { key: 'r7Return', b: { routeId: 'r7', date: '2026-10-20', boarding: 'Rupaidiha @ 18:00' }, want: IST(2026, 10, 20, 18, 0) },
];
CASES.forEach((c) => {
  const got = lazy.tcPickupTs(c.b);
  check('pickup ' + c.key + ' = ' + new Date(c.want).toISOString(), got === c.want, got ? new Date(got).toISOString() : String(got));
});
check('the time reads in IST whatever the phone zone', lazy.tcIst(IST(2026, 10, 20, 21, 0)) === '21:00 IST', lazy.tcIst(IST(2026, 10, 20, 21, 0)));
check('no fixed 08:15 departure left in 16-lazy.js', !/setHours\(8,\s*15/.test(SRC.lazy));

/* server parity, when the PHP side wrote Boarding::pickupTs() answers */
const srvFile = process.argv[2];
if (srvFile && fs.existsSync(srvFile)) {
  const srv = JSON.parse(fs.readFileSync(srvFile, 'utf8'));
  CASES.forEach((c) => {
    if (srv[c.key] === undefined) return;
    const got = lazy.tcPickupTs(c.b);
    check('same as the server Boarding::pickupTs — ' + c.key, got === srv[c.key] * 1000, got + ' vs ' + srv[c.key] * 1000);
  });
} else {
  console.log('  (server parity skipped: no Boarding::pickupTs file given)');
}

/* phase follows the pickup: a 21:00 Nana Chiloda passenger on travel day */
const realNow = Date.now;
const phaseAt = (ts, b) => { lazy.Date.now = () => ts; lazy.TC.booking = b; lazy.tcDeterminePhase(); lazy.Date.now = realNow; return lazy.TC.phase; };
const b21 = Object.assign({ status: 'confirmed' }, CASES[1].b);
check('10:00 on the day of a 21:00 pickup = pre-trip (phase 0)', phaseAt(IST(2026, 10, 20, 10, 0), b21) === 0);
check('20:00 = departure countdown (phase 1, two hours before 21:00)', phaseAt(IST(2026, 10, 20, 20, 0), b21) === 1);
check('21:30 = on board (phase 2) — never at 08:15', phaseAt(IST(2026, 10, 20, 21, 30), b21) === 2 && phaseAt(IST(2026, 10, 20, 8, 20), b21) === 0);
check('after the route\'s arrival (+2d 00:39) = arrived (phase 3)', phaseAt(IST(2026, 10, 22, 1, 0), b21) === 3);
check('a trip the office cancelled never runs a countdown', phaseAt(IST(2026, 10, 20, 20, 0), Object.assign({}, b21, { tripStatus: 'cancelled' })) === 0);

check('Trip Companion: full PNR, real fare, own stops (D6-10)',
  !SRC.lazy.includes('TC.pnr.substring(0, 10)') && !SRC.lazy.includes('b.totalFare || b.fare') && SRC.lazy.includes("inr(b.total)") && SRC.lazy.includes('function tcOriginD(b)'));
check('leave-by: official coordinates only, GPS only on a tap (D7-04)',
  SRC.lazy.includes('function tcPickupGeo(b)') && SRC.lazy.includes("card.style.display = 'none'; return; }   // never a guessed position")
  && SRC.lazy.includes("btn.onclick = function () {") && !/ROUTE_STOPS\.find\(function\(s\) \{ return s\.name === boarding; \}\) \|\| ROUTE_STOPS\[0\]/.test(SRC.lazy));
check('bus banner from /api/whereis.php for THIS ticket; no anonymous livebus, no /api/eta.php (D7-03)',
  SRC.lazy.includes("fetch('/api/whereis.php?pnr='") && !SRC.lazy.includes("store.get('shg:livebus'") && !SRC.lazy.includes("shgApi.get('/eta.php") && SRC.lazy.includes('d.live'));
check('offline chat quotes the range of real pickup fares (D7-14)', SRC.lazy.includes('function fareRange(dir)') && SRC.lazy.includes("go = '<b>' + fareTxt('toNepal')"));

/* ---------------------------------------------------------------------- */
console.log('-- 2. Fallback ticket: PAID only when paid (D6-02), keyed fetches (D6-03), IST (D3-11) --');
const pdf = sandbox(null, ['fbPayBand', 'fbContact', 'dlKeyQs'], SRC.pdf);
const codDue = pdf.fbPayBand({ status: 'confirmed', codFlag: true, payment: { method: 'cod' }, total: 2070, paidAmount: 0 });
check('confirmed COD with nothing paid = CASH ON BOARDING, never PAID', codDue.kind === 'cash' && /CASH ON BOARDING/.test(codDue.text) && codDue.text.indexOf('2,070') >= 0, codDue.text);
const codPaid = pdf.fbPayBand({ status: 'confirmed', codFlag: true, payment: { method: 'cod' }, total: 2070, paidAmount: 2070 });
check('COD settled = PAID · CASH', codPaid.kind === 'paid' && /PAID/.test(codPaid.text), codPaid.text);
const bal = pdf.fbPayBand({ status: 'confirmed', payment: { method: 'upi' }, total: 2070, paidAmount: 1000 });
check('confirmed UPI with a balance = BALANCE DUE', bal.kind === 'due' && bal.text.indexOf('1,070') >= 0, bal.text);
check('pending UPI = PAYMENT PENDING', pdf.fbPayBand({ status: 'pending', payment: { method: 'upi' }, total: 2070 }).kind === 'pending');
check('the key rides on the server PNG / PDF fetch', pdf.dlKeyQs({ dlKey: 'ab12' }) === '&k=ab12' && pdf.dlKeyQs({}) === '' && SRC.pdf.includes("'&img=1&view=1' + dlKeyQs(b)"));
check('the offline border copy is asked for WITH the key', SRC.pdf.includes("swMsg({ type: 'shg-cache-ticket', pnr: b.id, k: b.dlKey || '' })"));
check('arrival is India time on the fallback image and PDF (no NPT)', !SRC.pdf.includes("' NPT'") && (SRC.pdf.match(/r\.arrTime \+ ' IST'/g) || []).length === 2);
check('no hard-coded +91 before the passenger number', !SRC.pdf.includes("'Your contact: +91 '") && SRC.pdf.includes("'Your contact: ' + fbContact(b)"));

/* ---------------------------------------------------------------------- */
console.log('-- 3. Ticket screen + checkout helpers (07-checkout.js) --');
const co = sandbox({ boot: { settings: { refund_slabs: [{ minHrs: 96, pct: 90 }, { minHrs: 48, pct: 75 }, { minHrs: 24, pct: 50 }, { minHrs: 6, pct: 25 }, { minHrs: 0, pct: 0 }] } } },
  ['dlKeyFrom', 'fmtContact', 'phoneShapeOk', 'coRefundSlabs', 'coRefundChipText', 'paymentStepper', 'coServerExtras'], SRC.co);
check('dlKeyFrom keeps the ticket key from the keyed link', co.dlKeyFrom('https://x/download-ticket.php?pnr=SHG-2026-00001&k=abc123') === 'abc123' && co.dlKeyFrom(null) === '');
check('a Nepal number prints +977, an unknown one bare (D6-14)',
  co.fmtContact({ contact: { phone: '9812345678', cc: '977' } }) === '+977 9812345678' && co.fmtContact({ contact: { phone: '9812345678', cc: '' } }) === '9812345678');
check('one phone shape: +91 starts 6-9, +977 starts 96-99',
  co.phoneShapeOk('9812345678', '977') && co.phoneShapeOk('6012345678', '91') && !co.phoneShapeOk('6012345678', '977') && !co.phoneShapeOk('5012345678', '91'));
const chip = co.coRefundChipText();
check('the refund chip is the live refund_slabs row when no facts are booted (D4-09)', chip.indexOf('≥96h 90%') >= 0 && chip.indexOf('≥6h 25%') >= 0 && chip.indexOf('<6h 0%') >= 0, chip);
/* D8 (15 Oct 2026): SHG_BOOT.facts.policy.slabs (Fare::refundSlabs) wins over the raw row */
const coD8 = sandbox({ boot: { facts: { policy: { slabs: [{ minHrs: 48, pct: 75 }, { minHrs: 24, pct: 50 }, { minHrs: 0, pct: 0 }] } },
  settings: { refund_slabs: [{ minHrs: 96, pct: 90 }, { minHrs: 0, pct: 0 }] } } }, ['coRefundSlabs', 'coRefundChipText'], SRC.co);
const chipD8 = coD8.coRefundChipText();
check('D8: the chip prints the one policy from facts — 48h 75% · 24h 50% · <24h 0%', chipD8 === '↩️ T:coRefundLbl: ≥48h 75% · ≥24h 50% · <24h 0%', chipD8);
const stCod = co.paymentStepper({ status: 'confirmed', codFlag: true, payment: { status: 'cod_pending', method: 'cod' }, total: 2070, paidAmount: 0 });
check('confirmed COD: step 2 is "Pay ₹2,070 at counter" (now), never "Verified" (D4-06)',
  /<li class="now"[^>]*><i>•<\/i><b>T:pstCounterDue\{&quot;a&quot;:&quot;₹2,070&quot;\}/.test(stCod) && stCod.indexOf('T:pstVerified') < 0, stCod.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
const stPaid = co.paymentStepper({ status: 'confirmed', codFlag: true, payment: { status: 'verified', method: 'cod' }, total: 2070, paidAmount: 2070 });
check('after the cash is settled every step is done', (stPaid.match(/class="done"/g) || []).length === 4);
const stProof = co.paymentStepper({ status: 'pending', payment: { status: 'pending', method: 'upi' }, proofPending: { utr: 'X' }, total: 2070 });
check('a proof that never arrived reads "Proof not received", not "Payment sent" (D4-M1)', stProof.indexOf('T:pstProofMissing') >= 0 && stProof.indexOf('T:pstSent') < 0);
const ex = co.coServerExtras({ ticketUrl: '/download-ticket.php?pnr=P&k=kk', verifyUrl: 'https://v', pickupMap: null, delayMin: 45, delayNote: 'tyre', tripStatus: 'cancelled',
  live_status: { key: 'trip_cancelled', label: 'Trip cancelled', color: 'red' }, arrived: false }, { depTime: '13:00' });
check('the adapter keeps key, verify link, delay, trip status and live pill (D6-03/D6-18/D7-M2)',
  ex.dlKey === 'kk' && ex.verifyUrl === 'https://v' && ex.delayMin === 45 && ex.tripStatus === 'cancelled' && ex.live_status.key === 'trip_cancelled' && ex.depTime === '13:00');

check('D4-03: checkout passes the picked country; a guest is never signed in',
  (SRC.co.match(/ensureCustomerSession\([^;]*contactCountry\(\)\)/g) || []).length === 2 && SRC.si.includes('  if (!USER) return false;'));
check('D4-03: "signed in" is q.signedIn (a quick sign-in is never verified)', SRC.si.includes('if (q && q.signedIn) {') && !SRC.si.includes('if (q && q.verified)'));
check('D4-10/D2-01: Continue is never a silent dead button', SRC.co.includes("btn.setAttribute('aria-disabled', ready ? 'false' : 'true');") && !/btn\.disabled = !ready/.test(SRC.co) && SRC.tpl.includes('id="coWhy"'));
check('D2-02: the lead name is required, the stylesheet "· optional" is switched off', SRC.co.includes('required aria-required="true"') && SRC.css.includes('.pax-row .field-optional > label::after { content: none !important; }'));
check('D2-07: every passenger box has its own label for= / id, errors are announced',
  SRC.co.includes('<label for="px${i}Name">') && SRC.co.includes('id="px${i}NameErr"') && SRC.co.includes("inp.setAttribute('aria-invalid'") && SRC.si.includes('<label class="trk-lbl" for="trackId">') && SRC.si.includes('<label class="trk-lbl" for="trackPhone">'));
check('D4-12: the draft is keyed by route + date and keeps the number\'s country',
  SRC.co.includes("var parts = ['shg:codraft', out.routeId || '', out.date || ''];") && SRC.co.includes('ccTrusted:') && SRC.co.includes('cAltPhone:'));
check('D4-05: an untouched +91 with a Nepal-shaped number on a Nepal trip asks once', SRC.co.includes('function coAskNepal(p)') && SRC.co.includes('if (coAskNepal(dv.phoneVal)) return;'));
check('D4-04: the loyalty preview reads only the server points', SRC.co.includes('loyaltyPoints: Math.max(0, Math.floor(Number(USER.points) || 0))'));
check('D5-09: a private leg never shows a gender-cabin warning', SRC.co.includes("if (!seat || !leg || leg.bookingType === 'private') return 'none';"));
check('D4-M1: proof retried 3 times; failure leaves a resend band', SRC.co.includes('const waits = [0, 1500, 3000];') && SRC.co.includes("booking.proofPending = { method: PAY_METHOD") && SRC.co.includes('id="proofResendBtn"'));
check('D6-01: ✅ only when WhatsApp says delivered; the direct banner never beside a failure',
  SRC.co.includes("} else if (waState === 'delivered') {") && SRC.co.includes("(b.wa === null || waState === 'pending')") && !SRC.co.includes('comes to your WhatsApp by itself'));
check('D6-11: Directions only from official coordinates; correction opens the request sheet', SRC.co.includes("(conf && b.pickupMap && !tripCancelled)") && SRC.co.includes("window.shgOpenWaReq('correction', b.id)") && SRC.router.includes('window.shgOpenWaReq = function (tp, pnr)'));
check('D6-13: a confirmed COD seat says "cash due", the UPI option folded', SRC.co.includes("tf('codDueLbl', { a: money2(due2) })") && SRC.co.includes('<details class="premium-pay-opt">'));
check('D6-18: the on-screen QR is the keyed verify link', SRC.co.includes('makeQR(b.verifyUrl || ticketQrPayload(b, r), 150)'));
check('D7-M2: the office delay comes from the booking; a cancelled trip says so with no refund promise',
  SRC.co.includes('Number(b.delayMin) > 0') && SRC.co.includes("delayBanner = '<div class=\"tk-trip-cx\" role=\"alert\">") && !/tripCx[A-Za-z]*: '[^']*refund/i.test(SRC.i18n));
check('cancel: the needs_otp refusal turns into the WhatsApp-code door', SRC.co.includes("e.fields.code === 'needs_otp'") && SRC.co.includes('function cxNeedOtpHTML(b)'));

/* ---------------------------------------------------------------------- */
console.log('-- 4. Sign-in + My bookings (08-signin.js) --');
const si = sandbox(null, ['isMyHash'], SRC.si);
check('#/my?pnr= is My Bookings too (D4-13)', si.isMyHash('#/my?pnr=SHG-2026-00001') && si.isMyHash('#/my') && !si.isMyHash('#/myx') && SRC.router.includes("h === '#/my' || h.indexOf('#/my?') === 0"));
check('the skip box appears only when WhatsApp did not take the code — no 40 s timer (D4-M4)', !SRC.si.includes('waited === 40') && SRC.si.includes("r.sent === false && r.canSkip"));
check('D4-15: guest link, SHG-2026 example, shared phone shape', SRC.si.includes('id="lgGuest"') && SRC.si.includes('placeholder="SHG-2026-XXXXX"') && SRC.si.includes('phoneShapeOk(p, cc)') && !SRC.i18n.includes('SHG-AB12CD'));
check('My bookings shows the "verify on WhatsApp" banner from the server\'s needsOtp', SRC.si.includes('MY_NEEDS_OTP = !!d.needsOtp;') && SRC.si.includes('class="my-otp-banner"'));
check('one Track button = the keyed tracker (trackUrl), never the anonymous map', SRC.si.includes("const trackHref = b.trackUrl ? String(b.trackUrl) : '#/trip/' + b.id;") && !SRC.si.includes('🛰️ Live</a>') && !SRC.si.includes("'#/nav'"));
check('the live pill knows trip_cancelled (red)', SRC.si.includes('.live-pill.c-red,.live-pill.trip_cancelled'));
check('D4-11: sign-out removes server tickets, saved travellers, contact and drafts', SRC.si.includes('function shgSignOutPurge()') && SRC.si.includes('PaxMemory.clear()') && SRC.si.includes("localStorage.removeItem('shg_cust_contact')") && SRC.si.includes('CheckoutDraft.clearAll()'));
check('D2-04: the login sheet has its own key (lgMoreId)', SRC.si.includes("t('lgMoreId')") && !SRC.si.includes("t('lgMore')"));

/* ---------------------------------------------------------------------- */
console.log('-- 5. Words in all three languages, no duplicate keys --');
const objStart = SRC.i18n.indexOf('const I18N = {');
['en', 'hi', 'ne'].forEach((lang, idx) => {
  const re = new RegExp('^ *' + lang + ': \\{', 'm');
  const s = SRC.i18n.slice(objStart).search(re);
  check('found the ' + lang + ' dictionary', s >= 0);
  if (s < 0) return;
  const from = objStart + s;
  const nextLang = ['hi', 'ne', 'zz'][idx];
  const e = SRC.i18n.slice(from + 5).search(new RegExp('^ *(' + nextLang + ': \\{|\\};)', 'm'));
  const body = SRC.i18n.slice(from, e < 0 ? undefined : from + 5 + e);
  const keys = (body.match(/(?:^|[\s,{])([A-Za-z_][A-Za-z0-9_]*):\s*['"`]/g) || []).map((k) => k.replace(/^[\s,{]+/, '').replace(/:.*$/, ''));
  const seen = {}, dup = [];
  keys.forEach((k) => { if (seen[k] && dup.indexOf(k) < 0) dup.push(k); seen[k] = 1; });
  const mine = ['tSubmittedCod', 'pstCounterDue', 'coWhyLead', 'coNpAskT', 'myNeedsOtp', 'cxNeedOtp', 'lgGuest', 'lgMoreId', 'tkWaDirect', 'proofFailT', 'tcLiveAt', 'codDueLbl'];
  check(lang + ': no duplicate keys in the dictionary', dup.length === 0, dup.join(', '));
  check(lang + ': every FD key is defined', mine.every((k) => seen[k]), mine.filter((k) => !seen[k]).join(', '));
});
check('errProofUpload no longer says the booking is "confirmed"', !/errProofUpload: '[^']*confirmed/.test(SRC.i18n));
check('saveTicketNote no longer promises an offline-scanning PDF QR (D6-18)', !/saveTicketNote: '[^']*PDF/.test(SRC.i18n));
check('checkout chip and rules no longer say "<24h = no refund"', !SRC.tpl.includes('&lt;24h = no refund') && !SRC.tpl.includes('&lt;24h → no refund') && SRC.tpl.includes('id="coRefundChip"'));
check('FD styles sit inside depth.css, above "reduced motion"',
  SRC.css.indexOf('FD — checkout, sign-in, My bookings') > 0 && SRC.css.indexOf('FD — checkout, sign-in, My bookings') < SRC.css.indexOf('/* reduced motion: depth stays, movement goes */'));

console.log('\n----------------------------------------');
console.log(PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL > 0 ? 1 : 0);
