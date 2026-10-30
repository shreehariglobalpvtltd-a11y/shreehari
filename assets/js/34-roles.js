/* =====================================================================
   34-roles.js — AGENT | STAFF | PVT. LTD. PARTNER on the home page
   (23 Oct 2026, owner: "replace Partner Companies / Our Agents / Our Team
   with exactly three modern integrated sections").

   One section, #homeRoles, three role cards. Each card shows the Top 3
   (/api/top-people.php: approved people the office put on the website,
   the contact each one agreed to show, ranked by real work) and opens the
   full list on "See all" (/api/agent-directory.php for agents and partner
   companies, /api/staff-list.php for staff - the same public fields as
   before, nothing new leaves the server). Apply and Login doors per role.
   Read-only, no booking path. Replaces 32-agents.js and 33-team.js.
   31 Oct 2026: the lists also carry approved AgentPro members (photo from
   /api/ap-photo.php, role keys counter / manager / driver …); Apply goes to
   /join.php (an office user lands on the office form). Staff keep the A4
   staff sheet: the public join.php makes staff only from an office invite.
   ===================================================================== */
(function () {
  'use strict';
  if (!document.getElementById('homeRoles')) return;
  var PAGE = 12;
  var APPLY = { agent: '/join.php?from=apply&kind=agent', staff: '/agreements/apply.html?kind=staff', partner: '/join.php?from=apply&kind=partner' };
  var NE = { driver: 'चालक', conductor: 'कन्डक्टर', helper: 'हेल्पर', office: 'अफिस', accountant: 'लेखापाल', mechanic: 'मेकानिक', cleaner: 'सफाइ', security: 'सुरक्षा', other: 'अन्य',
    counter: 'काउन्टर', manager: 'म्यानेजर', support: 'ग्राहक सेवा', scanner: 'बोर्डिङ स्टाफ', official: 'अधिकारी', operations: 'सञ्चालन', viewer: 'कर्मचारी', superadmin: 'सञ्चालक' };
  var top = null, dir = null, staff = null, shown = { agent: PAGE, staff: PAGE, partner: PAGE }, open = {};

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function okPhoto(src) { src = String(src || ''); return /^\/api\/(agent|staff)-photo\.php\?k=[a-f0-9]+(&v=logo)?$/.test(src) || /^\/api\/ap-photo\.php\?k=[a-f0-9]{20}$/.test(src); }
  function box(kind) { return document.querySelector('#homeRoles [data-role="' + kind + '"]'); }
  var io = 'IntersectionObserver' in window && document.documentElement.getAttribute('data-motion') !== 'reduced'
    ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { rootMargin: '0px 0px -6% 0px' }) : null;
  function reveal(node, i) { if (io) { node.style.transitionDelay = (i * 70) + 'ms'; io.observe(node); } else node.classList.add('in'); }

  /* WhatsApp with a professional line + call: only with a full public number */
  function acts(p, kind) {
    var a = el('div', 'rl-acts');
    var wa = String(p.wa || '').replace(/\D/g, ''), tel = String(p.tel || '').replace(/[^\d+]/g, '');
    if (!tel && wa.length >= 10) tel = '+' + wa;
    if (wa.length >= 10) {
      var w = el('a', 'rl-wa', 'WhatsApp');
      w.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste ' + (p.name || '') + ' ji, S Hari Global ko website bata. ' + (kind === 'staff' ? 'Bus ko barema sodhnu thiyo.' : 'Malai bus ticket chahiyo.'));
      w.target = '_blank'; w.rel = 'noopener'; w.setAttribute('aria-label', 'WhatsApp ' + p.name);
      a.appendChild(w);
    }
    if (/^\+?\d{10,15}$/.test(tel)) { var c = el('a', 'rl-call', '📞'); c.href = 'tel:' + tel; c.setAttribute('aria-label', 'Call ' + p.name); a.appendChild(c); }
    return a.children.length ? a : null;
  }
  /* 23 Oct 2026 (owner: "photo xa vane photo, xaina vane name ra number matra"): no photo = no
     avatar at all (no letter circle) - the card shows the name and the number the person made public. */
  function photoOf(p, kind) { return kind === 'partner' && okPhoto(p.logo) ? p.logo : (okPhoto(p.photo) ? p.photo : ''); }
  function avatar(p, kind, big) {
    var src = photoOf(p, kind);
    if (!src) return null;
    var av = el('span', 'rl-av' + (kind === 'partner' ? ' logo' : ''));
    var im = el('img'); im.src = src; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = im.height = big ? 96 : 56;
    im.onerror = function () { var c = av.closest('.rl-top, .rl-item'); if (c) c.classList.add('nophoto'); av.remove(); };
    av.appendChild(im);
    return av;
  }
  function number(p) {
    var tel = String(p.tel || '').replace(/[^\d+]/g, ''), wa = String(p.wa || '').replace(/\D/g, '');
    if (!tel && wa.length >= 10) tel = '+' + wa;
    return /^\+?\d{10,15}$/.test(tel) ? tel : '';
  }
  function topCard(p, i, kind) {
    var av = avatar(p, kind, true), num = number(p);
    var c = el('article', 'rl-top r' + (i + 1) + (av ? '' : ' nophoto'));
    c.appendChild(el('span', 'rl-rank', '#' + (i + 1)));
    if (av) c.appendChild(av);
    c.appendChild(el('h4', 'rl-name', p.name || ''));
    if (num) c.appendChild(el('p', 'rl-num', '📞 ' + num));
    if (p.role) c.appendChild(el('p', 'rl-role', p.role));
    if (p.area) c.appendChild(el('p', 'rl-area', '📍 ' + p.area));
    c.appendChild(el('p', 'rl-ver', p.metric || '✔ कम्पनी प्रमाणित · ID verified'));
    var a = acts(p, kind); if (a) c.appendChild(a);
    return c;
  }
  function listCard(p, kind) {
    var av = avatar(p, kind, false), num = number(p);
    var li = el('li', 'rl-item' + (av ? '' : ' nophoto'));
    if (av) li.appendChild(av);
    var who = el('div', 'rl-who');
    who.appendChild(el('b', '', p.name || ''));
    if (num) who.appendChild(el('small', 'rl-num', '📞 ' + num));
    var sub = [];
    if (kind === 'staff') { if (p.role) sub.push((NE[p.role] || p.roleNe || '') + (p.roleEn ? ' · ' + p.roleEn : '')); if (p.loc) sub.push(p.loc); }
    else if (p.area) sub.push(p.area);
    if (sub.length) who.appendChild(el('small', '', sub.join(' · ')));
    var tick = el('small', 'rl-tick', kind === 'staff' ? '✔ ID verified' : (p.verified ? '✔ KYC verified' : '✔ Approved') + (p.signed ? ' · 📝 signed' : ''));
    who.appendChild(tick);
    li.appendChild(who);
    var a = acts(p, kind); if (a) li.appendChild(a);
    return li;
  }

  function fillTop(kind, people) {
    var b = box(kind); if (!b) return;
    var row = b.querySelector('.rl-row'); row.textContent = '';
    if (!people.length) {
      var e = el('p', 'rl-empty', ''), l = el('a', '', { agent: 'पहिलो एजेन्ट बन्नुहोस् →', staff: 'पहिलो कर्मचारी बन्नुहोस् →', partner: 'पहिलो साझेदार कम्पनी बन्नुहोस् →' }[kind]);
      l.href = APPLY[kind]; e.appendChild(l); row.appendChild(e); return;
    }
    people.slice(0, 3).forEach(function (p, i) { var c = topCard(p, i, kind); row.appendChild(c); reveal(c, i); });
  }
  function fullList(kind) {
    if (kind === 'staff') return staff ? staff.items : null;
    if (!dir) return null;
    return dir.filter(function (x) { return (x.kind === 'partner') === (kind === 'partner'); });
  }
  function paintList(kind) {
    var b = box(kind); if (!b) return;
    var ul = b.querySelector('.rl-list'), more = b.querySelector('.rl-more'), all = b.querySelector('.rl-all'), list = fullList(kind);
    var n = list ? list.length : 0;
    if (all) { all.hidden = !n; all.textContent = (open[kind] ? 'लुकाउनुहोस् · Hide' : 'सबै हेर्नुहोस् · See all') + ' (' + n + ')'; all.setAttribute('aria-expanded', open[kind] ? 'true' : 'false'); }
    if (!open[kind] || !list) { ul.hidden = true; more.hidden = true; return; }
    ul.textContent = '';
    list.slice(0, shown[kind]).forEach(function (p) { ul.appendChild(listCard(p, kind)); });
    ul.hidden = false;
    more.hidden = n <= shown[kind];
    more.textContent = 'थप · More (' + Math.max(0, n - shown[kind]) + ')';
  }
  /* 30 Oct 2026 (owner: "verified agent ... ticketing pachhi aaos"): the section sits right after the
     booking card and, until "+ थप · More" is open, shows ONE role card - the picked tab's (.rl-on,
     <style id="homeFold"> in app.template.html). The tabs are real tabs now. */
  function pick(kind) {
    [].forEach.call(document.querySelectorAll('#homeRoles .rl-tab'), function (x) { x.setAttribute('aria-selected', x.getAttribute('data-kind') === kind ? 'true' : 'false'); });
    [].forEach.call(document.querySelectorAll('#homeRoles .rl-card'), function (c) { c.classList.toggle('rl-on', c.getAttribute('data-role') === kind); });
  }
  function counts() {
    ['agent', 'staff', 'partner'].forEach(function (k) {
      var b = box(k), list = fullList(k); if (!b) return;
      var c = b.querySelector('.rl-count'); if (c && list) { c.textContent = list.length ? list.length + (k === 'staff' ? ' जना' : '') : ''; c.hidden = !list.length; }
      paintList(k);
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('#homeRoles .rl-all, #homeRoles .rl-more, #homeRoles .rl-tab') : null;
    if (!t) return;
    var kind = t.getAttribute('data-kind');
    if (t.classList.contains('rl-tab')) {
      e.preventDefault();
      pick(kind);
      /* the opened ("+ थप") page shows all three cards - the tab scrolls there; the compact
         home shows only the picked one, already in place */
      var vh = document.getElementById('view-home');
      var b = box(kind); if (b && vh && vh.classList.contains('home-full')) b.scrollIntoView({ behavior: document.documentElement.getAttribute('data-motion') === 'reduced' ? 'instant' : 'smooth', block: 'start' });
      return;
    }
    if (t.classList.contains('rl-more')) { shown[kind] += PAGE; paintList(kind); return; }
    open[kind] = !open[kind];
    if (open[kind]) loadLists();
    paintList(kind);
  });

  var tLoaded = false, lLoaded = false;
  function get(url) { return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }); }
  function loadTop() {
    if (tLoaded) return; tLoaded = true;
    get('/api/top-people.php').then(function (j) {
      if (!j || !j.ok) throw new Error('bad');
      top = j;
      ['agent', 'staff', 'partner'].forEach(function (k) { fillTop(k, Array.isArray(j[k]) ? j[k] : []); });
    }).catch(function () {
      [].forEach.call(document.querySelectorAll('#homeRoles .rl-row'), function (r) { r.textContent = ''; r.hidden = true; });
    }).then(loadLists);
  }
  function loadLists() {
    if (lLoaded) return; lLoaded = true;
    get('/api/agent-directory.php').then(function (d) { dir = d && d.ok ? d.items : []; counts(); }).catch(function () { dir = []; counts(); });
    get('/api/staff-list.php').then(function (d) { staff = d && d.ok ? { items: d.items || [], roles: d.roles || [] } : { items: [], roles: [] }; counts(); }).catch(function () { staff = { items: [], roles: [] }; counts(); });
  }
  /* a shared /#agents, /#team or /#partners link opens that card's full list */
  var hash = String(location.hash || '').replace(/^#/, '');
  var want = { agents: 'agent', team: 'staff', drivers: 'staff', conductors: 'staff', partners: 'partner' }[hash];
  if (want) { open[want] = true; pick(want); }
  var root = document.getElementById('homeRoles');
  if ('IntersectionObserver' in window && !want) {
    var w = new IntersectionObserver(function (en) { if (en.some(function (x) { return x.isIntersecting; })) { w.disconnect(); loadTop(); } }, { rootMargin: '500px 0px' });
    w.observe(root);
    setTimeout(loadTop, 3000);
  } else {
    loadTop();
    if (want) setTimeout(function () { var b = box(want); if (b) b.scrollIntoView({ block: 'start' }); }, 600);
  }
})();
