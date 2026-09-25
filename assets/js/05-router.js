
/* ================================================================
   [JS] 5. ROUTER — swaps the .view sections based on the URL hash.
   #/  #/results  #/seats  #/checkout  #/ticket/<ID>  #/my  #/terms  #/admin
================================================================ */
const Flow = {
  tripType: 'one',              // 'one' | 'round'
  from: '', to: '', date: '', retDate: '',
  legIndex: 0,                  // 0 = outbound, 1 = return
  legs: [],                     // finished legs: {routeId,date,seats,boarding,drop,fare,sid}
  route: null, seats: [],       // the leg being picked right now
  scheduleId: 0,                // extra bus on the same date (Bus Calendar, 5 Sep 2026); 0 = the daily bus
  fareOverride: 0,              // this departure's own per-seat price (4 Sep 2026); 0 = the normal fare
  draftId: '', shotThumb: '',
  /* The compressed jpeg from the screenshot uploader, kept so the submit
     step uploads it directly instead of rebuilding it from shotThumb's
     base64 while the passenger waits. */
  shotBlob: null
};

/* §1.3/§10 Booking progress line — fills as the passenger advances through the
   booking flow (Results 40 → Seats 60 → Checkout 80 → Ticket 100) and stays
   hidden on every other view. The bar element is created on first use so this
   is fully additive (no markup edit). On reaching the ticket it snaps to 100%,
   pulses once, then fades away. */
const BOOK_PROGRESS_PCT = { results: 40, seats: 60, checkout: 80, status: 100 };
function updateBookProgress(name) {
  const pct = BOOK_PROGRESS_PCT[name];
  let bar = document.getElementById('bookProgress');
  if (pct == null) { if (bar) bar.classList.remove('on', 'pulse'); return; }
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'bookProgress'; bar.className = 'book-progress'; bar.setAttribute('aria-hidden', 'true');
    bar.innerHTML = '<i></i>';
    document.body.appendChild(bar);
  }
  const fill = bar.firstElementChild;
  bar.classList.add('on');
  requestAnimationFrame(function () { if (fill) fill.style.transform = 'scaleX(' + (pct / 100) + ')'; });
  if (pct >= 100) {
    bar.classList.add('pulse');
    setTimeout(function () { bar.classList.remove('on', 'pulse'); }, 1500);
  } else {
    bar.classList.remove('pulse');
  }
}
function showView(name) {
  if (name !== 'track' && typeof cleanupTracker === 'function') cleanupTracker();
  if (name !== 'nav' && typeof cleanupNav === 'function') cleanupNav();
  if (name !== 'trip' && typeof cleanupTrip === 'function') cleanupTrip();
  $$('.view').forEach(v => v.classList.toggle('active', v.id === 'view-' + name));
  /* JS-set flow marker. The CSS that hides the bottom tab-bar + FABs
     during seat/checkout also has :has() selectors, but :has() needs
     Chrome 105+/Safari 15.4+ — a big slice of budget Androids here run
     older WebViews where those rules silently do nothing and the tab-bar
     would sit over the sticky Continue bar. A plain body class works on
     every browser that can run this app at all. */
  /* RESULTS IS PART OF THE FLOW TOO (30 Aug, 4th report of "the next
     button doesn't work"). Measured at 412x915: the moment results
     render, the page is at scrollTop 0 and the card's CTA lands at
     y=865-909 — but the fixed tab-bar starts at y=832, so
     elementFromPoint over the button returned .bn-item and the tap
     activated "बुक" instead. Every OTHER scroll offset was fine, which
     is why it kept surviving our tests: the one broken position is the
     one the user always lands on. Giving results the same treatment as
     seats/checkout hands those bottom pixels back to the content (and
     also drops the FABs that were sitting over the card). */
  document.body.classList.toggle('in-flow', name === 'results' || name === 'seats' || name === 'checkout');
  /* 11 Sep 2026 perf pass: a view switch is new content, not a move within
     a page. The smooth scroll from deep in the home page to the top ran
     ~300ms BEFORE the next view's fade could be seen, which read as a slow
     page transition on phones. Jump, then animate the new view in. */
  window.scrollTo(0, 0);
  observeReveals();
  updateBookProgress(name);
  /* 17 Sep 2026: the "bus on the road" strip (18-journey.js) — shown on
     results/seats, detached everywhere else. Optional file, so guarded. */
  if (window.SHG_JOURNEY && typeof window.SHG_JOURNEY.show === 'function') { try { window.SHG_JOURNEY.show(name); } catch (e) {} }
}
function router() {
  captureReferralFromURL();   // a #/?ref=CODE link can arrive mid-session too
  const h = location.hash || '#/';
  $('#mobileMenu').classList.remove('open');
  $('#hamburger').classList.remove('open');
  /* The pending-ticket poll (TicketPoll, below) lives only on its own view. */
  if (typeof TicketPoll !== 'undefined' && TicketPoll._id && h.indexOf('#/ticket/') !== 0) TicketPoll.stop();
  /* Both ticket views re-read the database before trusting what this browser
     remembers, so an approval, rejection or cancellation made by staff shows
     up on the passenger's next look instead of never. Fire-and-forget: the
     cached copy paints immediately and is corrected only if it was wrong. */
  if (h.indexOf('#/ticket/') === 0) {
    const tid = h.split('/')[2] || '';
    showView('status'); renderStatus(tid);
    syncBookings([tid]).then((changed) => {
      if (changed && location.hash === '#/ticket/' + tid) renderStatus(tid);
    });
  }
  else if (h.indexOf('#/trip/') === 0) {
    const pid = h.split('/')[2] || '';
    /* The Trip Companion is a ticket action (boarding hero, live-GPS rail,
       checklist), so it is gated on 'confirmed' exactly like the download
       buttons. Hiding the anchors is not enough: the PNR is printed on the
       pending band and embedded in shared ticket text, so this URL is
       guessable and has to be checked here too. Unknown PNRs are allowed
       through optimistically and corrected by the sync below, matching the
       ticket view's behaviour. */
    const tb = DB.bookings.find(x => x.id === pid);
    if (tb && tb.status !== 'confirmed') { location.hash = '#/ticket/' + pid; return; }
    showView('trip'); renderTrip(pid);
    syncBookings([pid]).then((changed) => {
      if (!changed || location.hash !== '#/trip/' + pid) return;
      const nb = DB.bookings.find(x => x.id === pid);
      if (nb && nb.status !== 'confirmed') location.hash = '#/ticket/' + pid;
      else renderTrip(pid);
    });
  }
  else if (h === '#/results')  { if (!Flow.date)  { location.hash = '#/'; return; } showView('results');  renderResults(); }
  else if (h === '#/seats')    { if (!Flow.route) { location.hash = '#/'; return; } showView('seats');    renderSeats(); }
  else if (h === '#/checkout') { if (!Flow.legs.length) { location.hash = '#/'; return; } showView('checkout'); renderCheckout(); }
  else if (h === '#/my')       {
    showView('my'); renderMyBookings();
    syncBookings().then((changed) => { if (changed && location.hash === '#/my') renderMyBookings(); });
  }
  else if (h === '#/admin')    { window.open('/admin/', '_blank'); location.hash = '#/'; return; }
  else if (h === '#/nav')    { showView('nav'); renderNav(); }
  else if (h === '#/track' || h.indexOf('#/track/') === 0 || h === '#/nav3d' || h === '#/analog') { location.hash = '#/nav'; return; }
  else if (h === '#/terms')    { showView('terms'); renderTerms(); }
  else if (h === '#/shg-ctrl') { window.open('/admin/', '_blank'); location.hash = '#/'; return; }
  else                         { showView('home'); try { renderNextTrip(); } catch (e) {} }
  updateBottomNav(h);
}
window.addEventListener('hashchange', router);

/* Smooth-scroll links that point at home-page sections */
document.addEventListener('click', (e) => {
  const a = e.target.closest('[data-scroll],[data-scroll-home]');
  if (!a) return;
  e.preventDefault();
  const target = a.getAttribute('data-scroll') || a.getAttribute('data-scroll-home');
  const go = () => {
    if (target === 'top') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    const el = document.getElementById(target);
    if (!el) return;
    // If the target sits inside the collapsed "More" brochure, open it first
    // or the scroll lands on a hidden element and appears to do nothing.
    var more = document.getElementById('homeMore');
    var extra = document.getElementById('homeExtra');
    if (more && more.hidden && (more.contains(el) || (extra && extra.contains(el))) && typeof window.openHomeMore === 'function') {
      window.openHomeMore();
      setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80);
      return;
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  if (!$('#view-home').classList.contains('active')) { location.hash = '#/'; setTimeout(go, 60); }
  else go();
});

/* Bottom nav (mobile) — active state + actions */
/* Which bottom-nav tab is the passenger actually in?

   This used to light "Home" for every screen except My Trips and the
   navigator — so through the entire booking flow (results → seats →
   checkout) the bar claimed you were on the home page, and the "Book"
   and "Help" tabs could never light at all. The bar is the only
   orientation a phone user has once the header scrolls away, so it has
   to tell the truth about where they are. */
function updateBottomNav(h) {
  h = h || '#/';
  const is = (...hashes) => hashes.some(x => h === x);
  const starts = (p) => h.indexOf(p) === 0;

  const map = {
    home:  is('#/', '', '#'),
    // The whole booking funnel is "Book".
    book:  is('#/results', '#/seats', '#/checkout'),
    // A finished ticket belongs with the passenger's trips.
    trips: is('#/my') || starts('#/ticket/'),
    // Live tracking of a specific trip is still the navigator.
    nav:   is('#/nav') || starts('#/trip/'),
    help:  is('#/terms'),
  };

  $$('.bn-item').forEach(b => {
    const k = b.getAttribute('data-bn');
    b.classList.toggle('on', !!map[k]);
  });
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('.bn-item'); if (!b) return;
  e.preventDefault();
  const k = b.getAttribute('data-bn');
  if (k === 'trips') { location.hash = '#/my'; return; }
  if (k === 'nav') { location.hash = '#/nav'; return; }
  const target = k === 'book' ? 'search-anchor' : (k === 'help' ? 'contact' : 'top');
  if (!$('#view-home').classList.contains('active')) {
    location.hash = '#/';
    setTimeout(() => { const el = document.getElementById(target); if (target === 'top') window.scrollTo({ top: 0 }); else if (el) el.scrollIntoView({ behavior: 'smooth' }); }, 60);
  } else {
    if (target === 'top') window.scrollTo({ top: 0, behavior: 'smooth' });
    else { const el = document.getElementById(target); if (el) el.scrollIntoView({ behavior: 'smooth' }); }
  }
});

/* Map controls ⚙️ toggle — collapses the right button stack so the
   default map view is clean: just bus position, locate, and ETA. */
(function() {
  var moreBtn = document.getElementById('snMore');
  var morePanel = document.getElementById('snRstackMore');
  if (moreBtn && morePanel) {
    moreBtn.addEventListener('click', function() {
      var show = morePanel.hidden;
      morePanel.hidden = !show;
      moreBtn.classList.toggle('open', show);
    });
  }
})();

/* Android back-button — step back through checkout sub-steps AND close
   any open modal before leaving the view entirely (hash-based routing
   handles the rest). Modal-close is programmatic: closeModal walks its
   own history entry, flagging window._shgModalClosingBack so this
   handler skips its own re-close and doesn't recurse. */
(function() {
  var pushed = false;
  window._shgCoStepPush = function() {
    if (!pushed) { history.pushState({ shgCo: 2 }, '', '#/checkout'); pushed = true; }
  };
  window.addEventListener('popstate', function(e) {
    /* Programmatic modal close is walking its own pushed entry — skip. */
    if (window._shgModalClosingBack) { window._shgModalClosingBack = false; return; }
    /* Modal open at the moment of a real user back-tap? Dismiss the
       sheet instead of navigating away. closeModal(true) removes .open
       without re-walking history (the browser has already popped). */
    var m = document.getElementById('shgModal');
    if (m && m.classList.contains('open') && typeof closeModal === 'function') {
      closeModal(true);
      return;
    }
    if (e.state && e.state.shgCo === 2) { pushed = false; return; }
    if (location.hash === '#/checkout' && typeof Flow !== 'undefined' && Flow.coStep === 2) {
      if (typeof coGoStep === 'function') coGoStep(1);
      pushed = false;
    }
  });
})();

/* "Where am I?" — Help section lightweight location lookup (see getLightLocation()
   in the GEO HELPERS block). Single-shot, low-power — does not start any
   continuous GPS watch, unlike the live navigator. */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('#whereAmIBtn'); if (!btn) return;
  const out = document.getElementById('whereAmIText');
  describeWhereAmI((u) => { if (out) out.textContent = u.text; });
});

/* ================================================================
   [JS] 6. HOME PAGE BEHAVIOUR
================================================================ */
/* Scroll-reveal */
const io = new IntersectionObserver((es) => {
  es.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
}, { threshold: 0.12 });
function observeReveals() { $$('.reveal:not(.in)').forEach(el => io.observe(el)); }

/* Number counters in the route-story facts */
const cio = new IntersectionObserver((es) => {
  es.forEach(en => {
    if (!en.isIntersecting) return;
    cio.unobserve(en.target);
    const el = en.target, end = parseInt(el.getAttribute('data-count'), 10) || 0;
    const t0 = performance.now(), dur = 1400;
    (function tick(t) {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString('en-IN');
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  });
}, { threshold: 0.5 });

/* Hero parallax — a continuous rAF loop tuned for high-refresh (120/144/165Hz)
   displays. Instead of snapping the transform once per mouse event, it eases
   every element toward its target every single frame, so a 165Hz panel draws
   165 smooth in-between steps a second. The easing is frame-rate INDEPENDENT
   (time-delta exponential smoothing), so the motion feels identical at 60Hz
   and 165Hz — only smoother on the faster screen. Everything is written as a
   translate3d so it stays on the GPU/compositor and never touches layout.
   The loop pauses itself when the hero scrolls away or the tab is hidden, and
   drifts gently on its own when the pointer is idle. */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (!reduceMotion) {
  const hero = $('.hero');
  if (hero) {
    const parallaxEls = $$('[data-parallax]', hero).map(el => {
      // The JS loop now owns the motion, so drop the CSS transition that would
      // otherwise fight it and add ~0.6s of lag on every frame.
      el.style.transition = 'none';
      el.style.willChange = 'transform';
      return { el, f: parseFloat(el.getAttribute('data-parallax')) || 0 };
    });

    const tgt = { x: 0, y: 0 };   // where the pointer wants things (-0.5..0.5)
    const cur = { x: 0, y: 0 };   // where they currently are (eased)
    let lastPointer = -1e9;       // timestamp of the last real pointer move
    let last = performance.now();
    let rafId = null;

    const clamp = (v) => v < -0.5 ? -0.5 : (v > 0.5 ? 0.5 : v);

    hero.addEventListener('pointermove', (e) => {
      const r = hero.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) return;
      tgt.x = clamp((e.clientX - r.left) / r.width - 0.5);
      tgt.y = clamp((e.clientY - r.top) / r.height - 0.5);
      lastPointer = performance.now();
    }, { passive: true });

    function frame(now) {
      const dt = Math.min(64, now - last) / 1000;   // seconds, capped after a stall
      last = now;

      // No pointer for a beat -> breathe on a slow Lissajous path so the hero
      // stays subtly alive (and shows off the smoothness) without a mouse.
      if (now - lastPointer > 2200) {
        tgt.x = Math.sin(now * 0.00022) * 0.16;
        tgt.y = Math.cos(now * 0.00017) * 0.12;
      }

      // Exponential smoothing with a fixed time constant (tau ~ 0.14s).
      const a = 1 - Math.exp(-dt / 0.14);
      cur.x += (tgt.x - cur.x) * a;
      cur.y += (tgt.y - cur.y) * a;

      for (let i = 0; i < parallaxEls.length; i++) {
        const p = parallaxEls[i];
        p.el.style.transform = 'translate3d(' + (cur.x * p.f).toFixed(2) + 'px,' + (cur.y * p.f).toFixed(2) + 'px,0)';
      }
      rafId = requestAnimationFrame(frame);
    }

    const start = () => { if (rafId === null && document.visibilityState !== 'hidden') { last = performance.now(); rafId = requestAnimationFrame(frame); } };
    const stop  = () => { if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; } };

    // Only animate while the hero is actually on screen and the tab is visible.
    let heroVisible = true;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((entries) => {
        heroVisible = entries[0].isIntersecting;
        heroVisible ? start() : stop();
        parallaxEls.forEach(p => { p.el.style.willChange = heroVisible ? 'transform' : 'auto'; });
      }, { threshold: 0 }).observe(hero);
    }
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') stop();
      else if (heroVisible) start();
    });

    start();
  }
}

/* Navbar shadow — throttled via requestAnimationFrame. */
let scrollTick = false;
window.addEventListener('scroll', () => {
  if (scrollTick) return;
  scrollTick = true;
  requestAnimationFrame(() => {
    scrollTick = false;
    $('#nav').classList.toggle('scrolled', window.scrollY > 8);
  });
}, { passive: true });
$('#hamburger').addEventListener('click', () => {
  const open = $('#mobileMenu').classList.toggle('open');
  $('#hamburger').classList.toggle('open', open);
  $('#hamburger').setAttribute('aria-expanded', open ? 'true' : 'false');
});

/* Ripple on primary buttons (CSS does the animation) */
document.addEventListener('pointerdown', (e) => {
  const b = e.target.closest('.btn'); if (!b || b.disabled) return;
  const r = b.getBoundingClientRect();
  const rip = document.createElement('span');
  rip.className = 'ripple';
  const d = Math.max(r.width, r.height) * 2;
  rip.style.width = rip.style.height = d + 'px';
  rip.style.left = (e.clientX - r.left - d / 2) + 'px';
  rip.style.top = (e.clientY - r.top - d / 2) + 'px';
  b.appendChild(rip);
  setTimeout(() => rip.remove(), 650);
});

/* Seasonal notice banner (text managed from Admin → Settings) */
function renderNotice() {
  const bar = $('#noticeBar'); if (!bar) return;
  const st = S();
  let hidden = false;
  try { hidden = sessionStorage.getItem('shg:noticeHide') === '1'; } catch (e) {}
  if (!st.noticeOn || !String(st.noticeText || '').trim() || hidden) { bar.classList.add('hide'); return; }
  bar.classList.remove('hide');
  $('#noticeText').textContent = st.noticeText;
}
document.addEventListener('click', (e) => {
  if (!e.target.closest('#noticeClose')) return;
  try { sessionStorage.setItem('shg:noticeHide', '1'); } catch (e2) {}
  $('#noticeBar').classList.add('hide');
});

/* FAQ accordion */
$('#faqList').addEventListener('click', (e) => {
  const q = e.target.closest('.faq-q'); if (!q) return;
  const item = q.parentElement, wasOpen = item.classList.contains('open');
  $$('#faqList .faq-item.open').forEach(i => i.classList.remove('open'));
  if (!wasOpen) item.classList.add('open');
});

/* Contact form → saved to admin Messages inbox */
$('#contactForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const name = $('#cfName'), phone = $('#cfPhone'), msg = $('#cfMsg');
  let ok = true;
  [[name, name.value.trim().length >= 2], [phone, /^\d{10}$/.test(phone.value.trim())], [msg, msg.value.trim().length >= 5]]
    .forEach(pair => { pair[0].closest('.field').classList.toggle('invalid', !pair[1]); if (!pair[1]) ok = false; });
  if (!ok) return;
  DB.messages.unshift({ name: name.value.trim(), phone: phone.value.trim(), topic: $('#cfTopic').value, msg: msg.value.trim(), at: Date.now() });
  persist('messages');
  e.target.reset();
  toast(t('tMsgSent'));
});

/* Search box — one-way / round-trip */
function populateCitySelects() {
  const froms = [], tos = [];
  DB.routes.filter(r => r.active).forEach(r => {
    if (froms.indexOf(r.from) < 0) froms.push(r.from);
    if (tos.indexOf(r.to) < 0) tos.push(r.to);
  });
  const fill = (sel, list, want) => {
    sel.innerHTML = list.map(c => '<option' + (c === want ? ' selected' : '') + '>' + esc(c) + '</option>').join('');
  };
  var mp = CONFIG.mainPoints || {};
  var defFrom = ((mp.india || [])[0]) || '';
  var defTo   = ((mp.nepal || [])[0]) || '';
  fill($('#fromSel'), froms.length ? froms : (defFrom ? [defFrom] : []), Flow.from || defFrom);
  fill($('#toSel'), tos.length ? tos : (defTo ? [defTo] : []), Flow.to || defTo);
}
$('#swapBtn').addEventListener('click', () => {
  const f = $('#fromSel'), tsel = $('#toSel'), fv = f.value, tv = tsel.value;
  const setVal = (sel, v) => { if ($$('option', sel).some(o => o.value === v)) sel.value = v; };
  setVal(f, tv); setVal(tsel, fv);
  renderFareLive();
});

/* ================================================================
   SIMPLE DIRECTION + TOWN SEARCH (redesign 2026-08-25)

   This coach runs one road: Gujarat ⇄ Rupaidiha. The passenger picks a
   DIRECTION (जाने / फर्किने) and ONE Gujarat town; there is no free-form
   city pair to get wrong. This block owns the visible controls (#dirGo,
   #dirBack, #pointSel) and keeps the hidden #fromSel / #toSel in sync —
   those two selects stay the single source of truth every downstream
   screen (results, fare, checkout) already reads, so nothing else changes.

   The town list is built from the LIVE routes, split by direction, so
   every option always returns at least one bus (results match on an exact
   from/to pair) and new origins added in Admin → Routes appear on their own.
================================================================ */
const SHG_NEP_HUB = 'Rupaidiha';    // fixed Nepal-side endpoint of every trip
let searchDir = 'go';               // 'go' = to Nepal (जाने), 'back' = to Gujarat (फर्किने)

/* The bookable Gujarat towns for a direction.

   These are the five PERMANENT stops of the daily run, in the order the
   coach reaches them (CONFIG.mainPoints.india) — Surat, Baroda,
   Emli Bhupal, S Hari Parking (Nana Chiloda) and Mehsana (Silver Complex).

   It used to list only the routes' ENDPOINT cities, because search matched
   an exact from/to city pair and anything else returned no buses. Search
   now matches a town against the route's real STOPS, so a passenger can
   pick the point they actually board at and still get the same one bus.
   The endpoint cities remain the fallback if the canonical list is ever
   empty, so the picker can never render blank. */
/* Mirror of Boarding::townKey() on the server: strip "[lat,lng]", strip
   "@ 23:00", keep the leading town before the first dash/·/comma, then
   drop punctuation. Lets a canonical name be compared with a decorated
   stop line ("Mehsana @ 23:00 [...]"). */
function townKeyJS(label) {
  let s = String(label || '').replace(/\[[^\]]*\]/g, ' ').replace(/@.*$/, ' ');
  s = s.split(/[—–\-·|,(]/)[0] || s;
  return s.replace(/[^\p{L}\p{N}]+/gu, '').toLowerCase();
}

/* Stop names the SERVER says each direction really calls at, loaded once
   from /api/timetable.php (the same route_stops rows the booking cut-off
   uses). The local catalogue only carries the seeded boarding lines, so
   without this the picker could not see stops added by a migration until
   a search had been run. Fire-and-forget: the picker paints immediately
   from local data and re-paints if the server knows more. */
const SrvStops = { go: null, back: null, _tried: false };
/* One /api/timetable.php request per minute, shared by every caller (the
   stops picker, the home timetable box, the trip map): the same public
   payload was being fetched up to 5× on one page load (5 Sep 2026). */
const _ttMemo = Object.create(null);
function shgTimetableGet(date) {
  const k = String(date || '');
  const hit = _ttMemo[k];
  if (hit && (Date.now() - hit.at) < 60000) return hit.p;
  const p = shgApi.get('/timetable.php' + (k ? '?date=' + encodeURIComponent(k) : ''))
    .catch(function (e) { delete _ttMemo[k]; throw e; });
  _ttMemo[k] = { p: p, at: Date.now() };
  return p;
}
function loadSrvStops() {
  if (SrvStops._tried) return;
  SrvStops._tried = true;
  shgTimetableGet('').then(function (d) {
    const data = (d && d.routes) ? d : (d && d.data) || {};
    const go = Object.create(null), back = Object.create(null);
    (data.routes || []).forEach(function (r) {
      const outbound = isNepalPoint(r.to) && !isNepalPoint(r.from);
      const inbound  = isNepalPoint(r.from) && !isNepalPoint(r.to);
      if (outbound) (r.boarding || []).forEach(function (b) { go[townKeyJS(b.name)] = true; });
      if (inbound)  (r.drop     || []).forEach(function (b) { back[townKeyJS(b.name)] = true; });
    });
    SrvStops.go = go; SrvStops.back = back;
    if (typeof populatePointSel === 'function') populatePointSel();
  }).catch(function () { /* offline — local catalogue still drives the picker */ });
}

/* Every town the live routes actually call at, for a direction. */
function servedTownKeys(dir) {
  /* When the server has told us its stops, that answer is FINAL — mixing in
     the local catalogue would resurrect towns the routes no longer call at.
     The seeded catalogue still lists a Surat pickup, so merging the two
     offered "Surat" on a server whose route_stops has no Surat, and the
     board came back empty for the only option in the picker. */
  const srv = dir === 'go' ? SrvStops.go : SrvStops.back;
  if (srv) return srv;

  const keys = Object.create(null);
  (DB.routes || []).forEach(r => {
    if (!r || !r.active) return;
    const outbound = isNepalPoint(r.to) && !isNepalPoint(r.from);
    const inbound  = isNepalPoint(r.from) && !isNepalPoint(r.to);
    if (dir === 'go' ? !outbound : !inbound) return;
    keys[townKeyJS(dir === 'go' ? r.from : r.to)] = true;
    ((dir === 'go' ? r.boarding : r.drop) || []).forEach(line => { keys[townKeyJS(line)] = true; });
  });
  return keys;
}

function gujaratTownsFor(dir) {
  /* The five canonical stops ARE the daily run — prefer the subset a live
     route confirms (self-healing while data catches up), but when the
     intersection is empty (stale KV, timetable not loaded yet) offer the
     FULL canonical list rather than the routes' endpoint cities: the one
     daily bus always calls at all five, so canonical is never a lie, while
     an endpoint-only list would hide four real pickups. */
  const canonical = ((CONFIG.mainPoints || {}).india || []).filter(Boolean);
  if (canonical.length) {
    const served = servedTownKeys(dir);
    const usable = canonical.filter(c => served[townKeyJS(c)]);
    return usable.length ? usable : canonical;
  }

  // mainPoints misconfigured/empty — fall back to the routes' endpoint cities
  const out = [];
  (DB.routes || []).forEach(r => {
    if (!r || !r.active) return;
    if (dir === 'go') {
      // buses heading INTO Nepal — their Gujarat origin is a boarding town
      if (isNepalPoint(r.to) && !isNepalPoint(r.from) && out.indexOf(r.from) < 0) out.push(r.from);
    } else {
      // buses coming BACK to Gujarat — their Gujarat destination is a drop town
      if (isNepalPoint(r.from) && !isNepalPoint(r.to) && out.indexOf(r.to) < 0) out.push(r.to);
    }
  });
  if (out.length) return out;
  var mp = CONFIG.mainPoints || {};
  return (dir === 'go' ? (mp.india || []) : (mp.india || [])).slice(0, 1);
}

/* Pickup-time lookup: maps a townKey to its departure time (24h string)
   from the route boarding/drop arrays. Shared by the fare board, the
   town picker and any future schedule display. Built once, rebuilt when
   DB.routes changes. This IS the single source of truth for display times. */
var _pickupTimes = {};
function rebuildPickupTimes() {
  _pickupTimes = {};
  (DB.routes || []).forEach(function (r) {
    (r.boarding || []).concat(r.drop || []).forEach(function (raw) {
      var bp = parseBP(raw);
      if (bp.time) _pickupTimes[townKeyJS(bp.name)] = bp.time;
    });
  });
}
function pickupTimeFor(city) {
  return _pickupTimes[townKeyJS(city)] || '';
}
/* Format a 24h time as 12h AM/PM for customer-facing display. */
function fmt12h(t24) {
  if (!t24) return '';
  var parts = t24.split(':'), h = parseInt(parts[0], 10), m = parts[1] || '00';
  var ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return h + ':' + m + ' ' + ampm;
}

/* Fill the single town picker for the current direction, keeping the
   passenger's choice if it is still valid.
   Shows "Surat — 1:00 PM" so the passenger knows when the bus departs. */
function populatePointSel() {
  const sel = $('#pointSel'); if (!sel) return;
  const towns = gujaratTownsFor(searchDir);
  const keep  = towns.indexOf(sel.value) >= 0 ? sel.value : towns[0];
  sel.innerHTML = towns.map(function (c) {
    var tm = pickupTimeFor(c);
    var label = tm ? c + ' — ' + fmt12h(tm) : c;
    return '<option value="' + esc(c) + '"' + (c === keep ? ' selected' : '') + '>' + esc(label) + '</option>';
  }).join('');
  applyDirection();
}

/* Mirror (direction + town) onto the hidden #fromSel / #toSel that the rest
   of the app reads, then refresh the live summary and the label. */
function applyDirection() {
  const townSel = $('#pointSel');
  const town = (townSel && townSel.value) || (((CONFIG.mainPoints || {}).india || [])[0] || '');
  const from = $('#fromSel'), to = $('#toSel');
  const setOpt = (sel, v) => {
    if (!sel) return;
    if (!$$('option', sel).some(o => o.value === v)) sel.insertAdjacentHTML('beforeend', '<option>' + esc(v) + '</option>');
    sel.value = v;
  };
  if (searchDir === 'go') { setOpt(from, town); setOpt(to, SHG_NEP_HUB); }
  else                    { setOpt(from, SHG_NEP_HUB); setOpt(to, town); }
  const lbl = $('#pointLabel');
  if (lbl) lbl.textContent = searchDir === 'go' ? t('sBoard') : t('sDrop');
  renderFareLive();
  if (typeof renderHomeRouteMap === 'function') { try { renderHomeRouteMap(); } catch (e) {} }
}

/* Switch direction and re-fill the town list for it. */
function setSearchDir(dir) {
  searchDir = (dir === 'back') ? 'back' : 'go';
  const g = $('#dirGo'), b = $('#dirBack');
  if (g) g.classList.toggle('on', searchDir === 'go');
  if (b) b.classList.toggle('on', searchDir === 'back');
  populatePointSel();
}

/* One-time wiring, called from the boot sequence after routes are loaded. */
function initSimpleSearch() {
  loadSrvStops();   // ask the server which stops the run really has
  const g = $('#dirGo'), b = $('#dirBack'), p = $('#pointSel');
  if (g && !g._wired) { g._wired = true; g.addEventListener('click', () => setSearchDir('go')); }
  if (b && !b._wired) { b._wired = true; b.addEventListener('click', () => setSearchDir('back')); }
  if (p && !p._wired) { p._wired = true; p.addEventListener('change', applyDirection); }
  setSearchDir(searchDir);   // first paint: fill towns + sync hidden selects
}

/* ================================================================
   MAIN-POINT FARES — "kati parcha?" answered on the home page.

   Every price the passenger sees here comes from sharingDir(), the same
   pair of numbers checkout charges on, so the board can never quote one
   fare and the ticket print another. Both renderers are pure string work
   over a four-item list — no network, no layout thrash — so they are
   safe to call on every dropdown change.
================================================================ */
/* ================================================================
   [JS] CONTACT NUMBERS STRIP
   Up to 8 booking lines across the top of the home page, each one shown or
   hidden from Admin -> Settings. The list lives in the shared settings blob,
   so every device sees the same numbers the moment the owner saves - no
   deploy, and nothing hardcoded about which ones are hidden today.
================================================================ */
const NUM_SLOTS = 8;

/* The strip's default is the two numbers already printed in the markup, so a
   site that has never opened Settings looks exactly as it did. Slots 3-8
   start empty and hidden, ready for the owner to fill in. */
function defaultContactNumbers() {
  /* The eight booking lines from the owner's official poster (Canva template,
     Aug 2026): four branch-office numbers, the CEO/head-office WhatsApp, 24x7
     support, and two counter agents. The operator can re-label, hide or
     replace any of them in Admin -> Settings; this is only the default a
     fresh site shows. */
  const list = [
    { label: 'Mehsana Office',   num: '+91 91048 01507', wa: false, show: true },
    { label: 'Ahmedabad Office', num: '+91 91570 01507', wa: false, show: true },
    { label: 'Baroda Office',    num: '+91 87358 81507', wa: false, show: true },
    { label: 'Surat Office',     num: '+91 73593 01507', wa: false, show: true },
    { label: 'CEO · Director',   num: '+91 97264 01507', wa: true,  show: true },
    { label: '24×7 Support',     num: '+91 91734 01507', wa: true,  show: true },
    { label: 'Lokesh Sunar',     num: '+91 98662 01375', wa: false, show: true },
    { label: 'Mahendra Singh',   num: '+91 98481 19600', wa: false, show: true }
  ];
  while (list.length < NUM_SLOTS) list.push({ label: '', num: '', wa: false, show: false });
  return list;
}

function contactNumbers() {
  const saved = S().contactNumbers;
  if (!Array.isArray(saved) || !saved.length) return defaultContactNumbers();
  const out = saved.slice(0, NUM_SLOTS).map(n => ({
    label: String((n && n.label) || ''),
    num:   String((n && n.num) || ''),
    wa:    !!(n && n.wa),
    show:  !!(n && n.show)
  }));
  while (out.length < NUM_SLOTS) out.push({ label: '', num: '', wa: false, show: false });
  return out;
}

/* The collapsible company/route brochure on the home page (minimal home).
   openHomeMore() is also called by the scroll handler when a menu or footer
   link points at a section inside it. */
window.openHomeMore = function () {
  var more = document.getElementById('homeMore');
  var extra = document.getElementById('homeExtra');
  var btn = document.getElementById('homeMoreToggle');
  if (!more) return;
  more.hidden = false;
  if (extra) extra.hidden = false;
  if (btn) { btn.setAttribute('aria-expanded', 'true'); btn.classList.add('open'); }
};
function wireHomeMore() {
  var btn = document.getElementById('homeMoreToggle');
  var more = document.getElementById('homeMore');
  if (!btn || !more || btn._wired) return;
  btn._wired = true;
  btn.addEventListener('click', function () {
    if (more.hidden) {
      window.openHomeMore();
    } else {
      more.hidden = true;
      var extra = document.getElementById('homeExtra');
      if (extra) extra.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('open');
    }
  });
}

function renderContactStrip() {
  const strip = $('#numStrip'), inner = $('#numInner');
  if (!strip || !inner) return;
  /* A number with no digits cannot be dialled, so it stays hidden whatever
     the checkbox says - otherwise ticking Show on an empty slot publishes a
     dead tel: link. */
  const live = contactNumbers().filter(n => n.show && digits(n.num).length >= 7);
  if (!live.length) { strip.hidden = true; inner.innerHTML = ''; return; }

  inner.innerHTML = live.map(n => {
    const d = digits(n.num);
    const href = n.wa ? 'https://wa.me/' + esc(d) : 'tel:+' + esc(d);
    const ext  = n.wa ? ' target="_blank" rel="noopener noreferrer"' : '';
    return '<a class="num-pill' + (n.wa ? ' wa' : '') + '" href="' + href + '"' + ext + '>'
      + '<span class="np-ic">' + (n.wa ? '💬' : '📞') + '</span>'
      + (n.label ? '<span class="np-lbl">' + esc(n.label) + '</span>' : '')
      + '<span class="np-num">' + esc(n.num) + '</span></a>';
  }).join('');
  strip.hidden = false;
}

/* ⚡ QUICK TICKET SERVICE (6 Sep 2026) — the home banner. A passenger types
   name + mobile and the request goes to the office WhatsApp with the trip
   they have set in the search card; the desk then issues the ticket from
   /admin/quick-ticket.php (name + mobile → auto bus / seat / fare → PNG on
   WhatsApp). For a signed-in SELLING staff member the same banner opens
   that desk directly, pre-filled. */
/* ================================================================
   BOOKING MODE (12 Sep 2026) — ⚡ QuickBot or 🪑 the seat map.
   Owner: customers and agents who do not want the quick option must have
   the regular booking right there. The regular search card has always
   been directly under the QuickBot card; it was just below the fold. The
   switch folds the card into one strip (body.bk-regular) so the search
   card is the first thing on screen, remembers the choice, and any
   "QuickBot" link on the page switches back. Also fills the ticket
   counter in the card's AI strip (SHG_BOOT.stats.tickets, counted by
   index.php) with a short count-up.
================================================================ */
function initBookMode() {
  var KEY = 'shg:bookMode';
  var $id = function (i) { return document.getElementById(i); };   // initQuickTicket's helper is local to it
  function apply(mode, scroll) {
    var regular = mode === 'regular';
    document.body.classList.toggle('bk-regular', regular);
    var col = $id('qtCollapsed'); if (col) col.hidden = !regular;
    try { localStorage.setItem(KEY, mode); } catch (e) {}
    if (!scroll) return;
    var target = document.getElementById(regular ? 'search-anchor' : 'quick-ticket');
    if (target) {
      var y = target.getBoundingClientRect().top + window.scrollY - 60;
      try { window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' }); } catch (e) { window.scrollTo(0, Math.max(0, y)); }
    }
    if (regular) {
      var sc = document.querySelector('#search-anchor .search-card');
      if (sc) { sc.classList.remove('bk-flash'); void sc.offsetWidth; sc.classList.add('bk-flash'); }
    }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-bkmode]');
    if (b) { e.preventDefault(); apply(b.getAttribute('data-bkmode'), true); return; }
    // Any "QuickBot" link (hero CTA, menu, quick-action card) unfolds the card.
    if (e.target.closest('[data-scroll="quick-ticket"]') && document.body.classList.contains('bk-regular')) apply('quick', false);
  });
  var saved = null; try { saved = localStorage.getItem(KEY); } catch (e) {}
  apply(saved === 'regular' ? 'regular' : 'quick', false);

  var st = (window.SHG_BOOT && window.SHG_BOOT.stats) || null;
  var wrap = $id('qtAiCountWrap'), el = $id('qtAiCount');
  if (st && wrap && el && st.tickets > 0) {
    wrap.hidden = false;
    var target = st.tickets, t0 = null, dur = 1200;
    var fmt = function (n) { try { return n.toLocaleString('en-IN'); } catch (e) { return String(n); } };
    /* rAF is frozen in a background tab / hidden pane, so the real number is
       also written on a plain timer - the animation is a bonus, never the
       only way the figure appears. */
    var done = false;
    var finish = function () { if (done) return; done = true; el.textContent = fmt(target); };
    setTimeout(finish, dur + 200);
    if (!window.requestAnimationFrame) { finish(); return; }
    var step = function (ts) {
      if (done) return;
      if (t0 === null) t0 = ts;
      var pr = Math.min(1, (ts - t0) / dur), ease = 1 - Math.pow(1 - pr, 3);
      el.textContent = fmt(Math.round(target * ease));
      if (pr < 1) requestAnimationFrame(step); else finish();
    };
    requestAnimationFrame(step);
  }
}

function initQuickTicket() {
  var form = document.getElementById('qtForm');
  if (!form || form._wired) return;
  form._wired = true;
  var $id = function (i) { return document.getElementById(i); };
  var staff = (window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell) ? window.SHG_BOOT.staff : null;

  /* A selling staff session: the card is a door to the desk, pre-filled
     (the desk runs the same QuickBot engine with the desk's own options). */
  if (staff) {
    var badge = $id('qtBadge');
    if (badge) { badge.textContent = t('qbDeskBadge'); badge.removeAttribute('data-i18n'); }
    var btn = $id('qtSend');
    if (btn) { btn.textContent = t('qbDeskOpen'); btn.removeAttribute('data-i18n'); }
    var cta = $id('heroQtCta');
    if (cta) { cta.textContent = t('qbDeskCta'); cta.removeAttribute('data-i18n'); cta.removeAttribute('data-scroll'); cta.href = '/admin/quick-ticket.php'; }
    form.classList.remove('qt-onetap');
    var lineS = $id('qtLine'); if (lineS) lineS.hidden = true;
    var lineW = $id('qtLineWrap'); if (lineW) lineW.hidden = true;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name  = String(($id('qtName') || {}).value || '').trim();
      var phone = digits(String(($id('qtPhone') || {}).value || ''));
      location.href = '/admin/quick-ticket.php?name=' + encodeURIComponent(name) + '&phone=' + encodeURIComponent(phone);
    });
    return;
  }

  /* 🤖 QUICKBOT TICKET — 10-SECOND BOOKING (owner brief, 7 Sep 2026).
     ONE smart line — "Ram 9876543210, 2 seats, Mehsana tomorrow" or just
     name + mobile — is read by the server (TicketBot::parse) and merged
     with the signed-in passenger's own verified history and the company
     defaults (S Hari Parking, 1 passenger, next bus); the card shows every
     detail with in-place editors and ONE button: Confirm & Issue Ticket.
     The sale is PINNED to the facts shown (expect{}), the bus / berth /
     fare are the server's on every call, an optional agent code rides
     along, a mistaken tap is undone free, and the WhatsApp goes with the
     ticket. History is session-bound: a typed number never reveals
     another phone's habits. */
  var qt = { name: '', phone: '', seats: 0, direction: '', boarding: '', gender: '', date: '', text: '', agentCode: '',
             plan: null, personal: null, parsed: null, busy: false, req: 0, timer: null, off: false, submitAfterPlan: false,
             prefer: [], ask: null, sameAsLast: null, missing: [] };
  var me = (window.SHG_BOOT && window.SHG_BOOT.user && window.SHG_BOOT.user.phone) ? window.SHG_BOOT.user : null;
  var meBox = $id('qtMe'), line = $id('qtLine'), pair = $id('qtPair');
  form.classList.add('qt-onetap');
  var understood = document.createElement('div');
  understood.className = 'qt-understood'; understood.id = 'qtUnderstood'; understood.hidden = true;
  if (pair && pair.parentNode) pair.parentNode.insertBefore(understood, pair.nextSibling);

  function err(msg) { var e = $id('qtFormErr'); if (!e) return; e.textContent = msg || ''; e.hidden = !msg; }
  function maxSeats() { var n = (window.CONFIG && CONFIG.booking && CONFIG.booking.maxSeats) || 6; return Math.max(1, Math.min(6, n)); }
  function codAllowed() {
    var s = window.SHG_BOOT && window.SHG_BOOT.settings;
    if (!s || !('allow_cod' in s)) return true;
    var v = s.allow_cod; return !(v === false || v === 0 || v === '0' || v === '');
  }
  function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }
  function stopValue(label) { return String(label || '').replace(/\s*\[[^\]]*\]\s*$/, '').replace(/\s*@.*$/, '').trim(); }
  function isoAdd(iso, n) {
    var p = String(iso || '').split('-'); var d = new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1);
    d.setDate(d.getDate() + n);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function isoDiff(a, b) {
    var pa = String(a).split('-'), pb = String(b).split('-');
    return Math.round((new Date(+pb[0], +pb[1] - 1, +pb[2]) - new Date(+pa[0], +pa[1] - 1, +pa[2])) / 86400000);
  }
  function fmtShort(iso) {
    var p = String(iso).split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2]);
    try { return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); } catch (e) { return iso; }
  }
  function whenLabel(iso, today) {
    if (!iso) return '';
    var n = isoDiff(today, iso);
    if (n === 0) return t('qtToday');
    if (n === 1) return t('qtTomorrow');
    return fmtShort(iso);
  }
  function valid(silent) {
    qt.name  = String(($id('qtName') || {}).value || '').trim();
    qt.phone = digits(String(($id('qtPhone') || {}).value || ''));
    // A line that was read carries the name / mobile the boxes did not get yet.
    if ((qt.name.length < 2 || qt.phone.length < 10) && qt.parsed) {
      if (qt.name.length < 2 && qt.parsed.name) { qt.name = qt.parsed.name; $id('qtName').value = qt.name; }
      if (qt.phone.length < 10 && qt.parsed.phone) { qt.phone = digits(qt.parsed.phone); $id('qtPhone').value = qt.phone; }
    }
    if (qt.name.length < 2 || qt.phone.length < 10 || qt.phone.length > 13) {
      if (!silent) {
        if (me) { showForm(); }
        err((line && line.value.trim() !== '') ? t('qtNeedLine') : t('qtErr'));
        (qt.name.length < 2 ? $id('qtName') : $id('qtPhone')).focus();
      }
      return false;
    }
    err('');
    return true;
  }
  function opts() {
    return { text: qt.text, seats: qt.seats, direction: qt.direction, boarding: qt.boarding, gender: qt.gender, date: qt.date,
             lang: (typeof LANG !== 'undefined' ? LANG : 'en'), prefer: qt.prefer,
             agentCode: qt.agentCode, name: String(($id('qtName') || {}).value || '').trim(), phone: digits(String(($id('qtPhone') || {}).value || '')) };
  }

  /* ---- identity: a signed-in passenger books as themselves ---------- */
  function showForm() { if (meBox) meBox.hidden = true; if (pair) pair.hidden = false; }
  function showIdentity() {
    if (!me || !meBox) return;
    var p = digits(me.phone || '');
    $id('qtName').value = me.name || ''; $id('qtPhone').value = p;
    meBox.innerHTML = '<span>👤 ' + esc(t('qtFor')) + ' <b>' + esc(me.name || p) + '</b> · ' + esc(p.replace(/^(\d{2})\d+(\d{3})$/, '$1•••••$2')) + '</span>'
      + '<a href="#" id="qtNotMe">' + esc(t('qtNotYou')) + '</a>';
    meBox.hidden = false; if (pair) pair.hidden = true;
    $id('qtNotMe').addEventListener('click', function (e) {
      e.preventDefault();
      /* Not this person: end the SERVER session too (otherwise the sale
         would still bind to the previous number), forget the local user,
         and re-plan without their history. */
      me = null; showForm();
      qt.prefer = []; qt.sameAsLast = null; qt.ask = null; qt.missing = [];   // the next person is not this passenger
      $id('qtName').value = ''; $id('qtPhone').value = ''; if (line) line.focus(); else $id('qtName').focus();
      try { if (typeof setUser === 'function') setUser(null); } catch (e1) {}
      shgApi.post('/otp.php', { action: 'logout' }).catch(function () {}).then(function () { qt.personal = null; qt.plan = null; plan(); });
    });
  }
  showIdentity();

  /* ---- the office switched self-service off: the old WhatsApp request -- */
  function whatsappFallback() {
    var dirGo = $id('dirGo');
    var going = !dirGo || dirGo.classList.contains('on');
    var date  = String(($id('dateInput') || {}).value || '');
    var town  = String(($id('pointSel') || {}).value || '');
    var msg = '⚡ Quick Ticket\n'
      + 'Name: ' + qt.name + '\n'
      + 'Mobile: ' + qt.phone + '\n'
      + (qt.text ? 'Request: ' + qt.text + '\n' : '')
      + 'Trip: ' + (going ? 'Gujarat → Rupaidiha' : 'Rupaidiha → Gujarat') + (date ? ' · ' + date : '') + '\n'
      + (town ? 'Boarding: ' + town + '\n' : '')
      + '— ' + t('qtWaTail');
    window.open('https://wa.me/' + quickTicketNumber() + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
    toast(t('qtSent'));
  }

  /* ---- what the line said ------------------------------------------- */
  function renderUnderstood(p) {
    if (!understood) return;
    if (!p || !p.found || !p.found.length) { understood.hidden = true; understood.textContent = ''; return; }
    var bits = [];
    if (p.name) bits.push('👤 ' + p.name);
    if (p.phone) bits.push('📱 ' + p.phone + (p.country === 'NP' ? ' (+977)' : ''));
    if (p.seats) bits.push('👥 ' + p.seats);
    if (p.boarding) bits.push('📍 ' + stopValue(p.boarding).split(/\s[—–-]\s|,|·/)[0].trim());
    if (p.date) bits.push('📅 ' + p.date);
    if (p.direction) bits.push(p.direction === 'toNepal' ? '🇮🇳→🇳🇵' : '🇳🇵→🇮🇳');
    if (p.gender) bits.push(p.gender === 'Female' ? '♀' : '♂');
    if (p.agentCode) bits.push('🎫 ' + p.agentCode);
    understood.textContent = tf('qtUnderstood', { what: bits.join(' · ') });
    understood.hidden = false;
  }

  /* ---- the plan --------------------------------------------------------- */
  function plan() {
    if (qt.off) return;
    var id = ++qt.req, card = $id('qtPlanCard');
    if (!card) return;
    card.hidden = false;
    /* 12 Sep 2026: the wait state reads as the AI at work (three bouncing
       dots, one small element each), and the first plan lands tile by tile
       (.qt-fresh, dropped after the entrance so chip taps do not replay it). */
    var first = !qt.plan;
    if (first) card.innerHTML = '<div class="qt-plan-wait qt-think"><span class="qt-think-dots"><i></i><i></i><i></i></span><span>🧠 ' + esc(t('qtChecking')) + '</span></div>';
    card.classList.add('qt-thinking');
    shgApi.post('/quick-ticket.php', Object.assign({ action: 'customer_plan' }, opts()))
      .then(function (d) {
        if (id !== qt.req) return;
        card.classList.remove('qt-thinking');
        if (first) { card.classList.add('qt-fresh'); setTimeout(function () { card.classList.remove('qt-fresh'); }, 700); }
        qt.plan = d; qt.personal = d.personal || null; qt.parsed = d.parsed || null;
        qt.ask = d.ask || null; qt.sameAsLast = d.sameAsLast || null; qt.missing = d.missing || [];
        /* What the line supplied becomes the card's state, so a chip tap
           afterwards re-plans on the same facts; explicit chips still win. */
        var f = d.fields || {}, pf = d.prefill || {};
        if (f.seats && f.seats.source === 'text') qt.seats = pf.seats || qt.seats;
        if (f.date && f.date.source === 'text') qt.date = pf.date || qt.date;
        if (f.boarding && f.boarding.source === 'text') qt.boarding = pf.boarding || qt.boarding;
        if (f.direction && f.direction.source === 'text') qt.direction = pf.direction || qt.direction;
        if (f.gender && f.gender.source === 'text') qt.gender = pf.gender || qt.gender;
        if (qt.parsed) {
          if (qt.parsed.name && (!me || pair && !pair.hidden)) $id('qtName').value = qt.parsed.name;
          if (qt.parsed.phone && !me) $id('qtPhone').value = qt.parsed.phone;
          if (qt.parsed.agentCode) qt.agentCode = qt.parsed.agentCode;
        }
        renderUnderstood(qt.parsed);
        renderPlan('');
        if (qt.submitAfterPlan) { qt.submitAfterPlan = false; confirmSale(); }
      })
      .catch(function (e) {
        if (id !== qt.req) return;
        card.classList.remove('qt-thinking');
        qt.submitAfterPlan = false;
        if (e && e.status === 503) {
          // Self-service off in Settings: back to the name + mobile request on WhatsApp.
          qt.off = true; card.hidden = true; form.classList.remove('qt-onetap'); showForm();
          return;
        }
        /* 422 = name / mobile refused; the server's generic line for it is English-only. */
        var why = (e && e.status !== 422 && e.message) || t('qtErr');
        if (qt.plan) renderPlan(why);      // keep the last good card, show the reason
        else card.innerHTML = '<div class="qt-plan-err">' + esc(why) + '</div>';
      });
  }
  function replan(ms) { clearTimeout(qt.timer); qt.timer = setTimeout(plan, ms == null ? 200 : ms); }

  function renderPlan(msg) {
    var p = qt.plan, card = $id('qtPlanCard');
    if (!p || !card) return;
    var f = p.fare || {}, cod = codAllowed();
    var today = p.today || isoAdd(new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0') + '-' + String(new Date().getDate()).padStart(2, '0'), 0);
    var stops = (p.stops || []).filter(function (s) { return s.ahead; });
    var chosen = stopValue(p.boarding);
    var when = whenLabel(p.date, today);
    var stopShort = p.boardingName + (p.boardingTime ? ' ' + p.boardingTime : '');

    // Why these defaults — the assistant explains itself in one line.
    var why;
    var a = (qt.personal && qt.personal.applied) || {};
    if (qt.personal && (a.boarding || a.seats || a.direction)) {
      var bits = [];
      if (a.boarding) bits.push(tf('qtUsualStop', { stop: p.boardingName }));
      if (a.seats) bits.push(tf('qtUsualParty', { n: p.seatCount }));
      if (a.direction) bits.push(t('qtUsualDir'));
      why = qt.personal.trips === 1 ? tf('qtUsual1', { what: bits.join(' · ') }) : tf('qtUsual', { n: qt.personal.trips, what: bits.join(' · ') });
    } else {
      why = t('qtDefaults');
    }

    /* ONE clarifying question (SHG AI BRAIN Phase 2, 10 Sep 2026): the fact the
       line did not settle, in the writer's language, answered with a tap. An
       answer is explicit input on the re-plan, so the question retires itself. */
    var askHtml = '';
    if (qt.ask && qt.ask.field && (qt.ask.options || []).length) {
      askHtml = '<div class="qt-ask" role="group"><div class="qt-ask-q">❓ ' + esc(qt.ask.question) + '</div>'
        + '<div class="qt-chips" data-k="' + esc(qt.ask.field) + '">'
        + qt.ask.options.map(function (o) { return '<button type="button" data-v="' + esc(o.value) + '">' + esc(o.label) + '</button>'; }).join('')
        + '</div></div>';
    }
    /* "Same as last time" — a returning passenger's usual pickup, direction,
       party and berths in one tap (the plan takes the berths when free). */
    var sameHtml = '';
    if (qt.sameAsLast && !(qt.prefer || []).length && (qt.sameAsLast.seats || []).length) {
      sameHtml = '<button type="button" class="qt-same" id="qtSame">🔁 ' + esc(t('qtSameLast')) + ' · ' + esc(seatLabelJoin(qt.sameAsLast.seats, 'sleeper', 'sharing'))
        + (qt.sameAsLast.lastTrip ? ' · ' + esc(qt.sameAsLast.lastTrip) : '') + '</button>';
    }
    // 0.60-0.84 band: the facts the assistant chose by itself carry a small "auto" tag.
    var autoTag = function (k) { return (p.ladder === 'highlight' && (qt.missing || []).indexOf(k) >= 0) ? ' <i class="qt-auto">' + esc(t('qtAuto')) + '</i>' : ''; };
    var html = askHtml + sameHtml
      + '<div class="qt-plan-head"><b>' + esc(t('qtPlanT')) + '</b><span>' + esc(p.seatsLeft != null ? tf('qtLeft', { n: p.seatsLeft }) : '') + '</span></div>'
      + (p.from && p.to ? '<div class="qt-route" aria-hidden="true"><span>' + esc(p.boardingCode || p.from) + '</span><i><b><img src="/assets/img/bus-side.svg?v=20260926b" alt="" width="640" height="200" decoding="async"></b></i><span>' + esc(p.to) + '</span></div>' : '')
      + '<div class="qt-plan-facts">'
      + '<div><small>' + esc(t('qtDateLbl')) + '</small><b>' + esc(when) + autoTag('date') + '</b><em>' + esc(p.dateLabel) + '</em></div>'
      + '<div><small>' + esc(t('qtBoardLbl')) + '</small><b>' + esc(p.boardingCode) + ' · ' + esc(p.boardingName) + autoTag('boarding') + '</b><em>' + esc(p.boardingTime || p.depTime || '') + ' · ' + esc(p.from) + ' → ' + esc(p.to) + '</em></div>'
      + '<div><small>' + esc(t('qtSeatsLbl')) + '</small><b>' + esc(p.seatCount) + autoTag('seats') + '</b><em>' + esc(t('qtSeatLbl')) + ' ' + esc(seatLabelJoin(p.seats, p.coach, p.bookingMode)) + '</em></div>'
      + '<div class="big"><small>' + esc(t('qtFareLbl')) + '</small><b>' + money(f.total) + '</b><em>' + esc(p.seatCount) + ' × ' + money(f.perSeat) + '</em></div>'
      + '</div>'
      + '<div class="qt-why">' + esc(why) + '</div>';

    /* Editors — built into their own block, COLLAPSED by default so the card
       is a one-tap booking (owner ask 8 Sep 2026: keep it simple, hide the
       options until the customer wants to change something). qt.showEditors
       survives a replan, so the panel stays open while they adjust. */
    var ed = '';
    var dates = '', otherOn = false;
    for (var i = 0; i < 7; i++) {
      var iso = isoAdd(today, i);
      if (p.window && p.window.to && iso > p.window.to) break;
      dates += '<button type="button" data-v="' + iso + '" class="' + (iso === p.date ? 'on' : '') + '">' + esc(i === 0 ? t('qtToday') : (i === 1 ? t('qtTomorrow') : fmtShort(iso))) + '</button>';
    }
    if (p.date && isoDiff(today, p.date) > 6) otherOn = true;
    dates += '<button type="button" data-v="other" class="' + (otherOn ? 'on' : '') + '">' + esc(t('qtOther')) + '</button>';
    ed += '<div class="qt-plan-row"><span>' + esc(t('qtDateLbl')) + '</span><div class="qt-chips qt-dates" data-k="date">' + dates + '</div></div>'
      + '<div class="qt-plan-row qt-date-other" id="qtDateOther"' + (otherOn ? '' : ' hidden') + '><span></span><input type="date" id="qtDateIn" min="' + esc((p.window && p.window.from) || today) + '" max="' + esc((p.window && p.window.to) || '') + '" value="' + esc(otherOn ? p.date : '') + '"></div>';
    if (stops.length > 1) {
      ed += '<label class="qt-plan-row"><span>' + esc(t('qtBoardLbl')) + '</span><select id="qtBoardSel">'
        + stops.map(function (s) {
            return '<option value="' + esc(s.name) + '"' + (s.name === chosen ? ' selected' : '') + '>' + esc(s.code + ' · ' + s.short + (s.time ? ' · ' + s.time : '')) + '</option>';
          }).join('')
        + '</select></label>';
    }
    var max = maxSeats(), chips = '';
    for (var n = 1; n <= max; n++) chips += '<button type="button" data-v="' + n + '" class="' + (p.seatCount === n ? 'on' : '') + '">' + n + '</button>';
    ed += '<div class="qt-plan-row"><span>' + esc(t('qtSeatsLbl')) + '</span><div class="qt-chips" data-k="seats">' + chips + '</div></div>';
    if ((p.directions || []).length > 1) {
      ed += '<div class="qt-plan-row"><span>↔</span><div class="qt-chips" data-k="direction">'
        + '<button type="button" data-v="toNepal" class="' + (p.direction === 'toNepal' ? 'on' : '') + '">' + esc(t('qtDirGo')) + '</button>'
        + '<button type="button" data-v="toIndia" class="' + (p.direction === 'toIndia' ? 'on' : '') + '">' + esc(t('qtDirBack')) + '</button></div></div>';
    }
    ed += '<div class="qt-plan-row"><span>' + esc(t('qtPaxLbl')) + '</span><div class="qt-chips" data-k="gender">'
      + '<button type="button" data-v="" class="' + (!qt.gender ? 'on' : '') + '">' + esc(t('qtGAny')) + '</button>'
      + '<button type="button" data-v="Female" class="' + (qt.gender === 'Female' ? 'on' : '') + '">' + esc(t('qtGF')) + '</button>'
      + '<button type="button" data-v="Male" class="' + (qt.gender === 'Male' ? 'on' : '') + '">' + esc(t('qtGM')) + '</button></div></div>'
      // Optional agent code — never required; an unknown code is a direct sale.
      + '<div class="qt-agent"><label for="qtAgentIn">' + esc(t('qtAgentLbl')) + '</label><input id="qtAgentIn" maxlength="10" placeholder="' + esc(t('qtAgentPh')) + '" value="' + esc(qt.agentCode) + '" autocomplete="off"></div>';

    var edOpen = !!qt.showEditors;
    /* 11 Sep 2026 perf pass: the ONE button that sells the ticket now comes
       BEFORE the "change date / seats / pickup" toggle and its editors. On a
       375x812 phone the confirm sat at y=947 under a collapsed toggle; the
       primary action is the first thing after the fare line now, and the
       editors still open right under their toggle. */
    html += '<div class="qt-pay">' + esc(tf(cod ? 'qtPayCod' : 'qtPayOnline', { amt: money(f.total) })) + '</div>'
      + (msg ? '<div class="qt-plan-msg" role="alert">' + esc(msg) + '</div>' : '')
      + '<button type="button" class="btn btn-orange qt-confirm qt-go" id="qtConfirm"' + (qt.busy ? ' disabled' : '') + '>'
      + esc(t('qtCta') + ' — ' + money(f.total) + ' · ' + when + ' · ' + stopShort) + '</button>'
      + '<button type="button" class="qt-modify" id="qtModify" aria-expanded="' + (edOpen ? 'true' : 'false') + '" aria-controls="qtEditors">'
      + esc(edOpen ? t('qtModifyHide') : t('qtModify')) + '</button>'
      + '<div class="qt-editors" id="qtEditors"' + (edOpen ? '' : ' hidden') + '>' + ed + '</div>'
      + '<div class="qt-tnc">' + esc(t('qtTnc')) + ' <a href="#/terms">T&amp;C</a></div>';
    card.innerHTML = html;

    var same = $id('qtSame');
    if (same) same.addEventListener('click', function () {
      var s = qt.sameAsLast || {};
      qt.prefer = (s.seats || []).slice(0, 4);
      if (s.boarding) qt.boarding = s.boarding;
      if (s.direction) qt.direction = s.direction;
      if (s.party > 0) qt.seats = s.party;
      replan(0);
    });
    var mod = $id('qtModify');
    if (mod) mod.addEventListener('click', function () {
      qt.showEditors = !qt.showEditors;
      var box = $id('qtEditors');
      if (box) box.hidden = !qt.showEditors;
      this.setAttribute('aria-expanded', qt.showEditors ? 'true' : 'false');
      this.textContent = qt.showEditors ? t('qtModifyHide') : t('qtModify');
    });

    Array.prototype.forEach.call(card.querySelectorAll('.qt-chips'), function (box) {
      box.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-v]'); if (!b) return;
        var k = box.getAttribute('data-k'), v = b.getAttribute('data-v');
        if (k === 'date' && v === 'other') {
          // The date box lives inside the (collapsed) editors: open them so the tap leads somewhere.
          qt.showEditors = true; var box0 = $id('qtEditors'); if (box0) box0.hidden = false;
          var mod0 = $id('qtModify'); if (mod0) { mod0.setAttribute('aria-expanded', 'true'); mod0.textContent = t('qtModifyHide'); }
          var row = $id('qtDateOther'); if (row) { row.hidden = false; var inp = $id('qtDateIn'); if (inp) { try { inp.showPicker(); } catch (e2) {} inp.focus(); } }
          Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
          return;
        }
        Array.prototype.forEach.call(box.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
        qt[k] = k === 'seats' ? (parseInt(v, 10) || 1) : v;
        replan();
      });
    });
    var din = $id('qtDateIn');
    if (din) din.addEventListener('change', function () { if (this.value) { qt.date = this.value; replan(); } });
    var sel = $id('qtBoardSel');
    if (sel) sel.addEventListener('change', function () { qt.boarding = this.value; replan(); });
    var ag = $id('qtAgentIn');
    if (ag) ag.addEventListener('input', function () { qt.agentCode = this.value.trim().toUpperCase(); });
    $id('qtConfirm').addEventListener('click', confirmSale);
  }

  /* ---- the ONE tap -------------------------------------------------------- */
  function confirmSale() {
    if (qt.busy) return;
    var p = qt.plan;
    if (!p) { qt.submitAfterPlan = true; plan(); return; }
    if (!valid(false)) return;
    qt.busy = true;
    clearTimeout(qt.timer); qt.req++;          // nothing may re-plan alongside the sale (session regeneration)
    var b0 = $id('qtConfirm'); if (b0) { b0.disabled = true; b0.textContent = t('qtIssuing'); }
    var a = (qt.personal && qt.personal.applied) || {};
    var payload = {
      action: 'customer_sell', name: qt.name, phone: qt.phone, gender: qt.gender, agentCode: qt.agentCode, prefer: qt.prefer,
      // pinned to the card: the plan's own day, direction, pickup and party
      seats: p.seatCount, direction: p.direction, boarding: stopValue(p.boarding), date: p.date,
      expect: { date: p.date, direction: p.direction, boardingCode: p.boardingCode, seats: p.seatCount, total: (p.fare || {}).total }
    };
    if (a.boarding || a.seats || a.direction) {
      // what the assistant pre-filled, so the outcome loop can score it
      payload.bot = { boarding: a.boarding ? stopValue(p.boarding) : '', direction: a.direction ? p.direction : '', seats: a.seats ? p.seatCount : 0 };
    }
    shgApi.post('/quick-ticket.php', payload)
      .then(function (d) {
        var b = d && d.customer ? bookingFromServer(d.customer, qt.phone) : null;
        if (b) {
          var at = -1;
          for (var i = 0; i < DB.bookings.length; i++) { if (DB.bookings[i] && DB.bookings[i].id === b.id) { at = i; break; } }
          if (at >= 0) DB.bookings[at] = Object.assign({}, DB.bookings[at], b); else DB.bookings.unshift(b);
          persist('bookings');
        }
        if (d && d.user && typeof setUser === 'function') {
          try {
            setUser({ phone: d.user.phone || qt.phone, name: d.user.name || qt.name, role: d.user.role || 'customer',
                      points: d.user.points || 0, tier: d.user.tier || 'Silver', at: Date.now(), srvAt: Date.now() });
            if (typeof ensureUser === 'function') ensureUser(qt.phone);
            if (typeof adoptAccountLang === 'function') adoptAccountLang(d.user);
          } catch (e2) {}
          // The sale signed them in: the card now greets them by name (no reload needed).
          me = { phone: d.user.phone || qt.phone, name: d.user.name || qt.name };
          try { showIdentity(); } catch (e4) {}
        }
        // Free undo window for a mistaken tap (rendered on the ticket screen).
        try {
          if (d && d.undoMin > 0) sessionStorage.setItem('shg_qt_undo', JSON.stringify({ pnr: d.pnr, until: Date.now() + d.undoMin * 60000 }));
          else sessionStorage.removeItem('shg_qt_undo');
        } catch (e3) {}
        toast(tf('qtDone', { s: ((d && d.elapsedMs) ? d.elapsedMs / 1000 : 0).toFixed(1) }));
        // Agent code + WhatsApp outcome, said once each.
        if (d && d.agent) setTimeout(function () { toast(d.agent.applied ? tf('qtAgentOk', { code: d.agent.label }) : t('qtAgentNo')); }, 1800);
        if (d && d.whatsapp && d.whatsapp.sent) setTimeout(function () { toast(tf('qtWaSent', { to: d.whatsapp.to || '' })); }, 3400);
        if (line) line.value = '';
        qt.text = ''; qt.parsed = null; renderUnderstood(null);
        var card = $id('qtPlanCard'); if (card) card.hidden = true;
        qt.plan = null;
        location.hash = '#/ticket/' + encodeURIComponent(d.pnr);
      })
      .catch(function (e) {
        var f = e && e.fields;
        if (f && f.code === 'plan_changed' && f.plan) {
          qt.plan = f.plan; qt.personal = f.plan.personal || qt.personal;
          renderPlan(t('qtChanged'));
        } else {
          renderPlan((e && e.message) || t('qtErr'));
        }
        var b1 = $id('qtConfirm'); if (b1) b1.disabled = false;
      })
      .then(function () { qt.busy = false; });
  }

  /* ---- free undo on the ticket screen --------------------------------- */
  function undoRecord() {
    try { var r = JSON.parse(sessionStorage.getItem('shg_qt_undo') || 'null'); return (r && r.pnr && r.until > Date.now()) ? r : null; } catch (e) { return null; }
  }
  function mountUndo() {
    var r = undoRecord(), m = /^#\/ticket\/(.+)$/.exec(location.hash || '');
    if (!r || !m) return;
    var pnr = decodeURIComponent(m[1]);
    if (pnr !== r.pnr) return;
    var body = $id('statusBody');
    if (!body || $id('qtUndo')) return;
    var box = document.createElement('div'); box.className = 'qt-undo'; box.id = 'qtUndo';
    body.insertBefore(box, body.firstChild);
    var doUndo = function () {
      var b = $id('qtUndoBtn'); if (b) b.disabled = true;
      shgApi.post('/quick-ticket.php', { action: 'customer_undo', pnr: r.pnr }).then(function () {
        try { sessionStorage.removeItem('shg_qt_undo'); } catch (e) {}
        for (var i = 0; i < DB.bookings.length; i++) { if (DB.bookings[i] && DB.bookings[i].id === r.pnr) DB.bookings[i].status = 'cancelled'; }
        persist('bookings');
        box.remove();
        toast(t('qtUndone'));
        location.hash = '#/';
      }).catch(function (e) { toast((e && e.message) || t('qtErr')); var b2 = $id('qtUndoBtn'); if (b2) b2.disabled = false; });
    };
    var tick = function () {
      if (!document.body.contains(box)) return false;
      var left = Math.max(0, r.until - Date.now());
      if (!left) { box.remove(); return false; }
      var mm = Math.floor(left / 60000), ss = Math.floor((left % 60000) / 1000);
      var btnEl = $id('qtUndoBtn');
      if (!btnEl) {
        box.innerHTML = '<span id="qtUndoTxt"></span><button type="button" class="btn" id="qtUndoBtn">' + esc(t('qtUndoBtn')) + '</button>';
        $id('qtUndoBtn').addEventListener('click', doUndo);
      }
      $id('qtUndoTxt').textContent = tf('qtUndoQ', { m: mm + ':' + (ss < 10 ? '0' : '') + ss });
      return true;
    };
    tick();
    var iv = setInterval(function () { if (!tick()) clearInterval(iv); }, 1000);
  }
  window.addEventListener('hashchange', function () {
    var h = location.hash || '#/';
    if (h.indexOf('#/ticket/') === 0) {
      setTimeout(mountUndo, 60); setTimeout(mountUndo, 800);
    } else {
      var stale = $id('qtUndo'); if (stale) stale.remove();     // the ticket view keeps its DOM when hidden
      // Back on the home after a sale or an undo: the card plans itself again.
      if (h === '#/' && !qt.plan && !qt.busy && !qt.off) plan();
    }
  });
  setTimeout(mountUndo, 300);

  /* ---- wiring ------------------------------------------------------------- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (qt.off) { if (valid(false)) whatsappFallback(); return; }
    confirmSale();                                   // Enter in a box = the one tap
  });
  /* 🎤 Voice (12 Sep 2026): Chrome Android + iOS 14.5+ expose SpeechRecognition.
     The transcript is dropped into the SAME smart line and dispatched as input,
     so the parser, the plan and Enter-to-confirm work exactly as when typed.
     Language follows the app's own language switch. No browser support = no
     button, nothing else changes. */
  (function () {
    var mic = $id('qtMic'), wrap = $id('qtLineWrap');
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!mic || !line || !SR || line.hidden) return;
    mic.hidden = false; if (wrap) wrap.classList.add('has-mic');
    var rec = null, hush = null, cap = null;

    /* 24 Sep 2026 (owner: "understand different speaking styles … short or
       incomplete sentences"). The single biggest accuracy problem here was
       not the parser — it was that this ran in the default one-shot mode.
       Android ends recognition at the FIRST pause, and a booking is spoken
       with pauses in it: "Ram Bahadur … nau aath saat … dui sit … bholi".
       Everything after the first gap was simply never heard, and the desk
       blamed the parser for a line it was never given.

       continuous:true keeps the session open across those gaps; a 2.2s
       hush timer after the last FINAL result closes it so nobody has to
       find the stop button, and a 20s cap means a phone left face-down in
       a pocket cannot hold the microphone open. */
    function stopSoon(ms) {
      clearTimeout(hush);
      hush = setTimeout(function () { try { if (rec) rec.stop(); } catch (e) {} }, ms);
    }
    function done() {
      clearTimeout(hush); clearTimeout(cap);
      mic.classList.remove('listening');
      mic.setAttribute('aria-pressed', 'false');
      rec = null;
    }

    mic.addEventListener('click', function () {
      if (rec) { try { rec.stop(); } catch (e) {} return; }
      try {
        rec = new SR();
        var map = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP', gu: 'gu-IN' };
        rec.lang = map[typeof LANG === 'string' ? LANG : 'ne'] || 'en-IN';
        rec.interimResults = true; rec.maxAlternatives = 1;
        try { rec.continuous = true; } catch (e) { /* older engines ignore it */ }
        mic.classList.add('listening');
        mic.setAttribute('aria-pressed', 'true');
        try { if (window.SHGFeel) window.SHGFeel.fire('select'); } catch (e) {}

        rec.onresult = function (ev) {
          var out = '', final = false;
          for (var i = 0; i < ev.results.length; i++) {
            out += ev.results[i][0].transcript;
            if (ev.results[i].isFinal) final = true;
          }
          line.value = out;
          line.dispatchEvent(new Event('input', { bubbles: true }));
          /* A pause AFTER something was actually heard ends the turn; while
             the speaker is still mid-phrase the timer keeps being pushed
             out, which is what lets a sentence with gaps arrive whole. */
          if (final) stopSoon(2200);
        };
        rec.onspeechend = function () { stopSoon(1200); };
        rec.onend = function () {
          done();
          try { if (window.SHGFeel) window.SHGFeel.fire('tap'); } catch (e) {}
        };
        rec.onerror = function (ev) {
          var why = ev && ev.error;
          done();
          /* "aborted" is the user tapping stop — not a failure to report.
             A refused microphone needs its own sentence, because telling
             somebody to "speak again" when the browser has blocked the mic
             is the most frustrating message the app could give. */
          if (why === 'aborted') return;
          if (why === 'not-allowed' || why === 'service-not-allowed') {
            toast('🎤 ' + t('qtMicErr'));
            return;
          }
          toast(t('qtMicErr'));
          try { if (window.SHGFeel) window.SHGFeel.fire('error'); } catch (e) {}
        };
        rec.start();
        cap = setTimeout(function () { try { if (rec) rec.stop(); } catch (e) {} }, 20000);
      } catch (e) { done(); }
    });
  })();
  if (line) {
    // The smart line is read on every pause; Enter = confirm (after it is read).
    line.addEventListener('input', function () {
      err('');
      qt.text = this.value.trim();
      replan(qt.text === '' ? 200 : 650);
    });
    line.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      qt.text = this.value.trim();
      clearTimeout(qt.timer);
      if (qt.off) { if (valid(false)) whatsappFallback(); return; }
      qt.submitAfterPlan = true;
      plan();
    });
  }
  ['qtName', 'qtPhone'].forEach(function (i) { var el = $id(i); if (el) el.addEventListener('input', function () { err(''); }); });

  /* Auto-plan for everyone, a moment after boot: the card sits right under
     the hero and must already read "tomorrow · S Hari Parking · 1 · ₹2,000"
     when the passenger reaches it — a signed-in passenger at once, a
     first-time visitor after the boot work has settled. (The plan call is
     read-only and cheap; its bucket is sized for shared carrier NATs.) */
  var started = false;
  function start() { if (started) return; started = true; plan(); }
  if (me) {
    start();
  } else if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(start, { timeout: 1200 });
  } else {
    setTimeout(start, 400);
  }
}
/* The office WhatsApp the Quick Ticket request goes to: the first admin-set
   WhatsApp number on the contact strip, else the Get Help card's number. */
function quickTicketNumber() {
  try {
    var live = contactNumbers().filter(function (n) { return n.wa && n.show && digits(n.num).length >= 7; });
    if (live.length) return digits(live[0].num);
  } catch (e) {}
  var help = document.querySelector('.ha-help[data-wa]');
  var m = help && String(help.getAttribute('data-wa') || '').match(/wa\.me\/(\d+)/);
  return m ? m[1] : '919104801507';
}

function onlinePct() {
  const pct = CONFIG.cabinPricing && CONFIG.cabinPricing.onlineDiscountPct;
  /* `|| 5` would turn a deliberate 0% into 5% and advertise a discount the
     server will not honour, so only a missing/invalid value falls back. */
  return (typeof pct === 'number' && pct >= 0 && pct < 100) ? pct : 5;
}
function onlinePP(base) {
  return Math.round(base * (1 - onlinePct() / 100));
}

/* The live strip inside the search card. */
function renderFareLive() {
  const box = $('#fareLive'); if (!box) return;
  const fromEl = $('#fromSel'), toEl = $('#toSel');
  if (!fromEl || !toEl) return;
  const from = fromEl.value, to = toEl.value;
  if (!from || !to || from === to) { box.classList.remove('on'); return; }

  const base = sharingBasePP(to);
  const now  = onlinePP(base);
  const save = base - now;
  const goingOut = isNepalPoint(to);
  /* How many buses actually serve this pair. Exact from/to equality alone
     showed "0 बस" for Baroda / S Hari Parking / Nana Chiloda / Mehsana — genuine
     pickups of the one daily run whose from is Surat — so a mid-route town
     also counts when its townKeyJS matches one of the route's boarding
     stops (going) / drop stops (coming). Pure local data, no network. */
  const townK = townKeyJS(goingOut ? from : to);
  const nBuses = (DB.routes || []).filter(r => {
    if (!r || !r.active) return false;
    if (r.from === from && r.to === to) return true;
    if (goingOut) {
      return isNepalPoint(r.to) && !isNepalPoint(r.from)
        && (r.boarding || []).some(s => townKeyJS(s) === townK);
    }
    return isNepalPoint(r.from) && !isNepalPoint(r.to)
      && (r.drop || []).some(s => townKeyJS(s) === townK);
  }).length;

  box.innerHTML =
      '<span class="fl-dir">' + (goingOut ? '🇮🇳 → 🇳🇵' : '🇳🇵 → 🇮🇳') + ' '
    + esc(from) + ' → ' + esc(to) + '</span>'
    + '<span class="fl-buses' + (nBuses ? '' : ' none') + '">🚌 ' + (nBuses === 1 ? t('flBus1') : tf('flBusN', { n: nBuses })) + '</span>'
    + '<span style="flex:1"></span>'
    + '<span><span class="fl-lbl">' + t('flPerPerson') + '</span><br>'
    + '<span class="fl-amt">' + inr(now) + '</span> '
    + (save > 0 ? '<span class="fl-was">' + inr(base) + '</span>' : '')
    + '<br><span class="fl-npr">' + nprEst(now) + '</span></span>'
    + (save > 0
        ? '<span class="fl-save"><b class="fl-off">' + tf('offPill', { pct: onlinePct() }) + '</b>'
          + tf('flSave', { a: inr(save) }) + '</span>'
        : '');
  box.classList.add('on');
}

/* The two fare-structure tables under the board. Sharing is priced per
   person by direction; private is priced per cabin, so the two tables are
   genuinely different shapes rather than one table with a flag. */
function renderPricingCards() {
  const box = $('#pricingCards'); if (!box) return;
  const pct  = onlinePct();
  const d    = sharingDir();
  const priv = (CONFIG.cabinPricing && CONFIG.cabinPricing.private) || {};

  const shareRow = (label, base) =>
    '<tr><td>' + esc(label) + '</td><td>' + inr(base) + '</td>'
    + '<td class="online-col">' + inr(onlinePP(base)) + '</td>'
    + '<td>' + nprEst(onlinePP(base)) + '</td></tr>';

  const privRow = (p, note) => p ? '<tr><td>' + esc(p.label) + '</td><td>' + p.capacity + '</td>'
    + '<td>' + inr(p.offline) + '</td><td class="online-col">' + inr(p.online) + '</td>'
    + '<td>' + esc(note) + '</td></tr>' : '';

  box.innerHTML =
      '<div class="pricing-card sharing reveal">'
    +   '<div class="pc-head"><span class="pc-ico">🤝</span>'
    +     '<div><h3>' + t('pcShareT') + '</h3><small>' + t('pcShareS') + '</small></div></div>'
    +   '<table><thead><tr><th>' + t('pcDir') + '</th><th>' + t('pcCounter') + '</th>'
    +     '<th>' + tf('pcOnline', { pct: pct }) + '</th><th>NPR</th></tr></thead><tbody>'
    +     shareRow(t('fbGoing'),  d.toNepal)
    +     shareRow(t('fbComing'), d.toIndia)
    +   '</tbody></table>'
    +   '<p style="font-size:12px;color:var(--muted);margin:10px 2px 0">' + t('pcShareNote') + '</p>'
    + '</div>'
    + '<div class="pricing-card private reveal d1">'
    +   '<div class="pc-head"><span class="pc-ico">🔒</span>'
    +     '<div><h3>' + t('pcPrivT') + '</h3><small>' + t('pcPrivS') + '</small></div></div>'
    +   '<table><thead><tr><th>' + t('pcCabin') + '</th><th>' + t('pcCap') + '</th>'
    +     '<th>' + t('pcCounter') + '</th><th>' + tf('pcOnline', { pct: pct }) + '</th><th>' + t('pcNote') + '</th></tr></thead><tbody>'
    +     privRow(priv.single_1pax, t('pcSolo'))
    +     privRow(priv.double_2pax, t('pcCouple'))
    +   '</tbody></table>'
    + '</div>';

  /* The "you save" headline, from the biggest real saving on the page rather
     than a number typed in once and never revisited. */
  const line = $('#pricingSaveLine');
  if (line) {
    const best = Math.max(
      d.toNepal - onlinePP(d.toNepal),
      d.toIndia - onlinePP(d.toIndia),
      (priv.double_2pax ? priv.double_2pax.offline - priv.double_2pax.online : 0)
    );
    // Flat pricing (no online discount): there is nothing to advertise, so the
    // "you save" headline is hidden rather than showing "saves up to ₹0".
    if (best > 0) { line.textContent = tf('pcSaveLine', { a: inr(best) }); line.hidden = false; }
    else { line.textContent = ''; line.hidden = true; }
  }
}

/* Only advertise a town a bus actually calls at.
   CONFIG.mainPoints is the operator's wish-list of towns; the routes are
   what the fleet really does. Those two drifted — the board was quoting a
   per-person fare for towns that appear on no route as a city, a boarding
   point or a drop, so a passenger could tap a price for a stop the coach
   never makes. A quoted fare has to correspond to a seat someone can
   actually board, so the board is now built from the routes.

   Add the stop in Admin -> Routes (with a real time) and the town comes
   back on its own; nothing here needs editing. If a mismatch ever empties
   a side completely, fall back to the configured list rather than render a
   blank card. */
function servedPoints(points) {
  /* The operator's CONFIG.mainPoints (Admin -> Settings -> Main points) is
     the DECLARED list of boarding/drop towns, and it is authoritative — the
     owner confirmed all four Gujarat points (Surat, Vadodara, Ahmedabad,
     Mehsana) are real pickups. An earlier build filtered this list against
     the per-route stop text and hid a town whose boarding TIME had not been
     entered yet; that dropped genuine pickups off the board. The fare is by
     direction, not by stop, so every declared main point is bookable and
     priced correctly whether or not its pickup time is filled in — that time
     is an operational detail set per route in Admin, not a reason to hide the
     town from the price board. */
  return Array.isArray(points) ? points : [];
}

/* The full board under the search card. */
function renderFareBoard() {
  const box = $('#fareBoard'); if (!box) return;
  const mp   = CONFIG.mainPoints || { india: [], nepal: [] };
  const d    = sharingDir();
  const hub  = (mp.nepal || ['Rupaidiha'])[0];
  const pct  = onlinePct();

  /* Use the shared pickup-time lookup (rebuildPickupTimes) to show
     "Baroda · 5:00 PM" instead of just "Baroda" on each fare row. */
  var pointWithTime = function (p) {
    var tm = pickupTimeFor(p);
    return tm ? p + ' · ' + fmt12h(tm) : p;
  };

  /* One direction card: the headline per-person price, then every main
     point on the far side listed against it — now with departure time. */
  const card = (cls, flagFrom, flagTo, title, points, toward, base) => {
    const now = onlinePP(base);
    return '<div class="fb-dir ' + cls + '">'
      + '<div class="fb-dir-t"><b>' + flagFrom + ' ' + esc(title) + ' ' + flagTo + '</b>'
      + '<span class="fb-price"><span class="p">' + inr(now) + '</span>'
      + (base > now ? '<span class="w">' + inr(base) + '</span>' : '')
      + '<span class="n">' + nprEst(now) + ' · ' + t('fbPerPerson') + '</span></span></div>'
      + points.map(p => '<button type="button" class="fb-row" data-fb-from="' + esc(p) + '" data-fb-to="' + esc(toward) + '">'
          + '<span class="c">' + esc(pointWithTime(p)) + ' → ' + esc(toward) + '</span>'
          + '<span class="k">' + inr(now) + ' · ' + nprEst(now) + '</span></button>').join('')
      + '</div>';
  };

  box.innerHTML =
      '<div class="fb-head"><h3>💰 ' + t('fbTitle') + '</h3><small>' + t('fbSub') + '</small>'
    + (pct > 0 ? '<span class="fb-off">' + tf('offPill', { pct: pct }) + '</span>' : '') + '</div>'
    + '<p class="fb-sub">' + tf('fbLead', { pct: pct }) + '</p>'
    + '<div class="fb-dirs">'
    + card('go',   '🇮🇳', '🇳🇵', t('fbGoing'),  servedPoints(mp.india), hub,                       d.toNepal)
    /* Return card points AT a single Gujarat hub: the FIRST canonical main
       point (Surat), which is also the return route's real endpoint. */
    + card('back', '🇳🇵', '🇮🇳', t('fbComing'), servedPoints(mp.nepal),
           ((mp.india || [])[0] || ''), d.toIndia)
    + '</div>'
    + '<div class="fb-foot"><span class="fb-chip">🤝 ' + t('fbSharing') + '</span>'
    + '<span>' + t('fbNote') + '</span></div>';

  /* Tapping a row is the shortest path from "what does it cost" to "book it":
     it pre-fills the search with that pair where a bus actually runs, and
     otherwise falls back to the served hub on the same side rather than
     leaving a dropdown on a city with no departures. */
  box.onclick = (e) => {
    const row = e.target.closest('[data-fb-from]'); if (!row) return;
    const want = { from: row.getAttribute('data-fb-from'), to: row.getAttribute('data-fb-to') };
    const has  = (sel, v) => $$('option', $(sel)).some(o => o.value === v);
    const pick = (sel, v, fallbackNepal) => {
      const el = $(sel); if (!el) return;
      if (has(sel, v)) { el.value = v; return; }
      /* No bus is listed from this town yet — fall back to the hub on the
         same side of the border so the search still returns something. */
      const alt = fallbackNepal ? SHG_NEP_HUB : (((CONFIG.mainPoints || {}).india || [])[0] || '');
      if (has(sel, alt)) el.value = alt;
    };
    pick('#fromSel', want.from, isNepalPoint(want.from));
    pick('#toSel',   want.to,   isNepalPoint(want.to));
    renderFareLive();
    const anchor = document.getElementById('search-anchor');
    if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
}
function setTripType(type) {
  /* Round trip gate (17 Sep 2026): the engine books ONE leg per ticket, so
     until the office switches round_trip_on on (CONFIG.roundTripOn) a round
     trip is not selectable — the return is sold as its own ticket from the
     confirmed ticket's "Book return journey" button. */
  if (type === 'round' && !(typeof CONFIG !== 'undefined' && CONFIG.roundTripOn)) {
    type = 'one';
    try { toast(t('roundOffNote')); } catch (e) {}
  }
  Flow.tripType = type;
  $('#tripOne').classList.toggle('on', type === 'one');
  $('#tripRound').classList.toggle('on', type === 'round');
  $('#retField').classList.toggle('hide', type !== 'round');
  $('#searchForm').classList.toggle('round', type === 'round');
}
$('#tripOne').addEventListener('click', () => setTripType('one'));
$('#tripRound').addEventListener('click', () => setTripType('round'));
/* Hide the pill (and show the one-line note in its place) while the gate is
   off; when it is on the search card is exactly as before. */
(function gateRoundTrip() {
  const on = !!(typeof CONFIG !== 'undefined' && CONFIG.roundTripOn);
  const pill = $('#tripRound'), note = $('#roundOffNote');
  if (pill) { pill.classList.toggle('hide', !on); pill.disabled = !on; pill.setAttribute('aria-disabled', on ? 'false' : 'true'); }
  if (note) note.classList.toggle('hide', on);
  if (!on && Flow.tripType === 'round') setTripType('one');
})();
/* Repricing on every From/To change is what makes the fare feel "automatic" —
   the passenger never has to press Search to find out what it costs. */
['#fromSel', '#toSel'].forEach(sel => {
  const el = $(sel);
  if (el) el.addEventListener('change', renderFareLive);
});
$('#dateInput').addEventListener('change', () => {
  const ri = $('#retInput');
  ri.min = $('#dateInput').value || todayISO();
  if (ri.value && ri.value < ri.min) ri.value = ri.min;
  markDateChip();
});

/* ================================================================
   V6 §"fewest possible clicks" — 30-day quick-date strip (Round 3).
   The passenger sees a DATE first. A horizontal scroll-snap strip of
   30 tap targets removes the calendar entirely for the common case
   (any journey inside the sellable window). Renders into
   #dateStrip30 when present (Builder VIS's new container), with a
   graceful fallback to the legacy #dateChips container so old markup
   still works. The strip drives the same #dateInput the calendar
   does — nothing downstream changes.
================================================================ */
/* Your next journey — one card at the top of home for a signed-in
   traveller with an upcoming confirmed ticket.

   It links to #/trip/<PNR>; it does NOT restate the journey. The trip
   companion there already has the phase timeline, live ETA and stop rail,
   and a second copy of that on home would be two views of one trip drifting
   apart. Hidden entirely for anonymous visitors, so the marketing home page
   is unchanged for them. */
function renderNextTrip() {
  const box = document.getElementById('nextTripBox');
  if (!box) return;

  const phone = (typeof USER !== 'undefined' && USER) ? USER.phone : '';
  if (!phone) { box.hidden = true; box.innerHTML = ''; return; }

  const today = new Date().toISOString().slice(0, 10);
  const next = (DB.bookings || [])
    .filter(b => b && b.status === 'confirmed' && (b.date || '') >= today
                 && digits(b.contact && b.contact.phone) === phone)
    .sort((a, b) => String(a.date).localeCompare(String(b.date)))[0];

  if (!next) { box.hidden = true; box.innerHTML = ''; return; }

  const r    = routeById(next.routeId) || {};
  const days = Math.round((new Date(next.date + 'T00:00:00') - new Date(today + 'T00:00:00')) / 86400000);
  const when = days <= 0 ? t('ntToday') : (days === 1 ? t('ntTomorrow') : tf('ntInDays', { n: days }));

  box.innerHTML =
    '<a class="next-trip" href="#/trip/' + esc(next.id) + '">'
    + '<span class="nt-when">' + esc(when) + '</span>'
    + '<span class="nt-route"><b>' + esc(r.from || '') + '</b> → <b>' + esc(r.to || '') + '</b></span>'
    + '<span class="nt-meta">' + esc(fmtDate(next.date)) + ' · '
      + esc(t('ntSeat')) + ' ' + esc(seatLabelJoin(next.seats, (routeById(next.routeId) || {}).type, next.bookingType)) + ' · ' + esc(next.id) + '</span>'
    + '<span class="nt-go">' + esc(t('ntOpen')) + ' →</span>'
    + '</a>';
  box.hidden = false;
}
window.renderNextTrip = renderNextTrip;

function renderDateChips() {
  const strip = document.getElementById('dateStrip30') || document.getElementById('dateChips');
  const di = $('#dateInput');
  if (!strip || !di) return;

  /* Same locale map fmtDate() uses, so a chip never disagrees with the
     date printed on the results card two taps later. */
  const loc = LANG === 'hi' ? 'hi-IN' : (LANG === 'ne' ? 'ne-NP' : 'en-IN');
  const fmt = (d, opts) => {
    try { return d.toLocaleDateString(loc, opts); }
    catch (e) { return d.toLocaleDateString('en-IN', opts); }
  };

  /* Strip starts at whichever of today / openFrom is later — the first
     SELLABLE day. Before the inauguration (bookingWindow.openFrom) any
     earlier date is refused by the server. */
  const bw = (typeof CONFIG !== 'undefined' && CONFIG.bookingWindow) || {};
  const openFrom = bw.openFrom || '';
  const today = todayISO();
  const base = (openFrom && openFrom > today) ? openFrom : today;

  /* Sold-out hint — display only, and only from what we already have
     cached. We never fire an extra fetch per date (API contract must not
     change). If the current from/to has a SrvCatalog snap for the ISO and
     every route there reports zero seatsLeft, mark it dim. */
  let fromV = '', toV = '';
  try { fromV = ($('#fromSel') && $('#fromSel').value) || ''; } catch (e) {}
  try { toV   = ($('#toSel')   && $('#toSel').value)   || ''; } catch (e) {}
  const soldOutIso = (iso) => {
    if (!fromV || !toV || typeof SrvCatalog === 'undefined') return false;
    const snap = SrvCatalog.snap(fromV, toV, iso);
    if (!snap || !snap.byCode) return false;
    const codes = Object.keys(snap.byCode);
    if (!codes.length) return false;
    return codes.every(c => (snap.byCode[c].seatsLeft || 0) <= 0);
  };

  const selectedIso = di.value;

  strip.innerHTML = Array.from({ length: (bw.horizonDays || 30) }, (_, n) => {
    const iso = addDaysISO(base, n);
    const d   = new Date(iso + 'T00:00:00');
    const isToday = iso === today;
    const isPast  = !!(openFrom && iso < openFrom);
    const isOn    = iso === selectedIso;
    const isSold  = !isPast && soldOutIso(iso);
    const disabled = isPast || isSold;
    const day = fmt(d, { weekday: 'short' });
    const num = fmt(d, { day: 'numeric' });
    const mo  = fmt(d, { month: 'short' });
    const cls = ['d-btn',
      isToday ? 'today' : '',
      isOn ? 'on' : '',
      isPast ? 'past' : '',
      isSold ? 'sold-out' : ''
    ].filter(Boolean).join(' ');
    const dis = disabled ? ' disabled aria-disabled="true"' : '';
    /* Bikram Sambat line — Nepali passengers pick dates in BS ("भदौ १७"),
       so each chip carries the AD date it books plus the BS date it means.
       Out-of-table years return null and the chip stays AD-only. */
    const bs = (typeof nepaliBS === 'function') ? nepaliBS(iso) : null;
    const bsLine = bs
      ? '<span class="d-bs">' + esc(bs.monthName + ' ' + bsDevanagari(bs.d)) + '</span>'
      : '';
    return '<button type="button" class="' + cls + '" data-iso="' + iso + '"' + dis
      + (bs ? ' title="' + esc(nepaliBSWithDay(iso)) + ' · ' + iso + '"' : '') + '>'
      + '<span class="d-day">' + esc(day) + '</span>'
      + '<span class="d-num">' + esc(num) + '</span>'
      + '<span class="d-mo">'  + esc(mo)  + '</span>'
      + bsLine
      + '</button>';
  }).join('');

  markDateChip();

  /* Anchor: keep the currently-selected day (or the first day, i.e. today
     when today >= openFrom) visible. Silent — no smooth scroll — so it
     never fights a passenger who is already dragging the strip. */
  const target = strip.querySelector('.d-btn.on') || strip.querySelector('.d-btn');
  if (target && typeof target.scrollIntoView === 'function') {
    try { target.scrollIntoView({ inline: 'center', block: 'nearest' }); }
    catch (e) { try { target.scrollIntoView(); } catch (_) {} }
  }
}
/* Highlight whichever chip matches the field — including after the
   passenger picks a date from the calendar instead. Works for both the
   30-day strip and the legacy 3-chip container. */
function markDateChip() {
  const di = $('#dateInput'); if (!di) return;
  const strip = document.getElementById('dateStrip30') || document.getElementById('dateChips');
  if (!strip) return;
  strip.querySelectorAll('button').forEach((b) =>
    b.classList.toggle('on', b.getAttribute('data-iso') === di.value)
  );
}
document.addEventListener('click', (e) => {
  const chip = e.target.closest('#dateStrip30 button, #dateChips button'); if (!chip) return;
  /* Past / sold-out days are visible but not sellable — the button carries
     `disabled`/`aria-disabled`, so the browser already blocks the click on
     real button elements, but check explicitly for safety. */
  if (chip.disabled || chip.getAttribute('aria-disabled') === 'true') return;
  const di = $('#dateInput'), ri = $('#retInput');
  di.value = chip.getAttribute('data-iso');
  if (ri) { ri.min = di.value; if (ri.value && ri.value < ri.min) ri.value = ri.min; }
  /* Programmatic `.value =` does NOT fire `change`, so any listener wired
     to #dateInput's change (the return-min sync, markDateChip, downstream
     search flow) would miss a strip tap. Dispatch it explicitly. */
  try { di.dispatchEvent(new Event('change', { bubbles: true })); } catch (_) {}
  markDateChip();
});
$('#searchForm').addEventListener('submit', (e) => {
  e.preventDefault();
  Flow.from = $('#fromSel').value; Flow.to = $('#toSel').value; Flow.date = $('#dateInput').value;
  Flow.retDate = $('#retInput').value;
  if (!Flow.date) { toast(t('tPickDate')); return; }
  if (Flow.from === Flow.to) { toast(t('tSameCity')); return; }
  if (Flow.tripType === 'round') {
    if (!Flow.retDate) { toast(t('tPickRetDate')); return; }
    if (Flow.retDate < Flow.date) { Flow.retDate = Flow.date; $('#retInput').value = Flow.date; }
  }
  releaseMyLocks();
  Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; Flow.fareOverride = 0;
  Flow.autoSkip = true;   // one bookable coach on the board -> straight to its seat map (06-results.js)
  location.hash = '#/results';
  if (location.hash === '#/results') router(); // re-render if hash unchanged
});

/* ================================================================
   [JS] 6b. SEAT LOCKS — session-based hold with countdown.
   A picked seat is locked for CONFIG.booking.seatHoldMinutes so a
   second visitor (another tab / device on the same storage) cannot
   select it. Locks expire on their own.
================================================================ */
function activeLocks() {
  const now = Date.now();
  const live = (DB.locks || []).filter(l => l.expires > now);
  if (live.length !== (DB.locks || []).length) { DB.locks = live; persist('locks'); }
  return live;
}
/* ================================================================
   SERVER SEAT TRUTH — who actually holds which berth.

   Availability used to be computed from DB.bookings / DB.locks, which for
   a guest is nothing but their own browser. Two visitors therefore saw two
   different buses: seats another customer had genuinely bought still looked
   free, and the sale only failed at the last step when /api/book.php hit the
   UNIQUE(schedule_id, seat_no) firewall. This pulls the real occupancy from
   /api/seats.php and overlays it, so the map matches the database.

   Cached per route+date for TTL_MS, de-duplicated while a request is in
   flight, and non-fatal: if the network is down the old local computation
   still runs, so the app keeps working offline.
================================================================ */
const SeatSrv = {
  TTL_MS: 20000,
  _snap: Object.create(null),      // 'r1|2026-08-16|sharing' -> {booked,locked,blocked,staff,female,units,layout,at}
  _inflight: Object.create(null),

  /* Cache key includes bookingMode — sharing and private have different seat
     namespaces AND geometry (sharing = 72 berths in 4+2 rows; private = 30
     berths in 2+1 rows), so they are cached separately and the toggle in
     renderSeats always paints the right layout. Callers that only care about
     occupancy (locked, blocked, female) may omit bookingMode and get the
     'sharing' bucket, which is the superset and thus always contains the
     seat IDs they will ask about. */
  key(routeId, date, bookingMode) {
    return String(routeId) + '|' + String(date) + '|' + (bookingMode || 'sharing');
  },

  /* The cached snapshot, or null when we have never fetched this one.
     A stale snapshot is still returned — showing 20-second-old truth beats
     falling back to this browser's private fiction while the refetch runs. */
  snap(routeId, date, bookingMode) { return this._snap[this.key(routeId, date, bookingMode)] || null; },

  /* Which departure the visitor is looking at: 0 = the daily bus, else the
     schedule id of an extra bus picked on the results board (Bus Calendar,
     5 Sep 2026). A snapshot fetched for another departure is never "fresh". */
  _sid() { return (typeof Flow !== 'undefined' && Flow.scheduleId) ? (parseInt(Flow.scheduleId, 10) || 0) : 0; },

  fresh(routeId, date, bookingMode) {
    const s = this.snap(routeId, date, bookingMode);
    return !!s && (Date.now() - s.at) < this.TTL_MS && (s.sid || 0) === this._sid();
  },

  /* Fetch (or reuse) the snapshot. Resolves true when the occupancy actually
     changed, so the caller only redraws when there is something to redraw —
     that is what keeps renderSeats() from looping on itself. */
  load(routeId, date, force, bookingMode) {
    bookingMode = bookingMode || 'sharing';
    const k = this.key(routeId, date, bookingMode);
    if (!routeId || !date) return Promise.resolve(false);
    if (!force && this.fresh(routeId, date, bookingMode)) return Promise.resolve(false);
    if (this._inflight[k]) return this._inflight[k];

    const prev = this._snap[k];
    const sid = this._sid();
    const body = sid
        ? { routeCode: routeId, date: date, bookingMode: bookingMode, scheduleId: sid }
        : { routeCode: routeId, date: date, bookingMode: bookingMode };
    /* Tell the server which seat version we hold: when nothing changed it
       answers "unchanged" in ~200 bytes instead of re-sending the map. */
    if (prev && prev.ver) body.ver = prev.ver;
    const p = shgApi.post('/seats.php', body)
      .then((d) => {
        if (d && d.unchanged) {
          if (prev) prev.at = Date.now();
          return false;
        }
        const next = {
          sid: sid,
          ver: d.ver || '',
          booked:  d.booked  || [],
          locked:  d.locked  || [],
          blocked: (d.blocked || []).concat(d.staff || []),
          female:  d.female  || [],
          units:   d.units   || {},
          /* Taken in the OTHER seat type (sharing <-> private) — a subset of
             booked, painted distinctly (SHG AI BRAIN 1.4, 10 Sep 2026). */
          crossMode: d.crossMode || [],
          /* Physical seat geometry — decks[i].rows[j].{left,right,aisle,cabinKey}.
             Feeds the unified renderer in 06-results.js so admin, customer and
             every future seat surface draw the SAME shape. Null on old servers
             (pre-Round 1 of the seat-unification rollout, 29 Aug 2026); the
             renderer falls back to its legacy math in that case. */
          layout:  d.layout  || null,
          at: Date.now()
        };
        this._snap[k] = next;
        return !prev || JSON.stringify([prev.booked, prev.locked, prev.blocked])
                     !== JSON.stringify([next.booked, next.locked, next.blocked]);
      })
      .catch(() => false)
      .then((changed) => { delete this._inflight[k]; return changed; });

    this._inflight[k] = p;
    return p;
  },

  /* Called the moment this visitor books, so the next map they see already
     has their own seats on it rather than the pre-purchase snapshot. Sweeps
     BOTH bookingMode variants for this route+date — an admin who tacked
     something onto the sharing map still needs the private map to reflect it. */
  invalidate(routeId, date) {
    const prefix = String(routeId) + '|' + String(date) + '|';
    Object.keys(this._snap).forEach(k => { if (k.indexOf(prefix) === 0) delete this._snap[k]; });
  }
};

/* ================================================================
   SERVER ROUTE CATALOGUE — which buses actually run, per the database.

   The results board used to render purely from the client catalogue
   (seedRoutes → DB.routes, ids 'r2'/'r7') while admin/routes.php edits the
   `routes` TABLE. They only agreed because both were written to match: a
   route added on the server never appeared to customers, and one
   deactivated there was still offered until /api/book.php refused it at
   the very end. This overlay makes /api/search.php the authority whenever
   it can be reached:

     · a route the server returns but the catalogue lacks is synthesised
       into DB.routes (runtime only — never persisted), so the whole
       downstream flow (seat map, checkout, booking) just works;
     · a catalogue route the server does NOT return for that from/to/date
       is dropped from the board;
     · live fields (times, fare, bus, seatsLeft) come from the server row.

   Offline the board still renders from the local catalogue — degraded,
   never blank. One request also replaces the per-bus seats.php burst the
   board used to fire, because search.php already reports seatsLeft.
================================================================ */
const SrvCatalog = {
  TTL_MS: 30000,
  _snap: Object.create(null),
  _inflight: Object.create(null),
  key(from, to, date) { return from + '|' + to + '|' + date; },
  snap(from, to, date) { return this._snap[this.key(from, to, date)] || null; },
  fresh(from, to, date) {
    const s = this.snap(from, to, date);
    return !!s && (Date.now() - s.at) < this.TTL_MS;
  },

  load(from, to, date, force) {
    const k = this.key(from, to, date);
    if (!from || !to || !date) return Promise.resolve(false);
    if (!force && this.fresh(from, to, date)) return Promise.resolve(false);
    if (this._inflight[k]) return this._inflight[k];

    const prev = this._snap[k];
    // Agent late-booking: send the known agent code so a valid agent's search
    // surfaces a bus within 24h of departure. Empty for ordinary customers →
    // server behaves exactly as before.
    const _ac = (typeof agentSearchCode === 'function') ? agentSearchCode() : '';
    const p = shgApi.post('/search.php', _ac ? { from: from, to: to, date: date, agentCode: _ac } : { from: from, to: to, date: date })
      .then((d) => {
        const byCode = {};
        const extras = [];
        (d.results || []).forEach(res => {
          /* Bus Calendar (5 Sep 2026): an EXTRA bus on the same date is its
             own card (own schedule id, own time, own seats). It must never
             overwrite the daily bus's catalogue entry, so it is kept aside. */
          if (res.extraBus) { extras.push(res); return; }
          byCode[res.routeCode] = res; this._absorb(res);
        });
        this._snap[k] = { byCode: byCode, extras: extras, at: Date.now(),
          // Task 1 (Surat 24×7): the server rolled a spent departure forward to
          // the next available date. Keep the flag + that date so renderResults
          // can show the banner and the select handler can adopt it as Flow.date.
          nextAvailable: !!d.nextAvailable, nextDate: d.date || date,
          requestedDate: d.requestedDate || date, nextStop: d.nextAvailableStop || '',
          // Master switch (4 Sep 2026): the office paused the daily service.
          serviceOff: !!d.serviceOff, offNote: d.note || '' };
        const sig = (s) => s ? Object.keys(s.byCode).sort().map(c =>
          c + ':' + s.byCode[c].seatsLeft + ':' + s.byCode[c].fare + ':' + (s.byCode[c].fareOverride || 0)).join(',')
          + '|' + (s.extras || []).map(x => x.scheduleId + ':' + x.seatsLeft + ':' + x.depTime + ':' + (x.fareOverride || 0)).join(',') : '';
        return sig(prev) !== sig(this._snap[k]);
      })
      .catch(() => false)
      .then((changed) => { delete this._inflight[k]; return changed; });

    this._inflight[k] = p;
    return p;
  },

  /* Fold one server result into the runtime catalogue so routeById() finds
     it everywhere downstream. Deliberately never calls persist() — the
     shared kv catalogue stays whatever the admin last saved. */
  _absorb(res) {
    /* api/search.php now returns each stop as a canonical single-string
       label ("Name · Landmark @ HH:MM [lat,lng]") — pass those through
       unchanged. The object form kept here for back-compat with anything
       still shipping raw route_stops rows (an older admin API, a stale
       response replayed from cache). */
    const stopLine = (s) => {
      if (typeof s === 'string') return s;
      return (s.stop_name || '')
        + (s.landmark ? ' · ' + s.landmark : '')
        + (s.stop_time ? ' @ ' + String(s.stop_time).slice(0, 5) : '')
        + (s.latitude != null && s.longitude != null ? ' [' + s.latitude + ',' + s.longitude + ']' : '');
    };
    let r = routeById(res.routeCode);
    if (!r) {
      r = { id: res.routeCode, pathId: 'via_bahraich', amenities: [], _srv: true };
      DB.routes.push(r);
    }
    r.from = res.from; r.to = res.to; r.active = true;
    r.type = res.coachType === 'sleeper' ? 'sleeper' : 'seater';
    if (res.busName) r.busName = res.busName;
    if (res.busNumber) r.busNo = res.busNumber;
    if (res.depTime) r.depTime = res.depTime;
    /* Assigned UNCONDITIONALLY, unlike the fields above: the server must be
       able to CLEAR these, not just change them. Rupaidiha is the last point
       now, so the old Nepalgunj arrival ("00:39 +2 day, 31h 54m") was blanked
       server-side — but `if (res.x) r.x = res.x` silently kept the stale KV
       value, and the board went on promising a time the bus will never keep. */
    r.arrTime  = res.arrTime  || '';
    r.duration = res.duration || '';
    if (res.dayOffset != null) r.dayOffset = res.dayOffset;
    if (res.fare) r.fare = res.fare;
    if (res.crewName) r.crewName = res.crewName;
    if (res.crewPhone) r.crewPhone = res.crewPhone;
    if (res.boarding && res.boarding.length) r.boarding = res.boarding.map(stopLine);
    if (res.drop && res.drop.length) r.drop = res.drop.map(stopLine);
    /* Propagate boardingIdx/dropIdx so the seat-map dropdown pre-selects
       the stop matching the customer's searched origin. Without this the
       dropdown always fell back to index 0 (Surat). */
    if (res.boardingIdx != null) r.boardingIdx = res.boardingIdx;
    if (res.dropIdx != null) r.dropIdx = res.dropIdx;
    if (!r.busName) r.busName = 'SHG Bus';
    if (!r.busNo) r.busNo = '';
    if (!r.duration) r.duration = '';
  }
};

function lockedSeatsFor(routeId, date) {
  const local = activeLocks().filter(l => l.routeId === routeId && l.date === date && l.sid !== SID).map(l => l.seat);
  // Read the snapshot for the CURRENT booking mode, not the default 'sharing'
  // bucket. Since blocked/staff are now resolved per mode (private L5/L6 ->
  // cabin L3), the sharing bucket's blocked pair (L5/L6) is NOT valid in the
  // private namespace — using it wrongly held a real private L5/L6.
  const mode = (typeof Flow !== 'undefined' && Flow.bookingType) ? Flow.bookingType : undefined;
  const srv = SeatSrv.snap(routeId, date, mode) || SeatSrv.snap(routeId, date);
  if (!srv) return local;
  // Union, never intersection: a seat held in either place is not sellable.
  // The server already omits THIS visitor's own holds, so their own picks
  // stay selectable.
  return srv.locked.concat(srv.blocked).concat(local);
}
/* ================================================================
   SERVER-BACKED SEAT HOLDS

   A hold used to live ONLY in DB.locks — localStorage, keyed by this
   browser's SID. That made it a private fiction: two customers could
   each "hold" L4 on their own phone, both fill in passenger details,
   and only the loser found out at the final submit, when the database's
   UNIQUE(schedule_id, seat_no) refused the second claim. The seat was
   never double-sold — the loss just happened as late as it possibly
   could, after the customer had already done all the work.

   seat_locks and /api/lock.php were built for exactly this and were
   called by nothing. This wires them together:

     · DB.locks stays as an OPTIMISTIC MIRROR, so a tap paints instantly
       and the countdown keeps running through a dropped connection;
     · the SERVER is the authority — a seat it refuses is rolled back out
       of the selection and the map repainted from its snapshot;
     · the pill counts down the server's own expiresAt, never a local
       guess, so it cannot promise time the database will not honour.

   Taps are coalesced: picking four berths quickly sends one request,
   not four. Every call is best-effort — offline the local mirror still
   drives the UI, and /api/book.php stays the final arbiter regardless.
================================================================ */
const SeatLock = {
  FLUSH_MS: 150,
  _rel: [],           // seats deselected since the last flush
  _timer: null,
  _inflight: false,
  serverExpiry: 0,    // ms epoch, taken from the server's expiresAt

  /* Grouped by route + date + BOOKING MODE (4 Sep 2026): a seat label only
     means something together with the mode it was picked in (private L4 is
     the sharing beds L7+L8), so every hold and every release carries the
     mode it was created with instead of whatever Flow.bookingType is now. */
  /* schId (5 Sep 2026) = the extra bus a hold belongs to (0 = the daily
     bus), so a hold on "bus 2" is never sent as a hold on bus 1. */
  _key(routeId, date, mode, schId) { return String(routeId) + '|' + String(date) + '|' + (mode || '') + '|' + (schId || 0); },

  /* This visitor's live mirror rows, grouped by route+date+mode+departure. */
  _mine() {
    const out = Object.create(null);
    activeLocks().filter(l => l.sid === SID).forEach(l => {
      const k = this._key(l.routeId, l.date, l.mode, l.schId);
      if (!out[k]) out[k] = { routeId: l.routeId, date: l.date, mode: l.mode || null, schId: l.schId || 0, seats: [] };
      out[k].seats.push(l.seat);
    });
    return out;
  },

  schedule() {
    clearTimeout(this._timer);
    this._timer = setTimeout(() => this.flush(), this.FLUSH_MS);
  },

  flush() {
    if (this._inflight) { this.schedule(); return; }

    const rel  = this._rel.splice(0);
    const mine = this._mine();
    const jobs = [];

    /* Releases go first: a seat toggled off has to be free for the next
       customer before we re-assert whatever is still selected. */
    const relByKey = Object.create(null);
    rel.forEach(r => {
      const k = this._key(r.routeId, r.date, r.mode, r.schId);
      if (!relByKey[k]) relByKey[k] = { routeId: r.routeId, date: r.date, mode: r.mode || null, schId: r.schId || 0, seats: [] };
      relByKey[k].seats.push(r.seat);
    });
    Object.keys(relByKey).forEach(k => {
      const j = relByKey[k];
      jobs.push(shgApi.post('/lock.php', {
        action: 'release', routeCode: j.routeId, date: j.date, seats: j.seats, bookingMode: j.mode, scheduleId: j.schId || undefined
      }).catch(() => null));
    });

    Object.keys(mine).forEach(k => {
      const j = mine[k];
      jobs.push(
        shgApi.post('/lock.php', { action: 'hold', routeCode: j.routeId, date: j.date, seats: j.seats, bookingMode: j.mode, scheduleId: j.schId || undefined })
          .then(d => { if (d && d.expiresAt) this._accept(d); })
          .catch(err => this._refused(j, err))
      );
    });

    if (!jobs.length) return;
    this._inflight = true;
    Promise.all(jobs).then(() => { this._inflight = false; });
  },

  /* Hold granted — adopt the SERVER's clock, never our own estimate. */
  _accept(d) {
    const exp = Date.parse(String(d.expiresAt).replace(' ', 'T'));
    if (!isFinite(exp)) return;
    this.serverExpiry = exp;
    (DB.locks || []).forEach(l => { if (l.sid === SID) l.expires = exp; });
    persist('locks');
  },

  /* Hold refused — somebody else reached those seats first. Take them
     back out of the selection and repaint from the database's truth.
     An error carrying no `failed` list is a network blip, not a
     conflict, so the selection is left alone. */
  _refused(job, err) {
    const failed = (err && err.data && err.data.failed) || [];
    if (!failed.length) return;

    failed.forEach(seat => {
      const ix = (Flow.seats || []).indexOf(seat);
      if (ix >= 0) Flow.seats.splice(ix, 1);
      DB.locks = (DB.locks || []).filter(
        l => !(l.sid === SID && l.routeId === job.routeId && l.date === job.date && l.seat === seat)
      );
      const b = document.querySelector('#view-seats .seat[data-id="' + seat + '"]');
      if (b) { b.classList.remove('sel'); b.disabled = true; }
    });
    persist('locks');

    toast('⚠️ ' + seatLabelJoin(failed, Flow.route && Flow.route.type, Flow.bookingType) + (failed.length > 1 ? ' were' : ' was')
        + ' just taken by another customer. Please pick again.');

    SeatSrv.invalidate(job.routeId, job.date);
    SeatSrv.load(job.routeId, job.date, true).then(() => {
      if ((location.hash || '') === '#/seats' && typeof renderSeats === 'function') renderSeats(true);
      if (typeof updateSeatSummary === 'function') updateSeatSummary();
    });
  },

  releaseAll() {
    this.serverExpiry = 0;
    shgApi.post('/lock.php', { action: 'releaseAll' }).catch(() => null);
  }
};

function lockSeat(routeId, date, seat) {
  const mode = (typeof Flow !== 'undefined' && Flow.bookingType) ? Flow.bookingType : null;
  DB.locks.push({ routeId: routeId, date: date, seat: seat, sid: SID, mode: mode, schId: (typeof Flow !== 'undefined' && Flow.scheduleId) ? (parseInt(Flow.scheduleId, 10) || 0) : 0, expires: Date.now() + CONFIG.booking.seatHoldMinutes * 60000 });
  persist('locks');
  SeatLock.schedule();
}
function unlockSeat(routeId, date, seat) {
  /* Release under the mode the hold was TAKEN in, not the current one. */
  const row = DB.locks.find(l => l.sid === SID && l.routeId === routeId && l.date === date && l.seat === seat);
  const mode = row && row.mode ? row.mode : ((typeof Flow !== 'undefined' && Flow.bookingType) ? Flow.bookingType : null);
  DB.locks = DB.locks.filter(l => !(l.sid === SID && l.routeId === routeId && l.date === date && l.seat === seat));
  persist('locks');
  SeatLock._rel.push({ routeId: routeId, date: date, seat: seat, mode: mode, schId: (typeof Flow !== 'undefined' && Flow.scheduleId) ? (parseInt(Flow.scheduleId, 10) || 0) : 0 });
  SeatLock.schedule();
}
function releaseMyLocks() {
  if (!(DB.locks || []).some(l => l.sid === SID)) return;
  DB.locks = DB.locks.filter(l => l.sid !== SID);
  persist('locks');
  SeatLock.releaseAll();
}
function myHoldExpiry() {
  const mine = activeLocks().filter(l => l.sid === SID);
  if (!mine.length) return 0;
  /* The server's clock wins whenever we have one: it is what actually
     governs whether the seat is still ours. */
  if (SeatLock.serverExpiry) return SeatLock.serverExpiry;
  return Math.min.apply(null, mine.map(l => l.expires));
}

/* ================================================================
   LIVE SEAT MAP

   Section 8: a seat another customer buys while this map is open must
   go red without a manual refresh. SeatSrv already caches the server
   snapshot; nothing ever re-fetched it, so an open map could sit on
   20-second-old truth indefinitely.

   Polls only while the seat map is actually the visible view AND the
   tab is in the foreground — a backgrounded phone must not keep waking
   the radio, and a hidden tab has nothing to repaint. Repainting is
   conditional on the snapshot having genuinely CHANGED (SeatSrv.load
   resolves true only then), so a quiet bus costs one small request and
   zero DOM work.
================================================================ */
const SeatPoll = {
  EVERY_MS: 8000,
  /* While the live stream is connected the poll is only a safety net. */
  SLOW_MS: 30000,
  _t: null,
  _ctx: null,
  _es: null,

  start(routeId, date) {
    this.stop();
    if (!routeId || !date) return;
    this._ctx = { routeId: routeId, date: date };
    this._t = setInterval(() => this._tick(), this.EVERY_MS);
    this._listen();
  },

  stop() { clearInterval(this._t); this._t = null; this._ctx = null; this._closeStream(); },

  /* Live seat events (24 Sep 2026): when the office switches seat_events_on,
     the server pushes "seats" the second a booking or hold lands on this
     departure and the map refreshes at once — customer, counter and office
     agree within about a second. Off, or on a browser without EventSource,
     the 8 s poll carries on exactly as before. */
  _listen() {
    if (!this._ctx || this._es || document.hidden) return;
    if (!window.EventSource || !seatEventsOn()) return;
    const c = this._ctx;
    const sid = SeatSrv._sid();
    const qs = 'routeCode=' + encodeURIComponent(c.routeId) + '&date=' + encodeURIComponent(c.date)
             + (sid ? '&scheduleId=' + encodeURIComponent(sid) : '');
    let es;
    try { es = new EventSource('/api/seat-events.php?' + qs); } catch (e) { return; }
    this._es = es;
    es.addEventListener('seats', () => this._tick());
    es.addEventListener('bye', () => { /* the server ended its turn; the browser reconnects */ });
    es.onopen = () => {
      clearInterval(this._t);
      this._t = setInterval(() => this._tick(), this.SLOW_MS);
    };
    es.onerror = () => {
      if (es.readyState === 2) {               // CLOSED for good (404 = switched off, 429)
        this._closeStream();
        if (this._ctx) { clearInterval(this._t); this._t = setInterval(() => this._tick(), this.EVERY_MS); }
      }
    };
  },

  _closeStream() {
    if (this._es) { try { this._es.close(); } catch (e) { /* already closed */ } this._es = null; }
  },

  _tick() {
    if (!this._ctx) return this.stop();
    if (document.hidden) return;                       // asleep in a background tab
    if ((location.hash || '') !== '#/seats') return this.stop();

    const c = this._ctx;
    /* Poll the snapshot of the mode being SHOWN (4 Sep 2026). Private labels
       (L1..L18) used to be compared against the sharing bucket (L1..L36), so
       a private picker got "just booked by another customer" for the wrong
       berth and its own bucket never refreshed inside the 20 s TTL. */
    const mode = (typeof Flow !== 'undefined' && Flow.bookingType) ? Flow.bookingType : undefined;
    SeatSrv.load(c.routeId, c.date, true, mode).then(changed => {
      if (!changed) return;
      /* Somebody else's booking landed. Repaint, and if it took a seat
         this visitor had selected, say so rather than silently dropping
         it from their basket. */
      const snap = SeatSrv.snap(c.routeId, c.date, mode) || SeatSrv.snap(c.routeId, c.date);
      const gone = snap
        ? (Flow.seats || []).filter(s => snap.booked.indexOf(s) >= 0 || snap.blocked.indexOf(s) >= 0)
        : [];

      if (gone.length) {
        gone.forEach(s => { const ix = Flow.seats.indexOf(s); if (ix >= 0) Flow.seats.splice(ix, 1); });
        toast('⚠️ ' + gone.join(', ') + (gone.length > 1 ? ' were' : ' was')
            + ' just booked by another customer.');
      }
      if (typeof renderSeats === 'function') renderSeats(true);
    });
  }
};
document.addEventListener('visibilitychange', () => {
  /* Coming back to the tab, refresh at once rather than waiting out the
     interval — the map on screen is exactly as old as the time away. A
     hidden tab drops its live stream (a phone in a pocket must not hold a
     server worker) and picks it up again on return. */
  if (document.hidden) { SeatPoll._closeStream(); return; }
  if (SeatPoll._ctx) { SeatPoll._tick(); SeatPoll._listen(); }
});

/** Has the office switched on live seat events? (public setting, shipped in SHG_BOOT) */
function seatEventsOn() {
  try {
    const v = window.SHG_BOOT && window.SHG_BOOT.settings && window.SHG_BOOT.settings.seat_events_on;
    return v === true || v === 1 || v === '1';
  } catch (e) { return false; }
}

/* ================================================================
   LIVE TICKET STATUS (17 Sep 2026)

   A passenger staring at "Waiting for verification" saw nothing when
   staff approved the payment: the only server re-read was the router's
   syncBookings on a hash change, so the screen moved only after they
   navigated away and back. Modelled on SeatPoll: polls /api/track.php
   (via syncBookings, PNR + phone) every 20 s — well inside track.php's
   40/60 s rate limit — ONLY while a PENDING ticket is the visible view
   (#/ticket/<id>) and the tab is in the foreground; repaints only when
   something actually changed; stops itself the moment the status leaves
   'pending', the hash moves, or the ticket is gone. Started/stopped by
   renderStatus (07-checkout.js), which knows the status it just drew.
================================================================ */
const TicketPoll = {
  EVERY_MS: 20000,
  _t: null,
  _id: '',

  start(id) {
    if (!id) return this.stop();
    if (this._id === id && (this._t || document.hidden)) return;   // already watching (or paused for) this ticket
    this.stop();
    this._id = id;
    if (document.hidden) return;                                   // no timer while hidden — resume() arms it
    this._t = setInterval(() => this._tick(), this.EVERY_MS);
  },

  stop() { clearInterval(this._t); this._t = null; this._id = ''; },

  /* A hidden tab has NO timer at all (not merely skipped ticks): the radio
     sleeps; coming back arms it again and asks at once. */
  pause() { clearInterval(this._t); this._t = null; },
  resume() {
    if (!this._id || this._t) return;
    this._t = setInterval(() => this._tick(), this.EVERY_MS);
    this._tick();
  },

  _tick() {
    const id = this._id;
    if (!id) return this.stop();
    if ((location.hash || '') !== '#/ticket/' + id) return this.stop();   // view left
    if (document.hidden) return;                                          // belt and braces (pause() already cleared the timer)
    const b = (DB.bookings || []).find(x => x && x.id === id);
    if (!b || b.status !== 'pending' || typeof syncBookings !== 'function') return this.stop();
    syncBookings([id]).then((changed) => {
      if ((location.hash || '') !== '#/ticket/' + id) return this.stop();
      if (!changed) return;
      /* Staff moved it (approved / rejected / cancelled): redraw — the
         confirmed render brings its own confetti + auto-PNG — and let
         renderStatus decide whether there is still anything to watch. */
      if (typeof renderStatus === 'function') renderStatus(id);
      const nb = (DB.bookings || []).find(x => x && x.id === id);
      if (!nb || nb.status !== 'pending') this.stop();
    }).catch(() => {});
  }
};
window.addEventListener('hashchange', () => {
  /* router() runs first (registered above) and re-arms the poll when the
     new hash is still this pending ticket; anything else stops it. */
  if (TicketPoll._id && (location.hash || '') !== '#/ticket/' + TicketPoll._id) TicketPoll.stop();
});
document.addEventListener('visibilitychange', () => {
  if (document.hidden) TicketPoll.pause(); else TicketPoll.resume();
});
let holdInt = null;
function startHoldTicker() {
  clearInterval(holdInt);
  holdInt = setInterval(updateHoldPills, 1000);
  updateHoldPills();
}
function updateHoldPills() {
  const pills = $$('.hold-pill');
  const exp = myHoldExpiry();
  if (!exp) { pills.forEach(p => p.classList.add('hide')); return; }
  const ms = exp - Date.now();
  if (ms <= 0) {
    releaseMyLocks();
    pills.forEach(p => p.classList.add('hide'));
    toast(t('tHoldExp'));
    const h = location.hash || '#/';
    if (h === '#/checkout') { Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; location.hash = '#/'; }
    else if (h === '#/seats') { Flow.seats = []; renderSeats(true); }
    return;
  }
  const mm = String(Math.floor(ms / 60000)).padStart(2, '0');
  const ss = String(Math.floor(ms / 1000) % 60).padStart(2, '0');
  pills.forEach(p => { p.classList.remove('hide'); p.innerHTML = '⏳ ' + tf('holdMsg', { t: mm + ':' + ss }); });
  const cd = $('#payCountdown');
  /* At a staff counter the sale confirms the moment it is submitted — there
     is no payment to "complete". The same countdown is still the seat hold,
     so say that instead of a deadline the desk cannot act on (6 Sep 2026). */
  const staffHold = !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
  if (cd) cd.textContent = staffHold ? '⏱️ Seat hold ' + mm + ':' + ss + ' · confirm the sale before it lapses'
                                     : '⏱️ Complete payment within ' + mm + ':' + ss;
}
/* Cross-tab sync: another tab booking / locking seats updates us live. */
if (store.local && !store.remote) {
  window.addEventListener('storage', async (e) => {
    if (!e.key || e.key.indexOf('shg:') !== 0) return;
    const k = e.key.slice(4);
    if (['routes', 'bookings', 'settings', 'messages', 'locks', 'waitlist',
         'users', 'commissions', 'payoutRequests', 'auditLog', 'commissionRules',
         'delays', 'tripExpenses', 'notifications'].indexOf(k) < 0) return;
    DB[k] = await store.get(e.key, DB[k]);
    if (k === 'notifications' && typeof updateNotifBadge === 'function') updateNotifBadge();
    const h = location.hash || '#/';
    if (h === '#/seats' && (k === 'bookings' || k === 'locks')) renderSeats(true);
    if (h === '#/results' && (k === 'bookings' || k === 'locks' || k === 'routes')) renderResults(true);
    /* #/admin and #/shg-ctrl now redirect to /admin/ — no client-side
       agent/admin panels on the customer surface (§2 purge, shg-v56). */
  });
}

/* ================================================================
   [JS] 6x. WHATSAPP SHEET + DIGITAL VISITING CARD (17 Sep 2026)
   The green FAB opens a chooser instead of one hardcoded chat: "book on
   WhatsApp" (message pre-filled from the search card), the director and
   every office printed on the visiting card (India + Nepal). The FAB keeps
   its wa.me href as the no-JS fallback. The search card's own WhatsApp
   button uses the same composer. The visiting-card section shares the card
   picture itself through the OS share sheet (WhatsApp gets the image) or a
   wa.me text link where files cannot be shared.
================================================================ */
(function () {
  function officeNum() {
    var settings = (window.SHG_BOOT && window.SHG_BOOT.settings) || {};
    var n = String(settings.whatsapp_booking_number || settings.company_whatsapp || settings.company_phone || '').replace(/\D/g, '').replace(/^00/, '');
    if (n.length === 10) n = '91' + n;
    return /^[1-9]\d{7,14}$/.test(n) ? n : '';
  }
  function val(id) { var el = document.getElementById(id); return el && typeof el.value === 'string' ? el.value.trim() : ''; }
  function contactPhone() {
    var raw = val('waReqPhone').replace(/[०-९]/g, function (d) { return String(d.charCodeAt(0) - 0x0966); });
    if (!/^[+\d\s().-]+$/.test(raw)) return '';
    var number = raw.replace(/\D/g, '').replace(/^00/, '');
    var country = val('waReqCountry');
    if (number.length === 10) number = country + number;
    return /^(?:91[6-9]\d{9}|9779[678]\d{8})$/.test(number) && number.indexOf(country) === 0 ? '+' + number : '';
  }
  function composeBooking() {
    var lines = ['S Hari Global website request', reqType === 'booking' ? 'Book a ticket' : reqType === 'correction' ? 'Correct my ticket' : 'Help request',
      'Name: ' + val('waReqName'), 'Mobile: ' + contactPhone()];
    if (reqType === 'booking') {
      var back = val('waReqDirection') === 'toIndia';
      lines.push('Date: ' + val('waReqDate'), 'Direction: ' + val('waReqDirection'),
        'Boarding: ' + (back ? 'Rupaidiha' : val('waReqPoint')), 'Drop: ' + (back ? val('waReqPoint') : 'Rupaidiha'), val('waReqPax') + ' seats');
    } else if (reqType === 'correction') lines.push('PNR: ' + val('waReqPnr').toUpperCase());
    if (val('waReqNote')) lines.push((reqType === 'correction' ? 'Requested correction: ' : 'Note: ') + val('waReqNote'));
    return lines.join('\n');
  }
  function waUrl(num, text) { return 'https://wa.me/' + num + '?text=' + encodeURIComponent(text); }
  var sheet = document.getElementById('waSheet');
  var lastFocus = null;
  function openSheet() {
    if (!sheet) return false;
    lastFocus = document.activeElement;
    var bk = document.getElementById('waBookLink');
    if (bk) bk.href = officeNum() ? waUrl(officeNum(), '') : '#';
    fillReq();
    sheet.hidden = false;
    document.body.classList.add('wa-open');
    try { sheet.querySelector('.wa-sheet-x').focus({ preventScroll: true }); } catch (e) {}
    return true;
  }
  function closeSheet() {
    if (!sheet) return;
    sheet.hidden = true; document.body.classList.remove('wa-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  }
  var fab = document.getElementById('waFab');
  if (fab && sheet) fab.addEventListener('click', function (e) { if (openSheet()) e.preventDefault(); });
  if (sheet) {
    sheet.addEventListener('click', function (e) {
      var tgt = e.target;
      if (tgt.closest('#waSheetBg') || tgt.closest('#waSheetClose')) { closeSheet(); return; }
      if (!e.defaultPrevented && (tgt.closest('a.wa-row') || tgt.closest('.wa-pin'))) setTimeout(closeSheet, 150);
    });
    document.addEventListener('keydown', function (e) {
      if (sheet.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); closeSheet(); }
      if (e.key !== 'Tab') return;
      var focusable = Array.from(sheet.querySelectorAll('button:not([disabled]),a[href],input,select,textarea')).filter(function (el) { return el.getClientRects().length; });
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }
  /* One-tap request (owner, 19 Sep 2026): the server's WhatsApp sender delivers it
     to the office (api/wa-request.php); the wa.me row below stays as the fallback. */
  var req = document.getElementById('waReq');
  var reqType = 'booking';
  function tripInfo() {
    var back = false, town = '';
    try { back = document.getElementById('dirBack').classList.contains('on'); } catch (e) {}
    try { var ps = document.getElementById('pointSel'); town = (ps && ps.selectedIndex >= 0 && ps.options[ps.selectedIndex]) ? ps.options[ps.selectedIndex].text.trim() : ''; } catch (e) {}
    return { back: back, date: val('dateInput'), point: town };
  }
  function setReqType(tp) {
    reqType = ['help', 'correction'].indexOf(tp) >= 0 ? tp : 'booking';
    if (!req) return;
    req.querySelectorAll('[data-wareq]').forEach(function (b) {
      var active = b.getAttribute('data-wareq') === reqType;
      b.classList.toggle('on', active); b.setAttribute('aria-pressed', String(active));
    });
    document.getElementById('waReqJourney').hidden = reqType !== 'booking';
    document.getElementById('waReqPaxRow').hidden = reqType !== 'booking';
    document.getElementById('waReqPnrRow').hidden = reqType !== 'correction';
    var label = document.getElementById('waReqNoteLabel');
    label.setAttribute('data-i18n', reqType === 'correction' ? 'waReqFixNote' : reqType === 'help' ? 'waReqHelpPh' : 'waReqNotePh');
    label.textContent = t(label.getAttribute('data-i18n'));
    say('', false);
  }
  function say(text, bad) {
    var msg = document.getElementById('waReqMsg');
    if (msg) { msg.textContent = text; msg.classList.toggle('bad', !!bad); msg.hidden = !text; }
  }
  function pointLabel() {
    var label = document.getElementById('waReqPointLabel');
    var key = val('waReqDirection') === 'toIndia' ? 'sDrop' : 'sBoard';
    label.setAttribute('data-i18n', key); label.textContent = t(key);
  }
  function fillReq() {
    if (!req) return;
    var tr = tripInfo(), date = document.getElementById('waReqDate'), sourceDate = document.getElementById('dateInput');
    date.min = sourceDate && sourceDate.min || todayISO();
    date.max = sourceDate && sourceDate.max || '';
    if (!date.value || date.value < date.min) date.value = tr.date || date.min;
    if (!req.dataset.tripFilled) {
      document.getElementById('waReqDirection').value = tr.back ? 'toIndia' : 'toNepal';
      var point = document.getElementById('waReqPoint'), source = document.getElementById('pointSel');
      var towns = source ? Array.from(source.options).filter(function (o) { return o.value && !o.disabled; }).map(function (o) { return o.text.trim(); }) : [];
      if (!towns.length && typeof CONFIG !== 'undefined') towns = CONFIG.mainPoints.india;
      point.textContent = '';
      towns.forEach(function (town) { var o = document.createElement('option'); o.value = town; o.textContent = town; point.appendChild(o); });
      if (towns.indexOf(tr.point) >= 0) point.value = tr.point;
      req.dataset.tripFilled = '1';
      var pax = document.getElementById('waReqPax');
      var cap = typeof CONFIG !== 'undefined' ? CONFIG.booking.maxSeats : 6;
      pax.textContent = '';
      for (var i = 1; i <= cap; i++) { var option = document.createElement('option'); option.value = String(i); option.textContent = String(i); pax.appendChild(option); }
    }
    pointLabel();
    var n = document.getElementById('waReqName'), p = document.getElementById('waReqPhone');
    var me = (typeof USER !== 'undefined' && USER) ? USER : null;
    if (n && !n.value) n.value = val('qtName') || (me && me.name) || '';
    if (p && !p.value) {
      p.value = val('qtPhone') || (me && me.phone) || '';
      var digits = p.value.replace(/\D/g, '').replace(/^00/, '');
      if ((digits.length === 13 && digits.indexOf('977') === 0) || (me && me.country === 'NP')) document.getElementById('waReqCountry').value = '977';
    }
    say('', false);
  }
  function validateRequest() {
    req.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    function fail(id, key) {
      say(t(key), true); var el = document.getElementById(id); el.setAttribute('aria-invalid', 'true'); el.focus(); return false;
    }
    if (val('waReqName').length < 2) return fail('waReqName', 'waReqNeed');
    if (!contactPhone()) return fail('waReqPhone', 'waReqPhoneError');
    if (reqType === 'booking') {
      var date = document.getElementById('waReqDate');
      if (!date.value || !date.checkValidity()) return fail('waReqDate', 'waReqDateError');
      if (!val('waReqPoint')) return fail('waReqPoint', 'waReqPointError');
    }
    if (reqType === 'correction' && !/^[A-Z0-9-]{5,40}$/i.test(val('waReqPnr'))) return fail('waReqPnr', 'waReqPnrError');
    if (reqType !== 'booking' && !val('waReqNote')) return fail('waReqNote', reqType === 'correction' ? 'waReqFixNote' : 'waReqNeedNote');
    return true;
  }
  if (req) {
    req.addEventListener('click', function (e) { var b = e.target.closest('[data-wareq]'); if (b) setReqType(b.getAttribute('data-wareq')); });
    document.getElementById('waReqDirection').addEventListener('change', pointLabel);
    document.getElementById('waBookLink').addEventListener('click', function (e) {
      if (!validateRequest()) { e.preventDefault(); return; }
      if (!officeNum()) { e.preventDefault(); say(t('waReqUnavailable'), true); return; }
      this.href = waUrl(officeNum(), composeBooking());
    });
    req.addEventListener('submit', function (e) {
      e.preventDefault();
      var go = document.getElementById('waReqGo');
      if ((go && go.disabled) || !validateRequest()) return;
      var note = val('waReqNote');
      if (reqType === 'correction') note = 'Correct my ticket. PNR: ' + val('waReqPnr').toUpperCase() + '\n' + note;
      var body = { type: reqType === 'booking' ? 'booking' : 'help', name: val('waReqName'), phone: contactPhone(), note: note.slice(0, 300) };
      if (reqType === 'booking') { body.direction = val('waReqDirection') === 'toIndia' ? 'back' : 'go'; body.date = val('waReqDate'); body.point = val('waReqPoint'); body.pax = val('waReqPax') || '1'; }
      var label = go ? go.textContent : '';
      if (go) { go.disabled = true; go.textContent = t('waReqBusy'); }
      shgApi.post('/wa-request.php', body).then(function (d) {
        say(d && d.sent ? t('waReqSent') : t('waReqFail'), !(d && d.sent));
      }).catch(function (err) {
        say((err && err.status !== 422 && err.message) || t('waReqNeed'), true);
      }).then(function () { if (go) { go.disabled = false; go.textContent = label; } });
    });
  }
  var sb = document.getElementById('waBookBtn');
  if (sb) sb.addEventListener('click', function () {
    if (req) {
      delete req.dataset.tripFilled;
      document.getElementById('waReqDate').value = val('dateInput');
    }
    if (!req || !openSheet()) return;
    setReqType('booking');
    var n = document.getElementById('waReqName');
    if (n && !n.value) { try { n.focus({ preventScroll: true }); } catch (e) {} }
  });
  document.querySelectorAll('[data-wa-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      setReqType(button.getAttribute('data-wa-open')); openSheet();
      document.getElementById('waReqName').focus({ preventScroll: true });
    });
  });

  /* Digital visiting card — share the picture. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-vc-share]'); if (!b) return;
    var lang = b.getAttribute('data-vc-share') === 'ne' ? 'ne' : 'en';
    var file = '/assets/img/card-' + lang + '.jpg';
    var url = location.origin + file;
    var text = '🚌 S Hari Global Pvt Ltd — India ⇄ Nepal bus\n'
      + '📞 Mehsana +91 91048 01507 · Ahmedabad +91 91570 01507 · Surat +91 73593 01507\n'
      + '🇳🇵 Nepal: +977 986-6201375 · +977 984-8889950 · +977 984-8119600\n'
      + '🌐 https://shreehariglobal.in\n' + url;
    var fallback = function () { window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener'); };
    if (navigator.share && navigator.canShare) {
      fetch(file).then(function (r) { return r.blob(); }).then(function (blob) {
        var f = new File([blob], 'S-Hari-Global-card-' + lang + '.jpg', { type: 'image/jpeg' });
        if (navigator.canShare({ files: [f] })) return navigator.share({ files: [f], text: text, title: 'S Hari Global — visiting card' });
        return navigator.share({ text: text, url: url });
      }).catch(function (err) { if (!err || err.name !== 'AbortError') fallback(); });
    } else {
      fallback();
    }
  });
})();
