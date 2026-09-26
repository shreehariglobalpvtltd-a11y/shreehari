/* =====================================================================
   21-me.js — "mero parichaya": the round photo, the flag, the name.

   Owner, 26 Sep 2026: "jo jo le first time kholcha tesko profile pic, name,
   number … photo offline mobile ma safe hos, suru ma sano golo frame bhaye
   ra dekhos, ani photo ko agadi flag hos — India ni Nepal ko select garna
   milos."

   So three things, and one rule.

   THE RULE — the photo never leaves the handset. It is scaled down in the
   browser and kept in this device's own localStorage. It is not uploaded, it
   is not in any backup, it is not on the VPS, and no other device or member
   of staff can see it. The NAME and the NUMBER are the account and do live on
   the server, exactly as before. That is the split the owner asked for, and
   it is also the honest one: a face photo is the one piece of a passenger we
   have no business keeping in a database we back up nightly.

   THE FLAG is not decoration. India and Nepal share the 10-digit mobile
   format, so a bare number carries no country and the ticket's WhatsApp has
   to be told which one — that is why bookings carry contact_country_code.
   Tapping the flag therefore does a real thing: it re-signs the account in
   with the chosen country, so users.country_code is corrected and the next
   ticket goes to the right country.

   Every read and write of localStorage is wrapped: a private window or a
   phone with site data blocked must fall back to the initial letter, never
   throw on the way into the sign-in modal.
   ===================================================================== */
(function () {
  'use strict';

  var PHOTO_KEY = 'shg:me:photo';
  var MAX_PX    = 256;      // a 44px circle on a 3x screen, no bigger
  var STYLE_ID  = 'me-av-css';

  /* The photo is filed under the NUMBER, not under the browser. A counter
     machine and a family phone are both shared, and the previous person's
     face must not sit on the next person's card. Before sign-in there is no
     number yet, so a photo added in the sheet is a DRAFT under the bare key
     and is promoted to the account the moment one is known. */
  function keyFor() {
    var u  = me();
    var ph = (u && u.phone) ? String(u.phone).replace(/\D/g, '') : '';
    return ph ? PHOTO_KEY + ':' + ph : PHOTO_KEY;
  }
  function readPhoto() {
    try {
      var k = keyFor();
      var v = localStorage.getItem(k) || '';
      if (!v && k !== PHOTO_KEY) {
        /* Promote the draft taken before the number was typed. */
        var draft = localStorage.getItem(PHOTO_KEY) || '';
        if (draft) {
          localStorage.setItem(k, draft);
          localStorage.removeItem(PHOTO_KEY);
          v = draft;
        }
      }
      return v;
    } catch (e) { return ''; }
  }
  function writePhoto(dataUrl) {
    try {
      var k = keyFor();
      if (dataUrl) { localStorage.setItem(k, dataUrl); }
      else { localStorage.removeItem(k); }
    } catch (e) { /* private window / quota — the initial still shows */ }
  }

  function me() { return (typeof USER !== 'undefined' && USER) ? USER : null; }

  function countryOf() {
    var u = me();
    return (u && u.country === 'NP') ? 'NP' : 'IN';
  }

  /* Drawn, not typed. The flag emoji is a pair of regional-indicator letters
     and Windows ships no glyph for it, so a desk PC (and the owner's own
     laptop) renders "NP" / "IN" as plain letters where a phone shows a flag.
     These two circles look the same everywhere. */
  function flagSvg(cc, px) {
    if (cc === 'NP') {
      return '<svg viewBox="0 0 24 24" width="' + px + '" height="' + px + '" aria-hidden="true">' +
        '<circle cx="12" cy="12" r="11" fill="#DC143C" stroke="#003893" stroke-width="2"/>' +
        '<path d="M12 4.2a3.6 3.6 0 1 0 2.8 5.9A4.4 4.4 0 0 1 12 4.2z" fill="#fff"/>' +
        '<circle cx="12" cy="16.2" r="2.6" fill="#fff"/>' +
        '</svg>';
    }
    return '<svg viewBox="0 0 24 24" width="' + px + '" height="' + px + '" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="11" fill="#fff" stroke="#cbd5e1" stroke-width="1"/>' +
      '<path d="M12 1a11 11 0 0 1 10.1 6.7H1.9A11 11 0 0 1 12 1z" fill="#FF9933"/>' +
      '<path d="M1.9 16.3h20.2A11 11 0 0 1 12 23a11 11 0 0 1-10.1-6.7z" fill="#138808"/>' +
      '<circle cx="12" cy="12" r="3.1" fill="none" stroke="#000080" stroke-width="1.5"/>' +
      '</svg>';
  }

  function initial() {
    var u = me();
    var n = (u && u.name ? String(u.name) : '').trim();
    if (n) { return n.charAt(0).toUpperCase(); }
    var p = (u && u.phone ? String(u.phone) : '').trim();
    return p ? p.charAt(0) : '👤';
  }

  function injectCss() {
    if (document.getElementById(STYLE_ID)) { return; }
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent =
      '.me-av{position:relative;display:inline-block;flex:0 0 auto;line-height:0}' +
      '.me-av>.me-ring{display:block;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#1e3a8a,#3b82f6);' +
        'border:2px solid var(--card,#fff);box-shadow:0 2px 8px rgba(15,23,42,.18)}' +
      '.me-av>.me-ring>img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.me-av>.me-ring>span{display:flex;align-items:center;justify-content:center;width:100%;height:100%;' +
        'color:#fff;font-weight:800;letter-spacing:.01em}' +
      '.me-av>.me-flag{position:absolute;left:-4px;bottom:-4px;background:var(--card,#fff);border-radius:50%;' +
        'line-height:1;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 4px rgba(15,23,42,.25)}' +
      '.me-av>select.me-flag{-webkit-appearance:none;appearance:none;border:0;padding:0;text-align:center;cursor:pointer;' +
        'font-family:inherit;color:transparent;text-shadow:none}' +
      '.me-av>.me-cam{position:absolute;right:-4px;bottom:-4px;width:22px;height:22px;border-radius:50%;border:0;cursor:pointer;' +
        'background:#f97316;color:#fff;font-size:11px;line-height:22px;padding:0;box-shadow:0 1px 4px rgba(15,23,42,.25)}' +
      '.me-card{display:flex;align-items:center;gap:12px}' +
      '.me-card .me-who{min-width:0}' +
      '.me-card .me-who b{display:block;font-size:15px;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.me-card .me-who small{display:block;color:var(--muted,#64748b);font-size:12px}' +
      '.me-hint{font-size:11.5px;color:var(--muted,#64748b);margin-top:6px;text-align:center}';
    document.head.appendChild(s);
  }

  /* The circle itself. `size` is the diameter in px; `opts.pick` adds the
     camera button, `opts.flagSelect` makes the flag a real chooser. */
  function avatarHtml(size, opts) {
    injectCss();
    opts = opts || {};
    var px  = Math.max(28, size || 44);
    var ph  = readPhoto();
    var fs  = Math.round(px * 0.34);
    var inner = ph
      ? '<img src="' + ph + '" alt="">'
      : '<span style="font-size:' + Math.round(px * 0.42) + 'px">' + initial() + '</span>';
    var badge = Math.round(fs + 8);
    var mark  = '<i class="me-flag" aria-hidden="true" style="width:' + badge + 'px;height:' + badge + 'px;font-style:normal' +
                (opts.flagSelect ? ';pointer-events:none' : '') + '">' + flagSvg(countryOf(), badge - 4) + '</i>';
    var flag = opts.flagSelect
      ? '<select class="me-flag" data-me-flag aria-label="Country / देश" style="width:' + badge + 'px;height:' + badge + 'px;font-size:' + fs + 'px">' +
          '<option value="IN"' + (countryOf() === 'IN' ? ' selected' : '') + '>India +91</option>' +
          '<option value="NP"' + (countryOf() === 'NP' ? ' selected' : '') + '>Nepal +977</option>' +
        '</select>' + mark
      : mark;

    return '<span class="me-av" data-me-av>' +
             '<span class="me-ring" style="width:' + px + 'px;height:' + px + 'px">' + inner + '</span>' +
             flag +
             (opts.pick ? '<button type="button" class="me-cam" data-me-pick aria-label="Add photo / फोटो">📷</button>' : '') +
             '<input type="file" accept="image/*" data-me-file hidden>' +
           '</span>';
  }

  /* The block that sits at the top of the sign-in sheet. */
  function pickerHtml() {
    return '<div style="display:flex;flex-direction:column;align-items:center;margin:2px 0 14px">' +
             avatarHtml(72, { pick: true, flagSelect: true }) +
             '<div class="me-hint">फोटो यही मोबाइलमा मात्र रहन्छ · Photo stays on this phone only</div>' +
           '</div>';
  }

  /* Scale to MAX_PX on the longest side and hand back a JPEG data URL. Big
     phone cameras produce 4 MB files; localStorage holds about 5 MB in total,
     so the original could never be kept even if we wanted to. */
  function shrink(file, cb) {
    var fr = new FileReader();
    fr.onload = function () {
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, MAX_PX / Math.max(img.width || 1, img.height || 1));
        var w = Math.max(1, Math.round((img.width || MAX_PX) * scale));
        var h = Math.max(1, Math.round((img.height || MAX_PX) * scale));
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        try {
          c.getContext('2d').drawImage(img, 0, 0, w, h);
          cb(c.toDataURL('image/jpeg', 0.82));
        } catch (e) { cb(''); }
      };
      img.onerror = function () { cb(''); };
      img.src = String(fr.result || '');
    };
    fr.onerror = function () { cb(''); };
    fr.readAsDataURL(file);
  }

  /* Re-render every avatar on the page in place. */
  function refresh() {
    var list = document.querySelectorAll('[data-me-av]');
    for (var i = 0; i < list.length; i++) {
      var el   = list[i];
      var ring = el.querySelector('.me-ring');
      if (!ring) { continue; }
      var px = parseInt(ring.style.width, 10) || 44;
      var ph = readPhoto();
      ring.innerHTML = ph
        ? '<img src="' + ph + '" alt="">'
        : '<span style="font-size:' + Math.round(px * 0.42) + 'px">' + initial() + '</span>';
      var fl = el.querySelector('i.me-flag');
      if (fl) { fl.innerHTML = flagSvg(countryOf(), Math.max(12, parseInt(fl.style.width, 10) - 4)); }
      var sel = el.querySelector('select[data-me-flag]');
      if (sel) { sel.value = countryOf(); }
    }
  }

  /* One delegated listener for the whole app — the avatar is rendered into
     modals and into #/my, both of which are replaced wholesale. */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-me-pick]') : null;
    if (!btn) { return; }
    e.preventDefault();
    var wrap = btn.closest('[data-me-av]');
    var inp  = wrap && wrap.querySelector('[data-me-file]');
    if (inp) { inp.click(); }
  });

  document.addEventListener('change', function (e) {
    var el = e.target;

    if (el && el.matches && el.matches('[data-me-file]')) {
      var f = el.files && el.files[0];
      el.value = '';
      if (!f) { return; }
      if (!/^image\//.test(f.type || '')) { return; }
      shrink(f, function (dataUrl) {
        if (!dataUrl) { return; }
        writePhoto(dataUrl);
        refresh();
        if (typeof toast === 'function') { toast('फोटो यही मोबाइलमा सेभ भयो · Saved on this phone'); }
      });
      return;
    }

    if (el && el.matches && el.matches('select[data-me-flag]')) {
      var want = el.value === 'NP' ? 'NP' : 'IN';
      var u = me();
      /* Before sign-in there is no account to correct — carry the choice into
         the country picker sitting right below in the same sheet. */
      var cc = document.getElementById('otpCC');
      if (cc) { cc.value = want === 'NP' ? '977' : '91'; }
      if (!u || !u.phone) { refresh(); return; }
      if (u.country === want) { refresh(); return; }
      u.country = want;
      if (typeof setUser === 'function') { setUser(u); }
      refresh();
      /* Make it true on the server too: the country decides which +91/+977
         number the ticket's WhatsApp goes to. */
      if (typeof shgApi !== 'undefined' && shgApi && typeof shgApi.post === 'function') {
        shgApi.post('/otp.php', { action: 'quick', phone: u.phone, name: u.name || '', country: want })
          .then(function () {
            if (typeof toast === 'function') {
              toast(want === 'NP' ? '🇳🇵 नेपाल · ticket +977 मा जान्छ' : '🇮🇳 भारत · ticket +91 मा जान्छ');
            }
          })
          .catch(function () { /* local choice already applied */ });
      }
    }
  });

  /* Sign-out on a shared machine: drop the draft. An account's own photo stays
     under its number, so the next person simply never sees it. */
  window.meForget = function () {
    try { localStorage.removeItem(PHOTO_KEY); } catch (e) {}
    refresh();
  };

  window.meAvatarHtml     = avatarHtml;
  window.meAvatarPicker   = pickerHtml;
  window.meRefreshAvatars = refresh;
  window.meHasPhoto       = function () { return readPhoto() !== ''; };
})();
