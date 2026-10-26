/* =====================================================================
   team-passkey.js — Face ID / fingerprint sign-in for /team/ (23 Oct 2026).

   The phone's own lock (WebAuthn / passkeys) signs a challenge from
   /api/team-passkey.php. Nothing biometric leaves the phone: the server
   keeps the public key only. Buttons:
     [data-pk="login"]           sign in with the device
     [data-pk="add"]             add this device (signed in); the label
                                 comes from [data-pk-label]
     [data-pk-remove="<id>"]     forget a device
   Status text goes to #pkMsg. The buttons hide where the browser has no
   platform authenticator.
   ===================================================================== */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var NEXT = (document.currentScript && document.currentScript.getAttribute('data-next')) || '/team/';   // 23 Oct 2026: /admin/login.php too
  var msg = document.getElementById('pkMsg');
  function say(t, bad) { if (msg) { msg.textContent = t; msg.className = (msg.getAttribute('data-cls') || 'tp-msg') + ' ' + (bad ? 'bad' : 'info'); msg.hidden = !t; } }
  function b2u(buf) { var s = '', b = new Uint8Array(buf); for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function u2b(str) { var s = atob(String(str).replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4)); var b = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i); return b.buffer; }
  function api(method, op, body) {
    var o = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method === 'POST') { o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = csrf; o.body = JSON.stringify(Object.assign({ op: op }, body || {})); }
    return fetch('/api/team-passkey.php' + (method === 'GET' ? '?op=' + op : ''), o).then(function (r) { return r.json().then(function (j) { if (!r.ok || !j || j.ok === false) throw new Error((j && (j.error || j.message)) || 'Request failed'); return j; }); });
  }
  var ok = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
  var btns = document.querySelectorAll('[data-pk]');
  if (!ok) { [].forEach.call(btns, function (b) { b.hidden = true; }); var n = document.getElementById('pkNo'); if (n) n.hidden = false; return; }
  if (window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
    window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(function (yes) {
      if (!yes) { [].forEach.call(document.querySelectorAll('[data-pk="add"]'), function (b) { b.hidden = true; }); var n = document.getElementById('pkNo'); if (n) n.hidden = false; }
    }).catch(function () {});
  }

  function login(btn) {
    btn.disabled = true; say('📱 फोनको लक खोल्नुहोस् · Unlock with your face / finger …');
    api('GET', 'login-options').then(function (j) {
      var pk = j.publicKey; pk.challenge = u2b(pk.challenge);
      return navigator.credentials.get({ publicKey: pk });
    }).then(function (c) {
      if (!c) throw new Error('cancelled');
      var r = c.response;
      return api('POST', 'login', { cred: { id: c.id, clientDataJSON: b2u(r.clientDataJSON), authenticatorData: b2u(r.authenticatorData), signature: b2u(r.signature) } });
    }).then(function (j) { say('✅ भित्र आउनुभयो · Signed in'); location.href = j.next || NEXT; })
      .catch(function (e) { btn.disabled = false; say(String(e && e.message || e).indexOf('cancel') >= 0 || (e && e.name === 'NotAllowedError') ? 'रद्द भयो — पासवर्डले पनि भित्र जान सकिन्छ · Cancelled. You can sign in with your password.' : (e.message || 'Could not sign in'), true); });
  }
  function add(btn) {
    var lab = document.querySelector('[data-pk-label]'), label = lab ? lab.value : '';
    btn.disabled = true; say('📱 फोनको लक खोल्नुहोस् · Confirm with your face / finger …');
    api('GET', 'reg-options').then(function (j) {
      var pk = j.publicKey; pk.challenge = u2b(pk.challenge); pk.user.id = u2b(pk.user.id);
      pk.excludeCredentials = (pk.excludeCredentials || []).map(function (x) { return { type: 'public-key', id: u2b(x.id) }; });
      return navigator.credentials.create({ publicKey: pk });
    }).then(function (c) {
      if (!c) throw new Error('cancelled');
      var r = c.response, tr = r.getTransports ? r.getTransports() : [];
      return api('POST', 'register', { label: label, cred: { id: c.id, clientDataJSON: b2u(r.clientDataJSON), attestationObject: b2u(r.attestationObject), transports: tr } });
    }).then(function () { say('✅ यो डिभाइस थपियो · Device added'); setTimeout(function () { location.reload(); }, 600); })
      .catch(function (e) { btn.disabled = false; say(e && e.name === 'InvalidStateError' ? 'यो डिभाइस पहिले नै थपिएको छ · Already added on this device.' : (e && e.name === 'NotAllowedError' ? 'रद्द भयो · Cancelled.' : (e.message || 'Could not add')), true); });
  }
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-pk],[data-pk-remove]') : null;
    if (!b) return;
    e.preventDefault();
    if (b.getAttribute('data-pk') === 'login') return login(b);
    if (b.getAttribute('data-pk') === 'add') return add(b);
    var id = b.getAttribute('data-pk-remove');
    if (id && confirm('यो डिभाइस हटाउने? · Remove this device?')) {
      api('POST', 'remove', { id: parseInt(id, 10) }).then(function () { location.reload(); }).catch(function (er) { say(er.message || 'Could not remove', true); });
    }
  });
})();
