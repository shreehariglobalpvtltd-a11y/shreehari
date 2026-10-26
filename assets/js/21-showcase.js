/* 21-showcase.js — 9 Oct 2026: home showcase behaviour (reveal/tilt, CEO card,
   perks, offices, timeline teaser, explore teaser). Owner: HOME work package.

   Owner: "home page attractive, smooth - I want to keep looking at it", with
   the feel of a Pixel phone. This file adds the motion and the small pieces of
   behaviour the home showcase needs; it never books, pays or sends anything.

     • reveal on scroll   .sc-reveal / .sc-stagger get .sc-in the first time they
                          are seen (IntersectionObserver; blocks already on screen
                          are marked at once, so nothing blinks out at load)
     • live loops         #homeHero, #homeFeat, #quick-ticket, #homeCeo, #bookHelp
                          carry .sc-live only while on screen - the CSS loops in
                          showcase.css run only then
     • 3D tilt            the hero keys lean toward the pointer / finger
                          (--sc-rx / --sc-ry, one write per frame)
     • press ripple       a Material ripple from the touch point (.sc-rip)
     • sounds             SHGFeel.play('tap' | 'sheetOpen' | 'sheetClose') - the
                          user's sound switch decides (19-premium.js)
     • office lists       the contact section's India offices + 24x7 line, the
                          Nepal office people and the WhatsApp sheet's extra rows,
                          from window.FACTS (+ SHG_BOOT.npTeam) - no typed numbers
   Motion respects prefers-reduced-motion and html[data-motion="reduced"]. */
(function () {
  'use strict';
  var d = document, de = d.documentElement;
  if (window.shgShowcase) return;

  function reduced() {
    try {
      return de.getAttribute('data-motion') === 'reduced'
        || !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) { return false; }
  }
  function feel(name) {
    try { if (window.SHGFeel && typeof window.SHGFeel.play === 'function') window.SHGFeel.play(name); } catch (e) {}
  }
  function tr(key, dflt) {
    try { if (typeof t === 'function') { var v = t(key); if (v && v !== key) return v; } } catch (e) {}
    return dflt;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function digits(v) { return String(v == null ? '' : v).replace(/\D/g, ''); }
  function lang() {
    try { if (typeof LANG === 'string' && LANG) return LANG; } catch (e) {}
    return de.getAttribute('lang') || 'ne';
  }
  var HAS_IO = 'IntersectionObserver' in window;
  if (HAS_IO) de.classList.add('sc-io');

  /* ---- 1. reveal on scroll ------------------------------------------- */
  function onScreen(el) {
    if (!el || el.offsetParent === null) return false;     // hidden view / collapsed block
    var r = el.getBoundingClientRect(), h = window.innerHeight || de.clientHeight || 800;
    return r.top < h && r.bottom > 0;
  }
  var revealIO = HAS_IO ? new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      en.target.classList.add('sc-in');
      revealIO.unobserve(en.target);
    });
  }, { rootMargin: '0px 0px -6% 0px', threshold: 0.06 }) : null;
  function reveal(el, cls) {
    if (!el || el._scReveal) return;
    el._scReveal = true;
    el.classList.add(cls || 'sc-reveal');
    if (!revealIO || reduced() || onScreen(el)) { el.classList.add('sc-in'); return; }
    revealIO.observe(el);
  }

  /* ---- 2. loops only while on screen --------------------------------- */
  var liveIO = HAS_IO ? new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { en.target.classList.toggle('sc-live', en.isIntersecting); });
  }, { rootMargin: '60px 0px 60px 0px' }) : null;
  function live(el) {
    if (!el || el._scLive) return;
    el._scLive = true;
    if (liveIO) liveIO.observe(el); else el.classList.add('sc-live');
  }

  /* ---- 3. the hero keys lean toward the pointer ---------------------- */
  function tilt(el) {
    if (!el || el._scTilt) return;
    el._scTilt = true;
    var raf = 0, px = 0, py = 0, down = false;
    function paint() {
      raf = 0;
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      var x = Math.max(-0.5, Math.min(0.5, (px - r.left) / r.width - 0.5));
      var y = Math.max(-0.5, Math.min(0.5, (py - r.top) / r.height - 0.5));
      el.style.setProperty('--sc-ry', (x * 16).toFixed(2) + 'deg');
      el.style.setProperty('--sc-rx', (-y * 16).toFixed(2) + 'deg');
      el.classList.add('sc-tilting');
    }
    function aim(e) {
      if (reduced()) return;
      if (e.pointerType === 'touch' && !down) return;       // a finger tilts only while pressing
      px = e.clientX; py = e.clientY;
      if (!raf) raf = window.requestAnimationFrame(paint);
    }
    function reset() {
      down = false;
      if (raf) { window.cancelAnimationFrame(raf); raf = 0; }
      el.classList.remove('sc-tilting');
      el.style.removeProperty('--sc-rx');
      el.style.removeProperty('--sc-ry');
    }
    el.addEventListener('pointerdown', function (e) { down = true; aim(e); }, { passive: true });
    el.addEventListener('pointermove', aim, { passive: true });
    el.addEventListener('pointerup', function () { setTimeout(reset, 90); }, { passive: true });
    el.addEventListener('pointerleave', reset, { passive: true });
    el.addEventListener('pointercancel', reset, { passive: true });
  }

  /* ---- 4. the press ripple ------------------------------------------- */
  var RIPPLE = '#homeHero .hh-act, #homeFeat .sc-go, .sc-btn, #bookHelp .bh-btn, #bookHelp .bh-actions > a, #bookHelp .bh-actions > button, #topHero .th-o a, #topHero .sc-th-all';
  d.addEventListener('pointerdown', function (e) {
    if (e.button > 0 || reduced()) return;
    var host = e.target && e.target.closest ? e.target.closest(RIPPLE) : null;
    if (!host) return;
    var r = host.getBoundingClientRect();
    var x = e.clientX - r.left, y = e.clientY - r.top;
    var reach = Math.sqrt(Math.pow(Math.max(x, r.width - x), 2) + Math.pow(Math.max(y, r.height - y), 2));
    var s = d.createElement('span');
    s.className = 'sc-rip';
    s.setAttribute('aria-hidden', 'true');
    s.style.left = x + 'px';
    s.style.top = y + 'px';
    s.style.setProperty('--s', (reach / 6).toFixed(1));     // the dot is 12px wide
    host.appendChild(s);
    var gone = function () { if (s.parentNode) s.parentNode.removeChild(s); };
    s.addEventListener('animationend', gone);
    setTimeout(gone, 900);
  }, { passive: true });

  /* ---- 5. sounds for the showcase's own controls ---------------------- */
  d.addEventListener('click', function (e) {
    var tgt = e.target && e.target.closest ? e.target : null;
    if (!tgt) return;
    var guide = tgt.closest('#bookHelpBtn');
    if (guide) {
      /* the inline toggle in the template has already flipped it */
      var open = guide.getAttribute('aria-expanded') === 'true';
      feel(open ? 'sheetOpen' : 'sheetClose');
      return;
    }
    if (tgt.closest('#homeHero .hh-act, #homeFeat .sc-go, .sc-btn, #bookHelp .bh-actions > *, #topHero .sc-th-all')) feel('tap');
  });

  /* ---- 6. office lists from the facts --------------------------------- */
  var WORDS = {
    en: { support: '24×7 Support', office: 'office', nepal: 'Nepal office', wa: 'WhatsApp', call: 'Call' },
    hi: { support: '24×7 सहायता', office: 'कार्यालय', nepal: 'नेपाल कार्यालय', wa: 'WhatsApp', call: 'कॉल' },
    ne: { support: '२४×७ सहायता', office: 'कार्यालय', nepal: 'नेपाल कार्यालय', wa: 'WhatsApp', call: 'फोन' }
  };
  function W() { return WORDS[lang()] || WORDS.en; }
  function officeName(o) {
    var L = lang();
    if (L === 'ne' && o.nameNe) return o.nameNe;
    if (L === 'hi' && (o.nameHi || o.nameNe)) return o.nameHi || o.nameNe;
    return o.name || o.city || '';
  }
  function npFlag(cc) {
    cc = cc === 'IN' ? 'IN' : 'NP';
    try { if (typeof window.shgFlag === 'function') return window.shgFlag(cc, 20, { label: false }); } catch (e) {}
    return '<span class="flg" data-flag="' + cc + '"></span>';
  }
  function contactRow(icon, label, phone, tel, wa) {
    var tl = digits(tel), wd = digits(wa);
    return '<div class="c-row"><span class="ico" aria-hidden="true">' + icon + '</span><div><b>' + esc(label) + '</b>'
      + (tl.length >= 10 ? '<a href="tel:+' + esc(tl) + '">' + esc(phone || ('+' + tl)) + '</a>' : (phone ? '<span>' + esc(phone) + '</span>' : ''))
      + (wd.length >= 10 ? ' <a class="wa-chip" href="https://wa.me/' + esc(wd) + '" target="_blank" rel="noopener">💬 ' + esc(W().wa) + '</a>' : '')
      + '</div></div>';
  }
  function waRow(icon, label, phone, wa, place) {
    var wd = digits(wa);
    if (wd.length < 10) return '';
    var F = window.FACTS || {}, brand = (F.brand && (F.brand.short || F.brand.name)) || 'S Hari Global';
    var hello = encodeURIComponent((wd.indexOf('977') === 0 ? 'नमस्ते 🙏 ' : 'Namaste 🙏 ') + brand + (place ? ' ' + place : ''));
    return '<a class="wa-row" href="https://wa.me/' + esc(wd) + '?text=' + hello + '" target="_blank" rel="noopener"><span>' + icon + '</span>'
      + '<div><b>' + esc(label) + '</b><small>' + esc(phone || ('+' + wd)) + '</small></div></a>';
  }
  function nepalPeople() {
    var team = [];
    try { team = (window.SHG_BOOT && Array.isArray(window.SHG_BOOT.npTeam)) ? window.SHG_BOOT.npTeam : []; } catch (e) { team = []; }
    return team.filter(function (p) { return p && p.key !== 'ceo' && digits(p.tel).length >= 10; });
  }
  function renderLists() {
    var F = window.FACTS || null;
    var offices = (F && Array.isArray(F.offices)) ? F.offices : [];
    var w = W();

    /* contact section: every open India office (head first, as listed) + the 24x7 line */
    d.querySelectorAll('[data-sc-list="offices"]').forEach(function (box) {
      var html = offices.filter(function (o) { return o && o.country !== 'NP' && o.status === 'open' && (o.tel || o.phone); })
        .map(function (o) { return contactRow('📞', officeName(o), o.phone, o.tel, o.wa || o.tel); }).join('');
      if (F && F.support && (F.support.phone || F.support.whatsapp)) {
        html += contactRow('💬', w.support, F.support.phone, F.support.tel, F.support.whatsapp);
      }
      if (!html && typeof window.contactNumbers === 'function') {      // no facts on this page: the strip's own lines
        try {
          html = window.contactNumbers().filter(function (n) { return n && n.show && digits(n.num).length >= 10; })
            .map(function (n) { return contactRow(n.wa ? '💬' : '📞', n.label, n.num, n.wa ? '' : n.num, n.wa ? n.num : ''); }).join('');
        } catch (e) { html = ''; }
      }
      box.innerHTML = html;
    });

    /* contact section, Nepal card: the office people from Admin -> Company details */
    d.querySelectorAll('[data-sc-list="nepal"]').forEach(function (box) {
      var ppl = nepalPeople();
      var html = ppl.map(function (p) { return contactRow('📞', (p.role ? p.role + ' · ' : '') + p.name, p.phone, p.tel, p.tel); }).join('');
      if (!html && F && F.nepal && F.nepal.phone) html = contactRow('📞', w.nepal, F.nepal.phone, F.nepal.tel, F.nepal.tel);
      box.innerHTML = html;
    });

    /* WhatsApp sheet: the other India offices, then the Nepal office people */
    d.querySelectorAll('[data-sc-list="wa"]').forEach(function (box) {
      var html = offices.filter(function (o) { return o && o.kind !== 'head' && o.country !== 'NP' && o.status === 'open'; })
        .map(function (o) { return waRow('🏢', officeName(o), o.phone, o.wa || o.tel, o.name); }).join('');
      var ppl = nepalPeople();
      if (ppl.length) {
        html += ppl.map(function (p) { return waRow(npFlag(p.country), p.name + (p.role ? ' · ' + p.role : ''), p.phone, p.tel, 'Nepalgunj'); }).join('');
      } else if (F && F.nepal && F.nepal.tel) {
        html += waRow(npFlag(), w.nepal, F.nepal.phone, F.nepal.tel, 'Nepalgunj');
      }
      box.innerHTML = html;
    });
    try { if (window.shgFlag && typeof window.shgFlag.scan === 'function') window.shgFlag.scan(d.querySelector('#waSheet') || d.body); } catch (e) {}
  }

  /* ---- 7. boot --------------------------------------------------------- */
  function boot() {
    reveal(d.querySelector('#topHero .th-brand'));
    reveal(d.querySelector('#homeCeo .sc-ceo'));
    reveal(d.getElementById('bookHelp'));
    reveal(d.getElementById('homeFeat'), 'sc-stagger');
    ['homeHero', 'homeFeat', 'quick-ticket', 'homeCeo', 'bookHelp'].forEach(function (id) { live(d.getElementById(id)); });
    d.querySelectorAll('#homeHero .hh-act').forEach(tilt);
    try { renderLists(); } catch (e) { /* the lists are extras - never break the page */ }
    /* the flag placeholders in the hero / CEO card / offices card / footer:
       23-flags.js fills them on its own boot, but make sure the home's are
       drawn before the first paint the visitor looks at */
    try {
      if (window.shgFlag && typeof window.shgFlag.scan === 'function') {
        ['view-home', 'siteFooter'].forEach(function (id) { var el = d.getElementById(id); if (el) window.shgFlag.scan(el); });
        var ft = d.querySelector('footer'); if (ft) window.shgFlag.scan(ft);
      }
    } catch (e) {}
    /* the CEO card's mantra follows the company setting (Admin) */
    try {
      var mt = d.querySelector('#homeCeo .sc-ceo-mantra'), mv = (typeof CONFIG !== 'undefined' && CONFIG.company && CONFIG.company.mantra) || '';
      if (mt && mv) mt.textContent = '🕉️ ' + mv + ' · शुभ यात्रा';
    } catch (e) {}
    /* the language changes <html lang>: redraw the lists in the new words */
    try {
      new MutationObserver(function () { try { renderLists(); } catch (e) {} })
        .observe(de, { attributes: true, attributeFilter: ['lang'] });
    } catch (e) {}
  }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();

  window.shgShowcase = { reveal: reveal, live: live, renderLists: renderLists, reduced: reduced };
})();
