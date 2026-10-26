/* =====================================================================
   wa-verify.js — "WhatsApp मा hi पठाउनुहोस्" number check (22 Oct 2026,
   owner: "WhatsApp redirect garera hi lekhne ani back aune"; later the same
   day: "button 2 choti click garnu parx, direct khulne banau").

   ShgWaVerify.mount(box, {
     purpose: 'staff' | 'agent' | 'sign',
     phone:   () => ({ phone: '98XXXXXXXX', cc: '91' | '977' }) | null (null = number not typed yet),
     token:   () => Promise<csrf>,
     onDone:  (how) => {}   how = 'verified' | 'skip'
   }) -> { reset(), state() }

   ONE TAP: as soon as the box is on screen and the number is complete,
   the page quietly asks /api/wa-verify.php for the token, so the button
   already holds the final link and the tap itself opens WhatsApp — the app
   on a phone (whatsapp://), WhatsApp Web on a computer (wa-direct.js), with
   "hi VERIFY K7Q2M9" typed to the company number. Then the page asks the
   server every 3 s (and the moment the person comes back) whether that
   message arrived from the same number. Two minutes after the first tap a
   "no WhatsApp on this number?" way out appears.

   23 Oct 2026: on a computer (no WhatsApp app, mouse pointer) the same
   link is also drawn as a QR (api/wa-verify.php "qr") the moment the number
   is complete, and the page starts asking the server right away - the
   person scans, presses Send on the phone, and the computer screen turns green.
   ===================================================================== */
(function () {
  'use strict';
  function el(tag, css, text) { var e = document.createElement(tag); if (css) e.style.cssText = css; if (text != null) e.textContent = text; return e; }
  function waUrl(to, text) {
    return window.ShgWa ? window.ShgWa.direct(to, text) : 'https://wa.me/' + to + '?text=' + encodeURIComponent(text);
  }

  function mount(box, o) {
    var s = { token: '', to: '', text: '', key: '', status: 'none', poll: null, skipT: null, first: 0, phone: '', busy: null, seen: false, qr: '' };
    // 23 Oct 2026 (owner: "PC bata QR dekhayera"): no WhatsApp on this machine -> the phone scans a QR instead
    var PC = !(window.ShgWa && window.ShgWa.mobile) && !(window.matchMedia && matchMedia('(pointer:coarse)').matches);
    box.textContent = '';
    var btn = el('a', 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;min-height:52px;padding:0 14px;border-radius:14px;font:inherit;font-size:15.5px;font-weight:800;color:#fff;background:#1faa59;box-shadow:0 4px 0 #127a3e;cursor:pointer;text-decoration:none',
      '💬 WhatsApp मा "hi" पठाउनुहोस् · Verify on WhatsApp');
    btn.href = '#';
    btn.setAttribute('role', 'button');
    btn.setAttribute('data-shg-wa-plain', '1');   // wa-direct.js leaves this one to us
    var info = el('p', 'margin:8px 0 0;font-size:13px;line-height:1.45', 'एक ट्यापमा WhatsApp खुल्छ, सन्देश आफैं लेखिएको हुन्छ — Send थिचेर यहीँ फर्कनुहोस्। कोड टाइप गर्नु पर्दैन।');
    var out = el('p', 'margin:8px 0 0;font-size:13.5px;font-weight:800');
    var skip = el('button', 'display:none;margin-top:6px;background:none;border:0;padding:0;min-height:44px;color:inherit;opacity:.8;font:inherit;font-size:13px;text-decoration:underline;cursor:pointer',
      'यो नम्बरमा WhatsApp छैन? प्रमाण बिना अगाडि बढ्नुहोस् (कार्यालयले फोन गर्छ)');
    skip.type = 'button';
    var qrBox = el('div', 'display:none;margin:10px 0 0;padding:12px;border-radius:16px;background:#fff;border:1.5px solid #cfe8d8;text-align:center;color:#0d1b3e');
    var qrImg = el('img', 'display:block;width:168px;height:168px;margin:0 auto;image-rendering:pixelated;border-radius:10px;box-shadow:0 0 0 3px #fff,0 0 0 5px #1faa59');
    qrImg.alt = 'WhatsApp QR'; qrImg.width = 168; qrImg.height = 168;
    var qrTxt = el('p', 'margin:8px 0 0;font-size:13px;font-weight:800;line-height:1.4', '📱 फोनको क्यामेराले स्क्यान गर्नुहोस् — WhatsApp खुल्छ, Send थिच्नुहोस् · Scan with your phone, WhatsApp opens, press Send');
    qrBox.appendChild(qrImg); qrBox.appendChild(qrTxt);
    box.appendChild(btn); box.appendChild(info); box.appendChild(out); box.appendChild(qrBox); box.appendChild(skip);

    function say(t, ok) { out.textContent = t; out.style.color = ok ? '#0f9d6b' : ''; }
    function stop() { clearInterval(s.poll); s.poll = null; }
    function post(body) {
      return o.token().then(function (t) {
        return fetch('/api/wa-verify.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t || '' }, body: JSON.stringify(body) });
      }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
    }
    function setLink() {
      if (s.token) {
        btn.href = waUrl(s.to, s.text);
        btn.target = window.ShgWa && window.ShgWa.mobile ? '' : '_blank';
        btn.rel = 'noopener';
      } else {
        btn.href = '#'; btn.removeAttribute('target');
      }
    }
    /* the token for the number on the form, fetched before the tap */
    function prepare() {
      var p = o.phone();
      var key = p && String(p.phone || '').length === 10 ? p.cc + ':' + p.phone : '';
      if (!key || key === s.key || s.status === 'verified' || s.status === 'skip') {
        return s.busy || Promise.resolve(!!s.token && key === s.key);
      }
      if (s.busy) return s.busy;
      s.busy = post({ action: 'start', purpose: o.purpose, phone: p.phone, cc: p.cc }).then(function (d) {
        s.busy = null;
        if (!d || !d.ok) { say((d && d.error) || ''); return false; }
        s.token = d.token; s.to = d.to; s.text = d.text; s.key = key; s.phone = '+' + p.cc + ' ' + p.phone; s.qr = d.qr || '';
        setLink();
        if (PC && s.qr) { qrImg.src = s.qr; qrBox.style.display = 'block'; if (s.status === 'none') waiting(true); }
        return true;
      }).catch(function () { s.busy = null; return false; });
      return s.busy;
    }
    function done(how) {
      stop(); clearTimeout(s.skipT); s.status = how; skip.style.display = 'none';
      if (how === 'verified') {
        btn.style.display = 'none'; info.style.display = 'none'; qrBox.style.display = 'none';
        say('✅ ' + (s.phone ? s.phone + ' — ' : '') + 'WhatsApp बाट प्रमाणित भयो · verified', true);
        try { if (navigator.vibrate) navigator.vibrate([10, 40, 10]); } catch (e) {}
      } else {
        say('ठीक छ — प्रमाण बिना पठाइन्छ; कार्यालयले फोन गरेर पुष्टि गर्छ।');
      }
      if (o.onDone) o.onDone(how);
    }
    function check() {
      if (!s.token || s.status !== 'waiting') return;
      post({ action: 'check', token: s.token }).then(function (d) {
        if (!d || !d.ok) return;
        if (d.status === 'verified') { done('verified'); return; }
        if (d.status === 'mismatch') say('❌ सन्देश अर्को नम्बरबाट आयो — फारममा लेखेको नम्बरको WhatsApp बाट नै पठाउनुहोस्।');
        if (d.status === 'expired') { stop(); s.token = ''; s.key = ''; s.qr = ''; qrBox.style.display = 'none'; s.status = 'none'; setLink(); say('समय सकियो — बटन फेरि थिच्नुहोस्।'); prepare(); }
      }).catch(function () {});
    }
    function waiting(quiet) {
      s.status = 'waiting';
      if (!quiet) say('⏳ WhatsApp मा "' + s.text + '" पठाउनुहोस्, अनि यहीँ फर्कनुहोस्…');
      stop(); s.poll = setInterval(check, 3000);
      if (!s.first) {
        s.first = Date.now();
        s.skipT = setTimeout(function () { if (s.status !== 'verified') skip.style.display = 'block'; }, 120000);
      }
    }
    btn.addEventListener('click', function (e) {
      var p = o.phone();
      if (!p || String(p.phone || '').length !== 10) { e.preventDefault(); say('पहिले १० अङ्कको मोबाइल नम्बर लेख्नुहोस्।'); return; }
      var key = p.cc + ':' + p.phone;
      if (s.token && key === s.key) {
        // the link is ready: let this very tap open WhatsApp
        if (window.ShgWa) { e.preventDefault(); window.ShgWa.open(s.to, s.text, 'https://wa.me/' + s.to + '?text=' + encodeURIComponent(s.text)); }
        waiting();
        return;
      }
      // not ready yet (number just typed): fetch, then open
      e.preventDefault();
      say('⏳ …');
      prepare().then(function (ok) {
        if (!ok) return;
        if (window.ShgWa) window.ShgWa.open(s.to, s.text, 'https://wa.me/' + s.to + '?text=' + encodeURIComponent(s.text));
        else window.open(btn.href, '_blank');
        waiting();
      });
    });
    skip.addEventListener('click', function () { done('skip'); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.addEventListener('focus', check);

    /* get the link ready while the box is on screen and the number is complete */
    function onScreen() {
      if (box.offsetParent === null) return false;   // a hidden step
      var r = box.getBoundingClientRect();
      return r.bottom > -200 && r.top < (window.innerHeight || 800) + 400;
    }
    setInterval(function () { if (s.status === 'none' && onScreen()) prepare(); }, 1200);

    return {
      reset: function () { stop(); clearTimeout(s.skipT); s.token = ''; s.key = ''; s.qr = ''; s.status = 'none'; s.first = 0; setLink(); btn.style.display = ''; info.style.display = ''; qrBox.style.display = 'none'; skip.style.display = 'none'; say(''); },
      state: function () { return s.status; }
    };
  }
  window.ShgWaVerify = { mount: mount };
})();
