/* 23-flags.js — 9 Oct 2026: SVG India + Nepal flags (window.shgFlag) and the
   emoji-flag swapper (Windows shows 🇮🇳🇳🇵 as letters). Owner: BRAND work package.

   Owner: "har ek document haru, thau haru, Nepal India ko flag haru dekhaunu
   paryo, ramro attractive lagxa". Windows (most desk PCs and laptops) has no
   flag emoji and prints the letters "IN" / "NP", so every flag is drawn:

   window.shgFlag(cc, h, opts)   inline <svg class="flg flg-in|flg-np"> markup for 'IN' | 'NP'.
                                 h = height in CSS px; omit it for a text-sized (1em) flag.
                                 opts.label: false = decorative (aria-hidden), or a custom name.
                                 opts.inline: the full drawing instead of <use href="#flag-in">
                                 (for print windows, canvas or exported markup). opts.cls: extra classes.
   window.shgFlag.el(cc, h, o)   the same as a DOM element.
   window.shgFlag.scan(root)     run the placeholder fill + emoji swap on a subtree now.
   <symbol id="flag-in"> / <symbol id="flag-np">   injected once into the page's sprite.
   <span class="flg" data-flag="IN" data-h="20"></span>   placeholder: REPLACED by the <svg>,
                                 keeping its classes, id and data-* (so CSS on .flg keeps
                                 applying; size it with CSS height — the width follows).
   Emoji swapper                 🇮🇳 / 🇳🇵 in visible text become the SVG flag; the emoji stays
                                 beside it as visually hidden text, so copy-paste, textContent
                                 and screen readers are unchanged. Skips OPTION, SELECT, INPUT,
                                 TEXTAREA, SCRIPT, STYLE, CODE, PRE, svg, [contenteditable] and
                                 [data-noflag]. Runs after load and on DOM mutations (i18n
                                 re-renders, late views) — batched once per frame, before paint.

   Same geometry as includes/brand.php (Brand::flagSvg) and assets/img/flag-*.svg:
   Nepal per the Constitution of Nepal (1 : 1.219 with the border), India 3 : 2
   with the 24-spoke Ashoka Chakra. Every shape has an explicit fill, so themes
   and inherited `stroke: currentColor` never repaint a flag. */
(function () {
  'use strict';
  if (window.shgFlag && window.shgFlag.ready) return;   // a second copy (admin + app) is a no-op

  var SVGNS = 'http://www.w3.org/2000/svg';
  var F = {
    IN: {
      vb: '0 0 30 20', w: 30, h: 20,
      body: '<path fill="#FF9933" stroke="none" d="M.8 0H29.2A.8.8 0 0 1 30 .8V6.667H0V.8A.8.8 0 0 1 .8 0Z"/>' +
        '<path fill="#FFFFFF" stroke="none" d="M0 6.667H30V13.333H0Z"/>' +
        '<path fill="#138808" stroke="none" d="M0 13.333H30V19.2A.8.8 0 0 1 29.2 20H.8A.8.8 0 0 1 0 19.2Z"/>' +
        '<g fill="none" stroke="#000080"><circle cx="15" cy="10" r="2.9" stroke-width=".42"/>' +
        '<path stroke-width=".2" d="M17.9 10L12.1 10M17.801 10.751L12.199 9.249M17.511 11.45L12.489 8.55M17.051 12.051L12.949 7.949M16.45 12.511L13.55 7.489M15.751 12.801L14.249 7.199M15 12.9L15 7.1M14.249 12.801L15.751 7.199M13.55 12.511L16.45 7.489M12.949 12.051L17.051 7.949M12.489 11.45L17.511 8.55M12.199 10.751L17.801 9.249"/></g>' +
        '<circle cx="15" cy="10" r=".55" fill="#000080" stroke="none"/>'
    },
    NP: {
      vb: '-2.582 -4.664 71.571 87.246', w: 71.571, h: 87.246,
      body: '<path fill="#003893" stroke="none" d="M-2.582 -4.664 L68.989 40.156 L23.808 40.156 L66.234 82.582 L-2.582 82.582Z"/>' +
        '<path fill="#DC143C" stroke="none" d="M0 0L60 37.574L17.574 37.574L60 80L0 80Z"/>' +
        '<g fill="#fff" stroke="none"><path d="M3.05 23.483A11.95 11.95 0 0 0 26.95 23.483A12.84 12.84 0 0 1 3.05 23.483Z"/>' +
        '<circle cx="15" cy="29.045" r="5.561"/>' +
        '<path d="M20.561 29.045L22.575 27.538L20.138 26.916ZM20.138 26.916L21.422 24.754L18.932 25.112ZM18.932 25.112L19.291 22.623L17.128 23.907ZM17.128 23.907L16.507 21.47L15 23.483ZM15 23.483L13.493 21.47L12.872 23.907ZM12.872 23.907L10.709 22.623L11.068 25.112ZM11.068 25.112L8.578 24.754L9.862 26.916ZM9.862 26.916L7.425 27.538L9.439 29.045Z"/>' +
        '<circle cx="15" cy="58.787" r="8.143"/>' +
        '<path d="M17.108 50.921L15 45.947L12.892 50.921L8.58 47.667L9.242 53.029L3.88 52.367L7.134 56.679L2.16 58.787L7.134 60.894L3.88 65.207L9.242 64.545L8.58 69.907L12.892 66.653L15 71.627L17.108 66.653L21.42 69.907L20.758 64.545L26.12 65.207L22.866 60.894L27.84 58.787L22.866 56.679L26.12 52.367L20.758 53.029L21.42 47.667Z"/></g>'
    }
  };
  var LBL = { IN: { en: 'India', hi: 'भारत', ne: 'भारत' }, NP: { en: 'Nepal', hi: 'नेपाल', ne: 'नेपाल' } };
  var EMOJI = { IN: '🇮🇳', NP: '🇳🇵' };
  var RE = /🇮🇳|🇳🇵/g;   // exactly 🇮🇳 or 🇳🇵
  var HINT = /🇮🇳|🇳🇵/;  // same, stateless (no /g)
  var SKIP = { OPTION: 1, OPTGROUP: 1, SELECT: 1, INPUT: 1, TEXTAREA: 1, SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1, TITLE: 1, CODE: 1, PRE: 1, CANVAS: 1, IFRAME: 1 };

  function norm(v) {
    v = String(v == null ? '' : v).trim().toUpperCase();
    if (v === 'NP' || v === 'NPL' || v === 'NEPAL') return 'NP';
    if (v === 'IN' || v === 'IND' || v === 'INDIA') return 'IN';
    return '';
  }
  function lang() {
    var l = String(document.documentElement.getAttribute('lang') || 'ne').slice(0, 2).toLowerCase();
    return (l === 'en' || l === 'hi' || l === 'ne') ? l : 'en';
  }
  function num(v) { return String(Math.round(v * 100) / 100); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }

  /* ---- component CSS (low specificity: any page rule on .flg wins) ---- */
  function injectCss() {
    if (document.getElementById('shg-flags-css')) return;
    var st = document.createElement('style');
    st.id = 'shg-flags-css';
    st.textContent =
      ':where(svg.flg){display:inline-block;width:auto;vertical-align:-.16em;overflow:visible;flex:none;' +
        'filter:drop-shadow(0 .5px .7px rgba(0,0,0,.32))}' +
      ':where(svg.flg-in){aspect-ratio:30/20}' +
      ':where(svg.flg-np){aspect-ratio:71.571/87.246}' +
      ':where(svg.flg-em.flg-in){height:.82em}' +
      ':where(svg.flg-em.flg-np){height:1.08em;vertical-align:-.24em}' +
      ':where(.flg-w){white-space:nowrap}' +
      ':where(.flg-pair){display:inline-flex;align-items:center;gap:.28em;vertical-align:middle}' +
      '.flg-t{position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;' +
        'border:0!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important}' +
      '@media print{:where(svg.flg){filter:none}}';
    (document.head || document.documentElement).appendChild(st);
  }

  /* ---- sprite: <symbol id="flag-in"> / <symbol id="flag-np"> ---- */
  function ensureSprite() {
    if (document.getElementById('flag-in') && document.getElementById('flag-np')) return true;
    if (!document.body) return false;
    var host = null;
    var sym = document.querySelector('svg > defs > symbol');   // the page's own icon sprite
    if (sym) host = sym.parentNode;
    if (!host) {
      var s = document.createElementNS(SVGNS, 'svg');
      s.setAttribute('id', 'shg-flag-sprite');
      s.setAttribute('width', '0'); s.setAttribute('height', '0');
      s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
      s.setAttribute('style', 'position:absolute;width:0;height:0;overflow:hidden');
      host = document.createElementNS(SVGNS, 'defs');
      s.appendChild(host);
      document.body.insertBefore(s, document.body.firstChild);
    }
    ['IN', 'NP'].forEach(function (c) {
      var id = 'flag-' + c.toLowerCase();
      if (document.getElementById(id)) return;
      var tmp = document.createElementNS(SVGNS, 'svg');   // parse in the SVG namespace
      tmp.innerHTML = '<symbol id="' + id + '" viewBox="' + F[c].vb + '">' + F[c].body + '</symbol>';
      if (tmp.firstChild) host.appendChild(tmp.firstChild);
    });
    return !!document.getElementById('flag-in');
  }

  /* ---- markup ---- */
  function shgFlag(code, h, opts) {
    var c = norm(code);
    if (!c) return '';
    opts = opts || {};
    var f = F[c], low = c.toLowerCase(), cls = 'flg flg-' + low, size = '';
    h = +h;
    if (h > 0) size = ' width="' + num(h * f.w / f.h) + '" height="' + num(h) + '"';
    else cls += ' flg-em';
    if (opts.cls) cls += ' ' + String(opts.cls).replace(/[^\w -]/g, '');
    var a11y = opts.label === false
      ? ' aria-hidden="true"'
      : ' role="img" aria-label="' + esc(typeof opts.label === 'string' ? opts.label : (LBL[c][lang()] || LBL[c].en)) + '"';
    if (opts.inline || !ensureSprite()) {
      return '<svg xmlns="' + SVGNS + '" class="' + cls + '" viewBox="' + f.vb + '"' + size + a11y + ' focusable="false">' + f.body + '</svg>';
    }
    return '<svg class="' + cls + '" viewBox="0 0 ' + f.w + ' ' + f.h + '"' + size + a11y + ' focusable="false"><use href="#flag-' + low + '"/></svg>';
  }
  function flagEl(code, h, opts) {
    var html = shgFlag(code, h, opts);
    if (!html) return null;
    var tmp = document.createElement('span');
    tmp.innerHTML = html;
    return tmp.firstChild;
  }

  /* ---- placeholders: <span class="flg" data-flag="IN" data-h="20"> ---- */
  function fillPlaceholders(root) {
    if (!root || !root.querySelectorAll) return 0;
    var list = Array.prototype.slice.call(root.querySelectorAll('.flg[data-flag]'));
    if (root.nodeType === 1 && root.matches && root.matches('.flg[data-flag]')) list.unshift(root);
    var n = 0;
    list.forEach(function (el) {
      if (el.namespaceURI === SVGNS || !el.parentNode) return;   // already a flag
      var c = norm(el.getAttribute('data-flag'));
      if (!c) return;
      var opts = {};
      if (el.getAttribute('aria-hidden') === 'true') opts.label = false;
      else if (el.getAttribute('aria-label')) opts.label = el.getAttribute('aria-label');
      var svg = flagEl(c, parseFloat(el.getAttribute('data-h')) || 0, opts);
      if (!svg) return;
      for (var i = 0; i < el.classList.length; i++) svg.classList.add(el.classList[i]);
      for (var j = 0; j < el.attributes.length; j++) {
        var a = el.attributes[j];
        if (a.name === 'id' || a.name === 'title' || a.name.indexOf('data-') === 0) svg.setAttribute(a.name, a.value);
      }
      el.parentNode.replaceChild(svg, el);
      n++;
    });
    return n;
  }

  /* ---- emoji swapper ---- */
  function rejected(el) {
    if (SKIP[el.nodeName] || el.namespaceURI === SVGNS) return true;
    var ce = el.getAttribute('contenteditable');
    if (ce !== null && ce !== 'false') return true;
    return el.hasAttribute('data-noflag') || (el.classList && el.classList.contains('flg-w'));
  }
  function insideRejected(el) {
    for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
      if (rejected(n)) return true;
    }
    return false;
  }
  function wrapFor(c) {
    var w = document.createElement('span');
    w.className = 'flg-w flg-w-' + c.toLowerCase();
    w.innerHTML = shgFlag(c, 0, { label: false }) + '<span class="flg-t">' + EMOJI[c] + '</span>';
    return w;
  }
  function swapNode(t) {
    var s = t.data, p = t.parentNode, last = 0, m, frag;
    if (!p) return 0;
    RE.lastIndex = 0;
    while ((m = RE.exec(s))) {
      if (!frag) frag = document.createDocumentFragment();
      if (m.index > last) frag.appendChild(document.createTextNode(s.slice(last, m.index)));
      frag.appendChild(wrapFor(m[0] === EMOJI.IN ? 'IN' : 'NP'));
      last = m.index + m[0].length;
    }
    if (!frag) return 0;
    if (last < s.length) frag.appendChild(document.createTextNode(s.slice(last)));
    p.replaceChild(frag, t);
    return 1;
  }
  function swapText(root) {
    if (!root || document.documentElement.hasAttribute('data-noflag')) return 0;
    if (root.nodeType === 3) {
      return (HINT.test(root.data) && root.parentNode && !insideRejected(root.parentNode)) ? swapNode(root) : 0;
    }
    if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return 0;
    var txt = root.textContent;
    if (!txt || !HINT.test(txt)) return 0;                        // the cheap common case
    if (root.nodeType === 1 && insideRejected(root)) return 0;
    var hits = [];
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (n.nodeType === 1) return rejected(n) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_SKIP;
        return HINT.test(n.data) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
      }
    });
    while (walker.nextNode()) hits.push(walker.currentNode);
    var n = 0;
    for (var i = 0; i < hits.length; i++) n += swapNode(hits[i]);
    return n;
  }

  /* ---- scheduling: one pass per frame, before paint; hidden tabs fall back to a timer ---- */
  var queue = [], full = false, scheduled = false, observer = null;
  function scan(root) {
    root = root || document.body;
    if (!root) return 0;
    injectCss();
    return fillPlaceholders(root.nodeType === 3 ? null : root) + swapText(root);
  }
  function observe() {
    if (observer && document.body) observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  function collect(recs) {
    for (var i = 0; i < recs.length; i++) {
      var r = recs[i];
      if (r.type === 'characterData') { queue.push(r.target); continue; }
      for (var j = 0; j < r.addedNodes.length; j++) {
        var nd = r.addedNodes[j];
        if (nd.nodeType === 1 || nd.nodeType === 3) queue.push(nd);
      }
    }
    if (queue.length > 400) { full = true; queue = []; }
  }
  function flush() {
    if (!scheduled) return;
    scheduled = false;
    if (observer) { collect(observer.takeRecords()); observer.disconnect(); }
    var nodes = queue, all = full;
    queue = []; full = false;
    try {
      if (all) scan(document.body);
      else for (var i = 0; i < nodes.length; i++) { if (nodes[i].isConnected) scan(nodes[i]); }
    } catch (e) { /* a flag must never break the page */ }
    observe();
  }
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    if (window.requestAnimationFrame) window.requestAnimationFrame(flush);
    setTimeout(flush, 150);
  }

  shgFlag.ready = true;
  shgFlag.el = flagEl;
  shgFlag.scan = scan;
  shgFlag.sprite = ensureSprite;
  shgFlag.label = function (code) { var c = norm(code); return c ? (LBL[c][lang()] || LBL[c].en) : ''; };
  window.shgFlag = shgFlag;

  function boot() {
    try {
      injectCss();
      ensureSprite();
      scan(document.body);
      if (window.MutationObserver && !observer) {
        observer = new MutationObserver(function (recs) { collect(recs); if (queue.length || full) schedule(); });
        observe();
      }
    } catch (e) { /* never block the bundle */ }
  }
  if (document.body) boot();
  else document.addEventListener('DOMContentLoaded', boot);
  window.addEventListener('load', function () { full = true; schedule(); });
})();
