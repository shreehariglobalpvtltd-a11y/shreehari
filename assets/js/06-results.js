
/* ================================================================
   [JS] 7. RESULTS VIEW — skeleton load, verified badge, waitlist
================================================================ */
function bookedSeatsFor(routeId, date) {
  /* The database is the authority on what is sold. Only when we have never
     managed to reach it do we fall back to what this browser remembers —
     which is this visitor's own bookings and nobody else's. Read the CURRENT
     mode's snapshot: for private the booked list is collapsed to cabins, so a
     sharing-bucket read would place occupancy on the wrong berths. */
  const mode = (typeof Flow !== 'undefined' && Flow.bookingType) ? Flow.bookingType : undefined;
  const srv = SeatSrv.snap(routeId, date, mode) || SeatSrv.snap(routeId, date);
  if (srv) return srv.booked.slice();

  // 'pending' (held) and 'confirmed' bookings both block seats,
  // on the outbound leg and on any return leg.
  const seats = [];
  DB.bookings.forEach(b => {
    if (b.status !== 'pending' && b.status !== 'confirmed') return;
    if (b.routeId === routeId && b.date === date) seats.push.apply(seats, b.seats);
    if (b.ret && b.ret.routeId === routeId && b.ret.date === date) seats.push.apply(seats, b.ret.seats);
  });
  return seats;
}

function seatIdsFor(route, bookingType) {
  if (route.type === 'sleeper') {
    const bt = bookingType || Flow.bookingType || 'sharing';
    /* Derived from the server's own seat_mode_map (SHG_BOOT), not from a second
       copy of the numbers. This used to hard-code "36 sharing / 18 private per
       deck" with a comment saying it MUST match includes/seats.php by hand —
       and a mismatch either hides sold berths or offers berths the bus does not
       have. The canonical count divided by the mode's beds-per-cabin IS the
       mode's seat count, so both follow one row now. The old literals remain as
       the fallback for a cached bundle or a missing row. */
    const spec = (() => {
      try {
        const m = window.SHG_BOOT && SHG_BOOT.settings && SHG_BOOT.settings.seat_mode_map;
        return (m && m[route.type]) || null;
      } catch (e) { return null; }
    })();
    const canonical = spec && parseInt(spec.perDeck, 10) >= 1 ? parseInt(spec.perDeck, 10) : 36;
    const perDeck   = Math.floor(canonical / bedsPerLabelJS(route.type, bt));
    const decks     = (spec && Array.isArray(spec.decks) && spec.decks.length) ? spec.decks : ['L', 'U'];
    const ids = [];
    decks.forEach(d => { for (let r = 1; r <= perDeck; r++) ids.push(d + r); });
    return ids;
  }
  const ids = [];
  for (let r = 1; r <= 10; r++) ['A', 'B', 'C', 'D'].forEach(c => ids.push(r + c));
  return ids;
}

/* Which leg is being searched right now? */
function legCtx() {
  if (Flow.tripType === 'round' && Flow.legIndex === 1) {
    return { from: Flow.to, to: Flow.from, date: Flow.retDate, ret: true };
  }
  return { from: Flow.from, to: Flow.to, date: Flow.date, ret: false };
}

function skeletonCards(n) {
  let out = '';
  for (let i = 0; i < n; i++) {
    out += '<div class="bus-card skel-card" aria-hidden="true">'
      + '<div><div class="skel" style="width:64%;height:22px"></div><div class="skel" style="width:42%;height:13px;margin-top:12px"></div><div class="skel" style="width:56%;height:13px;margin-top:8px"></div></div>'
      + '<div><div class="skel" style="width:78%;height:18px"></div><div class="skel" style="width:50%;height:13px;margin-top:10px"></div><div class="skel" style="width:88%;height:13px;margin-top:12px"></div></div>'
      + '<div><div class="skel" style="width:58%;height:26px;margin-left:auto"></div><div class="skel" style="width:90%;height:40px;margin-top:16px;border-radius:12px;margin-left:auto"></div></div>'
      + '</div>';
  }
  return out;
}

let RES_TOKEN = 0;
function renderResults(instant) {
  const ctx = legCtx();
  $('#resTitleH').innerHTML = t(ctx.ret ? 'resTitleRet' : 'resTitle');
  $('#resRoute').innerHTML = esc(ctx.from) + ' <em>→</em> ' + esc(ctx.to);
  /* AD date books the ticket; the BS date is what a Nepali passenger
     recognises, so both are shown. nepaliBS() returns null outside the
     BS_DATA table (02-config.js) — then this stays AD-only. */
  var _bsRes = (typeof nepaliBSFull === 'function') ? nepaliBSFull(ctx.date) : '';
  $('#resDate').textContent = fmtDate(ctx.date) + (_bsRes ? ' · ' + _bsRes : '');
  const banner = $('#retBanner');
  if (ctx.ret) {
    banner.classList.remove('hide');
    banner.innerHTML = t('resReturnPick') + ' <b>' + esc(ctx.from) + ' → ' + esc(ctx.to) + ' · ' + fmtDate(ctx.date) + '</b>';
  } else banner.classList.add('hide');

  const list = $('#resultsList');
  const token = ++RES_TOKEN;

  const doRender = () => {
    if (token !== RES_TOKEN) return;
    /* The database decides which buses run. One search request refreshes the
       whole board (routes, times, fares AND seatsLeft — no per-bus seats.php
       burst); until it lands, the local catalogue paints instantly. */
    SrvCatalog.load(ctx.from, ctx.to, ctx.date).then((changed) => {
      if (changed && token === RES_TOKEN && location.hash === '#/results') doRender();
    });
    const srvSnap = SrvCatalog.snap(ctx.from, ctx.to, ctx.date);
    /* Master switch (4 Sep 2026): the office turned the daily service OFF —
       say so plainly (with their note and the phone number) instead of a
       "no buses found" that reads like a search mistake. */
    if (srvSnap && srvSnap.serviceOff) {
      list.innerHTML = '<div class="empty-state reveal svc-off"><div class="big">⏸️</div><h3>' + t('svcOffT') + '</h3><p>'
        + tf('svcOffP', { phone: '<a href="tel:' + esc(digits(S().phone)) + '">' + esc(S().phone) + '</a>' })
        + (srvSnap.offNote ? '</p><p class="svc-off-note">' + esc(srvSnap.offNote) : '') + '</p></div>';
      observeReveals(); return;
    }
    /* When the server has answered, the SERVER decides which buses are on
       the board — that is what makes /api/search.php the single source of
       truth this overlay promises.

       Re-checking `r.from === ctx.from` here defeated that: a boarding point
       is a STOP on the run, not the route's endpoint city, so searching from
       Surat (or Baroda, Emli Bhupal, S Hari Parking) matched
       no local row — every route is stored from "Ahmedabad" — and the board
       said "No coaches found" even while search.php was returning the bus.
       The city test survives only as the OFFLINE fallback, where the local
       catalogue is all we have. */
    const routes = DB.routes.filter(r => r.active && (
        srvSnap ? !!srvSnap.byCode[r.id]
                : (r.from === ctx.from && r.to === ctx.to)
      ))
      .sort((a, b) => (a.type === 'sleeper' ? 0 : 1) - (b.type === 'sleeper' ? 0 : 1));
    if (!routes.length) {
      list.innerHTML = '<div class="empty-state reveal"><div class="big">🚌</div><h3>' + t('resEmptyT') + '</h3><p>'
        + tf('resEmptyP', { route: esc(ctx.from) + ' → ' + esc(ctx.to), date: fmtDate(ctx.date) }) + '</p></div>';
      observeReveals(); return;
    }
    const cheapest = routes.filter(r => r.type === 'sleeper').length ? sharingPP(ctx.to, true) : Math.min(...routes.map(r => r.fare));
    /* Task 1 (Surat 24×7): the server rolled a spent departure forward — tell
       the customer clearly that today's bus has left and show the next date,
       instead of an empty board. Task 6 (single daily bus): a quiet affirmation
       that this is the one daily service. */
    let boardBanner = '';
    if (srvSnap && srvSnap.nextAvailable) {
      const stop = srvSnap.nextStop || ctx.from;
      const rd = $('#resDate'); if (rd) rd.textContent = fmtDate(srvSnap.nextDate);
      boardBanner += '<div class="next-dep-banner reveal">🕒 <b>' + tf('nextDepT', { stop: esc(stop) })
        + '</b><div class="ndb-sub">' + tf('nextDepP', { date: esc(fmtDate(srvSnap.nextDate)) }) + '</div></div>';
    }
    boardBanner += '<div class="daily-service-note reveal">🚌 ' + t('dailyOnly') + '</div>';
    /* One card per departure. cardHTML(r, srv, extra) draws the daily bus
       (extra = false) and, appended after the daily cards, every EXTRA bus
       the office added for the date on the Bus Calendar (5 Sep 2026) —
       same route, its own departure time, coach and seats. */
    const cardHTML = (r, srv, extra) => {
      const total = srv ? srv.totalSeats : seatIdsFor(r).length;
      const taken = srv ? (total - srv.seatsLeft)
        : bookedSeatsFor(r.id, ctx.date).length + lockedSeatsFor(r.id, ctx.date).length;
      const left = srv ? Math.max(0, srv.seatsLeft) : Math.max(0, total - taken);
      const cabinsLeft = r.type === 'sleeper' ? Math.floor(left / 2) : left;
      const bp0 = (r.boarding || [])[0] || '';
      const isBest = r.type === 'sleeper' && sharingPP(r.to, true) <= cheapest;
      const valueBadge = isBest ? '<span class="badge ok" style="font-size:11px;margin-left:6px">BEST VALUE</span>' : '';
      const privateBadge = r.type === 'sleeper' ? '<span class="badge" style="background:var(--orange-100);color:var(--orange-600);font-size:11px;margin-left:4px">🔒 PRIVATE</span>' : '';
      const extraBadge = extra ? '<span class="badge" style="background:#efeaff;color:#5a3fb0;font-size:11px;margin-left:4px" title="Extra departure added for this date">➕ EXTRA BUS' + (r._slot ? ' · Bus ' + r._slot : '') + '</span>' : '';
      const amenityChips = '<div class="bc-tags"><span class="chip">❄️ AC</span><span class="chip">🔌 Charging</span><span class="chip">💧 Water</span><span class="chip">🛡️ Assisted Border</span></div>';
      const dayBadge = r.dayOffset ? '<span class="badge" style="background:#E3F2FD;color:#1565C0;font-size:10px;margin-left:4px">+' + r.dayOffset + ' day</span>' : '';
      /* Rupaidiha is the last point of this service — the coach does not go
         on to Nepalgunj or Kohalpur. Several routes still carry the ARRIVAL
         time of that old onward leg, so printing it verbatim tells the
         passenger a time the bus will never keep. Show the arrival only when
         it is one we can stand behind; otherwise name the destination and say
         the time is being confirmed, rather than inventing one. */
      const arrKnown = !!(r.arrTime && String(r.arrTime).trim() && r.duration && String(r.duration).trim());
      const arrLabel = arrKnown
        ? esc(r.arrTime) + ' <small>NPT</small>' + dayBadge
        : '<span title="Arrival time is being confirmed by the office">' + esc(r.to || 'Rupaidiha') + '</span>';
      const arrDuration = arrKnown ? r.duration : '';

      /* Round-3 TIME-prominent hierarchy:
           line 1  → big departure time (.depTime-big — styled by Builder VIS)
           line 2  → bus name + verified/best/private badges
           line 3  → seats-avail pill (.gone|.low|default) + fare-from pill
         The 3-col grid skeleton (.bc-time | .bc-mid | .bc-fare) is kept so
         existing CSS and mobile collapse (@media 1020px) still work. */
      /* A departure the office priced itself (Bus Calendar → Price) shows
         THAT number, because it is what the server will charge for it. */
      const busFare = Number((srv && srv.fareOverride) || r._fare || 0);
      const perPersonFare = busFare > 0 ? busFare : (r.type === 'sleeper' ? sharingPP(r.to, true) : r.fare);
      const availState    = left <= 0 ? 'gone' : (left <= 10 ? 'low' : 'ok');
      const seatsPillTxt  = left <= 0
        ? t('resSoldOut')
        : (r.type === 'sleeper' && cabinsLeft <= 4
            ? 'Only ' + cabinsLeft + ' cabins left'
            : left + ' ' + t('resLeft'));
      const pillsRow = `<div class="bc-pills">
          <span class="seats-avail-pill ${availState}">${seatsPillTxt}</span>
          <span class="fare-from-pill">From ${inr(perPersonFare)}</span>
        </div>`;
      /* Sold-out CTA — bilingual, disabled. Waitlist modal path removed
         from the card per Round-3 spec ("disable the click handler and swap
         the CTA text to 'Sold out'"); the modal function itself is left in
         place for future re-wiring. */
      const sidAttr = extra && r._sid ? ` data-sid="${r._sid}"` : '';
      const cta = left <= 0
        ? `<button class="btn btn-ghost btn-sm sold-out" type="button" disabled aria-disabled="true">बुक भइसक्यो · Sold out</button>`
        : `<button class="btn btn-orange btn-sm" type="button" data-sel="${r.id}"${sidAttr}>${t('resSelect')}</button>`;

      return `<div class="bus-card reveal${left <= 0 ? ' is-sold-out' : ''}${extra ? ' is-extra' : ''}" data-rid="${r.id}"${sidAttr}>
        <div class="bc-time">
          <div class="t depTime-big">${esc(r.depTime)} <small>IST</small></div>
          <div class="dur" style="flex:1;display:flex;align-items:center;gap:0;position:relative">
            <span style="width:8px;height:8px;border-radius:50%;background:var(--blue);flex:none"></span>
            <span style="flex:1;height:2px;background:linear-gradient(90deg,var(--blue),var(--orange));position:relative"><span style="position:absolute;top:-6px;left:45%;font-size:13px">🚌</span></span>
            <span style="width:8px;height:8px;border-radius:50%;background:var(--orange);flex:none"></span>
          </div>
          <div class="t">${arrLabel}</div>
          <div class="plus">${esc(arrDuration)}</div>
        </div>
        <div class="bc-mid">
          <h3>${esc(r.busName)}${routes.filter(x => x.busName === r.busName).length > 1 ? ' <b style="color:var(--orange);font-size:14px">(' + esc(r.busNo) + ')</b>' : ''} <span class="vbadge" title="Operated by S Hari Global Pvt Ltd · CIN ${esc(CONFIG.company.cin)}">${t('resVerified')}</span>${valueBadge}${privateBadge}${extraBadge}</h3>
          ${pillsRow}
          <div class="busno">${esc(r.busNo)} · ${r.type === 'sleeper' ? 'AC SLEEPER 72 · 4+2 Sharing / 2+1 Private' : 'AC SEATER (40)'}</div>
          ${amenityChips}
          <div class="bc-points"><b>${t('resBoardingLbl')}</b> ${esc(bpShort(bp0))} ${r.boarding && r.boarding.length > 1 ? '+' + (r.boarding.length - 1) + ' ' + t('resMore') : ''}</div>
        </div>
        <div class="bc-fare">
          <span class="amt">${inr(perPersonFare)}</span>
          <span class="npr">${r.type === 'sleeper' ? nprEst(perPersonFare) + ' /person' : nprEst(r.fare) + ' / ' + t('rowSeats').toLowerCase()}</span>
          ${r.type === 'sleeper' ? '<div class="cabin-price-tag"><span class="sharing-price">🤝 Sharing from ' + inr(busFare > 0 ? busFare : sharingPP(r.to, true)) + '/person</span></div><div class="cabin-price-tag"><span class="private-price">🔒 Private from ' + inr(CONFIG.cabinPricing.private.single_1pax.online) + '/cabin</span></div>' + (busFare <= 0 && sharingBasePP(r.to) > sharingPP(r.to, true) ? '<div class="save-badge">💸 ' + tf('saveOnline', { a: inr(sharingBasePP(r.to) - sharingPP(r.to, true)) }) + '</div>' : '') : ''}
          <span class="left" style="color:${left <= 5 ? 'var(--bad)' : 'var(--ok)'}">${left <= 0 ? t('resSoldOut') : (r.type === 'sleeper' && cabinsLeft <= 4 ? '<b style="color:var(--bad)">Only ' + cabinsLeft + ' cabins left!</b>' : left + ' ' + t('resLeft'))}</span>
          ${cta}
        </div>
      </div>`;
    };
    const extraCards = ((srvSnap && srvSnap.extras) || []).map(x => {
      const baseR = routes.find(rr => rr.id === x.routeCode) || routeById(x.routeCode);
      if (!baseR) return '';
      /* A clone of the route carrying this departure's own time / coach /
         (shifted) pickup times. Fares, stops and type are the route's. */
      const r2 = Object.assign({}, baseR, {
        depTime: x.depTime || baseR.depTime,
        busName: x.busName || baseR.busName, busNo: x.busNumber || baseR.busNo,
        boarding: (x.boarding && x.boarding.length) ? x.boarding : baseR.boarding,
        drop: (x.drop && x.drop.length) ? x.drop : baseR.drop,
        _sid: x.scheduleId, _slot: x.slot, _fare: Number(x.fareOverride || 0)
      });
      return cardHTML(r2, x, true);
    });
    list.innerHTML = boardBanner
      /* Seat-fill chart (13 Sep 2026): the next 7 days of this direction, so a
         passenger can pick a lighter day. Fills itself from /api/occupancy.php
         after the cards paint; the board never waits for it. */
      + '<div class="occ-host" id="occHost"></div>'
      + routes.map(r => cardHTML(r, srvSnap && srvSnap.byCode[r.id], false)).join('')
      + extraCards.join('');
    observeReveals();
    try {
      if (window.OccChart && !ctx.ret) window.OccChart.render($('#occHost'), ctx.from, ctx.to, ctx.date);
    } catch (e) {}
  };

  if (instant) { doRender(); return; }
  list.innerHTML = skeletonCards(2);
  /* Paint the moment the server answers, never later than 280 ms (13 Sep
     2026). The fixed 420 ms wait showed a skeleton even when search.php had
     already replied; on a slow link the local catalogue paints at 280 ms and
     doRender()'s own load re-renders when the server lands, as before. */
  let painted = false;
  const paintOnce = () => { if (painted || token !== RES_TOKEN) return; painted = true; doRender(); };
  SrvCatalog.load(ctx.from, ctx.to, ctx.date).then(paintOnce, paintOnce);
  setTimeout(paintOnce, 280);
}
/* One delegated click handler for the results list (select / waitlist) */
$('#resultsList').addEventListener('click', (e) => {
  const wl = e.target.closest('[data-wl]');
  if (wl) { openWaitlistModal(wl.getAttribute('data-wl'), legCtx().date); return; }
  let sel = e.target.closest('[data-sel]');
  /* WHOLE CARD IS THE BUTTON.
     The "सिट छान्नुहोस् →" CTA is the last thing in a ~570px-tall card,
     so on a phone it sits ~630px below the fold: the owner reported the
     next button "isn't there on mobile" because you have to hunt for it.
     Every booking app makes the result row itself tappable, so do that —
     the CTA stays for people who scroll to it, and the tap target becomes
     the entire card. Skipped for sold-out cards and for taps that landed
     on some other control inside the card (waitlist, links, selects). */
  if (!sel) {
    const card = e.target.closest('.bus-card');
    if (card
        && !card.classList.contains('is-sold-out')
        && !card.classList.contains('skel-card')
        && !e.target.closest('button,a,input,select,textarea,label')) {
      sel = card.querySelector('[data-sel]');
    }
  }
  if (!sel) return;
  Flow.route = routeById(sel.getAttribute('data-sel'));
  Flow.seats = [];
  /* Task 1 (Surat 24×7): if the board was rolled forward to the next available
     departure, adopt that date now so the seat map, checkout and ticket all use
     the date the customer is really booking (outbound one-way only). */
  const _lc = legCtx();
  const _sn = (typeof SrvCatalog !== 'undefined') ? SrvCatalog.snap(_lc.from, _lc.to, _lc.date) : null;
  if (_sn && _sn.nextAvailable && _sn.nextDate && !_lc.ret) { Flow.date = _sn.nextDate; }
  /* Bus Calendar (5 Sep 2026): an EXTRA bus card carries its schedule id.
     Remember it for the seat map / holds / checkout, and give Flow.route
     that departure's own time + coach. A daily-bus card resets it to 0, so
     nothing from an earlier pick can leak into the next booking. */
  const _card = sel.closest('.bus-card');
  const _sidAttr = sel.getAttribute('data-sid') || (_card && _card.getAttribute('data-sid')) || '';
  const _prevSid = Flow.scheduleId || 0;
  Flow.scheduleId = _sidAttr ? (parseInt(_sidAttr, 10) || 0) : 0;
  /* Per-departure price (4 Sep 2026): the picked bus may run at its own fare.
     Carried on Flow so the seat summary, the checkout total and the server
     quote all agree; a daily bus resets it to 0 and prices as before. */
  Flow.fareOverride = 0;
  if (Flow.scheduleId && Flow.route) {
    const _x = ((_sn && _sn.extras) || []).find(e => e.scheduleId === Flow.scheduleId);
    if (_x) {
      Flow.fareOverride = Number(_x.fareOverride || 0);
      Flow.route = Object.assign({}, Flow.route, {
        depTime: _x.depTime || Flow.route.depTime,
        busName: _x.busName || Flow.route.busName, busNo: _x.busNumber || Flow.route.busNo,
        boarding: (_x.boarding && _x.boarding.length) ? _x.boarding : Flow.route.boarding,
        drop: (_x.drop && _x.drop.length) ? _x.drop : Flow.route.drop,
        extraBus: true, slot: _x.slot, fare: Flow.fareOverride > 0 ? Flow.fareOverride : Flow.route.fare
      });
    }
  } else if (Flow.route) {
    // The daily bus can carry a price too (set on its own calendar row).
    const _d = _sn && _sn.byCode ? _sn.byCode[Flow.route.id] : null;
    Flow.fareOverride = Number((_d && _d.fareOverride) || 0);
    if (Flow.fareOverride > 0) {
      Flow.route = Object.assign({}, Flow.route, { fare: Flow.fareOverride });
    }
  }
  if (_prevSid !== Flow.scheduleId && typeof SeatSrv !== 'undefined' && Flow.route) {
    SeatSrv.invalidate(Flow.route.id, _lc.date);   // never paint another departure's occupancy
  }
  // Sleeper: sharing/private is now chosen INSIDE the seat view (the
  // #cabinToggle), not on the results card — so start it fresh each time.
  const bt = sel.getAttribute('data-bt');
  Flow.bookingType = bt || null;
  location.hash = '#/seats';
});

/* ================================================================
   [JS] 8. SEAT MAP — seater (2+2) or sleeper (deck tabs), with
   female-preferred seats, live locks and a hold countdown.
================================================================ */
function femaleBookedSeats(routeId, date) {
  const set = {};
  DB.bookings.forEach(b => {
    if (b.status !== 'pending' && b.status !== 'confirmed') return;
    const scan = (legRouteId, legDate, passengers) => {
      if (legRouteId !== routeId || legDate !== date) return;
      (passengers || []).forEach(p => { if (p.gender === 'Female') set[p.seat] = true; });
    };
    scan(b.routeId, b.date, b.passengers);
    if (b.ret) scan(b.ret.routeId, b.ret.date, b.passengers.map((p, i) => ({ seat: (b.ret.seats || [])[i], gender: p.gender })));
  });
  return set;
}

/* Physical shared-cabin key a seat belongs to — MUST match Seats::unitKey()
   on the server (two adjacent berths L1+L2 -> "L-1", a seater side pair
   1A+1B -> "1-AB"). Used only to communicate the server's gender rule in the
   seat map; the server is always the authority. (Part 2 · Feature A) */
/* The mode↔bed rule set, shipped from the server in SHG_BOOT.settings
   (settings key `seat_mode_map`, is_public). Before 8 Sep 2026 the 2:1 ratio
   was written out as a literal here AND in reservedSeatsForMode below, with a
   "change both together" comment on the server — so re-shaping a coach meant a
   two-file deploy plus a bundle version bump. Now the server row is the single
   source and this reads it; the historical 2:1 remains the fallback so a cached
   bundle, a missing row, or an old service-worker payload renders exactly the
   coach it always did rather than a wrong one. */
function seatModeRuleJS(coachType, mode) {
  try {
    const map = window.SHG_BOOT && SHG_BOOT.settings && SHG_BOOT.settings.seat_mode_map;
    const spec = map && map[coachType || 'sleeper'];
    const rule = spec && spec.modes && spec.modes[mode];
    if (rule) return rule;
  } catch (e) { /* boot payload absent or malformed — fall through */ }
  return null;
}
function bedsPerLabelJS(coachType, mode) {
  const rule = seatModeRuleJS(coachType, mode);
  const n = rule && parseInt(rule.bedsPerLabel, 10);
  if (n >= 1) return n;
  return mode === 'private' ? 2 : 1;
}
/* 0-based row index -> spreadsheet-style row letter (A..Z, AA..). */
function seatRowLetterJS(idx) {
  idx = Math.max(0, idx | 0);
  let out = '';
  do { out = String.fromCharCode(65 + (idx % 26)) + out; idx = Math.floor(idx / 26) - 1; } while (idx >= 0);
  return out;
}
/* PASSENGER-FACING seat label — MUST match Seats::displayLabel() on the server
   (includes/seats.php). Storage stays canonical (L1..L36 / U1..U36 / 1A..10D);
   this is the row-letter grid a human reads: Lower LA1..LF6, Upper UA1..UF6
   (private 3-across -> LA1..LF3). `perRow` is derived from the SAME seat_mode_map
   `across` the layout uses, so the tile text can never disagree with the grid.
   The canonical id keeps flowing through data-id, holds, sales and the API —
   only the visible text is prettified. */
function seatLabel(id, coachType, mode) {
  id = String(id == null ? '' : id).toUpperCase();
  const m = id.match(/^([LU])(\d+)$/);            // sleeper deck berth
  if (m) {
    const rule = seatModeRuleJS(coachType || 'sleeper', mode || 'sharing');
    const across = (rule && Array.isArray(rule.across) && rule.across.length === 2)
      ? rule.across : (mode === 'private' ? [2, 1] : [4, 2]);
    const perRow = Math.max(1, (parseInt(across[0], 10) || 0) + (parseInt(across[1], 10) || 0));
    const n = parseInt(m[2], 10);
    if (!(n >= 1)) return id;
    return m[1] + seatRowLetterJS(Math.floor((n - 1) / perRow)) + (((n - 1) % perRow) + 1);
  }
  if (/^\d+$/.test(id)) return 'A' + id;          // pure-numeric fallback
  return id;                                       // seater #A..#D, unchanged
}
/* Map a canonical seat-id list to a display string (LA1, LA2, …). Used wherever
   a selected/booked seat list is shown to a human; the stored array stays
   canonical, only the joined text is prettified. */
function seatLabelJoin(seats, coach, mode, glue) {
  return (seats || []).map(function (s) { return seatLabel(s, coach, mode); }).join(glue == null ? ', ' : glue);
}
/* A physical bed -> the label of the cabin holding it, in `mode`'s namespace.
   An `explicit` irregular cabin is checked FIRST, exactly as the server's
   Seats::physicalToMode does: dividing first would land a bed inside an
   irregular cabin on the wrong neighbour's label. */
function cabinOfBedJS(bed, coachType, mode) {
  bed = String(bed).toUpperCase();
  const rule = seatModeRuleJS(coachType, mode);
  if (rule && rule.explicit) {
    for (const label in rule.explicit) {
      const beds = rule.explicit[label] || [];
      for (let i = 0; i < beds.length; i++) {
        if (String(beds[i]).toUpperCase() === bed) return label;
      }
    }
  }
  const per = bedsPerLabelJS(coachType, mode);
  const m = bed.match(/^([A-Z])(\d+)$/);
  if (per > 1 && m) return m[1] + Math.ceil(parseInt(m[2], 10) / per);
  return bed;
}

function unitKeyJS(seat, coachType) {
  seat = String(seat).toUpperCase();
  let m = seat.match(/^([LU])(\d+)$/);
  if (m) {
    // The "unit" is the physical cabin, which is what a bed collapses to in
    // the cabin-shaped (private) mode — same definition the server uses.
    const cabin = cabinOfBedJS(seat, coachType || 'sleeper', 'private').match(/^([A-Z])(\d+)$/);
    return cabin ? cabin[1] + '-' + parseInt(cabin[2], 10) : m[1] + '-' + parseInt(m[2], 10);
  }
  m = seat.match(/^(\d+)([A-D])$/);
  if (m) return m[1] + '-' + ((m[2] === 'A' || m[2] === 'B') ? 'AB' : 'CD');
  return 'S-' + seat;
}

/* Reserved (staff + emergency) berths in a given booking mode's namespace — the
   client mirror of Seats::physicalSetToMode + Seats::emergencySeats. The
   permanent staff pair (CONFIG.staffSeats, in sharing labels) is collapsed for
   PRIVATE (a physical bed Ln -> its cabin L(ceil(n/2))), so private L5/L6 map to
   the emergency cabin L3 rather than wrongly reserving the real private L5/L6;
   the emergency map is already per-mode. Sharing/seater is identity. */
function reservedSeatsForMode(coachType, mode) {
  const staff = (CONFIG.staffSeats && CONFIG.staffSeats[coachType]) || [];
  const emgMap = (CONFIG.emergencySeats && CONFIG.emergencySeats[coachType]) || null;
  const emg = !emgMap ? [] : (Array.isArray(emgMap) ? emgMap : (emgMap[mode] || []));
  const toMode = (id) => {
    if (mode === 'private') {
      // Same rule set as the server (seat_mode_map), not a second literal.
      return cabinOfBedJS(id, coachType, 'private');
    }
    return String(id).toUpperCase();
  };
  const set = {};
  staff.forEach(s => { set[toMode(s)] = true; });
  emg.forEach(s => { set[String(s).toUpperCase()] = true; });
  return Object.keys(set);
}

/* Gender lock per shared cabin, derived from who is already booked in it:
   'female_only' (a woman is aboard — men blocked), 'male_only' (mirror),
   'mixed_allowed' (a group took the whole cabin together). Mirrors the
   server so the seat map can flag cabins before checkout. */
function unitGenderLocksFor(routeId, date) {
  /* The server keeps the authoritative cabin lock in schedule_unit_locks and
     enforces it inside the booking transaction. Mirror it when we have it —
     deriving the lock from DB.bookings only ever saw this browser's own
     passengers, so a cabin a woman had booked from another phone looked open
     to the next man to pick it, and he was refused at checkout instead. */
  const srv = SeatSrv.snap(routeId, date);
  if (srv) {
    const out = {};
    Object.keys(srv.units || {}).forEach(u => {
      const lock = srv.units[u] && srv.units[u].lock;
      if (lock && lock !== 'none') out[u] = lock;
    });
    return out;
  }

  const genderBySeat = {};
  DB.bookings.forEach(b => {
    if (b.status !== 'pending' && b.status !== 'confirmed') return;
    const scan = (legRouteId, legDate, passengers) => {
      if (legRouteId !== routeId || legDate !== date) return;
      (passengers || []).forEach(p => { if (p.seat && p.gender) genderBySeat[p.seat] = p.gender; });
    };
    scan(b.routeId, b.date, b.passengers);
    if (b.ret) scan(b.ret.routeId, b.ret.date, b.passengers.map((p, i) => ({ seat: (b.ret.seats || [])[i], gender: p.gender })));
  });

  const byUnit = {};
  Object.keys(genderBySeat).forEach(seat => {
    const u = unitKeyJS(seat);
    (byUnit[u] = byUnit[u] || []).push(genderBySeat[seat]);
  });

  const locks = {};
  Object.keys(byUnit).forEach(u => {
    const gs = byUnit[u];
    const hasM = gs.indexOf('Male') >= 0, hasF = gs.indexOf('Female') >= 0;
    locks[u] = (hasM && hasF) ? 'mixed_allowed' : (hasF ? 'female_only' : (hasM ? 'male_only' : 'none'));
  });
  return locks;
}

function skeletonSeatRows() {
  let out = '';
  for (let i = 0; i < 8; i++) {
    out += '<div class="seat-row" aria-hidden="true">'
      + '<span class="skel" style="height:40px;border-radius:9px"></span><span class="skel" style="height:40px;border-radius:9px"></span>'
      + '<span class="aisle"></span>'
      + '<span class="skel" style="height:40px;border-radius:9px"></span><span class="skel" style="height:40px;border-radius:9px"></span>'
      + '</div>';
  }
  return out;
}

/* ----------------------------------------------------------------
   FLOOR-FIRST GROUP SUGGESTION (Task 3) — the "How many seats?" picker.
   Highlights + selects N seats on FLOOR 1 (lower deck) first, keeping the
   group together on one floor when it fits and only spilling to FLOOR 2
   (upper deck) when Floor 1 cannot hold the whole group. A smart DEFAULT,
   not a restriction: everything runs through the SAME click path a finger
   takes (lock + pair rule + summary), so the traveller can still tap any
   seat to change the picks. Sleeper has two floors; seater has one; a
   private cabin maps the count to the Single/Double tier (max 2/cabin). */
/* Floor preference: 'ANY' (both decks, default) | 'L' | 'U' — remembered per device. */
function deckPrefGet() {
  try { const v = localStorage.getItem('shg:deckPref'); return (v === 'L' || v === 'U') ? v : 'ANY'; } catch (e) { return 'ANY'; }
}
function deckPrefSet(v) {
  Flow.deckPref = (v === 'L' || v === 'U') ? v : 'ANY';
  try { if (Flow.deckPref === 'ANY') localStorage.removeItem('shg:deckPref'); else localStorage.setItem('shg:deckPref', Flow.deckPref); } catch (e) {}
}
function applyDeckPref(grid) {
  const pref = deckPrefGet();
  const blocks = $$('.deck-block', grid);
  blocks.forEach(b => b.classList.toggle('hide', pref !== 'ANY' && b.getAttribute('data-deck') !== pref));
  grid.classList.toggle('both-decks', pref === 'ANY' && blocks.length > 1);
}
function deckHeadHTML(key, label) {
  const name = key === 'U' ? t('deckU') : t('deckL');
  return '<div class="deck-head">' + (key === 'U' ? '🔼' : '🔽') + ' ' + esc(name) + (label ? ' <small>· ' + esc(label) + '</small>' : '') + '</div>';
}

/* ----------------------------------------------------------------
   SEAT SUGGESTION (4 Sep 2026) — scored, not "first free button".
   Floors come in preference order (patient / Floor-1 pref → lower first,
   Floor-2 pref → upper first). A row that can seat the WHOLE group is
   preferred so a family stays together; inside a row a solo traveller gets
   a window seat and a patient gets an aisle-side seat (easy to reach, near
   the door). Everything is still committed through the real seat click, so
   the hold, the pair rule and the summary behave exactly as a finger would.
---------------------------------------------------------------- */
function pickSeatsSmart(floors, n, patient) {
  const free = (el) => Array.prototype.slice.call(el.querySelectorAll('.seat:not([disabled]):not(.sel)'));
  const score = (btn) => {
    const row = btn.closest('.seat-row'); if (!row) return 0;
    const seatsInRow = Array.prototype.slice.call(row.querySelectorAll('.seat'));
    const i = seatsInRow.indexOf(btn), last = seatsInRow.length - 1;
    const aisleEl = row.querySelector('.aisle');
    const leftCount = aisleEl ? seatsInRow.filter(s => !!(aisleEl.compareDocumentPosition(s) & Node.DOCUMENT_POSITION_PRECEDING)).length : 0;
    const isAisle = leftCount > 0 && (i === leftCount - 1 || i === leftCount);
    const isWindow = i === 0 || i === last;
    /* Women-only seats rank last for an automatic pick (gender is not known
       yet on this screen); a traveller can still tap one deliberately. */
    const femPenalty = btn.classList.contains('fem') ? 10 : 0;
    if (patient) return (isAisle ? 3 : (isWindow ? 0 : 1)) - femPenalty;
    return (n === 1 ? (isWindow ? 2 : (isAisle ? 1 : 0)) : 0) - femPenalty;
  };
  const byScore = (list) => list.slice().sort((a, b) => score(b) - score(a));
  const chosen = [];
  for (let fi = 0; fi < floors.length && chosen.length < n; fi++) {
    const rows = Array.prototype.slice.call(floors[fi].querySelectorAll('.seat-row'));
    const need = n - chosen.length;
    const together = rows.find(rw => free(rw).length >= need);
    if (together) { chosen.push.apply(chosen, byScore(free(together)).slice(0, need)); break; }
    for (let ri = 0; ri < rows.length && chosen.length < n; ri++) {
      chosen.push.apply(chosen, byScore(free(rows[ri])).slice(0, n - chosen.length));
    }
  }
  return chosen;
}

function suggestSeats(n) {
  const r = Flow.route;
  if (!r || location.hash !== '#/seats') return;
  const grid = $('#seatGrid'); if (!grid) return;
  n = Math.max(1, Math.min(parseInt(n, 10) || 1, CONFIG.booking.maxSeats));

  const isPrivate = r.type === 'sleeper' && Flow.bookingType === 'private';
  if (isPrivate) {
    // A private cabin seats at most 2 (Single/Double). Pin the tier so the
    // click path applies the correct pair rule and the continue-gate matches.
    if (n >= 2) { Flow.sharingTier = 'double'; n = 2; }
    else { Flow.sharingTier = 'single'; }
    Flow.tierManual = true;
    if (typeof updateTierUI === 'function') updateTierUI();
  }

  // "Give me N" — start from a clean selection (clear directly so a private
  // pair-toggle can't re-add a berth mid-clear).
  const ctxDate = (typeof legCtx === 'function' && legCtx()) ? legCtx().date : Flow.date;
  Flow.seats.slice().forEach(sid => {
    const b = grid.querySelector('.seat[data-id="' + sid + '"]');
    if (b) b.classList.remove('sel');
    unlockSeat(r.id, ctxDate, sid);
  });
  Flow.seats = [];
  $$('.seat.suggested', grid).forEach(b => b.classList.remove('suggested'));

  // Floors in preference order. Seater has a single deck block (or none →
  // the whole grid). A patient always starts on the lower deck.
  const blocks = $$('.deck-block', grid);
  const floors = blocks.length ? Array.prototype.slice.call(blocks) : [grid];
  const pref = deckPrefGet();
  const patient = !!Flow.patient;
  const deckOf = (f) => (f.getAttribute ? f.getAttribute('data-deck') : '') || '';
  const upperFirst = pref === 'U' && !patient;
  const order = floors.filter(f => (deckOf(f) === 'U') === upperFirst)
    .concat(floors.filter(f => (deckOf(f) === 'U') !== upperFirst));

  const picks = pickSeatsSmart(order, n, patient);

  // A pick on a floor the traveller had hidden (a spill-over) reveals both
  // floors again rather than selecting seats they cannot see.
  const pickDecks = picks.map(p => { const b = p.closest('.deck-block'); return b ? b.getAttribute('data-deck') : ''; });
  if (pref !== 'ANY' && pickDecks.some(d => d && d !== pref)) {
    deckPrefSet('ANY');
    const tabs = $('#deckTabs');
    if (tabs) $$('.deck-tab', tabs).forEach(tb => tb.classList.toggle('on', tb.getAttribute('data-deck') === 'ANY'));
    applyDeckPref(grid);
  }
  if (picks[0]) { const blk = picks[0].closest('.deck-block'); if (blk && blk.scrollIntoView) { try { blk.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {} } }

  for (let i = 0; i < picks.length && Flow.seats.length < n; i++) {
    if (picks[i].classList.contains('sel') || picks[i].disabled) continue;   // a pair-rule click may have taken it already
    const before = Flow.seats.length;
    picks[i].click();                          // real path: lock + pair rule + summary
    if (Flow.seats.length === before) break;   // refused (max reached)
  }

  // Brief "suggested" pulse on whatever got selected.
  $$('.seat.sel', grid).forEach(b => b.classList.add('suggested'));
  setTimeout(() => $$('.seat.suggested', grid).forEach(b => b.classList.remove('suggested')), 1600);

  updateSeatSummary();
  if (Flow.seats.length) {
    const firstDeck = pickDecks[0] || deckOf(order[0]);
    toast('✅ ' + tf('seatSuggestDone', { n: Flow.seats.length, f: firstDeck === 'U' ? t('floor2') : t('floor1') }));
  }
}

let SEAT_TOKEN = 0;
function renderSeats(instant) {
  const r = Flow.route; if (!r) return;
  const ctx = legCtx();

  /* Pull the real occupancy for this bus and date, then redraw once — and
     only once, because load() resolves false when nothing changed and the
     fresh cache short-circuits the second call. Drawing first and correcting
     on arrival keeps the map instant on a slow connection. The current
     booking mode is passed so SeatSrv caches the correct layout geometry
     (sharing = 72 in 4+2, private = 30 in 2+1) — the toggle handler below
     just calls renderSeats(true) and the right layout follows. */
  SeatSrv.load(r.id, ctx.date, false, Flow.bookingType || (r.type === 'sleeper' ? 'sharing' : undefined)).then((changed) => {
    if (changed && location.hash === '#/seats' && Flow.route && Flow.route.id === r.id) {
      /* A berth this visitor had picked may have sold underneath them. Drop
         it from the selection before redrawing rather than letting checkout
         fail on it later. */
      const gone = bookedSeatsFor(r.id, ctx.date).concat(lockedSeatsFor(r.id, ctx.date));
      const lost = Flow.seats.filter(s => gone.indexOf(s) >= 0);
      if (lost.length) {
        Flow.seats = Flow.seats.filter(s => gone.indexOf(s) < 0);
        toast(tf('tSeatGone', { s: seatLabelJoin(lost, r.type, Flow.bookingType) }));
      }
      renderSeats(true);
    }
  });

  let info = esc(r.busName) + ' · ' + esc(r.busNo) + ' · ' + fmtDate(ctx.date);
  if (Flow.tripType === 'round') info += ' · ' + t(ctx.ret ? 'tkRet' : 'tkOut') + ' (' + (Flow.legIndex + 1) + '/2)';
  $('#seatRouteInfo').textContent = info;
  var ssi = $('#seatStickyInfo');
  if (ssi) ssi.innerHTML = '<span class="sfi-route">' + esc(ctx.from) + ' → ' + esc(ctx.to) + '</span><span class="sfi-date">' + fmtDate(ctx.date) + ' · ' + esc(r.busName) + '</span><span class="sfi-fare">' + (r.type === 'sleeper' ? 'from ' + inr(sharingPP(ctx.to, true)) : inr(r.fare)) + '</span>';
  $('#sumRoute').textContent = ctx.from + ' → ' + ctx.to;
  $('#sumDate').textContent = fmtDate(ctx.date);
  $('#sumBus').textContent = r.busName + ' (' + r.busNo + ')';

  /* Boarding / drop selects — value keeps the raw string, label adds time &
     landmark. Options are the canonical string format the whole pipeline
     understands ("Name · Landmark @ HH:MM [lat,lng]"); see api/search.php's
     $encodeStop for the producer. Empty is coerced away so a route with no
     stop rows never lands here as [undefined] and never posts "" for the
     boarding column that drives the PDF ticket header. */
  const fill = (sel, arr) => {
    const opts = (arr || [])
      .map(p => (typeof p === 'string' ? p : '').trim())
      .filter(Boolean);
    sel.innerHTML = opts.map(p => '<option value="' + esc(p) + '">' + esc(bpLabel(p)) + '</option>').join('');
  };
  fill($('#boardingSel'), r.boarding);
  fill($('#dropSel'), r.drop);

  /* Pre-select the stop matching the customer's SEARCH origin/destination.
     Without this, a customer who searched "Ahmedabad" would land with the
     first stop in the route (Surat) already selected, so a distracted
     "Continue" tap would print Surat 13:00 on their Emli Bhupal 19:00
     ticket. The server did the matching with Boarding::townKey (identical
     to the cut-off normaliser); we just apply the index it returned. -1
     means "no explicit match" → keep the browser default (first option),
     so behaviour on old routes without stop rows is unchanged. */
  const bSel = $('#boardingSel');
  const dSel = $('#dropSel');
  if (bSel && typeof r.boardingIdx === 'number' && r.boardingIdx >= 0 && r.boardingIdx < bSel.options.length) {
    bSel.selectedIndex = r.boardingIdx;
  }
  if (dSel && typeof r.dropIdx === 'number' && r.dropIdx >= 0 && r.dropIdx < dSel.options.length) {
    dSel.selectedIndex = r.dropIdx;
  }

  $('#seatMaxNote').textContent = tf('seatNote', { n: CONFIG.booking.maxSeats });

  /* Cabin booking type toggle — only for sleeper coaches */
  const cabinToggle = $('#cabinToggle');
  if (r.type === 'sleeper') {
    cabinToggle.classList.remove('hide');
    if (!Flow.bookingType) Flow.bookingType = 'sharing';
    $$('.bt-pill', cabinToggle).forEach(p => p.classList.toggle('on', p.getAttribute('data-bt') === Flow.bookingType));
    cabinToggle.onclick = (e) => {
      const pill = e.target.closest('.bt-pill'); if (!pill) return;
      if (pill.getAttribute('data-bt') === Flow.bookingType) return;
      /* Release the holds taken in the OLD mode before switching (4 Sep 2026).
         They used to stay in the local mirror and were re-sent under the new
         mode on the next flush — a stale sharing L3 became a private cabin. */
      const ctxDate = (typeof legCtx === 'function' && legCtx()) ? legCtx().date : Flow.date;
      Flow.seats.slice().forEach(sid => unlockSeat(r.id, ctxDate, sid));
      Flow.bookingType = pill.getAttribute('data-bt');
      /* private has no Triple — coerce here, at the user's own action */
      if (Flow.bookingType === 'private' && Flow.sharingTier === 'triple') Flow.sharingTier = 'double';
      $$('.bt-pill', cabinToggle).forEach(p => p.classList.toggle('on', p === pill));
      Flow.seats = [];
      renderSeats(true);
    };
  } else {
    cabinToggle.classList.add('hide');
    Flow.bookingType = null;
  }

  const grid = $('#seatGrid');
  const tabs = $('#deckTabs');
  const token = ++SEAT_TOKEN;

  const paint = () => {
    if (token !== SEAT_TOKEN) return;
    const booked = bookedSeatsFor(r.id, ctx.date);
    const held = lockedSeatsFor(r.id, ctx.date);
    /* Women-only list comes from the SERVER snapshot (Admin → seat map toggle)
       when present; the hardcoded CONFIG list is only the offline fallback.
       Private cabins are whole cabins, so the list is not painted there. */
    const femSnap = (SeatSrv.snap(r.id, ctx.date, Flow.bookingType) || SeatSrv.snap(r.id, ctx.date) || {}).female;
    const femSet = Flow.bookingType === 'private' ? []
      : ((Array.isArray(femSnap) && femSnap.length) ? femSnap : (CONFIG.femaleSeats[r.type] || []));
    /* Reserved berths (permanent staff pair + the mode-aware emergency berth)
       resolved into the CURRENT booking mode's namespace, mirroring the server
       (Seats::physicalSetToMode): sharing/seater identity; private collapses a
       physical bed Ln -> its cabin L(ceil(n/2)). Without this the private view
       wrongly flagged a normal private L6 as reserved just because the sharing
       staff pair is labelled L5/L6. */
    const reservedSet = reservedSeatsForMode(r.type, Flow.bookingType || 'sharing');
    const femBooked = femaleBookedSeats(r.id, ctx.date);
    const unitLocks = unitGenderLocksFor(r.id, ctx.date);   // Feature A cabin locks
    const crossSet = ((SeatSrv.snap(r.id, ctx.date, Flow.bookingType) || SeatSrv.snap(r.id, ctx.date) || {}).crossMode) || [];
    const seatBtn = (id, slpr) => {
      // Staff / emergency reserved berth — never selectable by a customer; the
      // server rejects it too. Takes precedence over every other state so it
      // always reads as reserved. Includes the mode-aware emergency berth
      // (Private Sleeper -> L3) alongside the permanent staff pair (sharing L5/L6).
      const rsv = reservedSet.indexOf(id) >= 0;
      const bk = booked.indexOf(id) >= 0;
      const hd = !bk && !rsv && held.indexOf(id) >= 0;
      const sl = Flow.seats.indexOf(id) >= 0;
      const fem = !rsv && (femSet.indexOf(id) >= 0 || femBooked[id]);
      // A free bed inherits its cabin's gender lock, so the map shows the
      // rule the server will enforce at checkout. (Part 2 · Feature A)
      const ul = (!bk && !hd && !rsv) ? (unitLocks[unitKeyJS(id)] || 'none') : 'none';
      const uCls = ul === 'female_only' ? ' u-fem' : (ul === 'male_only' ? ' u-male' : '');
      const uTitle = ul === 'female_only' ? ' — 👩 women-only cabin · महिला मात्र' : (ul === 'male_only' ? ' — 👨 men-only cabin · पुरुष मात्र' : '');
      const rTitle = rsv ? ' — 🚨 EMERGENCY · permanently reserved · आपतकालीन सिट (बुक हुँदैन)' : '';
      /* Private mode paints every berth as a cabin (gold + 🔒) — the 6th
         seat state the legend promises (4 Sep 2026). */
      const pvt = r.type === 'sleeper' && Flow.bookingType === 'private';
      /* Taken in the OTHER mode (sharing <-> private): the bed really is
         occupied, but by a sale this view cannot show — a cabin sold private
         closes both sharing beds, one sharing bed closes the whole private
         cabin. Painted distinctly so it never reads as a plain sold seat
         (SHG AI BRAIN 1.3 / 1.4, 10 Sep 2026). */
      const xm = bk && crossSet.indexOf(id) >= 0;
      const xTitle = xm ? (pvt ? ' — ⇄ a bed of this cabin is sold as sharing · साझा सिट बिकेको' : ' — ⇄ sold as a private cabin · निजी केबिन बिकेको') : '';
      let cls = 'seat' + (slpr ? ' slpr' : '') + (rsv ? ' reserved' : '') + (bk || hd ? ' bkd' : '') + (xm ? ' xmode' : '') + (hd ? ' hold' : '') + (sl ? ' sel' : '') + (fem ? ' fem' : '') + (pvt ? ' pvt' : '') + uCls;
      /* Visible label is the row-letter grid id (LA1, UB3…); data-id stays the
         canonical L1/U7 the hold + sale + server speak. */
      const disp = seatLabel(id, r.type, Flow.bookingType || 'sharing');
      return `<button type="button" class="${cls}" data-id="${id}" ${bk || hd || rsv ? 'disabled' : ''} title="Seat ${disp}${rTitle}${uTitle}${xTitle}" aria-label="Seat ${disp}${rsv ? ' (emergency seat, cannot be booked)' : xm ? ' (taken in the other seat type)' : bk ? ' (booked)' : hd ? ' (held)' : ''}${uTitle}">${disp}</button>`;
    };
    const bt = Flow.bookingType || 'sharing';
    /* Real directional per-person fare — what the server will actually
       charge (was showing a stale flat tier price). Offline vs online are
       both shown so the 5% online saving is visible while picking a berth. */
    const PP_OFF  = sharingBasePP(ctx.to);
    const PP_ON   = sharingPP(ctx.to, true);
    const PP_SAVE = Math.max(0, PP_OFF - PP_ON);

    /* Physical layout — server-owned since 29 Aug 2026. `layout.decks[i].rows`
       gives {left, aisle, right} for each row, and layout.seatIds is the
       exact list Seats::seatIds() returns for this coach + mode. This is
       the single source of truth every renderer walks (customer here, admin
       seatmap partial in Round 3). If the snapshot is old (proxy-cached
       pre-Round-1 response) `layout` is null and the legacy math below
       renders the same picture the site drew last month. */
    const layout = (SeatSrv.snap(r.id, ctx.date, bt) || SeatSrv.snap(r.id, ctx.date) || {}).layout;
    const layoutOk = layout && Array.isArray(layout.decks) && layout.decks.length
                     && Array.isArray(layout.seatIds) && layout.seatIds.length;

    if (r.type === 'sleeper') {
      /* Floor preference (4 Sep 2026): Any (both decks on one scroll, each
         with a sticky floor label) / Floor 1 / Floor 2. The choice steers
         the seat suggestion too and is remembered on this device. */
      const deckPref = deckPrefGet();
      tabs.classList.remove('hide');
      tabs.innerHTML = '<span class="deck-lbl">' + t('deckPrefLbl') + '</span>'
        + '<button type="button" class="deck-tab' + (deckPref === 'ANY' ? ' on' : '') + '" data-deck="ANY">' + t('deckAny') + '</button>'
        + '<button type="button" class="deck-tab' + (deckPref === 'L' ? ' on' : '') + '" data-deck="L">' + t('deckL') + '</button>'
        + '<button type="button" class="deck-tab' + (deckPref === 'U' ? ' on' : '') + '" data-deck="U">' + t('deckU') + '</button>';
      tabs.onclick = (e) => {
        const dt = e.target.closest('.deck-tab'); if (!dt) return;
        deckPrefSet(dt.getAttribute('data-deck'));
        $$('.deck-tab', tabs).forEach(tb => tb.classList.toggle('on', tb === dt));
        applyDeckPref(grid);
      };
      const cls = bt === 'private' ? 'slp-pvt' : 'slp-shr6';

      if (layoutOk) {
        /* Server-driven render. Same visual as the legacy path — cabin cards
           with header (sharing) or berth-pair + single (private) — but the
           row geometry (how many left, how many right, which berth IDs)
           comes from Seats::layoutFor() so a different coach layout on the
           server needs zero client changes to render correctly. */
        grid.innerHTML = layout.decks.map(deck => {
          let rowsHtml = '';
          const firstRow = deck.rows[0];
          if (firstRow) {
            const leftN  = (firstRow.left  || []).length;
            const rightN = (firstRow.right || []).length;
            rowsHtml += bt === 'sharing'
              ? '<div class="berth-labels berth-labels6"><span class="bl-dbl">🛏️ ' + leftN + ' seats — अलग-अलग (1-1) बुक</span><span class="bl-aisle"></span><span class="bl-sgl">🛏️ ' + rightN + ' seats</span></div>'
              : '<div class="berth-labels"><span class="bl-dbl">👑 Double Cabin · २ जना (2)</span><span class="bl-aisle"></span><span class="bl-sgl">🛏️ Single Cabin (1)</span></div>';
          }
          deck.rows.forEach((row, ri) => {
            const left  = row.left  || [];
            const right = row.right || [];
            const inThisRow = left.length + right.length;
            const rowNo = ri + 1;
            if (bt === 'private') {
              const pairAttr = left.join(',');
              rowsHtml += '<div class="seat-row ' + cls + '">'
                + '<span class="berth-pair" data-pair="' + esc(pairAttr) + '">' + left.map(id => seatBtn(id, true)).join('') + '</span>'
                + '<span class="aisle">' + rowNo + '</span>'
                + right.map(id => seatBtn(id, true)).join('')
                + '</div>';
            } else {
              /* Sharing row card — per-person rate + live free counter. Row
                 label comes from the server (row.label = "Row L-1", etc.). */
              const rowLabel = row.label || ('Row ' + deck.key + '-' + rowNo);
              rowsHtml += '<div class="cabin-card">'
                + '<div class="cab-head"><span class="cab-no">🚪 ' + esc(rowLabel) + '</span><span class="cab-cap">👥 ' + inThisRow + ' berth' + (inThisRow > 1 ? 's' : '') + ' · अलग-अलग बुक</span><span class="cab-price">'
                + (PP_SAVE > 0 ? '<s class="pp-was">₹' + PP_OFF.toLocaleString('en-IN') + '</s> ' : '')
                + '₹' + PP_ON.toLocaleString('en-IN') + ' /person'
                + (PP_SAVE > 0 ? '<b class="pp-off">5% OFF</b>' : '')
                + '</span><span class="cab-free"></span></div>'
                + '<div class="seat-row ' + cls + '">'
                + left.map(id => seatBtn(id, true)).join('')
                + '<span class="aisle">' + rowNo + '</span>'
                + right.map(id => seatBtn(id, true)).join('')
                + '</div></div>';
            }
          });
          /* Both decks are rendered; applyDeckPref() hides one only when the
             traveller asked for a single floor. */
          return '<div class="deck-block" data-deck="' + esc(deck.key) + '">' + deckHeadHTML(deck.key, deck.label) + rowsHtml + '</div>';
        }).join('');
      } else {
        /* ⚠️ LEGACY FALLBACK — used only when the server layout is missing
           (a proxy that cached /api/seats.php before the layout field
           shipped). Kept byte-identical to the pre-unification renderer so
           the visual doesn't change when it triggers. Safe to delete once
           every ISP cache has cycled through, but harmless to leave. */
        const SHARING_PER_DECK = 36;
        const perRow = bt === 'private' ? 3 : 6;
        const perDeck = bt === 'private' ? 18 : SHARING_PER_DECK;
        const totalRows = Math.ceil(perDeck / perRow);
        grid.innerHTML = ['L', 'U'].map(d => {
          let rows = '';
          if (totalRows > 0) {
            rows += bt === 'sharing'
              ? '<div class="berth-labels berth-labels6"><span class="bl-dbl">🛏️ 4 seats — अलग-अलग (1-1) बुक</span><span class="bl-aisle"></span><span class="bl-sgl">🛏️ 2 seats</span></div>'
              : '<div class="berth-labels"><span class="bl-dbl">👑 Double Cabin · २ जना (2)</span><span class="bl-aisle"></span><span class="bl-sgl">🛏️ Single Cabin (1)</span></div>';
          }
          for (let row = 1; row <= totalRows; row++) {
            const base = (row - 1) * perRow;
            if (bt === 'private') {
              const s1 = d + (base + 1), s2 = d + (base + 2), s3 = d + (base + 3);
              rows += '<div class="seat-row ' + cls + '">'
                + '<span class="berth-pair" data-pair="' + s1 + ',' + s2 + '">' + seatBtn(s1, true) + seatBtn(s2, true) + '</span>'
                + '<span class="aisle">' + row + '</span>'
                + seatBtn(s3, true)
                + '</div>';
            } else {
              const inThisRow = Math.min(perRow, perDeck - base);
              const ids = [];
              for (let k = 1; k <= inThisRow; k++) ids.push(d + (base + k));
              const left  = ids.slice(0, 4);
              const right = ids.slice(4, 6);
              rows += '<div class="cabin-card">'
                + '<div class="cab-head"><span class="cab-no">🚪 Row ' + d + '-' + row + '</span><span class="cab-cap">👥 ' + inThisRow + ' berth' + (inThisRow > 1 ? 's' : '') + ' · अलग-अलग बुक</span><span class="cab-price">'
                + (PP_SAVE > 0 ? '<s class="pp-was">₹' + PP_OFF.toLocaleString('en-IN') + '</s> ' : '')
                + '₹' + PP_ON.toLocaleString('en-IN') + ' /person'
                + (PP_SAVE > 0 ? '<b class="pp-off">5% OFF</b>' : '')
                + '</span><span class="cab-free"></span></div>'
                + '<div class="seat-row ' + cls + '">'
                + left.map(function (id) { return seatBtn(id, true); }).join('')
                + '<span class="aisle">' + row + '</span>'
                + right.map(function (id) { return seatBtn(id, true); }).join('')
                + '</div></div>';
            }
          }
          return '<div class="deck-block" data-deck="' + d + '">' + deckHeadHTML(d, '') + rows + '</div>';
        }).join('');
      }
      applyDeckPref(grid);
      /* ♿ lower-deck door-side berth flagged differently-abled friendly (Module B) */
      const daBtn = grid.querySelector('.seat[data-id="L3"]');
      if (daBtn && !daBtn.disabled) {
        daBtn.classList.add('da');
        daBtn.title = '♿ Differently-abled friendly berth — lower deck, ढोका नजिक · priority for differently-abled passengers (anyone may book)';
      }
      /* Live availability per cabin card — booked/held berths reduce the count */
      grid.querySelectorAll('.cabin-card').forEach(cc => {
        const total = cc.querySelectorAll('.seat').length;
        const free = total - cc.querySelectorAll('.seat:disabled').length;
        const fe = cc.querySelector('.cab-free');
        if (fe) {
          fe.textContent = free ? (free + '/' + total + ' खाली · free') : 'FULL';
          fe.classList.toggle('cab-full', !free);
        }
      });
    } else {
      tabs.classList.add('hide');
      if (layoutOk) {
        /* Server-driven seater — one deck, N rows × (left|aisle|right). */
        const deck = layout.decks[0];
        grid.innerHTML = deck.rows.map((row, ri) => {
          const left  = row.left  || [];
          const right = row.right || [];
          const rowNo = ri + 1;
          return '<div class="seat-row">'
            + left.map(id => seatBtn(id)).join('')
            + '<span class="aisle">' + rowNo + '</span>'
            + right.map(id => seatBtn(id)).join('')
            + '</div>';
        }).join('');
      } else {
        let rows = '';
        for (let row = 1; row <= 10; row++) {
          const ids = [row + 'A', row + 'B', row + 'C', row + 'D'];
          rows += '<div class="seat-row">' + seatBtn(ids[0]) + seatBtn(ids[1])
            + '<span class="aisle">' + row + '</span>'
            + seatBtn(ids[2]) + seatBtn(ids[3]) + '</div>';
        }
        grid.innerHTML = rows;
      }
    }
    updateSeatSummary();
  };

  if (instant) paint();
  else { grid.innerHTML = skeletonSeatRows(); tabs.classList.add('hide'); setTimeout(paint, 340); }

  // Seat click handler (tap to select — locks the seat for this visitor)
  grid.onclick = (e) => {
    const btn = e.target.closest('.seat'); if (!btn || btn.disabled) return;
    const id = btn.getAttribute('data-id');
    const pair = btn.closest('.berth-pair');
    /* Pair auto-select is a PRIVATE-cabin behaviour only —
       sharing seats are always booked अलग-अलग (1-1) */
    const privateCabin = r.type === 'sleeper' && Flow.bookingType === 'private';
    let pairIds = null;
    if (privateCabin && Flow.sharingTier === 'double' && pair) {
      pairIds = pair.getAttribute('data-pair').split(',');            // Double cabin → both berths together
    }
    const toggleSeat = (sid) => {
      const ix = Flow.seats.indexOf(sid);
      const b = grid.querySelector('.seat[data-id="' + sid + '"]');
      if (ix >= 0) { Flow.seats.splice(ix, 1); if (b) b.classList.remove('sel'); unlockSeat(r.id, ctx.date, sid); shgHaptic('tap'); }
      else {
        // At the cap: say so instead of silently ignoring the tap (3 Sep 2026).
        if (Flow.seats.length >= CONFIG.booking.maxSeats) { try { toast(tf('seatNote', { n: CONFIG.booking.maxSeats })); } catch (e) {} shgHaptic('error'); return; }
        Flow.seats.push(sid); if (b) b.classList.add('sel'); lockSeat(r.id, ctx.date, sid); shgHaptic('select');
      }
    };
    const idx = Flow.seats.indexOf(id);
    if (idx >= 0) {
      if (pairIds) pairIds.forEach(toggleSeat); else toggleSeat(id);
    } else {
      if (pairIds) { pairIds.filter(s => Flow.seats.indexOf(s) < 0).forEach(s => { const b = grid.querySelector('.seat[data-id="' + s + '"]'); if (b && !b.disabled) toggleSeat(s); }); }
      else toggleSeat(id);
    }
    updateHoldPills();
    updateSeatSummary();
  };

  /* Auto-pick. A private cabin is sold whole, so it fills the tier's berth
     count; sharing and seater are sold seat-by-seat, so it takes the best
     single free seat and can be tapped again for the next passenger. */
  const autoBtn = $('#seatAutoPick');
  if (autoBtn) {
    const cabinPax = () => (r.type === 'sleeper' && Flow.bookingType === 'private'
        && Flow.sharingTier && SHARING_TIERS[Flow.sharingTier])
      ? SHARING_TIERS[Flow.sharingTier].pax : 0;

    const syncAuto = () => {
      const whole = cabinPax();
      const target = whole || (Flow.seats.length + 1);
      const full = Flow.seats.length >= (whole || CONFIG.booking.maxSeats);
      autoBtn.textContent = whole ? '⚡ ' + tf('seatAutoCabin', { n: whole }) : '⚡ ' + t('seatAutoOne');
      autoBtn.disabled = full || !grid.querySelector('.seat:not([disabled]):not(.sel)');
      return target;
    };

    autoBtn.onclick = () => {
      const target = syncAuto();
      if (autoBtn.disabled) return;
      /* Go through .click() rather than mutating Flow.seats directly: that
         is what locks the seat, applies the double-cabin pair rule and
         redraws the summary. A bounded loop, because each click can take
         two berths and a full bus must not spin. */
      let guard = 0;
      while (Flow.seats.length < target && guard++ < CONFIG.booking.maxSeats * 3) {
        const free = grid.querySelector('.seat:not([disabled]):not(.sel)');
        if (!free) break;
        const before = Flow.seats.length;
        free.click();
        if (Flow.seats.length === before) break;   // refused (max reached) — stop
      }
      syncAuto();
      if (Flow.seats.length) toast('✅ ' + tf('seatAutoDone', { s: seatLabelJoin(Flow.seats, r.type, Flow.bookingType) }));
    };

    const prevClick = grid.onclick;
    grid.onclick = (e) => { prevClick(e); syncAuto(); };
    syncAuto();
  }

  /* "How many seats?" group picker — floor-first suggestion (Task 3). Wired
     each render (idempotent via onclick=). Reads the grid live at tap time, so
     it works regardless of whether paint has finished yet. */
  const countPicker = $('#seatCountPicker');
  if (countPicker) {
    /* The buttons follow Admin → Settings → max seats per booking (capped at
       10 buttons — beyond that the customer just taps seats). Rebuilt only
       when the count changed, so a re-render never wipes the highlight.
       A counter session (bulk booking, 5 Sep 2026) gets the full 20 — a
       party of 15 at the desk should not have to tap 15 seats one by one. */
    const _staffSell = !!(window.SHG_BOOT && window.SHG_BOOT.staff && window.SHG_BOOT.staff.canSell);
    const maxN = Math.max(1, Math.min(_staffSell ? 20 : 10, parseInt(CONFIG.booking.maxSeats, 10) || 6));
    const btnBox = $('.scp-btns', countPicker);
    if (btnBox && $$('.scp-n', btnBox).length !== maxN) {
      btnBox.innerHTML = Array.from({ length: maxN }, (_, i) =>
        '<button type="button" class="scp-n" data-n="' + (i + 1) + '">' + (i + 1) + '</button>').join('');
    }
    countPicker.onclick = (e) => {
      const b = e.target.closest('.scp-n'); if (!b) return;
      $$('.scp-n', countPicker).forEach(x => x.classList.toggle('on', x === b));
      suggestSeats(parseInt(b.getAttribute('data-n'), 10));
    };
  }
  /* Patient / birami chip (4 Sep 2026): lower deck + aisle-side suggestion
     and a priority-boarding flag on the lead passenger. */
  const patientChip = $('#seatPatient');
  if (patientChip) {
    const prefRow = $('#seatPrefRow');
    const syncPatient = () => {
      patientChip.classList.toggle('on', !!Flow.patient);
      patientChip.setAttribute('aria-pressed', Flow.patient ? 'true' : 'false');
      if (prefRow) prefRow.classList.toggle('on', !!Flow.patient);
    };
    patientChip.onclick = () => {
      Flow.patient = !Flow.patient;
      syncPatient();
      shgHaptic('tap');
      const on = countPicker ? $$('.scp-n.on', countPicker)[0] : null;
      if (Flow.patient && (on || Flow.seats.length)) suggestSeats(on ? parseInt(on.getAttribute('data-n'), 10) : Flow.seats.length);
    };
    syncPatient();
  }

  updateSeatSummary();
  startHoldTicker();
  /* Keep this map honest while it is open: another customer's booking
     should turn a berth red here without the passenger refreshing. Stops
     itself when the view or the tab goes away. */
  SeatPoll.start(r.id, ctx.date);
  $('#continueBtn').onclick = () => {
    if (!Flow.seats.length) { toast(t('tPickSeat')); return; }
    /* Cabin↔seat-count gate: Single Cabin=1, Double Cabin=2 — PRIVATE only.
       Sharing has no gate: हरेक seat अलग-अलग, जति चाहिन्छ त्यति। */
    if (r.type === 'sleeper' && Flow.bookingType === 'private' && Flow.sharingTier && SHARING_TIERS[Flow.sharingTier]) {
      const needPax = SHARING_TIERS[Flow.sharingTier].pax;
      if (Flow.seats.length !== needPax) {
        const st = $('#seatPickStatus');
        if (st) { st.classList.add('warn'); st.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        toast('⚠️ ' + sharingTierLabel(Flow.sharingTier) + ' tier = ' + needPax + ' seat. '
          + (Flow.seats.length < needPax
            ? (needPax - Flow.seats.length) + ' more needed · अरू ' + (needPax - Flow.seats.length) + ' सिट छान्नुहोस्'
            : (Flow.seats.length - needPax) + ' extra — हटाउनुहोस् / हटाएँ'));
        return;
      }
    }
    if (Flow.tripType === 'round' && Flow.legIndex === 1 && Flow.legs[0] && Flow.seats.length !== Flow.legs[0].seats.length) {
      toast(tf('tRetCount', { n: Flow.legs[0].seats.length })); return;
    }
    const legData = {
      routeId: r.id, date: ctx.date, seats: Flow.seats.slice(),
      boarding: $('#boardingSel').value, drop: $('#dropSel').value, fare: r.fare,
      sid: Flow.scheduleId || 0,  // extra bus on this date (Bus Calendar, 5 Sep 2026); 0 = daily bus
      fareOverride: Flow.fareOverride || 0   // this bus's own per-seat price; 0 = the normal fare
    };
    if (r.type === 'sleeper' && Flow.bookingType) {
      legData.bookingType = Flow.bookingType;
      if (Flow.bookingType === 'private') {
        const tierCab = SHARING_TIERS[Flow.sharingTier];
        legData.cabinType = tierCab ? tierCab.cabin : 'single';
      } else {
        legData.cabinType = 'single';   // sharing = per-berth (1-1) booking
      }
    }
    Flow.legs[Flow.legIndex] = legData;
    if (Flow.tripType === 'round' && Flow.legIndex === 0) {
      Flow.legIndex = 1; Flow.route = null; Flow.seats = []; Flow.scheduleId = 0; Flow.fareOverride = 0;
      location.hash = '#/results';
    } else {
      location.hash = '#/checkout';
    }
  };
  $('#seatBackBtn').onclick = () => { location.hash = '#/results'; };
}

/* Legs already finished + the one being picked right now */
function currentLegs() {
  const legs = Flow.legs.slice(0, Flow.legIndex);
  if (Flow.route && Flow.seats.length) {
    legs.push({ routeId: Flow.route.id, date: legCtx().date, seats: Flow.seats, fare: Flow.route.fare, sid: Flow.scheduleId || 0, fareOverride: Flow.fareOverride || 0 });
  }
  return legs.filter(Boolean);
}

function fareRowsHTML(f) {
  const gd = CONFIG.booking.groupDiscount;
  /* Fare by passenger count: "2 passenger(s) × ₹2,000" reads at a glance. */
  const perSeat = f.perSeat || (f.seats ? Math.round(f.base / f.seats) : 0);
  let rows = '<div class="sum-row"><span>' + tf('rowPaxFare', { n: f.seats, f: inr(perSeat) }) + '</span><b>' + inr(f.base) + '</b></div>';
  if (f.discount > 0) rows += '<div class="sum-row disc"><span>' + t('rowGroup') + ' (' + gd.percent + '%)</span><b>− ' + inr(f.discount) + '</b></div>';
  rows += '<div class="sum-row"><span>' + t('rowFee') + '</span><b>' + (f.fee > 0 ? inr(f.fee) : t('feeFree')) + '</b></div>';
  return rows;
}

function updateSeatSummary() {
  const chips = $('#sumSeats');
  chips.innerHTML = Flow.seats.length
    ? Flow.seats.map(s => '<span class="chip">' + seatLabel(s, Flow.route && Flow.route.type, Flow.bookingType) + '</span>').join('')
    : '<span style="color:var(--muted);font-weight:500">' + t('sumNone') + '</span>';

  const r = Flow.route;
  const isSleeper = r && r.type === 'sleeper' && Flow.bookingType;

  /* Cabin Intelligence — VIP gold theme for private, blue emphasis for
     sharing. Null-safe: seater coaches (no bookingType) clear both. */
  const seatView = $('#view-seats');
  if (seatView) {
    seatView.classList.toggle('premium-mode', !!(isSleeper && Flow.bookingType === 'private'));
    seatView.classList.toggle('sharing-mode', !!(isSleeper && Flow.bookingType === 'sharing'));
  }

  let recapTotal = 0;
  if (isSleeper && Flow.seats.length) {
    const seatLabels = Flow.seats;
    const hasDouble = seatLabels.some(s => /^D/i.test(s));
    const cabinType = hasDouble ? 'double' : 'single';
    const cf = calcCabinFare(cabinType, Flow.bookingType, Flow.seats.length, true, Flow.route && Flow.route.to, Flow.fareOverride);
    recapTotal = cf.total;
    let rows = '<div class="sum-row"><span>' + cf.emoji + ' ' + cf.label + '</span><b>' + inr(cf.total) + '</b></div>';
    if (cf.saved > 0) rows += '<div class="sum-row disc"><span>🌐 Online Discount</span><b>− ' + inr(cf.saved) + '</b></div>';
    rows += '<div class="sum-row"><span>' + tf('rowPaxFare', { n: Flow.seats.length, f: inr(cf.perPerson) }) + '</span><b>' + inr(cf.total) + '</b></div>';
    const altMode = Flow.bookingType === 'sharing' ? 'private' : 'sharing';
    const altCf = calcCabinFare(cabinType, altMode, Flow.seats.length, true, Flow.route && Flow.route.to, Flow.fareOverride);
    rows += '<div class="sum-row" style="border-top:1px dashed var(--line);padding-top:6px;margin-top:4px;opacity:.7"><span>' + altCf.emoji + ' ' + (altMode === 'sharing' ? 'Sharing' : 'Private') + '</span><b>' + inr(altCf.total) + '</b></div>';
    rows += '<div class="sum-row" style="opacity:.7"><span>' + (altMode === 'sharing' ? '🤝 per person' : '🔒 per person') + '</span><b>' + inr(altCf.perPerson) + '</b></div>';
    $('#fareRows').innerHTML = rows;
    $('#sumFare').innerHTML = inr(cf.total) + ' <small style="color:var(--muted);font-weight:500">' + nprEst(cf.total) + '</small>';
  } else {
    const f = calcFare(currentLegs());
    recapTotal = f.total;
    $('#fareRows').innerHTML = fareRowsHTML(f);
    $('#sumFare').innerHTML = inr(f.total) + ' <small style="color:var(--muted);font-weight:500">' + nprEst(f.total) + '</small>';
  }
  /* Sticky recap in the fixed Continue bar (17 Sep 2026): on a phone the
     summary card scrolls away under the map, so the bar itself reads
     "L3, L4 · ₹4,000" — the same chips and total as above. Empty when
     nothing is picked, and the CSS hides an empty recap. */
  const recap = $('#seatRecap');
  if (recap) recap.textContent = Flow.seats.length
    ? Flow.seats.map(s => seatLabel(s, Flow.route && Flow.route.type, Flow.bookingType)).join(', ') + ' · ' + inr(recapTotal)
    : '';
  $('#continueBtn').disabled = !Flow.seats.length;
  updateTierUI();
}

/* ----------------------------------------------------------------
   SHARING SLEEPER TIERS — 🛏️ Single / 👥 Double / 👨‍👩‍👧 Triple.
   Only shown when bookingType === 'sharing' on a sleeper coach.
   Flow.sharingTier: 'single'|'double'|'triple' (auto-defaults from
   the selected seat count until the traveller taps a pill).
---------------------------------------------------------------- */
/* ----------------------------------------------------------------
   CABIN DATA MODEL (Module B) — seating vs sleeping capacity made
   explicit per cabin type, mirroring CONFIG.cabinPricing figures.
   seatingCapacity = बस्ने ठाउँ (daytime), sleepingCapacity = सुत्ने
   ठाउँ (night) — the two can differ in the same physical cabin.
---------------------------------------------------------------- */
const CABIN_TYPES = [
  { id: 'single_sleeper', label: 'Single Sleeper', seatingCapacity: 2, sleepingCapacity: 3,
    sharing: { capForPricing: 2, offline: 4400, online: 4180, perPerson: 2090 },
    private: { cap: 1, offline: 3800, online: 3800, note: 'Solo privacy' } },
  { id: 'double_sleeper_3p', label: 'Double Sleeper · 3P', seatingCapacity: 3, sleepingCapacity: 3,
    sharing: { capForPricing: 3, offline: 7500, online: 7125, perPerson: 2375 } },
  { id: 'double_sleeper_4p', label: 'Double Sleeper · 4P', seatingCapacity: 3, sleepingCapacity: 4,
    sharing: { capForPricing: 4, offline: 8800, online: 8360, perPerson: 2090 } },
  { id: 'double_sleeper_private', label: 'Double Sleeper', seatingCapacity: 2, sleepingCapacity: 2,
    private: { cap: 2, offline: 7600, online: 7600, note: 'Couple / Family' } }
];
function cabinTypeFor(tier, mode) {
  if (mode === 'private') return tier === 'double' ? CABIN_TYPES[3] : CABIN_TYPES[0];
  return tier === 'triple' ? CABIN_TYPES[1] : CABIN_TYPES[0];
}

const SHARING_TIERS = {
  single: { pax: 1, cabin: 'single', emoji: '🛏️', en: 'Single', berths: 1,
            rule: 'एकल यात्रीका लागि १ बर्थ · अकेले यात्री के लिए 1 बर्थ · 1 berth for a solo traveller.' },
  double: { pax: 2, cabin: 'double', emoji: '👥', en: 'Double', berths: 2,
            rule: 'जोडी/साथीका लागि २ बर्थ सँगै · जोड़े/मित्र के लिए 2 बर्थ साथ-साथ · 2 berths side-by-side for couples & friends.' },
  triple: { pax: 3, cabin: 'double', emoji: '👨‍👩‍👧', en: 'Triple', berths: 3,
            rule: 'सानो परिवारका लागि एउटै केबिनमा ३ बर्थ · परिवार के लिए एक केबिन में 3 बर्थ · 3 berths in one cabin for a small family.' }
};
function sharingTierLabel(tier) {
  const ti = SHARING_TIERS[tier];
  return ti ? ti.en + ' ' + ti.emoji : '';
}
function tierFromSeatCount(n) { return n >= 3 ? 'triple' : (n === 2 ? 'double' : 'single'); }

/* Per-person preview via calcCabinFare (its signature/behavior is
   untouched). Triple maps exactly to the 3-pax sharing rate; Single
   and Double have no exact row in CONFIG.cabinPricing.sharing, so the
   closest existing sharing rate is shown with a ≈ marker. */
function tierFarePreview(tier, mode) {
  const ti = SHARING_TIERS[tier] || SHARING_TIERS.single;
  const m = mode === 'private' ? 'private' : 'sharing';
  const cf = calcCabinFare(ti.cabin, m, ti.pax, true, Flow.route && Flow.route.to);
  return { perPerson: cf.perPerson, approx: m === 'sharing' && tier !== 'triple', label: cf.label };
}

function updateTierUI() {
  const box = $('#tierSelect'); if (!box) return;
  const view = $('#view-seats');
  const r = Flow.route;
  const mode = Flow.bookingType;
  /* Tier/cabin pills now belong to PRIVATE only (Single Cabin / Double Cabin).
     Sharing = हरेक seat अलग-अलग (1-1) बुक — no zones, no pairing, no gate. */
  const active = r && r.type === 'sleeper' && mode === 'private';
  if (!active) {
    box.classList.add('hide');
    if (view) view.removeAttribute('data-tier');
    const st0 = $('#seatPickStatus');
    if (st0) {
      if (r && r.type === 'sleeper' && mode === 'sharing') {
        st0.classList.remove('hide', 'warn');
        st0.classList.toggle('ok', Flow.seats.length > 0);
        st0.innerHTML = Flow.seats.length
          ? '✅ <b>' + Flow.seats.length + ' seat selected</b> — हरेक seat अलग-अलग बुक हुन्छ · per-person sharing rate'
          : '🎯 <b>Seat छान्नुहोस्</b> — Sharing मा हरेक seat अलग-अलग (1-1) बुक गर्न मिल्छ · जति चाहिन्छ त्यति लिनुहोस्';
      } else {
        st0.classList.add('hide');
      }
    }
    Flow.tierManual = false;   // re-derive default next time the cabin module is active
    return;
  }
  if (!Flow.tierManual || !SHARING_TIERS[Flow.sharingTier]) {
    Flow.sharingTier = tierFromSeatCount(Flow.seats.length || 1);
  }
  /* effective tier for display — never silently mutate the user's chosen tier
     (a transient render pass must not turn Triple into Double) */
  const effTier = (mode === 'private' && Flow.sharingTier === 'triple') ? 'double' : Flow.sharingTier;
  const tPill = box.querySelector('.tier-pill[data-tier="triple"]');
  if (tPill) tPill.style.display = mode === 'private' ? 'none' : '';
  const tLbl = box.querySelector('.tier-lbl');
  if (tLbl) tLbl.textContent = mode === 'private'
    ? '🔒 Private cabin छान्नुहोस् — Single (1 जना) वा Double (2 जना)'
    : '🤝 Sharing tier · साझा टियर छान्नुहोस् · शेयरिंग टियर चुनें';
  box.classList.remove('hide');
  if (view) view.setAttribute('data-tier', effTier);
  $$('.tier-pill', box).forEach(p => {
    const on = p.getAttribute('data-tier') === effTier;
    p.classList.toggle('on', on);
    p.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  box.onclick = (e) => {
    const pill = e.target.closest('.tier-pill'); if (!pill) return;
    Flow.sharingTier = pill.getAttribute('data-tier');
    Flow.tierManual = true;
    /* half-half zoning — drop seats that sit outside the chosen tier's zone
       (right column = n%3===0 is Single side; left pair = n%3!==0 is Double side) */
    const zoneOk = (sid) => {
      const n = parseInt(String(sid).replace(/\D/g, ''), 10) || 0;
      if (Flow.sharingTier === 'single') return n % 3 === 0;
      if (Flow.sharingTier === 'double') return n % 3 !== 0;
      return true;   // triple: whole row is valid
    };
    const ctxDate = (typeof legCtx === 'function' && legCtx()) ? legCtx().date : Flow.date;
    Flow.seats.slice().forEach(sid => {
      if (zoneOk(sid)) return;
      const ix = Flow.seats.indexOf(sid); if (ix >= 0) Flow.seats.splice(ix, 1);
      const b = document.querySelector('#view-seats .seat[data-id="' + sid + '"]');
      if (b) b.classList.remove('sel');
      if (Flow.route) unlockSeat(Flow.route.id, ctxDate, sid);
    });
    if (typeof SFX !== 'undefined' && SFX.select) SFX.select();
    updateSeatSummary();
  };

  const ti = SHARING_TIERS[effTier];
  const fp = tierFarePreview(effTier, mode);
  $('#tierHint').innerHTML = ti.emoji + ' <b>' + ti.berths + (ti.berths > 1 ? ' berths' : ' berth') + '</b> अपेक्षित / expected · '
    + (fp.approx ? '≈ ' : '') + inr(fp.perPerson)
    + ' <small style="color:var(--muted)">/person · प्रति व्यक्ति'
    + (fp.approx ? ' (निकटतम साझा दर / nearest sharing rate)' : '') + '</small>';
  $('#tierRule').textContent = ti.rule;
  /* Cabin capacity transparency (Module B) — seating vs sleeping made explicit */
  const ct = cabinTypeFor(effTier, mode);
  const ci = $('#cabinInfo');
  if (ci && ct) {
    ci.innerHTML = 'ℹ️ <b>' + esc(ct.label) + '</b> — बस्ने ठाउँ <b>' + ct.seatingCapacity + '</b> जना · सुत्ने ठाउँ <b>' + ct.sleepingCapacity + '</b> जना। '
      + (mode === 'private'
        ? '🔒 Private मा पूरा cabin तपाईंकै हुन्छ — अरू यात्रु हुँदैनन्।'
        : 'Sharing मा तपाईंले आफ्नो berth मात्र किन्नुहुन्छ — बाँकी berth अरू यात्रुले भर्न सक्छन्।');
  }

  /* Occupancy bar — cabin group (row of 3 berths per deck) containing
     the picked seats; falls back to whole-coach occupancy when no seat
     is selected yet. booked + held (other sessions) + my picks. */
  const ctx = legCtx();
  const occSet = {};
  bookedSeatsFor(r.id, ctx.date).forEach(s => { occSet[s] = 1; });
  lockedSeatsFor(r.id, ctx.date).forEach(s => { occSet[s] = 1; });
  Flow.seats.forEach(s => { occSet[s] = 1; });
  let ids = null, lbl = '';
  if (Flow.seats.length) {
    const group = {};
    Flow.seats.forEach(s => {
      const m = /^([LU])(\d+)$/.exec(s); if (!m) return;
      const row = Math.ceil(parseInt(m[2], 10) / 3);
      for (let k = 1; k <= 3; k++) group[m[1] + ((row - 1) * 3 + k)] = 1;
    });
    const gIds = Object.keys(group);
    if (gIds.length) { ids = gIds; lbl = 'केबिन अकुपेन्सी / Cabin occupancy'; }
  }
  if (!ids) { ids = seatIdsFor(r); lbl = 'कोच अकुपेन्सी / Coach occupancy'; }
  const occ = ids.filter(id => occSet[id]).length;
  const pct = ids.length ? Math.round(occ * 100 / ids.length) : 0;
  $('#occLbl').textContent = lbl;
  $('#occPct').textContent = occ + '/' + ids.length + ' (' + pct + '%)';
  $('#occFill').style.transform = 'scaleX(' + (pct / 100) + ')';

  /* Live filled/total counter + "Selected: x of y" status strip (Task 1) */
  const need = ti.pax;
  const have = Flow.seats.length;
  const occCount = $('#occCount');
  if (occCount) {
    occCount.textContent = have + '/' + need;
    occCount.style.color = have === need ? 'var(--ok)' : (have > need ? '#E53935' : 'var(--orange)');
  }
  const st = $('#seatPickStatus');
  if (st) {
    st.classList.remove('hide', 'ok', 'warn');
    const tierName = ti.en + ' ' + ti.emoji;
    let msg;
    if (have === 0) {
      msg = '🎯 <b>Selected: 0 of ' + need + '</b> (' + tierName + ') — seat छान्नुहोस् / सीट चुनें';
    } else if (have < need) {
      msg = '⏳ <b>Selected: ' + have + ' of ' + need + '</b> (' + tierName + ') — <b>' + (need - have) + ' more seat' + (need - have > 1 ? 's' : '') + ' needed</b> · अरू ' + (need - have) + ' सिट छान्नुहोस्';
      st.classList.add('warn');
    } else if (have === need) {
      msg = '✅ <b>Selected: ' + have + ' of ' + need + '</b> (' + tierName + ') — ready for checkout · अब अगाडि बढ्नुहोस्';
      st.classList.add('ok');
    } else {
      msg = '⚠️ <b>Selected: ' + have + ' of ' + need + '</b> (' + tierName + ') — too many! ' + (have - need) + ' seat हटाउनुहोस् / अतिरिक्त सीट हटाएँ';
      st.classList.add('warn');
    }
    if (effTier === 'triple') msg += '<br><small>👨‍👩‍👧 Triple cabin — books all 3 berths together · ३ बर्थ एकसाथ बुक हुन्छ</small>';
    st.innerHTML = msg;
  }
}
