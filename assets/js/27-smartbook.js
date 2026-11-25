/* =====================================================================
   27-smartbook.js — the SmartBook card (15 Oct 2026, owner's Final
   Prompt v5). One screen, one tap: direction → date → boarding → mobile +
   name → passengers (gender + age band) → type → berths (Auto by
   default) → [Cash] [Online]. The tap IS the booking.

   Everything is decided on the server (/api/smartbook.php → SmartBook):
   the bus, the date, the berths (AutoBerth), the price (the sale's own
   priceFor) and the sale (BookingService::create, idempotent). This file
   only collects the answers, shows the live summary and the ticket.

   Customer site only: no agent / counter / code field anywhere (D1, D13).
   ===================================================================== */
(function () {
  'use strict';
  var root = document.getElementById('smartBook');
  if (!root || typeof shgApi === 'undefined') return;

  /* ---------------------------------------------------------------- words */
  var W = {
    en: {
      title: 'One page. One tap. Happy journey.', sub: 'Gujarat ⇄ Rupaidiha · AC sleeper', botLbl: 'QuickBot — type it in one line',
      botPh: 'Ram 98…, 2 people, Vadodara tomorrow', botGo: 'Fill the card', pnames: 'Other passengers\' names (optional)', pnPh: 'Passenger {n} name', dirGo: 'Gujarat → Rupaidiha', dirBack: 'Rupaidiha → Gujarat',
      date: 'Date', board: 'Boarding point', drop: 'Getting off at', near: 'Nearest to me', mobile: 'Mobile (WhatsApp ticket)', name: 'Lead passenger name',
      namePh: 'Full name', pax: 'Passengers', sharing: 'Sharing', private: 'Private', cSingle: 'Single', cDouble: 'Double', bAny: 'Any', bLower: 'Lower', bUpper: 'Upper',
      auto: 'Auto', rec: 'Recommended', choose: 'Choose seats', terms: 'By booking I accept the', termsLink: 'Terms', summary: 'Journey Summary',
      sRoute: 'Route', sDate: 'Date', sBoard: 'Boarding', sPax: 'Passengers', sSeats: 'Seats', total: 'Total', cash: '💵 Book · Cash', cashSub: 'ticket now · pay at boarding',
      online: '📲 Book · Online', onlineSub: 'UPI · eSewa QR', pickT: 'Choose your berths', done: 'Done',
      today: 'Today', tomorrow: 'Tomorrow', pickDate: 'Pick a date', female: 'Female', male: 'Male', gender: 'F / M?',
      adult: 'Adult', child: 'Child <12', senior: 'Senior 60+', patient: 'Patient', lower: 'lower floor', upper: 'upper floor', autoTag: '🤖 S Hari AI pick', yours: 'your choice',
      report: 'report', needPhone: 'Enter a valid mobile', needPhoneNp: 'Nepal mobile: 10 digits starting 96 / 97 / 98', needPhoneIn: 'India mobile: 10 digits starting 6–9',
      needName: 'Enter the lead passenger\'s name', needGender: 'Tap F / M for every passenger', aiGuess: 'AI guessed from the name — tap to change', loading: 'Finding the best berths…',
      booking: 'Booking…', making: 'Preparing your ticket…', ticketOk: 'Ticket confirmed', nfNormal: 'Normal fare', nfSave: 'You save', codNote: 'Cash — pay at the boarding counter', pnr: 'PNR', dlPdf: 'Download PDF', dlPng: 'Download PNG',
      share: 'Share', print: 'Print', copy: 'Copy PNR', track: 'Track bus', copied: 'PNR copied', newB: 'Book another', heldQr: 'Berths held — opening the QR page…',
      needAgent: 'Choose the agent this sale is credited to (or Other = company)', agentPick: 'Type a code, name or city — or tap Other', agentBlank: 'Blank = company code',
      agentCo: 'Company code — no agent commission', agentNo: 'Not on the list — pick from the list or tap Other', credited: 'Credited to', commission: 'commission',
      paxN: '{n} passenger(s)', left: '{n} left', changed: 'The price or date changed — please check and tap again.', geoNo: 'Location not available — choose from the list.',
      pickN: '{a} of {b} chosen', women: 'Women only', taken: 'Taken', free: 'Free', mine: 'Your pick', pickAll: 'Choose one berth for each passenger',
      floor1: 'Lower floor (1F)', floor2: 'Upper floor (2F)', perCabin: 'cabin', cabins: 'cabins'
    },
    hi: {
      title: 'एक पेज। एक टैप। शुभ यात्रा।', sub: 'गुजरात ⇄ रुपईडीहा · AC स्लीपर', botLbl: 'QuickBot — एक लाइन में लिखें',
      botPh: 'राम 98…, 2 लोग, वडोदरा कल', botGo: 'कार्ड भरें', pnames: 'अन्य यात्रियों के नाम (वैकल्पिक)', pnPh: 'यात्री {n} का नाम', dirGo: 'गुजरात → रुपईडीहा', dirBack: 'रुपईडीहा → गुजरात',
      date: 'तारीख', board: 'चढ़ने की जगह', drop: 'उतरने की जगह', near: 'मेरे पास', mobile: 'मोबाइल (WhatsApp टिकट)', name: 'मुख्य यात्री का नाम',
      namePh: 'पूरा नाम', pax: 'यात्री', sharing: 'शेयरिंग', private: 'प्राइवेट', cSingle: 'सिंगल', cDouble: 'डबल', bAny: 'कोई भी', bLower: 'नीचे', bUpper: 'ऊपर',
      auto: 'ऑटो', rec: 'सुझाया', choose: 'सीट चुनें', terms: 'बुकिंग करके मैं', termsLink: 'शर्तें मानता हूँ', summary: 'यात्रा सारांश',
      sRoute: 'रूट', sDate: 'तारीख', sBoard: 'चढ़ना', sPax: 'यात्री', sSeats: 'सीट', total: 'कुल', cash: '💵 बुक · कैश', cashSub: 'टिकट अभी · चढ़ते समय भुगतान',
      online: '📲 बुक · ऑनलाइन', onlineSub: 'UPI · eSewa QR', pickT: 'अपनी बर्थ चुनें', done: 'हो गया',
      today: 'आज', tomorrow: 'कल', pickDate: 'तारीख चुनें', female: 'महिला', male: 'पुरुष', gender: 'म / पु?',
      adult: 'वयस्क', child: 'बच्चा <12', senior: 'वरिष्ठ 60+', patient: 'मरीज़', lower: 'नीचे', upper: 'ऊपर', autoTag: '🤖 S Hari AI की पसंद', yours: 'आपकी पसंद',
      report: 'रिपोर्ट', needPhone: 'सही मोबाइल लिखें', needPhoneNp: 'नेपाल मोबाइल: 96 / 97 / 98 से 10 अंक', needPhoneIn: 'भारत मोबाइल: 6–9 से 10 अंक',
      needName: 'मुख्य यात्री का नाम लिखें', needGender: 'हर यात्री के लिए म / पु दबाएँ', aiGuess: 'नाम से AI का अनुमान — बदलने के लिए दबाएँ', loading: 'सबसे अच्छी बर्थ ढूँढ रहे हैं…',
      booking: 'बुक हो रहा है…', making: 'टिकट तैयार हो रहा है…', ticketOk: 'टिकट पक्का', nfNormal: 'सामान्य किराया', nfSave: 'आपकी बचत', codNote: 'कैश — चढ़ते समय काउंटर पर भुगतान', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
      share: 'शेयर', print: 'प्रिंट', copy: 'PNR कॉपी', track: 'बस ट्रैक', copied: 'PNR कॉपी हुआ', newB: 'और बुक करें', heldQr: 'बर्थ रखी गई — QR पेज खुल रहा है…',
      needAgent: 'यह बिक्री किस एजेंट की है चुनें (या Other = कंपनी)', agentPick: 'कोड, नाम या शहर लिखें — या Other दबाएँ', agentBlank: 'खाली = कंपनी कोड',
      agentCo: 'कंपनी कोड — एजेंट कमीशन नहीं', agentNo: 'सूची में नहीं — सूची से चुनें या Other दबाएँ', credited: 'एजेंट', commission: 'कमीशन',
      paxN: '{n} यात्री', left: '{n} बाकी', changed: 'किराया या तारीख बदली — जाँचकर फिर दबाएँ।', geoNo: 'लोकेशन नहीं मिली — सूची से चुनें।',
      pickN: '{b} में {a} चुनी', women: 'केवल महिला', taken: 'बुक', free: 'खाली', mine: 'आपकी', pickAll: 'हर यात्री के लिए एक बर्थ चुनें',
      floor1: 'नीचे (1F)', floor2: 'ऊपर (2F)', perCabin: 'केबिन', cabins: 'केबिन'
    },
    ne: {
      title: 'एउटै पेज। एउटै ट्याप। शुभ यात्रा।', sub: 'गुजरात ⇄ रुपैडिहा · AC स्लिपर', botLbl: 'QuickBot — एक लाइनमा लेख्नुहोस्',
      botPh: 'राम 98…, 2 जना, वडोदरा भोलि', botGo: 'कार्ड भर्नुहोस्', pnames: 'अरू यात्रुको नाम (ऐच्छिक)', pnPh: 'यात्रु {n} को नाम', dirGo: 'गुजरात → रुपैडिहा', dirBack: 'रुपैडिहा → गुजरात',
      date: 'मिति', board: 'चढ्ने ठाउँ', drop: 'ओर्लने ठाउँ', near: 'मेरो नजिक', mobile: 'मोबाइल (WhatsApp टिकट)', name: 'मुख्य यात्रुको नाम',
      namePh: 'पूरा नाम', pax: 'यात्रु', sharing: 'सेयरिङ', private: 'प्राइभेट', cSingle: 'सिंगल', cDouble: 'डबल', bAny: 'जुनसुकै', bLower: 'तल', bUpper: 'माथि',
      auto: 'अटो', rec: 'सिफारिस', choose: 'सिट छान्ने', terms: 'बुकिङ गर्दा', termsLink: 'सर्त मान्छु', summary: 'यात्रा सारांश',
      sRoute: 'रुट', sDate: 'मिति', sBoard: 'चढ्ने', sPax: 'यात्रु', sSeats: 'सिट', total: 'जम्मा', cash: '💵 बुक · नगद', cashSub: 'टिकट अहिले · चढ्दा तिर्ने',
      online: '📲 बुक · अनलाइन', onlineSub: 'UPI · eSewa QR', pickT: 'आफ्नो बर्थ छान्नुहोस्', done: 'भयो',
      today: 'आज', tomorrow: 'भोलि', pickDate: 'मिति छान्नुहोस्', female: 'महिला', male: 'पुरुष', gender: 'म / पु?',
      adult: 'वयस्क', child: 'बच्चा <१२', senior: 'ज्येष्ठ ६०+', patient: 'बिरामी', lower: 'तल्लो तला', upper: 'माथिल्लो तला', autoTag: '🤖 S Hari AI को रोजाइ', yours: 'तपाईंको रोजाइ',
      report: 'रिपोर्ट', needPhone: 'सही मोबाइल लेख्नुहोस्', needPhoneNp: 'नेपाल मोबाइल: ९६ / ९७ / ९८ बाट १० अङ्क', needPhoneIn: 'भारत मोबाइल: ६–९ बाट १० अङ्क',
      needName: 'मुख्य यात्रुको नाम लेख्नुहोस्', needGender: 'हरेक यात्रुको म / पु थिच्नुहोस्', aiGuess: 'नामबाट AI को अनुमान — बदल्न थिच्नुहोस्', loading: 'सबैभन्दा राम्रो बर्थ खोज्दै…',
      booking: 'बुक हुँदैछ…', making: 'टिकट तयार हुँदैछ…', ticketOk: 'टिकट पक्का भयो', nfNormal: 'सामान्य भाडा', nfSave: 'तपाईंको बचत', codNote: 'नगद — चढ्दा काउन्टरमा तिर्ने', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
      share: 'सेयर', print: 'प्रिन्ट', copy: 'PNR कपी', track: 'बस ट्र्याक', copied: 'PNR कपी भयो', newB: 'अर्को बुक', heldQr: 'बर्थ राखियो — QR पेज खुल्दैछ…',
      needAgent: 'यो बिक्री कुन एजेन्टको हो छान्नुहोस् (वा Other = कम्पनी)', agentPick: 'कोड, नाम वा सहर लेख्नुहोस् — वा Other थिच्नुहोस्', agentBlank: 'खाली = कम्पनी कोड',
      agentCo: 'कम्पनी कोड — एजेन्ट कमिसन छैन', agentNo: 'सूचीमा छैन — सूचीबाट छान्नुहोस् वा Other थिच्नुहोस्', credited: 'एजेन्ट', commission: 'कमिसन',
      paxN: '{n} जना', left: '{n} बाँकी', changed: 'भाडा वा मिति बदलियो — हेरेर फेरि थिच्नुहोस्।', geoNo: 'लोकेसन भेटिएन — सूचीबाट छान्नुहोस्।',
      pickN: '{b} मध्ये {a} छानियो', women: 'महिला मात्र', taken: 'बुक भइसक्यो', free: 'खाली', mine: 'तपाईंको', pickAll: 'हरेक यात्रुका लागि एउटा बर्थ छान्नुहोस्',
      floor1: 'तल्लो तला (1F)', floor2: 'माथिल्लो तला (2F)', perCabin: 'क्याबिन', cabins: 'क्याबिन'
    }
  };
  /* Staff faces (admin/smartbook.php on the .network door) set window.SB_STAFF =
     {mode: 'agent' | 'counter' | 'admin', …}; the customer site never does, and
     the server ignores an asked mode a session may not use (SmartBook::sessionMode). */
  var STAFF = (window.SB_STAFF && typeof window.SB_STAFF === 'object') ? window.SB_STAFF : null;
  var MODE = STAFF ? String(STAFF.mode || 'admin') : 'customer';
  /* 16 Oct 2026: selling staff signed in on the website (counter / agent / office)
     book from the ONE staff face of this card - admin/smartbook.php, with the
     agent code, the cash book and the 80 mm print - never from the customer face. */
  var BOOT_STAFF = (window.SHG_BOOT && window.SHG_BOOT.staff) || null;
  var DESK = (!STAFF && BOOT_STAFF && BOOT_STAFF.canSell) ? '/admin/smartbook.php' : '';
  function api(body) { if (STAFF) body.mode = MODE; return shgApi.post('/smartbook.php', body); }
  function lang() { var l = (typeof LANG === 'string' && LANG) ? LANG : 'en'; return W[l] ? l : 'en'; }
  /* 15 Oct 2026 (owner: "catalog form jasto - naam + mobile, baaki default; customer lai k lekhne demo + voice"). */
  var QW = {
    en: { qQuick: '⚡ Quick — name + mobile', qFull: 'Full form', qHelp: '❓ How?', mic: 'Speak your name and mobile', qChange: '✏️ Change',
      micNo: 'Voice is not available in this browser — please type.', micHear: 'Listening… say the name and the mobile',
      hT: '⚡ Ticket in 10 seconds — how?', h1: 'Write the NAME and the MOBILE — that is all.',
      h2: 'Date, pickup and seat are filled by the AI (the next bus, the most-used pickup, the best berth). Tap “✏️ Change” to change any of them.',
      h3: 'Tap 🎫 Book — the ticket comes on WhatsApp.', hVoiceT: '🎤 Or just speak — tap the mic and say:',
      hVoiceEx: '“Ram Bahadur, 98123 45678, Surat, tomorrow”', hWaT: '💬 On WhatsApp — ONE message is enough:',
      hWaEx1: 'Ram Bahadur 9812345678', hWaEx2: 'Sita Kumari 9812345678 Surat tomorrow 2 seat', hWaBtn: '💬 Send on WhatsApp', hListen: '🔊 Listen',
      hChatBot: '✅ Ticket ready · SHG-2026-0xxxx · Surat 13:00 · LB3', hChatBot2: '🎫 Ticket picture + PDF sent on this WhatsApp',
      hWaTpl: 'Name: \nMobile: ',
      hSpeak: 'Booking a ticket is very easy. Just write the passenger name and the mobile number, and tap Book. The date, the pickup and the seat are filled automatically. You can also tap the microphone and speak the name and the number. On WhatsApp, one message with the name and the mobile number is enough, and the ticket comes back on WhatsApp.' },
    hi: { qQuick: '⚡ जल्दी — नाम + मोबाइल', qFull: 'पूरा फ़ॉर्म', qHelp: '❓ कैसे?', mic: 'नाम और मोबाइल बोलिए', qChange: '✏️ बदलें',
      micNo: 'इस ब्राउज़र में आवाज़ नहीं चलती — लिखिए।', micHear: 'सुन रहे हैं… नाम और मोबाइल बोलिए',
      hT: '⚡ 10 सेकंड में टिकट — कैसे?', h1: 'नाम और मोबाइल लिखिए — बस इतना।',
      h2: 'तारीख, चढ़ने की जगह और सीट AI खुद भरता है (अगली बस, सबसे ज़्यादा चढ़ने वाली जगह, अच्छी बर्थ)। बदलना हो तो “✏️ बदलें” दबाइए।',
      h3: '🎫 Book दबाइए — टिकट WhatsApp पर आ जाएगा।', hVoiceT: '🎤 या बोलिए — माइक दबाकर कहिए:',
      hVoiceEx: '“राम बहादुर, 98123 45678, सूरत, कल”', hWaT: '💬 WhatsApp पर — एक ही मैसेज काफ़ी है:',
      hWaEx1: 'Ram Bahadur 9812345678', hWaEx2: 'Sita Kumari 9812345678 Surat kal 2 seat', hWaBtn: '💬 WhatsApp पर भेजें', hListen: '🔊 सुनिए',
      hChatBot: '✅ टिकट तैयार · SHG-2026-0xxxx · Surat 13:00 · LB3', hChatBot2: '🎫 टिकट फ़ोटो + PDF इसी WhatsApp पर',
      hWaTpl: 'Name: \nMobile: ',
      hSpeak: 'टिकट बुक करना बहुत आसान है। बस यात्री का नाम और मोबाइल नंबर लिखिए और बुक दबाइए। तारीख, चढ़ने की जगह और सीट अपने आप भर जाती है। आप माइक दबाकर नाम और नंबर बोल भी सकते हैं। व्हाट्सऐप पर नाम और मोबाइल नंबर का एक मैसेज काफ़ी है, टिकट व्हाट्सऐप पर ही आ जाएगा।' },
    ne: { qQuick: '⚡ छिटो — नाम + मोबाइल', qFull: 'पूरा फारम', qHelp: '❓ कसरी?', mic: 'नाम र मोबाइल बोल्नुहोस्', qChange: '✏️ बदल्नुहोस्',
      micNo: 'यो ब्राउजरमा आवाज चल्दैन — लेख्नुहोस्।', micHear: 'सुन्दैछ… नाम र मोबाइल भन्नुहोस्',
      hT: '⚡ १० सेकेन्डमा टिकट — कसरी?', h1: 'नाम र मोबाइल लेख्नुहोस् — बस् यति।',
      h2: 'मिति, चढ्ने ठाउँ र सिट AI आफैं भर्छ (अर्को बस, धेरै चढ्ने ठाउँ, राम्रो बर्थ)। बदल्न “✏️ बदल्नुहोस्” थिच्नुहोस्।',
      h3: '🎫 Book थिच्नुहोस् — टिकट WhatsApp मा आउँछ।', hVoiceT: '🎤 वा बोल्नुहोस् — माइक थिचेर भन्नुहोस्:',
      hVoiceEx: '“राम बहादुर, 98123 45678, सुरत, भोलि”', hWaT: '💬 WhatsApp मा — एउटै मेसेज पुग्छ:',
      hWaEx1: 'Ram Bahadur 9812345678', hWaEx2: 'Sita Kumari 9812345678 Surat bholi 2 seat', hWaBtn: '💬 WhatsApp मा पठाउनुहोस्', hListen: '🔊 सुन्नुहोस्',
      hChatBot: '✅ टिकट तयार · SHG-2026-0xxxx · Surat 13:00 · LB3', hChatBot2: '🎫 टिकटको फोटो + PDF यही WhatsApp मा',
      hWaTpl: 'Name: \nMobile: ',
      hSpeak: 'टिकट बुक गर्न धेरै सजिलो छ। यात्रुको नाम र मोबाइल नम्बर लेख्नुहोस् र बुक थिच्नुहोस्। मिति, चढ्ने ठाउँ र सिट आफैं भरिन्छ। माइक थिचेर नाम र नम्बर बोल्न पनि सक्नुहुन्छ। व्हाट्सएपमा नाम र मोबाइल नम्बरको एउटै मेसेज पुग्छ, टिकट व्हाट्सएपमै आउँछ।' }
  };
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in QW[l]) { W[l][k] = QW[l][k]; } } });
  /* 16 Oct 2026 (owner: "cash wala lai verification ko lagi details compulsory,
     online ma click garda sidhai bank ko QR popup, 60 second timer"). */
  var VW = {
    en: { cvT: 'Cash booking — verification', cvSub: 'Pay at boarding needs these details. Online does not.', cvName: 'Full name (first name + surname)',
      cvAddr: 'Address — village / city, district', cvAddrPh: 'e.g. Kohalpur-5, Banke', cvIdT: 'ID document', cvIdN: 'ID number', cvIdPh: 'number on the card',
      cvAlt: 'Second mobile (family)', cvOpt: 'optional', cvNote: 'Show this same ID when you board. Wrong details can cancel the ticket.',
      cvGo: '🎫 Book ticket · Cash', cvOnline: 'Or pay Online — no details needed →', cvPick: 'Choose…',
      pyT: 'Pay to confirm', pyAmt: 'Amount to pay', pyScan: 'Scan with GPay · PhonePe · Paytm · BHIM — amount and PNR are filled in',
      pyApp: '📱 Pay in a UPI app', pyCopy: 'Copy', pyCopied: 'Copied', pyLeft: 'QR valid', pyOver: 'QR time is over', pyNew: '🔄 Show a new QR',
      pyHeld: 'Berths held until', pyPaid: 'Paid? Send the UTR', pyUtr: 'UTR / transaction number', pyShot: 'or a screenshot', pySend: 'Send for verification',
      pyNeed: 'Write the UTR or add a screenshot', pyVer: 'Payment is being verified', pyVerSub: 'The office checks it in 1–3 minutes. You can close this — the ticket comes on WhatsApp and in My Bookings.',
      pyGone: 'Time is over — the berths went back on sale. Please book again.', pyEsewa: 'Send this amount from the eSewa app with the PNR in the remarks.',
      pySafe: 'Secure · checked by the S Hari Global office', pyBank: 'Card / Net banking', pyShare: '📤 Send pay link', pyHelp: 'Help',
      waGet: '💬 Get the ticket on WhatsApp', waGetSub: 'tap → send “Hi” → ticket comes back in seconds', waSend: '💬 Send ticket on WhatsApp', fast: 'ready in',
      deskT: 'SmartBook desk', deskSub: 'You are signed in as staff — book from the SmartBook desk (same engine, agent code, 80 mm print).',
      deskGo: '🎫 Book a ticket — SmartBook desk', deskPanel: 'Panel' },
    hi: { cvT: 'कैश बुकिंग — सत्यापन', cvSub: 'चढ़ते समय भुगतान के लिए ये जानकारी ज़रूरी है। Online में नहीं।', cvName: 'पूरा नाम (नाम + सरनेम)',
      cvAddr: 'पता — गाँव / शहर, ज़िला', cvAddrPh: 'जैसे Kohalpur-5, Banke', cvIdT: 'पहचान पत्र', cvIdN: 'पहचान पत्र नंबर', cvIdPh: 'कार्ड पर लिखा नंबर',
      cvAlt: 'दूसरा मोबाइल (परिवार)', cvOpt: 'वैकल्पिक', cvNote: 'चढ़ते समय यही पहचान पत्र दिखाएँ। गलत जानकारी पर टिकट रद्द हो सकता है।',
      cvGo: '🎫 टिकट बुक करें · कैश', cvOnline: 'या Online भुगतान करें — जानकारी नहीं चाहिए →', cvPick: 'चुनें…',
      pyT: 'पक्का करने के लिए भुगतान', pyAmt: 'भुगतान राशि', pyScan: 'GPay · PhonePe · Paytm · BHIM से स्कैन करें — राशि और PNR भरे हुए हैं',
      pyApp: '📱 UPI ऐप में भुगतान', pyCopy: 'कॉपी', pyCopied: 'कॉपी हुआ', pyLeft: 'QR मान्य', pyOver: 'QR का समय खत्म', pyNew: '🔄 नया QR दिखाएँ',
      pyHeld: 'बर्थ रखी गई है', pyPaid: 'भुगतान किया? UTR भेजें', pyUtr: 'UTR / ट्रांज़ैक्शन नंबर', pyShot: 'या स्क्रीनशॉट', pySend: 'जाँच के लिए भेजें',
      pyNeed: 'UTR लिखें या स्क्रीनशॉट जोड़ें', pyVer: 'भुगतान की जाँच हो रही है', pyVerSub: 'ऑफिस 1–3 मिनट में जाँचता है। आप इसे बंद कर सकते हैं — टिकट WhatsApp और My Bookings में आएगा।',
      pyGone: 'समय खत्म — बर्थ फिर बिक्री में। कृपया फिर बुक करें।', pyEsewa: 'eSewa ऐप से यह राशि भेजें, remarks में PNR लिखें।',
      pySafe: 'सुरक्षित · S Hari Global ऑफिस द्वारा जाँच', pyBank: 'कार्ड / नेट बैंकिंग', pyShare: '📤 भुगतान लिंक भेजें', pyHelp: 'मदद',
      waGet: '💬 WhatsApp पर टिकट पाएँ', waGetSub: 'दबाएँ → “Hi” भेजें → टिकट कुछ सेकंड में', waSend: '💬 WhatsApp पर टिकट भेजें', fast: 'तैयार',
      deskT: 'SmartBook डेस्क', deskSub: 'आप staff के रूप में लॉगिन हैं — टिकट SmartBook डेस्क से काटें (वही इंजन, एजेंट कोड, 80 mm प्रिंट)।',
      deskGo: '🎫 टिकट काटें — SmartBook डेस्क', deskPanel: 'पैनल' },
    ne: { cvT: 'नगद बुकिङ — प्रमाणीकरण', cvSub: 'चढ्दा तिर्ने टिकटका लागि यी विवरण अनिवार्य छन्। Online मा चाहिँदैन।', cvName: 'पूरा नाम (नाम + थर)',
      cvAddr: 'ठेगाना — गाउँ / सहर, जिल्ला', cvAddrPh: 'जस्तै Kohalpur-5, Banke', cvIdT: 'परिचयपत्र', cvIdN: 'परिचयपत्र नम्बर', cvIdPh: 'कार्डमा लेखिएको नम्बर',
      cvAlt: 'दोस्रो मोबाइल (परिवार)', cvOpt: 'ऐच्छिक', cvNote: 'चढ्दा यही परिचयपत्र देखाउनुहोस्। गलत विवरण भए टिकट रद्द हुन सक्छ।',
      cvGo: '🎫 टिकट बुक गर्नुहोस् · नगद', cvOnline: 'वा Online तिर्नुहोस् — विवरण चाहिँदैन →', cvPick: 'छान्नुहोस्…',
      pyT: 'पक्का गर्न भुक्तानी', pyAmt: 'तिर्नुपर्ने रकम', pyScan: 'GPay · PhonePe · Paytm · BHIM ले स्क्यान गर्नुहोस् — रकम र PNR भरिएको छ',
      pyApp: '📱 UPI एपमा तिर्नुहोस्', pyCopy: 'कपी', pyCopied: 'कपी भयो', pyLeft: 'QR मान्य', pyOver: 'QR को समय सकियो', pyNew: '🔄 नयाँ QR देखाउनुहोस्',
      pyHeld: 'बर्थ राखिएको छ', pyPaid: 'तिर्नुभयो? UTR पठाउनुहोस्', pyUtr: 'UTR / कारोबार नम्बर', pyShot: 'वा स्क्रिनसट', pySend: 'जाँचका लागि पठाउनुहोस्',
      pyNeed: 'UTR लेख्नुहोस् वा स्क्रिनसट थप्नुहोस्', pyVer: 'भुक्तानी जाँच हुँदैछ', pyVerSub: 'अफिसले १–३ मिनेटमा जाँच्छ। यो बन्द गर्न सक्नुहुन्छ — टिकट WhatsApp र My Bookings मा आउँछ।',
      pyGone: 'समय सकियो — बर्थ फेरि बिक्रीमा। कृपया फेरि बुक गर्नुहोस्।', pyEsewa: 'eSewa एपबाट यो रकम पठाउनुहोस्, remarks मा PNR लेख्नुहोस्।',
      pySafe: 'सुरक्षित · S Hari Global अफिसले जाँच गर्छ', pyBank: 'कार्ड / नेट बैंकिङ', pyShare: '📤 भुक्तानी लिंक पठाउनुहोस्', pyHelp: 'सहयोग',
      waGet: '💬 WhatsApp मा टिकट पाउनुहोस्', waGetSub: 'थिच्नुहोस् → “Hi” पठाउनुहोस् → टिकट केही सेकेन्डमा', waSend: '💬 WhatsApp मा टिकट पठाउनुहोस्', fast: 'तयार',
      deskT: 'SmartBook डेस्क', deskSub: 'तपाईं staff को रूपमा लगइन हुनुहुन्छ — टिकट SmartBook डेस्कबाट काट्नुहोस् (उही इन्जिन, एजेन्ट कोड, 80 mm प्रिन्ट)।',
      deskGo: '🎫 टिकट काट्नुहोस् — SmartBook डेस्क', deskPanel: 'प्यानल' }
  };
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in VW[l]) { W[l][k] = VW[l][k]; } } });
  /* 16 Oct 2026 (owner: "Cash click garda simple final confirmation - summary + OK / Confirm
     Booking, extra step nahos"): Cash opens ONE summary sheet with one Confirm button. A
     customer whose boarding check (address + ID) is already on file only taps Confirm. */
  var OW = {
    en: { okT: 'Confirm booking', okCash: 'Cash', okSubDesk: 'Cash received at the desk — check and confirm', okSubAgent: 'Cash collected by you — check and confirm',
      okSubCus: 'Your details are filled in — just confirm', okGo: '✅ Confirm Booking', okBack: '← Back', okEnter: 'Enter = confirm',
      okPay: 'Pay {amt} in cash at the counter or on the bus when you board.',
      sName: 'Passenger', sMobile: 'Mobile', cvOnFile: 'Boarding check on file', cvEdit: '✏️ Edit', cvNeed: 'For pay at boarding — one-time details' },
    hi: { okT: 'बुकिंग पक्की करें', okCash: 'कैश', okSubDesk: 'कैश काउंटर पर लिया — जाँचें और पक्का करें', okSubAgent: 'कैश आपने लिया — जाँचें और पक्का करें',
      okSubCus: 'आपकी जानकारी भरी हुई है — बस पक्का करें', okPay: '{amt} कैश काउंटर पर या बस में चढ़ते समय दें।', okGo: '✅ बुकिंग पक्की करें', okBack: '← वापस', okEnter: 'Enter = पक्का',
      sName: 'यात्री', sMobile: 'मोबाइल', cvOnFile: 'पहचान जानकारी सुरक्षित है', cvEdit: '✏️ बदलें', cvNeed: 'चढ़ते समय भुगतान के लिए — एक बार की जानकारी' },
    ne: { okT: 'बुकिङ पक्का गर्नुहोस्', okCash: 'नगद', okSubDesk: 'नगद काउन्टरमा लिइयो — हेरेर पक्का गर्नुहोस्', okSubAgent: 'नगद तपाईंले लिनुभयो — हेरेर पक्का गर्नुहोस्',
      okSubCus: 'तपाईंको विवरण भरिसकिएको छ — पक्का मात्र गर्नुहोस्', okPay: '{amt} नगद काउन्टरमा वा बस चढ्दा दिनुहोस्।', okGo: '✅ बुकिङ पक्का गर्नुहोस्', okBack: '← पछाडि', okEnter: 'Enter = पक्का',
      sName: 'यात्रु', sMobile: 'मोबाइल', cvOnFile: 'परिचय विवरण सुरक्षित छ', cvEdit: '✏️ बदल्नुहोस्', cvNeed: 'चढ्दा तिर्नका लागि — एक पटकको विवरण' }
  };
  /* 5 Nov 2026 (master upgrade §1): the sheet names the coach, the drop, the fare
     breakdown and the payment, and every desk (agent / counter / admin) must confirm. */
  OW.en.sBus = 'Bus'; OW.en.sDrop = 'Getting off'; OW.en.sFare = 'Fare'; OW.en.sDisc = 'Discount'; OW.en.tNote = 'Note on ticket'; OW.en.noDisc = 'None — add one in the discount box'; OW.en.sPay = 'Payment'; OW.en.sPayCash = 'Cash — ticket issued on confirm';
  OW.hi.sBus = 'बस'; OW.hi.sDrop = 'उतरना'; OW.hi.sFare = 'भाड़ा'; OW.hi.sDisc = 'छूट'; OW.hi.tNote = 'टिकट पर नोट'; OW.hi.noDisc = 'नहीं — चाहिए तो छूट बॉक्स में'; OW.hi.sPay = 'भुगतान'; OW.hi.sPayCash = 'कैश — पक्का करने पर टिकट';
  OW.ne.sBus = 'बस'; OW.ne.sDrop = 'ओर्लने'; OW.ne.sFare = 'भाडा'; OW.ne.sDisc = 'छुट'; OW.ne.tNote = 'टिकटमा नोट'; OW.ne.noDisc = 'छैन — चाहिए माथिको छुट बक्स'; OW.ne.sPay = 'भुक्तानी'; OW.ne.sPayCash = 'नगद — पक्का गरेपछि टिकट';
  /* 14 Nov 2026: the sheet's Confirm sells what the sheet shows — if the quote moved
     while it was open, the sheet is drawn again with one of these lines. */
  OW.en.seatMoved = 'The seat changed: {a} → {b}.'; OW.en.cvAgain = 'Check the details above and confirm again.';
  OW.hi.seatMoved = 'सीट बदल गई: {a} → {b}।'; OW.hi.cvAgain = 'ऊपर की जानकारी देखकर फिर पक्का करें।';
  OW.ne.seatMoved = 'सिट बदलियो: {a} → {b}।'; OW.ne.cvAgain = 'माथिको विवरण हेरेर फेरि पक्का गर्नुहोस्।';
  OW.en.cvMoved = 'The booking details changed. Check them above and confirm again.';
  OW.hi.cvMoved = 'बुकिंग की जानकारी बदल गई। ऊपर देखकर फिर पक्का करें।';
  OW.ne.cvMoved = 'बुकिङको विवरण बदलियो। माथि हेरेर फेरि पक्का गर्नुहोस्।';
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in OW[l]) { W[l][k] = OW[l][k]; } } });
  /* 15 Nov 2026: the promo code link - customer card only, and only while the office runs a typed coupon. */
  var PW = {
    en: { promoAsk: '🏷️ Have a promo code?', promoPh: 'PROMO CODE', promoGo: 'Apply', promoRm: 'Remove', promoBad: 'Promo not applied',
      promoOk: '{c} applied · − {a} · new total {t}', promoWait: 'Checking the code…' },
    hi: { promoAsk: '🏷️ Promo code है?', promoPh: 'PROMO CODE', promoGo: 'Apply करें', promoRm: 'हटाएँ', promoBad: 'Promo लागू नहीं हुआ',
      promoOk: '{c} लागू · − {a} · नया कुल {t}', promoWait: 'Code जाँच रहे हैं…' },
    ne: { promoAsk: '🏷️ Promo code छ?', promoPh: 'PROMO CODE', promoGo: 'Apply गर्नुहोस्', promoRm: 'हटाउनुहोस्', promoBad: 'Promo लागू भएन',
      promoOk: '{c} लागू भयो · − {a} · नयाँ जम्मा {t}', promoWait: 'Code जाँच्दै…' }
  };
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in PW[l]) { W[l][k] = PW[l][k]; } } });
  /* 15 Nov 2026 (P7): the optional emergency contact - customer card only, never required,
     never messaged. The row's title stays Nepali-first with the English kept in every language. */
  var EW = {
    en: { emT: 'आपतकालीन सम्पर्क (ऐच्छिक) · Emergency contact (optional)', emName: 'Name', emNamePh: 'e.g. Hari Thapa', emPhone: 'Mobile',
      emRel: 'Relation', emPick: 'Choose the relation…', emHint: 'Only for an accident or emergency on the road — no message is ever sent to this number.',
      emOwn: 'This is the passenger\'s own mobile — it will not be saved. Write another person\'s number.',
      emBadNp: 'Nepal mobile: 10 digits starting 96 / 97 / 98 — otherwise it is not saved', emBadIn: 'India mobile: 10 digits starting 6–9 — otherwise it is not saved',
      emNoPhone: 'Add the mobile too — without it the contact is not saved', emOk: 'Emergency contact saved with the ticket' },
    hi: { emT: 'आपातकालीन संपर्क (वैकल्पिक) · Emergency contact (optional)', emName: 'नाम', emNamePh: 'जैसे Hari Thapa', emPhone: 'मोबाइल',
      emRel: 'रिश्ता', emPick: 'रिश्ता चुनें…', emHint: 'रास्ते में दुर्घटना या आपात स्थिति में ही — इस नंबर पर कभी कोई मैसेज नहीं भेजा जाता।',
      emOwn: 'यह यात्री का अपना मोबाइल है — सेव नहीं होगा। किसी और का नंबर लिखें।',
      emBadNp: 'नेपाल मोबाइल: 96 / 97 / 98 से 10 अंक — नहीं तो सेव नहीं होगा', emBadIn: 'भारत मोबाइल: 6–9 से 10 अंक — नहीं तो सेव नहीं होगा',
      emNoPhone: 'मोबाइल भी लिखें — नहीं तो संपर्क सेव नहीं होगा', emOk: 'आपातकालीन संपर्क टिकट के साथ सेव हुआ' },
    ne: { emT: 'आपतकालीन सम्पर्क (ऐच्छिक) · Emergency contact (optional)', emName: 'नाम', emNamePh: 'जस्तै Hari Thapa', emPhone: 'मोबाइल',
      emRel: 'नाता', emPick: 'नाता छान्नुहोस्…', emHint: 'बाटोमा दुर्घटना वा आपतकालमा मात्र — यो नम्बरमा कहिल्यै कुनै सन्देश पठाइँदैन।',
      emOwn: 'यो यात्रुकै मोबाइल हो — सेभ हुँदैन। अर्को व्यक्तिको नम्बर लेख्नुहोस्।',
      emBadNp: 'नेपाल मोबाइल: ९६ / ९७ / ९८ बाट १० अङ्क — नत्र सेभ हुँदैन', emBadIn: 'भारत मोबाइल: ६–९ बाट १० अङ्क — नत्र सेभ हुँदैन',
      emNoPhone: 'मोबाइल पनि लेख्नुहोस् — नत्र सम्पर्क सेभ हुँदैन', emOk: 'आपतकालीन सम्पर्क टिकटसँगै सेभ भयो' }
  };
  EW.en.emS = 'Emergency contact'; EW.hi.emS = 'आपातकालीन संपर्क'; EW.ne.emS = 'आपतकालीन सम्पर्क';
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in EW[l]) { W[l][k] = EW[l][k]; } } });
  /* 16 Nov 2026 (P2-4e): the offline call-back request - every line says it is NOT a ticket. */
  var CW = {
    en: { cbBtn: '📞 Call-back request', cbBtnSub: 'offline · not a ticket', cbOffT: '📵 No internet — booking needs the network',
      cbOffSub: 'Leave a call-back request: the office will call you to book. It is not a ticket.',
      cbT: 'Call-back request', cbSub: 'You are offline', cbNotT: 'This is NOT a ticket.',
      cbNot: 'No seat is held and nothing is paid. The request is saved on this phone and goes to the office when the network returns — the office will call you to book.',
      cbRoute: 'Route', cbDate: 'Travel date', cbSeats: 'Seats', cbName: 'Your name', cbPhone: 'Mobile (the office calls this number)',
      cbGo: '📨 Save call-back request', cbBack: '← Back', cbClose: 'Close', cbDone: 'Request saved on this phone',
      cbDoneSub: 'It goes to the office by itself when the network returns. The office will call {p} to book. This is not a ticket.',
      cbDup: 'This request is already saved — it goes to the office when the network returns. This is not a ticket.',
      cbWait: '{n} waiting to send', cbNoStore: 'This phone could not save the request — please call the office:' },
    hi: { cbBtn: '📞 कॉल का अनुरोध', cbBtnSub: 'ऑफ़लाइन · टिकट नहीं', cbOffT: '📵 इंटरनेट नहीं — बुकिंग के लिए नेटवर्क चाहिए',
      cbOffSub: 'कॉल का अनुरोध छोड़ें: बुकिंग के लिए ऑफ़िस आपको कॉल करेगा। यह टिकट नहीं है।',
      cbT: 'कॉल का अनुरोध', cbSub: 'आप ऑफ़लाइन हैं', cbNotT: 'यह टिकट नहीं है।',
      cbNot: 'कोई सीट रोकी नहीं गई और कोई भुगतान नहीं हुआ। अनुरोध इसी फ़ोन में सेव होगा और नेटवर्क आते ही ऑफ़िस पहुँचेगा — बुकिंग के लिए ऑफ़िस आपको कॉल करेगा।',
      cbRoute: 'रूट', cbDate: 'यात्रा की तारीख', cbSeats: 'सीट', cbName: 'आपका नाम', cbPhone: 'मोबाइल (इसी नंबर पर ऑफ़िस कॉल करेगा)',
      cbGo: '📨 कॉल का अनुरोध सेव करें', cbBack: '← वापस', cbClose: 'बंद करें', cbDone: 'अनुरोध इस फ़ोन में सेव हुआ',
      cbDoneSub: 'नेटवर्क आते ही यह अपने-आप ऑफ़िस पहुँचेगा। बुकिंग के लिए ऑफ़िस {p} पर कॉल करेगा। यह टिकट नहीं है।',
      cbDup: 'यह अनुरोध पहले से सेव है — नेटवर्क आते ही ऑफ़िस पहुँचेगा। यह टिकट नहीं है।',
      cbWait: '{n} भेजना बाकी', cbNoStore: 'यह फ़ोन अनुरोध सेव नहीं कर पाया — कृपया ऑफ़िस को कॉल करें:' },
    ne: { cbBtn: '📞 फोनको अनुरोध', cbBtnSub: 'अफलाइन · टिकट होइन', cbOffT: '📵 इन्टरनेट छैन — बुकिङका लागि नेटवर्क चाहिन्छ',
      cbOffSub: 'फोनको अनुरोध छोड्नुहोस्: बुकिङका लागि कार्यालयले फोन गर्नेछ। यो टिकट होइन।',
      cbT: 'फोनको अनुरोध', cbSub: 'तपाईं अफलाइन हुनुहुन्छ', cbNotT: 'यो टिकट होइन।',
      cbNot: 'कुनै सिट राखिएको छैन र कुनै भुक्तानी भएको छैन। अनुरोध यही फोनमा सेभ हुन्छ र नेटवर्क आएपछि कार्यालयमा पुग्छ — बुकिङका लागि कार्यालयले फोन गर्नेछ।',
      cbRoute: 'रुट', cbDate: 'यात्रा मिति', cbSeats: 'सिट', cbName: 'तपाईंको नाम', cbPhone: 'मोबाइल (यही नम्बरमा कार्यालयले फोन गर्छ)',
      cbGo: '📨 फोनको अनुरोध सेभ गर्नुहोस्', cbBack: '← पछाडि', cbClose: 'बन्द गर्नुहोस्', cbDone: 'अनुरोध यो फोनमा सेभ भयो',
      cbDoneSub: 'नेटवर्क आएपछि आफैं कार्यालयमा पुग्छ। बुकिङका लागि कार्यालयले {p} मा फोन गर्नेछ। यो टिकट होइन।',
      cbDup: 'यो अनुरोध पहिले नै सेभ छ — नेटवर्क आएपछि कार्यालयमा पुग्छ। यो टिकट होइन।',
      cbWait: '{n} पठाउन बाँकी', cbNoStore: 'यो फोनले अनुरोध सेभ गर्न सकेन — कृपया कार्यालयमा फोन गर्नुहोस्:' }
  };
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in CW[l]) { W[l][k] = CW[l][k]; } } });
  function tr(k, v) {
    var s = (W[lang()] && W[lang()][k]) || W.en[k] || k;
    if (v) Object.keys(v).forEach(function (x) { s = s.split('{' + x + '}').join(v[x]); });
    return s;
  }
  function L3(o) { if (!o) return ''; return o[lang()] || o.en || ''; }
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  /* 20 Nov 2026 (nf1): the route's REAL normal fare struck through + the saving -
     only when the server sent {normal, save} (office-set, higher than this price). */
  function nfHtml(nf) {
    if (!nf || !(Number(nf.save) >= 1)) return '';
    return '<span class="sb-nf"><s title="' + esc(tr('nfNormal')) + '">' + esc(money(nf.normal)) + '</s> <em>' + esc(tr('nfSave')) + ' ' + esc(money(nf.save)) + '</em></span>';
  }
  function npr(n) { return 'NPR ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function say(m) { try { if (typeof toast === 'function') toast(m); } catch (e) {} }

  /* ---------------------------------------------------------------- state */
  var S = {
    dirs: [], points: {}, dir: 'toNepal', date: '', point: '', cc: 'IN', phone: '', name: '',
    pax: [{ gender: '', band: 'adult' }], type: 'sharing', cabin: 'single', berth: 'any', seatMode: 'auto', manual: [],
    q: null, busy: false, idem: null, touched: {}, why: {}, allowCod: true, maxPax: 6, last: null,
    /* 16 Oct 2026 (owner: "fast quick name number system lai default, tesai
       rakhdeu sabai"): every visit opens on Quick (name + mobile). "✏️ Change"
       still opens the full form for this visit only - it is no longer saved. */
    /* 20 Nov 2026 (px2, owner: "quick ra full 2 ta form - euta matra, full form default"): the page may
       stamp window.SHG_SB_FORM = 'full' (setting smartbook_form_mode) - the card then opens as the full
       form and the Quick / Full switch is not drawn. Anything else = Quick first, as before. */
    quick: !(window.SHG_SB_FORM === 'full'),
    agent: '',  // staff desks only: the SHG code the sale is credited to ('OTHER' = company) - never on the customer page
    /* Cash verification (customer only) - kept in memory for this visit, never
       in localStorage (an ID number must not sit on a shared phone). */
    codVerify: false, idTypes: [], qrSeconds: 60, seatPickFirst: true,
    promoOn: false, promo: '',   // 15 Nov 2026: the typed promo code (memory only, never saved)
    verify: { address: '', idType: '', idNum: '', altPhone: '', altCc: 'NP' },
    /* 15 Nov 2026 (P7): the emergency contact - memory only (another person's number never
       sits in localStorage on a shared phone). Nepal by default: the family at home. */
    emOn: false, relations: [], em: { name: '', phone: '', cc: 'NP', rel: '', touched: false }
  };
  var store = {
    get: function () { try { return JSON.parse(localStorage.getItem('sb:v1') || 'null') || {}; } catch (e) { return {}; } },
    set: function () {
      try {
        localStorage.setItem('sb:v1', JSON.stringify({ dir: S.dir, point: S.point, cc: S.cc, phone: S.phone, name: S.name,
          pax: S.pax.map(function (p) { return { gender: p.gender, band: p.band }; }), type: S.type, cabin: S.cabin, berth: S.berth }));
      } catch (e) {}
    }
  };

  /* -------------------------------------------------------------- i18n */
  function applyWords() {
    root.querySelectorAll('[data-sbi]').forEach(function (el) { el.textContent = tr(el.getAttribute('data-sbi')); });
    root.querySelectorAll('[data-sbi-ph]').forEach(function (el) { el.setAttribute('placeholder', tr(el.getAttribute('data-sbi-ph'))); });
    root.querySelectorAll('[data-sbi-title]').forEach(function (el) { var s = tr(el.getAttribute('data-sbi-title')); el.title = s; el.setAttribute('aria-label', s); });
    try { if (typeof factPolicyLine === 'function') $('sbPolicy').textContent = ' · ' + factPolicyLine(); } catch (e) {}
    if (STAFF) {   // at a desk Cash is money taken now; an agent's Online is the customer's QR
      root.querySelector('#sbCash small').textContent = MODE === 'agent' ? 'agent collects' : 'received now';
      root.querySelector('#sbOnline small').textContent = MODE === 'agent' ? 'customer pays QR' : 'UPI · eSewa seen';
    }
    $('sbPointL').textContent = tr(pointType() === 'drop' ? 'drop' : 'board');
    renderDates(); renderPax(); renderQuote();
  }

  /* -------------------------------------------------------------- boot */
  function renderDesk() {
    document.documentElement.classList.add('sb-on');
    retargetBookLinks();
    var who = BOOT_STAFF.code ? BOOT_STAFF.code + ' · ' + (BOOT_STAFF.name || '') : (BOOT_STAFF.name || '');
    var role = BOOT_STAFF.role === 'agent' ? 'Agent' : (BOOT_STAFF.role === 'counter' ? 'Counter' : 'Office');
    var el = document.createElement('div');
    el.id = 'sbDesk'; el.className = 'sb-desk';
    el.innerHTML = '<div class="sb-desk-in"><span class="sb-desk-ic" aria-hidden="true">⚡</span><div class="sb-desk-tx"><b>' + esc(tr('deskT')) + ' · ' + esc(role) + '</b>'
      + '<small>' + esc(who) + '</small><p>' + esc(tr('deskSub')) + '</p></div>'
      + '<a class="sb-desk-go" href="' + esc(DESK) + '">' + esc(tr('deskGo')) + '</a>'
      + '<a class="sb-desk-alt" href="' + esc(BOOT_STAFF.panelUrl || '/admin/') + '">' + esc(tr('deskPanel')) + ' →</a></div>';
    root.parentNode.insertBefore(el, root);
    root.hidden = true;
    new MutationObserver(function () {
      el.querySelector('b').textContent = tr('deskT') + ' · ' + role;
      el.querySelector('p').textContent = tr('deskSub');
      el.querySelector('.sb-desk-go').textContent = tr('deskGo');
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
    steerClassic();
  }
  function boot() {
    if (DESK) { renderDesk(); return; }
    document.documentElement.classList.add('sb-on');
    root.hidden = false;
    retargetBookLinks();
    steerClassic();
    var saved = store.get();
    api({ action: 'boot', phone: saved.phone || '', direction: saved.dir || '' })
      .then(function (d) {
        S.dirs = d.directions || [];
        S.points = d.points || {};
        S.allowCod = d.allowCod !== false;
        S.maxPax = d.maxPax || 6;
        S.codVerify = !STAFF && !!d.codVerify;
        S.idTypes = d.idTypes || [];
        S.qrSeconds = d.qrSeconds || 60;
        S.seatPickFirst = d.seatPickFirst !== false;
        S.promoOn = !STAFF && !!d.promo;
        buildPromo();
        S.emOn = !STAFF && !!d.emergency;   // the server says so only once its columns exist
        S.relations = d.relations || [];
        buildEm();
        if (d.prefill) {
          ['address', 'idType'].forEach(function (k) { if (d.prefill[k] && !S.verify[k]) S.verify[k] = d.prefill[k]; });
          if (d.prefill.idNum && !S.verify.idNum) S.verify.idNum = d.prefill.idNum;
        }
        var df = d.defaults || {};
        S.why = df.why || {};
        S.dir = saved.dir || df.direction || 'toNepal';
        S.point = (saved.point && hasPoint(S.dir, saved.point)) ? saved.point : (df.point || '');
        if (S.dir !== df.direction && !hasPoint(S.dir, S.point)) S.point = firstPoint(S.dir);
        S.cc = saved.cc || 'IN'; S.phone = saved.phone || ''; S.name = saved.name || df.name || '';
        S.pax = (saved.pax && saved.pax.length ? saved.pax : (df.pax && df.pax.length ? df.pax : S.pax)).slice(0, S.maxPax)
          .map(function (p) { return { gender: p.gender === 'Female' || p.gender === 'Male' ? p.gender : '', band: p.band || 'adult' }; });
        /* 16 Oct 2026 (owner: "badi jaso sharing use hunx - default sharing, private clickable option"):
           every visit opens on Sharing; Private is one tap (the 🔒 chip / the Full form). */
        S.type = 'sharing'; S.cabin = 'single'; S.berth = saved.berth || df.berth || 'any';
        if (!S.allowCod) $('sbCash').hidden = true;
        fill(); applyWords(); applyQuick(); requote(0);
        /* 13 Nov 2026 (mg20): a ticket order typed in the Home AI box ("Ram 98…, Surat bholi 2 jana")
           arrives as ?order= — read into the card here; the person checks it and taps Book. */
        try {
          var ord = new URLSearchParams(location.search).get('order');
          if (ord && STAFF) {
            $('sbBotIn').value = ord; fillFromText(ord);
            history.replaceState(null, '', location.pathname + location.search.replace(/([?&])order=[^&]*&?/, '$1').replace(/[?&]$/, ''));
          }
        } catch (x) {}
        cbBooted = true; cbSync();   // P2-4e: offline right now -> the call-back button in place of Book
      })
      .catch(function (e) {
        // the engine is unreachable: the classic search card takes over
        document.documentElement.classList.remove('sb-on');
        root.hidden = true;
        console.warn('SmartBook boot failed', e);
        /* P2-4e: opened offline from the saved page - with offline_callback_on the card's
           place shows "no internet" + the call-back request (it boots again once online) */
        cbFailed = true; cbSync();
      });
  }
  function pointType() { return S.dir === 'toIndia' ? 'drop' : 'boarding'; }
  function pointList(dir) { return ((S.points[dir || S.dir] || {}).list) || []; }
  function hasPoint(dir, name) { return pointList(dir).some(function (p) { return p.name === name; }); }
  function firstPoint(dir) { var l = pointList(dir); return l.length ? l[0].name : ''; }

  /* ------------------------------------------------------- the controls */
  function fill() {
    root.querySelectorAll('.sb-dir-b').forEach(function (b) {
      var on = b.getAttribute('data-dir') === S.dir;
      b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    var sel = $('sbPoint'); var h = '';
    pointList().forEach(function (p) {
      var lbl = p.short || p.name;
      if (p.time) lbl += ' · ' + p.time; else if (p.landmark) lbl += ' · ' + p.landmark;
      h += '<option value="' + esc(p.name) + '"' + (p.name === S.point ? ' selected' : '') + '>' + esc(lbl) + '</option>';
    });
    sel.innerHTML = h || '<option value="">—</option>';
    if (!hasPoint(S.dir, S.point)) S.point = firstPoint();
    sel.value = S.point;
    $('sbPointWhy').textContent = S.why.point ? L3(S.why.point) : '';
    $('sbCc').value = S.cc; $('sbPhone').value = S.phone; $('sbName').value = S.name;
    altOther();   // a pasted / AI-filled main number can change S.cc without a 'change' event
    seg('sbType', S.type); seg('sbCabin', S.cabin); seg('sbBerth', S.berth); seg('sbSeatMode', S.seatMode);
    $('sbCabin').hidden = S.type !== 'private';
    nearSync();   // P4-1f: the 📍 button only where it can answer
    renderPax(); renderDates();
  }
  function seg(id, v) {
    $(id).querySelectorAll('button').forEach(function (b) { var on = b.getAttribute('data-v') === v; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  }

  function isoAdd(iso, n) { var d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function todayIso() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function dayLabel(iso) {
    if (iso === todayIso()) return tr('today');
    if (iso === isoAdd(todayIso(), 1)) return tr('tomorrow');
    try { return new Date(iso + 'T00:00:00').toLocaleDateString(lang() === 'en' ? 'en-IN' : (lang() === 'hi' ? 'hi-IN' : 'ne-NP'), { weekday: 'short' }); } catch (e) { return iso; }
  }
  function renderDates() {
    var box = $('sbDates'); if (!box) return;
    var first = (S.q && S.q.date) || S.date || todayIso();
    var cur = S.date || (S.q && S.q.date) || '';
    var a = S.date && S.date !== first ? [S.date] : [];
    var list = [first, isoAdd(first, 1)];
    if (a.length && list.indexOf(a[0]) < 0) list = [first, a[0]];
    var h = '';
    list.forEach(function (iso) {
      var d = new Date(iso + 'T00:00:00');
      h += '<button type="button" class="sb-chip' + (iso === cur ? ' on' : '') + '" role="radio" aria-checked="' + (iso === cur) + '" data-date="' + iso + '">'
        + '<b>' + esc(dayLabel(iso)) + '</b><small>' + d.getDate() + ' ' + d.toLocaleDateString('en-IN', { month: 'short' }) + esc(bsShort(iso, ' · ')) + '</small></button>';
    });
    h += '<button type="button" class="sb-chip sb-chip-cal" data-date="cal" aria-label="' + esc(tr('pickDate')) + '">📅</button>';
    box.innerHTML = h;
    if (S.q && S.q.window && !STAFF) { $('sbDateIn').min = S.q.window.from; $('sbDateIn').max = S.q.window.to; }
  }

  /* 17 Oct 2026 (owner): the Nepali calendar beside the English one - nepaliBS() in
     02-config.js (empty outside its table, so the date degrades to AD only) */
  function bsShort(iso, sep) {
    try { var b = typeof nepaliBS === 'function' ? nepaliBS(iso) : null; return b ? (sep || '') + b.monthName + ' ' + bsDevanagari(b.d) : ''; } catch (e) { return ''; }
  }
  function bsFull(iso, sep) {
    try { var t = typeof nepaliBSFull === 'function' ? nepaliBSFull(iso) : ''; return t ? (sep || '') + t : ''; } catch (e) { return ''; }
  }
  /* 17 Oct 2026 (owner): every passenger may carry a name - optional, the lead
     name stays the one typed above; blank = "<lead> (2)" as before (server) */
  function renderPNames() {
    var box = $('sbPNames'); if (!box) return;
    if (S.pax.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
    var h = '<span class="sb-pn-l">' + esc(tr('pnames')) + '</span>';
    for (var i = 1; i < S.pax.length; i++) {
      h += '<label class="sb-pn"><b>' + (i + 1) + '</b><input type="text" maxlength="60" autocomplete="off" data-pn="' + i + '" placeholder="' + esc(tr('pnPh', { n: i + 1 })) + '" value="' + esc(S.pax[i].name || '') + '"></label>';
    }
    box.innerHTML = h; box.hidden = false;
  }
  document.addEventListener('input', function (e) {
    var el = e.target; if (!el || !el.hasAttribute || !el.hasAttribute('data-pn')) return;
    var i = +el.getAttribute('data-pn'); if (S.pax[i]) S.pax[i].name = el.value.slice(0, 60);
  });
  function bandLabel(b) { return tr(b === 'child' ? 'child' : (b === 'senior' ? 'senior' : (b === 'patient' ? 'patient' : 'adult'))); }
  function renderPax() {
    $('sbCount').textContent = String(S.pax.length);
    var h = '';
    S.pax.forEach(function (p, i) {
      var g = p.gender === 'Female' ? 'f' : (p.gender === 'Male' ? 'm' : 'u');
      var gl = p.gender === 'Female' ? '♀ ' + tr('female') : (p.gender === 'Male' ? '♂ ' + tr('male') : tr('gender'));
      if (p.ai && g !== 'u') gl = '🤖 ' + gl;
      h += '<span class="sb-p' + (g === 'u' && S.touched.book ? ' sb-need' : '') + '"><button type="button" class="sb-g ' + g + (p.ai && g !== 'u' ? ' sb-ai' : '') + '" data-i="' + i + '" data-act="g"' + (p.ai && g !== 'u' ? ' title="' + esc(tr('aiGuess')) + '"' : '') + ' aria-label="Passenger ' + (i + 1) + ' gender">' + esc(gl) + '</button>'
        + '<button type="button" class="sb-b" data-i="' + i + '" data-act="b" aria-label="Passenger ' + (i + 1) + ' age band">' + esc(bandLabel(p.band)) + '</button></span>';
    });
    var row = $('sbPax'); row.innerHTML = h;
    row.classList.toggle('sb-scroll', S.pax.length >= 5);
    renderPNames();
    renderQLine();
  }

  /* ------------------------------------------------------- validation */
  function digits(s) { return String(s || '').replace(/\D/g, ''); }
  function phoneErr() {
    var d = digits(S.phone);
    if (!d && (MODE === 'counter' || MODE === 'admin')) return '';
    if (S.cc === 'NP') { if (d.length === 13 && d.indexOf('977') === 0) d = d.slice(3); return /^9[678]\d{8}$/.test(d) ? '' : tr('needPhoneNp'); }
    if (d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return /^[6-9]\d{9}$/.test(d) ? '' : tr('needPhoneIn');
  }
  /* 19 Nov 2026 (u4 desk glue): the desk card's optional second WhatsApp number - 10 digits
     in its own country, never the same as the first (SmartBook::bookNow checks it again). */
  function altDigits() {
    var el = $('sbAltPhone'); if (!el) return '';
    var d = digits(el.value), np = ($('sbAltCc') || {}).value === 'NP';
    if (np && d.length === 13 && d.indexOf('977') === 0) d = d.slice(3);
    if (!np && d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    if (!np && d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return d;
  }
  function altErr() {
    var d = altDigits(); if (!d) return '';
    if (d.length !== 10 || d === digits(S.phone).slice(-10)) return 'दोस्रो नम्बर: १० अङ्क, पहिलोभन्दा फरक · 2nd number: 10 digits, not the first number';
    /* the same country rules as phoneErr(), so the ticket never goes to a number that cannot
       exist (and BookingService::create would silently drop one not starting 6-9) */
    if (($('sbAltCc') || {}).value === 'NP') {
      if (!/^9[678]\d{8}$/.test(d)) return 'दोस्रो नम्बर: नेपाल नम्बर 98/97/96 बाट सुरु हुन्छ · 2nd number: a Nepal number starts with 98/97/96';
    } else if (!/^[6-9]\d{9}$/.test(d)) {
      return 'दोस्रो नम्बर: भारत नम्बर 6/7/8/9 बाट सुरु हुन्छ · 2nd number: an India number starts with 6/7/8/9';
    }
    return '';
  }
  /* while the clerk has not chosen the 2nd number's country: a typed +977 / +91 prefix picks
     it, and a number that cannot be a Nepal mobile (not 96/97/98) is India. 98... stays as
     it is (it can be either country). */
  function altGuessCc() {
    var cc = $('sbAltCc'), el = $('sbAltPhone'); if (!cc || !el || S.altPicked) return;
    var raw = String(el.value || '').replace(/[^\d+]/g, ''), d = digits(raw);
    /* 977 counts as the code only with a '+' or past 10 digits: 9771234567 is a real India mobile */
    if (/^\+977/.test(raw) || (d.length >= 11 && d.indexOf('977') === 0)) { cc.value = 'NP'; return; }
    if (/^\+91/.test(raw) || (d.length === 12 && d.indexOf('91') === 0)) { cc.value = 'IN'; return; }
    if (d.length >= 2 && !/^9[678]/.test(d)) cc.value = 'IN';
  }
  /* 19 Nov 2026: "पैसा कहिले? · When will they pay" on a desk Cash sale - a note for the
     office only, never a payment (BookingService::cashNoteClean keeps what is real). */
  function cashNote() {
    var w = $('sbxCashWhen'); if (!w || !w.value) return null;
    var o = { when: w.value };
    if (w.value === 'partial') { var a = Number(($('sbxCashAmt') || {}).value) || 0; if (a > 0) o.amount = Math.round(a * 100) / 100; }
    if (w.value !== 'full') { var d = String(($('sbxCashDate') || {}).value || ''); if (/^\d{4}-\d{2}-\d{2}$/.test(d)) o.date = d; }
    var n = String(($('sbxCashTxt') || {}).value || '').replace(/\s+/g, ' ').trim().slice(0, 80); if (n) o.note = n;
    return o;
  }
  function cashNoteText(o) {
    if (!o) return '';
    var w = { full: 'पूरा तिर्यो · Paid in full', partial: 'आंशिक · Part paid', later: 'पछि तिर्ने · Pays later' }[o.when] || '';
    return [w, o.amount ? money(o.amount) : '', o.date ? (o.when === 'later' ? '' : 'by ') + o.date : '', o.note || ''].filter(Boolean).join(' · ');
  }
  /* Is this desk-list entry a staff member who earns no commission (staff_commission_off)?
     The server says so per entry (a.noComm) or for the whole list (STAFF.staffNoComm). */
  function noComm(a) {
    if (!a || a.type !== 'staff') return false;
    if (typeof a.noComm === 'boolean') return a.noComm;
    return !!(STAFF && STAFF.staffNoComm);
  }
  function errs() {
    var e = {};
    if (STAFF && STAFF.agentRequired && $('sbAgent') && !S.agent) e.agent = tr('needAgent');
    var pe = phoneErr(); if (pe) e.phone = pe;
    var ae = STAFF ? altErr() : ''; if (ae) e.alt = ae;
    if (String(S.name || '').trim().length < 2) e.name = tr('needName');
    if (S.pax.some(function (p) { return !p.gender; })) e.pax = tr('needGender');
    return e;
  }
  function showErrs(all) {
    var e = errs();
    var f = [['phone', 'sbPhone', 'sbPhoneE'], ['name', 'sbName', 'sbNameE'], ['pax', null, 'sbPaxE']];
    if ($('sbAgent')) f.unshift(['agent', 'sbAgent', 'sbAgentE']);
    if ($('sbAltPhone')) f.push(['alt', 'sbAltPhone', 'sbAltE']);
    f.forEach(function (x) {
      var show = !!e[x[0]] && (all || S.touched[x[0]]);
      var el = $(x[2]); el.hidden = !show; el.textContent = show ? e[x[0]] : '';
      if (x[1]) $(x[1]).classList.toggle('sb-bad', show);
    });
    if (all) renderPax();
    return e;
  }
  function canBook() { return S.q && S.q.ok && !S.busy && Object.keys(errs()).length === 0; }
  /* The book buttons never go dead: a tap with something missing says what and
     jumps to it (book() → showErrs). .sb-wait only dims them until the berths
     are priced and the details are complete; .sb-ready = one tap books. */
  function syncButtons() {
    var ready = canBook(), priced = !!(S.q && S.q.ok) && !S.busy;
    ['sbCash', 'sbOnline'].forEach(function (id) {
      var b = $(id); b.disabled = false;
      b.classList.toggle('sb-ready', ready); b.classList.toggle('sb-wait', !priced || !ready);
    });
    paintRing(priced);
  }
  /* 15 Oct 2026 (Smart AI upgrade): a compact ring beside "How?" that fills as the
     card completes — name, mobile, gender, priced bus. Display only; the book
     buttons keep their own rules. */
  var RING_C = 2 * Math.PI * 15;
  function paintRing(priced) {
    var box = $('sbRing'); if (!box) {
      var bar = root.querySelector('.sb-qbar'), help = $('sbHelpBtn'); if (!bar) return;
      box = document.createElement('span'); box.id = 'sbRing'; box.className = 'sb-ring'; box.setAttribute('role', 'img');
      box.innerHTML = '<svg viewBox="0 0 36 36" aria-hidden="true"><circle class="sb-ring-bg" cx="18" cy="18" r="15"/>'
        + '<circle class="sb-ring-fg" cx="18" cy="18" r="15" stroke-dasharray="' + RING_C.toFixed(2) + '" stroke-dashoffset="' + RING_C.toFixed(2) + '"/></svg><b></b>';
      bar.insertBefore(box, help || null);
    }
    var e = errs(), steps = [!e.name, !e.phone, !e.pax, !!priced];
    var n = steps.filter(Boolean).length, pct = n / steps.length;
    box.querySelector('.sb-ring-fg').setAttribute('stroke-dashoffset', (RING_C * (1 - pct)).toFixed(2));
    box.className = 'sb-ring ' + (n === steps.length ? 'sb-ring-ok' : (n >= 2 ? 'sb-ring-mid' : 'sb-ring-lo'));
    box.querySelector('b').textContent = n === steps.length ? '✓' : (n + '/' + steps.length);
    box.setAttribute('aria-label', n + ' of ' + steps.length + ' ready');
    box.title = n === steps.length ? tr('done') : n + '/' + steps.length;
  }

  /* ------------------------------------------------------------ quote */
  var qTimer = null, qSeq = 0;
  /* 14 Nov 2026: qPend = the card changed and the new quote has not come back yet.
     While it is true S.q is the OLD answer (old berth, old pickup): book() waits for
     the new one before it opens the seat map / a confirm sheet or sells. */
  var qPend = false, quoteAt = 0;
  function requote(ms) {
    clearTimeout(qTimer);
    S.idem = null;   // anything changed → a new booking intent
    /* A hand pick that a change dropped (gender, age band, date, pickup, +/-, type) is
       an AutoBerth sale again: back to Auto, so the seat map opens once more before
       the sale (seat_pick_manual_first) with the new berth marked. */
    if (S.seatMode === 'manual' && !S.manual.length) { S.seatMode = 'auto'; seg('sbSeatMode', 'auto'); }
    qPend = true;
    qTimer = setTimeout(doQuote, ms == null ? 260 : ms);
  }
  function quoteBody() {
    var b = { action: 'quote', direction: S.dir, date: S.date, point: S.point, type: S.type, cabin: S.cabin, berth: S.berth,
      pax: S.pax.map(function (p) { return { gender: p.gender, band: p.band, name: String(p.name || '').trim().slice(0, 60) }; }),
      seats: S.seatMode === 'manual' ? S.manual : [], phone: digits(S.phone) };
    if (S.promoOn && S.promo) b.promo = S.promo;   // 15 Nov 2026: the server prices it (and the sale charges the same)
    /* 13 Nov 2026 (mg17): the desk's discount (admin / counter page only — the
       boxes exist nowhere else). The server caps it and prices the ticket. */
    var dv = document.getElementById('sbxDiscVal');
    if (dv && Number(dv.value) > 0) {
      b.discountValue = Number(dv.value);
      b.discountType = (document.getElementById('sbxDiscType') || {}).value === 'percent' ? 'percent' : 'flat';
    }
    return b;
  }
  (function () {
    var dv = document.getElementById('sbxDiscVal'), dt = document.getElementById('sbxDiscType');
    if (!dv || !dt) return;
    var chips = document.querySelectorAll('.sbx-chips button');
    var mark = function () {   // 15 Nov 2026: the chip matching what is typed lights up
      for (var i = 0; i < chips.length; i++) {
        var c = chips[i], v = Number(dv.value) || 0;
        c.classList.toggle('on', c.getAttribute('data-dt') !== '' && Number(c.getAttribute('data-dv')) === v && c.getAttribute('data-dt') === dt.value && v > 0);
      }
    };
    var again = function () { S.idem = null; mark(); requote(250); };
    dv.addEventListener('input', again);
    dt.addEventListener('change', again);
    for (var i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', function () {
        var t = this.getAttribute('data-dt'), v = Number(this.getAttribute('data-dv')) || 0;
        if (t) dt.value = t;
        dv.value = v > 0 ? String(v) : '';
        if (navigator.vibrate) { try { navigator.vibrate(12); } catch (x) {} }
        again();
      });
    }
    window.SB_DISC_MARK = mark;
  })();
  /* 15 Nov 2026: the desk's note for the passenger, printed on the ticket (staff page only). */
  function ticketNote() {
    var n = document.getElementById('sbxTNote');
    return n ? String(n.value || '').replace(/\s+/g, ' ').trim().slice(0, 80) : '';
  }
  /* 16 Nov 2026 (P8-3f / P4-4d): the desk's extras - extra luggage pieces x
     STAFF.luggageFee and infants on the lap x STAFF.infantFare (the staff page draws
     the fields only while the owner's switches are on). They ride on a DESK sale
     only - the one api/smartbook.php gives a seller (Cash, or any counter / admin
     sale) - and are added after the fare and its discount, never commissioned.
     The same caps BookingService::deskExtras() charges with. */
  function deskExtras(pay) {
    var none = { lug: 0, inf: 0, amt: 0 };
    if (!STAFF || !(pay === 'cash' || MODE === 'counter' || MODE === 'admin')) return none;
    var lFee = Number(STAFF.luggageFee) || 0, iFare = Number(STAFF.infantFare) || 0;
    var lEl = document.getElementById('sbxLug'), iEl = document.getElementById('sbxInf');
    var seats = S.q && S.q.seats && S.q.seats.length ? S.q.seats.length : S.pax.length;
    var lug = lEl && lFee > 0 ? Math.max(0, Math.min(Number(STAFF.lugMax) || 5, parseInt(lEl.value, 10) || 0)) : 0;
    var inf = iEl ? Math.max(0, Math.min(Number(STAFF.infMax) || 4, Math.max(1, seats), parseInt(iEl.value, 10) || 0)) : 0;
    return { lug: lug, inf: inf, amt: lug * lFee + inf * iFare };
  }
  (function () {
    var lEl = document.getElementById('sbxLug'), iEl = document.getElementById('sbxInf');
    var note = document.querySelector('.sbx-xnote');
    if ((!lEl && !iEl) || !note) return;
    var base = note.textContent;
    var paint = function () {
      var x = deskExtras('cash');
      note.textContent = (x.amt > 0 ? '+ ' + money(x.amt) + ' · ' : '') + base;
    };
    if (lEl) lEl.addEventListener('input', paint);
    if (iEl) iEl.addEventListener('input', paint);
  })();
  function discNote(q) {
    var box = document.querySelector('.sbx-disc'), note = document.getElementById('sbxDiscNote');
    if (!box || !note || !q || !q.price) return;
    var cap = Number(q.price.deskCap) || 0, cut = !!q.price.deskCut, got = Number(q.price.desk) || 0;
    box.classList.toggle('cut', cut);
    if (!note.getAttribute('data-base')) note.setAttribute('data-base', note.textContent);
    note.textContent = got > 0 ? ('− ' + money(got) + (cut ? ' (max ' + cap + '%)' : '')) : (cut ? 'offer already bigger' : note.getAttribute('data-base'));
  }
  /* 15 Nov 2026: "Promo code छ?" — one link that opens one box + Apply. Built only when the
     boot says a typed coupon is running (customer card); otherwise nothing renders at all.
     Apply re-quotes with the code; the server answers q.promo {code, ok, amount, error} and
     the card's own total already carries the discount. */
  function buildPromo() {
    if (!S.promoOn || $('sbPromo')) return;
    var terms = root.querySelector('.sb-terms');
    if (!terms) return;
    var wrap = document.createElement('div');
    wrap.id = 'sbPromo';
    wrap.style.cssText = 'margin:6px 0 8px';
    wrap.innerHTML = '<button type="button" id="sbPromoAsk" aria-expanded="false" aria-controls="sbPromoBox" data-sbi="promoAsk"'
      + ' style="min-height:44px;padding:0 4px;border:0;background:none;color:var(--sb-text,inherit);font:inherit;font-size:14px;font-weight:700;text-decoration:underline;text-underline-offset:3px;cursor:pointer"></button>'
      + '<div id="sbPromoBox" style="display:none;flex-wrap:wrap;gap:8px;align-items:center">'
      + '<input id="sbPromoIn" type="text" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-label="Promo code" data-sbi-ph="promoPh"'
      + ' style="flex:1 1 150px;min-width:0;min-height:44px;padding:0 12px;border:1px solid var(--sb-line,#cfd8e6);border-radius:10px;background:var(--sb-panel,#fff);color:var(--sb-text,#16233C);font:inherit;font-size:16px;text-transform:uppercase;letter-spacing:.04em">'
      + '<button type="button" id="sbPromoGo" data-sbi="promoGo" style="min-height:44px;padding:0 16px;border:1px solid var(--sb-primary,#0054A8);border-radius:10px;background:var(--sb-primary,#0054A8);color:#fff;font:inherit;font-size:14px;font-weight:800;cursor:pointer"></button>'
      + '<button type="button" id="sbPromoRm" data-sbi="promoRm" style="display:none;min-height:44px;padding:0 12px;border:1px solid var(--sb-line,#cfd8e6);border-radius:10px;background:var(--sb-panel,#fff);color:var(--sb-text,#16233C);font:inherit;font-size:13px;font-weight:700;cursor:pointer"></button>'
      + '</div><p id="sbPromoMsg" aria-live="polite" style="margin:6px 0 0;font-size:13px;overflow-wrap:anywhere"></p>';
    terms.parentNode.insertBefore(wrap, terms);
    var ask = $('sbPromoAsk'), box = $('sbPromoBox'), inp = $('sbPromoIn');
    ask.addEventListener('click', function () {
      var open = box.style.display === 'none';
      box.style.display = open ? 'flex' : 'none';
      ask.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { try { inp.focus(); } catch (e) {} }
    });
    var go = function () {
      var c = String(inp.value || '').trim().toUpperCase().replace(/[^A-Z0-9_-]/g, '').slice(0, 40);
      inp.value = c;
      // an emptied box + Apply takes the code off (a wrong code must never stick to the card)
      if (!c) { if (S.promo) { S.promo = ''; renderPromo(); requote(0); } else { try { inp.focus(); } catch (e) {} } return; }
      S.promo = c; $('sbPromoMsg').textContent = tr('promoWait'); $('sbPromoMsg').style.color = 'var(--sb-muted,#5C6B85)';
      requote(0);
    };
    $('sbPromoGo').addEventListener('click', go);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    $('sbPromoRm').addEventListener('click', function () { S.promo = ''; inp.value = ''; renderPromo(); requote(0); });
  }
  function renderPromo() {
    var msg = $('sbPromoMsg');
    if (!msg) return;
    var p = S.promo && S.q && S.q.promo && S.q.promo.code === S.promo ? S.q.promo : null;
    $('sbPromoRm').style.display = S.promo ? '' : 'none';   // also after a refused code, so its red line can be cleared
    if (!S.promo) { msg.textContent = ''; return; }
    if (!p) { if (!qPend) msg.textContent = ''; return; }
    if (p.ok) {
      msg.style.color = 'var(--sb-ok,#11703F)';
      msg.textContent = '✅ ' + tr('promoOk', { c: p.code, a: money(p.amount), t: S.q.price ? money(S.q.price.total) : '—' });
    } else {
      msg.style.color = 'var(--sb-err,#C62828)';
      msg.textContent = tr('promoBad') + ': ' + (p.error || '—');
    }
  }
  /* 15 Nov 2026 (P7): "आपतकालीन सम्पर्क (ऐच्छिक)" — one collapsed row under the passengers
     (Quick and Full form alike), opening name + mobile (+977 / +91, the main mobile's rules)
     + relation. Never required, never blocks a booking: a number that is not a valid mobile,
     or is the passenger's own, is not sent — the note under it says so (the server ignores
     it too, SmartBook::bookNow → EmergencyContact::read). Built only when the boot says the
     database can keep it; staff faces never get it. */
  function nat10(s) {
    var d = digits(s);
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 13 && d.indexOf('977') === 0) d = d.slice(3);
    else if (d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    else if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return d;
  }
  function emParse() {
    var d = digits(S.em.phone), cc = S.em.cc === 'IN' ? 'IN' : 'NP';
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.length === 13 && d.indexOf('977') === 0) { d = d.slice(3); cc = 'NP'; }        // a typed prefix wins over the box
    else if (d.length === 12 && d.indexOf('91') === 0) { d = d.slice(2); cc = 'IN'; }
    else if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return { d: d, cc: cc };
  }
  /* 'empty' | 'nophone' | 'bad' | 'own' | 'ok' */
  function emState() {
    var nm = String(S.em.name || '').trim();
    if (!digits(S.em.phone)) return (nm || S.em.rel) ? 'nophone' : 'empty';
    var p = emParse();
    var ok = !/^(\d)\1+$/.test(p.d) && (p.cc === 'NP' ? /^9[678]\d{8}$/.test(p.d) : /^[6-9]\d{9}$/.test(p.d));
    if (!ok) return 'bad';
    var own = nat10(S.phone);
    return own && own.slice(-10) === p.d ? 'own' : 'ok';
  }
  function emBody() {
    if (!S.emOn || emState() !== 'ok') return null;
    var p = emParse();
    return { name: String(S.em.name || '').trim().replace(/\s+/g, ' ').slice(0, 80), phone: p.d, country: p.cc, relation: S.em.rel || '' };
  }
  function buildEm() {
    if (!S.emOn || STAFF || $('sbEm')) return;
    var after = $('sbPNames');
    if (!after || !after.parentNode) return;
    var fld = 'width:100%;min-width:0;box-sizing:border-box;height:44px;min-height:44px;';
    // its own labels: the card's .sb-l is visually hidden on a phone, and these boxes have no other caption
    var lab = ' style="display:block;margin:0 0 4px;font-size:12px;font-weight:700;color:var(--sb-muted,#637491)"';
    var wrap = document.createElement('div');
    wrap.id = 'sbEm';
    wrap.style.cssText = 'margin:0 0 8px;min-width:0';
    wrap.innerHTML = '<button type="button" id="sbEmAsk" aria-expanded="false" aria-controls="sbEmBox"'
      + ' style="width:100%;min-height:44px;display:flex;align-items:center;gap:8px;padding:6px 12px;border:1px dashed var(--sb-line,#cfd8e6);border-radius:var(--sb-ri,11px);background:var(--sb-chip,#f3f7fc);color:var(--sb-text,#16233C);font:inherit;font-size:14px;font-weight:700;text-align:left;cursor:pointer">'
      + '<span aria-hidden="true">🆘</span><span data-sbi="emT" style="flex:1 1 auto;min-width:0;overflow-wrap:anywhere"></span>'
      + '<span id="sbEmOk" aria-hidden="true" style="color:var(--sb-ok,#0b9e71);font-weight:800"></span><span id="sbEmCaret" aria-hidden="true">▾</span></button>'
      + '<div id="sbEmBox" style="display:none;gap:8px;padding:10px 2px 2px;min-width:0">'
      + '<div><label for="sbEmName" data-sbi="emName"' + lab + '></label><input id="sbEmName" type="text" maxlength="80" autocomplete="off" data-sbi-ph="emNamePh" style="' + fld + '"></div>'
      + '<div><label for="sbEmPhone" data-sbi="emPhone"' + lab + '></label><div class="sb-tel">'
      + '<select id="sbEmCc" aria-label="Country code" style="flex:0 0 84px;height:44px;min-height:44px;padding:0 4px 0 8px"><option value="NP">+977</option><option value="IN">+91</option></select>'
      + '<input id="sbEmPhone" type="tel" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="98XXXXXXXX" style="' + fld + '"></div></div>'
      + '<div><label for="sbEmRel" data-sbi="emRel"' + lab + '></label><select id="sbEmRel" style="' + fld + '"><option value="" data-sbi="emPick"></option>'
      + S.relations.map(function (r) { return '<option value="' + esc(r.v) + '">' + esc(r.ne + ' · ' + r.en) + '</option>'; }).join('') + '</select></div>'
      + '<p id="sbEmNote" aria-live="polite" style="margin:0;font-size:12.5px;font-weight:600;line-height:1.35;overflow-wrap:anywhere"></p>'
      + '<p data-sbi="emHint" style="margin:0;font-size:12px;line-height:1.35;color:var(--sb-muted,#637491);overflow-wrap:anywhere"></p>'
      + '</div>';
    after.parentNode.insertBefore(wrap, after.nextSibling);
    var ask = $('sbEmAsk'), box = $('sbEmBox');
    ask.addEventListener('click', function () {
      var open = box.style.display === 'none';
      box.style.display = open ? 'grid' : 'none';
      ask.setAttribute('aria-expanded', open ? 'true' : 'false');
      $('sbEmCaret').textContent = open ? '▴' : '▾';
      if (open) { try { $('sbEmName').focus(); } catch (e) {} }
    });
    var edit = function () {
      S.em.name = $('sbEmName').value; S.em.phone = $('sbEmPhone').value; S.em.cc = $('sbEmCc').value; S.em.rel = $('sbEmRel').value;
      S.idem = null;   // another emergency contact = another booking request
      renderEm();
    };
    ['sbEmName', 'sbEmPhone'].forEach(function (id) { $(id).addEventListener('input', edit); });
    ['sbEmCc', 'sbEmRel'].forEach(function (id) { $(id).addEventListener('change', edit); });
    ['sbEmName', 'sbEmPhone', 'sbEmRel'].forEach(function (id) { $(id).addEventListener('blur', function () { S.em.touched = true; renderEm(); }); });
    renderEm();
  }
  function renderEm() {
    var note = $('sbEmNote'); if (!note) return;
    var st = emState(), d = digits(S.em.phone), show = S.em.touched || d.length >= 10;
    var p = emParse(), msg = '';
    if (st === 'own' && show) msg = '⚠️ ' + tr('emOwn');
    else if (st === 'bad' && show) msg = '⚠️ ' + tr(p.cc === 'IN' ? 'emBadIn' : 'emBadNp');
    else if (st === 'nophone' && S.em.touched) msg = 'ℹ️ ' + tr('emNoPhone');
    note.textContent = msg;
    note.style.color = st === 'nophone' ? 'var(--sb-muted,#637491)' : 'var(--sb-err,#cd4154)';
    $('sbEmPhone').classList.toggle('sb-bad', (st === 'own' || st === 'bad') && show);
    $('sbEmOk').textContent = st === 'ok' ? '✓' : '';
  }
  function emReset() {
    S.em = { name: '', phone: '', cc: 'NP', rel: '', touched: false };
    if (!$('sbEm')) return;
    $('sbEmName').value = ''; $('sbEmPhone').value = ''; $('sbEmCc').value = 'NP'; $('sbEmRel').value = '';
    renderEm();
  }
  function doQuote() {
    var my = ++qSeq;
    $('sbSeatLine').innerHTML = '<span class="sb-skel sb-skel-w"></span>';
    syncButtons();
    api(quoteBody())
      .then(function (q) {
        if (my !== qSeq) return;
        var flash = S.q && q.seats && S.q.seats && q.seats.join() !== S.q.seats.join();
        S.q = q; qPend = false; quoteAt = Date.now();
        if (!S.date && q.date) { /* next departure chosen by the engine */ }
        renderDates(); renderQuote(flash);
        syncButtons(); discNote(q); quoted();
      })
      .catch(function (e) {
        if (my !== qSeq) return;
        S.q = null; qPend = false;
        $('sbSeatLine').textContent = e && e.message ? e.message : '—';
        renderQuote(); syncButtons(); discNote(S.q); quoted();
      });
  }
  /* 15 Oct 2026 (staff one-line order, auto-book): who is waiting for the next quote. */
  var qWait = [];
  function whenQuoted() { return new Promise(function (res) { qWait.push(res); }); }
  function quoted() { var w = qWait; qWait = []; w.forEach(function (f) { try { f(); } catch (x) {} }); }
  function seatSummary(q) {
    if (!q || !q.seats || !q.seats.length) return '';
    var floors = q.floors || [];
    var allLower = floors.every(function (f) { return f === 'lower'; });
    var allUpper = floors.every(function (f) { return f === 'upper'; });
    return q.seatLabels.join(', ') + (allLower ? ' · ' + tr('lower') : (allUpper ? ' · ' + tr('upper') : ''));
  }
  function renderQuote(flash) {
    var q = S.q;
    var line = $('sbSeatLine');
    clockLine(q);
    if (q) {
      if (q.ok) {
        var tag = q.auto ? tr('autoTag') : tr('yours');
        var why = q.reasons && q.reasons[0] ? L3(q.reasons[0]) : '';
        line.innerHTML = '<b class="' + (flash ? 'sb-flash' : '') + '">' + esc(seatSummary(q)) + '</b> · ' + esc(tag)
          + (why ? '<br><small>' + esc(why) + '</small>' : '');
      } else {
        line.innerHTML = '<span style="color:var(--sb-err)">' + esc(q.error || '—') + '</span>';
      }
      var sug = $('sbSuggest');
      if (q.suggest && S.type === 'sharing') {
        sug.hidden = false;
        sug.innerHTML = '<span>' + esc(L3(q.suggest)) + '</span><button type="button" data-sug="private">' + esc(tr('private') + ' ' + tr('cDouble')) + '</button>';
      } else { sug.hidden = true; sug.innerHTML = ''; }
      var alt = $('sbAlt');
      if (!q.ok && q.alternatives && q.alternatives.length) {
        alt.hidden = false;
        alt.innerHTML = q.alternatives.map(function (a, i) { return '<button type="button" data-alt="' + i + '">' + esc(L3(a)) + '</button>'; }).join('');
      } else { alt.hidden = true; alt.innerHTML = ''; }
    }
    // summary rail + phone bar
    var route = q ? (q.from + ' → ' + q.to) : '—';
    $('sbSRoute').textContent = route;
    $('sbSDate').textContent = q ? (q.dateLabel + bsFull(q.date, ' · ') + (q.depTime ? ' · ' + q.depTime : '')) : '—';
    $('sbSBoard').textContent = q ? ((q.pointType === 'drop' ? q.point : q.boardingName + (q.boardingTime ? ' ' + q.boardingTime : '')) + (q.reportTime && q.pointType !== 'drop' ? ' (' + tr('report') + ' ' + q.reportTime + ')' : '')) : '—';
    $('sbSPax').textContent = tr('paxN', { n: S.pax.length }) + ' · ' + tr(S.type);
    $('sbSSeats').textContent = q && q.ok ? seatSummary(q) : '—';
    /* 15 Nov 2026 (owner: "Journey Summary ma discount"): fare and discount rows, only while a discount applies. */
    (function () {
      var dl = $('sbSum'); if (!dl) return;
      var row = dl.querySelector('.sbx-disc-row');
      var d = q && q.ok && q.price ? Number(q.price.discount) || 0 : 0;
      if (d <= 0) { if (row) row.remove(); var fr = dl.querySelector('.sbx-fare-row'); if (fr) fr.remove(); return; }
      var pct = Number(q.price.base) > 0 ? Math.round(d / Number(q.price.base) * 1000) / 10 : 0;
      if (!row) {
        var f = document.createElement('div'); f.className = 'sbx-fare-row'; f.innerHTML = '<dt></dt><dd></dd>'; dl.appendChild(f);
        row = document.createElement('div'); row.className = 'sbx-disc-row'; row.innerHTML = '<dt></dt><dd></dd>'; dl.appendChild(row);
      }
      var fr2 = dl.querySelector('.sbx-fare-row');
      fr2.firstChild.textContent = tr('sFare'); fr2.lastChild.textContent = money(q.price.base);
      row.firstChild.textContent = '🏷️ ' + tr('sDisc'); row.lastChild.textContent = '− ' + money(d) + (pct > 0 ? ' (' + pct + '%)' : '');
    })();
    var tot = q && q.ok && q.price ? money(q.price.total) : '—';
    $('sbTotal').textContent = tot;
    $('sbNpr').textContent = q && q.ok && q.price ? '≈ ' + npr(q.price.npr) : '';
    (function () {   // 20 Nov 2026 (nf1)
      var host = $('sbTotal').parentNode, el = $('sbNf'), h = q && q.ok && q.price ? nfHtml(q.price.normal) : '';
      if (!h) { if (el) el.remove(); return; }
      if (!el) { el = document.createElement('div'); el.id = 'sbNf'; host.appendChild(el); }
      el.innerHTML = h;
    })();
    $('sbBarTotal').textContent = tot;
    try { $('sbBarTotal').setAttribute('data-nf', q && q.ok && q.price && q.price.normal ? money(q.price.normal.normal) : ''); } catch (e) {}
    $('sbBarSeats').textContent = q && q.ok ? (seatSummary(q) + (q.price ? ' · ≈ ' + npr(q.price.npr) : '')) : (q && q.error ? q.error : tr('loading'));
    syncPoint();
    renderQLine();
    renderPromo();
  }
  /* 19 Nov 2026 (NP-3): today's bus was closed by the sales clock and the quote moved to a
     later day (quote.clockNote {label, reason}) - say so loudly above the quick line, never
     a silent jump. Shown in Quick and Full mode; gone again when the note is. */
  function clockLine(q) {
    var cn = q && q.clockNote, el = $('sbClock');
    if (!cn || !(cn.label || cn.reason)) { if (el) { el.hidden = true; el.innerHTML = ''; } return; }
    if (!el) {
      var at = $('sbQLine'); if (!at || !at.parentNode) return;
      el = document.createElement('p'); el.id = 'sbClock'; el.className = 'sb-clock'; el.setAttribute('role', 'alert');
      el.style.cssText = 'margin:0 0 8px;padding:8px 10px;border-radius:9px;background:#FFF4E5;border:1px solid #F5A623;color:#7A4100;font-weight:700;font-size:13px;line-height:1.35';
      at.parentNode.insertBefore(el, at);
    }
    el.hidden = false;
    el.innerHTML = '⛔ ' + esc(cn.label || '') + (cn.reason ? '<br><small style="font-weight:500">' + esc(cn.reason) + '</small>' : '')
      + (q.dateLabel ? '<br><small style="font-weight:500">यो टिकट ' + esc(q.dateLabel) + ' को बसका लागि हो · This ticket is for ' + esc(q.dateLabel) + '</small>' : '');
  }
  /* 14 Nov 2026: the pickup box shows the pickup that will really be booked.
     A pickup that is past / inside the boarding cut-off for the day is still in the
     list (it is open again on a later date), but the quote boards the passenger at
     the first pickup still ahead on the same bus (quote.boardingStop). The box then
     follows the quote and the line under it says why (quote.pointNote). S.point stays
     the pickup that was asked, so choosing a later date brings it back. */
  var pointMoved = false;
  function syncPoint() {
    var q = S.q, sel = $('sbPoint'), why = $('sbPointWhy');
    if (!sel || !why) return;
    var moved = !!(q && q.direction === S.dir && q.pointType !== 'drop' && q.boardingStop && q.boardingStop !== S.point && hasPoint(S.dir, q.boardingStop));
    var show = moved ? q.boardingStop : S.point;
    if (show && sel.value !== show && hasPoint(S.dir, show)) sel.value = show;
    why.classList.toggle('sb-why-moved', moved);
    if (moved) { why.textContent = '⚠️ ' + (q.pointNote ? L3(q.pointNote) : (q.boardingName || '') + (q.boardingTime ? ' · ' + q.boardingTime : '')); pointMoved = true; }
    else if (pointMoved) { pointMoved = false; why.textContent = S.why.point ? L3(S.why.point) : ''; }
  }
  /* Quick form: everything that was not typed, on one line, each a default the
     engine chose (next catchable bus, this mobile's / the most-used pickup,
     AutoBerth). "✏️ Change" opens the full form. */
  function applyQuick() {
    root.classList.toggle('sb-quick', !!S.quick);
    var m = $('sbQMode'); if (!m) return;
    m.querySelectorAll('button').forEach(function (b) { var on = (b.getAttribute('data-m') === 'quick') === !!S.quick; b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false'); });
    renderQLine();
  }
  function setQuick(on) { S.quick = !!on; try { localStorage.removeItem('sb:quick'); } catch (e) {} applyQuick(); }
  function renderQLine() {
    root.classList.toggle('sb-q-needg', S.pax.some(function (p) { return !p.gender; }));
    var el = $('sbQLine'); if (!el) return;
    var q = S.q, parts = [];
    parts.push((S.dir === 'toIndia' ? '🇳🇵→🇮🇳 ' : '🇮🇳→🇳🇵 ') + (q ? q.from + ' → ' + q.to : ''));
    if (q && q.dateLabel) parts.push('📅 ' + q.dateLabel + bsShort(q.date, ' · ') + (q.depTime ? ' · ' + q.depTime : ''));
    if (q) parts.push('📍 ' + (q.pointType === 'drop' ? (q.point || '') : (q.boardingName || '')));
    parts.push('👤 ' + tr('paxN', { n: S.pax.length }) + ' ' + S.pax.map(function (p) { return p.gender === 'Female' ? '♀' : (p.gender === 'Male' ? '♂' : ''); }).join('') + (S.pax[0] && S.pax[0].ai ? ' 🤖' : ''));
    if (q && q.ok) parts.push('🛏 ' + seatSummary(q));
    el.innerHTML = parts.map(function (t) { return '<span>' + esc(t) + '</span>'; }).join('')
      + '<button type="button" class="sb-q-priv' + (S.type === 'private' ? ' on' : '') + '" data-q="private" aria-pressed="' + (S.type === 'private') + '">'
      + esc(S.type === 'private' ? '🔒 ' + tr('private') + ' ✓' : '🔒 ' + tr('private') + '?') + '</button>'
      + '<button type="button" data-q="full">' + esc(tr('qChange')) + '</button>';
  }

  /* ------------------------------------------------------------ events */
  function changed(field) { if (field) S.touched[field] = true; store.set(); showErrs(false); requote(); }
  root.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('.sb-dir-b'))) {
      var d = b.getAttribute('data-dir'); if (d === S.dir) return;
      S.dir = d; S.point = firstPoint(d); S.date = ''; S.manual = []; S.seatMode = 'auto'; S.why = {};
      fill(); applyWords(); changed(); return;
    }
    if ((b = e.target.closest('#sbDates .sb-chip'))) {
      var v = b.getAttribute('data-date');
      if (v === 'cal') { var inp = $('sbDateIn'); try { inp.showPicker(); } catch (x) { inp.focus(); inp.click(); } return; }
      S.date = v; S.manual = []; renderDates(); changed(); return;
    }
    if ((b = e.target.closest('#sbPax button'))) {
      var i = +b.getAttribute('data-i'), p = S.pax[i]; if (!p) return;
      if (b.getAttribute('data-act') === 'g') { p.gender = p.gender === 'Female' ? 'Male' : (p.gender === 'Male' ? 'Female' : 'Female'); p.ai = false; }
      else { var order = ['adult', 'child', 'senior', 'patient']; p.band = order[(order.indexOf(p.band) + 1) % order.length]; }
      S.manual = []; renderPax(); changed('pax'); return;
    }
    if ((b = e.target.closest('#sbMinus, #sbPlus'))) {
      if (b.id === 'sbPlus' && S.pax.length < S.maxPax) S.pax.push({ gender: S.pax.length ? S.pax[S.pax.length - 1].gender : '', band: 'adult' });
      if (b.id === 'sbMinus' && S.pax.length > 1) S.pax.pop();
      S.manual = []; renderPax(); changed(); return;
    }
    if ((b = e.target.closest('.sb-seg button'))) {
      var segId = b.parentNode.id, val = b.getAttribute('data-v');
      if (segId === 'sbType') { S.type = val; $('sbCabin').hidden = val !== 'private'; S.manual = []; }
      else if (segId === 'sbCabin') { S.cabin = val; S.manual = []; }
      else if (segId === 'sbBerth') { S.berth = val; }
      else if (segId === 'sbSeatMode') {
        if (val === 'manual') { openSheet(); return; }
        S.seatMode = 'auto'; S.manual = [];
      }
      seg(segId, val); changed(); return;
    }
    if ((b = e.target.closest('[data-sug]'))) { S.type = 'private'; S.cabin = 'double'; fill(); changed(); return; }
    if ((b = e.target.closest('[data-alt]'))) {
      var a = S.q && S.q.alternatives ? S.q.alternatives[+b.getAttribute('data-alt')] : null; if (!a) return;
      S.type = a.type || S.type; S.cabin = a.cabin || S.cabin; if (a.kind === 'nextDay') S.date = a.date;
      S.manual = []; S.seatMode = 'auto'; fill(); changed(); return;
    }
    if (e.target.closest('#sbNear')) { nearMe(); return; }
    if ((b = e.target.closest('#sbCash, #sbOnline'))) { book(b.id === 'sbCash' ? 'cash' : 'online', false, null, true); return; }
  });
  $('sbPoint').addEventListener('change', function () { S.point = this.value; S.why.point = null; $('sbPointWhy').textContent = ''; S.manual = []; changed(); });
  $('sbDateIn').addEventListener('change', function () { if (this.value) { S.date = this.value; S.manual = []; renderDates(); changed(); } });
  $('sbCc').addEventListener('change', function () { S.cc = this.value; changed('phone'); });
  $('sbPhone').addEventListener('input', function () { S.phone = this.value; S.idem = null; store.set(); showErrs(false); syncButtons(); whoSoon(); renderEm(); });
  /* 13 Nov 2026 (mg13, owner: "mobile halda naam, female / male aafai bhariyos"):
     ten digits typed -> ask the server who this is (staff, or the number's own
     verified owner; otherwise it answers "nobody"). Fills ONLY what is still
     empty or was filled by the machine: the name, the party's F / M and
     child / senior. A box somebody typed in is never overwritten. */
  var whoT = 0;
  function whoSoon() {
    clearTimeout(whoT);
    var p = digits(S.phone);
    if (p.length !== 10) return;
        whoT = setTimeout(function () {
      api({ action: 'who', phone: p }).then(function (r) {
        if (!r || !r.found || digits(S.phone) !== p) return;
        var did = false, nm = String(S.name || '').trim();
        if (r.name && (nm === '' || S.autoName === nm)) { S.name = r.name; S.autoName = r.name; $('sbName').value = r.name; did = true; }
        var plain = !S.touched.pax && S.pax.every(function (x) { return !x.gender || x.ai; });
        if (plain && r.people && r.people.length) {
          S.pax = r.people.slice(0, S.maxPax || 6).map(function (x) { return { gender: x.gender === 'Female' || x.gender === 'Male' ? x.gender : '', band: x.band || 'adult', ai: true }; });
          S.manual = []; renderPax(); did = true;
        }
        if (did) { store.set(); showErrs(false); syncButtons(); requote(); say('🤖 ' + (r.name || p) + ' — ' + r.trips + ' trip' + (r.trips === 1 ? '' : 's') + ' · auto-filled'); }
      }).catch(function () {});
    }, 350);
  }
  $('sbPhone').addEventListener('blur', function () { S.touched.phone = true; showErrs(false); if (S.q) requote(); });
  $('sbName').addEventListener('input', function () { S.name = this.value; S.idem = null; store.set(); showErrs(false); syncButtons(); guessSoon(); });
  /* 15 Oct 2026 (owner: "naam bata gender guess"): the lead passenger's F / M
     chip is pre-set from the typed name (NameGuess on the server: titles,
     Kumari / Bahadur, -ben / -bhai, what the office learned from earlier
     tickets). Marked 🤖; one tap changes it; a chip somebody tapped is never
     overwritten. */
  var guessT = 0, guessFor = '';
  function guessSoon() {
    clearTimeout(guessT);
    var p0 = S.pax[0]; if (!p0 || (p0.gender && !p0.ai)) return;
    var nm = String(S.name || '').trim(); if (nm.length < 3) return;
    guessT = setTimeout(function () {
      if (nm === guessFor) return; guessFor = nm;
      api({ action: 'guess', name: nm }).then(function (r) {
        var q = S.pax[0]; if (!q || (q.gender && !q.ai) || String(S.name || '').trim() !== nm) return;
        if (r && r.gender) { if (q.gender !== r.gender) { q.gender = r.gender; q.ai = true; S.manual = []; renderPax(); changed('pax'); } }
        else if (q.ai) { q.gender = ''; q.ai = false; renderPax(); changed('pax'); }
      }).catch(function () {});
    }, 450);
  }
  $('sbName').addEventListener('blur', function () { S.touched.name = true; showErrs(false); });

  /* 16 Nov 2026 (P4-1f): the same rules as the classic pickup list (06-results.js
     nearMeWire, D7-10): the 📍 button only while Settings -> Near-me pickup is on
     and a point of this direction has a map position; nothing beyond
     nearme_max_km; and while any point has NO map position (it cannot be
     measured - coordinates are never guessed) the nearest measured point is only
     SUGGESTED with a "Use this stop" tap - selecting it silently changed the
     pickup time for someone standing at an unmeasured stop. The position itself
     goes nowhere. */
  /* The site's own words (04-i18n.js); the staff desk page does not load that
     file, so it gets the English line given here. */
  var GW_EN = {
    nearMeNone: 'No pickup within {km} km of you — please choose from the list.',
    nearMeSuggest: 'Nearest pickup with a map location: {s} — about {km} km away (straight line).',
    nearMeUse: 'Use this stop', nearMeSet: '✓ {s} selected — change it if you board elsewhere.',
    nearMeUnchecked: 'Not checked (no map location yet): {list}',
    tSeatGone: 'Sorry — seat(s) {s} were just taken. Please pick again.'
  };
  function gw(k, v) {
    var s = '';
    try { if (typeof t === 'function' && typeof I18N !== 'undefined') s = t(k); } catch (x) { s = ''; }
    if (!s || s === k) s = GW_EN[k] || k;
    if (v) Object.keys(v).forEach(function (x) { s = s.split('{' + x + '}').join(v[x]); });
    return s;
  }
  function nearCfg() {
    var s = (window.SHG_BOOT && window.SHG_BOOT.settings) || {};
    return { on: !(s.nearme_on === false || s.nearme_on === 0 || s.nearme_on === '0'), maxKm: Math.max(1, parseInt(s.nearme_max_km, 10) || 150) };
  }
  function hasXY(p) { return p && p.lat != null && p.lng != null; }
  function nearSync() {
    var b = $('sbNear'); if (!b) return;
    b.hidden = !(nearCfg().on && navigator.geolocation && pointList().some(hasXY));
  }
  function nearMe() {
    var cfg = nearCfg();
    if (!cfg.on) { nearSync(); return; }
    if (!navigator.geolocation) { say(tr('geoNo')); return; }
    var why = $('sbPointWhy'), dir = S.dir;
    navigator.geolocation.getCurrentPosition(function (pos) {
      if (S.dir !== dir) return;   // the direction changed while the phone looked
      var best = null, unchecked = [];
      pointList().forEach(function (p) {
        if (!hasXY(p)) { unchecked.push(p.short || p.name); return; }
        var dLat = (p.lat - pos.coords.latitude) * Math.PI / 180, dLng = (p.lng - pos.coords.longitude) * Math.PI / 180;
        var a = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(pos.coords.latitude * Math.PI / 180) * Math.cos(p.lat * Math.PI / 180) * Math.pow(Math.sin(dLng / 2), 2);
        var km = 12742 * Math.asin(Math.min(1, Math.sqrt(a)));
        if (!best || km < best.km) best = { name: p.name, km: km, short: p.short };
      });
      if (!best) { say(tr('geoNo')); return; }
      if (best.km > cfg.maxKm) { var none = gw('nearMeNone', { km: cfg.maxKm }); why.textContent = '📍 ' + none; say(none); return; }
      var kmTxt = best.km < 10 ? best.km.toFixed(1) : String(Math.round(best.km));
      var use = function () {
        S.point = best.name; $('sbPoint').value = best.name; pointMoved = false; S.why.point = null; S.manual = [];
        why.textContent = '📍 ' + (best.short || best.name) + ' · ~' + kmTxt + ' km';
        changed();
      };
      if (!unchecked.length) { use(); return; }   // every point measured: select it, as before
      why.textContent = '📍 ' + gw('nearMeSuggest', { s: best.short || best.name, km: kmTxt }) + ' ';
      if (S.point === best.name) {
        why.appendChild(document.createTextNode(gw('nearMeSet', { s: best.short || best.name })));
      } else {
        var bt = document.createElement('button');
        bt.type = 'button'; bt.className = 'sb-near-use'; bt.textContent = gw('nearMeUse');
        bt.addEventListener('click', use);
        why.appendChild(bt);
      }
      var un = document.createElement('small');
      un.className = 'sb-near-un'; un.textContent = gw('nearMeUnchecked', { list: unchecked.join(', ') });
      why.appendChild(un);
    }, function () { say(tr('geoNo')); }, { timeout: 6000, maximumAge: 600000 });
  }

  root.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('#sbQMode button'))) { setQuick(b.getAttribute('data-m') === 'quick'); return; }
    if (e.target.closest('[data-q="private"]')) {   // Sharing ⇄ Private in one tap
      S.type = S.type === 'private' ? 'sharing' : 'private'; S.manual = []; S.seatMode = 'auto';
      seg('sbType', S.type); $('sbCabin').hidden = S.type !== 'private'; changed(); renderQLine(); return;
    }
    if (e.target.closest('[data-q="full"]')) { setQuick(false); try { $('sbDates').scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (x) {} return; }
    if (e.target.closest('#sbHelpBtn')) { openHelp(); return; }
    if (e.target.closest('#sbMic')) { listen(); return; }
    if (e.target.closest('.sb-help-x') || e.target.classList.contains('sb-help')) { closeHelp(); return; }
    if (e.target.closest('.sb-h-say')) { speakHelp(); return; }
  });

  /* 🎤 Voice: the browser hears "Ram Bahadur, 98123 45678, Surat, bholi" and the
     SAME server reader as the QuickBot line fills the card (TicketBot::parse). */
  var SRx = window.SpeechRecognition || window.webkitSpeechRecognition, recog = null;
  if (SRx && $('sbMic')) $('sbMic').hidden = false;
  function listen() {
    if (!SRx) { say(tr('micNo')); return; }
    if (recog) { try { recog.stop(); } catch (x) {} return; }
    try {
      recog = new SRx();
      recog.lang = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP' }[lang()] || 'hi-IN';
      recog.interimResults = false; recog.maxAlternatives = 1;
      recog.onstart = function () { $('sbMic').classList.add('on'); say(tr('micHear')); };
      recog.onend = function () { $('sbMic').classList.remove('on'); recog = null; };
      recog.onerror = function (ev) { if (ev && ev.error === 'not-allowed') say(tr('micNo')); };
      recog.onresult = function (ev) {
        var t = ev.results && ev.results[0] && ev.results[0][0] ? String(ev.results[0][0].transcript || '').trim() : '';
        if (!t) return;
        $('sbBotIn').value = t;
        fillFromText(t);
      };
      recog.start();
    } catch (x) { recog = null; say(tr('micNo')); }
  }

  /* ❓ How? - what to write / say, a short animated chat, a 🔊 voice that reads
     it out, and the WhatsApp one-message order with the template pre-typed. */
  function openHelp() {
    var box = root.querySelector('.sb-help');
    if (!box) { box = document.createElement('div'); box.className = 'sb-help'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); root.appendChild(box); }
    var wa = '';
    try { if (!STAFF && typeof quickTicketNumber === 'function') wa = String(quickTicketNumber() || ''); } catch (x) {}
    box.innerHTML = '<div class="sb-help-in"><h3><span>' + esc(tr('hT')) + '</span><button type="button" class="sb-help-x" aria-label="Close">✕</button></h3>'
      + '<ol><li>' + esc(tr('h1')) + '</li><li>' + esc(tr('h2')) + '</li><li>' + esc(tr('h3')) + '</li></ol>'
      + '<div class="sb-h-sec"><b>' + esc(tr('hVoiceT')) + '</b><code>' + esc(tr('hVoiceEx')) + '</code></div>'
      + '<div class="sb-h-sec"><b>' + esc(tr('hWaT')) + '</b><code>' + esc(tr('hWaEx1')) + '</code><code>' + esc(tr('hWaEx2')) + '</code>'
      + '<div class="sb-chat"><p class="me">' + esc(tr('hWaEx1')) + '</p><p class="bot">' + esc(tr('hChatBot')) + '</p><p class="bot bot2">' + esc(tr('hChatBot2')) + '</p></div></div>'
      + '<div class="sb-h-btns">' + ('speechSynthesis' in window ? '<button type="button" class="sb-h-say">' + esc(tr('hListen')) + '</button>' : '')
      + (wa.length >= 10 ? '<a class="sb-h-wa" target="_blank" rel="noopener" href="https://wa.me/' + esc(wa) + '?text=' + encodeURIComponent(tr('hWaTpl').replace(/\\n/g, '\n')) + '">' + esc(tr('hWaBtn')) + '</a>' : '')
      + '</div></div>';
    box.hidden = false; document.body.classList.add('shg-modal-open');
    try { box.querySelector('.sb-help-x').focus(); } catch (x) {}
  }
  function closeHelp() {
    var box = root.querySelector('.sb-help'); if (!box) return;
    box.hidden = true; document.body.classList.remove('shg-modal-open');
    try { window.speechSynthesis.cancel(); } catch (x) {}
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeHelp(); });
  function speakHelp() {
    try {
      var syn = window.speechSynthesis, u = new SpeechSynthesisUtterance(tr('hSpeak'));
      var want = lang() === 'en' ? 'en' : (lang() === 'hi' ? 'hi' : 'ne');
      var vs = syn.getVoices() || [], v = null;
      [want, 'hi', 'en'].some(function (w) { v = vs.filter(function (x) { return String(x.lang || '').toLowerCase().indexOf(w) === 0; })[0] || null; return !!v; });
      if (v) { u.voice = v; u.lang = v.lang; } else { u.lang = want === 'en' ? 'en-IN' : 'hi-IN'; }
      u.rate = 0.95;
      syn.cancel(); syn.speak(u);
    } catch (x) { say(tr('micNo')); }
  }

  /* ----------------------------------------------------- QuickBot line */
  $('sbBot').addEventListener('submit', function (e) {
    e.preventDefault();
    var text = $('sbBotIn').value.trim(); if (!text) return;
    fillFromText(text);
  });
  /* One reader for the QuickBot line AND Sahayak's "Card ma herau" (§10): the
     server reads the text (TicketBot::parse), the card shows the result, and
     only the passenger's Cash / Online tap books. */
  function fillFromText(text) {
    return api({ action: 'parse', text: text }).then(function (f) {
      if (f.direction && f.direction !== S.dir) { S.dir = f.direction; S.point = firstPoint(S.dir); }
      if (f.point) S.point = f.point;
      if (f.date) S.date = f.date;
      if (f.phone) { S.phone = f.phone; if (f.country) S.cc = f.country; }
      if (f.name) S.name = f.name;
      if (f.pax && f.pax.length) S.pax = f.pax.slice(0, S.maxPax).map(function (p) { return { gender: p.gender || '', band: p.band || 'adult' }; });
      if (f.guessed && f.guessed.indexOf('gender') >= 0 && S.pax[0]) S.pax[0].ai = true;
      S.manual = []; S.seatMode = 'auto';
      fill(); applyWords(); store.set(); requote(0);
      $('sbBotIn').value = '';
      var missing = f.missing || [];
      if (missing.indexOf('gender') >= 0) { S.touched.pax = true; }
      if (missing.indexOf('phone') >= 0) { $('sbPhone').focus(); } else if (missing.indexOf('name') >= 0) { $('sbName').focus(); }
      showErrs(false);
      /* 15 Oct 2026 (owner: "confirmation automatic, quick flow ma ticket"): on a
         staff desk with Auto-book on, a complete one-line order books at once
         (Cash = confirmed, ticket + WhatsApp) - nothing to tap. Anything missing
         is shown and the clerk finishes it with one tap as before. */
      if (STAFF && typeof window.SB_AUTOBOOK === 'function' && window.SB_AUTOBOOK()) {
        whenQuoted().then(function () { if (canBook()) book('cash', false); else showErrs(true); });
      }
    }).catch(function (er) { say(er.message); });
  }
  /* Sahayak (16-lazy.js) and old "Book" links: the card is the booking now. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-sb-fill]');
    if (a) {
      e.preventDefault();
      var tx = a.getAttribute('data-sb-fill') || '';
      try { var cb = document.querySelector('.ai-close, #aiClose, [data-ai-close]'); if (cb) cb.click(); } catch (x) {}
      if (location.hash && location.hash !== '#/' && location.hash.indexOf('#/?') !== 0) location.hash = '#/';
      setTimeout(function () { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); if (tx) fillFromText(tx); }, 120);
      return;
    }
    /* 16 Oct 2026 (owner: "3 ta booking system lai eutai ma"): every old booking
       door - the seat-map search card and the QuickBot banner - is this card. */
    var s = e.target.closest('[data-scroll="search-anchor"], [data-scroll="quick-ticket"], [data-bkmode]');
    if (s && document.documentElement.classList.contains('sb-on')) {
      if (s.hasAttribute('data-bkmode')) { e.preventDefault(); e.stopPropagation(); goCard(); return; }
      s.setAttribute('data-scroll', DESK ? 'sbDesk' : 'smartBook');
    }
  }, true);
  function goCard() {
    if (location.hash && location.hash !== '#/' && location.hash.indexOf('#/?') !== 0) location.hash = '#/';
    setTimeout(function () { var el = DESK ? $('sbDesk') : root; if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
  }
  /* The old Search -> Seats -> Checkout pages: a booking now starts on the card
     (a staff session goes to the SmartBook desk). Only while the card is
     running - if its engine is unreachable the classic pages stay the fallback. */
  function steerClassic() {
    if (!document.documentElement.classList.contains('sb-on')) return;
    if (!/^#\/(results|seats|checkout)(\/|\?|$)/.test(location.hash || '')) return;
    if (DESK) { location.href = DESK; return; }
    try { history.replaceState(null, '', '#/'); } catch (x) { location.hash = '#/'; }
    try { window.dispatchEvent(new HashChangeEvent('hashchange')); } catch (x) {}
    goCard();
  }
  window.addEventListener('hashchange', steerClassic);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if ($('sbPayOv') && !$('sbPayOv').hidden) closePay();
    else if ($('sbCv') && !$('sbCv').hidden) closeCash();
  });

  /* ---------------------------------------------------- choose seats */
  var pick = [];
  function openSheet() {
    if (!S.q || !S.q.scheduleId) return;
    var need = S.type === 'private' ? (S.cabin === 'double' ? Math.ceil(S.pax.length / 2) : S.pax.length) : S.pax.length;
    pick = (S.manual.length ? S.manual : (S.q.seats || [])).slice(0, need);
    api({ action: 'seats', scheduleId: S.q.scheduleId, type: S.type }).then(function (m) {
      var sh = $('sbSheet'); sh.hidden = false; document.body.classList.add('shg-modal-open');
      drawMap(m, need);
      seatPoll(need, m);
      $('sbSheetOk').onclick = function () {
        if (pick.length !== need) { say(tr('pickAll')); return; }
        S.manual = pick.slice(); S.seatMode = 'manual'; seg('sbSeatMode', 'manual'); closeSheet(); changed();
      };
    }).catch(function (er) { say(er.message); });
  }
  function closeSheet() { seatPollStop(); $('sbSheet').hidden = true; document.body.classList.remove('shg-modal-open'); }
  /* 16 Nov 2026 (P2-3b): while the seat sheet is open and the tab is in front, the
     coach is read again every 30 s (read-only - the sale still re-checks every berth
     on the server). A berth somebody else took in the meantime leaves this pick with
     a toast, like the classic map's SeatPoll. A hidden tab asks nothing; coming back
     asks at once (the visibilitychange below). */
  var SEAT_MS = 30000, seatT = 0, seatNeed = 0, seatBusy = false, seatSig = '';
  // What the map shows: redrawn only when this changes (a redraw drops a keyboard user's focus).
  function seatSigOf(m) { return ((m && m.available) || []).join() + '|' + ((m && m.female) || []).join(); }
  function seatPoll(need, m) { seatPollStop(); seatNeed = need; seatSig = seatSigOf(m); seatT = setInterval(seatTick, SEAT_MS); }
  function seatPollStop() { clearInterval(seatT); seatT = 0; }
  function seatTick() {
    var sh = $('sbSheet');
    if (!sh || sh.hidden || !S.q || !S.q.scheduleId) { seatPollStop(); return; }
    if (document.hidden || seatBusy) return;
    var sid = S.q.scheduleId, typ = S.type, need = seatNeed, sync = window.SHGSync;
    seatBusy = true;
    if (sync) sync.begin();
    api({ action: 'seats', scheduleId: sid, type: typ }).then(function (m) {
      if (sh.hidden || !S.q || S.q.scheduleId !== sid || S.type !== typ) return;
      var free = {};
      (m.available || []).forEach(function (s) { free[s] = 1; });
      var gone = pick.filter(function (s) { return !free[s]; });
      if (gone.length) {
        pick = pick.filter(function (s) { return free[s]; });
        say('⚠️ ' + gw('tSeatGone', { s: gone.map(function (s) { return (m.labels || {})[s] || s; }).join(', ') }));
      }
      var sig = seatSigOf(m);
      if (!gone.length && sig === seatSig) return;   // nothing new on the coach
      seatSig = sig;
      drawMap(m, need);
    }).catch(function () {}).then(function () { seatBusy = false; if (sync) sync.end(); });
  }
  /* Back on the tab: the open sheet asks at once; an idle card (nothing open on top,
     no sale or quote on the way, last quote over 30 s old) is priced again, so the
     auto-picked berths are not the ones somebody bought while the tab was away. */
  function overlayOpen() {
    return ['sbSheet', 'sbPayOv', 'sbCv', 'sbModal'].some(function (id) { var el = $(id); return !!(el && !el.hidden); });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden || DESK || root.hidden) return;
    var sh = $('sbSheet');
    if (sh && !sh.hidden) { if (seatT) seatTick(); return; }
    /* S.idem still set = a sale whose answer was lost (timeout / offline) keeps its key so
       the retry gets the SAME booking back - requote() would drop that key (a double sale). */
    if (!S.q || S.busy || S.idem || qPend || overlayOpen() || (Date.now() - quoteAt) < SEAT_MS) return;
    if (!root.getClientRects().length) return;   // another screen is showing (results, My trips …): nothing to re-price
    requote(0);
  });
  $('sbSheetX').addEventListener('click', closeSheet);
  $('sbSheet').addEventListener('click', function (e) { if (e.target === this) closeSheet(); });
  function drawMap(m, need) {
    var free = {}, fem = {};
    (m.available || []).forEach(function (s) { free[s] = 1; });
    (m.female || []).forEach(function (s) { fem[s] = 1; });
    $('sbLegend').innerHTML = '<span><i></i>' + esc(tr('free')) + '</span><span><i style="background:var(--sb-women-bg);border-color:var(--sb-women)"></i>' + esc(tr('women'))
      + '</span><span><i style="background:var(--sb-line)"></i>' + esc(tr('taken')) + '</span><span><i style="background:var(--sb-primary)"></i>' + esc(tr('mine')) + '</span>';
    var h = '';
    (m.decks || []).forEach(function (dk) {
      h += '<div class="sb-deck"><h4>' + esc(dk.key === 'U' ? tr('floor2') : tr('floor1')) + '</h4>';
      dk.rows.forEach(function (r, ri) {
        h += '<div class="sb-mrow"><span class="sb-rl">' + esc(String.fromCharCode(65 + ri)) + '</span>';
        var sf = m.singleSide === 'left';   // 6 Nov 2026: SINGLE | aisle | DOUBLE (owner's berth layout)
        (sf ? r.right : r.left).forEach(function (s) { h += cell(s); });
        h += '<span class="sb-aisle"></span>';
        (sf ? r.left : r.right).forEach(function (s) { h += cell(s); });
        h += '</div>';
      });
      h += '</div>';
    });
    function cell(s) {
      var cls = 'sb-berth' + (fem[s] ? ' w' : '') + (!free[s] ? ' x' : '') + (pick.indexOf(s) >= 0 ? ' sel' : '');
      var lb = (m.labels || {})[s] || s;
      // 14 Nov 2026 (d360): the state is read out too, not shown by colour alone (the marks are in polish.css §7.4)
      var al = lb + (pick.indexOf(s) >= 0 ? ' · ' + tr('mine') : (!free[s] ? ' · ' + tr('taken') : (fem[s] ? ' · ' + tr('women') : '')));
      return '<button type="button" class="' + cls + '" data-seat="' + esc(s) + '"' + (!free[s] ? ' disabled aria-disabled="true"' : '') + ' aria-pressed="' + (pick.indexOf(s) >= 0) + '" aria-label="' + esc(al) + '">' + esc(lb) + '</button>';
    }
    $('sbMap').innerHTML = h;
    $('sbPickN').textContent = tr('pickN', { a: pick.length, b: need });
    $('sbMap').onclick = function (e) {
      var b = e.target.closest('.sb-berth'); if (!b || b.disabled) return;
      var s = b.getAttribute('data-seat'), at = pick.indexOf(s);
      if (at >= 0) pick.splice(at, 1); else { if (pick.length >= need) pick.shift(); pick.push(s); }
      drawMap(m, need);
    };
  }

  /* -------------------------------------------------------------- book */
  function newKey() {
    var a = new Uint8Array(16);
    try { crypto.getRandomValues(a); } catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); }
    return 'sb' + Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }
  /* tap = a customer pressed the Cash button: Cash then asks once (summary + Confirm).
     16 Oct 2026 (owner: "network wala ma confirm namagne, one click mai ticket"):
     a staff desk never gets the sheet - one click books, like Ctrl+Enter and Auto-book. */
  function book(pay, wantPrint, verify, tap) {
    S.touched.book = S.touched.phone = S.touched.name = S.touched.pax = S.touched.agent = S.touched.alt = true;
    var e = showErrs(true);
    if (Object.keys(e).length) {
      var k = Object.keys(e)[0], el = k === 'phone' ? $('sbPhone') : (k === 'name' ? $('sbName') : (k === 'agent' ? $('sbAgent') : (k === 'alt' ? $('sbAltPhone') : $('sbPax'))));
      say(e[k]);
      try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (x) {}
      if (k !== 'pax') el.focus({ preventScroll: true }); else { var g = el.querySelector('.sb-g.u'); if (g) g.focus({ preventScroll: true }); }
      return;
    }
    if (S.busy) return;
    // 19 Nov 2026: "Part paid" needs the amount, or the note would say nothing
    if (STAFF && pay === 'cash' && $('sbxCashWhen') && $('sbxCashWhen').value === 'partial' && !(Number(($('sbxCashAmt') || {}).value) > 0)) {
      say('आंशिक भए रकम लेख्नुहोस् · Part paid: enter the amount (₹)');
      try { $('sbxCashAmt').focus(); } catch (x) {}
      return;
    }
    /* 14 Nov 2026: the card just changed (gender, date, pickup …) and its new quote is
       still on the way — S.q is the OLD berth / pickup. Wait for the new one, then go
       on: the seat map and the confirm sheet are never drawn from a stale quote.
       (A sheet's own Confirm is checked by sheetFresh() before it comes here.) */
    if (qPend && !verify && !okGo) {
      if (!bookWait) { bookWait = true; whenQuoted().then(function () { bookWait = false; book(pay, wantPrint, verify, tap); }); }
      return;
    }
    if (!S.q || !S.q.ok) { say(S.q && S.q.error ? S.q.error : tr('loading')); if (!S.q) requote(0); return; }
    /* 6 Nov 2026 (owner: "customer, agent le book garda automatic bhanda seat selection
       prefer"): the seat map opens first, AutoBerth's berths already marked — one tap on
       OK keeps them, or tap other berths. Then the same button books. Setting
       seat_pick_manual_first = 0 brings back the straight auto sale. */
    if (S.seatPickFirst && S.seatMode !== 'manual') { say('💺 ' + (document.documentElement.lang === 'en' ? 'Choose the seats first, then press again.' : 'पहिले सिट छान्नुहोस्, अनि फेरि थिच्नुहोस्।')); openSheet(); return; }
    // Cash for a customer = pay at boarding: the verification sheet first (it calls back with the details).
    if (pay === 'cash' && S.codVerify && !verify) { openCash(); return; }
    /* 5 Nov 2026 (master upgrade §1, supersedes the 16 Oct one-click desk): every
       cash sale — customer, agent, counter, admin, Ctrl+Enter, Auto-book — stops on
       the summary sheet; only its Confirm button (or Enter) issues the ticket. */
    if (pay === 'cash' && !verify && !okGo && (tap || STAFF)) { openOk(wantPrint); return; }
    printNext = !!wantPrint;   // only a sale that really starts may print
    S.busy = true; S.idem = S.idem || newKey();
    var btn = $(pay === 'cash' ? 'sbCash' : 'sbOnline'); btn.classList.add('sb-busy');
    var lbl = btn.querySelector('span'); var old = lbl.textContent; lbl.textContent = tr('booking');
    var body = quoteBody();
    body.action = 'book'; body.pay = pay; body.idem = S.idem; body.name = S.name.trim(); body.phone = digits(S.phone); body.country = S.cc;
    body.seats = S.q.seats; body.autoPicked = !!S.q.auto;   // the shown berths are the berths sold (re-checked on the server)
    body.expect = { total: S.q.price ? S.q.price.total : null, date: S.q.date };
    if (STAFF && $('sbAgent')) body.agentCode = S.agent;   // the desk's pick (the server validates; an agent login never sends one)
    if (verify) body.verify = verify;
    if (STAFF && ticketNote()) body.ticketNote = ticketNote();
    var dx = deskExtras(pay);   // P8-3f / P4-4d: luggage / lap infant (desk seller only; 0 = not sent)
    if (dx.lug > 0) body.luggagePieces = dx.lug;
    if (dx.inf > 0) body.infantCount = dx.inf;
    var emB = emBody(); if (emB) body.emergency = emB;   // 15 Nov 2026 (P7): only a valid number that is not the passenger's own
    if (STAFF) {   // 19 Nov 2026: the 2nd WhatsApp number (its own country) + the Cash sale's "when will they pay"
      var altD = altDigits();
      if (altD && !altErr()) { body.altPhone = altD; body.altCountry = $('sbAltCc') && $('sbAltCc').value === 'NP' ? 'NP' : 'IN'; }
      var cnB = pay === 'cash' ? cashNote() : null; if (cnB) body.cashNote = cnB;
    }
    store.set();
    api(body).then(function (r) {
      S.busy = false; btn.classList.remove('sb-busy'); lbl.textContent = old;
      closeCash();
      adopt(r);
      S.idem = null;
      if (STAFF && r.agent) say('🤝 ' + r.agent.code + ' · ' + r.agent.name + (r.commission ? ' · 💰 ' + money(r.commission) : ''));
      else if (STAFF && r.commission) say('💰 Commission ' + money(r.commission));
      if (r.status === 'confirmed') {
        tkMagic(r, body.name, body.phone, body.country);
        showTicket(r, !STAFF);
        if (STAFF && (printNext || MODE === 'counter' && staffPrint())) window.open('/admin/ticket-80mm.php?pnr=' + encodeURIComponent(r.pnr), '_blank', 'noopener,width=420,height=700');
        printNext = false;
        if (STAFF && typeof window.SB_AFTER === 'function') { try { window.SB_AFTER(r); } catch (x) {} }
      }
      else if (r.pay) { openPay(r); }
      else if (r.payPage && STAFF) { showPayLink(r); }
      else if (r.payPage) { say(tr('heldQr')); setTimeout(function () { location.href = r.payPage; }, 400); }
    }).catch(function (er) {
      S.busy = false; btn.classList.remove('sb-busy'); lbl.textContent = old;
      printNext = false;
      if (er && er.fields && er.fields.code === 'verify') { openCash(er.fields.field, er.message); }
      else if (er && er.fields && er.fields.code === 'changed' && er.fields.quote) { closeCash(); S.q = er.fields.quote; renderDates(); renderQuote(true); say(tr('changed')); S.idem = null; }
      else if (er && er.fields && er.fields.field === 'agent' && $('sbAgent')) {   // the server refused the agent pick
        var ae = $('sbAgentE'); ae.textContent = er.message || tr('needAgent'); ae.hidden = false; $('sbAgent').classList.add('sb-bad');
        say(er.message); try { $('sbAgent').focus(); } catch (x) {}
      }
      else { say(er && er.message ? er.message : 'Error'); cashErr(er && er.message ? er.message : 'Error'); }
      // The server answered (a refusal): the next tap is a new request with a new key. A lost
      // answer (no status: timeout / offline) keeps the key, so the retry gets the same booking back.
      if (er && er.status) S.idem = null;
      syncButtons();
    });
  }
  var printNext = false, bookWait = false;
  function staffPrint() { try { return localStorage.getItem('sb:print80') !== '0'; } catch (e) { return true; } }

  /* ------------------------------------------------ Cash verification sheet
     16 Oct 2026 (owner): Cash = pay at boarding, so a customer gives the full
     name, address and one ID the crew can match at the door. The server
     checks the same rules (SmartBook::codVerify) and names the field it
     wants (422 code 'verify'); the sheet opens on that field. */
  function cashSheet() {
    var box = $('sbCv'); if (box) return box;
    box = document.createElement('div'); box.id = 'sbCv'; box.className = 'sb-cv'; box.hidden = true;
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-labelledby', 'sbCvT');
    root.appendChild(box);
    box.addEventListener('click', function (e) {
      if (e.target === box || e.target.closest('[data-cv="x"]')) { closeCash(); return; }
      if (e.target.closest('[data-cv="online"]')) { closeCash(); book('online'); return; }
      if (e.target.closest('[data-cv="edit"]')) {
        var fl = box.querySelector('.sb-cv-fields'), of = box.querySelector('.sb-cv-file');
        if (fl) fl.hidden = false; if (of) of.hidden = true;
        try { $('sbCvAddr').focus(); } catch (x) {}
      }
    });
    box.addEventListener('submit', function (e) { e.preventDefault(); if (box.getAttribute('data-kind') === 'ok') submitOk(); else submitCash(); });
    return box;
  }
  /* 14 Nov 2026: Confirm sells what the sheet SHOWS. sumHtml() notes the quote it drew
     (berths, pickup, drop, date, total); a sheet's Confirm goes through sheetFresh():
     it waits for a quote still on the way, and if the quote is no longer the one on
     the sheet (a late name → gender guess, a remembered party, a berth just taken)
     the sheet is drawn again with the new summary and a line saying what changed —
     nothing is sold until the passenger confirms what is on the screen. */
  var cvSig = '', cvSeat = '';
  function quoteSig(q) {
    return q && q.ok ? [(q.seats || []).join(','), q.boarding || '', q.drop || '', q.date || '', q.price ? q.price.total : ''].join('|') : '';
  }
  function sheetFresh(redraw, go) {
    if (qPend) { whenQuoted().then(function () { sheetFresh(redraw, go); }); return; }
    var box = $('sbCv'); if (!box || box.hidden) return;   // closed while it waited
    if (!S.q || !S.q.ok) { closeCash(); say(S.q && S.q.error ? S.q.error : tr('loading')); if (!S.q) requote(0); return; }
    var was = cvSeat, now = seatSummary(S.q);
    var moved = '⚠️ ' + tr('seatMoved', { a: was, b: now });
    /* the hand pick was dropped under the open sheet: the seat map comes first again
       (seat_pick_manual_first), with the new berth marked — then Cash shows this sheet */
    if (S.seatPickFirst && S.seatMode !== 'manual') { closeCash(); if (was !== now) say(moved); openSheet(); return; }
    if (quoteSig(S.q) === cvSig) { go(); return; }
    redraw(was !== now ? moved + ' ' + tr('cvAgain') : '⚠️ ' + tr('cvMoved'));
  }
  /* The booking at a glance - the one summary both Cash sheets lead with. */
  function sumHtml() {
    var q = S.q || {}, drop = q.pointType === 'drop';
    cvSig = quoteSig(q); cvSeat = seatSummary(q);
    var rows = [
      [tr('sRoute'), (q.from || '') + ' → ' + (q.to || '')],
      [tr('sDate'), (q.dateLabel || '') + bsFull(q.date, ' · ') + (q.depTime ? ' · ' + q.depTime : '')],
      [tr(drop ? 'drop' : 'sBoard'), drop ? (q.point || '') : (q.boardingName || '') + (q.boardingTime ? ' · ' + q.boardingTime : '')],
      [tr('sName'), S.name.trim() + ' · ' + tr('paxN', { n: S.pax.length })],
      [tr('sMobile'), (S.cc === 'NP' ? '+977 ' : '+91 ') + digits(S.phone)],
      [tr('sSeats'), seatSummary(q)]
    ];
    if (q.busNumber) rows.push([tr('sBus'), q.busNumber]);
    if (STAFF && q.price && !(Number(q.price.discount) > 0)) rows.push(['🏷️ ' + tr('sDisc'), tr('noDisc')]);   // 15 Nov 2026: the desk sees "no discount" before it confirms
    if (q.price && Number(q.price.discount) > 0) {
      rows.push([tr('sFare'), money(q.price.base)]);
      var dPct = Number(q.price.base) > 0 ? Math.round(Number(q.price.discount) / Number(q.price.base) * 1000) / 10 : 0;   // 15 Nov 2026: and how much that is
      rows.push([tr('sDisc'), '− ' + money(q.price.discount) + (dPct > 0 ? ' (' + dPct + '%)' : '') + (q.price.offer ? ' · ' + q.price.offer : '')
        + (Number(q.price.returnDiscount) > 0 ? ' · फिर्ती छुट / Return ' + money(q.price.returnDiscount) : '')]);   // P4-5d
    }
    /* P8-3f / P4-4d: the desk's extras on this Cash sale, added after the fare */
    var dxS = deskExtras('cash');
    if (dxS.lug > 0) rows.push(['🧳 अतिरिक्त सामान · Luggage', dxS.lug + ' × ' + money(STAFF.luggageFee) + ' = ' + money(dxS.lug * STAFF.luggageFee)]);
    if (dxS.inf > 0) rows.push(['👶 शिशु · Infant (lap)', dxS.inf + (Number(STAFF.infantFare) > 0 ? ' × ' + money(STAFF.infantFare) + ' = ' + money(dxS.inf * STAFF.infantFare) : ' · free')]);
    rows.push([tr('sPay'), tr('sPayCash')]);
    var emS = emBody();   // 15 Nov 2026 (P7): the emergency contact that goes with this ticket
    if (emS) {
      var emR = S.relations.filter(function (r) { return r.v === emS.relation; })[0];
      rows.push(['🆘 ' + tr('emS'), [emS.name, emR ? emR.ne : '', (emS.country === 'IN' ? '+91 ' : '+977 ') + emS.phone].filter(Boolean).join(' · ')]);
    }
    if (STAFF && ticketNote()) rows.push(['🧾 ' + tr('tNote'), ticketNote()]);
    if (STAFF && (S.agent || STAFF.ownCode)) {
      var credA = S.agent && S.agent !== 'OTHER' ? agentByCode(S.agent) : null;
      rows.push([tr('credited'), (S.agent === 'OTHER' ? (STAFF.company || '') : (S.agent || STAFF.ownCode)) + (noComm(credA) ? ' · जना मात्र · passenger count' : '')]);
    }
    if (STAFF && altDigits() && !altErr()) rows.push(['📱 दोस्रो नम्बर · 2nd number', (($('sbAltCc') || {}).value === 'NP' ? '+977 ' : '+91 ') + altDigits()]);
    if (STAFF) { var cnS = cashNoteText(cashNote()); if (cnS) rows.push(['💵 पैसा कहिले? · When will they pay', cnS]); }
    if (q.clockNote && q.clockNote.label) rows.push(['⛔ ' + q.clockNote.label, q.dateLabel || q.date || '']);
    return '<div class="sb-ok-sum"><div class="sb-ok-tot"><small>' + esc(tr('total')) + '</small><b>' + esc(q.price ? money(Number(q.price.total) + dxS.amt) : '—') + '</b>'
      + (q.price ? '<span>≈ ' + esc(npr(dxS.amt > 0 && Number(q.price.total) > 0 ? Math.round(Number(q.price.npr) * (Number(q.price.total) + dxS.amt) / Number(q.price.total)) : q.price.npr)) + '</span>' : '') + '</div><dl>'
      + rows.filter(function (r) { return String(r[1] || '').replace(/[\s·→+]/g, '') !== ''; })
          .map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('')
      + '</dl></div>';
  }
  /* Cash with no boarding check to fill (every desk, or a customer site with the
     check switched off): the summary and ONE Confirm button - Enter confirms. */
  var okPrint = false, okGo = false;
  function openOk(wantPrint) {
    var box = cashSheet(), q = S.q || {};
    var tot = q.price ? money(Number(q.price.total) + deskExtras('cash').amt) : '';   // P8-3f / P4-4d: + the desk's extras
    okPrint = !!wantPrint;
    box.setAttribute('data-kind', 'ok');
    box.innerHTML = '<form class="sb-cv-in sb-ok" novalidate>'
      + '<div class="sb-cv-hd"><span class="sb-cv-ic sb-ok-ic" aria-hidden="true">💵</span><div><h3 id="sbCvT">' + esc(tr('okT') + ' · ' + tr('okCash')) + '</h3>'
      + '<small>' + esc(tr(!STAFF ? 'okSubCus' : (MODE === 'agent' ? 'okSubAgent' : 'okSubDesk'))) + '</small></div>'
      + '<button type="button" class="sb-x" data-cv="x" aria-label="Close">✕</button></div>'
      + sumHtml()
      + (!STAFF ? '<p class="sb-ok-pay">💵 ' + esc(tr('okPay', { amt: tot })) + '</p>' : '')
      + '<p class="sb-cv-e" id="sbCvE" role="alert" hidden></p>'
      + '<button type="submit" class="sb-cv-go">' + esc(tr('okGo')) + (tot ? ' · ' + esc(tot) : '') + '</button>'
      + '<div class="sb-ok-row"><button type="button" class="sb-cv-alt" data-cv="x">' + esc(tr('okBack')) + '</button>' + (STAFF ? '<small>' + esc(tr('okEnter')) + '</small>' : '') + '</div>'
      + '</form>';
    box.hidden = false; document.body.classList.add('shg-modal-open');
    setTimeout(function () { var f = box.querySelector('.sb-cv-go'); if (f) try { f.focus(); } catch (x) {} }, 60);
  }
  function submitOk() {
    var go = $('sbCv').querySelector('.sb-cv-go'); if (!go || go.disabled) return;
    go.disabled = true; go.classList.add('sb-busy');
    sheetFresh(function (msg) { openOk(okPrint); cashErr(msg); }, function () {
      okGo = true;
      try { book('cash', okPrint); } finally { okGo = false; }
    });
    setTimeout(function () { go.disabled = false; go.classList.remove('sb-busy'); }, 1500);
  }
  function cvComplete() {
    var v = S.verify;
    return S.name.trim().split(/\s+/).length >= 2 && String(v.address || '').trim().length >= 5 && !!v.idType
      && String(v.idNum || '').replace(/[^A-Za-z0-9]/g, '').length >= 4;
  }
  function openCash(field, msg) {
    var box = cashSheet(), q = S.q || {}, v = S.verify;
    var types = (S.idTypes && S.idTypes.length ? S.idTypes : ['Aadhaar Card', 'Nepali Citizenship Card', 'Voter ID', 'Driving Licence', 'Passport']);
    if (!v.idType) v.idType = S.cc === 'NP' ? 'Nepali Citizenship Card' : (S.dir === 'toNepal' ? 'Aadhaar Card' : '');
    var tot = q.price ? money(q.price.total) : '';
    /* details already on file (a verified profile, or typed earlier this visit): one line + Edit */
    var onFile = !field && cvComplete();
    var idTail = String(v.idNum || '').replace(/[^A-Za-z0-9]/g, '').slice(-4);
    box.setAttribute('data-kind', 'cv');
    box.innerHTML = '<form class="sb-cv-in" novalidate>'
      + '<div class="sb-cv-hd"><span class="sb-cv-ic sb-ok-ic" aria-hidden="true">💵</span><div><h3 id="sbCvT">' + esc(tr('okT') + ' · ' + tr('okCash')) + '</h3><small>' + esc(tr('okSubCus')) + '</small></div>'
      + '<button type="button" class="sb-x" data-cv="x" aria-label="Close">✕</button></div>'
      + sumHtml()
      + '<div class="sb-cv-file"' + (onFile ? '' : ' hidden') + '><span aria-hidden="true">🪪</span><div><b>' + esc(tr('cvOnFile')) + '</b><small>' + esc(v.idType + ' ••' + idTail + ' · ' + v.address) + '</small></div>'
      + '<button type="button" data-cv="edit">' + esc(tr('cvEdit')) + '</button></div>'
      + '<div class="sb-cv-fields"' + (onFile ? ' hidden' : '') + '><p class="sb-cv-need">🛡️ ' + esc(tr('cvNeed')) + '</p>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cvName')) + ' <i>*</i></span><input id="sbCvName" autocomplete="name" maxlength="120" value="' + esc(S.name) + '"></label>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cvAddr')) + ' <i>*</i></span><input id="sbCvAddr" autocomplete="address-level2" maxlength="191" placeholder="' + esc(tr('cvAddrPh')) + '" value="' + esc(v.address) + '"></label>'
      + '<div class="sb-cv-2"><label class="sb-cv-f"><span>' + esc(tr('cvIdT')) + ' <i>*</i></span><select id="sbCvIdT"><option value="">' + esc(tr('cvPick')) + '</option>'
      + types.map(function (t) { return '<option' + (t === v.idType ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select></label>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cvIdN')) + ' <i>*</i></span><input id="sbCvIdN" maxlength="40" autocapitalize="characters" placeholder="' + esc(tr('cvIdPh')) + '" value="' + esc(v.idNum) + '"></label></div>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cvAlt')) + ' <small>(' + esc(tr('cvOpt')) + ')</small></span><span class="sb-cv-ph"><select id="sbCvAltCc" aria-label="Country"><option value="NP"' + (v.altCc !== 'IN' ? ' selected' : '') + '>+977</option><option value="IN"' + (v.altCc === 'IN' ? ' selected' : '') + '>+91</option></select>'
      + '<input id="sbCvAlt" type="tel" inputmode="numeric" maxlength="14" value="' + esc(v.altPhone) + '"></span></label>'
      + '<p class="sb-cv-note">🪪 ' + esc(tr('cvNote')) + '</p></div>'
      + '<p class="sb-cv-e" id="sbCvE" role="alert"' + (msg ? '' : ' hidden') + '>' + esc(msg || '') + '</p>'
      + '<button type="submit" class="sb-cv-go">' + esc(tr('okGo')) + (tot ? ' · ' + esc(tot) : '') + '</button>'
      + '<button type="button" class="sb-cv-alt" data-cv="online">' + esc(tr('cvOnline')) + '</button>'
      + '</form>';
    box.hidden = false; document.body.classList.add('shg-modal-open');
    var map = { name: 'sbCvName', address: 'sbCvAddr', idType: 'sbCvIdT', idNum: 'sbCvIdN', altPhone: 'sbCvAlt' };
    var first = field && map[field] ? map[field] : (S.name.trim().split(/\s+/).length < 2 ? 'sbCvName' : (!v.address ? 'sbCvAddr' : (!v.idType ? 'sbCvIdT' : (!v.idNum ? 'sbCvIdN' : ''))));
    if (field && map[field]) $(map[field]).classList.add('sb-bad');
    setTimeout(function () { var f = first ? $(first) : box.querySelector('.sb-cv-go'); if (f) try { f.focus(); } catch (x) {} }, 60);
  }
  function closeCash() { var box = $('sbCv'); if (!box || box.hidden) return; box.hidden = true; if (!($('sbPayOv') && !$('sbPayOv').hidden)) document.body.classList.remove('shg-modal-open'); }
  function cashErr(msg) { var e = $('sbCvE'); if (!e || $('sbCv').hidden) return; e.textContent = msg; e.hidden = false; }
  function submitCash() {
    var nm = $('sbCvName').value.trim().replace(/\s+/g, ' ');
    var v = S.verify;
    v.address = $('sbCvAddr').value.trim(); v.idType = $('sbCvIdT').value; v.idNum = $('sbCvIdN').value.trim();
    v.altPhone = digits($('sbCvAlt').value); v.altCc = $('sbCvAltCc').value;
    $('sbCv').querySelectorAll('.sb-bad').forEach(function (x) { x.classList.remove('sb-bad'); });
    var bad = nm.split(' ').length < 2 ? ['sbCvName', 'cvName'] : (v.address.length < 5 ? ['sbCvAddr', 'cvAddr'] : (!v.idType ? ['sbCvIdT', 'cvIdT'] : (v.idNum.replace(/[^A-Za-z0-9]/g, '').length < 4 ? ['sbCvIdN', 'cvIdN'] : null)));
    if (!bad && v.altPhone && v.altPhone.slice(-10).length !== 10) bad = ['sbCvAlt', 'cvAlt'];
    if (bad) {
      var fl = $('sbCv').querySelector('.sb-cv-fields'), of = $('sbCv').querySelector('.sb-cv-file');
      if (fl) fl.hidden = false; if (of) of.hidden = true;
      $(bad[0]).classList.add('sb-bad'); cashErr('⚠️ ' + tr(bad[1])); try { $(bad[0]).focus(); } catch (x) {} return;
    }
    if (nm !== S.name) { S.name = nm; $('sbName').value = nm; store.set(); }
    $('sbCvE').hidden = true;
    var go = $('sbCv').querySelector('.sb-cv-go'); go.disabled = true; go.classList.add('sb-busy');
    sheetFresh(function (msg) { openCash(null, msg); }, function () {
      book('cash', false, { address: v.address, idType: v.idType, idNum: v.idNum, altPhone: v.altPhone, altCountry: v.altCc });
    });
    setTimeout(function () { if (go) { go.disabled = false; go.classList.remove('sb-busy'); } }, 1500);
  }

  /* ---------------------------------------------------- Online QR popup
     16 Oct 2026 (owner: "online ma click garda sidhai bank ko QR popup open
     hos like Razorpay, 60 second timer"). The booking is made (berths held
     smartbook_online_hold_min on the server); the popup shows the bank QR
     with the amount and PNR filled in, a 60-second countdown (then "Show a
     new QR"), the UPI app button on a phone, eSewa for Nepal, any gateway,
     and the UTR / screenshot form. It watches the booking: the office's
     verification (or a bank callback into the verify queue) opens the
     ticket here. Nothing in this popup confirms a booking. */
  var PAY = null;
  function payTicket(r) {
    var k = '&k=' + encodeURIComponent(r.payKey || ''), p = encodeURIComponent(r.pnr);
    return { view: '/download-ticket.php?pnr=' + p + k + '&img=1&view=1', png: '/download-ticket.php?pnr=' + p + k + '&img=1',
      pdf: '/download-ticket.php?pnr=' + p + k, print: '/download-ticket.php?pnr=' + p + k + '&print=1', home: '/#/?ticket=' + p };
  }
  function openPay(r) {
    closePay(true);
    PAY = { r: r, d: r.pay || {}, tab: (r.pay && r.pay.upi) ? 'upi' : ((r.pay && r.pay.esewa) ? 'esewa' : 'gw'), end: 0, tick: 0, poll: 0, state: 'pay' };
    var box = $('sbPayOv');
    if (!box) {
      box = document.createElement('div'); box.id = 'sbPayOv'; box.className = 'sb-pyo';
      box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-labelledby', 'sbPyT');
      root.appendChild(box);
      box.addEventListener('click', payClick);
      box.addEventListener('submit', function (e) { e.preventDefault(); sendProof(); });
    }
    box.hidden = false; document.body.classList.add('shg-modal-open');
    drawPay(); startQrTimer(); pollPay(2500);
  }
  function closePay(silent) {
    if (!PAY) return;
    clearInterval(PAY.tick); clearTimeout(PAY.poll);
    var box = $('sbPayOv'); if (box) box.hidden = true;
    if (!silent) document.body.classList.remove('shg-modal-open');
    PAY = null;
  }
  function hhmm(sql) { var m = /(\d{2}):(\d{2})/.exec(String(sql || '').split(' ')[1] || ''); return m ? m[1] + ':' + m[2] : ''; }
  function drawPay() {
    var P = PAY; if (!P) return;
    var r = P.r, d = P.d, box = $('sbPayOv');
    var head = '<div class="sb-py-hd"><span class="sb-py-logo" aria-hidden="true">SH</span><div class="sb-py-who"><b id="sbPyT">S Hari Global</b><small>' + esc(r.pnr) + ' · ' + esc(r.route || '') + '</small></div>'
      + '<button type="button" class="sb-py-x" data-py="close" aria-label="Close">✕</button></div>'
      + '<div class="sb-py-amt"><small>' + esc(tr('pyAmt')) + '</small><b>' + esc(d.dueLabel || r.totalLabel || '') + '</b><span>≈ ' + esc(npr(d.npr || r.npr)) + '</span></div>';
    var body = '';
    if (P.state === 'verifying') {
      body = '<div class="sb-py-st"><span class="sb-py-spin" aria-hidden="true"></span><b>' + esc(tr('pyVer')) + '</b><p>' + esc(tr('pyVerSub')) + '</p>'
        + '<a class="sb-py-ghost" href="/#/my">My Bookings</a></div>';
    } else if (P.state === 'over') {
      body = '<div class="sb-py-st"><span class="sb-py-big" aria-hidden="true">⌛</span><p>' + esc(tr('pyGone')) + '</p><button type="button" class="sb-py-main" data-py="close">' + esc(tr('newB')) + '</button></div>';
    } else {
      var tabs = [];
      if (d.upi) tabs.push(['upi', '🇮🇳 UPI']);
      if (d.esewa) tabs.push(['esewa', '🇳🇵 eSewa']);
      if (d.gateways && d.gateways.length) tabs.push(['gw', '💳 ' + tr('pyBank')]);
      body += tabs.length > 1 ? '<div class="sb-py-tabs" role="tablist">' + tabs.map(function (t) { return '<button type="button" role="tab" data-pytab="' + t[0] + '" class="' + (P.tab === t[0] ? 'on' : '') + '" aria-selected="' + (P.tab === t[0]) + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>' : '';
      if (P.tab === 'upi' && d.upi) {
        body += '<div class="sb-py-timer" id="sbPyTimer" role="timer" aria-live="off"><svg viewBox="0 0 36 36" aria-hidden="true"><circle class="bg" cx="18" cy="18" r="15"/><circle class="fg" cx="18" cy="18" r="15"/></svg><span><small>' + esc(tr('pyLeft')) + '</small><b id="sbPyClock">' + Math.floor(S.qrSeconds / 60) + ':' + ('0' + (S.qrSeconds % 60)).slice(-2) + '</b></span></div>'
          + '<div class="sb-py-qrw" id="sbPyQrw"><img class="sb-py-qr" src="' + esc(d.upi.qr) + '" alt="UPI QR ' + esc(d.dueLabel || '') + '" width="240" height="240">'
          + '<div class="sb-py-exp" hidden><b>⌛ ' + esc(tr('pyOver')) + '</b><button type="button" data-py="newqr">' + esc(tr('pyNew')) + '</button></div></div>'
          + '<p class="sb-py-note">' + esc(tr('pyScan')) + '</p>'
          + '<div class="sb-py-apps" aria-hidden="true"><span>GPay</span><span>PhonePe</span><span>Paytm</span><span>BHIM</span></div>'
          + (d.upi.link ? '<a class="sb-py-main sb-py-upi" href="' + esc(d.upi.link) + '">' + esc(tr('pyApp')) + '</a>' : '')
          + '<div class="sb-py-id"><span>UPI ID</span><code>' + esc(d.upi.id) + '</code><button type="button" data-pycopy="' + esc(d.upi.id) + '">' + esc(tr('pyCopy')) + '</button></div>';
      } else if (P.tab === 'esewa' && d.esewa) {
        body += (d.esewa.qr ? '<div class="sb-py-qrw"><img class="sb-py-qr" src="' + esc(d.esewa.qr) + '" alt="eSewa QR" width="240" height="240"></div>' : '')
          + '<div class="sb-py-id"><span>eSewa</span><code>' + esc(d.esewa.id) + '</code><button type="button" data-pycopy="' + esc(d.esewa.id) + '">' + esc(tr('pyCopy')) + '</button></div>'
          + '<div class="sb-py-id"><span>NPR</span><b>' + esc(npr(d.esewa.npr)) + '</b><small>' + esc(d.esewa.name || '') + '</small></div>'
          + '<p class="sb-py-note">' + esc(tr('pyEsewa')) + '</p>';
      } else if (d.gateways && d.gateways.length) {
        body += d.gateways.map(function (g) { return '<a class="sb-py-main" href="' + esc(g.url) + '">' + esc(L3(g.label)) + '</a>'; }).join('');
      }
      body += '<form class="sb-py-proof" autocomplete="off"><b>' + esc(tr('pyPaid')) + '</b>'
        + '<input name="utr" maxlength="40" inputmode="text" placeholder="' + esc(tr('pyUtr')) + '" aria-label="' + esc(tr('pyUtr')) + '">'
        + '<label class="sb-py-file"><span>📎 ' + esc(tr('pyShot')) + '</span><input type="file" name="screenshot" accept="image/*"></label>'
        + '<p class="sb-py-err" role="alert" hidden></p><button type="submit" class="sb-py-send">' + esc(tr('pySend')) + '</button></form>';
      if (STAFF) body += '<button type="button" class="sb-py-ghost" data-py="share">' + esc(tr('pyShare')) + '</button>';
    }
    var foot = '<div class="sb-py-ft"><span>🔒 ' + esc(tr('pySafe')) + '</span>'
      + (d.holdUntil && P.state === 'pay' ? '<span>🛏 ' + esc(tr('pyHeld')) + ' ' + esc(hhmm(d.holdUntil)) + '</span>' : '') + '</div>';
    box.innerHTML = '<div class="sb-py">' + head + '<div class="sb-py-body">' + body + '</div>' + foot + '</div>';
    paintQrTimer();
  }
  function startQrTimer() {
    var P = PAY; if (!P) return;
    clearInterval(P.tick);
    P.end = Date.now() + S.qrSeconds * 1000;
    P.tick = setInterval(paintQrTimer, 250);
    paintQrTimer();
  }
  var QR_C = 2 * Math.PI * 15;
  function paintQrTimer() {
    var P = PAY; if (!P || P.state !== 'pay') return;
    var left = Math.max(0, Math.ceil((P.end - Date.now()) / 1000));
    var t = $('sbPyTimer'), c = $('sbPyClock'), w = $('sbPyQrw');
    if (c) c.textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
    if (t) {
      var fg = t.querySelector('.fg');
      fg.setAttribute('stroke-dasharray', QR_C.toFixed(2));
      fg.setAttribute('stroke-dashoffset', (QR_C * (1 - left / S.qrSeconds)).toFixed(2));
      t.classList.toggle('low', left <= 10);
    }
    if (w) { w.classList.toggle('sb-py-dead', left === 0); var ex = w.querySelector('.sb-py-exp'); if (ex) ex.hidden = left !== 0; }
    if (left === 0) clearInterval(P.tick);
  }
  function payClick(e) {
    var P = PAY; if (!P) return;
    var b;
    if (e.target === $('sbPayOv') || e.target.closest('[data-py="close"]')) { closePay(); return; }
    if ((b = e.target.closest('[data-pytab]'))) { P.tab = b.getAttribute('data-pytab'); drawPay(); if (P.tab === 'upi') startQrTimer(); return; }
    if ((b = e.target.closest('[data-pycopy]'))) {
      var v = b.getAttribute('data-pycopy');
      try { navigator.clipboard.writeText(v).then(function () { b.textContent = tr('pyCopied') + ' ✓'; }); } catch (x) { say(v); }
      return;
    }
    if (e.target.closest('[data-py="newqr"]')) {
      api({ action: 'payinfo', pnr: P.r.pnr, k: P.r.payKey || '' }).then(function (x) {
        if (!PAY) return;
        if (x.status === 'confirmed') { payDone(); return; }
        if (x.status !== 'pending' || !x.pay) { PAY.state = 'over'; drawPay(); return; }
        PAY.d = x.pay; drawPay(); startQrTimer();
      }).catch(function (er) { say(er.message); });
      return;
    }
    if (e.target.closest('[data-py="share"]')) { var r = P.r; closePay(); showPayLink(r); }
  }
  function sendProof() {
    var P = PAY; if (!P) return;
    var f = $('sbPayOv').querySelector('.sb-py-proof'), err = f.querySelector('.sb-py-err');
    var utr = f.utr.value.trim(), file = f.screenshot.files && f.screenshot.files[0];
    if (!utr && !file) { err.textContent = tr('pyNeed'); err.hidden = false; f.utr.focus(); return; }
    var fd = new FormData();
    fd.append('pnr', P.r.pnr); fd.append('k', P.r.payKey || ''); fd.append('utr', utr);
    fd.append('method', P.tab === 'esewa' ? 'esewa' : 'upi'); fd.append('amount', String((P.d && P.d.due) || P.r.total || ''));
    if (file) fd.append('screenshot', file);
    var btn = f.querySelector('.sb-py-send'); btn.disabled = true; btn.classList.add('sb-busy');
    var send = typeof shgApi.postForm === 'function' ? shgApi.postForm('/payment.php', fd)
      : fetch('/api/payment.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': (window.SHG_BOOT || {}).csrf || '' }, body: fd })
          .then(function (x) { return x.json(); }).then(function (j) { if (!j || j.ok !== true) throw new Error((j && j.error) || 'Error'); return j.data; });
    send.then(function () {
      if (!PAY) return;
      PAY.state = 'verifying'; clearInterval(PAY.tick); drawPay(); pollPay(4000);
    }).catch(function (er) { btn.disabled = false; btn.classList.remove('sb-busy'); err.textContent = er && er.message ? er.message : 'Error'; err.hidden = false; });
  }
  function pollPay(ms) {
    var P = PAY; if (!P) return;
    clearTimeout(P.poll);
    P.poll = setTimeout(function () {
      if (!PAY || PAY !== P) return;
      api({ action: 'status', pnr: P.r.pnr, k: P.r.payKey || '' }).then(function (x) {
        if (!PAY || PAY !== P) return;
        if (x.status === 'confirmed') { payDone(); return; }
        if (x.status === 'expired' || x.status === 'cancelled' || x.status === 'rejected') { P.state = 'over'; drawPay(); return; }
        if (x.proof && P.state === 'pay') { P.state = 'verifying'; clearInterval(P.tick); drawPay(); }
        pollPay(P.state === 'verifying' ? 5000 : 4000);
      }).catch(function () { pollPay(8000); });
    }, ms);
  }
  function payDone() {
    var r = PAY ? PAY.r : null; closePay(true);
    if (!r) return;
    r.status = 'confirmed'; r.ticket = payTicket(r);
    tkMagic(r, S.name, S.phone, S.cc);
    showTicket(r, true);
  }
  /* Agent + Online: the customer pays by QR on their own phone - the agent sends the link. */
  function showPayLink(r) {
    var url = location.origin + r.payPage;
    var text = 'S Hari Global — pay ' + (r.totalLabel || '') + ' for ' + r.pnr + ' (berths held ' + (r.expiresAt || '') + '): ' + url;
    var html = '<div class="sb-tk"><div class="sb-tk-h"><span class="sb-tk-ok" aria-hidden="true" style="background:var(--sb-gold)">⏳</span><div><b>' + esc(r.pnr) + '</b><small>' + esc(r.totalLabel || '') + '</small></div>'
      + '<button type="button" class="sb-x" data-tk="close" aria-label="Close" style="margin-left:auto">✕</button></div>'
      + '<div class="sb-tk-row">Send the payment link to the customer. The ticket is issued when the payment is verified.</div>'
      + '<div class="sb-tk-acts"><a class="sb-main-act" target="_blank" rel="noopener" href="https://wa.me/' + (r.phone ? (S.cc === 'NP' ? '977' : '91') + digits(r.phone) : '') + '?text=' + encodeURIComponent(text) + '">WhatsApp link</a>'
      + '<button type="button" data-copylink="' + esc(url) + '">Copy link</button><a href="' + esc(r.payPage) + '" target="_blank" rel="noopener">Open QR</a></div></div>';
    lastTicket = null;
    if (wide()) { var tk = $('sbTicket'); tk.innerHTML = html; tk.hidden = false; }
    else { $('sbModalIn').innerHTML = html; $('sbModal').hidden = false; }
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copylink]'); if (!b) return;
    try { navigator.clipboard.writeText(b.getAttribute('data-copylink')).then(function () { say('Link copied'); }); } catch (x) {}
  });
  /* ---- the desk's agent (15 Oct 2026) -----------------------------------
     admin/smartbook.php splices #sbAgent (a searchable list of every active
     agent with an SHG number) into the card for a counter / admin desk, and a
     locked code line for an agent login. The pick is remembered per clerk
     under shg_ctr_agent_<id> - the key counter mode and QuickBot share - so
     one pick serves the shift. The server validates it again on every sale. */
  var AGENTS = (STAFF && STAFF.agents) ? STAFF.agents : [];
  var KEY_AGENT = STAFF ? 'shg_ctr_agent_' + (STAFF.id || 0) : '';
  function lsGet(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } }
  function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function normCode(s) {
    var d = String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var st = /^(?:SHG)?S(\d{1,5})$/.exec(d);   /* 30 Oct 2026: a staff number, SHG-S-0004 */
    if (st) return 'SHG-S-' + ('0000' + st[1]).slice(-4);
    var m = /^SHG(\d{1,4})$/.exec(d) || /^(\d{1,4})$/.exec(d);
    return m ? 'SHG-' + ('0000' + m[1]).slice(-4) : '';
  }
  function agentByCode(code) { for (var i = 0; i < AGENTS.length; i++) { if (AGENTS[i].code === code) return AGENTS[i]; } return null; }
  function agentLine(a) { return a.code + ' · ' + a.name + (a.city ? ' · ' + a.city : ''); }
  function agentRate(a) {
    if (!STAFF) return '';
    if (noComm(a)) return 'जना मात्र · passenger count';   // AG-3: staff_commission_off - staff are counted, not paid per head
    if (!STAFF.commFlat) return '';
    return money(a && a.type === 'joint' ? STAFF.commJoint : STAFF.commDirect) + '/pax';
  }
  /* exact = a code only (while typing); loose = a code, or ONE agent whose line contains the text */
  function findAgent(s, loose) {
    var c = normCode(s); if (c) return agentByCode(c);
    if (!loose) return null;
    var t = String(s || '').trim().toLowerCase(); if (!t) return null;
    var hits = AGENTS.filter(function (a) { return agentLine(a).toLowerCase().indexOf(t) >= 0; });
    return hits.length === 1 ? hits[0] : null;
  }
  function setAgent(code, byUser, keepText) {
    var inp = $('sbAgent'); if (!inp) return;
    S.agent = code || '';
    var a = S.agent && S.agent !== 'OTHER' ? agentByCode(S.agent) : null;
    if (!a && S.agent !== 'OTHER') S.agent = '';
    $('sbAgentOther').classList.toggle('on', S.agent === 'OTHER');
    var why = $('sbAgentWhy');
    if (a) {
      if (!keepText) inp.value = a.code;
      var rate = agentRate(a);
      why.innerHTML = '<span class="sb-agent-ok">✓</span> ' + esc((a.co ? '🏢 ' : '👤 ') + agentLine(a)) + (rate ? ' · ' + esc(rate) : '');
    } else if (S.agent === 'OTHER') {
      if (!keepText) inp.value = '';
      why.textContent = '🏢 ' + (STAFF.company || 'SHG-0001') + ' — ' + tr('agentCo');
    } else {
      why.textContent = inp.value.trim() ? tr('agentNo') : tr(STAFF.agentRequired ? 'agentPick' : 'agentBlank');
    }
    if (byUser) { lsSet(KEY_AGENT, S.agent); S.touched.agent = true; }
    showErrs(false); syncButtons();
  }
  function initAgent() {
    var inp = $('sbAgent'); if (!STAFF || !inp) return;
    $('sbAgentList').innerHTML = AGENTS.map(function (a) {
      var rate = agentRate(a);
      return '<option value="' + esc(a.code) + '">' + esc((a.co ? '🏢 ' : (a.type === 'staff' ? '🧑‍💼 ' : '👤 ')) + a.name + (a.city ? ' · ' + a.city : '') + (rate ? ' · ' + rate : '')) + '</option>';
    }).join('');
    var saved = lsGet(KEY_AGENT);
    setAgent(saved === 'OTHER' ? 'OTHER' : (findAgent(saved) ? findAgent(saved).code : ''), false);
    inp.addEventListener('input', function () {     // a code typed or picked from the list resolves at once; text just searches
      var a = findAgent(inp.value, false);
      setAgent(a ? a.code : '', !!a, true);
    });
    inp.addEventListener('change', function () {    // the list's pick / leaving the box: one match is enough
      var a = findAgent(inp.value, true);
      if (a) setAgent(a.code, true); else if (!inp.value.trim() && S.agent !== 'OTHER') setAgent('', true);
    });
    inp.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.ctrlKey || e.metaKey) return;
      e.preventDefault();
      var a = findAgent(inp.value, true);
      if (a) { setAgent(a.code, true); $('sbPhone').focus(); } else { say(tr('agentNo')); }
    });
    $('sbAgentOther').addEventListener('click', function () { setAgent(S.agent === 'OTHER' ? '' : 'OTHER', true); if (S.agent === 'OTHER') $('sbPhone').focus(); });
  }

  /* Counter keyboard: Ctrl+Enter = auto-berth + Cash + print · Enter in the name / passengers = one more passenger. */
  /* 19 Nov 2026 (u4 desk glue): the staff card's own extra fields, built here so the
     customer page never carries them (admin/smartbook.php reads the card from the one
     template). (1) दोस्रो नम्बर · 2nd number with its own India / Nepal select, under the
     mobile + name; it starts on the OTHER country of the main number until the clerk picks.
     (2) पैसा कहिले? · When will they pay - in the desk box above Book (else under the 2nd
     number); sent with a Cash sale only. */
  function buildDeskMore() {
    if (!STAFF || $('sbAltF')) return;
    var grid = root.querySelector('.sb-grid');
    if (grid && grid.parentNode) {
      var f = document.createElement('div');
      f.className = 'sb-f sb-f-alt'; f.id = 'sbAltF'; f.style.margin = '8px 0 0';
      f.innerHTML = '<label class="sb-l" for="sbAltPhone">📱 दोस्रो नम्बर · 2nd number <small style="text-transform:none;font-weight:600;opacity:.7">(ऐच्छिक · optional — टिकट दुवैमा जान्छ · the ticket goes to both)</small></label>'
        + '<div class="sb-tel"><select id="sbAltCc" aria-label="2nd number country"><option value="IN">+91 🇮🇳</option><option value="NP">+977 🇳🇵</option></select>'
        + '<input id="sbAltPhone" type="tel" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="98XXXXXXXX"></div>'
        + '<small class="sb-fe" id="sbAltE" hidden></small>';
      grid.parentNode.insertBefore(f, grid.nextSibling);
      var cc = $('sbAltCc');
      S.altPicked = false;   // resetDeskMore() clears it for the next customer
      altOther();
      cc.addEventListener('change', function () { S.altPicked = true; S.idem = null; showErrs(false); syncButtons(); });
      $('sbCc').addEventListener('change', altOther);
      $('sbAltPhone').addEventListener('input', function () { altGuessCc(); S.idem = null; showErrs(false); syncButtons(); });
      $('sbAltPhone').addEventListener('blur', function () { S.touched.alt = true; showErrs(false); });
    }
    var host = $('sbxDesk') || $('sbAltF');
    if (host && !$('sbxCash')) {
      var c = document.createElement('span');
      c.className = 'sbx-tnote sbx-cash'; c.id = 'sbxCash';
      c.innerHTML = '<label for="sbxCashWhen">💵 पैसा कहिले? · When will they pay <small style="font-weight:600;opacity:.7">(Cash)</small></label>'
        + '<select id="sbxCashWhen" style="min-height:40px;border-radius:9px;padding:0 8px;font:inherit;font-weight:600">'
        + '<option value="">— ऐच्छिक · optional —</option><option value="full">पूरा तिर्यो · Paid in full</option>'
        + '<option value="partial">आंशिक · Part paid (₹)</option><option value="later">पछि तिर्ने · Pays later</option></select>'
        + '<span id="sbxCashMore" hidden style="flex-wrap:wrap;gap:6px">'
        + '<input id="sbxCashAmt" type="number" min="1" step="1" inputmode="numeric" placeholder="₹ रकम · amount" aria-label="Amount paid now (₹)" style="flex:1 1 110px">'
        + '<input id="sbxCashDate" type="date" aria-label="Pays by (date)" style="flex:1 1 140px"></span>'
        + '<input id="sbxCashTxt" maxlength="80" autocomplete="off" placeholder="नोट · note (जस्तै: बाँकी भाइले दिन्छ)" aria-label="Cash note" hidden>'
        + '<small>कार्यालयको लागि नोट मात्र — भुक्तानी पक्का गर्दैन · a note for the office only, never a payment</small>';
      host.appendChild(c);
      var w = $('sbxCashWhen');
      var paintCash = function () {
        var v = w.value, more = $('sbxCashMore');
        more.hidden = !(v === 'partial' || v === 'later');
        more.style.display = more.hidden ? 'none' : 'flex';
        $('sbxCashAmt').hidden = v !== 'partial';
        $('sbxCashTxt').hidden = v === '';
        try { $('sbxCashDate').min = todayIso(); } catch (x) {}
      };
      w.addEventListener('change', paintCash);
      paintCash();
    }
  }
  /* the 2nd number starts on the other country of the main number until the clerk picks one */
  function altOther() { var cc = $('sbAltCc'); if (!cc || S.altPicked) return; cc.value = S.cc === 'NP' ? 'IN' : 'NP'; altGuessCc(); }
  function resetDeskMore() {
    ['sbAltPhone', 'sbxCashAmt', 'sbxCashDate', 'sbxCashTxt'].forEach(function (id) { var el = $(id); if (el) el.value = ''; });
    S.altPicked = false; altOther();   // a country picked for one customer must not stick to the next
    var w = $('sbxCashWhen'); if (w && w.value) { w.value = ''; try { w.dispatchEvent(new Event('change')); } catch (x) {} }
  }

  if (STAFF) {
    initAgent();
    buildDeskMore();
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); book('cash', true); return; }
      if (e.key === 'Enter' && (e.target.id === 'sbName' || e.target.closest('#sbPax'))) {
        e.preventDefault();
        if (S.pax.length < S.maxPax) { S.pax.push({ gender: S.pax.length ? S.pax[S.pax.length - 1].gender : '', band: 'adult' }); S.manual = []; renderPax(); changed(); }
        var last = $('sbPax').querySelector('.sb-p:last-child .sb-g'); if (last) last.focus();
      }
    });
  }
  /* A regular customer from the staff list: their number + their own history (staff may read it). */
  function loadCustomer(phone, name) {
    S.phone = String(phone || ''); S.name = String(name || S.name || '');
    api({ action: 'boot', phone: digits(S.phone), direction: '' }).then(function (d) {
      var df = d.defaults || {};
      S.why = df.why || {};
      if (df.direction) S.dir = df.direction;
      if (df.point) S.point = df.point;
      if (df.name && !name) S.name = df.name;
      if (df.pax && df.pax.length) S.pax = df.pax.map(function (p) { return { gender: p.gender || '', band: p.band || 'adult' }; });
      S.manual = []; S.seatMode = 'auto'; S.date = '';
      fill(); applyWords(); store.set(); requote(0);
    }).catch(function (er) { say(er.message); });
  }
  function adopt(r) {
    try {
      if (!r || !r.customer || typeof bookingFromServer !== 'function' || typeof DB === 'undefined') return;
      var b = bookingFromServer(r.customer, r.phone); if (!b) return;
      b.ownBrowser = true;
      var at = -1;
      for (var i = 0; i < DB.bookings.length; i++) { if (DB.bookings[i] && DB.bookings[i].id === b.id) { at = i; break; } }
      if (at >= 0) DB.bookings[at] = Object.assign({}, DB.bookings[at], b); else DB.bookings.unshift(b);
      if (typeof persist === 'function') persist('bookings');
    } catch (e) {}
  }

  /* ------------------------------------------------------------ ticket magic */
  /* 20 Nov 2026 (owner: "ticket generate huda magic jasari ghumdai aos, name number ni
     flow hos"): the moment a sale is confirmed, a 3D ticket spins in over the screen,
     the passenger's name, number, route and berths write themselves in letter by
     letter, a seal stamps "confirmed", and it hands over to the real ticket below.
     Presentation only - the sale is already done when this starts. Tap / Esc skips.
     Desk sales play a shorter cut (the counter is busy); reduced motion = a quick fade. */
  function tkMagic(r, name, phone, cc) {
    try {
      if (!r || !r.pnr || document.getElementById('tkMagic')) return;
      var calm = (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)
        || document.documentElement.getAttribute('data-motion') === 'reduced';
      var ms = calm ? 900 : (STAFF ? 2300 : 3200);
      var ph = digits(phone || '');
      var phTxt = ph ? '+' + (ph.length > 10 ? '' : (cc === 'NP' ? '977 ' : '91 ')) + ph.replace(/(\d{5})(\d+)$/, '$1 $2') : '';
      var flow = function (txt, d0) {   // one span per letter, staggered
        var out = '', chars = Array.from(String(txt || ''));
        for (var i = 0; i < chars.length; i++) {
          out += '<span style="--d:' + (d0 + i * 0.035).toFixed(3) + 's">' + (chars[i] === ' ' ? '&nbsp;' : esc(chars[i])) + '</span>';
        }
        return out;
      };
      var sparks = '';
      for (var k = 0; k < 18; k++) sparks += '<i style="--a:' + (k * 20) + 'deg;--r:' + (120 + (k % 3) * 40) + 'px;--d:' + (0.9 + (k % 4) * 0.06).toFixed(2) + 's"></i>';
      var el = document.createElement('div');
      el.id = 'tkMagic';
      el.className = 'tkm' + (calm ? ' tkm-calm' : '') + (STAFF ? ' tkm-desk' : '');
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.innerHTML = '<div class="tkm-glow" aria-hidden="true"></div><div class="tkm-sparks" aria-hidden="true">' + sparks + '</div>'
        + '<div class="tkm-stage"><div class="tkm-card">'
        + '<div class="tkm-top"><span class="tkm-logo">SH</span><b>S Hari Global<sup>™</sup></b><span class="tkm-pnr">' + esc(r.pnr) + '</span></div>'
        + '<div class="tkm-name">' + flow(name || '', 0.75) + '</div>'
        + (phTxt ? '<div class="tkm-ph">📱 ' + flow(phTxt, 1.05) + '</div>' : '')
        + '<div class="tkm-route">' + flow(r.route || '', 1.3) + '</div>'
        + '<div class="tkm-meta"><span>📅 ' + esc(r.dateLabel || '') + '</span><span>💺 ' + esc((r.seatLabels || []).join(', ')) + '</span>'
        + (r.totalLabel ? '<span>' + esc(r.totalLabel) + '</span>' : '') + '</div>'
        + (r.normal && r.normal.save >= 1 ? '<div class="tkm-nf"><s>' + esc(money(r.normal.normal)) + '</s> <b>' + esc(tr('nfSave')) + ' ' + esc(money(r.normal.save)) + '</b></div>' : '')
        + '<div class="tkm-perf" aria-hidden="true"></div>'
        + '<div class="tkm-seal" aria-hidden="true">✓</div><div class="tkm-sheen" aria-hidden="true"></div>'
        + '</div><p class="tkm-ok">✓ ' + esc(tr('ticketOk')) + '</p>'
        /* 25 Nov 2026 (mp1 tk review, spec D): Share (WhatsApp), PNG and Print are on the
           overlay from its first frame - no waiting for the spin to end. A tap on one closes
           the overlay and does it (the links work as links; Share = the ticket's own Share). */
        + tkmActs(r)
        + '</div>';
      document.body.appendChild(el);
      var gone = false, tmr;
      var close = function () {
        if (gone) return; gone = true; clearTimeout(tmr);
        el.classList.add('tkm-out');
        document.removeEventListener('keydown', onKey, true);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 420);
      };
      var onKey = function (e) { if (e.key === 'Escape' || (e.key === 'Enter' && !(e.target && e.target.closest && e.target.closest('.tkm-acts')))) close(); };
      el.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('[data-tkm]') : null;
        close();
        if (a && a.getAttribute('data-tkm') === 'share') shareTicket(r);
      });
      document.addEventListener('keydown', onKey, true);
      tmr = setTimeout(close, ms);
      try { if (!calm && navigator.vibrate) navigator.vibrate([18, 60, 28]); } catch (e) {}
    } catch (e) { /* never block the ticket */ }
  }
  /* The three ticket actions on the magic overlay: Share (WhatsApp on a phone), the PNG and
     Print - the same links the ticket card below carries (r.ticket), nothing new. */
  function tkmActs(r) {
    var t = (r && r.ticket) || {};
    return '<div class="tkm-acts">'
      + '<button type="button" class="tkm-act-main" data-tkm="share">📤 ' + esc(tr('share')) + '</button>'
      + (t.png ? '<a href="' + esc(t.png) + '" download="SHG-Ticket-' + esc(r.pnr) + '.png">⬇️ ' + esc(tr('dlPng')) + '</a>' : '')
      + (t.print ? '<a href="' + esc(t.print) + '" target="_blank" rel="noopener">🖨 ' + esc(tr('print')) + '</a>' : '')
      + '</div>';
  }
  /* Share the ticket: the PNG itself where the phone can share files, else the link
     (system share sheet, else WhatsApp). Used by the ticket card and the magic overlay. */
  function shareTicket(r) {
    if (!r) return;
    var url = location.origin + (r.ticket && r.ticket.view ? r.ticket.view : '/#/ticket/' + r.pnr);
    var text = 'S Hari Global ticket ' + r.pnr + ' · ' + (r.route || '') + ' · ' + (r.dateLabel || '') + ' · ' + (r.seatLabels || []).join(', ');
    var shareFile = function () {
      if (!(r.ticket && r.ticket.png && navigator.canShare)) return Promise.reject();
      return fetch(r.ticket.png, { credentials: 'same-origin' }).then(function (x) { return x.blob(); }).then(function (blob) {
        var f = new File([blob], r.pnr + '.png', { type: blob.type || 'image/png' });
        if (!navigator.canShare({ files: [f] })) throw new Error('no');
        return navigator.share({ files: [f], title: r.pnr, text: text });
      });
    };
    shareFile().catch(function () {
      if (navigator.share) return navigator.share({ title: r.pnr, text: text, url: url }).catch(function () {});
      window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
    });
  }

  /* ------------------------------------------------------------ ticket */
  var lastTicket = null;
  /* 16 Oct 2026 (owner: "3-4 second mai ticket delivery"): the picture on this
     screen is the ticket the moment the sale answers. WhatsApp: a customer taps
     once and sends "Hi" to our Meta number - the free reply carries the ticket
     PNG in seconds (paid template sends can fail on an empty wallet); a desk
     sends the ticket link straight to the customer's own WhatsApp. */
  function waTicketBtn(r) {
    if (!STAFF) {
      var hi = String(((window.SHG_BOOT || {}).waHi) || '').replace(/\D/g, '');
      if (hi.length < 10) return '';
      return '<a class="sb-tk-wa" target="_blank" rel="noopener" href="https://wa.me/' + hi + '?text=' + encodeURIComponent('Hi ' + r.pnr) + '">'
        + '<span>' + esc(tr('waGet')) + '</span><small>' + esc(tr('waGetSub')) + '</small></a>';
    }
    var ph = digits(r.phone || ''); if (ph.length < 10) return '';
    var cc = ph.length > 10 ? '' : (S.cc === 'NP' ? '977' : '91');
    var link = location.origin + ((r.ticket && r.ticket.view) || '');
    var text = 'S Hari Global 🎫 ' + r.pnr + ' · ' + (r.route || '') + ' · ' + (r.dateLabel || '') + ' · ' + (r.seatLabels || []).join(', ') + '\n' + link;
    return '<a class="sb-tk-wa" target="_blank" rel="noopener" href="https://wa.me/' + cc + ph + '?text=' + encodeURIComponent(text) + '"><span>' + esc(tr('waSend')) + '</span><small>+' + esc(cc + ph) + '</small></a>';
  }
  function ticketHtml(r) {
    var t = r.ticket || {};
    var secs = r.elapsedMs > 0 ? (r.elapsedMs / 1000).toFixed(1) + ' s' : '';
    return '<div class="sb-tk">'
      + '<div class="sb-tk-h"><span class="sb-tk-ok" aria-hidden="true">✓</span><div><b>' + esc(tr('ticketOk')) + '</b><small>' + esc(r.route || '') + ' · ' + esc(r.dateLabel || '') + '</small></div>'
      + (secs ? '<span class="sb-tk-fast" title="' + esc(tr('fast')) + '">⚡ ' + esc(secs) + '</span>' : '')
      + '<button type="button" class="sb-x" data-tk="close" aria-label="Close" style="margin-left:auto">✕</button></div>'
      + '<div class="sb-tk-pnr">' + esc(tr('pnr')) + ' ' + esc(r.pnr) + '</div>'
      + (t.view ? '<div class="sb-tk-imgw" data-tkimg><img class="sb-tk-img" src="' + esc(t.view) + '" alt="Ticket ' + esc(r.pnr) + '" loading="eager" decoding="async"><span class="sb-tk-wait">' + esc(tr('making')) + '</span></div>' : '')
      + '<div class="sb-tk-row">' + esc((r.seatLabels || []).join(', ')) + ' · ' + esc(r.totalLabel || '') + ' <small>(≈ ' + esc(npr(r.npr)) + ')</small>' + nfHtml(r.normal) + '</div>'
      + (r.boardingTime ? '<div class="sb-tk-row">' + esc(r.boarding || '') + ' · <small>' + esc(tr('report')) + ' ' + esc(r.reportTime || '') + '</small></div>' : '')
      + (r.isCod ? '<div class="sb-tk-cod">💵 ' + esc(tr('codNote')) + ' — ' + esc(r.totalLabel || '') + '</div>' : '')
      /* staff desks: who the sale was credited to and what it earned (the server
         answers these only to a staff session; a customer's answer has neither) */
      + (STAFF && r.agent ? '<div class="sb-tk-agent">🤝 ' + esc(tr('credited')) + ' ' + esc(r.agent.code + ' · ' + r.agent.name)
          + (r.commission ? ' · ' + esc(money(r.commission)) + ' ' + esc(tr('commission')) : '') + '</div>' : '')
      + waTicketBtn(r)
      + '<div class="sb-tk-acts">'
      /* 15 Oct 2026 (owner: "same page mai delivery, share ya download button"):
         the picture above IS the ticket; Share leads (WhatsApp on a phone),
         then the PNG, then the PDF for print. Nothing downloads by itself. */
      + '<button type="button" class="sb-main-act" data-tk="share">📤 ' + esc(tr('share')) + '</button>'
      + (t.png ? '<a href="' + esc(t.png) + '" download="SHG-Ticket-' + esc(r.pnr) + '.png">⬇️ ' + esc(tr('dlPng')) + '</a>' : '')
      + (t.pdf ? '<a href="' + esc(t.pdf) + '" download>' + esc(tr('dlPdf')) + '</a>' : '')
      + (t.print && STAFF ? '<a href="' + esc(t.print) + '" target="_blank" rel="noopener">' + esc(tr('print')) + '</a>' : '')
      /* mp1 tk (25 Nov 2026): a customer gets Print right away too - the same ticket PDF
         opened in the browser's own viewer (?print=1, the keyed public link), print from there. */
      + (!STAFF && t.print ? '<a href="' + esc(t.print) + '" target="_blank" rel="noopener">🖨 ' + esc(tr('print')) + '</a>' : '')
      + '<button type="button" data-tk="copy">' + esc(tr('copy')) + '</button>'
      + '<a href="#/trip/' + encodeURIComponent(r.pnr) + '">' + esc(tr('track')) + '</a>'
      + '</div><button type="button" class="sb-tk-new" data-tk="new">＋ ' + esc(tr('newB')) + '</button></div>';
  }
  function wide() { return window.matchMedia && window.matchMedia('(min-width: 860px)').matches; }
  function showTicket(r, fresh) {
    lastTicket = r;
    var html = ticketHtml(r);
    var host;
    if (wide()) { host = $('sbTicket'); host.innerHTML = html; host.hidden = false; host.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    else { host = $('sbModalIn'); host.innerHTML = html; $('sbModal').hidden = false; document.body.classList.add('shg-modal-open'); }
    wireTicketImg(host);
    if (!STAFF) pushCard(host, r);
    try { if (!STAFF && location.hash.indexOf('#/?ticket=') !== 0) history.replaceState(null, '', '#/?ticket=' + encodeURIComponent(r.pnr)); } catch (e) {}
    /* 31 Oct 2026 (review): SmartBook is the home page's booking path and replaceState fires no
       hashchange - tell the install sheet a customer's booking just finished (never a staff sale). */
    if (fresh && !STAFF) { try { if (window.SHGInstall && window.SHGInstall.afterBooking) window.SHGInstall.afterBooking(); } catch (e) {} }
    /* 15 Oct 2026: the PDF no longer downloads by itself - on a phone that
       navigation closed the ticket the customer had just been shown. The PNG
       on the page is the delivery (the server pre-draws it while the booking
       answer is still in flight); Share / Download are one tap away. */
  }
  /* 16 Nov 2026 (P2-2c): a customer's own ticket offers departure alerts right here,
     the moment it is booked - the same card (17-pwa.js SHGPush) the classic
     #/ticket view shows, with its own denied / unsupported / iPhone-install states.
     No new message: it only lets this phone receive the trip reminders and
     delay notices that already go out. Never on a staff sale (another person's
     phone). The server ties the ticket to this browser's own sale only. */
  function pushCard(host, r) {
    try {
      var P = window.SHGPush; if (!P || typeof P.card !== 'function' || !r || !r.pnr) return;
      var b = null;
      if (typeof DB !== 'undefined' && DB.bookings) {
        for (var i = 0; i < DB.bookings.length; i++) { if (DB.bookings[i] && DB.bookings[i].id === r.pnr) { b = DB.bookings[i]; break; } }
      }
      b = b ? Object.assign({}, b) : { id: r.pnr, date: r.date || '' };
      if (!b.status) b.status = r.status || 'confirmed';
      var html = P.card(b); if (!html) return;
      var tk = host.querySelector('.sb-tk'); if (!tk) return;
      var at = tk.querySelector('.sb-tk-new');
      var box = document.createElement('div'); box.className = 'sb-tk-push'; box.innerHTML = html;
      tk.insertBefore(box, at || null);
      P.wire(b, box);
    } catch (e) {}
  }
  /* The ticket picture: a "preparing…" shimmer until it has loaded, and a
     couple of quiet retries if the first fetch failed (a cold render on a
     busy server) - the customer never sees a broken image. */
  function wireTicketImg(host) {
    var w = host.querySelector('[data-tkimg]'); if (!w) return;
    var img = w.querySelector('img'), tries = 0, src = img.getAttribute('src');
    function ok() { w.classList.add('ok'); }
    img.addEventListener('load', ok);
    img.addEventListener('error', function () {
      if (++tries > 3) { w.classList.add('bad'); return; }
      setTimeout(function () { img.src = src + '&t=' + Date.now(); }, 1200 * tries);
    });
    if (img.complete && img.naturalWidth > 0) ok();
  }
  function closeTicket() {
    $('sbModal').hidden = true; document.body.classList.remove('shg-modal-open');
    $('sbTicket').hidden = true;
    try { if (location.hash.indexOf('#/?ticket=') === 0) history.replaceState(null, '', '#/'); } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tk]'); if (!b || !root.contains(b)) return;
    var r = lastTicket; var act = b.getAttribute('data-tk');
    if (act === 'close') { closeTicket(); return; }
    if (act === 'new') { closeTicket(); S.manual = []; S.seatMode = 'auto'; S.date = ''; S.promo = ''; if ($('sbPromoIn')) $('sbPromoIn').value = ''; emReset(); fill(); requote(0); window.scrollTo({ top: root.offsetTop - 60, behavior: 'smooth' }); return; }
    if (!r) return;
    if (act === 'copy') { try { navigator.clipboard.writeText(r.pnr).then(function () { say(tr('copied')); }); } catch (x) { say(r.pnr); } return; }
    if (act === 'share') shareTicket(r);
  });
  $('sbModal').addEventListener('click', function (e) { if (e.target === this) closeTicket(); });

  /* /#/?ticket=PNR opens the ticket on the home page (§7) */
  function ticketFromHash() {
    if (DESK) return;
    var m = /^#\/\?ticket=([A-Za-z0-9-]+)/.exec(location.hash || '');
    if (!m) return;
    var pnr = decodeURIComponent(m[1]);
    if (lastTicket && lastTicket.pnr === pnr) { showTicket(lastTicket, false); return; }
    var b = (typeof DB !== 'undefined' && DB.bookings) ? DB.bookings.find(function (x) { return x && x.id === pnr; }) : null;
    if (b && b.dlKey) {
      var k = '&k=' + encodeURIComponent(b.dlKey);
      showTicket({ pnr: pnr, route: (b.from || '') + ' → ' + (b.to || ''), dateLabel: b.date || '', seatLabels: b.seats || [], totalLabel: money(b.total || 0), npr: 0,
        isCod: !!b.codFlag, ticket: { view: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&img=1&view=1', png: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&img=1',
          pdf: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k, print: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&print=1' } }, false);
    } else {
      location.hash = '#/ticket/' + encodeURIComponent(pnr);
    }
  }

  /* --------------------------------------------- page-level wiring */
  function retargetBookLinks() {
    document.querySelectorAll('[data-scroll="search-anchor"], [data-scroll="quick-ticket"]').forEach(function (a) { a.setAttribute('data-scroll', DESK ? 'sbDesk' : 'smartBook'); });
  }
  function homeFlag() {
    var home = document.getElementById('view-home');
    document.documentElement.classList.toggle('sb-home', !home || home.classList.contains('active'));
  }
  window.addEventListener('hashchange', function () { setTimeout(function () { homeFlag(); ticketFromHash(); }, 0); });
  // 20 Nov 2026 (d360b): a page opened straight at #/my or #/track never fires hashchange, so .sb-home stayed
  // on from the first paint. Follow the home view's own "active" class as well.
  (function () {
    var hv = document.getElementById('view-home');
    if (hv && 'MutationObserver' in window) { new MutationObserver(homeFlag).observe(hv, { attributes: true, attributeFilter: ['class'] }); }
    homeFlag();
  })();
  // While the card is on screen the floating WhatsApp / Sahayak buttons step aside (phone).
  // 14 Nov 2026 (d360): .sb-fab-ok = "the observer has decided" - until then polish.css §7.1 keeps the two
  // buttons hidden, so they no longer flash over the form while the page loads.
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) {
      document.documentElement.classList.toggle('sb-in-view', !!(en[0] && en[0].isIntersecting));
      document.documentElement.classList.add('sb-fab-ok');
    }, { threshold: 0.25 }).observe($('sbCard'));
  } else { document.documentElement.classList.add('sb-fab-ok'); }
  // 14 Nov 2026 (d360): a focused field must not sit under the fixed book bar / bottom nav. The browser counts a
  // field at the bottom edge as "visible", so focus alone does not move it; scrollIntoView does honour the
  // scroll-padding polish.css §7.3 sets for the nav + bar. Phone only (on desktop the bar is not fixed).
  function clearOfBar(el) {
    var bar = $('sbBar');
    if (!el || !bar || !bar.getClientRects().length || getComputedStyle(bar).position !== 'fixed') return;
    var r = el.getBoundingClientRect();
    if (r.bottom > bar.getBoundingClientRect().top - 8 || r.top < 60) { try { el.scrollIntoView({ block: 'nearest' }); } catch (e) {} }
  }
  $('sbCard').addEventListener('focusin', function (e) {
    var el = e.target; if (!el || !/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) return;
    setTimeout(function () { clearOfBar(el); }, 60);
    setTimeout(function () { if (document.activeElement === el) clearOfBar(el); }, 450);   // again once the keyboard is up
  });
  new MutationObserver(function () { applyWords(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
  /* Staff: clear the card for the next customer after a sale (keeps the date / pickup / type). */
  function resetForNext() {
    (function () { var dv = document.getElementById('sbxDiscVal'); if (dv) dv.value = ''; var tn = document.getElementById('sbxTNote'); if (tn) tn.value = ''; if (window.SB_DISC_MARK) window.SB_DISC_MARK(); })();   // a discount or a ticket note never rides into the next ticket
    ['sbxLug', 'sbxInf'].forEach(function (id) {   // P8-3f / P4-4d: nor luggage / an infant
      var el = document.getElementById(id); if (el && el.value !== '0') { el.value = '0'; try { el.dispatchEvent(new Event('input')); } catch (x) {} }
    });
    resetDeskMore();   // 19 Nov 2026: nor a 2nd number or a cash note
    S.phone = ''; S.name = ''; S.pax = [{ gender: '', band: 'adult' }]; S.manual = []; S.seatMode = 'auto'; S.idem = null; S.touched = {};
    S.why = {}; fill(); applyWords(); store.set(); showErrs(false); requote(0);
  }
  /* ------------------------------------------------ Offline call-back request
     16 Nov 2026 (P2-4e + P2-4g, setting offline_callback_on, default OFF): the card cannot
     sell without the network (the engine is on the server). While navigator.onLine is
     false the bar shows ONE button - "Call-back request" - in place of a dead Book, and a
     card whose engine could not boot (the saved page opened offline) shows a short "no
     internet" panel with the same button. The sheet asks the route, date, seats, name and
     mobile and puts ONE item in the Sahayak complaint desk's offline queue 'shg:cq'
     (16-lazy.js), which goes to /api/enquiry.php as source 'callback' once the network is
     back: the Enquiries inbox + a Help Desk number, like the contact button's "call me
     back". It is NOT a ticket - no berth is held, nothing is charged or sold; the office
     phones the passenger. Customer face only (the desks have their own offline desk).
     With the switch off none of this runs. */
  var CQ = 'shg:cq', cbBooted = false, cbFailed = false, cbPanel = null;
  function cbOn() {
    if (STAFF || DESK) return false;
    var v = ((window.SHG_BOOT && window.SHG_BOOT.settings) || {}).offline_callback_on;
    return v === true || v === 1 || v === '1';
  }
  function cbOffline() { return cbOn() && navigator.onLine === false; }
  function cbQueue() { try { var q = JSON.parse(localStorage.getItem(CQ) || '[]'); return Array.isArray(q) ? q : []; } catch (e) { return []; } }
  function cbNepal() { var n = ((window.SHG_BOOT && window.SHG_BOOT.locations) || {}).nepal; return (n && n[0]) || 'Rupaidiha'; }
  function cbTowns(dir) {
    var l = pointList(dir).map(function (p) { return p.name; });
    if (!l.length) {   // engine never answered (opened offline): the pickup list inside the saved page
      var loc = (window.SHG_BOOT && window.SHG_BOOT.locations) || {};
      l = ((dir === 'toIndia' ? loc.back : loc.go) || []).map(function (x) { return x && x.name; });
    }
    return l.filter(Boolean);
  }
  /* the same mobile rules as the card (phoneErr); a Nepal number goes as 977 + 10 digits */
  function cbPhone(cc, raw) {
    var d = digits(raw);
    if (cc === 'NP') { if (d.length === 13 && d.indexOf('977') === 0) d = d.slice(3); return /^9[678]\d{8}$/.test(d) && !/^(\d)\1+$/.test(d) ? '977' + d : ''; }
    if (d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return /^[6-9]\d{9}$/.test(d) && !/^(\d)\1+$/.test(d) ? d : '';
  }
  function cbStamp() { var d = new Date(); return todayIso() + ' ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  /* 16-lazy.js owns the flush (one at a time, safe to call twice); not loaded yet -> load it, its own timer sends */
  function cbFlush() {
    if (navigator.onLine === false) return;
    if (typeof window.shgCqFlush === 'function') { window.shgCqFlush(); return; }
    try { if (typeof shgLazyLoad === 'function') shgLazyLoad().catch(function () {}); } catch (e) {}
  }
  function cbSync() {
    if (!cbOn()) return;
    var off = navigator.onLine === false;
    if (!cbBooted) { if (cbFailed) cbPanelSync(off); return; }
    if (cbPanel) cbPanelSync(false);
    var bar = $('sbBar'), btn = $('sbCbBtn');
    if (!bar) return;
    if (off && !btn) {
      btn = document.createElement('button');
      btn.type = 'button'; btn.id = 'sbCbBtn'; btn.className = 'sb-pay sb-online';
      btn.style.flex = '2 1 auto';
      btn.innerHTML = '<span></span><small></small>';
      btn.addEventListener('click', cbOpen);
      bar.appendChild(btn);
    }
    if (btn) {
      btn.style.display = off ? '' : 'none';
      btn.querySelector('span').textContent = tr('cbBtn'); btn.querySelector('small').textContent = tr('cbBtnSub');
    }
    ['sbCash', 'sbOnline'].forEach(function (id) { var b = $(id); if (b) b.style.display = off ? 'none' : ''; });
  }
  function cbPanelSync(show) {
    if (!show && !cbPanel) return;
    if (!cbPanel) {
      cbPanel = document.createElement('div');
      cbPanel.id = 'sbCbOff'; cbPanel.className = 'sb-card';
      cbPanel.setAttribute('role', 'region'); cbPanel.setAttribute('aria-labelledby', 'sbCbOffT');
      cbPanel.addEventListener('click', function (e) { if (e.target.closest('[data-cb="open"]')) cbOpen(); });
      root.insertBefore(cbPanel, root.firstChild);
    }
    if (show) {
      var n = cbQueue().length;
      cbPanel.innerHTML = '<h2 class="sb-title" id="sbCbOffT">' + esc(tr('cbOffT')) + '</h2>'
        + '<p class="sb-sub">' + esc(tr('cbOffSub')) + '</p>'
        + '<button type="button" class="sb-pay sb-online" data-cb="open" style="width:100%;margin-top:10px"><span>' + esc(tr('cbBtn')) + '</span><small>' + esc(tr('cbBtnSub')) + '</small></button>'
        + '<p class="sb-sub" data-cb="wait"' + (n ? '' : ' hidden') + ' style="margin-top:6px">⏳ ' + esc(tr('cbWait', { n: n })) + '</p>';
    }
    cbPanel.style.display = show ? '' : 'none';
    var inner = root.querySelector('.sb-in'); if (inner) inner.style.display = show ? 'none' : '';
    root.style.display = show ? 'block' : '';   // the classic search card stays below it (html:not(.sb-on))
    if (show) root.hidden = false; else if (!cbBooted) root.hidden = true;
  }
  function cbSheet() {
    var box = $('sbCb'); if (box) return box;
    box = document.createElement('div'); box.id = 'sbCb'; box.className = 'sb-cv'; box.hidden = true;
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-labelledby', 'sbCbT');
    root.appendChild(box);
    box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('[data-cb="x"]')) cbClose(); });
    box.addEventListener('change', function (e) { if (e.target && e.target.id === 'sbCbDir') cbTownOpts(e.target.value, ''); });
    box.addEventListener('submit', function (e) { e.preventDefault(); cbSave(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) cbClose(); });
    return box;
  }
  function cbTownOpts(dir, want) {
    var sel = $('sbCbPt'), l = cbTowns(dir); if (!sel) return;
    if (want && l.indexOf(want) < 0) want = '';
    sel.innerHTML = l.length ? l.map(function (n) { return '<option value="' + esc(n) + '"' + (n === want ? ' selected' : '') + '>' + esc(n) + '</option>'; }).join('')
      : '<option value="">Gujarat</option>';
    $('sbCbPtL').textContent = tr(dir === 'toIndia' ? 'drop' : 'board');
  }
  function cbOpen() {
    var sv = cbBooted ? S : store.get();   // never booted: what this phone typed last time (sb:v1)
    var box = cbSheet(), dir = sv.dir === 'toIndia' ? 'toIndia' : 'toNepal', t0 = todayIso();
    var date = S.date || (S.q && S.q.date) || t0; if (date < t0) date = t0;
    var nm = String(sv.name || ''), phv = String(sv.phone || ''), ccv = sv.cc === 'NP' ? 'NP' : 'IN';
    var seats = Math.max(1, Math.min(10, (sv.pax && sv.pax.length) || 1)), opts = '';
    for (var i = 1; i <= 10; i++) opts += '<option value="' + i + '"' + (i === seats ? ' selected' : '') + '>' + i + '</option>';
    box.innerHTML = '<form class="sb-cv-in" novalidate>'
      + '<div class="sb-cv-hd"><span class="sb-cv-ic" aria-hidden="true">📞</span><div><h3 id="sbCbT">' + esc(tr('cbT')) + '</h3><small>📵 ' + esc(tr('cbSub')) + '</small></div></div>'
      + '<p class="sb-cv-sum" style="display:block;font-size:13px;color:var(--sb-text)">⚠️ <strong>' + esc(tr('cbNotT')) + '</strong> ' + esc(tr('cbNot')) + '</p>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cbRoute')) + '</span><select id="sbCbDir">'
      + '<option value="toNepal"' + (dir === 'toNepal' ? ' selected' : '') + '>' + esc(tr('dirGo')) + '</option>'
      + '<option value="toIndia"' + (dir === 'toIndia' ? ' selected' : '') + '>' + esc(tr('dirBack')) + '</option></select></label>'
      + '<label class="sb-cv-f"><span id="sbCbPtL"></span><select id="sbCbPt"></select></label>'
      + '<div class="sb-cv-2"><label class="sb-cv-f"><span>' + esc(tr('cbDate')) + '</span><input type="date" id="sbCbDate" min="' + t0 + '" value="' + esc(date) + '"></label>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cbSeats')) + '</span><select id="sbCbSeats">' + opts + '</select></label></div>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cbName')) + ' <i>*</i></span><input id="sbCbName" autocomplete="name" maxlength="60" value="' + esc(nm) + '"></label>'
      + '<label class="sb-cv-f"><span>' + esc(tr('cbPhone')) + ' <i>*</i></span><span class="sb-cv-ph"><select id="sbCbCc" aria-label="Country">'
      + '<option value="IN"' + (ccv !== 'NP' ? ' selected' : '') + '>+91</option><option value="NP"' + (ccv === 'NP' ? ' selected' : '') + '>+977</option></select>'
      + '<input id="sbCbPhone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" value="' + esc(phv) + '"></span></label>'
      + '<p class="sb-cv-e" id="sbCbE" role="alert" hidden></p>'
      + '<button type="submit" class="sb-cv-go">' + esc(tr('cbGo')) + '</button>'
      + '<button type="button" class="sb-cv-alt" data-cb="x">' + esc(tr('cbBack')) + '</button>'
      + '</form>';
    cbTownOpts(dir, String(sv.point || ''));
    box.hidden = false; document.body.classList.add('shg-modal-open');
    setTimeout(function () { var f = $(nm.trim().length < 2 ? 'sbCbName' : 'sbCbPhone'); if (f) try { f.focus(); } catch (x) {} }, 60);
  }
  function cbClose() {
    var box = $('sbCb'); if (!box || box.hidden) return;
    box.hidden = true; document.body.classList.remove('shg-modal-open');
  }
  function cbSave() {
    var e = $('sbCbE'), t0 = todayIso();
    var dir = $('sbCbDir').value === 'toIndia' ? 'toIndia' : 'toNepal';
    var pt = String($('sbCbPt').value || '').trim().slice(0, 60);
    var date = String($('sbCbDate').value || ''); if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date < t0) date = '';
    var seats = Math.max(1, Math.min(10, parseInt($('sbCbSeats').value, 10) || 1));
    var name = String($('sbCbName').value || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    var cc = $('sbCbCc').value === 'NP' ? 'NP' : 'IN', ph = cbPhone(cc, $('sbCbPhone').value);
    var bad = name.length < 2 ? 'sbCbName' : (!ph ? 'sbCbPhone' : '');
    if (bad) {
      e.textContent = tr(bad === 'sbCbName' ? 'needName' : (cc === 'NP' ? 'needPhoneNp' : 'needPhoneIn')); e.hidden = false;
      try { $(bad).focus(); } catch (x) {}
      return;
    }
    var nep = cbNepal(), from = dir === 'toNepal' ? (pt || 'Gujarat') : nep, to = dir === 'toNepal' ? nep : (pt || 'Gujarat');
    var note = 'Offline booking request: ' + from + ' -> ' + to + ' ' + (date || 'any date') + ' x' + seats;
    var item = { name: name, phone: ph, country: cc, seats: seats, source: 'callback', lang: lang(), note: (note + ' · saved ' + cbStamp()).slice(0, 250) };
    if (date) item.travelDate = date;
    var q = cbQueue();
    var dup = q.some(function (x) { return x && x.source === 'callback' && x.phone === ph && String(x.note || '').indexOf(note) === 0; });
    if (!dup) {
      q.push(item);
      try { localStorage.setItem(CQ, JSON.stringify(q)); }
      catch (x) {
        var tel = String(((window.SHG_BOOT && window.SHG_BOOT.contact) || {}).phone || '');
        e.innerHTML = esc(tr('cbNoStore')) + (tel ? ' <a href="tel:' + esc(tel.replace(/[^\d+]/g, '')) + '">' + esc(tel) + '</a>' : ''); e.hidden = false;
        return;
      }
      try { window.dispatchEvent(new CustomEvent('shg:cq')); } catch (x) {}
    }
    var shown = (cc === 'NP' ? '+977 ' + ph.slice(3) : '+91 ' + ph);
    $('sbCb').innerHTML = '<div class="sb-cv-in">'
      + '<div class="sb-cv-hd"><span class="sb-cv-ic" aria-hidden="true">✅</span><div><h3 id="sbCbT">' + esc(tr('cbDone')) + '</h3><small data-cb="wait">⏳ ' + esc(tr('cbWait', { n: cbQueue().length })) + '</small></div></div>'
      + '<p class="sb-cv-sum" style="display:block;font-size:13px;color:var(--sb-text)">' + esc(dup ? tr('cbDup') : tr('cbDoneSub', { p: shown })) + '</p>'
      + '<button type="button" class="sb-cv-go" data-cb="x">' + esc(tr('cbClose')) + '</button></div>';
    cbFlush();   // already back online: send it now
  }
  /* P2-4g: the "N waiting" lines follow the queue (a save, a flush, another tab) */
  function cbCount() {
    if (!cbOn()) return;
    var n = cbQueue().length;
    // style.display too: '.sb-cv-hd small{display:block}' would keep a [hidden] "⏳ 0 waiting" line on screen
    root.querySelectorAll('[data-cb="wait"]').forEach(function (el) { el.textContent = '⏳ ' + tr('cbWait', { n: n }); el.hidden = !n; el.style.display = n ? '' : 'none'; });
  }
  window.addEventListener('shg:cq', cbCount);
  window.addEventListener('offline', cbSync);
  window.addEventListener('online', function () {
    if (!cbOn()) return;
    var retry = !cbBooted && cbPanel && cbPanel.style.display !== 'none';
    cbSync(); cbFlush();
    if (retry) { cbFailed = false; boot(); }   // the panel came from a boot that failed offline: try the engine again
  });
  new MutationObserver(function () { cbSync(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });

  window.SmartBook = { quote: function () { return S.q; }, state: S, requote: requote, loadCustomer: loadCustomer, fillText: fillFromText, resetForNext: resetForNext, mode: MODE,
    errors: errs, canBook: canBook, book: book,
    setAgent: function (code) { setAgent(code, true); } };

  boot();
  homeFlag();
  setTimeout(ticketFromHash, 300);
})();
