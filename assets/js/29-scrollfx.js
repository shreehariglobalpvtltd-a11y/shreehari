/* =====================================================================
   29-scrollfx.js — the home page's scroll feel (17 Oct 2026, owner:
   "scroll garda premium animate feel, touch garda vibrate jasto, magic
   jasto colour, but smooth").

   Four small things, all decoration, none of them a booking path:
     1. reveal: blocks below the fold slide up the first time they are seen
        (.p3-rv -> .p3-seen, IntersectionObserver; siblings stagger by --p3-i).
        Nothing is hidden unless the observer runs (html.p3-fx), so a browser
        without it, reduced motion and a low-tier device see the plain page.
     2. progress: --p3-prog (0..1) on .nav + .p3-aurora - the brand hairline under the
        navbar fills as you scroll, the aurora behind the page drifts with it.
     3. touch: a pressed tile / chip / key gets .p3-tap for 450 ms (a glow
        ring, premium3d.css) and one short tick through SHGFeel.buzz, which
        honours the owner's own Vibration switch and reduced motion.
     4. the hero scene parallaxes a little while the band scrolls away.
   ===================================================================== */
(function () {
  'use strict';
  var de = document.documentElement;
  if (!('IntersectionObserver' in window)) return;
  var still = function () {
    return de.getAttribute('data-motion') === 'reduced' || de.getAttribute('data-tier') === 'low'
      || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  };
  if (still()) return;
  de.classList.add('p3-fx');

  /* ---- 1. reveal ----------------------------------------------------- */
  var SEL = '#view-home .hx-tile, #view-home .hx-card, #view-home .hx-head, #homeHero .hh-card, #homeFares .hx-fares-body > *, '
    + '#homeRoute .hx-rf, #topHero .th-card, #topHero .th-npcard, #view-home .wa-ticket-highlight';   /* 18 Oct: the ticket is never re-animated by scrolling */
  var pending = [];
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      en.target.classList.add('p3-seen');
      io.unobserve(en.target);
    });
  }, { rootMargin: '0px 0px 18% 0px', threshold: 0 });
  /* belt and braces: a throttled observer or a paused rAF (background tab, slow
     phone) must never leave a block that is on screen invisible - the pending
     ones are checked on every scroll and once a second while any remain */
  var tick = 0;
  function checkPending() {
    if (!pending.length) { if (tick) { clearInterval(tick); tick = 0; } return; }
    var vh = window.innerHeight || 800, keep = [];
    for (var i = 0; i < pending.length; i++) {
      var el = pending[i], b = el.getBoundingClientRect();
      if (b.top < vh * 1.15 && b.bottom > 0) { el.classList.add('p3-seen'); io.unobserve(el); } else keep.push(el);
    }
    pending = keep;
    if (!tick && pending.length) tick = setInterval(checkPending, 1000);
  }
  function tag() {
    var h = window.innerHeight || 800;
    document.querySelectorAll(SEL).forEach(function (el) {
      if (el._p3rv) return;
      el._p3rv = true;
      var i = 0, s = el;
      while ((s = s.previousElementSibling) && i < 4) i++;
      el.style.setProperty('--p3-i', String(i));
      el.classList.add('p3-rv');
      var r = el.getBoundingClientRect();
      if (r.top < h * 1.1 && r.bottom > 0) { el.classList.add('p3-seen'); return; }   /* already on screen: never hide it */
      pending.push(el);
      io.observe(el);
    });
  }
  tag();
  checkPending();
  var mo = new MutationObserver(function () { clearTimeout(mo._t); mo._t = setTimeout(tag, 120); });
  var home = document.getElementById('view-home');
  if (home) mo.observe(home, { childList: true, subtree: true });

  /* ---- 2. progress + 4. hero parallax -------------------------------- */
  var hero = document.getElementById('hero3d'), scene = hero && hero.querySelector('.p3-scene');
  var raf = 0;
  /* 17 Oct 2026 (owner: "165 Hz jasto smooth"): --p3-prog used to be set on
     <html>, which re-styles the whole page on every scroll frame. It is now
     set only on the two things that read it (the navbar hairline and the
     aurora), and the page height is measured on resize, not per frame. */
  var progEls = [], maxY = 1, lastVh = 0;
  function measure() {
    lastVh = window.innerHeight || 0;
    maxY = Math.max(1, (de.scrollHeight || 1) - (window.innerHeight || 1));
    progEls = Array.prototype.slice.call(document.querySelectorAll('.nav, .p3-aurora'));
  }
  var lastProg = '';
  function paint() {
    raf = 0;
    if (!progEls.length || (window.innerHeight || 0) !== lastVh) measure();   /* a resize re-measures inside the frame */
    var y = window.scrollY || de.scrollTop || 0;
    var prog = Math.min(1, y / maxY).toFixed(3);
    if (prog !== lastProg) {
      lastProg = prog;
      for (var k = 0; k < progEls.length; k++) progEls[k].style.setProperty('--p3-prog', prog);
    }
    if (scene && hero.offsetParent !== null) {
      var hh = hero.offsetHeight || 1;
      scene.style.transform = y < hh ? 'translate3d(0,' + (y * 0.28).toFixed(1) + 'px,0)' : '';
    }
  }
  function onScroll() { checkPending(); if (!raf) raf = requestAnimationFrame(paint); }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  /* the home page grows as sections load: re-measure now and then, never per frame */
  setTimeout(measure, 1500); setTimeout(measure, 5000);
  window.addEventListener('load', measure);

  /* the aurora behind the page (three slow blobs; premium3d.css moves them) */
  if (!document.querySelector('.p3-aurora')) {
    var au = document.createElement('div');
    au.className = 'p3-aurora';
    au.setAttribute('aria-hidden', 'true');
    au.innerHTML = '<i></i><i></i><i></i>';
    document.body.insertBefore(au, document.body.firstChild);
  }
  measure();
  paint();

  /* ---- 3. touch ------------------------------------------------------ */
  var TAP = '.hx-tile, .hx-card, .p3-feats li, #homeHero .hh-act, #smartBook .sb-chip, #smartBook .sb-dir-b, #smartBook .sb-pay, '
    + '#smartBook .sb-seg button, #smartBook .sb-qmode button, .num-pill, .bottom-nav .bn-item, .nav-links .nav-link, .p3-stop';
  document.addEventListener('pointerdown', function (e) {
    var t = e.target && e.target.closest ? e.target.closest(TAP) : null;
    if (!t) return;
    t.classList.remove('p3-tap');
    void t.offsetWidth;                                    /* restart the animation */
    t.classList.add('p3-tap');
    setTimeout(function () { t.classList.remove('p3-tap'); }, 450);
    if (e.pointerType === 'touch' && window.SHGFeel && typeof window.SHGFeel.buzz === 'function') {
      try { window.SHGFeel.buzz('tap'); } catch (err) {}
    }
  }, { passive: true });
})();
