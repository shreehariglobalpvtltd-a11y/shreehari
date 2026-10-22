/* =====================================================================
   wa-verify.js — "WhatsApp मा hi पठाउनुहोस्" number check (22 Oct 2026,
   owner: "WhatsApp redirect garera hi lekhne ani back aune").

   ShgWaVerify.mount(box, {
     purpose: 'staff' | 'agent' | 'sign',
     phone:   () => ({ phone: '98XXXXXXXX', cc: '91' | '977' }) | null (null = number not typed yet),
     token:   () => Promise<csrf>,
     onDone:  (how) => {}   how = 'verified' | 'skip'
   }) -> { reset(), state() }

   The button asks /api/wa-verify.php for a token, opens WhatsApp with
   "hi VERIFY K7Q2M9" typed to the company number, and then asks the server
   every 3 s (and the moment the person comes back to the page) whether that
   message arrived from the same number. Two minutes after the first press a
   "no WhatsApp on this number?" way out appears; the office then sees the
   number as not verified. Styles are inline so every page can use it.
   ===================================================================== */
(function () {
  'use strict';
  function el(tag, css, text) { var e = document.createElement(tag); if (css) e.style.cssText = css; if (text != null) e.textContent = text; return e; }

  function mount(box, o) {
    var s = { token: '', status: 'none', poll: null, skipT: null, first: 0, phone: '' };
    box.textContent = '';
    var btn = el('button', 'display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:52px;padding:0 14px;border:0;border-radius:14px;font:inherit;font-size:15.5px;font-weight:800;color:#fff;background:#1faa59;box-shadow:0 4px 0 #127a3e;cursor:pointer',
      '💬 WhatsApp मा "hi" पठाउनुहोस् · Verify on WhatsApp');
    btn.type = 'button';
    var info = el('p', 'margin:8px 0 0;font-size:13px;line-height:1.45', 'बटन थिच्दा WhatsApp खुल्छ, सन्देश आफैं लेखिएको हुन्छ — Send थिचेर यहीँ फर्कनुहोस्। कोड टाइप गर्नु पर्दैन।');
    var out = el('p', 'margin:8px 0 0;font-size:13.5px;font-weight:800');
    var skip = el('button', 'display:none;margin-top:6px;background:none;border:0;padding:0;min-height:44px;color:inherit;opacity:.8;font:inherit;font-size:13px;text-decoration:underline;cursor:pointer',
      'यो नम्बरमा WhatsApp छैन? प्रमाण बिना अगाडि बढ्नुहोस् (कार्यालयले फोन गर्छ)');
    skip.type = 'button';
    box.appendChild(btn); box.appendChild(info); box.appendChild(out); box.appendChild(skip);

    function say(t, ok) { out.textContent = t; out.style.color = ok ? '#0f9d6b' : ''; }
    function stop() { clearInterval(s.poll); s.poll = null; }
    function post(body) {
      return o.token().then(function (t) {
        return fetch('/api/wa-verify.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t || '' }, body: JSON.stringify(body) });
      }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
    }
    function done(how) {
      stop(); clearTimeout(s.skipT); s.status = how; skip.style.display = 'none';
      if (how === 'verified') {
        btn.style.display = 'none'; info.style.display = 'none';
        say('✅ ' + (s.phone ? s.phone + ' — ' : '') + 'WhatsApp बाट प्रमाणित भयो · verified', true);
        try { if (navigator.vibrate) navigator.vibrate([10, 40, 10]); } catch (e) {}
      } else {
        say('ठीक छ — प्रमाण बिना पठाइन्छ; कार्यालयले फोन गरेर पुष्टि गर्छ।');
      }
      if (o.onDone) o.onDone(how);
    }
    function check() {
      if (!s.token || s.status === 'verified') return;
      post({ action: 'check', token: s.token }).then(function (d) {
        if (!d || !d.ok) return;
        if (d.status === 'verified') { done('verified'); return; }
        if (d.status === 'mismatch') say('❌ सन्देश अर्को नम्बरबाट आयो — फारममा लेखेको नम्बरको WhatsApp बाट नै पठाउनुहोस्।');
        if (d.status === 'expired') { stop(); say('समय सकियो — बटन फेरि थिच्नुहोस्।'); s.token = ''; }
      }).catch(function () {});
    }
    btn.addEventListener('click', function () {
      var p = o.phone();
      if (!p || String(p.phone || '').length !== 10) { say('पहिले १० अङ्कको मोबाइल नम्बर लेख्नुहोस्।'); return; }
      // open the window inside the tap, so a phone browser does not block it
      var win = null;
      try { win = window.open('about:blank', '_blank'); } catch (e) {}
      btn.disabled = true;
      post({ action: 'start', purpose: o.purpose, phone: p.phone, cc: p.cc }).then(function (d) {
        btn.disabled = false;
        if (!d || !d.ok) { if (win) win.close(); say((d && d.error) || 'अहिले मिलेन — फेरि थिच्नुहोस्।'); return; }
        s.token = d.token; s.phone = '+' + p.cc + ' ' + p.phone; s.status = 'waiting';
        if (win) { win.location.href = d.url; } else { location.href = d.url; }
        say('⏳ WhatsApp मा "' + d.text + '" पठाउनुहोस्, अनि यहीँ फर्कनुहोस्…');
        stop(); s.poll = setInterval(check, 3000);
        if (!s.first) {
          s.first = Date.now();
          s.skipT = setTimeout(function () { if (s.status !== 'verified') skip.style.display = 'block'; }, 120000);
        }
      }).catch(function () { btn.disabled = false; if (win) win.close(); say('इन्टरनेट जाँच गर्नुहोस्।'); });
    });
    skip.addEventListener('click', function () { done('skip'); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.addEventListener('focus', check);
    return {
      reset: function () { stop(); clearTimeout(s.skipT); s.token = ''; s.status = 'none'; s.first = 0; btn.style.display = ''; info.style.display = ''; skip.style.display = 'none'; say(''); },
      state: function () { return s.status; }
    };
  }
  window.ShgWaVerify = { mount: mount };
})();
