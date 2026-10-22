/* =====================================================================
   staff-sign.js — the sign box at the bottom of
   /agreements/staff-agreement.html (22 Oct 2026).

   An applicant or an approved staff member (the approval WhatsApp links
   here with ?ref=STF-…) signs the staff agreement from the phone:
   name + mobile -> WhatsApp code -> finger signature -> /api/staff-apply.php
   action "sign". The server keeps name, mobile (proven or not), time, IP
   and the signature PNG in agreement_signatures. When WhatsApp cannot
   deliver the code, the sign goes through unproven after two minutes and
   the office sees that. Nobody without an application can sign.
   ===================================================================== */
(function () {
  'use strict';
  var mount = document.getElementById('signMount');
  if (!mount) return;
  var st = { csrf: null, otp: 'none', skipT: null, inked: 0 };
  var refQ = (location.search.match(/[?&]ref=(STF-\d{6})/i) || [])[1] || '';

  mount.innerHTML =
    (refQ ? '<p class="hint">आवेदन · Application <b>' + refQ.toUpperCase() + '</b></p>' : '') +
    '<label class="f"><span>पूरा नाम (आवेदनमा जस्तै) · Full name <em>*</em></span><input type="text" id="sgName" autocomplete="name" maxlength="120"></label>' +
    '<label class="f"><span>मोबाइल · Mobile <em>*</em></span><div class="row2"><select id="sgCc" aria-label="Country code"><option value="977">🇳🇵 +977</option><option value="91">🇮🇳 +91</option></select>' +
    '<input type="tel" id="sgPhone" inputmode="numeric" maxlength="14" placeholder="98XXXXXXXX" autocomplete="tel-national"></div></label>' +
    '<button type="button" class="btn ghost" id="sgSend">📲 WhatsApp कोड पठाउनुहोस् · Send code</button>' +
    '<div class="row2" id="sgCodeRow" hidden style="margin-top:8px;grid-template-columns:minmax(0,1fr) auto"><input type="text" id="sgCode" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="••••••" aria-label="Code" style="letter-spacing:.3em;text-align:center;font-weight:800"><button type="button" class="btn small" id="sgVerify">✓ प्रमाणित</button></div>' +
    '<p class="ok" id="sgOtpOk" style="margin:8px 0 0"></p>' +
    '<p class="hint" id="sgSkip" hidden style="margin:6px 0 0">कोड आएन? <button type="button" class="btn ghost small" id="sgSkipBtn">कोड बिना सही गर्नुहोस्</button></p>' +
    '<p style="margin:14px 0 6px;font-weight:700;font-size:13px">✍️ औंलाले सही · Sign with your finger <em style="color:var(--bad);font-style:normal">*</em></p>' +
    '<div class="sigw" id="sgW"><canvas id="sgC" aria-label="Sign here with your finger"></canvas><div class="line"></div><div class="ph">✍️ यहाँ सही गर्नुहोस् · Sign here</div></div>' +
    '<div class="sig-bar"><span class="ok" id="sgSigOk"></span><button type="button" class="btn ghost small" id="sgClear">↺ मेटाउनुहोस्</button></div>' +
    '<label class="chk"><input type="checkbox" id="sgAgree"><span>मैले यो कर्मचारी सम्झौता पढें, बुझें र स्वीकार गर्छु। · I have read, understood and accept this staff agreement.</span></label>' +
    '<button type="button" class="btn go" id="sgGo">✍️ सही गरेर पठाउनुहोस् · Sign &amp; send</button>' +
    '<p class="msg" id="sgMsg" hidden role="alert"></p>';

  var $ = function (id) { return document.getElementById(id); };
  function digits(v) { return String(v || '').replace(/[०-९]/g, function (d) { return String(d.charCodeAt(0) - 0x0966); }).replace(/\D/g, '').replace(/^0+/, ''); }
  function say(t, good) { var m = $('sgMsg'); m.textContent = t || ''; m.className = 'msg ' + (good ? 'good' : 'bad'); m.hidden = !t; }
  function token() {
    if (st.csrf) return Promise.resolve(st.csrf);
    return fetch('/api/staff-apply.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
      .then(function (d) { st.csrf = d && d.csrf; return st.csrf; });
  }
  function post(body) {
    return token().then(function (t) {
      return fetch('/api/staff-apply.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t || '' }, body: JSON.stringify(body) });
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
      .then(function (d) { if (d && d.code === 419) st.csrf = null; return d; });
  }
  token().catch(function () {});

  /* ---- WhatsApp code ---- */
  $('sgSend').addEventListener('click', function () {
    var b = this, ph = digits($('sgPhone').value); say('');
    if (ph.length !== 10) { $('sgPhone').setAttribute('aria-invalid', 'true'); say('१० अङ्कको मोबाइल नम्बर लेख्नुहोस्।'); return; }
    $('sgPhone').removeAttribute('aria-invalid'); b.disabled = true;
    post({ action: 'otp', phone: ph, cc: $('sgCc').value }).then(function (d) {
      b.disabled = false; b.textContent = '📲 फेरि पठाउनुहोस् · Send again';
      if (!d || !d.ok) { say((d && d.error) || 'कोड पठाउन सकिएन।'); return; }
      if (!d.sent) { st.otp = 'skip'; b.hidden = true; $('sgOtpOk').textContent = '⚠️ WhatsApp ले कोड पठाउन सकेन — सही गर्न सकिन्छ, कार्यालयले फोन गरेर पुष्टि गर्छ।'; return; }
      $('sgCodeRow').hidden = false; $('sgCode').focus();
      $('sgOtpOk').textContent = '✓ कोड WhatsApp मा पठाइयो' + (d.debugCode ? ' [dev: ' + d.debugCode + ']' : '');
      clearTimeout(st.skipT); st.skipT = setTimeout(function () { if (st.otp === 'none') $('sgSkip').hidden = false; }, (d.skipAfter || 120) * 1000);
    }).catch(function () { b.disabled = false; say('इन्टरनेट जाँच गर्नुहोस्।'); });
  });
  $('sgVerify').addEventListener('click', function () {
    var b = this; b.disabled = true;
    post({ action: 'verify', phone: digits($('sgPhone').value), cc: $('sgCc').value, code: digits($('sgCode').value) }).then(function (d) {
      b.disabled = false;
      if (d && d.ok) { st.otp = 'verified'; $('sgCodeRow').hidden = true; $('sgSkip').hidden = true; $('sgSend').hidden = true; $('sgOtpOk').textContent = '✅ मोबाइल प्रमाणित भयो · verified'; return; }
      $('sgCode').setAttribute('aria-invalid', 'true'); say((d && d.error) || 'कोड मिलेन।');
    }).catch(function () { b.disabled = false; say('इन्टरनेट जाँच गर्नुहोस्।'); });
  });
  $('sgSkipBtn').addEventListener('click', function () { st.otp = 'skip'; $('sgSkip').hidden = true; $('sgCodeRow').hidden = true; $('sgOtpOk').textContent = 'ठीक छ — कार्यालयले फोन गरेर पुष्टि गर्छ।'; });
  ['sgPhone', 'sgCc'].forEach(function (id) { $(id).addEventListener('change', function () { if (st.otp !== 'none') { st.otp = 'none'; $('sgSend').hidden = false; $('sgOtpOk').textContent = ''; } }); });

  /* ---- finger signature ---- */
  var cv = $('sgC'), wrap = $('sgW'), ctx = cv.getContext('2d'), drawing = false, last = null;
  function size() {
    var r = cv.getBoundingClientRect(); if (!r.width) return;
    var dpr = Math.min(2, window.devicePixelRatio || 1), keep = st.inked ? cv.toDataURL() : null;
    cv.width = Math.round(r.width * dpr); cv.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0a2a8f'; ctx.lineWidth = 2.6;
    if (keep) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = keep; }
  }
  size();
  window.addEventListener('resize', function () { clearTimeout(size._t); size._t = setTimeout(size, 150); });
  function pt(e) { var r = cv.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  cv.addEventListener('pointerdown', function (e) { e.preventDefault(); drawing = true; last = pt(e); try { cv.setPointerCapture(e.pointerId); } catch (er) {} });
  cv.addEventListener('pointermove', function (e) {
    if (!drawing) return; e.preventDefault();
    var p = pt(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.quadraticCurveTo(last.x, last.y, (last.x + p.x) / 2, (last.y + p.y) / 2); ctx.lineTo(p.x, p.y); ctx.stroke();
    st.inked += Math.abs(p.x - last.x) + Math.abs(p.y - last.y); last = p;
    if (st.inked > 40) { wrap.classList.add('drawn'); $('sgSigOk').textContent = '✓ सही भयो'; }
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) { cv.addEventListener(n, function () { drawing = false; }); });
  $('sgClear').addEventListener('click', function () { ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, cv.width, cv.height); ctx.restore(); st.inked = 0; wrap.classList.remove('drawn'); $('sgSigOk').textContent = ''; });
  function png() {
    var w = cv.width, h = cv.height, d = ctx.getImageData(0, 0, w, h).data, x0 = w, y0 = h, x1 = 0, y1 = 0;
    for (var y = 0; y < h; y += 2) for (var x = 0; x < w; x += 2) if (d[(y * w + x) * 4 + 3] > 20) { if (x < x0) x0 = x; if (y < y0) y0 = y; if (x > x1) x1 = x; if (y > y1) y1 = y; }
    if (x1 <= x0 || y1 <= y0) return null;
    x0 = Math.max(0, x0 - 16); y0 = Math.max(0, y0 - 16); x1 = Math.min(w, x1 + 16); y1 = Math.min(h, y1 + 16);
    var k = Math.min(1, 900 / (x1 - x0)), o = document.createElement('canvas'); o.width = Math.round((x1 - x0) * k); o.height = Math.round((y1 - y0) * k);
    var ox = o.getContext('2d'); ox.fillStyle = '#fff'; ox.fillRect(0, 0, o.width, o.height); ox.drawImage(cv, x0, y0, x1 - x0, y1 - y0, 0, 0, o.width, o.height);
    return o.toDataURL('image/png');
  }

  /* ---- sign ---- */
  $('sgGo').addEventListener('click', function () {
    var b = this; say('');
    if ($('sgName').value.trim().length < 3) { $('sgName').setAttribute('aria-invalid', 'true'); say('पूरा नाम लेख्नुहोस्।'); return; }
    if (st.otp === 'none') { say('पहिले WhatsApp कोडले मोबाइल प्रमाणित गर्नुहोस्।'); return; }
    if (st.inked <= 40) { say('बाकसभित्र औंलाले सही गर्नुहोस्।'); return; }
    if (!$('sgAgree').checked) { say('सम्झौता स्वीकार गर्छु भनी ✓ लगाउनुहोस्।'); return; }
    b.disabled = true;
    post({ action: 'sign', name: $('sgName').value.trim(), phone: digits($('sgPhone').value), cc: $('sgCc').value, signature: png() }).then(function (d) {
      b.disabled = false;
      if (d && d.ok) {
        mount.innerHTML = '<p class="msg good">✅ सही भयो · Signed — ' + String(d.ref || '') + ' · v' + String(d.version || '') + ' · ' + String(d.signedAt || '') +
          (d.verified ? ' · 📱 ✓' : '') + '</p><p class="hint">यो पेज बन्द गर्न सक्नुहुन्छ। कार्यालयमा पनि रेकर्ड भयो। · You can close this page.</p>';
        return;
      }
      say((d && d.error) || 'सही पठाउन सकिएन — फेरि प्रयास गर्नुहोस्।');
    }).catch(function () { b.disabled = false; say('इन्टरनेट जाँच गर्नुहोस्।'); });
  });
})();
