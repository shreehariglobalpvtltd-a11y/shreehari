
/* ================================================================
   [JS] 9. CHECKOUT — passenger form (live validation), fare
   breakdown, UPI / eSewa payment, payment proof, welcome-back.
================================================================ */
let PAY_METHOD = 'upi';

/* A selling staff session (index.php?counter=1, see 14-counter.js) sells to
   a DIFFERENT stranger every few minutes. The repeat-customer conveniences
   in this file — remembered contact, PaxMemory chips, "welcome back", the
   signed-in account's own name and phone — exist so one passenger never
   retypes their details; at a desk they carry the PREVIOUS passenger onto
   the next ticket (owner's report, 6 Sep 2026: "the app treats me like the
   passenger"). They are all off for staff; the customer app is unchanged. */
function isCounterSale() {
  return !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
}

/* The country the customer chose for their number ('NP'/'IN'), or '' when we
   are not sure. India and Nepal share 10-digit mobiles, so the server cannot
   read the country off the number — but it CAN fall back to the country on the
   account. So we send an explicit value ONLY when the picker is trusted (it was
   pre-set from a known account, or the customer touched it); otherwise '' lets
   the server use the account on file rather than a guessed default. */
function contactCountry() {
  var s = document.getElementById('cCountry');
  if (!s || s.dataset.trusted !== '1') return '';
  return s.value === '977' ? 'NP' : 'IN';
}

/* ── Voice input — adds a mic button to any text input (Web Speech API).
   Graceful degradation: the button never appears on unsupported browsers. ── */
function shgVoice(el) {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR || !el) return;
  var wrap = document.createElement('div');
  wrap.className = 'field-voice';
  el.parentNode.insertBefore(wrap, el);
  wrap.appendChild(el);
  var btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'mic-btn';
  btn.setAttribute('aria-label', t('micLbl'));
  btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';
  wrap.appendChild(btn);
  var active = null;
  btn.onclick = function() {
    if (active) { active.stop(); return; }
    var rec = new SR();
    rec.lang = 'hi-IN'; /* Hindi recogniser covers Nepali names too */
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    active = rec;
    btn.classList.add('listening');
    btn.setAttribute('aria-label', t('micListening'));
    try { rec.start(); } catch(e) { toast(t('micNA')); active = null; btn.classList.remove('listening'); return; }
    rec.onresult = function(e) {
      el.value = e.results[0][0].transcript;
      el.dispatchEvent(new Event('input', {bubbles: true}));
      el.focus();
    };
    var done = function() { active = null; btn.classList.remove('listening'); btn.setAttribute('aria-label', t('micLbl')); };
    rec.onerror = done;
    rec.onend = done;
  };
}

function liveField(el, testFn) {
  const f = el.closest('.field');
  const run = () => f.classList.toggle('invalid', !testFn());
  el.addEventListener('input', run);
  el.addEventListener('blur', run);
}
function wireLiveValidation() {
  $$('.pax-row').forEach(row => {
    const n = $('.pxName', row), a = $('.pxAge', row);
    liveField(n, () => n.value.trim() === '' || n.value.trim().length >= 2);
    /* age and ID are optional (lean checkout) — an empty field must stay
       neutral here, or it turns red while submit happily accepts it */
    liveField(a, () => { if (a.value.trim() === '') return true; const v = parseInt(a.value, 10); return v >= 1 && v <= 99; });
  });
  const ph = $('#cPhone'), em = $('#cEmail'), idn = $('#idNum');
  liveField(ph, () => /^\d{10}$/.test(digits(ph.value)));
  liveField(em, () => !em.value.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em.value.trim()));
  liveField(idn, () => idn.value.trim() === '' || idn.value.trim().length >= 4);
  ph.addEventListener('input', checkWelcomeBack);
  ph.addEventListener('input', autoFillFromPhone);
}
/* What goes on the ticket when the passenger left the name box empty.
   The account holder's own name is used for the first berth when we have
   it — that is a real name, and it is the one the border desk will match
   against the ID they are carrying. The rest are numbered. */
function autoPaxName(index) {
  if (index === 0 && !isCounterSale()) {
    const mine = (typeof USER !== 'undefined' && USER && (USER.name || '').trim()) || '';
    if (mine.length >= 2) return mine;
  }
  return t('paxAuto') + ' ' + (index + 1);
}

function checkWelcomeBack() {
  const box = $('#wbNote'); if (!box) return;
  if (isCounterSale()) { box.classList.add('hide'); return; }
  const p = digits($('#cPhone').value);
  if (p.length === 10) {
    const n = tripsForPhone(p);
    if (n >= CONFIG.booking.loyaltyTrips) {
      box.classList.remove('hide');
      $('#wbText').innerHTML = tf('wbText', { n: n + 1 });
      return;
    }
  }
  box.classList.add('hide');
}

/* Auto-fill passenger name from PaxMemory when a known phone is entered */
function autoFillFromPhone() {
  if (isCounterSale()) return;
  const rec = PaxMemory.read();
  if (!rec) return;
  const p = digits($('#cPhone').value);
  if (p.length !== 10 || p !== digits(rec.phone || '')) return;
  /* Only fill if the first passenger name is still empty */
  const rows = $$('#paxRows .pax-row');
  if (!rows.length) return;
  const firstName = $('.pxName', rows[0]);
  if (firstName && !firstName.value.trim() && rec.people && rec.people[0]) {
    fillPaxRow(rows[0], rec.people[0]);
  }
  /* Also fill contact fields */
  const setIfBlank = (sel, val) => { const el = $(sel); if (el && !el.value && val) el.value = val; };
  setIfBlank('#cEmail', rec.email);
  setIfBlank('#idNum', rec.idNum);
  const idT = $('#idType');
  if (idT && rec.idType && $$('option', idT).some(o => o.value === rec.idType)) idT.value = rec.idType;
}

/* ── §1 two-step checkout ─────────────────────────────────────────
   Step 1 = passenger + contact details, Step 2 = payment. Both feed the
   SAME submitBooking() payload — this is a UI/state split, not a new model.
   validateCheckoutDetails() is the single details validator, shared by the
   "Continue to Payment" gate and the final submit, so they never drift. */
function validateCheckoutDetails() {
  let ok = true;
  const passengers = $$('.pax-row').map((row, i) => {
    const nameF = $('.pxName', row), ageF = $('.pxAge', row);
    const name = nameF.value.trim(), ageRaw = ageF.value.trim(), age = parseInt(ageRaw, 10);
    /* Names are OPTIONAL. Typing four names is the slowest part of a group
       booking and the counter can add them later, so a blank box is
       accepted and auto-filled instead of blocking the sale. A name that IS
       typed still has to be a real one — a single stray letter is a slip,
       not an intention, and would print on the ticket. */
    /* 4 Sep 2026: the LEAD name is required — it is the sign-in name and the
       name that prints on the ticket. Extra seats in a group stay optional
       (they mirror the lead). */
    const nOk = i === 0 ? name.length >= 2 : (name === '' || name.length >= 2);
    /* lean checkout: age optional — blank ok; if given must be 1–99 */
    const aOk = ageRaw === '' || (age >= 1 && age <= 99);
    nameF.closest('.field').classList.toggle('invalid', !nOk);
    ageF.closest('.field').classList.toggle('invalid', !aOk);
    if (!nOk || !aOk) ok = false;
    return { seat: row.getAttribute('data-seat'), name: name || autoPaxName(i), age: ageRaw === '' ? 0 : (age || 0), gender: $('.pxGender', row).value };
  });
  /* Group booking (Task 6): one name + one gender for the whole party. The
     lead passenger's resolved values are applied to every seat, so the ticket
     reads "Name · N passengers" and the shared-cabin lock sees one gender. */
  /* Unless "Different names per seat" is on (Flow.paxIndividual, 17 Sep
     2026) — then every row is its own passenger and nothing is mirrored. */
  if (!Flow.paxIndividual && passengers.length > 1 && $('#paxRows .grp-extra')) {
    const gName = passengers[0].name, gGender = passengers[0].gender;
    passengers.forEach(p => { p.name = gName; p.gender = gGender; });
  }
  /* Name the failures (5 Sep 2026): "check the highlighted fields" told a
     hurried counter nothing — collect human labels so the toast can say
     WHICH field is wrong even when it sits above the fold. */
  const fails = [];
  $$('.pax-row').forEach((row, i) => {
    if (row.querySelector('.field.invalid .pxName')) fails.push('नाम / Name');
    if (row.querySelector('.field.invalid .pxAge')) fails.push('उमेर / Age');
  });
  const phone = $('#cPhone'), email = $('#cEmail'), idNum = $('#idNum');
  const phoneVal = digits(phone.value);
  /* Counter sale (5 Sep 2026): a walk-in may have NO phone (the server
     stores its placeholder for staff sales) and a Nepali passenger's
     number with +977 is 12-13 digits — both were failing the strict
     10-digit rule and blocking the desk with "check the highlighted
     fields". Customers online keep the strict Indian-mobile rule. */
  const ctrSell = !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
  const pOk = ctrSell ? (phoneVal === '' || /^\d{8,15}$/.test(phoneVal)) : /^\d{10}$/.test(phoneVal);
  const eOk = !email.value.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
  const iOk = idNum.value.trim() === '' || idNum.value.trim().length >= 4;
  phone.closest('.field').classList.toggle('invalid', !pOk);
  email.closest('.field').classList.toggle('invalid', !eOk);
  idNum.closest('.field').classList.toggle('invalid', !iOk);
  if (!pOk) fails.push('मोबाइल / Mobile');
  if (!eOk) fails.push('इमेल / Email');
  if (!iOk) fails.push('ID');
  if (!pOk || !eOk || !iOk) ok = false;

  let blocked = false;
  const blk = (DB.settings && DB.settings.blacklist) || [];
  if (pOk && blk.indexOf(phoneVal) >= 0) { phone.closest('.field').classList.add('invalid'); blocked = true; ok = false; }

  /* T&C (5 Sep 2026): the tick is the CUSTOMER's electronic signature.
     At a staff counter the desk paperwork covers it, so the requirement is
     dropped ENTIRELY for a selling session — not merely auto-ticked, which
     depended on decorate-timing and could resurrect as an invisible
     "highlighted field" (owner: the toast kept naming T&C). The box is also
     force-ticked + hidden by 14-counter, but this is the real gate. */
  const tncEl = $('#agreeTnc');
  const tnc = ctrSell ? true : (tncEl && tncEl.checked);
  const tncErrEl = $('#tncErr'); if (tncErrEl) tncErrEl.style.display = (ctrSell || tnc) ? 'none' : 'block';
  if (!tnc) { fails.push('नियम / T&C'); ok = false; }

  return { ok: ok, blocked: blocked, passengers: passengers, phone: phone, email: email, idNum: idNum, phoneVal: phoneVal, fails: fails };
}
/* The specific version of "fix the highlighted fields": name the fields. */
function coFixToast(dv) {
  const list = (dv && dv.fails && dv.fails.length) ? dv.fails.filter((v, i, a) => a.indexOf(v) === i).join(' · ') : '';
  toast(t('tFixFields') + (list ? '  →  ' + list : ''));
  coScrollInvalid();
}
/* Enable "Continue to Payment" only once the minimum required details are in
   (every seat named, a valid 10-digit phone, T&C accepted). Full validation
   still runs on click via validateCheckoutDetails(). */
function coSyncContinue() {
  const btn = $('#coContinueBtn'); if (!btn) return;
  let ready = true;
  const rows = $$('.pax-row');
  if (!rows.length) ready = false;
  rows.forEach((row, i) => {
    const n = $('.pxName', row), a = $('.pxAge', row);
    /* Lead name is required (it signs the traveller in and prints on the
       ticket); the rest may stay blank. One stray character is never fine. */
    if (n && i === 0 && n.value.trim().length < 2) ready = false;
    if (n && n.value.trim() !== '' && n.value.trim().length < 2) ready = false;
    /* age is optional, but a filled-in age must be in range — otherwise the
       button would look clickable and then be refused by the click gate */
    if (a && a.value.trim() !== '') {
      const av = parseInt(a.value, 10);
      if (!(av >= 1 && av <= 99)) ready = false;
    }
  });
  const ph = $('#cPhone');
  const phv = ph ? digits(ph.value) : '';
  // Counter sale: phone optional / international (mirrors validateCheckoutDetails).
  const ctrSell2 = !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
  if (ctrSell2 ? (phv !== '' && !/^\d{8,15}$/.test(phv)) : !/^\d{10}$/.test(phv)) ready = false;
  /* blacklisted numbers are refused on click, so don't offer an enabled button */
  if (ready && ((DB.settings && DB.settings.blacklist) || []).indexOf(phv) >= 0) ready = false;
  const em = $('#cEmail');
  if (em && em.value.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em.value.trim())) ready = false;
  const idn = $('#idNum');
  if (idn && idn.value.trim() !== '' && idn.value.trim().length < 4) ready = false;
  // Counter session: T&C is the desk's responsibility, not a gate (mirrors
  // validateCheckoutDetails — the two entry points must never disagree).
  if (!ctrSell2) { const tnc = $('#agreeTnc'); if (!tnc || !tnc.checked) ready = false; }
  btn.disabled = !ready;
}
/* Switch between step 1 (details) and step 2 (payment). Going forward runs the
   details validator; going back preserves everything — the panels are only
   hidden/shown, never re-rendered, so no entered data is lost. */
/* Take the person TO the mistake (5 Sep 2026): "check the highlighted
   fields" was useless when the invalid field sat above the fold — the
   counter stared at the confirm button and saw nothing highlighted. */
function coScrollInvalid() {
  var bad = document.querySelector('#view-checkout .field.invalid, #view-checkout .invalid');
  if (!bad) return;
  try { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { bad.scrollIntoView(); }
  var inp = bad.querySelector('input,select,textarea');
  if (inp) { try { inp.focus({ preventScroll: true }); } catch (e) {} }
}
function coGoStep(n) {
  if (n === 2) {
    const dv = validateCheckoutDetails();
    if (dv.blocked) { toast('⛔ This number is blocked from booking — contact office +91 91048 01507'); SFX.error(); return; }
    if (!dv.ok) { coFixToast(dv); return; }
    /* Name + mobile ARE the sign-in: establish the traveller's session in
       the background while they look at the payment step, so the booking
       lands on their account and the ticket carries these same details. */
    if (dv.phoneVal && typeof ensureCustomerSession === 'function') {
      const leadEl = $('#paxRows .pxName');
      ensureCustomerSession(leadEl ? leadEl.value : '', dv.phoneVal, '');
    }
    /* Push history entry so Android back-button returns to step 1 */
    if (typeof window._shgCoStepPush === 'function') window._shgCoStepPush();
  }
  const s1 = $('#coStep1'), pc = $('#payCard'), ind = $('#co2Step');
  if (s1) s1.classList.toggle('hide', n !== 1);
  if (pc) pc.classList.toggle('hide', n !== 2);
  if (ind) $$('.cs2', ind).forEach(el => {
    const st = parseInt(el.getAttribute('data-step'), 10);
    el.classList.toggle('on', st === n);
    el.classList.toggle('done', st < n);
  });
  Flow.coStep = n;
  try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { try { window.scrollTo(0, 0); } catch (e2) {} }
}

function setPayMethod(m) {
  PAY_METHOD = m;
  ['upi', 'esewa', 'link', 'cod'].forEach(k => {
    const btn = $('#payMethod' + k.charAt(0).toUpperCase() + k.slice(1));
    if (btn) btn.classList.toggle('on', m === k);
  });
  $('#upiPanel').classList.toggle('hide', m !== 'upi');
  $('#esewaPanel').classList.toggle('hide', m !== 'esewa');
  const lp = $('#linkPayPanel'); if (lp) lp.classList.toggle('hide', m !== 'link');
  const cp = $('#codPanel'); if (cp) cp.classList.toggle('hide', m !== 'cod');
  const ps = $('#proofSection'); if (ps) ps.classList.toggle('hide', m === 'cod');
  var ppC = $('#ppCard'); var tsBtn = $('#tabShot');
  if (ppC) ppC.style.display = (m === 'cod' || (tsBtn && !tsBtn.classList.contains('on'))) ? 'none' : '';
  if (m !== 'cod') ppUpdateLinks();
  if (m === 'upi' || m === 'esewa') $('#refLbl').innerHTML = t(m === 'upi' ? 'lblUtr' : 'lblRef');
  if (m === 'link') {
    const upiId = (DB.settings && DB.settings.upiId) || '9104801507.eazypay@icici';
    const lt = Flow.checkoutTotals ? Flow.checkoutTotals() : null;
    const amt = lt && !lt.fareBad ? lt.total : 0;
    const linkUrl = 'upi://pay?pa=' + encodeURIComponent(upiId) + '&pn=S+Hari+Global' + (amt ? '&am=' + amt : '') + '&cu=INR';
    const el = $('#linkPayUrl'); if (el) el.textContent = linkUrl;
    const wa = $('#linkPayWa');
    if (wa) wa.href = 'https://wa.me/?text=' + encodeURIComponent('S Hari Global Bus Booking Payment\nAmount: ' + (amt ? inr(amt) : '—') + '\nPay via UPI: ' + linkUrl);
    $('#copyLinkPayBtn').onclick = () => { navigator.clipboard.writeText(linkUrl).then(() => toast(t('copied'))); };
  }
  if (m === 'cod') {
    /* checkoutTotals is a function; this read .grand off it, which never
       existed, so the cash panel always told the passenger ₹0. */
    const ct = Flow.checkoutTotals ? Flow.checkoutTotals() : null;
    const el = $('#codAmount'); if (el) el.textContent = ct && !ct.fareBad ? inr(ct.total) : '—';
  }
}

/* ================================================================
   V6 §"Smart customer features" — remembered travellers.

   A repeat passenger on this route is nearly always the same person
   with the same phone and the same citizenship/Aadhaar number. Making
   them retype all of it every trip is the single biggest reason a
   booking takes minutes instead of seconds, so the last set of
   travellers is kept in this browser and offered back with one tap.

   Stored locally only — it never leaves the device, and "Forget these"
   removes it. Nothing here is authoritative: the server still validates
   every field at /api/book.php.
================================================================ */
const PaxMemory = {
  KEY: 'shg:pax',
  MAX: 6,
  read() {
    try {
      const v = JSON.parse(localStorage.getItem(this.KEY) || 'null');
      return (v && Array.isArray(v.people) && v.people.length) ? v : null;
    } catch (e) { return null; }
  },
  save(rec) { try { localStorage.setItem(this.KEY, JSON.stringify(rec)); } catch (e) {} },
  clear()   { try { localStorage.removeItem(this.KEY); } catch (e) {} },
  /* Called once a booking actually succeeds — never from a half-filled form. */
  remember(booking) {
    if (!booking || !booking.passengers || isCounterSale()) return;   // never keep the desk's customers in the office browser
    const seen = {}, people = [];
    booking.passengers.forEach((p) => {
      const name = (p.name || '').trim();
      const key  = name.toLowerCase();
      if (!name || seen[key]) return;
      seen[key] = 1;
      people.push({ name: name, age: p.age || '', gender: p.gender || '' });
    });
    if (!people.length) return;
    const c = booking.contact || {};
    this.save({
      people: people.slice(0, this.MAX),
      phone: c.phone || '', email: c.email || '',
      idType: c.idType || '', idNum: c.idNum || '',
      at: Date.now()
    });
  }
};

/* ================================================================
   CheckoutDraft — sessionStorage form persistence DURING checkout.

   PaxMemory (above) only writes AFTER a successful booking. If the tab is
   killed mid-form (Android background eviction, accidental pull-to-refresh,
   OS memory pressure), a passenger three pax rows deep loses everything
   and abandons the booking. This module debounce-saves the whole visible
   form state to sessionStorage keyed by route+date+seats, restores it on
   renderCheckout(), and clears it on successful book. Session-scoped so a
   clean tab-close forgets (privacy on shared devices); prune drops
   anything older than 6h. Runs AFTER applyPaxMemory so a fresh chip-pick
   wins over an old draft via setIfBlank semantics.
================================================================ */
const CheckoutDraft = {
  keyFor() {
    var out = Flow.legs && Flow.legs[0]; if (!out) return '';
    var parts = ['shg:codraft', out.routeId || '', out.date || '', (out.seats || []).join('|')];
    var ret = Flow.legs && Flow.legs[1];
    if (ret) parts.push(ret.routeId || '', ret.date || '', (ret.seats || []).join('|'));
    return parts.join(':');
  },
  read() {
    try { return JSON.parse(sessionStorage.getItem(this.keyFor()) || 'null'); }
    catch (e) { return null; }
  },
  save() {
    try {
      var key = this.keyFor(); if (!key) return;
      var data = {
        pxName:   $$('#paxRows .pxName').map(function (el) { return el.value || ''; }),
        pxAge:    $$('#paxRows .pxAge').map(function (el) { return el.value || ''; }),
        pxGender: $$('#paxRows .pxGender').map(function (el) { return el.value || ''; }),
        cPhone: ($('#cPhone') || {}).value || '',
        cEmail: ($('#cEmail') || {}).value || '',
        idType: ($('#idType') || {}).value || '',
        idNum:  ($('#idNum')  || {}).value || '',
        tnc:    !!(($('#agreeTnc') || {}).checked),
        at: Date.now()
      };
      /* Only save if anything meaningful was typed — a blank restore is worse
         than no restore (it can overwrite Quick-Booking hand-offs). */
      var anyTyped = data.pxName.some(Boolean) || data.pxAge.some(Boolean) || data.pxGender.some(Boolean)
                  || data.cEmail || data.idNum || data.cPhone;
      if (!anyTyped) return;
      sessionStorage.setItem(key, JSON.stringify(data));
    } catch (e) {}
  },
  restore() {
    var d = this.read(); if (!d) return;
    var names   = $$('#paxRows .pxName');
    var ages    = $$('#paxRows .pxAge');
    var genders = $$('#paxRows .pxGender');
    (d.pxName || []).forEach(function (v, i) {
      if (names[i] && !names[i].value.trim() && v) {
        names[i].value = v;
        names[i].dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    (d.pxAge || []).forEach(function (v, i) {
      if (ages[i] && !ages[i].value.trim() && v) ages[i].value = v;
    });
    (d.pxGender || []).forEach(function (v, i) {
      if (genders[i] && !genders[i].value && v) {
        genders[i].value = v;
        genders[i].dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    var setIfBlank = function (sel, v) { var el = $(sel); if (el && !el.value && v) el.value = v; };
    setIfBlank('#cEmail', d.cEmail);
    setIfBlank('#idNum',  d.idNum);
    setIfBlank('#cPhone', d.cPhone);
    if (d.idType) {
      var idT = $('#idType');
      if (idT && $$('option', idT).some(function (o) { return o.value === d.idType; })) idT.value = d.idType;
    }
    if (d.tnc) { var tnc = $('#agreeTnc'); if (tnc && !tnc.checked) { tnc.checked = true; tnc.dispatchEvent(new Event('change', { bubbles: true })); } }
  },
  clear() { try { sessionStorage.removeItem(this.keyFor()); } catch (e) {} },
  /* Kill any draft older than 6h — the tab may have crashed mid-booking
     on a totally different route; leave nothing dangling. */
  prune() {
    try {
      var cutoff = Date.now() - 6 * 60 * 60 * 1000, kill = [];
      for (var i = 0; i < sessionStorage.length; i++) {
        var k = sessionStorage.key(i);
        if (!k || k.indexOf('shg:codraft:') !== 0) continue;
        try {
          var d = JSON.parse(sessionStorage.getItem(k) || 'null');
          if (!d || !d.at || d.at < cutoff) kill.push(k);
        } catch (e) { kill.push(k); }
      }
      kill.forEach(function (k) { try { sessionStorage.removeItem(k); } catch (e) {} });
    } catch (e) {}
  }
};

/* Fill one passenger row from a remembered traveller. */
function fillPaxRow(row, person) {
  if (!row || !person) return;
  const n = $('.pxName', row), a = $('.pxAge', row), g = $('.pxGender', row);
  if (n) { n.value = person.name || ''; n.dispatchEvent(new Event('input', { bubbles: true })); }
  if (a && person.age) a.value = person.age;
  if (g && person.gender) { g.value = person.gender; g.dispatchEvent(new Event('change', { bubbles: true })); }
}

/* Prefill checkout from memory and offer the rest as one-tap chips. */
function applyPaxMemory() {
  const rec  = PaxMemory.read();
  const rows = $$('#paxRows .pax-row');
  const host = $('#paxRows');
  const old  = $('#paxMemory');
  if (old) old.remove();
  if (isCounterSale() || !rec || !host || !rows.length) return;

  /* Contact details: only ever fill a field the passenger left empty. */
  const setIfBlank = (sel, val) => { const el = $(sel); if (el && !el.value && val) el.value = val; };
  setIfBlank('#cPhone', rec.phone);
  setIfBlank('#cEmail', rec.email);
  setIfBlank('#idNum',  rec.idNum);
  const idT = $('#idType');
  if (idT && rec.idType && $$('option', idT).some(o => o.value === rec.idType)) idT.value = rec.idType;

  /* The booker is almost always seat 1 — fill that row outright. */
  const first = $('.pxName', rows[0]);
  if (first && !first.value.trim()) fillPaxRow(rows[0], rec.people[0]);

  /* Everyone else is offered, not assumed. */
  const strip = document.createElement('div');
  strip.id = 'paxMemory';
  strip.className = 'pax-memory';
  strip.innerHTML = '<small>' + esc(t('savedPaxT')) + '</small>'
    + '<div class="pm-chips">'
    + rec.people.map((p, i) => '<button type="button" class="pm-chip" data-pm="' + i + '">👤 ' + esc(p.name) + '</button>').join('')
    + '<button type="button" class="pm-chip pm-clear" data-pm-clear="1">✕ ' + esc(t('savedPaxClear')) + '</button>'
    + '</div>';
  host.parentNode.insertBefore(strip, host);

  strip.addEventListener('click', (e) => {
    const clear = e.target.closest('[data-pm-clear]');
    if (clear) { PaxMemory.clear(); strip.remove(); return; }
    const chip = e.target.closest('[data-pm]');
    if (!chip) return;
    const person = rec.people[parseInt(chip.getAttribute('data-pm'), 10)];
    const target = $$('#paxRows .pax-row').find(r => { const n = $('.pxName', r); return n && !n.value.trim(); })
                || $$('#paxRows .pax-row').slice(-1)[0];
    fillPaxRow(target, person);
    toast(t('tPaxFilled'));
  });
}

/* ================================================================
   V6 §"One-click rebook previous trip".
   Loads the same route straight into the results view for tomorrow,
   with that trip's passengers already remembered for checkout.

   reverse = true (17 Sep 2026, "Book return journey" on a confirmed
   ticket): the OPPOSITE direction instead — the Nepal hub ⇄ the
   passenger's own Gujarat stop — dated after the outbound bus arrives,
   with the lead passenger handed to the checkout through the same
   SHG_QUICK prefill the home-page card uses. Still one ticket per leg:
   the engine books no return leg yet (round_trip_on gate, 05-router).
================================================================ */
function rebookFrom(bookingId, reverse) {
  const b = DB.bookings.find(x => x.id === bookingId);
  if (!b) return;
  const r = routeById(b.routeId);
  if (!r) { toast(t('errRouteNotFound') || 'That route is no longer available.'); return; }

  PaxMemory.remember(b);

  const setVal = (sel, v) => { const el = $(sel); if (el && $$('option', el).some(o => o.value === v)) el.value = v; };
  let from = r.from, to = r.to;
  let date = addDaysISO(todayISO(), 1);
  if (reverse) {
    from = r.to; to = r.from;
    /* The passenger's own Gujarat stop (boarding going out, drop coming
       back) is where the return starts or ends — not the route's nominal
       end city. The Nepal side stays the hub the search understands. */
    const nep = (typeof isNepalPoint === 'function') ? isNepalPoint : (() => false);
    const ownTown = nep(r.from) ? (parseBP(b.drop || '').name || '') : (parseBP(b.boarding || '').name || '');
    if (ownTown && !nep(ownTown)) { if (nep(from)) to = ownTown; else from = ownTown; }
    /* Earliest sensible day: the one after the outbound bus arrives. */
    const arrive = addDaysISO(b.date || todayISO(), (parseInt(r.dayOffset, 10) || 0) + 1);
    if (arrive > date) date = arrive;
    /* Drive the simple search (direction + town) when it is there, so the
       hidden #fromSel/#toSel it mirrors onto are set exactly as a tap would. */
    if (typeof setSearchDir === 'function' && $('#pointSel')) {
      const dir = nep(from) ? 'back' : 'go';
      const town = dir === 'back' ? to : from;
      setSearchDir(dir);
      const ps = $('#pointSel');
      if (ps && $$('option', ps).some(o => o.value === town)) { ps.value = town; if (typeof applyDirection === 'function') applyDirection(); }
      from = ($('#fromSel') || {}).value || from; to = ($('#toSel') || {}).value || to;
    } else { setVal('#fromSel', from); setVal('#toSel', to); }
    /* Same lead passenger on the return ticket (renderCheckout reads SHG_QUICK). */
    try {
      const lead = (b.passengers || [])[0] || {};
      const nm = String(lead.name || '').trim();
      window.SHG_QUICK = {
        name: (nm && nm.indexOf(t('paxAuto')) !== 0) ? nm : '',
        phone: digits((b.contact && b.contact.phone) || ''),
        gender: lead.gender || '', age: lead.age || ''
      };
    } catch (e) {}
  } else {
    setVal('#fromSel', from);
    setVal('#toSel', to);
  }

  const di = $('#dateInput'); if (di) di.value = date;
  markDateChip();

  setTripType('one');
  releaseMyLocks();
  Flow.from = from; Flow.to = to; Flow.date = date; Flow.retDate = '';
  Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; Flow.fareOverride = 0;
  Flow.paxIndividual = false;

  toast(t(reverse ? 'tRebookRet' : 'tRebookOn'));
  location.hash = '#/results';
  if (location.hash === '#/results') router();
}

function renderCheckout() {
  const legs = Flow.legs;
  const out = legs[0]; if (!out) { location.hash = '#/'; return; }
  const rOut = routeById(out.routeId) || {};
  const ret = legs[1] || null;
  const rRet = ret ? (routeById(ret.routeId) || {}) : null;
  if (!Flow.draftId) {
    Flow.draftId = genId(rOut);  // ticket ID reserved up-front
    Flow.paxIndividual = false;  // a new draft starts as a one-name group booking (17 Sep 2026)
  }

  let info = (rOut.from || '') + ' → ' + (rOut.to || '') + ' · ' + fmtDate(out.date) + ' · ' + seatLabelJoin(out.seats, rOut.type, out.bookingType);
  if (ret) info += '   |   ↩ ' + (rRet.from || '') + ' → ' + (rRet.to || '') + ' · ' + fmtDate(ret.date) + ' · ' + seatLabelJoin(ret.seats, rRet.type, ret.bookingType);
  $('#coRouteInfo').textContent = info;

  /* GROUP BOOKING under ONE name (Task 6). "Hari, 4 passengers" is a single
     name box — not a separate name/ID form per seat. The per-seat rows still
     exist in the DOM (so live validation, the draft autosave, PaxMemory and the
     one-passenger-per-seat POST are all unchanged), but only the FIRST row's
     fields are visible; the rest are hidden and MIRROR the lead, so every berth
     is booked under the one name. Contact fields (phone/email/document) stay
     single-entry exactly as before. */
  const grpN = out.seats.length;
  $('#paxRows').innerHTML = out.seats.map((s, i) => `
    <div class="pax-row${i === 0 ? ' grp-lead' : ' grp-extra'}" data-seat="${s}" data-ret-seat="${ret ? esc((ret.seats || [])[i] || '') : ''}">
      <span class="seat-tag">${seatLabel(s, rOut.type, out.bookingType)}${ret ? '<small>↩ ' + esc(seatLabel((ret.seats || [])[i] || '', rRet.type, ret.bookingType)) + '</small>' : ''}</span>
      <div class="field field-optional grp-fields"><label data-i18n="${i === 0 ? 'lblName' : 'lblName'}">${i === 0 && grpN > 1 ? tf('lblGroupName', { n: grpN }) : t('lblName')}</label><input class="pxName" placeholder="${esc(t('paxAuto'))} ${i + 1}" autocomplete="name" autocapitalize="words"><div class="err" data-i18n="errName">${t('errName')}</div></div>
      <div class="field field-optional grp-fields"><label data-i18n="lblAge">${t('lblAge')}</label><input class="pxAge" type="number" min="1" max="99" inputmode="numeric" placeholder="—"><div class="err" data-i18n="errAge">${t('errAge')}</div></div>
      <div class="field field-optional grp-fields"><label data-i18n="lblGender">${t('lblGender')}</label><select class="pxGender"><option value="">—</option><option value="Male">${t('gM')}</option><option value="Female">${t('gF')}</option><option value="Other">${t('gO')}</option></select></div>
    </div>`).join('') + (grpN > 1 ? `<p class="pax-group-note" data-i18n="paxGroupNote">${tf('paxGroupNote', { n: grpN })}</p><button type="button" class="btn btn-ghost btn-sm pax-names-toggle" id="paxNamesToggle" aria-pressed="false">${esc(t('paxNamesOn'))}</button>` : '');

  /* Mirror the lead passenger onto the hidden extra seats so the whole party
     books under ONE name + one gender (Task 6). Gender flows to every seat too,
     which keeps the shared-cabin lock and solo-female protection working for a
     same-party group without four separate forms. */
  if (grpN > 1) {
    const lead = $('#paxRows .grp-lead');
    const syncGroup = () => {
      if (Flow.paxIndividual) return;   // per-seat names on (17 Sep 2026): every row is its own passenger
      const nm = $('.pxName', lead).value, ag = $('.pxAge', lead).value, gd = $('.pxGender', lead).value;
      $$('#paxRows .grp-extra').forEach(row => {
        $('.pxName', row).value = nm; $('.pxAge', row).value = ag; $('.pxGender', row).value = gd;
      });
    };
    ['input', 'change'].forEach(ev => lead.addEventListener(ev, syncGroup));
    syncGroup();

    /* "Different names per seat" (17 Sep 2026): an opt-in that shows the
       hidden rows as full passenger rows (#paxRows.pax-names-on, views.css)
       and stops the mirroring — a mixed party, or a manifest that needs
       every name, no longer has to travel under one name. Same inputs,
       same one-passenger-per-seat POST, server unchanged; the default is
       exactly as before. Switching on clears the mirrored copies so each
       row shows its own placeholder; switching off re-mirrors the lead. */
    const pnt = $('#paxNamesToggle');
    const applyPaxMode = () => {
      const on = !!Flow.paxIndividual;
      $('#paxRows').classList.toggle('pax-names-on', on);
      if (pnt) { pnt.setAttribute('aria-pressed', on ? 'true' : 'false'); pnt.textContent = t(on ? 'paxNamesOff' : 'paxNamesOn'); }
      const leadLbl = $('#paxRows .grp-lead .grp-fields label');
      if (leadLbl) leadLbl.textContent = on ? t('lblName') : tf('lblGroupName', { n: grpN });
      if (!on) syncGroup();
    };
    if (pnt) pnt.onclick = () => {
      Flow.paxIndividual = !Flow.paxIndividual;
      if (Flow.paxIndividual) {
        $$('#paxRows .grp-extra').forEach(row => { $('.pxName', row).value = ''; $('.pxAge', row).value = ''; $('.pxGender', row).value = ''; });
      }
      applyPaxMode();
      if (Flow.paxIndividual) { const f = $('#paxRows .grp-extra .pxName'); if (f) { try { f.focus({ preventScroll: true }); } catch (e) {} } }
      coSyncContinue();
    };
    applyPaxMode();
  } else {
    Flow.paxIndividual = false;
  }

  /* A fresh checkout must never inherit the previous sale's button state.
     submitBooking() disables + spins #submitBookingBtn and, on SUCCESS,
     navigates to the ticket without re-enabling it — only the failure paths
     do. One booking per visit hid that for years; a counter selling ticket
     after ticket in one tab found the next "Confirm counter sale" dead
     (owner, 6 Sep 2026: "euta kate paxi arko katna mildaina"). Reset it
     here, on every render. */
  const sbtn = $('#submitBookingBtn');
  if (sbtn) {
    sbtn.disabled = false;
    sbtn.classList.remove('loading');
    if (sbtn.dataset.i18n) sbtn.textContent = t(sbtn.dataset.i18n);
  }

  wireLiveValidation();

  /* Voice input on the (single) name field and document-number (Web Speech API) */
  $$('#paxRows .grp-lead .pxName').forEach(function(el) { shgVoice(el); });
  shgVoice($('#idNum'));

  /* ── Customer contact persistence (localStorage) ─────────────────
     Save name + phone after a successful booking and pre-fill them next
     time so repeat customers don't retype their details every trip.     */
  (function() {
    const KEY = 'shg_cust_contact';
    if (isCounterSale()) { window._shgSaveContact = function () {}; return; }
    function saveContact() {
      try {
        const ph  = digits($('#cPhone').value);
        const nm  = ($('#paxRows .pxName') || {}).value || '';
        if (ph.length === 10 || nm.length >= 2) {
          localStorage.setItem(KEY, JSON.stringify({ phone: ph, name: nm }));
        }
      } catch(e) {}
    }
    function loadContact() {
      try {
        const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (!saved) return;
        const phEl = $('#cPhone');
        const nmEl = $('#paxRows .pxName');
        if (phEl && !phEl.value && saved.phone) { phEl.value = saved.phone; checkWelcomeBack(); }
        if (nmEl && !nmEl.value && saved.name)  { nmEl.value = saved.name; }
      } catch(e) {}
    }
    loadContact();
    // Save whenever user types phone or name
    const phEl2 = $('#cPhone');
    if (phEl2) phEl2.addEventListener('blur', saveContact);
    // Also save on submit success (called from booking confirmation code)
    window._shgSaveContact = saveContact;
  })();

  if (!isCounterSale() && typeof USER !== 'undefined' && USER && !$('#cPhone').value) { $('#cPhone').value = USER.phone; checkWelcomeBack(); }
  /* Sign-in details auto-copy onto the ticket: the account's name fills the
     lead passenger box when it is still empty (4 Sep 2026). */
  if (!isCounterSale() && typeof USER !== 'undefined' && USER && USER.name) {
    const leadNm = $('#paxRows .grp-lead .pxName') || $('#paxRows .pxName');
    if (leadNm && !leadNm.value.trim()) { leadNm.value = USER.name; leadNm.dispatchEvent(new Event('input', { bubbles: true })); }
  }

  /* Number-country picker. India and Nepal share the 10-digit mobile format,
     so the WhatsApp/SMS ticket cannot be routed by the number alone. Pre-select
     the picker from the account on file (server-fresh SHG_BOOT wins over any
     cached USER) so a Nepali traveller is not defaulted to +91. We mark it
     "trusted" only when it came from a known account or the customer touched
     it; the submit sends an explicit country ONLY when trusted, so a stale
     client can never override the correct account country server-side. */
  (function () {
    var sel = $('#cCountry');
    if (!sel) return;
    var acc = (window.SHG_BOOT && SHG_BOOT.user && SHG_BOOT.user.country) ||
              (typeof USER !== 'undefined' && USER && USER.country) || '';
    if (!isCounterSale() && (acc === 'NP' || acc === 'IN')) {
      sel.value = acc === 'NP' ? '977' : '91';
      sel.dataset.trusted = '1';
    }
    sel.addEventListener('change', function () { sel.dataset.trusted = '1'; });
  })();

  /* Quick-Booking hand-off (homepage card) + IndiGo-style boarding summary */
  try {
    var _q = window.SHG_QUICK || null;
    if (_q) {
      var firstName = $('#paxRows .pxName');
      if (firstName && !firstName.value && _q.name) firstName.value = _q.name;
      if (_q.phone && !$('#cPhone').value) $('#cPhone').value = _q.phone;
      var g0 = $('#paxRows .pxGender'), a0 = $('#paxRows .pxAge');
      if (g0 && !g0.value && _q.gender) g0.value = _q.gender;
      if (a0 && !a0.value && _q.age) a0.value = _q.age;
    }
  } catch (e) {}

  /* Remembered travellers — runs after the Quick-Booking hand-off so an
     explicit name typed on the homepage always wins over memory. */
  try { applyPaxMemory(); } catch (e) {}

  /* Restore any in-progress draft from a killed tab / pull-to-refresh /
     background eviction. Uses setIfBlank semantics inside restore(), so
     a fresh PaxMemory chip-pick or a Quick-Booking hand-off always wins.
     Prune first to avoid resurrecting anything older than 6h. */
  try { CheckoutDraft.prune(); if (!isCounterSale()) CheckoutDraft.restore(); } catch (e) {}   // a desk never inherits an abandoned customer's typing
  /* Debounced auto-save on every input/change inside the checkout form,
     so a tab that dies mid-typing preserves what was already there. */
  try {
    var _coDraftTimer;
    var _coDraftSave = function () {
      clearTimeout(_coDraftTimer);
      _coDraftTimer = setTimeout(function () { CheckoutDraft.save(); }, 350);
    };
    var _coDraftRoot = $('#coStep1');
    if (_coDraftRoot) {
      _coDraftRoot.addEventListener('input',  _coDraftSave);
      _coDraftRoot.addEventListener('change', _coDraftSave);
    }
    /* #paxRows may live outside #coStep1 in some layouts — bind it too, no-op
       if it's already inside (event handlers on the same element are
       distinct objects; browsers do not dedupe automatically but the
       500ms debounce collapses duplicate ticks into one save). */
    var _coDraftPax = $('#paxRows');
    if (_coDraftPax && (!_coDraftRoot || !_coDraftRoot.contains(_coDraftPax))) {
      _coDraftPax.addEventListener('input',  _coDraftSave);
      _coDraftPax.addEventListener('change', _coDraftSave);
    }
  } catch (e) {}
  function updateCoSummary() {
    var names = [];
    $$('#paxRows .pxName').forEach(function (n) { if (n.value.trim()) names.push(n.value.trim()); });
    var nm = names.length ? names.join(', ')
             : ((window.SHG_QUICK && window.SHG_QUICK.name) || t('coPax') || 'Passenger');
    var elN = $('#bpName'); if (elN) elN.textContent = nm;
    var elR = $('#bpRoute'); if (elR) elR.textContent = (rOut.from || '') + ' → ' + (rOut.to || '');
    var elM = $('#bpMeta'); if (elM) elM.textContent = fmtDate(out.date) + ' · ' + out.seats.length + ' seat' + (out.seats.length > 1 ? 's' : '') + ' · ' + seatLabelJoin(out.seats, rOut.type, out.bookingType);
    var elA = $('#bpAmt'), pa = $('#payAmount'); if (elA && pa) elA.textContent = pa.textContent;
    /* Sticky recap in the step-1 action bar (17 Sep 2026): route · seats ·
       amount — step 1 otherwise shows no amount at all. data-val is the
       tween's true target (flipNumber), so a mid-animation read never
       leaves a half-way number here. */
    var rc = $('#coRecap');
    if (rc) {
      var dv = pa ? pa.getAttribute('data-val') : null;
      var amt = (dv != null && dv !== '' && !isNaN(parseInt(dv, 10))) ? inr(parseInt(dv, 10)) : ((pa && pa.textContent) || '');
      rc.textContent = (rOut.from || '') + ' → ' + (rOut.to || '') + ' · ' + seatLabelJoin(out.seats, rOut.type, out.bookingType) + (amt ? ' · ' + amt : '');
    }
  }
  $$('#paxRows .pxName').forEach(function (n) { n.addEventListener('input', updateCoSummary); });
  updateCoSummary();

  /* Feature A — warn inline the moment a chosen gender clashes with a
     shared-cabin lock, so the passenger fixes it here instead of being
     rejected (server-side) at submit. One delegated listener on #paxRows. */
  (function () {
    const pr = $('#paxRows');
    if (!pr || pr.__glBound) return;
    pr.__glBound = true;
    pr.addEventListener('change', function (e) {
      const sel = e.target.closest && e.target.closest('.pxGender');
      if (!sel) return;
      const row = sel.closest('.pax-row'); if (!row) return;
      const g = sel.value;
      const lockFor = function (seat, leg) {
        if (!seat || !leg) return 'none';
        return (unitGenderLocksFor(leg.routeId, leg.date)[unitKeyJS(seat)]) || 'none';
      };
      /* Operator's women-only seats (server list; enforced at sale time since
         4 Sep 2026 for sharing/seater bookings) — warn here, before submit. */
      const femFor = function (seat, leg) {
        if (!seat || !leg || leg.bookingType === 'private') return false;
        const sn = SeatSrv.snap(leg.routeId, leg.date, leg.bookingType) || SeatSrv.snap(leg.routeId, leg.date);
        const list = (sn && Array.isArray(sn.female) && sn.female.length) ? sn.female
          : (CONFIG.femaleSeats[(routeById(leg.routeId) || {}).type] || []);
        return list.indexOf(seat) >= 0;
      };
      const checks = [
        { seat: row.getAttribute('data-seat'), leg: Flow.legs[0] },
        { seat: row.getAttribute('data-ret-seat'), leg: Flow.legs[1] || null },
      ];
      let msg = '';
      for (let j = 0; j < checks.length; j++) {
        const lk = lockFor(checks[j].seat, checks[j].leg);
        if (lk === 'female_only' && g === 'Male') { msg = '⚠️ Seat ' + seatLabel(checks[j].seat, (routeById(checks[j].leg.routeId) || {}).type, checks[j].leg.bookingType) + ' is in a women-only cabin — a male passenger can’t be booked here. Please choose another seat. · महिला मात्र केबिन'; break; }
        if (lk === 'male_only' && g === 'Female') { msg = '⚠️ Seat ' + seatLabel(checks[j].seat, (routeById(checks[j].leg.routeId) || {}).type, checks[j].leg.bookingType) + ' is in a men-only cabin — a female passenger can’t be booked here. Please choose another seat. · पुरुष मात्र केबिन'; break; }
        if (g === 'Male' && femFor(checks[j].seat, checks[j].leg)) { msg = '⚠️ Seat ' + seatLabel(checks[j].seat, (routeById(checks[j].leg.routeId) || {}).type, checks[j].leg.bookingType) + ' is reserved for women — please choose another seat. · महिलाका लागि आरक्षित सिट'; break; }
      }
      let warn = row.querySelector('.gender-lock-warn');
      if (msg) {
        if (!warn) { warn = document.createElement('div'); warn.className = 'gender-lock-warn'; warn.style.cssText = 'grid-column:1/-1;color:var(--bad);font-size:12px;font-weight:600;margin-top:4px'; row.appendChild(warn); }
        warn.textContent = msg; sel.style.borderColor = 'var(--bad)';
      } else if (warn) { warn.remove(); sel.style.borderColor = ''; }
    });
  })();

  /* Fare breakdown + payable amount (no surprise charges).
     Loyalty: a signed-in user's tier discount is applied automatically;
     point redemption is opt-in (checkbox). Both recompute in one place. */
  const f = calcFare(legs);
  const st = S();
  const me = (typeof USER !== 'undefined' && USER) ? userByPhone(USER.phone) : null;
  const tierInfo = me ? tierFor(me.loyaltyPoints || 0) : null;
  const redeemCfg = CONFIG.booking.loyaltyRedeem;
  Flow.usePoints = false;

  /* Cabin fare for sleeper legs */
  const cabinLegs = legs.filter(l => l.bookingType && l.cabinType);
  let cabinTotal = 0;
  const cabinRows = cabinLegs.map(l => {
    const cf = calcCabinFare(l.cabinType, l.bookingType, l.seats.length, true, (routeById(l.routeId) || {}).to, l.fareOverride);
    cabinTotal += cf.total;
    return '<div class="sum-row"><span>' + cf.emoji + ' ' + cf.label + '</span><b>' + inr(cf.total) + '</b></div>'
      + (l.sharingTier ? '<div class="sum-row"><span>Sharing tier · साझा टियर</span><b>' + sharingTierLabel(l.sharingTier) + '</b></div>' : '')
      + (cf.saved > 0 ? '<div class="sum-row disc"><span>🌐 Online Discount</span><b>− ' + inr(cf.saved) + '</b></div>' : '')
      /* Fare by passenger count — "2 passengers × ₹2,000" — so the total is never a surprise. */
      + '<div class="sum-row"><span>' + tf('rowPaxFare', { n: l.seats.length, f: inr(cf.perPerson) }) + '</span><b>' + inr(cf.total) + '</b></div>';
  }).join('');
  const useCabin = cabinLegs.length > 0;
  const fareBase = useCabin ? cabinTotal : f.total;

  /* Cabin Intelligence — VIP gold theme on the checkout pay card when any
     leg is a private cabin. Null-safe: non-sleeper flows clear both. */
  const coView = $('#view-checkout');
  if (coView) {
    const hasPrivateLeg = cabinLegs.some(l => l.bookingType === 'private');
    coView.classList.toggle('premium-mode', hasPrivateLeg);
    coView.classList.toggle('sharing-mode', !hasPrivateLeg && cabinLegs.some(l => l.bookingType === 'sharing'));
  }

  const checkoutTotals = () => {
    const tierDisc = (tierInfo && tierInfo.tier.discountPct > 0) ? Math.round(fareBase * tierInfo.tier.discountPct / 100) : 0;
    const afterTier = fareBase - tierDisc;
    const red = Flow.usePoints ? loyaltyRedemption(me, (me && me.loyaltyPoints) || 0, afterTier) : { points: 0, rupees: 0 };
    return { tierDisc: tierDisc, tierName: tierInfo ? tierInfo.tier.icon + ' ' + tierInfo.tier.name : '',
             points: red.points, pointsValue: red.rupees, total: Math.max(1, afterTier - red.rupees),
             fareBad: !fareOk(fareBase) };
  };

  /* Number-flip: tween a money element from its current value to the new
     one (easeOutCubic, ~450ms). Re-entrancy-safe (cancels an in-flight
     tween), reads the true target from data-val so rapid changes don't
     accumulate rounding, mirrors an optional second element (the
     boarding-pass summary), and honours prefers-reduced-motion. */
  const flipNumber = (el, to, fmt, mirror) => {
    if (!el) return;
    fmt = fmt || (v => String(Math.round(v)));
    to = Math.round(to);
    let from = parseInt(String(el.getAttribute('data-val') != null ? el.getAttribute('data-val') : el.textContent).replace(/[^0-9.\-]/g, ''), 10);
    if (isNaN(from)) from = to;
    el.setAttribute('data-val', String(to));
    if (mirror) mirror.setAttribute('data-val', String(to));
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || from === to) { el.textContent = fmt(to); if (mirror) mirror.textContent = fmt(to); return; }
    if (el._flipRAF) cancelAnimationFrame(el._flipRAF);
    const dur = 450; let start = null;
    const step = (ts) => {
      if (start === null) start = ts;
      const p = Math.min(1, (ts - start) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = Math.round(from + (to - from) * eased);
      el.textContent = fmt(val);
      if (mirror) mirror.textContent = fmt(val);
      if (p < 1) { el._flipRAF = requestAnimationFrame(step); }
      else { el.textContent = fmt(to); if (mirror) mirror.textContent = fmt(to); el._flipRAF = null; }
    };
    el._flipRAF = requestAnimationFrame(step);
  };

  const updatePayment = () => {
    const x = checkoutTotals();
    let rows = useCabin ? cabinRows : fareRowsHTML(f);
    if (x.tierDisc > 0) rows += '<div class="sum-row disc"><span>' + tf('loyRowTier', { t: tierInfo.tier.icon + ' ' + tierInfo.tier.name }) + '</span><b>− ' + inr(x.tierDisc) + '</b></div>';
    if (x.pointsValue > 0) rows += '<div class="sum-row disc"><span>' + t('loyRowPoints') + ' (' + x.points + ')</span><b>− ' + inr(x.pointsValue) + '</b></div>';
    /* opt-in points checkbox — only when the user has enough points */
    if (me && (me.loyaltyPoints || 0) >= redeemCfg.points) {
      const preview = loyaltyRedemption(me, me.loyaltyPoints, f.total - x.tierDisc);
      rows += '<label class="loy-use"><input type="checkbox" id="loyUseChk"' + (Flow.usePoints ? ' checked' : '') + '> '
        + tf('loyUseChk', { p: preview.points, a: inr(preview.rupees) }) + '</label>';
    }
    /* Math.max(1, …) above would turn a fare that failed to load into a
       payable ₹1; say so instead and let submitBooking refuse. */
    $('#coFareRows').innerHTML = x.fareBad
      ? '<div class="sum-row total"><span style="color:var(--bad)">' + esc(t('fareNA')) + '</span><b>—</b></div>'
      : rows + '<div class="sum-row total"><span>' + t('rowTotal') + '</span><b>' + inr(x.total) + '</b></div>';
    const chk = $('#loyUseChk');
    if (chk) chk.onchange = () => { Flow.usePoints = chk.checked; updatePayment(); };
    flipNumber($('#payAmount'), x.total, x.fareBad ? (() => '—') : inr, $('#bpAmt'));
    $('#payAmountNpr').textContent = x.fareBad ? '' : nprEst(x.total);
    const codEl = $('#codAmount'); if (codEl) codEl.textContent = x.fareBad ? '—' : inr(x.total);
    if (typeof updateCoSummary === 'function') updateCoSummary();

    /* — UPI panel — */
    $('#upiIdText').textContent = st.upiId;
    const upiUrl = 'upi://pay?pa=' + encodeURIComponent(st.upiId) + '&pn=' + encodeURIComponent(st.upiName) + '&am=' + x.total + '&cu=INR&tn=' + encodeURIComponent('Bus ' + Flow.draftId);
    const dl = $('#upiDeepLink'); if (dl) dl.href = upiUrl;
    /* UPI app buttons (13 Sep 2026): an iPhone has no system handler for
       upi://, so the generic link often does nothing there; each app's own
       scheme does. Android keeps the generic chooser and gets the same
       shortcuts (GPay's Android scheme is tez://). Same amount, same note. */
    const upiApps = $('#upiApps');
    if (upiApps) {
      const qs = upiUrl.split('?')[1] || '';
      const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent || '');
      const apps = [['GPay', ios ? 'gpay://upi/pay?' : 'tez://upi/pay?', '🟢'], ['PhonePe', 'phonepe://pay?', '🟣'], ['Paytm', 'paytmmp://pay?', '🔵']];
      upiApps.innerHTML = '<span class="upi-apps-lbl">' + esc(t('payApps')) + '</span>'
        + apps.map(a => '<a class="upi-app" href="' + esc(a[1] + qs) + '"><i aria-hidden="true">' + a[2] + '</i>' + a[0] + '</a>').join('');
    }
    if (CONFIG.customQrImage) { $('#qrImg').src = CONFIG.customQrImage; }
    else makeQR(upiUrl, 220).then(url => { if (url) $('#qrImg').src = url; else $('#qrImg').alt = 'QR unavailable — pay to the UPI ID shown below'; });

    /* — eSewa panel — default receiver Dileep Sunar; official QR image
       (esewa-qr.jpg beside the HTML) wins, else an info-QR is generated */
    $('#esewaIdText').textContent = st.esewaId + ' (' + (st.esewaName || CONFIG.esewaName) + ')';
    const nprAmt = Math.round(x.total * CONFIG.nprPerInr);
    $('#esewaAmt').textContent = 'NPR ' + nprAmt.toLocaleString('en-IN');
    const esewaPayload = 'eSewa|' + st.esewaId + '|' + st.esewaName + '|NPR ' + nprAmt + '|Bus ' + Flow.draftId;
    const eqi = $('#esewaQrImg');
    if (CONFIG.customEsewaQrImage) {
      eqi.onerror = () => { eqi.onerror = null; makeQR(esewaPayload, 220).then(url => { if (url) eqi.src = url; }); };
      eqi.src = CONFIG.customEsewaQrImage;
    } else {
      makeQR(esewaPayload, 220).then(url => { if (url) eqi.src = url; });
    }
  };
  Flow.checkoutTotals = checkoutTotals;   // submitBooking reads the same maths
  updatePayment();

  setPayMethod(PAY_METHOD);
  $('#payMethodUpi').onclick = () => setPayMethod('upi');
  $('#payMethodEsewa').onclick = () => setPayMethod('esewa');
  $('#payMethodLink').onclick = () => setPayMethod('link');
  $('#payMethodCod').onclick = () => setPayMethod('cod');

  const copyText = (txt) => {
    const tmp = document.createElement('textarea'); tmp.value = txt;
    document.body.appendChild(tmp); tmp.select();
    try { document.execCommand('copy'); toast(t('tCopied')); } catch (e) { toast(txt); }
    tmp.remove();
  };
  $('#copyUpiBtn').onclick = () => copyText(st.upiId);
  $('#copyEsewaBtn').onclick = () => copyText(st.esewaId);

  /* Proof tabs: screenshot ⇄ transaction reference */
  const showShot = (on) => {
    $('#tabShot').classList.toggle('on', on); $('#tabUtr').classList.toggle('on', !on);
    $('#panelUtr').classList.toggle('hide', on);
    /* The screenshot-upload card (#ppCard) is the single screenshot control —
       show it on the Screenshot tab, hide it on the UTR tab so a customer on
       the UTR tab can't attach a photo submitBooking() then silently ignores. */
    var ppC = $('#ppCard');
    if (ppC && PAY_METHOD !== 'cod') { ppC.style.display = on ? '' : 'none'; }
  };
  $('#tabShot').onclick = () => showShot(true);
  $('#tabUtr').onclick = () => showShot(false);

  /* §2: screenshot proof is captured by the single #ppCard uploader (ppSetupCard),
     which writes Flow.shotThumb. Reset BOTH the payload and the card's view on each
     render so a re-entered checkout never shows a stale "Screenshot Ready" card with
     an empty payload. */
  ppResetCard();

  $('#checkoutBackBtn').onclick = () => {
    const li = Flow.legs.length - 1;
    const leg = Flow.legs[li];
    Flow.legIndex = li;
    Flow.route = routeById(leg.routeId);
    Flow.scheduleId = leg.sid || 0;   // back onto the same departure (extra bus or daily)
    Flow.seats = leg.seats.slice();
    location.hash = '#/seats';
  };
  $('#submitBookingBtn').onclick = submitBooking;
  startHoldTicker();

  /* Hold keep-alive (4 Sep 2026): a traveller who is actively filling the
     form no longer loses the seats at the 30-minute mark. Every 8 minutes,
     while this checkout is on screen, the tab is visible and the person has
     touched the page in the last 5 minutes, the same holds are re-asserted
     (the server extends expires_at for the same token). An idle or
     backgrounded tab is left to expire exactly as before. */
  clearInterval(window._shgHoldKeep);
  window._shgHoldKeep = setInterval(function () {
    if ((location.hash || '') !== '#/checkout') { clearInterval(window._shgHoldKeep); return; }
    if (document.hidden) return;
    if (typeof LAST_ACTIVITY !== 'undefined' && (Date.now() - LAST_ACTIVITY) > 5 * 60000) return;
    if ((DB.locks || []).some(l => l.sid === SID)) SeatLock.schedule();
  }, 8 * 60000);

  /* Payment Proof Card — populate WhatsApp & Email links with booking details */
  ppUpdateLinks();
  var ppCard = $('#ppCard');
  if (ppCard) {
    var isCodMode = PAY_METHOD === 'cod';
    var shotTabOn = $('#tabShot') ? $('#tabShot').classList.contains('on') : true;
    ppCard.style.display = (isCodMode || !shotTabOn) ? 'none' : '';
  }

  /* §1 two-step: wire the Continue / Edit-details nav and open on step 1. The
     step panels are hidden/shown (never re-rendered), so switching back to
     details never loses entered data. */
  var coCont = $('#coContinueBtn'); if (coCont) coCont.onclick = () => coGoStep(2);
  var coBackD = $('#coBackToDetailsBtn'); if (coBackD) coBackD.onclick = () => coGoStep(1);
  var coS1 = $('#coStep1');
  if (coS1 && !coS1._coSynced) { coS1.addEventListener('input', coSyncContinue); coS1.addEventListener('change', coSyncContinue); coS1._coSynced = true; }
  coSyncContinue();
  coGoStep(1);
}

/* Validate everything, save the booking as PENDING, go to status page.
   🔁 PAYMENT UPGRADE PATH: to use Razorpay/PhonePe (or the eSewa API)
   later, collect payment here via their SDK, set payment.status =
   'verified' on success and skip the manual proof — the rest of the
   flow stays identical. */
async function submitBooking() {
  const legs = Flow.legs;
  const out = legs[0]; if (!out) return;
  const ret = legs[1] || null;
  let ok = true;

  /* §1: run the shared step-1 details validator — identical checks to the
     Continue-to-Payment gate, so the two entry points never drift. */
  const dv = validateCheckoutDetails();
  const passengers = dv.passengers;
  const phone = dv.phone, email = dv.email, idNum = dv.idNum;
  const phoneVal = dv.phoneVal;
  if (dv.blocked) {
    toast('⛔ This number is blocked from booking — contact office +91 91048 01507');
    SFX.error(); shgHaptic('error');
    return;
  }
  if (!dv.ok) ok = false;

  /* Payment proof: screenshot / transaction ref / COD (no proof needed) */
  const isCod = PAY_METHOD === 'cod';
  const isLink = PAY_METHOD === 'link';
  const usingShot = $('#tabShot').classList.contains('on');
  const utr = $('#utrInput').value.trim();
  const utrOk = /^[A-Za-z0-9-]{8,22}$/.test(utr);
  let payMode = '', payUtr = '', payShot = '';
  if (isCod) {
    payMode = 'cod';
  } else if (usingShot) {
    if (!Flow.shotThumb) { toast(t('tShotNeed')); ok = false; }
    payMode = 'screenshot'; payShot = Flow.shotThumb;
  } else {
    $('#utrInput').closest('.field').classList.toggle('invalid', !utrOk);
    if (!utrOk) ok = false;
    payMode = 'utr'; payUtr = utr;
  }
  if (!ok) {
    /* If a details field is the problem, jump back to step 1 so the hidden
       passenger/contact form (and its error cues) are visible again; when
       the details are fine the blocker is the payment proof (UTR /
       screenshot) — say THAT instead of a generic "check the fields". */
    if (!dv.ok) {
      if (Flow.coStep === 2) coGoStep(1);
      setTimeout(function () { coFixToast(dv); }, 200);
    } else {
      toast(t('tFixFields') + '  →  UTR / भुक्तानी प्रमाण');
      setTimeout(coScrollInvalid, 200);
    }
    return;
  }

  /* Duplicate-booking guard: same phone + route + date already active →
     warn (non-blocking), don't silently allow an accidental double. */
  const dup = DB.bookings.find(x => x.id !== Flow.draftId
    && digits(x.contact && x.contact.phone) === phoneVal
    && x.routeId === out.routeId && x.date === out.date
    && (x.status === 'pending' || x.status === 'confirmed'));
  /* A staff counter sells to the same number all day (a family's tickets,
     the office number for a blank phone) — the double-booking question is
     noise there and it stops a bulk sale dead. Staff skip it (6 Sep 2026). */
  const staffSale = !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
  if (dup && !staffSale && !confirm(tf('dupAsk', { id: dup.id }))) return;

  /* Last-moment safety: were any of these seats taken meanwhile? */
  const clash = [];
  legs.forEach(l => {
    const taken = bookedSeatsFor(l.routeId, l.date).concat(lockedSeatsFor(l.routeId, l.date));
    l.seats.forEach(s => { if (taken.indexOf(s) >= 0 && clash.indexOf(s) < 0) clash.push(s); });
  });
  if (clash.length) {
    toast(tf('tSeatGone', { s: seatLabelJoin(clash, (routeById(out.routeId) || {}).type, out.bookingType) }));
    const lg = legs[0];
    Flow.legIndex = 0; Flow.route = routeById(lg.routeId); Flow.scheduleId = lg.sid || 0; Flow.seats = []; Flow.legs = [];
    releaseMyLocks();
    location.hash = '#/seats';
    return;
  }

  /* Referral / agent-code tag. In priority order:
     1) Optional #cAgentCode text field the customer typed on this checkout
        (master-prompt §3 — plain string only, server resolves SHG-NNN to
        an admin_id and writes sold_by_admin_id; invalid codes never block
        the booking, they just fall through to a normal direct sale).
     2) A ?ref=CODE affiliate link (legacy referral-agent path).
     3) A signed-in customer-side agent (users.role='agent'), the old
        counter-agent-on-web fallback.
     Only the plain string leaves the browser — never any admin_id or
     commission field. */
  const me = USER ? userByPhone(USER.phone) : null;
  const agentCodeEl = $('#cAgentCode');
  const agentCodeInput = agentCodeEl ? (agentCodeEl.value || '').trim().toUpperCase() : '';
  const refCode = agentCodeInput || pendingRefCode() || (me && me.role === 'agent' ? me.refCode : '');
  // Remember a typed agent code so future searches already surface departed
  // buses (agent late-booking).
  if (agentCodeInput && typeof persistAgentCode === 'function') { persistAgentCode(agentCodeInput); }

  const f = calcFare(legs);
  const x = Flow.checkoutTotals ? Flow.checkoutTotals() : { tierDisc: 0, points: 0, pointsValue: 0, total: f.total };
  if (x.fareBad) { toast('⚠️ ' + t('fareNA')); SFX.error(); shgHaptic('error'); return; }

  /* ---- Create the real booking on the server (authoritative) --------
     This is the actual reservation: server-side price quote, the DB-level
     UNIQUE(schedule_id, seat_no) double-booking firewall, and the row the
     staff Admin Panel and ticket/PDF/QR pipeline all read from. Everything
     above this point was client-side pre-validation for instant feedback;
     this call is what actually secures the seats. */
  const submitBtn = $('#submitBookingBtn');
  const submitBtnLabel = submitBtn ? submitBtn.textContent : '';
  if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = t('subBtnBusy'); submitBtn.classList.add('loading'); }

  /* Bind the booking to the traveller's account (name + mobile sign-in)
     before it is written. Never blocks: a failed sign-in still books as a
     guest, exactly as before. */
  if (typeof ensureCustomerSession === 'function') {
    try {
      const leadEl0 = $('#paxRows .pxName');
      await ensureCustomerSession(leadEl0 ? leadEl0.value : '', phoneVal, '');
    } catch (e) {}
  }

  /* The client's route catalogue uses its own string ids ('r1','r2'...);
     the server's routes table uses numeric ids, joined by route_code
     (which equals that same string). Resolve the real id via the live
     search endpoint right before booking — this also confirms the route
     is still active and gives us its current server-side schedule. */
  const rOut = routeById(out.routeId) || {};
  let realRouteId;
  try {
    // Pass the agent code so the pre-book re-search can still find a bus that
    // has already departed (a valid agent within its 24h window); without this
    // a departed trip would throw errRouteNotFound here.
    const searchRes = await shgApi.post('/search.php', refCode ? { from: rOut.from, to: rOut.to, date: out.date, agentCode: refCode } : { from: rOut.from, to: rOut.to, date: out.date });
    // An extra bus (Bus Calendar) is matched by its schedule id; the daily bus by route code.
    const match = out.sid
      ? (searchRes.results || []).find(rr => rr.scheduleId === out.sid)
      : (searchRes.results || []).find(rr => rr.routeCode === out.routeId && !rr.extraBus);
    if (!match) throw new Error(t('errRouteNotFound'));
    realRouteId = match.routeId;
  } catch (e) {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtnLabel; submitBtn.classList.remove('loading'); }
    toast(e.message || t('errRouteVerify'));
    SFX.error(); shgHaptic('error');
    return;
  }

  /* Patient / birami mode flags the lead passenger for priority boarding. */
  if (Flow.patient && passengers[0]) passengers[0].special = 'patient';
  const apiPassengers = passengers.map(p => ({ seat: p.seat, name: p.name, age: p.age, gender: p.gender, special: p.special || undefined }));
  let apiResult;
  try {
    apiResult = await shgApi.post('/book.php', {
      routeId: realRouteId, travelDate: out.date, seats: out.seats.slice(),
      scheduleId: out.sid || 0,   // extra bus on the same date (Bus Calendar, 5 Sep 2026); 0 = daily bus
      passengers: apiPassengers,
      contact: { phone: phoneVal, email: email.value.trim(), idType: $('#idType').value, idNum: idNum.value.trim(), country: contactCountry() },
      bookingMode: out.bookingType || 'sharing',
      cabinType: out.cabinType || null,
      sharingTier: out.sharingTier || null,
      // "Other" pickup / drop (17 Sep 2026): the select posts the '__other__'
      // sentinel and the typed text travels in its own field — the server
      // accepts free text only behind the sentinel (BookingService::BOARDING_OTHER).
      boarding: out.boardingOther ? BOARD_OTHER : out.boarding, drop: out.dropOther ? BOARD_OTHER : out.drop,
      boardingOther: out.boardingOther || '', dropOther: out.dropOther || '',
      // Search-origin town; server backfills boarding_stop from it when
      // the client posted an empty boarding (SHG-2026-00056 fix).
      originTown: Flow.from || '',
      pointsRequested: x.points || 0,
      referralCode: refCode,
      paymentMethod: PAY_METHOD,
      isCod: isCod
    });
  } catch (e) {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = submitBtnLabel; submitBtn.classList.remove('loading'); }
    /* Show the SERVER's per-field reason, not the generic top-level message
       (5 Sep 2026): api/book.php returns e.g. {passengers:"Enter a name..."}
       with the top-level "Please check the highlighted fields." — surfacing
       only the top line told a hurried counter nothing. */
    var srvReason = '';
    try { if (e && e.fields && typeof e.fields === 'object') srvReason = Object.keys(e.fields).map(function (k) { return e.fields[k]; }).filter(Boolean).join(' · '); } catch (e2) {}
    toast(srvReason || e.message || t('errBookCreate'));
    SFX.error(); shgHaptic('error');
    return;
  }

  const pnr = apiResult.pnr;   // the real, permanent booking reference

  /* Agent-code applied/rejected notice (§3). Server sends only a display
     label like "SHG-027" — never the agent's name, branch, or admin id. */
  if (apiResult.agent) {
    const msgEl = $('#cAgentCodeMsg');
    if (apiResult.agent.applied) {
      const label = apiResult.agent.label || 'Agent code applied';
      toast('🎟️ ' + label + ' · applied');
      if (msgEl) { msgEl.textContent = '✓ ' + label + ' · applied'; msgEl.style.color = 'var(--good)'; msgEl.hidden = false; }
    } else if (agentCodeInput) {
      toast('Agent code not recognised — booked as direct sale.');
      if (msgEl) { msgEl.textContent = 'Agent code not recognised — booked as direct sale.'; msgEl.style.color = 'var(--muted)'; msgEl.hidden = false; }
    }
  }

  /* These berths are sold now. Drop the cached occupancy for every leg so
     the next seat map is drawn from the database, not from the snapshot
     taken before this sale. */
  legs.forEach(l => SeatSrv.invalidate(l.routeId, l.date));

  const booking = {
    id: pnr, createdAt: Date.now(), routeId: out.routeId, date: out.date,
    seats: out.seats.slice(),
    passengers: passengers,
    contact: { phone: phoneVal, email: email.value.trim(), idType: $('#idType').value, idNum: idNum.value.trim() },
    boarding: out.boarding, drop: out.drop,
    farePerSeat: out.fare, baseTotal: f.base, discount: f.discount, fee: f.fee,
    total: apiResult.total,   // server-authoritative amount — may include coupon/tier/points math the client preview approximated
    tierDiscount: x.tierDisc, tierName: x.tierDisc > 0 ? x.tierName : '',
    pointsUsed: x.points, pointsValue: x.pointsValue,
    pointsBy: (x.points > 0 && me) ? me.phone : '',
    referralCode: refCode,
    payment: { method: PAY_METHOD, mode: payMode, utr: payUtr, shotThumb: payShot, status: isCod ? 'cod_pending' : 'pending' },
    status: apiResult.status || 'pending',
    codFlag: isCod
  };
  if (out.bookingType) {
    booking.bookingType = out.bookingType;
    booking.cabinType = out.cabinType;
    if (out.sharingTier) booking.sharingTier = out.sharingTier;
    const cf = calcCabinFare(out.cabinType, out.bookingType, out.seats.length, true, (routeById(out.routeId) || {}).to, out.fareOverride);
    booking.cabinFare = cf.total;
    booking.cabinLabel = cf.label;
  }
  /* Points are deducted the moment they're spent; refunded automatically
     if the booking is later rejected or cancelled. */
  if (x.points > 0 && me) awardPoints(me, -x.points, 'Redeemed at checkout ' + booking.id, booking.id);
  if (ret) booking.ret = { routeId: ret.routeId, date: ret.date, seats: ret.seats.slice(), boarding: ret.boarding, drop: ret.drop, farePerSeat: ret.fare };

  /* ---- Forward the payment proof already collected above ------------
     Non-COD submission is blocked above unless a UTR or screenshot was
     given, so we always have proof to send the instant the booking
     exists. This is what makes it show up in Admin → Verify Payments. */
  if (!isCod) {
    try {
      const fd = new FormData();
      fd.append('pnr', pnr);
      fd.append('method', PAY_METHOD);
      fd.append('payerName', (passengers[0] && passengers[0].name) || '');
      if (payMode === 'utr') fd.append('utr', payUtr);
      if (payMode === 'screenshot' && payShot) {
        /* The uploader already produced this exact jpeg as a Blob. Prefer it:
           dataUrlToBlob() decodes ~200 KB of base64 back into the same bytes,
           and it runs here — on the tap that submits the booking — which is
           the one moment the passenger is watching. Fall back to decoding
           only for a proof restored from a saved draft, where no live Blob
           exists. */
        const shotBlob = (Flow.shotBlob instanceof Blob) ? Flow.shotBlob : shgApi.dataUrlToBlob(payShot);
        fd.append('screenshot', shotBlob, 'payment.jpg');
      }
      await shgApi.postForm('/payment.php', fd);
    } catch (e) {
      // The booking itself is already safely created server-side; let the
      // customer resubmit proof from My Bookings rather than lose the seat.
      toast(t('errProofUpload'));
    }
  }

  DB.bookings.unshift(booking);
  persist('bookings');
  /* Remember these travellers for next time — only now that the booking
     actually exists on the server, never from a half-filled form. */
  try { PaxMemory.remember(booking); } catch (e) {}
  /* Consumed — the draft is no longer needed and would otherwise linger
     until prune (6h) or a fresh checkout on the same route overwrites it. */
  try { CheckoutDraft.clear(); } catch (e) {}
  releaseMyLocks();

  const id = pnr;
  Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; Flow.fareOverride = 0; Flow.shotThumb = ''; Flow.shotBlob = null;
  Flow.sharingTier = null; Flow.tierManual = false; Flow.patient = false;
  Flow.paxIndividual = false;   // the next party starts under one name again
  location.hash = '#/ticket/' + id;
  toast(t('tSubmitted'));
  SFX.success(); shgHaptic('success');
  /* Notification centre entry (additive — guarded so booking flow never breaks) */
  try {
    if (typeof pushNotif === 'function') {
      if (isCod) pushNotif('🎫', 'Booking submitted · बुकिंग दर्ज', id + ' — COD: बोर्डिङ काउन्टरमा नगद तिर्नुहोस् · काउंटर पर नकद दें · pay cash at the counter before departure.');
      else pushNotif('🎫', 'Booking submitted · बुकिंग दर्ज', id + ' — payment under verification · भुक्तानी जाँच हुँदैछ · भुगतान सत्यापित हो रहा है.');
    }
  } catch (e) {}
  // Payment proof was already sent to the server above — no follow-up prompt needed.
  pvLoadEmailJS();
}

/* ================================================================
   [JS] 10. STATUS / TICKET VIEW — letterhead card, QR, cancel with
   refund message, WhatsApp share, print, crew contact.
================================================================ */
/* ================================================================
   TICKET TRUTH — the database decides what a ticket says.

   A ticket's status used to be frozen in this browser at the moment it was
   created. Staff would verify the payment, the bookings row would flip to
   confirmed, and the passenger's screen would still read "Waiting for
   verification" forever — there was no code path that ever asked the server
   again. Clearing the browser lost the ticket outright, and opening it on a
   second device showed "not found" even though the row existed.

   /api/track.php is the authority. It answers a guest with no account as
   long as they can supply the PNR together with the mobile number used at
   booking, which is the verification the no-login flow is built on.
================================================================ */

/* Turn a track.php payload into the shape the app renders. Used both to
   refresh a known ticket and to recover one this browser has never seen. */
function bookingFromServer(d, phone) {
  const legs = d.legs || [];
  const out  = legs.find(l => l.type === 'outbound') || legs[0] || {};
  const ret  = legs.find(l => l.type === 'return');
  // Per-leg seats when the server sends them (round trips need the split);
  // the flat booking-wide list is the fallback for older responses.
  const seats = ((out.seats && out.seats.length) ? out.seats : (d.seats || [])).slice();
  const b = {
    id: d.pnr,
    createdAt: (typeof d.createdAt === 'number' && d.createdAt > 0) ? d.createdAt : Date.now(),
    routeId: out.routeCode || '',
    date: out.date || '',
    seats: seats,
    passengers: (d.passengers || []).map(p => ({ seat: p.seat, name: p.name, age: p.age, gender: p.gender, special: p.special || null })),
    contact: { phone: digits(phone || ''), email: '', idType: '', idNum: '' },
    boarding: out.boarding || '', drop: out.drop || '',
    farePerSeat: seats.length ? Math.round((d.total || 0) / seats.length) : (d.total || 0),
    baseTotal: d.total || 0, discount: 0, fee: 0, total: d.total || 0,
    payment: {
      method: (d.payment && d.payment.method) || '', mode: '',
      utr: (d.payment && d.payment.utr) || '', shotThumb: '',
      status: (d.payment && d.payment.status) || 'pending',
      reason: (d.payment && d.payment.reason) || ''
    },
    status: d.status,
    codFlag: !!d.isCod,
    ticketNumber: d.ticketNumber || '',
    /* WhatsApp delivery of the ticket (track.php → shg_wa_last, 17 Sep 2026):
       null = nothing sent yet, {ok,last4,status,code} otherwise. */
    wa: (d.wa === undefined) ? undefined : (d.wa || null),
    fromServer: true
  };
  if (ret) {
    b.ret = {
      routeId: ret.routeCode || '', date: ret.date || '',
      seats: (ret.seats || []).slice(),
      boarding: ret.boarding || '', drop: ret.drop || '',
      farePerSeat: (ret.seats && ret.seats.length) ? Math.round((d.total || 0) / ((seats.length + ret.seats.length) || 1)) : 0
    };
  }
  return b;
}

/* Re-read one ticket. Returns true when anything the passenger can see
   moved, so the caller knows whether a redraw is worth it. */
async function syncBooking(b) {
  if (!b || typeof b.id !== 'string' || b.id.indexOf('SHG-') !== 0) return false;
  let d;
  try {
    d = await shgApi.post('/track.php', { pnr: b.id, phone: digits((b.contact && b.contact.phone) || '') });
  } catch (e) {
    return false;   // offline, or a locally-created id the server never saw
  }
  if (!d || d.partial) return false;

  let changed = false;
  if (d.status && b.status !== d.status) { b.status = d.status; changed = true; }
  if (typeof d.total === 'number' && b.total !== d.total) { b.total = d.total; changed = true; }
  if (d.ticketNumber && b.ticketNumber !== d.ticketNumber) { b.ticketNumber = d.ticketNumber; changed = true; }
  if (typeof d.isCod === 'boolean' && !!b.codFlag !== d.isCod) { b.codFlag = d.isCod; changed = true; }
  b.payment = b.payment || {};
  if (d.payment) {
    if (d.payment.status && b.payment.status !== d.payment.status) { b.payment.status = d.payment.status; changed = true; }
    if (d.payment.method && b.payment.method !== d.payment.method) { b.payment.method = d.payment.method; changed = true; }
    /* The rejection reason is what the ticket page shows a customer whose
       payment was refused — without syncing it they only ever see the
       generic fallback. */
    if (b.payment.reason !== (d.payment.reason || '')) { b.payment.reason = d.payment.reason || ''; changed = true; }
  }
  /* WhatsApp delivery state (17 Sep 2026) — informational, never flips `changed`
     on its own, the ticket page re-renders itself when it asked for it. */
  if (d.wa !== undefined) b.wa = d.wa || null;
  return changed;
}

/* Refresh the tickets on screen. Capped so a browser holding a long history
   cannot fire an unbounded burst of requests on every view change.

   The bulk sweep (no ids — the My Bookings list) skips cancelled tickets:
   cancellation is terminal server-side, so re-asking about a whole history of
   them is wasted traffic on a phone. Opening ONE ticket always re-asks,
   whatever this browser thinks its status is — that view is the passenger
   checking a specific claim, and a stale local 'cancelled' is exactly the
   answer they must not be given. */
async function syncBookings(ids) {
  const live = (DB.bookings || []).filter(b =>
    b && typeof b.id === 'string' && b.id.indexOf('SHG-') === 0
    && (ids ? ids.indexOf(b.id) >= 0 : b.status !== 'cancelled'));
  if (!live.length) return false;
  const res = await Promise.all(live.slice(0, 12).map(syncBooking));
  const changed = res.some(Boolean);
  if (changed) persist('bookings');
  return changed;
}

/* Recover a ticket this browser does not have — a cleared cache, a new
   phone, a booking made at the counter. PNR alone is not enough: it is
   printed on the ticket, so the mobile number used at booking is what
   proves ownership (track.php enforces this server-side too). */
async function recoverBooking(pnr, phone) {
  const d = await shgApi.post('/track.php', { pnr: pnr, phone: digits(phone || '') });
  if (!d || d.partial) {
    throw new Error(t('trkNeedPhone'));
  }
  const b = bookingFromServer(d, phone);
  const at = DB.bookings.findIndex(x => x && x.id === b.id);
  if (at >= 0) DB.bookings[at] = Object.assign({}, DB.bookings[at], b);
  else DB.bookings.unshift(b);
  persist('bookings');
  return b;
}

/* What to print in the ticket's "Payment ref" row. COD has no transaction
   reference at all — it used to fall through to the UPI branch and print
   "UPI · —" on screen, in the PDF and on the printed ticket, which reads as a
   failed online payment rather than cash owed at the counter. */
function payRefLabel(b) {
  var p = (b && b.payment) || {};
  if (b && (b.codFlag || p.method === 'cod')) {
    return 'Cash at counter · ' + (p.status === 'verified' || p.status === 'settled' ? 'paid ✓' : 'due');
  }
  var pre = p.method === 'esewa' ? 'eSewa · ' : 'UPI · ';
  return pre + (p.utr || (p.mode === 'screenshot' ? 'Screenshot ✓' : '—'));
}
function canCancel(b) {
  return (b.status === 'pending' || b.status === 'confirmed') && depTimestamp(b) > Date.now();
}
function waShare(id) {
  const b = DB.bookings.find(x => x.id === id); if (!b) return;
  const r = routeById(b.routeId) || {};
  let msg = '🚌 S HARI GLOBAL PVT LTD\n' + t('tkId') + ': ' + b.id
    + '\n' + (parseBP(b.boarding || '').name || r.from || '') + ' → ' + (parseBP(b.drop || '').name || r.to || '') + ' · ' + fmtDate(b.date)
    + '\n' + t('tkSeats') + ': ' + seatLabelJoin(b.seats, r.type, b.bookingType)
    + '\n' + t('tkBoard') + ': ' + bpShort(b.boarding);
  if (b.ret) {
    const rr = routeById(b.ret.routeId) || {};
    msg += '\n↩ ' + (rr.from || '') + ' → ' + (rr.to || '') + ' · ' + fmtDate(b.ret.date) + ' · ' + seatLabelJoin(b.ret.seats, rr.type, b.ret.bookingType);
  }
  msg += '\n' + t('tkFareT') + ': ' + inr(b.total)
    + '\n' + t('supportLbl') + ': ' + S().phone
    /* Self-service open-ticket link — recipient (family, driver, checkpoint)
       can tap through to the live ticket page. Not a signed verify URL (that
       needs Ticket::downloadToken server-side), just the SPA route which the
       recover-booking flow will validate on open. */
    + '\n' + (location.origin + location.pathname + '#/ticket/' + b.id);

  /* Send the TICKET, not a description of it.
     On a phone, navigator.share with a File hands WhatsApp the actual
     boarding-pass image — which is what a passenger shows at the counter and
     what they forward to whoever is paying. Text-only wa.me stays as the
     desktop fallback, because Chrome on a laptop cannot attach a file to a
     wa.me link at all. buildTicketCanvas() already draws the image for the
     Download-JPG button, so this reuses it rather than drawing a second. */
  var openText = function () {
    window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener');
  };

  if (typeof navigator.canShare !== 'function' || typeof buildTicketCanvas !== 'function') {
    openText();
    return;
  }

  /* Server PNG first (5 Sep 2026) — the centralized HD design; the client
     canvas remains as the offline fallback. */
  (typeof serverTicketPngBlob === 'function' ? serverTicketPngBlob(b) : Promise.reject(new Error('no srv')))
    .catch(function () {
      return buildTicketCanvas(b).then(function (cv) {
        return new Promise(function (res) { cv.toBlob(res, 'image/png'); });
      });
    }).then(function (blob) {
    if (!blob) throw new Error('no blob');
    var file = new File([blob], 'SHG-Ticket-' + b.id + '.png', { type: 'image/png' });
    if (!navigator.canShare({ files: [file] })) throw new Error('files unsupported');
    return navigator.share({ files: [file], text: msg, title: 'S Hari Global E-Ticket' });
  }).catch(function (e) {
    /* Closing the share sheet is not a failure — do not then shove a second
       WhatsApp window at them. Anything else means this device cannot share
       files, so fall back to the text link. */
    if (e && e.name === 'AbortError') return;
    openText();
  });
}
function openCancelModal(id) {
  const b = DB.bookings.find(x => x.id === id); if (!b || !canCancel(b)) return;
  const r = routeById(b.routeId) || {};
  const ri = refundInfo(b);
  const refundMsg = ri.pct > 0 ? tf('refundLine', { a: inr(ri.amount), p: ri.pct }) : t('noRefund');
  openModal(`
    <h3 class="m-title">${t('cancelAsk')}</h3>
    <p class="m-sub">${esc((parseBP(b.boarding || '').name || r.from || '') + ' → ' + (parseBP(b.drop || '').name || r.to || ''))} · ${fmtDate(b.date)} · ${seatLabelJoin(b.seats, r.type, b.bookingType)} · <b>${inr(b.total)}</b></p>
    <div class="verify-note" style="margin:14px 0">💰 <span>${refundMsg}</span></div>
    <div class="m-actions">
      <button class="btn btn-ghost" type="button" id="mKeep">${t('cancelKeep')}</button>
      <button class="btn btn-danger" type="button" id="mCancelYes">${t('cancelYes')}</button>
    </div>`);
  $('#mKeep').onclick = closeModal;
  $('#mCancelYes').onclick = async () => {
    const yesBtn = $('#mCancelYes');
    if (yesBtn) { yesBtn.disabled = true; yesBtn.textContent = '…'; }
    /* Real refund-slab math and seat release happen server-side
       (BookingService::cancel) — this is what the Admin Panel and any
       future booking on the freed seat actually see. */
    let res;
    try {
      res = await shgApi.post('/cancel.php', {
        pnr: b.id, phone: (b.contact && b.contact.phone) || '', reason: 'Cancelled by customer'
      });
    } catch (e) {
      if (yesBtn) { yesBtn.disabled = false; yesBtn.textContent = t('cancelYes'); }
      toast(e.message || 'Could not cancel this booking. Please try again or contact support.');
      return;
    }
    b.status = 'cancelled';
    b.cancelledAt = Date.now();
    b.refundNote = (res && res.message) || refundMsg;
    reverseCommissionFor(b);   // cancelled after confirm → commission reversed
    persist('bookings');
    promoteWaitlist(b.routeId, b.date);
    if (b.ret) promoteWaitlist(b.ret.routeId, b.ret.date);
    closeModal();
    toast(t('cancelDone'));
    if ((location.hash || '').indexOf('#/ticket/') === 0) renderStatus(b.id);
    else if (location.hash === '#/my') renderMyBookings();
  };
}

/* Decorative code-128-style barcode, deterministic from the booking id.
   barcodeWidths(id) is the SHARED widths logic: even indexes = dark bars,
   odd indexes = gaps (widths in units). Used by barcodeSVG() on screen
   and by downloadTicketPDF() which draws the same bars via doc.rect. */
function barcodeWidths(id) {
  const s = String(id || 'SHG');
  const w = [2, 1, 1, 2];                                   // start guard
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    w.push((c % 3) + 1, ((c >> 2) % 3) + 1, ((c >> 4) % 3) + 1, ((c >> 6) % 2) + 1);
  }
  w.push(2, 1, 1, 2);                                       // stop guard
  return w;                                                 // ~40 bars for a typical id
}
function barcodeSVG(id) {
  const w = barcodeWidths(id);
  const total = w.reduce((a, b) => a + b, 0);
  let x = 0, bars = '';
  w.forEach((u, i) => {
    if (i % 2 === 0) bars += '<rect x="' + x + '" y="0" width="' + u + '" height="44"/>';
    x += u;
  });
  return '<svg class="bp-bars" viewBox="0 0 ' + total + ' 44" preserveAspectRatio="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor">' + bars + '</svg>';
}

/* Payment progress strip — the four states a customer actually asks about.
   "Has my payment gone through?" is the most common support message on a
   manually-verified system, and until now the ticket screen answered it
   with a single band: you could see WHERE you were but not what came next
   or how far along you were.

   Nothing new is fetched. Every input already arrives in the payload:
   b.status, b.payment.status and b.codFlag (see shg_customer_payload in
   includes/helpers.php). Purely a rendering of what the server already
   said, so it cannot disagree with the band above it. */
/* ---------------------------------------------------------------------
   Post-journey rating. Shown on the ticket screen only once the trip is
   marked 'arrived' (server-computed b.arrived — a rating collected before
   the journey ends is not a rating of the journey).

   One overall star score is required; the three sub-scores and the comment
   are optional and are sent only if the passenger actually chose them, so
   a skipped question stores NULL rather than a number nobody picked.
   ------------------------------------------------------------------- */
function ratingCard(b) {
  if (!b || !b.arrived || b.status !== 'confirmed') return '';
  if (b._rated) {
    return '<div class="rate-card done"><div class="rate-h"><b>' + esc(t('rateDone')) + '</b></div></div>';
  }
  function row(key, label) {
    var stars = '';
    for (var i = 1; i <= 5; i++) {
      stars += '<button type="button" class="rate-star" data-rk="' + key + '" data-rv="' + i
             + '" aria-label="' + i + '">☆</button>';
    }
    return '<div class="rate-row"><span>' + esc(label) + '</span><span class="rate-stars" data-rrow="'
         + key + '">' + stars + '</span></div>';
  }
  return '<div class="rate-card" id="rateCard" data-pnr="' + esc(b.id) + '">'
    + '<div class="rate-h"><b>' + esc(t('rateTitle')) + '</b><small>' + esc(t('rateSub')) + '</small></div>'
    + row('rating', '★')
    + row('comfort', t('rateComfort'))
    + row('punctuality', t('ratePunctual'))
    + row('staff', t('rateStaff'))
    + '<textarea id="rateComment" rows="2" maxlength="1000" placeholder="' + esc(t('rateComment')) + '"></textarea>'
    + '<button type="button" class="btn btn-blue" id="rateSend">' + esc(t('rateSend')) + '</button>'
    + '</div>';
}

/* Wire the stars + submit. Called after the ticket HTML is in the DOM. */
function wireRatingCard(b) {
  var card = document.getElementById('rateCard');
  if (!card) return;
  var picked = {};

  card.querySelectorAll('.rate-star').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var k = btn.getAttribute('data-rk');
      var v = parseInt(btn.getAttribute('data-rv'), 10);
      picked[k] = v;
      card.querySelectorAll('[data-rrow="' + k + '"] .rate-star').forEach(function (s2) {
        var on = parseInt(s2.getAttribute('data-rv'), 10) <= v;
        s2.textContent = on ? '★' : '☆';
        s2.classList.toggle('on', on);
      });
    });
  });

  var send = document.getElementById('rateSend');
  send.addEventListener('click', async function () {
    if (!picked.rating) { toast(t('rateNeedStars')); return; }
    send.disabled = true;
    send.classList.add('loading');
    try {
      var body = { pnr: b.id, rating: picked.rating };
      ['comfort', 'punctuality', 'staff'].forEach(function (k) { if (picked[k]) body[k] = picked[k]; });
      var c = (document.getElementById('rateComment') || {}).value || '';
      if (c.trim()) body.comment = c.trim();

      await shgApi.post('/feedback.php', body);

      b._rated = true;
      persist('bookings');
      card.outerHTML = '<div class="rate-card done"><div class="rate-h"><b>'
                     + esc(t('rateThanks')) + '</b></div></div>';
    } catch (e) {
      send.disabled = false;
      send.classList.remove('loading');
      toast((e && e.message) ? e.message : 'Could not send the rating.');
    }
  });
}

function paymentStepper(b) {
  if (!b) return '';

  var st   = b.status || 'pending';
  var pay  = (b.payment && b.payment.status) || '';
  var cod  = !!b.codFlag;

  // Cancelled/expired never reached verification — a 4-step "progress" bar
  // would imply it is still moving. Say it stopped instead.
  if (st === 'cancelled' || st === 'expired') {
    return '<ol class="paystep stopped" aria-label="' + esc(t('pstStopped')) + '">'
      + '<li class="done"><i>✓</i><b>' + esc(t('pstBooked')) + '</b></li>'
      + '<li class="off"><i>–</i><b>' + esc(t('pstStopped')) + '</b></li>'
      + '</ol>';
  }

  var conf = (st === 'confirmed');
  var rej  = (st === 'rejected' || pay === 'rejected');

  // Step 2 is "money has left the customer" — for COD that has not happened
  // yet by design, so it stays an instruction rather than a completed step.
  var step2Label = cod ? t('pstCounter') : t('pstSent');
  var step2State = cod ? (conf ? 'done' : 'now') : 'done';

  /* On a COD booking nothing is being checked until the cash is actually
     handed over, so step 3 stays inactive rather than pulsing alongside
     step 2 — two "in progress" markers at once reads as a stalled bar. */
  var step3State = conf ? 'done' : (rej ? 'bad' : (cod ? 'off' : 'now'));
  var step3Label = rej ? t('pstRejected') : (conf ? t('pstVerified') : t('pstCheck'));

  var step4State = conf ? 'done' : 'off';

  function li(state, label) {
    var mark = state === 'done' ? '✓' : (state === 'bad' ? '!' : (state === 'now' ? '•' : '–'));
    return '<li class="' + state + '"'
      + (state === 'now' ? ' aria-current="step"' : '')
      + '><i>' + mark + '</i><b>' + esc(label) + '</b></li>';
  }

  return '<ol class="paystep' + (rej ? ' has-bad' : '') + '" aria-label="'
    + esc(t('pstBooked') + ' → ' + t('pstTicket')) + '">'
    + li('done', t('pstBooked'))
    + li(step2State, step2Label)
    + li(step3State, step3Label)
    + li(step4State, t('pstTicket'))
    + '</ol>';
}

function renderStatus(id) {
  const b = DB.bookings.find(x => x.id === id);
  const body = $('#statusBody');
  if (!b) {
    if (typeof TicketPoll !== 'undefined') TicketPoll.stop();
    body.innerHTML = '<div class="empty-state"><div class="big">🔍</div><h3>' + t('stNotFoundT') + '</h3><p>'
      + tf('stNotFoundP', { id: esc(id) }) + '</p><a class="btn btn-blue" href="#/">' + t('btnHome') + '</a></div>';
    return;
  }
  const r = routeById(b.routeId) || {};
  const rr = b.ret ? (routeById(b.ret.routeId) || {}) : null;
  const paxRows = b.passengers.map((p, i) => '<tr><td>' + esc(seatLabel(p.seat, r.type, b.bookingType)) + (b.ret ? ' <small style="color:var(--muted)">/ ↩ ' + esc(seatLabel((b.ret.seats || [])[i] || '', rr.type, b.ret.bookingType)) + '</small>' : '') + '</td><td>' + esc(p.name) + (p.special ? ' <span class="chip" style="font-size:11px;padding:2px 8px">' + esc(t('patientTag')) + '</span>' : '') + '</td><td>' + esc(p.age) + '</td><td>' + esc(p.gender) + '</td></tr>').join('');

  let band = '', extra = '', bpHeader = '';
  const stepper = paymentStepper(b);
  const rateBox = ratingCard(b);
  if (b.status === 'pending') {
    if (b.codFlag) {
      band = '<div class="status-band pending" style="border-color:#FF9800"><div class="big-ico">💵</div><h2>COD — Pay at Counter</h2><p>Seats ' + seatLabelJoin(b.seats, r.type, b.bookingType) + ' held. बोर्डिङ पोइन्टमा ' + inr(b.total) + ' नगद तिर्नुहोस्।<br>Pay <b>' + inr(b.total) + '</b> cash at the boarding counter before departure.</p></div>';
    } else {
      band = '<div class="status-band pending"><div class="big-ico">⏳</div><h2>' + t('stPendT') + '</h2><p>' + tf('stPendP', { s: seatLabelJoin(b.seats, r.type, b.bookingType) }) + '</p></div>';
      /* Proof was already sent inline during checkout (submitBooking → /api/payment.php),
         so the default state is "waiting" — no second upload prompt. A tiny
         "Resend proof" link is still available in case the first upload was
         unclear or the customer wants to re-share via WhatsApp. */
      var pvSub = PV.submissions.find(function(s) { return s.bookingId === b.id && s.status === 'pending'; });
      var verId = pvSub ? esc(pvSub.id) : esc(b.id);
      extra = '<div class="pp-status show waiting" style="margin-bottom:14px"><div class="pp-st-ico">⏳</div><div class="pp-st-title">Waiting for Verification...</div>'
        + '<div class="pp-st-sub">Average verification time: <b>' + esc(CONFIG.verifyTimeText || '1–3 minutes') + '</b><br>Reference: <b>' + verId + '</b><br>Our team will confirm your ticket shortly.<br>'
        + '<a href="#" onclick="event.preventDefault(); pvOpenForm(\'' + esc(b.id) + '\')" style="font-size:12px;color:var(--muted);text-decoration:underline">Resend payment proof if needed</a></div></div>';
    }
  } else if (b.status === 'confirmed') {
    band = '<div class="status-band confirmed"><div class="confetti-burst"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="confirm-checkmark"><svg viewBox="0 0 44 44"><circle class="ck-circle" cx="22" cy="22" r="20"/><polyline class="ck-check" points="13,23 20,30 32,16"/></svg></div><h2>' + t('stConfT') + '</h2><p>' + t('stConfP') + '</p></div>';
    /* Luxury boarding-pass header — Nepali-first design with city codes + bus arrow.
       Known cities map to airline-style codes; anything else falls back
       to the first three letters uppercased. */
    /* Codes + names come from stopDisplay() (02-config), the mirror of the
       server's Boarding::stopDisplay(): the passenger's OWN boarding and drop
       points, not the route's ends. */
    const fromD = stopDisplay(b.boarding, r.from), toD = stopDisplay(b.drop, r.to);
    const bpParsed = parseBP(b.boarding);
    const bpTimeFull = bpParsed.time ? nepaliTimeFull(bpParsed.time) : '';
    bpHeader = '<div class="bp-band' + (b.bookingType === 'private' ? ' bp-vip' : '') + '">'
      + (b.bookingType === 'private' ? '<span class="bp-ribbon" aria-hidden="true">VIP ⭐</span>' : '')
      + '<div class="bp-band-top"><span class="bp-title">' + t('tkBoardPass') + '</span><span class="bp-id">' + esc(b.id) + '</span></div>'
      + '<div class="bp-codes">'
      + '<div class="bp-code">' + esc(fromD.code) + '<small>' + esc(fromD.name || r.from || '') + '</small></div>'
      + '<span class="bp-arrow" aria-hidden="true"><i class="ln"></i><span class="pl">🚌</span><i class="ln arr"></i></span>'
      + '<div class="bp-code">' + esc(toD.code) + '<small>' + esc(toD.name || r.to || '') + '</small></div>'
      + '</div>'
      + (bpTimeFull ? '<div class="bp-time-hero"><span class="bp-time-np">' + esc(bpTimeFull) + '</span><span class="bp-time-en">' + esc(bpParsed.time) + ' IST</span></div>' : '')
      + '</div>';
  } else if (b.status === 'cancelled') {
    band = '<div class="status-band cancelled"><div class="big-ico">✕</div><h2>' + t('stCancT') + '</h2><p>' + t('stCancP') + (b.refundNote ? ' ' + esc(b.refundNote) : '') + '</p></div>';
  } else if (b.status === 'expired' || b.status === 'completed') {
    /* An expired hold is NOT a rejected payment: the seats were released by the
       expiry sweep and may already be resold, so offering to re-send proof
       would take money for a seat the passenger no longer holds. Send them to
       a fresh search instead. */
    band = '<div class="status-band cancelled"><div class="big-ico">⏱</div><h2>' + t('stExpT') + '</h2><p>' + t('stExpP') + ' — ' + t('supportLbl') + ': ' + esc(S().phone) + '</p></div>';
    extra = '<div class="pp-status show waiting" style="margin-bottom:14px"><div class="pp-st-ico">🔄</div><div class="pp-st-title">' + t('stExpT') + '</div>'
      + '<div class="pp-st-sub">' + t('stExpP') + '<br><a href="#/" style="font-size:13px;color:var(--orange);font-weight:700;text-decoration:underline">' + t('stExpBtn') + '</a></div></div>';
  } else {
    band = '<div class="status-band rejected"><div class="big-ico">!</div><h2>' + t('stRejT') + '</h2><p>' + esc(b.payment.reason || 'We could not verify this payment.') + ' — ' + t('supportLbl') + ': ' + esc(S().phone) + '</p></div>';
    /* Rejected payments get a clear re-submit path (§3): no ticket actions,
       but the customer can re-send a clearer proof for review, or call support. */
    extra = '<div class="pp-status show waiting" style="margin-bottom:14px;background:#FDECEC;border-color:#F3C6C6"><div class="pp-st-ico">🔄</div><div class="pp-st-title">' + t('rejResubT') + '</div>'
      + '<div class="pp-st-sub">' + t('rejResubP') + '<br><a href="#" onclick="event.preventDefault(); pvOpenForm(\'' + esc(b.id) + '\')" style="font-size:13px;color:var(--orange);font-weight:700;text-decoration:underline">' + t('rejResubBtn') + '</a></div></div>';
  }

  /* Cabin class strip under the status band — gold VIP band for private
     cabins, blue chip for sharing. Null-safe: seater bookings skip both. */
  if (b.bookingType === 'private') {
    band += '<div class="vip-band">VIP ⭐ Private Cabin · Priority Boarding<small>👑 पूरा केबिन तपाईंको मात्र · पूरी केबिन सिर्फ आपकी · The whole cabin is exclusively yours — board first with VIP support.</small></div>';
  } else if (b.bookingType === 'sharing') {
    band += '<div class="share-band"><span class="share-chip">🤝 Sharing Sleeper' + (b.sharingTier ? ' · ' + esc(sharingTierLabel(b.sharingTier)) : '') + '</span></div>';
  }

  /* Fare by passenger count on the ticket itself (4 Sep 2026): the per-seat
     rate × the party size, shown above the breakdown for any multi-seat
     booking. Cabin bookings use the per-person share of the cabin price. */
  const paxN = (b.seats || []).length;
  const paxRate = b.bookingType && b.cabinLabel
    ? (paxN ? Math.round((b.cabinFare || b.total) / paxN) : 0)
    : (b.farePerSeat || (paxN ? Math.round((b.baseTotal || b.total) / paxN) : 0));
  const paxFareRow = paxN > 1 && paxRate > 0
    ? '<div class="sum-row"><span>' + tf('rowPaxFare', { n: paxN, f: inr(paxRate) }) + '</span><b>' + inr(paxRate * paxN) + '</b></div>'
    : '';
  let fareRows;
  if (b.bookingType && b.cabinLabel) {
    fareRows = paxFareRow + '<div class="sum-row"><span>' + esc(b.cabinLabel) + '</span><b>' + inr(b.cabinFare || b.total) + '</b></div>'
      + '<div class="sum-row"><span>Booking</span><b>' + esc(b.bookingType.charAt(0).toUpperCase() + b.bookingType.slice(1)) + '</b></div>'
      + (b.sharingTier ? '<div class="sum-row"><span>Sharing tier · साझा टियर</span><b>' + sharingTierLabel(b.sharingTier) + '</b></div>' : '')
      + (b.tierDiscount > 0 ? '<div class="sum-row disc"><span>' + tf('loyRowTier', { t: esc(b.tierName || '⭐') }) + '</span><b>− ' + inr(b.tierDiscount) + '</b></div>' : '')
      + (b.pointsValue > 0 ? '<div class="sum-row disc"><span>' + t('loyRowPoints') + ' (' + b.pointsUsed + ')</span><b>− ' + inr(b.pointsValue) + '</b></div>' : '')
      + '<div class="sum-row"><span>' + t('tkFareT') + '</span><b>' + inr(b.total) + '</b></div>';
  } else {
    fareRows = paxFareRow + ((b.discount > 0 || b.fee > 0 || b.tierDiscount > 0 || b.pointsValue > 0)
      ? '<div class="sum-row"><span>' + t('rowBase') + '</span><b>' + inr(b.baseTotal || b.total) + '</b></div>'
        + (b.discount > 0 ? '<div class="sum-row disc"><span>' + t('rowGroup') + '</span><b>− ' + inr(b.discount) + '</b></div>' : '')
        + (b.tierDiscount > 0 ? '<div class="sum-row disc"><span>' + tf('loyRowTier', { t: esc(b.tierName || '⭐') }) + '</span><b>− ' + inr(b.tierDiscount) + '</b></div>' : '')
        + (b.pointsValue > 0 ? '<div class="sum-row disc"><span>' + t('loyRowPoints') + ' (' + b.pointsUsed + ')</span><b>− ' + inr(b.pointsValue) + '</b></div>' : '')
        + (b.fee > 0 ? '<div class="sum-row"><span>' + t('rowFee') + '</span><b>' + inr(b.fee) + '</b></div>' : '')
        + '<div class="sum-row"><span>' + t('tkFareT') + '</span><b>' + inr(b.total) + '</b></div>'
      : '<div class="sum-row"><span>' + t('tkFareT') + '</span><b>' + inr(b.total) + '</b></div>');
  }
  /* Who booked — the sign-in name + mobile, copied onto the ticket. */
  const bookedByName = ((b.passengers || [])[0] || {}).name || '';
  const bookedByPhone = digits((b.contact && b.contact.phone) || '');
  const bookedByRow = (bookedByName || bookedByPhone)
    ? '<div class="sum-row"><span>' + t('tkBookedBy') + '</span><b>' + esc(bookedByName) + (bookedByPhone ? (bookedByName ? ' · ' : '') + '+91 ' + esc(bookedByPhone) : '') + '</b></div>'
    : '';

  const retBlock = b.ret ? `
    <div class="ret-block">
      <div class="ret-tag">↩ ${t('tkRet')}</div>
      <div class="tk-route" style="margin-bottom:10px">
        <div class="city">${esc(rr.from || '—')}<small>DEP ${esc(rr.depTime || '')}</small></div>
        <span class="route-dash" style="flex:1;max-width:110px"><span class="dot"></span><span class="line"></span><span class="dot end"></span></span>
        <div class="city" style="text-align:right">${esc(rr.to || '—')}<small>ARR ${esc(rr.arrTime || '')}${rr.dayOffset ? ' (+' + rr.dayOffset + 'd)' : ''}</small></div>
      </div>
      <div class="tk-grid">
        <div class="sum-row"><span>${t('tkDate')}</span><b>${fmtDate(b.ret.date)}</b></div>
        <div class="sum-row"><span>${t('tkSeats')}</span><b>${seatLabelJoin(b.ret.seats, rr.type, b.ret.bookingType)}</b></div>
        <div class="sum-row"><span>${t('tkBoard')}</span><b>${esc(bpShort(b.ret.boarding))}</b></div>
        <div class="sum-row"><span>${t('tkDrop')}</span><b>${esc(bpShort(b.ret.drop))}</b></div>
      </div>
    </div>` : '';

  const crewBits = [];
  if (r.crewName) crewBits.push('<b>' + esc(r.crewName) + '</b> · <a href="tel:' + esc(digits(r.crewPhone)) + '">' + esc(r.crewPhone || '') + '</a>' + (b.ret ? ' <small>(' + t('tkOut') + ')</small>' : ''));
  if (rr && rr.crewName && (rr.crewName !== r.crewName || rr.crewPhone !== r.crewPhone)) {
    crewBits.push('<b>' + esc(rr.crewName) + '</b> · <a href="tel:' + esc(digits(rr.crewPhone)) + '">' + esc(rr.crewPhone || '') + '</a> <small>(' + t('tkRet') + ')</small>');
  }
  const crewBox = crewBits.length ? '<div class="crew-box">👨‍✈️ <div><small>' + t('tkCrew') + '</small>' + crewBits.join('<br>') + '</div></div>' : '';

  /* Delay banner — pull-based: admin publishes {routeId, date, delayMinutes}
     in Live Ops; any matching confirmed booking shows it here. */
  const delay = (b.status === 'confirmed') ? (DB.delays || []).find(d => d.routeId === b.routeId && d.date === b.date) : null;
  const delayBanner = delay
    ? '<div class="verify-note" style="margin:0 0 14px;background:#FFF3E2;border-color:#F3D9B0">⏰ <span><b>'
      + tf('delayLine', { m: delay.delayMinutes }) + '</b>' + (delay.note ? ' ' + esc(delay.note) : '') + '</span></div>'
    : '';

  /* Bikram Sambat alongside the AD date — see nepaliBS() in 02-config.js.
     Empty string outside the BS table, so the strip degrades to AD-only. */
  const bsHero = (typeof nepaliBSFull === 'function') ? nepaliBSFull(b.date) : '';
  const heroStrip = b.status === 'confirmed' ? '<div class="status-hero-strip"><span class="sh-pnr">' + esc(b.id) + '</span><span class="sh-route">' + esc(parseBP(b.boarding || '').name || r.from || '') + ' → ' + esc(parseBP(b.drop || '').name || r.to || '') + '</span><span class="sh-date">' + nepaliDateFull(b.date) + (bsHero ? ' <small>· ' + esc(bsHero) + '</small>' : '') + '</span><span class="sh-total">' + inr(b.total) + '</span></div>' : '';
  /* ---- TICKET v2 (4 Sep 2026): simple, modern ------------------------
     One card: brand + status pill, route hero, QR beside the four things a
     passenger needs, three primary actions, everything else under "More".
     Every element id the handlers below bind to is kept (#ticketCard,
     #tkQrBox, #pdfBtn, #imgBtn, #shareBtn, #printBtn, #waBtn, #refreshBtn,
     #cancelBtn, #wxBox, .status-actions for the reminder button). */
  /* Route hero reads the passenger's own boarding + drop (stopDisplay, 02-config). */
  const fromD2 = stopDisplay(b.boarding || '', r.from), toD2 = stopDisplay(b.drop || '', r.to);
  const isCod2 = !!(b.codFlag || (b.payment && b.payment.method === 'cod'));
  const conf = b.status === 'confirmed';
  const soon = (b.status === 'pending' || conf) && depTimestamp(b) > Date.now();
  let pill2;
  if (conf) pill2 = '<span class="tk2-pill ok">' + esc(t('stConfT')) + '</span>';
  else if (b.status === 'pending') pill2 = '<span class="tk2-pill pend">' + (isCod2 ? '💵 ' : '⏳ ') + esc(t('stPendT')) + '</span>';
  else if (b.status === 'cancelled') pill2 = '<span class="tk2-pill off">✕ ' + esc(t('stCancT')) + '</span>';
  else if (b.status === 'expired' || b.status === 'completed') pill2 = '<span class="tk2-pill off">⏱ ' + esc(t('stExpT')) + '</span>';
  else pill2 = '<span class="tk2-pill bad">! ' + esc(t('stRejT')) + '</span>';
  let note2 = '';
  if (b.status === 'pending' && !isCod2) note2 = '<div class="tk2-note wait">⏳ ' + tf('stPendP', { s: seatLabelJoin(b.seats, r.type, b.bookingType) }) + '</div>';
  else if (b.status === 'pending' && isCod2) note2 = '<div class="tk2-note cash">💵 ' + esc(seatLabelJoin(b.seats, r.type, b.bookingType)) + ' · ' + t('codCashNote') + '</div>';
  else if (conf && isCod2) note2 = '<div class="tk2-note cash">' + t('codCashNote') + '</div>';
  else if (b.status === 'cancelled') note2 = '<div class="tk2-note">' + t('stCancP') + (b.refundNote ? ' ' + esc(b.refundNote) : '') + '</div>';
  else if (b.status === 'expired' || b.status === 'completed') note2 = '<div class="tk2-note">' + t('stExpP') + ' — ' + t('supportLbl') + ': ' + esc(S().phone) + '</div>';
  else if (!conf) note2 = '<div class="tk2-note">' + esc(b.payment.reason || 'We could not verify this payment.') + ' — ' + t('supportLbl') + ': ' + esc(S().phone) + '</div>';
  /* Did the office's WhatsApp ticket reach the passenger? (17 Sep 2026.) Known
     once the ticket has been read back from track.php (b.wa defined); a
     confirmed ticket that has never synced asks the server once. */
  if (conf) {
    if (b.wa === undefined) {
      if (!b._waSync && typeof syncBooking === 'function') {
        b._waSync = true;
        try { syncBooking(b).then(function () { if (b.wa !== undefined && location.hash === '#/ticket/' + b.id) renderStatus(b.id); }).catch(function () {}); } catch (e) {}
      }
    } else if (b.wa && b.wa.ok) {
      note2 += '<div class="tk2-note wa ok">📲 ' + tf('tkWaSent', { n: esc(b.wa.last4 || '') }) + '</div>';
    } else if (b.wa && !b.wa.ok) {
      note2 += '<div class="tk2-note wa warn">⚠️ ' + tf('tkWaFailed', { n: esc(b.wa.last4 || '') }) + '</div>';
    } else if (digits((b.contact && b.contact.phone) || '')) {
      note2 += '<div class="tk2-note wa">📲 ' + t('tkWaPending') + '</div>';
    }
  }
  const bp2 = parseBP(b.boarding || '');
  const bpNp2 = bp2.time ? nepaliTimeFull(bp2.time) : '';
  const lead2 = (b.passengers || [])[0] || {};
  const paxN2 = (b.seats || []).length;
  const rate2 = b.farePerSeat || (paxN2 ? Math.round((b.baseTotal || b.total) / paxN2) : 0);
  const fareSub2 = (paxN2 > 1 && rate2 > 0 ? tf('rowPaxFare', { n: paxN2, f: inr(rate2) }) + ' · ' : '') + esc(payRefLabel(b));
  /* PWA layer (13 Sep 2026, 17-pwa.js): the Border Crossing Prep card for an
     India → Nepal ticket (opens itself 24 h before departure), the delay-alert
     push card, and the split-pay button on a pending group booking. Each is a
     guarded string — a missing 17-pwa.js leaves the ticket exactly as before.
     Declared BEFORE primary2: a pending ticket reads splitBtn there. */
  let borderCard = '', pushCard = '', splitBtn = '';
  try { if (window.SHGBorder) borderCard = window.SHGBorder.card(b) || ''; } catch (e) {}
  try { if (window.SHGPush) pushCard = window.SHGPush.card(b) || ''; } catch (e) {}
  try { if (window.SHGSplit) splitBtn = window.SHGSplit.button(b) || ''; } catch (e) {}
  const primary2 = conf
    ? '<button class="btn btn-orange" id="imgBtn" type="button">' + t('btnImg') + '</button>'
      + '<button class="btn btn-wa" id="waBtn" type="button">🟢 ' + t('btnWa') + '</button>'
      + '<a class="btn btn-blue" href="#/trip/' + esc(b.id) + '">' + t('tkTrackBtn') + '</a>'
      /* Return journey as its own ticket (17 Sep 2026): the engine books one
         leg per ticket (round_trip_on gate), so this starts a fresh search in
         the opposite direction with the same lead passenger — rebookFrom(id, true). */
      + '<button class="btn btn-ghost tk2-wide" id="retBookBtn" type="button">' + t('btnRetBook') + '</button>'
    : (b.status === 'pending' ? '<button class="btn btn-blue" id="refreshBtn" type="button">' + t('btnRefresh') + '</button>' + splitBtn : '')
      + (canCancel(b) ? '<button class="btn btn-danger-ghost" id="cancelBtn" type="button">' + t('btnCancel') + '</button>' : '')
      + '<a class="btn btn-orange" href="#/">' + t('tkNewTicket') + '</a>';
  const secondary2 = (conf ? '<button class="btn btn-ghost" id="pdfBtn" type="button">' + t('btnPdf') + '</button>'
      + (soon ? '<button class="btn btn-ghost" type="button" data-ics="' + esc(b.id) + '">' + t('btnCal') + '</button>' : '')
      + '<button class="btn btn-ghost" id="printBtn" type="button">' + t('btnPrint') + '</button>'
      + ((typeof navigator.share === 'function') ? '<button class="btn btn-ghost" id="shareBtn" type="button">' + t('btnShare') + '</button>' : '')
      + '<a class="btn btn-ghost" href="#/trip/' + esc(b.id) + '">🚌 Trip Companion</a>'
      + (canCancel(b) ? '<button class="btn btn-danger-ghost" id="cancelBtn" type="button">' + t('btnCancel') + '</button>' : '')
      + '<a class="btn btn-ghost" href="#/">' + t('tkNewTicket') + '</a>' : '')
    + '<a class="btn btn-ghost" href="#/">' + t('btnHome') + '</a>';
  const pax2 = (lead2.name ? esc(lead2.name) : '—') + (lead2.special ? ' <span class="chip" style="font-size:11px;padding:2px 8px">' + esc(t('patientTag')) + '</span>' : '');
  const phone2 = digits((b.contact && b.contact.phone) || '');
  body.innerHTML = `
  ${delayBanner}
  ${borderCard}
  ${stepper}
  <div class="status-card tk2${conf ? ' confirm-success' : ''}" id="ticketCard">
    <div class="tk2-head">
      <div class="tk2-brand">
        <img src="/assets/img/logo.png?v=20260920a" alt="" loading="lazy" decoding="async">
        <div><b>S HARI GLOBAL PVT LTD</b><small>${esc(t('tkEticket'))} · ${esc(t('tkServiceLine'))}</small></div>
      </div>
      ${pill2}
    </div>
    ${(typeof routeOverviewSVG === 'function') ? routeOverviewSVG({ from: (isNepalPoint(r.from) ? r.from : (parseBP(b.boarding || '').name || r.from)), to: (isNepalPoint(r.to) ? r.to : (parseBP(b.drop || '').name || r.to)), compact: true }) : ''}
    <div class="tk2-hero">
      <div class="tk2-pnr"><span>${esc(b.id)}</span><small>${esc(r.busName || '')}${r.busNo ? ' · ' + esc(r.busNo) : ''}</small></div>
      <div class="tk2-route">
        <div class="tk2-city"><b>${esc(fromD2.code)}</b><span>${esc(fromD2.name || r.from || '—')}</span><small>${esc(t('tkDep'))} ${esc(fromD2.time || r.depTime || '')} IST</small></div>
        <div class="tk2-arrow"><span>🚌</span><i></i></div>
        <div class="tk2-city to"><b>${esc(toD2.code)}</b><span>${esc(toD2.name || r.to || '—')}</span><small>${esc(t('tkArr'))} ${esc(r.arrTime || '')}${r.dayOffset ? ' (+' + r.dayOffset + 'd)' : ''}</small></div>
      </div>
      <div class="tk2-date"><span>📅 ${nepaliDateFull(b.date)}</span><span>${esc(fmtDate(b.date))}${bsHero ? ' · ' + esc(bsHero) : ''}</span></div>
    </div>
    ${note2}
    <div class="tk2-ess">
      ${conf ? '<div class="tk2-qr" id="tkQrBox"><small>' + t('tkQrNote') + '</small></div>' : '<div class="tk2-qr" style="visibility:hidden"></div>'}
      <div class="tk2-tiles">
        <div class="tk2-tile wide"><small>📍 ${esc(t('tkBoard'))}</small><b>${esc(bp2.name || bpShort(b.boarding))}</b>${bp2.time ? '<em><span class="np">' + esc(bpNp2) + '</span> · ' + esc(bp2.time) + ' IST</em>' : ''}</div>
        <div class="tk2-tile"><small>💺 ${esc(t('tkSeats'))}</small><b>${esc(seatLabelJoin(b.seats, r.type, b.bookingType))}</b><em>${paxN2} ${paxN2 > 1 ? 'seats' : 'seat'}${b.bookingType === 'private' ? ' · 🔒 Private' : ''}</em></div>
        <div class="tk2-tile"><small>🏁 ${esc(t('tkDrop'))}</small><b>${esc(parseBP(b.drop || '').name || bpShort(b.drop) || '—')}</b></div>
        <div class="tk2-tile wide"><small>👤 ${esc(t('tkPaxLbl'))}</small><b>${pax2}</b>${phone2 ? '<em>+91 ' + esc(phone2) + (paxN2 > 1 ? ' · ' + paxN2 + ' ' + esc(t('coPax')) : '') + '</em>' : ''}</div>
      </div>
    </div>
    <div class="tk2-fare"><div><span class="lbl">${esc(t('tkFareT'))}</span><div class="amt">${inr(b.total)}</div></div><div class="sub">${fareSub2}</div></div>
    <div class="tk2-actions">${primary2}</div>
    <details class="tk2-more">
      <summary>${esc(t('tkMore'))}</summary>
      <div class="status-actions tk2-links">${secondary2}</div>
      <div class="tk-grid">
        <div class="sum-row"><span>${t('tkCoach')}</span><b>${esc(r.busName || '')} · ${esc(r.busNo || '')}</b></div>
        <div class="sum-row"><span>${t('tkPayRef')}</span><b style="font-family:var(--f-code)">${esc(payRefLabel(b))}</b></div>
        ${bookedByRow}
        <div class="sum-row"><span>${t('tkBoard')}</span><b>${esc(bpShort(b.boarding))}</b></div>
        <div class="sum-row"><span>${t('tkDrop')}</span><b>${esc(bpShort(b.drop))}</b></div>
        ${fareRows}
      </div>
      ${retBlock}
      <table class="pax-table"><thead><tr><th>${t('tkSeats')}</th><th>${t('coPax')}</th><th>${t('lblAge')}</th><th>${t('lblGender')}</th></tr></thead><tbody>${paxRows}</tbody></table>
      ${crewBox}
      ${soon ? distanceWidgetHTML(b) : ''}
      ${soon ? '<div class="wx-box" id="wxBox"><small>' + tf('wxTitle', { c: esc(parseBP(b.drop).name || '') }) + '</small><b>…</b></div>' : ''}
      ${conf ? checklistHTML() : ''}
      ${conf ? '<div class="verify-note" style="margin:12px 0 0;background:#E9F8EF;border-color:#BFE8CF">💾 <span>' + t('saveTicketNote') + '</span></div>' : ''}
      <div class="tk2-company"><b>🇮🇳 S HARI GLOBAL PVT LTD 🇳🇵</b> · ${esc(CONFIG.company.mantra)}<br>CIN: ${esc(CONFIG.company.cin)}${CONFIG.company.gstin ? ' · GSTIN: ' + esc(CONFIG.company.gstin) : ''} · CEO: ${esc(CONFIG.company.ceo || '')}<br>☎ ${t('supportLbl')}: <a href="tel:${esc(digits(S().phone))}">${esc(S().phone)}</a> · <a href="mailto:${esc(S().email)}">${esc(S().email)}</a></div>
    </details>
    ${conf ? '<div class="tk2-foot"><div class="bp-barcode">' + barcodeSVG(b.id) + '<span class="bp-bc-id">' + esc(b.id) + '</span></div><div class="tk2-thanks">' + t('tkThanks') + '</div></div>' : ''}
    ${extra}
  </div>
  ${pushCard}
  ${rateBox}`;

  if (b.status === 'confirmed') { var tc = $('#ticketCard'); if (tc) requestAnimationFrame(function () { tc.classList.add('confirm-anim'); }); }
  if ((b.status === 'pending' || b.status === 'confirmed') && depTimestamp(b) > Date.now()) loadDestinationWeather(b);
  if (b.status === 'confirmed') {
    makeQR(ticketQrPayload(b, r), 150).then(url => {
      if (!url) return;
      const box = $('#tkQrBox'); if (!box) return;
      const img = new Image(); img.src = url; img.width = 150; img.height = 150; img.alt = 'Ticket QR';
      box.insertBefore(img, box.firstChild);
    });
    $('#pdfBtn').onclick = () => downloadTicketPDF(b);
    /* §4 Auto-download the TICKET the first time a booking shows up
       confirmed — since 5 Sep 2026 that is the PNG image (opens straight
       in the gallery / WhatsApp; the PDF button stays for printing).
       Guarded by a persisted flag so it fires once per booking. */
    if (!b._autoDownloaded) {
      b._autoDownloaded = true;
      persist('bookings');
      toast(t('autoDlToast'));
      setTimeout(() => { try { downloadTicketImage(b); } catch (e) {} }, 250);   // quick: the toast and the file arrive together
    }
    /* Keep this ticket readable with no network. The border crossing at
       Rupaidiha is the whole reason: the passenger is asked for it in the
       one place their phone has no data. Fire-and-forget — the worker
       fetches and stores it, and everything still works if there is no
       service worker at all. */
    if (typeof cacheTicketOffline === 'function') { try { cacheTicketOffline(b); } catch (e) {} }

    try { wireRatingCard(b); } catch (e) {}

    $('#printBtn').onclick = () => window.print();
    const imgBtn = $('#imgBtn'); if (imgBtn) imgBtn.onclick = () => downloadTicketImage(b);
    const shareBtn = $('#shareBtn'); if (shareBtn) shareBtn.onclick = () => shareTicket(b);
    if (typeof window.wireBoardingReminder === 'function') window.wireBoardingReminder(b);
  }
  if (b.status === 'pending') {
    /* Refresh now ASKS the server (17 Sep 2026) — it used to only redraw the
       local copy — and the same ticket is re-read every 20 s while it stays
       pending and on screen (TicketPoll, 05-router.js), so an approval shows
       up here without navigating away and back. */
    const rfBtn = $('#refreshBtn');
    if (rfBtn) rfBtn.onclick = () => {
      rfBtn.disabled = true; rfBtn.classList.add('loading');
      syncBookings([id]).then((changed) => {
        renderStatus(id);
        if (!changed) toast(t('tStatusFresh'));
      }).catch(() => { renderStatus(id); });
    };
    if (typeof TicketPoll !== 'undefined') TicketPoll.start(id);
  } else if (typeof TicketPoll !== 'undefined') {
    TicketPoll.stop();
  }
  const rbk = $('#retBookBtn'); if (rbk) rbk.onclick = () => rebookFrom(id, true);
  const wb = $('#waBtn'); if (wb) wb.onclick = () => waShare(id);
  const cb = $('#cancelBtn'); if (cb) cb.onclick = () => openCancelModal(id);
  /* 17-pwa.js wiring (13 Sep 2026): border checklist ticks + print, push
     opt-in, split-pay modal. Each guarded — the ticket never depends on them. */
  try { if (window.SHGBorder) window.SHGBorder.wire(b); } catch (e) {}
  try { if (window.SHGPush) window.SHGPush.wire(b); } catch (e) {}
  try { if (window.SHGSplit) window.SHGSplit.wire(b); } catch (e) {}

  /* 🔔 Boarding reminder — browser Notification at departure − 90 min.
     In-session only (setTimeout, nothing persisted); every Notification
     API touch is guarded so unsupported browsers just see a toast. */
  const rb = $('#remindBtn');
  if (rb) rb.onclick = () => {
    try {
      if (!('Notification' in window)) { toast('⚠️ यो ब्राउजरमा सूचना छैन · इस ब्राउज़र में नोटिफिकेशन नहीं · notifications not supported.'); return; }
      const handlePerm = (perm) => {
        if (perm !== 'granted') { toast('⚠️ अनुमति अस्वीकृत — ब्राउजर सेटिङबाट अनुमति दिनुहोस् · ब्राउज़र सेटिंग से अनुमति दें · permission denied.'); return; }
        const dep = depTimestamp(b);
        const fireIn = dep - 90 * 60000 - Date.now();
        if (dep - Date.now() <= 12 * 3600000 && fireIn > 0) {
          setTimeout(() => {
            try { new Notification('🚌 Boarding soon — S Hari Global', { body: b.id + ' · Report 60 min early · ६० मिनेट अगाडि आउनुहोस् · ६० मिनट पहले पहुँचें' }); } catch (e2) {}
          }, fireIn);
          toast('🔔 Reminder set ✓ प्रस्थानभन्दा ९० मिनेट अगाडि सूचना आउँछ · प्रस्थान से 90 मिनट पहले सूचना आएगी (यो ट्याब खुला राख्नुहोस् · टैब खुला रखें)');
        } else {
          toast('🔔 Reminder on ✓ प्रस्थानको १२ घण्टाभित्र यो टिकट फेरि खोल्नुहोस् · प्रस्थान के 12 घंटे के भीतर यह टिकट फिर खोलें — reopen this ticket within 12h of departure.');
        }
      };
      const ret = Notification.requestPermission(handlePerm);
      if (ret && typeof ret.then === 'function') ret.then(handlePerm).catch(() => {});
    } catch (e) {}
  };
}
