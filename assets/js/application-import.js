/* S Hari document-first intake. OCR is a suggestion, never approval or phone verification. */
(function () {
  'use strict';
  var active = null, pdfLib = null;
  function el(tag, text, cls) { var e = document.createElement(tag); if (text) e.textContent = text; if (cls) e.className = cls; return e; }
  function dataUrl(file) { return new Promise(function (resolve, reject) { var r = new FileReader(); r.onload = function () { resolve(r.result); }; r.onerror = reject; r.readAsDataURL(file); }); }
  function jpeg(source) {
    return new Promise(function (resolve, reject) {
      var im = new Image(); im.onload = function () {
        var k = Math.min(1, 1500 / Math.max(im.width, im.height)), cv = document.createElement('canvas');
        cv.width = Math.round(im.width * k); cv.height = Math.round(im.height * k);
        var x = cv.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cv.width, cv.height); x.drawImage(im, 0, 0, cv.width, cv.height);
        var q = .88, out = cv.toDataURL('image/jpeg', q);
        while (out.length > 500000 && q > .4) { q -= .08; out = cv.toDataURL('image/jpeg', q); }
        resolve(out);
      }; im.onerror = reject; im.src = source;
    });
  }
  function idImage(file) {
    if (file.type !== 'application/pdf' && !/\.pdf$/i.test(file.name)) { return dataUrl(file).then(jpeg); }
    if (!pdfLib) pdfLib = import('/assets/vendor/pdfjs/pdf.min.js').then(function (lib) { lib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js'; return lib; });
    return Promise.all([pdfLib, file.arrayBuffer()]).then(function (a) { return a[0].getDocument({ data: new Uint8Array(a[1]), isEvalSupported: false, cMapUrl: '/assets/vendor/pdfjs/cmaps/', cMapPacked: true, standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/', wasmUrl: '/assets/vendor/pdfjs/wasm/' }).promise; }).then(function (pdf) {
      return pdf.getPage(1).then(function (page) {
        var unit = page.getViewport({ scale: 1 }), view = page.getViewport({ scale: Math.min(2.5, 1500 / Math.max(unit.width, unit.height)) });
        var cv = document.createElement('canvas'); cv.width = Math.ceil(view.width); cv.height = Math.ceil(view.height);
        return page.render({ canvasContext: cv.getContext('2d'), viewport: view }).promise.then(function () { var result = cv.toDataURL('image/jpeg', .86); return pdf.destroy().then(function () { return result; }); });
      });
    });
  }
  function mount(o) {
    if (active) return active;
    var host = document.getElementById(o.host), submit = document.getElementById(o.submit); if (!host || !submit) return null;
    var root = el('section', '', 'ai-intake'), title = el('h2', '१ · पहिले परिचयपत्र upload गर्नुहोस् · Upload ID first');
    var hint = el('p', 'नागरिकता, आधार, राहदानी वा लाइसेन्सको स्पष्ट फोटो/PDF राख्नुहोस्। S Hari AI ले विवरण पढेर फारम भर्छ; तपाईंले सच्याउन सक्नुहुन्छ।');
    var idLabel = el('label', '🪪 परिचयपत्र छान्नुहोस् (अनिवार्य) · Choose ID (required)', 'ai-pick'), idInput = document.createElement('input');
    idInput.type = 'file'; idInput.accept = 'image/jpeg,image/png,image/webp,application/pdf'; idInput.id = 'intakeId'; idLabel.appendChild(idInput);
    var preview = document.createElement('img'); preview.alt = 'Uploaded ID preview'; preview.className = 'ai-preview'; preview.hidden = true;
    var status = el('p', '', 'ai-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    var more = el('details'), sum = el('summary', '२ · हातले भरेको फारम पनि छ? · Import a completed form (optional)'); more.appendChild(sum);
    var note = el('p', 'खाली फारम डाउनलोड गरेर कलमले भर्नुहोस्। भरिएको फोटो वा फोनमा save भएको PDF राखेपछि AI ले थप विवरण भर्छ। परिचयपत्र भने माथि छुट्टै चाहिन्छ। · Up to 6 MB.');
    var blank = el('a', '📄 खाली फारम डाउनलोड / प्रिन्ट · Download blank form'); blank.href = '/agreements/form.php?kind=' + o.kind; blank.target = '_blank'; blank.rel = 'noopener';
    var formLabel = el('label', '📑 भरिएको फारमको फोटो/PDF छान्नुहोस् · Choose completed form', 'ai-pick'), formInput = document.createElement('input'); formInput.type = 'file'; formInput.accept = idInput.accept; formInput.id = 'intakeForm'; formLabel.appendChild(formInput);
    more.appendChild(note); more.appendChild(blank); more.appendChild(formLabel);
    var progress = el('progress'); progress.hidden = true; progress.removeAttribute('value'); progress.setAttribute('aria-label', 'AI reading your document');
    var report = el('p', '', 'ai-report');
    var reviewLabel = el('label', '', 'ai-review'), review = document.createElement('input'); review.type = 'checkbox'; review.id = 'intakeReviewed'; reviewLabel.appendChild(review); reviewLabel.appendChild(document.createTextNode('AI ले भरेको नाम, मोबाइल र ID विवरण जाँचेर आवश्यक सुधार गरें। · I reviewed and corrected the main details.'));
    var retry = el('button', '↻ AI ले फेरि पढ्नुहोस् · Retry AI', 'ai-retry'); retry.type = 'button'; retry.hidden = true;
    [title,hint,idLabel,preview,status,progress,retry,more,report,reviewLabel].forEach(function (e) { root.appendChild(e); });
    host.insertBefore(root, host.firstChild);
    var style = el('style'); style.textContent = '.ai-intake{padding:20px;margin:12px auto 24px;max-width:820px;box-sizing:border-box;background:#fff;border:2px solid #b5c9ef;border-radius:18px;color:#102657;box-shadow:0 12px 30px #10265714;font:500 15px/1.6 system-ui,sans-serif}.ai-intake h2{font-size:21px;line-height:1.5;margin:0 0 12px}.ai-intake p{margin:10px 0}.ai-pick{display:block;padding:14px;background:#edf3ff;border:2px dashed #537ed2;border-radius:12px;font-weight:750;cursor:pointer;margin:12px 0}.ai-pick input{display:block;max-width:100%;margin-top:10px;font:inherit;font-size:13px}.ai-intake details{margin-top:16px}.ai-intake summary{font-weight:750;cursor:pointer;padding:10px 0}.ai-preview{max-width:240px;max-height:150px;object-fit:contain;display:block}.ai-preview[hidden]{display:none}.ai-review{display:flex;align-items:flex-start;gap:10px;background:#eff8ee;padding:12px;border-radius:10px;margin-top:16px;font-weight:700}.ai-review input{width:22px;height:22px;flex-shrink:0}.ai-intake progress{width:100%;height:12px}.ai-status{font-weight:750}.ai-report{white-space:pre-line}.ai-retry{padding:10px 14px;background:#fff;border:1px solid #9cb8e8;border-radius:10px;cursor:pointer}.intake-locked #saForm>:not(.ai-intake),.intake-locked #ap>:not(.ai-intake){display:none!important}.intake-locked #saBar .submit{opacity:.5}@media print{.ai-intake{display:none!important}}'; document.head.appendChild(style);
    var busy = false, uploaded = false, currentId = '', formReceipt = '', retryDoc = '', retryKind = 'id', timer = null;
    function lock(on) { document.documentElement.classList.toggle('intake-locked', on); if (!o.closed || !o.closed()) submit.disabled = on || busy; }
    lock(true); review.disabled = true;
    function say(t, bad) { status.textContent = t; status.style.color = bad ? '#a32d2d' : '#174e30'; }
    function post(doc, kind) {
      var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 120000);
      return o.token().then(function (t) { return fetch('/api/application-import.php', { method: 'POST', signal: controller.signal, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t || '' }, body: JSON.stringify({ kind: kind, application_kind: o.kind, doc: doc }) }); }).then(function (r) { return r.json().catch(function () { throw new Error('Server response could not be read. Please retry.'); }); }).finally(function () { clearTimeout(timeout); });
    }
    function read(doc, kind) {
      if (busy) return Promise.reject(new Error('AI is still reading.'));
      retryDoc = doc; retryKind = kind;
      busy = true; review.checked = false; review.disabled = true; progress.hidden = false; idInput.disabled = true; formInput.disabled = true; retry.hidden = true; submit.disabled = true;
      if (kind === 'id') { uploaded = false; currentId = doc; lock(true); }
      var elapsed = 0; say('⏳ S Hari AI ले document पढ्दैछ… कृपया पर्खनुहोस्। · Reading your document…');
      timer = setInterval(function () { elapsed += 5; say('⏳ AI ले पढ्दैछ (' + elapsed + 's)… फारम बन्द नगर्नुहोस्। · Still reading; please keep this page open.'); }, 5000);
      return post(doc, kind).then(function (d) {
        if (!d || !d.ok) throw new Error((d && (d.error || d.message)) || 'Import failed.');
        if (d.status === 'notdoc') throw new Error(kind === 'id' ? 'यो परिचयपत्र जस्तो देखिएन। नागरिकता/आधारको स्पष्ट फोटो राख्नुहोस्। · Choose a government ID.' : 'भरिएको फारम भेटिएन। स्पष्ट फोटो वा PDF राख्नुहोस्। · Choose a completed form.');
        if (kind === 'form') { formReceipt = d.receipt || ''; }
        if (kind === 'id') { uploaded = true; preview.src = doc; preview.hidden = false; o.onId(doc); }
        var fields = d.fields || {};
        if (d.status !== 'unavailable') retry.hidden = true;
        var changed = o.onFields(fields, kind) || 0;
        if (d.status === 'unavailable') {
          say('परिचयपत्र राखियो, तर AI अहिले उपलब्ध भएन। फेरि पढाउन वा विवरण आफैं भर्न सक्नुहुन्छ। · AI unavailable: retry or enter the details for review.', true); retry.hidden = false;
        } else {
          say('✓ AI ले पढ्यो — ' + changed + ' विवरण भरियो। तलको फारम हेरेर सच्याउनुहोस्। · Review and correct the filled details.');
        }
        report.textContent = 'नाम, मोबाइल र ID विवरण सही भएपछि तलको ✓ लगाउनुहोस्। बाँकी विवरण ऐच्छिक छ। · Other fields remain optional.';
      }).catch(function (e) { if (e.message && /session|expired|419|csrf/i.test(e.message) && o.onSessionExpired) o.onSessionExpired();
        say(e.name === 'AbortError' ? 'समय सकियो — फेरि प्रयास गर्नुहोस् · Reading timed out. Please retry.' : e.message || 'Could not read the file. Please retry.', true); retry.hidden = false; throw e; }).finally(function () {
        clearInterval(timer); busy = false; progress.hidden = true; idInput.disabled = false; formInput.disabled = false; review.disabled = !uploaded; lock(!uploaded);
      });
    }
    function choose(input, kind) {
      var f = input.files && input.files[0]; if (!f) return;
      if (f.size > 6000000) { say('फाइल 6 MB भन्दा सानो राख्नुहोस् · Maximum 6 MB.', true); return; }
      if (kind === 'form' && !uploaded) { say('पहिले माथि परिचयपत्र upload गर्नुहोस् · Upload your ID first.', true); idInput.focus(); return; }
      say('⏳ फाइल खोल्दै… · Opening your file…');
      var isPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name);
      (kind === 'id' ? idImage(f) : dataUrl(f)).then(function (uri) { return read(uri, kind); }).catch(function (e) { if (!busy) say(e.message || 'फाइल खुलेन — अर्को फोटो/PDF छान्नुहोस् · Could not open the file.', true); });
    }
    idInput.addEventListener('change', function () { choose(idInput, 'id'); }); formInput.addEventListener('change', function () { choose(formInput, 'form'); }); retry.addEventListener('click', function () { if (retryDoc) read(retryDoc, retryKind).catch(function () {}); });
    host.addEventListener('input', function (e) { if (!root.contains(e.target) && e.isTrusted) { e.target.dataset.userEdited = '1'; review.checked = false; } });
    var api = { receipt: function () { return formReceipt; }, readData: function (d, k) { return read(d, k || 'id'); }, reviewed: function () { return uploaded && !busy && review.checked; }, requireReviewed: function () { if (api.reviewed()) return true; root.scrollIntoView({ block: 'start', behavior: 'smooth' }); review.focus({ preventScroll: true }); say(uploaded ? 'मुख्य विवरण जाँचेर ✓ लगाउनुहोस् · Review the main details and tick the box.' : 'पहिले परिचयपत्र राख्नुहोस् · Upload your ID first.', true); return false; }, restoreExisting: function () { uploaded = true; review.disabled = false; lock(false); say('पहिले पठाएको ID सुरक्षित छ। फेरि upload नगरी विवरण सच्याउन मिल्छ। · Your existing ID is retained.'); } };
    active = api; return api;
  }
  function setField(id, value, source) {
    var e = document.getElementById(id); if (!e || value == null || value === '' || e.disabled || e.readOnly || e.dataset.userEdited === '1') return false;
    var current = String(e.value || '').trim();
    if (current === String(value)) return false;
    if (current && !e.dataset.aiFilled && e.tagName !== 'SELECT') return false;
    if (source === 'form' && current && e.dataset.aiFilled === 'id') return false;
    if (e.tagName === 'SELECT' && ![].some.call(e.options, function (o) { return o.value === String(value) && !o.disabled; })) return false;
    e.value = String(value); e.dataset.aiFilled = source; e.classList.add('auto'); e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); return true;
  }
  window.ShgIntake = { mount: mount, receipt: function () { return active ? active.receipt() : ''; }, setField: setField, readData: function (d,k) { return active ? active.readData(d,k) : Promise.reject(new Error('Intake not ready')); }, reviewed: function () { return active && active.reviewed(); }, requireReviewed: function () { return active && active.requireReviewed(); }, restoreExisting: function () { if (active) active.restoreExisting(); } };
})();
