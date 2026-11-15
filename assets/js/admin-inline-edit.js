/*
 * assets/js/admin-inline-edit.js — click a value, type, Enter (16 Nov 2026, v2.0 P3-6).
 *
 * Mark any value on a staff page:
 *   <span data-ie-entity="route_stop" data-ie-field="fare" data-ie-id="406"
 *         data-ie-value="2200" data-ie-type="money" data-ie-confirm="1">₹2,200</span>
 *   data-ie-type    money | time | text
 *   data-ie-confirm "1" = ask "old → new?" before saving (fares)
 *   data-ie-empty   what to show when the value is cleared (default: "default" for money, "—")
 * and load <script src="/assets/js/admin-inline-edit.js" defer></script>.
 *
 * Click (or Enter on the focused value) → an input; Enter saves through
 * /admin/api/inline-save.php (JSON + the staff CSRF token), Esc cancels,
 * a 3-second note says what happened. The element fires "shg:inline-saved"
 * with {old, new} so a page can update a total. No library.
 */
(function () {
  'use strict';
  if (window.__shgInlineEdit) return;
  window.__shgInlineEdit = true;

  var API = '/admin/api/inline-save.php';

  function csrf() {
    var m = document.querySelector('meta[name="shg-admin-csrf"]') || document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') || '' : '';
  }

  function css() {
    if (document.getElementById('shg-ie-css')) return;
    var s = document.createElement('style');
    s.id = 'shg-ie-css';
    s.textContent =
      '[data-ie-entity]{cursor:pointer;border-bottom:1px dashed var(--mut,#6b7688);border-radius:2px}' +
      '[data-ie-entity]:hover,[data-ie-entity]:focus-visible{background:var(--hover,#eef2fa);outline:none}' +
      '[data-ie-entity].ie-busy{opacity:.55;pointer-events:none}' +
      '.ie-box{display:inline-flex;align-items:center;gap:4px;max-width:100%}' +
      '.ie-box input{font:inherit;font-size:16px;min-height:36px;width:9em;max-width:60vw;padding:4px 8px;border:1.5px solid var(--blue,#2E5FA8);' +
        'border-radius:8px;background:var(--card,#fff);color:var(--ink,#1b2436)}' +
      '.ie-box input[type=time]{width:7.5em}.ie-box input.ie-wide{width:14em}' +
      '.ie-box button{font:inherit;font-size:14px;min-width:36px;min-height:36px;border-radius:8px;border:1px solid var(--line,#e5e9f0);' +
        'background:var(--card,#fff);color:var(--ink,#1b2436);cursor:pointer;padding:0 8px}' +
      '.ie-box button.ie-ok{background:#16A34A;border-color:#16A34A;color:#fff}' +
      '#shg-ie-toast{position:fixed;left:50%;bottom:calc(96px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:10060;' +
        'max-width:calc(100vw - 32px);padding:10px 16px;border-radius:12px;font-size:14px;line-height:1.4;color:#fff;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.25);transition:opacity .2s;pointer-events:none}' +
      '#shg-ie-toast.ok{background:#15803D}#shg-ie-toast.bad{background:#B91C1C}' +
      '@media (min-width:821px){#shg-ie-toast{bottom:28px}}';
    document.head.appendChild(s);
  }

  var toastTimer = 0;
  function toast(msg, ok) {
    var t = document.getElementById('shg-ie-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'shg-ie-toast';
      t.setAttribute('role', 'status');
      t.setAttribute('aria-live', 'polite');
      document.body.appendChild(t);
    }
    t.className = ok ? 'ok' : 'bad';
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.style.opacity = '0'; }, 3000);
  }

  function shown(el, raw) {
    var type = el.getAttribute('data-ie-type') || 'text';
    var empty = el.getAttribute('data-ie-empty');
    if (raw === null || raw === undefined || raw === '') {
      return empty !== null ? empty : (type === 'money' ? 'default' : '—');
    }
    if (type === 'money') {
      var n = Number(raw);
      return isFinite(n) ? '₹' + n.toLocaleString('en-IN', { maximumFractionDigits: 2 }) : String(raw);
    }
    if (type === 'time') return String(raw).slice(0, 5);
    return String(raw);
  }

  function open(el) {
    if (el.classList.contains('ie-open') || el.classList.contains('ie-busy')) return;
    css();
    var type = el.getAttribute('data-ie-type') || 'text';
    var before = el.innerHTML;
    var cur = el.getAttribute('data-ie-value') || '';
    el.classList.add('ie-open');

    var box = document.createElement('span');
    box.className = 'ie-box';
    var inp = document.createElement('input');
    if (type === 'time') {
      inp.type = 'time';
      inp.value = cur.slice(0, 5);
    } else {
      inp.type = 'text';
      inp.value = cur;
      if (type === 'money') { inp.inputMode = 'decimal'; inp.placeholder = 'खाली = default'; }
      else { inp.className = 'ie-wide'; inp.maxLength = 191; }
    }
    inp.setAttribute('aria-label', el.getAttribute('title') || 'Edit');
    var ok = document.createElement('button');
    ok.type = 'button'; ok.className = 'ie-ok'; ok.textContent = '✓'; ok.title = 'Save (Enter)';
    var no = document.createElement('button');
    no.type = 'button'; no.textContent = '✕'; no.title = 'Cancel (Esc)';
    box.appendChild(inp); box.appendChild(ok); box.appendChild(no);
    el.innerHTML = '';
    el.appendChild(box);
    inp.focus();
    if (inp.select) { try { inp.select(); } catch (e) { /* time inputs */ } }

    var done = false;
    function close(html) {
      if (done) return;
      done = true;
      el.innerHTML = html;
      el.classList.remove('ie-open');
    }
    // focus back on the value after Esc / ✕ only — a blur-close must not pull focus off what was tapped next
    function cancel(refocus) { close(before); if (refocus !== false) el.focus(); }
    function save() {
      var val = inp.value.trim();
      if (val === cur.trim()) { cancel(); return; }
      if (el.getAttribute('data-ie-confirm') === '1'
          && !window.confirm(shown(el, cur) + ' → ' + shown(el, val) + ' ?\n\nबदल्ने हो? · Save this change?')) {
        return;
      }
      close(before);
      el.classList.add('ie-busy');
      fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
        body: JSON.stringify({
          entity: el.getAttribute('data-ie-entity'),
          field: el.getAttribute('data-ie-field'),
          id: el.getAttribute('data-ie-id'),
          value: val
        })
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, message: 'HTTP ' + r.status }; });
      }).then(function (j) {
        el.classList.remove('ie-busy');
        if (!j || !j.ok) { toast((j && j.message) || 'Not saved', false); return; }
        var raw = j['new'] === null || j['new'] === undefined ? '' : String(j['new']);
        el.setAttribute('data-ie-value', raw);
        el.textContent = shown(el, raw);
        toast(j.message || 'Saved', true);
        try { el.dispatchEvent(new CustomEvent('shg:inline-saved', { bubbles: true, detail: { old: j.old, 'new': j['new'] } })); } catch (e) { /* old browsers */ }
      }).catch(function () {
        el.classList.remove('ie-busy');
        toast('नेटवर्क मिलेन · Network error — not saved', false);
      });
    }
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); save(); }
      else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
    // the ✓ / ✕ taps must not first blur the input into a cancel
    [ok, no].forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); });
    ok.addEventListener('click', function (e) { e.stopPropagation(); save(); });
    no.addEventListener('click', function (e) { e.stopPropagation(); cancel(); });
    inp.addEventListener('click', function (e) { e.stopPropagation(); });
    inp.addEventListener('blur', function () {
      // leaving an unchanged box closes it; a typed value stays until Enter / ✓ / Esc
      setTimeout(function () { if (!done && inp.value.trim() === cur.trim() && document.activeElement !== inp) cancel(false); }, 150);
    });
  }

  function arm(root) {
    var list = (root || document).querySelectorAll('[data-ie-entity]:not([data-ie-armed])');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      el.setAttribute('data-ie-armed', '1');
      if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
      el.setAttribute('role', 'button');
      if (!el.title) el.title = 'ट्याप गरेर बदल्नुहोस् · Tap to edit';
    }
    if (list.length) css();
  }

  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-ie-entity]') : null;
    if (!el || el.classList.contains('ie-open')) return;
    e.preventDefault();
    open(el);
  });
  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && el && el.hasAttribute && el.hasAttribute('data-ie-entity') && !el.classList.contains('ie-open')) {
      e.preventDefault();
      open(el);
    }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { arm(); });
  else arm();
  window.shgInlineEditArm = arm;   // a page that draws rows later calls this
})();
