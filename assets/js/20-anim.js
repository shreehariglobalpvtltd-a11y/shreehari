/* =====================================================================
   Homepage animation scenes (4 Oct 2026) — controller for the three
   .shg-animation sections in app.template.html (styles: assets/css/anim.css,
   artwork: assets/img/anim/, from the owner's premium animation pack).

   A scene plays only while it is on screen, the tab is visible, the visitor
   has not paused it and the device does not ask for reduced motion; off
   screen it is paused (IntersectionObserver), not merely hidden. Artwork is
   requested only when a scene comes near the viewport.

   Pure decoration: no network request of its own, no storage, and no link
   to any real booking, seat, payment or WhatsApp state. The booking scene
   is a sample; its ticket and message say so on screen.
   ===================================================================== */
(function () {
  'use strict';
  if (!('IntersectionObserver' in window) || !window.Map) return;   // old browser: the still frames stay
  var scenes = [].slice.call(document.querySelectorAll('.shg-animation [data-anim]'));
  if (!scenes.length) return;

  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  var reduced = function () { return !!(mq && mq.matches); };
  var canAnimate = typeof Element.prototype.animate === 'function';
  var tr = function (key, fallback) {
    try { if (typeof t === 'function') { var s = t(key); if (s && s !== key) return s; } } catch (e) { /* i18n not ready */ }
    return fallback;
  };
  var ROUTE = 'M229.61,374.43 L238.71,350.55 L222.86,335.53 C277.86,260.53 377.99,238.12 457.99,228.12';

  var S = new Map();
  scenes.forEach(function (el) {
    S.set(el, { kind: el.getAttribute('data-anim'), visible: false, built: false, paused: false, anims: [], finalMs: 0 });
  });

  /* ---------- Web Animations helpers (all transform / opacity) ---------- */
  function add(st, el, frames, ms) {
    if (!el) return null;
    var a = el.animate(frames, { duration: ms, iterations: Infinity, easing: 'linear' });
    a.pause(); a.currentTime = 0;
    st.anims.push(a);
    return a;
  }
  /* visible between start and end (fractions of the loop), quick fades */
  function show(st, el, start, end, ms) {
    return add(st, el, [
      { opacity: 0, offset: 0 }, { opacity: 0, offset: start }, { opacity: 1, offset: Math.min(start + 0.02, 1) },
      { opacity: 1, offset: end }, { opacity: 0, offset: Math.min(end + 0.02, 1) }, { opacity: 0, offset: 1 }
    ], ms);
  }

  /* ---------- 01 · journey: CSS layers; this only feeds artwork + direction ---------- */
  function rims(el) {
    var img = el.querySelector('.sa-bus img');
    if (!img) return;
    var paint = function () {
      var u = img.currentSrc || img.src;
      [].forEach.call(el.querySelectorAll('.sa-rim'), function (r) { r.style.backgroundImage = u ? 'url("' + u + '")' : ''; });
    };
    img.addEventListener('load', paint);
    if (img.complete && img.naturalWidth) paint();
  }
  /* जाने (Gujarat → Rupaidiha) = the right-facing coach; फर्किने = the
     left-facing one. Two separate renders — the lettering is never mirrored. */
  function direction(el) {
    var back = document.getElementById('dirBack');
    var dir = back && back.classList.contains('on') ? 'back' : 'go';
    var was = el.getAttribute('data-dir') || 'go';     // the markup ships the right-facing coach
    el.setAttribute('data-dir', dir);
    if (was === dir) return;
    var swap = function (s) { return dir === 'back' ? s.replace(/shg-bus-main/g, 'shg-bus-left') : s.replace(/shg-bus-left/g, 'shg-bus-main'); };
    var src = el.querySelector('.sa-bus source'), img = el.querySelector('.sa-bus img');
    [].forEach.call(el.querySelectorAll('.sa-rim'), function (r) { r.style.backgroundImage = ''; });
    if (src) src.srcset = swap(src.srcset);
    if (img) img.src = swap(img.getAttribute('src'));
    el.classList.toggle('is-left', dir === 'back');
  }
  function buildJourney(el) {
    /* The artwork is requested only now, about 400 px before the scene is on
       screen (native lazy loading starts ~1,250–2,500 px early on phones). */
    [].forEach.call(el.querySelectorAll('source[data-srcset]'), function (s) { s.srcset = s.getAttribute('data-srcset'); s.removeAttribute('data-srcset'); });
    [].forEach.call(el.querySelectorAll('img[data-src]'), function (i) { i.src = i.getAttribute('data-src'); i.removeAttribute('data-src'); });
    el.classList.add('is-near');                      // and the road texture
    rims(el);
    direction(el);
    var back = document.getElementById('dirBack');
    if (back && window.MutationObserver) {
      var mo = new MutationObserver(function () { direction(el); });
      mo.observe(back, { attributes: true, attributeFilter: ['class'] });
      cleanups.push(function () { mo.disconnect(); });
    }
  }

  /* ---------- 02 · route: 64 lit segments + a marker along the corridor ---------- */
  function buildRoute(el, st) {
    [].forEach.call(el.querySelectorAll('image[data-href]'), function (im) { im.setAttribute('href', im.getAttribute('data-href')); });
    if (!canAnimate) return;
    var track = el.querySelector('.sa-route-track'), segs = el.querySelector('.sa-route-segs');
    var marker = el.querySelector('.sa-map-bus'), pulse = el.querySelector('.sa-pulse circle');
    var len = 0;
    try { len = track.getTotalLength(); } catch (e) { len = 0; }
    if (!len || !segs || !marker) return;
    var D = 10000, SPAN = 0.84, N = 64, ns = 'http://www.w3.org/2000/svg';
    for (var i = 0; i < N; i++) {
      var p = track.getPointAtLength(len * i / N), q = track.getPointAtLength(len * (i + 1) / N);
      var seg = document.createElementNS(ns, 'path');
      seg.setAttribute('d', 'M' + p.x.toFixed(2) + ' ' + p.y.toFixed(2) + 'L' + q.x.toFixed(2) + ' ' + q.y.toFixed(2));
      segs.appendChild(seg);
      var at = i / N * SPAN;
      add(st, seg, [
        { opacity: 0.12, offset: 0 }, { opacity: 0.12, offset: at }, { opacity: 1, offset: Math.min(at + 0.02, 0.87) },
        { opacity: 1, offset: 0.91 }, { opacity: 0.12, offset: 0.98 }, { opacity: 0.12, offset: 1 }
      ], D);
    }
    var frames = [];
    for (var k = 0; k <= 80; k++) {
      var m = track.getPointAtLength(len * k / 80);
      frames.push({ transform: 'translate(' + m.x.toFixed(2) + 'px,' + m.y.toFixed(2) + 'px)', opacity: 1, offset: k / 80 * SPAN });
    }
    var last = frames[frames.length - 1];
    frames.push({ transform: last.transform, opacity: 1, offset: 0.91 });
    frames.push({ transform: last.transform, opacity: 0, offset: 0.96 });
    frames.push({ transform: frames[0].transform, opacity: 0, offset: 1 });   // reset while invisible
    add(st, marker, frames, D);
    if (pulse) add(st, pulse, [{ opacity: 0.2, transform: 'scale(.8)' }, { opacity: 0.85, transform: 'scale(1.2)' }, { opacity: 0.2, transform: 'scale(.8)' }], 2000);
    el.classList.add('is-built');
    st.finalMs = 8500;                                  // full line lit, coach at Rupaidiha
  }

  /* ---------- 03 · booking: route → berth → details → demo ticket (+ sample notice) ---------- */
  function buildBooking(el, st) {
    if (!canAnimate) return;
    var q = function (s) { return el.querySelector(s); };
    var D = 8000;
    add(st, q('.sa-ui--route'), [
      { opacity: 1, offset: 0 }, { opacity: 1, offset: 0.22 }, { opacity: 0, offset: 0.26 }, { opacity: 0, offset: 0.96 }, { opacity: 1, offset: 1 }
    ], D);
    show(st, q('.sa-ui--berth'), 0.22, 0.43, D);
    show(st, q('.sa-ui--details'), 0.43, 0.64, D);
    show(st, q('.sa-berth .sa-pick'), 0.30, 0.45, D);           // L12 is tapped
    show(st, q('.sa-sel'), 0.31, 0.45, D);
    add(st, q('.sa-pay-wait'), [
      { opacity: 1, offset: 0 }, { opacity: 1, offset: 0.545 }, { opacity: 0, offset: 0.565 }, { opacity: 0, offset: 0.98 }, { opacity: 1, offset: 1 }
    ], D);
    show(st, q('.sa-pay-ok'), 0.55, 0.66, D);                   // the ticket only after the payment check
    add(st, q('.sa-ui--ticket'), [
      { opacity: 0, offset: 0 }, { opacity: 0, offset: 0.64 }, { opacity: 1, offset: 0.68 }, { opacity: 1, offset: 0.95 }, { opacity: 0, offset: 1 }
    ], D);
    add(st, q('.sa-ticket'), [
      { transform: 'translateY(30px)', offset: 0 }, { transform: 'translateY(30px)', offset: 0.64, easing: 'cubic-bezier(.22,1,.36,1)' },
      { transform: 'translateY(0)', offset: 0.71 }, { transform: 'translateY(0)', offset: 1 }
    ], D);
    add(st, q('.sa-wa'), [
      { opacity: 0, transform: 'translateY(10px)', offset: 0 }, { opacity: 0, transform: 'translateY(10px)', offset: 0.79, easing: 'cubic-bezier(.22,1,.36,1)' },
      { opacity: 1, transform: 'translateY(0)', offset: 0.85 }, { opacity: 1, transform: 'translateY(0)', offset: 0.95 }, { opacity: 0, transform: 'translateY(0)', offset: 1 }
    ], D);
    // the chips under the phone and, on desktop, the list beside it
    var steps = (el.closest('.shg-animation') || el).querySelectorAll('.sa-steplist .sa-on, .sa-steps .sa-on');
    var on = [
      [{ opacity: 1, offset: 0 }, { opacity: 1, offset: 0.215 }, { opacity: 0, offset: 0.235 }, { opacity: 0, offset: 0.955 }, { opacity: 1, offset: 0.975 }, { opacity: 1, offset: 1 }],
      null, null, null
    ];
    [[0.215, 0.425], [0.425, 0.635], [0.635, 0.95]].forEach(function (w, n) {
      on[n + 1] = [{ opacity: 0, offset: 0 }, { opacity: 0, offset: w[0] }, { opacity: 1, offset: w[0] + 0.02 }, { opacity: 1, offset: w[1] }, { opacity: 0, offset: w[1] + 0.02 }, { opacity: 0, offset: 1 }];
    });
    [].forEach.call(steps, function (s, n) { add(st, s, on[n % 4], D); });
    st.finalMs = 7200;                                  // demo ticket + sample notice, step 4 lit
  }

  function build(el) {
    var st = S.get(el);
    if (!st || st.built) return;
    st.built = true;
    if (st.kind === 'journey') buildJourney(el);
    else if (st.kind === 'route') buildRoute(el, st);
    else if (st.kind === 'booking') buildBooking(el, st);
  }

  /* ---------- run / pause ---------- */
  function sync() {
    var still = reduced();
    S.forEach(function (st, el) {
      var run = st.visible && !st.paused && !document.hidden && !still;
      el.classList.toggle('is-running', run);
      st.anims.forEach(function (a) {
        if (still) { a.pause(); a.currentTime = st.finalMs; }
        else if (run) a.play();
        else a.pause();
      });
      var btn = el.querySelector('.sa-pause');
      if (btn) btn.hidden = still;                      // nothing moves, nothing to pause
    });
  }
  function label(btn, paused) {
    var key = paused ? 'animPlay' : 'animPause';
    var text = tr(key, paused ? 'Play animation' : 'Pause animation');
    btn.setAttribute('data-paused', paused ? 'true' : 'false');   // the icon; the label says the action
    btn.setAttribute('data-i18n-title', key);           // a language switch keeps the right label
    btn.setAttribute('title', text);
    btn.setAttribute('aria-label', text);
  }

  var cleanups = [];
  var near = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { if (e.isIntersecting) { build(e.target); near.unobserve(e.target); } });
    sync();
  }, { rootMargin: '400px 0px 400px 0px' });
  var seen = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { var st = S.get(e.target); if (st) st.visible = e.isIntersecting; });
    sync();
  }, { threshold: 0.15 });
  scenes.forEach(function (el) {
    near.observe(el); seen.observe(el);
    var btn = el.querySelector('.sa-pause');
    if (btn) {
      var onClick = function () { var st = S.get(el); st.paused = !st.paused; label(btn, st.paused); sync(); };
      btn.addEventListener('click', onClick);
      cleanups.push(function () { btn.removeEventListener('click', onClick); });
    }
  });
  document.addEventListener('visibilitychange', sync);
  if (mq) { if (mq.addEventListener) mq.addEventListener('change', sync); else if (mq.addListener) mq.addListener(sync); }
  sync();

  /* Inspection hooks (screenshots, tests). seek() freezes a scene at a time;
     destroy() releases every observer, listener and animation. Neither has
     any side effect outside these three sections. */
  window.shgAnim = {
    seek: function (kind, ms) {
      S.forEach(function (st, el) {
        if (st.kind !== kind) return;
        build(el); st.paused = true;
        var btn = el.querySelector('.sa-pause'); if (btn) label(btn, true);
        el.classList.remove('is-running');
        st.anims.forEach(function (a) { a.pause(); a.currentTime = ms; });
      });
    },
    destroy: function () {
      near.disconnect(); seen.disconnect();
      document.removeEventListener('visibilitychange', sync);
      if (mq) { if (mq.removeEventListener) mq.removeEventListener('change', sync); else if (mq.removeListener) mq.removeListener(sync); }
      cleanups.forEach(function (f) { f(); }); cleanups = [];
      S.forEach(function (st, el) { st.anims.forEach(function (a) { a.cancel(); }); st.anims = []; el.classList.remove('is-running'); });
    }
  };
})();
