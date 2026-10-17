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
      booking: 'Booking…', making: 'Preparing your ticket…', ticketOk: 'Ticket confirmed', codNote: 'Cash — pay at the boarding counter', pnr: 'PNR', dlPdf: 'Download PDF', dlPng: 'Download PNG',
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
      booking: 'बुक हो रहा है…', making: 'टिकट तैयार हो रहा है…', ticketOk: 'टिकट पक्का', codNote: 'कैश — चढ़ते समय काउंटर पर भुगतान', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
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
      booking: 'बुक हुँदैछ…', making: 'टिकट तयार हुँदैछ…', ticketOk: 'टिकट पक्का भयो', codNote: 'नगद — चढ्दा काउन्टरमा तिर्ने', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
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
  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in OW[l]) { W[l][k] = OW[l][k]; } } });
  function tr(k, v) {
    var s = (W[lang()] && W[lang()][k]) || W.en[k] || k;
    if (v) Object.keys(v).forEach(function (x) { s = s.split('{' + x + '}').join(v[x]); });
    return s;
  }
  function L3(o) { if (!o) return ''; return o[lang()] || o.en || ''; }
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
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
    quick: true,
    agent: '',  // staff desks only: the SHG code the sale is credited to ('OTHER' = company) - never on the customer page
    /* Cash verification (customer only) - kept in memory for this visit, never
       in localStorage (an ID number must not sit on a shared phone). */
    codVerify: false, idTypes: [], qrSeconds: 60,
    verify: { address: '', idType: '', idNum: '', altPhone: '', altCc: 'NP' }
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
      })
      .catch(function (e) {
        // the engine is unreachable: the classic search card takes over
        document.documentElement.classList.remove('sb-on');
        root.hidden = true;
        console.warn('SmartBook boot failed', e);
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
    seg('sbType', S.type); seg('sbCabin', S.cabin); seg('sbBerth', S.berth); seg('sbSeatMode', S.seatMode);
    $('sbCabin').hidden = S.type !== 'private';
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
  function errs() {
    var e = {};
    if (STAFF && STAFF.agentRequired && $('sbAgent') && !S.agent) e.agent = tr('needAgent');
    var pe = phoneErr(); if (pe) e.phone = pe;
    if (String(S.name || '').trim().length < 2) e.name = tr('needName');
    if (S.pax.some(function (p) { return !p.gender; })) e.pax = tr('needGender');
    return e;
  }
  function showErrs(all) {
    var e = errs();
    var f = [['phone', 'sbPhone', 'sbPhoneE'], ['name', 'sbName', 'sbNameE'], ['pax', null, 'sbPaxE']];
    if ($('sbAgent')) f.unshift(['agent', 'sbAgent', 'sbAgentE']);
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
  function requote(ms) {
    clearTimeout(qTimer);
    S.idem = null;   // anything changed → a new booking intent
    qTimer = setTimeout(doQuote, ms == null ? 260 : ms);
  }
  function quoteBody() {
    return { action: 'quote', direction: S.dir, date: S.date, point: S.point, type: S.type, cabin: S.cabin, berth: S.berth,
      pax: S.pax.map(function (p) { return { gender: p.gender, band: p.band, name: String(p.name || '').trim().slice(0, 60) }; }),
      seats: S.seatMode === 'manual' ? S.manual : [], phone: digits(S.phone) };
  }
  function doQuote() {
    var my = ++qSeq;
    $('sbSeatLine').innerHTML = '<span class="sb-skel sb-skel-w"></span>';
    syncButtons();
    api(quoteBody())
      .then(function (q) {
        if (my !== qSeq) return;
        var flash = S.q && q.seats && S.q.seats && q.seats.join() !== S.q.seats.join();
        S.q = q;
        if (!S.date && q.date) { /* next departure chosen by the engine */ }
        renderDates(); renderQuote(flash);
        syncButtons(); quoted();
      })
      .catch(function (e) {
        if (my !== qSeq) return;
        S.q = null;
        $('sbSeatLine').textContent = e && e.message ? e.message : '—';
        renderQuote(); syncButtons(); quoted();
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
    var tot = q && q.ok && q.price ? money(q.price.total) : '—';
    $('sbTotal').textContent = tot;
    $('sbNpr').textContent = q && q.ok && q.price ? '≈ ' + npr(q.price.npr) : '';
    $('sbBarTotal').textContent = tot;
    $('sbBarSeats').textContent = q && q.ok ? (seatSummary(q) + (q.price ? ' · ≈ ' + npr(q.price.npr) : '')) : (q && q.error ? q.error : tr('loading'));
    renderQLine();
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
  $('sbPhone').addEventListener('input', function () { S.phone = this.value; S.idem = null; store.set(); showErrs(false); syncButtons(); });
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

  function nearMe() {
    if (!navigator.geolocation) { say(tr('geoNo')); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var best = null;
      pointList().forEach(function (p) {
        if (p.lat == null || p.lng == null) return;
        var dLat = (p.lat - pos.coords.latitude) * Math.PI / 180, dLng = (p.lng - pos.coords.longitude) * Math.PI / 180;
        var a = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(pos.coords.latitude * Math.PI / 180) * Math.cos(p.lat * Math.PI / 180) * Math.pow(Math.sin(dLng / 2), 2);
        var km = 12742 * Math.asin(Math.min(1, Math.sqrt(a)));
        if (!best || km < best.km) best = { name: p.name, km: Math.round(km), short: p.short };
      });
      if (!best) { say(tr('geoNo')); return; }
      S.point = best.name; $('sbPoint').value = best.name;
      $('sbPointWhy').textContent = '📍 ' + (best.short || best.name) + ' · ~' + best.km + ' km';
      changed();
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
      $('sbSheetOk').onclick = function () {
        if (pick.length !== need) { say(tr('pickAll')); return; }
        S.manual = pick.slice(); S.seatMode = 'manual'; seg('sbSeatMode', 'manual'); closeSheet(); changed();
      };
    }).catch(function (er) { say(er.message); });
  }
  function closeSheet() { $('sbSheet').hidden = true; document.body.classList.remove('shg-modal-open'); }
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
        r.left.forEach(function (s) { h += cell(s); });
        h += '<span class="sb-aisle"></span>';
        r.right.forEach(function (s) { h += cell(s); });
        h += '</div>';
      });
      h += '</div>';
    });
    function cell(s) {
      var cls = 'sb-berth' + (fem[s] ? ' w' : '') + (!free[s] ? ' x' : '') + (pick.indexOf(s) >= 0 ? ' sel' : '');
      return '<button type="button" class="' + cls + '" data-seat="' + esc(s) + '"' + (!free[s] ? ' disabled aria-disabled="true"' : '') + ' aria-pressed="' + (pick.indexOf(s) >= 0) + '">' + esc((m.labels || {})[s] || s) + '</button>';
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
    S.touched.book = S.touched.phone = S.touched.name = S.touched.pax = S.touched.agent = true;
    var e = showErrs(true);
    if (Object.keys(e).length) {
      var k = Object.keys(e)[0], el = k === 'phone' ? $('sbPhone') : (k === 'name' ? $('sbName') : (k === 'agent' ? $('sbAgent') : $('sbPax')));
      say(e[k]);
      try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (x) {}
      if (k !== 'pax') el.focus({ preventScroll: true }); else { var g = el.querySelector('.sb-g.u'); if (g) g.focus({ preventScroll: true }); }
      return;
    }
    if (S.busy) return;
    if (!S.q || !S.q.ok) { say(S.q && S.q.error ? S.q.error : tr('loading')); if (!S.q) requote(0); return; }
    // Cash for a customer = pay at boarding: the verification sheet first (it calls back with the details).
    if (pay === 'cash' && S.codVerify && !verify) { openCash(); return; }
    if (pay === 'cash' && tap && !verify && !STAFF) { openOk(wantPrint); return; }   // a desk books in one click
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
    store.set();
    api(body).then(function (r) {
      S.busy = false; btn.classList.remove('sb-busy'); lbl.textContent = old;
      closeCash();
      adopt(r);
      S.idem = null;
      if (STAFF && r.agent) say('🤝 ' + r.agent.code + ' · ' + r.agent.name + (r.commission ? ' · 💰 ' + money(r.commission) : ''));
      else if (STAFF && r.commission) say('💰 Commission ' + money(r.commission));
      if (r.status === 'confirmed') {
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
  var printNext = false;
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
  /* The booking at a glance - the one summary both Cash sheets lead with. */
  function sumHtml() {
    var q = S.q || {}, drop = q.pointType === 'drop';
    var rows = [
      [tr('sRoute'), (q.from || '') + ' → ' + (q.to || '')],
      [tr('sDate'), (q.dateLabel || '') + bsFull(q.date, ' · ') + (q.depTime ? ' · ' + q.depTime : '')],
      [tr(drop ? 'drop' : 'sBoard'), drop ? (q.point || '') : (q.boardingName || '') + (q.boardingTime ? ' · ' + q.boardingTime : '')],
      [tr('sName'), S.name.trim() + ' · ' + tr('paxN', { n: S.pax.length })],
      [tr('sMobile'), (S.cc === 'NP' ? '+977 ' : '+91 ') + digits(S.phone)],
      [tr('sSeats'), seatSummary(q)]
    ];
    if (STAFF && (S.agent || STAFF.ownCode)) rows.push([tr('credited'), S.agent === 'OTHER' ? (STAFF.company || '') : (S.agent || STAFF.ownCode)]);
    return '<div class="sb-ok-sum"><div class="sb-ok-tot"><small>' + esc(tr('total')) + '</small><b>' + esc(q.price ? money(q.price.total) : '—') + '</b>'
      + (q.price ? '<span>≈ ' + esc(npr(q.price.npr)) + '</span>' : '') + '</div><dl>'
      + rows.filter(function (r) { return String(r[1] || '').replace(/[\s·→+]/g, '') !== ''; })
          .map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('')
      + '</dl></div>';
  }
  /* Cash with no boarding check to fill (every desk, or a customer site with the
     check switched off): the summary and ONE Confirm button - Enter confirms. */
  var okPrint = false;
  function openOk(wantPrint) {
    var box = cashSheet(), q = S.q || {};
    var tot = q.price ? money(q.price.total) : '';
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
    book('cash', okPrint);
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
    book('cash', false, { address: v.address, idType: v.idType, idNum: v.idNum, altPhone: v.altPhone, altCountry: v.altCc });
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
    var m = /^SHG(\d{1,4})$/.exec(d) || /^(\d{1,4})$/.exec(d);
    return m ? 'SHG-' + ('0000' + m[1]).slice(-4) : '';
  }
  function agentByCode(code) { for (var i = 0; i < AGENTS.length; i++) { if (AGENTS[i].code === code) return AGENTS[i]; } return null; }
  function agentLine(a) { return a.code + ' · ' + a.name + (a.city ? ' · ' + a.city : ''); }
  function agentRate(a) { if (!STAFF || !STAFF.commFlat) return ''; return money(a && a.type === 'joint' ? STAFF.commJoint : STAFF.commDirect) + '/pax'; }
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
      return '<option value="' + esc(a.code) + '">' + esc((a.co ? '🏢 ' : '👤 ') + a.name + (a.city ? ' · ' + a.city : '') + (rate ? ' · ' + rate : '')) + '</option>';
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
  if (STAFF) {
    initAgent();
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
      + '<div class="sb-tk-row">' + esc((r.seatLabels || []).join(', ')) + ' · ' + esc(r.totalLabel || '') + ' <small>(≈ ' + esc(npr(r.npr)) + ')</small></div>'
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
    try { if (!STAFF && location.hash.indexOf('#/?ticket=') !== 0) history.replaceState(null, '', '#/?ticket=' + encodeURIComponent(r.pnr)); } catch (e) {}
    /* 15 Oct 2026: the PDF no longer downloads by itself - on a phone that
       navigation closed the ticket the customer had just been shown. The PNG
       on the page is the delivery (the server pre-draws it while the booking
       answer is still in flight); Share / Download are one tap away. */
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
    if (act === 'new') { closeTicket(); S.manual = []; S.seatMode = 'auto'; S.date = ''; fill(); requote(0); window.scrollTo({ top: root.offsetTop - 60, behavior: 'smooth' }); return; }
    if (!r) return;
    if (act === 'copy') { try { navigator.clipboard.writeText(r.pnr).then(function () { say(tr('copied')); }); } catch (x) { say(r.pnr); } return; }
    if (act === 'share') {
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
  // While the card is on screen the floating WhatsApp / Sahayak buttons step aside (phone).
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) {
      document.documentElement.classList.toggle('sb-in-view', !!(en[0] && en[0].isIntersecting));
    }, { threshold: 0.25 }).observe($('sbCard'));
  }
  new MutationObserver(function () { applyWords(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
  /* Staff: clear the card for the next customer after a sale (keeps the date / pickup / type). */
  function resetForNext() {
    S.phone = ''; S.name = ''; S.pax = [{ gender: '', band: 'adult' }]; S.manual = []; S.seatMode = 'auto'; S.idem = null; S.touched = {};
    S.why = {}; fill(); applyWords(); store.set(); showErrs(false); requote(0);
  }
  window.SmartBook = { quote: function () { return S.q; }, state: S, requote: requote, loadCustomer: loadCustomer, fillText: fillFromText, resetForNext: resetForNext, mode: MODE,
    errors: errs, canBook: canBook, book: book,
    setAgent: function (code) { setAgent(code, true); } };

  boot();
  homeFlag();
  setTimeout(ticketFromHash, 300);
})();
