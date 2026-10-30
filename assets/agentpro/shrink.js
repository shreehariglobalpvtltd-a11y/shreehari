/* =====================================================================
   shrink.js — make every phone photo small enough to send (30 Oct 2026).

   Owner: the old form said "That is too large to send" — a 10 MB phone
   photo went up as it was. Every picture is now redrawn as a JPEG no
   longer than `maxEdge` px and no bigger than `maxBytes` before it leaves
   the phone: quality steps down first, then the size, until it fits.

     ApShrink.blob(file, { maxEdge: 1600, maxBytes: 600000 })   → Promise<Blob>   (JPEG)
     ApShrink.dataUrl(fileOrDataUrl, { maxEdge, maxBytes })     → Promise<string> (data:image/jpeg;base64,…)
     ApShrink.fit(encode, w, h, opts)                           → the loop itself (encode(w, h, q) → Promise<Blob>)
     ApShrink.dataUrlBytes(uri)                                 → decoded size of a data: URI

   A file the browser cannot open (e.g. HEIC on Android Chrome) rejects
   with err.code = 'decode'; the page then asks for a JPG photo instead.
   Plain ES5 (old phones); also loads under Node for tests (module.exports).
   ===================================================================== */
(function (root) {
  'use strict';

  var MIN_EDGE = 480;   // never smaller than this: an ID must stay readable

  /** Bytes of a data: URI once decoded (base64 is 4 chars per 3 bytes). */
  function dataUrlBytes(uri) {
    var s = String(uri || ''), i = s.indexOf(',');
    if (i < 0) return 0;
    var b64 = s.slice(i + 1), pad = b64.slice(-2) === '==' ? 2 : (b64.slice(-1) === '=' ? 1 : 0);
    return Math.max(0, Math.floor(b64.length * 3 / 4) - pad);
  }

  /**
   * The loop: start at maxEdge and quality q0, lower the quality to qMin, then
   * the size (×0.8, quality back up) until the JPEG is ≤ maxBytes — or the
   * picture is down to MIN_EDGE, when the smallest try is returned.
   */
  function fit(encode, w, h, opts) {
    opts = opts || {};
    var maxEdge = opts.maxEdge || 1600, maxBytes = opts.maxBytes || 600000;
    var q0 = opts.quality || 0.86, qMin = opts.minQuality || 0.5;
    var k = Math.min(1, maxEdge / Math.max(w, h, 1)), q = q0, tries = 0, best = null;
    function step() {
      var cw = Math.max(1, Math.round(w * k)), ch = Math.max(1, Math.round(h * k));
      return Promise.resolve(encode(cw, ch, q)).then(function (b) {
        tries++;
        if (b && (!best || b.size < best.size)) best = b;
        if (b && b.size <= maxBytes) return b;
        if (tries >= 20) return best;
        if (q - 0.1 >= qMin - 1e-9) { q = Math.round((q - 0.1) * 100) / 100; return step(); }
        if (Math.max(cw, ch) * 0.8 < Math.min(MIN_EDGE, Math.max(w, h))) return best;
        k *= 0.8; q = Math.min(q0, 0.8);
        return step();
      });
    }
    return step();
  }

  function decodeErr() { var e = new Error('This picture could not be opened.'); e.code = 'decode'; return e; }

  /** An <img> for a File / Blob / data URI (the browser applies the EXIF rotation itself). */
  function loadImage(src) {
    return new Promise(function (resolve, reject) {
      var img = new Image(), url = null;
      if (typeof src !== 'string') { try { url = URL.createObjectURL(src); } catch (e) { reject(decodeErr()); return; } }
      img.onload = function () { if (url) URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { if (url) URL.revokeObjectURL(url); reject(decodeErr()); };
      img.src = url || src;
    });
  }

  function canvasEncoder(img) {
    var cv = document.createElement('canvas'), cx = cv.getContext('2d');
    return function (w, h, q) {
      cv.width = w; cv.height = h;
      cx.fillStyle = '#fff'; cx.fillRect(0, 0, w, h);   // a transparent PNG becomes white, not black
      cx.drawImage(img, 0, 0, w, h);
      return new Promise(function (resolve) {
        if (cv.toBlob) { cv.toBlob(function (b) { resolve(b); }, 'image/jpeg', q); return; }
        var u = cv.toDataURL('image/jpeg', q), bin = atob(u.split(',')[1]), a = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
        resolve(new Blob([a], { type: 'image/jpeg' }));
      });
    };
  }

  /** Any picture → a JPEG Blob within maxEdge / maxBytes. */
  function blob(file, opts) {
    return loadImage(file).then(function (img) {
      var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
      if (!w || !h) throw decodeErr();
      return fit(canvasEncoder(img), w, h, opts);
    }).then(function (b) { if (!b) throw decodeErr(); return b; });
  }

  function blobToDataUrl(b) {
    return new Promise(function (resolve, reject) {
      var fr = new FileReader();
      fr.onload = function () { resolve(String(fr.result)); };
      fr.onerror = function () { reject(decodeErr()); };
      fr.readAsDataURL(b);
    });
  }

  /** A File / Blob / data URI → a JPEG data URI within maxEdge / maxBytes (already small: kept as it is). */
  function dataUrl(src, opts) {
    opts = opts || {};
    if (typeof src === 'string' && /^data:image\/jpeg/i.test(src) && dataUrlBytes(src) <= (opts.maxBytes || 600000) && !opts.always) {
      return loadImage(src).then(function (img) {
        var big = Math.max(img.naturalWidth || 0, img.naturalHeight || 0) > (opts.maxEdge || 1600);
        return big ? blob(src, opts).then(blobToDataUrl) : src;
      });
    }
    return blob(src, opts).then(blobToDataUrl);
  }

  var api = { fit: fit, blob: blob, dataUrl: dataUrl, dataUrlBytes: dataUrlBytes, MIN_EDGE: MIN_EDGE };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.ApShrink = api;
})(typeof window !== 'undefined' ? window : this);
