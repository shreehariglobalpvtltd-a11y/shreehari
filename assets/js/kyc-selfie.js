/* =====================================================================
   kyc-selfie.js — the application form's LIVE selfie (29 Oct 2026, owner's
   "AI System Prompt (Opus 5.5 Max)" 2.2 step 1: "live camera opens, no
   file upload — prevents fake photos; face detected → auto-snap").

   KycSelfie.open({ csrf, doc, onPhoto, onResult, onFallback })
     - front camera in a full-screen frame, three steps (straight / left /
       right). Where the browser has FaceDetector (Chrome on Android) the
       straight shot snaps by itself once one face sits in the oval;
       elsewhere each step counts down.
     - the three small frames (+ the ID photo when the form already has
       one) go to /api/face-check.php: a liveness + same-person score for
       the OFFICE. It never blocks the form — the result waits in this
       browser's session and is written onto the application it sends.
     - the straight frame, full size, goes to onPhoto (the form hands it
       to the crop studio).
     - no camera, or the person closes it: onFallback (the old photo
       picker) — the application then simply carries no live check, and
       the office sees that.
   Same look as the team door's face login (assets/js/team-face.js).
   ===================================================================== */
(function () {
  'use strict';
  if (window.KycSelfie) { return; }

  var CSS = '.ks-ov{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:16px;background:radial-gradient(120% 80% at 50% 30%,#10265e,#050c22)}.ks-ov[hidden]{display:none}'
    + '.ks-cam{position:relative;width:min(78vw,340px);aspect-ratio:3/4;border-radius:28px;overflow:hidden;box-shadow:0 0 0 4px #fff,0 0 0 8px #1f4fe0,0 0 40px 10px rgba(92,240,255,.35)}.ks-cam video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);background:#000}'
    + '.ks-oval{position:absolute;inset:12% 16% 16%;border:3px dashed rgba(255,255,255,.85);border-radius:50% 50% 46% 46%;box-shadow:0 0 0 999px rgba(5,12,34,.35);transition:border-color .2s}.ks-oval.ok{border-color:#22c55e;border-style:solid}'
    + '.ks-step{margin:0;color:#fff;font-size:19px;font-weight:900;text-align:center;min-height:2.6em}.ks-dots{display:flex;gap:8px}.ks-dots i{width:12px;height:12px;border-radius:50%;background:rgba(255,255,255,.3)}.ks-dots i.on{background:#5cf0ff;box-shadow:0 0 10px #5cf0ff}'
    + '.ks-row{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}.ks-ov button{color:#fff;background:transparent;border:1.5px solid rgba(255,255,255,.5);min-height:44px;padding:0 18px;border-radius:12px;font:inherit;font-weight:800;cursor:pointer}'
    + '.ks-note{color:#cbd5e1;font-size:12.5px;text-align:center;max-width:340px;margin:0}';

  var STEPS = [
    '🙂 सिधा क्यामेरामा हेर्नुहोस् · Look straight at the camera',
    '👈 टाउको बिस्तारै बायाँ घुमाउनुहोस् · Slowly turn your head left',
    '👉 अब दायाँ घुमाउनुहोस् · Now turn right'
  ];

  var ov = null, stream = null, busy = false, opts = {};

  function el(sel) { return ov ? ov.querySelector(sel) : null; }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function overlay() {
    if (ov) { return ov; }
    if (!document.getElementById('ksCss')) {
      var st = document.createElement('style'); st.id = 'ksCss'; st.textContent = CSS; document.head.appendChild(st);
    }
    ov = document.createElement('div');
    ov.className = 'ks-ov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-label', 'Live selfie');
    ov.innerHTML = '<div class="ks-cam"><video playsinline autoplay muted></video><div class="ks-oval"></div></div>'
      + '<p class="ks-step" aria-live="polite"></p><div class="ks-dots"><i></i><i></i><i></i></div>'
      + '<p class="ks-note">फोटो यही क्यामेराबाट मात्र — पुरानो फोटो चल्दैन · Taken live, here and now.</p>'
      + '<div class="ks-row"><button type="button" data-k="stop">✕ बन्द · Close</button>'
      + '<button type="button" data-k="pick">🖼️ क्यामेरा चलेन? फोटो छान्नुहोस्</button></div>';
    document.body.appendChild(ov);
    el('[data-k="stop"]').addEventListener('click', function () { stop(); });
    el('[data-k="pick"]').addEventListener('click', function () { stop(); if (opts.onFallback) { opts.onFallback(); } });
    return ov;
  }

  function stop() {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (ov) { ov.hidden = true; }
    document.body.style.overflow = '';
    busy = false;
  }

  /** One frame, un-mirrored (the server sees the real face), `w` px wide. */
  function grab(v, w, q) {
    var c = document.createElement('canvas'), k = w / (v.videoWidth || w);
    c.width = w; c.height = Math.round((v.videoHeight || w) * k);
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', q || 0.8);
  }

  /** Straight shot: snap as soon as ONE face sits near the middle (FaceDetector), else count down. */
  function straightShot(v) {
    var oval = el('.ks-oval');
    if (!('FaceDetector' in window)) {
      return countdown(3);
    }
    var fd;
    try { fd = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 2 }); } catch (e) { return countdown(3); }
    var hits = 0, started = Date.now();
    return new Promise(function (resolve) {
      (function tick() {
        if (!stream) { resolve(); return; }
        if (Date.now() - started > 9000) { resolve(); return; }       // never stuck: snap anyway
        fd.detect(v).then(function (faces) {
          var ok = false;
          if (faces.length === 1) {
            var b = faces[0].boundingBox, cx = (b.x + b.width / 2) / (v.videoWidth || 1), cy = (b.y + b.height / 2) / (v.videoHeight || 1);
            ok = cx > 0.3 && cx < 0.7 && cy > 0.25 && cy < 0.75 && b.width / (v.videoWidth || 1) > 0.22;
          }
          oval.classList.toggle('ok', ok);
          hits = ok ? hits + 1 : 0;
          if (hits >= 3) { resolve(); } else { setTimeout(tick, 250); }
        }).catch(function () { countdown(3).then(resolve); });
      })();
    });
  }

  function countdown(n) {
    var step = el('.ks-step'), base = step.textContent;
    var chain = Promise.resolve();
    for (var i = n; i >= 1; i--) {
      (function (k) { chain = chain.then(function () { step.textContent = base + ' · ' + k; return wait(700); }); })(i);
    }
    return chain.then(function () { step.textContent = base; });
  }

  function post(frames, doc) {
    return fetch('/api/face-check.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': opts.csrf ? opts.csrf() : '' },
      body: JSON.stringify({ frames: frames, doc: doc || null, consent: true })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
  }

  /** ID photo for the same-person check, shrunk under the server's 700 KB. */
  function docFrame() {
    var d = opts.doc ? opts.doc() : '';
    if (!d || d.indexOf('data:image/') !== 0) { return Promise.resolve(null); }
    if (d.length < 650000) { return Promise.resolve(d); }
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var s = Math.min(1, 1200 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        resolve(c.toDataURL('image/jpeg', 0.8));
      };
      img.onerror = function () { resolve(null); };
      img.src = d;
    });
  }

  function open(o) {
    opts = o || {};
    if (busy) { return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (opts.onFallback) { opts.onFallback(); }
      return;
    }
    busy = true;
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false })
      .then(function (s) {
        stream = s;
        var o2 = overlay(), v = o2.querySelector('video'), dots = o2.querySelectorAll('.ks-dots i');
        v.srcObject = s; o2.hidden = false; document.body.style.overflow = 'hidden';
        [].forEach.call(dots, function (d) { d.className = ''; });
        var frames = [], photo = '';
        var chain = wait(900);
        STEPS.forEach(function (txt, i) {
          chain = chain.then(function () {
            el('.ks-step').textContent = txt;
            return i === 0 ? straightShot(v) : wait(1900);
          }).then(function () {
            if (!stream) { throw new Error('closed'); }
            frames.push(grab(v, 360, 0.8));
            if (i === 0) { photo = grab(v, Math.min(720, v.videoWidth || 720), 0.92); }
            dots[i].className = 'on';
            try { if (navigator.vibrate) { navigator.vibrate(15); } } catch (e) {}
          });
        });
        return chain.then(function () {
          stop();
          if (opts.onPhoto) { opts.onPhoto(photo); }
          // The check runs on its own: a network error here must never
          // throw the person back to the photo picker after a good selfie.
          docFrame().then(function (doc) { return post(frames, doc); })
            .catch(function () { return { ok: false, status: 'unavailable' }; })
            .then(function (d) { if (opts.onResult) { opts.onResult(d || {}); } });
        });
      })
      .catch(function (err) {
        var closed = err && err.message === 'closed';
        stop();
        if (!closed && opts.onFallback) { opts.onFallback(err); }
      });
  }

  window.KycSelfie = { open: open, stop: stop };
})();
