
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
  // Drop only ref= from the hash query: #/my?pnr=... must survive a ?ref= link (8 Oct 2026).
  if (location.hash.indexOf('?') >= 0) {
    const parts = location.hash.split('?');
    const rest = parts.slice(1).join('?').split('&').filter(function (kv) { return kv && kv.indexOf('ref=') !== 0; });
    const next = parts[0] + (rest.length ? '?' + rest.join('&') : '');
    if (next !== location.hash) location.hash = next;
  }
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
  /* The tier % is money off at checkout: shown and applied only while the
     office has the member discount switched on (2 Oct 2026). */
  if (!CONFIG.loyaltyTierDiscountOn) {
    cur = Object.assign({}, cur, { discountPct: 0 });
    if (next) next = Object.assign({}, next, { discountPct: 0 });
  }
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
/* Three displays (4 Oct 2026): light · dark · AMOLED. AMOLED is stamped as
   data-theme="dark" + data-display="amoled" so every dark rule still
   applies and assets/css/depth.css only swaps the tokens to a pure-black
   ground. The browser cannot tell whether a screen is AMOLED, so it is an
   explicit choice only; with nothing saved the system light/dark is used.
   The value is written raw: it used to go through store.set as well, which
   JSON-encoded it ('"dark"'), so the pre-paint check missed it and a saved
   dark theme came back light on the next visit. */
/* 9 Oct 2026 (Pixel / Material You): the colour palettes of assets/css/themes.css.
   html[data-palette="<id>"] re-points the design tokens; no attribute = the S Hari
   brand look. Per palette: tri-tone swatch [top, bottom-left, bottom-right], status
   bar [light, dark], dark page colour, then the name in ne / en / hi. Generated with
   themes.css - tests/theme-palette-test.php keeps this list, the CSS and the
   pre-paint whitelist in app.template.html identical. Declared here, above
   initTheme(), because applyTheme() reads it on the very first call.
   10 Oct 2026 (owner: "default colour professional, white-blue, golden-orange
   like the logo"): the first three rows are the BRAND LOOKS - hand-tuned in
   themes.css to the HD visual guide, shown first in the sheet under their own
   label (SHG_BRAND_LOOK_IDS); professional is the owner default. */
const SHG_PALETTES = [
  ['professional', ['#154faa', '#b8d5ff', '#d59a37'], ['#0f2f66', '#10203a'], '#0a1018', 'प्रोफेसनल (मूल)', 'Professional (default)', 'प्रोफ़ेशनल (मूल)'],
  ['whiteblue',  ['#0054a8', '#d9e7fa', '#8fbfff'], ['#0c306c', '#0f1f3d'], '#0b1220', 'सेतो-निलो', 'White & Blue', 'सफ़ेद-नीला'],
  ['goldenorange', ['#b4530a', '#ffb26e', '#e2a528'], ['#3a1f05', '#241608'], '#15100b', 'सुनौलो-सुन्तला', 'Golden & Orange', 'सुनहरा-नारंगी'],
  ['himalaya',   ['#3567d3', '#bbc3ff', '#dd8eb3'], ['#003175', '#141a32'], '#12131b', 'हिमालय नीलो', 'Himalaya Blue', 'हिमालय नीला'],
  ['laligurans', ['#d32137', '#ffb3ad', '#87b177'], ['#6c0b17', '#311210'], '#1c100f', 'लालीगुराँस', 'Laligurans Red', 'बुरांश लाल'],
  ['saffron',    ['#a95700', '#fcb886', '#7eb378'], ['#542a00', '#2c1700'], '#1b1109', 'केसरी', 'Saffron', 'केसरिया'],
  ['tulsi',      ['#2f7c31', '#a1d39b', '#d6986a'], ['#003e09', '#0d2008'], '#0e150c', 'तुलसी हरियो', 'Tulsi Green', 'तुलसी हरा'],
  ['phewa',      ['#007983', '#6ed6e2', '#80a8df'], ['#003b41', '#001f23'], '#051617', 'फेवा ताल', 'Phewa Teal', 'फेवा झील'],
  ['lotus',      ['#c92283', '#ffafd3', '#cd9d60'], ['#6a0342', '#301120'], '#1b1015', 'कमल गुलाबी', 'Lotus Pink', 'कमल गुलाबी'],
  ['lavender',   ['#7f57c2', '#d6bbfa', '#e58c9e'], ['#3f266b', '#20172f'], '#16121a', 'साँझ बैजनी', 'Lavender Dusk', 'सांझ बैंगनी'],
  ['marigold',   ['#886900', '#e4c27c', '#f38781'], ['#433200', '#241a00'], '#181307', 'सयपत्री', 'Marigold', 'गेंदा'],
  ['monsoon',    ['#517280', '#96cee8', '#6bb2ac'], ['#173946', '#141d21'], '#08151a', 'मनसुन', 'Monsoon Slate', 'मानसून'],
  ['everest',    ['#0074a5', '#8ecdfd', '#bc99cf'], ['#003953', '#001e2f'], '#0b141b', 'सगरमाथा आकाश', 'Everest Sky', 'एवरेस्ट आसमान'],
  ['terracotta', ['#b64c29', '#ffb59b', '#44b4ca'], ['#651a00', '#301406'], '#1c110c', 'भक्तपुर इँटा', 'Bhaktapur Brick', 'भक्तपुर ईंट'],
  ['peacock',    ['#007b6a', '#7fd6c3', '#8ea3eb'], ['#003c33', '#00201b'], '#061613', 'मयूर', 'Peacock', 'मोर']
];
const SHG_PAL_IDS = SHG_PALETTES.map(p => p[0]);
/* 10 Oct 2026: the three brand looks lead the colour grid under their own label;
   every other row is a Material You palette. */
const SHG_BRAND_LOOK_IDS = ['professional', 'whiteblue', 'goldenorange'];
function shgPalRow(id) { for (let i = 0; i < SHG_PALETTES.length; i++) { if (SHG_PALETTES[i][0] === id) return SHG_PALETTES[i]; } return null; }
/* 10 Oct 2026 (review fix, stale-stylesheet guard - the JS twin of the one in
   index.php): this file can reach a phone from a newer build than its cached
   themes.css (the SW or HTTP cache keeps one file and drops the other). A
   palette that stylesheet does not know leaves --royal and --md-primary
   pointing at each other, both invalid, and every button and link loses its
   colour. A palette the CSS knows always resolves --md-primary, so one
   computed read tells; unknown -> drop the attribute, the S Hari brand look
   paints. Called by boot() and applyPalette() right after they set
   data-palette. Safe at boot: this deferred script runs only after the
   stylesheets above it have loaded. Returns false when it dropped one. */
function shgPalGuard() {
  const d = document.documentElement;
  if (!d.hasAttribute('data-palette') || !window.getComputedStyle) return true;
  try { if (getComputedStyle(d).getPropertyValue('--md-primary').trim()) return true; } catch (e) { return true; }
  d.removeAttribute('data-palette');
  return false;
}
/* The phone's status bar and the overscroll colour follow the theme AND the palette. */
function shgPaintChrome(mode) {
  const d = document.documentElement;
  const dark = mode !== 'light';
  const pal = shgPalRow(d.getAttribute('data-palette'));
  d.style.background = mode === 'amoled' ? '#000' : (dark ? (pal ? pal[3] : '#0B1220') : '');
  const tc = document.querySelector('meta[name="theme-color"]');
  if (tc) tc.setAttribute('content', mode === 'amoled' ? '#000000' : (pal ? pal[2][dark ? 1 : 0] : (dark ? '#0F1A2E' : '#0C306C')));
}
/* One answer for "should this move?" - the in-app Motion switch (html[data-motion])
   or the phone's own reduce-motion setting. JS motion (WAAPI / rAF) asks this. */
window.shgReducedMotion = function () {
  if (document.documentElement.getAttribute('data-motion') === 'reduced') return true;
  try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; }
};
function shgFeelFire(kind) { try { if (window.SHGFeel) window.SHGFeel.fire(kind); } catch (e) {} }

const SHG_THEMES = ['light', 'dark', 'amoled'];
function readSavedTheme() {
  try {
    const raw = (localStorage.getItem('shg:theme') || '').replace(/"/g, '');
    return SHG_THEMES.indexOf(raw) >= 0 ? raw : '';
  } catch (e) { return ''; }
}
function currentTheme() {
  const d = document.documentElement;
  if (d.getAttribute('data-display') === 'amoled') return 'amoled';
  return d.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}
function applyTheme(mode, save) {
  if (SHG_THEMES.indexOf(mode) < 0) mode = 'light';
  const d = document.documentElement;
  const dark = mode !== 'light';
  d.setAttribute('data-theme', dark ? 'dark' : 'light');
  if (mode === 'amoled') d.setAttribute('data-display', 'amoled'); else d.removeAttribute('data-display');
  /* The phone's status bar / address bar follows the page (and the palette, 9 Oct 2026). */
  shgPaintChrome(mode);
  /* The topbar button cycles light → dark → AMOLED; it shows the next one. */
  const next = { light: ['🌙', 'Switch to dark mode'], dark: ['⚫', 'Switch to AMOLED black mode'], amoled: ['☀️', 'Switch to light mode'] }[mode];
  $$('.theme-btn').forEach(b => { b.textContent = next[0]; b.setAttribute('aria-label', next[1]); b.setAttribute('title', next[1]); });
  if (save !== false) { try { localStorage.setItem('shg:theme', mode); } catch (e) {} }
  /* Phase 12 (8 Oct 2026): the menu's "Auto" radio is the lit one while no
     choice is saved (the screen follows the phone's own light / dark). */
  const picked = (save === false && !readSavedTheme()) ? 'auto' : mode;
  $$('[data-theme-set]').forEach(b => {
    const on = b.getAttribute('data-theme-set') === picked;
    b.classList.toggle('on', on);
    b.setAttribute('aria-checked', on ? 'true' : 'false');
  });
}
/* Nothing saved: the phone's light / dark, read now. AMOLED is never guessed. */
function systemTheme() {
  try { return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; } catch (e) { return 'light'; }
}
/* The counter screen (?counter=1) follows the office panel instead (D9-08). */
function isCounterThemed() {
  try { return /[?&]counter=1(?:&|$)/.test(location.search); } catch (e) { return false; }
}
(function initTheme() {
  let mode = readSavedTheme();
  /* D9-08 (8 Oct 2026): the counter screen (?counter=1) follows the office
     panel's theme while this device has no customer theme saved - same rule
     as the pre-paint script in app.template.html. Not saved as a customer
     choice. */
  if (!mode) {
    try {
      if (/[?&]counter=1(?:&|$)/.test(location.search)) {
        const at = (localStorage.getItem('shg_admin_theme') || 'dark').replace(/"/g, '');
        if (SHG_THEMES.indexOf(at) >= 0) { applyTheme(at, false); return; }
      }
    } catch (e) {}
  }
  if (!mode) {
    try { mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; } catch (e) { mode = 'light'; }
    applyTheme(mode, false);           /* follow the system until the user picks one */
  } else {
    applyTheme(mode);                  /* also rewrites an old '"dark"' value raw */
  }
})();
/* Phase 12 (8 Oct 2026): follow the system theme LIVE. When the phone flips
   to dark at sunset (or the passenger changes it in Settings) and nothing is
   saved here, the page follows at once - only attributes on <html> change,
   so no view re-renders and no half-typed form is lost. A saved choice
   (Light / Dark / AMOLED) always wins; "Auto" in the menu clears it. */
(function followSystemTheme() {
  let mq = null;
  try { mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)'); } catch (e) {}
  if (!mq) return;
  const onChange = () => {
    if (readSavedTheme() || isCounterThemed()) return;
    applyTheme(systemTheme(), false);
  };
  try { mq.addEventListener('change', onChange); }
  catch (e) { try { mq.addListener(onChange); } catch (e2) {} }   // older Safari / WebViews
})();
document.addEventListener('click', (e) => {
  const set = e.target.closest('[data-theme-set]');
  if (set) {
    const v = set.getAttribute('data-theme-set');
    if (v === 'auto') {
      try { localStorage.removeItem('shg:theme'); } catch (err) {}
      applyTheme(systemTheme(), false);
    } else {
      applyTheme(v);
    }
    shgFeelFire('select');
    return;
  }
  if (!e.target.closest('.theme-btn')) return;
  const cur = currentTheme();
  applyTheme(cur === 'light' ? 'dark' : (cur === 'dark' ? 'amoled' : 'light'));
  shgFeelFire('select');
});

/* ================================================================
   [JS] 4g-2. APPEARANCE - colour palette, UI style, motion, sound
   (9 Oct 2026, owner: "dherai colour theme option, UI Android Google
   Pixel jasto, smooth colour, sound haru pani testai").
   The retired day-palette picker (data-day, Oct redesign) is gone; this
   replaces it with one bottom sheet, "🎨 रूप · Appearance", opened from a
   row in the phone menu and from the topbar's 🎨 button on a computer:
     Display  Auto / Light / Dark / AMOLED  (the same [data-theme-set]
              buttons the menu already has - applyTheme() above drives both)
     Colour   two radiogroups [data-palette-set] (10 Oct 2026): "Brand looks"
              = S Hari (brand) + professional (default) + white & blue +
              golden & orange; "More colours" = 12 Material You palettes
     Style    Pixel (rounded, tonal) / Classic 3D (today's raised depth)
     Motion   Full / Reduced (html[data-motion="reduced"])
     Sound    on/off, style (Pixel / Classic), vibration - via window.SHGFeel
   Saved per device: shg:palette ('shg' = the brand look chosen on purpose;
   an old '0'-'6' / 'brand' / 'logo' / 'auto' value counts as nothing saved),
   shg:ui, shg:motion, shg:soundpack. Nothing saved = the owner's defaults
   from Admin -> Settings (app_theme_palette, app_ui_style, app_motion_on,
   app_sound_pack), stamped before paint by index.php; the template's
   pre-paint script stamps a saved choice even earlier.
   Accessible: a modal dialog with focus trap, Esc and the Android back key;
   every group is a radiogroup with arrow keys; 44px+ targets; every change
   is announced by a sound / buzz through SHGFeel (respecting its switches).
================================================================ */
(function () {
  const d = document.documentElement;
  const lsGet = (k) => { try { return (localStorage.getItem(k) || '').replace(/"/g, ''); } catch (e) { return ''; } };
  const lsSet = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
  const owner = (k, dflt) => {
    try { const s = window.SHG_BOOT && window.SHG_BOOT.settings; return (s && s[k] != null && s[k] !== '') ? s[k] : dflt; } catch (e) { return dflt; }
  };
  const lang = () => {
    try { if (typeof LANG === 'string' && /^(ne|en|hi)$/.test(LANG)) return LANG; } catch (e) {}
    const l = (d.getAttribute('lang') || '').slice(0, 2);
    return /^(ne|en|hi)$/.test(l) ? l : 'ne';
  };
  const T = {
    title: ['रूप', 'Appearance', 'रूप'],
    sub: ['रङ, शैली, चाल र आवाज', 'Colour, style, motion and sound', 'रंग, शैली, गति और आवाज़'],
    menu: ['रूप · Appearance', 'Appearance · रूप', 'रूप · Appearance'],
    menuSub: ['रङ, Pixel शैली, आवाज', 'Colours, Pixel style, sounds', 'रंग, Pixel शैली, आवाज़'],
    display: ['स्क्रिन', 'Display', 'स्क्रीन'],
    auto: ['Auto', 'Auto', 'Auto'], autoS: ['फोन जस्तै', 'Like phone', 'फ़ोन जैसा'],
    light: ['उज्यालो', 'Light', 'लाइट'], lightS: ['दिउँसो', 'Day', 'दिन'],
    dark: ['अँध्यारो', 'Dark', 'डार्क'], darkS: ['राति', 'Night', 'रात'],
    amoled: ['AMOLED', 'AMOLED', 'AMOLED'], amoledS: ['पूरा कालो', 'Pure black', 'पूरा काला'],
    colour: ['रङ', 'Colour', 'रंग'],
    colourNote: ['रङले सिट, भाडा वा टिकटको अर्थ बदल्दैन - हरियो सधैं ठीक, रातो सधैं सावधान।', 'Colours never change what a seat, fare or status means - green is always OK, red is always careful.', 'रंग से सीट, किराया या स्टेटस का मतलब नहीं बदलता - हरा हमेशा ठीक, लाल हमेशा सावधान।'],
    /* 10 Oct 2026 (review fix): "(default)" belongs to professional alone now;
       the logo's own colours are the BRAND look, named so. */
    brand: ['S Hari (ब्रान्ड)', 'S Hari (brand)', 'S Hari (ब्रांड)'],
    brandS: ['लोगोकै नेभी, निलो र रातो', 'The logo\'s navy, royal blue and red', 'लोगो वाला नेवी, नीला और लाल'],
    /* 10 Oct 2026: the two group labels, each naming its own radiogroup */
    brandLooks: ['ब्रान्ड रूप · Brand looks', 'Brand looks · ब्रान्ड रूप', 'ब्रांड रूप · Brand looks'],
    moreColours: ['थप रङहरू · Material You', 'More colours · Material You', 'और रंग · Material You'],
    style: ['शैली', 'Style', 'शैली'],
    pixel: ['Pixel', 'Pixel', 'Pixel'], pixelS: ['गोलो र नरम', 'Round & soft', 'गोल और नरम'],
    classic: ['क्लासिक 3D', 'Classic 3D', 'क्लासिक 3D'], classicS: ['उठेको गहिराइ', 'Raised depth', 'उभरी गहराई'],
    motion: ['चाल (एनिमेसन)', 'Motion', 'गति (एनिमेशन)'],
    full: ['पूरा', 'Full', 'पूरी'], fullS: ['स्प्रिङ जस्तो', 'Springy', 'स्प्रिंग जैसी'],
    reduced: ['कम', 'Reduced', 'कम'], reducedS: ['शान्त स्क्रिन', 'Calm screen', 'शांत स्क्रीन'],
    sound: ['आवाज र कम्पन', 'Sound & vibration', 'आवाज़ और कंपन'],
    soundOn: ['आवाज', 'Sound', 'आवाज़'], soundOnS: ['ट्याप, सिट र टिकटमा', 'Taps, seats and tickets', 'टैप, सीट और टिकट पर'],
    pack: ['आवाजको शैली', 'Sound style', 'आवाज़ की शैली'],
    packPixel: ['Pixel', 'Pixel', 'Pixel'], packPixelS: ['नयाँ, नरम घण्टी', 'New, soft chimes', 'नई, नरम घंटी'],
    packClassic: ['क्लासिक', 'Classic', 'क्लासिक'], packClassicS: ['पहिलेको आवाज', 'The original', 'पहले वाली'],
    vib: ['कम्पन', 'Vibration', 'कंपन'], vibS: ['छुँदा हल्का कम्पन', 'A light buzz on touch', 'छूने पर हल्का कंपन'],
    done: ['ठीक छ', 'Done', 'ठीक है'],
    close: ['बन्द गर्नुहोस्', 'Close', 'बंद करें']
  };
  const L = { ne: 0, en: 1, hi: 2 };
  const tx = (k) => (T[k] || [k])[L[lang()]] || T[k][1];
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---- apply + save ---- */
  function savedPalette() { const v = lsGet('shg:palette'); return (v === 'shg' || SHG_PAL_IDS.indexOf(v) >= 0) ? v : ''; }
  function applyPalette(id, save) {
    const ok = SHG_PAL_IDS.indexOf(id) >= 0;
    if (ok) d.setAttribute('data-palette', id); else d.removeAttribute('data-palette');
    if (save !== false) lsSet('shg:palette', ok ? id : 'shg');
    shgPalGuard();   /* 10 Oct 2026: a stale cached themes.css -> brand look, never colourless */
    shgPaintChrome(currentTheme());
  }
  function applyUi(v, save) {
    v = v === 'classic' ? 'classic' : 'pixel';
    d.setAttribute('data-ui', v);
    if (save !== false) lsSet('shg:ui', v);
  }
  function applyMotion(v, save) {
    if (v === 'reduced') d.setAttribute('data-motion', 'reduced'); else d.removeAttribute('data-motion');
    if (save !== false) lsSet('shg:motion', v === 'reduced' ? 'reduced' : 'full');
  }
  window.shgAppearance = { applyPalette, applyUi, applyMotion, open: (o) => open(o) };

  /* Boot: index.php already stamped the owner's defaults where nothing is saved;
     this repeats that rule for a page served without the inject (idempotent). */
  (function boot() {
    try {
      const sp = savedPalette();
      if (sp === 'shg') d.removeAttribute('data-palette');
      else if (sp) d.setAttribute('data-palette', sp);
      else if (!d.hasAttribute('data-palette')) { const op = String(owner('app_theme_palette', '')); if (SHG_PAL_IDS.indexOf(op) >= 0) d.setAttribute('data-palette', op); }
      shgPalGuard();   /* 10 Oct 2026 (review fix): never undo index.php's stale-stylesheet guard */
      const su = lsGet('shg:ui');
      if (su === 'pixel' || su === 'classic') d.setAttribute('data-ui', su);
      else if (!d.hasAttribute('data-ui')) d.setAttribute('data-ui', String(owner('app_ui_style', 'pixel')) === 'classic' ? 'classic' : 'pixel');
      const sm = lsGet('shg:motion');
      if (sm === 'reduced') d.setAttribute('data-motion', 'reduced');
      else if (sm === 'full') d.removeAttribute('data-motion');
      else { const om = owner('app_motion_on', true); if (om === false || om === 0 || om === '0') d.setAttribute('data-motion', 'reduced'); }
      shgPaintChrome(currentTheme());
    } catch (e) {}
  })();

  /* ---- the sheet ---- */
  let sheet = null, scrim = null, opener = null, isOpen = false, pushed = false, closingBack = false, inerted = [];

  function swatchBg(sw) { return 'conic-gradient(from 270deg, ' + sw[0] + ' 0deg 180deg, ' + sw[2] + ' 180deg 270deg, ' + sw[1] + ' 270deg 360deg)'; }
  function opt(attrs, icon, label, small, checked) {
    return '<button type="button" class="ap-opt" role="radio" aria-checked="' + (checked ? 'true' : 'false') + '" ' + attrs + '>'
      + (icon ? '<b aria-hidden="true">' + icon + '</b>' : '') + '<span>' + esc(label) + '</span>' + (small ? '<small>' + esc(small) + '</small>' : '') + '</button>';
  }
  function toggle(name, on, label, small) {
    return '<div class="ap-row"><span class="ap-lbl" id="apLbl-' + name + '">' + esc(label) + (small ? '<small>' + esc(small) + '</small>' : '') + '</span>'
      + '<button type="button" class="ap-sw-toggle" role="switch" data-ap-toggle="' + name + '" aria-checked="' + (on ? 'true' : 'false') + '" aria-labelledby="apLbl-' + name + '"></button></div>';
  }
  function render() {
    const theme = readSavedTheme() || 'auto';
    const pal = d.getAttribute('data-palette') || '';
    const ui = d.getAttribute('data-ui') === 'classic' ? 'classic' : 'pixel';
    const mo = d.getAttribute('data-motion') === 'reduced' ? 'reduced' : 'full';
    const F = window.SHGFeel;
    const li = L[lang()];
    let h = '<span class="ap-handle" aria-hidden="true"></span>'
      + '<div class="ap-head"><h2 id="apTitle">🎨 ' + esc(tx('title')) + '<small>' + esc(tx('sub')) + '</small></h2>'
      + '<button type="button" class="ap-x" data-ap-close aria-label="' + esc(tx('close')) + '">✕</button></div>';
    h += '<section class="ap-sec"><h3 id="apDispH">🖥️ ' + esc(tx('display')) + '</h3><div class="ap-seg" style="--n:4" role="radiogroup" aria-labelledby="apDispH">'
      + opt('data-theme-set="auto"', '🌗', tx('auto'), tx('autoS'), theme === 'auto')
      + opt('data-theme-set="light"', '☀️', tx('light'), tx('lightS'), theme === 'light')
      + opt('data-theme-set="dark"', '🌙', tx('dark'), tx('darkS'), theme === 'dark')
      + opt('data-theme-set="amoled"', '⚫', tx('amoled'), tx('amoledS'), theme === 'amoled')
      + '</div></section>';
    h += '<section class="ap-sec"><h3 id="apColH">🎨 ' + esc(tx('colour')) + '<span aria-hidden="true">' + (SHG_PALETTES.length + 1) + '</span></h3>';
    const sw = (id, colours, name, sub) => '<button type="button" class="ap-sw' + (sub ? ' ap-sw-brand' : '') + '" role="radio" data-palette-set="' + id + '" aria-checked="' + ((id === 'shg' ? !pal : pal === id) ? 'true' : 'false') + '" aria-label="' + esc(name) + '">'
      + '<span class="ap-dot" data-sw="' + colours.join(',') + '" aria-hidden="true"></span><span class="ap-nm">' + esc(name) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span></button>';
    /* 10 Oct 2026 (review fix): TWO radiogroups, each named by its own label
       alone (aria-labelledby = the label <p>, which holds only the label text;
       the heading's palette count is aria-hidden), so a screen reader hears
       the grouping a sighted visitor sees: "Brand looks" = S Hari (brand)
       + the three hand-tuned looks, "More colours" = the Material You
       palettes. rove() gives each group one tab stop and the arrow keys stay
       inside a group; mark('[data-palette-set]') clears across both. */
    const grp = (k, id) => '<p class="ap-grp" id="' + id + '">' + esc(tx(k)) + '</p><div class="ap-sw-grid" role="radiogroup" aria-labelledby="' + id + '">';
    h += grp('brandLooks', 'apGrpBrand');
    h += sw('shg', ['#0054A8', '#0C306C', '#DC143C'], tx('brand'), tx('brandS'));
    SHG_PALETTES.forEach((p) => { if (SHG_BRAND_LOOK_IDS.indexOf(p[0]) >= 0) h += sw(p[0], p[1], p[4 + li]); });
    h += '</div>' + grp('moreColours', 'apGrpMore');
    SHG_PALETTES.forEach((p) => { if (SHG_BRAND_LOOK_IDS.indexOf(p[0]) < 0) h += sw(p[0], p[1], p[4 + li]); });
    h += '</div><p class="ap-note">' + esc(tx('colourNote')) + '</p></section>';
    h += '<section class="ap-sec"><h3 id="apStyH">✨ ' + esc(tx('style')) + '</h3><div class="ap-seg" role="radiogroup" aria-labelledby="apStyH">'
      + opt('data-ap-ui="pixel"', '<span class="ap-prev px"><i></i><i></i></span>', tx('pixel'), tx('pixelS'), ui === 'pixel')
      + opt('data-ap-ui="classic"', '<span class="ap-prev cl"><i></i><i></i></span>', tx('classic'), tx('classicS'), ui === 'classic')
      + '</div></section>';
    h += '<section class="ap-sec"><h3 id="apMoH">🌊 ' + esc(tx('motion')) + '</h3><div class="ap-seg" role="radiogroup" aria-labelledby="apMoH">'
      + opt('data-ap-motion="full"', '〰️', tx('full'), tx('fullS'), mo === 'full')
      + opt('data-ap-motion="reduced"', '➖', tx('reduced'), tx('reducedS'), mo === 'reduced')
      + '</div></section>';
    if (F) {
      const pack = F.pack === 'classic' ? 'classic' : 'pixel';
      h += '<section class="ap-sec"><h3>🔊 ' + esc(tx('sound')) + '</h3>'
        + toggle('sound', !!F.sound, tx('soundOn'), tx('soundOnS'))
        + '<div class="ap-seg" role="radiogroup" aria-label="' + esc(tx('pack')) + '">'
        + opt('data-ap-pack="pixel"', '🎐', tx('packPixel'), tx('packPixelS'), pack === 'pixel')
        + opt('data-ap-pack="classic"', '🔔', tx('packClassic'), tx('packClassicS'), pack === 'classic')
        + '</div>'
        + toggle('haptics', !!F.haptics, tx('vib'), tx('vibS'))
        + '</section>';
    }
    h += '<button type="button" class="ap-done" data-ap-close>' + esc(tx('done')) + '</button>';
    sheet.innerHTML = h;
    sheet.querySelectorAll('.ap-dot[data-sw]').forEach((el) => { el.style.background = swatchBg(el.getAttribute('data-sw').split(',')); });
    rove();
  }
  /* one tab stop per radiogroup: the checked radio (or the first) */
  function rove() {
    sheet.querySelectorAll('[role="radiogroup"]').forEach((g) => {
      const rs = Array.prototype.slice.call(g.querySelectorAll('[role="radio"]'));
      const on = rs.find((r) => r.getAttribute('aria-checked') === 'true') || rs[0];
      rs.forEach((r) => r.setAttribute('tabindex', r === on ? '0' : '-1'));
    });
  }
  function mark(sel, test) {
    sheet.querySelectorAll(sel).forEach((b) => b.setAttribute('aria-checked', test(b) ? 'true' : 'false'));
    rove();
  }
  function focusables() {
    return Array.prototype.slice.call(sheet.querySelectorAll('button:not([disabled]):not([tabindex="-1"]), [href], [tabindex="0"]'))
      .filter((el) => el.offsetParent !== null || el === document.activeElement);
  }
  function build() {
    scrim = document.createElement('div');
    scrim.className = 'shg-ap-scrim';
    scrim.setAttribute('aria-hidden', 'true');
    sheet = document.createElement('div');
    sheet.className = 'shg-ap';
    sheet.id = 'shgAppearance';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-labelledby', 'apTitle');
    sheet.tabIndex = -1;
    scrim.hidden = sheet.hidden = true;
    document.body.appendChild(scrim);
    document.body.appendChild(sheet);
    scrim.addEventListener('click', () => close());
    sheet.addEventListener('click', onClick);
    sheet.addEventListener('keydown', onKey);
  }
  function onClick(e) {
    const t = e.target;
    if (t.closest('[data-ap-close]')) { close(); return; }
    const p = t.closest('[data-palette-set]');
    if (p) {
      applyPalette(p.getAttribute('data-palette-set'));
      mark('[data-palette-set]', (b) => b === p);
      paintMenuRow();
      shgFeelFire('select');
      return;
    }
    if (t.closest('[data-theme-set]')) { setTimeout(rove, 0); return; }   /* the document handler above applies it */
    const u = t.closest('[data-ap-ui]');
    if (u) { applyUi(u.getAttribute('data-ap-ui')); mark('[data-ap-ui]', (b) => b === u); shgFeelFire('select'); return; }
    const m = t.closest('[data-ap-motion]');
    if (m) { applyMotion(m.getAttribute('data-ap-motion')); mark('[data-ap-motion]', (b) => b === m); shgFeelFire('select'); return; }
    const k = t.closest('[data-ap-pack]');
    if (k && window.SHGFeel) { window.SHGFeel.setPack(k.getAttribute('data-ap-pack')); mark('[data-ap-pack]', (b) => b === k); return; }
    const s = t.closest('[data-ap-toggle]');
    if (s && window.SHGFeel) {
      const on = s.getAttribute('aria-checked') !== 'true';
      s.setAttribute('aria-checked', on ? 'true' : 'false');
      const name = s.getAttribute('data-ap-toggle');
      if (name === 'sound') window.SHGFeel.setSound(on);
      else { window.SHGFeel.setHaptics(on); window.SHGFeel.play(on ? 'toggleOn' : 'toggleOff'); }
      /* keep the phone menu's own switch (19-premium.js) in step */
      const ms = document.querySelector('.feel-row[data-feel="' + name + '"] .feel-sw');
      if (ms) { ms.classList.toggle('on', on); ms.setAttribute('aria-checked', on ? 'true' : 'false'); }
      return;
    }
  }
  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      const f = focusables();
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === sheet)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      return;
    }
    const r = e.target.closest && e.target.closest('[role="radio"]');
    if (!r) return;
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
    if (!step && e.key !== 'Home' && e.key !== 'End') return;
    const g = r.closest('[role="radiogroup"]');
    const rs = Array.prototype.slice.call(g.querySelectorAll('[role="radio"]'));
    let i = rs.indexOf(r);
    i = e.key === 'Home' ? 0 : e.key === 'End' ? rs.length - 1 : (i + step + rs.length) % rs.length;
    e.preventDefault();
    rs[i].focus();
    rs[i].click();
  }
  function setInert(on) {
    if (on) {
      inerted = [];
      Array.prototype.forEach.call(document.body.children, (el) => {
        if (el === sheet || el === scrim || el.id === 'toast' || /^(SCRIPT|STYLE|LINK)$/.test(el.tagName) || el.hasAttribute('inert')) return;
        el.setAttribute('inert', ''); inerted.push(el);
      });
    } else {
      inerted.forEach((el) => el.removeAttribute('inert'));
      inerted = [];
    }
  }
  function open(o) {
    if (!sheet) build();
    if (isOpen) return;
    opener = (o && o.nodeType === 1) ? o : document.activeElement;
    /* coming from the phone menu: fold it away first */
    const mm = document.getElementById('mobileMenu');
    if (mm && mm.classList.contains('open')) {
      mm.classList.remove('open');
      const hb = document.getElementById('hamburger');
      if (hb) { hb.classList.remove('open'); hb.setAttribute('aria-expanded', 'false'); }
      if (opener && mm.contains(opener)) opener = hb;
    }
    render();
    isOpen = true;
    scrim.hidden = sheet.hidden = false;
    sheet.classList.remove('closing');
    document.body.classList.add('shg-ap-open');
    setInert(true);
    void sheet.offsetWidth;                                   /* start the spring from off-screen */
    scrim.classList.add('open');
    sheet.classList.add('open');
    try { history.pushState({ shgAppear: 1 }, '', location.href); pushed = true; } catch (e) { pushed = false; }
    const first = sheet.querySelector('[role="radio"][aria-checked="true"]') || sheet.querySelector('.ap-x');
    setTimeout(() => { try { (first || sheet).focus({ preventScroll: true }); } catch (e) {} }, 30);
    if (window.SHGFeel) { try { window.SHGFeel.play('sheetOpen'); } catch (e) {} }
  }
  function close(fromBack) {
    if (!isOpen) return;
    isOpen = false;
    sheet.classList.add('closing');
    sheet.classList.remove('open');
    scrim.classList.remove('open');
    setInert(false);
    document.body.classList.remove('shg-ap-open');
    const done = () => { if (!isOpen) { sheet.hidden = scrim.hidden = true; } };
    setTimeout(done, window.shgReducedMotion() ? 0 : 320);
    if (pushed && !fromBack) {
      pushed = false; closingBack = true;
      window._shgModalClosingBack = true;                     /* 05-router.js: not a real back */
      try { history.back(); } catch (e) { closingBack = false; window._shgModalClosingBack = false; }
    }
    pushed = false;
    if (window.SHGFeel) { try { window.SHGFeel.play('sheetClose'); } catch (e) {} }
    const ret = opener; opener = null;
    if (ret && document.contains(ret) && ret.offsetParent !== null) { try { ret.focus({ preventScroll: true }); } catch (e) {} }
  }
  /* Android back / browser back closes the sheet instead of leaving the page.
     Registered at load, before 05-router.js's own popstate handler. */
  window.addEventListener('popstate', () => {
    if (closingBack) { closingBack = false; return; }
    if (isOpen) { window._shgModalClosingBack = true; pushed = false; close(true); }
  });

  /* ---- entry points ---- */
  function paintMenuRow() {
    const row = document.querySelector('#mobileMenu .mm-appear');
    if (!row) return;
    const p = shgPalRow(d.getAttribute('data-palette'));
    const c = p ? p[1] : ['#0054A8', '#0C306C', '#DC143C'];
    row.querySelectorAll('.mm-ap-dots i').forEach((el, i) => { el.style.background = c[i]; });
  }
  function buildEntry() {
    const menu = document.getElementById('mobileMenu');
    if (menu && !menu.querySelector('.mm-appear')) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'mm-appear';
      b.setAttribute('aria-haspopup', 'dialog');
      b.innerHTML = '<span class="mm-ap-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="mm-ap-txt"><span class="mm-ap-t"></span><small class="mm-ap-s"></small></span><span class="mm-ap-go" aria-hidden="true">›</span>';
      const anchor = menu.querySelector('.mm-display');
      menu.insertBefore(b, anchor || null);
      b.addEventListener('click', (e) => { e.stopPropagation(); open(b); });
      relabel();
      paintMenuRow();
    }
    document.querySelectorAll('.palette-btn').forEach((pb) => {
      pb.setAttribute('aria-haspopup', 'dialog');
      pb.removeAttribute('aria-expanded');
      pb.setAttribute('aria-label', 'Appearance · रूप');
      pb.title = 'Appearance · रूप';
    });
  }
  function relabel() {
    const b = document.querySelector('#mobileMenu .mm-appear');
    if (!b) return;
    b.querySelector('.mm-ap-t').textContent = '🎨 ' + tx('menu');
    b.querySelector('.mm-ap-s').textContent = tx('menuSub');
  }
  document.addEventListener('click', (e) => {
    const pb = e.target.closest && e.target.closest('.palette-btn');
    if (pb) { e.preventDefault(); open(pb); }
  });
  /* language switches re-label the menu row (the sheet re-renders on every open) */
  try { new MutationObserver(relabel).observe(d, { attributes: true, attributeFilter: ['lang'] }); } catch (e) {}
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildEntry); else buildEntry();
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
