/* =====================================================================
   join.js — the Agent Pro join form. Six steps, phone first.
   No framework: one state object, render() per step, fetch() to
   api/agentpro.php. The draft key lives in localStorage so a dropped
   connection or a closed tab resumes where it stopped.
   ===================================================================== */
(function () {
  'use strict';
  var C = window.APJOIN || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

  /* ---------------- words ---------------- */
  var W = {
    en: {
      idSays: 'Your ID says:', useIt: 'Use this',
      heroTitle: 'Become an agent', heroSub: '5 minutes · your phone is enough', heroStaff: 'Join the team', loading: 'Loading…',
      back: 'Back', next: 'Continue', send: 'Send to office', skip: 'Skip', step: 'Step', of: 'of',
      s_who: 'About you', s_wa: 'WhatsApp', s_id: 'ID document', s_photo: 'Your photo', s_bank: 'Payout', s_finish: 'Finish',
      who_q: 'Who is applying?', who_lead: 'Keep your Aadhaar / citizenship card near you.', person: 'Individual agent', personSub: 'You sell tickets yourself', org: 'Travel agency', orgSub: 'A shop or company with a counter',
      fullName: 'Your full name', fullNameHint: 'Exactly as on your ID — it is printed on your ID card', orgName: 'Agency / shop name', contactName: 'Your name (owner / contact person)',
      inviteFor: 'Invitation for', wa_q: 'Verify your WhatsApp', wa_lead: 'Your login and ID card come on this number.', mobile: 'Mobile (WhatsApp)',
      sendCode: 'Send code on WhatsApp', codeSent: 'Enter the 6-digit code we sent on WhatsApp', verify: 'Verify', resend: 'Send again',
      or: 'or', reverseBtn: 'Verify by sending a WhatsApp', reverseLead: 'Tap below — WhatsApp opens with a message ready. Just press send.', reverseWait: 'Waiting for your message…',
      verified: 'WhatsApp verified', change: 'Change',
      id_q: 'Photo of your ID', id_lead: 'Take a clear photo — we fill the form for you.', idType: 'Which ID?', front: 'Front', back2: 'Back', tapPhoto: 'Tap to take a photo', optional: 'optional',
      reading: 'Reading your document…', aiReading: 'AI on our server is reading your ID — fields fill in by themselves', checkFields: 'Check every field — fix anything that is wrong.',
      dob: 'Date of birth', gender: 'Gender', male: 'Male', female: 'Female', other: 'Other', guardian: 'Father / husband name', idNumber: 'ID number',
      address: 'Address', city: 'City / town', district: 'District', state: 'State / province', pincode: 'PIN / postal code', expires: 'ID valid until',
      src_qr: 'from QR', src_mrz: 'from passport code', src_ai: 'read by AI', src_ocr: 'read from photo', dobBs: 'Converted from your BS date — please check',
      photo_q: 'Your photo', photo_lead: 'This photo goes on your ID card. Face the camera, good light, no cap or glasses.', openCam: 'Open camera', upload: 'Upload a photo', retake: 'Retake', usePhoto: 'Use this photo',
      lookCam: 'Fit your face in the oval', tooDark: 'Too dark — move to the light', camNo: 'Camera not available — upload a photo instead.', cardPreview: 'Your ID card will look like this',
      bank_q: 'Where should we pay your commission?', bank_lead: 'Optional — you can add this later in your account.', upi: 'UPI ID', holder: 'Account holder', account: 'Account number', ifsc: 'IFSC code', bankName: 'Bank name', branch: 'Branch',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'India', bankNp: 'Nepal',
      finish_q: 'Almost done', extras: 'More documents', extrasLead: 'PAN, address proof, shop licence… (optional)', addDoc: 'Add a document', docLabel: 'What is it?', thumb: 'Thumb impression', thumbLead: 'Optional: photo of your thumb print on white paper',
      email: 'Email', review: 'Check your details', consent: 'I confirm these details are true and allow S Hari Global to keep my documents for verification.',
      fpNote: 'After approval you can sign in to your account with your fingerprint or face.',
      done_t: 'Sent to the office', done_s: 'The office checks and approves — usually the same day. Your agent code, ID card and login come on WhatsApp.', done_ref: 'Your reference',
      done_wa: 'Message the office', done_me: 'Already approved? Open your account',
      closed: 'New applications are closed right now. Please call the office.', badInvite: 'This invitation link has expired. Ask the office for a new one.',
      need_kind: 'Choose individual or agency.', need_name: 'Enter your full name.', need_org: 'Enter the agency name.', need_wa: 'Verify your WhatsApp first.',
      need_front: 'Add a photo of the front of your ID.', need_dob: 'Enter your date of birth.', need_addr: 'Enter your address or city.', need_selfie: 'Add your photo.', need_consent: 'Please tick the box.',
      net: 'No connection — check your internet and try again.', saved: 'Saved', copy: 'Copy'
    },
    ne: {
      idSays: 'परिचयपत्रमा:', useIt: 'यही राख्नुहोस्',
      heroTitle: 'एजेन्ट बन्नुहोस्', heroSub: '५ मिनेट · मोबाइल मात्र पुग्छ', heroStaff: 'टिममा सामेल हुनुहोस्', loading: 'खुल्दैछ…',
      back: 'पछाडि', next: 'अगाडि बढ्नुहोस्', send: 'अफिसमा पठाउनुहोस्', skip: 'छोड्नुहोस्', step: 'चरण', of: 'मध्ये',
      s_who: 'तपाईंको बारेमा', s_wa: 'WhatsApp', s_id: 'परिचयपत्र', s_photo: 'तपाईंको फोटो', s_bank: 'भुक्तानी', s_finish: 'अन्तिम',
      who_q: 'को आवेदन दिँदै हुनुहुन्छ?', who_lead: 'आफ्नो आधार / नागरिकता नजिकै राख्नुहोस्।', person: 'व्यक्तिगत एजेन्ट', personSub: 'तपाईं आफैं टिकट बेच्नुहुन्छ', org: 'ट्राभल एजेन्सी', orgSub: 'काउन्टर भएको पसल वा कम्पनी',
      fullName: 'तपाईंको पूरा नाम', fullNameHint: 'परिचयपत्रमा जस्तै — यही नाम ID कार्डमा छापिन्छ', orgName: 'एजेन्सी / पसलको नाम', contactName: 'तपाईंको नाम (सञ्चालक / सम्पर्क व्यक्ति)',
      inviteFor: 'निमन्त्रणा:', wa_q: 'WhatsApp प्रमाणित गर्नुहोस्', wa_lead: 'तपाईंको लगइन र ID कार्ड यही नम्बरमा आउँछ।', mobile: 'मोबाइल (WhatsApp)',
      sendCode: 'WhatsApp मा कोड पठाउनुहोस्', codeSent: 'WhatsApp मा आएको ६ अंकको कोड लेख्नुहोस्', verify: 'प्रमाणित गर्नुहोस्', resend: 'फेरि पठाउनुहोस्',
      or: 'वा', reverseBtn: 'WhatsApp पठाएर प्रमाणित गर्नुहोस्', reverseLead: 'तल थिच्नुहोस् — WhatsApp मा सन्देश तयार हुन्छ, पठाउनुहोस् मात्र।', reverseWait: 'तपाईंको सन्देश पर्खँदै…',
      verified: 'WhatsApp प्रमाणित भयो', change: 'बदल्नुहोस्',
      id_q: 'परिचयपत्रको फोटो', id_lead: 'सफा फोटो खिच्नुहोस् — फारम हामी भर्छौँ।', idType: 'कुन परिचयपत्र?', front: 'अगाडि', back2: 'पछाडि', tapPhoto: 'फोटो खिच्न थिच्नुहोस्', optional: 'ऐच्छिक',
      reading: 'परिचयपत्र पढ्दै…', aiReading: 'हाम्रो सर्भरको AI ले पढ्दैछ — बाँकी कुरा आफैं भरिन्छ', checkFields: 'सबै कुरा जाँच्नुहोस् — गलत भए सच्याउनुहोस्।',
      dob: 'जन्म मिति', gender: 'लिङ्ग', male: 'पुरुष', female: 'महिला', other: 'अन्य', guardian: 'बुबा / पतिको नाम', idNumber: 'परिचयपत्र नम्बर',
      address: 'ठेगाना', city: 'सहर / गाउँ', district: 'जिल्ला', state: 'प्रदेश / राज्य', pincode: 'पिन / हुलाक कोड', expires: 'परिचयपत्रको म्याद',
      src_qr: 'QR बाट', src_mrz: 'पासपोर्ट कोडबाट', src_ai: 'AI ले पढ्यो', src_ocr: 'फोटोबाट पढियो', dobBs: 'वि.सं. बाट बदलिएको — कृपया जाँच्नुहोस्',
      photo_q: 'तपाईंको फोटो', photo_lead: 'यही फोटो ID कार्डमा जान्छ। क्यामेरातिर हेर्नुहोस्, उज्यालोमा, टोपी/चस्मा बिना।', openCam: 'क्यामेरा खोल्नुहोस्', upload: 'फोटो छान्नुहोस्', retake: 'फेरि खिच्नुहोस्', usePhoto: 'यो फोटो राख्नुहोस्',
      lookCam: 'अनुहार गोलाभित्र राख्नुहोस्', tooDark: 'अँध्यारो छ — उज्यालोमा जानुहोस्', camNo: 'क्यामेरा चलेन — फोटो छान्नुहोस्।', cardPreview: 'तपाईंको ID कार्ड यस्तो देखिन्छ',
      bank_q: 'कमिसन कहाँ पठाउने?', bank_lead: 'ऐच्छिक — पछि आफ्नो खातामा पनि थप्न सकिन्छ।', upi: 'UPI ID', holder: 'खातावालाको नाम', account: 'खाता नम्बर', ifsc: 'IFSC कोड', bankName: 'बैंकको नाम', branch: 'शाखा',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'भारत', bankNp: 'नेपाल',
      finish_q: 'लगभग सकियो', extras: 'थप कागजात', extrasLead: 'PAN, ठेगाना प्रमाण, पसल दर्ता… (ऐच्छिक)', addDoc: 'कागजात थप्नुहोस्', docLabel: 'के हो?', thumb: 'औंठाछाप', thumbLead: 'ऐच्छिक: सेतो कागजमा औंठाछापको फोटो',
      email: 'इमेल', review: 'विवरण जाँच्नुहोस्', consent: 'यी विवरण सत्य हुन् र प्रमाणिकरणका लागि एस हरि ग्लोबललाई मेरा कागजात राख्न अनुमति दिन्छु।',
      fpNote: 'स्वीकृत भएपछि औंठा वा अनुहारले आफ्नो खातामा लगइन गर्न सकिन्छ।',
      done_t: 'अफिसमा पठाइयो', done_s: 'अफिसले जाँचेर स्वीकृत गर्छ — प्रायः सोही दिन। एजेन्ट कोड, ID कार्ड र लगइन WhatsApp मा आउँछ।', done_ref: 'तपाईंको सन्दर्भ नम्बर',
      done_wa: 'अफिसलाई सन्देश', done_me: 'स्वीकृत भइसक्यो? खाता खोल्नुहोस्',
      closed: 'अहिले नयाँ आवेदन बन्द छ। अफिसमा फोन गर्नुहोस्।', badInvite: 'यो निमन्त्रणा लिंकको म्याद सकियो। अफिससँग नयाँ माग्नुहोस्।',
      need_kind: 'व्यक्तिगत वा एजेन्सी छान्नुहोस्।', need_name: 'पूरा नाम लेख्नुहोस्।', need_org: 'एजेन्सीको नाम लेख्नुहोस्।', need_wa: 'पहिले WhatsApp प्रमाणित गर्नुहोस्।',
      need_front: 'परिचयपत्रको अगाडिको फोटो थप्नुहोस्।', need_dob: 'जन्म मिति लेख्नुहोस्।', need_addr: 'ठेगाना वा सहर लेख्नुहोस्।', need_selfie: 'आफ्नो फोटो थप्नुहोस्।', need_consent: 'कृपया बाकसमा टिक लगाउनुहोस्।',
      net: 'इन्टरनेट छैन — जाँचेर फेरि प्रयास गर्नुहोस्।', saved: 'सेभ भयो', copy: 'कपी'
    },
    hi: {
      idSays: 'पहचान पत्र में:', useIt: 'यही रखें',
      heroTitle: 'एजेंट बनें', heroSub: '5 मिनट · सिर्फ मोबाइल काफी है', heroStaff: 'टीम से जुड़ें', loading: 'खुल रहा है…',
      back: 'पीछे', next: 'आगे बढ़ें', send: 'ऑफिस भेजें', skip: 'छोड़ें', step: 'चरण', of: 'में से',
      s_who: 'आपके बारे में', s_wa: 'WhatsApp', s_id: 'पहचान पत्र', s_photo: 'आपकी फ़ोटो', s_bank: 'भुगतान', s_finish: 'आखिरी',
      who_q: 'कौन आवेदन कर रहा है?', who_lead: 'अपना आधार / नागरिकता कार्ड पास रखें।', person: 'व्यक्तिगत एजेंट', personSub: 'आप खुद टिकट बेचते हैं', org: 'ट्रैवल एजेंसी', orgSub: 'काउंटर वाली दुकान या कंपनी',
      fullName: 'आपका पूरा नाम', fullNameHint: 'पहचान पत्र जैसा — यही नाम ID कार्ड पर छपेगा', orgName: 'एजेंसी / दुकान का नाम', contactName: 'आपका नाम (मालिक / संपर्क व्यक्ति)',
      inviteFor: 'निमंत्रण:', wa_q: 'WhatsApp सत्यापित करें', wa_lead: 'आपका लॉगिन और ID कार्ड इसी नंबर पर आएगा।', mobile: 'मोबाइल (WhatsApp)',
      sendCode: 'WhatsApp पर कोड भेजें', codeSent: 'WhatsApp पर आया 6 अंकों का कोड लिखें', verify: 'सत्यापित करें', resend: 'फिर भेजें',
      or: 'या', reverseBtn: 'WhatsApp भेजकर सत्यापित करें', reverseLead: 'नीचे दबाएँ — WhatsApp में संदेश तैयार होगा, बस भेजें।', reverseWait: 'आपके संदेश का इंतज़ार…',
      verified: 'WhatsApp सत्यापित', change: 'बदलें',
      id_q: 'पहचान पत्र की फ़ोटो', id_lead: 'साफ़ फ़ोटो लें — फ़ॉर्म हम भरेंगे।', idType: 'कौन सा पहचान पत्र?', front: 'आगे', back2: 'पीछे', tapPhoto: 'फ़ोटो लेने के लिए दबाएँ', optional: 'वैकल्पिक',
      reading: 'पहचान पत्र पढ़ रहे हैं…', aiReading: 'हमारे सर्वर का AI पढ़ रहा है — बाकी अपने आप भरेगा', checkFields: 'हर बात जाँचें — गलत हो तो सुधारें।',
      dob: 'जन्म तिथि', gender: 'लिंग', male: 'पुरुष', female: 'महिला', other: 'अन्य', guardian: 'पिता / पति का नाम', idNumber: 'पहचान पत्र नंबर',
      address: 'पता', city: 'शहर / गाँव', district: 'ज़िला', state: 'राज्य', pincode: 'पिन कोड', expires: 'पहचान पत्र की वैधता',
      src_qr: 'QR से', src_mrz: 'पासपोर्ट कोड से', src_ai: 'AI ने पढ़ा', src_ocr: 'फ़ोटो से पढ़ा', dobBs: 'वि.सं. से बदला गया — कृपया जाँचें',
      photo_q: 'आपकी फ़ोटो', photo_lead: 'यही फ़ोटो ID कार्ड पर जाएगी। कैमरे की ओर देखें, रोशनी में, टोपी/चश्मा के बिना।', openCam: 'कैमरा खोलें', upload: 'फ़ोटो चुनें', retake: 'फिर से लें', usePhoto: 'यह फ़ोटो रखें',
      lookCam: 'चेहरा गोले के अंदर रखें', tooDark: 'अंधेरा है — रोशनी में जाएँ', camNo: 'कैमरा नहीं चला — फ़ोटो चुनें।', cardPreview: 'आपका ID कार्ड ऐसा दिखेगा',
      bank_q: 'कमीशन कहाँ भेजें?', bank_lead: 'वैकल्पिक — बाद में अपने खाते में भी जोड़ सकते हैं।', upi: 'UPI ID', holder: 'खाताधारक', account: 'खाता नंबर', ifsc: 'IFSC कोड', bankName: 'बैंक का नाम', branch: 'शाखा',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'भारत', bankNp: 'नेपाल',
      finish_q: 'लगभग हो गया', extras: 'और दस्तावेज़', extrasLead: 'PAN, पते का प्रमाण, दुकान लाइसेंस… (वैकल्पिक)', addDoc: 'दस्तावेज़ जोड़ें', docLabel: 'यह क्या है?', thumb: 'अंगूठे का निशान', thumbLead: 'वैकल्पिक: सफ़ेद कागज़ पर अंगूठे के निशान की फ़ोटो',
      email: 'ईमेल', review: 'विवरण जाँचें', consent: 'मैं पुष्टि करता/करती हूँ कि ये विवरण सही हैं और सत्यापन के लिए एस हरि ग्लोबल को मेरे दस्तावेज़ रखने की अनुमति देता/देती हूँ।',
      fpNote: 'स्वीकृति के बाद आप अंगूठे या चेहरे से अपने खाते में लॉगिन कर सकेंगे।',
      done_t: 'ऑफिस भेज दिया', done_s: 'ऑफिस जाँचकर स्वीकृत करेगा — अक्सर उसी दिन। एजेंट कोड, ID कार्ड और लॉगिन WhatsApp पर आएगा।', done_ref: 'आपका संदर्भ नंबर',
      done_wa: 'ऑफिस को संदेश', done_me: 'स्वीकृत हो गया? खाता खोलें',
      closed: 'अभी नए आवेदन बंद हैं। ऑफिस को फ़ोन करें।', badInvite: 'यह निमंत्रण लिंक पुराना हो गया। ऑफिस से नया माँगें।',
      need_kind: 'व्यक्तिगत या एजेंसी चुनें।', need_name: 'पूरा नाम लिखें।', need_org: 'एजेंसी का नाम लिखें।', need_wa: 'पहले WhatsApp सत्यापित करें।',
      need_front: 'पहचान पत्र के आगे की फ़ोटो जोड़ें।', need_dob: 'जन्म तिथि लिखें।', need_addr: 'पता या शहर लिखें।', need_selfie: 'अपनी फ़ोटो जोड़ें।', need_consent: 'कृपया बॉक्स पर टिक करें।',
      net: 'इंटरनेट नहीं — जाँचकर फिर कोशिश करें।', saved: 'सेव हुआ', copy: 'कॉपी'
    }
  };
  var lang = (function () {
    try { var s = localStorage.getItem('ap_lang'); if (s && W[s]) return s; } catch (e) {}
    var n = (navigator.language || 'en').toLowerCase();
    return n.indexOf('ne') === 0 ? 'ne' : (n.indexOf('hi') === 0 ? 'hi' : 'en');
  })();
  var t = function (k) { return (W[lang] && W[lang][k]) || W.en[k] || k; };

  /* ---------------- state ---------------- */
  var S = { key: '', d: {}, step: 0, read: {}, src: {}, warn: {}, touched: {}, suggest: {}, previews: {}, aiPoll: null, revPoll: null, consent: false, otpSent: false };
  var STEPS = ['who', 'wa', 'id', 'photo', 'bank', 'finish'];
  var staffInvite = !!(C.inviteOk && C.role && C.role !== 'agent');
  var KEY = 'apjoin_' + (C.invite || 'public');

  /* ---------------- network ---------------- */
  function api(action, body, isForm) {
    var opt = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF-Token': C.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' };
    var url = C.api + '?a=' + encodeURIComponent(action);
    if (body) {
      if (isForm) { body.append('key', S.key); opt.body = body; }
      else { body.key = S.key; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    } else if (S.key) { url += '&key=' + encodeURIComponent(S.key); }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: t('net') }; });
    }, function () { return { ok: false, error: t('net') }; });
  }
  function toast(msg, bad) {
    var el = $('#toast'); el.textContent = msg; el.className = 'ap-toast on' + (bad ? ' bad' : '');
    clearTimeout(toast._t); toast._t = setTimeout(function () { el.className = 'ap-toast'; }, bad ? 4200 : 2400);
  }
  function busy(btn, on) {
    if (!btn) return;
    if (on) { btn.dataset.label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="ap-spin"></span>'; }
    else { btn.disabled = false; if (btn.dataset.label) btn.innerHTML = btn.dataset.label; }
  }

  /* ---------------- language ---------------- */
  function applyLang() {
    document.documentElement.lang = lang;
    $$('.ap-lang button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.lang === lang ? 'true' : 'false'); });
    $$('[data-t]').forEach(function (el) { el.textContent = t(el.dataset.t); });
    if (staffInvite) { $('.ap-hero h1').textContent = t('heroStaff'); }
  }
  $$('.ap-lang button').forEach(function (b) {
    b.addEventListener('click', function () { lang = b.dataset.lang; try { localStorage.setItem('ap_lang', lang); } catch (e) {} applyLang(); render(); });
  });

  /* ---------------- boot ---------------- */
  function boot() {
    applyLang();
    if (C.invite && !C.inviteOk) { return fatal(t('badInvite')); }
    if (!C.open) { return fatal(t('closed')); }
    try { S.key = localStorage.getItem(KEY) || ''; } catch (e) { S.key = ''; }
    (S.key ? api('get') : Promise.resolve({ ok: false })).then(function (r) {
      if (r.ok) { take(r.data.draft); return; }
      return api('start', { invite: C.invite || '' }).then(function (s) {
        if (!s.ok) { return fatal(s.error || t('net')); }
        S.key = s.data.key; try { localStorage.setItem(KEY, S.key); } catch (e) {}
        take(s.data.draft);
      });
    });
  }
  function take(d) {
    S.d = d || {};
    if (S.d.status && S.d.status !== 'draft') { S.step = 6; render(); return; }
    // Resume at the first step that is not finished yet.
    var s = 0;
    if (S.d.full_name && (staffInvite || S.d.agent_kind)) s = 1;
    if (s === 1 && S.d.verified) s = 2;
    if (s === 2 && S.d.has && S.d.has.front && S.d.dob) s = 3;
    if (s === 3 && S.d.has && S.d.has.selfie) s = 4;
    S.step = s;
    render();
  }
  function fatal(msg) {
    $('#app').innerHTML = '<div class="ap-card"><div class="ap-note bad">' + esc(msg) + '</div><p class="lead" style="margin-top:12px">☎ ' + esc(C.office || '') + '</p></div>';
    $('#bar').hidden = true;
  }

  /* ---------------- rendering ---------------- */
  function head() {
    var n = STEPS.length;
    var idx = S.step + 1;
    var bars = '';
    for (var i = 0; i < STEPS.length; i++) {
      bars += '<i class="' + (i < S.step ? 'done' : (i === S.step ? 'on' : '')) + '"></i>';
    }
    return '<div class="ap-steps" aria-hidden="true">' + bars + '</div>'
      + '<div class="ap-step-label"><span>' + t('step') + ' ' + idx + ' ' + t('of') + ' ' + n + '</span><b>' + esc(t('s_' + STEPS[S.step])) + '</b></div>';
  }
  function field(name, label, opts) {
    opts = opts || {};
    var v = S.d[name] != null ? S.d[name] : '';
    var src = S.src[name] ? '<span class="ap-src ' + S.src[name] + '">' + esc(t('src_' + S.src[name])) + '</span>' : '';
    var opt = opts.optional ? ' <em>· ' + esc(t('optional')) + '</em>' : '';
    var warn = S.warn[name] ? '<div class="ap-hint" style="color:var(--warn)">⚠ ' + esc(t(S.warn[name])) + '</div>' : '';
    var hint = opts.hint ? '<div class="ap-hint">' + esc(opts.hint) + '</div>' : '';
    if (S.suggest[name] && S.suggest[name].v !== v) {
      hint += '<div class="ap-note" style="margin-top:8px;display:flex;gap:10px;align-items:center;justify-content:space-between">'
        + '<span>' + esc(t('idSays')) + ' <b>' + esc(S.suggest[name].v) + '</b></span>'
        + '<button type="button" class="ap-btn sm" data-use="' + name + '">' + esc(t('useIt')) + '</button></div>';
    }
    return '<label class="ap-field"><span>' + esc(label) + opt + src + '</span>'
      + '<input class="ap-in' + (S.src[name] && !S.touched[name] ? ' filled' : '') + '" name="' + name + '" value="' + esc(v) + '"'
      + (opts.type ? ' type="' + opts.type + '"' : '') + (opts.ac ? ' autocomplete="' + opts.ac + '"' : '')
      + (opts.mode ? ' inputmode="' + opts.mode + '"' : '') + (opts.max ? ' maxlength="' + opts.max + '"' : '') + '>' + hint + warn + '</label>';
  }
  function render() {
    stopPolls(false);
    var app = $('#app');
    if (S.step === 6) { $('#bar').hidden = true; app.innerHTML = renderDone(); return; }
    $('#bar').hidden = false;
    var body = { who: renderWho, wa: renderWa, id: renderId, photo: renderPhoto, bank: renderBank, finish: renderFinish }[STEPS[S.step]]();
    app.innerHTML = head() + body;
    $('#back').style.visibility = S.step === 0 ? 'hidden' : 'visible';
    $('#next').innerHTML = esc(S.step === 5 ? t('send') : t('next'));
    $('#next').className = 'ap-btn' + (S.step === 5 ? ' gold' : '');
    bind();
    var first = $('#app .ap-in:not([value]), #app .ap-in[value=""]');
    if (first && window.innerWidth > 700) first.focus();
  }

  function renderWho() {
    var invite = staffInvite ? '<div class="ap-note" style="margin-bottom:12px">✉️ ' + esc(t('inviteFor')) + ' <b>' + esc(C.designation || C.role) + '</b></div>' : '';
    var kind = S.d.agent_kind || '';
    var choice = staffInvite ? '' : '<div class="ap-choices" role="group" aria-label="' + esc(t('who_q')) + '">'
      + '<button type="button" class="ap-choice" data-kind="person" aria-pressed="' + (kind === 'person') + '"><span class="ico">🧑‍💼</span><b>' + esc(t('person')) + '</b><small>' + esc(t('personSub')) + '</small></button>'
      + '<button type="button" class="ap-choice" data-kind="org" aria-pressed="' + (kind === 'org') + '"><span class="ico">🏢</span><b>' + esc(t('org')) + '</b><small>' + esc(t('orgSub')) + '</small></button></div>';
    return '<section class="ap-card"><h2>' + esc(t('who_q')) + '</h2><p class="lead">' + esc(t('who_lead')) + '</p>' + invite + choice
      + '<div style="margin-top:16px">'
      + (kind === 'org' && !staffInvite ? field('org_name', t('orgName'), { ac: 'organization' }) : '')
      + field('full_name', kind === 'org' && !staffInvite ? t('contactName') : t('fullName'), { ac: 'name', hint: t('fullNameHint'), max: 120 })
      + '</div></section>';
  }

  function renderWa() {
    if (S.d.verified) {
      return '<section class="ap-card"><h2>' + esc(t('wa_q')) + '</h2><div class="ap-ok">✅ ' + esc(t('verified')) + ' · <span class="ap-mono">' + esc(S.d.phone || '') + '</span></div>'
        + '<p class="lead" style="margin-top:12px">' + esc(t('wa_lead')) + '</p><button type="button" class="ap-link" id="waChange">' + esc(t('change')) + '</button></section>';
    }
    var cc = S.d.country === 'NP' ? 'NP' : 'IN';
    return '<section class="ap-card"><h2>' + esc(t('wa_q')) + '</h2><p class="lead">' + esc(t('wa_lead')) + '</p>'
      + '<label class="ap-field"><span>' + esc(t('mobile')) + '</span><div class="ap-phone"><select class="ap-sel" id="cc" aria-label="Country">'
      + '<option value="IN"' + (cc === 'IN' ? ' selected' : '') + '>🇮🇳 +91</option><option value="NP"' + (cc === 'NP' ? ' selected' : '') + '>🇳🇵 +977</option></select>'
      + '<input class="ap-in" id="phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" placeholder="98XXXXXXXX" value="' + esc(S.d.phone || '') + '"></div></label>'
      + '<button type="button" class="ap-btn wa block" id="sendOtp">💬 ' + esc(t('sendCode')) + '</button>'
      + '<div id="otpBox"' + (S.otpSent ? '' : ' hidden') + '><p class="lead" style="margin:14px 0 4px">' + esc(t('codeSent')) + '</p>'
      + '<div class="ap-otp">' + [0, 1, 2, 3, 4, 5].map(function (i) { return '<input inputmode="numeric" maxlength="1" autocomplete="' + (i === 0 ? 'one-time-code' : 'off') + '" aria-label="Digit ' + (i + 1) + '">'; }).join('') + '</div>'
      + '<div class="ap-row"><button type="button" class="ap-btn block" id="verifyOtp">' + esc(t('verify')) + '</button></div>'
      + '<button type="button" class="ap-link" id="resendOtp">' + esc(t('resend')) + '</button></div>'
      + '<div class="ap-or">' + esc(t('or')) + '</div>'
      + '<p class="lead">' + esc(t('reverseLead')) + '</p><button type="button" class="ap-btn ghost block" id="reverse">📲 ' + esc(t('reverseBtn')) + '</button>'
      + '<div id="revBox" hidden></div></section>';
  }

  function renderId() {
    var types = C.idTypes || {};
    var chips = Object.keys(types).map(function (k) {
      return '<button type="button" class="ap-chip" data-idt="' + k + '" aria-pressed="' + (S.d.id_type === k) + '">' + esc(types[k]) + '</button>';
    }).join('');
    var g = S.d.gender || '';
    var gender = '<div class="ap-field"><span>' + esc(t('gender')) + (S.src.gender ? '<span class="ap-src ' + S.src.gender + '">' + esc(t('src_' + S.src.gender)) + '</span>' : '') + '</span><div class="ap-chips" role="group">'
      + ['Male', 'Female', 'Other'].map(function (x) { return '<button type="button" class="ap-chip" data-gender="' + x + '" aria-pressed="' + (g === x) + '">' + esc(t(x.toLowerCase())) + '</button>'; }).join('') + '</div></div>';
    var expiryTypes = { passport: 1, dl: 1 };
    return '<section class="ap-card"><h2>' + esc(t('id_q')) + '</h2><p class="lead">' + esc(t('id_lead')) + '</p>'
      + '<div class="ap-field"><span>' + esc(t('idType')) + '</span><div class="ap-chips" role="group">' + chips + '</div></div>'
      + '<div class="ap-tiles">' + tile('front', t('front'), false) + tile('back', t('back2'), true) + '</div>'
      + '<div id="readBox"></div></section>'
      + '<section class="ap-card"><p class="lead">' + esc(t('checkFields')) + '</p>'
      + field('full_name', t('fullName'), { ac: 'name', max: 120 })
      + '<div class="ap-grid2 stack">' + field('dob', t('dob'), { type: 'date' }) + field('id_number', t('idNumber'), { max: 40, ac: 'off' }) + '</div>'
      + gender
      + field('guardian', t('guardian'), { optional: true, max: 120 })
      + field('address', t('address'), { ac: 'street-address', max: 255 })
      + '<div class="ap-grid2">' + field('city', t('city'), { ac: 'address-level2', max: 80 }) + field('district', t('district'), { optional: true, max: 80 }) + '</div>'
      + '<div class="ap-grid2">' + field('state', t('state'), { optional: true, ac: 'address-level1', max: 80 }) + field('pincode', t('pincode'), { optional: true, mode: 'numeric', max: 10, ac: 'postal-code' }) + '</div>'
      + (expiryTypes[S.d.id_type] ? field('id_expires_on', t('expires'), { type: 'date', optional: true }) : '')
      + '</section>';
  }
  function tile(slot, label, optional) {
    var has = S.d.has && S.d.has[slot];
    var prev = S.previews[slot] ? '<img alt="" src="' + S.previews[slot] + '">' : '';
    return '<label class="ap-tile' + (has ? ' has' : '') + '" data-slot="' + slot + '">' + prev
      + (has ? '<span class="tag">✓ ' + esc(label) + '</span>' : '<span class="big">📷</span><b>' + esc(label) + '</b><small>' + esc(optional ? t('optional') : t('tapPhoto')) + '</small>')
      + '<input type="file" accept="image/*,application/pdf" capture="environment" data-up="' + slot + '" aria-label="' + esc(label) + '"></label>';
  }

  function renderPhoto() {
    var src = S.previews.selfie || '';
    return '<section class="ap-card"><h2>' + esc(t('photo_q')) + '</h2><p class="lead">' + esc(t('photo_lead')) + '</p>'
      + '<div id="camArea">' + (src ? '<div class="ap-cam" style="max-width:300px"><img alt="" src="' + src + '"></div>' : '') + '</div>'
      + '<div class="ap-actions"><button type="button" class="ap-btn" id="openCam">📸 ' + esc(src ? t('retake') : t('openCam')) + '</button>'
      + '<label class="ap-btn ghost" style="position:relative">🖼️ ' + esc(t('upload')) + '<input type="file" accept="image/*" capture="user" id="selfieFile" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label></div>'
      + '</section><section class="ap-card"><h3>' + esc(t('cardPreview')) + '</h3>' + mini() + '<p class="ap-hint" style="text-align:center;margin-top:12px">🔐 ' + esc(t('fpNote')) + '</p></section>';
  }
  function mini() {
    var name = S.d.agent_kind === 'org' && S.d.org_name ? S.d.org_name : (S.d.full_name || '—');
    var desig = staffInvite ? (C.designation || C.role) : (S.d.agent_kind === 'org' ? 'Authorised Booking Agency' : 'Ticketing Agent');
    return '<div class="ap-mini"><div class="h">S HARI GLOBAL</div><div class="p">' + (S.previews.selfie ? '<img alt="" src="' + S.previews.selfie + '">' : '') + '</div>'
      + '<div class="n">' + esc(name) + '</div><div class="d">' + esc(desig) + '</div><div class="i">SHG-····</div></div>';
  }

  function renderBank() {
    var np = S.d.country === 'NP';
    return '<section class="ap-card"><h2>' + esc(t('bank_q')) + '</h2><p class="lead">' + esc(t('bank_lead')) + '</p>'
      + '<div class="ap-chips" role="group" style="margin-bottom:14px"><button type="button" class="ap-chip" data-bc="IN" aria-pressed="' + !np + '">🇮🇳 ' + esc(t('bankIn')) + '</button>'
      + '<button type="button" class="ap-chip" data-bc="NP" aria-pressed="' + np + '">🇳🇵 ' + esc(t('bankNp')) + '</button></div>'
      + (np
        ? field('esewa_id', t('esewa'), { optional: true, mode: 'numeric', max: 40 }) + field('khalti_id', t('khalti'), { optional: true, mode: 'numeric', max: 40 })
          + field('bank_name', t('bankName'), { optional: true, max: 120 }) + field('bank_holder', t('holder'), { optional: true, max: 120 })
          + '<label class="ap-field"><span>' + esc(t('account')) + ' <em>· ' + esc(t('optional')) + '</em></span><input class="ap-in" name="bank_account" inputmode="numeric" maxlength="24" autocomplete="off" placeholder="' + (S.d.bank_account_last4 ? '••••' + esc(S.d.bank_account_last4) : '') + '"></label>'
          + field('bank_branch', t('branch'), { optional: true, max: 120 })
        : field('upi_id', t('upi'), { optional: true, max: 100, hint: 'name@okaxis · 98XXXXXXXX@ybl' })
          + field('bank_holder', t('holder'), { optional: true, max: 120 })
          + '<label class="ap-field"><span>' + esc(t('account')) + ' <em>· ' + esc(t('optional')) + '</em></span><input class="ap-in" name="bank_account" inputmode="numeric" maxlength="24" autocomplete="off" placeholder="' + (S.d.bank_account_last4 ? '••••' + esc(S.d.bank_account_last4) : '') + '"></label>'
          + '<div class="ap-grid2">' + field('bank_ifsc', t('ifsc'), { optional: true, max: 11 }) + field('bank_name', t('bankName'), { optional: true, max: 120 }) + '</div>')
      + '</section>';
  }

  function renderFinish() {
    var ex = (S.d.extras || []).map(function (x) {
      return '<li class="ap-row" style="justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)"><span>📎 ' + esc(x.label) + '</span><button type="button" class="ap-link" data-drop="' + x.i + '">✕</button></li>';
    }).join('');
    var name = S.d.agent_kind === 'org' && S.d.org_name ? S.d.org_name + ' (' + (S.d.full_name || '') + ')' : (S.d.full_name || '');
    var types = C.idTypes || {};
    var rv = [[t('fullName'), name], [t('mobile'), S.d.phone], [t('dob'), S.d.dob], [t('idType'), types[S.d.id_type] || ''], [t('idNumber'), S.d.id_number_masked || ''],
      [t('address'), [S.d.address, S.d.city, S.d.district, S.d.state, S.d.pincode].filter(Boolean).join(', ')],
      [t('s_bank'), S.d.upi_id || (S.d.bank_account_last4 ? (S.d.bank_name || '') + ' ••••' + S.d.bank_account_last4 : '') || S.d.esewa_id || '—']];
    return '<section class="ap-card"><h2>' + esc(t('extras')) + '</h2><p class="lead">' + esc(t('extrasLead')) + '</p><ul style="list-style:none;margin:0 0 10px;padding:0">' + ex + '</ul>'
      + ((S.d.extras || []).length < (C.maxExtras || 6)
        ? '<div class="ap-row"><input class="ap-in" id="docLabel" placeholder="' + esc(t('docLabel')) + '" maxlength="60" style="flex:1"><label class="ap-btn ghost sm" style="position:relative">➕ ' + esc(t('addDoc'))
          + '<input type="file" accept="image/*,application/pdf" data-up="extra" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label></div>' : '')
      + '</section><section class="ap-card"><h3>' + esc(t('thumb')) + ' <span class="ap-hint">· ' + esc(t('optional')) + '</span></h3><p class="lead">' + esc(t('thumbLead')) + '</p>'
      + '<div class="ap-tiles" style="grid-template-columns:1fr">' + tile('thumb', t('thumb'), true) + '</div></section>'
      + '<section class="ap-card">' + field('email', t('email'), { optional: true, type: 'email', ac: 'email', max: 191 }) + '</section>'
      + '<section class="ap-card"><h3>' + esc(t('review')) + '</h3><dl class="ap-review">' + rv.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1] || '—') + '</dd>'; }).join('') + '</dl>'
      + '<label class="ap-row" style="align-items:flex-start;margin-top:16px;gap:12px;cursor:pointer"><input type="checkbox" id="consent" style="width:22px;height:22px;margin-top:2px"' + (S.consent ? ' checked' : '') + '><span style="font-size:14px">' + esc(t('consent')) + '</span></label></section>';
  }

  function renderDone() {
    var ref = S.d.ref || '';
    var waText = encodeURIComponent('Namaste! My application ' + ref + ' — ' + (S.d.full_name || ''));
    return '<section class="ap-card ap-done"><div class="seal">✓</div><h2>' + esc(t('done_t')) + '</h2>'
      + '<div class="ap-caps" style="margin-top:14px">' + esc(t('done_ref')) + '</div><div class="ap-ref">' + esc(ref) + '</div>'
      + '<p class="lead">' + esc(t('done_s')) + '</p>'
      + '<div class="ap-actions"><a class="ap-btn wa" href="https://wa.me/' + esc(C.wa) + '?text=' + waText + '" target="_blank" rel="noopener">💬 ' + esc(t('done_wa')) + '</a>'
      + '<a class="ap-btn ghost" href="' + esc(C.me) + '">🔐 ' + esc(t('done_me')) + '</a></div></section>';
  }

  /* ---------------- behaviour ---------------- */
  function bind() {
    $$('#app .ap-in').forEach(function (el) {
      el.addEventListener('input', function () {
        S.touched[el.name] = true; el.classList.remove('filled'); el.removeAttribute('aria-invalid');
        if (el.name) S.d[el.name] = el.value;
      });
    });
    $$('[data-kind]').forEach(function (b) {
      b.addEventListener('click', function () { collect(); S.d.agent_kind = b.dataset.kind; render(); });
    });
    $$('[data-idt]').forEach(function (b) {
      b.addEventListener('click', function () {
        collect(); S.d.id_type = b.dataset.idt; S.touched.id_type = true;
        S.d.country = (b.dataset.idt === 'citizenship' || b.dataset.idt === 'nid') ? 'NP' : (S.d.country || 'IN');
        saveQuiet(); render();
      });
    });
    $$('[data-gender]').forEach(function (b) {
      b.addEventListener('click', function () { collect(); S.d.gender = b.dataset.gender; S.touched.gender = true; render(); });
    });
    $$('[data-bc]').forEach(function (b) {
      b.addEventListener('click', function () { collect(); S.d.country = b.dataset.bc; render(); });
    });
    $$('[data-up]').forEach(function (inp) {
      inp.addEventListener('change', function () { if (inp.files && inp.files[0]) upload(inp.dataset.up, inp.files[0]); });
    });
    $$('[data-drop]').forEach(function (b) {
      b.addEventListener('click', function () { api('drop_extra', { i: +b.dataset.drop }).then(function (r) { if (r.ok) { S.d = r.data.draft; render(); } }); });
    });
    var c = $('#consent'); if (c) c.addEventListener('change', function () { S.consent = c.checked; });
    $$('[data-use]').forEach(function (b) {
      b.addEventListener('click', function () { collect(); var k = b.dataset.use; S.d[k] = S.suggest[k].v; S.src[k] = S.suggest[k].s; delete S.suggest[k]; delete S.touched[k]; saveQuiet(); render(); });
    });
    if (STEPS[S.step] === 'wa') bindWa();
    if (STEPS[S.step] === 'photo') bindPhoto();
  }
  /** Copy what is on screen into the draft (no network). */
  function collect() {
    $$('#app .ap-in[name]').forEach(function (el) { if (el.name !== 'bank_account' || el.value) S.d[el.name] = el.value; });
  }

  function bindWa() {
    var ch = $('#waChange');
    if (ch) { ch.addEventListener('click', function () { S.d.verified = false; S.otpSent = false; render(); }); return; }
    var send = function (btn) {
      var phone = ($('#phone').value || '').replace(/\D/g, '');
      if (phone.length < 10) { $('#phone').setAttribute('aria-invalid', 'true'); toast(t('mobile'), true); return; }
      busy(btn, true);
      api('otp_send', { phone: phone, country: $('#cc').value }).then(function (r) {
        busy(btn, false);
        if (!r.ok) { toast(r.error, true); return; }
        S.otpSent = true; S.d.phone = phone; S.d.country = $('#cc').value;
        $('#otpBox').hidden = false; var f = $('.ap-otp input'); if (f) f.focus();
        toast('✓ WhatsApp');
      });
    };
    $('#sendOtp').addEventListener('click', function () { send(this); });
    var rs = $('#resendOtp'); if (rs) rs.addEventListener('click', function () { send(this); });
    var boxes = $$('.ap-otp input');
    boxes.forEach(function (b, i) {
      b.addEventListener('input', function () {
        var v = b.value.replace(/\D/g, '');
        if (v.length > 1) { v.split('').slice(0, 6).forEach(function (ch, j) { if (boxes[j]) boxes[j].value = ch; }); boxes[Math.min(5, v.length - 1)].focus(); }
        else { b.value = v; if (v && boxes[i + 1]) boxes[i + 1].focus(); }
        if (boxes.every(function (x) { return x.value; })) $('#verifyOtp').click();
      });
      b.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !b.value && boxes[i - 1]) boxes[i - 1].focus(); });
    });
    $('#verifyOtp').addEventListener('click', function () {
      var code = boxes.map(function (x) { return x.value; }).join('');
      if (code.length !== 6) return;
      var btn = this; busy(btn, true);
      api('otp_verify', { code: code }).then(function (r) {
        busy(btn, false);
        if (!r.ok) { toast(r.error, true); boxes.forEach(function (x) { x.value = ''; }); boxes[0].focus(); return; }
        S.d = r.data.draft; toast('✓ ' + t('verified')); render();
      });
    });
    $('#reverse').addEventListener('click', function () {
      var btn = this; busy(btn, true);
      api('reverse', {}).then(function (r) {
        busy(btn, false);
        if (!r.ok) { toast(r.error, true); return; }
        var box = $('#revBox'); box.hidden = false;
        box.innerHTML = '<a class="ap-btn wa block" style="margin-top:12px" href="' + esc(r.data.link) + '" target="_blank" rel="noopener">💬 JOIN ' + esc(r.data.code) + '</a>'
          + '<div class="ap-reading" style="margin-top:12px"><span class="dot"></span>' + esc(t('reverseWait')) + '</div>';
        window.open(r.data.link, '_blank');
        var n = 0;
        S.revPoll = setInterval(function () {
          if (++n > 90) { stopPolls(true); return; }
          api('reverse_status').then(function (s) {
            if (s.ok && s.data.verified) { stopPolls(true); S.d.verified = true; S.d.phone = s.data.phone; toast('✓ ' + t('verified')); render(); }
          });
        }, 3000);
      });
    });
  }

  /* ---- documents ---- */
  function upload(slot, file) {
    var tileEl = $('.ap-tile[data-slot="' + slot + '"]');
    if (tileEl) { var b = document.createElement('div'); b.className = 'busy'; b.textContent = t('reading'); tileEl.appendChild(b); }
    var isImg = /^image\//.test(file.type);
    if (isImg && (slot === 'front' || slot === 'back' || slot === 'thumb')) {
      S.previews[slot] = URL.createObjectURL(file);
    }
    var qrP = (isImg && (slot === 'front' || slot === 'back')) ? readQr(file) : Promise.resolve('');
    Promise.all([qrP, isImg ? shrink(file, slot === 'selfie' ? 1400 : 2200) : Promise.resolve(file)]).then(function (res) {
      var fd = new FormData();
      fd.append('slot', slot);
      fd.append('file', res[1], (file.name || 'photo').replace(/\.[a-z0-9]+$/i, '') + (res[1].type === 'application/pdf' ? '.pdf' : '.jpg'));
      if (res[0]) fd.append('qr', res[0]);
      if (S.d.id_type) fd.append('id_type', S.d.id_type);
      if (slot === 'extra') fd.append('label', ($('#docLabel') && $('#docLabel').value) || 'Document');
      collect();
      return api('upload', fd, true);
    }).then(function (r) {
      if (!r.ok) { toast(r.error, true); render(); return; }
      S.d = Object.assign(S.d, r.data.draft, pickTyped());
      if (r.data.read) applyRead(r.data.read, false);
      render();
      if (r.data.aiQueued) pollAi();
    });
  }
  /** Keep what the person typed this session; the server copy may be older. */
  function pickTyped() {
    var o = {}; Object.keys(S.touched).forEach(function (k) { if (S.d[k] != null) o[k] = S.d[k]; }); return o;
  }
  var RANK = { qr: 4, mrz: 3, ai: 2, ocr: 1 };
  function applyRead(read, fromAi) {
    var f = read.fields || {}, src = read.sources || {};
    Object.keys(f).forEach(function (k) {
      var s = fromAi ? 'ai' : (src[k] || 'ocr');
      // Typed by the person: never overwrite — offer the ID's version when it is certain (QR / passport code).
      if (S.touched[k]) { if ((RANK[s] || 0) >= 3 && String(S.d[k] || '') !== String(f[k])) S.suggest[k] = { v: f[k], s: s }; return; }
      var key = k === 'id_expires_on' ? 'id_expires_on' : k;
      if (key === 'id_number') { if (S.src.id_number && (RANK[S.src.id_number] || 0) >= (RANK[s] || 0)) return; S.d.id_number = f[k]; S.src.id_number = s; return; }
      if (key === 'full_name_local' || key === 'country') { if (key === 'country' && !S.d.country) S.d.country = f[k]; return; }
      if (S.src[key] && (RANK[S.src[key]] || 0) >= (RANK[s] || 0)) return;
      if (!fromAi || !S.d[key]) { S.d[key] = f[k]; S.src[key] = s; }
    });
    (read.warnings || []).forEach(function (w) { if (w === 'dob_from_bs') S.warn.dob = 'dobBs'; });
    saveQuiet();
  }
  function saveQuiet() {
    var o = {};
    ['full_name', 'dob', 'gender', 'guardian', 'address', 'city', 'district', 'state', 'pincode', 'id_number', 'id_expires_on', 'country'].forEach(function (k) { if (S.d[k]) o[k] = S.d[k]; });
    if (S.d.id_type) o.id_type = S.d.id_type;
    api('save', o).then(function (r) { if (r.ok) { var keep = pickTyped(); S.d = Object.assign(r.data.draft, keep, { id_number: S.d.id_number }); } });
  }
  function pollAi() {
    var box = $('#readBox'); if (box) box.innerHTML = '<div class="ap-reading"><span class="dot"></span>' + esc(t('aiReading')) + '</div>';
    var n = 0;
    stopPolls(false);
    S.aiPoll = setInterval(function () {
      if (++n > 40) { stopPolls(false); var b = $('#readBox'); if (b) b.innerHTML = ''; return; }
      api('read_status').then(function (r) {
        if (!r.ok) return;
        if (Object.keys(r.data.ai || {}).length) { applyRead({ fields: r.data.ai }, true); if (STEPS[S.step] === 'id') render(); }
        if (!r.data.waiting) { stopPolls(false); var b2 = $('#readBox'); if (b2) b2.innerHTML = ''; }
      });
    }, 4000);
  }
  function stopPolls(rev) {
    if (rev && S.revPoll) { clearInterval(S.revPoll); S.revPoll = null; }
    if (!rev && S.aiPoll && STEPS[S.step] !== 'id') { /* keep polling only while the ID step is on screen */ clearInterval(S.aiPoll); S.aiPoll = null; }
    if (!rev && S.step !== 1 && S.revPoll) { clearInterval(S.revPoll); S.revPoll = null; }
  }

  /** Shrink a photo before upload: phones send 4–12 MB; 2200 px JPEG is plenty and quick on 3G. */
  function shrink(file, max) {
    return new Promise(function (resolve) {
      if (!/^image\//.test(file.type) || file.type === 'image/gif') { resolve(file); return; }
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
        var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob(function (b) { URL.revokeObjectURL(img.src); resolve(b || file); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { resolve(file); };
      img.src = URL.createObjectURL(file);
    });
  }

  /** Read an Aadhaar (or any) QR from the photo: the browser's own reader, else jsQR. */
  function readQr(file) {
    return createBitmap(file).then(function (bmp) {
      if (!bmp) return '';
      if ('BarcodeDetector' in window) {
        try {
          var det = new window.BarcodeDetector({ formats: ['qr_code'] });
          return det.detect(bmp).then(function (codes) { return (codes[0] && codes[0].rawValue) || jsqrRead(bmp); }, function () { return jsqrRead(bmp); });
        } catch (e) { return jsqrRead(bmp); }
      }
      return jsqrRead(bmp);
    }).catch(function () { return ''; });
  }
  function createBitmap(file) {
    if (window.createImageBitmap) return createImageBitmap(file).catch(function () { return null; });
    return new Promise(function (res) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = function () { res(null); }; i.src = URL.createObjectURL(file); });
  }
  function loadJsqr() {
    if (window.jsQR) return Promise.resolve(window.jsQR);
    return new Promise(function (res) { var s = document.createElement('script'); s.src = C.jsqr; s.onload = function () { res(window.jsQR || null); }; s.onerror = function () { res(null); }; document.head.appendChild(s); });
  }
  function jsqrRead(bmp) {
    return loadJsqr().then(function (jsQR) {
      if (!jsQR) return '';
      var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
      var sizes = [1600, 2400, Math.max(w, h)];
      for (var i = 0; i < sizes.length; i++) {
        var k = Math.min(1, sizes[i] / Math.max(w, h));
        var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
        var cx = cv.getContext('2d'); cx.drawImage(bmp, 0, 0, cv.width, cv.height);
        var data = cx.getImageData(0, 0, cv.width, cv.height);
        var r = jsQR(data.data, cv.width, cv.height, { inversionAttempts: 'attemptBoth' });
        if (r && r.data) return r.data;
        if (k === 1) break;
      }
      return '';
    });
  }

  /* ---- selfie ---- */
  var stream = null;
  function bindPhoto() {
    $('#openCam').addEventListener('click', openCam);
    $('#selfieFile').addEventListener('change', function () { var f = this.files && this.files[0]; if (f) { S.previews.selfie = URL.createObjectURL(f); upload('selfie', f); } });
  }
  function openCam() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { toast(t('camNo'), true); return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1080 }, height: { ideal: 1440 } }, audio: false }).then(function (s) {
      stream = s;
      $('#camArea').innerHTML = '<div class="ap-cam" style="max-width:340px"><video playsinline autoplay muted></video><div class="oval"></div><div class="say" id="camSay">' + esc(t('lookCam')) + '</div></div>'
        + '<button type="button" class="ap-shutter" id="shutter" aria-label="' + esc(t('usePhoto')) + '"></button>';
      var v = $('#camArea video'); v.srcObject = s;
      $('#shutter').addEventListener('click', function () { snap(v); });
      watchLight(v);
    }, function () { toast(t('camNo'), true); });
  }
  function watchLight(v) {
    var cv = document.createElement('canvas'); cv.width = 32; cv.height = 32; var cx = cv.getContext('2d');
    var tick = function () {
      if (!stream) return;
      try {
        cx.drawImage(v, 0, 0, 32, 32); var d = cx.getImageData(0, 0, 32, 32).data, sum = 0;
        for (var i = 0; i < d.length; i += 4) sum += d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114;
        var say = $('#camSay'); if (say) say.textContent = (sum / 1024) < 55 ? t('tooDark') : t('lookCam');
      } catch (e) {}
      setTimeout(tick, 600);
    };
    setTimeout(tick, 800);
  }
  function snap(v) {
    var w = v.videoWidth, h = v.videoHeight; if (!w) return;
    // Portrait 3:4 crop around the oval, mirrored like the preview.
    var tw = Math.min(w, Math.round(h * 0.75)), th = Math.round(tw / 0.75), sx = (w - tw) / 2, sy = Math.max(0, (h - th) / 2);
    var cv = document.createElement('canvas'); cv.width = 900; cv.height = 1200;
    var cx = cv.getContext('2d'); cx.translate(900, 0); cx.scale(-1, 1); cx.drawImage(v, sx, sy, tw, th, 0, 0, 900, 1200);
    stopCam();
    cv.toBlob(function (b) { S.previews.selfie = URL.createObjectURL(b); upload('selfie', new File([b], 'selfie.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', 0.9);
  }
  function stopCam() { if (stream) { stream.getTracks().forEach(function (tr) { tr.stop(); }); stream = null; } }

  /* ---- navigation ---- */
  function validate() {
    var st = STEPS[S.step], d = S.d, need = '';
    if (st === 'who') {
      if (!staffInvite && !d.agent_kind) need = 'need_kind';
      else if (!staffInvite && d.agent_kind === 'org' && !(d.org_name || '').trim()) need = 'need_org';
      else if ((d.full_name || '').trim().length < 2) need = 'need_name';
    } else if (st === 'wa' && !d.verified) need = 'need_wa';
    else if (st === 'id') {
      if (!(d.has && d.has.front)) need = 'need_front';
      else if ((d.full_name || '').trim().length < 2) need = 'need_name';
      else if (!d.dob) need = 'need_dob';
      else if (!(d.address || d.city)) need = 'need_addr';
    } else if (st === 'photo' && !(d.has && d.has.selfie)) need = 'need_selfie';
    else if (st === 'finish' && !S.consent) need = 'need_consent';
    if (need) { toast(t(need), true); }
    return !need;
  }
  function stepFields() {
    var st = STEPS[S.step], o = {};
    var pick = function (ks) { ks.forEach(function (k) { if (S.d[k] != null) o[k] = S.d[k]; }); };
    if (st === 'who') pick(['full_name', 'org_name', 'agent_kind']);
    if (st === 'id') pick(['id_type', 'full_name', 'dob', 'gender', 'guardian', 'id_number', 'address', 'city', 'district', 'state', 'pincode', 'id_expires_on', 'country']);
    if (st === 'bank') { pick(['upi_id', 'bank_holder', 'bank_ifsc', 'bank_name', 'bank_branch', 'esewa_id', 'khalti_id', 'country']); var acc = $('#app [name="bank_account"]'); if (acc && acc.value) o.bank_account = acc.value; }
    if (st === 'finish') pick(['email']);
    if (o.id_number && /^X{4}/.test(o.id_number)) delete o.id_number;   // a masked number from the QR is already stored
    return o;
  }
  $('#next').addEventListener('click', function () {
    collect();
    if (!validate()) return;
    var btn = this; busy(btn, true);
    var fields = stepFields();
    var p = Object.keys(fields).length ? api('save', fields) : Promise.resolve({ ok: true, data: { draft: S.d } });
    p.then(function (r) {
      if (!r.ok) { busy(btn, false); toast(r.error, true); return; }
      S.d = Object.assign(r.data.draft, pickTyped());
      if (STEPS[S.step] === 'finish') {
        return api('submit', { consent: '1' }).then(function (s) {
          busy(btn, false);
          if (!s.ok) { toast(s.error, true); return; }
          S.d.ref = s.data.ref; S.d.status = 'submitted'; S.step = 6; stopCam();
          try { localStorage.removeItem(KEY); } catch (e) {}
          render(); window.scrollTo(0, 0);
        });
      }
      busy(btn, false); stopCam();
      S.step = Math.min(5, S.step + 1); render(); window.scrollTo(0, 0);
    });
  });
  $('#back').addEventListener('click', function () { collect(); stopCam(); S.step = Math.max(0, S.step - 1); render(); window.scrollTo(0, 0); });

  boot();
})();
