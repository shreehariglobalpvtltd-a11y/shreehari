/* =====================================================================
   35-homehub.js — "Agents & Locations" on the home page (29 Oct 2026,
   owner's overhaul prompt: "list all agents and their locations in a
   clean, organized category section").

   One list, one row per place: the pickup / drop times of every route
   (/api/timetable.php, the same route_stops the checkout sells from), the
   office there (/api/nav-offices.php) and the agents who work it
   (/api/agent-directory.php - only the public fields the office allowed).
   Fetched once, when the card nears the screen, so the ticket-first home
   stays fast. Text only through textContent - nothing from an API is
   ever parsed as HTML.
   ===================================================================== */
(function () {
  'use strict';
  var list = document.getElementById('hubLocList');
  if (!list) return;
  var q = document.getElementById('hubLocQ');
  var more = document.getElementById('hubLocMore');
  var PAGE = 6;
  var places = [], shown = PAGE, loaded = false, ready = false, failed = false;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function key(s) { return String(s || '').toLowerCase().replace(/\(.*?\)/g, '').replace(/[^a-zऀ-ॿ]+/g, ' ').trim(); }
  function get(u) { return fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }); }

  function place(map, name) {
    var k = key(name);
    if (!k) return null;
    if (!map[k]) { map[k] = { name: String(name).trim(), k: k, n: Object.keys(map).length, stops: [], office: [], agents: [], lat: null, lng: null }; }
    return map[k];
  }

  function build(tt, off, dir) {
    var map = {};
    var routes = tt && tt.ok && tt.data && tt.data.routes ? tt.data.routes : [];
    routes.forEach(function (r) {
      var via = r.from + ' → ' + r.to;
      (r.boarding || []).forEach(function (b) {
        var p = place(map, b.name);
        if (!p) return;
        p.stops.push('🚌 ' + (b.time ? b.time + ' · ' : '') + via + (b.landmark && !/designated/i.test(b.landmark) ? ' · ' + b.landmark : ''));
        if (b.lat && b.lng && p.lat == null) { p.lat = b.lat; p.lng = b.lng; }
      });
      (r.drop || []).forEach(function (d) {
        var p = place(map, d.name);
        if (p) p.stops.push('🏁 Drop · ' + via);
      });
    });
    (off && off.ok ? off.offices || [] : []).forEach(function (o) {
      var city = String(o.name || '').split(/\s+[—-]\s+/)[0];
      var p = place(map, city);
      if (!p) return;
      p.office.push('🏢 ' + (o.kind === 'head' ? 'Head office' : 'Office') + (o.phone ? ' · ' + o.phone : ''));
      if (o.lat && o.lng && p.lat == null) { p.lat = o.lat; p.lng = o.lng; }
    });
    (dir && dir.ok ? dir.items || [] : []).forEach(function (a) {
      String(a.area || '').split(/[,/]+/).forEach(function (area) {
        var p = place(map, area);
        if (p) p.agents.push(a);
      });
    });
    return Object.keys(map).map(function (k) { return map[k]; });
  }

  function agentTag(a) {
    var t = el('span', 'hub-tag', '👤 ' + a.name + (a.kind === 'partner' ? ' (Pvt Ltd)' : '') + (a.verified ? ' ✓' : ''));
    var wa = String(a.wa || '').replace(/\D/g, '');
    if (wa.length >= 10) {
      var w = el('a', '', 'WhatsApp');
      w.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste ' + a.name + ' ji, S Hari Global ko website bata. Malai bus ticket chahiyo.');
      w.target = '_blank'; w.rel = 'noopener';
      t.appendChild(document.createTextNode(' · '));
      t.appendChild(w);
    }
    return t;
  }

  function row(p) {
    var li = el('li');
    var n = el('div', 'hub-loc-n');
    n.appendChild(el('span', '', p.name));
    if (p.lat != null && p.lng != null) {
      var m = el('a', '', 'Map ↗');
      m.href = 'https://www.google.com/maps?q=' + encodeURIComponent(p.lat + ',' + p.lng);
      m.target = '_blank'; m.rel = 'noopener';
      n.appendChild(m);
    }
    li.appendChild(n);
    var tags = el('div', 'hub-tags');
    p.stops.concat(p.office).forEach(function (s) { tags.appendChild(el('span', 'hub-tag', s)); });
    p.agents.forEach(function (a) { tags.appendChild(agentTag(a)); });
    li.appendChild(tags);
    return li;
  }

  function render() {
    var term = key(q ? q.value : '');
    var hits = places.filter(function (p) { return !term || p.k.indexOf(term) !== -1 || p.agents.some(function (a) { return key(a.name).indexOf(term) !== -1; }); });
    list.textContent = '';
    if (!hits.length) {
      list.appendChild(el('li', 'hub-skel', !failed ? 'No match. Call the office or WhatsApp us. · मिलेन — अफिसमा सोध्नुहोस्।' : 'Could not load the list. · सूची लोड भएन।'));
    }
    var cut = term ? hits.length : Math.min(shown, hits.length);
    hits.slice(0, cut).forEach(function (p) { list.appendChild(row(p)); });
    if (more) {
      more.hidden = cut >= hits.length;
      more.textContent = 'See all ' + hits.length + ' locations · सबै हेर्नुहोस्';
    }
  }

  function load() {
    if (loaded) return;
    loaded = true;
    Promise.all([get('/api/timetable.php'), get('/api/nav-offices.php'), get('/api/agent-directory.php')]).then(function (r) {
      failed = !r[0] && !r[1] && !r[2];
      places = build(r[0], r[1], r[2]);
      ready = true;
      /* places with an agent or an office first, then the run's own stop order */
      places.sort(function (a, b) {
        var wa = (a.agents.length || a.office.length) ? 0 : 1, wb = (b.agents.length || b.office.length) ? 0 : 1;
        return wa - wb || a.n - b.n;
      });
      render();
    });
  }

  if (more) more.addEventListener('click', function () { shown = places.length; render(); });
  if (q) q.addEventListener('input', function () { load(); if (ready) render(); });
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); load(); } }, { rootMargin: '300px 0px' });
    io.observe(list);
  } else {
    load();
  }
})();
