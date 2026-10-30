/* =====================================================================
   me.js — the member's own portal: ID card, visiting card, wallet,
   documents, profile, fingerprint sign-in. Phone first.
   ===================================================================== */
(function () {
  'use strict';
  var C = window.APME || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var money = function (n) { n = +n || 0; var s = Math.abs(n).toLocaleString('en-IN', { maximumFractionDigits: 2, minimumFractionDigits: Math.abs(n) % 1 ? 2 : 0 }); return '₹' + s; };

  var W = {
    en: { hi: 'Hello', signIn: 'Sign in', signLead: 'Agents: your agent code and mobile — a code comes on WhatsApp.', code: 'Agent code', mobile: 'Registered mobile',
      sendCode: 'Send code on WhatsApp', otp: 'Code from WhatsApp', verify: 'Sign in', fp: 'Sign in with fingerprint / face', staff: 'Office staff sign in', apply: 'Not an agent yet? Apply',
      t_id: 'ID', t_card: 'Card', t_wallet: 'Wallet', t_docs: 'Docs', t_me: 'Profile',
      tapFlip: 'Tap the card to see the back', download: 'Download', print: 'Print', share: 'Share', verifyLink: 'Verify link', copy: 'Copy link', copied: 'Copied',
      valid: 'Valid', suspended: 'Suspended', expired: 'Expired', revoked: 'Revoked', inactive: 'Not active', validTill: 'Valid till {d}',
      vcLead: 'Scanning the QR opens a WhatsApp chat with this number.', waOnCard: 'WhatsApp number printed on the card', otherPhone: 'Second phone (optional)', tagline: 'Line under the company name',
      greeting: 'Message ready when someone scans', greetingHint: 'Keep it short (about 3 words) — a long message is left off the printed QR so it still scans.', theme: 'Style', royal: 'Royal', gold: 'Gold', night: 'Night', save: 'Save', saved: 'Saved — your cards are updated', shareWa: 'Share on WhatsApp',
      available: 'Commission you can withdraw', holding: 'Company cash with you', month: 'Earned this month', withdraw: 'Withdraw', payIn: 'Pay company', statement: 'Statement', payout: 'Payout',
      passbook: 'Passbook', requests: 'Requests', none: 'Nothing yet', amount: 'Amount', all: 'All', note: 'Note (optional)', send: 'Send request', withdrawLead: 'The office pays it to', notSet: 'add payout details in Profile',
      payLead: 'Pay with any UPI app, then send the reference (UTR).', payUpi: 'Pay with UPI app', utr: 'UTR / reference', proof: 'Screenshot (optional)', purpose: 'What is it for?', handover: 'Cash I collected', security: 'Security deposit',
      method: 'Paid by', sentOffice: 'Sent — the office will confirm', from: 'From', to: 'To', getPdf: 'Get PDF',
      commission: 'Commission', commission_void: 'Commission reversed', payout_e: 'Paid to you', cash_due: 'Cash collected', cash_handover: 'Cash handed over', adjustment: 'Adjustment',
      docs: 'My documents', kyc: 'KYC', docFront: 'ID (front)', docBack: 'ID (back) / address proof', replace: 'Replace', addFile: 'Add', open: 'Open',
      photo: 'Photo', photoLead: 'Your ID card uses this photo.', changePhoto: 'Change photo', camera: 'Camera', details: 'Details', blood: 'Blood group', emergency: 'Emergency contact', emName: 'Name', emPhone: 'Phone',
      dob: 'Date of birth', bank: 'Payout details', upi: 'UPI ID', holder: 'Account holder', account: 'Account number', ifsc: 'IFSC', esewa: 'eSewa', khalti: 'Khalti',
      security_t: 'Sign-in & security', fpOn: 'Turn on fingerprint / face sign-in', fpLead: 'Next time, sign in with your finger — no OTP.', fpAdded: 'Fingerprint sign-in is on', remove: 'Remove',
      waLinked: 'WhatsApp numbers linked to your code', waHow: 'On another WhatsApp, send: LINK', signOut: 'Sign out', lang: 'Language', office: 'Office', adminPanel: 'Admin panel',
      open_s: 'Waiting', paid_s: 'Paid', declined_s: 'Declined', confirmed_s: 'Confirmed', net: 'No connection — try again.', loading: 'Loading…' },
    ne: { hi: 'नमस्ते', signIn: 'लगइन', signLead: 'एजेन्ट: आफ्नो एजेन्ट कोड र मोबाइल — WhatsApp मा कोड आउँछ।', code: 'एजेन्ट कोड', mobile: 'दर्ता भएको मोबाइल',
      sendCode: 'WhatsApp मा कोड पठाउनुहोस्', otp: 'WhatsApp मा आएको कोड', verify: 'लगइन गर्नुहोस्', fp: 'औंठा / अनुहारले लगइन', staff: 'अफिस स्टाफ लगइन', apply: 'एजेन्ट बन्न आवेदन',
      t_id: 'ID', t_card: 'कार्ड', t_wallet: 'वालेट', t_docs: 'कागजात', t_me: 'प्रोफाइल',
      tapFlip: 'पछाडि हेर्न कार्डमा थिच्नुहोस्', download: 'डाउनलोड', print: 'प्रिन्ट', share: 'सेयर', verifyLink: 'प्रमाणित लिंक', copy: 'लिंक कपी', copied: 'कपी भयो',
      valid: 'मान्य', suspended: 'रोकिएको', expired: 'म्याद सकियो', revoked: 'रद्द', inactive: 'सक्रिय छैन', validTill: '{d} सम्म मान्य',
      vcLead: 'QR स्क्यान गर्दा यही नम्बरमा सिधै WhatsApp खुल्छ।', waOnCard: 'कार्डमा छापिने WhatsApp नम्बर', otherPhone: 'अर्को फोन (ऐच्छिक)', tagline: 'कम्पनीको नामतलको लाइन',
      greeting: 'स्क्यान गर्दा तयार हुने सन्देश', greetingHint: 'छोटो राख्नुहोस् (३ शब्द जति) — लामो सन्देश छापिएको QR मा राखिँदैन, ताकि QR सजिलै स्क्यान होस्।', theme: 'शैली', royal: 'शाही', gold: 'सुनौलो', night: 'रात', save: 'सेभ', saved: 'सेभ भयो — कार्ड अपडेट भयो', shareWa: 'WhatsApp मा सेयर',
      available: 'निकाल्न मिल्ने कमिसन', holding: 'तपाईंसँग कम्पनीको नगद', month: 'यो महिनाको कमाइ', withdraw: 'निकाल्ने', payIn: 'कम्पनीलाई तिर्ने', statement: 'स्टेटमेन्ट', payout: 'भुक्तानी',
      passbook: 'पासबुक', requests: 'अनुरोधहरू', none: 'अहिलेसम्म केही छैन', amount: 'रकम', all: 'सबै', note: 'टिप्पणी (ऐच्छिक)', send: 'अनुरोध पठाउनुहोस्', withdrawLead: 'अफिसले यहाँ पठाउँछ:', notSet: 'प्रोफाइलमा भुक्तानी विवरण थप्नुहोस्',
      payLead: 'कुनै पनि UPI एपले तिर्नुहोस्, अनि UTR पठाउनुहोस्।', payUpi: 'UPI एपले तिर्नुहोस्', utr: 'UTR / सन्दर्भ नम्बर', proof: 'स्क्रिनसट (ऐच्छिक)', purpose: 'केको लागि?', handover: 'मैले उठाएको नगद', security: 'धरौटी',
      method: 'माध्यम', sentOffice: 'पठाइयो — अफिसले पुष्टि गर्छ', from: 'देखि', to: 'सम्म', getPdf: 'PDF लिनुहोस्',
      commission: 'कमिसन', commission_void: 'कमिसन फिर्ता', payout_e: 'तपाईंलाई भुक्तानी', cash_due: 'नगद उठाइयो', cash_handover: 'नगद बुझाइयो', adjustment: 'मिलान',
      docs: 'मेरा कागजात', kyc: 'KYC', docFront: 'परिचयपत्र (अगाडि)', docBack: 'परिचयपत्र (पछाडि) / ठेगाना प्रमाण', replace: 'बदल्नुहोस्', addFile: 'थप्नुहोस्', open: 'खोल्नुहोस्',
      photo: 'फोटो', photoLead: 'तपाईंको ID कार्डमा यही फोटो।', changePhoto: 'फोटो बदल्नुहोस्', camera: 'क्यामेरा', details: 'विवरण', blood: 'रगत समूह', emergency: 'आपतकालीन सम्पर्क', emName: 'नाम', emPhone: 'फोन',
      dob: 'जन्म मिति', bank: 'भुक्तानी विवरण', upi: 'UPI ID', holder: 'खातावाला', account: 'खाता नम्बर', ifsc: 'IFSC', esewa: 'eSewa', khalti: 'Khalti',
      security_t: 'लगइन र सुरक्षा', fpOn: 'औंठा / अनुहार लगइन खोल्नुहोस्', fpLead: 'अर्को पटक OTP बिना औंठाले लगइन।', fpAdded: 'औंठा लगइन खुल्यो', remove: 'हटाउनुहोस्',
      waLinked: 'तपाईंको कोडसँग जोडिएका WhatsApp', waHow: 'अर्को WhatsApp बाट पठाउनुहोस्: LINK', signOut: 'लगआउट', lang: 'भाषा', office: 'अफिस', adminPanel: 'एडमिन प्यानल',
      open_s: 'पर्खँदै', paid_s: 'भुक्तानी भयो', declined_s: 'अस्वीकृत', confirmed_s: 'पुष्टि भयो', net: 'इन्टरनेट छैन — फेरि प्रयास गर्नुहोस्।', loading: 'खुल्दैछ…' },
    hi: { hi: 'नमस्ते', signIn: 'लॉगिन', signLead: 'एजेंट: अपना एजेंट कोड और मोबाइल — WhatsApp पर कोड आएगा।', code: 'एजेंट कोड', mobile: 'रजिस्टर्ड मोबाइल',
      sendCode: 'WhatsApp पर कोड भेजें', otp: 'WhatsApp पर आया कोड', verify: 'लॉगिन करें', fp: 'अंगूठे / चेहरे से लॉगिन', staff: 'ऑफिस स्टाफ लॉगिन', apply: 'एजेंट बनने के लिए आवेदन',
      t_id: 'ID', t_card: 'कार्ड', t_wallet: 'वॉलेट', t_docs: 'दस्तावेज़', t_me: 'प्रोफ़ाइल',
      tapFlip: 'पीछे देखने के लिए कार्ड दबाएँ', download: 'डाउनलोड', print: 'प्रिंट', share: 'शेयर', verifyLink: 'सत्यापन लिंक', copy: 'लिंक कॉपी', copied: 'कॉपी हुआ',
      valid: 'मान्य', suspended: 'रोका गया', expired: 'समाप्त', revoked: 'रद्द', inactive: 'सक्रिय नहीं', validTill: '{d} तक मान्य',
      vcLead: 'QR स्कैन करते ही इसी नंबर पर WhatsApp खुलता है।', waOnCard: 'कार्ड पर छपने वाला WhatsApp नंबर', otherPhone: 'दूसरा फ़ोन (वैकल्पिक)', tagline: 'कंपनी के नाम के नीचे की लाइन',
      greeting: 'स्कैन पर तैयार संदेश', greetingHint: 'छोटा रखें (लगभग 3 शब्द) — लंबा संदेश छपे QR में नहीं जाता, ताकि QR आसानी से स्कैन हो।', theme: 'शैली', royal: 'शाही', gold: 'सुनहरा', night: 'रात', save: 'सेव', saved: 'सेव हुआ — कार्ड अपडेट हुआ', shareWa: 'WhatsApp पर शेयर',
      available: 'निकालने लायक कमीशन', holding: 'आपके पास कंपनी का नकद', month: 'इस महीने की कमाई', withdraw: 'निकालें', payIn: 'कंपनी को भुगतान', statement: 'स्टेटमेंट', payout: 'भुगतान',
      passbook: 'पासबुक', requests: 'अनुरोध', none: 'अभी कुछ नहीं', amount: 'रकम', all: 'सब', note: 'टिप्पणी (वैकल्पिक)', send: 'अनुरोध भेजें', withdrawLead: 'ऑफिस यहाँ भेजेगा:', notSet: 'प्रोफ़ाइल में भुगतान विवरण जोड़ें',
      payLead: 'किसी भी UPI ऐप से भरें, फिर UTR भेजें।', payUpi: 'UPI ऐप से भरें', utr: 'UTR / संदर्भ', proof: 'स्क्रीनशॉट (वैकल्पिक)', purpose: 'किसलिए?', handover: 'मेरा जमा नकद', security: 'सिक्योरिटी डिपॉज़िट',
      method: 'माध्यम', sentOffice: 'भेजा — ऑफिस पुष्टि करेगा', from: 'से', to: 'तक', getPdf: 'PDF लें',
      commission: 'कमीशन', commission_void: 'कमीशन वापस', payout_e: 'आपको भुगतान', cash_due: 'नकद लिया', cash_handover: 'नकद जमा', adjustment: 'समायोजन',
      docs: 'मेरे दस्तावेज़', kyc: 'KYC', docFront: 'पहचान पत्र (आगे)', docBack: 'पहचान पत्र (पीछे) / पते का प्रमाण', replace: 'बदलें', addFile: 'जोड़ें', open: 'खोलें',
      photo: 'फ़ोटो', photoLead: 'आपके ID कार्ड पर यही फ़ोटो।', changePhoto: 'फ़ोटो बदलें', camera: 'कैमरा', details: 'विवरण', blood: 'ब्लड ग्रुप', emergency: 'आपातकालीन संपर्क', emName: 'नाम', emPhone: 'फ़ोन',
      dob: 'जन्म तिथि', bank: 'भुगतान विवरण', upi: 'UPI ID', holder: 'खाताधारक', account: 'खाता नंबर', ifsc: 'IFSC', esewa: 'eSewa', khalti: 'Khalti',
      security_t: 'लॉगिन और सुरक्षा', fpOn: 'अंगूठा / चेहरा लॉगिन चालू करें', fpLead: 'अगली बार बिना OTP अंगूठे से लॉगिन।', fpAdded: 'अंगूठा लॉगिन चालू', remove: 'हटाएँ',
      waLinked: 'आपके कोड से जुड़े WhatsApp', waHow: 'दूसरे WhatsApp से भेजें: LINK', signOut: 'लॉगआउट', lang: 'भाषा', office: 'ऑफिस', adminPanel: 'एडमिन पैनल',
      open_s: 'इंतज़ार', paid_s: 'भुगतान हुआ', declined_s: 'अस्वीकृत', confirmed_s: 'पुष्टि हुई', net: 'इंटरनेट नहीं — फिर कोशिश करें।', loading: 'खुल रहा है…' }
  };
  var lang = (function () { try { var s = localStorage.getItem('ap_lang'); if (s && W[s]) return s; } catch (e) {} var n = (navigator.language || 'en').toLowerCase(); return n.indexOf('ne') === 0 ? 'ne' : (n.indexOf('hi') === 0 ? 'hi' : 'en'); })();
  var t = function (k) { return (W[lang] && W[lang][k]) || W.en[k] || k; };
  var S = { me: null, wallet: null, tab: (location.hash || '#id').slice(1) };
  var TABS = [['id', 'idcard'], ['card', 'card'], ['wallet', 'wallet'], ['docs', 'doc'], ['me', 'user']];
  var ICON = {
    idcard: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="11" r="2.5"/><path d="M5.5 17c.8-2 2-3 3.5-3s2.7 1 3.5 3M15 10h3M15 13.5h3"/></svg>',
    card: '<svg viewBox="0 0 24 24"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M6 10h6M6 13.5h4M15.5 9.5h3v3h-3z"/></svg>',
    wallet: '<svg viewBox="0 0 24 24"><path d="M4 7.5V18a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-3"/><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H17v2.5"/><path d="M20 10.5h-4a2 2 0 0 0 0 4h4z"/></svg>',
    doc: '<svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
    user: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c1.3-3.4 3.8-5 7-5s5.7 1.6 7 5"/></svg>'
  };

  function api(action, body, isForm) {
    var opt = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf, 'Accept': 'application/json' } };
    if (body && !isForm) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    if (body && isForm) opt.body = body;
    return fetch(C.api + '?a=' + action, opt).then(function (r) {
      if (r.status === 401) { C.signed = false; renderLogin(); return { ok: false, error: '' }; }
      return r.json().catch(function () { return { ok: false, error: t('net') }; });
    }, function () { return { ok: false, error: t('net') }; });
  }
  function toast(m, bad) { var el = $('#toast'); el.textContent = m; el.className = 'ap-toast on' + (bad ? ' bad' : ''); clearTimeout(toast.x); toast.x = setTimeout(function () { el.className = 'ap-toast'; }, bad ? 4000 : 2200); }
  function busy(b, on) { if (!b) return; if (on) { b.dataset.l = b.innerHTML; b.disabled = true; b.innerHTML = '<span class="ap-spin"></span>'; } else { b.disabled = false; if (b.dataset.l) b.innerHTML = b.dataset.l; } }
  function sheet(html) { $('#sheetBody').innerHTML = html; $('#sheet').classList.add('on'); $('#sheet').setAttribute('aria-hidden', 'false'); }
  function closeSheet() { $('#sheet').classList.remove('on'); $('#sheet').setAttribute('aria-hidden', 'true'); }
  $('#sheet .scrim').addEventListener('click', closeSheet);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

  function setLang() {
    document.documentElement.lang = lang;
    $$('.ap-lang button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.lang === lang ? 'true' : 'false'); });
  }
  $$('.ap-lang button').forEach(function (b) { b.addEventListener('click', function () { lang = b.dataset.lang; try { localStorage.setItem('ap_lang', lang); } catch (e) {} setLang(); C.signed ? (renderTabs(), render()) : renderLogin(); }); });

  /* ======================= sign in ======================= */
  function renderLogin() {
    $('#tabs').hidden = true;
    $('#heroT').textContent = t('signIn'); $('#heroS').textContent = C.company;
    var fp = window.PublicKeyCredential ? '<div class="ap-or">·</div><button type="button" class="ap-btn ghost block" id="fpLogin">🔐 ' + esc(t('fp')) + '</button>' : '';
    $('#app').innerHTML = '<section class="ap-card"><h2>' + esc(t('signIn')) + '</h2><p class="lead">' + esc(t('signLead')) + '</p>'
      + '<label class="ap-field"><span>' + esc(t('code')) + '</span><input class="ap-in" id="lc" placeholder="SHG-0027" autocomplete="username" autocapitalize="characters"></label>'
      + '<label class="ap-field"><span>' + esc(t('mobile')) + '</span><input class="ap-in" id="lm" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="98XXXXXXXX"></label>'
      + '<button type="button" class="ap-btn wa block" id="lsend">💬 ' + esc(t('sendCode')) + '</button>'
      + '<div id="lotp" hidden><label class="ap-field" style="margin-top:14px"><span>' + esc(t('otp')) + '</span><input class="ap-in ap-mono" id="lo" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="letter-spacing:.4em;font-size:22px;text-align:center"></label>'
      + '<button type="button" class="ap-btn block" id="lverify">' + esc(t('verify')) + '</button></div>' + fp + '</section>'
      + '<section class="ap-card"><a class="ap-btn ghost block" href="' + esc(C.staffLogin) + '">🏢 ' + esc(t('staff')) + '</a>'
      + '<p style="text-align:center;margin:14px 0 0"><a href="' + esc(C.join) + '">' + esc(t('apply')) + ' →</a></p></section>';
    $('#lsend').addEventListener('click', function () {
      var b = this; busy(b, true);
      api('login_otp_start', { code: $('#lc').value, mobile: $('#lm').value }).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error || t('net'), true); return; } $('#lotp').hidden = false; $('#lo').focus(); });
    });
    $('#lverify').addEventListener('click', function () {
      var b = this; busy(b, true);
      api('login_otp_verify', { code: $('#lc').value, mobile: $('#lm').value, otp: $('#lo').value }).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error || t('net'), true); return; } C.csrf = r.data.csrf; C.signed = true; start(); });
    });
    var fb = $('#fpLogin'); if (fb) fb.addEventListener('click', function () { passkeyLogin(this); });
  }

  /* ---- passkeys (WebAuthn) ---- */
  var b64u = { enc: function (buf) { var s = String.fromCharCode.apply(null, new Uint8Array(buf)); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); },
               dec: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a.buffer; } };
  function passkeyLogin(btn) {
    busy(btn, true);
    api('pk_login_options', {}).then(function (r) {
      if (!r.ok) { busy(btn, false); toast(r.error, true); return; }
      var o = r.data; o.challenge = b64u.dec(o.challenge);
      return navigator.credentials.get({ publicKey: o }).then(function (cred) {
        return api('pk_login_verify', { credential: { id: cred.id, clientDataJSON: b64u.enc(cred.response.clientDataJSON), authenticatorData: b64u.enc(cred.response.authenticatorData), signature: b64u.enc(cred.response.signature) } });
      }).then(function (v) { busy(btn, false); if (!v.ok) { toast(v.error, true); return; } C.csrf = v.data.csrf; C.signed = true; start(); });
    }).catch(function (e) { busy(btn, false); toast(String(e && e.message || e), true); });
  }
  function passkeyAdd(btn) {
    busy(btn, true);
    api('pk_reg_options', {}).then(function (r) {
      if (!r.ok) { busy(btn, false); toast(r.error, true); return; }
      var o = r.data; o.challenge = b64u.dec(o.challenge); o.user.id = b64u.dec(o.user.id);
      o.excludeCredentials = (o.excludeCredentials || []).map(function (c) { return { type: 'public-key', id: b64u.dec(c.id) }; });
      return navigator.credentials.create({ publicKey: o }).then(function (cred) {
        return api('pk_reg_verify', { credential: { id: cred.id, clientDataJSON: b64u.enc(cred.response.clientDataJSON), attestationObject: b64u.enc(cred.response.attestationObject) } });
      }).then(function (v) { busy(btn, false); if (!v.ok) { toast(v.error, true); return; } toast('✓ ' + t('fpAdded')); load(); });
    }).catch(function (e) { busy(btn, false); toast(String(e && e.message || e), true); });
  }

  /* ======================= signed in ======================= */
  function start() { load(); }
  function load() {
    return Promise.all([api('me'), api('wallet')]).then(function (r) {
      if (!r[0].ok) { if (C.signed) toast(r[0].error || t('net'), true); return; }
      S.me = r[0].data; S.wallet = r[1].ok ? r[1].data : null; C.csrf = S.me.csrf || C.csrf;
      var m = S.me.member;
      $('#who').textContent = (m.idNo || '') + (m.idNo ? ' · ' : '') + m.designation;
      $('#heroT').textContent = t('hi') + ', ' + (m.name.split(' ')[0] || '');
      $('#heroS').innerHTML = '<span class="ap-status ' + esc(m.status) + '">● ' + esc(t(m.status)) + '</span>' + (m.validUntil ? ' &nbsp;<span style="opacity:.8">' + esc(t('validTill').replace('{d}', fmtDate(m.validUntil))) + '</span>' : '');
      renderTabs(); render();
    });
  }
  function fmtDate(d) { try { return new Date(d.replace(' ', 'T')).toLocaleDateString(lang === 'en' ? 'en-IN' : (lang === 'hi' ? 'hi-IN' : 'ne-NP'), { day: 'numeric', month: 'short', year: 'numeric' }); } catch (e) { return d; } }
  function renderTabs() {
    var tb = $('#tabs'); tb.hidden = false;
    tb.innerHTML = TABS.map(function (x) { return '<button type="button" role="tab" data-tab="' + x[0] + '" aria-selected="' + (S.tab === x[0]) + '">' + ICON[x[1]] + '<span>' + esc(t('t_' + x[0])) + '</span></button>'; }).join('');
    $$('#tabs button').forEach(function (b) { b.addEventListener('click', function () { S.tab = b.dataset.tab; history.replaceState(null, '', '#' + S.tab); renderTabs(); render(); window.scrollTo(0, 0); }); });
  }
  function render() {
    if (!S.me) return;
    var f = { id: tabId, card: tabCard, wallet: tabWallet, docs: tabDocs, me: tabMe }[S.tab] || tabId;
    $('#app').innerHTML = f();
    bindTab();
  }

  /* ---- ID ---- */
  function tabId() {
    var c = S.me.cards, v = '&t=' + Date.now();
    return '<section class="ap-card" style="padding:18px 14px"><div class="ap-cardview"><div class="ap-flip" id="flip" role="button" tabindex="0" aria-label="' + esc(t('tapFlip')) + '">'
      + '<div class="face"><img alt="ID card front" src="' + esc(c['id-front'] + v) + '" width="540" height="856"></div>'
      + '<div class="face b"><img alt="ID card back" src="' + esc(c['id-back'] + v) + '" width="540" height="856"></div></div></div>'
      + '<p class="ap-hint" style="text-align:center;margin-top:12px">↻ ' + esc(t('tapFlip')) + '</p>'
      + '<div class="ap-actions"><a class="ap-btn ghost sm" href="' + esc(c['id-front']) + '&dl=1">⬇ ' + esc(t('download')) + '</a>'
      + '<a class="ap-btn ghost sm" href="' + esc(c['id-back']) + '&dl=1">⬇ ' + esc(t('download')) + ' (2)</a>'
      + '<a class="ap-btn ghost sm" href="/me/print.php" target="_blank" rel="noopener">🖨 ' + esc(t('print')) + '</a>'
      + '<button type="button" class="ap-btn ghost sm" data-copy="' + esc(S.me.verify) + '">🔗 ' + esc(t('verifyLink')) + '</button></div></section>'
      + quickNav();
  }
  function quickNav() {
    var b = S.wallet ? S.wallet.balances : { commission: 0, cash: 0 };
    return '<section class="ap-card"><div class="ap-row" style="justify-content:space-between"><div><div class="ap-caps">' + esc(t('available')) + '</div><div style="font-size:26px;font-weight:700">' + money(b.commission) + '</div></div>'
      + '<button type="button" class="ap-btn sm" data-go="wallet">' + esc(t('t_wallet')) + ' →</button></div></section>';
  }

  /* ---- Visiting card ---- */
  function tabCard() {
    var m = S.me.member, c = S.me.cards, v = '&t=' + Date.now();
    var themes = ['royal', 'gold', 'night'].map(function (x) { return '<button type="button" class="ap-chip" data-theme="' + x + '" aria-pressed="' + (m.theme === x) + '">' + esc(t(x)) + '</button>'; }).join('');
    return '<section class="ap-card"><p class="lead">' + esc(t('vcLead')) + '</p>'
      + '<div class="ap-vc"><img alt="Visiting card front" src="' + esc(c['vc-front'] + v) + '" width="889" height="508"></div>'
      + '<div class="ap-vc" style="margin-top:12px"><img alt="Visiting card back" src="' + esc(c['vc-back'] + v) + '" width="889" height="508"></div>'
      + '<div class="ap-actions"><button type="button" class="ap-btn wa sm" id="shareVc">💬 ' + esc(t('shareWa')) + '</button>'
      + '<a class="ap-btn ghost sm" href="' + esc(c['vc-front']) + '&dl=1">⬇ ' + esc(t('download')) + '</a>'
      + '<a class="ap-btn ghost sm" href="/me/print.php?only=vc" target="_blank" rel="noopener">🖨 ' + esc(t('print')) + '</a>'
      + '<button type="button" class="ap-btn ghost sm" data-copy="' + esc(S.me.share) + '">🔗 ' + esc(t('copy')) + '</button></div></section>'
      + '<section class="ap-card"><form id="vcForm"><label class="ap-field"><span>' + esc(t('waOnCard')) + '</span><input class="ap-in" name="vc_whatsapp" type="tel" value="' + esc(m.waDisplay) + '" placeholder="+91 98765 43210"></label>'
      + '<label class="ap-field"><span>' + esc(t('otherPhone')) + '</span><input class="ap-in" name="vc_phone" type="tel" value="' + esc(m.call !== m.wa ? m.callDisplay : '') + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('tagline')) + '</span><input class="ap-in" name="vc_tagline" maxlength="120" value="' + esc(m.tagline) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('greeting')) + '</span><input class="ap-in" name="vc_greeting" maxlength="160" value="' + esc(m.greeting) + '"><small class="ap-hint">' + esc(t('greetingHint')) + '</small></label>'
      + '<div class="ap-field"><span>' + esc(t('theme')) + '</span><div class="ap-chips" id="themes">' + themes + '</div></div>'
      + '<button type="submit" class="ap-btn block">' + esc(t('save')) + '</button></form></section>';
  }

  /* ---- Wallet ---- */
  function tabWallet() {
    var w = S.wallet;
    if (!w) return '<section class="ap-card">' + esc(t('net')) + '</section>';
    var b = w.balances, s = w.summary;
    var rows = (w.entries || []).map(entryRow).join('') || '<li><span class="t"><small>' + esc(t('none')) + '</small></span></li>';
    var req = (w.payouts || []).map(function (r) { return reqRow('💸', t('withdraw'), r.amount, r.status, r.at, r.note); })
      .concat((w.deposits || []).map(function (r) { return reqRow('💵', t('payIn') + (r.utr ? ' · ' + r.utr : ''), r.amount, r.status, r.at, r.note); })).join('');
    return '<section class="ap-money"><div class="lbl">' + esc(t('available')) + '</div><div class="amt">' + money(b.commission) + '</div>'
      + '<div class="sub"><span>' + esc(t('holding')) + ' <b>' + money(b.cash) + '</b></span><span>' + esc(t('month')) + ' <b>' + money(s.earnedMonth) + '</b></span></div></section>'
      + '<div class="ap-quick"><button type="button" data-sheet="withdraw"><span class="q blue">↗</span>' + esc(t('withdraw')) + '</button>'
      + '<button type="button" data-sheet="pay"><span class="q green">₹</span>' + esc(t('payIn')) + '</button>'
      + '<button type="button" data-sheet="statement"><span class="q gold">📄</span>' + esc(t('statement')) + '</button>'
      + '<button type="button" data-go="me"><span class="q violet">🏦</span>' + esc(t('payout')) + '</button></div>'
      + (req ? '<section class="ap-card"><h3>' + esc(t('requests')) + '</h3><ul class="ap-pass">' + req + '</ul></section>' : '')
      + '<section class="ap-card"><h3>' + esc(t('passbook')) + '</h3><ul class="ap-pass">' + rows + '</ul></section>';
  }
  function entryRow(e) {
    var cash = e.account === 'cash', plus = e.amount >= 0;
    var ic = cash ? (plus ? ['🔸', 'gold'] : ['✅', 'green']) : (plus ? ['＋', 'green'] : ['↗', 'blue']);
    var label = t(e.type === 'payout' ? 'payout_e' : e.type);
    return '<li><span class="ic q ' + ic[1] + '">' + ic[0] + '</span><span class="t"><b>' + esc(label) + '</b><small>' + esc(fmtDate(e.at)) + (e.ref ? ' · ' + esc(e.ref) : '') + (e.note ? ' · ' + esc(e.note) : '') + '</small></span>'
      + '<span class="a ' + (cash ? 'cash' : (plus ? 'plus' : 'minus')) + '">' + (plus ? '+' : '−') + money(e.amount) + '</span></li>';
  }
  function reqRow(ic, label, amt, status, at, note) {
    return '<li><span class="ic q blue">' + ic + '</span><span class="t"><b>' + esc(label) + '</b><small>' + esc(fmtDate(at)) + (note ? ' · ' + esc(note) : '') + '</small></span>'
      + '<span style="text-align:right"><span class="a">' + money(amt) + '</span><br><span class="ap-pill ' + esc(status) + '">' + esc(t(status + '_s')) + '</span></span></li>';
  }
  function sheetWithdraw() {
    var due = S.wallet.balances.commission;
    sheet('<h2>' + esc(t('withdraw')) + '</h2><p class="lead">' + esc(t('withdrawLead')) + ' <b>' + esc(S.wallet.payoutTo || t('notSet')) + '</b></p>'
      + '<label class="ap-field"><span>' + esc(t('amount')) + '</span><input class="ap-in" id="wa" type="number" inputmode="decimal" min="1" max="' + due + '" value="' + (due > 0 ? due : '') + '"></label>'
      + '<div class="ap-chips" style="margin-bottom:12px">' + [500, 1000, 2000].filter(function (x) { return x < due; }).map(function (x) { return '<button type="button" class="ap-chip" data-amt="' + x + '">' + money(x) + '</button>'; }).join('')
      + '<button type="button" class="ap-chip" data-amt="' + due + '">' + esc(t('all')) + ' ' + money(due) + '</button></div>'
      + '<label class="ap-field"><span>' + esc(t('note')) + '</span><input class="ap-in" id="wn" maxlength="200"></label>'
      + '<button type="button" class="ap-btn block" id="wgo">' + esc(t('send')) + '</button>');
    $$('#sheet [data-amt]').forEach(function (c) { c.addEventListener('click', function () { $('#wa').value = c.dataset.amt; }); });
    $('#wgo').addEventListener('click', function () {
      var b = this; busy(b, true);
      api('withdraw', { amount: +$('#wa').value, note: $('#wn').value }).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error, true); return; } closeSheet(); toast('✓ ' + (r.message || t('sentOffice'))); load(); });
    });
  }
  function sheetPay() {
    var cash = S.wallet.balances.cash;
    sheet('<h2>' + esc(t('payIn')) + '</h2><p class="lead">' + esc(t('payLead')) + '</p>'
      + '<label class="ap-field"><span>' + esc(t('amount')) + '</span><input class="ap-in" id="pa" type="number" inputmode="decimal" min="1" value="' + (cash > 0 ? cash : '') + '"></label>'
      + '<div id="payQr" style="text-align:center"></div>'
      + '<div class="ap-field"><span>' + esc(t('purpose')) + '</span><div class="ap-chips"><button type="button" class="ap-chip" data-pp="handover" aria-pressed="true">' + esc(t('handover')) + '</button><button type="button" class="ap-chip" data-pp="security" aria-pressed="false">' + esc(t('security')) + '</button></div></div>'
      + '<div class="ap-field"><span>' + esc(t('method')) + '</span><div class="ap-chips">' + ['upi', 'bank', 'cash', 'esewa'].map(function (x, i) { return '<button type="button" class="ap-chip" data-pm="' + x + '" aria-pressed="' + (i === 0) + '">' + x.toUpperCase() + '</button>'; }).join('') + '</div></div>'
      + '<label class="ap-field"><span>' + esc(t('utr')) + '</span><input class="ap-in ap-mono" id="pu" maxlength="40" autocomplete="off"></label>'
      + '<label class="ap-field"><span>' + esc(t('proof')) + '</span><input class="ap-in" id="pf" type="file" accept="image/*,application/pdf"></label>'
      + '<button type="button" class="ap-btn wa block" id="pgo">' + esc(t('send')) + '</button>');
    var pp = 'handover', pm = 'upi';
    $$('#sheet [data-pp]').forEach(function (c) { c.addEventListener('click', function () { pp = c.dataset.pp; $$('#sheet [data-pp]').forEach(function (x) { x.setAttribute('aria-pressed', x === c); }); }); });
    $$('#sheet [data-pm]').forEach(function (c) { c.addEventListener('click', function () { pm = c.dataset.pm; $$('#sheet [data-pm]').forEach(function (x) { x.setAttribute('aria-pressed', x === c); }); }); });
    var showQr = function () {
      var a = +$('#pa').value; if (!(a > 0)) { $('#payQr').innerHTML = ''; return; }
      api('pay_link', { amount: a }).then(function (r) {
        if (!r.ok) { $('#payQr').innerHTML = '<div class="ap-note">' + esc(r.error) + '</div>'; return; }
        $('#payQr').innerHTML = '<img alt="UPI QR" src="' + esc(r.data.qr) + '" style="max-width:240px;margin:0 auto 10px;border-radius:16px;box-shadow:var(--sh-2)">'
          + '<a class="ap-btn gold block" href="' + esc(r.data.upi) + '">₹ ' + esc(t('payUpi')) + '</a><p class="ap-hint">UPI: <span class="ap-mono">' + esc(r.data.to) + '</span></p>';
      });
    };
    $('#pa').addEventListener('change', showQr); showQr();
    $('#pgo').addEventListener('click', function () {
      var b = this, fd = new FormData();
      fd.append('amount', $('#pa').value); fd.append('purpose', pp); fd.append('method', pm); fd.append('utr', $('#pu').value);
      if ($('#pf').files[0]) fd.append('proof', $('#pf').files[0]);
      busy(b, true);
      api('deposit', fd, true).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error, true); return; } closeSheet(); toast('✓ ' + t('sentOffice')); load(); });
    });
  }
  function sheetStatement() {
    var d = new Date(), from = new Date(d.getFullYear(), d.getMonth(), 1);
    var iso = function (x) { return x.toISOString().slice(0, 10); };
    sheet('<h2>' + esc(t('statement')) + '</h2><div class="ap-grid2"><label class="ap-field"><span>' + esc(t('from')) + '</span><input class="ap-in" id="sf" type="date" value="' + iso(from) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('to')) + '</span><input class="ap-in" id="st" type="date" value="' + iso(d) + '"></label></div>'
      + '<button type="button" class="ap-btn block" id="sgo">📄 ' + esc(t('getPdf')) + '</button>');
    $('#sgo').addEventListener('click', function () {
      var b = this; busy(b, true);
      api('statement', { from: $('#sf').value, to: $('#st').value }).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error, true); return; } closeSheet(); window.open(r.data.url, '_blank'); });
    });
  }

  /* ---- Documents ---- */
  function tabDocs() {
    var m = S.me.member;
    var doc = function (slot, label, has) {
      return '<li><span class="ic q ' + (has ? 'green' : 'gold') + '">' + (has ? '✓' : '＋') + '</span><span class="t"><b>' + esc(label) + '</b><small>' + esc(has ? m.idType + ' ' + (m.idMasked || '') : t('none')) + '</small></span>'
        + (has ? '<a class="ap-btn ghost sm" href="/admin/agent-kyc-file.php?agent=' + m.id + '&slot=' + slot + '" target="_blank" rel="noopener">' + esc(t('open')) + '</a>' : '')
        + '<label class="ap-btn ghost sm" style="position:relative;margin-left:6px">' + esc(has ? t('replace') : t('addFile')) + '<input type="file" accept="image/*,application/pdf" data-kyc="' + slot + '" style="position:absolute;inset:0;opacity:0"></label></li>';
    };
    return '<section class="ap-card"><div class="ap-row" style="justify-content:space-between"><h2>' + esc(t('docs')) + '</h2><span class="ap-status ' + (m.kycStatus === 'verified' ? 'valid' : (m.kycStatus === 'rejected' ? 'revoked' : 'expired')) + '">' + esc(t('kyc')) + ': ' + esc(m.kycStatus) + '</span></div>'
      + '<ul class="ap-pass">' + doc(1, t('docFront'), m.hasDoc1) + doc(2, t('docBack'), m.hasDoc2) + '</ul></section>';
  }

  /* ---- Profile ---- */
  function tabMe() {
    var m = S.me.member, bk = m.bank;
    var pk = (S.me.passkeys || []).map(function (p) { return '<li><span class="ic q violet">🔐</span><span class="t"><b>' + esc(p.label || 'Passkey') + '</b><small>' + esc(fmtDate(p.created_at)) + '</small></span><button type="button" class="ap-btn danger sm" data-pk="' + p.id + '">' + esc(t('remove')) + '</button></li>'; }).join('');
    var wa = (S.me.whatsapp || []).map(function (w) { return '<li><span class="ic q green">💬</span><span class="t"><b class="ap-mono">+' + esc(w.phone) + '</b><small>' + esc(fmtDate(w.verified_at)) + '</small></span></li>'; }).join('');
    var bloods = ['', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map(function (b) { return '<option' + (m.bloodGroup === b ? ' selected' : '') + '>' + b + '</option>'; }).join('');
    return '<section class="ap-card"><h3>' + esc(t('photo')) + '</h3><div class="ap-row" style="gap:16px"><div style="width:96px;height:116px;border-radius:14px;overflow:hidden;border:2px solid var(--gold);background:var(--soft);flex:0 0 auto">'
      + (m.photoUrl ? '<img alt="" src="' + esc(m.photoUrl) + '" style="width:100%;height:100%;object-fit:cover">' : '') + '</div>'
      + '<div><p class="lead" style="margin:0 0 10px">' + esc(t('photoLead')) + '</p><label class="ap-btn sm" style="position:relative">📸 ' + esc(t('changePhoto')) + '<input type="file" accept="image/*" capture="user" id="newPhoto" style="position:absolute;inset:0;opacity:0"></label></div></div></section>'
      + '<section class="ap-card"><h3>' + esc(t('details')) + '</h3><dl class="ap-review"><dt>ID</dt><dd class="ap-mono">' + esc(m.idNo || '—') + '</dd><dt>' + esc(t('mobile')) + '</dt><dd>' + esc(m.callDisplay || m.phone) + '</dd><dt>Branch</dt><dd>' + esc(m.branch || '—') + '</dd></dl>'
      + '<form id="pForm" style="margin-top:14px"><div class="ap-grid2"><label class="ap-field"><span>' + esc(t('dob')) + '</span><input class="ap-in" type="date" name="dob" value="' + esc(m.dob) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('blood')) + '</span><select class="ap-sel" name="blood_group">' + bloods + '</select></label></div>'
      + '<div class="ap-grid2"><label class="ap-field"><span>' + esc(t('emergency')) + ' · ' + esc(t('emName')) + '</span><input class="ap-in" name="emergency_name" value="' + esc(m.emergencyName) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('emPhone')) + '</span><input class="ap-in" type="tel" name="emergency_phone" value="' + esc(m.emergencyPhone) + '"></label></div>'
      + '<button type="submit" class="ap-btn block">' + esc(t('save')) + '</button></form></section>'
      + '<section class="ap-card"><h3>' + esc(t('bank')) + '</h3><form id="bForm"><label class="ap-field"><span>' + esc(t('upi')) + '</span><input class="ap-in" name="upi_id" value="' + esc(bk.upi) + '" placeholder="name@okaxis"></label>'
      + '<div class="ap-grid2"><label class="ap-field"><span>' + esc(t('holder')) + '</span><input class="ap-in" name="bank_holder" value="' + esc(bk.holder) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('account')) + '</span><input class="ap-in" name="bank_account" inputmode="numeric" placeholder="' + (bk.last4 ? '••••' + esc(bk.last4) : '') + '"></label></div>'
      + '<div class="ap-grid2"><label class="ap-field"><span>' + esc(t('ifsc')) + '</span><input class="ap-in" name="bank_ifsc" value="' + esc(bk.ifsc) + '"></label>'
      + '<label class="ap-field"><span>' + esc(t('esewa')) + '</span><input class="ap-in" name="esewa_id" value="' + esc(bk.esewa) + '"></label></div>'
      + '<button type="submit" class="ap-btn block">' + esc(t('save')) + '</button></form></section>'
      + '<section class="ap-card"><h3>' + esc(t('security_t')) + '</h3><p class="lead">' + esc(t('fpLead')) + '</p><ul class="ap-pass">' + pk + '</ul>'
      + (window.PublicKeyCredential ? '<button type="button" class="ap-btn block" id="fpAdd" style="margin-top:10px">🔐 ' + esc(t('fpOn')) + '</button>' : '')
      + '<h3 style="margin-top:18px">' + esc(t('waLinked')) + '</h3><ul class="ap-pass">' + (wa || '<li><span class="t"><small>' + esc(t('none')) + '</small></span></li>') + '</ul>'
      + '<p class="ap-hint">' + esc(t('waHow')) + ' <b class="ap-mono">' + esc(m.idNo || '') + '</b></p></section>'
      + '<section class="ap-card">' + (C.admin ? '<a class="ap-btn ghost block" href="' + esc(C.admin) + '" style="margin-bottom:10px">🏢 ' + esc(t('adminPanel')) + '</a>' : '')
      + '<button type="button" class="ap-btn danger block" id="out">' + esc(t('signOut')) + '</button><p class="ap-hint" style="text-align:center;margin-top:12px">' + esc(t('office')) + ': ' + esc(C.office) + '</p></section>';
  }

  /* ---- events ---- */
  function bindTab() {
    var f = $('#flip'); if (f) { var flip = function () { f.classList.toggle('back'); }; f.addEventListener('click', flip); f.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); } }); }
    $$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.copy); }); });
    $$('[data-go]').forEach(function (b) { b.addEventListener('click', function () { S.tab = b.dataset.go; history.replaceState(null, '', '#' + S.tab); renderTabs(); render(); }); });
    $$('[data-sheet]').forEach(function (b) { b.addEventListener('click', function () { ({ withdraw: sheetWithdraw, pay: sheetPay, statement: sheetStatement })[b.dataset.sheet](); }); });
    var vf = $('#vcForm');
    if (vf) {
      var theme = S.me.member.theme;
      $$('#themes [data-theme]').forEach(function (c) { c.addEventListener('click', function () { theme = c.dataset.theme; $$('#themes [data-theme]').forEach(function (x) { x.setAttribute('aria-pressed', x === c); }); }); });
      vf.addEventListener('submit', function (e) {
        e.preventDefault(); var b = $('button[type=submit]', vf), o = formObj(vf); o.vc_theme = theme; busy(b, true);
        api('profile_save', o).then(function (r) { busy(b, false); if (!r.ok) { toast(r.error, true); return; } toast('✓ ' + t('saved')); load(); });
      });
      $('#shareVc').addEventListener('click', shareCard);
    }
    var pf = $('#pForm'); if (pf) pf.addEventListener('submit', function (e) { e.preventDefault(); var b = $('button[type=submit]', pf); busy(b, true); api('profile_save', formObj(pf)).then(function (r) { busy(b, false); r.ok ? (toast('✓ ' + t('saved')), load()) : toast(r.error, true); }); });
    var bf = $('#bForm'); if (bf) bf.addEventListener('submit', function (e) { e.preventDefault(); var b = $('button[type=submit]', bf), o = formObj(bf); if (!o.bank_account) delete o.bank_account; busy(b, true); api('bank_save', o).then(function (r) { busy(b, false); r.ok ? (toast('✓ ' + (r.message || t('saved'))), load()) : toast(r.error, true); }); });
    var np = $('#newPhoto'); if (np) np.addEventListener('change', function () {
      if (!np.files[0]) return; var fd = new FormData(); fd.append('file', np.files[0]);
      toast(t('loading')); api('photo', fd, true).then(function (r) { r.ok ? (toast('✓ ' + (r.message || t('saved'))), load()) : toast(r.error, true); });
    });
    $$('[data-kyc]').forEach(function (inp) { inp.addEventListener('change', function () {
      if (!inp.files[0]) return; var fd = new FormData(); fd.append('file', inp.files[0]); fd.append('slot', inp.dataset.kyc);
      api('kyc_upload', fd, true).then(function (r) { r.ok ? (toast('✓ ' + (r.message || t('saved'))), load()) : toast(r.error, true); });
    }); });
    var fa = $('#fpAdd'); if (fa) fa.addEventListener('click', function () { passkeyAdd(fa); });
    $$('[data-pk]').forEach(function (b) { b.addEventListener('click', function () { api('pk_delete', { id: +b.dataset.pk }).then(function () { load(); }); }); });
    var out = $('#out'); if (out) out.addEventListener('click', function () { api('logout', {}).then(function () { C.signed = false; S.me = null; S.tab = 'id'; history.replaceState(null, '', location.pathname); renderLogin(); }); });
  }
  function formObj(f) { var o = {}; $$('[name]', f).forEach(function (el) { o[el.name] = el.value; }); return o; }
  function copy(text) {
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast('✓ ' + t('copied')); }, function () { prompt('', text); });
  }
  function shareCard() {
    var m = S.me.member, url = S.me.share;
    var text = m.name + ' — ' + m.designation + ' · ' + (m.idNo || '') + '\nWA: ' + m.waDisplay + '\n' + url;
    fetch(S.me.cards['vc-front'], { credentials: 'same-origin' }).then(function (r) { return r.blob(); }).then(function (blob) {
      var file = new File([blob], 'visiting-card.png', { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) return navigator.share({ files: [file], text: text });
      window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    }).catch(function () { window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank'); });
  }

  setLang();
  if (!C.ready) { $('#app').innerHTML = '<section class="ap-card"><div class="ap-note warn">Agent Pro is not installed yet.</div></section>'; return; }
  C.signed ? start() : renderLogin();
})();
