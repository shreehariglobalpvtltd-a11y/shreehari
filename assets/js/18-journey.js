
/* ================================================================
   [JS] 18. JOURNEY — the "bus on the road" strip on the results and
   seat-selection views (owner brief 17 Sep 2026, item 5).

   Drops ONE aria-hidden <div class="shg-journey"> under the .flow-head of
   #view-results / #view-seats: dawn sky, two mountain ranges, a road with
   a rolling centre line, a milestone at each end naming the leg's cities,
   and the S Hari Global coach (assets/img/bus-side.svg) driving in from
   the left, settling centre-left and idling. transform/opacity only.

   The entrance plays ONCE per session per view (sessionStorage
   'shg:journey:<view>'); later visits get the resting state. Reduced
   motion = static scene; under 360px no strip; a hidden tab pauses it.

   Self-contained: injects /assets/css/journey.css with the ?v= stamp of
   its own <script> tag (an existing <link> is reused) and inserts the
   strip only once the sheet has loaded, so its height is reserved in the
   frame it appears. 05-router.js showView() calls SHG_JOURNEY.show(name);
   any view other than results/seats detaches the strip.
================================================================ */
(function () {
  'use strict';
  if (window.SHG_JOURNEY) return;
  var doc = document, VIEWS = { results: 1, seats: 1 };
  var IMG = 'img/bus-side.svg';   /* side-on coach, name + logo straight, 12 KB */

  /* Where was I loaded from? → asset base + cache stamp (the v= query). */
  var me = doc.currentScript || (function () { var s = doc.getElementsByTagName('script'); return s[s.length - 1] || null; })();
  var m = /^(.*\/assets\/)js\/[^\/?#]+(\?[^#]*)?/.exec((me && me.src) || '');
  var base = m ? m[1] : '/assets/', stamp = (m && m[2]) || '';

  var strip = null, img = null, labA = null, labB = null;
  var cssReady = false, dead = false, pending = '', paused = !!doc.hidden, seq = 0;

  /* ---- stylesheet ------------------------------------------------- */
  function cssOk() { cssReady = true; if (pending) { var v = pending; pending = ''; show(v); } }
  function cssFail() { dead = true; pending = ''; }
  var link = doc.querySelector('link[href*="journey.css"]');
  if (!link) {
    link = doc.createElement('link');
    link.rel = 'stylesheet'; link.href = base + 'css/journey.css' + stamp;
    link.onload = cssOk; link.onerror = cssFail;
    doc.head.appendChild(link);
  } else if (link.sheet) { cssReady = true; }
  else { link.addEventListener('load', cssOk); link.addEventListener('error', cssFail); }

  /* ---- helpers ---------------------------------------------------- */
  function flag(key, set) {
    try {
      if (set) { sessionStorage.setItem(key, '1'); return true; }
      return sessionStorage.getItem(key) === '1';
    } catch (e) { return false; }
  }
  function reduced() {
    try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; }
  }
  /* legCtx() (06-results.js) already swaps the pair for the return leg;
     Flow (05-router.js) is the fallback; neither = the corridor itself. */
  function cities() {
    var f = '', t = '';
    try {
      if (typeof legCtx === 'function') { var c = legCtx() || {}; f = c.from; t = c.to; }
      else if (typeof Flow === 'object' && Flow) { f = Flow.from; t = Flow.to; }
    } catch (e) {}
    f = String(f || '').replace(/\s+/g, ' ').trim();
    t = String(t || '').replace(/\s+/g, ' ').trim();
    return (f && t) ? [f, t] : ['India', 'Nepal'];
  }
  function mode(cls) { strip.className = 'shg-journey jy-' + cls + (paused ? ' jy-paused' : ''); }
  function build() {
    var el = doc.createElement('div');
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      '<svg class="jy-mtn jy-far" viewBox="0 0 1200 100" preserveAspectRatio="none" focusable="false"><path d="M0 100V60Q30 58 60 48L95 30L128 46Q160 44 190 40L228 22L262 42Q296 40 326 36L360 14L396 38Q430 40 462 34L500 20L536 40Q568 42 598 36L636 10L672 36Q704 42 736 44L770 30L804 46Q840 48 872 42L910 24L944 44Q976 46 1010 38L1046 18L1082 42Q1118 46 1150 44L1180 40L1200 44V100Z"/></svg>' +
      '<svg class="jy-mtn jy-near" viewBox="0 0 1200 100" preserveAspectRatio="none" focusable="false"><path d="M0 100V76Q60 62 130 68T270 60T410 70T550 58T690 68T830 56T970 66T1110 60T1200 64V100Z"/></svg>' +
      '<div class="jy-road"></div>' +
      '<div class="jy-mark jy-a"><span></span></div>' +
      '<div class="jy-mark jy-b"><span></span></div>' +
      '<div class="jy-bus"><i class="jy-shadow"></i><div class="jy-body"><img alt="" decoding="async" src="' + base + IMG + stamp + '"></div></div>';
    img = el.querySelector('img');
    labA = el.querySelector('.jy-a span');
    labB = el.querySelector('.jy-b span');
    return el;
  }
  /* cb once the coach picture can paint (never drive in blank); 2.5 s cap */
  function whenImg(cb) {
    var done = false, fire = function () { if (!done) { done = true; cb(); } };
    if (img.complete && img.naturalWidth) { fire(); return; }
    img.onload = img.onerror = fire;
    setTimeout(fire, 2500);
  }

  /* ---- API -------------------------------------------------------- */
  function hide() {
    pending = ''; seq++;
    if (strip && strip.parentNode) strip.parentNode.removeChild(strip);
  }
  function show(name) {
    if (!VIEWS[name] || dead) { hide(); return; }
    if (!cssReady) { pending = name; return; }
    var view = doc.getElementById('view-' + name);
    var w = window.innerWidth;   /* 0 = not laid out yet: let the CSS media query decide */
    if (!view || (w > 0 && w < 360)) { hide(); return; }
    if (!strip) strip = build();
    var c = cities(); labA.textContent = c[0]; labB.textContent = c[1];
    /* below the page header; a view without one gets it as first child */
    var head = view.querySelector('.flow-head');
    var at = head ? head.nextSibling : view.firstChild;
    if (strip !== at) view.insertBefore(strip, at);
    var key = 'shg:journey:' + name, my = ++seq;
    if (flag(key) || reduced() || doc.hidden) { mode('instant'); return; }
    mode('wait');
    whenImg(function () {
      if (my !== seq) return;                 /* the view changed meanwhile */
      mode('enter');
      setTimeout(function () { if (my === seq) flag(key, true); }, 1700);
    });
  }
  doc.addEventListener('visibilitychange', function () {
    paused = !!doc.hidden;
    if (strip) strip.classList.toggle('jy-paused', paused);
  });
  /* fetch the coach picture while idle so the first search starts at once */
  function warm() { try { var i = new Image(); i.fetchPriority = 'low'; i.src = base + IMG + stamp; } catch (e) {} }
  if (window.requestIdleCallback) window.requestIdleCallback(warm, { timeout: 6000 }); else setTimeout(warm, 3000);

  window.SHG_JOURNEY = {
    show: show,
    hide: hide,
    /* dev/demo helper: forget the session flag and play the entrance again */
    replay: function (name) {
      name = VIEWS[name] ? name : 'results';
      try { sessionStorage.removeItem('shg:journey:' + name); } catch (e) {}
      hide(); show(name);
    }
  };
})();
