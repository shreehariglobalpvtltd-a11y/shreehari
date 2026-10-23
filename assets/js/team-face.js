/* =====================================================================
   team-face.js — the team portal door without a password (23 Oct 2026,
   owner: "face id / fingerprint nai password; camera selfie recognise;
   date of birth + document number last").

   On /team/ (signed out):
     [data-face="login"]   front camera, 3 frames (straight / left / right)
                           -> POST /api/team-face-login.php {action:'face'}
     [data-face="facts"]   birth date + ID number -> {action:'facts'}
   Both read the mobile + country from the sign-in form (#m, select[name=cc])
   and reload the page when the server opened the session.
   ===================================================================== */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var $ = function (id) { return document.getElementById(id); };
  function say(t, bad) { var m = $('faceMsg'); if (!m) return; m.hidden = !t; m.textContent = t; m.className = 'tp-msg ' + (bad ? 'bad' : 'info'); }
  function phone() {
    var m = $('m'), cc = document.querySelector('select[name="cc"]');
    var d = m ? m.value.replace(/\D/g, '').replace(/^0+/, '') : '';
    return d.length === 10 ? { phone: d, cc: cc ? cc.value : '91' } : null;
  }
  function post(body) {
    return fetch('/api/team-face-login.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
  }
  function opened(d) {
    say('✅ चिनियो — भित्र जाँदै… · Recognised, opening…');
    try { if (navigator.vibrate) navigator.vibrate([10, 40, 10]); } catch (e) {}
    setTimeout(function () { location.href = '/team/'; }, 400);
  }

  /* ---- camera ---- */
  var ov = null, stream = null, busy = false;
  var STEPS = ['🙂 सिधा क्यामेरामा हेर्नुहोस् · Look straight', '👈 टाउको बिस्तारै बायाँ घुमाउनुहोस् · Turn left', '👉 अब दायाँ घुमाउनुहोस् · Now turn right'];
  function overlay() {
    if (ov) return ov;
    ov = document.createElement('div');
    ov.className = 'tf-ov';
    ov.innerHTML = '<div class="tf-cam"><video playsinline autoplay muted></video><div class="tf-oval"></div><div class="tf-scan"></div></div>' +
      '<p class="tf-step"></p><div class="tf-dots"><i></i><i></i><i></i></div><button type="button" class="b gh tf-stop">✕ बन्द · Close</button>';
    document.body.appendChild(ov);
    ov.querySelector('.tf-stop').addEventListener('click', function () { stop(); say('बन्द गरियो — फेरि गर्न सकिन्छ · Closed, you can try again.'); });
    return ov;
  }
  function stop() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } if (ov) ov.hidden = true; document.body.style.overflow = ''; busy = false; }
  function grab(v, w) {
    var c = document.createElement('canvas'), k = w / (v.videoWidth || w); c.width = w; c.height = Math.round((v.videoHeight || w) * k);
    var x = c.getContext('2d'); x.translate(c.width, 0); x.scale(-1, 1); x.drawImage(v, 0, 0, c.width, c.height); return c.toDataURL('image/jpeg', 0.8);
  }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function faceLogin() {
    var p = phone();
    if (!p) { say('पहिले माथि १० अङ्कको मोबाइल नम्बर लेख्नुहोस् · First enter your 10-digit mobile above.', true); ($('m') || {}).focus && $('m').focus(); return; }
    if (busy) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('यो ब्राउजरमा क्यामेरा खुलेन — जन्म मिति + ID नम्बर वा पासवर्ड प्रयोग गर्नुहोस् · No camera here; use birth date + ID or the password.', true); return; }
    busy = true; say('');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false }).then(function (s) {
      stream = s; var o = overlay(), v = o.querySelector('video'); v.srcObject = s; o.hidden = false; document.body.style.overflow = 'hidden';
      var dots = o.querySelectorAll('.tf-dots i'), frames = [];
      [].forEach.call(dots, function (d) { d.className = ''; });
      var chain = wait(900);
      STEPS.forEach(function (txt, i) {
        chain = chain.then(function () { o.querySelector('.tf-step').textContent = txt; return wait(2000); })
          .then(function () { if (!stream) throw new Error('closed'); frames.push(grab(v, 360)); dots[i].className = 'on'; try { if (navigator.vibrate) navigator.vibrate(15); } catch (e) {} });
      });
      return chain.then(function () {
        o.querySelector('.tf-step').textContent = '🔍 चिन्दै… · Recognising…';
        return post({ action: 'face', phone: p.phone, cc: p.cc, frames: frames });
      }).then(function (d) {
        stop();
        if (d && d.ok) return opened(d);
        say((d && d.error) || 'चिनिएन — फेरि प्रयास गर्नुहोस् वा जन्म मिति + ID नम्बर प्रयोग गर्नुहोस् · Not recognised, try again or use birth date + ID.', true);
      });
    }).catch(function (e) {
      stop();
      if (e && e.message === 'closed') return;
      say('क्यामेरा खुलेन — अनुमति दिनुहोस् वा अर्को तरिका प्रयोग गर्नुहोस् · Camera did not open: allow it, or use another way.', true);
    });
  }

  /* ---- birth date + ID number ---- */
  function factsLogin() {
    var p = phone();
    if (!p) { say('पहिले माथि १० अङ्कको मोबाइल नम्बर लेख्नुहोस् · First enter your 10-digit mobile above.', true); return; }
    var dob = ($('fDob') || {}).value || '', idno = (($('fId') || {}).value || '').trim();
    if (!idno) { say('ID / नागरिकता / आधार नम्बर लेख्नुहोस् · Enter your ID number.', true); ($('fId') || {}).focus && $('fId').focus(); return; }
    var b = document.querySelector('[data-face="facts"]'); if (b) b.disabled = true;
    say('⏳ जाँच्दै… · Checking…');
    post({ action: 'facts', phone: p.phone, cc: p.cc, dob: dob, idno: idno }).then(function (d) {
      if (b) b.disabled = false;
      if (d && d.ok) return opened(d);
      say((d && d.error) || 'मिलेन · Did not match.', true);
    }).catch(function () { if (b) b.disabled = false; say('इन्टरनेट जाँच गरेर फेरि प्रयास गर्नुहोस् · Check the internet and try again.', true); });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-face]');
    if (!b) return;
    e.preventDefault();
    if (b.getAttribute('data-face') === 'login') faceLogin();
    else if (b.getAttribute('data-face') === 'facts') factsLogin();
    else if (b.getAttribute('data-face') === 'show-facts') { var box = $('factsBox'); if (box) { box.hidden = !box.hidden; if (!box.hidden) ($('fId') || {}).focus && $('fId').focus(); } }
  });
})();
