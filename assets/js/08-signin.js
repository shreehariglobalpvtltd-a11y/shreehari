
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
  /* 7 Oct 2026: the profile photo (when saved) instead of the 👤. */
  const ava = USER && USER.photoUrl ? '<img class="nav-ava" src="' + esc(USER.photoUrl) + '" alt="" width="22" height="22"> ' : '👤 ';
  const label = USER
    ? (ava + (first ? esc(first.length > 14 ? first.slice(0, 13) + '…' : first) : (USER.phone.slice(0, 2) + '•••' + USER.phone.slice(7))))
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
   CUSTOMER LOGIN = PHOTO + NAME + ADDRESS + MOBILE, code on WhatsApp
   (owner, 7 Oct 2026: "name, address, number liyera OTP WhatsApp throw
   AiSensy le gardela. Photo bhaye jhan ramro. Tyo data ticket ma use hune").

   Step 1 collects the details, api/otp.php `request` sends the code through
   AiSensy, step 2 checks it (`verify` saves the address, then the photo
   goes to api/profile.php). When WhatsApp refuses at once (empty wallet,
   not a WhatsApp number) or the code does not come, "continue without
   code" signs in the old name + mobile way (`quick`) — the traveller can
   book either way, but the saved address / photo stay locked until the
   number is proven.
   ------------------------------------------------------------------- */
/* Gender / current location / ID document / email — the extra profile
   boxes (owner, 7 Oct 2026: "mahila, afno current location, document
   number save garna milos, optional email ID"). One block for the sign-in
   sheet and the profile editor; `px` keeps their ids apart. */
const LG_ID_TYPES = ['Passport', 'Voter ID', 'Aadhaar Card', 'Nepali Citizenship Card', 'Driving Licence'];
function lgExtraFieldsHTML(u, px, open) {
  u = u || {};
  const g = u.gender || '';
  const gBtn = (v, label) => '<button type="button" class="lg-g' + (g === v ? ' on' : '') + '" data-g="' + v + '" aria-pressed="' + (g === v) + '">' + label + '</button>';
  return `
    <div class="field"><label id="${px}GenderLbl">${t('lgGender')} <small>(${t('lgOpt')})</small></label>
      <div class="lg-gender" id="${px}Gender" role="group" aria-labelledby="${px}GenderLbl" data-val="${esc(g)}">${gBtn('Male', '👨 ' + t('gM'))}${gBtn('Female', '👩 ' + t('gF'))}${gBtn('Other', t('gO'))}</div></div>
    <div class="field"><label for="${px}Loc">${t('lgLoc')} <small>(${t('lgOpt')})</small></label>
      <div class="lg-loc"><input id="${px}Loc" maxlength="255" placeholder="${esc(t('lgLocPh'))}" value="${esc(u.location || '')}">
        <button type="button" class="btn btn-ghost btn-sm" id="${px}LocBtn">📍 ${t('lgLocBtn')}</button></div></div>
    <details class="lg-more"${open ? ' open' : ''}>
      <summary>🪪 ${t('lgMoreId')}</summary>
      <div class="form-2col">
        <div class="field"><label for="${px}IdType">${t('lgIdType')}</label><select id="${px}IdType">${LG_ID_TYPES.map(v => '<option' + (u.idType === v ? ' selected' : '') + '>' + v + '</option>').join('')}</select></div>
        <div class="field"><label for="${px}IdNum">${t('lgIdNum')}</label><input id="${px}IdNum" maxlength="60" autocapitalize="characters" placeholder="${esc(t('lgIdNumPh'))}" value="${esc(u.idNumber || '')}"></div>
      </div>
      <div class="field"><label for="${px}Email">${t('lgEmail')} <small>(${t('lgOpt')})</small></label><input id="${px}Email" type="email" inputmode="email" autocomplete="email" maxlength="191" placeholder="you@example.com" value="${esc(u.email || '')}"><div class="err">${t('lgEmailBad')}</div></div>
    </details>`;
}
function lgBindExtras(px) {
  const box = $('#' + px + 'Gender');
  if (box) box.querySelectorAll('[data-g]').forEach(b => b.onclick = () => {
    const v = box.dataset.val === b.dataset.g ? '' : b.dataset.g;   // tap again = unselect
    box.dataset.val = v;
    box.querySelectorAll('[data-g]').forEach(x => { const on = x.dataset.g === v; x.classList.toggle('on', on); x.setAttribute('aria-pressed', String(on)); });
  });
  const lb = $('#' + px + 'LocBtn'), li = $('#' + px + 'Loc');
  if (lb && li) lb.onclick = () => {
    if (!navigator.geolocation) { toast(t('lgLocFail')); return; }
    const label = lb.innerHTML; lb.disabled = true; lb.textContent = t('lgLocBusy');
    navigator.geolocation.getCurrentPosition(async (pos) => {
      const la = pos.coords.latitude.toFixed(5), lo = pos.coords.longitude.toFixed(5);
      let name = '';
      try {
        const r = await fetch('/api/nav-search.php?city=' + la + ',' + lo, { credentials: 'same-origin' });
        const j = await r.json(); name = (j && j.name) || '';
      } catch (e) {}
      li.value = name ? name + ' (' + la + ', ' + lo + ')' : la + ', ' + lo;
      lb.disabled = false; lb.innerHTML = label;
    }, () => { lb.disabled = false; lb.innerHTML = label; toast(t('lgLocFail')); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  };
}
/* Read the block; null when the email is mistyped (the box is marked). */
function lgReadExtras(px) {
  const v = id => { const el = $('#' + px + id); return el ? el.value.trim() : ''; };
  const em = v('Email');
  const emEl = $('#' + px + 'Email');
  const emOk = !em || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em);
  if (emEl) emEl.closest('.field').classList.toggle('invalid', !emOk);
  if (!emOk) { const d = emEl.closest('details'); if (d) d.open = true; emEl.focus(); return null; }
  const num = v('IdNum');
  return { gender: (($('#' + px + 'Gender') || {}).dataset || {}).val || '', location: v('Loc'),
           idType: num ? v('IdType') : '', idNumber: num, email: em };
}

/* The account's own details onto the open checkout — only into empty boxes
   unless `force` (the "For me" tap). */
function shgFillMine(force) {
  if (!USER || (typeof isCounterSale === 'function' && isCounterSale())) return;
  const set = (el, val, ev) => { if (el && val && (force || !String(el.value || '').trim())) { el.value = val; el.dispatchEvent(new Event(ev || 'input', { bubbles: true })); } };
  const lead = $('#paxRows .grp-lead') || $('#paxRows .pax-row');
  if (lead) set($('.pxGender', lead), USER.gender, 'change');
  set($('#cEmail'), USER.email);
  if (USER.idNumber) {
    const it = $('#idType');
    if (it && (force || !(($('#idNum') || {}).value || '').trim()) && USER.idType) it.value = USER.idType;
    set($('#idNum'), USER.idNumber);
  }
}

let _lgPhoto = null;   // the File picked in step 1, uploaded after the code is right

function lgAvatarHTML(u, size) {
  const px = size || 40;
  const nm = (u && u.name) ? String(u.name).trim() : '';
  const ini = nm ? nm.split(/\s+/).slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase() : '👤';
  return u && u.photoUrl
    ? '<img class="lg-ava" src="' + esc(u.photoUrl) + '" alt="" width="' + px + '" height="' + px + '" style="width:' + px + 'px;height:' + px + 'px">'
    : '<span class="lg-ava lg-ava-i" style="width:' + px + 'px;height:' + px + 'px;font-size:' + Math.round(px * 0.38) + 'px">' + esc(ini) + '</span>';
}

function openLoginModal(opts) {
  opts = opts || {};
  const savedU = opts.u ? Object.assign({}, getUser() || {}, opts.u) : getUser();
  let savedName = (savedU && savedU.name) || '';
  if (!savedName) { try { savedName = (JSON.parse(localStorage.getItem('shg_cust_contact') || 'null') || {}).name || ''; } catch (e) {} }
  const savedPhone = (savedU && savedU.phone) || '';
  const savedAddr = (savedU && savedU.address) || '';
  _lgPhoto = null;
  openModal(`
    <h3 class="m-title">👤 ${t('lgTitle')}</h3>
    <p class="m-sub">${t('lgP')}</p>
    <div class="lg-photo-row">
      <label class="lg-photo" for="lgPhoto" title="${esc(t('lgPhoto'))}">
        <span id="lgPhotoPrev">${savedU && savedU.photoUrl ? lgAvatarHTML(savedU, 72) : '<span class="lg-photo-plus">📷</span>'}</span>
        <input id="lgPhoto" type="file" accept="image/jpeg,image/png,image/webp" capture="user" hidden>
      </label>
      <div class="lg-photo-txt"><b>${t('lgPhoto')}</b><small>${t('lgPhotoHint')}</small></div>
    </div>
    <div class="field"><label for="qlName">${t('lblName')}</label><input id="qlName" autocomplete="name" autocapitalize="words" maxlength="120" value="${esc(savedName)}"><div class="err">${t('errName')}</div></div>
    <div class="field"><label for="lgAddr">${t('lgAddr')}</label><input id="lgAddr" autocomplete="street-address" maxlength="255" placeholder="${esc(t('lgAddrPh'))}" value="${esc(savedAddr)}"><div class="err">${t('lgAddrErr')}</div></div>
    <div class="field"><label for="otpPhone">${t('lblPhone')}</label>
      <div style="display:flex;gap:8px;align-items:stretch">
        <select id="otpCC" aria-label="Country code" style="flex:0 0 116px;padding:0 10px;border:1.5px solid var(--line);border-radius:12px;background:var(--card);color:inherit;font:inherit;font-weight:600">
          <option value="91">🇮🇳 +91</option>
          <option value="977">🇳🇵 +977</option>
        </select>
        <input id="otpPhone" inputmode="numeric" maxlength="10" placeholder="98XXXXXXXX" autocomplete="tel-national" value="${esc(savedPhone)}" style="flex:1;min-width:0">
      </div>
      <div class="err">${t('errPhone')}</div></div>
    ${lgExtraFieldsHTML(savedU, 'lg', false)}
    <div class="m-actions"><button class="btn btn-orange" type="button" id="otpSendBtn" style="width:100%">🟢 ${t('lgSendOtp')}</button></div>
    <div class="lg-links lg-guest-row"><button type="button" class="lg-link" id="lgGuest">${t('lgGuest')}</button></div>
    <p class="lg-note">🔒 ${t('lgNote')}</p>`);
  /* A visible way on without an account (D4-15): booking never needs one. */
  const gl = $('#lgGuest'); if (gl) gl.onclick = () => closeModal();
  lgBindExtras('lg');
  const savedCC = (savedU && savedU.country) || '';
  if (savedCC === 'NP' || savedCC === 'IN') { const ccEl = $('#otpCC'); if (ccEl) ccEl.value = savedCC === 'NP' ? '977' : '91'; }
  (savedName ? (savedAddr ? $('#otpPhone') : $('#lgAddr')) : $('#qlName')).focus();
  $('#otpSendBtn').onclick = sendOtp;
  $('#lgPhoto').addEventListener('change', function () {
    const f = this.files && this.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type) || f.size > 6 * 1024 * 1024) { toast(t('lgPhotoBad')); this.value = ''; return; }
    _lgPhoto = f;
    const url = URL.createObjectURL(f);
    $('#lgPhotoPrev').innerHTML = '<img class="lg-ava" src="' + url + '" alt="" style="width:72px;height:72px">';
  });
  $('#qlName').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#lgAddr').focus(); } });
  $('#lgAddr').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#otpPhone').focus(); } });
  $('#otpPhone').addEventListener('keydown', (e) => { if (e.key === 'Enter') sendOtp(); });
}

/* After a sign-in: keep the account object in one shape everywhere. */
function lgSetUserFrom(su, p, extra) {
  su = su || {};
  const keep = (USER && USER.phone === (su.phone || p)) ? USER : {};
  setUser(Object.assign({}, keep, {
    phone: su.phone || p, name: su.name || (extra && extra.name) || keep.name || '', role: su.role || 'customer',
    points: su.points || 0, tier: su.tier || 'Silver', country: su.country || keep.country || '',
    address: su.otpVerified ? (su.address || '') : ((extra && extra.address) || keep.address || ''),
    gender: su.otpVerified ? (su.gender || '') : ((extra && extra.gender) || keep.gender || ''),
    location: su.otpVerified ? (su.location || '') : ((extra && extra.location) || keep.location || ''),
    email: su.otpVerified ? (su.email || '') : ((extra && extra.email) || keep.email || ''),
    idType: su.otpVerified ? (su.idType || '') : ((extra && extra.idType) || keep.idType || ''),
    idNumber: su.otpVerified ? (su.idNumber || '') : ((extra && extra.idNumber) || keep.idNumber || ''),
    photoUrl: su.otpVerified ? (su.photoUrl || '') : (keep.otpVerified ? '' : (keep.photoUrl || '')),
    otpVerified: !!su.otpVerified,
    /* 8 Oct 2026: the WhatsApp code proved the number (api/otp.php
       user.verified). Only this opens the full history, keyless tickets and
       cancel; a name + mobile session is signed in for booking only. */
    verified: !!(su.verified || su.otpVerified),
    at: Date.now(), srvAt: Date.now()
  }));
  ensureUser(su.phone || p);
  adoptAccountLang(su);
}

function lgAfterSignIn() {
  closeModal();
  toast(t('otpOkT'));
  try { if (typeof window.shgQuickMe === 'function') window.shgQuickMe(USER); } catch (e) {}
  /* a new session: ask the server again (a verified one may now see more) */
  _myBkAt = 0; MY_NEEDS_OTP = null;
  const h = location.hash || '';
  if (h === '#/my' || h.indexOf('#/my?') === 0) renderMyBookings();
  else if (h === '#/checkout') { applyUserToCheckout(); shgFillMine(false); if (typeof shgWhoBar === 'function') shgWhoBar(); }
  else if (h.indexOf('#/ticket/') === 0 && typeof renderStatus === 'function') {
    const id = decodeURIComponent(h.split('/')[2] || '');
    renderStatus(id);
    if (typeof syncBookings === 'function') syncBookings([id]).then(ch => { if (ch && location.hash === h) renderStatus(id); });
  }
}

/* Step 1 → ask the server to send the code on WhatsApp. */
async function sendOtp() {
  const p = digits($('#otpPhone').value);
  const cc = ($('#otpCC') && $('#otpCC').value) || '91';
  const country = cc === '977' ? 'NP' : 'IN';
  const f = $('#otpPhone').closest('.field');
  const nameEl = $('#qlName'), addrEl = $('#lgAddr');
  const name = nameEl ? nameEl.value.trim() : '';
  const address = addrEl ? addrEl.value.trim() : '';
  let bad = null;
  if (nameEl) { const ok = name.length >= 2; nameEl.closest('.field').classList.toggle('invalid', !ok); if (!ok) bad = bad || nameEl; }
  if (addrEl) { const ok = address.length >= 3; addrEl.closest('.field').classList.toggle('invalid', !ok); if (!ok) bad = bad || addrEl; }
  /* The same mobile shape as the checkout (D4-15): India 6-9…, Nepal 9[6-9]… */
  const pOk = (typeof phoneShapeOk === 'function') ? phoneShapeOk(p, cc) : /^\d{10}$/.test(p);
  f.classList.toggle('invalid', !pOk); if (!pOk) bad = bad || $('#otpPhone');
  if (bad) { bad.focus(); return; }
  const extras = lgReadExtras('lg');
  if (!extras) return;

  const sendBtn = $('#otpSendBtn');
  const label = sendBtn ? sendBtn.innerHTML : '';
  if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = t('lgSending'); }
  let result;
  try {
    result = await shgApi.post('/otp.php', { action: 'request', phone: p, name: name, purpose: 'login', country: country });
  } catch (e) {
    if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = label; }
    toast((e && e.message) || t('errOtpSend'));
    return;
  }
  lgOtpStep({ p: p, cc: cc, country: country, name: name, address: address, extras: extras }, result || {});
}

/* Step 2 → the code from WhatsApp. */
function lgOtpStep(s, result) {
  const demoNote = result.debugCode ? tf('otpDemo', { c: result.debugCode }) : '';
  /* "Continue without code" only when WhatsApp did NOT take the code (sent:
     false — refused, or the global send ceiling) AND the office allows it
     (canSkip). 8 Oct 2026 (D4-M4): no more 40-second timer that offered the
     skip while a delivered code was on its way. */
  const failed = result.sent === false;
  openModal(`
    <h3 class="m-title">🔐 ${t('lgOtpTitle')}</h3>
    <p class="m-sub">${failed ? t('lgOtpFail') : tf('lgOtpSent', { p: '+' + s.cc + ' ' + s.p })}</p>
    ${demoNote ? '<div class="verify-note" style="margin-bottom:14px">🧪 <span>' + demoNote + '</span></div>' : ''}
    <div class="field"><label for="otpCode">${t('lblOtpCode')}</label><input id="otpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="text-align:center;font-family:var(--f-code);font-size:22px;letter-spacing:.3em"><div class="err">${t('otpBad')}</div></div>
    <div class="m-actions"><button class="btn btn-orange" type="button" id="otpVerifyBtn" style="width:100%">${t('otpVerify')}</button></div>
    <div class="lg-links">
      <button type="button" class="lg-link" id="lgResend" disabled>${t('lgResend')}</button>
      <button type="button" class="lg-link" id="lgBack">✏️ ${t('lgChange')}</button>
    </div>
    <div class="lg-skip" id="lgSkipBox" ${failed ? '' : 'hidden'}>
      <small>${t('lgSkipP')}</small>
      <button type="button" class="btn btn-ghost btn-sm" id="lgSkip" style="width:100%">${t('lgSkip')}</button>
    </div>`);
  const code = $('#otpCode');
  code.focus();
  if (!result.canSkip) { const sb = $('#lgSkipBox'); if (sb) sb.remove(); }

  /* Resend countdown (the resend button says why it waits: "Resend in 23 s"). */
  /* 10 Oct 2026 (perf): counted from a deadline, not tick by tick, so a
     hidden tab can skip its redraws and still show the right number (and
     a throttled background timer no longer makes the wait longer). */
  let until = Date.now() + Math.max(30, Number(result.resendIn) || 60) * 1000;
  const rs = $('#lgResend');
  const tick = setInterval(() => {
    if (!document.body.contains(rs)) { clearInterval(tick); return; }
    if (document.hidden) return;
    const left = Math.ceil((until - Date.now()) / 1000);
    if (left > 0) { rs.disabled = true; rs.textContent = tf('lgResendIn', { s: left }); }
    else { rs.disabled = false; rs.textContent = t('lgResend'); }
  }, 1000);
  rs.textContent = tf('lgResendIn', { s: Math.ceil((until - Date.now()) / 1000) });
  rs.onclick = async () => {
    rs.disabled = true;
    try {
      const r = await shgApi.post('/otp.php', { action: 'request', phone: s.p, name: s.name, purpose: 'login', country: s.country });
      until = Date.now() + Math.max(30, Number(r && r.resendIn) || 60) * 1000;
      toast(r && r.sent === false ? t('lgOtpFail') : t('lgResent'));
      if (r && r.sent === false && r.canSkip) { const sb = $('#lgSkipBox'); if (sb) sb.hidden = false; }
    } catch (e) { rs.disabled = false; toast((e && e.message) || t('errOtpSend')); }
  };
  $('#lgBack').onclick = () => { clearInterval(tick); openLoginModal({ u: Object.assign({ name: s.name, address: s.address, phone: s.p, country: s.country }, s.extras) }); const c = $('#otpCC'); if (c) c.value = s.cc; };

  const skip = $('#lgSkip');
  if (skip) skip.onclick = async () => {
    skip.disabled = true;
    try {
      const q = await shgApi.post('/otp.php', { action: 'quick', phone: s.p, name: s.name, country: s.country });
      /* q.signedIn = may book; q.verified is false here (no code) */
      if (!q || !q.signedIn) throw new Error(t('errOtpSend'));
      clearInterval(tick);
      lgSetUserFrom(q && q.user, s.p, Object.assign({ name: s.name, address: s.address }, s.extras));
      if (_lgPhoto) toast(t('lgPhotoLater'));
      lgAfterSignIn();
    } catch (e) { skip.disabled = false; toast((e && e.message) || t('errOtpSend')); }
  };

  const verify = async () => {
    const c = code.value.trim();
    if (!/^\d{4,6}$/.test(c)) { code.closest('.field').classList.add('invalid'); return; }
    const vbtn = $('#otpVerifyBtn');
    if (vbtn) { vbtn.disabled = true; vbtn.textContent = '…'; }
    try {
      const v = await shgApi.post('/otp.php', Object.assign({ action: 'verify', phone: s.p, code: c, purpose: 'login', country: s.country, name: s.name, address: s.address }, s.extras));
      clearInterval(tick);
      let su = (v && v.user) || { phone: s.p };
      if (_lgPhoto) {
        try {
          const fd = new FormData();
          fd.append('action', 'photo');
          fd.append('photo', _lgPhoto);
          const pr = await shgApi.postForm('/profile.php', fd);
          if (pr && pr.profile) su = Object.assign({}, su, pr.profile);
        } catch (e) { toast((e && e.message) || t('lgPhotoBad')); }
        _lgPhoto = null;
      }
      lgSetUserFrom(su, s.p, Object.assign({ name: s.name, address: s.address }, s.extras));
      lgAfterSignIn();
    } catch (e) {
      if (vbtn) { vbtn.disabled = false; vbtn.textContent = t('otpVerify'); }
      code.closest('.field').classList.add('invalid');
      toast((e && e.message) || t('otpBad'));
    }
  };
  $('#otpVerifyBtn').onclick = verify;
  code.addEventListener('input', () => { code.closest('.field').classList.remove('invalid'); if (/^\d{6}$/.test(code.value.trim())) verify(); });
  code.addEventListener('keydown', (e) => { if (e.key === 'Enter') verify(); });
}

/* ---------------------------------------------------------------------
   MY PROFILE (My Bookings page) — photo, name, address, number; edit.
   ------------------------------------------------------------------- */
function profileCardHTML() {
  if (!USER) return '';
  const cc = USER.country === 'NP' ? '977' : '91';
  return `<div class="c-card lg-prof">
    <div class="lg-prof-top">
      ${lgAvatarHTML(USER, 64)}
      <div class="lg-prof-id">
        <b>${esc(USER.name || t('lgNoName'))}</b>
        <small>📱 +${cc} ${esc(USER.phone)} ${USER.otpVerified ? '<span class="badge ok">✓ ' + t('lgVerified') + '</span>' : ''}</small>
        ${USER.gender ? '<small>' + (USER.gender === 'Female' ? '👩 ' + t('gF') : USER.gender === 'Male' ? '👨 ' + t('gM') : t('gO')) + '</small>' : ''}
        ${USER.address ? '<small>🏠 ' + esc(USER.address) + '</small>' : ''}
        ${USER.location ? '<small>📍 ' + esc(USER.location) + '</small>' : ''}
        ${USER.idNumber ? '<small>🪪 ' + esc(USER.idType || '') + ' · ' + esc(String(USER.idNumber).replace(/.(?=.{4})/g, '•')) + '</small>' : ''}
        ${USER.email ? '<small>✉️ ' + esc(USER.email) + '</small>' : ''}
      </div>
    </div>
    <div class="lg-prof-actions">
      ${USER.otpVerified
        ? '<button class="btn btn-ghost btn-sm" type="button" id="lgEditBtn">✏️ ' + t('lgEdit') + '</button>'
        : '<button class="btn btn-orange btn-sm" type="button" id="lgVerifyBtn">🟢 ' + t('lgVerifyNow') + '</button>'}
      <button class="btn btn-ghost btn-sm" type="button" id="myLogoutBtn">${t('myLogout')}</button>
    </div>
  </div>`;
}

function openProfileEdit() {
  if (!USER) return;
  _lgPhoto = null;
  openModal(`
    <h3 class="m-title">✏️ ${t('lgEdit')}</h3>
    <p class="m-sub">${t('lgEditP')}</p>
    <div class="lg-photo-row">
      <label class="lg-photo" for="lgPhoto"><span id="lgPhotoPrev">${USER.photoUrl ? lgAvatarHTML(USER, 72) : '<span class="lg-photo-plus">📷</span>'}</span>
        <input id="lgPhoto" type="file" accept="image/jpeg,image/png,image/webp" hidden></label>
      <div class="lg-photo-txt"><b>${t('lgPhoto')}</b><small>${t('lgPhotoHint')}</small>
        ${USER.photoUrl ? '<button type="button" class="lg-link" id="lgPhotoRm">🗑️ ' + t('lgPhotoRm') + '</button>' : ''}</div>
    </div>
    <div class="field"><label for="peName">${t('lblName')}</label><input id="peName" maxlength="120" autocapitalize="words" value="${esc(USER.name || '')}"><div class="err">${t('errName')}</div></div>
    <div class="field"><label for="peAddr">${t('lgAddr')}</label><input id="peAddr" maxlength="255" placeholder="${esc(t('lgAddrPh'))}" value="${esc(USER.address || '')}"></div>
    ${lgExtraFieldsHTML(USER, 'pe', true)}
    <div class="m-actions"><button class="btn btn-orange" type="button" id="peSave" style="width:100%">${t('lgSave')}</button></div>`);
  lgBindExtras('pe');
  $('#lgPhoto').addEventListener('change', function () {
    const f = this.files && this.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png|webp)$/.test(f.type) || f.size > 6 * 1024 * 1024) { toast(t('lgPhotoBad')); this.value = ''; return; }
    _lgPhoto = f;
    $('#lgPhotoPrev').innerHTML = '<img class="lg-ava" src="' + URL.createObjectURL(f) + '" alt="" style="width:72px;height:72px">';
  });
  const rm = $('#lgPhotoRm');
  if (rm) rm.onclick = async () => {
    try { const r = await shgApi.post('/profile.php', { action: 'photo_remove' }); lgSetUserFrom(r.profile, USER.phone); openProfileEdit(); }
    catch (e) { toast((e && e.message) || t('apiErr')); }
  };
  $('#peSave').onclick = async () => {
    const nm = $('#peName').value.trim();
    if (nm.length < 2) { $('#peName').closest('.field').classList.add('invalid'); return; }
    const ex = lgReadExtras('pe');
    if (!ex) return;
    const btn = $('#peSave'); btn.disabled = true; btn.textContent = '…';
    try {
      let r = await shgApi.post('/profile.php', Object.assign({ action: 'save', name: nm, address: $('#peAddr').value.trim() }, ex));
      if (_lgPhoto) {
        const fd = new FormData(); fd.append('action', 'photo'); fd.append('photo', _lgPhoto);
        r = await shgApi.postForm('/profile.php', fd);
        _lgPhoto = null;
      }
      lgSetUserFrom(r.profile, USER.phone);
      closeModal(); toast(t('lgSaved'));
      if (isMyHash()) renderMyBookings();
    } catch (e) {
      btn.disabled = false; btn.textContent = t('lgSave');
      if (e && e.status === 403) { setUser(Object.assign({}, USER, { otpVerified: false })); closeModal(); openLoginModal(); }
      toast((e && e.message) || t('apiErr'));
    }
  };
}

/* ---------------------------------------------------------------------
   CHECKOUT — "Who is this ticket for?" (owner: "arko ko lagi ticket katna
   milne"). Me = the saved name / number / country. Someone else = blank
   boxes (or a person booked for before); the booking stays on this
   account and the account's number gets a copy of the ticket (api/book.php
   forOther). Typing a different number flips it to "someone else" by itself.
   ------------------------------------------------------------------- */
let _whoOthers = null;
let _whoMode = '';   // 'other' once the traveller tapped "Someone else" (boxes may still be empty)
function shgForOther() {
  if (!USER || (typeof isCounterSale === 'function' && isCounterSale())) return false;
  const ph = $('#cPhone');
  const p = ph ? digits(ph.value) : '';
  return p ? p !== USER.phone : _whoMode === 'other';
}
function shgWhoBar() {
  const old = $('#whoBar');
  if (!USER || (typeof isCounterSale === 'function' && isCounterSale())) { if (old) old.remove(); return; }
  const card = $('#coStep1 .co-card');
  if (!card) return;
  const bar = old || document.createElement('div');
  bar.id = 'whoBar';
  bar.className = 'who-bar';
  const other = shgForOther();
  const cc = USER.country === 'NP' ? '977' : '91';
  bar.innerHTML = `
    <div class="who-t">🎫 ${t('whoT')}</div>
    <div class="who-opts" role="radiogroup">
      <button type="button" class="who-btn${other ? '' : ' on'}" data-who="me" role="radio" aria-checked="${!other}">
        ${lgAvatarHTML(USER, 34)}<span><b>${t('forMe')}</b><small>${esc((USER.name || '').split(/\s+/)[0] || ('+' + cc + ' ' + USER.phone))}</small></span></button>
      <button type="button" class="who-btn${other ? ' on' : ''}" data-who="other" role="radio" aria-checked="${other}">
        <span class="lg-ava lg-ava-i" style="width:34px;height:34px;font-size:16px">👥</span><span><b>${t('forOther')}</b><small>${t('forOtherS')}</small></span></button>
    </div>
    <div class="who-more" ${other ? '' : 'hidden'}>
      <div class="who-chips" id="whoChips"></div>
      <small class="who-hint">📲 ${tf('forOtherHint', { p: '+' + cc + ' ' + USER.phone })}</small>
    </div>
    ${!other && USER.address ? '<small class="who-hint">📍 ' + esc(USER.address) + '</small>' : ''}`;
  if (!old) { const h = card.querySelector('.hint'); (h || card.firstElementChild).insertAdjacentElement('afterend', bar); }
  bar.querySelectorAll('[data-who]').forEach(b => b.onclick = () => shgWhoPick(b.getAttribute('data-who')));
  if (other) shgWhoChips();
  const ph = $('#cPhone');
  if (ph && !ph.dataset.whoBound) {
    ph.dataset.whoBound = '1';
    ph.addEventListener('input', () => { const want = shgForOther(); const on = bar.querySelector('.who-btn.on'); if (on && (on.getAttribute('data-who') === 'other') !== want) shgWhoBar(); });
  }
}
function shgWhoPick(who) {
  const ph = $('#cPhone');
  const nm = $('#paxRows .grp-lead .pxName') || $('#paxRows .pxName');
  _whoMode = who;
  if (who === 'me') {
    if (ph) ph.value = USER.phone;
    const cc = $('#cCountry'); if (cc && (USER.country === 'NP' || USER.country === 'IN')) { cc.value = USER.country === 'NP' ? '977' : '91'; cc.dataset.trusted = '1'; }
    if (nm) { nm.value = USER.name || ''; nm.dispatchEvent(new Event('input', { bubbles: true })); }
    shgFillMine(true);
  } else {
    const lead = $('#paxRows .grp-lead') || $('#paxRows .pax-row');
    const clr = (el, mine, ev) => { if (el && mine && String(el.value || '').trim() === String(mine)) { el.value = ''; el.dispatchEvent(new Event(ev || 'input', { bubbles: true })); } };
    if (lead) clr($('.pxGender', lead), USER.gender, 'change');
    clr($('#cEmail'), USER.email);
    clr($('#idNum'), USER.idNumber);
    if (ph && digits(ph.value) === USER.phone) ph.value = '';
    if (nm && nm.value.trim() === (USER.name || '').trim()) { nm.value = ''; nm.dispatchEvent(new Event('input', { bubbles: true })); }
    shgWhoBar();
    if (nm) { try { nm.focus({ preventScroll: false }); } catch (e) {} }
    if (typeof coSyncContinue === 'function') coSyncContinue();
    return;
  }
  if (typeof checkWelcomeBack === 'function') checkWelcomeBack();
  if (typeof coSyncContinue === 'function') coSyncContinue();
  shgWhoBar();
}
async function shgWhoChips() {
  const box = $('#whoChips');
  if (!box) return;
  if (_whoOthers === null) {
    _whoOthers = [];
    try { const d = await shgApi.get('/profile.php'); _whoOthers = (d && d.others) || []; } catch (e) {}
  }
  if (!_whoOthers.length) { box.innerHTML = ''; return; }
  box.innerHTML = '<small>' + t('recentPax') + '</small>' + _whoOthers.map((o, i) =>
    '<button type="button" class="chip who-chip" data-oi="' + i + '">👤 ' + esc(o.name) + ' · ' + esc(String(o.phone).slice(-4).padStart(6, '•')) + '</button>').join('');
  box.querySelectorAll('[data-oi]').forEach(b => b.onclick = () => {
    const o = _whoOthers[+b.getAttribute('data-oi')];
    const ph = $('#cPhone'), nm = $('#paxRows .grp-lead .pxName') || $('#paxRows .pxName');
    if (ph) ph.value = o.phone;
    if (nm) { nm.value = o.name; nm.dispatchEvent(new Event('input', { bubbles: true })); }
    if (typeof checkWelcomeBack === 'function') checkWelcomeBack();
    if (typeof coSyncContinue === 'function') coSyncContinue();
  });
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
  /* 8 Oct 2026 (D4-03, owner: name + phone is never proof): a GUEST is never
     signed in by the number typed at checkout — a son booking on his
     mother's number, or one wrong digit, used to become "signed in" as that
     person and pull their whole history onto this phone. api/book.php binds
     the new PNR to this browser session instead. Only an existing sign-in
     is kept fresh below. */
  if (!USER) return false;
  /* 7 Oct 2026: a ticket for SOMEBODY ELSE (their number in the contact
     box) must not sign this browser in as them - the booking stays on the
     account and api/book.php (forOther) sends the account a copy. Keep the
     account's own session fresh instead. */
  if (USER && USER.phone && USER.phone !== p) {
    return /^\d{10}$/.test(USER.phone) ? ensureCustomerSession(USER.name || '', USER.phone, USER.country || '') : false;
  }
  const nm = String(name || '').trim();
  if (USER && USER.phone === p && USER.srvAt && (Date.now() - USER.srvAt) < 5 * 60 * 1000) return true;
  try {
    const q = await shgApi.post('/otp.php', { action: 'quick', phone: p, name: nm.length >= 2 ? nm : ((USER && USER.phone === p && USER.name) || ''), country: country || USER.country || '' });
    /* q.signedIn = the session may book; q.verified / q.user.verified = the
       WhatsApp code proved the number (8 Oct 2026 — a quick sign-in is
       signedIn but never verified). */
    if (q && q.signedIn) {
      lgSetUserFrom(q.user || {}, p, { name: nm });
      return true;
    }
  } catch (e) { /* guest checkout still works */ }
  return false;
}

/* One account, many tickets: pull every booking the server holds for this
   number and merge it into the local list (a phone that booked at the
   counter, on another device, or before a cleared cache sees them all). */
let _myBkAt = 0, _myBkBusy = false;
/* The server's answer to "may this session see every ticket on the number?"
   (api/my-bookings.php needsOtp). null = not asked yet this page. */
let MY_NEEDS_OTP = null;
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
      const ok = await ensureCustomerSession(USER.name, USER.phone, USER.country || '');
      if (!ok) {
        /* no server session and no way back without the code (the office
           may have closed the no-code door): say so on the page */
        const wasNeed = MY_NEEDS_OTP;
        MY_NEEDS_OTP = true; _myBkAt = Date.now();
        if (USER && USER.verified) setUser(Object.assign({}, USER, { verified: false, otpVerified: false }));
        return wasNeed !== true;
      }
      d = await shgApi.get(q);
    }
    _myBkAt = Date.now();
    let changed = false;
    /* 8 Oct 2026: an unverified (name + mobile) session gets ONLY the tickets
       this browser booked, plus needsOtp — a thinner list than this device
       may already hold. Merging only ever adds or refreshes, so a fuller
       local copy is never replaced by the thinner one; the page shows the
       "verify on WhatsApp" banner instead. */
    const wasNeed = MY_NEEDS_OTP;
    MY_NEEDS_OTP = !!d.needsOtp;
    if (wasNeed !== MY_NEEDS_OTP) changed = true;
    if (typeof d.verified === 'boolean' && USER && !!USER.verified !== d.verified) {
      setUser(Object.assign({}, USER, { verified: d.verified, otpVerified: d.verified }));
      changed = true;
    }
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
        paidAmount: b.paidAmount,
        dlKey: b.dlKey || cur.dlKey || '', verifyUrl: b.verifyUrl || cur.verifyUrl || null,
        delayMin: b.delayMin, delayNote: b.delayNote, tripStatus: b.tripStatus, arrived: b.arrived,
        live_status: b.live_status || cur.live_status || null,
        contact: Object.assign({}, cur.contact || {}, b.contact && b.contact.cc ? { cc: b.contact.cc } : {}),
        createdAt: cur.createdAt || b.createdAt });
      /* the live pill's minutes tick: compare without it, so it alone never redraws */
      const cmp = (o) => JSON.stringify(Object.assign({}, o, { live_status: o.live_status ? o.live_status.key : null }));
      if (cmp(next) !== cmp(cur)) changed = true;
      DB.bookings[at] = next;
    });
    if (changed || (d.bookings || []).length) persist('bookings');
    return changed;
  } catch (e) {
    return false;
  } finally {
    _myBkBusy = false;
  }
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
    + '.live-pill.departed{background:var(--bg,#f0f0f2);color:var(--muted,#666);border:1px solid var(--line,#dcdce2)}'
    /* 8 Oct 2026: the server's own colour wins (Departed is red since the
       pickup-time fix), and a trip the office cancelled is always red. */
    + '.live-pill.c-red,.live-pill.trip_cancelled{background:var(--danger-soft,#FBE3E3);color:var(--danger,#B42525);border:1px solid var(--danger,#B42525)}'
    + '.live-pill.c-orange{background:var(--warning-soft,#FFF4D6);color:var(--warning,#8A5A00);border:1px solid var(--warning,#8A5A00)}';
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
  const liveCls = live && live.key ? String(live.key).replace(/[^a-z_]/g, '') : '';
  const liveCol = live && live.color ? String(live.color).replace(/[^a-z]/g, '') : '';
  const livePill = (live && live.key && live.label)
    ? '<span class="live-pill ' + liveCls + (liveCol ? ' c-' + liveCol : '') + '" title="' + esc(live.description || '') + '">' + esc(live.label) + '</span>'
    : '';
  const tripCx = b.tripStatus === 'cancelled' || liveCls === 'trip_cancelled';
  /* ONE track button (D6-10): this passenger's own keyed tracker (trackUrl,
     k = track token) — the same door as the bottom bar's TRACK, never the
     anonymous map. Without a key yet, the Trip Companion. */
  const trackHref = b.trackUrl ? String(b.trackUrl) : '#/trip/' + b.id;
  return `<div class="mybk-card">
    <div class="mybk-head">
      <b style="font-family:var(--f-code)">${esc(b.id)}</b>
      ${b.ret ? '<span class="chip orange">⇄</span>' : ''}
      ${stBadge}
      ${livePill}
      ${(function () { try { return window.SHGBorder ? window.SHGBorder.badge(b) : ''; } catch (e) { return ''; } })()}
      <small>${fmtDate(b.date)}</small>
    </div>
    <div class="mybk-route">${esc(parseBP(b.boarding).name || r.from || '?')} <em>→</em> ${esc(parseBP(b.drop).name || r.to || '?')} · ${seatLabelJoin(b.seats, r.type, b.bookingType)} · <b>${inr(b.total)}</b>${b.agentCode ? ' · <span style="font-family:var(--f-code);font-size:12px;color:var(--orange-800,#7a3d00)">🏷️ ' + esc(b.agentCode) + '</span>' : ''}</div>
    <div class="mybk-actions">
      <a class="btn btn-blue btn-sm" href="#/ticket/${esc(b.id)}">🎫 ${t('st5')}</a>
      ${b.status === 'confirmed' ? '<button class="btn btn-ghost btn-sm" type="button" data-mypdf="' + esc(b.id) + '">' + t('btnPdf') + '</button>' : ''}
      ${b.status === 'confirmed' && !tripCx ? '<a class="btn btn-ghost btn-sm" href="' + esc(trackHref) + '">' + esc(t('myTrackOne')) + '</a>' : ''}
      ${b.status === 'confirmed' ? '<button class="btn btn-ghost btn-sm" type="button" data-mywa="' + esc(b.id) + '">🟢 WhatsApp</button>' : ''}
      <button class="btn btn-orange btn-sm" type="button" data-myrebook="${esc(b.id)}">${t('btnRebook')}</button>
      ${canCancel(b) ? '<button class="btn btn-danger-ghost btn-sm" type="button" data-mycancel="' + esc(b.id) + '">' + t('btnCancel') + '</button>' : ''}
    </div>
    ${(b.status === 'pending' || b.status === 'confirmed') && depTimestamp(b) > Date.now() && !tripCx ? distanceWidgetHTML(b) : ''}
  </div>`;
}

/* Sign-out on a shared phone (8 Oct 2026, D4-11): the next person must not
   find the previous traveller's tickets, saved passengers (with the ID
   number) or contact pre-filled. Removes every ticket that came from the
   server or belongs to the signed-out number, the remembered travellers, the
   saved contact, any half-typed checkout and the local loyalty record. A
   never-signed-in guest's own tickets on their own phone are untouched. */
function shgSignOutPurge() {
  try {
    const was = USER && USER.phone ? digits(USER.phone) : '';
    DB.bookings = (DB.bookings || []).filter(b => !(b && (b.fromServer || (was && digits(b.contact && b.contact.phone) === was))));
    persist('bookings');
    if (was && Array.isArray(DB.users)) { DB.users = DB.users.filter(u => !(u && digits(u.phone) === was)); persist('users'); }
  } catch (e) {}
  try { if (typeof PaxMemory !== 'undefined') PaxMemory.clear(); } catch (e) {}
  try { localStorage.removeItem('shg_cust_contact'); } catch (e) {}
  try { if (typeof CheckoutDraft !== 'undefined' && CheckoutDraft.clearAll) CheckoutDraft.clearAll(); } catch (e) {}
}
window.shgSignOutPurge = shgSignOutPurge;
/* '#/my' or '#/my?pnr=…' (an older keyless ticket link, D4-13) */
function isMyHash(h) { h = h === undefined ? (location.hash || '') : h; return h === '#/my' || h.indexOf('#/my?') === 0; }
let _myPrefilled = '';
function renderMyBookings() {
  const box = $('#myBody');
  /* One account, many tickets: ask the server for this number's bookings
     and redraw once if anything new arrived (throttled inside). */
  if (USER && typeof refreshMyBookingsFromServer === 'function') {
    refreshMyBookingsFromServer().then(ch => { if (ch && isMyHash()) renderMyBookings(); });
  }
  /* Visible labels above both boxes (D2-07): the placeholder vanishes the
     moment typing starts, so an elderly passenger forgot what the box was. */
  const trackCard = `
    <div class="c-card" style="margin-top:18px">
      <b style="font-family:var(--f-display);font-size:17px">🔎 ${t('myTrackT')}</b>
      <p style="font-size:13.5px;color:var(--muted);margin:6px 0 14px">${t('myTrackP')}</p>
      <div class="trk-fields" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="trk-f" style="flex:1;min-width:160px"><label class="trk-lbl" for="trackId">${esc(t('trkPnrLbl'))}</label>
        <input id="trackId" placeholder="SHG-2026-XXXXX" autocomplete="off" spellcheck="false" style="width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg,#FBFCFE);font-family:var(--f-code);text-transform:uppercase"></div>
        <div class="trk-f" style="flex:1;min-width:160px"><label class="trk-lbl" for="trackPhone">${esc(t('trkPhoneLbl'))}</label>
        <input id="trackPhone" inputmode="tel" autocomplete="tel" placeholder="${esc(t('trkPhone'))}" value="${esc(USER ? USER.phone : '')}" style="width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;background:var(--bg,#FBFCFE)"></div>
        <button class="btn btn-blue" type="button" id="trackBtn">${t('myTrackBtn')}</button>
      </div>
      <div id="trackMsg" role="status" style="font-size:12.5px;color:var(--bad);margin-top:8px"></div>
    </div>`;
  {
    const uPhone = USER ? USER.phone : '';
    const mine = uPhone
      ? DB.bookings.filter(b => digits(b.contact && b.contact.phone) === uPhone).sort((a, b2) => b2.createdAt - a.createdAt)
      : DB.bookings.slice().sort((a, b2) => b2.createdAt - a.createdAt);
    const shown = mine.filter(function (b) { return myTabMatch(b, MY_TAB); });
    const trips = uPhone ? tripsForPhone(uPhone) : DB.bookings.length;
    /* A name + mobile session sees only this browser's own tickets from the
       server: say so, and offer the WhatsApp code that unlocks the rest. */
    const needOtp = !!(USER && (MY_NEEDS_OTP === true || (MY_NEEDS_OTP === null && !USER.verified)));
    let loyaltyCard = '';
    if (uPhone) {
      /* points = the SERVER balance (USER.points from api/otp.php, 0 until
         the code proved the number — D4-04); the history list stays local */
      const u = Object.assign({}, ensureUser(uPhone), { loyaltyPoints: Math.max(0, Math.floor(Number(USER.points) || 0)) });
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
      ${uPhone ? profileCardHTML() : ''}
      <div class="my-hello">
        <div>
          <b>${uPhone ? t('myHello') + ' +' + ((USER && USER.country === 'NP') ? '977' : '91') + ' ' + esc(uPhone) : '🎫 All Bookings'}</b>
          ${trips ? '<small>⭐ ' + tf('myTripsN', { n: trips }) + '</small>' : ''}
        </div>
        ${uPhone ? '' : '<button class="btn btn-orange btn-sm" type="button" id="mySignInBtn">👤 ' + t('lgTitle') + '</button>'}
      </div>
      ${needOtp ? '<div class="my-otp-banner" role="status"><div><b>🔐 ' + esc(t('myNeedsOtp')) + '</b><small>' + esc(t('myNeedsOtpP')) + '</small></div>'
        + '<button class="btn btn-orange btn-sm" type="button" id="myVerifyBtn">' + esc(t('myVerifyBtn')) + '</button></div>' : ''}
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
    if ($('#myLogoutBtn')) $('#myLogoutBtn').onclick = () => { shgSignOutPurge(); setUser(null); _whoOthers = null; MY_NEEDS_OTP = null; shgApi.post('/otp.php', { action: 'logout' }).catch(() => {}); renderMyBookings(); };
    if ($('#lgEditBtn')) $('#lgEditBtn').onclick = openProfileEdit;
    if ($('#lgVerifyBtn')) $('#lgVerifyBtn').onclick = () => openLoginModal();
    if ($('#myVerifyBtn')) $('#myVerifyBtn').onclick = () => openLoginModal();
    if ($('#mySignInBtn')) $('#mySignInBtn').onclick = () => openLoginModal();
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
  /* An older keyless ticket link lands on '#/my?pnr=SHG-…' (download-ticket.php,
     D4-13): put the PNR in the box, say what is needed and focus the phone. */
  try {
    const h = location.hash || '';
    const qs = h.indexOf('#/my?') === 0 ? new URLSearchParams(h.split('?')[1] || '') : null;
    const pnr = qs ? String(qs.get('pnr') || '').toUpperCase() : '';
    if (pnr && /^SHG-[A-Z0-9-]{4,30}$/.test(pnr)) {
      /* the box is redrawn by every refresh: refill it each time, but take
         the focus only once per link */
      const ti = $('#trackId'), tp = $('#trackPhone'), tm = $('#trackMsg');
      if (ti && !ti.value) ti.value = pnr;
      if (tm && !tm.textContent) { tm.textContent = t('trkNeedPhone'); tm.style.color = 'var(--ink-2, inherit)'; }
      if (_myPrefilled !== pnr) {
        _myPrefilled = pnr;
        if (tp) { try { tp.focus({ preventScroll: true }); tp.scrollIntoView({ block: 'center' }); } catch (e) { try { tp.focus(); } catch (e2) {} } }
      }
    }
  } catch (e) {}
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

/* ---------------------------------------------------------------------
   FIRST OPEN = THE LOGIN SHEET (owner, 7 Oct 2026: "suru mai kholda first
   time login page open hunu paryo, tei name ra number bata ticket katna
   prefer garne"). A traveller who is not signed in sees the sign-in sheet
   as the app opens; once signed in, the saved name + number fill the Quick
   Ticket card, the seat page and the checkout. It can be closed (the ✕),
   and then it waits for the next visit — never again in the same tab.
   Never for staff / the counter desk, and never over a link that opens
   something specific (a ticket, tracking, payment).
   ------------------------------------------------------------------- */
(function shgFirstOpenLogin() {
  function maybe() {
    try {
      if (USER) return;
      const sw = (window.SHG_BOOT && window.SHG_BOOT.settings) || {};
      if (sw.btn_login_popup_on === false || sw.btn_login_popup_on === 0 || sw.btn_login_popup_on === '0') return;   // Settings -> Website buttons
      if (window.SHG_BOOT && (window.SHG_BOOT.staff || (window.SHG_BOOT.user && window.SHG_BOOT.user.phone))) return;
      if (typeof isCounterSale === 'function' && isCounterSale()) return;
      if (/[?&](counter|pnr|pay|ref|agent)=/i.test(location.search)) return;
      const h = location.hash || '';
      if (h && h !== '#' && h !== '#/' && h !== '#/home' && h !== '#/my') return;
      if (sessionStorage.getItem('shg:lgAsked')) return;
      sessionStorage.setItem('shg:lgAsked', '1');
      if (document.getElementById('shgModal') && document.getElementById('shgModal').classList.contains('open')) return;
      if (typeof openLoginModal === 'function') openLoginModal();
    } catch (e) {}
  }
  const go = () => setTimeout(maybe, 1200);   // after the splash / first paint
  if (document.readyState === 'complete') go(); else window.addEventListener('load', go, { once: true });
})();
