
/* ================================================================
   [JS] 18. JOURNEY — the "bus on the road" strip on the results and
   seat-selection views (owner brief 17 Sep 2026, item 5).

   Drops ONE aria-hidden <div class="shg-journey"> under the .flow-head of
   #view-results / #view-seats: dawn sky, snow-capped Himalaya, foothills,
   a forest band, a road, a milestone at each end naming the leg's cities
   under its country flag, and the S Hari Global coach standing on the
   road (assets/img/bus-side.svg).

   25 Sep 2026 — STILL SCENE (owner: "yo bus ko animation hataidinus,
   ali ramro image rakhdinus taki smooth chalos … hang nahos"). The strip
   used to run six animations at once on the view the traveller reaches
   first with the date list: a 1.6s drive-in, a five-beat suspension bob,
   an idle bob that never stopped, a breathing shadow, a scrolling road
   dash and two drifting mountain ranges — on the same frames where the
   results list was being built and the occupancy poll was landing. On a
   low-end phone that is where the stutter came from. Everything now
   paints once and holds: no keyframes, no will-change, no session flag,
   no image gate. The picture is better, not busier — the ranges gained
   snow lines and a forest band, and each milestone carries 🇮🇳 / 🇳🇵.

   Under 360px no strip.

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

  var strip = null, img = null, labA = null, labB = null, flagA = null, flagB = null;
  var cssReady = false, dead = false, pending = '', seq = 0;

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
  /* Which flag stands over a milestone (owner, 25 Sep 2026: "thau thau ma
     Nepal ko jhanda, India jhanda pani highlight"). isNepalPoint() in
     02-config.js is the app's one answer to "which side of the border is
     this town on", so the strip asks it rather than keeping a second list
     that could drift from the fare engine. If the bundle is partial the
     name itself is the fallback, and an unknown town shows no flag rather
     than the wrong one. */
  var NP = '\uD83C\uDDF3\uD83C\uDDF5', IN = '\uD83C\uDDEE\uD83C\uDDF3';
  function flagFor(city) {
    var s = String(city || '').toLowerCase();
    if (!s) return '';
    /* isNepalPoint() only knows the towns the service actually sells, so a
       "true" from it is final — but a "false" is not: the strip's own
       fallback pair is the literal words India / Nepal, and the corridor
       name "Nepal" is not a bookable point, so asking the fare engine
       alone put the Indian flag over Nepal. The name test therefore runs
       on a false as well, and only then does it default to India. */
    try { if (typeof isNepalPoint === 'function' && isNepalPoint(city)) return NP; } catch (e) {}
    if (s.indexOf('nepal') >= 0 || s.indexOf('rupaidiha') >= 0 || s.indexOf('jamunaha') >= 0
      || s.indexOf('kohalpur') >= 0 || s.indexOf('lumbini') >= 0) return NP;
    return IN;
  }
  function mode(cls) { strip.className = 'shg-journey jy-' + cls; }
  function build() {
    var el = doc.createElement('div');
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      /* Far Himalaya: the same ridge line drawn twice — rock, then a
         snow path clipped to the peaks above it. Two <path>s, no filter
         and no animation, so it costs one paint. */
      '<svg class="jy-mtn jy-far" viewBox="0 0 1200 100" preserveAspectRatio="none" focusable="false">' +
        '<path class="jy-rock" d="M0 100V60Q30 58 60 48L95 30L128 46Q160 44 190 40L228 22L262 42Q296 40 326 36L360 14L396 38Q430 40 462 34L500 20L536 40Q568 42 598 36L636 10L672 36Q704 42 736 44L770 30L804 46Q840 48 872 42L910 24L944 44Q976 46 1010 38L1046 18L1082 42Q1118 46 1150 44L1180 40L1200 44V100Z"/>' +
        '<path class="jy-snow" d="M95 30L112 44L78 44ZM228 22L248 40L208 40ZM360 14L382 36L338 36ZM500 20L520 38L480 38ZM636 10L659 34L613 34ZM770 30L787 45L753 45ZM910 24L930 42L890 42ZM1046 18L1067 39L1025 39Z"/>' +
      '</svg>' +
      '<svg class="jy-mtn jy-near" viewBox="0 0 1200 100" preserveAspectRatio="none" focusable="false"><path d="M0 100V76Q60 62 130 68T270 60T410 70T550 58T690 68T830 56T970 66T1110 60T1200 64V100Z"/></svg>' +
      /* The tree line between the foothills and the verge — one repeating
         CSS gradient, no elements (see .jy-trees in journey.css). */
      '<div class="jy-trees"></div>' +
      '<div class="jy-road"></div>' +
      '<div class="jy-mark jy-a"><span><i class="jy-flag"></i><b></b></span></div>' +
      '<div class="jy-mark jy-b"><span><i class="jy-flag"></i><b></b></span></div>' +
      '<div class="jy-bus"><i class="jy-shadow"></i><div class="jy-body"><img alt="" decoding="async" loading="lazy" src="' + base + IMG + stamp + '"></div></div>';
    img = el.querySelector('img');
    labA = el.querySelector('.jy-a b');
    labB = el.querySelector('.jy-b b');
    flagA = el.querySelector('.jy-a .jy-flag');
    flagB = el.querySelector('.jy-b .jy-flag');
    return el;
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
    var c = cities();
    labA.textContent = c[0]; labB.textContent = c[1];
    flagA.textContent = flagFor(c[0]); flagB.textContent = flagFor(c[1]);
    /* below the page header; a view without one gets it as first child */
    var head = view.querySelector('.flow-head');
    var at = head ? head.nextSibling : view.firstChild;
    if (strip !== at) view.insertBefore(strip, at);
    seq++;
    mode('still');
  }
  /* fetch the coach picture while idle so the first search starts at once */
  function warm() { try { var i = new Image(); i.fetchPriority = 'low'; i.src = base + IMG + stamp; } catch (e) {} }
  if (window.requestIdleCallback) window.requestIdleCallback(warm, { timeout: 6000 }); else setTimeout(warm, 3000);

  window.SHG_JOURNEY = {
    show: show,
    hide: hide,
    /* dev/demo helper: rebuild the scene (kept so anything that still calls
       replay() from a console or an older view keeps working). */
    replay: function (name) {
      name = VIEWS[name] ? name : 'results';
      hide(); strip = null; show(name);
    }
  };
})();
