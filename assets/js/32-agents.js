/* =====================================================================
   32-agents.js — "Our agents" on the home page (18 Oct 2026, owner:
   "live website ma agents dekhine design").

   Reads the PUBLIC list /api/agent-directory.php (approved + listed agents
   only: name, area, phone - masked unless that agent agreed - and the two
   positive badges) when the section comes near the screen, and draws one
   card per agent. With nobody listed yet the card shows the perks and the
   "Apply online" door to /agreements/apply.html. Read-only, no booking path.

   22 Oct 2026 (owner, team portal): the same list fills two sections -
   Pvt. Ltd. partner companies (#homePartners: logo, name, number) and
   agents (#homeAgents: photo, name, number) - each card with a WhatsApp
   chat (wa.me) and a call button. Agents show 12 at a time.
   ===================================================================== */
(function () {
  'use strict';
  /* the home view can be re-drawn after this runs, so the nodes are looked
     up at paint time, never kept */
  if (!document.getElementById('homeAgents') && !document.getElementById('homePartners')) return;
  var items = null, PAGE = 12, shown = PAGE;

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
  function img(src, size) {
    var im = el('img');
    im.src = src; im.alt = ''; im.loading = 'lazy'; im.decoding = 'async'; im.width = size; im.height = size;
    im.onerror = function () { if (im.parentNode) im.parentNode.removeChild(im); };
    return im;
  }
  /* WhatsApp + call: only when the person agreed to show the full number */
  function actions(a, pre) {
    var digits = String(a.phone || '').replace(/\D/g, '');
    if (digits.length < 10 || String(a.phone).indexOf('•') >= 0) return null;
    var acts = el('div', pre + '-acts');
    var wa = String(a.wa || '').replace(/\D/g, '');
    if (wa.length >= 10) {
      var w = el('a', pre + '-wa', 'WhatsApp');
      w.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste ' + a.name + ' ji, S Hari Global ko website bata. Malai bus ticket chahiyo.');
      w.target = '_blank'; w.rel = 'noopener';
      w.setAttribute('aria-label', 'WhatsApp ' + a.name);
      acts.appendChild(w);
    }
    var call = el('a', pre + '-call', '📞');
    call.href = 'tel:+' + (wa.length >= 10 ? wa : (digits.length === 10 ? '91' + digits : digits));
    call.setAttribute('aria-label', 'Call ' + a.name);
    acts.appendChild(call);
    return acts;
  }

  function agentCard(a) {
    var li = el('li', 'ag-card');
    var av = el('span', 'ag-av', initials(a.name));
    /* 19 Oct 2026: the photo, only when the office allowed it (the API sends '' otherwise) */
    if (a.photo && /^\/api\/agent-photo\.php\?k=[a-f0-9]+$/.test(a.photo)) av.appendChild(img(a.photo, 52));
    li.appendChild(av);
    var who = el('div', 'ag-who');
    /* 22 Oct 2026 (owner: "name, photo, number matra dekhsune"): no area,
       address or badges on the public card - those stay with the office */
    who.appendChild(el('b', '', a.name));
    who.appendChild(el('small', 'ag-ph', String(a.phone || '')));
    li.appendChild(who);
    var acts = actions(a, 'ag');
    if (acts) li.appendChild(acts);
    return li;
  }

  function partnerCard(a) {
    var li = el('li', 'pt-card');
    var lg = el('span', 'pt-logo', initials(a.name));
    var src = a.logo && /^\/api\/agent-photo\.php\?k=[a-f0-9]+&v=logo$/.test(a.logo) ? a.logo
            : (a.photo && /^\/api\/agent-photo\.php\?k=[a-f0-9]+$/.test(a.photo) ? a.photo : '');
    if (src) lg.appendChild(img(src, 64));
    li.appendChild(lg);
    var who = el('div', 'pt-who');
    who.appendChild(el('b', '', a.name));        /* 22 Oct 2026: logo, name, number only */
    who.appendChild(el('small', 'pt-ph', String(a.phone || '')));
    li.appendChild(who);
    var acts = actions(a, 'pt');
    if (acts) li.appendChild(acts);
    return li;
  }

  function paintList(id, list, make, limit) {
    var ul = document.getElementById(id);
    if (!ul) return;
    var key = list.length + ':' + limit;
    if (ul.getAttribute('data-n') === key) return;
    ul.setAttribute('data-n', key);
    ul.textContent = '';
    list.slice(0, limit).forEach(function (a) { ul.appendChild(make(a)); });
    ul.hidden = !list.length;
  }
  function render() {
    if (!items || !items.length) return;
    var partners = items.filter(function (a) { return a.kind === 'partner'; });
    var agents = items.filter(function (a) { return a.kind !== 'partner'; });
    paintList('ptList', partners, partnerCard, 60);
    paintList('agList', agents, agentCard, shown);
    var more = document.getElementById('agMore');
    if (more) {
      more.hidden = agents.length <= shown;
      more.textContent = 'थप एजेन्ट · More agents (' + (agents.length - shown) + ')';
    }
  }
  document.addEventListener('click', function (e) {
    if (!e.target || e.target.id !== 'agMore') return;
    shown += PAGE; render();
  });

  var loaded = false;
  function load() {
    if (loaded) return;
    loaded = true;
    fetch('/api/agent-directory.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) { items = d.items; render(); setTimeout(render, 1500); } })
      .catch(function () {});
  }
  var first = document.getElementById('homePartners') || document.getElementById('homeAgents');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (en) {
      if (en.some(function (x) { return x.isIntersecting; })) { io.disconnect(); load(); }
    }, { rootMargin: '400px 0px' });
    io.observe(first);
    setTimeout(load, 2500);
  } else {
    load();
  }
})();
