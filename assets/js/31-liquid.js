/* =====================================================================
   31-liquid.js — the "water / liquid display" touch + scroll feel
   (17 Oct 2026, owner: "bus haru ma touch garda, scroll garda different
   different colour wala vibrate feel hos; vibrate enable / disable garna
   milos; ticket aauda premium feel").

   Decoration only - nothing here books, locks, blocks or changes a value:
     1. drops: where a finger / click lands on a tile, card, coach or the
        booking keys, a coloured liquid drop spreads (one fixed overlay
        layer, never inside the element, so nothing is clipped or moved).
        Every block keeps its own colour (blue, sky, orange, gold, mint,
        pink, violet), and every colour has its own vibration rhythm.
     2. coaches: touching a coach bounces it, with a three-colour splash
        and an engine-like rhythm; a coach scrolling into view glows once.
     3. ticket: when SmartBook shows a confirmed ticket (.sb-tk-pnr) the
        ribbon gets a wave of light + ring (liquid.css .lq-land) and a
        "ticket" rhythm.
     4. a "📳 Vibrate ON / OFF" pill on the hero band, the same switch as
        Menu → Vibration (SHGFeel / localStorage shg:haptics).

   Vibration obeys SHGFeel.haptics (the passenger's switch), the in-app
   Motion switch (data-motion=reduced), and only ever runs after a real
   touch (browsers refuse it before one anyway). iPhone Safari has no
   vibration API, so there it is colour only and the pill is hidden.
   ===================================================================== */
(function () {
  'use strict';
  var de = document.documentElement;
  var COLORS = [
    { c: '#2f6bff', v: [10] },                 /* blue    - one crisp tick   */
    { c: '#22c7ff', v: [8, 24, 8] },           /* sky     - double drip      */
    { c: '#ff8a2b', v: [16, 30, 10] },         /* orange  - thump + tick     */
    { c: '#ffc23d', v: [6, 18, 6, 18, 6] },    /* gold    - shimmer          */
    { c: '#18c98b', v: [12, 40, 12] },         /* mint    - soft pair        */
    { c: '#ff4f9a', v: [22] },                 /* pink    - one firm press   */
    { c: '#8a5cff', v: [6, 14, 12, 14, 18] }   /* violet  - rising ripple    */
  ];
  var BUS_RHYTHM = [28, 36, 18, 36, 40];
  var TICKET_RHYTHM = [12, 28, 12, 28, 46];

  function still() {
    return de.getAttribute('data-motion') === 'reduced';
  }
  function hapticsOn() {
    try { return !window.SHGFeel || window.SHGFeel.haptics !== false; } catch (e) { return true; }
  }
  var touched = false;
  function vibrate(pattern) {
    if (!touched || still() || !hapticsOn()) return;
    try { if (navigator.userActivation && !navigator.userActivation.hasBeenActive) return; } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate(pattern); } catch (e) {}
  }

  /* one overlay for every drop */
  var layer = null;
  function getLayer() {
    if (layer && layer.isConnected) return layer;
    layer = document.createElement('div');
    layer.className = 'lq-layer';
    layer.setAttribute('aria-hidden', 'true');
    document.body.appendChild(layer);
    return layer;
  }
  var live = 0;
  function drop(x, y, color, ring, delay) {
    if (still() || live > 14) return;
    var d = document.createElement('span');
    d.className = 'lq-drop' + (ring ? ' lq-ring' : '');
    d.style.left = x + 'px';
    d.style.top = y + 'px';
    d.style.setProperty('--lq-c', color);
    if (delay) d.style.animationDelay = delay + 'ms';
    live++;
    getLayer().appendChild(d);
    setTimeout(function () { live--; if (d.parentNode) d.parentNode.removeChild(d); }, 900 + (delay || 0));
  }

  /* each block keeps one colour: the n-th block seen gets the n-th colour */
  var seq = 0;
  var colourOf = (window.WeakMap ? new WeakMap() : null);
  function paletteFor(el) {
    if (!colourOf) return COLORS[0];
    var k = colourOf.get(el);
    if (k == null) { k = seq++ % COLORS.length; colourOf.set(el, k); }
    return COLORS[k];
  }

  var BUS = '#hyperBus, #hero3d .p3-art, .hx-bus, .hx-coach, #animRoute .sa-bus, #homeHero .hh-bus, .hero-hd img';
  var BLOCK = '.hx-tile, .hx-card, .th-card, .th-npcard, .hh-card, .p3-feats li, .wa-ticket-highlight, #smartBook .sb-tk';
  var KEY = '#smartBook .sb-pay, #smartBook .sb-chip, #smartBook .sb-dir-b, #smartBook .sb-qmode button, #smartBook .sb-seg button, '
    + '.bottom-nav .bn-item, .btn, .num-pill, .p3-stop, .wa-ticket-fix';

  function squish(el, cls) {
    if (still()) return;
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
    setTimeout(function () { el.classList.remove(cls); }, 750);
  }

  document.addEventListener('pointerdown', function (e) {
    if (e.button && e.button !== 0) return;
    var t = e.target;
    if (!t || !t.closest) return;
    var isTouch = e.pointerType !== 'mouse';
    if (isTouch) touched = true;
    var bus = t.closest(BUS);
    if (bus) {
      var img = bus.querySelector ? (bus.querySelector('img') || bus) : bus;
      squish(img, 'lq-bob');
      drop(e.clientX, e.clientY, '#2f6bff', false, 0);
      drop(e.clientX, e.clientY, '#ff8a2b', true, 90);
      drop(e.clientX, e.clientY, '#ffc23d', true, 190);
      if (isTouch) vibrate(BUS_RHYTHM);
      return;
    }
    var block = t.closest(BLOCK);
    var key = t.closest(KEY);
    var el = key || block;
    if (!el) return;
    var p = paletteFor(el);
    drop(e.clientX, e.clientY, p.c, false, 0);
    drop(e.clientX, e.clientY, p.c, true, 60);
    if (block && !key) squish(block, 'lq-squish');
    if (isTouch) vibrate(p.v);
  }, { passive: true });

  /* ---- coaches glow once, in a fresh colour, as they scroll into view - */
  if ('IntersectionObserver' in window) {
    var glowSeq = 0;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting || still()) return;
        var el = en.target;
        var p = COLORS[glowSeq++ % COLORS.length];
        el.style.setProperty('--lq-c', p.c);
        el.classList.add('lq-glow');
        setTimeout(function () { el.classList.remove('lq-glow'); }, 1300);
        if (touched && glowSeq > 1) vibrate([6, 20, 6]);
      });
    }, { threshold: 0.6 });
    var watchBus = function () {
      document.querySelectorAll('#hyperBus, #view-home .hx-coach').forEach(function (el) {
        if (el._lqw) return; el._lqw = true; io.observe(el);
      });
    };
    watchBus();
    setTimeout(watchBus, 2500);
  }

  /* ---- 3. a confirmed ticket lands ------------------------------------ */
  var sb = document.getElementById('smartBook');
  if (sb && 'MutationObserver' in window) {
    var seen = window.WeakSet ? new WeakSet() : null;
    new MutationObserver(function () {
      var tks = sb.querySelectorAll('.sb-tk');
      for (var i = 0; i < tks.length; i++) {
        var tk = tks[i];
        if (!tk.querySelector('.sb-tk-pnr')) continue;
        if (seen) { if (seen.has(tk)) continue; seen.add(tk); }
        else if (tk._lq) continue; else tk._lq = true;
        if (still()) continue;
        tk.classList.add('lq-land');
        (function (n) { setTimeout(function () { n.classList.remove('lq-land'); }, 2200); })(tk);
        vibrate(TICKET_RHYTHM);
        var r = tk.getBoundingClientRect();
        if (r.width) {
          var cx = r.left + r.width / 2, cy = r.top + Math.min(60, r.height / 2);
          drop(cx, cy, '#2f6bff', true, 0); drop(cx, cy, '#ffc23d', true, 160); drop(cx, cy, '#18c98b', true, 320);
        }
      }
    }).observe(sb, { childList: true, subtree: true });
  }

  /* ---- never a blank screen: the showcase reveal (21-showcase.js) waits
     for its own observer; on a slow phone or a fast flick that can leave an
     on-screen block invisible. Any that is on screen is shown on scroll. -- */
  var scT = 0;
  function showOnScreen() {
    scT = 0;
    var vh = window.innerHeight || 800;
    document.querySelectorAll('.sc-reveal:not(.sc-in), .sc-stagger:not(.sc-in)').forEach(function (el) {
      var r = el.getBoundingClientRect();
      if (r.top < vh * 1.1 && r.bottom > 0) el.classList.add('sc-in');
    });
  }
  window.addEventListener('scroll', function () { if (!scT) scT = setTimeout(showOnScreen, 90); }, { passive: true });
  setTimeout(showOnScreen, 1200);

  /* ---- 4. the vibration switch on the hero band ---------------------- */
  var LABEL = { en: 'Vibrate', hi: 'कंपन', ne: 'कम्पन', gu: 'કંપન' };
  function lang() { try { return (typeof LANG !== 'undefined' && LABEL[LANG]) ? LANG : (LABEL[de.lang] ? de.lang : 'en'); } catch (e) { return 'en'; } }
  function paintPill(b) {
    var on = hapticsOn();
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.innerHTML = '📳 <b></b> <span></span>';
    b.querySelector('b').textContent = LABEL[lang()];
    b.querySelector('span').textContent = on ? 'ON' : 'OFF';
  }
  function mountPill() {
    var hero = document.getElementById('hero3d');
    if (!hero || hero.querySelector('.lq-vib')) return;
    if (typeof navigator.vibrate !== 'function') return;          /* iPhone / desktop: nothing to switch */
    if (window.matchMedia && !matchMedia('(pointer:coarse)').matches) return;
    if (getComputedStyle(hero).position === 'static') hero.style.position = 'relative';
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'lq-vib';
    b.title = 'Vibration on / off';
    paintPill(b);
    b.addEventListener('click', function () {
      var on = !hapticsOn();
      touched = true;
      try { if (window.SHGFeel) window.SHGFeel.setHaptics(on); else localStorage.setItem('shg:haptics', on ? '1' : '0'); } catch (e) {}
      paintPill(b);
      if (on) vibrate([10, 30, 10]);
    });
    hero.appendChild(b);
    /* the language can change after load */
    document.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('[data-lang]')) setTimeout(function () { paintPill(b); }, 60);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountPill); else mountPill();
})();
