/* =====================================================================
   33-team.js — "Our team" on the home page (22 Oct 2026, owner: approved
   staff on the website like the agents).

   Reads the PUBLIC list /api/staff-list.php (approved staff the office left
   on the website; only the fields Settings staff_public_fields allows) when
   the section comes near the screen, and draws 12 cards at a time: round
   photo, name, job chip, district, call button. One tab per job present;
   each tab is a real link (#drivers, #conductors …) so a shared /#drivers
   opens the home page on that tab. The hash is set with replaceState, so
   the SPA router never re-runs for a tab. JSON-LD Person entries (worksFor
   the #org node index.php prints) are added once the list is in.
   Read-only, no booking path; the door to /agreements/staff.html.

   22 Oct 2026 (owner, team portal): grouped by the office each person
   works from (base_location the office sets: Nepalgunj, Surat …) - one
   tab per office (#team-nepalgunj …) above the job tabs, and under "All"
   a heading per office. Each card has a WhatsApp chat (wa.me) next to the
   call button.
   ===================================================================== */
(function () {
  'use strict';
  if (!document.getElementById('homeTeam')) return;
  var PAGE = 12;
  var HASH = { driver: 'drivers', conductor: 'conductors', helper: 'helpers', office: 'office', accountant: 'accountants',
               mechanic: 'mechanics', cleaner: 'cleaners', security: 'security', other: 'team-other' };
  var NE = { driver: 'चालक', conductor: 'कन्डक्टर', helper: 'हेल्पर', office: 'अफिस', accountant: 'लेखापाल',
             mechanic: 'मेकानिक', cleaner: 'सफाइ', security: 'सुरक्षा', other: 'अन्य' };
  var LOC_NE = { nepalgunj: 'नेपालगञ्ज', surat: 'सुरत', rupaidiha: 'रुपैडिहा', kathmandu: 'काठमाडौं', lucknow: 'लखनऊ' };
  var NOLOC = '~';   // people the office has not placed yet
  var data = null, filter = '', loc = '', shown = PAGE, ld = false;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function initials(name) {
    var p = String(name || '').trim().split(/\s+/);
    return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase() || '★';
  }
  function slug(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }
  function locOf(it) { return it.loc ? String(it.loc) : NOLOC; }
  function locLabel(l) {
    if (l === NOLOC) return 'अन्य · Other';
    var ne = LOC_NE[slug(l)];
    return ne ? ne + ' · ' + l : l;
  }
  function roleFromHash() {
    var h = String(location.hash || '').replace(/^#/, '');
    for (var k in HASH) if (HASH[k] === h) return k;
    return h === 'team' ? '' : null;
  }
  function locFromHash() {
    var h = String(location.hash || '').replace(/^#/, '');
    if (h.indexOf('team-') !== 0 || h === 'team-other' || !data) return null;
    for (var i = 0; i < data.locs.length; i++) if ('team-' + slug(data.locs[i]) === h) return data.locs[i];
    return null;
  }
  function setHash(h) {
    try { history.replaceState(history.state, '', '#' + h); } catch (er) {}
  }

  function tab(label, href, id, on, click) {
    var a = el('a', 'tm-tab', label);
    a.href = href; a.id = id;
    a.setAttribute('role', 'tab');
    a.setAttribute('aria-selected', on ? 'true' : 'false');
    a.addEventListener('click', function (e) { e.preventDefault(); click(); });
    return a;
  }

  function card(it) {
    var li = el('li', 'tm-card');
    var av = el('span', 'tm-av', initials(it.name || it.roleEn));
    if (it.photo && /^\/api\/staff-photo\.php\?k=[a-f0-9]{20}$/.test(it.photo)) {
      var im = el('img');
      im.src = it.photo; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = 84; im.height = 84;
      im.onerror = function () { if (im.parentNode) im.parentNode.removeChild(im); };
      av.appendChild(im);
    }
    li.appendChild(av);
    li.appendChild(el('b', 'tm-name', it.name || 'S Hari Global'));
    if (it.role) li.appendChild(el('span', 'tm-role', (it.roleNe || '') + (it.roleEn ? ' · ' + it.roleEn : '')));
    if (it.loc) li.appendChild(el('small', 'tm-loc', '🏢 ' + it.loc));
    if (it.district) li.appendChild(el('small', 'tm-dist', '📍 ' + it.district));
    if (it.tel && /^\+\d{11,13}$/.test(it.tel)) {
      var acts = el('div', 'tm-acts');
      var a = el('a', 'tm-call', it.phone);   // the 📞 is a ::before, dropped on narrow phones so the number fits
      a.href = 'tel:' + it.tel;
      a.setAttribute('aria-label', 'Call ' + (it.name || 'staff'));
      acts.appendChild(a);
      var wa = String(it.wa || '').replace(/\D/g, '');
      if (wa.length >= 11) {
        var w = el('a', 'tm-wa', 'WhatsApp');
        w.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste ' + (it.name || '') + ' ji, S Hari Global ko website bata.');
        w.target = '_blank'; w.rel = 'noopener';
        w.setAttribute('aria-label', 'WhatsApp ' + (it.name || 'staff'));
        acts.appendChild(w);
      }
      li.appendChild(acts);
    }
    return li;
  }

  /* the home view can be re-drawn after this runs, so nodes are looked up at paint time */
  function paint() {
    var list = document.getElementById('tmList'), tabs = document.getElementById('tmTabs'),
        locs = document.getElementById('tmLocs'), more = document.getElementById('tmMore');
    if (!list || !data || !data.items.length) return;
    var inLoc = data.items.filter(function (it) { return !loc || locOf(it) === loc; });
    var items = inLoc.filter(function (it) { return !filter || it.role === filter; });

    /* office tabs: only when people sit in two or more offices */
    if (locs && data.locs.length > 1) {
      locs.textContent = '';
      locs.appendChild(tab('सबै अफिस · All', '#team', 'tm-loc-all', loc === '', function () {
        loc = ''; shown = PAGE; setHash(filter ? HASH[filter] : 'team'); paint();
      }));
      data.locs.forEach(function (l) {
        var n = data.items.filter(function (x) { return locOf(x) === l; }).length;
        var h = l === NOLOC ? 'team' : 'team-' + slug(l);
        locs.appendChild(tab('🏢 ' + locLabel(l) + ' (' + n + ')', '#' + h, 'tm-loc-' + (slug(l) || 'other'), loc === l, function () {
          loc = l; shown = PAGE; setHash(h); paint();
        }));
      });
      locs.hidden = false;
    }

    /* job tabs: the jobs present in the chosen office */
    var roles = data.roles.filter(function (r) { return inLoc.some(function (x) { return x.role === r; }); });
    if (filter && roles.indexOf(filter) < 0) { filter = ''; items = inLoc; }
    tabs.textContent = '';
    if (roles.length > 1) {
      [''].concat(roles).forEach(function (r) {
        tabs.appendChild(tab(r ? (NE[r] || r) + ' · ' + (data.items.filter(function (x) { return x.role === r; })[0] || {}).roleEn : 'सबै · All',
          '#' + (r ? HASH[r] : 'team'), 'tm-tab-' + (r || 'all'), r === filter, function () {
            filter = r; shown = PAGE; setHash(r ? HASH[r] : (loc && loc !== NOLOC ? 'team-' + slug(loc) : 'team')); paint();
          }));
      });
      tabs.hidden = false;
    } else {
      tabs.hidden = true;
    }

    /* keep the chosen tab in view inside its scrolling row (never scrolls the page) */
    [locs, tabs].forEach(function (row) {
      var sel = row && !row.hidden && row.querySelector('[aria-selected="true"]');
      if (sel) row.scrollLeft = Math.max(0, sel.offsetLeft - row.offsetLeft - 14);
    });

    list.textContent = '';
    /* "All offices" with people in two or more: a heading per office, offices in the API's order */
    var grouped = !loc && data.locs.length > 1;
    if (grouped) items = items.slice().sort(function (a, b) { return data.locs.indexOf(locOf(a)) - data.locs.indexOf(locOf(b)); });
    var last = null;
    items.slice(0, shown).forEach(function (it) {
      if (grouped && locOf(it) !== last) {
        last = locOf(it);
        var hd = el('li', 'tm-group', '🏢 ' + locLabel(last));
        hd.setAttribute('role', 'presentation');
        list.appendChild(hd);
      }
      list.appendChild(card(it));
    });
    list.hidden = false;
    more.hidden = items.length <= shown;
    more.textContent = 'थप हेर्नुहोस् · Show more (' + (items.length - shown) + ')';

    if (!ld) {
      ld = true;
      var org = location.origin + '/#org', graph = data.items.filter(function (x) { return x.name; }).slice(0, 60).map(function (x) {
        var p = { '@type': 'Person', name: x.name, worksFor: { '@id': org } };
        if (x.roleEn) p.jobTitle = x.roleEn;
        if (x.loc || x.district) p.workLocation = { '@type': 'Place', name: x.loc || x.district };
        if (x.tel) p.telephone = x.tel;
        return p;
      });
      if (graph.length) {
        var s = document.createElement('script');
        s.type = 'application/ld+json';
        s.textContent = JSON.stringify({ '@context': 'https://schema.org', '@type': 'Organization', '@id': org, employee: graph });
        document.head.appendChild(s);
      }
    }
  }

  function moreClick(e) {
    if (!e.target || e.target.id !== 'tmMore') return;
    shown += PAGE; paint();
  }
  document.addEventListener('click', moreClick);

  var loaded = false;
  function load() {
    if (loaded) return;
    loaded = true;
    fetch('/api/staff-list.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.ok || !d.items || !d.items.length) return;
        /* offices in the API's order (the office's display order), "not placed yet" last */
        var locs = [];
        d.items.forEach(function (it) { var l = locOf(it); if (locs.indexOf(l) < 0) locs.push(l); });
        locs.sort(function (a, b) { return (a === NOLOC) - (b === NOLOC); });
        data = { items: d.items, roles: d.roles || [], locs: locs };
        var r = roleFromHash(), l = locFromHash();
        if (l !== null) loc = l;
        if (r !== null && (r === '' || data.roles.indexOf(r) >= 0)) filter = r;
        if (r !== null || l !== null) {
          /* the home view settles (and resets the scroll) after load: jump again once it has,
             unless the visitor already scrolled somewhere themselves */
          var y0 = -1;
          [400, 1600].forEach(function (ms) {
            setTimeout(function () {
              var s = document.getElementById('homeTeam');
              if (!s || (y0 >= 0 && Math.abs(window.scrollY - y0) > 40)) return;
              // html has scroll-behavior:smooth, which never finishes in a background tab: the retry jumps
              s.scrollIntoView({ behavior: ms > 1000 ? 'instant' : 'smooth', block: 'start' });
              setTimeout(function () { y0 = window.scrollY; }, 500);
            }, ms);
          });
        }
        paint(); setTimeout(paint, 1500);
      })
      .catch(function () {});
  }
  /* a shared /#drivers or /#team-surat link: load at once instead of waiting for the scroll */
  if (roleFromHash() !== null || /^#team-/.test(location.hash || '')) load();
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (en) {
      if (en.some(function (x) { return x.isIntersecting; })) { io.disconnect(); load(); }
    }, { rootMargin: '400px 0px' });
    io.observe(document.getElementById('homeTeam'));
    setTimeout(load, 3000);
  } else {
    load();
  }
})();
