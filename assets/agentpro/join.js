/* =====================================================================
   join.js — the Agent Pro join form. Six steps, phone first.
   No framework: one state object, render() per step, fetch() to
   api/agentpro.php. The draft key lives in localStorage so a dropped
   connection or a closed tab resumes where it stopped — and in the
   "continue later" link /join.php?d=<key>, which opens it on any phone.

   30 Oct 2026 (owner: "euta form system"):
     * photo step: live selfie OR an uploaded passport photo, both equal;
       an optional thumb print turned into a black & white ink print here
     * the hand-filled paper form (photos or a PDF — pdf.js turns PDF pages
       into JPEGs in this browser) → api scan_form → ApFormRead fills the
       EMPTY boxes only (yellow = check, red = AI not sure)
     * OFFICE MODE (C.officeMode, join.php?office=1): role picker, only
       name + mobile + ID number + photo required, WhatsApp check may be
       skipped with a reason, "Approve now" issues the login + code
   ===================================================================== */
(function () {
  'use strict';
  var C = window.APJOIN || {};
  var OFF = !!C.officeMode;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

  /* ---------------- words ---------------- */
  var W = {
    en: {
      idSays: 'Your ID says:', useIt: 'Use this', paperSays: 'Paper form says:',
      heroTitle: 'Become an agent', heroSub: '5 minutes · your phone is enough', heroStaff: 'Join the team', loading: 'Loading…',
      heroOffice: 'Office form · fast', heroOfficeSub: 'Name, mobile, ID number and photo are enough — the rest is optional',
      back: 'Back', next: 'Continue', send: 'Send to office', skip: 'Skip', step: 'Step', of: 'of',
      s_who: 'About you', s_wa: 'WhatsApp', s_id: 'ID document', s_photo: 'Your photo', s_bank: 'Payout', s_finish: 'Finish',
      who_q: 'Who is applying?', who_lead: 'Keep your Aadhaar / citizenship card near you.', person: 'Individual agent', personSub: 'You sell tickets yourself', org: 'Travel agency', orgSub: 'A shop or company with a counter',
      fullName: 'Your full name', fullNameHint: 'Exactly as on your ID — it is printed on your ID card', orgName: 'Agency / shop name', contactName: 'Your name (owner / contact person)',
      inviteFor: 'Invitation for', wa_q: 'Verify your WhatsApp', wa_lead: 'Your login and ID card come on this number.', mobile: 'Mobile (WhatsApp)',
      sendCode: 'Send code on WhatsApp', codeSent: 'Enter the 6-digit code we sent on WhatsApp', verify: 'Verify', resend: 'Send again',
      or: 'or', reverseBtn: 'Verify by sending a WhatsApp', reverseLead: 'Tap below — WhatsApp opens with a message ready. Just press send.', reverseWait: 'Waiting for your message…',
      verified: 'WhatsApp verified', change: 'Change', whatsapp2: 'Another WhatsApp number', officeOk: 'Mobile accepted by the office',
      id_q: 'Photo of your ID', id_lead: 'Take a clear photo or upload it — we fill the form for you.', idType: 'Which ID?', front: 'Front', back2: 'Back', tapPhoto: 'Tap: camera or gallery', optional: 'optional',
      reading: 'Reading your document…', aiReading: 'AI on our server is reading your ID — fields fill in by themselves', checkFields: 'Check every field — fix anything that is wrong.',
      dob: 'Date of birth', gender: 'Gender', male: 'Male', female: 'Female', other: 'Other', guardian: 'Father / husband name', idNumber: 'ID number',
      address: 'Address', city: 'City / town', district: 'District', state: 'State / province', pincode: 'PIN / postal code', expires: 'ID valid until', pan: 'PAN number',
      src_qr: 'from QR', src_mrz: 'from passport code', src_ai: 'read by AI', src_ocr: 'read from photo', src_paper: 'from paper form', lowConf: 'AI not sure — check', dobBs: 'Converted from your BS date — please check',
      paper_q: 'Filled the paper form by hand?', paper_lead: 'Upload photos of the pages or the PDF — AI fills this form. Only empty boxes are filled.', paper_btn: 'Upload photos / PDF', paper_blank: 'Print blank form',
      paper_prep: 'Preparing page', paper_read: 'AI is reading your form… (can take up to 2 minutes)', paper_done: 'boxes filled from the paper form — check the yellow ones, fix the red ones.',
      paper_zero: 'Nothing new to fill — the pages are attached for the office.', paper_notform: 'This does not look like our form — check the photo. The pages are attached.', paper_pages: 'pages attached',
      reader_vps: 'read by our own server AI', reader_gemini: 'read by cloud AI', reader_tesseract: 'read by OCR (check carefully)', paper_pdfFail: 'This PDF could not be opened — send photos of the pages instead.',
      photo_q: 'Your photo', photo_lead: 'This photo goes on your ID card. Face the camera, good light, no cap or glasses.', openCam: 'Open camera', upload: 'Upload a photo', retake: 'Retake', usePhoto: 'Use this photo',
      selfieBtn: 'Live selfie', selfieSub: 'Camera opens here', passportBtn: 'Upload passport photo', passportSub: 'From gallery or files', photoOr: 'Either one is enough.',
      lookCam: 'Fit your face in the oval', tooDark: 'Too dark — move to the light', camNo: 'Camera not available — upload a photo instead.', cardPreview: 'Your ID card will look like this',
      thumb: 'Thumb print', thumbLead: 'Optional. Press your right thumb on an ink pad and then on white paper — or photograph the thumb close up. We turn it into a black & white print.',
      thumbCam: 'Take photo', thumbFile: 'Choose photo', thumbUse: 'Use this', thumbRetake: 'Retake', thumbSaved: 'Thumb print saved', thumbWeak: 'Lines are faint — try closer in good light (you can still use it).', thumbSkipped: 'Skipped', thumbAdd: 'Add thumb print', thumbWorking: 'Making the print…',
      bank_q: 'Where should we pay your commission?', bank_lead: 'Optional — you can add this later in your account.', upi: 'UPI ID', holder: 'Account holder', account: 'Account number', ifsc: 'IFSC code', bankName: 'Bank name', branch: 'Branch',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'India', bankNp: 'Nepal',
      finish_q: 'Almost done', extras: 'More documents', extrasLead: 'PAN, address proof, shop licence… (optional)', addDoc: 'Add a document', docLabel: 'What is it?',
      more_q: 'More about you', more_lead: 'Optional — it goes on your filled form.', occupation: 'Occupation', education: 'Education', experience: 'Experience', skills: 'Skills', languages: 'Languages you speak',
      email: 'Email', review: 'Check your details', consent: 'I confirm these details are true and allow S Hari Global to keep my documents for verification.',
      fpNote: 'After approval you can sign in to your account with your fingerprint or face.',
      later_t: 'Continue later — on any phone', later_lead: 'Open this link to finish. Keep it private: it opens your form.', later_lead_o: 'Send this link to the applicant to finish the form themselves.', copy: 'Copy', shareWa: 'WhatsApp', copied: 'Link copied',
      done_t: 'Sent to the office', done_s: 'The office checks and approves — usually the same day. Your agent code and login come on WhatsApp.', done_ref: 'Your reference',
      done_pdf: 'Download my filled form (PDF)', done_cards: 'Your ID card and visiting card come on WhatsApp after approval.', done_new: 'New application',
      done_wa: 'Message the office', done_me: 'Already approved? Open your account',
      closed: 'New applications are closed right now. Please call the office.', badInvite: 'This invitation link has expired. Ask the office for a new one.', badLink: 'This form link has expired. Please start again.',
      need_kind: 'Choose individual or agency.', need_name: 'Enter your full name.', need_org: 'Enter the agency name.', need_wa: 'Verify your WhatsApp first.',
      need_front: 'Add a photo of the front of your ID.', need_dob: 'Enter your date of birth.', need_addr: 'Enter your address or city.', need_selfie: 'Add your photo.', need_consent: 'Please tick the box.',
      need_idno: 'Enter the ID number.', need_role: 'Choose who the form is for.',
      officeMode: 'Office mode', officeNew: 'New form', role_q: 'Who is this form for?', designation: 'Designation (printed on the ID card)', roleFixed: 'The role is fixed once the form was sent.',
      o_skip_t: 'Office: accept this mobile without a WhatsApp code', o_reason: 'Reason (e.g. came to the office, no WhatsApp)', o_skip_btn: 'Accept this mobile', o_taken: 'This mobile already has an account:', openMember: 'Open member',
      o_saw: 'I saw the original ID (KYC verified)', o_approve: 'Approve now — issue the login and ID code', o_consent: 'The applicant agreed to the terms (signed the paper form or said yes).',
      o_send_ok: 'Approve & issue ID', o_done_t: 'Approved — ID issued', o_done_sent: 'Sent to approvals', o_code: 'ID / agent code',
      o_cards: 'Open member — ID card & visiting card', o_form: 'Filled form (PDF)', o_next: 'Create another', o_driver: 'Driver app ready: they sign in at /driver/ with this mobile + the PIN sent on WhatsApp, or tap “Start bus location” in /me/.', o_appr: 'Open approvals', o_kycNo: 'KYC still to check — the original ID was not seen.', o_kycMgr: 'A manager checks the KYC — the ID stays "to check" until then.',
      net: 'No connection — check your internet and try again.', saved: 'Saved'
    },
    ne: {
      idSays: 'परिचयपत्रमा:', useIt: 'यही राख्नुहोस्', paperSays: 'कागजी फारममा:',
      heroTitle: 'एजेन्ट बन्नुहोस्', heroSub: '५ मिनेट · मोबाइल मात्र पुग्छ', heroStaff: 'टिममा सामेल हुनुहोस्', loading: 'खुल्दैछ…',
      heroOffice: 'अफिस फारम · छिटो', heroOfficeSub: 'नाम, मोबाइल, परिचयपत्र नम्बर र फोटो पुग्छ — बाँकी ऐच्छिक',
      back: 'पछाडि', next: 'अगाडि बढ्नुहोस्', send: 'अफिसमा पठाउनुहोस्', skip: 'छोड्नुहोस्', step: 'चरण', of: 'मध्ये',
      s_who: 'तपाईंको बारेमा', s_wa: 'WhatsApp', s_id: 'परिचयपत्र', s_photo: 'तपाईंको फोटो', s_bank: 'भुक्तानी', s_finish: 'अन्तिम',
      who_q: 'को आवेदन दिँदै हुनुहुन्छ?', who_lead: 'आफ्नो आधार / नागरिकता नजिकै राख्नुहोस्।', person: 'व्यक्तिगत एजेन्ट', personSub: 'तपाईं आफैं टिकट बेच्नुहुन्छ', org: 'ट्राभल एजेन्सी', orgSub: 'काउन्टर भएको पसल वा कम्पनी',
      fullName: 'तपाईंको पूरा नाम', fullNameHint: 'परिचयपत्रमा जस्तै — यही नाम ID कार्डमा छापिन्छ', orgName: 'एजेन्सी / पसलको नाम', contactName: 'तपाईंको नाम (सञ्चालक / सम्पर्क व्यक्ति)',
      inviteFor: 'निमन्त्रणा:', wa_q: 'WhatsApp प्रमाणित गर्नुहोस्', wa_lead: 'तपाईंको लगइन र ID कार्ड यही नम्बरमा आउँछ।', mobile: 'मोबाइल (WhatsApp)',
      sendCode: 'WhatsApp मा कोड पठाउनुहोस्', codeSent: 'WhatsApp मा आएको ६ अंकको कोड लेख्नुहोस्', verify: 'प्रमाणित गर्नुहोस्', resend: 'फेरि पठाउनुहोस्',
      or: 'वा', reverseBtn: 'WhatsApp पठाएर प्रमाणित गर्नुहोस्', reverseLead: 'तल थिच्नुहोस् — WhatsApp मा सन्देश तयार हुन्छ, पठाउनुहोस् मात्र।', reverseWait: 'तपाईंको सन्देश पर्खँदै…',
      verified: 'WhatsApp प्रमाणित भयो', change: 'बदल्नुहोस्', whatsapp2: 'अर्को WhatsApp नम्बर', officeOk: 'अफिसले मोबाइल स्वीकार्यो',
      id_q: 'परिचयपत्रको फोटो', id_lead: 'सफा फोटो खिच्नुहोस् वा अपलोड गर्नुहोस् — फारम हामी भर्छौँ।', idType: 'कुन परिचयपत्र?', front: 'अगाडि', back2: 'पछाडि', tapPhoto: 'थिच्नुहोस्: क्यामेरा वा ग्यालरी', optional: 'ऐच्छिक',
      reading: 'परिचयपत्र पढ्दै…', aiReading: 'हाम्रो सर्भरको AI ले पढ्दैछ — बाँकी कुरा आफैं भरिन्छ', checkFields: 'सबै कुरा जाँच्नुहोस् — गलत भए सच्याउनुहोस्।',
      dob: 'जन्म मिति', gender: 'लिङ्ग', male: 'पुरुष', female: 'महिला', other: 'अन्य', guardian: 'बुबा / पतिको नाम', idNumber: 'परिचयपत्र नम्बर',
      address: 'ठेगाना', city: 'सहर / गाउँ', district: 'जिल्ला', state: 'प्रदेश / राज्य', pincode: 'पिन / हुलाक कोड', expires: 'परिचयपत्रको म्याद', pan: 'PAN नम्बर',
      src_qr: 'QR बाट', src_mrz: 'पासपोर्ट कोडबाट', src_ai: 'AI ले पढ्यो', src_ocr: 'फोटोबाट पढियो', src_paper: 'कागजी फारमबाट', lowConf: 'AI पक्का छैन — जाँच्नुहोस्', dobBs: 'वि.सं. बाट बदलिएको — कृपया जाँच्नुहोस्',
      paper_q: 'कागजी फारम हातले भर्नुभयो?', paper_lead: 'पानाहरूको फोटो वा PDF अपलोड गर्नुहोस् — AI ले यो फारम भर्छ। खाली ठाउँ मात्र भरिन्छ।', paper_btn: 'फोटो / PDF अपलोड', paper_blank: 'खाली फारम प्रिन्ट',
      paper_prep: 'पाना तयार गर्दै', paper_read: 'AI ले फारम पढ्दैछ… (२ मिनेटसम्म लाग्न सक्छ)', paper_done: 'ठाउँ कागजी फारमबाट भरियो — पहेँलो जाँच्नुहोस्, रातो सच्याउनुहोस्।',
      paper_zero: 'नयाँ भर्नुपर्ने केही भेटिएन — पानाहरू अफिसका लागि जोडियो।', paper_notform: 'यो हाम्रो फारम जस्तो देखिएन — फोटो जाँच्नुहोस्। पानाहरू जोडियो।', paper_pages: 'पाना जोडियो',
      reader_vps: 'हाम्रै सर्भरको AI ले पढ्यो', reader_gemini: 'क्लाउड AI ले पढ्यो', reader_tesseract: 'OCR ले पढ्यो (ध्यान दिएर जाँच्नुहोस्)', paper_pdfFail: 'यो PDF खुलेन — पानाहरूको फोटो पठाउनुहोस्।',
      photo_q: 'तपाईंको फोटो', photo_lead: 'यही फोटो ID कार्डमा जान्छ। क्यामेरातिर हेर्नुहोस्, उज्यालोमा, टोपी/चस्मा बिना।', openCam: 'क्यामेरा खोल्नुहोस्', upload: 'फोटो छान्नुहोस्', retake: 'फेरि खिच्नुहोस्', usePhoto: 'यो फोटो राख्नुहोस्',
      selfieBtn: 'लाइभ सेल्फी', selfieSub: 'यहीँ क्यामेरा खुल्छ', passportBtn: 'पासपोर्ट फोटो अपलोड', passportSub: 'ग्यालरी वा फाइलबाट', photoOr: 'जुनसुकै एउटा पुग्छ।',
      lookCam: 'अनुहार गोलाभित्र राख्नुहोस्', tooDark: 'अँध्यारो छ — उज्यालोमा जानुहोस्', camNo: 'क्यामेरा चलेन — फोटो छान्नुहोस्।', cardPreview: 'तपाईंको ID कार्ड यस्तो देखिन्छ',
      thumb: 'औंठाछाप', thumbLead: 'ऐच्छिक। दायाँ औंठा मसीमा थिचेर सेतो कागजमा छाप्नुहोस् — वा औंठाको नजिकबाट फोटो खिच्नुहोस्। हामी कालो-सेतो छाप बनाउँछौँ।',
      thumbCam: 'फोटो खिच्नुहोस्', thumbFile: 'फोटो छान्नुहोस्', thumbUse: 'यही राख्नुहोस्', thumbRetake: 'फेरि खिच्नुहोस्', thumbSaved: 'औंठाछाप सेभ भयो', thumbWeak: 'रेखा कम देखिए — उज्यालोमा नजिकबाट फेरि खिच्नुहोस् (यही पनि राख्न मिल्छ)।', thumbSkipped: 'छोडियो', thumbAdd: 'औंठाछाप थप्नुहोस्', thumbWorking: 'छाप बनाउँदै…',
      bank_q: 'कमिसन कहाँ पठाउने?', bank_lead: 'ऐच्छिक — पछि आफ्नो खातामा पनि थप्न सकिन्छ।', upi: 'UPI ID', holder: 'खातावालाको नाम', account: 'खाता नम्बर', ifsc: 'IFSC कोड', bankName: 'बैंकको नाम', branch: 'शाखा',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'भारत', bankNp: 'नेपाल',
      finish_q: 'लगभग सकियो', extras: 'थप कागजात', extrasLead: 'PAN, ठेगाना प्रमाण, पसल दर्ता… (ऐच्छिक)', addDoc: 'कागजात थप्नुहोस्', docLabel: 'के हो?',
      more_q: 'तपाईंको बारेमा थप', more_lead: 'ऐच्छिक — भरिएको फारममा जान्छ।', occupation: 'पेशा', education: 'शिक्षा', experience: 'अनुभव', skills: 'सीप', languages: 'बोल्ने भाषा',
      email: 'इमेल', review: 'विवरण जाँच्नुहोस्', consent: 'यी विवरण सत्य हुन् र प्रमाणिकरणका लागि एस हरि ग्लोबललाई मेरा कागजात राख्न अनुमति दिन्छु।',
      fpNote: 'स्वीकृत भएपछि औंठा वा अनुहारले आफ्नो खातामा लगइन गर्न सकिन्छ।',
      later_t: 'पछि भर्नुहोस् — जुनसुकै फोनबाट', later_lead: 'यो लिंक खोलेर बाँकी भर्नुहोस्। अरूलाई नदिनुहोस्: यसले तपाईंको फारम खोल्छ।', later_lead_o: 'आवेदकलाई आफैं भर्न यो लिंक पठाउनुहोस्।', copy: 'कपी', shareWa: 'WhatsApp', copied: 'लिंक कपी भयो',
      done_t: 'अफिसमा पठाइयो', done_s: 'अफिसले जाँचेर स्वीकृत गर्छ — प्रायः सोही दिन। एजेन्ट कोड र लगइन WhatsApp मा आउँछ।', done_ref: 'तपाईंको सन्दर्भ नम्बर',
      done_pdf: 'मेरो भरिएको फारम (PDF) डाउनलोड', done_cards: 'स्वीकृत भएपछि ID कार्ड र भिजिटिङ कार्ड WhatsApp मा आउँछ।', done_new: 'नयाँ आवेदन',
      done_wa: 'अफिसलाई सन्देश', done_me: 'स्वीकृत भइसक्यो? खाता खोल्नुहोस्',
      closed: 'अहिले नयाँ आवेदन बन्द छ। अफिसमा फोन गर्नुहोस्।', badInvite: 'यो निमन्त्रणा लिंकको म्याद सकियो। अफिससँग नयाँ माग्नुहोस्।', badLink: 'यो फारम लिंकको म्याद सकियो। फेरि सुरु गर्नुहोस्।',
      need_kind: 'व्यक्तिगत वा एजेन्सी छान्नुहोस्।', need_name: 'पूरा नाम लेख्नुहोस्।', need_org: 'एजेन्सीको नाम लेख्नुहोस्।', need_wa: 'पहिले WhatsApp प्रमाणित गर्नुहोस्।',
      need_front: 'परिचयपत्रको अगाडिको फोटो थप्नुहोस्।', need_dob: 'जन्म मिति लेख्नुहोस्।', need_addr: 'ठेगाना वा सहर लेख्नुहोस्।', need_selfie: 'आफ्नो फोटो थप्नुहोस्।', need_consent: 'कृपया बाकसमा टिक लगाउनुहोस्।',
      need_idno: 'परिचयपत्र नम्बर लेख्नुहोस्।', need_role: 'फारम कसको लागि हो छान्नुहोस्।',
      officeMode: 'अफिस मोड', officeNew: 'नयाँ फारम', role_q: 'यो फारम कसको लागि?', designation: 'पद (ID कार्डमा छापिने)', roleFixed: 'फारम पठाएपछि पद बदलिँदैन।',
      o_skip_t: 'अफिस: WhatsApp कोड बिना यो मोबाइल स्वीकार्ने', o_reason: 'कारण (जस्तै: अफिसमै आउनुभयो, WhatsApp छैन)', o_skip_btn: 'मोबाइल स्वीकार्नुहोस्', o_taken: 'यो मोबाइलको खाता पहिल्यै छ:', openMember: 'सदस्य खोल्नुहोस्',
      o_saw: 'सक्कल परिचयपत्र हेरेँ (KYC प्रमाणित)', o_approve: 'अहिल्यै स्वीकृत — लगइन र ID कोड दिनुहोस्', o_consent: 'आवेदक सर्तमा सहमत हुनुभयो (कागजी फारममा सही वा मौखिक सहमति)।',
      o_send_ok: 'स्वीकृत गरी ID दिनुहोस्', o_done_t: 'स्वीकृत — ID जारी भयो', o_done_sent: 'स्वीकृतिका लागि पठाइयो', o_code: 'ID / एजेन्ट कोड',
      o_cards: 'सदस्य खोल्नुहोस् — ID र भिजिटिङ कार्ड', o_form: 'भरिएको फारम (PDF)', o_next: 'अर्को बनाउनुहोस्', o_driver: 'ड्राइभर एप तयार: /driver/ मा यही मोबाइल + WhatsApp मा गएको PIN ले लगइन, वा /me/ मा “बस लोकेसन सुरु”।', o_appr: 'स्वीकृति सूची', o_kycNo: 'KYC बाँकी — सक्कल परिचयपत्र हेरिएन।', o_kycMgr: 'KYC म्यानेजरले जाँच्छ — त्यतिन्जेल ID "जाँच्न बाँकी" रहन्छ।',
      net: 'इन्टरनेट छैन — जाँचेर फेरि प्रयास गर्नुहोस्।', saved: 'सेभ भयो'
    },
    hi: {
      idSays: 'पहचान पत्र में:', useIt: 'यही रखें', paperSays: 'कागज़ी फ़ॉर्म में:',
      heroTitle: 'एजेंट बनें', heroSub: '5 मिनट · सिर्फ मोबाइल काफी है', heroStaff: 'टीम से जुड़ें', loading: 'खुल रहा है…',
      heroOffice: 'ऑफिस फ़ॉर्म · तेज़', heroOfficeSub: 'नाम, मोबाइल, पहचान नंबर और फ़ोटो काफी — बाकी वैकल्पिक',
      back: 'पीछे', next: 'आगे बढ़ें', send: 'ऑफिस भेजें', skip: 'छोड़ें', step: 'चरण', of: 'में से',
      s_who: 'आपके बारे में', s_wa: 'WhatsApp', s_id: 'पहचान पत्र', s_photo: 'आपकी फ़ोटो', s_bank: 'भुगतान', s_finish: 'आखिरी',
      who_q: 'कौन आवेदन कर रहा है?', who_lead: 'अपना आधार / नागरिकता कार्ड पास रखें।', person: 'व्यक्तिगत एजेंट', personSub: 'आप खुद टिकट बेचते हैं', org: 'ट्रैवल एजेंसी', orgSub: 'काउंटर वाली दुकान या कंपनी',
      fullName: 'आपका पूरा नाम', fullNameHint: 'पहचान पत्र जैसा — यही नाम ID कार्ड पर छपेगा', orgName: 'एजेंसी / दुकान का नाम', contactName: 'आपका नाम (मालिक / संपर्क व्यक्ति)',
      inviteFor: 'निमंत्रण:', wa_q: 'WhatsApp सत्यापित करें', wa_lead: 'आपका लॉगिन और ID कार्ड इसी नंबर पर आएगा।', mobile: 'मोबाइल (WhatsApp)',
      sendCode: 'WhatsApp पर कोड भेजें', codeSent: 'WhatsApp पर आया 6 अंकों का कोड लिखें', verify: 'सत्यापित करें', resend: 'फिर भेजें',
      or: 'या', reverseBtn: 'WhatsApp भेजकर सत्यापित करें', reverseLead: 'नीचे दबाएँ — WhatsApp में संदेश तैयार होगा, बस भेजें।', reverseWait: 'आपके संदेश का इंतज़ार…',
      verified: 'WhatsApp सत्यापित', change: 'बदलें', whatsapp2: 'दूसरा WhatsApp नंबर', officeOk: 'ऑफिस ने मोबाइल स्वीकार किया',
      id_q: 'पहचान पत्र की फ़ोटो', id_lead: 'साफ़ फ़ोटो लें या अपलोड करें — फ़ॉर्म हम भरेंगे।', idType: 'कौन सा पहचान पत्र?', front: 'आगे', back2: 'पीछे', tapPhoto: 'दबाएँ: कैमरा या गैलरी', optional: 'वैकल्पिक',
      reading: 'पहचान पत्र पढ़ रहे हैं…', aiReading: 'हमारे सर्वर का AI पढ़ रहा है — बाकी अपने आप भरेगा', checkFields: 'हर बात जाँचें — गलत हो तो सुधारें।',
      dob: 'जन्म तिथि', gender: 'लिंग', male: 'पुरुष', female: 'महिला', other: 'अन्य', guardian: 'पिता / पति का नाम', idNumber: 'पहचान पत्र नंबर',
      address: 'पता', city: 'शहर / गाँव', district: 'ज़िला', state: 'राज्य', pincode: 'पिन कोड', expires: 'पहचान पत्र की वैधता', pan: 'PAN नंबर',
      src_qr: 'QR से', src_mrz: 'पासपोर्ट कोड से', src_ai: 'AI ने पढ़ा', src_ocr: 'फ़ोटो से पढ़ा', src_paper: 'कागज़ी फ़ॉर्म से', lowConf: 'AI पक्का नहीं — जाँचें', dobBs: 'वि.सं. से बदला गया — कृपया जाँचें',
      paper_q: 'कागज़ी फ़ॉर्म हाथ से भरा है?', paper_lead: 'पन्नों की फ़ोटो या PDF अपलोड करें — AI यह फ़ॉर्म भरेगा। सिर्फ खाली जगह भरी जाएगी।', paper_btn: 'फ़ोटो / PDF अपलोड', paper_blank: 'खाली फ़ॉर्म प्रिंट',
      paper_prep: 'पन्ना तैयार', paper_read: 'AI फ़ॉर्म पढ़ रहा है… (2 मिनट तक लग सकते हैं)', paper_done: 'जगह कागज़ी फ़ॉर्म से भरी — पीली जाँचें, लाल सुधारें।',
      paper_zero: 'भरने को कुछ नया नहीं — पन्ने ऑफिस के लिए जुड़ गए।', paper_notform: 'यह हमारा फ़ॉर्म नहीं लगता — फ़ोटो जाँचें। पन्ने जुड़ गए।', paper_pages: 'पन्ने जुड़े',
      reader_vps: 'हमारे सर्वर के AI ने पढ़ा', reader_gemini: 'क्लाउड AI ने पढ़ा', reader_tesseract: 'OCR ने पढ़ा (ध्यान से जाँचें)', paper_pdfFail: 'यह PDF नहीं खुली — पन्नों की फ़ोटो भेजें।',
      photo_q: 'आपकी फ़ोटो', photo_lead: 'यही फ़ोटो ID कार्ड पर जाएगी। कैमरे की ओर देखें, रोशनी में, टोपी/चश्मा के बिना।', openCam: 'कैमरा खोलें', upload: 'फ़ोटो चुनें', retake: 'फिर से लें', usePhoto: 'यह फ़ोटो रखें',
      selfieBtn: 'लाइव सेल्फ़ी', selfieSub: 'यहीं कैमरा खुलेगा', passportBtn: 'पासपोर्ट फ़ोटो अपलोड', passportSub: 'गैलरी या फ़ाइल से', photoOr: 'कोई भी एक काफी है।',
      lookCam: 'चेहरा गोले के अंदर रखें', tooDark: 'अंधेरा है — रोशनी में जाएँ', camNo: 'कैमरा नहीं चला — फ़ोटो चुनें।', cardPreview: 'आपका ID कार्ड ऐसा दिखेगा',
      thumb: 'अंगूठे का निशान', thumbLead: 'वैकल्पिक। दायाँ अंगूठा स्याही पर दबाकर सफ़ेद कागज़ पर छापें — या अंगूठे की पास से फ़ोटो लें। हम इसे काला-सफ़ेद निशान बनाते हैं।',
      thumbCam: 'फ़ोटो लें', thumbFile: 'फ़ोटो चुनें', thumbUse: 'यही रखें', thumbRetake: 'फिर से लें', thumbSaved: 'अंगूठे का निशान सेव हुआ', thumbWeak: 'रेखाएँ कम दिखीं — रोशनी में पास से फिर लें (यही भी चलेगा)।', thumbSkipped: 'छोड़ा', thumbAdd: 'अंगूठा निशान जोड़ें', thumbWorking: 'निशान बन रहा है…',
      bank_q: 'कमीशन कहाँ भेजें?', bank_lead: 'वैकल्पिक — बाद में अपने खाते में भी जोड़ सकते हैं।', upi: 'UPI ID', holder: 'खाताधारक', account: 'खाता नंबर', ifsc: 'IFSC कोड', bankName: 'बैंक का नाम', branch: 'शाखा',
      esewa: 'eSewa ID', khalti: 'Khalti ID', bankIn: 'भारत', bankNp: 'नेपाल',
      finish_q: 'लगभग हो गया', extras: 'और दस्तावेज़', extrasLead: 'PAN, पते का प्रमाण, दुकान लाइसेंस… (वैकल्पिक)', addDoc: 'दस्तावेज़ जोड़ें', docLabel: 'यह क्या है?',
      more_q: 'आपके बारे में और', more_lead: 'वैकल्पिक — भरे फ़ॉर्म में जाएगा।', occupation: 'पेशा', education: 'शिक्षा', experience: 'अनुभव', skills: 'कौशल', languages: 'बोली जाने वाली भाषाएँ',
      email: 'ईमेल', review: 'विवरण जाँचें', consent: 'मैं पुष्टि करता/करती हूँ कि ये विवरण सही हैं और सत्यापन के लिए एस हरि ग्लोबल को मेरे दस्तावेज़ रखने की अनुमति देता/देती हूँ।',
      fpNote: 'स्वीकृति के बाद आप अंगूठे या चेहरे से अपने खाते में लॉगिन कर सकेंगे।',
      later_t: 'बाद में भरें — किसी भी फ़ोन से', later_lead: 'यह लिंक खोलकर बाकी भरें। किसी को न दें: इससे आपका फ़ॉर्म खुलता है।', later_lead_o: 'आवेदक को खुद भरने के लिए यह लिंक भेजें।', copy: 'कॉपी', shareWa: 'WhatsApp', copied: 'लिंक कॉपी हुआ',
      done_t: 'ऑफिस भेज दिया', done_s: 'ऑफिस जाँचकर स्वीकृत करेगा — अक्सर उसी दिन। एजेंट कोड और लॉगिन WhatsApp पर आएगा।', done_ref: 'आपका संदर्भ नंबर',
      done_pdf: 'मेरा भरा फ़ॉर्म (PDF) डाउनलोड', done_cards: 'स्वीकृति के बाद ID कार्ड और विज़िटिंग कार्ड WhatsApp पर आएगा।', done_new: 'नया आवेदन',
      done_wa: 'ऑफिस को संदेश', done_me: 'स्वीकृत हो गया? खाता खोलें',
      closed: 'अभी नए आवेदन बंद हैं। ऑफिस को फ़ोन करें।', badInvite: 'यह निमंत्रण लिंक पुराना हो गया। ऑफिस से नया माँगें।', badLink: 'यह फ़ॉर्म लिंक पुराना हो गया। फिर से शुरू करें।',
      need_kind: 'व्यक्तिगत या एजेंसी चुनें।', need_name: 'पूरा नाम लिखें।', need_org: 'एजेंसी का नाम लिखें।', need_wa: 'पहले WhatsApp सत्यापित करें।',
      need_front: 'पहचान पत्र के आगे की फ़ोटो जोड़ें।', need_dob: 'जन्म तिथि लिखें।', need_addr: 'पता या शहर लिखें।', need_selfie: 'अपनी फ़ोटो जोड़ें।', need_consent: 'कृपया बॉक्स पर टिक करें।',
      need_idno: 'पहचान पत्र नंबर लिखें।', need_role: 'फ़ॉर्म किसके लिए है चुनें।',
      officeMode: 'ऑफिस मोड', officeNew: 'नया फ़ॉर्म', role_q: 'यह फ़ॉर्म किसके लिए?', designation: 'पद (ID कार्ड पर छपेगा)', roleFixed: 'फ़ॉर्म भेजने के बाद पद नहीं बदलता।',
      o_skip_t: 'ऑफिस: WhatsApp कोड के बिना यह मोबाइल स्वीकार करें', o_reason: 'कारण (जैसे: ऑफिस आए, WhatsApp नहीं)', o_skip_btn: 'मोबाइल स्वीकार करें', o_taken: 'इस मोबाइल का खाता पहले से है:', openMember: 'सदस्य खोलें',
      o_saw: 'असली पहचान पत्र देखा (KYC सत्यापित)', o_approve: 'अभी स्वीकृत — लॉगिन और ID कोड दें', o_consent: 'आवेदक शर्तों से सहमत (कागज़ी फ़ॉर्म पर हस्ताक्षर या मौखिक हाँ)।',
      o_send_ok: 'स्वीकृत कर ID दें', o_done_t: 'स्वीकृत — ID जारी', o_done_sent: 'स्वीकृति के लिए भेजा', o_code: 'ID / एजेंट कोड',
      o_cards: 'सदस्य खोलें — ID और विज़िटिंग कार्ड', o_form: 'भरा फ़ॉर्म (PDF)', o_next: 'एक और बनाएँ', o_driver: 'ड्राइवर ऐप तैयार: /driver/ पर यही मोबाइल + WhatsApp पर भेजा PIN, या /me/ में “बस लोकेशन शुरू”।', o_appr: 'स्वीकृति सूची', o_kycNo: 'KYC बाकी — असली पहचान पत्र नहीं देखा।', o_kycMgr: 'KYC मैनेजर जाँचेंगे — तब तक ID "जाँच बाकी" रहेगा।',
      net: 'इंटरनेट नहीं — जाँचकर फिर कोशिश करें।', saved: 'सेव हुआ'
    }
  };
  var lang = (function () {
    try { var s = localStorage.getItem('ap_lang'); if (s && W[s]) return s; } catch (e) {}
    var n = (navigator.language || 'en').toLowerCase();
    return n.indexOf('ne') === 0 ? 'ne' : (n.indexOf('hi') === 0 ? 'hi' : 'en');
  })();
  var t = function (k) { return (W[lang] && W[lang][k]) || W.en[k] || k; };

  /* ---------------- state ---------------- */
  var S = { key: '', d: {}, step: 0, read: {}, src: {}, conf: {}, bad: {}, warn: {}, touched: {}, keep: {}, suggest: {}, previews: {},
            aiPoll: null, revPoll: null, consent: false, otpSent: false, paper: null, paperPhone: '',
            thumb: { pv: null, q: 0, skip: false, busy: false }, sawId: false, approveNow: true, res: null };
  var STEPS = ['who', 'wa', 'id', 'photo', 'bank', 'finish'];
  var staffInvite = !!(C.inviteOk && C.role && C.role !== 'agent');
  var KEY = OFF ? 'apjoin_office' : 'apjoin_' + (C.invite || 'public');
  var EXTRA = ['occupation', 'education', 'experience', 'skills', 'languages'];
  function isStaff() { return S.d.kind === 'staff' || (!OFF && staffInvite); }

  /* ---------------- network ---------------- */
  function api(action, body, isForm) {
    var opt = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF-Token': C.csrf, 'Accept': 'application/json' }, credentials: 'same-origin' };
    var url = C.api + '?a=' + encodeURIComponent(action) + (OFF ? '&office=1' : '');
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
  /** A refusal that names the account the mobile already belongs to (409 + fields.member_url). */
  function takenNote(r) {
    if (!r || !r.fields || !r.fields.member_url) return '';
    return '<div class="ap-note warn" style="margin-top:12px">⚠ ' + esc(r.error) + ' <a class="ap-btn sm" style="margin-top:8px" href="' + esc(r.fields.member_url) + '">🪪 ' + esc(t('openMember')) + '</a></div>';
  }

  /* ---------------- language ---------------- */
  function applyLang() {
    document.documentElement.lang = lang;
    $$('.ap-lang button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.lang === lang ? 'true' : 'false'); });
    $$('[data-t]').forEach(function (el) { el.textContent = t(el.dataset.t); });
    if (OFF) { $('.ap-hero h1').textContent = t('heroOffice'); $('.ap-hero p').textContent = t('heroOfficeSub'); }
    else if (isStaff()) { $('.ap-hero h1').textContent = t('heroStaff'); }
  }
  $$('.ap-lang button').forEach(function (b) {
    b.addEventListener('click', function () { lang = b.dataset.lang; try { localStorage.setItem('ap_lang', lang); } catch (e) {} applyLang(); render(); });
  });
  var nf = $('#officeNew');
  if (nf) nf.addEventListener('click', function () { try { localStorage.removeItem(KEY); } catch (e) {} location.href = '/join.php?office=1'; });

  /* ---------------- boot ---------------- */
  function boot() {
    applyLang();
    if (C.invite && !C.inviteOk && !C.resume) { return fatal(t('badInvite')); }
    if (!C.open) { return fatal(t('closed')); }
    var stored = '';
    try { stored = localStorage.getItem(KEY) || ''; } catch (e) { stored = ''; }
    S.key = C.resume || stored;
    (S.key ? api('get') : Promise.resolve({ ok: false })).then(function (r) {
      if (r.ok) { remember(); take(r.data.draft); return; }
      if (C.resume) { return fatal(r.error || t('badLink')); }   // a stale "continue later" link
      return startNew();
    });
  }
  function startNew() {
    return api('start', { invite: C.invite || '' }).then(function (s) {
      if (!s.ok) { return fatal(s.error || t('net')); }
      S.key = s.data.key; remember();
      // 30 Oct 2026: the office form starts with the role asked for (?role=driver from Team) or the
      // role of the last form this office user filled — "Create another" makes the next one fast.
      if (OFF) {
        var want = C.roleWanted || '';
        if (!want) { try { want = localStorage.getItem('apjoin_office_role') || ''; } catch (e) { want = ''; } }
        var okRole = want && want !== 'agent:person' && (C.roles || []).some(function (r) { return r.v === want; });
        if (okRole) {
          var ag = want.indexOf('agent:') === 0;
          return api('office_role', { role: ag ? 'agent' : want, agent_kind: ag ? want.split(':')[1] : 'person', designation: '' }).then(function (r) {
            take(r.ok ? r.data.draft : s.data.draft);
          });
        }
      }
      // agreements/apply.html?kind=partner → an agency; an office invitation also brings the name / mobile
      var pf = (!OFF && C.prefill) || {}, first = {};
      if (C.as === 'org' && !OFF) first.agent_kind = 'org';
      if (pf.name) first.full_name = pf.name;
      if (pf.phone) { S.pfPhone = pf.phone; first.country = pf.cc === 'NP' ? 'NP' : 'IN'; }
      if (Object.keys(first).length) {
        return api('save', first).then(function (r) {
          take(r.ok ? r.data.draft : s.data.draft);
          if (S.step > 0 && S.step < 6) { S.step = 0; render(); }   // a new form starts at "who" — they confirm the prefilled name
        });
      }
      take(s.data.draft);
    });
  }
  function remember() { try { localStorage.setItem(KEY, S.key); } catch (e) {} }
  function take(d) {
    S.d = d || {};
    applyLang();
    if (S.d.status && S.d.status !== 'draft' && !(OFF && S.d.status === 'submitted')) { S.step = 6; render(); return; }
    // Resume at the first step that is not finished yet.
    var s = 0;
    if (S.d.full_name && (isStaff() || S.d.agent_kind)) s = 1;
    if (s === 1 && S.d.verified) s = 2;
    if (s === 2 && (OFF ? S.d.id_number_masked : (S.d.has && S.d.has.front && S.d.dob))) s = 3;
    if (s === 3 && S.d.has && S.d.has.selfie) s = 4;
    S.step = s;
    render();
  }
  function fatal(msg) {
    $('#app').innerHTML = '<div class="ap-card"><div class="ap-note bad">' + esc(msg) + '</div><p class="lead" style="margin-top:12px">☎ ' + esc(C.office || '') + '</p>'
      + '<div class="ap-actions"><a class="ap-btn ghost" href="' + (OFF ? '/join.php?office=1' : '/join.php') + '">↻ ' + esc(t('done_new')) + '</a></div></div>';
    $('#bar').hidden = true;
    try { if (C.resume) localStorage.removeItem(KEY); } catch (e) {}
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
    var s = S.src[name];
    var low = s === 'paper' && S.conf[name] === 'low' && !S.touched[name];
    var src = s ? '<span class="ap-src ' + s + (low ? ' low' : '') + '">' + esc(low ? t('lowConf') : t('src_' + s)) + '</span>' : '';
    var opt = opts.optional ? ' <em>· ' + esc(t('optional')) + '</em>' : '';
    var warn = S.warn[name] ? '<div class="ap-hint" style="color:var(--warn)">⚠ ' + esc(t(S.warn[name])) + '</div>' : '';
    if (S.bad[name] && !S.touched[name]) warn += '<div class="ap-err">⚠ ' + esc(S.bad[name]) + '</div>';
    var hint = opts.hint ? '<div class="ap-hint">' + esc(opts.hint) + '</div>' : '';
    if (S.suggest[name] && String(S.suggest[name].v) !== String(v)) {
      hint += '<div class="ap-note" style="margin-top:8px;display:flex;gap:10px;align-items:center;justify-content:space-between">'
        + '<span>' + esc(S.suggest[name].s === 'paper' ? t('paperSays') : t('idSays')) + ' <b>' + esc(S.suggest[name].v) + '</b></span>'
        + '<button type="button" class="ap-btn sm" data-use="' + name + '">' + esc(t('useIt')) + '</button></div>';
    }
    return '<label class="ap-field"><span>' + esc(label) + opt + src + '</span>'
      + '<input class="ap-in' + (s && !S.touched[name] ? ' filled' : '') + (low || (S.bad[name] && !S.touched[name]) ? ' low' : '') + '" name="' + name + '" value="' + esc(v) + '"'
      + (opts.type ? ' type="' + opts.type + '"' : '') + (opts.ac ? ' autocomplete="' + opts.ac + '"' : '')
      + (opts.ph ? ' placeholder="' + esc(opts.ph) + '"' : '')
      + (opts.mode ? ' inputmode="' + opts.mode + '"' : '') + (opts.max ? ' maxlength="' + opts.max + '"' : '') + '>' + hint + warn + '</label>';
  }
  function render() {
    stopPolls(false);
    var app = $('#app');
    if (S.step === 6) { $('#bar').hidden = true; app.innerHTML = renderDone(); bindDone(); return; }
    $('#bar').hidden = false;
    var st = STEPS[S.step];
    var body = { who: renderWho, wa: renderWa, id: renderId, photo: renderPhoto, bank: renderBank, finish: renderFinish }[st]();
    if (S.step >= 2 && S.d.status === 'draft') body += laterCard();
    app.innerHTML = head() + body;
    $('#back').style.visibility = S.step === 0 ? 'hidden' : 'visible';
    var last = S.step === 5;
    $('#next').innerHTML = esc(last ? (OFF && S.approveNow ? t('o_send_ok') : t('send')) : t('next'));
    $('#next').className = 'ap-btn' + (last ? ' gold' : '');
    bind();
    var first = $('#app .ap-in:not([value]), #app .ap-in[value=""]');
    if (first && window.innerWidth > 700) first.focus();
  }

  function renderWho() {
    var staff = isStaff();
    var kind = S.d.agent_kind || '';
    var top = '';
    if (OFF) {
      var cur = S.d.kind === 'staff' ? (S.d.role_wanted || 'counter') : 'agent:' + (kind || 'person');
      var fixed = S.d.status !== 'draft';
      top = '<div class="ap-field"><div class="ap-chips" role="group" aria-label="' + esc(t('role_q')) + '">'
        + (C.roles || []).map(function (r) {
          return '<button type="button" class="ap-chip" data-role="' + esc(r.v) + '" aria-pressed="' + (r.v === cur) + '"' + (fixed && r.v !== cur ? ' disabled' : '') + '>' + esc(r.label) + '</button>';
        }).join('') + '</div>' + (fixed ? '<div class="ap-hint">' + esc(t('roleFixed')) + '</div>' : '') + '</div>';
    } else if (staff) {
      top = '<div class="ap-note" style="margin-bottom:12px">✉️ ' + esc(t('inviteFor')) + ' <b>' + esc(S.d.designation || C.designation || S.d.role_wanted || C.role) + '</b></div>';
    } else {
      top = '<div class="ap-choices" role="group" aria-label="' + esc(t('who_q')) + '">'
        + '<button type="button" class="ap-choice" data-kind="person" aria-pressed="' + (kind === 'person') + '"><span class="ico">🧑‍💼</span><b>' + esc(t('person')) + '</b><small>' + esc(t('personSub')) + '</small></button>'
        + '<button type="button" class="ap-choice" data-kind="org" aria-pressed="' + (kind === 'org') + '"><span class="ico">🏢</span><b>' + esc(t('org')) + '</b><small>' + esc(t('orgSub')) + '</small></button></div>';
    }
    var org = kind === 'org' && !staff;
    return '<section class="ap-card"><h2>' + esc(OFF ? t('role_q') : t('who_q')) + '</h2>' + (OFF ? '' : '<p class="lead">' + esc(t('who_lead')) + '</p>') + top
      + '<div style="margin-top:16px">'
      + (org ? field('org_name', t('orgName'), { ac: 'organization' }) : '')
      + field('full_name', org ? t('contactName') : t('fullName'), { ac: 'name', hint: t('fullNameHint'), max: 120 })
      + (OFF ? field('designation', t('designation'), { optional: true, max: 80 }) : '')
      + '</div></section>'
      + (OFF ? paperCard() : '');
  }

  function renderWa() {
    if (S.d.verified) {
      var via = S.d.verifiedVia === 'office' ? t('officeOk') : t('verified');
      return '<section class="ap-card"><h2>' + esc(t('wa_q')) + '</h2><div class="ap-ok">✅ ' + esc(via) + ' · <span class="ap-mono">' + esc(S.d.phone || '') + '</span></div>'
        + '<p class="lead" style="margin-top:12px">' + esc(t('wa_lead')) + '</p>'
        + field('whatsapp', t('whatsapp2'), { optional: true, type: 'tel', mode: 'numeric', max: 15, ac: 'tel' })
        + (S.d.status === 'draft' ? '<button type="button" class="ap-link" id="waChange">' + esc(t('change')) + '</button>' : '') + '</section>'
        + (S.d.status === 'draft' ? laterCard() : '');
    }
    var cc = S.d.country === 'NP' ? 'NP' : 'IN';
    var phone = S.d.phone || (OFF ? S.paperPhone : '') || S.pfPhone || '';
    var officeBox = OFF
      ? '<section class="ap-card ap-officecard"><h3>🏢 ' + esc(t('o_skip_t')) + '</h3>'
        + '<label class="ap-field"><span>' + esc(t('o_reason')) + '</span><input class="ap-in" id="oReason" maxlength="160" value="' + esc(S.oReason || '') + '"></label>'
        + '<button type="button" class="ap-btn gold block" id="oSkip">✔ ' + esc(t('o_skip_btn')) + '</button><div id="oTaken"></div></section>'
      : '';
    return '<section class="ap-card"><h2>' + esc(t('wa_q')) + '</h2><p class="lead">' + esc(t('wa_lead')) + '</p>'
      + '<label class="ap-field"><span>' + esc(t('mobile')) + '</span><div class="ap-phone"><select class="ap-sel" id="cc" aria-label="Country">'
      + '<option value="IN"' + (cc === 'IN' ? ' selected' : '') + '>🇮🇳 +91</option><option value="NP"' + (cc === 'NP' ? ' selected' : '') + '>🇳🇵 +977</option></select>'
      + '<input class="ap-in" id="phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" placeholder="98XXXXXXXX" value="' + esc(phone) + '"></div></label>'
      + '<div id="phoneTaken"></div>'
      + '<button type="button" class="ap-btn wa block" id="sendOtp">💬 ' + esc(t('sendCode')) + '</button>'
      + '<div id="otpBox"' + (S.otpSent ? '' : ' hidden') + '><p class="lead" style="margin:14px 0 4px">' + esc(t('codeSent')) + '</p>'
      + '<div class="ap-otp">' + [0, 1, 2, 3, 4, 5].map(function (i) { return '<input inputmode="numeric" maxlength="1" autocomplete="' + (i === 0 ? 'one-time-code' : 'off') + '" aria-label="Digit ' + (i + 1) + '">'; }).join('') + '</div>'
      + '<div class="ap-row"><button type="button" class="ap-btn block" id="verifyOtp">' + esc(t('verify')) + '</button></div>'
      + '<button type="button" class="ap-link" id="resendOtp">' + esc(t('resend')) + '</button></div>'
      + (OFF ? '' : '<div class="ap-or">' + esc(t('or')) + '</div>'
        + '<p class="lead">' + esc(t('reverseLead')) + '</p><button type="button" class="ap-btn ghost block" id="reverse">📲 ' + esc(t('reverseBtn')) + '</button>'
        + '<div id="revBox" hidden></div>')
      + '</section>' + officeBox;
  }

  /** "Continue later": the draft key as a link that opens this form on any phone. */
  function laterLink() { return location.origin + '/join.php?d=' + S.key; }
  function laterCard() {
    var link = laterLink();
    var to = OFF ? String(S.d.phone || '').replace(/\D/g, '') : '';
    if (to.length === 10) to = (S.d.country === 'NP' ? '977' : '91') + to;
    var text = C.company + ' — ' + t('later_t') + ': ' + link;
    return '<section class="ap-card ap-later"><h3>⏸ ' + esc(t('later_t')) + '</h3><p class="lead">' + esc(OFF ? t('later_lead_o') : t('later_lead')) + '</p>'
      + '<div class="ap-later-row"><input class="ap-in ap-mono" id="laterLink" readonly value="' + esc(link) + '" aria-label="Link">'
      + '<button type="button" class="ap-btn ghost sm" id="laterCopy">📋 ' + esc(t('copy')) + '</button>'
      + '<a class="ap-btn wa sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(to) + '?text=' + encodeURIComponent(text) + '">💬 ' + esc(t('shareWa')) + '</a></div></section>';
  }

  /** The hand-filled paper form: photos or a PDF → the AI fills the empty boxes. */
  function paperCard() {
    var n = (S.d.has && S.d.has.paper) || 0;
    var res = '';
    if (S.paper && S.paper.msg) res = '<div class="ap-note' + (S.paper.bad ? ' warn' : '') + '" style="margin-top:10px">' + S.paper.msg + '</div>';
    else if (n) res = '<div class="ap-hint">📎 ' + n + ' ' + esc(t('paper_pages')) + '</div>';
    return '<section class="ap-card ap-paper"><h3>📄 ' + esc(t('paper_q')) + '</h3><p class="lead">' + esc(t('paper_lead')) + '</p>'
      + '<div class="ap-actions" style="margin-top:0"><label class="ap-btn" style="position:relative">📷 ' + esc(t('paper_btn'))
      + '<input type="file" id="paperIn" accept="image/*,application/pdf" multiple style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>'
      + '<a class="ap-btn ghost" href="' + esc(C.blankForm || '/agreements/form.php?kind=agent') + '" target="_blank" rel="noopener">🖨 ' + esc(t('paper_blank')) + '</a></div>'
      + '<div id="paperBox">' + res + '</div></section>';
  }

  function renderId() {
    var types = C.idTypes || {};
    var chips = Object.keys(types).map(function (k) {
      return '<button type="button" class="ap-chip" data-idt="' + k + '" aria-pressed="' + (S.d.id_type === k) + '">' + esc(types[k]) + '</button>';
    }).join('');
    var g = S.d.gender || '';
    var gLow = S.src.gender === 'paper' && S.conf.gender === 'low' && !S.touched.gender;
    var gender = '<div class="ap-field"><span>' + esc(t('gender')) + (S.src.gender ? '<span class="ap-src ' + S.src.gender + (gLow ? ' low' : '') + '">' + esc(gLow ? t('lowConf') : t('src_' + S.src.gender)) + '</span>' : '') + '</span><div class="ap-chips" role="group">'
      + ['Male', 'Female', 'Other'].map(function (x) { return '<button type="button" class="ap-chip" data-gender="' + x + '" aria-pressed="' + (g === x) + '">' + esc(t(x.toLowerCase())) + '</button>'; }).join('') + '</div></div>';
    var expiryTypes = { passport: 1, dl: 1 };
    var opt = OFF;   // the office needs only the ID number here
    return paperCard()
      + '<section class="ap-card"><h2>' + esc(t('id_q')) + '</h2><p class="lead">' + esc(t('id_lead')) + '</p>'
      + '<div class="ap-field"><span>' + esc(t('idType')) + '</span><div class="ap-chips" role="group">' + chips + '</div></div>'
      + '<div class="ap-tiles">' + tile('front', t('front'), OFF) + tile('back', t('back2'), true) + '</div>'
      + '<div id="readBox"></div></section>'
      + '<section class="ap-card"><p class="lead">' + esc(t('checkFields')) + '</p>'
      + field('full_name', t('fullName'), { ac: 'name', max: 120 })
      + '<div class="ap-grid2 stack">' + field('dob', t('dob'), { type: 'date', optional: opt }) + field('id_number', t('idNumber'), { max: 40, ac: 'off', ph: S.d.id_number_masked || '' }) + '</div>'
      + gender
      + field('guardian', t('guardian'), { optional: true, max: 120 })
      + field('address', t('address'), { ac: 'street-address', max: 255, optional: opt })
      + '<div class="ap-grid2">' + field('city', t('city'), { ac: 'address-level2', max: 80, optional: opt }) + field('district', t('district'), { optional: true, max: 80 }) + '</div>'
      + '<div class="ap-grid2">' + field('state', t('state'), { optional: true, ac: 'address-level1', max: 80 }) + field('pincode', t('pincode'), { optional: true, mode: 'numeric', max: 10, ac: 'postal-code' }) + '</div>'
      + (expiryTypes[S.d.id_type] ? field('id_expires_on', t('expires'), { type: 'date', optional: true }) : '')
      + (S.d.id_type !== 'pan' ? field('pan', t('pan'), { optional: true, max: 12, ac: 'off', hint: 'ABCDE1234F' }) : '')
      + '</section>';
  }
  function tile(slot, label, optional) {
    var has = S.d.has && S.d.has[slot];
    var prev = S.previews[slot] ? '<img alt="" src="' + S.previews[slot] + '">' : '';
    // No capture attribute: the phone offers camera AND gallery / files (an ID card photo or scan already on the phone).
    return '<label class="ap-tile' + (has ? ' has' : '') + '" data-slot="' + slot + '">' + prev
      + (has ? '<span class="tag">✓ ' + esc(label) + '</span>' : '<span class="big">📷</span><b>' + esc(label) + '</b><small>' + esc(optional ? t('optional') : t('tapPhoto')) + '</small>')
      + '<input type="file" accept="image/*,application/pdf" data-up="' + slot + '" aria-label="' + esc(label) + '"></label>';
  }

  function renderPhoto() {
    var src = S.previews.selfie || '';
    var has = S.d.has && S.d.has.selfie;
    return '<section class="ap-card"><h2>' + esc(t('photo_q')) + '</h2><p class="lead">' + esc(t('photo_lead')) + '</p>'
      + '<div id="camArea">' + (src ? '<div class="ap-cam" style="max-width:300px"><img alt="" src="' + src + '"></div>' : (has ? '<div class="ap-ok" style="margin-bottom:12px">✅ ' + esc(t('saved')) + '</div>' : '')) + '</div>'
      + '<div class="ap-choices ap-photo-choices" role="group">'
      + '<button type="button" class="ap-choice" id="openCam"><span class="ico">📸</span><b>' + esc(src || has ? t('retake') : t('selfieBtn')) + '</b><small>' + esc(t('selfieSub')) + '</small></button>'
      + '<label class="ap-choice" style="cursor:pointer"><span class="ico">🖼</span><b>' + esc(t('passportBtn')) + '</b><small>' + esc(t('passportSub')) + '</small>'
      + '<input type="file" accept="image/*" id="selfieFile" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label></div>'
      + '<p class="ap-hint" style="text-align:center">' + esc(t('photoOr')) + '</p>'
      + '</section>'
      + '<section class="ap-card" id="thumbCard">' + renderThumb() + '</section>'
      + '<section class="ap-card"><h3>' + esc(t('cardPreview')) + '</h3>' + mini() + '<p class="ap-hint" style="text-align:center;margin-top:12px">🔐 ' + esc(t('fpNote')) + '</p></section>';
  }
  function mini() {
    var name = S.d.agent_kind === 'org' && S.d.org_name && !isStaff() ? S.d.org_name : (S.d.full_name || '—');
    var desig = isStaff() ? (S.d.designation || C.designation || S.d.role_wanted || C.role) : (S.d.designation || (S.d.agent_kind === 'org' ? 'Authorised Booking Agency' : 'Ticketing Agent'));
    return '<div class="ap-mini"><div class="h"><img class="lg" alt="" src="' + esc(C.logo || '/assets/img/logo-mark-tm.png') + '"><span>S HARI GLOBAL<small>PVT. LTD.</small></span></div><div class="p">' + (S.previews.selfie ? '<img alt="" src="' + S.previews.selfie + '">' : '') + '</div>'
      + '<div class="n">' + esc(name) + '</div><div class="d">' + esc(desig) + '</div><div class="i">' + (isStaff() ? 'SHG-S-····' : 'SHG-····') + '</div></div>';
  }

  /* ---- thumb print (K4): photo → black & white ink print, made here ---- */
  function renderThumb() {
    var T = S.thumb, has = S.d.has && S.d.has.thumb;
    var head = '<h3>🖐 ' + esc(t('thumb')) + ' <span class="ap-hint">· ' + esc(t('optional')) + '</span></h3>';
    if (T.skip && !T.pv) {
      return head + '<div class="ap-row" style="justify-content:space-between"><span class="ap-hint">' + esc(t('thumbSkipped')) + '</span><button type="button" class="ap-link" id="thumbUnskip">➕ ' + esc(t('thumbAdd')) + '</button></div>';
    }
    var pick = '<label class="ap-btn ghost sm" style="position:relative">📷 ' + esc(t('thumbCam')) + '<input type="file" accept="image/*" capture="environment" data-thumb="1" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>'
      + '<label class="ap-btn ghost sm" style="position:relative">🖼 ' + esc(t('thumbFile')) + '<input type="file" accept="image/*" data-thumb="1" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>';
    var shown = T.pv || (has ? S.previews.thumb : '');
    var pv = shown ? '<img alt="" src="' + shown + '">' : (has ? '✅' : '👍');
    var body;
    if (T.busy) body = '<div class="ap-reading"><span class="dot"></span>' + esc(t('thumbWorking')) + '</div>';
    else if (T.pv) body = (T.q <= 5 ? '<div class="ap-hint" style="color:var(--warn)">⚠ ' + esc(t('thumbWeak')) + '</div>' : '')
      + '<div class="ap-row" style="flex-wrap:wrap;margin-top:8px"><button type="button" class="ap-btn sm" id="thumbUse">✓ ' + esc(t('thumbUse')) + '</button>' + pick.replace(esc(t('thumbCam')), esc(t('thumbRetake'))) + '<button type="button" class="ap-link" id="thumbSkip">' + esc(t('skip')) + '</button></div>';
    else if (has) body = '<div class="ap-ok">✅ ' + esc(t('thumbSaved')) + '</div><div class="ap-row" style="flex-wrap:wrap;margin-top:8px">' + pick.replace(esc(t('thumbCam')), esc(t('thumbRetake'))) + '</div>';
    else body = '<p class="lead" style="margin:0 0 8px">' + esc(t('thumbLead')) + '</p><div class="ap-row" style="flex-wrap:wrap">' + pick + '<button type="button" class="ap-link" id="thumbSkip">' + esc(t('skip')) + '</button></div>';
    return head + '<div class="ap-thumb"><div class="pv' + (shown ? ' on' : '') + '">' + pv + '</div><div class="tx">' + body + '</div></div>';
  }
  function paintThumb() { var c = $('#thumbCard'); if (c) { c.innerHTML = renderThumb(); bindThumb(); } }
  function bindThumb() {
    $$('[data-thumb]').forEach(function (inp) {
      inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0]; inp.value = '';
        if (!f || !/^image\//.test(f.type || '')) return;
        S.thumb.busy = true; S.thumb.skip = false; paintThumb();
        thumbFromFile(f).then(function (r) { S.thumb.busy = false; S.thumb.pv = r ? r.uri : null; S.thumb.q = r ? r.quality : 0; paintThumb(); },
          function () { S.thumb.busy = false; paintThumb(); toast(t('net'), true); });
      });
    });
    var u = $('#thumbUse');
    if (u) u.addEventListener('click', function () {
      var blob = dataUrlBlob(S.thumb.pv); if (!blob) return;
      S.previews.thumb = S.thumb.pv; S.thumb.pv = null;
      upload('thumb', new File([blob], 'thumb.png', { type: 'image/png' }));
    });
    var sk = $('#thumbSkip'); if (sk) sk.addEventListener('click', function () { S.thumb.skip = true; S.thumb.pv = null; paintThumb(); });
    var us = $('#thumbUnskip'); if (us) us.addEventListener('click', function () { S.thumb.skip = false; paintThumb(); });
  }
  function dataUrlBlob(uri) {
    try {
      var p = String(uri || '').split(','), mime = (p[0].match(/data:([^;]+)/) || [])[1] || 'image/png', b = atob(p[1]), u = new Uint8Array(b.length);
      for (var i = 0; i < b.length; i++) u[i] = b.charCodeAt(i);
      return new Blob([u], { type: mime });
    } catch (e) { return null; }
  }
  function thumbFromFile(f) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(f), im = new Image();
      im.onload = function () {
        try {
          var k = Math.min(1, 1200 / Math.max(im.naturalWidth, im.naturalHeight));   // cheap phones: work on ~1200 px
          var c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
          c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
          URL.revokeObjectURL(url);
          resolve(toPrint(c));
        } catch (e) { URL.revokeObjectURL(url); reject(e); }
      };
      im.onerror = function () { URL.revokeObjectURL(url); reject(new Error('img')); };
      im.src = url;
    });
  }
  /* Ported from assets/js/bio-kit.js ShgThumb.toPrint (22 Oct 2026): local
     contrast → ridges, an oval like a stamp — here in pure black ink on white. */
  var TW = 360, TH = 450;
  function integral(v, w, h) {
    var I = new Float64Array((w + 1) * (h + 1));
    for (var y = 0; y < h; y++) { var row = 0; for (var x = 0; x < w; x++) { row += v[y * w + x]; I[(y + 1) * (w + 1) + x + 1] = I[y * (w + 1) + x + 1] + row; } }
    return I;
  }
  function boxMean(I, w, h, x, y, r) {
    var x0 = Math.max(0, x - r), y0 = Math.max(0, y - r), x1 = Math.min(w, x + r + 1), y1 = Math.min(h, y + r + 1);
    return (I[y1 * (w + 1) + x1] - I[y0 * (w + 1) + x1] - I[y1 * (w + 1) + x0] + I[y0 * (w + 1) + x0]) / ((x1 - x0) * (y1 - y0));
  }
  function toPrint(img) {
    var sw = img.naturalWidth || img.width, sh = img.naturalHeight || img.height;
    var side = Math.min(sw, sh) * 0.78, cw = side * 0.8, ch = side;
    if (ch > sh) { ch = sh; cw = ch * 0.8; }
    var sx = (sw - cw) / 2, sy = (sh - ch) / 2;
    var c = document.createElement('canvas'); c.width = TW; c.height = TH;
    var x = c.getContext('2d');
    x.fillStyle = '#fff'; x.fillRect(0, 0, TW, TH);
    x.drawImage(img, sx, sy, cw, ch, 0, 0, TW, TH);
    var px = x.getImageData(0, 0, TW, TH), d = px.data, n = TW * TH, i, y, xx;
    var g = new Float64Array(n), g2 = new Float64Array(n);
    for (i = 0; i < n; i++) g[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
    var I0 = integral(g, TW, TH), s = new Float64Array(n);
    for (y = 0; y < TH; y++) for (xx = 0; xx < TW; xx++) s[y * TW + xx] = boxMean(I0, TW, TH, xx, y, 1);
    var I1 = integral(s, TW, TH);
    for (i = 0; i < n; i++) g2[i] = s[i] * s[i];
    var I2 = integral(g2, TW, TH);
    var R = 7, cx = TW / 2, cy = TH / 2, rx = TW * 0.46, ry = TH * 0.47, spread = 0, cnt = 0;
    var out = x.createImageData(TW, TH), o = out.data;
    for (y = 0; y < TH; y++) {
      for (xx = 0; xx < TW; xx++) {
        i = y * TW + xx;
        var m = boxMean(I1, TW, TH, xx, y, R), m2 = boxMean(I2, TW, TH, xx, y, R);
        var sd = Math.sqrt(Math.max(0, m2 - m * m));
        var e = ((xx - cx) * (xx - cx)) / (rx * rx) + ((y - cy) * (y - cy)) / (ry * ry);
        var ink = 0;
        if (e <= 1) {
          spread += sd; cnt++;
          var z = (s[i] - m) / (sd + 4);           // < 0 = darker than around it = a ridge
          ink = z < (e > 0.85 ? -0.55 : -0.15) ? 1 : 0;
        }
        var v = ink ? 17 : 255;                    // black ink on white — nothing else
        o[i * 4] = v; o[i * 4 + 1] = v; o[i * 4 + 2] = v; o[i * 4 + 3] = 255;
      }
    }
    x.putImageData(out, 0, 0);
    return { uri: c.toDataURL('image/png'), quality: cnt ? spread / cnt : 0 };
  }

  function renderBank() {
    var np = S.d.country === 'NP';
    var acc = '<label class="ap-field"><span>' + esc(t('account')) + ' <em>· ' + esc(t('optional')) + '</em>' + (S.src.bank_account ? '<span class="ap-src paper">' + esc(t('src_paper')) + '</span>' : '') + '</span><input class="ap-in' + (S.src.bank_account && !S.touched.bank_account ? ' filled' : '') + '" name="bank_account" inputmode="numeric" maxlength="24" autocomplete="off" value="' + esc(S.d.bank_account || '') + '" placeholder="' + (S.d.bank_account_last4 ? '••••' + esc(S.d.bank_account_last4) : '') + '"></label>';
    return '<section class="ap-card"><h2>' + esc(t('bank_q')) + '</h2><p class="lead">' + esc(t('bank_lead')) + '</p>'
      + '<div class="ap-chips" role="group" style="margin-bottom:14px"><button type="button" class="ap-chip" data-bc="IN" aria-pressed="' + !np + '">🇮🇳 ' + esc(t('bankIn')) + '</button>'
      + '<button type="button" class="ap-chip" data-bc="NP" aria-pressed="' + np + '">🇳🇵 ' + esc(t('bankNp')) + '</button></div>'
      + (np
        ? field('esewa_id', t('esewa'), { optional: true, mode: 'numeric', max: 40 }) + field('khalti_id', t('khalti'), { optional: true, mode: 'numeric', max: 40 })
          + field('bank_name', t('bankName'), { optional: true, max: 120 }) + field('bank_holder', t('holder'), { optional: true, max: 120 })
          + acc + field('bank_branch', t('branch'), { optional: true, max: 120 })
        : field('upi_id', t('upi'), { optional: true, max: 100, hint: 'name@okaxis · 98XXXXXXXX@ybl' })
          + field('bank_holder', t('holder'), { optional: true, max: 120 })
          + acc
          + '<div class="ap-grid2">' + field('bank_ifsc', t('ifsc'), { optional: true, max: 11 }) + field('bank_name', t('bankName'), { optional: true, max: 120 }) + '</div>')
      + '</section>';
  }

  function renderFinish() {
    var ex = (S.d.extras || []).filter(function (x) { return !x.paper; }).map(function (x) {
      return '<li class="ap-row" style="justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)"><span>📎 ' + esc(x.label) + '</span><button type="button" class="ap-link" data-drop="' + x.i + '" aria-label="Remove">✕</button></li>';
    }).join('');
    var own = (S.d.extras || []).filter(function (x) { return !x.paper; }).length;
    var name = S.d.agent_kind === 'org' && S.d.org_name && !isStaff() ? S.d.org_name + ' (' + (S.d.full_name || '') + ')' : (S.d.full_name || '');
    var types = C.idTypes || {};
    var rv = [[t('fullName'), name], [t('mobile'), S.d.phone], [t('dob'), S.d.dob], [t('idType'), types[S.d.id_type] || ''], [t('idNumber'), S.d.id_number_masked || ''],
      [t('address'), [S.d.address, S.d.city, S.d.district, S.d.state, S.d.pincode].filter(Boolean).join(', ')],
      [t('s_bank'), S.d.upi_id || (S.d.bank_account_last4 ? (S.d.bank_name || '') + ' ••••' + S.d.bank_account_last4 : '') || S.d.esewa_id || S.d.khalti_id || '—']];
    if (S.d.has && S.d.has.paper) rv.push(['📄', S.d.has.paper + ' ' + t('paper_pages')]);
    var office = OFF
      ? (C.canKyc   // KYC verified: super-admin / manager only (the admin/team.php rule); others leave it for a manager
        ? '<label class="ap-row ap-tick"><input type="checkbox" id="oSaw"' + (S.sawId ? ' checked' : '') + '><span>🪪 ' + esc(t('o_saw')) + '</span></label>'
        : '<div class="ap-note warn">' + esc(t('o_kycMgr')) + '</div>')
        + '<label class="ap-row ap-tick"><input type="checkbox" id="oNow"' + (S.approveNow ? ' checked' : '') + '><span>⚡ ' + esc(t('o_approve')) + '</span></label>'
      : '';
    return '<section class="ap-card"><h2>' + esc(t('more_q')) + '</h2><p class="lead">' + esc(t('more_lead')) + '</p>'
      + '<div class="ap-grid2 stack">' + field('occupation', t('occupation'), { optional: true, max: 120 }) + field('education', t('education'), { optional: true, max: 120 }) + '</div>'
      + field('experience', t('experience'), { optional: true, max: 160 })
      + field('skills', t('skills'), { optional: true, max: 200 })
      + field('languages', t('languages'), { optional: true, max: 120, hint: 'Nepali, Hindi, Gujarati…' })
      + field('email', t('email'), { optional: true, type: 'email', ac: 'email', max: 191 }) + '</section>'
      + '<section class="ap-card"><h2>' + esc(t('extras')) + '</h2><p class="lead">' + esc(t('extrasLead')) + '</p><ul style="list-style:none;margin:0 0 10px;padding:0">' + ex + '</ul>'
      + (own < (C.maxExtras || 6)
        ? '<div class="ap-row"><input class="ap-in" id="docLabel" placeholder="' + esc(t('docLabel')) + '" maxlength="60" style="flex:1"><label class="ap-btn ghost sm" style="position:relative">➕ ' + esc(t('addDoc'))
          + '<input type="file" accept="image/*,application/pdf" data-up="extra" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label></div>' : '')
      + '</section>'
      + '<section class="ap-card"><h3>' + esc(t('review')) + '</h3><dl class="ap-review">' + rv.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1] || '—') + '</dd>'; }).join('') + '</dl>'
      + office
      + '<label class="ap-row ap-tick" style="margin-top:16px"><input type="checkbox" id="consent"' + (S.consent ? ' checked' : '') + '><span>' + esc(OFF ? t('o_consent') : t('consent')) + '</span></label></section>';
  }

  function renderDone() {
    var ref = S.d.ref || '';
    if (OFF) {
      var R = S.res || {};
      var approved = (R.status || S.d.status) === 'approved';
      var member = R.member_url || S.d.memberUrl || '';
      var form = R.form_url ? R.form_url : (S.d.adminId ? '/admin/team-form.php?admin=' + S.d.adminId : '');
      return '<section class="ap-card ap-done"><div class="seal">' + (approved ? '✓' : '⏳') + '</div><h2>' + esc(approved ? t('o_done_t') : t('o_done_sent')) + '</h2>'
        + '<div class="ap-caps" style="margin-top:14px">' + esc(t('done_ref')) + '</div><div class="ap-ref">' + esc(ref) + '</div>'
        + (R.code ? '<div class="ap-caps">' + esc(t('o_code')) + '</div><div class="ap-ref ap-code">' + esc(R.code) + '</div>' : '')
        + (approved && R.kyc === 'submitted' ? '<div class="ap-note warn">' + esc(t('o_kycNo')) + '</div>' : '')
        + (approved && R.admin_id ? '<div style="text-align:center;margin:14px 0 4px"><a href="' + esc(member || '#') + '"><img alt="ID card" src="/api/agentpro-card.php?m=' + (+R.admin_id) + '&s=id-front&t=' + Date.now() + '" style="width:min(240px,70vw);border-radius:14px;box-shadow:var(--sh-2)"></a></div>' : '')
        + (approved && R.driver ? '<div class="ap-note" style="margin-top:10px">🚌 ' + esc(t('o_driver')) + '</div>' : '')
        + '<div class="ap-actions">'
        + (member ? '<a class="ap-btn" href="' + esc(member) + '">🪪 ' + esc(t('o_cards')) + '</a>' : '')
        + (form ? '<a class="ap-btn ghost" href="' + esc(form + (form.indexOf('?') >= 0 ? '&' : '?') + 'auto=pdf') + '" target="_blank" rel="noopener">📄 ' + esc(t('o_form')) + '</a>' : '')
        + (!approved ? '<a class="ap-btn ghost" href="/admin/team.php?tab=approvals">📋 ' + esc(t('o_appr')) + '</a>' : '')
        + '<button type="button" class="ap-btn gold" id="doneNew">➕ ' + esc(t('o_next')) + '</button></div></section>';
    }
    var waText = encodeURIComponent('Namaste! My application ' + ref + ' — ' + (S.d.full_name || ''));
    // K3: the applicant's own copy — the HMAC link from submit / get (join-form.php refuses a raw ?k= draft key)
    var own = (S.res && S.res.form_url) || S.d.formUrl || '';
    var pdf = own ? own + (own.indexOf('?') >= 0 ? '&' : '?') + 'auto=pdf' : '';
    return '<section class="ap-card ap-done"><div class="seal">✓</div><h2>' + esc(t('done_t')) + '</h2>'
      + '<div class="ap-caps" style="margin-top:14px">' + esc(t('done_ref')) + '</div><div class="ap-ref">' + esc(ref) + '</div>'
      + '<p class="lead">' + esc(t('done_s')) + '</p>'
      + '<p class="lead">🪪 ' + esc(t('done_cards')) + '</p>'
      + '<div class="ap-actions">'
      + (pdf && (S.d.status === 'submitted' || S.d.status === 'approved') ? '<a class="ap-btn gold" href="' + esc(pdf) + '" target="_blank" rel="noopener">📄 ' + esc(t('done_pdf')) + '</a>' : '')
      + '<a class="ap-btn wa" href="https://wa.me/' + esc(C.wa) + '?text=' + waText + '" target="_blank" rel="noopener">💬 ' + esc(t('done_wa')) + '</a>'
      + '<a class="ap-btn ghost" href="' + esc(C.me) + '">🔐 ' + esc(t('done_me')) + '</a>'
      + '<button type="button" class="ap-link" id="doneNew">➕ ' + esc(t('done_new')) + '</button></div></section>';
  }
  function bindDone() {
    var b = $('#doneNew');
    if (b) b.addEventListener('click', function () { try { localStorage.removeItem(KEY); } catch (e) {} location.href = OFF ? '/join.php?office=1' : '/join.php'; });
  }

  /* ---------------- behaviour ---------------- */
  function bind() {
    $$('#app .ap-in').forEach(function (el) {
      el.addEventListener('input', function () {
        S.touched[el.name] = true; el.classList.remove('filled'); el.classList.remove('low'); el.removeAttribute('aria-invalid');
        if (el.name) { S.d[el.name] = el.value; delete S.bad[el.name]; }
      });
    });
    $$('[data-kind]').forEach(function (b) {
      b.addEventListener('click', function () { collect(); S.d.agent_kind = b.dataset.kind; render(); });
    });
    $$('[data-role]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.disabled) return;
        collect();
        var v = b.dataset.role, agent = v.indexOf('agent:') === 0;
        api('office_role', { role: agent ? 'agent' : v, agent_kind: agent ? v.split(':')[1] : 'person', designation: S.d.designation || '' }).then(function (r) {
          if (!r.ok) { toast(r.error, true); return; }
          try { localStorage.setItem('apjoin_office_role', v); } catch (e) {}
          S.d = Object.assign(r.data.draft, pickTyped()); render();
        });
      });
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
      b.addEventListener('click', function () { api('drop_extra', { i: +b.dataset.drop }).then(function (r) { if (r.ok) { S.d = Object.assign(r.data.draft, pickTyped()); render(); } }); });
    });
    var c = $('#consent'); if (c) c.addEventListener('change', function () { S.consent = c.checked; });
    var os = $('#oSaw'); if (os) os.addEventListener('change', function () { S.sawId = os.checked; });
    var on = $('#oNow'); if (on) on.addEventListener('change', function () { S.approveNow = on.checked; $('#next').innerHTML = esc(S.approveNow ? t('o_send_ok') : t('send')); });
    $$('[data-use]').forEach(function (b) {
      b.addEventListener('click', function () {
        collect(); var k = b.dataset.use, sg = S.suggest[k];
        S.d[k] = sg.v; S.src[k] = sg.s; delete S.suggest[k]; delete S.touched[k]; S.keep[k] = true;
        if (sg.s === 'paper') { var o = { lenient: '1' }; o[k] = sg.v; api('save', o); } else saveQuiet();
        render();
      });
    });
    var pi = $('#paperIn'); if (pi) pi.addEventListener('change', function () { var fs = pi.files; if (fs && fs.length) scanPaper(fs); pi.value = ''; });
    var lc = $('#laterCopy');
    if (lc) lc.addEventListener('click', function () {
      var inp = $('#laterLink'), link = laterLink();
      var done = function () { toast('✓ ' + t('copied')); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(link).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
      else { inp.select(); try { document.execCommand('copy'); } catch (e) {} done(); }
    });
    if (STEPS[S.step] === 'wa') bindWa();
    if (STEPS[S.step] === 'photo') { bindPhoto(); bindThumb(); }
  }
  /** Copy what is on screen into the draft (no network). */
  function collect() {
    $$('#app .ap-in[name]').forEach(function (el) { if (el.name !== 'bank_account' || el.value) S.d[el.name] = el.value; });
  }

  function bindWa() {
    var ch = $('#waChange');
    if (ch) { ch.addEventListener('click', function () { S.d.verified = false; S.otpSent = false; render(); }); }
    if (S.d.verified) return;
    var phoneOf = function () { return ($('#phone').value || '').replace(/\D/g, ''); };
    if (OFF) {
      // The office learns at once when the mobile already belongs to someone (not at submit).
      $('#phone').addEventListener('change', function () {
        var p = phoneOf(); if (p.length < 8) return;
        api('phone_check', { phone: p }).then(function (r) {
          var box = $('#phoneTaken'); if (!box) return;
          box.innerHTML = r.ok && r.data.owner ? takenNote({ error: t('o_taken') + ' ' + r.data.owner.name + ' (#' + r.data.owner.id + ')', fields: { member_url: r.data.owner.member_url } }) : '';
        });
      });
      $('#oSkip').addEventListener('click', function () {
        var p = phoneOf(), reason = ($('#oReason').value || '').trim(); S.oReason = reason;
        if (p.length < 8) { $('#phone').setAttribute('aria-invalid', 'true'); toast(t('mobile'), true); return; }
        if (reason.length < 3) { $('#oReason').setAttribute('aria-invalid', 'true'); toast(t('o_reason'), true); return; }
        var btn = this; busy(btn, true);
        api('office_verify', { phone: p, country: $('#cc').value, reason: reason }).then(function (r) {
          busy(btn, false);
          if (!r.ok) { $('#oTaken').innerHTML = takenNote(r); toast(r.error, true); return; }
          S.d = Object.assign(r.data.draft, pickTyped()); toast('✓ ' + t('officeOk')); render();
        });
      });
    }
    var send = function (btn) {
      var phone = phoneOf();
      if (phone.length < 10) { $('#phone').setAttribute('aria-invalid', 'true'); toast(t('mobile'), true); return; }
      busy(btn, true);
      api('otp_send', { phone: phone, country: $('#cc').value }).then(function (r) {
        busy(btn, false);
        if (!r.ok) { var tb = $('#phoneTaken'); if (tb) tb.innerHTML = takenNote(r); toast(r.error, true); return; }
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
        S.d = Object.assign(r.data.draft, pickTyped()); toast('✓ ' + t('verified')); render();
      });
    });
    var rv = $('#reverse');
    if (rv) rv.addEventListener('click', function () {
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
    var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '');
    // A PDF of the ID: its first page becomes a JPEG here, so the QR / OCR / AI can read it.
    var prep = (isPdf && (slot === 'front' || slot === 'back'))
      ? pdfPages(file, 1).then(function (pages) { return pages[0] ? new File([dataUrlBlob(pages[0])], 'id-' + slot + '.jpg', { type: 'image/jpeg' }) : file; }, function () { return file; })
      : Promise.resolve(file);
    prep.then(function (f) {
      var isImg = /^image\//.test(f.type);
      if (isImg && (slot === 'front' || slot === 'back')) S.previews[slot] = URL.createObjectURL(f);
      var qrP = (isImg && (slot === 'front' || slot === 'back')) ? readQr(f) : Promise.resolve('');
      return Promise.all([qrP, isImg ? shrink(f, slot === 'selfie' ? 1400 : 2200) : Promise.resolve(f)]);
    }).then(function (res) {
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
  /** Keep what the person typed this session (and what the paper form filled); the server copy may be older. */
  function pickTyped() {
    var o = {};
    Object.keys(S.touched).concat(Object.keys(S.keep)).forEach(function (k) { if (S.d[k] != null) o[k] = S.d[k]; });
    return o;
  }
  var RANK = { qr: 4, mrz: 3, ai: 2, paper: 2, ocr: 1 };
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
    if (o.id_number && /^X{4}/.test(o.id_number)) delete o.id_number;
    o.lenient = '1';   // a doubtful read must not block the good ones
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

  /* ---- the paper form (K2) ---- */
  /** pdf.js from the repo (an ES module): loaded once through a module <script>, no eval. */
  var pdfLib = null;
  function loadPdf() {
    if (pdfLib) return pdfLib;
    pdfLib = new Promise(function (resolve, reject) {
      if (window.__apPdf) { resolve(window.__apPdf); return; }
      var done = function () { window.removeEventListener('appdf', done); if (window.__apPdf) { window.__apPdf.GlobalWorkerOptions.workerSrc = C.pdfWorker; resolve(window.__apPdf); } else reject(new Error('pdf.js')); };
      window.addEventListener('appdf', done);
      var s = document.createElement('script'); s.type = 'module';
      s.textContent = 'import * as L from ' + JSON.stringify(C.pdfjs) + '; window.__apPdf = L; window.dispatchEvent(new Event("appdf"));';
      s.onerror = function () { reject(new Error('pdf.js')); };
      document.head.appendChild(s);
      setTimeout(function () { if (!window.__apPdf) reject(new Error('pdf.js timeout')); }, 20000);
    });
    pdfLib.catch(function () { pdfLib = null; });
    return pdfLib;
  }
  /** Up to `max` pages of a PDF as JPEG data URIs, longest edge ~1600 px (white page under the ink). */
  function pdfPages(file, max, onPage) {
    return Promise.all([loadPdf(), file.arrayBuffer()]).then(function (a) {
      return a[0].getDocument({ data: new Uint8Array(a[1]), isEvalSupported: false, standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/', wasmUrl: '/assets/vendor/pdfjs/wasm/' }).promise;
    }).then(function (pdf) {
      var n = Math.min(pdf.numPages, max), out = [], i = 1;
      var next = function () {
        if (i > n) return pdf.destroy().then(function () { return out; }, function () { return out; });
        if (onPage) onPage(i, n);
        return pdf.getPage(i).then(function (page) {
          var v1 = page.getViewport({ scale: 1 });
          var view = page.getViewport({ scale: Math.min(3, 1600 / Math.max(v1.width, v1.height)) });
          var cv = document.createElement('canvas'); cv.width = Math.round(view.width); cv.height = Math.round(view.height);
          var cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height);
          // intent 'print': no requestAnimationFrame pacing, so a page still renders while the phone
          // shows another app (a hidden tab never runs rAF and the render would wait forever)
          var job = page.render({ canvasContext: cx, viewport: view, intent: 'print' }).promise;
          var limit = new Promise(function (res, rej) { setTimeout(function () { rej(new Error('pdf page timeout')); }, 60000); });
          return Promise.race([job, limit]).then(function () { out.push(cv.toDataURL('image/jpeg', 0.82)); i++; return next(); });
        });
      };
      return next();
    });
  }
  /** One photo of a page → JPEG data URI, longest edge 1600 px. */
  function photoPage(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file), im = new Image();
      im.onload = function () {
        var k = Math.min(1, 1600 / Math.max(im.naturalWidth, im.naturalHeight));
        var cv = document.createElement('canvas'); cv.width = Math.round(im.naturalWidth * k); cv.height = Math.round(im.naturalHeight * k);
        var cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.drawImage(im, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(url); resolve(cv.toDataURL('image/jpeg', 0.82));
      };
      im.onerror = function () { URL.revokeObjectURL(url); resolve(''); };
      im.src = url;
    });
  }
  function paperMsg(html, bad) { S.paper = { msg: html, bad: !!bad }; var b = $('#paperBox'); if (b) b.innerHTML = '<div class="ap-note' + (bad ? ' warn' : '') + '" style="margin-top:10px">' + html + '</div>'; }
  function scanPaper(files) {
    var list = Array.prototype.slice.call(files, 0, 8), pages = [], pdfBad = false;
    var work = Promise.resolve();
    list.forEach(function (f) {
      work = work.then(function () {
        if (pages.length >= 4) return;
        var isPdf = f.type === 'application/pdf' || /\.pdf$/i.test(f.name || '');
        if (isPdf) {
          return pdfPages(f, 4 - pages.length, function (i, n) { paperMsg('<span class="ap-spin dark"></span> ' + esc(t('paper_prep')) + ' ' + (pages.length + i) + '/' + (pages.length + n) + '…'); })
            .then(function (ps) { pages = pages.concat(ps); }, function () { pdfBad = true; });
        }
        if (!/^image\//.test(f.type || '')) return;
        paperMsg('<span class="ap-spin dark"></span> ' + esc(t('paper_prep')) + ' ' + (pages.length + 1) + '…');
        return photoPage(f).then(function (u) { if (u) pages.push(u); });
      });
    });
    work.then(function () {
      if (!pages.length) { paperMsg(esc(pdfBad ? t('paper_pdfFail') : t('paper_notform')), true); return; }
      var started = Date.now();
      var tick = function () { paperMsg('<span class="ap-spin dark"></span> ' + esc(t('paper_read')) + ' · ' + Math.round((Date.now() - started) / 1000) + 's'); };
      tick(); var timer = setInterval(tick, 1000);
      collect();
      // Under PHP-FPM the server answers "queued" at once and reads in the background (a model may take
      // minutes, longer than the web server waits); ask scan_status every 4 s for up to 8 minutes.
      var poll = function (job, tries) {
        return new Promise(function (res) { setTimeout(res, 4000); }).then(function () {
          return api('scan_status', { job: job });
        }).then(function (s) {
          if (s.ok && s.status === 'queued' && tries < 120) return poll(job, tries + 1);
          if (s.ok && s.status === 'queued') return { ok: false, error: t('paper_zero') };
          if (!s.ok && s.error === t('net') && tries < 120) return poll(job, tries + 1);   // a dropped poll (phone lost signal) is retried
          return s;
        });
      };
      return api('scan_form', { pages: pages }).then(function (r) {
        return r.ok && r.status === 'queued' && r.job ? poll(r.job, 0) : r;
      }).then(function (r) {
        clearInterval(timer);
        if (!r.ok) { paperMsg('⚠ ' + esc(r.error || t('net')), true); return; }
        S.d = Object.assign(S.d, r.data.draft, pickTyped());
        applyPaper(r);
      });
    });
  }
  /** Fill only EMPTY boxes; a differing value becomes a "Paper form says … Use this" offer. */
  function applyPaper(r) {
    var f = r.fields || {}, c = r.confidence || {}, toSave = {}, filled = 0;
    var GEN = { m: 'Male', male: 'Male', 'पुरुष': 'Male', f: 'Female', female: 'Female', 'महिला': 'Female', other: 'Other', o: 'Other' };
    Object.keys(f).forEach(function (k) {
      var v = String(f[k]).trim(); if (!v) return;
      if (k === 'phone') { S.paperPhone = v.replace(/\D/g, '').slice(-10); return; }
      if (k === 'gender') { v = GEN[v.toLowerCase()] || ''; if (!v) return; }
      if (k === 'country') { v = v.toUpperCase(); if (v !== 'IN' && v !== 'NP') return; }
      if (k === 'org_name' && S.d.agent_kind !== 'org') return;
      var cur = S.d[k];
      if (k === 'id_number' && !cur) cur = S.d.id_number_masked || '';
      if (k === 'bank_account' && !cur) cur = S.d.bank_account_last4 ? '••••' + S.d.bank_account_last4 : '';
      if (S.touched[k] || (cur != null && String(cur).trim() !== '')) {
        if (String(cur) !== v && !(k === 'id_number' && /^[X•*]/.test(String(cur)))) S.suggest[k] = { v: v, s: 'paper' };
        return;
      }
      S.d[k] = v; S.src[k] = 'paper'; S.conf[k] = c[k] || 'low'; S.keep[k] = true; toSave[k] = v; filled++;
    });
    var reader = r.reader ? ' · ' + esc(t('reader_' + r.reader)) : '';
    var note = r.note ? '<div class="ap-hint">' + esc(r.note) + '</div>' : '';
    var pages = r.pages ? ' · 📎 ' + r.pages + ' ' + esc(t('paper_pages')) : '';
    if (r.status === 'notform') paperMsg('⚠ ' + esc(t('paper_notform')) + reader + note, true);
    else if (r.status === 'unavailable' || !Object.keys(f).length) paperMsg(esc(t('paper_zero')) + pages + note, r.status === 'unavailable');
    else paperMsg('✨ <b>' + filled + '</b> ' + esc(t('paper_done')) + reader + pages + note);
    if (!Object.keys(toSave).length) { render(); return; }
    toSave.lenient = '1';
    api('save', toSave).then(function (s) {
      if (s.ok) {
        Object.keys(s.data.skipped || {}).forEach(function (k) { S.bad[k] = s.data.skipped[k]; S.conf[k] = 'low'; });
        S.d = Object.assign(s.data.draft, pickTyped());
      }
      render();
    });
  }

  /** Shrink a photo before upload: phones send 4–12 MB; 2200 px JPEG is plenty and quick on 3G. */
  function shrink(file, max) {
    return new Promise(function (resolve) {
      if (!/^image\//.test(file.type) || file.type === 'image/gif') { resolve(file); return; }
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
        var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
        var cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.drawImage(img, 0, 0, cv.width, cv.height);
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

  /* ---- selfie or passport photo ---- */
  var stream = null;
  function bindPhoto() {
    $('#openCam').addEventListener('click', openCam);
    // No capture attribute: the gallery / files open, so a passport photo can be chosen.
    $('#selfieFile').addEventListener('change', function () { var f = this.files && this.files[0]; if (f) { stopCam(); S.previews.selfie = URL.createObjectURL(f); upload('selfie', f); } });
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
      if (!isStaff() && !d.agent_kind) need = 'need_kind';
      else if (!isStaff() && d.agent_kind === 'org' && !(d.org_name || '').trim()) need = 'need_org';
      else if ((d.full_name || '').trim().length < 2) need = 'need_name';
    } else if (st === 'wa' && !d.verified) need = 'need_wa';
    else if (st === 'id') {
      if (OFF) {   // the office: the ID number is enough
        if ((d.full_name || '').trim().length < 2) need = 'need_name';
        else if (!(d.id_number || '').trim() && !d.id_number_masked) need = 'need_idno';
      } else if (!(d.has && d.has.front)) need = 'need_front';
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
    if (st === 'who') pick(OFF ? ['full_name', 'org_name', 'designation'] : ['full_name', 'org_name', 'agent_kind']);
    if (st === 'wa') pick(['whatsapp']);
    if (st === 'id') pick(['id_type', 'full_name', 'dob', 'gender', 'guardian', 'id_number', 'address', 'city', 'district', 'state', 'pincode', 'id_expires_on', 'country', 'pan']);
    if (st === 'bank') { pick(['upi_id', 'bank_holder', 'bank_ifsc', 'bank_name', 'bank_branch', 'esewa_id', 'khalti_id', 'country']); var acc = $('#app [name="bank_account"]'); if (acc && acc.value) o.bank_account = acc.value; }
    if (st === 'finish') pick(['email'].concat(EXTRA));
    if (o.id_number && /^[X•*]{4}/.test(o.id_number)) delete o.id_number;   // a masked number from the QR is already stored
    // The server never sends the full ID number back (only id_number_masked), so after a reload / ?d= resume /
    // office reopen the box is empty: an empty box nobody typed in must not erase the stored number.
    if ((o.id_number == null || String(o.id_number).trim() === '') && S.d.id_number_masked && !S.touched.id_number && !S.src.id_number) delete o.id_number;
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
        var body = { consent: '1' };
        if (OFF) { body.approve_now = S.approveNow ? '1' : '0'; body.saw_id = S.sawId ? '1' : '0'; }
        return api('submit', body).then(function (s) {
          busy(btn, false);
          if (!s.ok) { toast(s.error, true); return; }
          S.res = s.data; S.d.ref = s.data.ref; S.d.status = s.data.status; S.step = 6; stopCam();
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
