/* agreement-live.js — the office's corrections on the /agreements/ pages
   (2 Oct 2026, owner: "agreement ka point admin panel bata sachauna milne").

   The page is static; its points are <div class="cl"><span class="n">2.1</span>
   <div class="t">…</div></div>. Admin → Agents → 📜 Agreement points keeps
   only what the office changed, served by /api/agreement-points.php:
     points: {"2.1": {text: "…{{org_rate}}…", hidden: false}}
     added:  [{num: "1.6", section: "1", text: "…"}]
   A {{name}} blank is a box of the agreement form (name, or name=value for a
   radio choice). The page's OWN box is moved into the new wording — never
   re-created — so whatever the reader already typed, and any handler the
   page attached, stays with it. No answer = the page's wording stands. */
(function () {
  'use strict';
  var m = location.pathname.match(/(agent|partner)-agreement-(ne|hi|en|gu)\.html$/);
  if (!m || !window.fetch) return;
  var doc = m[1], lang = m[2];

  function clauses() {
    var out = {};
    document.querySelectorAll('.cl').forEach(function (cl) {
      var n = cl.querySelector('.n');
      if (n) out[n.textContent.trim()] = cl;
    });
    return out;
  }

  /* The box a blank names, inside one point: a radio's <label>, else the input. */
  function widget(t, key) {
    var name = key, val = null, eq = key.indexOf('=');
    if (eq > 0) { name = key.slice(0, eq); val = key.slice(eq + 1); }
    var inputs = t.querySelectorAll('input');
    for (var i = 0; i < inputs.length; i++) {
      var inp = inputs[i];
      if (inp.name !== name) continue;
      if (val !== null && inp.value !== val) continue;
      var lab = inp.closest('label');
      return (lab && t.contains(lab)) ? lab : inp;
    }
    return null;
  }

  /* Text with {{blanks}} → nodes; the characters touching a blank (₹, %, a
     comma) go into one no-wrap span with it, as the built page had them. */
  function build(t, text) {
    var frag = document.createDocumentFragment();
    var parts = String(text).split(/\{\{([^{}]{1,80})\}\}/);
    var carry = '';
    function addText(s) {
      var lines = s.split('\n');
      lines.forEach(function (ln, i) {
        if (i > 0) frag.appendChild(document.createElement('br'));
        if (ln) frag.appendChild(document.createTextNode(ln));
      });
    }
    for (var i = 0; i < parts.length; i++) {
      if (i % 2 === 0) {
        var s = carry + parts[i]; carry = '';
        if (i + 1 < parts.length) {                       // a blank follows: hold back the touching run
          var mm = s.match(/(\S*)$/); var lead = mm ? mm[1] : '';
          addText(s.slice(0, s.length - lead.length));
          carry = lead;
        } else {
          addText(s);
        }
      } else {
        var w = widget(t, parts[i]);
        var next = parts[i + 1] || '';
        var tail = (next.match(/^(\S*)/) || ['', ''])[1];
        parts[i + 1] = next.slice(tail.length);
        if (!w) { addText(carry + '{{' + parts[i] + '}}' + tail); carry = ''; continue; }
        var nw = document.createElement('span'); nw.className = 'nw';
        if (carry) nw.appendChild(document.createTextNode(carry));
        nw.appendChild(w);
        if (tail) nw.appendChild(document.createTextNode(tail));
        frag.appendChild(nw); carry = '';
      }
    }
    return frag;
  }

  /* 10 Oct 2026 (trust, owner: "agreement ko data live hunu paryo"): the
     company facts printed on the page - legal name, CIN, website, registered
     office, phone - are <span data-fact="brand.cin">…</span> (built by
     tools/agreements/build.js). The built text is the fallback; the live
     value from SiteFacts (Admin -> Company details) replaces it, so the
     agreement never lags the office. Runs BEFORE the point corrections: an
     edited point keeps the office's own wording. */
  function fillFacts(facts) {
    if (!facts || typeof facts !== 'object') return;
    document.querySelectorAll('[data-fact]').forEach(function (el) {
      var v = facts[el.getAttribute('data-fact')];
      if (typeof v === 'string' && v.trim() !== '') el.textContent = v;
    });
  }

  function apply(data) {
    if (!data || !data.ok) return;
    fillFacts(data.facts);
    var cls = clauses();
    var pts = data.points || {};
    Object.keys(pts).forEach(function (num) {
      var cl = cls[num], p = pts[num]; if (!cl || !p) return;
      if (p.hidden) { cl.hidden = true; cl.style.display = 'none'; return; }
      if (typeof p.text === 'string' && p.text !== '') {
        var t = cl.querySelector('.t'); if (!t) return;
        var fresh = build(t, p.text);                     // takes the boxes out of the old text
        t.textContent = '';
        t.appendChild(fresh);
        cl.setAttribute('data-edited', '1');
      }
    });
    (data.added || []).forEach(function (a) {
      if (cls[a.num]) return;
      var anchor = null;
      document.querySelectorAll('.sec').forEach(function (sec) {
        var no = sec.querySelector('.sh .no');
        if (no && no.textContent.trim() === String(a.section)) {
          var all = sec.querySelectorAll('.cl'); anchor = all.length ? all[all.length - 1] : null;
        }
      });
      if (!anchor) return;
      var cl = document.createElement('div'); cl.className = 'cl'; cl.setAttribute('data-added', '1');
      var n = document.createElement('span'); n.className = 'n'; n.textContent = a.num;
      var t = document.createElement('div'); t.className = 't';
      t.appendChild(build(t, a.text));
      cl.appendChild(n); cl.appendChild(t);
      anchor.parentNode.insertBefore(cl, anchor.nextSibling);
      cls[a.num] = cl;
    });
  }

  fetch('/api/agreement-points.php?doc=' + doc + '&lang=' + lang, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { apply(d); });
      else apply(d);
    })
    .catch(function () { /* the page's own wording stands */ });
})();
