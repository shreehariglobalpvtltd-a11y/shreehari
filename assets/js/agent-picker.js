/* assets/js/agent-picker.js — type-ahead over an agent <select> (5 Oct 2026).
 *
 * Owner: "agent code type garnu pardaina — list bata search garera select".
 * shgAgentPicker(selectEl) puts a search box over an existing agent <select>:
 * type "Mah" and the list narrows to "Mahabu Basi · SHG-0006 · Surat". The
 * <select> stays in the page as the single source of truth — the picker only
 * writes its value and fires `change`, so every script that already reads
 * select.value (the counter checkout, Quick Ticket, the remembered agent)
 * keeps working untouched, and without this file the plain select still does.
 *
 * Each <option> may carry data-name / data-city / data-co; without them the
 * name is read from the option text ("👤 SHG-0006 · Name").
 */
(function () {
  'use strict';
  if (window.shgAgentPicker) return;

  var CSS = ''
    + '.shg-ap{position:relative;width:100%;font:inherit}'
    + '.shg-ap-in{width:100%;box-sizing:border-box;min-height:48px;padding:10px 40px 10px 38px;font-size:16px;font-family:inherit;font-weight:600;'
    + 'border:1.5px solid var(--line,#dde3ee);border-radius:12px;background:var(--card,#fff);color:inherit;outline:0;'
    + 'box-shadow:inset 0 2px 4px rgba(16,38,77,.06);transition:border-color .15s,box-shadow .15s}'
    + '.shg-ap-in:focus{border-color:#2F6FD0;box-shadow:0 0 0 3px rgba(47,111,208,.22)}'
    + '.shg-ap.invalid .shg-ap-in{border-color:#DC3B3B;box-shadow:0 0 0 3px rgba(220,59,59,.18)}'
    + '.shg-ap.has .shg-ap-in{border-color:#1FA35B}'
    + '.shg-ap-ic{position:absolute;left:12px;top:24px;transform:translateY(-50%);font-size:15px;pointer-events:none;opacity:.7}'
    + '.shg-ap.has .shg-ap-ic{opacity:1}'
    + '.shg-ap-x{position:absolute;right:4px;top:4px;width:40px;height:40px;border:0;border-radius:10px;background:transparent;color:inherit;font-size:20px;line-height:1;cursor:pointer;opacity:.6}'
    + '.shg-ap-x[hidden]{display:none}'
    + '.shg-ap-list{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:1200;margin:0;padding:6px;list-style:none;max-height:min(300px,46vh);overflow:auto;'
    + 'border-radius:14px;background:var(--card,#fff);color:var(--ink,#16233c);border:1px solid var(--line,#dde3ee);'
    + 'box-shadow:0 4px 0 rgba(16,38,77,.10),0 16px 34px rgba(16,38,77,.24);-webkit-overflow-scrolling:touch}'
    + '.shg-ap-list[hidden]{display:none}'
    + '.shg-ap-o{display:grid;grid-template-columns:10px minmax(0,1fr) auto;gap:2px 9px;align-items:center;min-height:46px;padding:6px 10px;border-radius:10px;cursor:pointer}'
    + '.shg-ap-o[aria-selected="true"],.shg-ap-o:hover{background:rgba(47,111,208,.12)}'
    + '.shg-ap-dot{width:9px;height:9px;border-radius:50%;background:#1FA35B;box-shadow:0 0 0 3px rgba(31,163,91,.18);grid-row:1 / 3}'
    + '.shg-ap-o b{font-size:14.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.shg-ap-code{font:700 12px/1 ui-monospace,Consolas,monospace;padding:4px 7px;border-radius:7px;background:rgba(47,111,208,.14);color:#1c4fa3;white-space:nowrap}'
    + '.shg-ap-o small{grid-column:2 / 4;font-size:12px;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.shg-ap-none{padding:12px 10px;font-size:13.5px;opacity:.75}'
    + ':root[data-theme="dark"] .shg-ap-code{background:rgba(120,170,255,.18);color:#9cc0ff}'
    + '.shg-ap-sel{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important;left:0;bottom:0}';

  function addCss() {
    if (document.getElementById('shgApCss')) return;
    var s = document.createElement('style');
    s.id = 'shgApCss';
    s.textContent = CSS;
    document.head.appendChild(s);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function norm(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9ऀ-ॿ]+/g, ' ').trim(); }

  function readOptions(sel) {
    var out = [];
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];
      if (o.value === '') continue;
      var text = o.textContent || '';
      var other = o.value === 'OTHER';
      var name = o.getAttribute('data-name') || (text.indexOf(' · ') > -1 ? text.split(' · ').slice(1).join(' · ') : text);
      var code = other ? '' : o.value;
      var city = o.getAttribute('data-city') || '';
      var co = o.getAttribute('data-co') === '1' || text.indexOf('🏢') === 0;
      var num = code.replace(/\D/g, '').replace(/^0+/, '');
      out.push({ value: o.value, name: name.trim(), code: code, city: city, co: co, other: other,
        hay: norm(name + ' ' + code + ' ' + city + ' ' + num + (co ? ' company office direct' : '')) });
    }
    return out;
  }

  window.shgAgentPicker = function (sel) {
    if (!sel || sel.tagName !== 'SELECT' || sel.getAttribute('data-ap') === '1') return null;
    sel.setAttribute('data-ap', '1');
    addCss();

    var wrap = document.createElement('div');
    wrap.className = 'shg-ap';
    var uid = 'shgAp' + Math.random().toString(36).slice(2, 8);
    wrap.innerHTML = '<span class="shg-ap-ic" aria-hidden="true">🔍</span>'
      + '<input class="shg-ap-in" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' + uid + '" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done"'
      + ' placeholder="एजेन्ट खोज्नुहोस् · type name / code / city" aria-label="' + esc(sel.getAttribute('aria-label') || 'Agent (required)') + '">'
      + '<button type="button" class="shg-ap-x" aria-label="Clear agent" hidden>×</button>'
      + '<ul class="shg-ap-list" id="' + uid + '" role="listbox" hidden></ul>';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('shg-ap-sel');
    sel.tabIndex = -1;

    var input = wrap.querySelector('.shg-ap-in'), list = wrap.querySelector('.shg-ap-list'),
        clear = wrap.querySelector('.shg-ap-x'), icon = wrap.querySelector('.shg-ap-ic');
    var shown = [], active = -1, painted = null;

    function labelOf(o) { return o.name + (o.code ? ' · ' + o.code : '') + (o.city ? ' · ' + o.city : ''); }
    function paint() {
      var cur = null, all = readOptions(sel);
      for (var i = 0; i < all.length; i++) { if (all[i].value === sel.value) { cur = all[i]; break; } }
      painted = sel.value;
      input.value = cur ? labelOf(cur) : '';
      wrap.classList.toggle('has', !!cur);
      icon.textContent = cur ? '✅' : '🔍';
      clear.hidden = !cur;
      if (cur) wrap.classList.remove('invalid');
    }
    function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; }
    function open(q) {
      var all = readOptions(sel), words = norm(q).split(' ').filter(Boolean);
      shown = all.filter(function (o) { return words.every(function (w) { return o.hay.indexOf(w) > -1; }); }).slice(0, 60);
      list.innerHTML = shown.length
        ? shown.map(function (o, i) {
            return '<li class="shg-ap-o" role="option" id="' + uid + '_' + i + '" data-i="' + i + '" aria-selected="false">'
              + '<span class="shg-ap-dot"></span><b>' + (o.co ? '🏢 ' : '') + esc(o.name) + '</b>'
              + (o.code ? '<span class="shg-ap-code">' + esc(o.code) + '</span>' : '<span></span>')
              + '<small>' + esc(o.other ? 'Direct sale — company' : (o.city || (o.co ? 'Company code · direct sale' : 'Active agent'))) + '</small></li>';
          }).join('')
        : '<li class="shg-ap-none">कुनै एजेन्ट भेटिएन · No agent matches “' + esc(q) + '”</li>';
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      active = shown.length === 1 ? 0 : -1;
      mark();
    }
    function mark() {
      var rows = list.querySelectorAll('.shg-ap-o');
      for (var i = 0; i < rows.length; i++) rows[i].setAttribute('aria-selected', i === active ? 'true' : 'false');
      if (active > -1 && rows[active]) {
        input.setAttribute('aria-activedescendant', rows[active].id);
        if (rows[active].scrollIntoView) rows[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }
    function choose(value) {
      if (sel.value !== value) {
        sel.value = value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      paint();
      close();
    }

    input.addEventListener('focus', function () { input.select(); open(''); });
    input.addEventListener('input', function () { open(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (list.hidden) { open(''); }
        if (!shown.length) return;
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
        mark(); e.preventDefault();
      } else if (e.key === 'Enter') {
        if (!list.hidden && shown.length) { choose(shown[active > -1 ? active : 0].value); input.blur(); e.preventDefault(); }
      } else if (e.key === 'Escape') {
        close(); paint();
      }
    });
    input.addEventListener('blur', function () { setTimeout(function () { close(); paint(); }, 160); });
    /* pointerdown, not click: the pick must land before the input's blur closes the list */
    list.addEventListener('pointerdown', function (e) {
      var li = e.target.closest ? e.target.closest('.shg-ap-o') : null;
      if (!li) return;
      e.preventDefault();
      choose(shown[+li.getAttribute('data-i')].value);
      input.blur();
    });
    clear.addEventListener('click', function () { choose(''); input.focus(); });
    /* scripts that focus / flag the select itself (a "select the agent first" toast) reach the box instead */
    sel.addEventListener('focus', function () { input.focus(); });
    sel.addEventListener('change', paint);
    if (window.MutationObserver) {
      new MutationObserver(function () { wrap.classList.toggle('invalid', sel.classList.contains('invalid')); })
        .observe(sel, { attributes: true, attributeFilter: ['class'] });
    }
    /* a value set from script (the remembered agent) fires no event - keep the box in step */
    var tick = setInterval(function () {
      if (!sel.isConnected) { clearInterval(tick); return; }
      if (document.activeElement !== input && painted !== sel.value) paint();
    }, 500);

    paint();
    return { sync: paint, input: input };
  };
})();
