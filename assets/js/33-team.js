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
   ===================================================================== */
(function () {
  'use strict';
  if (!document.getElementById('homeTeam')) return;
  var PAGE = 12;
  var HASH = { driver: 'drivers', conductor: 'conductors', helper: 'helpers', office: 'office', accountant: 'accountants',
               mechanic: 'mechanics', cleaner: 'cleaners', security: 'security', other: 'team-other' };
  var NE = { driver: 'चालक', conductor: 'कन्डक्टर', helper: 'हेल्पर', office: 'अफिस', accountant: 'लेखापाल',
             mechanic: 'मेकानिक', cleaner: 'सफाइ', security: 'सुरक्षा', other: 'अन्य' };
  var data = null, filter = '', shown = PAGE, ld = false;

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
  function roleFromHash() {
    var h = String(location.hash || '').replace(/^#/, '');
    for (var k in HASH) if (HASH[k] === h) return k;
    return h === 'team' ? '' : null;
  }

  /* the home view can be re-drawn after this runs, so nodes are looked up at paint time */
  function paint() {
    var list = document.getElementById('tmList'), tabs = document.getElementById('tmTabs'), more = document.getElementById('tmMore');
    if (!list || !data || !data.items.length) return;
    var items = data.items.filter(function (it) { return !filter || it.role === filter; });

    if (data.roles.length > 1) {
      tabs.textContent = '';
      [''].concat(data.roles).forEach(function (r) {
        var a = el('a', 'tm-tab', r ? (NE[r] || r) + ' · ' + (data.items.filter(function (x) { return x.role === r; })[0] || {}).roleEn : 'सबै · All');
        a.href = '#' + (r ? HASH[r] : 'team');
        a.id = 'tm-tab-' + (r || 'all');
        a.setAttribute('role', 'tab');
        a.setAttribute('aria-selected', r === filter ? 'true' : 'false');
        a.addEventListener('click', function (e) {
          e.preventDefault();
          filter = r; shown = PAGE;
          try { history.replaceState(history.state, '', '#' + (r ? HASH[r] : 'team')); } catch (er) {}
          paint();
        });
        tabs.appendChild(a);
      });
      tabs.hidden = false;
    }

    list.textContent = '';
    items.slice(0, shown).forEach(function (it) {
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
      if (it.district) li.appendChild(el('small', 'tm-dist', '📍 ' + it.district));
      if (it.tel && /^\+\d{11,13}$/.test(it.tel)) {
        var a = el('a', 'tm-call', it.phone);   // the 📞 is a ::before, dropped on narrow phones so the number fits
        a.href = 'tel:' + it.tel;
        a.setAttribute('aria-label', 'Call ' + (it.name || 'staff'));
        li.appendChild(a);
      }
      list.appendChild(li);
    });
    list.hidden = false;
    more.hidden = items.length <= shown;
    more.textContent = 'थप हेर्नुहोस् · Show more (' + (items.length - shown) + ')';

    if (!ld) {
      ld = true;
      var org = location.origin + '/#org', graph = data.items.filter(function (x) { return x.name; }).slice(0, 60).map(function (x) {
        var p = { '@type': 'Person', name: x.name, worksFor: { '@id': org } };
        if (x.roleEn) p.jobTitle = x.roleEn;
        if (x.district) p.workLocation = { '@type': 'Place', name: x.district };
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
        data = { items: d.items, roles: d.roles || [] };
        var r = roleFromHash();
        if (r !== null && (r === '' || data.roles.indexOf(r) >= 0)) {
          filter = r;
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
  /* a shared /#drivers link: load at once instead of waiting for the scroll */
  if (roleFromHash() !== null) load();
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
