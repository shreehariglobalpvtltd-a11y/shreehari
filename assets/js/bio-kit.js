/* =====================================================================
 * bio-kit.js — the thumbprint by camera, the phone lock and the agreement's
 * terms (ShgTerms, at the end), for the
 * online staff and agent / partner forms (22 Oct 2026, owner: "face id ni
 * lagau, fingerprint ni rakhna milnu paryo, natra fingerprint scan garera
 * camera bata rakhdinus").
 *
 * A browser can never read the fingerprint sensor itself, so two honest parts:
 *
 *   ShgThumb.mount(box, {onChange})   the right thumb photographed close up
 *       with the back camera, turned ON THE PHONE into an ink-style print
 *       (local contrast -> ridges, oval like a stamp), PNG ~20-60 KB. It goes
 *       with the form and prints in the thumb box of the A4 form + agreement.
 *
 *   ShgPasskey.create({challenge, name, label})   the phone's own fingerprint
 *       / Face ID lock (WebAuthn, platform authenticator). Only the public
 *       credential is sent; the fingerprint never leaves the phone.
 * ===================================================================== */
(function () {
  'use strict';

  var W = 360, H = 450;   // the print, 4:5 like a thumb

  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]); }
    if (html != null) e.innerHTML = html;
    return e;
  }

  /* integral image of v (Float64), size (w+1)*(h+1) */
  function integral(v, w, h) {
    var I = new Float64Array((w + 1) * (h + 1));
    for (var y = 0; y < h; y++) {
      var row = 0;
      for (var x = 0; x < w; x++) {
        row += v[y * w + x];
        I[(y + 1) * (w + 1) + x + 1] = I[y * (w + 1) + x + 1] + row;
      }
    }
    return I;
  }
  function boxMean(I, w, h, x, y, r) {
    var x0 = Math.max(0, x - r), y0 = Math.max(0, y - r), x1 = Math.min(w, x + r + 1), y1 = Math.min(h, y + r + 1);
    var s = I[y1 * (w + 1) + x1] - I[y0 * (w + 1) + x1] - I[y1 * (w + 1) + x0] + I[y0 * (w + 1) + x0];
    return s / ((x1 - x0) * (y1 - y0));
  }

  /**
   * photo (an <img>) -> {uri, quality}. The middle of the photo is the thumb
   * (the box asks for it to fill the frame); ridges come out as ink where the
   * skin is darker than its surroundings.
   */
  function toPrint(img) {
    var sw = img.naturalWidth || img.width, sh = img.naturalHeight || img.height;
    var side = Math.min(sw, sh) * 0.78, cw = side * 0.8, ch = side;
    if (ch > sh) { ch = sh; cw = ch * 0.8; }
    var sx = (sw - cw) / 2, sy = (sh - ch) / 2;
    var c = document.createElement('canvas'); c.width = W; c.height = H;
    var x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, W, H);
    x.drawImage(img, sx, sy, cw, ch, 0, 0, W, H);
    var px = x.getImageData(0, 0, W, H), d = px.data, n = W * H;
    var g = new Float64Array(n), g2 = new Float64Array(n);
    for (var i = 0; i < n; i++) {
      var v = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
      g[i] = v; g2[i] = v * v;
    }
    // light smoothing first (camera noise), then local mean / spread over ~2 ridge widths
    var I0 = integral(g, W, H), s = new Float64Array(n);
    for (var y = 0; y < H; y++) for (var xx = 0; xx < W; xx++) s[y * W + xx] = boxMean(I0, W, H, xx, y, 1);
    var I1 = integral(s, W, H);
    for (i = 0; i < n; i++) g2[i] = s[i] * s[i];
    var I2 = integral(g2, W, H);
    var R = 7, cx = W / 2, cy = H / 2, rx = W * 0.46, ry = H * 0.47, spread = 0, cnt = 0;
    var out = x.createImageData(W, H), o = out.data;
    for (y = 0; y < H; y++) {
      for (xx = 0; xx < W; xx++) {
        i = y * W + xx;
        var m = boxMean(I1, W, H, xx, y, R), m2 = boxMean(I2, W, H, xx, y, R);
        var sd = Math.sqrt(Math.max(0, m2 - m * m));
        var e = ((xx - cx) * (xx - cx)) / (rx * rx) + ((y - cy) * (y - cy)) / (ry * ry);
        var ink = 0;
        if (e <= 1) {
          spread += sd; cnt++;
          var z = (s[i] - m) / (sd + 4);           // < 0 = darker than around it = a ridge
          // two tones only, like a stamp-pad print: crisp, and a PNG of 15-60 KB (the forms take at most 150 KB)
          ink = z < (e > 0.85 ? -0.55 : -0.15) ? 1 : 0;
        }
        // ink colour: stamp-pad blue-black on white
        o[i * 4] = Math.round(255 - ink * (255 - 28));
        o[i * 4 + 1] = Math.round(255 - ink * (255 - 36));
        o[i * 4 + 2] = Math.round(255 - ink * (255 - 92));
        o[i * 4 + 3] = 255;
      }
    }
    x.putImageData(out, 0, 0);
    var uri = c.toDataURL('image/png');
    if (uri.length > 180000) {   // a very busy photo: the same print at 2/3 size stays under the 150 KB limit
      var c2 = document.createElement('canvas'); c2.width = Math.round(W * 2 / 3); c2.height = Math.round(H * 2 / 3);
      c2.getContext('2d').drawImage(c, 0, 0, c2.width, c2.height);
      uri = c2.toDataURL('image/png');
    }
    return { uri: uri, quality: cnt ? spread / cnt : 0 };
  }

  function mount(box, opts) {
    opts = opts || {};
    box.classList.add('bk-thumb');
    box.innerHTML = '';
    var st = el('style', {}, '.bk-thumb{display:flex;gap:12px;align-items:center;flex-wrap:wrap}' +
      '.bk-thumb .bk-pv{width:96px;height:120px;border-radius:50% / 46%;border:2px dashed #9aa6bd;background:#fff center/cover no-repeat;flex:0 0 96px;display:grid;place-items:center;font-size:34px}' +
      '.bk-thumb .bk-pv.on{border-style:solid;border-color:#0f9d6b;font-size:0}' +
      '.bk-thumb .bk-tx{flex:1 1 200px;min-width:0;font-size:13px;line-height:1.45}' +
      '.bk-thumb .bk-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}' +
      '.bk-thumb label.bk-btn,.bk-thumb button.bk-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 14px;border-radius:12px;font:inherit;font-weight:800;font-size:14px;cursor:pointer;border:1.5px solid #0a2a8f;background:#0a2a8f;color:#fff}' +
      '.bk-thumb button.bk-btn.ghost{background:transparent;color:inherit;border-color:#9aa6bd}' +
      '.bk-thumb label.bk-btn input{position:absolute;inset:0;opacity:0;cursor:pointer}' +
      '.bk-thumb .bk-msg{display:block;margin-top:6px;font-weight:700}');
    var pv = el('div', { 'class': 'bk-pv', 'aria-hidden': 'true' }, '👍');
    var tx = el('div', { 'class': 'bk-tx' },
      '<b>👍 औंठाछाप · Thumbprint</b><br>दायाँ हातको बुढी औंलाको टुप्पो उज्यालोमा, क्यामेराबाट ८–१० से.मि. नजिक राखेर खिच्नुहोस् — औंठाले पूरै फोटो ढाकोस्, रेखा देखियोस्।' +
      '<br><small>Right thumb, close up in good light, filling the photo.</small>');
    var row = el('div', { 'class': 'bk-row' });
    var pick = el('label', { 'class': 'bk-btn' }, '📷 औंठा स्क्यान · Scan thumb<input type="file" accept="image/*" capture="environment" aria-label="Scan thumb">');
    var again = el('button', { 'type': 'button', 'class': 'bk-btn ghost', 'hidden': '' }, '↺ फेरि · Retake');
    var msg = el('span', { 'class': 'bk-msg', 'role': 'status' });
    row.appendChild(pick); row.appendChild(again); tx.appendChild(row); tx.appendChild(msg);
    box.appendChild(st); box.appendChild(pv); box.appendChild(tx);
    var input = pick.querySelector('input'), value = null;

    function set(uri, note, ok) {
      value = uri;
      pv.style.backgroundImage = uri ? 'url(' + uri + ')' : '';
      pv.classList.toggle('on', !!uri);
      again.hidden = !uri;
      msg.textContent = note || '';
      msg.style.color = ok === false ? '#b45309' : '#0f9d6b';
      if (opts.onChange) opts.onChange(uri);
    }
    input.addEventListener('change', function () {
      var f = this.files && this.files[0];
      this.value = '';
      if (!f) return;
      if (!/^image\//.test(f.type || '')) { set(null, 'फोटो मात्र · a photo only', false); return; }
      msg.style.color = ''; msg.textContent = '⏳ …';
      var url = URL.createObjectURL(f), im = new Image();
      im.onload = function () {
        try {
          // big camera photos first come down to ~1200 px (memory on cheap phones)
          var k = Math.min(1, 1200 / Math.max(im.naturalWidth, im.naturalHeight));
          var c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
          c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
          var small = new Image();
          small.onload = function () {
            var r = toPrint(small);
            var clear = r.quality > 5;
            set(r.uri, clear ? '✓ औंठाछाप लियो · Thumbprint taken' : '⚠️ रेखा कम देखिए — उज्यालोमा अझ नजिकबाट फेरि खिच्नुहोस् (यही पनि पठाउन मिल्छ)', clear);
          };
          small.src = c.toDataURL('image/jpeg', 0.92);
        } catch (e) { set(null, 'भएन — फेरि खिच्नुहोस् · try again', false); }
        URL.revokeObjectURL(url);
      };
      im.onerror = function () { URL.revokeObjectURL(url); set(null, 'फोटो खुलेन — फेरि खिच्नुहोस् · could not open', false); };
      im.src = url;
    });
    again.addEventListener('click', function () { set(null, ''); input.click(); });
    return {
      value: function () { return value; },
      set: function (uri) { set(uri || null, uri ? '✓ औंठाछाप · Thumbprint' : ''); },
      reset: function () { set(null, ''); }
    };
  }

  /* ---------- the phone lock (WebAuthn, platform authenticator) ---------- */
  function b64u(buf) { var s = '', b = new Uint8Array(buf); for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function unb64u(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var b = atob(s), u = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) u[i] = b.charCodeAt(i); return u; }
  function passkeySupported() {
    if (!window.PublicKeyCredential || !navigator.credentials || !window.isSecureContext) return Promise.resolve(false);
    var f = PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable;
    return f ? f.call(PublicKeyCredential).catch(function () { return false; }) : Promise.resolve(false);
  }
  /** d = {challenge, rpId, rpName} from the server; resolves the JSON to send. */
  function passkeyCreate(d, name, label) {
    return navigator.credentials.create({ publicKey: {
      challenge: unb64u(d.challenge), rp: { id: d.rpId, name: d.rpName },
      user: { id: crypto.getRandomValues(new Uint8Array(16)), name: name, displayName: label || name },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
      authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'preferred' },
      timeout: 60000, attestation: 'none' } }).then(function (cred) {
      return JSON.stringify({ id: cred.id, rawId: b64u(cred.rawId), type: cred.type,
        clientDataJSON: b64u(cred.response.clientDataJSON), attestationObject: b64u(cred.response.attestationObject),
        transports: cred.response.getTransports ? cred.response.getTransports() : [] });
    });
  }

  /* ---------- every term of the agreement, inside the form (owner: "agreement garne bela sart haru k k chha
     sabai lekhidinus") - /api/agreement-terms.php, the same words the A4 form prints; loads on first open ---------- */
  function terms(box, kind) {
    box.innerHTML = '';
    box.appendChild(el('style', {}, '.tk{margin:10px 0;border:1.5px solid #d8e1f3;border-radius:14px;background:#fff;color:#0d1b3e}' +
      '.tk summary{cursor:pointer;padding:12px 14px;font-weight:800;font-size:14.5px;list-style:none;display:flex;gap:8px;align-items:center}' +
      '.tk summary::-webkit-details-marker{display:none}.tk summary::after{content:"▾";margin-left:auto;transition:transform .2s}.tk[open] summary::after{transform:rotate(180deg)}' +
      '.tk .tk-l{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 8px}.tk .tk-l button{min-height:34px;padding:0 12px;border-radius:999px;border:1.5px solid #d8e1f3;background:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer;color:#0d1b3e}' +
      '.tk .tk-l button.on{background:#0a2a8f;border-color:#0a2a8f;color:#fff}' +
      '.tk .tk-b{max-height:56vh;overflow:auto;padding:4px 14px 12px;font-size:13.5px;line-height:1.6;border-top:1px solid #eef3fc;-webkit-overflow-scrolling:touch}' +
      '.tk h4{margin:12px 0 4px;font-size:14px;color:#0a2a8f}.tk p{margin:0 0 6px}.tk p b.n{display:inline-block;min-width:30px;color:#0a2a8f}' +
      '.tk .fill{display:inline-block;min-width:60px;border-bottom:1.5px solid #8a96ad;height:1em;vertical-align:-3px}' +
      '.tk .tk-f{padding:0 14px 12px;font-size:12.5px}.tk .tk-f a{color:#0a2a8f;font-weight:700}'));
    var d = el('details', { 'class': 'tk' });
    var sum = el('summary', {}, '📜 <span>सम्झौताका सबै सर्तहरू — सही गर्नुअघि पढ्नुहोस् · Read every term before you sign</span>');
    var langs = el('div', { 'class': 'tk-l', 'role': 'group', 'aria-label': 'Language', 'data-noi18n': '' });
    var body = el('div', { 'class': 'tk-b', 'data-noi18n': '' }, '⏳ …');   // already in its own language: form-i18n.js leaves it
    var foot = el('div', { 'class': 'tk-f' });
    d.appendChild(sum); d.appendChild(langs); d.appendChild(body); d.appendChild(foot);
    box.appendChild(d);
    var cur = { kind: kind, lang: 'ne', loaded: '' }, seq = 0;
    var NAMES = { ne: 'नेपाली', hi: 'हिन्दी', en: 'English', gu: 'ગુજરાતી' };
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function load() {
      var key = cur.kind + '|' + cur.lang;
      if (cur.loaded === key) return;
      cur.loaded = key; body.textContent = '⏳ …';
      var my = ++seq;   // 23 Oct 2026 (review): only the newest request may fill the box
      fetch('/api/agreement-terms.php?kind=' + encodeURIComponent(cur.kind) + '&lang=' + encodeURIComponent(cur.lang), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (my !== seq) return;
          if (!j || !j.ok) throw new Error('bad');
          var n = 0, html = '<p style="margin-top:8px"><b>' + esc(j.title) + '</b></p>';
          (j.sections || []).forEach(function (s) {
            html += '<h4>' + esc((s.no ? s.no + '. ' : '') + s.title) + '</h4>';
            (s.points || []).forEach(function (p) { n++; html += '<p>' + (p.num ? '<b class="n">' + esc(p.num) + '</b> ' : '') + p.html + '</p>'; });
          });
          body.innerHTML = html;   // our own agreement pages; the office's edits arrive escaped (AgreementPrint)
          sum.querySelector('span').textContent = 'सम्झौताका सबै ' + n + ' सर्तहरू — सही गर्नुअघि पढ्नुहोस् · Read all ' + n + ' terms before you sign';
          langs.innerHTML = '';
          (j.langs || []).forEach(function (l) {
            var b = el('button', { 'type': 'button', 'class': l === j.lang ? 'on' : '' }, esc(NAMES[l] || l));
            b.addEventListener('click', function () { cur.lang = l; load(); });
            langs.appendChild(b);
          });
          langs.hidden = (j.langs || []).length < 2;
          foot.innerHTML = '<a href="' + esc(j.url) + '" target="_blank" rel="noopener">पूरा सम्झौता छुट्टै पानामा खोल्नुहोस् · Open the full agreement ↗</a>';
        })
        .catch(function () { if (my !== seq) return; cur.loaded = ''; body.textContent = 'खुलेन — फेरि थिच्नुहोस् · could not load, tap again'; });
    }
    d.addEventListener('toggle', function () { if (d.open) load(); });
    return {
      setKind: function (k) { if (k === cur.kind) return; cur.kind = k; cur.loaded = ''; sum.querySelector('span').textContent = 'सम्झौताका सबै सर्तहरू — सही गर्नुअघि पढ्नुहोस् · Read every term before you sign'; if (d.open) load(); },
      open: function () { d.open = true; load(); },
      // 23 Oct 2026: the form's language picker (form-i18n.js) also picks the terms' language
      setLang: function (l) { if (!l || l === cur.lang) return; cur.lang = l; cur.loaded = ''; if (d.open) load(); }
    };
  }

  window.ShgThumb = { mount: mount, toPrint: toPrint };
  window.ShgPasskey = { supported: passkeySupported, create: passkeyCreate };
  window.ShgTerms = { mount: terms };
})();
