/* =====================================================================
   32-agents.js — "Our agents" on the home page (18 Oct 2026, owner:
   "live website ma agents dekhine design").

   Reads the PUBLIC list /api/agent-directory.php (approved + listed agents
   only: name, area, phone - masked unless that agent agreed - and the two
   positive badges) when the section comes near the screen, and draws one
   card per agent. With nobody listed yet the card shows the perks and the
   "Apply online" door to /agreements/apply.html. Read-only, no booking path.
   ===================================================================== */
(function () {
  'use strict';
  /* the home view can be re-drawn after this runs, so the nodes are looked
     up at paint time, never kept */
  if (!document.getElementById('homeAgents')) return;
  var items = null;

  function initials(name) {
    var p = String(name || '').trim().split(/\s+/);
    return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase() || '★';
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function render() {
    var list = document.getElementById('agList');
    if (!list || !items || !items.length || list.getAttribute('data-n') === String(items.length)) return;
    list.setAttribute('data-n', String(items.length));
    list.textContent = '';
    items.slice(0, 12).forEach(function (a) {
      var li = el('li', 'ag-card');
      var av = el('span', 'ag-av', initials(a.name));
      /* 19 Oct 2026: the photo, only when the office allowed it (the API sends '' otherwise) */
      if (a.photo && /^\/api\/agent-photo\.php\?k=[a-f0-9]+$/.test(a.photo)) {
        var im = el('img');
        im.src = a.photo; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = 52; im.height = 52;
        im.onerror = function () { if (im.parentNode) im.parentNode.removeChild(im); };
        av.appendChild(im);
      }
      li.appendChild(av);
      var who = el('div', 'ag-who');
      who.appendChild(el('b', '', a.name));
      who.appendChild(el('small', '', (a.kind === 'partner' ? '🏢 ' : '👤 ') + (a.area || 'S Hari Global')));
      if (a.addr) who.appendChild(el('small', 'ag-addr', '📍 ' + a.addr));
      var digits = String(a.phone || '').replace(/\D/g, '');
      var full = digits.length >= 10 && String(a.phone).indexOf('•') < 0;
      who.appendChild(el('small', 'ag-ph', String(a.phone || '')));
      var badges = el('div', 'ag-badges');
      if (a.verified) badges.appendChild(el('i', '', '✓ KYC'));
      if (a.signed) badges.appendChild(el('i', '', '✓ सम्झौता'));
      if (badges.childNodes.length) who.appendChild(badges);
      li.appendChild(who);
      if (full) {
        var acts = el('div', 'ag-acts');
        var wa = String(a.wa || '').replace(/\D/g, '');
        if (wa.length >= 10) {
          var w = el('a', 'ag-wa', 'WhatsApp');
          w.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste ' + a.name + ' ji, S Hari Global ko website bata. Malai bus ticket chahiyo.');
          w.target = '_blank'; w.rel = 'noopener';
          w.setAttribute('aria-label', 'WhatsApp ' + a.name);
          acts.appendChild(w);
        }
        var call = el('a', 'ag-call', '📞');
        call.href = 'tel:+' + (wa.length >= 10 ? wa : (digits.length === 10 ? '91' + digits : digits));
        call.setAttribute('aria-label', 'Call ' + a.name);
        acts.appendChild(call);
        li.appendChild(acts);
      }
      list.appendChild(li);
    });
    list.hidden = false;
  }
  var loaded = false;
  function load() {
    if (loaded) return;
    loaded = true;
    fetch('/api/agent-directory.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) { items = d.items; render(); setTimeout(render, 1500); } })
      .catch(function () {});
  }
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (en) {
      if (en.some(function (x) { return x.isIntersecting; })) { io.disconnect(); load(); }
    }, { rootMargin: '400px 0px' });
    io.observe(document.getElementById('homeAgents'));
    setTimeout(load, 2500);
  } else {
    load();
  }
})();
