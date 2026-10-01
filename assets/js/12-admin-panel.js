
/* ================================================================
   [JS] 12. ADMIN PANEL — PIN gate, verify payments, search/filter,
   CSV export, revenue cards. (Admin stays English-only.)
================================================================ */
/* §7: the in-app admin has been consolidated into a thin launcher into the
   real RBAC-gated /admin/ panel. ADMIN_ON is kept as a no-op flag so any
   legacy caller doesn't throw, but the gate always renders the launcher and
   the parallel localStorage #adminApp is never shown. */
let ADMIN_ON = false;   // legacy flag — always false; launcher is the only surface
const ADM = { q: '', st: 'all' };

function renderAdminGateOrApp() {
  /* §7 launcher consolidation: always show the launcher gate, never the
     parallel localStorage admin. The gate's "Open Admin Panel" button opens
     the RBAC-protected /admin/ in a new tab (goRealAdmin, wired below). */
  ADMIN_ON = false;
  var g = $('#adminGate'); if (g) g.classList.remove('hide');
  var a = $('#adminApp'); if (a) a.classList.add('hide');
}
function renderAdminAll() { renderAdminStats(); renderAdminCharts(); renderAdminBookings(); renderAdminWaitlist(); renderAdminAgents(); renderAdminRoutes(); renderAdminLiveOps(); renderAdminSettings(); renderAdminMessages(); renderAdminAudit(); renderAdminPayments(); pvSetupSearch(); }

/* A booking's seats as a HUMAN reads them. Seat ids are stored canonically
   (L1..L36 / U1..U36) and the rest of the app prints the grid label
   (A1..F6 lower, A7..F12 upper) through seatLabel() in 06-results.js —
   this table and its CSV export were the last two places that still
   printed the raw id, which is one of the places an "L" could still be
   seen (owner, 25 Sep 2026). b.ret carries its own routeId, so a return
   leg on a different coach type is labelled against ITS coach. */
function admSeats(b, glue, modeFallback) {
  var list = (b && b.seats) || [];
  if (!list.length) return '';
  var r = (typeof routeById === 'function' && routeById(b.routeId)) || {};
  var mode = (b && b.bookingType) || modeFallback || 'sharing';
  if (typeof seatLabelJoin === 'function') return seatLabelJoin(list, r.type || 'sleeper', mode, glue);
  return list.join(glue);
}

function sameDay(ts, ref) { const a = new Date(ts), b = new Date(ref); return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
function sameMonth(ts, ref) { const a = new Date(ts), b = new Date(ref); return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth(); }
function weekStart(ref) { const d = new Date(ref); const day = (d.getDay() + 6) % 7; d.setHours(0, 0, 0, 0); return d.getTime() - day * 86400000; }  // Monday 00:00

function renderAdminStats() {
  const now = Date.now();
  const pend = DB.bookings.filter(b => b.status === 'pending');
  const conf = DB.bookings.filter(b => b.status === 'confirmed');
  $('#statPending').textContent = pend.length;
  $('#statConfirmed').textContent = conf.length;
  $('#statRevToday').textContent = inr(conf.filter(b => sameDay(b.createdAt, now)).reduce((s, b) => s + b.total, 0));
  $('#statRevWeek').textContent = inr(conf.filter(b => b.createdAt >= weekStart(now)).reduce((s, b) => s + b.total, 0));
  $('#statRevMonth').textContent = inr(conf.filter(b => sameMonth(b.createdAt, now)).reduce((s, b) => s + b.total, 0));
  /* Agents + referral programme cards */
  const agents = DB.users.filter(u => u.role === 'agent');
  $('#statAgents').textContent = agents.length;
  const cPend = DB.commissions.filter(c => c.status === 'confirmed').reduce((s, c) => s + c.amount, 0);
  const cPaid = DB.commissions.filter(c => c.status === 'paid').reduce((s, c) => s + c.amount, 0);
  $('#statCommission').innerHTML = inr(cPend) + ' <small style="color:var(--muted)">/ ' + inr(cPaid) + '</small>';
  const ptsMonth = DB.users.reduce((s, u) => s + (u.loyaltyHistory || [])
    .filter(h => h.change > 0 && sameMonth(h.date, now)).reduce((x, h) => x + h.change, 0), 0);
  $('#statPointsMonth').textContent = ptsMonth.toLocaleString('en-IN');

  const sharingRev = conf.filter(b => b.bookingType === 'sharing').reduce((s, b) => s + b.total, 0);
  const privateRev = conf.filter(b => b.bookingType === 'private').reduce((s, b) => s + b.total, 0);
  $('#statSharingRev').textContent = inr(sharingRev);
  $('#statPrivateRev').textContent = inr(privateRev);

  const today = new Date().toISOString().slice(0, 10);
  const todayTrips = conf.filter(b => b.date === today).length;
  $('#statTripsToday').textContent = todayTrips;

  const totalCabins = DB.routes.filter(r => r.active && r.type === 'sleeper').reduce((s, r) => s + Math.floor(seatIdsFor(r).length / 2), 0);
  const bookedCabins = conf.filter(b => b.bookingType && b.date === today).reduce((s, b) => s + Math.ceil(b.seats.length / 2), 0);
  $('#statCabinOcc').textContent = totalCabins ? Math.round(bookedCabins / totalCabins * 100) + '%' : '0%';
  const notify = (DB.waitlist || []).filter(w => w.status === 'notify').length;
  const wlTab = $('#wlTabBtn');
  if (wlTab) wlTab.innerHTML = '📋 Waitlist' + (notify ? ' <span class="wl-dot">' + notify + '</span>' : '');
  const agAttn = DB.users.filter(u => u.agentRequestPending).length
    + DB.payoutRequests.filter(p => p.status === 'requested').length;
  const agTab = $('#agTabBtn');
  if (agTab) agTab.innerHTML = '🤝 Agents' + (agAttn ? ' <span class="wl-dot">' + agAttn + '</span>' : '');
}

/* Lightweight inline-SVG charts — no external chart library on purpose
   (single-file rule). Real data only: bookings/day + revenue/route. */
function svgLineChart(values, labels, w, h) {
  const max = Math.max(1, Math.max.apply(null, values));
  const px = (i) => 30 + i * ((w - 40) / Math.max(1, values.length - 1));
  const py = (v) => (h - 22) - (v / max) * (h - 38);
  const pts = values.map((v, i) => px(i) + ',' + py(v)).join(' ');
  const dots = values.map((v, i) => '<circle cx="' + px(i) + '" cy="' + py(v) + '" r="3" fill="var(--orange)"/>').join('');
  const lbls = labels.map((l, i) => (i % 2 === 0) ? '<text x="' + px(i) + '" y="' + (h - 6) + '" text-anchor="middle" font-size="9" fill="var(--muted)">' + esc(l) + '</text>' : '').join('');
  return '<svg viewBox="0 0 ' + w + ' ' + h + '" role="img" preserveAspectRatio="none" style="width:100%;height:auto">'
    + '<text x="26" y="14" font-size="10" fill="var(--muted)">max ' + max + '</text>'
    + '<polyline points="' + pts + '" fill="none" stroke="var(--blue)" stroke-width="2" stroke-linejoin="round"/>'
    + dots + lbls + '</svg>';
}
function svgBarChart(items, w, h) {
  const max = Math.max(1, Math.max.apply(null, items.map(x => x.v)));
  const bw = Math.min(64, (w - 40) / Math.max(1, items.length) - 14);
  const bars = items.map((x, i) => {
    const bx = 30 + i * ((w - 40) / items.length) + 6;
    const bh = Math.max(2, (x.v / max) * (h - 52));
    return '<rect x="' + bx + '" y="' + (h - 28 - bh) + '" width="' + bw + '" height="' + bh + '" rx="5" fill="var(--blue)" opacity="0.85"/>'
      + '<text x="' + (bx + bw / 2) + '" y="' + (h - 32 - bh) + '" text-anchor="middle" font-size="9.5" fill="var(--ink)">' + esc(x.top) + '</text>'
      + '<text x="' + (bx + bw / 2) + '" y="' + (h - 12) + '" text-anchor="middle" font-size="9" fill="var(--muted)">' + esc(x.label) + '</text>';
  }).join('');
  return '<svg viewBox="0 0 ' + w + ' ' + h + '" role="img" preserveAspectRatio="none" style="width:100%;height:auto">' + bars + '</svg>';
}
function renderAdminCharts() {
  const box = $('#admCharts'); if (!box) return;
  /* bookings per day — last 14 days (all statuses, shows demand) */
  const days = [], counts = [];
  for (let i = 13; i >= 0; i--) {
    const iso = addDaysISO(todayISO(), -i);
    days.push(iso.slice(8) + '/' + iso.slice(5, 7));
    counts.push(DB.bookings.filter(b => sameDay(b.createdAt, new Date(iso.split('-')[0], iso.split('-')[1] - 1, iso.split('-')[2]).getTime())).length);
  }
  /* revenue per route — confirmed bookings */
  const byRoute = {};
  DB.bookings.filter(b => b.status === 'confirmed').forEach(b => { byRoute[b.routeId] = (byRoute[b.routeId] || 0) + (b.total || 0); });
  const items = Object.keys(byRoute).map(rid => {
    const r = routeById(rid) || {};
    return { label: ((r.from || '?').slice(0, 3) + '→' + (r.to || '?').slice(0, 3)).toUpperCase(), v: byRoute[rid], top: '₹' + Math.round(byRoute[rid] / 1000) + 'k' };
  }).sort((a, b) => b.v - a.v).slice(0, 6);
  box.innerHTML = `
    <div class="chart-card"><b>Bookings per day · last 14 days</b>${svgLineChart(counts, days, 420, 130)}</div>
    <div class="chart-card"><b>Revenue per route · confirmed</b>${items.length ? svgBarChart(items, 420, 130) : '<p style="color:var(--muted);font-size:13px;padding:20px 0">No confirmed revenue yet.</p>'}</div>`;
}

function adminFilteredBookings() {
  const q = ADM.q.toLowerCase();
  return DB.bookings.filter(b => {
    if (ADM.st !== 'all' && b.status !== ADM.st) return false;
    if (!q) return true;
    const r = routeById(b.routeId) || {};
    const hay = [b.id, b.contact && b.contact.phone, r.from, r.to, b.date]
      .concat((b.passengers || []).map(p => p.name)).join(' ').toLowerCase();
    return hay.indexOf(q) >= 0;
  });
}

/* Lazy render: only the first page of rows goes into the DOM; a
   "Show more" row appends the rest on demand (keeps big lists snappy). */
const ADM_PAGE = 60;
let ADM_SHOWN = ADM_PAGE;
function renderAdminBookings() {
  const tb = $('#bookingsBody');
  const all = adminFilteredBookings();
  const rows = all.slice(0, ADM_SHOWN);
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:26px">'
      + (DB.bookings.length ? 'No bookings match this search / filter.' : 'No bookings yet.') + '</td></tr>';
  } else {
    tb.innerHTML = rows.map(b => {
      const r = routeById(b.routeId) || {};
      const proof = b.payment.mode === 'screenshot'
        ? (b.payment.shotThumb ? '<img class="thumb" src="' + b.payment.shotThumb + '" alt="Payment screenshot" title="Payment screenshot">' : '<i>screenshot</i>')
        : '<span style="font-family:var(--f-code);font-size:12px">' + esc(b.payment.utr) + '</span>';
      const method = '<span class="badge" style="margin-top:4px">' + (b.payment.method === 'esewa' ? '🇳🇵 eSewa' : '🇮🇳 UPI') + '</span>';
      const stBadge = bookingStatusBadge(b.status, { pending: 'Pending', confirmed: 'Confirmed', cancelled: 'Cancelled', rejected: 'Rejected' });
      const trips = tripsForPhone(b.contact && b.contact.phone);
      const repeat = trips >= CONFIG.booking.loyaltyTrips ? '<br><span class="badge ok" title="Repeat customer">⭐ ×' + trips + '</span>' : '';
      const actions = b.status === 'pending'
        ? '<button class="btn btn-blue btn-sm" data-act="realadmin" data-id="' + b.id + '">🔗 Verify in Admin Panel</button>'
        : '<a class="btn btn-ghost btn-sm" href="#/ticket/' + b.id + '">View</a>';
      return '<tr><td><b style="font-family:var(--f-code)">' + esc(b.id) + '</b>' + (b.ret ? ' <span class="chip orange" title="Round trip">⇄</span>' : '') + '<br><small>' + new Date(b.createdAt).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + '</small></td>'
        + '<td>' + esc((r.from || '?') + ' → ' + (r.to || '?')) + '<br><small>' + fmtDate(b.date) + (b.ret ? ' · ↩ ' + fmtDate(b.ret.date) : '') + '</small></td>'
        + '<td>' + b.passengers.map(p => esc(p.name)).join('<br>') + '<br><small>📞 ' + esc(b.contact.phone) + '</small>' + repeat + '</td>'
        + '<td>' + admSeats(b, ', ') + (b.ret ? '<br><small>↩ ' + admSeats(b.ret, ', ', b.bookingType) + '</small>' : '') + '</td>'
        + '<td><b>' + inr(b.total) + '</b>' + (b.discount > 0 ? '<br><small style="color:var(--ok)">−' + inr(b.discount) + ' group</small>' : '') + (b.bookingType ? '<br><span class="badge" title="' + esc(b.cabinLabel || '') + '">' + (b.bookingType === 'private' ? '🔒 ' : '👥 ') + esc(b.cabinLabel || (b.bookingType === 'private' ? 'Private Cabin' : 'Sharing Seat')) + '</span>' : '') + '</td>'
        + '<td>' + proof + '<br>' + method + '</td>'
        + '<td>' + stBadge + '</td>'
        + '<td style="white-space:nowrap">' + actions + '</td></tr>';
    }).join('');
    if (all.length > ADM_SHOWN) {
      tb.innerHTML += '<tr><td colspan="8" style="text-align:center;padding:14px">'
        + '<button class="btn btn-ghost btn-sm" id="admMoreBtn" type="button">⌄ Show ' + Math.min(ADM_PAGE, all.length - ADM_SHOWN) + ' more (' + (all.length - ADM_SHOWN) + ' hidden)</button></td></tr>';
      $('#admMoreBtn').onclick = () => { ADM_SHOWN += ADM_PAGE; renderAdminBookings(); };
    }
  }

  $('#admSearch').oninput = (e) => { ADM.q = e.target.value.trim(); ADM_SHOWN = ADM_PAGE; renderAdminBookings(); };
  $('#admFilter').onchange = (e) => { ADM.st = e.target.value; ADM_SHOWN = ADM_PAGE; renderAdminBookings(); };
  $('#admExport').onclick = exportBookingsCSV;
  if ($('#admRevenuePdf')) $('#admRevenuePdf').onclick = exportRevenuePDF;

  /* Confirm/reject actually issuing a real ticket (PDF/QR, seat claim,
     loyalty + commission) only happens through BookingService in the
     real backend — this delegated handler just opens that Admin Panel
     for the specific booking rather than faking a status flip here. */
  tb.onclick = (e) => {
    const btn = e.target.closest('[data-act="realadmin"]'); if (!btn) return;
    const b = DB.bookings.find(x => x.id === btn.getAttribute('data-id')); if (!b) return;
    const base = (window.SHG_BOOT && window.SHG_BOOT.appUrl) || location.origin;
    window.open(base.replace(/\/$/, '') + '/admin/payments.php?pnr=' + encodeURIComponent(b.id), '_blank', 'noopener');
    toast('Opening the staff Admin Panel — sign in there to verify or reject this payment for real.');
  };
}

/* Generic CSV download helper — array-of-arrays → quoted CSV → Blob → click.
   BOM prefix so Excel opens UTF-8 (Devanagari names) correctly. */
function downloadCSV(filename, rows) {
  const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const lines = (rows || []).map(r => (r || []).map(q).join(','));
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/* Client-side CSV export (opens in Excel) — respects search & filter */
function exportBookingsCSV() {
  const rows = adminFilteredBookings();
  const head = ['Booking ID', 'Created', 'Route', 'Journey date', 'Return date', 'Passengers', 'Phone', 'Seats', 'Return seats', 'Boarding', 'Drop', 'Method', 'Payment ref', 'Base', 'Discount', 'Total', 'Status', 'Created ISO', 'Bus no', 'Type', 'Agent/Referral'];
  const data = [head].concat(rows.map(b => {
    const r = routeById(b.routeId) || {};
    return [
      b.id, new Date(b.createdAt).toLocaleString('en-IN'),
      (r.from || '') + ' -> ' + (r.to || ''), b.date, b.ret ? b.ret.date : '',
      (b.passengers || []).map(p => p.name + ' (' + p.age + p.gender[0] + ')').join('; '),
      b.contact && b.contact.phone, admSeats(b, ' '), b.ret ? admSeats(b.ret, ' ', b.bookingType) : '',
      bpShort(b.boarding), bpShort(b.drop),
      b.payment.method || 'upi', b.payment.utr || (b.payment.mode === 'screenshot' ? 'screenshot' : ''),
      b.baseTotal || b.total, b.discount || 0, b.total, b.status,
      new Date(b.createdAt).toISOString(), r.busNo || '',
      b.bookingType ? (b.bookingType + (b.sharingTier ? ' ' + b.sharingTier : '')) : 'seat',
      b.agentCode || b.referralCode || ''
    ];
  }));
  downloadCSV('SHG-bookings-' + todayISO() + '.csv', data);
  toast('CSV exported — open it in Excel ✓');
}

/* 📊 One-page revenue summary PDF (jsPDF, A4) — same stat maths as
   renderAdminStats so the office report always matches the dashboard. */
async function exportRevenuePDF() {
  if (!(await waitForJsPDF(null))) return;
  const doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
  const now = Date.now();
  const conf = DB.bookings.filter(b => b.status === 'confirmed');
  const revToday = conf.filter(b => sameDay(b.createdAt, now)).reduce((s, b) => s + b.total, 0);
  const revWeek = conf.filter(b => b.createdAt >= weekStart(now)).reduce((s, b) => s + b.total, 0);
  const revMonth = conf.filter(b => sameMonth(b.createdAt, now)).reduce((s, b) => s + b.total, 0);
  const revAll = conf.reduce((s, b) => s + b.total, 0);
  const sharingRev = conf.filter(b => b.bookingType === 'sharing').reduce((s, b) => s + b.total, 0);
  const privateRev = conf.filter(b => b.bookingType === 'private').reduce((s, b) => s + b.total, 0);
  const byRoute = {};
  conf.forEach(b => { byRoute[b.routeId] = (byRoute[b.routeId] || 0) + b.total; });
  const topRoutes = Object.keys(byRoute).sort((a, b) => byRoute[b] - byRoute[a]).slice(0, 3);
  const rs = (n) => 'Rs ' + Math.round(n || 0).toLocaleString('en-IN');   // jsPDF helvetica has no rupee glyph

  const W = doc.internal.pageSize.getWidth();
  doc.setFillColor(10, 61, 145); doc.rect(0, 0, W, 92, 'F');
  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold'); doc.setFontSize(19);
  doc.text('S HARI GLOBAL PVT LTD - Revenue Report', 40, 42);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(10.5);
  doc.text('Generated ' + new Date().toLocaleString('en-IN') + '  |  Surat - Rupaidiha', 40, 64);

  let y = 128;
  const line = (label, val, big) => {
    doc.setFont('helvetica', 'normal'); doc.setFontSize(big ? 12.5 : 11.5); doc.setTextColor(60, 66, 80);
    doc.text(label, 44, y);
    doc.setFont('helvetica', 'bold'); doc.setTextColor(20, 24, 33);
    doc.text(String(val), W - 44, y, { align: 'right' });
    doc.setDrawColor(228, 232, 240); doc.setLineWidth(0.6); doc.line(44, y + 7, W - 44, y + 7);
    y += big ? 26 : 24;
  };
  const head = (txt) => {
    y += 12; doc.setFont('helvetica', 'bold'); doc.setFontSize(13);
    doc.setTextColor(10, 61, 145); doc.text(txt, 40, y);
    doc.setTextColor(20, 24, 33); y += 22;
  };

  head('Bookings');
  line('Total bookings (all statuses)', DB.bookings.length, true);
  line('Confirmed', conf.length);
  line('Pending verification', DB.bookings.filter(b => b.status === 'pending').length);

  head('Revenue (confirmed bookings)');
  line('Today', rs(revToday), true);
  line('This week (from Monday)', rs(revWeek));
  line('This month', rs(revMonth));
  line('All time', rs(revAll));

  head('Sharing vs Private (sleeper cabins)');
  line('Sharing cabins', rs(sharingRev));
  line('Private cabins', rs(privateRev));
  line('Regular seats / other', rs(revAll - sharingRev - privateRev));

  head('Top routes by revenue');
  if (!topRoutes.length) line('No confirmed bookings yet', '-');
  topRoutes.forEach((rid, i) => {
    const r = routeById(rid) || {};
    line((i + 1) + '. ' + ((r.from || '?') + ' -> ' + (r.to || '?')) + (r.busNo ? '  (' + r.busNo + ')' : ''), rs(byRoute[rid]));
  });

  const H = doc.internal.pageSize.getHeight();
  doc.setDrawColor(10, 61, 145); doc.setLineWidth(1.2); doc.line(40, H - 58, W - 40, H - 58);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(110, 118, 132);
  doc.text('Generated by S HARI GLOBAL booking app - admin panel  |  ' + S().phone + '  |  ' + S().email, 40, H - 40);
  doc.save('SHG-revenue-' + todayISO() + '.pdf');
  audit('Revenue PDF exported', conf.length + ' confirmed · month ' + inr(revMonth));
  renderAdminAudit();
  toast('Revenue PDF downloaded ✓');
}

/* Waitlist tab — 🔔 rows are next-in-line after a cancellation */
function renderAdminWaitlist() {
  const box = $('#waitlistList'); if (!box) return;
  const wl = (DB.waitlist || []).slice().sort((a, b) => (a.status === 'notify' ? -1 : 1) - (b.status === 'notify' ? -1 : 1) || a.at - b.at);
  if (!wl.length) { box.innerHTML = '<p style="color:var(--muted);font-size:14px;padding:14px 4px">Waitlist is empty. Passengers can join it from any sold-out coach.</p>'; return; }
  box.innerHTML = wl.map(w => {
    const r = routeById(w.routeId) || {};
    const st = w.status === 'notify'
      ? '<span class="badge pend">🔔 Seat freed — notify now</span>'
      : w.status === 'done' ? '<span class="badge ok">Contacted</span>' : '<span class="badge">Waiting</span>';
    return `<div class="msg-card${w.status === 'notify' ? ' wl-notify' : ''}">
      <div class="msg-head"><b>${esc(w.name)}</b>${st}<small>${new Date(w.at).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small></div>
      <p>${esc((r.from || '?') + ' → ' + (r.to || '?'))} · ${fmtDate(w.date)} · ${w.seats} seat${w.seats > 1 ? 's' : ''}</p>
      <small>📞 <a href="tel:${esc(w.phone)}">${esc(w.phone)}</a> · <a href="https://wa.me/91${esc(w.phone)}" target="_blank" rel="noopener">WhatsApp</a></small>
      <div style="margin-top:10px;display:flex;gap:8px">
        ${w.status !== 'done' ? '<button class="btn btn-blue btn-sm" data-wldone="' + w.id + '">✓ Mark contacted</button>' : ''}
        <button class="btn btn-ghost btn-sm" data-wldel="${w.id}">🗑 Remove</button>
      </div>
    </div>`;
  }).join('');
  box.onclick = (e) => {
    const d = e.target.closest('[data-wldone]');
    if (d) { const w = DB.waitlist.find(x => x.id === d.getAttribute('data-wldone')); if (w) { w.status = 'done'; persist('waitlist'); renderAdminStats(); renderAdminWaitlist(); } return; }
    const x = e.target.closest('[data-wldel]');
    if (x) { DB.waitlist = DB.waitlist.filter(w => w.id !== x.getAttribute('data-wldel')); persist('waitlist'); renderAdminStats(); renderAdminWaitlist(); }
  };
}

/* ================================================================
   [JS] 12b. ADMIN — AGENTS TAB: approvals, block/unblock, fraud
   flags, commission ledger + payout requests, CSV export.
================================================================ */
function renderAdminAgents() {
  const cards = $('#agStatCards'); if (!cards) return;
  const agents = DB.users.filter(u => u.role === 'agent');
  const now = Date.now();
  const refsMonth = DB.commissions.filter(c => sameMonth(c.confirmedAt || c.createdAt, now) && (c.status === 'confirmed' || c.status === 'paid')).length;
  const cPend = DB.commissions.filter(c => c.status === 'confirmed').reduce((s, c) => s + c.amount, 0);
  const cPaid = DB.commissions.filter(c => c.status === 'paid').reduce((s, c) => s + c.amount, 0);
  cards.innerHTML = `
    <div class="astat"><b>${agents.length}</b><span>Total agents</span></div>
    <div class="astat"><b>${refsMonth}</b><span>Confirmed referrals this month</span></div>
    <div class="astat"><b style="color:var(--warn)">${inr(cPend)}</b><span>Commission pending</span></div>
    <div class="astat"><b style="color:var(--ok)">${inr(cPaid)}</b><span>Commission paid</span></div>`;

  /* — pending customer → agent upgrade requests — */
  const reqs = DB.users.filter(u => u.agentRequestPending);
  $('#agentRequests').innerHTML = reqs.length ? '<div class="adm-toolbar" style="margin-top:4px"><span style="font-family:var(--f-display);font-weight:800;font-size:16px">Agent requests</span></div>'
    + reqs.map(u => `<div class="msg-card wl-notify">
        <div class="msg-head"><b>+91 ${esc(u.phone)}</b><span class="badge pend">Wants to become an agent</span>
        <small>${u.agentRequestedAt ? new Date(u.agentRequestedAt).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''}</small></div>
        <div style="display:flex;gap:8px;margin-top:6px">
          <button class="btn btn-blue btn-sm" data-agapprove="${esc(u.phone)}">✓ Approve as agent</button>
          <button class="btn btn-ghost btn-sm" data-agdecline="${esc(u.phone)}">✕ Decline</button>
        </div>
      </div>`).join('')
    : '';

  /* — agents table — */
  const tb = $('#agentsBody');
  tb.innerHTML = agents.length ? agents.map(u => {
    const led = agentLedger(u.phone);
    const flags = agentFraudFlags(u);
    const flagHtml = flags.length ? '<br><span class="badge bad" title="' + esc(flags.join(' · ')) + '">⚠ ' + flags.length + ' flag' + (flags.length > 1 ? 's' : '') + '</span>' : '';
    return '<tr><td><b>' + (u.name ? esc(u.name) : 'Agent') + '</b><br><small>📞 ' + esc(u.phone) + '</small>' + flagHtml + '</td>'
      + '<td style="font-family:var(--f-code)">' + esc(u.refCode) + '</td>'
      + '<td>' + agentReferralCount(u.refCode) + '</td>'
      + '<td style="color:var(--ok);font-weight:700">' + led.confirmedCount + '</td>'
      + '<td>' + inr(led.pending) + '</td>'
      + '<td>' + inr(led.paid) + '</td>'
      + '<td>' + (u.blocked ? '<span class="badge bad">Blocked</span>' : '<span class="badge ok">Active</span>') + '</td>'
      + '<td style="white-space:nowrap">'
      + '<button class="btn btn-ghost btn-sm" data-agblock="' + esc(u.phone) + '">' + (u.blocked ? '🔓 Unblock' : '🚫 Block') + '</button> '
      + '<button class="btn btn-ghost btn-sm" data-agadjust="' + esc(u.phone) + '" title="Add a manual commission adjustment (±₹)">± Adjust</button>'
      + '</td></tr>';
  }).join('') : '<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:22px">No agents yet — approve a request above, or ask a customer to tap "Become an agent" in the Agent Zone.</td></tr>';

  /* — open payout requests — */
  const pos = DB.payoutRequests.slice().sort((a, b) => (a.status === 'requested' ? -1 : 1) - (b.status === 'requested' ? -1 : 1) || b.at - a.at);
  $('#payoutAdmin').innerHTML = '<div class="adm-toolbar"><span style="font-family:var(--f-display);font-weight:800;font-size:16px">Payout requests</span></div>'
    + (pos.length ? pos.map(p => {
      const st = p.status === 'paid' ? '<span class="badge ok">Paid' + (p.paidAt ? ' · ' + new Date(p.paidAt).toLocaleDateString('en-IN') : '') + '</span>'
        : p.status === 'declined' ? '<span class="badge bad">Declined</span>' : '<span class="badge pend">Requested</span>';
      return `<div class="msg-card${p.status === 'requested' ? ' wl-notify' : ''}">
        <div class="msg-head"><b>+91 ${esc(p.agentId)}</b>${st}<small>${new Date(p.at).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small></div>
        <p><b>${inr(p.amount)}</b>${p.note ? ' · ' + esc(p.note) : ''}</p>
        ${p.status === 'requested' ? '<div style="display:flex;gap:8px;margin-top:6px"><button class="btn btn-blue btn-sm" data-popaid="' + esc(p.id) + '">💸 Mark paid</button><button class="btn btn-ghost btn-sm" data-podecline="' + esc(p.id) + '">✕ Decline</button></div>' : ''}
      </div>`;
    }).join('') : '<p style="color:var(--muted);font-size:13.5px;padding:8px 4px">No payout requests.</p>');

  /* — full commission ledger — */
  const led = DB.commissions.slice(0, 200);
  $('#commissionLedger').innerHTML = '<div class="adm-toolbar"><span style="font-family:var(--f-display);font-weight:800;font-size:16px">Commission ledger</span></div>'
    + (led.length ? '<div class="table-wrap"><table class="admin"><thead><tr><th>Entry</th><th>Booking</th><th>Agent</th><th>Amount</th><th>Status</th><th>Note</th><th>Action</th></tr></thead><tbody>'
      + led.map(c => '<tr><td><small>' + new Date(c.confirmedAt || c.createdAt).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + '</small></td>'
        + '<td style="font-family:var(--f-code);font-size:12px">' + esc(c.bookingId) + '</td>'
        + '<td>' + esc(c.agentCode) + '<br><small>' + esc(c.agentId) + '</small></td>'
        + '<td><b>' + inr(c.amount) + '</b></td>'
        + '<td>' + (c.status === 'confirmed' ? '<span class="badge pend">Unpaid</span>' : c.status === 'paid' ? '<span class="badge ok">Paid</span>' : c.status === 'expired' ? '<span class="badge">Expired</span>' : '<span class="badge bad">Cancelled</span>') + '</td>'
        + '<td><small>' + esc(c.note || '') + '</small></td>'
        + '<td>' + (c.status === 'confirmed' ? '<button class="btn btn-ghost btn-sm" data-cpaid="' + esc(c.id) + '">💸 Mark paid</button>' : '') + '</td></tr>').join('')
      + '</tbody></table></div>'
      : '<p style="color:var(--muted);font-size:13.5px;padding:8px 4px">No commission entries yet — they are created when a referred booking is confirmed.</p>');

  $('#agExportBtn').onclick = exportAgentsCSV;

  /* one delegated handler for the whole tab */
  $('#tab-agents').onclick = (e) => {
    const ap = e.target.closest('[data-agapprove]');
    if (ap) {
      const u = userByPhone(ap.getAttribute('data-agapprove')); if (!u) return;
      u.role = 'agent'; u.agentRequestPending = false; u.blocked = false;
      if (!u.refCode) u.refCode = genRefCode(u.phone);
      persist('users');
      audit('Agent approved', '+91 ' + u.phone + ' → code ' + u.refCode);
      toast('Agent approved — code ' + u.refCode + ' ✓');
      renderAdminStats(); renderAdminAgents(); renderAdminAudit();
      return;
    }
    const dc = e.target.closest('[data-agdecline]');
    if (dc) {
      const u = userByPhone(dc.getAttribute('data-agdecline')); if (!u) return;
      u.agentRequestPending = false;
      persist('users');
      audit('Agent request declined', '+91 ' + u.phone);
      renderAdminStats(); renderAdminAgents(); renderAdminAudit();
      return;
    }
    const bl = e.target.closest('[data-agblock]');
    if (bl) {
      const u = userByPhone(bl.getAttribute('data-agblock')); if (!u) return;
      u.blocked = !u.blocked;
      persist('users');
      audit(u.blocked ? 'Agent blocked' : 'Agent unblocked', '+91 ' + u.phone + ' (' + u.refCode + ')');
      renderAdminAgents(); renderAdminAudit();
      return;
    }
    const adj = e.target.closest('[data-agadjust]');
    if (adj) {
      const u = userByPhone(adj.getAttribute('data-agadjust')); if (!u) return;
      const amtS = prompt('Adjustment amount in ₹ (positive = credit, negative = deduct):', '0');
      if (amtS === null) return;
      const amt = Math.round(parseFloat(amtS));
      if (!amt) { toast('No adjustment made.'); return; }
      const note = prompt('Reason for this adjustment (required, goes to the audit log):', '');
      if (!note || !note.trim()) { toast('Adjustment needs a note — cancelled.'); return; }
      DB.commissions.unshift({
        id: 'C' + Date.now().toString(36) + 'adj', bookingId: 'ADJ-' + Date.now().toString(36).toUpperCase(),
        agentId: u.phone, agentCode: u.refCode, amount: amt,
        status: 'confirmed', createdAt: Date.now(), confirmedAt: Date.now(), paidAt: 0,
        note: 'Manual adjustment: ' + note.trim()
      });
      persist('commissions');
      audit('Commission adjusted', '+91 ' + u.phone + ' ' + (amt > 0 ? '+' : '') + inr(amt) + ' — ' + note.trim());
      toast('Adjustment recorded ✓');
      renderAdminStats(); renderAdminAgents(); renderAdminAudit();
      return;
    }
    const pp = e.target.closest('[data-popaid]');
    if (pp) {
      const p = DB.payoutRequests.find(x => x.id === pp.getAttribute('data-popaid')); if (!p) return;
      const note = prompt('Payment note (e.g. "UPI ref 4152…", optional):', '') || '';
      p.status = 'paid'; p.paidAt = Date.now(); p.note = note.trim();
      settleCommissions(p.agentId, p.amount, 'Paid out ' + new Date().toLocaleDateString('en-IN'));
      persist('payoutRequests');
      audit('Payout marked paid', '+91 ' + p.agentId + ' · ' + inr(p.amount) + (note ? ' — ' + note : ''));
      toast('Payout marked paid ✓');
      renderAdminStats(); renderAdminAgents(); renderAdminAudit();
      return;
    }
    const pd = e.target.closest('[data-podecline]');
    if (pd) {
      const p = DB.payoutRequests.find(x => x.id === pd.getAttribute('data-podecline')); if (!p) return;
      p.status = 'declined';
      persist('payoutRequests');
      audit('Payout declined', '+91 ' + p.agentId + ' · ' + inr(p.amount));
      renderAdminAgents(); renderAdminAudit();
      return;
    }
    const cp = e.target.closest('[data-cpaid]');
    if (cp) {
      const c = DB.commissions.find(x => x.id === cp.getAttribute('data-cpaid')); if (!c) return;
      const note = prompt('Payment note (optional):', '') || '';
      c.status = 'paid'; c.paidAt = Date.now();
      if (note.trim()) c.note = ((c.note ? c.note + ' · ' : '') + note.trim());
      persist('commissions');
      audit('Commission marked paid', c.bookingId + ' · ' + esc(c.agentCode) + ' · ' + inr(c.amount));
      renderAdminStats(); renderAdminAgents(); renderAdminAudit();
    }
  };
}

/* Agents + commission ledger CSV (same pattern as exportBookingsCSV) */
function exportAgentsCSV() {
  const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const lines = [['Agent phone', 'Name', 'Code', 'Status', 'Total referrals', 'Confirmed', 'Pending ₹', 'Paid ₹'].join(',')];
  DB.users.filter(u => u.role === 'agent').forEach(u => {
    const led = agentLedger(u.phone);
    lines.push([u.phone, u.name || '', u.refCode, u.blocked ? 'blocked' : 'active', agentReferralCount(u.refCode), led.confirmedCount, led.pending, led.paid].map(q).join(','));
  });
  lines.push('');
  lines.push(['Entry date', 'Booking', 'Agent phone', 'Code', 'Amount ₹', 'Status', 'Paid date', 'Note'].join(','));
  DB.commissions.forEach(c => {
    lines.push([new Date(c.confirmedAt || c.createdAt).toLocaleString('en-IN'), c.bookingId, c.agentId, c.agentCode, c.amount, c.status,
      c.paidAt ? new Date(c.paidAt).toLocaleString('en-IN') : '', c.note || ''].map(q).join(','));
  });
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'SHG-agents-' + todayISO() + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  toast('Agents CSV exported ✓');
}

/* ================================================================
   [JS] 12c. ADMIN — ACTIVITY / AUDIT LOG (reverse-chronological)
================================================================ */
function renderAdminAudit() {
  const box = $('#auditList'); if (!box) return;
  if (!DB.auditLog.length) { box.innerHTML = '<p style="color:var(--muted);font-size:14px;padding:14px 4px">Nothing logged yet. Admin actions (confirm/reject, route edits, settings, commissions, approvals) appear here.</p>'; return; }
  box.innerHTML = DB.auditLog.slice(0, 120).map(a => `
    <div class="audit-row">
      <span class="audit-ts">${new Date(a.ts).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
      <b>${esc(a.action)}</b>
      <span>${esc(a.detail)}</span>
    </div>`).join('');
  var cs = typeof getChatAnalytics === 'function' ? getChatAnalytics() : null;
  if (cs) {
    var topIntents = Object.entries(cs.intents).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 6).map(function (e) { return '<span class="chip" style="font-size:11px">' + esc(e[0]) + ' <b>' + e[1] + '</b></span>'; }).join(' ');
    var langDist = Object.entries(cs.langs).map(function (e) { return esc(e[0].toUpperCase()) + ': ' + e[1]; }).join(' · ') || '—';
    box.innerHTML += '<div style="margin-top:22px;padding:18px;background:var(--blue-50);border:1px solid var(--blue-100);border-radius:16px">'
      + '<h4 style="font-family:var(--f-display);font-size:15px;margin-bottom:8px">🤖 AI Chat Analytics <small style="color:var(--muted);font-weight:400">(this session only)</small></h4>'
      + '<div class="astat-grid" style="grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px">'
      + '<div class="astat" style="padding:12px;animation:none"><b>' + cs.opens + '</b><span>Chat Opens</span></div>'
      + '<div class="astat" style="padding:12px;animation:none"><b>' + Object.values(cs.intents).reduce(function (a, b) { return a + b; }, 0) + '</b><span>Queries</span></div>'
      + '<div class="astat" style="padding:12px;animation:none"><b>' + Object.keys(cs.intents).length + '</b><span>Unique Intents</span></div>'
      + '</div>'
      + '<p style="font-size:12.5px;color:var(--muted);margin-bottom:6px"><b>Top intents:</b> ' + (topIntents || 'No queries yet') + '</p>'
      + '<p style="font-size:12.5px;color:var(--muted)"><b>Language distribution:</b> ' + langDist + '</p>'
      + '<p style="font-size:11px;color:var(--muted);margin-top:8px;opacity:.7">⚠️ Session-only data — resets on page reload. Persistent analytics requires a backend (Phase 3).</p>'
      + '</div>';
  }
}
