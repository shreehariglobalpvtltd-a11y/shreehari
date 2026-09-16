
/* ================================================================
   [JS] 14. COUNTER MODE — staff sell through the SAME app (3 Sep 2026)

   Owner rule: multi-seat booking must work identically for customer,
   agent and admin — same search, same seat map, same checkout, no
   separate logic per role. So instead of a second seat picker in the
   admin panel, a signed-in staff member who may sell simply opens this
   app (index.php?counter=1) and this file adds the counter details on
   top of the customer flow:

     • a "Counter mode" bar (who is selling, back to panel, sell another)
     • ?from=&to=&date= pre-fills the search (links from the panel)
     • the date input accepts ANY date for staff (6 Sep 2026; was: yesterday)
     • checkout: "Received at counter" chips (cash / UPI / eSewa / bank),
       a capped discount box and a note replace the customer's UPI-proof
       screen; the agent code is fixed to the seller's own code
     • /api/book.php gets counterPayment + discount; the server confirms
       the booking, marks the payment verified and issues the ticket
     • the ticket screen gets "Sell another" / "Open in panel" buttons

   Everything is a no-op unless window.SHG_BOOT.staff is present (index.php
   only adds it for a staff session), so the customer bundle is unchanged.
   Hooks wrap existing globals (renderCheckout, renderStatus, shgApi.post,
   pushNotif) rather than editing them — the customer files stay intact.
================================================================ */
(function () {
  'use strict';
  var STAFF = null;
  try { STAFF = (window.SHG_BOOT && window.SHG_BOOT.staff) || null; } catch (e) { STAFF = null; }
  if (!STAFF) return;

  var $q = function (sel, root) { return (root || document).querySelector(sel); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var CTR = { pay: 'cash', lastPnr: '', lastAdminUrl: '', lastDraft: '' };
  var PANEL = STAFF.panelUrl || '/admin/';
  var WHO = STAFF.code ? (STAFF.code + ' · ' + (STAFF.name || '')) : (STAFF.name || 'staff');
  var CAN = !!STAFF.canSell;

  /* Bulk booking (5 Sep 2026): a selling staff session lifts the per-booking
     seat cap to the staff cap the boot payload carries (default 20). Runs
     after 02-config's applyServerSeatCap, so the public cap never wins here;
     the server re-checks the same staff cap in BookingService::create(). */
  try {
    var staffCap = parseInt(STAFF.maxSeats, 10);
    if (CAN && staffCap > 0 && staffCap <= 40) CONFIG.booking.maxSeats = staffCap;
  } catch (e) {}

  /* ---- 1. Counter bar --------------------------------------------- */
  /* The running bundle version, read off this script's own ?v= — so the
     badge can never lie, and support can diagnose "stale tab" in seconds. */
  var CTR_VER = (function () {
    try {
      var s = document.querySelector('script[src*="14-counter"]');
      return (s && (s.src.match(/[?&]v=([\w-]+)/) || [])[1]) || '';
    } catch (e) { return ''; }
  })();

  function bar() {
    if ($q('#counterBar')) return;
    var el = document.createElement('div');
    el.id = 'counterBar';
    el.innerHTML = '<span class="cb-who">🧾 <b>Counter mode</b> · ' + (CAN ? 'selling as <b>' + esc(WHO) + '</b>' : esc(WHO) + ' · <i>view only — your role cannot sell</i>')
      + (CTR_VER ? ' <small style="opacity:.65">· v' + esc(CTR_VER) + '</small>' : '') + '</span>'
      + '<span class="cb-links">'
      /* ⚡ Quick Ticket (6 Sep 2026): the desk's fast lane — name + mobile
         only, the server picks the bus / pickup / seat / fare, confirms and
         sends the PNG on WhatsApp. Lives in the panel; linked from here so a
         counter tab never has to hunt for it. */
      + (CAN ? '<a href="/admin/quick-ticket.php" id="cbQuick" style="background:#F07C1F">🤖 QuickBot Ticket</a>' : '')
      + '<a href="#/" id="cbSellAnother">＋ Sell another</a><a href="' + esc(PANEL) + '">← Back to panel</a></span>';
    document.body.insertBefore(el, document.body.firstChild);
    var st = document.createElement('style');
    st.textContent = '#counterBar{position:sticky;top:0;z-index:1200;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;justify-content:space-between;padding:8px 14px;background:#0C3B2A;color:#fff;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.25)}'
      + '#counterBar a{color:#fff;font-weight:700;text-decoration:none;padding:6px 10px;border-radius:8px;background:rgba(255,255,255,.14);min-height:36px;display:inline-flex;align-items:center}'
      + '#counterBar .cb-links{display:flex;gap:8px;flex-wrap:wrap}'
      + '#ctrPanel{border:2px solid #178A50;border-radius:14px;padding:14px;margin:12px 0;background:#f3fbf6}'
      + ':root[data-theme="dark"] #ctrPanel{background:#0f2a1c}'
      + '#ctrPanel h4{margin:0 0 10px;font-size:15px}'
      + '.ctr-pays{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}'
      + '.ctr-pay{flex:1 1 120px;min-height:48px;border:2px solid var(--line,#dde3ee);border-radius:10px;background:var(--card,#fff);font-weight:700;font-size:14px;cursor:pointer;color:inherit}'
      + '.ctr-pay.on{background:#178A50;color:#fff;border-color:#178A50}'
      + '.ctr-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}'
      + '.ctr-row input,.ctr-row select{font-size:16px;padding:10px 12px;border:1px solid var(--line,#dde3ee);border-radius:9px;background:var(--card,#fff);color:inherit;min-width:0}'
      + '.ctr-row input[type=number]{width:120px}.ctr-row input[type=text]{flex:1 1 160px}'
      + '.ctr-note{font-size:12px;color:var(--muted,#6b7688);margin-top:8px}'
      + '#ctrActions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}'
      + '#ctrActions a{flex:1 1 140px;text-align:center}';
    document.head.appendChild(st);
    $q('#cbSellAnother').addEventListener('click', function () {
      try { if (typeof releaseMyLocks === 'function') releaseMyLocks(); } catch (e) {}
      try { Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; } catch (e) {}
    });
    updateWatch();
  }

  /* A counter tab stays open ALL DAY, so deploys never reached it until a
     manual reload — every fix looked "still broken" at the desk. Watch
     sw.js's ETag (served no-cache, changes on every deploy): when it moves,
     show a refresh chip in the bar. Checked every 10 min and whenever the
     tab comes back to the foreground. */
  var swTag = null;
  function checkVer() {
    fetch('/sw.js', { method: 'HEAD', cache: 'no-store' }).then(function (r) {
      var tag = r.headers.get('ETag') || r.headers.get('Last-Modified') || '';
      if (!tag) return;
      if (swTag === null) { swTag = tag; return; }
      if (tag !== swTag && !$q('#cbUpdate')) {
        var links = $q('#counterBar .cb-links');
        if (links) {
          var a = document.createElement('a');
          a.id = 'cbUpdate'; a.href = '#';
          a.style.background = '#F07C1F';
          a.textContent = '🔄 New version — tap to refresh';
          a.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
          links.insertBefore(a, links.firstChild);
        }
      }
    }).catch(function () {});
  }
  function updateWatch() {
    checkVer();
    setInterval(checkVer, 10 * 60 * 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) checkVer(); });
  }

  /* ---- 2. Date floor + search prefill ------------------------------ */
  function isoOffset(days) {
    var d = new Date(); d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function setSelect(sel, want) {
    if (!sel || !want) return false;
    var w = String(want).trim().toLowerCase();
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];
      if (String(o.value).trim().toLowerCase() === w || String(o.textContent).trim().toLowerCase() === w) { sel.value = o.value; return true; }
    }
    return false;
  }
  function prefill() {
    var di = $q('#dateInput');
    if (di && CAN) {
      /* Any date at the counter (6 Sep 2026). The old min of "yesterday" was
         the 24h departed-bus grace; a paper ticket from three days ago, or a
         group two months out, could not be entered at all. The server holds
         the only hard floor (the inaugural date) and still refuses a
         cancelled or OFF departure, so nothing here needs to guess. The
         30-day strip stays as the quick pick; any other date is typed here. */
      di.removeAttribute('min');
      di.removeAttribute('max');
    }
    var p = new URLSearchParams(location.search);
    if (!p.get('from') && !p.get('to') && !p.get('date')) return;
    // Deep link from the admin seat map (5 Sep 2026): &sid= names the exact
    // departure (0 / absent = daily bus) and &seat= a seat to pre-select, so
    // "book this bus" is one click with nothing to retype.
    var wantSid = parseInt(p.get('sid') || '0', 10) || 0;
    var wantSeat = String(p.get('seat') || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    var tries = 0;
    var timer = setInterval(function () {
      tries++;
      var fs = $q('#fromSel'), ts = $q('#toSel'), form = $q('#searchForm');
      if (!fs || !ts || !form || fs.options.length === 0) { if (tries > 40) clearInterval(timer); return; }
      clearInterval(timer);
      var okFrom = setSelect(fs, p.get('from')), okTo = setSelect(ts, p.get('to'));
      if (p.get('date') && di) { di.value = p.get('date'); try { di.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {} }
      if (okFrom && okTo && di && di.value) {
        try { form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); } catch (e) {}
        // Auto-select ONLY when the link explicitly named a departure or a
        // seat. Legacy from/to/date links (agent panel "Sell seats",
        // new-booking redirect) must keep stopping at the results list —
        // auto-entering the daily bus there would sell the wrong departure
        // on a day that also runs an extra bus.
        if (CAN && (wantSid > 0 || wantSeat !== '')) autoPick(wantSid, wantSeat);
      }
      // Clean the query so a reload does not re-run the search.
      try { history.replaceState(null, '', location.pathname + '?counter=1' + location.hash); } catch (e) {}
    }, 250);
  }

  /* After the auto-submitted search: click the result card the deep link
     named (matching data-sid for an extra bus, the plain daily card for
     sid=0), then — once the seat grid is up — tap the requested seat.
     Every step goes through the app's own click handlers, so locks, the
     pair rule and the summary all behave exactly as a manual tap.
     Extra-bus cards render LATE — the board paints daily cards from the
     local catalogue first and only adds extras once /api/search.php
     answers — so for sid>0 keep polling until the matching card appears
     rather than giving up at the first cards seen. */
  function autoPick(sid, seat) {
    var tries = 0;
    var timer = setInterval(function () {
      tries++; if (tries > 40) { clearInterval(timer); return; }   // ~10s, then leave the list up
      var sels = document.querySelectorAll('#resultsList [data-sel]');
      if (!sels.length) return;
      var btn = null;
      for (var i = 0; i < sels.length; i++) {
        var s = parseInt(sels[i].getAttribute('data-sid') || '0', 10) || 0;
        if (s === sid) { btn = sels[i]; break; }
      }
      if (!btn) return;                    // not rendered yet (or gone) — keep waiting
      clearInterval(timer);
      btn.click();
      if (!seat) return;
      var st = 0;
      var seatTimer = setInterval(function () {
        st++; if (st > 40) { clearInterval(seatTimer); return; }
        var cell = document.querySelector('.seat[data-id="' + seat + '"]');
        if (!cell) return;
        clearInterval(seatTimer);
        if (!cell.disabled && !cell.classList.contains('sel')) cell.click();
      }, 250);
    }, 250);
  }

  /* ---- 3. Checkout: counter payment + discount --------------------- */
  function decorateCheckout() {
    if (!CAN) return;

    /* A fresh form for every sale (6 Sep 2026). The checkout view is reused
       between sales and the contact / document boxes are not rebuilt, so the
       previous passenger's phone, email and ID rode into the next ticket —
       and its WhatsApp went to the wrong person. Blank them once per draft
       (a new draft id = a new sale); a draft the clerk is mid-way through is
       never touched. The passenger rows are rebuilt by renderCheckout itself,
       and 07-checkout's repeat-customer prefills are off for staff. */
    try {
      var draft = (typeof Flow !== 'undefined' && Flow && Flow.draftId) || '';
      if (draft && draft !== CTR.lastDraft) {
        CTR.lastDraft = draft;
        ['#cPhone', '#cEmail', '#idNum', '#ctrDiscVal', '#ctrNote'].forEach(function (id) { var el = $q(id); if (el) el.value = ''; });
        var wb = $q('#wbNote'); if (wb) wb.classList.add('hide');
        var pm = $q('#paxMemory'); if (pm) pm.remove();
        // and a live button — renderCheckout resets it too; this is the belt to that brace
        var sb = $q('#submitBookingBtn'); if (sb) { sb.disabled = false; sb.classList.remove('loading'); }
      }
    } catch (e) {}

    /* T&C tick (5 Sep 2026): the checkbox is the CUSTOMER's electronic
       signature — at the desk the customer is standing in front of the
       staff and the desk paperwork covers it. Left unticked it kept the
       "भुक्तानीमा जानुहोस्" button disabled and lit the checkbox red on
       every counter sale ("ticket hun lako xain"). Auto-accept it and
       swap the signature wording for an honest desk note. */
    var tnc = $q('#agreeTnc');
    if (tnc) {
      if (!tnc.checked) {
        tnc.checked = true;
        try { tnc.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
      }
      var tncBox = tnc.closest('.tnc-box');
      if (tncBox && !tncBox.hasAttribute('data-ctr')) {
        tncBox.setAttribute('data-ctr', '1');
        tncBox.style.display = 'none';
        var tncNote = document.createElement('div');
        tncNote.className = 'ctr-note';
        tncNote.textContent = '🧾 Counter sale — Terms & Conditions accepted at the desk · काउन्टरमा नियम स्वीकृत।';
        tncBox.parentNode.insertBefore(tncNote, tncBox);
      }
      var tncErr = $q('#tncErr'); if (tncErr) tncErr.style.display = 'none';
    }

    /* Phone is OPTIONAL at the desk (5 Sep 2026): say so on the field
       itself, lift the 10-char maxlength so a +977 Nepali number fits,
       and soften the error line. The validator already accepts blank /
       8-15 digits for a selling staff session. */
    var cp = $q('#cPhone');
    if (cp && !cp.hasAttribute('data-ctr')) {
      cp.setAttribute('data-ctr', '1');
      cp.maxLength = 15;
      cp.placeholder = 'optional · WhatsApp ticket ko lagi';
      var cpl = cp.closest('.field') && cp.closest('.field').querySelector('label');
      if (cpl) cpl.innerHTML = 'Mobile <small>(optional at counter · +977 pani milcha)</small>';
      var cpe = cp.closest('.field') && cp.closest('.field').querySelector('.err');
      if (cpe) cpe.textContent = 'Blank OK — or 8-15 digits (Indian / Nepali).';
    }

    var card = $q('#payCard'); if (!card) return;
    // Hide the customer's payment tabs, panels and proof uploader; the "cod"
    // path is the proof-free one, so drive the existing flow through it.
    try { if (typeof setPayMethod === 'function') setPayMethod('cod'); } catch (e) {}
    ['#payMethodUpi', '#payMethodEsewa', '#payMethodLink', '#payMethodCod', '#upiPanel', '#esewaPanel', '#linkPayPanel', '#codPanel', '#proofSection', '#ppCard']
      .forEach(function (id) { var el = $q(id); if (el) el.style.display = 'none'; });
    var tabsWrap = $q('#payMethodUpi') && $q('#payMethodUpi').parentElement; if (tabsWrap) tabsWrap.style.display = 'none';

    var btn = $q('#submitBookingBtn');
    if (btn) { btn.textContent = '🧾 Confirm counter sale →'; btn.removeAttribute('data-i18n'); }

    /* Belt and braces (5 Sep 2026): re-assert the proof-free 'cod' path and
       the auto-accepted T&C at the very moment of submit — a document-level
       CAPTURE listener runs before the app's own click handler, so no state
       drift in a long-open counter tab can resurrect the hidden UTR field
       or the signature checkbox as an invisible "highlighted field". */
    if (!document._ctrSubmitGuard) {
      document._ctrSubmitGuard = true;
      document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest || !e.target.closest('#submitBookingBtn')) return;
        try { if (typeof setPayMethod === 'function') setPayMethod('cod'); } catch (e2) {}
        try {
          var t2 = $q('#agreeTnc');
          if (t2 && !t2.checked) { t2.checked = true; t2.dispatchEvent(new Event('change', { bubbles: true })); }
        } catch (e2) {}
      }, true);
    }

    if (!$q('#ctrPanel')) {
      var maxPct = Number(STAFF.maxDiscountPct || 15);
      var el = document.createElement('div');
      el.id = 'ctrPanel';
      el.innerHTML = '<h4>🧾 Received at counter · काउन्टरमा लिएको</h4>'
        + '<div class="ctr-pays">'
        + '<button type="button" class="ctr-pay on" data-pay="cash">💵 Cash</button>'
        + '<button type="button" class="ctr-pay" data-pay="upi">📱 UPI received</button>'
        + '<button type="button" class="ctr-pay" data-pay="esewa">🇳🇵 eSewa received</button>'
        + '<button type="button" class="ctr-pay" data-pay="bank">🏦 Bank</button>'
        + '</div>'
        + '<div class="ctr-row"><label style="font-size:12px;font-weight:700">Discount</label>'
        + '<input type="number" id="ctrDiscVal" min="0" step="1" placeholder="0" inputmode="numeric">'
        + '<select id="ctrDiscType"><option value="flat">₹ off</option><option value="percent">% off</option></select>'
        + '<input type="text" id="ctrNote" maxlength="255" placeholder="Note (optional) · receipt no, remarks"></div>'
        + '<div class="ctr-note">Max discount ' + esc(maxPct) + '% of the fare (server re-checks). The booking is <b>confirmed immediately</b>, the ticket goes to the passenger\'s WhatsApp and the sale is credited to <b>' + esc(WHO) + '</b>.</div>';
      if (btn && btn.parentElement) btn.parentElement.insertBefore(el, btn); else card.appendChild(el);
      el.addEventListener('click', function (e) {
        var b = e.target.closest('.ctr-pay'); if (!b) return;
        CTR.pay = b.getAttribute('data-pay') || 'cash';
        el.querySelectorAll('.ctr-pay').forEach(function (x) { x.classList.toggle('on', x === b); });
      });
      $q('#ctrDiscVal').addEventListener('input', function () {
        var v = parseFloat(this.value) || 0;
        if ($q('#ctrDiscType').value === 'percent' && v > maxPct) { this.value = String(maxPct); }
      });
    }

    // The seller IS the agent: fix the agent-code box to their own code.
    var ac = $q('#cAgentCode');
    if (ac) {
      var field = ac.closest('.field') || ac.parentElement;
      if (STAFF.code) { ac.value = STAFF.code; ac.readOnly = true; }
      else if (field) { field.style.display = 'none'; }
    }
    // Do not pre-fill the passenger phone with the staff member's own customer login.
    try {
      var ph = $q('#cPhone');
      if (ph && typeof USER !== 'undefined' && USER && ph.value === USER.phone) ph.value = '';
    } catch (e) {}
  }

  /* ---- 4. Payload + result hooks ---------------------------------- */
  function hookApi() {
    if (typeof shgApi === 'undefined' || !shgApi || typeof shgApi.post !== 'function') return;
    var orig = shgApi.post;
    shgApi.post = function (path, body) {
      var isBook = CAN && typeof path === 'string' && path.indexOf('/book.php') === 0 && body && typeof body === 'object';
      if (isBook) {
        var dv = parseFloat(($q('#ctrDiscVal') || {}).value) || 0;
        body = Object.assign({}, body, {
          counterPayment: CTR.pay,
          discountType: dv > 0 ? (($q('#ctrDiscType') || {}).value || 'flat') : '',
          discountValue: dv,
          note: (($q('#ctrNote') || {}).value || '').trim(),
          couponCode: '', pointsRequested: 0, isCod: false
        });
      }
      var p = orig.call(shgApi, path, body);
      if (!isBook) return p;
      return p.then(function (res) {
        if (res && res.counter && res.pnr) {
          CTR.lastPnr = res.pnr; CTR.lastAdminUrl = res.adminUrl || '';
          // submitBooking() stores the local record right after this resolves
          // (as a COD-style pending booking); mark it confirmed + paid.
          setTimeout(function () { patchLocal(res.pnr); }, 0);
        }
        return res;
      });
    };
  }
  function patchLocal(pnr) {
    try {
      var b = DB.bookings.find(function (x) { return x.id === pnr; });
      if (!b) return;
      b.status = 'confirmed'; b.codFlag = false;
      b.payment = Object.assign({}, b.payment || {}, { method: CTR.pay, mode: 'counter', status: 'verified' });
      b.counter = { by: WHO, code: STAFF.code || '', adminUrl: CTR.lastAdminUrl };
      if (typeof persist === 'function') persist('bookings');
      if (location.hash === '#/ticket/' + pnr && typeof renderStatus === 'function') renderStatus(pnr);
      var dv = $q('#ctrDiscVal'); if (dv) dv.value = ''; var nt = $q('#ctrNote'); if (nt) nt.value = '';
    } catch (e) {}
  }

  /* ---- 5. Ticket screen: staff actions ----------------------------- */
  function decorateStatus(id) {
    var body = $q('#statusBody'); if (!body || $q('#ctrActions')) return;
    var b = null; try { b = DB.bookings.find(function (x) { return x.id === id; }); } catch (e) {}
    var adminUrl = (b && b.counter && b.counter.adminUrl) || ('/admin/booking-view.php?pnr=' + encodeURIComponent(id));
    var el = document.createElement('div');
    el.id = 'ctrActions';
    el.innerHTML = '<a class="btn btn-orange" href="#/" id="ctrSellAnother">🧾 Sell another · अर्को टिकट</a>'
      + '<a class="btn btn-blue" href="' + esc(adminUrl) + '">📋 Open in panel</a>'
      + '<a class="btn" href="' + esc(PANEL) + '">← Back to panel</a>';
    body.insertBefore(el, body.firstChild);
    $q('#ctrSellAnother').addEventListener('click', function () {
      try { Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; } catch (e) {}
    });
  }

  /* ---- 6. Wire the hooks once the app's globals exist -------------- */
  function wrap() {
    if (typeof renderCheckout === 'function') {
      var rc = renderCheckout;
      window.renderCheckout = function () { var r = rc.apply(this, arguments); try { decorateCheckout(); } catch (e) {} return r; };
    }
    if (typeof renderStatus === 'function') {
      var rs = renderStatus;
      window.renderStatus = function (id) { var r = rs.apply(this, arguments); try { decorateStatus(id); } catch (e) {} return r; };
    }
    if (typeof pushNotif === 'function') {
      // The customer-facing "pay cash at the counter" notice makes no sense for staff.
      window.pushNotif = function () {};
    }
    hookApi();
  }

  function start() {
    /* The office browser is the DESK, not a passenger. Before today's fix a
       sale signed this tab in as the customer it sold to (ensureCustomerSession),
       so live counters still carry the last passenger's identity: their name
       on the 👤 button, their My Bookings, their offline ticket. Drop any
       customer sign-in when counter mode starts (setUser(null) also tells the
       service worker to forget cached tickets — right on a shared machine). */
    try { if (CAN && typeof setUser === 'function' && typeof USER !== 'undefined' && USER) setUser(null); } catch (e) {}
    try { bar(); } catch (e) {}
    try { wrap(); } catch (e) {}
    try { prefill(); } catch (e) {}
    // Already on the checkout / ticket screen when this ran late? Decorate now.
    try { if (location.hash === '#/checkout') decorateCheckout(); } catch (e) {}
    try { if (location.hash.indexOf('#/ticket/') === 0) decorateStatus(location.hash.slice(9)); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
