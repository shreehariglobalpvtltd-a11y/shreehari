/* Staff panel phone audit (14 Nov 2026) - measurement only, changes nothing.
 *
 * Load this file in a signed-in staff page on the LOCAL dev server and call
 *   await __shgRun(['/admin/bookings.php', ...], 360, 640)
 * Each URL is opened in a same-origin iframe of that size and measured:
 *   over   px the document is wider than the viewport
 *   off    outermost visible elements whose right edge is past the viewport
 *          and that are NOT inside a box that scrolls sideways (= cut off)
 *   scroll boxes that scroll sideways, and what sits in them
 *   card   card-table cells whose right edge is past the screen / their box
 *   tap    buttons, selects, inputs under 40px high (links counted apart)
 *   text   elements whose own text is under 12px
 *   bar    fixed / sticky bottom bar and how many px of the last content it covers
 *   focus  form fields that are off-screen or under a fixed bar once focused
 */
(function () {
  function vis(el, win) {
    if (!el.getClientRects().length) return false;
    // a closed <details> keeps its children laid out (content-visibility:hidden) - not visible
    if (el.checkVisibility && !el.checkVisibility()) return false;
    var cs = win.getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || +cs.opacity === 0) return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }
  function sel(el) {
    var s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    if (el.className && typeof el.className === 'string' && el.className.trim()) s += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
    return s;
  }
  function txt(el) { return (el.textContent || el.value || '').replace(/\s+/g, ' ').trim().slice(0, 24); }
  function xScroller(el, win) {
    var root = win.document.documentElement;
    for (var p = el.parentElement; p && p !== root && p !== win.document.body; p = p.parentElement) {
      var cs = win.getComputedStyle(p);
      if (/(auto|scroll)/.test(cs.overflowX) && p.scrollWidth > p.clientWidth + 1) return p;
    }
    return null;
  }
  function pinned(el, win) {
    for (var p = el; p && p.nodeType === 1; p = p.parentElement) {
      var pos = win.getComputedStyle(p).position;
      if (pos === 'fixed' || pos === 'sticky') return p;
    }
    return null;
  }

  function audit(win) {
    var doc = win.document, root = doc.documentElement, vw = win.innerWidth, vh = win.innerHeight;
    var out = { u: win.location.pathname + win.location.search, title: doc.title.slice(0, 40), vw: vw, coarse: win.matchMedia('(pointer:coarse)').matches };
    win.scrollTo(0, 0);
    out.over = Math.max(root.scrollWidth, doc.body ? doc.body.scrollWidth : 0) - vw;

    var all = doc.body ? doc.body.querySelectorAll('*') : [], i, el, r;

    // 1. elements past the right edge (outermost only, not inside a sideways scroller)
    var off = [], offSet = new Set();
    for (i = 0; i < all.length; i++) {
      el = all[i];
      if (/^(SCRIPT|STYLE|OPTION|BR|HEAD|META|LINK|TEMPLATE)$/.test(el.tagName)) continue;
      if (!vis(el, win)) continue;
      r = el.getBoundingClientRect();
      if (r.right > vw + 1 && r.left < vw + 2000) offSet.add(el);
    }
    offSet.forEach(function (e) {
      if (e.parentElement && offSet.has(e.parentElement)) return;      // not outermost
      if (xScroller(e, win)) return;                                    // scrolls in its own box
      var fx = pinned(e, win);
      if (fx && fx.getBoundingClientRect().left >= vw) return;          // closed drawer
      off.push({ s: sel(e), px: Math.round(e.getBoundingClientRect().right - vw) });
    });
    off.sort(function (a, b) { return b.px - a.px; });
    out.off = { n: off.length, max: off.length ? off[0].px : 0, top: off.slice(0, 4).map(function (o) { return o.s + ' +' + o.px; }) };

    // 2. sideways scroll boxes
    var sc = [];
    for (i = 0; i < all.length; i++) {
      el = all[i];
      if (!vis(el, win)) continue;
      var cs = win.getComputedStyle(el);
      if (/(auto|scroll)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 2) {
        var t = el.querySelector('table');
        sc.push(sel(el) + ' ' + el.scrollWidth + '/' + el.clientWidth + (t ? ' <' + (t.className || 'table') + '>' : ''));
      }
    }
    out.scroll = { n: sc.length, top: sc.slice(0, 5) };

    // 3. card-tables
    var ct = doc.querySelectorAll('table.card-table'), card = { tables: 0, widest: 0, tdOff: 0, maxOver: 0, spill: 0, top: [] };
    for (i = 0; i < ct.length; i++) {
      var tb = ct[i];
      if (!vis(tb, win)) continue;
      card.tables++;
      var tw = Math.round(tb.getBoundingClientRect().width);
      if (tw > card.widest) card.widest = tw;
      var box = xScroller(tb, win), limit = vw;
      if (box) limit = Math.min(vw, box.getBoundingClientRect().right);
      var tds = tb.querySelectorAll('tbody td'), bad = 0;
      for (var j = 0; j < tds.length; j++) {
        var td = tds[j];
        if (!vis(td, win)) continue;
        var tr = td.getBoundingClientRect();
        if (tr.right > limit + 1) { bad++; card.tdOff++; if (tr.right - limit > card.maxOver) card.maxOver = Math.round(tr.right - limit); }
        else if (td.scrollWidth > td.clientWidth + 1) { card.spill++; }
      }
      if (bad && card.top.length < 3) card.top.push((tb.className || 'table') + ' w' + tw + ' bad' + bad);
    }
    out.card = card;

    // 4. tap targets
    var ctl = doc.querySelectorAll('button,.btn,input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]),select,textarea,summary,[role=button]');
    var tap = [], seen = new Set();
    for (i = 0; i < ctl.length; i++) {
      el = ctl[i];
      if (seen.has(el) || !vis(el, win)) continue;
      seen.add(el);
      r = el.getBoundingClientRect();
      if (r.height < 39.5) tap.push({ s: sel(el) + ' "' + txt(el) + '"', h: Math.round(r.height) });
    }
    tap.sort(function (a, b) { return a.h - b.h; });
    out.tap = { n: tap.length, min: tap.length ? tap[0].h : 0, top: tap.slice(0, 5).map(function (o) { return o.s + ' ' + o.h; }) };
    var links = doc.querySelectorAll('a[href]:not(.btn)'), ln = 0;
    for (i = 0; i < links.length; i++) { if (vis(links[i], win) && links[i].getBoundingClientRect().height < 39.5) ln++; }
    out.linkTap = ln;

    // 5. small text
    var tw2 = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null), n, small = new Map();
    while ((n = tw2.nextNode())) {
      if (!n.nodeValue || !n.nodeValue.trim()) continue;
      var pe = n.parentElement;
      if (!pe || /^(SCRIPT|STYLE|OPTION|NOSCRIPT|TEMPLATE)$/.test(pe.tagName) || small.has(pe) || !vis(pe, win)) continue;
      var fs = parseFloat(win.getComputedStyle(pe).fontSize);
      if (fs < 11.95) small.set(pe, fs);
    }
    var sm = [];
    small.forEach(function (fs, e) { sm.push({ s: sel(e) + ' "' + txt(e) + '"', fs: Math.round(fs * 10) / 10 }); });
    sm.sort(function (a, b) { return a.fs - b.fs; });
    out.text = { n: sm.length, min: sm.length ? sm[0].fs : 0, top: sm.slice(0, 4).map(function (o) { return o.s + ' ' + o.fs; }) };

    // 6. bottom bar covering the last content
    var content = [], bars = [];
    for (i = 0; i < all.length; i++) {
      el = all[i];
      if (!vis(el, win)) continue;
      var pos = win.getComputedStyle(el).position;
      if (pos === 'fixed' || pos === 'sticky') bars.push(el);
    }
    win.scrollTo(0, root.scrollHeight);
    var bar = null;
    bars.forEach(function (b) {
      var br = b.getBoundingClientRect();
      if (br.width >= vw * 0.5 && br.height < vh * 0.5 && br.bottom >= vh - 2 && br.top > vh * 0.4 && br.top < vh) {
        if (!bar || br.top < bar.getBoundingClientRect().top) bar = b;
      }
    });
    out.bar = null;
    if (bar) {
      var barTop = bar.getBoundingClientRect().top, lastBottom = 0, lastEl = null;
      var leafs = doc.querySelectorAll('button,.btn,a,input,select,textarea,td,th,p,li,h1,h2,h3,h4,label,small,span,b,img');
      for (i = 0; i < leafs.length; i++) {
        el = leafs[i];
        if (bar.contains(el) || !vis(el, win) || pinned(el, win)) continue;
        r = el.getBoundingClientRect();
        if (r.left >= vw || r.right <= 0) continue;
        if (r.bottom > lastBottom) { lastBottom = r.bottom; lastEl = el; }
      }
      out.bar = { s: sel(bar), h: Math.round(bar.getBoundingClientRect().height), covers: Math.max(0, Math.round(lastBottom - barTop)), last: lastEl ? sel(lastEl) : '' };
    }
    // sticky / fixed stack at the top (eats the space left above a keyboard)
    win.scrollTo(0, Math.min(400, root.scrollHeight));
    var topStack = 0;
    bars.forEach(function (b) {
      var br = b.getBoundingClientRect();
      if (br.width >= vw * 0.5 && br.top <= 1 && br.bottom > 0 && br.bottom < vh * 0.6) topStack = Math.max(topStack, Math.round(br.bottom));
    });
    out.topStack = topStack;

    // 7. focused field hidden
    var fields = doc.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=submit]):not([type=button]),select,textarea');
    var fl = [], tested = 0;
    for (i = 0; i < fields.length && tested < 30; i++) {
      el = fields[i];
      if (!vis(el, win) || el.disabled) continue;
      tested++;
      try { el.focus(); } catch (e) { continue; }
      r = el.getBoundingClientRect();
      var why = '';
      if (r.top < -1 || r.bottom > vh + 1 || r.left < -1 || r.right > vw + 1) why = 'off-screen';
      else {
        var hit = doc.elementFromPoint(Math.min(vw - 1, Math.max(0, r.left + r.width / 2)), r.top + r.height / 2);
        if (hit && hit !== el && !el.contains(hit) && !hit.contains(el)) {
          var px = pinned(hit, win);
          if (px && !px.contains(el)) why = 'under ' + sel(px);
        }
      }
      if (why) fl.push(sel(el) + '[' + (el.name || '') + '] ' + why);
    }
    try { if (doc.activeElement && doc.activeElement.blur) doc.activeElement.blur(); } catch (e) {}
    out.focus = { tested: tested, fail: fl.length, top: fl.slice(0, 4) };
    win.scrollTo(0, 0);
    return out;
  }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  async function run(urls, w, h, wait) {
    var old = document.getElementById('__shgAuditFrame');
    if (old) old.remove();
    var f = document.createElement('iframe');
    f.id = '__shgAuditFrame';
    f.style.cssText = 'position:fixed;left:0;top:0;border:0;z-index:2147483647;background:#fff;width:' + w + 'px;height:' + h + 'px';
    document.body.appendChild(f);
    var res = [];
    for (var i = 0; i < urls.length; i++) {
      var u = urls[i];
      try {
        await new Promise(function (ok) {
          var done = false, t = setTimeout(function () { if (!done) { done = true; ok(); } }, 15000);
          f.onload = function () { if (!done) { done = true; clearTimeout(t); ok(); } };
          f.src = u;
        });
        await sleep(wait || 700);
        var o = audit(f.contentWindow);
        o.req = u;
        res.push(o);
      } catch (e) { res.push({ req: u, err: String(e).slice(0, 120) }); }
    }
    return res;
  }

  /* One short line per page - easier to read than the JSON. */
  function brief(res) {
    return res.map(function (o) {
      if (o.err) return o.req + ' ERR ' + o.err;
      return [o.req + (o.u !== o.req ? ' => ' + o.u : ''),
        'over=' + o.over, 'off=' + o.off.n + '/' + o.off.max, 'scroll=' + o.scroll.n,
        'card=' + o.card.tables + 't w' + o.card.widest + ' tdOff' + o.card.tdOff + '/' + o.card.maxOver + ' spill' + o.card.spill,
        'tap=' + o.tap.n + '/' + o.tap.min, 'link=' + o.linkTap, 'text=' + o.text.n + '/' + o.text.min,
        'bar=' + (o.bar ? o.bar.h + ' covers' + o.bar.covers : '-'), 'top=' + o.topStack,
        'focus=' + o.focus.fail + '/' + o.focus.tested].join(' | ');
    }).join('\n');
  }

  /* Layout fingerprint: tag + class + rounded box of every visible element.
   * Run the same URLs against the untouched copy of the code and against the
   * changed one at 1280x800 - equal hashes mean the desktop layout did not move.
   * Returns {url: {n: elements, h: hash, sec: {top-level block: hash}}}. */
  function fnv(s) { var h = 0x811c9dc5; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h * 0x01000193) >>> 0; } return h.toString(16); }
  function fingerprint(win) {
    var doc = win.document, all = doc.body.querySelectorAll('*'), parts = [], sec = {}, n = 0;
    win.scrollTo(0, 0);
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (/^(SCRIPT|STYLE|OPTION|BR|TEMPLATE|use|symbol|path|defs)$/i.test(el.tagName)) continue;
      if (!vis(el, win)) continue;
      var r = el.getBoundingClientRect();
      var line = el.tagName + '.' + (typeof el.className === 'string' ? el.className : '') + '@' + Math.round(r.left) + ',' + Math.round(r.top) + ',' + Math.round(r.width) + ',' + Math.round(r.height);
      parts.push(line); n++;
      var top = el.closest('header.tb,nav.side,main.wrap>*') || el;
      var key = top.tagName + '.' + (typeof top.className === 'string' ? top.className.split(' ')[0] : '') + '#' + Array.prototype.indexOf.call(top.parentElement ? top.parentElement.children : [], top);
      (sec[key] = sec[key] || []).push(line);
    }
    var out = { n: n, h: fnv(parts.join('|')), sec: {} };
    Object.keys(sec).forEach(function (k) { out.sec[k] = fnv(sec[k].join('|')) + ':' + sec[k].length; });
    return out;
  }
  async function fp(urls, w, h, wait) {
    var old = document.getElementById('__shgAuditFrame');
    if (old) old.remove();
    var f = document.createElement('iframe');
    f.id = '__shgAuditFrame';
    f.style.cssText = 'position:fixed;left:0;top:0;border:0;z-index:2147483647;background:#fff;width:' + w + 'px;height:' + h + 'px';
    document.body.appendChild(f);
    var res = {};
    for (var i = 0; i < urls.length; i++) {
      await new Promise(function (ok) {
        var done = false, t = setTimeout(function () { if (!done) { done = true; ok(); } }, 15000);
        f.onload = function () { if (!done) { done = true; clearTimeout(t); ok(); } };
        f.src = urls[i];
      });
      await sleep(wait || 900);
      try { res[urls[i]] = fingerprint(f.contentWindow); } catch (e) { res[urls[i]] = { err: String(e).slice(0, 100) }; }
    }
    return res;
  }
  window.__shgFp = fp;
  window.__shgAudit = audit;
  window.__shgRun = run;
  window.__shgBrief = brief;
})();
