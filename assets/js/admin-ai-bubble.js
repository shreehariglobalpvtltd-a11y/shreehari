/*
 * assets/js/admin-ai-bubble.js — S Hari AI on every staff page (16 Nov 2026, v2.0 P3-1a / C3).
 *
 * One small round button (above the phone bottom bar) opens a bottom sheet:
 * type what to change → the old → new list → "हो · Haan" writes it, "होइन · Na"
 * drops it. The same command path as Admin → S Hari AI (easy-settings.php),
 * through /admin/api/ai-settings.php; nothing changes before "Haan".
 * Self-contained (own CSS, no library), works at 360 px, light + dark.
 * The sheet's footer also links the AI assistant ("🤖 एआई सहायक · AI Sahayak",
 * admin/ai-copilot.php) beside "Full page →" when the page carries
 * <i hidden data-shg-copilot="1"></i> (P8-14b, admin_footer) - pages with this
 * bubble get no separate floating AI link.
 * Load: <script src="/assets/js/admin-ai-bubble.js" defer></script>
 */
(function () {
  'use strict';
  if (window.__shgAiBubble) return;
  window.__shgAiBubble = true;
  if (/\/admin\/easy-settings\.php$/.test(location.pathname)) return;   // that page IS the AI box

  var API = '/admin/api/ai-settings.php';
  var EXAMPLES = ['Surat ko bhada 2300', 'bholi 1 baje jane bus band', 'Seat U15 block karo kal'];
  // 16 Nov 2026 (v2.0 P8-14b): admin_footer() prints <i hidden data-shg-copilot="1"> when this
  // person may open the AI assistant (admin/ai-copilot.php, bookings.view); a deferred script
  // runs after the whole page is parsed, so the marker is already there
  var COPILOT = !!document.querySelector('[data-shg-copilot="1"]') && !/\/admin\/ai-copilot\.php$/.test(location.pathname);

  function csrf() {
    var m = document.querySelector('meta[name="shg-admin-csrf"]') || document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') || '' : '';
  }
  if (!csrf()) return;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  var style = el('style');
  style.id = 'shg-aib-css';
  style.textContent =
    // --admin-fab-bottom (admin_css): 88px where a page has a bottom dock (Home), else 16px
    '.shg-aib-fab{position:fixed;right:16px;bottom:max(24px,var(--admin-fab-bottom,24px));z-index:50;width:52px;height:52px;border-radius:50%;border:0;cursor:pointer;' +
      'background:var(--navy,#12264E);color:#fff;font:700 15px/1 system-ui,-apple-system,"Segoe UI",sans-serif;letter-spacing:.3px;' +
      'box-shadow:0 6px 18px rgba(11,18,32,.35);display:flex;align-items:center;justify-content:center}' +
    '.shg-aib-fab:focus-visible{outline:3px solid var(--orange,#F07C1F);outline-offset:2px}' +
    '@media (max-width:820px){.shg-aib-fab{bottom:max(calc(88px + env(safe-area-inset-bottom,0px)),var(--admin-fab-bottom,0px));right:14px}}' +
    '@media print{.shg-aib-fab,.shg-aib-scrim,.shg-aib-sheet{display:none!important}}' +
    '.shg-aib-scrim{position:fixed;inset:0;z-index:10040;background:rgba(0,0,0,.4)}' +
    '.shg-aib-sheet{position:fixed;left:0;right:0;bottom:0;z-index:10050;margin:0 auto;max-width:560px;max-height:85vh;overflow:auto;' +
      'background:var(--card,#fff);color:var(--ink,#1b2436);border-radius:18px 18px 0 0;box-shadow:0 -8px 30px rgba(0,0,0,.25);' +
      'padding:12px 16px calc(16px + env(safe-area-inset-bottom,0px));font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;box-sizing:border-box}' +
    '.shg-aib-sheet *{box-sizing:border-box}' +
    '.shg-aib-hd{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px}' +
    '.shg-aib-hd b{display:block;font-size:16px}.shg-aib-hd small{color:var(--mut,#6b7688);font-size:12.5px}' +
    '.shg-aib-x{margin-left:auto;flex:0 0 auto;width:40px;height:40px;border-radius:10px;border:1px solid var(--line,#e5e9f0);background:transparent;color:inherit;font-size:18px;cursor:pointer}' +
    '.shg-aib-row{display:flex;gap:8px}' +
    '.shg-aib-in{flex:1 1 auto;min-width:0;min-height:48px;font-size:16px;padding:8px 12px;border-radius:12px;border:1.5px solid var(--line,#c9d3e3);background:var(--bg,#f4f6fb);color:inherit}' +
    '.shg-aib-go{flex:0 0 auto;min-height:48px;padding:0 14px;border-radius:12px;border:0;background:var(--blue,#2E5FA8);color:#fff;font-weight:700;font-size:15px;cursor:pointer}' +
    '.shg-aib-go[disabled],.shg-aib-yes[disabled]{opacity:.55;cursor:default}' +
    '.shg-aib-ex{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}' +
    '.shg-aib-ex button{min-height:36px;border:1px dashed var(--line,#c9d3e3);background:transparent;color:inherit;border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer}' +
    '.shg-aib-msg{margin-top:10px;font-size:14px;white-space:pre-line}.shg-aib-msg:empty{display:none}' +
    '.shg-aib-msg.bad{color:#B91C1C}.shg-aib-msg.ok{color:#15803D}' +
    '.shg-aib-prop{margin-top:10px;border:1.5px solid #F0B44C;background:#FFF8E6;color:#3A2A00;border-radius:14px;padding:10px 12px}' +
    '.shg-aib-prop ul{margin:6px 0 10px;padding-left:18px}.shg-aib-prop li{margin:4px 0;word-break:break-word}' +
    '.shg-aib-btns{display:flex;gap:8px;flex-wrap:wrap}' +
    '.shg-aib-btns button{flex:1 1 140px;min-height:46px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer}' +
    '.shg-aib-yes{border:0;background:#16A34A;color:#fff}.shg-aib-no{border:1px solid #c9b27a;background:transparent;color:#3A2A00}' +
    '.shg-aib-ft{margin-top:12px;font-size:12.5px;color:var(--mut,#6b7688);display:flex;gap:0 12px;flex-wrap:wrap;align-items:center}' +
    '.shg-aib-ft a{color:var(--blue,#2E5FA8)}' +
    // the two footer links sit side by side, never stacked: one row, each shrinks with … at 360 px
    '.shg-aib-ftl{display:flex;flex-wrap:nowrap;gap:12px;flex:1 1 auto;min-width:0;max-width:100%}' +
    '.shg-aib-ftl a{display:block;flex:0 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:10px 0;line-height:20px}' +
    '[data-theme="dark"] .shg-aib-ft a{color:#9cc0ff}' +
    '[data-theme="dark"] .shg-aib-prop{background:#2a2312;color:#f5e6c4;border-color:#a8822f}[data-theme="dark"] .shg-aib-no{color:#f5e6c4}';
  document.head.appendChild(style);

  var fab = el('button', 'shg-aib-fab', 'AI');
  fab.type = 'button';
  fab.title = 'S Hari AI · बोलेर सेटिङ बदल्नुहोस्';
  fab.setAttribute('aria-label', 'S Hari AI — सेटिङ बदल्नुहोस् · change a setting');
  fab.setAttribute('aria-haspopup', 'dialog');

  var scrim = el('div', 'shg-aib-scrim');
  var sheet = el('div', 'shg-aib-sheet');
  sheet.setAttribute('role', 'dialog');
  sheet.setAttribute('aria-modal', 'true');
  sheet.setAttribute('aria-labelledby', 'shg-aib-title');

  var hd = el('div', 'shg-aib-hd');
  var ttl = el('div');
  var tb = el('b', '', 'S Hari AI · सेटिङ (बोलेर)');
  tb.id = 'shg-aib-title';
  ttl.appendChild(tb);
  ttl.appendChild(el('small', '', 'भन्नुहोस् → पहिले देखाउँछ → "हो" पछि मात्र बदल्छ · Say it, check it, then Yes'));
  var x = el('button', 'shg-aib-x', '✕');
  x.type = 'button';
  x.setAttribute('aria-label', 'बन्द · Close');
  hd.appendChild(ttl); hd.appendChild(x);

  var form = el('form', 'shg-aib-row');
  var input = el('input', 'shg-aib-in');
  input.type = 'text';
  input.maxLength = 400;
  input.autocomplete = 'off';
  input.placeholder = 'जस्तै: Surat ko bhada 2300';
  input.setAttribute('aria-label', 'के बदल्ने? · What to change');
  var go = el('button', 'shg-aib-go', 'पठाउनुहोस् →');
  go.type = 'submit';
  form.appendChild(input); form.appendChild(go);

  var ex = el('div', 'shg-aib-ex');
  EXAMPLES.forEach(function (t) {
    var b = el('button', '', t);
    b.type = 'button';
    b.addEventListener('click', function () { input.value = t; input.focus(); });
    ex.appendChild(b);
  });

  var msg = el('div', 'shg-aib-msg');
  msg.setAttribute('role', 'status');
  msg.setAttribute('aria-live', 'polite');

  var prop = el('div', 'shg-aib-prop');
  prop.hidden = true;
  var pTitle = el('b', '', 'यो बदल्ने हो? · Change this?');
  var list = el('ul');
  var btns = el('div', 'shg-aib-btns');
  var yes = el('button', 'shg-aib-yes', '✅ हो · Haan');
  yes.type = 'button';
  var no = el('button', 'shg-aib-no', '✖ होइन · Na');
  no.type = 'button';
  btns.appendChild(yes); btns.appendChild(no);
  prop.appendChild(pTitle); prop.appendChild(list); prop.appendChild(btns);

  var ft = el('div', 'shg-aib-ft');
  var links = el('div', 'shg-aib-ftl');
  var full = el('a', '', 'पूरा पेज · Full page →');
  full.href = '/admin/easy-settings.php';
  links.appendChild(full);
  if (COPILOT) {
    var sahayak = el('a', 'shg-aib-copilot', '🤖 एआई सहायक · AI Sahayak');
    sahayak.href = '/admin/ai-copilot.php';
    sahayak.title = 'एआई सहायक · AI Sahayak';
    links.appendChild(sahayak);
  }
  var reload = el('a', '', '↻ पेज ताजा गर्नुहोस् · Reload page');
  reload.href = '#';
  reload.hidden = true;
  reload.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
  ft.appendChild(links); ft.appendChild(reload);

  sheet.appendChild(hd); sheet.appendChild(form); sheet.appendChild(ex); sheet.appendChild(msg); sheet.appendChild(prop); sheet.appendChild(ft);

  var pid = null, busy = false;

  function say(text, kind) {
    msg.className = 'shg-aib-msg' + (kind ? ' ' + kind : '');
    msg.textContent = text || '';
  }

  function post(body, cb) {
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 60000);
    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
      body: JSON.stringify(body),
      signal: ctl ? ctl.signal : undefined
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'HTTP ' + r.status }; });
    }).then(function (j) {
      clearTimeout(timer); cb(j || { ok: false });
    }).catch(function () {
      clearTimeout(timer); cb({ ok: false, message: 'नेटवर्क मिलेन वा धेरै ढिलो भयो · Network error or too slow — try again.' });
    });
  }

  function showProposal(rows) {
    list.innerHTML = '';
    rows.forEach(function (c) {
      var li = el('li');
      li.appendChild(document.createTextNode(String(c.what) + ': '));
      li.appendChild(el('s', '', String(c.old)));
      li.appendChild(document.createTextNode(' → '));
      li.appendChild(el('b', '', String(c['new'])));
      list.appendChild(li);
    });
    prop.hidden = false;
    yes.disabled = false;
    yes.focus();
  }

  function reset() {
    pid = null;
    prop.hidden = true;
    list.innerHTML = '';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var cmd = input.value.trim();
    if (!cmd || busy) return;
    if (pid) post({ cancel: pid }, function () {});   // a new sentence drops the old proposal
    reset();
    busy = true; go.disabled = true;
    say('बुझ्दै छ… · Reading…');
    var slow = setTimeout(function () { if (busy) say('AI ले बुझ्दै छ, केही सेकेन्ड लाग्छ… · The AI is reading it, this can take a few seconds…'); }, 2500);
    post({ cmd: cmd }, function (j) {
      clearTimeout(slow);
      busy = false; go.disabled = false;
      if (j.ok && j.proposal_id && j.changes && j.changes.length) {
        pid = j.proposal_id;
        say('');
        showProposal(j.changes);
      } else {
        say(j.message || 'बुझिएन · Not understood.', j.ok ? '' : 'bad');
      }
    });
  });

  yes.addEventListener('click', function () {
    if (!pid || busy) return;
    busy = true; yes.disabled = true;
    say('बदल्दै… · Saving…');
    var id = pid;
    post({ confirm: id }, function (j) {
      busy = false;
      reset();
      say(j.message || (j.ok ? 'Done' : 'Not saved'), j.ok && j.success !== false ? 'ok' : 'bad');
      if (j.ok) { reload.hidden = false; input.value = ''; }
    });
  });

  no.addEventListener('click', function () {
    if (!pid) return;
    var id = pid;
    reset();
    say('✖ केही बदलिएन · Nothing changed.');
    post({ cancel: id }, function () {});
    input.focus();
  });

  function openSheet() {
    if (!sheet.parentNode) { document.body.appendChild(scrim); document.body.appendChild(sheet); }
    scrim.hidden = false; sheet.hidden = false;
    fab.setAttribute('aria-expanded', 'true');
    setTimeout(function () { input.focus(); }, 30);
  }
  function closeSheet() {
    scrim.hidden = true; sheet.hidden = true;
    fab.setAttribute('aria-expanded', 'false');
    fab.focus();
  }
  fab.addEventListener('click', openSheet);
  x.addEventListener('click', closeSheet);
  scrim.addEventListener('click', closeSheet);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sheet.parentNode && !sheet.hidden) { e.preventDefault(); closeSheet(); }
  });

  function mount() { document.body.appendChild(fab); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
