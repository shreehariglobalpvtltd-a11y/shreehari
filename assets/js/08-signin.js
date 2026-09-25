
/* ================================================================
   [JS] 10b. SIGN-IN (phone + OTP, mocked locally) & MY BOOKINGS
   🔁 SMS UPGRADE PATH: replace the two marked lines in sendOtp()
   with a fetch() to your SMS gateway — the rest stays the same.
================================================================ */
let USER = (function () {
  try { return JSON.parse(localStorage.getItem('shg:user') || 'null'); } catch (e) { return null; }
})();
/* getUser() used to live in 12-admin-panel.js, which left the customer
   bundle in the §2 purge (shg-v56) — every sign-in click since then threw
   "getUser is not defined". Defined here, guarded, so nothing else can
   take it away again. */
if (typeof getUser !== 'function') { window.getUser = function () { return USER; }; }
/* Adopt the language stored against the account, if the traveller has not
   already chosen one on THIS device. Local choice wins — someone who just
   tapped "EN" on a borrowed phone should not be overridden by a preference
   they set months ago. */
function adoptAccountLang(su) {
  try {
    const stored = (su && su.lang) || '';
    if (!stored) return;
    let local = null;
    try { local = localStorage.getItem('shg:lang'); } catch (e) {}
    if (!local && typeof setLang === 'function' && stored !== (typeof LANG !== 'undefined' ? LANG : '')) {
      setLang(stored);
    }
  } catch (e) {}
}

function setUser(u) {
  USER = u;
  try { if (u) localStorage.setItem('shg:user', JSON.stringify(u)); else localStorage.removeItem('shg:user'); } catch (e) {}
  /* Signing out drops any ticket kept for offline use. These are cached on
     phones but also on the shared counter machine, and the next passenger
     at that desk must not be able to open the previous one's ticket. */
  if (!u) { try { swMsg({ type: 'shg-clear-tickets' }); } catch (e) {} }
  updateNavUser();
}
function updateNavUser() {
  /* A signed-in traveller sees their own first name on the button (that is
     what "sign in with name + mobile" promises); the masked number is the
     fallback for an account that has no name on file yet. */
  const first = USER && USER.name ? String(USER.name).trim().split(/\s+/)[0] : '';
  const label = USER
    ? ('👤 ' + (first ? esc(first.length > 14 ? first.slice(0, 13) + '…' : first) : (USER.phone.slice(0, 2) + '•••' + USER.phone.slice(7))))
    : '👤 Sign in';
  const b1 = $('#navUserBtn'); if (b1) b1.innerHTML = label;
  const b2 = $('#mmUserBtn'); if (b2) b2.innerHTML = label;
}
document.addEventListener('click', (e) => {
  if (!e.target.closest('#navUserBtn') && !e.target.closest('#mmUserBtn')) return;
  e.preventDefault();
  document.body.classList.remove('menu-open');
  /* Already signed in as a customer? That button is their account, so go
     straight to their bookings. Otherwise this is a Login click — open the
     OTP modal directly. Staff sign in at /admin/ via a bookmark; the old
     3-portal picker is gone (master-prompt §2, 2026-08-29). */
  if (USER) { location.hash = '#/my'; return; }
  if (typeof openLoginModal === 'function') openLoginModal();
});
/* mmRoleBtn handler removed 2026-08-29 — the "Switch portal" menu entry
   is gone; staff bookmark /admin/. */

/* ---------------------------------------------------------------------
   SIGN-IN = NAME + MOBILE (owner's rule, 4 Sep 2026). Two boxes, one tap.
   No OTP, no password: api/otp.php's `quick` action registers a new
   number on the spot and welcomes a returning one back. The same two
   details are copied onto every ticket the account books, and one
   account may hold as many tickets as it likes (see refreshMyBookings).
   The OTP request/verify path below is kept only as a fallback for an
   older server that still answers `needsOtp`.
   ------------------------------------------------------------------- */
function openLoginModal() {
  const savedU = getUser();
  let savedName = (savedU && savedU.name) || '';
  if (!savedName) { try { savedName = (JSON.parse(localStorage.getItem('shg_cust_contact') || 'null') || {}).name || ''; } catch (e) {} }
  const savedPhone = (savedU && savedU.phone) || '';
  openModal(`
    <h3 class="m-title">👤 ${t('qlTitle')}</h3>
    <p class="m-sub">${t('qlP')}</p>
    <div class="field"><label for="qlName">${t('lblName')}</label><input id="qlName" autocomplete="name" autocapitalize="words" maxlength="120" value="${esc(savedName)}"><div class="err">${t('errName')}</div></div>
    <div class="field"><label for="otpPhone">${t('lblPhone')}</label>
      <div style="display:flex;gap:8px;align-items:stretch">
        <select id="otpCC" aria-label="Country code" style="flex:0 0 116px;padding:0 10px;border:1.5px solid var(--line);border-radius:12px;background:var(--card);color:inherit;font:inherit;font-weight:600">
          <option value="91">🇮🇳 +91</option>
          <option value="977">🇳🇵 +977</option>
        </select>
        <input id="otpPhone" inputmode="numeric" maxlength="10" placeholder="98XXXXXXXX" autocomplete="tel-national" value="${esc(savedPhone)}" style="flex:1;min-width:0">
      </div>
      <div class="err">${t('errPhone')}</div></div>
    <div class="m-actions"><button class="btn btn-orange" type="button" id="otpSendBtn" style="width:100%">${t('qlBtn')}</button></div>`);
  /* Returning traveller: pre-select the country they signed in with last time
     so a Nepali number is not shown against +91. */
  const savedCC = (savedU && savedU.country) || '';
  if (savedCC === 'NP' || savedCC === 'IN') { const ccEl = $('#otpCC'); if (ccEl) ccEl.value = savedCC === 'NP' ? '977' : '91'; }
  (savedName ? $('#otpPhone') : $('#qlName')).focus();
  $('#otpSendBtn').onclick = sendOtp;
  $('#qlName').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#otpPhone').focus(); } });
  $('#otpPhone').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendOtp(); });
}

/* Put the signed-in traveller's details onto an open checkout form: phone
   and lead-passenger name, only where the box is still empty, then poke the
   group mirror + Continue gate so they notice. */
function applyUserToCheckout() {
  if (!USER) return;
  const ph = $('#cPhone');
  if (ph && !digits(ph.value) && USER.phone) { ph.value = USER.phone; if (typeof checkWelcomeBack === 'function') checkWelcomeBack(); }
  /* Route the ticket to the account's country, so a Nepali traveller who just
     signed in is not left on the +91 default. Marks the picker trusted. */
  const cc = $('#cCountry');
  if (cc && !(typeof isCounterSale === 'function' && isCounterSale()) && (USER.country === 'NP' || USER.country === 'IN')) {
    cc.value = USER.country === 'NP' ? '977' : '91';
    cc.dataset.trusted = '1';
  }
  const nm = $('#paxRows .grp-lead .pxName') || $('#paxRows .pxName');
  if (nm && !nm.value.trim() && USER.name) {
    nm.value = USER.name;
    nm.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (typeof coSyncContinue === 'function') coSyncContinue();
}

/* Make sure the SERVER session belongs to this traveller before a booking
   is written, so the ticket's contact number is bound to the account. A
   fresh sign-in is skipped when one happened in the last few minutes; any
   failure is swallowed — the booking still goes through as a guest and the
   server keeps its own guards. */
async function ensureCustomerSession(name, phone, country) {
  const p = digits(phone || '');
  if (!/^\d{10}$/.test(p)) return false;
  /* A staff counter session (index.php?counter=1) must NEVER sign the shared
     office browser in as the passenger it is selling to — otherwise USER
     becomes the last customer, the app greets the clerk by that name and
     pre-fills the next ticket with the previous passenger (owner, 6 Sep 2026:
     "app le passenger jasto treat garirax, arko ticket katna miliraxoina").
     The booking is still bound to the passenger server-side by phone; the
     desk stays itself. */
  if (window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell) return false;
  const nm = String(name || '').trim();
  if (USER && USER.phone === p && USER.srvAt && (Date.now() - USER.srvAt) < 5 * 60 * 1000) return true;
  try {
    const q = await shgApi.post('/otp.php', { action: 'quick', phone: p, name: nm.length >= 2 ? nm : ((USER && USER.phone === p && USER.name) || ''), country: country || '' });
    if (q && q.verified) {
      const su = q.user || {};
      setUser({ phone: su.phone || p, name: su.name || nm, role: su.role || 'customer',
                points: su.points || 0, tier: su.tier || 'Silver', country: su.country || '', at: Date.now(), srvAt: Date.now() });
      ensureUser(p);
      adoptAccountLang(su);
      return true;
    }
  } catch (e) { /* guest checkout still works */ }
  return false;
}

/* One account, many tickets: pull every booking the server holds for this
   number and merge it into the local list (a phone that booked at the
   counter, on another device, or before a cleared cache sees them all). */
let _myBkAt = 0, _myBkBusy = false;
/* Which slice of My Bookings is on screen. The server filters the same
   four ways (api/my-bookings.php?tab=), but the list is also rendered from
   the local cache so the page still works with no network — so the rule
   has to exist on both sides and agree. */
let MY_TAB = 'all';

/* "Upcoming" is about the JOURNEY, not the booking row: a confirmed ticket
   for tomorrow is upcoming, the same ticket next week is completed.
   Mirrors the SQL in api/my-bookings.php. */
function myTabMatch(b, tab) {
  if (!b || tab === 'all') return true;
  const st = b.status || 'pending';
  const d  = (b.date || '').slice(0, 10);
  const todayStr = new Date().toISOString().slice(0, 10);
  const future = d !== '' && d >= todayStr;
  if (tab === 'upcoming')  return (st === 'pending' || st === 'confirmed') && future;
  if (tab === 'completed') return st === 'completed' || (st === 'confirmed' && !future);
  if (tab === 'cancelled') return st === 'cancelled' || st === 'rejected' || st === 'expired';
  return true;
}

function myTabBar(counts) {
  const tabs = [['all', t('tabAll')], ['upcoming', t('tabUpcoming')],
                ['completed', t('tabDone')], ['cancelled', t('tabCancelled')]];
  return '<div class="my-tabs" role="tablist">' + tabs.map(function (x) {
    const on = MY_TAB === x[0];
    return '<button type="button" role="tab" aria-selected="' + on + '" class="my-tab'
      + (on ? ' on' : '') + '" data-mytab="' + x[0] + '">' + esc(x[1])
      + '<i>' + (counts[x[0]] || 0) + '</i></button>';
  }).join('') + '</div>';
}

async function refreshMyBookingsFromServer(force) {
  if (!USER || _myBkBusy) return false;
  if (!force && (Date.now() - _myBkAt) < 20000) return false;
  _myBkBusy = true;
  try {
    let d;
    const q = '/my-bookings.php?tab=' + encodeURIComponent(MY_TAB);
    try { d = await shgApi.get(q); }
    catch (e) {
      if (!(e && e.status === 401)) throw e;
      /* Server session idled out while the browser still remembers the
         traveller — sign in again silently with the same name + number. */
      if (USER) USER.srvAt = 0;
      const ok = await ensureCustomerSession(USER.name, USER.phone, '');
      if (!ok) throw e;
      d = await shgApi.get(q);
    }
    _myBkAt = Date.now();
    let changed = false;
    (d.bookings || []).forEach(sb => {
      const b = bookingFromServer(sb, d.phone || USER.phone);
      const at = DB.bookings.findIndex(x => x && x.id === b.id);
      if (at < 0) { DB.bookings.push(b); changed = true; return; }
      const cur = DB.bookings[at];
      const pay = Object.assign({}, cur.payment || {}, {
        status: b.payment.status || (cur.payment || {}).status,
        method: b.payment.method || (cur.payment || {}).method,
        reason: b.payment.reason
      });
      const next = Object.assign({}, cur, { status: b.status, total: b.total, codFlag: b.codFlag,
        ticketNumber: b.ticketNumber || cur.ticketNumber || '', payment: pay,
        trackUrl: b.trackUrl || cur.trackUrl || null,
        createdAt: cur.createdAt || b.createdAt });
      if (JSON.stringify(next) !== JSON.stringify(cur)) { DB.bookings[at] = next; changed = true; }
    });
    if (changed) persist('bookings');
    return changed;
  } catch (e) {
    return false;
  } finally {
    _myBkBusy = false;
  }
}

/* Sign-in click. Validates the two boxes, asks the server for a session,
   and falls back to the old OTP handshake only if the server asks for it. */
async function sendOtp() {
  const p = digits($('#otpPhone').value);
  const cc = ($('#otpCC') && $('#otpCC').value) || '91';
  const country = cc === '977' ? 'NP' : 'IN';
  const f = $('#otpPhone').closest('.field');
  if (!/^\d{10}$/.test(p)) { f.classList.add('invalid'); $('#otpPhone').focus(); return; }
  f.classList.remove('invalid');
  const nameEl = $('#qlName');
  const knownName = nameEl ? nameEl.value.trim() : ((getUser() || {}).name || '');
  if (nameEl) {
    const nf = nameEl.closest('.field');
    if (knownName.length < 2) { nf.classList.add('invalid'); nameEl.focus(); return; }
    nf.classList.remove('invalid');
  }

  const sendBtn = $('#otpSendBtn');
  if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = '…'; }

  let q = null;
  try {
    q = await shgApi.post('/otp.php', { action: 'quick', phone: p, name: knownName, country: country });
  } catch (e) {
    /* A refusal (suspended account, missing name, too many tries, network)
       is shown as-is — there is no second door to try. */
    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = t('qlBtn'); }
    toast((e && e.message) || t('errOtpSend'));
    return;
  }
  if (q && q.verified) {
    const su = q.user || { phone: p };
    setUser({ phone: su.phone || p, name: su.name || knownName, role: su.role || 'customer',
              points: su.points || 0, tier: su.tier || 'Silver', country: su.country || '', at: Date.now(), srvAt: Date.now() });
    ensureUser(p);
    adoptAccountLang(su);
    closeModal();
    toast(t('otpOkT'));
    if (location.hash === '#/my') renderMyBookings();
    else if (location.hash === '#/checkout') applyUserToCheckout();
    return;
  }
  /* q.needsOtp === true from an older server → the OTP handshake below. */

  let result;
  try {
    result = await shgApi.post('/otp.php', { action: 'request', phone: p, purpose: 'login', country: country });
  } catch (e) {
    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = t('otpSend'); }
    toast(e.message || t('errOtpSend'));
    return;
  }

  // debugCode is only ever present when the server runs in development mode.
  const demoNote = result && result.debugCode ? tf('otpDemo', { c: result.debugCode }) : '';
  openModal(`
    <h3 class="m-title">🔐 ${t('otpTitle')}</h3>
    <p class="m-sub">${tf('otpSentP', { p: '+' + cc + ' ' + p })}</p>
    ${demoNote ? '<div class="verify-note" style="margin-bottom:14px">🧪 <span>' + demoNote + '</span></div>' : ''}
    <div class="field"><label>${t('lblOtpCode')}</label><input id="otpCode" inputmode="numeric" maxlength="6" style="text-align:center;font-family:var(--f-code);font-size:22px;letter-spacing:.3em"><div class="err">${t('otpBad')}</div></div>
    <div class="m-actions"><button class="btn btn-orange" type="button" id="otpVerifyBtn" style="width:100%">${t('otpVerify')}</button></div>`);
  $('#otpCode').focus();
  const verify = async () => {
    const code = $('#otpCode').value.trim();
    if (!/^\d{4,6}$/.test(code)) { $('#otpCode').closest('.field').classList.add('invalid'); return; }
    const vbtn = $('#otpVerifyBtn');
    if (vbtn) { vbtn.disabled = true; vbtn.textContent = '…'; }
    try {
      const v = await shgApi.post('/otp.php', { action: 'verify', phone: p, code: code, purpose: 'login', country: country });
      const su = (v && v.user) || { phone: p };
      setUser({ phone: su.phone || p, name: su.name || '', role: su.role || 'customer',
                points: su.points || 0, tier: su.tier || 'Silver', country: su.country || '', at: Date.now() });
      ensureUser(p);          // keeps the local agent/referral mirror (DB.users) in sync
      closeModal();
      toast(t('otpOkT'));
      if (location.hash === '#/my') renderMyBookings();
      else if (location.hash === '#/checkout' && !$('#cPhone').value) { $('#cPhone').value = p; checkWelcomeBack(); }
    } catch (e) {
      if (vbtn) { vbtn.disabled = false; vbtn.textContent = t('otpVerify'); }
      $('#otpCode').closest('.field').classList.add('invalid');
      toast(e.message || t('otpBad'));
    }
  };
  $('#otpVerifyBtn').onclick = verify;
  $('#otpCode').addEventListener('keydown', (e) => { if (e.key === 'Enter') verify(); });
}

/* Shared pending/confirmed/cancelled/rejected badge markup (was independently duplicated
   here and in renderAdminBookings). Labels are passed in, not hardcoded here, because the
   two call sites deliberately differ: customer-facing myBookingCard is trilingual via t(),
   while the Admin panel stays English-only by design — see project conventions. */
function bookingStatusBadge(status, labels) {
  const cls = status === 'pending' ? 'pend' : status === 'confirmed' ? 'ok' : status === 'cancelled' ? '' : 'bad';
  const text = status === 'pending' ? labels.pending : status === 'confirmed' ? labels.confirmed : status === 'cancelled' ? labels.cancelled : labels.rejected;
  return '<span class="badge' + (cls ? ' ' + cls : '') + '">' + text + '</span>';
}

/* Live-status pill styles for My Bookings — one-time injection, keyed by
   id so it stays idempotent no matter how many cards render. Kept inline
   in this JS file so no other file has to change to pick up the pill. */
(function ensureLivePillCss() {
  if (typeof document === 'undefined' || document.getElementById('live-pill-css')) return;
  const s = document.createElement('style');
  s.id = 'live-pill-css';
  s.textContent =
      '.live-pill{display:inline-block;padding:2px 9px;border-radius:999px;'
    +   'font-size:11px;font-weight:700;letter-spacing:.02em;margin-left:6px;'
    +   'vertical-align:middle;line-height:1.4}'
    + '.live-pill.upcoming{background:var(--blue-50,#e6efff);color:var(--blue-800,#1f4bb8);border:1px solid var(--blue-100,#cddaf5)}'
    + '.live-pill.departing{background:var(--orange-100,#fff2e0);color:var(--orange-600,#a35700);border:1px solid var(--orange-100,#f8dcb0)}'
    + '.live-pill.departed{background:var(--bg,#f0f0f2);color:var(--muted,#666);border:1px solid var(--line,#dcdce2)}';
  (document.head || document.documentElement).appendChild(s);
})();

function myBookingCard(b) {
  const r = routeById(b.routeId) || {};
  /* V5: a COD/offline booking is "pending" until staff phone-confirm it — show
     a distinct "awaiting call" badge instead of the generic verification one. */
  const isCodPending = b.status === 'pending' && (b.codFlag || (b.payment && b.payment.method === 'cod'));
  const stBadge = isCodPending
    ? '<span class="badge pend">📞 Awaiting call · पुष्टि बाँकी</span>'
    : bookingStatusBadge(b.status, { pending: t('stPendT'), confirmed: t('stConfT'), cancelled: t('stCancT'), rejected: t('stRejT') });
  /* Server-computed live-trip pill (Ticket::liveStatus → api/track.php).
     Local-only bookings that never round-tripped through the server simply
     don't have b.live_status — fallback: render nothing. */
  const live = b && b.live_status;
  const livePill = (live && live.key && live.label)
    ? '<span class="live-pill ' + esc(live.key) + '" title="' + esc(live.description || '') + '">' + esc(live.label) + '</span>'
    : '';
  return `<div class="mybk-card">
    <div class="mybk-head">
      <b style="font-family:var(--f-code)">${esc(b.id)}</b>
      ${b.ret ? '<span class="chip orange">⇄</span>' : ''}
      ${stBadge}
      ${livePill}
      ${(function () { try { return window.SHGBorder ? window.SHGBorder.badge(b) : ''; } catch (e) { return ''; } })()}
      <small>${fmtDate(b.date)}</small>
    </div>
    <div class="mybk-route">${esc(parseBP(b.boarding).name || r.from || '?')} <em>→</em> ${esc(parseBP(b.drop).name || r.to || '?')} · ${seatLabelJoin(b.seats, r.type, b.bookingType)} · <b>${inr(b.total)}</b></div>
    ${(b.status === 'cancelled' && window.SHG_TRUST) ? SHG_TRUST.refundLine(b) : ''}
    <div class="mybk-actions">
      <a class="btn btn-blue btn-sm" href="#/ticket/${esc(b.id)}">🎫 ${t('st5')}</a>
      ${b.status === 'confirmed' ? '<button class="btn btn-ghost btn-sm" type="button" data-mypdf="' + esc(b.id) + '">' + t('btnPdf') + '</button>' : ''}
      ${b.status === 'confirmed' ? '<a class="btn btn-ghost btn-sm" href="#/trip/' + esc(b.id) + '">🚌 Trip</a>' : ''}
      ${b.status === 'confirmed' ? '<a class="btn btn-ghost btn-sm" href="#/trip/' + esc(b.id) + '">🛰️ Live</a>' : ''}
      ${b.status === 'confirmed' ? '<button class="btn btn-ghost btn-sm" type="button" data-mywa="' + esc(b.id) + '">🟢 WhatsApp</button>' : ''}
      <button class="btn btn-orange btn-sm" type="button" data-myrebook="${esc(b.id)}">${t('btnRebook')}</button>
      ${canCancel(b) ? '<button class="btn btn-danger-ghost btn-sm" type="button" data-mycancel="' + esc(b.id) + '">' + t('btnCancel') + '</button>' : ''}
    </div>
    ${(b.status === 'pending' || b.status === 'confirmed') && depTimestamp(b) > Date.now() ? distanceWidgetHTML(b) : ''}
  </div>`;
}

function renderMyBookings() {
  const box = $('#myBody');
  /* One account, many tickets: ask the server for this number's bookings
     and redraw once if anything new arrived (throttled inside). */
  if (USER && typeof refreshMyBookingsFromServer === 'function') {
    refreshMyBookingsFromServer().then(ch => { if (ch && location.hash === '#/my') renderMyBookings(); });
  }
  const trackCard = `
    <div class="c-card" style="margin-top:18px">
      <b style="font-family:var(--f-display);font-size:17px">🔎 ${t('myTrackT')}</b>
      <p style="font-size:13.5px;color:var(--muted);margin:6px 0 14px">${t('myTrackP')}</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <input id="trackId" placeholder="SHG-XXXXXX" style="flex:1;min-width:160px;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg,#FBFCFE);font-family:var(--f-code);text-transform:uppercase">
        <input id="trackPhone" inputmode="tel" autocomplete="tel" placeholder="${esc(t('trkPhone'))}" value="${esc(USER ? USER.phone : '')}" style="flex:1;min-width:160px;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg,#FBFCFE)">
        <button class="btn btn-blue" type="button" id="trackBtn">${t('myTrackBtn')}</button>
      </div>
      <div id="trackMsg" style="font-size:12.5px;color:var(--bad);margin-top:8px"></div>
    </div>`;
  {
    const uPhone = USER ? USER.phone : '';
    const mine = uPhone
      ? DB.bookings.filter(b => digits(b.contact && b.contact.phone) === uPhone).sort((a, b2) => b2.createdAt - a.createdAt)
      : DB.bookings.slice().sort((a, b2) => b2.createdAt - a.createdAt);
    const shown = mine.filter(function (b) { return myTabMatch(b, MY_TAB); });
    const trips = uPhone ? tripsForPhone(uPhone) : DB.bookings.length;
    let loyaltyCard = '';
    if (uPhone) {
      const u = ensureUser(uPhone);
      const ti = tierFor(u.loyaltyPoints || 0);
      const histRows = (u.loyaltyHistory || []).slice(0, 8).map(h =>
        '<div class="sum-row"><span>' + new Date(h.date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' · ' + esc(h.reason) + '</span><b style="color:' + (h.change > 0 ? 'var(--ok)' : 'var(--bad)') + '">' + (h.change > 0 ? '+' : '') + h.change + '</b></div>').join('');
      loyaltyCard = `
        <div class="c-card loy-card" style="margin-bottom:16px">
          <div class="loy-head">
            <div><small>${t('loyTitle')}</small><b class="loy-n">${(u.loyaltyPoints || 0).toLocaleString('en-IN')} <span>${t('loyPts')}</span></b></div>
            <span class="loy-tier">${ti.tier.icon} ${esc(ti.tier.name)}${ti.tier.discountPct ? ' · −' + ti.tier.discountPct + '%' : ''}</span>
          </div>
          ${ti.next ? '<div class="loy-bar"><i style="width:' + Math.min(100, Math.round(((u.loyaltyPoints || 0) - ti.tier.min) / Math.max(1, ti.next.min - ti.tier.min) * 100)) + '%"></i></div><small style="color:var(--muted)">' + tf('loyNextTier', { n: ti.next.min - (u.loyaltyPoints || 0), t: ti.next.icon + ' ' + ti.next.name }) + '</small>' : ''}
          ${histRows ? '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px;font-weight:700">' + t('loyHistT') + '</summary>' + histRows + '</details>' : ''}
        </div>`;
    }
    box.innerHTML = `
      <div class="my-hello">
        <div>
          <b>${uPhone ? t('myHello') + ' +' + ((USER && USER.country === 'NP') ? '977' : '91') + ' ' + esc(uPhone) : '🎫 All Bookings'}</b>
          ${trips ? '<small>⭐ ' + tf('myTripsN', { n: trips }) + '</small>' : ''}
        </div>
        ${uPhone ? '<button class="btn btn-ghost btn-sm" type="button" id="myLogoutBtn">' + t('myLogout') + '</button>' : ''}
      </div>
      ${loyaltyCard}
      ${mine.length ? myTabBar({
          all:       mine.length,
          upcoming:  mine.filter(x => myTabMatch(x, 'upcoming')).length,
          completed: mine.filter(x => myTabMatch(x, 'completed')).length,
          cancelled: mine.filter(x => myTabMatch(x, 'cancelled')).length
        }) : ''}
      ${mine.length
        ? (shown.length
            ? shown.map(myBookingCard).join('')
            : '<div class="empty-state"><div class="big">🗂️</div><p>' + t('tabEmpty') + '</p></div>')
        : '<div class="empty-state"><div class="big">🎫</div><p>' + t('myNone') + '</p></div>'}
      ${trackCard}`;
    /* Sign out ends the SERVER session too (6 Sep 2026): a shared phone must
       not keep booking against the previous passenger's number after the
       app has "forgotten" them locally. Same door as the Quick Ticket card's
       "Not you?". */
    if ($('#myLogoutBtn')) $('#myLogoutBtn').onclick = () => { setUser(null); shgApi.post('/otp.php', { action: 'logout' }).catch(() => {}); renderMyBookings(); };
    box.querySelectorAll('[data-mytab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        MY_TAB = btn.getAttribute('data-mytab');
        renderMyBookings();
      });
    });
  }
  const tb = $('#trackBtn');
  if (tb) tb.onclick = async () => {
    const raw = ($('#trackId').value || '').trim().toUpperCase();
    if (!raw) return;
    const pnr = raw.indexOf('SHG-') === 0 ? raw : 'SHG-' + raw;
    const phone = digits(($('#trackPhone') && $('#trackPhone').value) || '');
    const msg = $('#trackMsg');
    if (msg) msg.textContent = '';

    // Already in this browser — nothing to fetch, just open it.
    if (DB.bookings.some(b => b && b.id === pnr)) { location.hash = '#/ticket/' + pnr; return; }

    if (!phone) { if (msg) msg.textContent = t('trkNeedPhone'); return; }

    const label = tb.textContent;
    tb.disabled = true; tb.textContent = t('trkBusy');
    try {
      await recoverBooking(pnr, phone);
      location.hash = '#/ticket/' + pnr;
    } catch (e) {
      if (msg) msg.textContent = (e && e.message) || t('trkNotFound');
    } finally {
      tb.disabled = false; tb.textContent = label;
    }
  };
  box.onclick = (e) => {
    const pdf = e.target.closest('[data-mypdf]');
    if (pdf) { const b = DB.bookings.find(x => x.id === pdf.getAttribute('data-mypdf')); if (b) downloadTicketPDF(b); return; }
    const wa = e.target.closest('[data-mywa]');
    if (wa) { waShare(wa.getAttribute('data-mywa')); return; }
    const cx = e.target.closest('[data-mycancel]');
    if (cx) { openCancelModal(cx.getAttribute('data-mycancel')); return; }
    const rb = e.target.closest('[data-myrebook]');
    if (rb) { rebookFrom(rb.getAttribute('data-myrebook')); return; }
  };
}

/* ================================================================
   [JS] 10c. WAITLIST — join from sold-out coaches; admin promotes
   the next person manually when a cancellation frees seats.
================================================================ */
function openWaitlistModal(routeId, date) {
  const r = routeById(routeId); if (!r) return;
  openModal(`
    <h3 class="m-title">📋 ${t('wlTitle')}</h3>
    <p class="m-sub">${esc(r.from + ' → ' + r.to)} · ${fmtDate(date)}</p>
    <p style="font-size:13px;color:var(--muted);margin-bottom:14px">${t('wlP')}</p>
    <div class="field"><label>${t('lblName')}</label><input id="wlName"><div class="err">${t('errName')}</div></div>
    <div class="form-2col">
      <div class="field"><label>${t('lblPhone')}</label><input id="wlPhone" inputmode="numeric" maxlength="10"><div class="err">${t('errPhone')}</div></div>
      <div class="field"><label>${t('wlSeatsN')}</label><select id="wlSeats">${[1,2,3,4,5,6].map(n => '<option>' + n + '</option>').join('')}</select></div>
    </div>
    <div class="m-actions"><button class="btn btn-orange" type="button" id="wlJoinBtn" style="width:100%">${t('wlJoin')}</button></div>`);
  $('#wlJoinBtn').onclick = () => {
    const name = $('#wlName').value.trim(), phone = digits($('#wlPhone').value);
    const nOk = name.length >= 2, pOk = /^\d{10}$/.test(phone);
    $('#wlName').closest('.field').classList.toggle('invalid', !nOk);
    $('#wlPhone').closest('.field').classList.toggle('invalid', !pOk);
    if (!nOk || !pOk) return;
    DB.waitlist.unshift({
      id: 'W' + Date.now(), routeId: routeId, date: date,
      name: name, phone: phone, seats: parseInt($('#wlSeats').value, 10) || 1,
      at: Date.now(), status: 'waiting'
    });
    persist('waitlist');
    closeModal();
    toast(t('wlOk'));
  };
}
/* A cancellation frees seats → flag the next waiting person for admin. */
function promoteWaitlist(routeId, date) {
  const w = (DB.waitlist || []).filter(x => x.status === 'waiting' && x.routeId === routeId && x.date === date)
    .sort((a, b) => a.at - b.at)[0];
  if (!w) return;
  w.status = 'notify';
  persist('waitlist');
}
/* Modal overlay close (backdrop / ✕) */
document.addEventListener('click', (e) => {
  if (e.target.id === 'shgModal' || e.target.closest('.m-close')) closeModal();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
