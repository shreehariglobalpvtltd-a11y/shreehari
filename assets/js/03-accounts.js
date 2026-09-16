
/* ================================================================
   [JS] 4c. ACCOUNTS & ROLES — users created at OTP sign-in.
   Roles: 'customer' (default) → 'agent' (admin-approved).
   The admin panel itself stays PIN-gated (same trust model as before).

   ⚠️ SECURITY NOTE (read before "going live"):
   Sign-in here is a locally mocked OTP and the admin gate is a shared
   PIN checked in the browser. That is fine for this offline-first,
   single-operator architecture, but it is NOT equivalent to real
   server-side authentication. Anyone with DevTools can read or edit
   browser storage. Real production security — rate limiting, JWT /
   server sessions, server-side role checks, HTTPS-enforced APIs —
   requires the eventual backend (see the ROADMAP block near CONFIG).
   Do not advertise "secure login" to end users until that exists.
================================================================ */
const userByPhone = (p) => DB.users.find(u => u.phone === digits(p));
function ensureUser(phone) {
  const p = digits(phone);
  let u = userByPhone(p);
  if (!u) {
    u = { phone: p, name: '', role: 'customer', createdAt: Date.now(),
          refCode: '', agentRequestPending: false, blocked: false,
          loyaltyPoints: 0, loyaltyHistory: [] };
    DB.users.push(u);
    persist('users');
  }
  return u;
}
function genRefCode(phone) {
  // short, readable, collision-checked against existing agents
  const A = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  for (let tries = 0; tries < 40; tries++) {
    let s = '';
    for (let i = 0; i < 4; i++) s += A[Math.floor(Math.random() * A.length)];
    const code = 'SHG' + s;
    if (!DB.users.some(u => u.refCode === code)) return code;
  }
  return 'SHG' + digits(phone).slice(-6);
}
const agentByCode = (code) => DB.users.find(u => u.role === 'agent' && u.refCode === String(code || '').toUpperCase());
const maskPhone = (p) => { const d = digits(p); return d.length >= 10 ? d.slice(0, 2) + '•••••' + d.slice(7) : '•••'; };
const maskName = (n) => { const s = String(n || '').trim(); return s ? s.split(/\s+/).map(w => w[0] + '…').join(' ') : '—'; };

/* Admin activity / audit log — one entry per admin action. */
function audit(action, detail) {
  DB.auditLog.unshift({ ts: Date.now(), actor: 'admin', action: action, detail: String(detail || '') });
  if (DB.auditLog.length > 500) DB.auditLog.length = 500;   // keep storage bounded
  persist('auditLog');
}

/* Referral capture: a shared link looks like  site/#/?ref=SHG1234
   Remember the code on this device so the eventual booking is tagged. */
function captureReferralFromURL() {
  const m = (location.hash + location.search).match(/[?&]ref=([A-Za-z0-9]+)/);
  if (!m) return;
  try { localStorage.setItem('shg:pendingRef', m[1].toUpperCase()); } catch (e) {}
  if (location.hash.indexOf('?') >= 0) location.hash = location.hash.split('?')[0];
}
function pendingRefCode() {
  try { return (localStorage.getItem('shg:pendingRef') || '').toUpperCase(); } catch (e) { return ''; }
}

/* Agent late-booking (owner ask, 3 Sep 2026): the best-known SHG-NNN agent
   code to send with a SEARCH, so a valid agent surfaces (and can then book) a
   bus that has already departed — within its 24h grace. Order: a code the
   agent typed once (persisted) → a ?ref= link → a signed-in agent's own code.
   Empty for an ordinary customer, so the server treats the search exactly as
   before. The DEPARTED-bus BOOKING itself is always re-checked server-side
   against a valid code — this only decides what search shows. */
function agentSearchCode() {
  try {
    var stored = (localStorage.getItem('shg_agent_code') || '').trim().toUpperCase();
    if (stored) return stored;
  } catch (e) {}
  try {
    var pr = (typeof pendingRefCode === 'function') ? (pendingRefCode() || '') : '';
    if (pr) return String(pr).toUpperCase();
  } catch (e) {}
  try {
    var me = (typeof USER !== 'undefined' && USER && typeof userByPhone === 'function') ? userByPhone(USER.phone) : null;
    if (me && me.role === 'agent' && me.refCode) return String(me.refCode).toUpperCase();
  } catch (e) {}
  return '';
}

/* Remember an agent code the user typed (checkout or the search-screen field)
   so their next search already surfaces departed buses. */
function persistAgentCode(code) {
  try {
    code = (code || '').trim().toUpperCase();
    if (code) localStorage.setItem('shg_agent_code', code);
  } catch (e) {}
}

/* ================================================================
   [JS] 4d. REFERRAL COMMISSIONS — the single calculation path.
   Rules: CONFIG.referral defaults ← DB.commissionRules overrides
   (mode flat/percent, per-route override, date-range festival bonus).
================================================================ */
function commissionRuleFor(routeId, dateISO) {
  const base = CR();
  const perRoute = (base.perRoute || {})[routeId];
  const rule = Object.assign({}, base, perRoute || {});
  let bonus = 0, bonusLabel = '';
  (base.bonuses || []).forEach(bn => {
    if (!bn || !bn.from || !bn.to) return;
    if (dateISO >= bn.from && dateISO <= bn.to) { bonus += Number(bn.extra) || 0; bonusLabel = bn.label || 'Festival bonus'; }
  });
  return { mode: rule.mode || 'flat', flat: Number(rule.flat) || 0, percent: Number(rule.percent) || 0,
           windowDays: Number(rule.windowDays) || 7, bonus: bonus, bonusLabel: bonusLabel };
}
/* Pure ₹ amount this booking would earn its agent (0 if not referred /
   self-referral / agent blocked). Sits next to the fare maths on purpose. */
function computeReferralCommission(booking) {
  const code = String(booking.referralCode || '').toUpperCase();
  if (!code) return { amount: 0 };
  const agent = agentByCode(code);
  if (!agent || agent.blocked) return { amount: 0 };
  if (digits(booking.contact && booking.contact.phone) === agent.phone) return { amount: 0 };  // self-referral guard
  const rule = commissionRuleFor(booking.routeId, booking.date);
  const seats = (booking.seats || []).length + ((booking.ret && booking.ret.seats) || []).length;
  let amt = rule.mode === 'percent'
    ? Math.round((booking.total || 0) * rule.percent / 100)
    : rule.flat;                       // flat ₹ per confirmed booking
  amt += rule.bonus * (rule.bonus ? seats : 0);   // festival bonus is per ticket/seat
  return { amount: Math.max(0, amt), agent: agent, rule: rule };
}
/* Booking confirmed by admin → create the commission entry exactly once. */
function onBookingConfirmed(b) {
  awardBookingLoyalty(b);
  const calc = computeReferralCommission(b);
  if (!calc.agent) return;
  if (DB.commissions.some(c => c.bookingId === b.id)) return;   // idempotent
  const withinWindow = (Date.now() - (b.createdAt || 0)) <= calc.rule.windowDays * 86400000;
  DB.commissions.unshift({
    id: 'C' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
    bookingId: b.id, agentId: calc.agent.phone, agentCode: calc.agent.refCode,
    amount: withinWindow ? calc.amount : 0,
    status: withinWindow ? 'confirmed' : 'expired',   // expired = confirmed too late, pays ₹0
    createdAt: b.createdAt || Date.now(), confirmedAt: Date.now(), paidAt: 0,
    note: withinWindow ? (calc.rule.bonusLabel || '') : ('Confirmed after ' + calc.rule.windowDays + '-day window')
  });
  persist('commissions');
  if (withinWindow) awardPoints(calc.agent, CONFIG.booking.loyaltyReferralPoints, 'Successful referral ' + b.id, b.id);
}
/* Booking cancelled/rejected after being commissioned → reverse it. */
function reverseCommissionFor(b) {
  refundBookingPoints(b);
  const c = DB.commissions.find(x => x.bookingId === b.id);
  if (!c || c.status === 'cancelled') return;
  c.status = 'cancelled';
  c.note = ((c.note ? c.note + ' · ' : '') + 'Reversed — booking ' + (b.status || 'cancelled'));
  persist('commissions');
}
/* Redeemed loyalty points come back if the booking dies. */
function refundBookingPoints(b) {
  if (!b.pointsUsed || !b.pointsBy || b.pointsRefunded) return;
  const u = userByPhone(b.pointsBy);
  if (u) awardPoints(u, b.pointsUsed, 'Refund — booking ' + b.id + ' ' + (b.status || 'cancelled'), b.id);
  b.pointsRefunded = true;
  persist('bookings');
}
/* Wallet maths — everything derives from the commission ledger.
   pending  = confirmed but not yet paid out
   reserved = amount sitting in open withdrawal requests
   wallet   = pending − reserved (what the agent can still request) */
function agentLedger(phone) {
  const mine = DB.commissions.filter(c => c.agentId === phone);
  const sum = (f) => mine.filter(f).reduce((s, c) => s + (c.amount || 0), 0);
  const pending = sum(c => c.status === 'confirmed');
  const paid = sum(c => c.status === 'paid');
  const reserved = DB.payoutRequests.filter(p => p.agentId === phone && p.status === 'requested')
    .reduce((s, p) => s + (p.amount || 0), 0);
  return {
    entries: mine,
    confirmedCount: mine.filter(c => c.status === 'confirmed' || c.status === 'paid').length,
    pending: pending, paid: paid, earned: pending + paid,
    reserved: reserved, wallet: Math.max(0, pending - reserved)
  };
}
/* Admin marked a payout request "paid" → settle the agent's oldest
   confirmed commission entries (FIFO) so pending drops accordingly. */
function settleCommissions(phone, amount, note) {
  let left = amount;
  DB.commissions.slice().reverse().forEach(c => {
    if (left <= 0 || c.agentId !== phone || c.status !== 'confirmed') return;
    if ((c.amount || 0) <= left) { left -= c.amount; c.status = 'paid'; c.paidAt = Date.now(); if (note) c.note = ((c.note ? c.note + ' · ' : '') + note); }
  });
  persist('commissions');
}
/* Referral count (all bookings tagged with the code, any status). */
function agentReferralCount(code) {
  return DB.bookings.filter(b => String(b.referralCode || '').toUpperCase() === code).length;
}
/* Basic fraud heuristics — flags for a human to review, never auto-block. */
function agentFraudFlags(agent) {
  const flags = [];
  const mine = DB.bookings.filter(b => String(b.referralCode || '').toUpperCase() === agent.refCode);
  const seen = {};
  mine.forEach(b => {
    const p = digits(b.contact && b.contact.phone);
    if (!p) return;
    seen[p] = (seen[p] || 0) + 1;
  });
  Object.keys(seen).forEach(p => {
    if (seen[p] >= 3) flags.push('Same phone ' + maskPhone(p) + ' referred ' + seen[p] + '× ');
  });
  const recent = DB.bookings.filter(b => Date.now() - b.createdAt < 7 * 86400000);
  const recentMine = recent.filter(b => String(b.referralCode || '').toUpperCase() === agent.refCode);
  if (recent.length >= 8 && recentMine.length / recent.length > 0.6) {
    flags.push('Referred ' + recentMine.length + ' of ' + recent.length + ' bookings this week');
  }
  return flags;
}
/* Leaderboard: top agents by confirmed referrals. */
function referralLeaderboard(limit) {
  const byAgent = {};
  DB.commissions.forEach(c => {
    if (c.status !== 'confirmed' && c.status !== 'paid') return;
    byAgent[c.agentId] = (byAgent[c.agentId] || 0) + 1;
  });
  return Object.keys(byAgent)
    .map(p => ({ phone: p, user: userByPhone(p), n: byAgent[p] }))
    .sort((a, b) => b.n - a.n)
    .slice(0, limit || 10);
}

/* ================================================================
   [JS] 4e. LOYALTY POINTS & TIERS — earn on confirm, redeem at
   checkout (redemption maths lives next to calcFare, one function).
================================================================ */
function tierFor(points) {
  const tiers = (S().loyaltyTiers || CONFIG.loyaltyTiers).slice().sort((a, b) => a.min - b.min);
  let cur = tiers[0], next = null;
  tiers.forEach(tr => { if (points >= tr.min) cur = tr; });
  next = tiers.find(tr => tr.min > points) || null;
  return { tier: cur, next: next };
}
function awardPoints(user, change, reason, bookingId) {
  if (!user || !change) return;
  user.loyaltyPoints = Math.max(0, (user.loyaltyPoints || 0) + change);
  (user.loyaltyHistory = user.loyaltyHistory || []).unshift({ date: Date.now(), change: change, reason: reason, bookingId: bookingId || '' });
  if (user.loyaltyHistory.length > 200) user.loyaltyHistory.length = 200;
  persist('users');
}
/* Earn: 1 point / ₹100 of confirmed fare (configurable) — plus the
   welcome-back trip counter that already existed keeps working. */
function awardBookingLoyalty(b) {
  const u = userByPhone(b.contact && b.contact.phone);
  if (!u) return;
  if ((u.loyaltyHistory || []).some(h => h.bookingId === b.id && h.change > 0)) return;   // idempotent
  const pts = Math.floor((b.total || 0) / 100 * CONFIG.booking.loyaltyPointsPer100);
  if (pts > 0) awardPoints(u, pts, 'Booking confirmed ' + b.id, b.id);
}
/* Completed trips (departure passed, not cancelled) earn a small bonus.
   Swept on boot — idempotent via the per-booking history check. */
function awardCompletedTrips() {
  const now = Date.now();
  let touched = false;
  DB.bookings.forEach(b => {
    if (b.status !== 'confirmed' || depTimestamp(b) > now) return;
    const u = userByPhone(b.contact && b.contact.phone);
    if (!u) return;
    if ((u.loyaltyHistory || []).some(h => h.bookingId === b.id && /Trip completed/.test(h.reason))) return;
    awardPoints(u, CONFIG.booking.loyaltyTripBonus, 'Trip completed ' + b.id, b.id);
    touched = true;
  });
  return touched;
}
/* Redemption maths — one place, next to the fare logic in spirit:
   how many ₹ off for the points this user chose to burn. */
function loyaltyRedemption(user, requestedPoints, fareTotal) {
  if (!user) return { points: 0, rupees: 0 };
  const cfg = CONFIG.booking.loyaltyRedeem;
  const usable = Math.min(user.loyaltyPoints || 0, requestedPoints || 0);
  const blocks = Math.floor(usable / cfg.points);
  const rupees = Math.min(blocks * cfg.rupees, Math.max(0, fareTotal - 1));  // never below ₹1
  return { points: Math.floor(rupees / cfg.rupees) * cfg.points, rupees: rupees };
}

/* ================================================================
   [JS] 4f. GEO HELPERS — Haversine distance (pure JS, no API) for
   the passenger-side "distance to boarding point" calculator.
================================================================ */
function haversineKm(lat1, lng1, lat2, lng2) {
  const R = 6371, toRad = (d) => d * Math.PI / 180;
  const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
    + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/* ================================================================
   [JS] 4g. LIGHTWEIGHT "WHERE AM I" — available anywhere on the site
   (Help section, any page), separate from the live navigator's continuous
   high-accuracy watchPosition. Single-shot, low-power getCurrentPosition;
   resolves a place name from data already on this page first (instant,
   free, offline) and only calls Nominatim's reverse-geocode as a cached,
   rate-limited fallback when nothing known is nearby. Never runs alongside
   the full nav view's watchPosition loop — this is the "just roughly where
   am I" helper, that one is the "turn-by-turn while moving" helper.
================================================================ */
function getLightLocation() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('no-geo')); return; }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      (err) => reject(err),
      { enableHighAccuracy: false, maximumAge: 60000, timeout: 8000 }
    );
  });
}
function nearestKnownPlace(lat, lng) {
  const pool = [];
  (typeof SN_STOPS !== 'undefined' ? SN_STOPS : []).forEach((s) => pool.push({ name: s.n, lat: s.lat, lng: s.lng }));
  (typeof SHG_TEMPLES !== 'undefined' ? SHG_TEMPLES : []).forEach((tp) => pool.push({ name: tp.name + ' (' + tp.city + ')', lat: tp.lat, lng: tp.lng }));
  let best = null, bestKm = Infinity;
  pool.forEach((p) => { const d = haversineKm(lat, lng, p.lat, p.lng); if (d < bestKm) { bestKm = d; best = p; } });
  return best ? { name: best.name, km: bestKm } : null;
}
const _lightGeoCache = {};
function reverseGeocodeCached(lat, lng) {
  const key = lat.toFixed(2) + ',' + lng.toFixed(2); /* ~1.1km cells — avoids re-querying the same area */
  if (_lightGeoCache[key]) return Promise.resolve(_lightGeoCache[key]);
  return fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat.toFixed(5) + '&lon=' + lng.toFixed(5) + '&zoom=12', { referrerPolicy: 'origin' })
    .then((r) => r.json())
    .then((j) => {
      const addr = j.address || {};
      const name = addr.town || addr.village || addr.city || addr.county || (j.display_name ? j.display_name.split(',')[0] : null) || 'nearby area';
      _lightGeoCache[key] = name;
      return name;
    });
}
/* ================================================================
   [JS] 4h. LIVE BUS POSITION — backend-agnostic postLocation()/subscribeLocation()
   pair for the public live-tracking view. [NEEDS BACKEND]: this demo shim only
   syncs across tabs of the SAME browser (BroadcastChannel + a localStorage
   last-known fallback) — there is no server here, by design, per project scope.
   To go cross-device, replace ONLY the bodies of these two functions with a
   Firebase Realtime DB / Supabase Realtime channel write+listen; every caller
   (the driver-broadcast toggle, the passenger tracking card) stays unchanged.
================================================================ */
var _liveBusChannel = (typeof BroadcastChannel !== 'undefined') ? new BroadcastChannel('shari-live-bus') : null;
function postLocation(loc) {
  var msg = { lat: loc.lat, lng: loc.lng, bearing: loc.bearing != null ? loc.bearing : null, ts: loc.ts || Date.now() };
  try { localStorage.setItem('shari:livebus', JSON.stringify(msg)); } catch (e) {}
  if (_liveBusChannel) _liveBusChannel.postMessage(msg);
}
function subscribeLocation(cb) {
  try { var last = JSON.parse(localStorage.getItem('shari:livebus') || 'null'); if (last) cb(last); } catch (e) {}
  var onMsg = function (e) { cb(e.data); };
  if (_liveBusChannel) _liveBusChannel.addEventListener('message', onMsg);
  var onStorage = function (e) { if (e.key === 'shari:livebus' && e.newValue) { try { cb(JSON.parse(e.newValue)); } catch (err) {} } };
  window.addEventListener('storage', onStorage);
  return function unsubscribeLocation() {
    if (_liveBusChannel) _liveBusChannel.removeEventListener('message', onMsg);
    window.removeEventListener('storage', onStorage);
  };
}

/* onUpdate({state:'locating'|'known'|'denied', text}) — caller renders it however it likes. */
function describeWhereAmI(onUpdate) {
  onUpdate({ state: 'locating', text: 'Locating…' });
  getLightLocation().then(({ lat, lng }) => {
    const near = nearestKnownPlace(lat, lng);
    if (near && near.km <= 15) {
      onUpdate({ state: 'known', text: 'near ' + near.name + ' (~' + (near.km < 1 ? Math.round(near.km * 1000) + ' m' : near.km.toFixed(1) + ' km') + ' away)' });
      return;
    }
    reverseGeocodeCached(lat, lng).then((name) => {
      onUpdate({ state: 'known', text: 'near ' + name });
    }).catch(() => {
      onUpdate({ state: 'known', text: near ? ('closest known point: ' + near.name + ' (~' + near.km.toFixed(0) + ' km away)') : 'location found, but the area name is unavailable' });
    });
  }).catch(() => onUpdate({ state: 'denied', text: 'Location permission needed to show this' }));
}

/* ================================================================
   [JS] 4g. DARK MODE — data-theme="dark" on <html>, tokens override
   in CSS section 26. Applies site-wide; persisted per device.
================================================================ */
function applyTheme(mode) {
  const dark = mode === 'dark';
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  /* The phone's status bar / address bar follows the page: OLED black in
     dark mode, brand navy in light (4 Sep 2026 — it used to stay navy). */
  document.documentElement.style.background = dark ? '#000' : '';
  const tc = document.querySelector('meta[name="theme-color"]');
  if (tc) tc.setAttribute('content', dark ? '#000000' : '#1A3A6A');
  $$('.theme-btn').forEach(b => { b.textContent = dark ? '☀️' : '🌙'; b.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode'); });
  try { localStorage.setItem('shg:theme', mode); } catch (e) {}
  store.set('shg:theme', mode);
}
(function initTheme() {
  let mode = 'light';
  try { mode = localStorage.getItem('shg:theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
  applyTheme(mode);
})();
document.addEventListener('click', (e) => {
  if (!e.target.closest('.theme-btn')) return;
  applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
});

/* Palette picker (Part 8): the 7 tuned day-palettes (data-day 0–6) become a
   manual theme chooser; "Auto" restores the deity day-of-week rotation. It
   only touches data-day, so it's orthogonal to the light/dark toggle and each
   palette works in both. Accents (--blue/--orange) survive dark mode because
   the dark block overrides surfaces only. */
(function () {
  var pop = document.getElementById('palettePop');
  if (!pop) return;
  /* 'brand' (13 Sep 2026): the saffron #FF6B00 + navy #0A0A1A palette from the
     master prompt's brand system, tokens in views.css section 27. */
  var PAL = [['brand', 'Saffron', '#0A0A1A', '#FF6B00'], ['0', 'Royal', '#2E5FA8', '#F07C1F'], ['1', 'Ocean', '#1B6B93', '#E8672C'],
    ['2', 'Amethyst', '#5B4FA8', '#D4830F'], ['3', 'Emerald', '#2A7E6F', '#E05A3A'],
    ['4', 'Orchid', '#7B4B9E', '#CF7A21'], ['5', 'Azure', '#2868A8', '#D4561E'],
    ['6', 'Bronze', '#8B5A3C', '#C47A22']];
  function saved() { try { return localStorage.getItem('shg:palette'); } catch (e) { return null; } }
  function mark(v) {
    var sel = (v === null || v === '') ? 'auto' : v;
    pop.querySelectorAll('.pal-sw').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pal') === sel); });
  }
  function setPalette(v) {
    try { if (v === 'auto') localStorage.removeItem('shg:palette'); else localStorage.setItem('shg:palette', v); } catch (e) {}
    document.documentElement.setAttribute('data-day', v === 'auto' ? String(new Date().getDay()) : v);
    mark(v === 'auto' ? null : v);
  }
  pop.innerHTML = PAL.map(function (p) {
    return '<button type="button" class="pal-sw" role="menuitemradio" data-pal="' + p[0] + '" title="' + p[1] + '">'
      + '<span class="pal-dot" style="background:linear-gradient(135deg,' + p[2] + ' 58%,' + p[3] + ' 58%)"></span>' + p[1] + '</button>';
  }).join('') + '<button type="button" class="pal-sw pal-auto" role="menuitemradio" data-pal="auto">🔄 Auto · by day</button>';
  mark(saved());
  pop.addEventListener('click', function (e) {
    var b = e.target.closest('.pal-sw'); if (!b) return;
    setPalette(b.getAttribute('data-pal'));
    pop.classList.remove('open');
    var pb = document.querySelector('.palette-btn'); if (pb) pb.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('click', function (e) {
    var pb = e.target.closest('.palette-btn');
    if (pb) { var open = pop.classList.toggle('open'); pb.setAttribute('aria-expanded', open ? 'true' : 'false'); return; }
    if (!e.target.closest('.palette-pop')) pop.classList.remove('open');
  });
})();

/* ================================================================
   [JS] 4h. SESSION TIMEOUT — auto sign-out after N idle minutes
   (Admin → Settings, 0 disables). Client-side hygiene only; it does
   NOT replace server-side session invalidation (see note in 4c).
================================================================ */
let LAST_ACTIVITY = Date.now();
['click', 'keydown', 'scroll', 'touchstart'].forEach(ev =>
  document.addEventListener(ev, () => { LAST_ACTIVITY = Date.now(); }, { passive: true }));
setInterval(() => {
  const mins = S().sessionTimeoutMin;
  if (!mins || !USER) return;
  if (Date.now() - LAST_ACTIVITY > mins * 60000) {
    setUser(null);
    if (location.hash === '#/my') renderMyBookings();
    else if (location.hash === '#/admin' || location.hash === '#/shg-ctrl') { location.hash = '#/'; }
    toast('Signed out after inactivity 🔒');
  }
}, 60000);
