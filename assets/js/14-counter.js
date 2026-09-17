
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
  /* last = the departure the previous sale went on ({from,to,date,sid,rid}),
     for "Same bus · next ticket"; confirmWatch = the timer that mirrors the
     real submit button's outcome onto the one-screen confirm button. */
  var CTR = { pay: 'cash', lastPnr: '', lastAdminUrl: '', lastDraft: '', last: null, confirmWatch: null };
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
      + '#ctrActions a{flex:1 1 140px;text-align:center}'
      /* one-screen confirm + the seat view's quick passenger line (17 Sep 2026) */
      + '.ctr-sum{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center;margin:10px 0 12px;padding:10px 12px;border-radius:10px;background:#FFF3E2;border:1px solid #F3D9B0;font-size:13.5px;color:inherit}'
      + ':root[data-theme="dark"] .ctr-sum{background:rgba(240,124,31,.14);border-color:#5a3a1a}'
      + '#ctrConfirmBtn{flex:1;width:100%}'
      + '#ctrQuick{margin:12px 0 4px;padding:10px 12px;border:1.5px dashed #178A50;border-radius:12px;background:#f3fbf6}'
      + ':root[data-theme="dark"] #ctrQuick{background:#0f2a1c}'
      + '.ctr-q-lbl{font-size:12.5px;font-weight:700;margin-bottom:6px}.ctr-q-lbl small{font-weight:500;color:var(--muted,#6b7688)}'
      + '.ctr-q-row{display:flex;gap:8px;flex-wrap:wrap}'
      + '.ctr-q-row input{flex:1 1 130px;min-width:0;font-size:16px;padding:10px 12px;border:1px solid var(--line,#dde3ee);border-radius:9px;background:var(--card,#fff);color:inherit}';
    document.head.appendChild(st);
    $q('#cbSellAnother').addEventListener('click', function () {
      try { if (typeof releaseMyLocks === 'function') releaseMyLocks(); } catch (e) {}
      try { Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; Flow.paxIndividual = false; } catch (e) {}
      forgetPassenger();
    });
    updateWatch();
  }

  /* The desk sells to a NEW stranger every sale: whatever passenger the seat
     view's quick line or "Book return journey" handed over (window.SHG_QUICK,
     read by renderCheckout) is dropped the moment a sale completes or the
     clerk starts over, so it can never ride into the next ticket. */
  function forgetPassenger() {
    try { window.SHG_QUICK = null; } catch (e) {}
    ['#ctrQuickName', '#ctrQuickPhone'].forEach(function (id) { var el = $q(id); if (el) el.value = ''; });
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
     rather than giving up at the first cards seen.
     rid (optional, 17 Sep 2026) = the client route code (data-sel) to match
     as well, so "Same bus · next ticket" re-enters the exact daily bus on a
     board that lists more than one route; the deep links leave it blank. */
  function autoPick(sid, seat, rid) {
    var tries = 0;
    var timer = setInterval(function () {
      tries++; if (tries > 40) { clearInterval(timer); return; }   // ~10s, then leave the list up
      var sels = document.querySelectorAll('#resultsList [data-sel]');
      if (!sels.length) return;
      var btn = null;
      for (var i = 0; i < sels.length; i++) {
        var s = parseInt(sels[i].getAttribute('data-sid') || '0', 10) || 0;
        if (s === sid && (!rid || sels[i].getAttribute('data-sel') === rid)) { btn = sels[i]; break; }
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
        // per-seat names are off for the new party (renderCheckout resets the
        // flag when it mints the draft id; this keeps the class honest too)
        try { Flow.paxIndividual = false; var pr0 = $q('#paxRows'); if (pr0) pr0.classList.remove('pax-names-on'); } catch (e) {}
        // The seat view's quick line / "Book return journey" handed THIS sale's
        // passenger over in SHG_QUICK: renderCheckout already put the name on the
        // lead row, and the phone was blanked just above — put it back.
        try {
          var q0 = window.SHG_QUICK, cp0 = $q('#cPhone');
          if (q0 && q0.phone && cp0 && !cp0.value) cp0.value = String(q0.phone).replace(/[^0-9]/g, '');
        } catch (e) {}
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
      // One screen (17 Sep 2026): the panel lives on step 1, right above the
      // Confirm button — the desk never has to open the customer's payment step.
      var nav0 = $q('#coStep1 .flow-actions');
      if (nav0 && nav0.parentNode) nav0.parentNode.insertBefore(el, nav0);
      else if (btn && btn.parentElement) btn.parentElement.insertBefore(el, btn); else card.appendChild(el);
      el.addEventListener('click', function (e) {
        var b = e.target.closest('.ctr-pay'); if (!b) return;
        CTR.pay = b.getAttribute('data-pay') || 'cash';
        el.querySelectorAll('.ctr-pay').forEach(function (x) { x.classList.toggle('on', x === b); });
        ctrSummary();
      });
      $q('#ctrDiscVal').addEventListener('input', function () {
        var v = parseFloat(this.value) || 0;
        if ($q('#ctrDiscType').value === 'percent' && v > maxPct) { this.value = String(maxPct); }
        ctrSummary();
      });
      $q('#ctrDiscType').addEventListener('change', ctrSummary);
    }
    oneScreen();

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

  /* ---- 3b. One-screen confirm (17 Sep 2026) ------------------------
     Counter mode used to run the customer's two-step checkout: staff had
     to tap "Continue to Payment →" to reach a step whose only staff
     content was the #ctrPanel above and the confirm button. Now the
     panel sits on step 1, the step-2 label reads "Counter payment", the
     Continue button is relabelled "Received at counter →" and hidden,
     and a cloned "Confirm counter sale" button in step 1's own action bar
     runs coGoStep(2) and then clicks the REAL #submitBookingBtn — so
     validateCheckoutDetails, the capture-phase submit guard, submitBooking
     and the /api/book.php payload (hookApi) are exactly as they were. A
     one-line sale summary above it says what is being confirmed. */
  function oneScreen() {
    var s1 = $q('#coStep1'); if (!s1) return;
    var nav = s1.querySelector('.flow-actions');
    var cont = $q('#coContinueBtn');
    if (cont && !cont.hasAttribute('data-ctr')) {
      cont.setAttribute('data-ctr', '1');
      cont.removeAttribute('data-i18n');
      cont.textContent = '🧾 Received at counter →';
      cont.style.display = 'none';
    }
    var stepLbl = document.querySelector('#co2Step .cs2[data-step="2"] span');
    if (stepLbl && !stepLbl.hasAttribute('data-ctr')) {
      stepLbl.setAttribute('data-ctr', '1');
      stepLbl.removeAttribute('data-i18n');
      stepLbl.textContent = 'Counter payment';
    }
    // belt and braces: an older ctrPanel (or one re-parented by anything) moves up here
    var panel = $q('#ctrPanel');
    if (panel && nav && panel.parentNode !== s1) s1.insertBefore(panel, nav);
    if (nav && !$q('#ctrSummary')) {
      var sum = document.createElement('div');
      sum.id = 'ctrSummary'; sum.className = 'ctr-sum'; sum.setAttribute('aria-live', 'polite');
      s1.insertBefore(sum, nav);
    }
    if (nav && !$q('#ctrConfirmBtn')) {
      var cb = document.createElement('button');
      cb.type = 'button'; cb.id = 'ctrConfirmBtn'; cb.className = 'btn btn-orange';
      cb.textContent = '🧾 Confirm counter sale →';
      nav.appendChild(cb);
      cb.addEventListener('click', ctrConfirm);
    }
    // re-arm on every render, like the real submit button
    var live = $q('#ctrConfirmBtn'); if (live) { live.disabled = false; live.classList.remove('loading'); }
    ctrSummary();
  }

  /* "2 seats · ₹4,000 · 💵 Cash · discount − ₹200 · ≈ ₹3,800 to collect" —
     read from the checkout's own maths (Flow.checkoutTotals, the same
     function submitBooking uses) and the desk's chips/discount box. The
     discount line is an estimate: the server applies and caps it. */
  function ctrSummary() {
    var el = $q('#ctrSummary'); if (!el) return;
    var seats = 0, total = 0;
    try { seats = ((Flow.legs && Flow.legs[0] && Flow.legs[0].seats) || []).length; } catch (e) {}
    try { total = (typeof Flow.checkoutTotals === 'function') ? (Number(Flow.checkoutTotals().total) || 0) : 0; } catch (e) { total = 0; }
    if (!total) {
      var pa = $q('#payAmount');
      total = parseInt(String((pa && (pa.getAttribute('data-val') || pa.textContent)) || '').replace(/[^0-9]/g, ''), 10) || 0;
    }
    var dv = parseFloat(($q('#ctrDiscVal') || {}).value) || 0;
    var dt = (($q('#ctrDiscType') || {}).value) || 'flat';
    var maxPct = Number(STAFF.maxDiscountPct || 15);
    var off = 0;
    if (dv > 0 && total > 0) off = dt === 'percent' ? Math.round(total * Math.min(dv, maxPct) / 100) : Math.min(dv, total);
    var payLbl = { cash: '💵 Cash', upi: '📱 UPI received', esewa: '🇳🇵 eSewa received', bank: '🏦 Bank' }[CTR.pay] || CTR.pay;
    var fmt = function (n) { try { return inr(n); } catch (e) { return '₹' + n; } };
    el.innerHTML = '<b>' + seats + ' seat' + (seats === 1 ? '' : 's') + '</b><span>·</span>' + fmt(total)
      + '<span>·</span>' + esc(payLbl)
      + (off > 0 ? '<span>·</span>discount − ' + fmt(off) + '<span>·</span><b>≈ ' + fmt(Math.max(1, total - off)) + ' to collect</b>'
                 : '<span>·</span><b>' + fmt(total) + ' to collect</b>');
  }

  function ctrConfirm() {
    var sb = $q('#submitBookingBtn'), me = $q('#ctrConfirmBtn');
    if (!sb || typeof coGoStep !== 'function') return;
    if (sb.disabled) return;                       // a sale is already in flight
    try { coGoStep(2); } catch (e) { return; }
    // details refused → coGoStep never reached step 2; the validator has
    // already toasted the field names and scrolled to the first bad one
    if (!(typeof Flow !== 'undefined' && Flow && Flow.coStep === 2)) return;
    if (me) { me.disabled = true; me.classList.add('loading'); }
    sb.click();
    // From here the sale is submitBooking()'s: on success it navigates to the
    // ticket; on failure it re-enables #submitBookingBtn (and returns to step 1
    // itself when the details were the problem). Mirror that outcome onto our
    // button and bring the desk back to the one screen it works on.
    var n = 0;
    clearInterval(CTR.confirmWatch);
    CTR.confirmWatch = setInterval(function () {
      n++;
      var onCheckout = (location.hash || '') === '#/checkout';
      if (onCheckout && sb.disabled && n <= 240) return;    // still submitting (≤ 60 s)
      clearInterval(CTR.confirmWatch); CTR.confirmWatch = null;
      if (me) { me.disabled = false; me.classList.remove('loading'); }
      if (onCheckout) { try { if (Flow.coStep === 2) coGoStep(1); } catch (e) {} }
    }, 250);
  }

  /* ---- 3c. Seat view: quick passenger line (17 Sep 2026) ------------
     The QuickBot desk lane (name + mobile → ticket) and the seat map were
     two different surfaces. For staff only, the seat view's summary card
     gets a compact Name · Mobile pair above Continue; at Continue they are
     handed to the checkout through the SAME window.SHG_QUICK prefill the
     home-page card uses (renderCheckout reads name/phone), so a repeat
     sale is: tap seat → type name/mobile → Continue → Confirm counter
     sale. Nothing here submits — the confirm stays a deliberate tap. */
  function decorateSeats() {
    if (!CAN) return;
    var card = document.querySelector('#view-seats .sum-card'); if (!card) return;
    var nav = card.querySelector('.flow-actions');
    if (!nav || $q('#ctrQuick')) return;
    var el = document.createElement('div');
    el.id = 'ctrQuick';
    el.innerHTML = '<div class="ctr-q-lbl">⚡ Passenger for this ticket <small>· lands on the checkout, ready to confirm</small></div>'
      + '<div class="ctr-q-row">'
      + '<input id="ctrQuickName" type="text" maxlength="80" placeholder="Name" autocomplete="off" autocapitalize="words">'
      + '<input id="ctrQuickPhone" type="tel" inputmode="numeric" maxlength="15" placeholder="Mobile (optional)" autocomplete="off">'
      + '</div>';
    card.insertBefore(el, nav);
    if (!document._ctrQuickGuard) {
      document._ctrQuickGuard = true;
      // capture phase on the document: runs before the app's own Continue
      // handler navigates to #/checkout and renders it
      document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest || !e.target.closest('#continueBtn')) return;
        var nm = (($q('#ctrQuickName') || {}).value || '').trim();
        var ph = (($q('#ctrQuickPhone') || {}).value || '').replace(/[^0-9]/g, '');
        try { window.SHG_QUICK = (nm || ph) ? { name: nm, phone: ph } : null; } catch (e2) {}
      }, true);
    }
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
          // Remember the departure for "Same bus · next ticket" (17 Sep 2026).
          // Flow.legs[0] is still intact here: submitBooking resets Flow only
          // after this promise hands the result back.
          try {
            var leg0 = (typeof Flow !== 'undefined' && Flow && Flow.legs && Flow.legs[0]) || null;
            CTR.last = {
              from: (Flow && Flow.from) || '', to: (Flow && Flow.to) || '',
              date: body.travelDate || (leg0 && leg0.date) || '',
              sid: parseInt(body.scheduleId, 10) || 0,
              rid: (leg0 && leg0.routeId) || ''
            };
          } catch (e) {}
          forgetPassenger();   // this sale's passenger never rides into the next one
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
    el.innerHTML = (CTR.last ? '<a class="btn btn-orange" href="#/results" id="ctrSameBus">🚌 Same bus · next ticket</a>' : '')
      + '<a class="btn ' + (CTR.last ? 'btn-blue' : 'btn-orange') + '" href="#/" id="ctrSellAnother">🧾 Sell another · अर्को टिकट</a>'
      + '<a class="btn btn-blue" href="' + esc(adminUrl) + '">📋 Open in panel</a>'
      + '<a class="btn" href="' + esc(PANEL) + '">← Back to panel</a>';
    body.insertBefore(el, body.firstChild);
    $q('#ctrSellAnother').addEventListener('click', function () {
      try { if (typeof releaseMyLocks === 'function') releaseMyLocks(); } catch (e) {}
      try { Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = ''; Flow.paxIndividual = false; } catch (e) {}
      forgetPassenger();
    });
    var same = $q('#ctrSameBus');
    if (same) same.addEventListener('click', function (e) { e.preventDefault(); sameBus(); });
  }

  /* "Same bus · next ticket" (17 Sep 2026): the desk sells ticket after
     ticket on ONE departure, and "Sell another" threw the bus and date
     away every time. Re-run the search the last sale came from and let
     autoPick tap the same card — every step goes through the app's own
     handlers (results click → seat map with fresh occupancy: SeatSrv was
     invalidated by submitBooking), so holds and pricing behave exactly as
     a manual tap. No server involvement. */
  function sameBus() {
    var L = CTR.last; if (!L || !L.from || !L.to || !L.date) return;
    try { if (typeof releaseMyLocks === 'function') releaseMyLocks(); } catch (e) {}
    forgetPassenger();
    try {
      if (typeof setTripType === 'function') setTripType('one');
      Flow.from = L.from; Flow.to = L.to; Flow.date = L.date; Flow.retDate = '';
      Flow.route = null; Flow.seats = []; Flow.legs = []; Flow.legIndex = 0; Flow.draftId = '';
      Flow.scheduleId = L.sid || 0; Flow.fareOverride = 0; Flow.paxIndividual = false;
      Flow.shotThumb = ''; Flow.shotBlob = null;
    } catch (e) { return; }
    // keep the search card in step, so "Change search" starts from this bus
    try {
      var fs = $q('#fromSel'), ts = $q('#toSel'), di = $q('#dateInput');
      var setOpt = function (sel, v) {
        if (!sel || !v) return;
        if (!setSelect(sel, v)) { sel.insertAdjacentHTML('beforeend', '<option>' + esc(v) + '</option>'); sel.value = v; }
      };
      setOpt(fs, L.from); setOpt(ts, L.to);
      if (di) { di.value = L.date; try { if (typeof markDateChip === 'function') markDateChip(); } catch (e2) {} }
    } catch (e) {}
    location.hash = '#/results';
    if (location.hash === '#/results') { try { if (typeof router === 'function') router(); } catch (e) {} }
    autoPick(L.sid || 0, '', L.rid || '');
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
    try { decorateSeats(); } catch (e) {}   // the seat view's summary card is static markup — once is enough
    // Already on the checkout / ticket screen when this ran late? Decorate now.
    try { if (location.hash === '#/checkout') decorateCheckout(); } catch (e) {}
    try { if (location.hash.indexOf('#/ticket/') === 0) decorateStatus(location.hash.slice(9)); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
