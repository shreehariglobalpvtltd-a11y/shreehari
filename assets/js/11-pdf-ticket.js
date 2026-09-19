
/* ================================================================
   [JS] 11. PDF TICKET — branded blue→orange header, logo, QR,
   watermark, fare breakdown, return leg & crew contact.
   Modular: edit only this function to restyle tickets.
================================================================ */
function lerpColor(c1, c2, t2) {
  return [Math.round(c1[0] + (c2[0] - c1[0]) * t2), Math.round(c1[1] + (c2[1] - c1[1]) * t2), Math.round(c1[2] + (c2[2] - c1[2]) * t2)];
}
/* jsPDF is ~350KB, so it is injected only when a PDF button is actually
   clicked (cdnjs → jsdelivr fallback). All 4 PDF generators await this. */
var jsPdfPromise = null;
function waitForJsPDF(btn) {
  if (window.jspdf && window.jspdf.jsPDF) return Promise.resolve(true);
  var origHTML = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Loading PDF engine…'; }
  if (!jsPdfPromise) {
    jsPdfPromise = new Promise(function (resolve) {
      var s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
      s.onload = function () { resolve(true); };
      s.onerror = function () {
        var s2 = document.createElement('script');
        s2.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';
        s2.onload = function () { resolve(true); };
        s2.onerror = function () { jsPdfPromise = null; resolve(false); };
        document.head.appendChild(s2);
      };
      document.head.appendChild(s);
    });
  }
  return jsPdfPromise.then(function (ok) {
    if (btn) { btn.disabled = false; btn.innerHTML = origHTML; }
    if (!ok) toast('PDF library failed to load — check your internet connection and retry.');
    return ok;
  });
}
/* ---- Devanagari font for jsPDF — lazy-loaded, cached ----
   Rule 4: UTF-8 + full Devanagari font (NotoSansDevanagari.ttf).
   Rule 5: if font fails to load → fall back to Roman transliteration. */
var _devFontB64 = null;
async function _loadDevFont(doc) {
  try {
    if (!_devFontB64) {
      var r = await fetch('/assets/fonts/NotoSansDevanagari.ttf');
      if (!r.ok) return false;
      var buf = await r.arrayBuffer();
      var u8 = new Uint8Array(buf);
      var bin = '';
      for (var i = 0; i < u8.length; i += 8192)
        bin += String.fromCharCode.apply(null, u8.subarray(i, Math.min(i + 8192, u8.length)));
      _devFontB64 = btoa(bin);
    }
    doc.addFileToVFS('NotoSansDevanagari.ttf', _devFontB64);
    doc.addFont('NotoSansDevanagari.ttf', 'NotoSansDevanagari', 'normal');
    return true;
  } catch (e) { return false; }
}
/* Write text in Devanagari font if loaded, else keep current font with Roman fallback.
   Caller must setFontSize/setTextColor before calling.
   After the call, font is restored to helvetica. */
function _dt(doc, hasDev, x, y, nepali, roman, opts) {
  if (hasDev) {
    doc.setFont('NotoSansDevanagari', 'normal');
    doc.text(nepali, x, y, opts);
    doc.setFont('helvetica', 'normal');
  } else {
    doc.text(roman, x, y, opts);
  }
}
/* ================================================================
   [JS] 11B. TICKET IMAGE — canvas-drawn JPG of the e-ticket (no new
   dependency: hand-rolled canvas + toDataURL). Reused by both
   the direct download button and the Web Share button below.
================================================================ */
async function buildTicketCanvas(b) {
  /* Minimal airline-style JPG ticket — same layout as the PDF, so a
     WhatsApp share, a printed A4 and a phone-download all read the same.
     The selected boarding city is the origin (same helpers). */
  const r = routeById(b.routeId) || {};
  const W = 1240, H = 1754, pad = 60, colW = (W - pad * 2);
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const g = cv.getContext('2d');

  const NAVY = '#1A3A6A', ORANGE = '#F07C1F', INK = '#1E1E1E', MUTE = '#787888', LINE = '#DEE0E8';

  g.fillStyle = '#FFFFFF'; g.fillRect(0, 0, W, H);

  /* Header */
  g.fillStyle = NAVY; g.fillRect(0, 0, W, 200);
  g.fillStyle = ORANGE; g.fillRect(0, 200, W, 6);
  g.fillStyle = '#FFD268'; g.font = 'bold 40px sans-serif';
  g.fillText('S HARI GLOBAL PVT LTD', pad, 76);
  g.fillStyle = '#C8D2E6'; g.font = '20px sans-serif';
  g.fillText('भारत–नेपाल बस सेवा  ·  India–Nepal Bus Service', pad, 110);
  if (CONFIG.company.cin) {
    g.font = '16px sans-serif';
    g.fillText('CIN: ' + CONFIG.company.cin, pad, 138);
  }
  g.fillStyle = '#FFD268'; g.font = 'bold 22px sans-serif'; g.textAlign = 'right';
  g.fillText('यात्रा टिकट · BOARDING PASS', W - pad, 66);
  g.fillStyle = '#fff'; g.font = 'bold 38px monospace';
  g.fillText(String(b.id), W - pad, 116);
  g.fillStyle = '#C8D2E6'; g.font = '17px sans-serif';
  g.fillText('Issued ' + new Date().toLocaleDateString('en-IN'), W - pad, 146);
  g.textAlign = 'left';

  /* Payment band */
  var isPaid = b.status === 'confirmed';
  var pay = b.payment || {};
  var band, bandBg;
  if (isPaid) {
    var methodTxt = pay.method === 'esewa' ? 'eSewa' : (pay.method === 'cod' ? 'CASH' : 'UPI');
    bandBg = '#0E7A42'; band = 'PAID  ·  ' + inr(b.total) + '  ·  ' + methodTxt;
  } else if (b.status === 'pending' && pay.method === 'cod') {
    bandBg = '#C46A00'; band = 'CASH ON BOARDING  ·  Pay ' + inr(b.total) + ' to the crew';
  } else {
    bandBg = '#B02A2A'; band = 'PAYMENT PENDING  ·  Not valid until approved';
  }
  g.fillStyle = bandBg; g.fillRect(0, 206, W, 40);
  g.fillStyle = '#fff'; g.font = 'bold 20px sans-serif'; g.textAlign = 'center';
  g.fillText(band, W / 2, 232); g.textAlign = 'left';

  /* Route — BOARDING CITY → DROP CITY (selected pickup drives the ticket) */
  var boardCity = _boardName(b, r).toUpperCase();
  var dropCity  = _dropName(b, r).toUpperCase();
  var depHHMM   = _boardTimeHHMM(b, r);

  var y = 320;
  g.fillStyle = NAVY; g.font = 'bold 60px sans-serif';
  g.fillText(boardCity, pad, y);
  g.textAlign = 'right';
  g.fillText(dropCity, W - pad, y);
  g.textAlign = 'left';

  /* dashed rule between cities */
  var fromW = g.measureText(boardCity).width;
  var toW   = g.measureText(dropCity).width;
  g.strokeStyle = ORANGE; g.lineWidth = 3; g.setLineDash([8, 6]);
  g.beginPath(); g.moveTo(pad + fromW + 20, y - 20); g.lineTo(W - pad - toW - 20, y - 20); g.stroke();
  g.setLineDash([]);
  g.fillStyle = ORANGE; g.font = 'bold 26px sans-serif'; g.textAlign = 'center';
  g.fillText('▶', W / 2, y - 12); g.textAlign = 'left';

  y += 34;
  g.fillStyle = MUTE; g.font = '20px sans-serif';
  g.fillText('Dep ' + (depHHMM || '—') + ' IST', pad, y);
  g.textAlign = 'right';
  if (r.arrTime) g.fillText('Arr ' + r.arrTime + ' NPT' + (r.dayOffset ? ' (+' + r.dayOffset + 'd)' : ''), W - pad, y);
  g.textAlign = 'center'; g.fillStyle = INK;
  g.fillText(fmtDate(b.date) + '  ·  ' + (r.busName || '—') + '  (' + (r.busNo || '') + ')', W / 2, y);
  g.textAlign = 'left';

  /* Passenger name */
  y += 60;
  g.strokeStyle = LINE; g.lineWidth = 1;
  g.beginPath(); g.moveTo(pad, y - 24); g.lineTo(W - pad, y - 24); g.stroke();
  g.fillStyle = MUTE; g.font = '16px sans-serif';
  g.fillText('यात्री  ·  PASSENGER', pad, y);
  var primary = (b.passengers && b.passengers[0]) || {};
  g.fillStyle = NAVY; g.font = 'bold 34px sans-serif';
  g.fillText(String(primary.name || '').toUpperCase(), pad, y + 40);
  if ((b.passengers || []).length > 1) {
    g.fillStyle = MUTE; g.font = '17px sans-serif';
    g.fillText('+ ' + (b.passengers.length - 1) + ' more — see the list below', pad, y + 66);
  }
  g.textAlign = 'right';
  g.fillStyle = MUTE; g.font = '18px sans-serif';
  g.fillText('ID: ' + (b.contact.idType || '—') + '  ·  ' + (b.contact.idNum || '—'), W - pad, y + 40);
  g.textAlign = 'left';

  /* 4 tiles */
  y += 100;
  var tiles = [
    ['मिति · DATE',       fmtDate(b.date)],
    ['चढ्ने · BOARDING', (depHHMM || '—') + ' IST'],
    [(b.seats && b.seats.length > 1 ? 'सिट' : 'सिट नं.'), seatLabelJoin(b.seats, r.type, b.bookingType) || '—'],
    ['भाडा · FARE',      inr(b.total)]
  ];
  var tileW = (colW - 24) / 4, tileH = 110;
  tiles.forEach(function (t, i) {
    var tx = pad + i * (tileW + 8);
    g.fillStyle = '#F6F7FB';
    g.beginPath(); g.roundRect(tx, y, tileW, tileH, 10); g.fill();
    g.fillStyle = MUTE; g.font = '15px sans-serif';
    g.fillText(t[0], tx + 20, y + 32);
    g.fillStyle = NAVY; g.font = 'bold 28px sans-serif';
    g.fillText(String(t[1]), tx + 20, y + 72);
  });

  /* Boarding + Drop */
  y += tileH + 60;
  g.strokeStyle = LINE; g.beginPath(); g.moveTo(pad, y - 24); g.lineTo(W - pad, y - 24); g.stroke();
  g.fillStyle = MUTE; g.font = '15px sans-serif';
  g.fillText('चढ्ने ठाउँ  ·  PICKUP POINT', pad, y);
  g.fillText('गन्तव्य  ·  DESTINATION', pad + colW / 2 + 20, y);
  g.fillStyle = INK; g.font = 'bold 22px sans-serif';
  g.fillText(bpShort(b.boarding) || '—', pad, y + 34);
  g.fillText(bpShort(b.drop) || '—',     pad + colW / 2 + 20, y + 34);

  /* Passenger table (only if multi) */
  y += 90;
  if ((b.passengers || []).length > 1) {
    /* Group booking (Task 6): one shared name → one summary row (name · N
       passengers · all seats), not the same name repeated down the table. */
    var pnames = b.passengers.map(function (p) { return String(p.name || '').trim(); });
    var isGroup = (new Set(pnames)).size === 1 && pnames[0] !== '';
    g.fillStyle = NAVY; g.fillRect(pad, y, colW, 40);
    g.fillStyle = '#fff'; g.font = 'bold 17px sans-serif';
    g.fillText('SEAT', pad + 20, y + 26);
    g.fillText('यात्री · NAME', pad + 140, y + 26);
    if (!isGroup) { g.fillText('AGE', pad + 700, y + 26); g.fillText('GENDER', pad + 820, y + 26); }
    y += 40;
    if (isGroup) {
      g.fillStyle = '#F6F7FB'; g.fillRect(pad, y, colW, 38);
      g.fillStyle = INK; g.font = 'bold 18px sans-serif';
      g.fillText(pnames[0] + '  ·  ' + b.passengers.length + ' passengers · ' + seatLabelJoin(b.seats, r.type, b.bookingType)
        + (b.farePerSeat ? '  ·  ' + b.passengers.length + ' × ' + inr(b.farePerSeat) : ''), pad + 20, y + 26);
      y += 38;
    } else {
      b.passengers.forEach(function (p, i) {
        if (i % 2 === 0) { g.fillStyle = '#F6F7FB'; g.fillRect(pad, y, colW, 38); }
        g.fillStyle = INK; g.font = 'bold 18px monospace';
        g.fillText((p.seat ? seatLabel(p.seat, r.type, b.bookingType) : '—') + (b.ret ? ' / ' + seatLabel((b.ret.seats || [])[i] || '', routeById(b.ret.routeId) && routeById(b.ret.routeId).type, b.ret.bookingType) : ''), pad + 20, y + 26);
        g.font = '18px sans-serif';
        g.fillText(String(p.name || ''), pad + 140, y + 26);
        g.fillText(String(p.age  || ''), pad + 700, y + 26);
        g.fillText(String(p.gender || ''), pad + 820, y + 26);
        y += 38;
      });
    }
    y += 20;
  }

  /* QR + offices */
  y += 40;
  g.strokeStyle = LINE; g.beginPath(); g.moveTo(pad, y - 24); g.lineTo(W - pad, y - 24); g.stroke();
  try {
    var qrUrl = await makeQR('https://shreehariglobal.in/', 300);
    if (qrUrl) {
      await new Promise(function (res) {
        var img = new Image();
        img.onload = function () { g.drawImage(img, pad, y, 220, 220); res(); };
        img.onerror = res; img.src = qrUrl;
      });
    }
  } catch (e) {}
  g.fillStyle = MUTE; g.font = '16px sans-serif'; g.textAlign = 'center';
  g.fillText('Scan to visit  ·  shreehariglobal.in', pad + 110, y + 244);
  g.textAlign = 'left';

  var infoX = pad + 260;
  g.fillStyle = NAVY; g.font = 'bold 20px sans-serif';
  g.fillText('सम्पर्क  ·  OFFICE CONTACTS', infoX, y + 24);
  var offices = [
    ['Mehsana',   '+91 91048 01507'],
    ['Ahmedabad', '+91 91570 01507'],
    ['Baroda',    '+91 87358 81507'],
    ['Surat',     '+91 73593 01507']
  ];
  g.fillStyle = INK; g.font = '18px sans-serif';
  offices.forEach(function (o, i) {
    var ox = infoX + (i % 2) * ((W - pad - infoX) / 2);
    var oy = y + 60 + Math.floor(i / 2) * 32;
    g.font = 'bold 18px sans-serif'; g.fillText(o[0] + ':', ox, oy);
    g.font = '18px sans-serif';      g.fillText(o[1], ox + 130, oy);
  });
  g.fillStyle = MUTE; g.font = '15px sans-serif';
  g.fillText('Head Office: Near Shilpa Garage, Silver Complex, Mehsana – 384002', infoX, y + 156);
  g.fillStyle = INK; g.font = '16px sans-serif';
  g.fillText('Your contact: +91 ' + (b.contact.phone || '—'), infoX, y + 188);
  if (b.agentCode) {
    var ag = (DB.agents || []).find(function (a) { return a.code === b.agentCode; });
    g.fillStyle = ORANGE; g.font = 'bold 17px sans-serif';
    g.fillText('Booked via Agent: ' + b.agentCode + (ag ? '  (' + ag.name + ')' : ''), infoX, y + 214);
  }

  /* Footer */
  y = H - 120;
  g.strokeStyle = LINE; g.beginPath(); g.moveTo(pad, y - 20); g.lineTo(W - pad, y - 20); g.stroke();
  g.fillStyle = MUTE; g.font = '15px sans-serif'; g.textAlign = 'center';
  g.fillText('कृपया बोर्डिङ समयभन्दा ३० मिनेट अगाडि पुग्नुहोस्  ·  Reach the boarding point 30 min early.', W / 2, y + 4);
  g.fillText('भारत–नेपाल सीमामा मान्य सरकारी फोटो ID राख्नुहोस्  ·  Carry a valid photo ID at the border.', W / 2, y + 26);
  g.fillStyle = NAVY; g.font = 'bold 18px sans-serif';
  g.fillText('धन्यवाद  ·  Thank you for travelling with S Hari Global', W / 2, y + 62);
  g.textAlign = 'left';

  return cv;
}
/* Server PNG first (5 Sep 2026): ONE centralized HD ticket image for
   customer, agent and counter — rendered by Ticket::pngPath() so WhatsApp,
   the download button and the future auto-send all ship the same design.
   The session (owner / staff) authorizes the keyless URL; if the server
   says no, the old client canvas still draws a fallback. */
/* Post a message to the service worker, if there is one. Silent no-op
   when the SW is absent (first visit, private window, unsupported). */
function swMsg(msg) {
  try {
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage(msg);
      return true;
    }
  } catch (e) {}
  return false;
}
window.swMsg = swMsg;

/* Ask the worker to keep this ticket for offline use.
   Only ever called for a CONFIRMED booking: a pending one has no ticket to
   show, and caching it would hand the passenger an image that says
   "pending" at the border long after it was approved. */
function cacheTicketOffline(b) {
  if (!b || !b.id || b.status !== 'confirmed') return;
  swMsg({ type: 'shg-cache-ticket', pnr: b.id });
}
window.cacheTicketOffline = cacheTicketOffline;

function serverTicketPngBlob(b) {
  return fetch('/download-ticket.php?pnr=' + encodeURIComponent(b.id) + '&img=1&view=1', {
    credentials: 'same-origin', redirect: 'error'
  }).then(function (r) {
    if (!r.ok || (r.headers.get('content-type') || '').indexOf('image/png') !== 0) throw new Error('no png');
    return r.blob();
  });
}
function downloadTicketImage(b) {
  serverTicketPngBlob(b).then(function (blob) {
    const a = document.createElement('a');
    a.download = 'SHG-Ticket-' + b.id + '.png';
    a.href = URL.createObjectURL(blob);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 30000);
  }).catch(function () {
    // Old client-side canvas as the offline / refused fallback.
    buildTicketCanvas(b).then(cv => {
      const a = document.createElement('a');
      a.download = 'SHG-Ticket-' + b.id + '.jpg';
      a.href = cv.toDataURL('image/jpeg', 0.92);
      a.click();
    }).catch(() => toast('Could not generate ticket image — try the PDF instead.'));
  });
}
async function shareTicket(b) {
  const r = routeById(b.routeId) || {};
  const text = 'S Hari Global Pvt Ltd — E-Ticket ' + b.id + '\n'
    + (r.from || '') + ' → ' + (r.to || '') + ' · ' + fmtDate(b.date) + ' · Seats ' + seatLabelJoin(b.seats, r.type, b.bookingType) + '\n'
    + 'My Trip: ' + location.origin + location.pathname + '#/trip/' + b.id;
  try {
    let shareData = { title: 'S Hari Global E-Ticket', text: text };
    if (navigator.canShare) {
      // Server PNG first; the local canvas only if the server refuses.
      let blob = null;
      try { blob = await serverTicketPngBlob(b); } catch (e2) {}
      if (!blob && typeof buildTicketCanvas === 'function') {
        const cv = await buildTicketCanvas(b);
        blob = await new Promise(res => cv.toBlob(res, 'image/png'));
      }
      if (blob) {
        const file = new File([blob], 'SHG-Ticket-' + b.id + '.png', { type: 'image/png' });
        if (navigator.canShare({ files: [file] })) shareData.files = [file];
      }
    }
    await navigator.share(shareData);
  } catch (e) {
    if (e && e.name !== 'AbortError') toast('Sharing was cancelled or is not available on this device.');
  }
}

/* --- ticket helpers ---
   The passenger's SELECTED boarding point IS the ticket's origin. Reading
   this from b.boarding (parseBP → the town name) — not from the route's
   endpoint city — means the ticket says "EMLI BHUPAL → RUPAIDIHA" when
   they picked Emli Bhupal, not the route's default "SURAT". Every downstream
   text on the ticket reads through these helpers so nothing can drift.

   Labels are bilingual Nepali·English (Rule 3: dual-label format).
   NotoSansDevanagari.ttf is lazy-loaded into jsPDF for Devanagari rendering
   (Rule 4). If font loading fails, labels fall back to romanized Nepali
   "Yatri", "Bhada" etc. so they still print correctly (Rule 5: no mojibake).
   Place names, person names, ID types stay in Roman (Rule 2). */
function _boardName(b, r) {
  var raw = b && b.boarding ? bpShort(b.boarding) : '';
  var name = raw ? String(raw).split(' · ')[0].trim() : '';
  return name || (r && r.from) || '';
}
function _boardTimeHHMM(b, r) {
  if (b && b.boarding) {
    var p = parseBP(b.boarding);
    if (p && p.time) return p.time;
  }
  return (r && r.depTime) || '';
}
function _dropName(b, r) {
  var raw = b && b.drop ? bpShort(b.drop) : '';
  var name = raw ? String(raw).split(' · ')[0].trim() : '';
  return name || (r && r.to) || '';
}

async function downloadTicketPDF(b) {
  if (!(await waitForJsPDF($('#pdfBtn')))) return;
  toast('Preparing your ticket…');
  const r = routeById(b.routeId) || {};
  const rr = b.ret ? (routeById(b.ret.routeId) || {}) : null;
  const doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
  /* Load Devanagari font — Rule 4: full Devanagari Unicode block.
     If loading fails, hasDev=false → Rule 5: Roman fallback. */
  var hasDev = await _loadDevFont(doc);
  const W = 595.28, PH = 841.89;
  /* #logoNav used to carry a 52KB base64 blob purely so this check could
     require a data: URI. It now points at /assets/img/logo.png — the same
     file the page already preloads and the SW precaches — which keeps 52KB
     out of every no-store HTML load. So test for a DECODED image instead of
     a data: URI, and hand jsPDF the element (same-origin, so rasterising it
     never taints the canvas). naturalWidth is 0 until it decodes; a
     display:none <img> still decodes, but this guard keeps a cold cache
     from drawing a blank box. */
  const logo = document.getElementById('logoNav');
  const logoOk = !!(logo && logo.src && logo.complete && logo.naturalWidth > 0);
  const logoAsp = logoOk && logo.naturalHeight ? (logo.naturalWidth / logo.naturalHeight) : 1.37;

  const NAVY = [26, 58, 106], ORANGE = [240, 124, 31], INK = [30, 30, 30], MUTE = [120, 120, 130], LINE = [222, 224, 232];

  /* Airline-style header: single flat navy band, orange rule underneath */
  doc.setFillColor(NAVY[0], NAVY[1], NAVY[2]);
  doc.rect(0, 0, W, 96, 'F');
  doc.setFillColor(ORANGE[0], ORANGE[1], ORANGE[2]);
  doc.rect(0, 96, W, 3, 'F');

  /* Logo chip left */
  if (logoOk) {
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(24, 20, 68, 56, 6, 6, 'F');
    const lw = 54, lh = lw / logoAsp;
    try { doc.addImage(logo, 'PNG', 24 + (68 - lw) / 2, 20 + (56 - lh) / 2, lw, lh); } catch (e) {}
  }

  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold'); doc.setFontSize(17);
  doc.text('S HARI GLOBAL PVT LTD', 104, 40);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
  doc.setTextColor(200, 210, 230);
  _dt(doc, hasDev, 104, 55, 'भारत–नेपाल बस सेवा  ·  India–Nepal Bus Service', 'Bharat-Nepal Bus Sewa  ·  India-Nepal Bus Service');
  if (CONFIG.company && CONFIG.company.cin) {
    doc.text('CIN: ' + CONFIG.company.cin, 104, 68);
  }

  /* PNR block on the right, airline-style */
  doc.setFont('helvetica', 'bold'); doc.setFontSize(10);
  doc.setTextColor(255, 214, 100);
  _dt(doc, hasDev, W - 24, 32, 'यात्रा टिकट · BOARDING PASS', 'YATRA TICKET / BOARDING PASS', { align: 'right' });
  doc.setFont('courier', 'bold'); doc.setFontSize(16);
  doc.setTextColor(255, 255, 255);
  doc.text(String(b.id || ''), W - 24, 54, { align: 'right' });
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
  doc.setTextColor(200, 210, 230);
  doc.text('Issued ' + new Date().toLocaleDateString('en-IN'), W - 24, 70, { align: 'right' });

  /* Payment status band (green paid / orange COD / red pending) */
  var pay = b.payment || {}, isPaid = b.status === 'confirmed';
  var band, bandBg;
  if (isPaid) {
    var methodTxt = pay.method === 'esewa' ? 'eSewa' : (pay.method === 'cod' ? 'CASH' : 'UPI');
    bandBg = [14, 122, 66];
    band   = 'PAID  ·  ' + inr(b.total) + '  ·  ' + methodTxt;
  } else if (b.status === 'pending' && pay.method === 'cod') {
    bandBg = [196, 106, 0];
    band   = 'CASH ON BOARDING  ·  Pay ' + inr(b.total) + ' to the crew';
  } else {
    bandBg = [176, 42, 42];
    band   = 'PAYMENT PENDING  ·  Not valid for travel until approved';
  }
  doc.setFillColor(bandBg[0], bandBg[1], bandBg[2]);
  doc.rect(0, 99, W, 18, 'F');
  doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold'); doc.setFontSize(9);
  doc.text(band, W / 2, 111, { align: 'center' });

  /* ROUTE — big airline-style: BOARDING CITY (selected) → DROP CITY.
     THIS is the "selected city sync" fix: we no longer print r.from
     unconditionally. */
  var boardCity = _boardName(b, r).toUpperCase();
  var dropCity  = _dropName(b, r).toUpperCase();
  var depHHMM   = _boardTimeHHMM(b, r);

  var y = 148;
  doc.setTextColor(NAVY[0], NAVY[1], NAVY[2]);
  doc.setFont('helvetica', 'bold'); doc.setFontSize(24);
  doc.text(boardCity, 40, y);
  doc.text(dropCity,  W - 40, y, { align: 'right' });

  var fromW2 = doc.getTextWidth(boardCity);
  var toW2   = doc.getTextWidth(dropCity);
  doc.setDrawColor(ORANGE[0], ORANGE[1], ORANGE[2]); doc.setLineWidth(1.4);
  doc.setLineDashPattern([3, 3], 0);
  doc.line(48 + fromW2, y - 7, W - 48 - toW2, y - 7);
  doc.setLineDashPattern([], 0);

  /* Small "airplane" style arrow over the dashes */
  doc.setFont('helvetica', 'bold'); doc.setFontSize(11);
  doc.setTextColor(ORANGE[0], ORANGE[1], ORANGE[2]);
  doc.text('▶', W / 2, y - 3, { align: 'center' });

  /* Sub-line: departure time + travel date + coach */
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  doc.text('Dep ' + (depHHMM || '—') + ' IST', 40, y + 16);
  if (r.arrTime) {
    doc.text('Arr ' + r.arrTime + ' NPT' + (r.dayOffset ? ' (+' + r.dayOffset + 'd)' : ''), W - 40, y + 16, { align: 'right' });
  }
  doc.setTextColor(INK[0], INK[1], INK[2]);
  doc.text(fmtDate(b.date) + '  ·  ' + (r.busName || '—') + '  (' + (r.busNo || '') + ')', W / 2, y + 16, { align: 'center' });

  /* PASSENGER name, big */
  y += 46;
  doc.setDrawColor(LINE[0], LINE[1], LINE[2]); doc.setLineWidth(0.5);
  doc.line(40, y - 12, W - 40, y - 12);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  _dt(doc, hasDev, 40, y, 'यात्री  ·  PASSENGER', 'YATRI  ·  PASSENGER');
  var primaryPax = (b.passengers && b.passengers[0]) || {};
  doc.setFont('helvetica', 'bold'); doc.setFontSize(15);
  doc.setTextColor(NAVY[0], NAVY[1], NAVY[2]);
  doc.text(String(primaryPax.name || '').toUpperCase(), 40, y + 16);
  if ((b.passengers || []).length > 1) {
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
    doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
    doc.text('+ ' + (b.passengers.length - 1) + ' more — see the list below', 40, y + 30);
  }
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  doc.text('ID: ' + (b.contact.idType || '—') + '  ·  ' + (b.contact.idNum || '—'), W - 40, y + 16, { align: 'right' });

  /* 4-tile info strip: MITI / CHADNE / SEAT / BHADA */
  y += 50;
  var tiles = [
    [hasDev ? 'मिति · DATE' : 'MITI · DATE',               fmtDate(b.date)],
    [hasDev ? 'चढ्ने · BOARDING' : 'CHADNE · BOARDING',     (depHHMM || '—') + ' IST'],
    [hasDev ? (b.seats && b.seats.length > 1 ? 'सिट' : 'सिट नं.') : (b.seats && b.seats.length > 1 ? 'SEATS' : 'SEAT'), seatLabelJoin(b.seats, r.type, b.bookingType) || '—'],
    [hasDev ? 'भाडा · FARE' : 'BHADA · FARE',              inr(b.total)]
  ];
  var tileW = (W - 80) / 4, tileH = 46, tileGap = 2;
  tiles.forEach(function (t, i) {
    var tx = 40 + i * tileW;
    doc.setFillColor(246, 247, 251);
    doc.roundedRect(tx + tileGap, y, tileW - tileGap * 2, tileH, 5, 5, 'F');
    doc.setFontSize(6.8);
    doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
    if (hasDev) { doc.setFont('NotoSansDevanagari', 'normal'); } else { doc.setFont('helvetica', 'normal'); }
    doc.text(t[0], tx + 10, y + 14);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(11);
    doc.setTextColor(NAVY[0], NAVY[1], NAVY[2]);
    /* shrink to fit */
    var size = 12;
    while (size > 7.5 && doc.getTextWidth(String(t[1])) > tileW - 20) { size -= 0.5; doc.setFontSize(size); }
    doc.text(String(t[1]), tx + 10, y + 32);
  });

  /* BOARDING / DROP full details (with time) — the passenger's exact pickup */
  y += tileH + 24;
  doc.setDrawColor(LINE[0], LINE[1], LINE[2]); doc.setLineWidth(0.5);
  doc.line(40, y - 12, W - 40, y - 12);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  _dt(doc, hasDev, 40, y, 'चढ्ने ठाउँ  ·  PICKUP POINT', 'CHADNE THAUN  ·  PICKUP POINT');
  _dt(doc, hasDev, W / 2 + 10, y, 'गन्तव्य  ·  DESTINATION', 'GANTAVYA  ·  DESTINATION');
  doc.setFont('helvetica', 'bold'); doc.setFontSize(10.5);
  doc.setTextColor(INK[0], INK[1], INK[2]);
  doc.text(bpShort(b.boarding) || '—', 40, y + 15, { maxWidth: (W - 80) / 2 - 10 });
  doc.text(bpShort(b.drop) || '—',     W / 2 + 10, y + 15, { maxWidth: (W - 80) / 2 - 10 });

  /* Passenger table — only if more than one, keeps the ticket lean */
  if ((b.passengers || []).length > 1) {
    /* Group booking (Task 6): one shared name → one summary row. */
    var jpn = b.passengers.map(function (p) { return String(p.name || '').trim(); });
    var jGroup = (new Set(jpn)).size === 1 && jpn[0] !== '';
    y += 40;
    doc.setFillColor(NAVY[0], NAVY[1], NAVY[2]);
    doc.rect(40, y, W - 80, 18, 'F');
    doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5);
    doc.text('SEAT',     50,  y + 12);
    if (hasDev) { doc.setFont('NotoSansDevanagari', 'normal'); doc.text('यात्री · NAME', 110, y + 12); doc.setFont('helvetica', 'bold'); }
    else doc.text('YATRI · NAME', 110, y + 12);
    if (!jGroup) { doc.text('AGE', 360, y + 12); doc.text('GENDER',  420, y + 12); }
    y += 18;
    if (jGroup) {
      doc.setFillColor(246, 247, 251); doc.rect(40, y, W - 80, 18, 'F');
      doc.setTextColor(INK[0], INK[1], INK[2]); doc.setFont('helvetica', 'bold'); doc.setFontSize(9);
      doc.text(jpn[0] + '  ·  ' + b.passengers.length + ' passengers · ' + seatLabelJoin(b.seats, r.type, b.bookingType)
        + (b.farePerSeat ? '  ·  ' + b.passengers.length + ' x ' + inr(b.farePerSeat) : ''), 50, y + 12, { maxWidth: W - 100 });
      y += 18;
    } else {
      b.passengers.forEach(function (p, i) {
        if (i % 2 === 0) { doc.setFillColor(246, 247, 251); doc.rect(40, y, W - 80, 18, 'F'); }
        doc.setTextColor(INK[0], INK[1], INK[2]); doc.setFont('courier', 'bold'); doc.setFontSize(9);
        doc.text((p.seat ? seatLabel(p.seat, r.type, b.bookingType) : '—') + (b.ret ? ' / ' + seatLabel((b.ret.seats || [])[i] || '', rr.type, b.ret.bookingType) : ''), 50, y + 12);
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
        doc.text(String(p.name || ''), 110, y + 12);
        doc.text(String(p.age  || ''), 360, y + 12);
        doc.text(String(p.gender || ''), 420, y + 12);
        y += 18;
      });
    }
  }

  /* Return leg (compact one-liner, only if present) */
  if (b.ret && rr) {
    y += 20;
    doc.setFont('helvetica', 'bold'); doc.setFontSize(9);
    doc.setTextColor(ORANGE[0], ORANGE[1], ORANGE[2]);
    doc.text('↩ RETURN', 40, y);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
    doc.setTextColor(INK[0], INK[1], INK[2]);
    var retLine = _boardName(b.ret, rr).toUpperCase() + ' → ' + _dropName(b.ret, rr).toUpperCase()
      + '  ·  ' + fmtDate(b.ret.date) + '  ·  Seats ' + seatLabelJoin(b.ret.seats, rr.type, b.ret.bookingType);
    doc.text(retLine, 90, y);
  }

  /* QR + support block. Website QR is PERMANENT — always shreehariglobal.in */
  y += 34;
  doc.setDrawColor(LINE[0], LINE[1], LINE[2]); doc.setLineWidth(0.5);
  doc.line(40, y - 12, W - 40, y - 12);

  var qrY = y;
  try {
    var webQr = await makeQR('https://shreehariglobal.in/', 240);
    if (webQr) doc.addImage(webQr, 'PNG', 40, qrY, 108, 108);
  } catch (e) {}
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  doc.text('Scan to visit  ·  shreehariglobal.in', 94, qrY + 118, { align: 'center' });

  /* Office contacts, next to the QR — clean 4-line block */
  var infoX = 168;
  doc.setFont('helvetica', 'bold'); doc.setFontSize(9);
  doc.setTextColor(NAVY[0], NAVY[1], NAVY[2]);
  _dt(doc, hasDev, infoX, qrY + 10, 'सम्पर्क  ·  OFFICE CONTACTS', 'SAMPARK  ·  OFFICE CONTACTS');
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
  doc.setTextColor(INK[0], INK[1], INK[2]);
  var offices = [
    ['Mehsana',   '+91 91048 01507'],
    ['Ahmedabad', '+91 91570 01507'],
    ['Baroda',    '+91 87358 81507'],
    ['Surat',     '+91 73593 01507']
  ];
  offices.forEach(function (o, i) {
    var ox = infoX + (i % 2) * ((W - 40 - infoX) / 2);
    var oy = qrY + 28 + Math.floor(i / 2) * 14;
    doc.setFont('helvetica', 'bold'); doc.text(o[0] + ':', ox, oy);
    doc.setFont('helvetica', 'normal'); doc.text(o[1], ox + 52, oy);
  });
  doc.setFontSize(7.5); doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  doc.text('Head Office: Near Shilpa Garage, Silver Complex, Mehsana – 384002, Gujarat', infoX, qrY + 66);

  /* Agent — one clean line if applicable */
  if (b.agentCode) {
    var ag = (DB.agents || []).find(function (a) { return a.code === b.agentCode; });
    doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5);
    doc.setTextColor(ORANGE[0], ORANGE[1], ORANGE[2]);
    doc.text('Booked via Agent: ' + b.agentCode + (ag ? '  (' + ag.name + ')' : ''), infoX, qrY + 82);
  }

  /* Contact number */
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  doc.text('Your contact: +91 ' + (b.contact.phone || '—'), infoX, qrY + (b.agentCode ? 96 : 82));

  /* Footer — clean, minimal, only essentials */
  var footY = PH - 44;
  doc.setDrawColor(LINE[0], LINE[1], LINE[2]); doc.setLineWidth(0.5);
  doc.line(40, footY - 6, W - 40, footY - 6);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
  doc.setTextColor(MUTE[0], MUTE[1], MUTE[2]);
  _dt(doc, hasDev, W / 2, footY + 4,
    'कृपया बोर्डिङ समयभन्दा ३० मिनेट अगाडि पुग्नुहोस्  ·  Reach the boarding point 30 min early.',
    'Kripaya boarding time bhanda 30 min agadi thau ma pugnuhos  ·  Reach the boarding point 30 min early.',
    { align: 'center' });
  _dt(doc, hasDev, W / 2, footY + 14,
    'भारत–नेपाल सीमामा मान्य सरकारी फोटो ID राख्नुहोस्  ·  Carry a valid photo ID at the border.',
    'Bharat–Nepal border ma manya sarkari photo ID sathama rakhnuhos  ·  Carry a valid government photo ID at the border.',
    { align: 'center' });
  doc.setFont('helvetica', 'bold'); doc.setFontSize(8);
  doc.setTextColor(NAVY[0], NAVY[1], NAVY[2]);
  _dt(doc, hasDev, W / 2, footY + 28,
    'धन्यवाद  ·  Thank you for travelling with S Hari Global',
    'Dhanyabad  ·  Thank you for travelling with S Hari Global',
    { align: 'center' });

  doc.save('SHG-Ticket-' + b.id + '.pdf');
}

async function downloadTimetablePDF() {
  if (!(await waitForJsPDF(null))) return;
  toast('Preparing timetable…');
  var doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: 'landscape' });
  var W = 841.89, H = 595.28, mx = 40, y = 40;
  doc.setFillColor(18, 38, 78); doc.rect(0, 0, W, 60, 'F');
  doc.setTextColor(255); doc.setFontSize(18); doc.setFont('helvetica', 'bold');
  doc.text('S Hari Global Pvt Ltd — Bus Timetable', mx, 38);
  doc.setFontSize(10); doc.setFont('helvetica', 'normal');
  doc.text('Surat / Gujarat ⇄ Rupaidiha · India–Nepal Border', mx, 54);
  doc.text('Generated: ' + new Date().toLocaleDateString('en-IN'), W - mx, 38, { align: 'right' });
  y = 80;
  var routes = DB.routes.filter(function (r) { return r.active; });
  routes.forEach(function (r, ri) {
    if (y > H - 120) { doc.addPage(); y = 40; }
    doc.setFillColor(46, 95, 168); doc.rect(mx, y, W - mx * 2, 22, 'F');
    doc.setTextColor(255); doc.setFontSize(11); doc.setFont('helvetica', 'bold');
    doc.text(r.busName + ' (' + r.busNo + ') — ' + r.from + ' → ' + r.to + ' · ' + r.type.toUpperCase(), mx + 8, y + 15);
    doc.text('Dep: ' + r.depTime + '  Arr: ' + r.arrTime + ' (+' + (r.dayOffset || 0) + 'd)  Duration: ' + r.duration, W - mx - 8, y + 15, { align: 'right' });
    y += 30;
    doc.setTextColor(60); doc.setFontSize(9); doc.setFont('helvetica', 'bold');
    doc.text('BOARDING POINTS (Entry)', mx + 4, y + 10);
    y += 16;
    doc.setFont('helvetica', 'normal');
    (r.boarding || []).forEach(function (bp) {
      var p = parseBP(bp);
      doc.setFillColor(ri % 2 === 0 ? 245 : 250, 248, 255); doc.rect(mx + 4, y - 2, (W - mx * 2) / 2 - 16, 14, 'F');
      doc.text((p.time || '--:--') + '  ' + p.name + (p.landmark ? ' · ' + p.landmark : ''), mx + 8, y + 8);
      y += 16;
    });
    y -= (r.boarding || []).length * 16;
    var dx = W / 2 + 20;
    doc.setFont('helvetica', 'bold');
    doc.text('DROP POINTS (Exit)', dx, y - 6 + 10);
    y += 0;
    doc.setFont('helvetica', 'normal');
    (r.drop || []).forEach(function (bp) {
      var p = parseBP(bp);
      doc.setFillColor(255, ri % 2 === 0 ? 248 : 252, 240); doc.rect(dx, y - 2, (W - mx * 2) / 2 - 16, 14, 'F');
      doc.text((p.time || '--:--') + '  ' + p.name + (p.landmark ? ' · ' + p.landmark : ''), dx + 4, y + 8);
      y += 16;
    });
    var maxRows = Math.max((r.boarding || []).length, (r.drop || []).length);
    y = y + (maxRows - (r.drop || []).length) * 16 + 20;
  });
  y += 10;
  doc.setFontSize(8); doc.setTextColor(120);
  doc.text('CEO: ' + (CONFIG.company.ceo || '') + '  |  CIN: ' + CONFIG.company.cin + '  |  Contact: ' + S().phone, mx, y);
  doc.save('SHG-Bus-Timetable.pdf');
  toast('Timetable downloaded!');
}

async function downloadRouteMapPDF() {
  if (!(await waitForJsPDF(null))) return;
  toast('Preparing route map…');
  var doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
  var W = 595.28, mx = 40, y = 40;
  doc.setFillColor(18, 38, 78); doc.rect(0, 0, W, 55, 'F');
  doc.setTextColor(255); doc.setFontSize(16); doc.setFont('helvetica', 'bold');
  doc.text('S Hari Global — Route Map', mx, 35);
  doc.setFontSize(9); doc.setFont('helvetica', 'normal');
  /* Display-only corridor summary (short names — canonical stop names would overflow the line) */
  doc.text('Surat → Bharuch → Vadodara → Nadiad → Ahmedabad (Nana Chiloda) → Rupaidiha', mx, 48);
  doc.text(new Date().toLocaleDateString('en-IN'), W - mx, 35, { align: 'right' });
  y = 70;
  var paths = [
    { id: 'via_gorakhpur', label: 'Route A — Via Gorakhpur' },
    { id: 'via_bahraich', label: 'Route B — Via Bahraich' }
  ];
  paths.forEach(function (route) {
    var stops = TRACKER_PATHS[route.id];
    if (!stops) return;
    doc.setFillColor(240, 124, 31); doc.rect(mx, y, W - mx * 2, 20, 'F');
    doc.setTextColor(255); doc.setFontSize(11); doc.setFont('helvetica', 'bold');
    doc.text(route.label + ' — ' + stops.length + ' stops, ~' + stops[stops.length - 1].km + ' km', mx + 8, y + 14);
    y += 30;
    var lineX = mx + 14, dataX = mx + 32;
    stops.forEach(function (s, i) {
      var isFirst = i === 0, isLast = i === stops.length - 1, isBorder = s.flag === '🛃';
      if (i > 0) { doc.setDrawColor(180); doc.setLineWidth(1); doc.line(lineX, y - 6, lineX, y + 2); }
      if (isBorder) { doc.setFillColor(220, 50, 50); }
      else if (isFirst || isLast) { doc.setFillColor(46, 95, 168); }
      else if (s.landmark) { doc.setFillColor(240, 124, 31); }
      else { doc.setFillColor(160, 175, 200); }
      doc.circle(lineX, y + 6, isFirst || isLast || isBorder ? 5 : 3.5, 'F');
      doc.setTextColor(30); doc.setFontSize(s.landmark || isFirst || isLast || isBorder ? 10 : 8.5);
      doc.setFont('helvetica', s.landmark || isFirst || isLast || isBorder ? 'bold' : 'normal');
      var label = (s.flag ? s.flag + ' ' : '') + s.name + '  —  ' + s.km + ' km';
      if (isBorder) label += '  [BORDER CHECKPOINT]';
      doc.text(label, dataX, y + 9);
      y += (s.landmark || isFirst || isLast || isBorder) ? 22 : 17;
      if (y > 780) { doc.addPage(); y = 40; }
    });
    y += 16;
  });
  doc.setFontSize(8); doc.setTextColor(120);
  doc.text('S Hari Global Pvt Ltd · CIN: ' + CONFIG.company.cin + ' · CEO: ' + (CONFIG.company.ceo || ''), mx, y);
  doc.text('Route data from OpenStreetMap. Distances approximate.', mx, y + 12);
  doc.save('SHG-Route-Map.pdf');
  toast('Route map downloaded!');
}
