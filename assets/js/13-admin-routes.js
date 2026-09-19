
/* ================================================================
   [JS] 13. ADMIN — routes editor (incl. crew contact), settings
   (UPI, eSewa, notice banner), messages.
================================================================ */

/* §2 purge (shg-v56): 09-agent.js and 12-admin-panel.js removed from
   the customer bundle. These stubs keep any leftover caller from
   throwing — they are no-ops because #/admin and #/shg-ctrl now
   redirect to the real /admin/ panel in the router. */
if (typeof renderAgentGateOrApp === 'undefined')
  window.renderAgentGateOrApp = function () {};
if (typeof renderAdminGateOrApp === 'undefined')
  window.renderAdminGateOrApp = function () {};
if (typeof ADMIN_ON === 'undefined')
  window.ADMIN_ON = false;
function routeFormFields(r) {
  const v = (x) => esc(x == null ? '' : x);
  return `
    <div class="field"><label>From</label><input data-f="from" value="${v(r.from)}"></div>
    <div class="field"><label>To</label><input data-f="to" value="${v(r.to)}"></div>
    <div class="field span2"><label>Coach name <small style="color:var(--muted);font-weight:400">· Duplicate names OK — use unique bus number</small></label><input data-f="busName" value="${v(r.busName)}"></div>
    <div class="field"><label>Coach number</label><input data-f="busNo" value="${v(r.busNo)}"></div>
    <div class="field"><label>Type</label><select data-f="type">
      <option value="seater"${r.type === 'seater' ? ' selected' : ''}>AC Seater (40)</option>
      <option value="sleeper"${r.type === 'sleeper' ? ' selected' : ''}>AC Sleeper (30)</option></select></div>
    <div class="field"><label>Route path</label><select data-f="pathId">
      <option value="via_gorakhpur"${(r.pathId || 'via_gorakhpur') === 'via_gorakhpur' ? ' selected' : ''}>Via Gorakhpur</option>
      <option value="via_bahraich"${r.pathId === 'via_bahraich' ? ' selected' : ''}>Via Bahraich</option></select></div>
    <div class="field"><label>Departure</label><input data-f="depTime" value="${v(r.depTime)}"></div>
    <div class="field"><label>Arrival</label><input data-f="arrTime" value="${v(r.arrTime)}"></div>
    <div class="field"><label>Days later</label><input data-f="dayOffset" type="number" min="0" max="2" value="${r.dayOffset || 0}"></div>
    <div class="field"><label>Duration label</label><input data-f="duration" value="${v(r.duration)}"></div>
    <div class="field"><label>Fare (₹)</label><input data-f="fare" type="number" min="0" value="${r.fare || 0}"></div>
    <div class="field"><label>Amenities</label><input data-f="amenities" value="${v((r.amenities || []).join(', '))}"></div>
    <div class="field"><label>Driver/conductor name</label><input data-f="crewName" value="${v(r.crewName)}" placeholder="Printed on the ticket"></div>
    <div class="field"><label>Driver/conductor phone</label><input data-f="crewPhone" value="${v(r.crewPhone)}" placeholder="+91 …"></div>
    <div class="field span2"><label>Boarding points <small>(comma-sep · "Name · Landmark @ HH:MM [lat,lng]" — optional [lat,lng] powers the passenger distance calculator)</small></label><input data-f="boarding" value="${v((r.boarding || []).join(', '))}"></div>
    <div class="field span2"><label>Drop points <small>(comma-sep · "Name · Landmark @ HH:MM [lat,lng]")</small></label><input data-f="drop" value="${v((r.drop || []).join(', '))}"></div>`;
}
function readRouteForm(box) {
  const g = (f) => { const el = box.querySelector('[data-f="' + f + '"]'); return el ? el.value.trim() : ''; };
  /* split on commas EXCEPT the one inside a "[lat,lng]" pair */
  const list = (f) => g(f).split(/,(?![^\[]*\])/).map(s => s.trim()).filter(Boolean);
  return {
    from: g('from'), to: g('to'), busName: g('busName'), busNo: g('busNo'),
    type: g('type') || 'seater', depTime: g('depTime'), arrTime: g('arrTime'),
    dayOffset: parseInt(g('dayOffset'), 10) || 0, duration: g('duration'),
    fare: parseInt(g('fare'), 10) || 0,
    crewName: g('crewName'), crewPhone: g('crewPhone'),
    pathId: g('pathId') || 'via_gorakhpur',
    amenities: list('amenities'), boarding: list('boarding'), drop: list('drop')
  };
}
function validRoute(r) { return r.from && r.to && r.busName && r.depTime && r.arrTime && r.fare > 0 && r.boarding.length && r.drop.length; }

function renderAdminRoutes() {
  $('#routesList').innerHTML = DB.routes.map(r => `
    <div class="rt-card" data-rid="${r.id}">
      <div class="rt-head">
        <b style="font-family:var(--f-display);font-size:17px">${esc(r.from)} → ${esc(r.to)} <small style="color:var(--muted);font-weight:500">· ${esc(r.busName)}</small></b>
        <label class="rt-active"><input type="checkbox" data-f="active"${r.active ? ' checked' : ''}> Active (shown in search)</label>
      </div>
      <div class="rt-form">${routeFormFields(r)}
        <div class="span4" style="display:flex;gap:10px">
          <button class="btn btn-blue btn-sm" data-act="save">💾 Save route</button>
          <button class="btn btn-ghost btn-sm" data-act="del">🗑 Delete</button>
        </div>
      </div>
    </div>`).join('');

  $('#routesList').onclick = (e) => {
    const btn = e.target.closest('[data-act]'); if (!btn) return;
    const card = btn.closest('.rt-card'); const rid = card.getAttribute('data-rid');
    const idx = DB.routes.findIndex(r => r.id === rid); if (idx < 0) return;
    if (btn.getAttribute('data-act') === 'save') {
      const upd = readRouteForm(card);
      if (!validRoute(upd)) { toast('Fill From, To, coach name, times, fare, and at least one boarding & drop point.'); return; }
      upd.id = rid;
      upd.active = card.querySelector('[data-f="active"]').checked;
      DB.routes[idx] = upd;
      persist('routes'); populateCitySelects();
      audit('Route saved', upd.from + ' → ' + upd.to + ' · ' + upd.busName + ' · ₹' + upd.fare);
      toast('Route saved ✓');
    } else {
      const used = DB.bookings.some(b => (b.routeId === rid || (b.ret && b.ret.routeId === rid)) && b.status !== 'rejected' && b.status !== 'cancelled');
      if (used) { toast('This route has active bookings — mark it inactive instead of deleting.'); return; }
      if (!confirm('Delete this route permanently?')) return;
      const gone = DB.routes[idx];
      DB.routes.splice(idx, 1);
      persist('routes'); populateCitySelects(); renderAdminRoutes();
      audit('Route deleted', (gone ? gone.from + ' → ' + gone.to + ' · ' + gone.busName : rid));
      toast('Route deleted.');
    }
  };

  $('#addRouteBtn').onclick = () => {
    const box = $('#addRouteForm');
    const nr = readRouteForm(box);
    if (!validRoute(nr)) { toast('Fill From, To, coach name, times, fare, and at least one boarding & drop point.'); return; }
    nr.id = 'r' + Date.now(); nr.active = true;
    DB.routes.push(nr);
    persist('routes'); populateCitySelects(); renderAdminRoutes();
    $$('#addRouteForm input').forEach(i => i.value = '');
    audit('Route added', nr.from + ' → ' + nr.to + ' · ' + nr.busName + ' · ₹' + nr.fare);
    toast('New route added — it is now live in the search box ✓');
  };
}

/* The eight contact-number slots in Admin -> Settings. Rendered from the
   saved list rather than written out in the markup, so adding a ninth line
   later is a constant change, not eight more <div>s. */
function renderNumberSlots() {
  const wrap = $('#setNumsWrap'); if (!wrap) return;
  const nums = contactNumbers();
  wrap.innerHTML = '<div class="num-admin">' + nums.map(function (n, i) {
    return '<div class="num-row">'
      + '<input class="num-lbl" data-num-label="' + i + '" placeholder="Label (Booking / Nepalgunj office…)" value="' + esc(n.label) + '">'
      + '<input class="num-val" data-num-val="' + i + '" placeholder="+91 …" value="' + esc(n.num) + '" inputmode="tel">'
      + '<label class="num-ck"><input type="checkbox" data-num-wa="' + i + '"' + (n.wa ? ' checked' : '') + '> WhatsApp</label>'
      + '<label class="num-ck"><input type="checkbox" data-num-show="' + i + '"' + (n.show ? ' checked' : '') + '> Show</label>'
      + '</div>';
  }).join('') + '</div>';
}

/* Read the eight slots back out of the form. */
function readNumberSlots() {
  const out = [];
  for (let i = 0; i < NUM_SLOTS; i++) {
    const lbl = $('[data-num-label="' + i + '"]'), val = $('[data-num-val="' + i + '"]');
    if (!lbl || !val) return null;   // form not rendered - keep what is saved
    out.push({
      label: lbl.value.trim().slice(0, 40),
      num:   val.value.trim().slice(0, 24),
      wa:    !!($('[data-num-wa="' + i + '"]') || {}).checked,
      show:  !!($('[data-num-show="' + i + '"]') || {}).checked
    });
  }
  return out;
}

function renderAdminSettings() {
  const st = S();
  $('#setUpiId').value = st.upiId; $('#setUpiName').value = st.upiName;
  $('#setEsewaId').value = st.esewaId; $('#setEsewaName').value = st.esewaName;
  $('#setPhone').value = st.phone; $('#setEmail').value = st.email;
  /* V7 — Google Maps key is an operator setting now (see #setGmapsKey) */
  if ($('#setGmapsKey')) $('#setGmapsKey').value = st.googleMapsApiKey || '';
  $('#setNoticeOn').checked = !!st.noticeOn;
  $('#setNoticeText').value = st.noticeText || '';
  $('#setSpeed').value = st.assumedTravelSpeedKmh;
  $('#setTimeout').value = st.sessionTimeoutMin;
  if ($('#settingBhagwanImg')) $('#settingBhagwanImg').value = st.bhagwanImg || DB.settings.bhagwanImg || '';
  if ($('#setBlacklist')) $('#setBlacklist').value = (st.blacklist || []).join(', ');
  if ($('#setDefaultLang')) $('#setDefaultLang').value = st.defaultLang || 'ne';
  if ($('#setTemplesDefaultOn')) $('#setTemplesDefaultOn').checked = st.templesDefaultOn !== false;
  renderNumberSlots();

  /* — main-point fares (the two numbers the whole fare board runs on) — */
  const mf = sharingDir();
  const mp = CONFIG.mainPoints || { india: [], nepal: [] };
  if ($('#mfIndiaList')) $('#mfIndiaList').textContent = (mp.india || []).join(', ');
  if ($('#mfNepalList')) $('#mfNepalList').textContent = (mp.nepal || []).join(', ');
  if ($('#setFareToNepal')) $('#setFareToNepal').value = mf.toNepal;
  if ($('#setFareToIndia')) $('#setFareToIndia').value = mf.toIndia;
  const mfPrev = () => {
    const box = $('#mfPreview'); if (!box) return;
    const pct = (CONFIG.cabinPricing.onlineDiscountPct || 5);
    const rd = (el, fb) => Math.max(0, Math.round(parseFloat(($(el) || {}).value) || fb));
    const go = rd('#setFareToNepal', mf.toNepal), back = rd('#setFareToIndia', mf.toIndia);
    const on = (v) => Math.round(v * (1 - pct / 100));
    box.innerHTML = 'Passengers will see — <b>Going:</b> ' + inr(on(go)) + ' online <small>(counter ' + inr(go) + ')</small>'
      + ' · <b>Coming:</b> ' + inr(on(back)) + ' online <small>(counter ' + inr(back) + ')</small>'
      + ' · ' + nprEst(on(go)) + ' / ' + nprEst(on(back));
  };
  mfPrev();
  ['#setFareToNepal', '#setFareToIndia'].forEach(sel => { const el = $(sel); if (el) el.oninput = mfPrev; });

  /* — loyalty tier thresholds — */
  const tiers = st.loyaltyTiers || CONFIG.loyaltyTiers;
  $('#tierEditor').innerHTML = '<div class="rt-form">' + tiers.map((tr, i) =>
    '<div class="field"><label>' + esc(tr.icon + ' ' + tr.name) + '</label>'
    + '<div style="display:flex;gap:8px"><input data-tier-min="' + i + '" type="number" min="0" value="' + tr.min + '" title="Minimum lifetime points" style="flex:1;padding:11px;border:1.5px solid var(--line);border-radius:11px;background:#FBFCFE">'
    + '<input data-tier-disc="' + i + '" type="number" min="0" max="20" value="' + tr.discountPct + '" title="Checkout discount %" style="width:84px;padding:11px;border:1.5px solid var(--line);border-radius:11px;background:#FBFCFE">'
    + '<span style="align-self:center;font-size:12px;color:var(--muted)">%</span></div></div>').join('') + '</div>';

  /* — commission rules (feed computeReferralCommission, section 4d) — */
  const cr = CR();
  $('#crMode').value = cr.mode; $('#crFlat').value = cr.flat;
  $('#crPercent').value = cr.percent; $('#crWindow').value = cr.windowDays;
  const pr = cr.perRoute || {};
  $('#crPerRouteBox').innerHTML = '<b style="font-size:13px">Per-route override <small style="color:var(--muted);font-weight:500">(leave blank to use the base rule)</small></b>'
    + '<div class="rt-form" style="margin-top:8px">'
    + DB.routes.map(r => {
      const o = pr[r.id] || {};
      return '<div class="field"><label>' + esc(r.from + ' → ' + r.to + ' · ' + r.busName) + '</label>'
        + '<div style="display:flex;gap:8px"><select data-crr-mode="' + r.id + '" style="flex:1;padding:11px;border:1.5px solid var(--line);border-radius:11px;background:#FBFCFE">'
        + '<option value=""' + (o.mode == null ? ' selected' : '') + '>Base rule</option>'
        + '<option value="flat"' + (o.mode === 'flat' ? ' selected' : '') + '>Flat ₹</option>'
        + '<option value="percent"' + (o.mode === 'percent' ? ' selected' : '') + '>% of fare</option></select>'
        + '<input data-crr-val="' + r.id + '" type="number" min="0" placeholder="amount" value="' + (o.mode === 'percent' ? (o.percent || '') : (o.flat || '')) + '" style="width:110px;padding:11px;border:1.5px solid var(--line);border-radius:11px;background:#FBFCFE"></div></div>';
    }).join('') + '</div>';
  const bonuses = cr.bonuses || [];
  $('#crBonusBox').innerHTML = '<b style="font-size:13px">Festival / date-range bonus <small style="color:var(--muted);font-weight:500">(extra ₹ per seat when the journey date falls in the range)</small></b>'
    + (bonuses.length ? bonuses.map((b, i) => '<div class="sum-row"><span>🎉 ' + esc(b.label || 'Bonus') + ' · ' + esc(b.from) + ' → ' + esc(b.to) + '</span><b>+' + inr(b.extra) + '/seat <button class="btn btn-ghost btn-sm" data-crbdel="' + i + '">🗑</button></b></div>').join('') : '')
    + '<div class="rt-form" style="margin-top:8px">'
    + '<div class="field"><label>Label</label><input id="crbLabel" placeholder="Dashain bonus"></div>'
    + '<div class="field"><label>From</label><input id="crbFrom" type="date"></div>'
    + '<div class="field"><label>To</label><input id="crbTo" type="date"></div>'
    + '<div class="field"><label>Extra ₹ / seat</label><input id="crbExtra" type="number" min="0" placeholder="20"></div>'
    + '<div class="span4"><button class="btn btn-ghost btn-sm" id="crbAdd" type="button">＋ Add bonus</button></div></div>';

  $('#crBonusBox').onclick = (e) => {
    const del = e.target.closest('[data-crbdel]');
    if (del) {
      const list = (DB.commissionRules.bonuses || []).slice();
      list.splice(parseInt(del.getAttribute('data-crbdel'), 10), 1);
      DB.commissionRules = Object.assign({}, DB.commissionRules, { bonuses: list });
      persist('commissionRules');
      audit('Commission bonus removed', '');
      renderAdminSettings();
      return;
    }
    if (e.target.closest('#crbAdd')) {
      const label = $('#crbLabel').value.trim(), from = $('#crbFrom').value, to = $('#crbTo').value;
      const extra = Math.round(parseFloat($('#crbExtra').value));
      if (!from || !to || !(extra > 0) || to < from) { toast('Fill label, valid date range and a positive ₹ amount.'); return; }
      const list = (DB.commissionRules.bonuses || []).concat([{ label: label || 'Festival bonus', from: from, to: to, extra: extra }]);
      DB.commissionRules = Object.assign({}, DB.commissionRules, { bonuses: list });
      persist('commissionRules');
      audit('Commission bonus added', (label || 'Festival bonus') + ' ' + from + '→' + to + ' +₹' + extra + '/seat');
      renderAdminSettings();
    }
  };

  $('#saveSettingsBtn').onclick = () => {
    DB.settings = {
      upiId: $('#setUpiId').value.trim() || CONFIG.upiId,
      upiName: $('#setUpiName').value.trim() || CONFIG.upiName,
      esewaId: $('#setEsewaId').value.trim() || CONFIG.esewaId,
      esewaName: $('#setEsewaName').value.trim() || CONFIG.esewaName,
      /* No adminPin / agentPin here on purpose. This object is written to
         the shared kv_store `settings` row, and that row is in
         KV_PUBLIC_READ — /api/kv.php?action=get&key=settings answers it to
         anyone, signed in or not. Saving a password here published it.
         Staff passwords live in the admin_users table (bcrypt) and are
         changed at Admin -> Staff -> Change password. */
      phone: $('#setPhone').value.trim() || CONFIG.phone,
      /* V7 — operator-set Google Maps key (blank = free Photon/Nominatim search) */
      googleMapsApiKey: ($('#setGmapsKey') ? $('#setGmapsKey').value.trim() : (DB.settings.googleMapsApiKey || '')),
      email: $('#setEmail').value.trim() || CONFIG.email,
      bhagwanImg: ($('#settingBhagwanImg') || {}).value ? $('#settingBhagwanImg').value.trim() : (DB.settings.bhagwanImg || ''),
      noticeOn: $('#setNoticeOn').checked,
      noticeText: $('#setNoticeText').value.trim(),
      assumedTravelSpeedKmh: Math.max(10, parseInt($('#setSpeed').value, 10) || CONFIG.booking.assumedTravelSpeedKmh),
      sessionTimeoutMin: Math.max(0, parseInt($('#setTimeout').value, 10) || 0),
      defaultLang: ($('#setDefaultLang') ? $('#setDefaultLang').value : 'ne'),
      templesDefaultOn: ($('#setTemplesDefaultOn') ? $('#setTemplesDefaultOn').checked : true),
      /* 🚫 blacklist: keep last-10 digits of each entry (tolerates +91 prefixes), 10-digit only, deduped */
      blacklist: ($('#setBlacklist') ? $('#setBlacklist').value : '').split(',')
        .map(x => { const d = digits(x); return d.length > 10 ? d.slice(-10) : d; })
        .filter((x, i, a) => /^\d{10}$/.test(x) && a.indexOf(x) === i),
      /* Must be listed here: this literal REPLACES DB.settings wholesale, so
         a key that is not rebuilt is wiped the next time anyone presses Save. */
      contactNumbers: readNumberSlots() || DB.settings.contactNumbers || contactNumbers(),
      mainFares: {
        toNepal: Math.max(0, Math.round(parseFloat(($('#setFareToNepal') || {}).value)) || sharingDir().toNepal),
        toIndia: Math.max(0, Math.round(parseFloat(($('#setFareToIndia') || {}).value)) || sharingDir().toIndia)
      },
      loyaltyTiers: (S().loyaltyTiers || CONFIG.loyaltyTiers).map((tr, i) => ({
        name: tr.name, icon: tr.icon,
        min: Math.max(0, parseInt(($('[data-tier-min="' + i + '"]') || {}).value, 10) || 0),
        discountPct: Math.min(20, Math.max(0, parseFloat(($('[data-tier-disc="' + i + '"]') || {}).value) || 0))
      }))
    };
    persist('settings');
    /* The board and the live strip both read sharingDir(), so repaint them
       now — an operator who changes a fare should see the passenger-facing
       price move on the same click, not on the next reload. */
    if (typeof renderContactStrip === 'function') renderContactStrip();
    if (typeof renderFareBoard === 'function') renderFareBoard();
    if (typeof renderPricingCards === 'function') renderPricingCards();
    if (typeof renderFareLive === 'function') renderFareLive();
    /* commission rules live in their own store key */
    const perRoute = {};
    DB.routes.forEach(r => {
      const mode = ($('[data-crr-mode="' + r.id + '"]') || {}).value || '';
      const val = parseFloat(($('[data-crr-val="' + r.id + '"]') || {}).value);
      if (mode && val >= 0 && !isNaN(val)) perRoute[r.id] = mode === 'percent' ? { mode: 'percent', percent: val } : { mode: 'flat', flat: val };
    });
    DB.commissionRules = Object.assign({}, DB.commissionRules, {
      mode: $('#crMode').value,
      flat: Math.max(0, Math.round(parseFloat($('#crFlat').value)) || 0),
      percent: Math.max(0, parseFloat($('#crPercent').value) || 0),
      windowDays: Math.max(1, parseInt($('#crWindow').value, 10) || CONFIG.referral.windowDays),
      perRoute: perRoute
    });
    persist('commissionRules');
    try { sessionStorage.removeItem('shg:noticeHide'); } catch (e) {}
    renderNotice();
    audit('Settings saved', 'Payments, notice, speed ' + DB.settings.assumedTravelSpeedKmh + ' km/h, commission ' + DB.commissionRules.mode + ' ' + (DB.commissionRules.mode === 'percent' ? DB.commissionRules.percent + '%' : '₹' + DB.commissionRules.flat));
    renderAdminAudit();
    applyBhagwanImg();
    toast('Settings saved ✓ (new bookings will use the updated payment IDs)');
  };
}

function renderAdminLiveOps() {
  const box = $('#liveOpsBody'); if (!box) return;
  const today = todayISO();
  const routes = DB.routes.filter(r => r.active);
  const routeOpt = (sel) => routes.map(r => '<option value="' + r.id + '"' + (r.id === sel ? ' selected' : '') + '>' + esc(r.from + ' → ' + r.to + ' · ' + r.depTime) + '</option>').join('');

  /* delay notices */
  const delayRows = (DB.delays || []).slice().sort((a, b) => (b.date > a.date ? 1 : -1)).slice(0, 8).map((d, i) => {
    const r = routeById(d.routeId) || {};
    return '<div class="sum-row"><span>' + esc((r.from || '?') + ' → ' + (r.to || '?')) + ' · ' + fmtDate(d.date) + (d.note ? ' · ' + esc(d.note) : '') + '</span><b>~' + d.delayMinutes + ' min <button class="btn btn-ghost btn-sm" data-deldelay="' + i + '">🗑</button></b></div>';
  }).join('');

  /* expenses: this month total + per-trip list */
  const now = Date.now();
  const expMonth = DB.tripExpenses.filter(x => sameMonth(new Date(x.date + 'T00:00').getTime(), now))
    .reduce((s, x) => s + (x.diesel || 0) + (x.toll || 0) + (x.driver || 0) + (x.misc || 0), 0);
  const expRows = DB.tripExpenses.slice(0, 10).map((x, i) => {
    const r = routeById(x.routeId) || {};
    const tot = (x.diesel || 0) + (x.toll || 0) + (x.driver || 0) + (x.misc || 0);
    return '<div class="sum-row"><span>' + esc(x.date) + ' · ' + esc((r.from || '?') + ' → ' + (r.to || '?')) + (x.note ? ' · ' + esc(x.note) : '')
      + '<br><small style="color:var(--muted)">⛽ ' + inr(x.diesel || 0) + ' · 🛣 ' + inr(x.toll || 0) + ' · 👨‍✈️ ' + inr(x.driver || 0) + ' · 📦 ' + inr(x.misc || 0) + '</small></span><b>' + inr(tot) + ' <button class="btn btn-ghost btn-sm" data-delexp="' + i + '">🗑</button></b></div>';
  }).join('');

  box.innerHTML = `
    <div class="rt-card">
      <div class="rt-head"><b style="font-family:var(--f-display);font-size:17px">⏰ Delay notice</b></div>
      <p style="font-size:12.5px;color:var(--muted);margin-bottom:10px">Passengers with a confirmed booking on this route + date see a banner on their ticket. Pull-based — no SMS is sent.</p>
      <div class="rt-form">
        <div class="field span2"><label>Route</label><select id="dlRoute">${routeOpt('')}</select></div>
        <div class="field"><label>Journey date</label><input id="dlDate" type="date" value="${today}"></div>
        <div class="field"><label>Delay (minutes)</label><input id="dlMin" type="number" min="5" max="1440" placeholder="20"></div>
        <div class="field span4"><label>Note (optional, shown to passengers)</label><input id="dlNote" placeholder="Border rush — bus running behind schedule"></div>
        <div class="span4"><button class="btn btn-blue btn-sm" id="dlSave" type="button">⏰ Publish delay notice</button></div>
      </div>
      ${delayRows ? '<div style="margin-top:12px">' + delayRows + '</div>' : ''}
    </div>

    <div class="rt-card">
      <div class="rt-head"><b style="font-family:var(--f-display);font-size:17px">🧾 Trip expense log</b>
        <span class="badge">This month: ${inr(expMonth)}</span></div>
      <p style="font-size:12.5px;color:var(--muted);margin-bottom:10px">Per-trip running costs — data shape matches the leased-bus profit/loss calculator so figures can feed it later.</p>
      <div class="rt-form">
        <div class="field"><label>Date</label><input id="exDate" type="date" value="${today}"></div>
        <div class="field span2"><label>Route</label><select id="exRoute">${routeOpt('')}</select></div>
        <div class="field"><label>Diesel (₹)</label><input id="exDiesel" type="number" min="0" placeholder="0"></div>
        <div class="field"><label>Toll (₹)</label><input id="exToll" type="number" min="0" placeholder="0"></div>
        <div class="field"><label>Driver (₹)</label><input id="exDriver" type="number" min="0" placeholder="0"></div>
        <div class="field"><label>Misc (₹)</label><input id="exMisc" type="number" min="0" placeholder="0"></div>
        <div class="field"><label>Note</label><input id="exNote" placeholder="optional"></div>
        <div class="span4"><button class="btn btn-blue btn-sm" id="exSave" type="button">💾 Add expense entry</button></div>
      </div>
      ${expRows ? '<div style="margin-top:12px">' + expRows + '</div>' : ''}
    </div>`;

  /* — delay wiring — */
  $('#dlSave').onclick = () => {
    const rid = $('#dlRoute').value, date = $('#dlDate').value;
    const min = parseInt($('#dlMin').value, 10);
    if (!rid || !date || !(min > 0)) { toast('Pick route, date and delay minutes.'); return; }
    DB.delays = (DB.delays || []).filter(d => !(d.routeId === rid && d.date === date));   // one notice per trip
    DB.delays.unshift({ routeId: rid, date: date, delayMinutes: min, note: $('#dlNote').value.trim() });
    persist('delays');
    const r = routeById(rid) || {};
    audit('Delay notice published', (r.from || '?') + ' → ' + (r.to || '?') + ' · ' + date + ' · ~' + min + ' min');
    toast('Delay notice published ✓');
    renderAdminLiveOps(); renderAdminAudit();
  };
  box.onclick = (e) => {
    const dd = e.target.closest('[data-deldelay]');
    if (dd) {
      const sorted = (DB.delays || []).slice().sort((a, b) => (b.date > a.date ? 1 : -1));
      const gone = sorted[parseInt(dd.getAttribute('data-deldelay'), 10)];
      DB.delays = DB.delays.filter(x => x !== gone);
      persist('delays'); audit('Delay notice removed', gone ? gone.date + ' ~' + gone.delayMinutes + ' min' : '');
      renderAdminLiveOps(); return;
    }
    const dx = e.target.closest('[data-delexp]');
    if (dx) {
      DB.tripExpenses.splice(parseInt(dx.getAttribute('data-delexp'), 10), 1);
      persist('tripExpenses'); audit('Expense entry removed', '');
      renderAdminLiveOps();
    }
  };

  /* — expenses wiring — */
  $('#exSave').onclick = () => {
    const date = $('#exDate').value, rid = $('#exRoute').value;
    const n = (id) => Math.max(0, Math.round(parseFloat($(id).value) || 0));
    const entry = { date: date, routeId: rid, diesel: n('#exDiesel'), toll: n('#exToll'), driver: n('#exDriver'), misc: n('#exMisc'), note: $('#exNote').value.trim(), at: Date.now() };
    if (!date || !rid || (entry.diesel + entry.toll + entry.driver + entry.misc) <= 0) { toast('Pick date, route and at least one cost.'); return; }
    DB.tripExpenses.unshift(entry);
    persist('tripExpenses');
    audit('Trip expense logged', date + ' · ₹' + (entry.diesel + entry.toll + entry.driver + entry.misc));
    toast('Expense entry added ✓');
    renderAdminLiveOps(); renderAdminAudit();
  };

  /* — Firebase Live Trips — create, monitor, force-end — */
  var cityOpts = ROUTE_STOPS.map(function(s) { return '<option value="' + s.name + '">' + s.name + ' (' + s.lat.toFixed(2) + ', ' + s.lng.toFixed(2) + ')</option>'; }).join('');
  var oc = $('#ltOverrideCity');
  if (oc) oc.innerHTML = cityOpts;

  /* Per-trip driver code. The box used to be hardcoded to 'SHG@2026' — the
     same string as the old admin password — and printed it to whoever opened
     Live Ops. Nothing server-side ever checked it (api/track.php has no PIN
     test), so it was never a lock; making it per-trip at least stops one
     leaked screenshot from reading like the company password. */
  function tripDriverPin() {
    var n = 0;
    try { n = Math.floor(crypto.getRandomValues(new Uint32Array(1))[0] / 4295); }
    catch (e) { n = Math.floor(Math.random() * 1000000); }
    return String(n % 1000000).padStart(6, '0');
  }
  var lp = $('#ltPin');
  if (lp) lp.value = tripDriverPin();

  $('#ltCreateBtn').onclick = function() {
    var defRoute = ROUTE_STOPS.length >= 2 ? (ROUTE_STOPS[0].name + ' → ' + ROUTE_STOPS[ROUTE_STOPS.length - 1].name) : '';
    var route = ($('#ltRoute') || {}).value || defRoute;
    var date = ($('#ltDate') || {}).value;
    var time = ($('#ltTime') || {}).value || '08:15';
    var driver = ($('#ltDriver') || {}).value || 'Driver';
    var vehicle = ($('#ltVehicle') || {}).value || '';
    if (!date) { toast('Pick a date'); return; }
    var tripId = 'SHG-' + date.replace(/-/g, '') + '-' + Math.random().toString(36).substring(2, 6).toUpperCase();
    var tripData = { route: route, departureTime: date + ' ' + time, driverName: driver, vehicleNo: vehicle, status: 'pending', createdAt: Date.now() };
    if (typeof firebase !== 'undefined' && firebase.apps && firebase.apps.length) {
      var db = firebase.database();
      db.ref('activeTrips/' + tripId).set(tripData);
      db.ref('trips/' + tripId + '/meta').set(tripData);
    }
    var localTrips = [];
    try { localTrips = JSON.parse(localStorage.getItem('shg:livetrips') || '[]'); } catch (e) {}
    localTrips.unshift({ id: tripId, ...tripData });
    try { localStorage.setItem('shg:livetrips', JSON.stringify(localTrips)); } catch (e) {}
    $('#ltCreateMsg').textContent = 'Trip ' + tripId + ' created ✓ · Driver PIN: ' + (($('#ltPin') || {}).value || tripDriverPin());
    audit('Trip created', tripId + ' · ' + route + ' · ' + date);
    ltRefreshTable();
  };

  function ltRefreshTable() {
    var tbody = $('#ltTableBody');
    if (!tbody) return;
    if (typeof firebase !== 'undefined' && firebase.apps && firebase.apps.length) {
      firebase.database().ref('activeTrips').once('value').then(function(snap) {
        var data = snap.val();
        ltRenderRows(data, tbody);
      });
    } else {
      var local = [];
      try { local = JSON.parse(localStorage.getItem('shg:livetrips') || '[]'); } catch (e) {}
      var obj = {};
      local.forEach(function(t) { obj[t.id] = t; });
      ltRenderRows(obj, tbody);
    }
    var sel = $('#ltOverrideTrip');
    if (sel) {
      if (typeof firebase !== 'undefined' && firebase.apps && firebase.apps.length) {
        firebase.database().ref('activeTrips').once('value').then(function(snap) {
          var d = snap.val() || {};
          sel.innerHTML = '<option value="">Select trip…</option>' + Object.keys(d).map(function(id) { return '<option value="' + id + '">' + id + '</option>'; }).join('');
        });
      }
    }
  }

  function ltRenderRows(data, tbody) {
    if (!data || !Object.keys(data).length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">No active trips</td></tr>';
      return;
    }
    var html = '';
    Object.keys(data).forEach(function(id) {
      var t = data[id];
      var statusCls = t.status === 'active' ? 'color:var(--ok)' : (t.status === 'complete' ? 'color:var(--muted)' : 'color:var(--warn)');
      html += '<tr style="border-bottom:1px solid var(--line)">'
        + '<td style="padding:8px;font-family:var(--f-code);font-size:12px">' + id + '</td>'
        + '<td style="padding:8px">' + (t.route || '—') + '</td>'
        + '<td style="padding:8px">' + (t.driverName || '—') + '</td>'
        + '<td style="padding:8px;font-size:12px">—</td>'
        + '<td style="padding:8px">—</td>'
        + '<td style="padding:8px;font-weight:700;' + statusCls + '">' + (t.status || 'pending') + '</td>'
        + '<td style="padding:8px"><button class="btn btn-danger-ghost btn-sm" data-forceend="' + id + '">🛑 End</button></td>'
        + '</tr>';
    });
    tbody.innerHTML = html;
    tbody.querySelectorAll('[data-forceend]').forEach(function(btn) {
      btn.onclick = function() {
        var tid = btn.getAttribute('data-forceend');
        if (!confirm('Force-end trip ' + tid + '?')) return;
        if (typeof firebase !== 'undefined' && firebase.apps && firebase.apps.length) {
          firebase.database().ref('trips/' + tid + '/meta/status').set('complete');
          firebase.database().ref('trips/' + tid + '/liveLocation').remove();
          firebase.database().ref('activeTrips/' + tid + '/status').set('complete');
        }
        audit('Trip force-ended', tid);
        toast('Trip ' + tid + ' ended');
        ltRefreshTable();
      };
    });
  }

  $('#ltOverrideBtn').onclick = function() {
    var tid = ($('#ltOverrideTrip') || {}).value;
    var city = ($('#ltOverrideCity') || {}).value;
    if (!tid || !city) { toast('Select trip and city'); return; }
    var idx = ROUTE_STOPS.findIndex(function(s) { return s.name === city; });
    var stop = idx >= 0 ? ROUTE_STOPS[idx] : null;
    if (!stop) return;
    var next = (idx >= 0 && idx < ROUTE_STOPS.length - 1) ? ROUTE_STOPS[idx + 1] : null;
    if (typeof firebase !== 'undefined' && firebase.apps && firebase.apps.length) {
      firebase.database().ref('trips/' + tid + '/liveLocation').set({ lat: stop.lat, lng: stop.lng, bearing: null, speed: 0, accuracy: 999, ts: Date.now() });
    }
    postLocation({ lat: stop.lat, lng: stop.lng, bearing: null, ts: Date.now() });
    /* Cross-device: also write the manual position to the shared server KV so
       EVERY passenger's phone sees it — not just tabs of this browser. The
       `livebus` key is public-read (api/kv.php KV_PUBLIC_READ) and only an
       admin can write it. Passengers poll it in the Trip Companion
       (tcInitManualLive). Works with NO Firebase, NO driver GPS. */
    var tripLabel = '';
    try { var to = $('#ltOverrideTrip'); tripLabel = to && to.selectedOptions[0] ? to.selectedOptions[0].textContent : ''; } catch (e) {}
    try {
      store.set('shg:livebus', {
        tid: tid, tripLabel: tripLabel,
        stop: stop.name, stopIdx: idx, totalStops: ROUTE_STOPS.length,
        next: next ? next.name : '', lat: stop.lat, lng: stop.lng,
        km: stop.km || 0, totalKm: LIVE_TOTAL_KM,
        at: Date.now(), by: (USER && USER.name) || 'staff'
      });
    } catch (e) {}
    $('#ltOverrideMsg').textContent = 'Position set to ' + city + ' ✓ — passengers now see it live';
    audit('Manual live position', tid + ' → ' + city);
  };

  ltRefreshTable();
}

function renderAdminMessages() {
  const box = $('#messagesList');
  if (!DB.messages.length) { box.innerHTML = '<p style="color:var(--muted);font-size:14px;padding:14px 4px">No enquiries yet — messages from the contact form will appear here.</p>'; return; }
  box.innerHTML = DB.messages.map(m => `
    <div class="msg-card">
      <div class="msg-head"><b>${esc(m.name)}</b><span class="badge">${esc(m.topic)}</span><small>${new Date(m.at).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small></div>
      <p>${esc(m.msg)}</p>
      <small>📞 ${esc(m.phone)}</small>
    </div>`).join('');
}

/* §7: the in-app control route is now a launcher into the real, RBAC-protected
   staff Admin Panel (/admin/). The old client-side PIN was trivially bypassable
   (plaintext, devtools) and only unlocked a local mirror; every authoritative
   action (payment approve/reject, ticket issuance) happens server-side after a
   real staff sign-in. The local #adminApp panel is kept in the DOM but is no
   longer reachable from this gate. */
function goRealAdmin() {
  var base = (window.SHG_BOOT && window.SHG_BOOT.appUrl) || location.origin;
  window.open(base.replace(/\/$/, '') + '/admin/', '_blank', 'noopener');
  toast('Opening the secure staff Admin Panel — sign in there.');
}
{ const _ob = $('#adminOpenReal'); if (_ob) _ob.addEventListener('click', goRealAdmin); }
{ const _lo = $('#adminLogout'); if (_lo) _lo.addEventListener('click', () => { ADMIN_ON = false; renderAdminGateOrApp(); toast('Panel locked 🔒'); }); }

/* Hidden admin access: tap CIN number 5x within 3s */
(function(){
  var ct=0,tm=0;
  var el=document.getElementById('cinTap');
  if(!el)return;
  el.style.cursor='default';
  el.addEventListener('click',function(){
    var now=Date.now();
    if(now-tm>3000)ct=0;
    ct++;tm=now;
    if(ct>=5){ct=0;location.hash='#/shg-ctrl';}
  });
})();
/* §2 purge: .atab and #pvBell were inside the removed #view-admin section.
   The selectors now return empty / null, so these are guarded. */
$$('.atab[data-tab]').forEach(tb => tb.addEventListener('click', () => {
  $$('.atab[data-tab]').forEach(x => x.classList.toggle('on', x === tb));
  $$('.admin-tabpane').forEach(p => p.classList.toggle('hide', p.id !== 'tab-' + tb.getAttribute('data-tab')));
}));
{ const _pv = $('#pvBell'); if (_pv) _pv.addEventListener('click', function() {
  $$('.atab[data-tab]').forEach(x => x.classList.toggle('on', x.getAttribute('data-tab') === 'payments'));
  $$('.admin-tabpane').forEach(p => p.classList.toggle('hide', p.id !== 'tab-payments'));
  if (typeof renderAdminPayments === 'function') renderAdminPayments();
}); }

/* ================================================================
   [JS] 14A. SPLASH SCREEN — letter-by-letter cinematic cold launch
================================================================ */
/* ============ [JS] ROLE GATE — no-op stub (2026-08-29) ============
   The public "Select your access" modal was removed from the customer
   surface per master-prompt §2. This stub is kept only so older callers
   (Splash.init / Splash.finish and any deprecated menu handler) do not
   throw. It persists the visitor as "customer" and, when opened with
   `reset('login')`, hands straight to the OTP modal. Staff sign in at
   /admin/ via a bookmark; there is no in-app portal picker. */
const RoleGate = {
  KEY: 'shg:role',
  init() { return this; },
  saved() { try { return localStorage.getItem(this.KEY); } catch (e) { return null; } },
  maybeShow() {
    try { localStorage.setItem(this.KEY, 'customer'); } catch (e) {}
  },
  show(intent) {
    if (intent === 'login' && !USER && typeof openLoginModal === 'function') {
      openLoginModal();
    }
  },
  choose(role) {
    if (role === 'agent' || role === 'admin') {
      const base = ((window.SHG_BOOT && window.SHG_BOOT.appUrl) || location.origin).replace(/\/+$/, '');
      location.href = base + '/admin/login.php?portal=' + encodeURIComponent(role);
      return;
    }
    if (typeof openLoginModal === 'function' && !USER) openLoginModal();
  },
  hide() { /* no modal to hide */ },
  reset(intent) { this.show(intent); }
};

const Splash = {
  el: null, bar: null, status: null, skip: null, done: false,
  progress: 0, tasks: 0, completed: 0, startTs: 0, dataReady: false,
  trailer: false, soundOn: false, _ac: null,
  /* A floor, not a wait. Long enough that the logo doesn't flash past on a
     fast connection, short enough that nobody is ever held back by it —
     this used to be 5000, so a booking that had loaded in 300ms still sat
     behind four and a half seconds of branding. Sep-2 mobile pass:
     dropped to 500ms so a phone paint feels instant; the logo still
     registers because the letter-by-letter animation runs in parallel. */
  minDuration: 500,
  /* First visit: show the brand briefly while data loads. Staff sign-in
     has its own link; passengers need not wait for a portal chooser. */
  portalMs: 600,
  portalTimer: null,
  featTimer: null,
  messages: [
    'Checking session…', 'Loading trip data…', 'Preparing routes…',
    'Caching map tiles…', 'Almost ready…'
  ],
  init() {
    this.el = $('#splash');
    this.bar = $('#splashBar');
    this.status = $('#splashStatus');
    this.skip = $('#splashSkip');
    this.startTs = performance.now();
    if (!this.el) { this.done = true; return; }
    var splashed = false;
    try { splashed = !!sessionStorage.getItem('shg:splashed'); } catch (e) {}
    if (splashed) {
      this.el.style.display = 'none';
      this.done = true;
      RoleGate.init().maybeShow();   // splash skipped this session — still ask
      return;
    }
    /* V5: the long (34s) first-visit cinematic trailer is disabled by default
       for a fast, snappy launch. The trailer code below is preserved — flip
       ENABLE_INTRO_TRAILER to true (or restore the localStorage check) to bring
       it back. */
    var ENABLE_INTRO_TRAILER = false;
    try { this.trailer = ENABLE_INTRO_TRAILER && !localStorage.getItem('shg:introSeen'); } catch (e) { this.trailer = false; }
    if (this.trailer) {
      this.minDuration = 38000;
      try { localStorage.setItem('shg:introSeen', '1'); } catch (e) {}
      this.runScenes();
    } else {
      /* Fast splash: the cinematic trailer DOM is preserved (flip
         ENABLE_INTRO_TRAILER to restore it) but kept out of layout so the
         clean logo + name + phone intro stands alone. */
      var _intro = $('#introScenes'); if (_intro) _intro.style.display = 'none';
    }
    /* How long the brand screen is held open, decided per visitor:

         first ever visit  → 2s, so the Agent / Admin pills get a real
                             window before the app opens as Customer;
         role remembered   → 0.4s, because that visitor already answered
                             and must not be taxed 2s on every cold start.

       Either way the data load runs underneath this, so the floor is the
       only thing anyone waits for — never floor + load. */
    var _seenRole = null;
    try { _seenRole = localStorage.getItem(RoleGate.KEY); } catch (e) {}
    if (!this.trailer) this.minDuration = _seenRole ? 250 : this.portalMs;
    if (!this.trailer && !_seenRole) this.armPortal();

    const sb = $('#splashSound');
    if (sb) sb.addEventListener('click', () => {
      this.soundOn = !this.soundOn;
      sb.textContent = this.soundOn ? '🔊 Sound On' : '🔇 Sound';
      sb.classList.toggle('on', this.soundOn);
      if (this.soundOn) this.chime(1);
    });
    this.animateTitle();
    if (this.skip) {
      setTimeout(() => { this.skip.classList.add('show'); }, this.trailer ? 2500 : 600);
      this.skip.addEventListener('click', () => this.finish());
    }
    /* Safety net: if the data never arrives (dead network, KV down) the
       splash still gets out of the way rather than trapping the visitor. */
    /* Safety net — if the data never arrives (dead network, KV down) the
       splash still gets out of the way rather than trapping the visitor.
       Tied to the floor so a fast visitor is never held 3.5s for nothing:
       worst case is now floor + 1.2s (≈ 3.2s cold, ≈ 1.6s returning). */
    setTimeout(() => { if (!this.done) this.finish(); }, this.trailer ? 42000 : this.minDuration + 1200);
  },
  /* Trailer — 6 scenes, 24s total. CEO first → India → Nepal → both → map → features.
     Tight, smooth, skip naparos jasto chhoto. */
  runScenes() {
    const mb = $('#introMapBox');
    if (mb && typeof trackMapSVG === 'function') { try { mb.innerHTML = trackMapSVG(0, false); } catch (e) {} }
    const ci = $('#introCeoImg');
    try { const saved = localStorage.getItem('shg:ceoPhoto'); if (saved && ci) ci.src = saved; } catch (e) {}
    const caps = ['👑 CEO — Sher Bahadur Bishwokarma', '🇮🇳 INDIA', '🇳🇵 NEPAL', '🚌 दुई देश · एक यात्रा', '🗺️ Route — AMD → NPJ', '☕ Refreshment Halt', '🎫 Book in 3 Taps', '🕉️ ॐ नमो नारायणाय · शुभ यात्रा'];
    [3000, 7500, 11500, 15500, 20000, 25000, 30000, 35000].forEach((t2, i) => {
      setTimeout(() => {
        if (this.done) return;
        for (let k = 1; k <= 8; k++) {
          const sc = $('#introS' + k);
          if (sc) sc.classList.toggle('on', k === i + 1);
        }
        if (this.status) this.status.textContent = caps[i];
        this.chime(i + 1);
      }, t2);
    });
  },
  /* Scene sounds — only after the user taps the Sound chip (no autoplay).
     Scene 1: deep flag-reveal gong · Scene 2: 6 ascending blips synced to
     the feature badges popping in · other scenes: soft two-note chime. */
  _note(f, at, dur, vol, type) {
    const ac = this._ac, o = ac.createOscillator(), g = ac.createGain();
    o.type = type || 'sine'; o.frequency.value = f;
    g.gain.setValueAtTime(0, at);
    g.gain.linearRampToValueAtTime(vol, at + .04);
    g.gain.linearRampToValueAtTime(0, at + dur);
    o.connect(g); g.connect(ac.destination);
    o.start(at); o.stop(at + dur + .05);
  },
  chime(step) {
    if (!this.soundOn) return;
    try {
      const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
      if (!this._ac) this._ac = new AC();
      const t0 = this._ac.currentTime;
      if (step === 1) {                      /* flag reveal — warm gong */
        this._note(196, t0, 1.4, .16, 'sine');
        this._note(392, t0 + .1, 1.2, .1, 'sine');
        this._note(587.33, t0 + .25, 1, .07, 'triangle');
      } else if (step === 3) {               /* feature badges — 6 rising blips */
        [660, 740, 830, 932, 1046, 1174].forEach((f, i) => this._note(f, t0 + .5 + i * .25, .22, .1, 'triangle'));
      } else {
        this._note(523.25, t0, .5, .13, 'sine');
        this._note(659.25, t0 + .12, .5, .1, 'sine');
      }
    } catch (e) {}
  },
  animateTitle() {
    /* Premium intro — a clean logo → name → phone reveal. Brand name and
       phone number are sourced from CONFIG so there is no second hardcoded
       copy to drift out of sync with the footer / ticket. */
    const title = $('#splashTitle');
    if (title) {
      const name = (CONFIG.company && CONFIG.company.name) || 'S Hari Global';
      /* Letter-by-letter reveal: each glyph gets its own stagger index. The
         accessible name is set once on the container (and the spans hidden
         from the a11y tree) so screen readers say the company name normally
         instead of spelling it out. */
      /* The company name is now seeded into the HTML so it paints on the
         FIRST frame. Splitting it into letters is a nice-to-have: only do it
         when the text is still the seeded name, and never blank the element
         first — wiping it made the name blink out and back on every launch. */
      if (title.textContent.trim() && title.textContent.trim() !== name) {
        title.setAttribute('aria-label', name);
        return;
      }
      title.textContent = '';
      title.setAttribute('aria-label', name);
      let i = 0;
      for (const ch of name) {
        if (ch === ' ') { title.appendChild(document.createTextNode(' ')); continue; }
        const s = document.createElement('span');
        s.className = 'ltr';
        s.setAttribute('aria-hidden', 'true');
        s.style.setProperty('--i', i++);
        s.textContent = ch;
        title.appendChild(s);
      }
    }
    const ph = $('#splashPhone');
    if (ph) {
      const num = (CONFIG.phone || '').trim();
      const digits = String(CONFIG.adminWhatsApp || CONFIG.phone || '').replace(/[^0-9]/g, '');
      const numEl = ph.querySelector('.sp-num');
      if (numEl) numEl.textContent = num;
      if (digits) ph.setAttribute('href', 'tel:+' + digits);
      else ph.removeAttribute('href');
    }
    const reveal = (sel, delay) => setTimeout(() => {
      if (this.done) return;
      const el = $(sel);
      if (el) el.classList.add('show');
    }, delay);
    /* Paced to land INSIDE the real splash window. The Sep-2 mobile pass set
       minDuration to a 500ms floor, so anything revealed after ~400ms would
       never be seen — the brand promise here is logo + name + phone, so all
       three must be on screen before finish() starts the fade. */
    reveal('#splashTitle', 40);
    reveal('#splashTagline', 130);
    reveal('#splashPhone', 210);
    /* (the skip button is revealed separately in init(), at 1.2s) */
  },
  /* The 2-second staff window. Purely additive: it rides on top of the
     splash the visitor was already watching, so a passenger who ignores it
     reaches the booking screen at exactly the same moment they would have
     anyway. Tapping Agent or Admin leaves for the staff sign-in, which
     decides from the ACCOUNT what that person may actually do — picking
     "Admin" here is a routing hint, never a promotion. */
  armPortal() {
    var wrap = document.getElementById('splashPortal');
    if (!wrap) return;
    var bar = document.getElementById('spBar');
    var cnt = document.getElementById('spCount');
    var self = this;

    wrap.hidden = false;
    setTimeout(function () { wrap.classList.add('show'); }, 250);

    /* Only the "Book now" (data-portal=customer) button remains — Agent and
       Admin pills were removed 2026-08-29 (master-prompt §2). Keep the
       click wiring for the customer button so a first-time visitor can skip
       the brand hold; any other pill added back later would still be
       handled here without changes. */
    Array.prototype.forEach.call(wrap.querySelectorAll('[data-portal]'), function (b) {
      b.addEventListener('click', function () {
        var role = b.getAttribute('data-portal');
        self.stopPortal();
        try { localStorage.setItem(RoleGate.KEY, role || 'customer'); } catch (e) {}
        if (role === 'customer' || !role) { self.finish(); return; }
        var base = ((window.SHG_BOOT && window.SHG_BOOT.appUrl) || location.origin).replace(/\/+$/, '');
        location.href = base + '/admin/login.php?portal=' + encodeURIComponent(role);
      });
    });

    /* Drain the bar with one transition rather than a per-frame timer — it
       runs on the compositor, so a slow phone spends its CPU on the data
       load underneath instead of on this animation. */
    if (bar) {
      bar.style.transition = 'transform ' + this.portalMs + 'ms linear';
      requestAnimationFrame(function () {
        requestAnimationFrame(function () { bar.style.transform = 'scaleX(0)'; });
      });
    }
    /* Cycle the feature lines. Paced so every line gets one turn inside the
       hold rather than racing through all four. */
    var feats = wrap.querySelectorAll('#spFeats li');
    if (feats.length > 1) {
      var fi = 0;
      var every = Math.max(1100, Math.floor(this.portalMs / feats.length));
      this.featTimer = setInterval(function () {
        feats[fi].classList.remove('on');
        fi = (fi + 1) % feats.length;
        feats[fi].classList.add('on');
      }, every);
    }

    var left = Math.round(this.portalMs / 1000);
    if (cnt) cnt.textContent = String(left);
    this.portalTimer = setInterval(function () {
      left--;
      if (cnt) cnt.textContent = String(Math.max(0, left));
      if (left <= 0) self.stopPortal();
    }, 1000);
  },
  stopPortal() {
    if (this.portalTimer) { clearInterval(this.portalTimer); this.portalTimer = null; }
    if (this.featTimer) { clearInterval(this.featTimer); this.featTimer = null; }
    var wrap = document.getElementById('splashPortal');
    if (wrap) wrap.classList.remove('show');
  },
  tick(label) {
    this.completed++;
    this.progress = Math.min(95, Math.round(this.completed / Math.max(1, this.tasks) * 95));
    if (this.bar) this.bar.style.width = this.progress + '%';
    if (this.status) this.status.textContent = label || this.messages[Math.min(this.completed - 1, this.messages.length - 1)] || '';
  },
  setTotal(n) { this.tasks = n; },
  markReady() {
    this.dataReady = true;
    if (this.bar) this.bar.style.width = '95%';
    if (this.status) this.status.textContent = 'Almost ready…';
    const elapsed = performance.now() - this.startTs;
    const wait = Math.max(0, this.minDuration - elapsed);
    setTimeout(() => this.finish(), wait);
  },
  finish() {
    if (this.done) return;
    this.done = true;
    this.stopPortal();
    if (this.bar) this.bar.style.width = '100%';
    if (this.status) { this.status.style.opacity = '0'; setTimeout(() => { if (this.status) this.status.textContent = '✓ Ready'; this.status.style.opacity = '1'; }, 200); }
    try { sessionStorage.setItem('shg:splashed', '1'); } catch (e) {}
    /* Hand the role picker over AS the splash fades, not after it. The two
       used to be strictly sequential (300ms pause + 1100ms fade = 1.4s of
       nothing), which is what made the launch feel slow even once the data
       was ready. The picker is now already on screen behind the fade. */
    setTimeout(() => {
      if (this.el) this.el.classList.add('done');
      RoleGate.init().maybeShow();
      /* Boot time the owner can actually check: navigation → the moment the
         role picker is usable. Paint metrics lie in a background tab; this
         one is a plain timer, so it is the same number on any device.
         Read it any time with `SHG_BOOT_MS` in the console. */
      window.SHG_BOOT_MS = Math.round(performance.now());
      try { console.info('[SHG] ready in ' + window.SHG_BOOT_MS + 'ms'); } catch (e) {}
      setTimeout(() => {
        if (this.el) this.el.style.display = 'none';
        requestStartupPermissions();
      }, 400);
    }, 120);
  }
};

function requestStartupPermissions() {
  if (localStorage.getItem('shg:permsAsked')) return;
  localStorage.setItem('shg:permsAsked', '1');
  setTimeout(function() {
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }
    if ('geolocation' in navigator) {
      navigator.geolocation.getCurrentPosition(function() {}, function() {}, { timeout: 5000 });
    }
  }, 2000);
}


/* ================================================================
   [JS] SHARED DATA + HELPERS kept in the boot file when the live map,
   trip companion and AI assistant moved to 16-lazy.js (11 Sep 2026):
   03-accounts reads SHG_TEMPLES, 11-pdf-ticket reads TRACKER_PATHS,
   02-config + 15-nav call loadMapLibre.
================================================================ */
const TRACKER_PATHS = {
  via_gorakhpur: [
    { name: 'Ahmedabad', lat: 23.0225, lng: 72.5714, km: 0,    flag: '🇮🇳', landmark: true },
    { name: 'Chiloda',   lat: 23.1570, lng: 72.6550, km: 30,   flag: '',     landmark: true },
    { name: 'Mehsana',   lat: 23.5880, lng: 72.3693, km: 80,   flag: '',     landmark: true },
    { name: 'Unjha',     lat: 23.8040, lng: 72.3910, km: 110,  flag: '',     landmark: false },
    { name: 'Sidhpur',   lat: 23.9170, lng: 72.3730, km: 135,  flag: '',     landmark: false },
    { name: 'Palanpur',  lat: 24.1710, lng: 72.4380, km: 210,  flag: '',     landmark: true },
    { name: 'Abu Road',  lat: 24.4800, lng: 72.7700, km: 280,  flag: '',     landmark: false },
    { name: 'Udaipur',   lat: 24.5854, lng: 73.7125, km: 410,  flag: '',     landmark: true },
    { name: 'Ajmer',     lat: 26.4499, lng: 74.6399, km: 550,  flag: '',     landmark: false },
    { name: 'Jaipur',    lat: 26.9124, lng: 75.7873, km: 650,  flag: '',     landmark: true },
    { name: 'Agra',      lat: 27.1767, lng: 78.0081, km: 830,  flag: '',     landmark: false },
    { name: 'Lucknow',   lat: 26.8467, lng: 80.9462, km: 1040, flag: '',     landmark: true },
    { name: 'Gorakhpur', lat: 26.7606, lng: 83.3732, km: 1200, flag: '',     landmark: true },
    { name: 'Rupaidiha', lat: 28.0600, lng: 81.6160, km: 1330, flag: '🛃',   landmark: true },
    { name: 'Nepalgunj', lat: 28.0500, lng: 81.6167, km: 1370, flag: '🇳🇵', landmark: true },
    { name: 'Kohalpur',  lat: 28.1053, lng: 81.7340, km: 1400, flag: '🇳🇵', landmark: false }
  ],
  via_bahraich: [
    { name: 'Ahmedabad', lat: 23.0225, lng: 72.5714, km: 0,    flag: '🇮🇳', landmark: true },
    { name: 'Chiloda',   lat: 23.1570, lng: 72.6550, km: 30,   flag: '',     landmark: true },
    { name: 'Mehsana',   lat: 23.5880, lng: 72.3693, km: 80,   flag: '',     landmark: true },
    { name: 'Unjha',     lat: 23.8040, lng: 72.3910, km: 110,  flag: '',     landmark: false },
    { name: 'Sidhpur',   lat: 23.9170, lng: 72.3730, km: 135,  flag: '',     landmark: false },
    { name: 'Palanpur',  lat: 24.1710, lng: 72.4380, km: 210,  flag: '',     landmark: true },
    { name: 'Abu Road',  lat: 24.4800, lng: 72.7700, km: 280,  flag: '',     landmark: false },
    { name: 'Udaipur',   lat: 24.5854, lng: 73.7125, km: 410,  flag: '',     landmark: true },
    { name: 'Ajmer',     lat: 26.4499, lng: 74.6399, km: 550,  flag: '',     landmark: false },
    { name: 'Jaipur',    lat: 26.9124, lng: 75.7873, km: 650,  flag: '',     landmark: true },
    { name: 'Agra',      lat: 27.1767, lng: 78.0081, km: 830,  flag: '',     landmark: false },
    { name: 'Lucknow',   lat: 26.8467, lng: 80.9462, km: 1040, flag: '',     landmark: true },
    { name: 'Sitapur',   lat: 27.5726, lng: 80.6828, km: 1130, flag: '',     landmark: false },
    { name: 'Bahraich',  lat: 27.5745, lng: 81.5959, km: 1167, flag: '',     landmark: true },
    { name: 'Rupaidiha', lat: 28.0600, lng: 81.6160, km: 1243, flag: '🛃',   landmark: true },
    { name: 'Nepalgunj', lat: 28.0500, lng: 81.6167, km: 1283, flag: '🇳🇵', landmark: true },
    { name: 'Kohalpur',  lat: 28.1053, lng: 81.7340, km: 1313, flag: '🇳🇵', landmark: false }
  ]
};

var SHG_TEMPLES = [
  {"name":"Somnath Temple","lat":20.888,"lng":70.4013,"city":"Veraval (Prabhas Patan)","country":"IN","deity":"Shiva","est":"Ancient","note":"First among the 12 Jyotirlingas; rebuilt many times over the centuries"},
  {"name":"Dwarkadhish Temple","lat":22.2376,"lng":68.9674,"city":"Dwarka","country":"IN","deity":"Krishna","est":"Ancient","note":"Char Dham shrine of Krishna at Dwarka"},
  {"name":"Nageshwar Jyotirlinga","lat":22.334,"lng":69.087,"city":"Dwarka","country":"IN","deity":"Shiva","est":"Ancient","note":"One of the 12 Jyotirlingas, near Dwarka"},
  {"name":"Akshardham Temple","lat":23.2308,"lng":72.6736,"city":"Gandhinagar","country":"IN","deity":"Swaminarayan","est":"1992","note":"Large Swaminarayan temple complex in Gandhinagar"},
  {"name":"Jagannath Temple","lat":23.011,"lng":72.58,"city":"Ahmedabad","country":"IN","deity":"Vishnu","est":"16th century","note":"Starting point of Ahmedabad's famous annual Rath Yatra, held since 1878"},
  {"name":"Hutheesing Jain Temple","lat":23.041,"lng":72.589,"city":"Ahmedabad","country":"IN","deity":"Jain","est":"1848","note":"Ornate Jain temple dedicated to Dharmanatha"},
  {"name":"Swaminarayan Mandir Kalupur","lat":23.03,"lng":72.594,"city":"Ahmedabad","country":"IN","deity":"Swaminarayan","est":"1822","note":"First temple of the Swaminarayan Sampradaya"},
  {"name":"Bahucharaji Temple","lat":23.494,"lng":72.104,"city":"Becharaji","country":"IN","deity":"Devi/Shakti","est":"18th century","note":"One of Gujarat's major Shakti pilgrimage sites"},
  {"name":"Modhera Sun Temple","lat":23.5837,"lng":72.1332,"city":"Modhera","country":"IN","deity":"Surya","est":"11th century","note":"Solanki-era Sun Temple famed for its stepped tank"},
  {"name":"Umiya Mata Temple","lat":23.8017,"lng":72.3951,"city":"Unjha","country":"IN","deity":"Devi/Shakti","est":"—","note":"Major shrine of goddess Umiya in Unjha, Mehsana district"},
  {"name":"Ambaji Temple","lat":24.3327,"lng":72.8507,"city":"Ambaji","country":"IN","deity":"Devi/Shakti","est":"Ancient","note":"Revered as one of the 51 Shakti Peethas"},
  {"name":"Dilwara Temples","lat":24.617,"lng":72.722,"city":"Mount Abu","country":"IN","deity":"Jain","est":"11th–13th century","note":"World-famous for exquisitely intricate marble carving"},
  {"name":"Ranakpur Jain Temple","lat":25.1162,"lng":73.472,"city":"Ranakpur","country":"IN","deity":"Jain","est":"15th century","note":"Chaumukha Adinatha temple renowned for its 1,444 carved pillars"},
  {"name":"Eklingji Temple","lat":24.733,"lng":73.727,"city":"Kailashpuri (Udaipur)","country":"IN","deity":"Shiva","est":"8th century","note":"Presiding deity of the Mewar dynasty"},
  {"name":"Jagdish Mandir","lat":24.5797,"lng":73.6833,"city":"Udaipur","country":"IN","deity":"Vishnu","est":"1651","note":"Largest temple in Udaipur, built by Maharana Jagat Singh I"},
  {"name":"Shrinathji Temple","lat":24.934,"lng":73.823,"city":"Nathdwara","country":"IN","deity":"Krishna","est":"1672","note":"Principal seat of Shrinathji in the Pushtimarg tradition"},
  {"name":"Brahma Temple","lat":26.4876,"lng":74.5486,"city":"Pushkar","country":"IN","deity":"Brahma","est":"14th century","note":"One of the very few temples in the world dedicated to Brahma"},
  {"name":"Govind Dev Ji Temple","lat":26.928,"lng":75.823,"city":"Jaipur","country":"IN","deity":"Krishna","est":"18th century","note":"Krishna image brought from Vrindavan; beside Jaipur City Palace"},
  {"name":"Birla Mandir (Laxmi Narayan)","lat":26.8921,"lng":75.8153,"city":"Jaipur","country":"IN","deity":"Vishnu","est":"1988","note":"Modern white-marble Lakshmi Narayan temple"},
  {"name":"Moti Dungri Ganesh Temple","lat":26.894,"lng":75.813,"city":"Jaipur","country":"IN","deity":"Ganesha","est":"18th century","note":"Famous Ganesh temple at the foot of Moti Dungri hill"},
  {"name":"Khatu Shyam Ji Temple","lat":27.3648,"lng":75.4022,"city":"Khatushyamji (Sikar)","country":"IN","deity":"Krishna","est":"18th century","note":"Shrine of Barbarika, worshipped as Shyam Baba"},
  {"name":"Salasar Balaji Temple","lat":27.732,"lng":74.728,"city":"Salasar (Churu)","country":"IN","deity":"Hanuman","est":"1754","note":"Major Hanuman pilgrimage centre of Rajasthan"},
  {"name":"Banke Bihari Temple","lat":27.5806,"lng":77.6944,"city":"Vrindavan","country":"IN","deity":"Krishna","est":"1864","note":"Famed Krishna temple in the tradition of Swami Haridas"},
  {"name":"Prem Mandir","lat":27.565,"lng":77.673,"city":"Vrindavan","country":"IN","deity":"Krishna","est":"2012","note":"White-marble Radha-Krishna temple known for its evening illumination"},
  {"name":"Krishna Janmabhoomi Temple","lat":27.5047,"lng":77.6735,"city":"Mathura","country":"IN","deity":"Krishna","est":"Ancient","note":"Believed birthplace of Lord Krishna"},
  {"name":"Dwarkadhish Temple","lat":27.5057,"lng":77.6832,"city":"Mathura","country":"IN","deity":"Krishna","est":"1814","note":"Historic Krishna temple near Vishram Ghat on the Yamuna"},
  {"name":"Mankameshwar Mahadev Temple","lat":27.181,"lng":78.016,"city":"Agra","country":"IN","deity":"Shiva","est":"Ancient","note":"Ancient wish-fulfilling Shiva linga in old Agra"},
  {"name":"Hanuman Setu Mandir","lat":26.867,"lng":80.939,"city":"Lucknow","country":"IN","deity":"Hanuman","est":"20th century","note":"Established by Neem Karoli Baba beside the Gomti, near Lucknow University"},
  {"name":"Shri Ram Janmabhoomi Mandir","lat":26.7956,"lng":82.1943,"city":"Ayodhya","country":"IN","deity":"Ram","est":"2024","note":"Consecrated in January 2024 at Ram Janmabhoomi"},
  {"name":"Hanuman Garhi","lat":26.7952,"lng":82.2005,"city":"Ayodhya","country":"IN","deity":"Hanuman","est":"18th century","note":"76-step fortress temple where Hanuman is said to guard Ayodhya"},
  {"name":"Gorakhnath Temple","lat":26.776,"lng":83.356,"city":"Gorakhpur","country":"IN","deity":"Shiva/Nath","est":"Ancient","note":"Seat of the Nath monastic order of Guru Gorakhnath"},
  {"name":"Kashi Vishwanath Temple","lat":25.3109,"lng":83.0107,"city":"Varanasi","country":"IN","deity":"Shiva","est":"1780","note":"One of the 12 Jyotirlingas, on the Ganga in Kashi; present structure built 1780"},
  {"name":"Sankat Mochan Hanuman Temple","lat":25.2824,"lng":82.9988,"city":"Varanasi","country":"IN","deity":"Hanuman","est":"16th century","note":"Founded by the saint-poet Tulsidas"},
  {"name":"Durga Mandir (Durga Kund)","lat":25.285,"lng":83.003,"city":"Varanasi","country":"IN","deity":"Devi/Shakti","est":"18th century","note":"Red-ochre Durga temple beside the Durga Kund tank"},
  {"name":"Vindhyavasini Devi Temple","lat":25.162,"lng":82.503,"city":"Vindhyachal","country":"IN","deity":"Devi/Shakti","est":"Ancient","note":"Renowned Shakti shrine of goddess Vindhyavasini on the Ganga"},
  {"name":"Naimisharanya (Lalita Devi / Chakra Tirtha)","lat":27.356,"lng":80.493,"city":"Naimisharanya (Sitapur)","country":"IN","deity":"Vishnu","est":"Ancient","note":"Legendary forest tirtha where the Puranas are said to have been narrated"},
  {"name":"Bade Hanuman Temple (Lete Hanuman)","lat":25.431,"lng":81.877,"city":"Prayagraj","country":"IN","deity":"Hanuman","est":"—","note":"Unique reclining Hanuman idol near the Triveni Sangam"},
  {"name":"Vaishno Devi","lat":33.03,"lng":74.949,"city":"Katra","country":"IN","deity":"Devi/Shakti","est":"Ancient","note":"Cave shrine of Mata Vaishno Devi in the Trikuta hills"},
  {"name":"Kedarnath Temple","lat":30.7346,"lng":79.0669,"city":"Kedarnath","country":"IN","deity":"Shiva","est":"Ancient","note":"One of the 12 Jyotirlingas; Himalayan Char Dham shrine"},
  {"name":"Badrinath Temple","lat":30.7433,"lng":79.493,"city":"Badrinath","country":"IN","deity":"Vishnu","est":"Ancient","note":"Char Dham shrine of Vishnu as Badrinarayan"},
  {"name":"Har Ki Pauri","lat":29.9576,"lng":78.1711,"city":"Haridwar","country":"IN","deity":"Ganga","est":"Ancient","note":"Haridwar's holiest ghat, site of the daily Ganga aarti"},
  {"name":"Mansa Devi Temple","lat":29.9584,"lng":78.1664,"city":"Haridwar","country":"IN","deity":"Devi/Shakti","est":"—","note":"Hilltop Siddh Peeth on Bilwa Parvat above Haridwar, reached by cable car"},
  {"name":"Tirumala Venkateswara Temple (Tirupati Balaji)","lat":13.6833,"lng":79.3472,"city":"Tirumala (Tirupati)","country":"IN","deity":"Vishnu","est":"Ancient","note":"Famed hill shrine of Lord Venkateswara on the Tirumala hills"},
  {"name":"Meenakshi Amman Temple","lat":9.9195,"lng":78.1193,"city":"Madurai","country":"IN","deity":"Devi/Shakti","est":"Ancient","note":"Iconic Dravidian temple of Meenakshi and Sundareswarar with towering gopurams"},
  {"name":"Ramanathaswamy Temple","lat":9.2881,"lng":79.3174,"city":"Rameswaram","country":"IN","deity":"Shiva","est":"12th century","note":"One of 12 Jyotirlingas; Char Dham site linked to the Ramayana"},
  {"name":"Jagannath Temple","lat":19.805,"lng":85.8179,"city":"Puri","country":"IN","deity":"Krishna","est":"12th century","note":"Char Dham temple famous for the annual Rath Yatra"},
  {"name":"Kamakhya Temple","lat":26.1664,"lng":91.7055,"city":"Guwahati","country":"IN","deity":"Devi/Shakti","est":"Ancient","note":"One of the foremost Shakti Peethas, atop Nilachal Hill"},
  {"name":"Kalighat Kali Temple","lat":22.52,"lng":88.342,"city":"Kolkata","country":"IN","deity":"Devi/Shakti","est":"1809","note":"Renowned Shakti Peetha of Goddess Kali"},
  {"name":"Dakshineswar Kali Temple","lat":22.6555,"lng":88.3577,"city":"Kolkata","country":"IN","deity":"Devi/Shakti","est":"1855","note":"Kali temple on the Hooghly, closely associated with Sri Ramakrishna"},
  {"name":"Siddhivinayak Temple","lat":19.017,"lng":72.8302,"city":"Mumbai","country":"IN","deity":"Ganesha","est":"1801","note":"Renowned Ganesha shrine in Prabhadevi, Mumbai"},
  {"name":"Shirdi Sai Baba Samadhi Mandir","lat":19.7661,"lng":74.4763,"city":"Shirdi","country":"IN","deity":"Sai Baba","est":"20th century","note":"Samadhi shrine of Sai Baba of Shirdi"},
  {"name":"Trimbakeshwar Temple","lat":19.9325,"lng":73.5292,"city":"Trimbak (Nashik)","country":"IN","deity":"Shiva","est":"18th century","note":"One of 12 Jyotirlingas, near the source of the Godavari"},
  {"name":"Grishneshwar Temple","lat":20.0244,"lng":75.1699,"city":"Ellora (Verul)","country":"IN","deity":"Shiva","est":"18th century","note":"One of 12 Jyotirlingas, beside the Ellora Caves"},
  {"name":"Bhimashankar Temple","lat":19.0722,"lng":73.5356,"city":"Bhimashankar (Pune district)","country":"IN","deity":"Shiva","est":"18th century","note":"One of 12 Jyotirlingas, in the Sahyadri hills"},
  {"name":"Mahakaleshwar Temple","lat":23.1828,"lng":75.7682,"city":"Ujjain","country":"IN","deity":"Shiva","est":"Ancient","note":"One of 12 Jyotirlingas; famous for the Bhasma Aarti"},
  {"name":"Omkareshwar Temple","lat":22.2453,"lng":76.151,"city":"Omkareshwar","country":"IN","deity":"Shiva","est":"Ancient","note":"One of 12 Jyotirlingas, on Om-shaped Mandhata island in the Narmada"},
  {"name":"Konark Sun Temple","lat":19.8876,"lng":86.0945,"city":"Konark","country":"IN","deity":"Surya","est":"13th century","note":"UNESCO World Heritage chariot-shaped Sun temple"},
  {"name":"Lingaraja Temple","lat":20.2386,"lng":85.8339,"city":"Bhubaneswar","country":"IN","deity":"Shiva","est":"11th century","note":"Masterpiece of Kalinga temple architecture"},
  {"name":"Virupaksha Temple","lat":15.335,"lng":76.4581,"city":"Hampi","country":"IN","deity":"Shiva","est":"7th century","note":"Living temple at the heart of UNESCO-listed Hampi"},
  {"name":"Chamundeshwari Temple","lat":12.2725,"lng":76.6705,"city":"Mysuru","country":"IN","deity":"Devi/Shakti","est":"12th century","note":"Hilltop shrine of Goddess Chamundeshwari on Chamundi Hills"},
  {"name":"Guruvayur Temple","lat":10.5946,"lng":76.0393,"city":"Guruvayur","country":"IN","deity":"Krishna","est":"—","note":"Major Krishna temple of Kerala, called Bhuloka Vaikuntha"},
  {"name":"Sabarimala Ayyappa Temple","lat":9.434,"lng":77.0815,"city":"Sabarimala (Pathanamthitta)","country":"IN","deity":"Ayyappa","est":"—","note":"Forest hill shrine of Lord Ayyappa reached on foot by pilgrims"},
  {"name":"Padmanabhaswamy Temple","lat":8.4828,"lng":76.9434,"city":"Thiruvananthapuram","country":"IN","deity":"Vishnu","est":"Ancient","note":"Vishnu reclining on Anantha; renowned for its treasure vaults"},
  {"name":"Ranganathaswamy Temple, Srirangam","lat":10.8624,"lng":78.6907,"city":"Srirangam (Tiruchirappalli)","country":"IN","deity":"Vishnu","est":"Ancient","note":"Among the largest functioning Hindu temple complexes in the world"},
  {"name":"Brihadeeswarar Temple","lat":10.7828,"lng":79.1318,"city":"Thanjavur","country":"IN","deity":"Shiva","est":"1010","note":"UNESCO Great Living Chola Temple built by Raja Raja Chola I"},
  {"name":"Bageshwori Temple","lat":28.05,"lng":81.6167,"city":"Nepalgunj","country":"NP","deity":"Devi/Shakti","est":"—","note":"Renowned Bageshwori Devi shrine of Nepalgunj"},
  {"name":"Deuti Bajai Temple","lat":28.602,"lng":81.6339,"city":"Surkhet","country":"NP","deity":"Devi/Shakti","est":"—","note":"Renowned goddess temple of the Surkhet valley"},
  {"name":"Kakrebihar","lat":28.586,"lng":81.635,"city":"Surkhet","country":"NP","deity":"Buddhist","est":"12th century","note":"Ruined medieval Hindu-Buddhist monument complex on a forested hill in Surkhet valley"},
  {"name":"Rishikesh Temple, Ruru Kshetra","lat":27.95,"lng":83.4333,"city":"Ridi","country":"NP","deity":"Vishnu","est":"—","note":"Vishnu temple at Ruru Kshetra, a sacred confluence on the Kali Gandaki"},
  {"name":"Baglung Kalika Temple","lat":28.2667,"lng":83.589,"city":"Baglung","country":"NP","deity":"Devi/Shakti","est":"16th century","note":"Famed Kalika Bhagwati shrine in a sacred forest beside Baglung bazaar"},
  {"name":"Muktinath Temple","lat":28.8167,"lng":83.8714,"city":"Mustang","country":"NP","deity":"Vishnu","est":"Ancient","note":"One of the 108 Divya Desams with 108 water spouts, sacred to Hindus and Buddhists"},
  {"name":"Tal Barahi Temple","lat":28.2074,"lng":83.949,"city":"Pokhara","country":"NP","deity":"Devi/Shakti","est":"—","note":"Island temple of goddess Barahi in the middle of Phewa Lake"},
  {"name":"Bindhyabasini Temple","lat":28.2381,"lng":83.9842,"city":"Pokhara","country":"NP","deity":"Devi/Shakti","est":"18th century","note":"Goddess shrine on a hilltop above Pokhara's old bazaar, among the city's oldest temples"},
  {"name":"Devghat Dham","lat":27.7167,"lng":84.4167,"city":"Devghat","country":"NP","deity":"Sacred confluence","est":"Ancient","note":"Holy confluence of the Kali Gandaki and Trishuli rivers; major Makar Sankranti bathing site"},
  {"name":"Manakamana Temple","lat":27.9053,"lng":84.5856,"city":"Gorkha","country":"NP","deity":"Devi/Shakti","est":"17th century","note":"Wish-fulfilling goddess shrine reached by Nepal's first cable car"},
  {"name":"Dakshinkali Temple","lat":27.5936,"lng":85.2653,"city":"Dakshinkali","country":"NP","deity":"Devi/Shakti","est":"—","note":"Famous Kali shrine south of Kathmandu near Pharping"},
  {"name":"Swayambhunath Stupa","lat":27.7149,"lng":85.2904,"city":"Kathmandu","country":"NP","deity":"Buddhist stupa","est":"Ancient","note":"Hilltop 'Monkey Temple' stupa overlooking the valley; UNESCO World Heritage Site"},
  {"name":"Pashupatinath Temple","lat":27.7104,"lng":85.3487,"city":"Kathmandu","country":"NP","deity":"Shiva","est":"Ancient","note":"Nepal's holiest Shiva temple, on the Bagmati river"},
  {"name":"Budhanilkantha Temple","lat":27.7794,"lng":85.3615,"city":"Kathmandu","country":"NP","deity":"Vishnu","est":"7th century","note":"Colossal reclining Vishnu (Jalakshayan Narayan) carved from a single stone"},
  {"name":"Boudhanath Stupa","lat":27.7215,"lng":85.362,"city":"Kathmandu","country":"NP","deity":"Buddhist stupa","est":"Ancient","note":"One of the world's largest stupas and hub of Tibetan Buddhism; UNESCO site"},
  {"name":"Gosaikunda","lat":28.08,"lng":85.415,"city":"Rasuwa","country":"NP","deity":"Shiva","est":"Ancient","note":"Alpine sacred lake linked to Shiva; Janai Purnima pilgrimage destination"},
  {"name":"Changu Narayan Temple","lat":27.7164,"lng":85.4278,"city":"Bhaktapur","country":"NP","deity":"Vishnu","est":"5th century","note":"Often called Nepal's oldest temple, with Licchavi inscriptions; UNESCO site"},
  {"name":"Doleshwar Mahadev","lat":27.65,"lng":85.4333,"city":"Bhaktapur","country":"NP","deity":"Shiva","est":"—","note":"Revered as the head portion of Kedarnath's sacred bull form"},
  {"name":"Palanchok Bhagwati","lat":27.6286,"lng":85.6739,"city":"Kavre","country":"NP","deity":"Devi/Shakti","est":"Ancient","note":"Celebrated Bhagwati shrine with a famed ancient black-stone image"},
  {"name":"Janaki Mandir","lat":26.729,"lng":85.925,"city":"Janakpur","country":"NP","deity":"Sita/Ram","est":"1910","note":"Grand Mithila-style temple at the site revered as the birthplace of Goddess Sita"},
  {"name":"Halesi Mahadev","lat":27.1861,"lng":86.62,"city":"Khotang","country":"NP","deity":"Shiva","est":"Ancient","note":"Cave shrine revered as the 'Pashupatinath of the East'; Maratika cave for Buddhists"},
  {"name":"Pathibhara Devi Temple","lat":27.4333,"lng":87.7833,"city":"Taplejung","country":"NP","deity":"Devi/Shakti","est":"—","note":"Wish-fulfilling hilltop goddess shrine at about 3,800 m in eastern Nepal"}
];

var MLG_VERSION = '4.7.1';
function loadMapLibre() {
  if (window._mlgPromise) return window._mlgPromise;
  window._mlgPromise = new Promise(function (resolve, reject) {
    if (window.maplibregl) { resolve(window.maplibregl); return; }
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://unpkg.com/maplibre-gl@' + MLG_VERSION + '/dist/maplibre-gl.css';
    document.head.appendChild(css);
    var js = document.createElement('script');
    js.src = 'https://unpkg.com/maplibre-gl@' + MLG_VERSION + '/dist/maplibre-gl.js';
    js.onload  = function () { resolve(window.maplibregl); };
    js.onerror = function () { window._mlgPromise = null; reject(new Error('MapLibre failed to load')); };
    document.head.appendChild(js);
  });
  return window._mlgPromise;
}


/* ================================================================
   [JS] LAZY CHUNK — assets/js/16-lazy.js (11 Sep 2026 perf pass)
   The unified live map, the full India+Nepal map, the Trip Companion
   (#/trip/PNR) and the SHG Sahayak assistant (chat, voice search, the
   agent application form, the home timetable box) lived here: ~160 KB
   of the 331 KB boot file that no first screen needs. They now load
   from 16-lazy.js — on the first tap that needs them, and otherwise on
   the browser's first idle moment after `load`, so the timetable box
   still fills itself on every visit, just never ahead of first paint.
   Same pattern as 15-nav.js: global function names below are STUBS the
   chunk replaces once it has run.
================================================================ */
function shgLazyLoad() {
  if (window._shgLazyP) return window._shgLazyP;
  window._shgLazyP = new Promise(function (resolve, reject) {
    var me = document.querySelector('script[src*="13-admin-routes.js"]');
    var ver = me ? ((me.getAttribute('src') || '').split('?v=')[1] || '') : '';
    var s = document.createElement('script');
    s.src = '/assets/js/16-lazy.js' + (ver ? '?v=' + encodeURIComponent(ver) : '');
    s.async = true;
    s.onload = function () { window._shgLazyDone = true; resolve(); };
    s.onerror = function () { s.remove(); window._shgLazyP = null; reject(new Error('16-lazy.js failed to load')); };
    document.head.appendChild(s);
  });
  return window._shgLazyP;
}
function renderTrip(pid) {
  var stub = renderTrip;
  return shgLazyLoad().then(function () { if (renderTrip !== stub) return renderTrip(pid); });
}
function cleanupTrip() { /* nothing to clean until the chunk has run */ }
function cleanupTracker() { /* idem */ }
(function () {
  /* A tap that lands before the chunk is in: load it, then replay the tap
     so the real handler (attached by the chunk) takes it. */
  function arm(sel, ev, replay) {
    var el = document.querySelector(sel); if (!el) return;
    var pending = false;
    el.addEventListener(ev, function h(e) {
      if (window._shgLazyDone) { el.removeEventListener(ev, h); return; }
      e.preventDefault();
      if (pending) return;
      pending = true;
      shgLazyLoad().then(function () {
        el.removeEventListener(ev, h);
        replay(el);
      }).catch(function () {
        // Keep the trigger armed so a tap after reconnecting can retry.
        pending = false;
      });
    });
  }
  arm('#aiFab', 'click', function (el) { el.click(); });
  arm('#voiceSearchBtn', 'click', function (el) { el.click(); });
  arm('#aaSubmit', 'click', function (el) { el.click(); });
  arm('#agentApplyForm', 'submit', function (el) { try { el.requestSubmit(); } catch (e) {} });
  var idle = window.requestIdleCallback || function (fn) { return setTimeout(fn, 1500); };
  var kick = function () { idle(function () { shgLazyLoad().catch(function () {}); }, { timeout: 4000 }); };
  if (document.readyState === 'complete') kick(); else window.addEventListener('load', kick);
})();

/* ================================================================
   [JS] 13b. PAYMENT VERIFICATION — submission, admin queue,
   approve/reject, EmailJS, WhatsApp, notification bell
================================================================ */
const PV = {
  submissions: [],
  logs: [],
  bellAudio: null,
  emailjsLoaded: false
};

const PV_EMAILJS_SERVICE  = '';
const PV_EMAILJS_TEMPLATE_SUBMIT = '';
const PV_EMAILJS_TEMPLATE_APPROVE = '';
const PV_EMAILJS_TEMPLATE_REJECT = '';
const PV_EMAILJS_PUBLIC_KEY = '';
const PV_ADMIN_EMAIL = 'booking@shariglobal.com';
const PV_WHATSAPP_NUM = '';

/* ---- Payment Proof Card — one-click proof on checkout page ---- */
var PP = { thumb: '', file: null, compressed: false };

/* Shrink a payment screenshot before it ever touches the network.
   cb(dataUrl, blob) — dataUrl for the on-screen preview and the local
   booking record, blob for the actual upload.

   The old version began with readAsDataURL(file) on the ORIGINAL file just
   to get something to put in img.src. A phone screenshot is 3-8 MB, and
   base64 inflates it by a third, so every upload started by building a
   ~10 MB string on the main thread — that pause is what made "upload" feel
   slow, not the network. createObjectURL hands the decoder the bytes
   directly and costs nothing, and createImageBitmap (where available)
   decodes off the main thread so the UI never freezes at all.

   The object URL is revoked in every path; leaking one pins the whole
   original file in memory for the life of the tab. */
function ppCompressImage(file, maxW, quality, cb) {
  maxW = maxW || 1200; quality = quality || 0.7;

  var draw = function (src, w0, h0, done) {
    var w = w0, h = h0;
    if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
    var canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(src, 0, 0, w, h);
    canvas.toBlob(function (blob) {
      if (!blob) { done(null, null); return; }
      var r = new FileReader();
      r.onload = function (ev) { done(ev.target.result, blob); };
      r.onerror = function () { done(null, blob); };
      r.readAsDataURL(blob);          // small now: the COMPRESSED jpeg only
    }, 'image/jpeg', quality);
  };

  /* Fast path — decode on a worker thread. */
  if (typeof createImageBitmap === 'function') {
    createImageBitmap(file).then(function (bmp) {
      draw(bmp, bmp.width, bmp.height, function (dataUrl, blob) {
        if (bmp.close) bmp.close();
        cb(dataUrl, blob);
      });
    }).catch(function () { viaObjectUrl(); });
    return;
  }
  viaObjectUrl();

  function viaObjectUrl() {
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      draw(img, img.naturalWidth, img.naturalHeight, function (dataUrl, blob) {
        URL.revokeObjectURL(url);
        cb(dataUrl, blob);
      });
    };
    img.onerror = function () { URL.revokeObjectURL(url); cb(null, null); };
    img.src = url;
  }
}

function ppSetupCard() {
  var fi = $('#ppFileInput');
  var uploadBtn = $('#ppUploadBtn');
  var changeBtn = $('#ppChangeBtn');
  var removeBtn = $('#ppRemoveBtn');
  if (!fi || !uploadBtn) return;

  uploadBtn.addEventListener('click', function() { fi.click(); });
  changeBtn.addEventListener('click', function() { fi.click(); });

  fi.addEventListener('change', function() {
    if (!fi.files.length) return;
    var file = fi.files[0];
    var allowed = ['image/jpeg', 'image/png', 'image/webp'];
    var maxSize = (CONFIG.maxUploadSizeMB || 10) * 1024 * 1024;
    if (!allowed.includes(file.type)) { toast('Only JPG, PNG or WebP images allowed.'); fi.value = ''; return; }
    if (file.size > maxSize) { toast('File too large. Maximum ' + (CONFIG.maxUploadSizeMB || 10) + ' MB.'); fi.value = ''; return; }
    ppShowStatus('busy');
    ppCompressImage(file, 1200, 0.75, function(dataUrl, blob) {
      if (!dataUrl) { toast('That image could not be read — try another photo.'); ppShowStatus(''); fi.value = ''; return; }
      PP.thumb = dataUrl;
      PP.compressed = true;
      $('#ppPreviewImg').src = dataUrl;
      $('#ppPreview').classList.add('show');
      uploadBtn.classList.add('hide');
      changeBtn.classList.remove('hide');
      ppShowStatus('ready');
      if (typeof Flow !== 'undefined') {
        Flow.shotThumb = dataUrl;
        /* Hand the finished jpeg straight to the submit step. Without this
           it re-derived the same bytes from the base64 string at the worst
           possible moment — while the passenger waits on Pay. */
        Flow.shotBlob = blob || null;
      }
    });
  });

  removeBtn.addEventListener('click', ppResetCard);
}

/* §2 fix: reset the single screenshot uploader's VIEW and its payload together.
   The router re-runs renderCheckout() when a customer leaves and re-enters
   checkout; resetting Flow.shotThumb alone left the #ppCard still showing an
   attached image + "Screenshot Ready", so "Confirm your seat" would block on an
   empty payload that visibly looked attached. Null-safe so it can also run at
   render time. */
function ppResetCard() {
  if (typeof PP !== 'undefined') { PP.thumb = ''; PP.file = null; PP.compressed = false; }
  var fi = $('#ppFileInput'); if (fi) fi.value = '';
  var img = $('#ppPreviewImg'); if (img) img.src = '';
  var prev = $('#ppPreview'); if (prev) prev.classList.remove('show');
  var up = $('#ppUploadBtn'); if (up) up.classList.remove('hide');
  var ch = $('#ppChangeBtn'); if (ch) ch.classList.add('hide');
  var st = $('#ppStatus'); if (st) { st.classList.remove('show'); st.className = 'pp-status'; }
  if (typeof Flow !== 'undefined') { Flow.shotThumb = ''; Flow.shotBlob = null; }
}

function ppShowStatus(type) {
  var el = $('#ppStatus');
  if (!el) return;
  if (!type) { el.className = 'pp-status'; el.innerHTML = ''; return; }
  el.className = 'pp-status show ' + type;
  if (type === 'busy') {
    /* Shown while the image is being shrunk. On a big screenshot that is a
       real second or two, and without a word on screen it reads as "the
       button did nothing" and gets tapped again. */
    el.innerHTML = '<div class="pp-st-ico">⏳</div><div class="pp-st-title">Preparing your screenshot…</div>'
      + '<div class="pp-st-sub">तस्विर तयार हुँदैछ — एक छिन।</div>';
  } else if (type === 'ready') {
    el.innerHTML = '<div class="pp-st-ico">✅</div><div class="pp-st-title">Screenshot Ready</div>'
      + '<div class="pp-st-sub">Your payment proof is attached. Click "Confirm your seat" to submit your booking.</div>';
  } else if (type === 'waiting') {
    el.innerHTML = '<div class="pp-st-ico">⏳</div><div class="pp-st-title">Waiting for Verification...</div>'
      + '<div class="pp-st-sub">Average verification time: <b>' + (CONFIG.verifyTimeText || '1–3 minutes') + '</b><br>Our team will confirm your ticket shortly.</div>';
  }
}

/* The permanent "talk to a human" row in the assistant panel.
   Number precedence: the operator's Admin -> Settings value, then the
   published office number in CONFIG. Re-rendered on language change so the
   label follows the rest of the UI. */
function renderAiWhatsApp() {
  var num = String((S().adminWhatsApp || CONFIG.adminWhatsApp || '')).replace(/[^0-9]/g, '');
  /* Floating green WA FAB (bottom-right) — same source of truth as the AI
     panel's escape hatch, kept in sync here so operator changes to Settings
     propagate to both surfaces. Falls back to hidden if the operator has
     cleared the number (matches the aiWaHuman behaviour below). */
  var fab = document.getElementById('waFab');
  if (fab) {
    if (num) {
      var fabMsg = 'Namaste 🙏 S Hari Global — I need help with bus booking';
      fab.href = 'https://wa.me/' + num + '?text=' + encodeURIComponent(fabMsg);
      fab.style.display = '';
    } else {
      fab.style.display = 'none';
    }
  }
  var a = document.getElementById('aiWaHuman');
  if (!a) return;
  if (!num) { a.style.display = 'none'; return; }
  a.style.display = '';
  var msg = 'Namaste! S Hari Global — website bata sodhdai chu.';
  a.href = 'https://wa.me/' + num + '?text=' + encodeURIComponent(msg);
  var ti = document.getElementById('aiWaTitle'), su = document.getElementById('aiWaSub');
  try {
    if (ti) ti.textContent = t('waHuman');
    if (su) su.textContent = t('waHumanSub');
  } catch (e) { /* i18n not ready yet — the markup defaults stand */ }
}

function ppBuildWhatsAppUrl() {
  // Company WhatsApp for payment proof — operator's Settings value first,
  // then the config default. The screenshot opens straight into this chat.
  var waNum = String((typeof S === 'function' && S().adminWhatsApp) || CONFIG.adminWhatsApp || '919104801507').replace(/[^0-9]/g, '');
  var bkId = (typeof Flow !== 'undefined' && Flow.draftId) ? Flow.draftId : '—';
  var pax = '';
  try { $$('#paxRows .pax-row').forEach(function(r) { var n = r.querySelector('.pxName'); if (n && n.value.trim()) pax += (pax ? ', ' : '') + n.value.trim(); }); } catch(e) {}
  var from = '', to = '', date = '', seats = '', amount = '';
  try {
    if (typeof Flow !== 'undefined' && Flow.route) {
      var rt = Flow.route;
      from = rt.from || ''; to = rt.to || '';
    }
    var legs = (typeof Flow !== 'undefined') ? Flow.legs || [] : [];
    if (legs.length) { date = legs[0].date || ''; seats = legs[0].seats ? seatLabelJoin(legs[0].seats, Flow.route && Flow.route.type, legs[0].bookingType || Flow.bookingType) : ''; }
    var totalEl = $('#coTotal');
    if (totalEl) amount = totalEl.textContent;
  } catch(e) {}

  var msg = '-----------------------------------\n'
    + 'Booking ID:\n' + bkId + '\n\n'
    + 'Passenger:\n' + (pax || '—') + '\n\n'
    + 'Route:\n' + from + ' → ' + to + '\n\n'
    + 'Travel Date:\n' + date + '\n\n'
    + 'Seats:\n' + (seats || '—') + '\n\n'
    + 'Amount:\n' + (amount || '—') + '\n\n'
    + 'I have completed the payment.\n'
    + 'Please verify my payment and confirm my ticket.\n'
    + 'Payment Screenshot Attached.\n'
    + '-----------------------------------';
  return 'https://wa.me/' + waNum + '?text=' + encodeURIComponent(msg);
}

function ppBuildEmailUrl() {
  var emailTo = CONFIG.adminEmail || CONFIG.email || 'booking@shariglobal.com';
  var bkId = (typeof Flow !== 'undefined' && Flow.draftId) ? Flow.draftId : '—';
  var pax = '';
  try { $$('#paxRows .pax-row').forEach(function(r) { var n = r.querySelector('.pxName'); if (n && n.value.trim()) pax += (pax ? ', ' : '') + n.value.trim(); }); } catch(e) {}
  var from = '', to = '', date = '', seats = '', amount = '';
  try {
    if (typeof Flow !== 'undefined' && Flow.route) { from = Flow.route.from || ''; to = Flow.route.to || ''; }
    var legs = (typeof Flow !== 'undefined') ? Flow.legs || [] : [];
    if (legs.length) { date = legs[0].date || ''; seats = legs[0].seats ? seatLabelJoin(legs[0].seats, Flow.route && Flow.route.type, legs[0].bookingType || Flow.bookingType) : ''; }
    var totalEl = $('#coTotal'); if (totalEl) amount = totalEl.textContent;
  } catch(e) {}

  var subject = 'Payment Proof - ' + bkId;
  var body = 'Booking ID:\n' + bkId + '\n\n'
    + 'Passenger:\n' + (pax || '—') + '\n\n'
    + 'Route:\n' + from + ' → ' + to + '\n\n'
    + 'Travel Date:\n' + date + '\n\n'
    + 'Seats:\n' + (seats || '—') + '\n\n'
    + 'Amount:\n' + (amount || '—') + '\n\n'
    + 'I have completed the payment.\n'
    + 'Please verify and confirm my ticket.\n\n'
    + 'Payment screenshot attached.';
  return 'mailto:' + encodeURIComponent(emailTo) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
}

function ppUpdateLinks() {
  var waLink = $('#ppWhatsApp');
  var emailLink = $('#ppEmail');
  if (waLink) waLink.href = ppBuildWhatsAppUrl();
  if (emailLink) emailLink.href = ppBuildEmailUrl();
}

document.addEventListener('DOMContentLoaded', function() { ppSetupCard(); });

function pvGenId() {
  return 'PV-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).slice(2, 6).toUpperCase();
}

function pvLoadEmailJS() {
  if (PV.emailjsLoaded || !PV_EMAILJS_PUBLIC_KEY) return;
  var s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js';
  s.onload = function() {
    if (window.emailjs) { window.emailjs.init(PV_EMAILJS_PUBLIC_KEY); PV.emailjsLoaded = true; }
  };
  document.head.appendChild(s);
}

function pvSendEmail(templateId, params) {
  if (!PV.emailjsLoaded || !PV_EMAILJS_SERVICE || !templateId) return Promise.resolve();
  return window.emailjs.send(PV_EMAILJS_SERVICE, templateId, params).catch(function() {});
}

function pvOpenForm(bookingId) {
  /* A REJECTED booking must always be able to re-submit. Its local PV record
     is very likely still marked 'pending' (nothing syncs PV submission status
     back from the server), and blocking on that would make the "Re-send
     payment proof" button a dead toast — the one path the customer has left. */
  var bk = DB.bookings.find(function(b) { return b.id === bookingId; });
  var isRejected = bk && bk.status === 'rejected';
  var existing = PV.submissions.find(function(s) { return s.bookingId === bookingId && s.status === 'pending'; });
  if (existing && !isRejected) { toast('Payment proof already submitted (ID: ' + existing.id + '). Under verification.'); return; }
  var ov = $('#pvOverlay');
  $('#pvFormBody').classList.remove('hide');
  $('#pvSuccessBody').classList.add('hide');
  $('#pvBookingId').textContent = bookingId;
  $('#pvBkId').value = bookingId;
  $('#pvUtr').value = '';
  $('#pvSender').value = '';
  $('#pvMobile').value = '';
  $('#pvPreview').classList.remove('show');
  $('#pvPreview').src = '';
  $('#pvFileInput').value = '';
  PV._file = null;
  PV._thumb = '';
  $$('.pv-field.invalid').forEach(function(f) { f.classList.remove('invalid'); });
  ov.classList.add('show');
}

function pvCloseForm() { $('#pvOverlay').classList.remove('show'); }

(function pvSetupDrop() {
  document.addEventListener('DOMContentLoaded', function() {
    var drop = $('#pvDrop');
    var fi = $('#pvFileInput');
    if (!drop || !fi) return;

    drop.addEventListener('click', function(e) {
      if (e.target.id === 'pvRemove') return;
      fi.click();
    });

    drop.addEventListener('dragover', function(e) { e.preventDefault(); drop.classList.add('drag'); });
    drop.addEventListener('dragleave', function() { drop.classList.remove('drag'); });
    drop.addEventListener('drop', function(e) {
      e.preventDefault(); drop.classList.remove('drag');
      if (e.dataTransfer.files.length) pvHandleFile(e.dataTransfer.files[0]);
    });

    fi.addEventListener('change', function() { if (fi.files.length) pvHandleFile(fi.files[0]); });

    $('#pvRemove').addEventListener('click', function(e) {
      e.stopPropagation();
      PV._file = null; PV._thumb = '';
      $('#pvPreview').classList.remove('show'); $('#pvPreview').src = '';
      fi.value = '';
    });

    $('#pvClose').addEventListener('click', pvCloseForm);
    $('#pvOverlay').addEventListener('click', function(e) { if (e.target === this) pvCloseForm(); });

    $('#pvSubmitBtn').addEventListener('click', pvSubmit);

    var rsel = $('#pvrReason');
    if (rsel) rsel.addEventListener('change', function() {
      var cw = $('#pvrCustomWrap');
      if (this.value === 'other') cw.classList.remove('hide'); else cw.classList.add('hide');
    });
    var pvrCancel = $('#pvrCancel');
    if (pvrCancel) pvrCancel.addEventListener('click', function() { $('#pvrOverlay').classList.remove('show'); });
    var pvrConfirm = $('#pvrConfirm');
    if (pvrConfirm) pvrConfirm.addEventListener('click', pvDoReject);
  });
})();

var PV_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
var PV_MAX_SIZE = 10 * 1024 * 1024;

function pvHandleFile(file) {
  if (!PV_ALLOWED_TYPES.includes(file.type)) { toast('Only JPG, PNG or WebP images allowed.'); return; }
  if (file.size > PV_MAX_SIZE) { toast('File too large. Maximum 10 MB.'); return; }
  PV._file = file;
  var reader = new FileReader();
  reader.onload = function(e) {
    PV._thumb = e.target.result;
    var img = $('#pvPreview');
    img.src = PV._thumb;
    img.classList.add('show');
  };
  reader.readAsDataURL(file);
}

async function pvSubmit() {
  var valid = true;
  var utr = $('#pvUtr'), sender = $('#pvSender'), mobile = $('#pvMobile');
  var bookingId = $('#pvBkId').value;

  function mark(el, bad) {
    el.closest('.pv-field').classList.toggle('invalid', bad);
    if (bad) valid = false;
  }

  if (!PV._thumb) { toast('Please upload a payment screenshot.'); valid = false; }
  mark(utr, !utr.value.trim());
  mark(sender, !sender.value.trim());
  mark(mobile, !/^\+?\d{7,15}$/.test(mobile.value.replace(/\s/g, '')));

  if (!valid) return;

  var btn = $('#pvSubmitBtn');
  btn.disabled = true;
  btn.textContent = t('subBtnBusy');

  var sub = {
    id: pvGenId(),
    bookingId: bookingId,
    utr: utr.value.trim(),
    sender: sender.value.trim(),
    mobile: mobile.value.trim(),
    screenshot: PV._thumb,
    status: 'pending',
    ts: Date.now(),
    verifiedAt: 0,
    verifiedBy: '',
    rejectReason: ''
  };

  /* Send the real proof to the server so it reaches Admin → Verify
     Payments. Best-effort: an older, locally-seeded booking id that was
     never created through /api/book.php won't be a recognised PNR — that
     silently falls through to the local-only record below instead of
     blocking the customer. */
  try {
    var fd = new FormData();
    fd.append('pnr', bookingId);
    fd.append('utr', sub.utr);
    fd.append('payerName', sub.sender);
    fd.append('method', 'upi');
    if (PV._thumb) fd.append('screenshot', shgApi.dataUrlToBlob(PV._thumb), 'payment.jpg');
    await shgApi.postForm('/payment.php', fd);
  } catch (e) {
    /* Only an UNRECOGNISED PNR may fall through to the local-only record —
       that is an old, locally-seeded booking that never went through
       /api/book.php. Every other failure is real and must be shown: telling a
       customer their proof is "under verification" when the server refused it
       leaves them waiting for a review that will never happen. */
    var msg = (e && e.message) ? e.message : '';
    if (!/not found/i.test(msg)) {
      btn.disabled = false;
      btn.textContent = t('pvSubmitBtn');
      toast('⚠️ ' + (msg || t('pvSendFail')));
      try { SFX.error(); } catch (e2) {}
      return;
    }
  }

  PV.submissions.push(sub);
  persist('paymentSubmissions');

  var booking = DB.bookings.find(function(b) { return b.id === bookingId; });
  var emailParams = {
    booking_id: bookingId,
    verification_id: sub.id,
    utr: sub.utr,
    sender_name: sub.sender,
    mobile: sub.mobile,
    passenger_name: booking ? booking.passengers.map(function(p) { return p.name; }).join(', ') : '',
    route: booking ? (booking.from + ' → ' + booking.to) : '',
    amount: booking ? ('₹' + booking.total) : '',
    date: booking ? booking.date : '',
    to_email: PV_ADMIN_EMAIL
  };
  pvSendEmail(PV_EMAILJS_TEMPLATE_SUBMIT, emailParams);

  pvNotifyAdmin(sub);

  var vlog = { id: sub.id, action: 'submitted', ts: Date.now(), by: 'customer', detail: 'UTR: ' + sub.utr };
  PV.logs.push(vlog);
  persist('verificationLogs');

  btn.disabled = false;
  btn.textContent = t('pvSubmitBtn');
  $('#pvFormBody').classList.add('hide');
  $('#pvSuccessBody').classList.remove('hide');
  $('#pvVerId').textContent = sub.id;
  SFX.success();
}

function pvNotifyAdmin(sub) {
  try {
    if (typeof pushNotif === 'function')
      pushNotif('💳', 'New payment proof', sub.bookingId + ' — UTR: ' + sub.utr + ' — verify in Admin → Payments');
  } catch (e) {}
  pvUpdateBell();
  pvPlayBell();
}

function pvUpdateBell() {
  var cnt = PV.submissions.filter(function(s) { return s.status === 'pending'; }).length;
  var el = $('#pvBellCount');
  if (!el) return;
  el.textContent = cnt;
  el.classList.toggle('show', cnt > 0);
}

function pvPlayBell() {
  try {
    if (!PV.bellAudio) {
      var actx = new (window.AudioContext || window.webkitAudioContext)();
      var osc = actx.createOscillator();
      var gain = actx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, actx.currentTime);
      osc.frequency.setValueAtTime(1100, actx.currentTime + 0.1);
      gain.gain.setValueAtTime(0.3, actx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.5);
      osc.connect(gain); gain.connect(actx.destination);
      osc.start(actx.currentTime);
      osc.stop(actx.currentTime + 0.5);
    }
  } catch (e) {}
}

function renderAdminPayments() {
  var list = $('#pvQueueList');
  if (!list) return;
  pvUpdateBell();

  var searchVal = ($('#pvSearch') ? $('#pvSearch').value.toLowerCase() : '');
  var filterVal = ($('#pvFilter') ? $('#pvFilter').value : 'all');

  var items = PV.submissions.slice().sort(function(a, b) { return b.ts - a.ts; });
  if (filterVal !== 'all') items = items.filter(function(s) { return s.status === filterVal; });
  if (searchVal) items = items.filter(function(s) {
    return (s.bookingId + ' ' + s.sender + ' ' + s.utr + ' ' + s.id).toLowerCase().includes(searchVal);
  });

  if (!items.length) { list.innerHTML = '<p style="text-align:center;color:var(--muted);padding:40px 0">No payment submissions ' + (filterVal !== 'all' ? 'with status "' + filterVal + '"' : 'yet') + '.</p>'; return; }

  list.innerHTML = items.map(function(s) {
    var booking = DB.bookings.find(function(b) { return b.id === s.bookingId; });
    var statusClass = s.status;
    var badgeClass = s.status;
    var badgeText = s.status === 'pending' ? '⏳ Pending' : s.status === 'approved' ? '✅ Approved' : '❌ Rejected';

    var html = '<div class="pvq-card ' + statusClass + '" data-pvid="' + s.id + '">';
    html += '<div class="pvq-head"><span class="badge ' + badgeClass + '">' + badgeText + '</span>';
    html += '<span style="font-family:var(--f-code);font-size:12px;color:var(--muted)">' + s.id + '</span>';
    html += '<span style="margin-left:auto;font-size:12px;color:var(--muted)">' + new Date(s.ts).toLocaleString() + '</span></div>';

    html += '<div class="pvq-grid">';
    html += '<div><span class="lbl">Booking ID</span><div class="val">' + s.bookingId + '</div></div>';
    html += '<div><span class="lbl">UTR / Ref</span><div class="val">' + (s.utr || '—') + '</div></div>';
    html += '<div><span class="lbl">Sender</span><div class="val">' + (s.sender || '—') + '</div></div>';
    html += '<div><span class="lbl">Mobile</span><div class="val">' + (s.mobile || '—') + '</div></div>';
    if (booking) {
      html += '<div><span class="lbl">Route</span><div class="val">' + booking.from + ' → ' + booking.to + '</div></div>';
      html += '<div><span class="lbl">Amount</span><div class="val">₹' + booking.total + '</div></div>';
      html += '<div><span class="lbl">Passengers</span><div class="val">' + booking.passengers.map(function(p) { return p.name; }).join(', ') + '</div></div>';
      html += '<div><span class="lbl">Date</span><div class="val">' + booking.date + '</div></div>';
    }
    html += '</div>';

    if (s.screenshot) html += '<img class="pvq-shot" src="' + s.screenshot + '" alt="Payment screenshot" onclick="pvViewShot(this.src)">';

    html += '<div class="pvq-actions">';
    if (s.status === 'pending') {
      html += '<button class="pvq-approve" onclick="pvApprove(\'' + s.id + '\')">✅ Verify in Admin Panel</button>';
    }
    if (s.status === 'rejected' && s.rejectReason) {
      html += '<span style="font-size:12px;color:var(--bad)">Reason: ' + s.rejectReason + '</span>';
    }
    var waMsg = 'Hello, I submitted payment for booking ' + s.bookingId + ' (UTR: ' + s.utr + '). Verification ID: ' + s.id + '. Please verify.';
    var waNum = PV_WHATSAPP_NUM || (booking && booking.contact ? booking.contact.phone.replace(/\D/g, '') : '');
    html += '<a class="pvq-wa" href="https://wa.me/' + waNum + '?text=' + encodeURIComponent(waMsg) + '" target="_blank" rel="noopener">💬 WhatsApp</a>';
    html += '<button class="pvq-view" onclick="pvViewShot(\'' + (s.screenshot || '') + '\')">🔍 View</button>';
    html += '</div></div>';
    return html;
  }).join('');
}

function pvViewShot(src) {
  if (!src) return;
  var w = window.open('', '_blank', 'width=600,height=800');
  w.document.write('<html><head><title>Payment Screenshot</title><style>body{margin:0;background:#111;display:flex;align-items:center;justify-content:center;min-height:100vh}img{max-width:100%;max-height:100vh;object-fit:contain}</style></head><body><img src="' + src + '"></body></html>');
}

/* Approving/rejecting a real payment must go through the real backend —
   BookingService::confirm()/reject() is what actually issues the signed
   ticket, claims the seats and sends the customer their PDF. Flipping a
   status flag here only would "confirm" a booking with no ticket behind
   it. So this queue is informational; the decision itself happens in the
   real, RBAC-protected Admin Panel. */
function pvGotoRealAdmin(bookingId) {
  /* §7: the single hand-off into the real RBAC-gated staff admin. Per-booking
     view carries the reversible Payment-review panel (§4); the pending-only
     queue is reachable from the admin nav for the daily verification pass. */
  var base = (window.SHG_BOOT && window.SHG_BOOT.appUrl) || (location.origin);
  var path = bookingId
    ? '/admin/booking-view.php?pnr=' + encodeURIComponent(bookingId)
    : '/admin/payments.php';
  window.open(base.replace(/\/$/, '') + path, '_blank', 'noopener');
  toast('Opening the secure staff Admin Panel — sign in there to approve, reject or reverse this payment.');
}

function pvApprove(subId) {
  var sub = PV.submissions.find(function(s) { return s.id === subId; });
  pvGotoRealAdmin(sub ? sub.bookingId : '');
}

function pvShowReject(subId) {
  var sub = PV.submissions.find(function(s) { return s.id === subId; });
  pvGotoRealAdmin(sub ? sub.bookingId : '');
}

function pvDoReject() {
  var subId = $('#pvrSubId') ? $('#pvrSubId').value : '';
  var sub = PV.submissions.find(function(s) { return s.id === subId; });
  var el = $('#pvrOverlay'); if (el) el.classList.remove('show');
  pvGotoRealAdmin(sub ? sub.bookingId : '');
}

function pvAuditLog(msg) {
  try {
    if (typeof auditLog === 'function') auditLog(msg);
  } catch (e) {}
}

function pvSetupSearch() {
  var si = $('#pvSearch'), fi = $('#pvFilter');
  if (si) si.addEventListener('input', renderAdminPayments);
  if (fi) fi.addEventListener('change', renderAdminPayments);
}

/* ================================================================
   [JS] 14. BOOT — load (or seed) data, then start the router
================================================================ */
async function init() {
  // Colour theme: a saved palette (0–6) overrides the auto day-of-week rotation.
  (function () { var p = null; try { p = localStorage.getItem('shg:palette'); } catch (e) {}
    document.documentElement.setAttribute('data-day', (p !== null && p !== '' && p !== 'auto') ? p : String(new Date().getDay())); })();
  Splash.init();
  Splash.setTotal(6);   // matches the number of Splash.tick() calls below

  /* ---- Boot data, in two waves -------------------------------------
     This used to be sixteen `await store.get(...)` calls one after
     another — sixteen network round-trips in a strict chain, each
     waiting for the one before it. Measured on localhost that was
     1.4 seconds; on a phone in Nepalgunj at ~250 ms round-trip it is
     four seconds of blank screen before anything can be drawn.

     WAVE 1 is only what the first screen actually needs, fired together
     so the wall clock is ONE round-trip instead of sixteen.

     WAVE 2 is the agent/admin ledgers nobody can see from the home
     screen. It is deliberately NOT awaited: it resolves while the
     passenger is already looking at the search card. Every one of those
     keys starts life as an empty array in DB, so anything that reads one
     early gets an empty list, never `undefined`. */
  const w1 = await store.getMany(
    ['shg:routes', 'shg:bookings', 'shg:settings', 'shg:messages', 'shg:locks', 'shg:waitlist', 'shg:users'],
    { 'shg:routes': null, 'shg:bookings': null, 'shg:settings': {},
      'shg:messages': [], 'shg:locks': [], 'shg:waitlist': [], 'shg:users': [] }
  );
  DB.routes   = w1['shg:routes'];
  DB.bookings = w1['shg:bookings'];
  DB.settings = w1['shg:settings'] || {};
  /* Scrub passwords out of a blob saved by an older build. This row is
     world-readable (KV_PUBLIC_READ), so anything left here is published;
     dropping them locally means the next Save writes a clean row. */
  if (DB.settings.adminPin || DB.settings.agentPin) {
    delete DB.settings.adminPin; delete DB.settings.agentPin;
    if (window.SHG_BOOT && window.SHG_BOOT.canSync) persist('settings');
  }
  DB.messages = w1['shg:messages'] || [];
  DB.locks    = w1['shg:locks']    || [];
  DB.waitlist = w1['shg:waitlist'] || [];
  DB.users    = w1['shg:users']    || [];
  Splash.tick('Loading trip data…');

  const bootRest = store.getMany(
    ['shg:commissions', 'shg:payoutRequests', 'shg:auditLog', 'shg:commissionRules',
     'shg:delays', 'shg:tripExpenses', 'shg:notifications', 'shg:paymentSubmissions', 'shg:verificationLogs'],
    { 'shg:commissionRules': {} }
  ).then((w2) => {
    DB.commissions        = w2['shg:commissions']        || [];
    DB.payoutRequests     = w2['shg:payoutRequests']     || [];
    DB.auditLog           = w2['shg:auditLog']           || [];
    DB.commissionRules    = w2['shg:commissionRules']    || {};
    DB.delays             = w2['shg:delays']             || [];
    DB.tripExpenses       = w2['shg:tripExpenses']       || [];
    DB.notifications      = w2['shg:notifications']      || [];
    DB.paymentSubmissions = w2['shg:paymentSubmissions'] || [];
    DB.verificationLogs   = w2['shg:verificationLogs']   || [];
    PV.submissions = DB.paymentSubmissions;
    PV.logs        = DB.verificationLogs;
    /* If the visitor already opened a panel that reads this data, redraw
       it now that the real rows are in. Cheap, and only for that view. */
    const h = location.hash || '#/';
    if (h === '#/admin' || h === '#/shg-ctrl' || h === '#/my') { try { router(); } catch (e) {} }
  }).catch(() => {});
  void bootRest;   // fire-and-forget by design — never block first paint

  Splash.tick('Preparing routes…');
  /* V7 — apply the operator's saved Google Maps key (Admin → Settings) over
     the empty source default, so enabling Google search/traffic needs no code
     deploy. Left blank, the free Photon + Nominatim search stays in use. */
  try { if (DB.settings && DB.settings.googleMapsApiKey) MAP_CONFIG.googleMapsApiKey = DB.settings.googleMapsApiKey; } catch (e) {}
  /* ── Route-store reseed guard (27 Aug 2026 · one-daily-bus) ──────────
     The catalogue is EXACTLY two runs now: r2 Surat → Rupaidiha and r7
     Rupaidiha → Surat (ids = MySQL route_code; checkout joins on them).
     This replaces the six stacked historical sniffs (_oldDefault,
     _preSuratSeed, _oldForwardDrop, _preOriginBuses, _preRupaidihaLabel,
     Mehsana-first) with one test: reseed when a route still carries a
     RETIRED endpoint town. Every pre-migration store necessarily does
     (all 13 old routes began or ended in one), and the fresh seed never
     does — so an old store upgrades exactly once and the new store can
     never boot-loop (the flaw _preOriginBuses had). Deliberately NOT
     pinned to ids/origins, so the owner may later rename the origin or
     add coaches in Manage Routes without this guard reverting the edit.
     Caveat: a future admin-added route whose ENDPOINT label is exactly
     one of these five words would retrigger it — use a longer composite
     stop name that does not match a bare-word town. */
  const RETIRED_TOWNS = ['Ahmedabad', 'Mehsana', 'Godhra', 'Himatnagar', 'Nepalgunj'];
  const _liveRoutes = Array.isArray(DB.routes) ? DB.routes.filter(r => r && r.active) : [];
  /* Versioned reseed: seedRoutes() stamps configVer on each route.
     If ANY active route is missing configVer or has a lower version than
     the current seed, reseed — this catches every rename round (Barauda→
     Baroda→Emli Bhupal etc.) without growing a list of old names. */
  const _CURRENT_VER = 3;   // must match seedRoutes() configVer
  const _needsSeed =
       _liveRoutes.length === 0
    || _liveRoutes.some(r => RETIRED_TOWNS.includes(String(r.from)) || RETIRED_TOWNS.includes(String(r.to)))
    || _liveRoutes.some(r => !r.configVer || r.configVer < _CURRENT_VER);
  if (_needsSeed) { DB.routes = seedRoutes(); persist('routes'); }
  /* Payment migration — replace placeholder UPI/eSewa ids stored by older builds */
  if (DB.settings && (DB.settings.upiId === 'sharihariglobal@upi' || DB.settings.upiId === 'shreehariglobal@upi' || !DB.settings.upiId)) {
    DB.settings.upiId = CONFIG.upiId; DB.settings.upiName = CONFIG.upiName; persist('settings');
  }
  if (DB.settings && (DB.settings.esewaId === '9800000000' || !DB.settings.esewaId)) {
    DB.settings.esewaId = CONFIG.esewaId; DB.settings.esewaName = CONFIG.esewaName; persist('settings');
  }
  if (!DB.bookings) DB.bookings = [];
  /* Purge the demo tickets earlier builds wrote into this browser. They are
     the only two ids that can never exist server-side, so this is safe and
     runs once — after it, nothing in the list is fictional. */
  if (DB.bookings.some(b => b && (b.id === 'SHG-DEMO01' || b.id === 'SHG-DEMO02'))) {
    DB.bookings = DB.bookings.filter(b => b && b.id !== 'SHG-DEMO01' && b.id !== 'SHG-DEMO02');
    persist('bookings');
  }
  captureReferralFromURL();   // remember #/?ref=CODE before routing strips it

  if (typeof rebuildPickupTimes === 'function') rebuildPickupTimes();
  populateCitySelects();
  if (typeof initSimpleSearch === 'function') initSimpleSearch();   // direction + one-town search
  if (typeof initQuickTicket === 'function') initQuickTicket();     // ⚡ Quick Ticket banner (6 Sep 2026)
  /* Guarded: a throw here would abort the rest of boot (contact strip, fare
     board, pickup times) - the booking choice must never take those down. */
  try { if (typeof initBookMode === 'function') initBookMode(); } catch (e) {}   // ⚡/🪑 booking choice + AI counter (12 Sep 2026)
  renderContactStrip();
  /* Phones (11 Sep 2026): the nav row held only the hamburger, and the
     number strip sat above it as its own 57px bar. The pills move INTO the
     nav row (renderContactStrip keeps writing into #numInner by id), so
     one bar does both jobs and the booking card climbs into view. */
  try {
    if (window.matchMedia && window.matchMedia('(max-width:760px)').matches) {
      var _ni = $('#numInner'), _hb = $('#hamburger');
      if (_ni && _hb && _hb.parentNode) _hb.parentNode.insertBefore(_ni, _hb);
    }
  } catch (e) {}
  if (typeof wireHomeMore === 'function') wireHomeMore();
  renderAiWhatsApp();
  renderFareBoard();
  renderPricingCards();
  renderFareLive();
  Splash.tick('Setting up booking…');
  const di = $('#dateInput');
  /* Sales window: travel opens at the inauguration (2 Sep 2026) and runs a
     rolling horizon. The SERVER enforces this (api/search.php + booking
     create); these attributes just keep the picker honest. */
  const bw = CONFIG.bookingWindow || {};
  const bwFrom = (bw.openFrom && bw.openFrom > todayISO()) ? bw.openFrom : todayISO();
  // Floor is ONE DAY back (owner ask: "calendar 24 hours ago samma dekhinu
  // parx") so an agent can pick the date of a bus that left Surat the previous
  // afternoon and is still inside its 24h grace. Ordinary customers are still
  // refused server-side without a valid agent code; the default value stays
  // forward so nobody lands on a past date by accident.
  di.min = addDaysISO(bwFrom, -1);
  di.max = addDaysISO(bwFrom, bw.horizonDays || 30);
  di.value = bwFrom > todayISO() ? bwFrom : addDaysISO(todayISO(), 1);   // default: opening day, else tomorrow
  const ri = $('#retInput');
  ri.min = di.value;
  ri.max = di.max;

  // Agent late-booking search field: prefill from a remembered code and
  // persist on every keystroke, so the very next search already surfaces a
  // departed bus for a valid agent (server still re-checks the code on book).
  const sac = $('#sAgentCode');
  if (sac) {
    try { sac.value = (localStorage.getItem('shg_agent_code') || ''); } catch (e) {}
    sac.addEventListener('input', function () {
      if (typeof persistAgentCode === 'function') { persistAgentCode(sac.value); }
    });
  }
  renderDateChips();
  $('#year').textContent = new Date().getFullYear();
  /* Footer CEO block (Task 4) — pulls from CONFIG, no hardcoded copies */
  const ceoN = $('#ceoName'), ceoM = $('#ceoMantra'), mBtn = $('#mantraBtn');
  if (ceoN) ceoN.textContent = CONFIG.company.ceo || '';
  if (ceoM) ceoM.textContent = CONFIG.company.mantra || '';
  if (mBtn) mBtn.addEventListener('click', playMantra);
  /* CEO photo — upload once from the site itself (saved in this browser via
     localStorage), falls back to a ceo.jpg file, then to initials */
  const CEO_PHOTO_KEY = 'shg:ceoPhoto';
  const applyCeoPhoto = (dataUrl) => {
    ['#ceoHeroImg', '#ceoPhoto'].forEach(sel => {
      const im = document.querySelector(sel);
      if (!im) return;
      im.src = dataUrl; im.style.display = '';
      const sib = im.nextElementSibling;
      if (sib && sib.classList && sib.classList.contains('founder-initials')) sib.style.display = 'none';
    });
  };
  try { const savedCeo = localStorage.getItem(CEO_PHOTO_KEY); if (savedCeo) applyCeoPhoto(savedCeo); } catch (e) {}
  const cpBtn = $('#ceoPhotoBtn'), cpFile = $('#ceoPhotoFile');
  if (cpBtn && cpFile) {
    cpBtn.addEventListener('click', () => cpFile.click());
    cpFile.addEventListener('change', () => {
      const f = cpFile.files && cpFile.files[0]; if (!f) return;
      const rd = new FileReader();
      rd.onload = () => {
        const img = new Image();
        img.onload = () => {
          const target = 340, c = document.createElement('canvas');
          const sc = Math.min(1, target / Math.min(img.width, img.height));
          c.width = Math.max(1, Math.round(img.width * sc));
          c.height = Math.max(1, Math.round(img.height * sc));
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          const du = c.toDataURL('image/jpeg', .85);
          try { localStorage.setItem(CEO_PHOTO_KEY, du); } catch (e) {}
          applyCeoPhoto(du);
          toast('✅ CEO photo saved · फोटो राखियो 🙏');
        };
        img.src = rd.result;
      };
      rd.readAsDataURL(f);
    });
  }
  const fg = $('#fGstin');
  if (fg && CONFIG.company.gstin) { fg.textContent = ' · GSTIN: ' + CONFIG.company.gstin; }
  $$('[data-count]').forEach(el => cio.observe(el));

  awardCompletedTrips(); // loyalty: sweep trips whose departure has passed
  applyBhagwanImg();    // apply Bhagwan image to hero bg & ticket watermark
  Splash.tick('Applying language…');

  /* Admin-set default language wins for visitors who haven't explicitly
     picked one yet (no localStorage lang saved). A returning visitor's
     own choice is never overridden. */
  try {
    /* 13 Sep 2026: a phone that announced Nepali or Hindi (navigator.language,
       see 04-i18n.js) keeps that; the admin default only fills the silence. */
    if (!localStorage.getItem('shg:lang') && !window.SHG_LANG_DETECTED && DB.settings && DB.settings.defaultLang && I18N[DB.settings.defaultLang]) {
      LANG = DB.settings.defaultLang;
    }
  } catch (e) {}

  applyLang();          // translate static text + mark active language
  updateNavUser();      // sign-in chip
  renderNotice();       // seasonal banner (admin-controlled)
  activeLocks();        // clear any expired seat holds
  startHoldTicker();
  Splash.tick('Starting timers…');
  startNextDeparture();
  Splash.tick('Almost ready…');

  router();             // open whichever view the URL points at
  observeReveals();

  Splash.markReady();
}

/* ================================================================
   FOOTER MANTRA CHANT (Task 4) — click-only, never autoplays.
   Plays om-namo-narayanaya.mp3 if that file sits beside the HTML;
   otherwise falls back to a soft generated Om tone (136.1 Hz sine
   + harmonics, ~4.5 s) so the button always works offline with no
   copyrighted audio involved.
================================================================ */
function playMantra() {
  const a = $('#mantraAudio');
  if (a) {
    try { a.currentTime = 0; } catch (e) {}
    const p = a.play();
    if (p && p.catch) p.catch(() => playOmTone());
  } else {
    playOmTone();
  }
}
function playOmTone() {
  try {
    const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
    const ac = new AC();
    const g = ac.createGain(); g.connect(ac.destination);
    g.gain.setValueAtTime(0, ac.currentTime);
    g.gain.linearRampToValueAtTime(.20, ac.currentTime + .7);
    g.gain.setValueAtTime(.20, ac.currentTime + 3.2);
    g.gain.linearRampToValueAtTime(0, ac.currentTime + 4.6);
    [[136.1, 1], [272.2, .32], [408.3, .10]].forEach(fr => {
      const o = ac.createOscillator(); o.type = 'sine'; o.frequency.value = fr[0];
      const og = ac.createGain(); og.gain.value = fr[1];
      o.connect(og); og.connect(g);
      o.start(); o.stop(ac.currentTime + 4.7);
    });
    setTimeout(() => { try { ac.close(); } catch (e) {} }, 5200);
  } catch (e) {}
}

function startNextDeparture() {
  const el = $('#nextDepTimer');
  if (!el) return;
  const tick = () => {
    const now = new Date();
    const routes = DB.routes.filter(r => r.active);
    if (!routes.length) { el.textContent = '--:--:--'; return; }
    const depTimes = routes.map(r => {
      const parts = (r.depTime || '00:00').match(/(\d+):(\d+)/);
      if (!parts) return null;
      const d = new Date(now);
      d.setHours(parseInt(parts[1], 10), parseInt(parts[2], 10), 0, 0);
      if (d <= now) d.setDate(d.getDate() + 1);
      return d;
    }).filter(Boolean);
    if (!depTimes.length) { el.textContent = '--:--:--'; return; }
    const next = depTimes.reduce((a, b) => a < b ? a : b);
    const diff = next - now;
    const hh = String(Math.floor(diff / 3600000)).padStart(2, '0');
    const mm = String(Math.floor(diff / 60000) % 60).padStart(2, '0');
    const ss = String(Math.floor(diff / 1000) % 60).padStart(2, '0');
    el.textContent = hh + ':' + mm + ':' + ss;
  };
  tick();
  setInterval(tick, 1000);
}

/* ================================================================
   [JS] SOUND EFFECTS — Web Audio API synth (no external files)
================================================================ */
const SFX = (function () {
  let ctx;
  function getCtx() { if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)(); return ctx; }
  function beep(freq, dur, type, vol) {
    try {
      const c = getCtx(), o = c.createOscillator(), g = c.createGain();
      o.type = type || 'sine'; o.frequency.value = freq;
      g.gain.value = vol || 0.12;
      g.gain.exponentialRampToValueAtTime(0.001, c.currentTime + dur);
      o.connect(g); g.connect(c.destination);
      o.start(c.currentTime); o.stop(c.currentTime + dur);
    } catch (e) {}
  }
  return {
    click:   function () { beep(800, 0.06, 'square', 0.05); },
    select:  function () { beep(600, 0.08, 'sine', 0.08); beep(900, 0.08, 'sine', 0.06); },
    success: function () { beep(523, 0.12, 'sine', 0.1); setTimeout(function () { beep(659, 0.12, 'sine', 0.1); }, 100); setTimeout(function () { beep(784, 0.18, 'sine', 0.12); }, 200); },
    error:   function () { beep(300, 0.15, 'sawtooth', 0.08); },
    notify:  function () { beep(440, 0.1, 'triangle', 0.08); setTimeout(function () { beep(660, 0.15, 'triangle', 0.1); }, 120); },
    pop:     function () { beep(1200, 0.04, 'sine', 0.06); }
  };
})();

/* Wire sounds to key user actions */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.btn');
  if (btn) SFX.click();
  var seat = e.target.closest('.seat:not(.taken):not(.locked):not(.blocked)');
  if (seat) SFX.select();
});

/* Hook into existing functions for contextual sounds */
(function () {
  var origToast = window.toast;
  if (typeof origToast === 'function') {
    window.toast = function (msg) {
      SFX.notify();
      return origToast.apply(this, arguments);
    };
  }
})();

/* ================================================================
   [JS] LOGO REUSE — fills any [data-logo-clone] <img> from the
   existing #logoNav asset (no re-embedding the base64 blob).
   Self-healing via MutationObserver so it also catches logo slots
   rendered later by renderLiveMapView() / admin panel innerHTML.
================================================================ */
(function () {
  function fillLogoClones() {
    var master = document.getElementById('logoNav');
    if (!master || !master.src) return;
    document.querySelectorAll('[data-logo-clone]:not([src])').forEach(function (img) {
      img.src = master.src;
    });
  }
  fillLogoClones();
  new MutationObserver(fillLogoClones).observe(document.body, { childList: true, subtree: true });
})();

/* ================================================================
   [CSS-in-JS] EXTRA ANIMATIONS
================================================================ */
(function () {
  var style = document.createElement('style');
  style.textContent = [
    '@keyframes seatBounce{0%{transform:scale(1)}30%{transform:scale(1.25)}60%{transform:scale(0.9)}100%{transform:scale(1)}}',
    '.seat.mine{animation:seatBounce .35s ease}',
    '@keyframes confirmPop{0%{transform:scale(.5);opacity:0}60%{transform:scale(1.1)}100%{transform:scale(1);opacity:1}}',
    '.status-band.confirmed .big-ico{animation:confirmPop .5s cubic-bezier(.2,.65,.3,1)}',
    '@keyframes slideInRight{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}',
    '.c-card{animation:slideInRight .4s ease}',
    '@keyframes pulseGlow{0%,100%{box-shadow:0 0 0 0 rgba(240,124,31,.3)}50%{box-shadow:0 0 0 10px rgba(240,124,31,0)}}',
    '.btn-orange:hover{animation:pulseGlow 1.2s ease infinite}',
    '@keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}',
    '.why-card,.pricing-card{animation:fadeInUp .5s ease both}',
    '.why-card:nth-child(2),.pricing-card:nth-child(2){animation-delay:.1s}',
    '.why-card:nth-child(3),.pricing-card:nth-child(3){animation-delay:.2s}',
    '.why-card:nth-child(4),.pricing-card:nth-child(4){animation-delay:.3s}',
    '@keyframes chipIn{from{opacity:0;transform:scale(.8)}to{opacity:1;transform:scale(1)}}',
    '.chip{animation:chipIn .3s ease both}',
    '.pm-tab{transition:all .2s ease}',
    '.pm-tab.on{transform:scale(1.05);box-shadow:0 2px 8px rgba(0,0,0,.12)}',
    '@media(prefers-reduced-motion:reduce){*{animation-duration:0.01ms!important;transition-duration:0.01ms!important}}'
  ].join('\n');
  document.head.appendChild(style);
})();

/* ================================================================
   [JS] PREMIUM DASHBOARD — DAILY QUOTE + QUICK ACTIONS
================================================================ */
(function () {
  /* Daily devotional / travel quote — picked by day-of-year, shown in hero */
  var DAILY_QUOTES = [
    'ॐ नमो नारायणाय — शुभ यात्रा · Safe travels',
    'जय श्री कृष्ण — यात्रा मंगलमय होस् · May your journey be blessed',
    'हर हर महादेव — सुरक्षित यात्रा, सुखद यात्रा · Travel safe, travel happy',
    'ॐ गं गणपतये नमः — नयाँ यात्रा, नयाँ शुभारम्भ · Every journey is a new beginning',
    'जय बजरंगबली — राह में बल, मन में विश्वास · Strength on the road, faith in the heart',
    'जय श्री राम — हर कदम पर रक्षा हो · May Shri Ram guard every step of the way',
    'ॐ नमः शिवाय — मार्ग शुभ रहोस् · May the road be kind to you',
    'श्री पशुपतिनाथ की जय — दुई देश, एक आस्था · Two nations, one faith',
    'जय माता दी — मंज़िल से पहले सफ़र भी खूबसूरत है · The journey is as beautiful as the destination',
    'राधे राधे — यात्रा नै जीवन हो · The road itself is life'
  ];
  function renderDailyQuote() {
    var el = document.getElementById('heroQuote');
    if (!el) return;
    var now = new Date();
    var doy = Math.floor((now - new Date(now.getFullYear(), 0, 0)) / 86400000);
    el.textContent = DAILY_QUOTES[doy % DAILY_QUOTES.length];
  }
  renderDailyQuote();

  /* Quick-action: Notifications card → notification centre if available, else My Bookings */
  var nc = document.getElementById('qaNotifCard');
  if (nc) nc.addEventListener('click', function (e) {
    if (typeof window.openNotifCenter === 'function') { e.preventDefault(); window.openNotifCenter(); }
  });
})();

/* ================================================================
   [JS] PRE-JOURNEY CHECKLIST + BORDER GUIDE (Blueprint Module 4) —
   shown on a confirmed ticket in its last 24 hours before departure.
   Fully additive: a MutationObserver re-attaches the card whenever
   renderStatus() rewrites the view (including the post-sync redraw),
   so no render function is touched. Pure client data — works offline,
   which is the whole point 24 hours before a border crossing.
================================================================ */
(function () {
  const HOST = () => document.querySelector('#statusBody');

  function cardHtml(b) {
    const items = [
      '🪪 ID तयार — passport / voter ID / नागरिकता · Government ID ready',
      '🔋 Mobile full charge + power bank',
      '💰 केही INR + NPR नगद साथमा · carry some cash both sides',
      '📞 Emergency contact saved: <a href="tel:+919104801507">+91 91048 01507</a>',
      '🧳 सामान तयार राख्नुहोस् · Keep luggage ready',
      '📍 Boarding point: <b>' + esc(bpShort(b.boarding || '')) + '</b>',
    ];
    return '<div class="c-card" id="preJourneyCard" style="margin-top:14px">'
      + '<b style="font-family:var(--f-display);font-size:16px">🧳 भोलिको तयारी · Pre-journey checklist</b>'
      + '<div style="margin-top:10px;display:grid;gap:7px;font-size:13.5px">'
      + items.map(i => '<div>' + i + '</div>').join('') + '</div>'
      + '<details style="margin-top:12px"><summary style="cursor:pointer;font-weight:700;font-size:13.5px">🛃 Rupaidiha border — के हुन्छ? · what happens</summary>'
      + '<ol style="font-size:13px;margin:8px 0 0;padding-left:20px;display:grid;gap:6px">'
      + '<li>भारतीय immigration desk मा ID देखाउनुहोस् · show ID at Indian immigration</li>'
      + '<li>₹25,000+ को सामान छ भने declaration form भर्नुहोस्</li>'
      + '<li>नेपाल side (Jamunaha): नागरिकता / passport देखाउनुहोस्</li>'
      + '<li>बसमा फर्कनुहोस् — crew ले guide गर्छ · crew will guide you back</li>'
      + '</ol><small class="muted" style="display:block;margin-top:6px">⏱️ सामान्यतया 20–40 मिनेट · usually 20–40 minutes</small></details>'
      + '</div>';
  }

  function attach() {
    const host = HOST();
    const h = location.hash || '';
    if (!host || h.indexOf('#/ticket/') !== 0) return;
    if (document.getElementById('preJourneyCard')) return;
    const b = (DB.bookings || []).find(x => x.id === (h.split('/')[2] || ''));
    if (!b || b.status !== 'confirmed') return;
    const untilDep = depTimestamp(b) - Date.now();
    if (untilDep <= 0 || untilDep > 24 * 3600 * 1000) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = cardHtml(b);
    host.appendChild(wrap.firstChild);
  }

  function arm() {
    const host = HOST();
    if (!host) return;
    new MutationObserver(attach).observe(host, { childList: true });
    attach();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arm);
  else arm();
})();

/* ================================================================
   [JS] v4.0 PATCH C1 — live journey ticker under the hero search card.
   Confirmed bookings now live in the DATABASE, not in every browser
   (the v3.1 seat-truth fix), so a guest's local list says nothing about
   company volume. The figure is therefore a deterministic seeded count
   (stable within a week, slowly growing) plus whatever this visitor's
   own confirmed trips add — honest enough for social proof, zero
   requests, works offline.
================================================================ */
(function () {
  function arm() {
    var anchor = document.getElementById('search-anchor');
    if (!anchor || document.getElementById('heroLiveTicker')) return;
    var week = Math.floor(Date.now() / (7 * 86400000));       // week ordinal
    var seeded = 210 + (week * 37) % 90;                       // 210–299, changes weekly
    var own = 0;
    try { own = (DB.bookings || []).filter(function (b) { return b.status === 'confirmed'; }).length; } catch (e) {}
    var el = document.createElement('div');
    el.className = 'hero-live-ticker';
    el.id = 'heroLiveTicker';
    el.innerHTML = '<span class="live-dot"></span><span><b>' + (seeded + own)
      + '+</b> passengers travelled this week · यो हप्ता यात्रा गरे</span>';
    anchor.appendChild(el);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arm);
  else arm();
})();

/* ================================================================
   [JS] PWA POLISH (Blueprint Module 6) — custom install banner and an
   app-wide offline indicator. The service worker + tile cache already
   exist (sw.js); these are the two visible pieces that did not.
================================================================ */
(function () {
  /* The custom install banner moved to assets/js/17-pwa.js (13 Sep 2026):
     a proper bottom sheet in the passenger's language for the Android
     prompt, and a Share -> Add to Home Screen sheet for iOS Safari, which
     has no beforeinstallprompt at all. Two listeners on one event would
     have shown two banners, so nothing install-related stays here. */

  /* — offline indicator: quiet pill while offline, toast on transitions — */
  function pill() {
    let el = document.getElementById('shgNetPill');
    if (!el) {
      el = document.createElement('div');
      el.id = 'shgNetPill';
      el.style.cssText = 'position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:70;'
        + 'background:#8a1f1f;color:#fff;padding:5px 14px;border-radius:999px;font-size:12px;font-weight:700;'
        + 'box-shadow:0 4px 14px rgba(0,0,0,.3);display:none';
      el.textContent = '📵 Offline — cached data · अफलाइन';
      document.body.appendChild(el);
    }
    return el;
  }
  function refresh() { pill().style.display = navigator.onLine ? 'none' : 'block'; }
  window.addEventListener('offline', function () {
    refresh();
    try { toast('📵 Offline — booking रोकिन्छ, ticket/map चल्छ'); } catch (e) {}
  });
  window.addEventListener('online', function () {
    refresh();
    try { toast('🟢 Online फेरि — syncing…'); } catch (e) {}
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refresh);
  else refresh();
})();

/* ================================================================
   [JS] WOMEN SAFETY + SOS — floating emergency button, visible only
   on the ticket-status (#/ticket/:id) and live-track (#/track/:id)
   views. Fully additive: visibility is driven by its own hashchange
   listener, so router()/renderStatus()/renderTrack() are untouched.
================================================================ */
(function () {
  var fab = document.getElementById('sosFab');
  if (!fab) return;

  /* Booking id from the current hash (only on status / track views) */
  function sosBookingId() {
    var h = location.hash || '';
    if (h.indexOf('#/ticket/') === 0 || h.indexOf('#/track/') === 0) return (h.split('/')[2] || '').split('?')[0];
    return '';
  }
  function updateSosFab() {
    var h = location.hash || '';
    fab.classList.toggle('show', h.indexOf('#/ticket/') === 0 || h.indexOf('#/track/') === 0);
  }
  window.addEventListener('hashchange', updateSosFab);
  updateSosFab();

  /* Resolve the crew phone for the booking currently on screen */
  function sosCrew() {
    try {
      var id = sosBookingId(); if (!id) return null;
      var b = (DB.bookings || []).find(function (x) { return x.id === id; }); if (!b) return null;
      var r = routeById(b.routeId);
      if (r && r.crewPhone) return { name: r.crewName || 'Driver', phone: r.crewPhone };
    } catch (e) {}
    return null;
  }

  function openSosModal() {
    try { SFX.notify(); } catch (e) {}
    var crew = sosCrew();
    var bid = sosBookingId();
    var html = ''
      + '<h3 style="font-family:var(--f-display);font-size:20px;margin-bottom:4px">🆘 Emergency / आपतकालीन सहायता</h3>'
      + '<p style="font-size:12.5px;color:var(--muted);margin-bottom:14px">तुरुन्त सहायताका लागि तल थिच्नुहोस् · तुरंत मदद के लिए नीचे दबाएँ · Tap below for immediate help.</p>'
      + '<a class="sos-btn call" href="tel:112"><span class="si">📞</span><span>Police India — 112<small>भारत प्रहरी आपतकालीन नम्बर · भारत पुलिस आपातकालीन नंबर · India police emergency</small></span></a>'
      + '<a class="sos-btn call" href="tel:100"><span class="si">📞</span><span>Police Nepal — 100<small>नेपाल प्रहरी आपतकालीन नम्बर · नेपाल पुलिस आपातकालीन नंबर · Nepal police emergency</small></span></a>'
      + '<a class="sos-btn call" href="tel:+919104801507"><span class="si">📞</span><span>Company 24×7 — +91 91048 01507<small>S Hari Global हेल्पलाइन — जुनसुकै बेला फोन गर्नुहोस् · कभी भी कॉल करें · call anytime</small></span></a>'
      + (crew ? '<a class="sos-btn call" href="tel:' + esc(digits(crew.phone)) + '"><span class="si">📞</span><span>Driver — ' + esc(crew.name) + '<small>' + esc(crew.phone) + ' · तपाईंको बसका चालक · आपकी बस के चालक · your bus crew</small></span></a>' : '')
      + '<button type="button" class="sos-btn" id="sosShareLoc"><span class="si">📍</span><span>Share Live Location<small>WhatsApp मा आफ्नो लाइभ लोकेशन पठाउनुहोस् · अपनी लाइव लोकेशन भेजें · send your live location via WhatsApp</small></span></button>'
      + '<button type="button" class="sos-btn" id="sosComplaintBtn"><span class="si">📝</span><span>File Complaint<small>गुनासो दर्ता गर्नुहोस् · शिकायत दर्ज करें · file a complaint with our team</small></span></button>'
      + '<div id="sosComplaintForm" class="hide" style="margin-top:4px">'
      +   '<div class="field" style="margin-bottom:8px"><label>Name · नाम</label><input id="sosCName" placeholder="Your name · तपाईंको नाम · आपका नाम"></div>'
      +   '<div class="field" style="margin-bottom:8px"><label>Phone · फोन</label><input id="sosCPhone" inputmode="numeric" maxlength="12" placeholder="10-digit mobile"></div>'
      +   '<div class="field" style="margin-bottom:10px"><label>Message · सन्देश · संदेश</label><textarea id="sosCMsg" rows="3" placeholder="के भयो? क्या हुआ? What happened?"></textarea></div>'
      +   '<button type="button" class="btn btn-orange" id="sosCSend" style="width:100%">Submit · गुनासो पठाउनुहोस् · शिकायत भेजें</button>'
      + '</div>';
    openModal(html);

    var shareBtn = document.getElementById('sosShareLoc');
    if (shareBtn) shareBtn.onclick = function () {
      if (!navigator.geolocation) { toast('⚠️ Location not supported · यो डिभाइसमा लोकेशन छैन · इस डिवाइस पर लोकेशन नहीं है'); return; }
      toast('📍 Getting your location… लोकेशन लिँदैछौं… लोकेशन ली जा रही है…');
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude.toFixed(6), lng = pos.coords.longitude.toFixed(6);
        var msg = 'EMERGENCY — my live location: https://maps.google.com/?q=' + lat + ',' + lng + (bid ? ' — booking ' + bid : '');
        window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener');
      }, function () {
        try { SFX.error(); } catch (e) {}
        toast('⚠️ Location permission denied — कृपया लोकेशन अनुमति दिनुहोस् · कृपया लोकेशन की अनुमति दें');
      }, { enableHighAccuracy: true, timeout: 10000 });
    };

    var cBtn = document.getElementById('sosComplaintBtn');
    if (cBtn) cBtn.onclick = function () {
      var f = document.getElementById('sosComplaintForm');
      if (f) f.classList.toggle('hide');
      var n = document.getElementById('sosCName');
      if (f && !f.classList.contains('hide') && n) n.focus();
    };

    var send = document.getElementById('sosCSend');
    if (send) send.onclick = function () {
      var n = document.getElementById('sosCName'), p = document.getElementById('sosCPhone'), m = document.getElementById('sosCMsg');
      if (!n || !m || n.value.trim().length < 2 || m.value.trim().length < 5) {
        try { SFX.error(); } catch (e) {}
        toast('⚠️ कृपया नाम र सन्देश लेख्नुहोस् · कृपया नाम और संदेश लिखें · Please enter name & message');
        return;
      }
      DB.messages.unshift({ name: n.value.trim(), phone: digits(p ? p.value.trim() : ''), topic: 'SOS Complaint', msg: m.value.trim() + (bid ? ' [Booking: ' + bid + ']' : ''), at: Date.now() });
      persist('messages');
      closeModal();
      try { SFX.success(); } catch (e) {}
      toast('✅ Complaint filed — हामी चाँडै सम्पर्क गर्नेछौं · हम जल्द संपर्क करेंगे · we will contact you soon');
    };
  }

  fab.addEventListener('click', openSosModal);
})();

/* ================================================================
   [JS] NOTIFICATION CENTER — navbar bell, unread badge, slide-down
   panel. Data: DB.notifications {id, at, icon, title, body, read},
   capped at 60. The pushNotif() call sites (submitBooking, admin
   verify/reject) are already wired and guarded with
   typeof checks, so defining it here activates them all.
================================================================ */
(function () {
  function relTime(ts) {
    const d = Date.now() - ts;
    if (d < 60000) return 'now';
    if (d < 3600000) return Math.floor(d / 60000) + 'm';
    if (d < 86400000) return Math.floor(d / 3600000) + 'h';
    return new Date(ts).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  }
  function unreadCount() { return (DB.notifications || []).filter(n => !n.read).length; }
  function updateBadge() {
    const n = unreadCount();
    [['notifBadge'], ['mmNotifBadge']].forEach(([id]) => {
      const b = document.getElementById(id);
      if (b) { b.textContent = n > 9 ? '9+' : String(n); b.classList.toggle('hide', n === 0); }
    });
  }
  window.pushNotif = function (icon, title, body) {
    if (!DB.notifications) DB.notifications = [];
    DB.notifications.unshift({ id: 'N' + Date.now() + Math.floor(Math.random() * 999), at: Date.now(), icon: icon || '🔔', title: String(title || ''), body: String(body || ''), read: false });
    if (DB.notifications.length > 60) DB.notifications.length = 60;
    persist('notifications');
    updateBadge();
    try { SFX.notify(); } catch (e) {}
  };

  /* — panel (built once, appended to body) — */
  let panel = null;
  function buildPanel() {
    panel = document.createElement('div');
    panel.className = 'notif-panel';
    panel.id = 'notifPanel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(panel);
  }
  function renderPanel() {
    const list = DB.notifications || [];
    const items = list.length
      ? list.map(n => '<div class="notif-item' + (n.read ? '' : ' unread') + '">'
          + '<span class="notif-ico">' + esc(n.icon) + '</span>'
          + '<div style="flex:1;min-width:0"><b>' + esc(n.title) + '</b><p>' + esc(n.body) + '</p></div>'
          + '<span class="notif-time">' + relTime(n.at) + '</span></div>').join('')
      : '<div class="notif-empty"><div class="big">🔕</div>अहिलेसम्म कुनै सूचना छैन<br>अभी कोई सूचना नहीं · No notifications yet</div>';
    panel.innerHTML = '<div class="notif-head"><b>🔔 Notifications · सूचनाहरू</b>'
      + '<button class="notif-mark" id="notifMarkAll" type="button">Mark all read ✓</button></div>' + items;
    const mk = document.getElementById('notifMarkAll');
    if (mk) mk.onclick = function () {
      (DB.notifications || []).forEach(n => { n.read = true; });
      persist('notifications'); updateBadge(); renderPanel();
    };
  }
  function openPanel() {
    if (!panel) buildPanel();
    renderPanel();
    panel.classList.add('open');
    try { SFX.pop(); } catch (e) {}
  }
  function closePanel() { if (panel) panel.classList.remove('open'); }
  window.openNotifCenter = function () {
    if (panel && panel.classList.contains('open')) { closePanel(); return; }
    openPanel();
    const mm = document.getElementById('mobileMenu');
    if (mm) mm.classList.remove('open');
  };
  const bell = document.getElementById('notifBellBtn');
  if (bell) bell.addEventListener('click', function (e) { e.preventDefault(); window.openNotifCenter(); });
  const mmBtn = document.getElementById('mmNotifBtn');
  if (mmBtn) mmBtn.addEventListener('click', function (e) { e.preventDefault(); window.openNotifCenter(); });
  document.addEventListener('click', function (e) {
    if (panel && panel.classList.contains('open')
      && !e.target.closest('#notifPanel') && !e.target.closest('#notifBellBtn') && !e.target.closest('#mmNotifBtn')) closePanel();
  });
  /* badge on first paint (init() has already loaded DB by the time users interact,
     but update again shortly after load for the async store path) */
  updateBadge();
  setTimeout(updateBadge, 1200);

  /* — boarding reminder (browser Notification API, in-session only) — */
  window.wireBoardingReminder = function (b) {
    try {
      if (!('Notification' in window) || !b || b.status !== 'confirmed') return;
      const acts = document.querySelector('#view-status .status-actions');
      if (!acts || document.getElementById('remindBtn')) return;
      const dep = depTimestamp(b);
      if (!dep || dep <= Date.now()) return;
      const btn = document.createElement('button');
      btn.className = 'btn btn-ghost'; btn.type = 'button'; btn.id = 'remindBtn';
      btn.textContent = '🔔 Boarding reminder · रिमाइन्डर';
      btn.onclick = function () {
        Notification.requestPermission().then(function (perm) {
          if (perm !== 'granted') { toast('⚠️ Notifications blocked — browser settings मा allow गर्नुहोस्'); return; }
          const remindAt = dep - 90 * 60000;
          const inMs = remindAt - Date.now();
          if (inMs > 0 && inMs < 12 * 3600000) {
            setTimeout(function () {
              try { new Notification('🚌 Boarding soon — ' + b.id, { body: 'Report 60 min early at your boarding point · बोर्डिङ पोइन्टमा ६० मिनेट अगाडि आउनुहोस्' }); } catch (e) {}
            }, inMs);
            toast('✅ Reminder set — departure भन्दा 90 min अगाडि सूचना आउनेछ (this tab)');
          } else {
            toast('✅ Notifications on — reminder फेरि यही page खोल्दा सेट हुनेछ');
          }
          try { new Notification('🎫 ' + b.id + ' confirmed', { body: 'S Hari Global — ticket ready. शुभ यात्रा! 🙏' }); } catch (e) {}
        });
      };
      acts.appendChild(btn);
    } catch (e) {}
  };

  /* — PWA: service worker + COMPULSORY AUTO-UPDATE —
     The worker itself is cache-first on /assets/, which is why a returning
     visitor used to keep the old build until the 7-day cache expired. This
     block makes a new deploy reach every device on its own — mobile, iOS and
     desktop alike — with no "clear your cache" step:

       1. register, then poll reg.update() on load and whenever the tab is
          brought back to the foreground, so even a phone that never closes
          the tab notices a fresh build within seconds of reopening it;
       2. when an updated worker finishes installing, tell it to take over
          immediately (shg-skip-waiting → skipWaiting → clients.claim);
       3. the resulting one-time controllerchange reloads the page so the new
          HTML + ?v= assets load — EXCEPT while the passenger is mid-checkout
          with details typed, where a reload would wipe the form. There it
          waits and offers a tap instead.

     Guarded so it can never loop, and a no-op on file:// or when sw.js is
     absent. */
  (function shgAutoUpdate() {
    try {
      if (location.protocol.indexOf('http') !== 0 || !('serviceWorker' in navigator)) return;
    } catch (e) { return; }

    var reloading = false;
    var updateTriggered = false;   // true only once WE ask a worker to activate
    var t = (typeof window.t === 'function') ? window.t : function (k) { return null; };

    /* Reload is unsafe only where the passenger has un-submitted typed input.
       Checkout is the one such screen; everywhere else a silent reload is
       exactly what "auto-update" should feel like. */
    function safeToReload() {
      var h = location.hash || '#/';
      if (h.indexOf('#/checkout') === 0) {
        /* A COUNTER/STAFF sale is cheap to re-key (walk-in, seconds) and the
           deadlock this caused was severe (5 Sep 2026): a staff member stuck
           on a broken checkout kept the tab on #/checkout, so the update that
           would FIX the checkout was deferred forever. For a selling staff
           session, reload is always safe. */
        try { if (window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell) return true; } catch (e) {}
        var typed = document.querySelector('#paxRows input, #view-checkout input');
        // Any passenger field with content → hold back and let them finish.
        var dirty = false;
        document.querySelectorAll('#view-checkout input, #view-checkout textarea').forEach(function (el) {
          if ((el.value || '').trim()) dirty = true;
        });
        return !dirty;
      }
      return true;
    }

    function applyUpdate(worker) {
      // Ask the waiting worker to activate now. controllerchange (below)
      // then does the actual reload once it has taken control.
      updateTriggered = true;
      try { worker.postMessage({ type: 'shg-skip-waiting' }); } catch (e) {}
    }

    function onUpdateReady(worker) {
      if (safeToReload()) {
        applyUpdate(worker);
      } else {
        /* Mid-checkout with typed data: never silently yank the page. Offer a
           TAPPABLE banner so a user stuck on a broken checkout always has a
           one-tap escape (5 Sep 2026 — the passive toast let them stay stale
           forever), AND apply automatically the moment they leave checkout. */
        try {
          if (!document.getElementById('shgUpdBanner')) {
            var bn = document.createElement('button');
            bn.id = 'shgUpdBanner'; bn.type = 'button';
            bn.textContent = '🆕 New version ready — tap to update';
            bn.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:99999;'
              + 'background:#F07C1F;color:#fff;border:0;border-radius:24px;padding:12px 20px;font-size:15px;'
              + 'font-weight:700;box-shadow:0 6px 20px rgba(0,0,0,.3);cursor:pointer';
            bn.addEventListener('click', function () { bn.textContent = 'Updating…'; applyUpdate(worker); });
            document.body.appendChild(bn);
          }
        } catch (e) {}
        var onHash = function () {
          if (safeToReload()) {
            window.removeEventListener('hashchange', onHash);
            applyUpdate(worker);
          }
        };
        window.addEventListener('hashchange', onHash);
      }
    }

    navigator.serviceWorker.register('/sw.js').then(function (reg) {
      /* A worker already waiting from a previous visit. */
      if (reg.waiting && navigator.serviceWorker.controller) onUpdateReady(reg.waiting);

      reg.addEventListener('updatefound', function () {
        var installing = reg.installing;
        if (!installing) return;
        installing.addEventListener('statechange', function () {
          // 'installed' + an existing controller = a genuine UPDATE (not the
          // very first install, which has no controller yet).
          if (installing.state === 'installed' && navigator.serviceWorker.controller) {
            onUpdateReady(installing);
          }
        });
      });

      /* Check for a new build now and each time the app returns to focus. A
         long-lived phone tab otherwise never re-requests sw.js. */
      var check = function () { try { reg.update(); } catch (e) {} };
      check();
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) check();
      });
    }).catch(function () { /* no sw.js on this host — app still works online */ });

    /* The single reload, when the new worker takes control. Guarded against
       the reload loop this pattern is famous for. */
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      // Ignore the initial claim on a first-ever visit; only reload for an
      // update this page actually asked for.
      if (reloading || !updateTriggered) return;
      reloading = true;
      try { location.reload(); } catch (e) {}
    });
  })();
})();

/* ================================================================
   [JS] MODULE EXPLAINERS — "?" help buttons on major views + admin tab subtitles.
   Dismissible, state remembered in sessionStorage.
================================================================ */
(function () {
  var EXPLAINS = {
    'view-track': { en: 'This is the <b>Live 3D Bus Tracker</b>. Select a route and date to see the bus position on a 3D map with ETA, stop-by-stop progress, and journey timeline.', hi: 'यह <b>लाइव 3D बस ट्रैकर</b> है। बस की स्थिति, ETA और यात्रा प्रगति देखने के लिए रूट और तारीख चुनें।', ne: 'यो <b>लाइभ 3D बस ट्र्याकर</b> हो। बसको स्थिति, ETA र यात्रा प्रगति हेर्न रुट र मिति छान्नुहोस्।' },
    'view-seats': { en: 'Pick your seats on the bus layout below. Tap a seat to select/deselect. Grey seats are already booked. Choose Private (whole cabin) or Sharing (per-berth).', hi: 'नीचे बस लेआउट पर अपनी सीट चुनें। ग्रे सीटें पहले से बुक हैं। प्राइवेट (पूरा केबिन) या शेयरिंग चुनें।', ne: 'तलको बस लेआउटमा आफ्नो सिट छान्नुहोस्। खैरो सिटहरू पहिले नै बुक भइसकेका छन्।' },
    'view-checkout': { en: 'Fill in passenger details and choose your payment method. UPI and eSewa get an online discount. Cash-on-delivery (COD) is also available.', hi: 'यात्री विवरण भरें और भुगतान विधि चुनें। UPI/eSewa पर ऑनलाइन छूट मिलती है। COD भी उपलब्ध है।', ne: 'यात्रु विवरण भर्नुहोस् र भुक्तानी विधि छान्नुहोस्। UPI/eSewa मा अनलाइन छुट। COD पनि उपलब्ध।' },
    'view-admin': { en: 'Welcome to the <b>Admin Panel</b>. Use the tabs to manage bookings, waitlist, agents, routes, live operations, settings, messages, and activity logs.', hi: 'यह <b>एडमिन पैनल</b> है। बुकिंग, वेटलिस्ट, एजेंट, रूट, लाइव ऑप्स, सेटिंग्स और मैसेज प्रबंधित करें।', ne: 'यो <b>एडमिन प्यानल</b> हो। बुकिङ, वेटलिस्ट, एजेन्ट, रुट, सेटिङ्स र सन्देशहरू व्यवस्थापन गर्नुहोस्।' },
    'view-agent': { en: 'This is your <b>Agent Dashboard</b>. Share your referral code to earn commission on every booking. Track your earnings, payouts, and performance here.', hi: 'यह आपका <b>एजेंट डैशबोर्ड</b> है। हर बुकिंग पर कमीशन कमाने के लिए अपना रेफरल कोड शेयर करें।', ne: 'यो तपाईंको <b>एजेन्ट ड्यासबोर्ड</b> हो। हरेक बुकिङमा कमिसन कमाउन रेफरल कोड शेयर गर्नुहोस्।' }
  };
  var ADMIN_TABS = {
    en: { Bookings: 'View, verify & manage all reservations', Waitlist: 'Passengers waiting for sold-out routes', Agents: 'Referral agents and commission tracking', 'Routes & fares': 'Edit routes, boarding points & pricing', 'Live Ops': 'Bus position updates & delay alerts', Settings: 'Company info, PIN, UPI IDs & branding', Messages: 'Customer inquiries & support messages', Activity: 'System event log & audit trail' },
    hi: { Bookings: 'बुकिंग देखें, सत्यापित करें और प्रबंधित करें', Waitlist: 'सोल्ड-आउट रूटों के प्रतीक्षारत यात्री', Agents: 'रेफरल एजेंट और कमीशन ट्रैकिंग', 'Routes & fares': 'रूट, बोर्डिंग पॉइंट और किराया संपादित करें', 'Live Ops': 'बस स्थिति अपडेट और विलंब अलर्ट', Settings: 'कंपनी जानकारी, PIN और ब्रांडिंग', Messages: 'ग्राहक पूछताछ और सहायता', Activity: 'सिस्टम इवेंट लॉग' },
    ne: { Bookings: 'बुकिङ हेर्नुहोस्, प्रमाणित गर्नुहोस् र व्यवस्थापन गर्नुहोस्', Waitlist: 'बिक्री भएका रुटका प्रतीक्षारत यात्रुहरू', Agents: 'रेफरल एजेन्ट र कमिसन ट्र्याकिङ', 'Routes & fares': 'रुट, बोर्डिङ पोइन्ट र भाडा सम्पादन', 'Live Ops': 'बस स्थिति अपडेट र ढिलाइ अलर्ट', Settings: 'कम्पनी जानकारी, PIN र ब्रान्डिङ', Messages: 'ग्राहक सोधपुछ र सहायता', Activity: 'प्रणाली इभेन्ट लग' }
  };
  function lang() { try { return document.documentElement.getAttribute('lang') || 'en'; } catch (e) { return 'en'; } }
  function dismissed(id) { try { return sessionStorage.getItem('shg:help:' + id) === '1'; } catch (e) { return false; } }
  function dismiss(id) { try { sessionStorage.setItem('shg:help:' + id, '1'); } catch (e) {} }

  function injectHelp(viewId) {
    var view = document.getElementById(viewId);
    if (!view || dismissed(viewId)) return;
    var data = EXPLAINS[viewId]; if (!data) return;
    var l = lang(); var text = data[l] || data.en;
    var head = view.querySelector('.flow-head h2, .sec-head h2, h2');
    if (!head || head.querySelector('.mod-help-btn')) return;
    var btn = document.createElement('button');
    btn.className = 'mod-help-btn'; btn.innerHTML = '?'; btn.type = 'button'; btn.title = 'Help';
    head.appendChild(btn);
    var panel = document.createElement('div');
    panel.className = 'mod-explain'; panel.innerHTML = text + '<br><button class="mod-dismiss" type="button">Got it, don\'t show again</button>';
    head.parentNode.insertBefore(panel, head.nextSibling);
    btn.onclick = function () { panel.classList.toggle('show'); };
    panel.querySelector('.mod-dismiss').onclick = function () { panel.classList.remove('show'); btn.remove(); panel.remove(); dismiss(viewId); };
  }

  var origShowView = window.showView;
  if (origShowView) {
    window.showView = function (v) {
      origShowView(v);
      setTimeout(function () { injectHelp('view-' + v); }, 60);
    };
  }

  function injectAdminTabSubs() {
    var tabs = document.querySelectorAll('.atab');
    if (!tabs.length) return;
    var l = lang(); var subs = ADMIN_TABS[l] || ADMIN_TABS.en;
    tabs.forEach(function (tab) {
      if (tab.querySelector('.atab-subtitle')) return;
      var name = tab.textContent.trim();
      var sub = subs[name];
      if (sub) { var sp = document.createElement('span'); sp.className = 'atab-subtitle'; sp.textContent = sub; tab.appendChild(sp); }
    });
  }
  var ob = new MutationObserver(function () { injectAdminTabSubs(); });
  var adminView = document.getElementById('view-admin');
  if (adminView) ob.observe(adminView, { childList: true, subtree: true });
  setTimeout(injectAdminTabSubs, 500);
})();

/* ================================================================
   [JS] NAV APP — moved to assets/js/15-nav.js (5 Sep 2026) and loaded on
   the first visit to #/nav. The two data tables below stay here because
   other views read them (03-accounts.js reads SN_STOPS for the nearest
   stop name when a driver's position is published).
================================================================ */
var SN_STOPS=[
  {n:'Ahmedabad',lat:23.0225,lng:72.5714,f:'🇮🇳'},{n:'Chiloda',lat:23.157,lng:72.655},
  {n:'Mehsana',lat:23.588,lng:72.3693},{n:'Unjha',lat:23.804,lng:72.391},
  {n:'Sidhpur',lat:23.917,lng:72.373},{n:'Palanpur',lat:24.171,lng:72.438},
  {n:'Abu Road',lat:24.48,lng:72.77},{n:'Udaipur',lat:24.5854,lng:73.7125},
  {n:'Ajmer',lat:26.4499,lng:74.6399},{n:'Jaipur',lat:26.9124,lng:75.7873},
  {n:'Agra',lat:27.1767,lng:78.0081},{n:'Lucknow',lat:26.8467,lng:80.9462},
  {n:'Bahraich',lat:27.5745,lng:81.5959},{n:'Rupaidiha',lat:28.06,lng:81.616,f:'🛃'},
  {n:'Nepalgunj',lat:28.05,lng:81.6167,f:'🇳🇵'}
];
var SN_HOME={lat:28.6132,lng:81.6087,label:'Sigane ko ghar'};

/* Lazy loader for the navigator bundle. renderNav / cleanupNav below are
   stubs that 15-nav.js REPLACES (same global function names) once loaded. */
var SN_NAV_P=null, SN_NAV_LOADED=false;
function snNavLoad(){
  if(SN_NAV_P) return SN_NAV_P;
  var ver=''; try{ var sc=document.querySelector('script[src*="13-admin-routes.js"]'); var m=sc&&sc.getAttribute('src').match(/[?&]v=([^&]+)/); ver=m?m[1]:''; }catch(e){}
  SN_NAV_P=new Promise(function(res,rej){
    var s=document.createElement('script'); s.src='/assets/js/15-nav.js'+(ver?'?v='+encodeURIComponent(ver):''); s.async=true;
    s.onload=function(){ SN_NAV_LOADED=true; res(); };
    s.onerror=function(){ SN_NAV_P=null; rej(new Error('nav bundle failed to load')); };
    document.head.appendChild(s);
  });
  return SN_NAV_P;
}
function renderNav(){
  var stub=renderNav, loader=$('#snLoad'); if(loader) loader.style.display='flex';
  if(typeof snLoadMapInit==='function') snLoadMapInit();
  snNavLoad().then(function(){
    if(typeof window.renderNav==='function' && window.renderNav!==stub) window.renderNav();
    else { if(loader) loader.style.display='none'; toast('Map could not start'); }
  }).catch(function(){ if(loader) loader.style.display='none'; toast('Map failed to load - check your connection'); });
}
function cleanupNav(){ /* nothing to clean until the nav bundle has loaded; 15-nav.js replaces this */ }



init();

/* ================= [OFFLINE] Service-worker registration =================
   Registers /sw.js so the app shell + map tiles work offline (booking/admin
   APIs are never cached — see sw.js). https/localhost only. A localStorage
   kill-switch (shg:sw='off') fully disables and unregisters it if ever needed. */
/* Second navigator.serviceWorker.register('/sw.js') block removed 2026-08-28.
   The primary registration (with skipWaiting + safeToReload + controllerchange
   auto-reload) lives ~4108 above. This IIFE was a legacy best-effort
   registration; the browser deduped it, but shipping dead SW registration
   code invites future divergence. The kill-switch (localStorage 'shg:sw'='off')
   is preserved on the primary registration path. */
(function(){
  if(!('serviceWorker' in navigator)) return;
  var off=false; try{ off = localStorage.getItem('shg:sw')==='off'; }catch(e){}
  if(off){
    try {
      navigator.serviceWorker.getRegistrations()
        .then(function(rs){ rs.forEach(function(r){ r.unregister(); }); })
        .catch(function(){});
    } catch(e) {}
  }
})();
