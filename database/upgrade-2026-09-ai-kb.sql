-- =====================================================================
--  AI knowledge base for the WhatsApp / website assistant (22 Sep 2026).
--  Additive and re-runnable. It gives the office a place to curate the
--  STATIC company knowledge the assistant answers from (policies, rules,
--  FAQs, how-to), separate from the LIVE facts (routes, fares, refund
--  slabs) which the booking tools always read fresh.
--
--  Read by includes/aiknowledge.php (class AiKb) and gated by ai_kb_on
--  (default OFF). The two tables mirror database/upgrade-2026-09-ai-manager.sql
--  so this migration stands on its own even if that one was never applied.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_kb_articles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category VARCHAR(80) NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE,
 canonical_title VARCHAR(200) NOT NULL, canonical_answer TEXT NOT NULL, nepali_content TEXT NULL, hindi_content TEXT NULL,
 english_content TEXT NULL, roman_nepali_examples TEXT NULL, roman_hindi_examples TEXT NULL, keywords TEXT NULL, synonyms TEXT NULL,
 applicable_roles JSON NOT NULL, source_type VARCHAR(40) NOT NULL, source_reference VARCHAR(255) NOT NULL,
 verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified', publication_status VARCHAR(30) NOT NULL DEFAULT 'draft',
 effective_from DATETIME NULL, review_after DATETIME NULL, version INT NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_ai_kb_status(publication_status,verification_status,category), KEY ix_ai_kb_review(review_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_kb_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, version INT NOT NULL,
 snapshot JSON NOT NULL, created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ai_kb_version(article_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_unanswered_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_hash CHAR(64) NOT NULL UNIQUE, normalized_question TEXT NOT NULL,
 language VARCHAR(10) NOT NULL, frequency INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'new',
 article_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY ix_ai_unanswered(status,frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The switch. OFF by default: the assistant does not see the knowledge tool until the office turns it on.
INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
 ('ai_kb_on','0','bool','ai','Assistant knowledge base (answers policy / FAQ / how-to questions)',0);

-- Three starter articles. Each only RESTATES what the assistant already says from its briefing,
-- so it cannot contradict the live system. The office edits these and adds its own in Admin.
INSERT IGNORE INTO ai_kb_articles
 (category, slug, canonical_title, canonical_answer, nepali_content, hindi_content, english_content,
  roman_nepali_examples, roman_hindi_examples, keywords, synonyms, applicable_roles, source_type,
  source_reference, verification_status, publication_status)
VALUES
 ('booking','how-to-book','How do I book a ticket?',
  'You can book on our app or website (shreehariglobal.in): choose the date and route, pick your seat on the live map, and confirm with your mobile number and an OTP — no account needed. You can also just tell me here on WhatsApp and I will help you. Payment is by UPI, eSewa, a payment link a family member can pay, or cash at boarding.',
  'तपाईं हाम्रो app वा website (shreehariglobal.in) बाट बुक गर्न सक्नुहुन्छ: मिति र रुट छान्नुहोस्, live नक्सामा सिट रोज्नुहोस्, अनि मोबाइल नम्बर र OTP ले पक्का गर्नुहोस् — खाता चाहिँदैन। यहीँ WhatsApp मा भन्नुभयो भने पनि म मद्दत गर्छु। भुक्तानी: UPI, eSewa, परिवारले तिर्न मिल्ने payment link, वा बसमै नगद।',
  'आप हमारे app या website (shreehariglobal.in) से बुक कर सकते हैं: तारीख और रूट चुनें, live मैप पर सीट चुनें, और मोबाइल नंबर व OTP से पक्का करें — खाता ज़रूरी नहीं। यहीं WhatsApp पर बताएँ तो भी मैं मदद करता हूँ। भुगतान: UPI, eSewa, परिवार जो भर सके ऐसा payment link, या बस में नकद।',
  'You can book on our app or website (shreehariglobal.in): choose the date and route, pick your seat on the live map, and confirm with your mobile number and an OTP — no account needed. You can also just tell me here on WhatsApp and I will help you. Payment is by UPI, eSewa, a payment link a family member can pay, or cash at boarding.',
  'ticket kasari katne, kasari book garne, booking kasari garne, seat kasari rojne',
  'ticket kaise book kare, booking kaise kare, seat kaise chune',
  'book booking ticket reserve seat how',
  'reserve, buy ticket, kaatnu, katne, booking',
  '["public"]','seed','Restates the assistant briefing (system prompt), 22 Sep 2026 — office may edit','verified','published'),
 ('payment','payment-methods','How can I pay for my ticket?',
  'We accept UPI, eSewa, a shareable payment link (so a family member can pay for you), or cash paid at the time of boarding. The fare is the same online and at the counter.',
  'हामी UPI, eSewa, share गर्न मिल्ने payment link (परिवारको कसैले तपाईंको तर्फबाट तिर्न सक्ने), वा बस चढ्ने बेला नगद स्वीकार गर्छौं। भाडा online र counter मा उस्तै हुन्छ।',
  'हम UPI, eSewa, शेयर करने लायक payment link (ताकि परिवार का कोई आपके लिए भर दे), या बोर्डिंग के समय नकद स्वीकार करते हैं। किराया online और counter पर एक समान है।',
  'We accept UPI, eSewa, a shareable payment link (so a family member can pay for you), or cash paid at the time of boarding. The fare is the same online and at the counter.',
  'kasari tirne, payment kasari garne, paisa kasari tirne, online tirna milcha',
  'payment kaise kare, paise kaise de, online payment',
  'pay payment upi esewa cash link fare',
  'paisa, bhugtan, tirne, pay online',
  '["public"]','seed','Restates the assistant briefing (system prompt), 22 Sep 2026 — office may edit','verified','published'),
 ('route','direct-route','Is it a direct bus with no change?',
  'Yes. We run our own AC sleeper buses direct on the Gujarat–Nepal border route (Rupaidiha), so there is no bus change on the way. The bus, the driver and the counter staff are ours — you deal with the operator directly, not a reseller.',
  'हो। हामी आफ्नै AC sleeper बस गुजरात–नेपाल सीमा (Rupaidiha) रुटमा सिधै चलाउँछौं, त्यसैले बीचमा बस फेर्नु पर्दैन। बस, चालक र counter staff हाम्रै हुन् — तपाईं reseller सँग होइन, सिधै operator सँग कुरा गर्नुहुन्छ।',
  'हाँ। हम अपनी AC sleeper बसें गुजरात–नेपाल सीमा (Rupaidiha) रूट पर सीधे चलाते हैं, इसलिए बीच में बस बदलनी नहीं पड़ती। बस, ड्राइवर और counter staff हमारे अपने हैं — आप reseller से नहीं, सीधे operator से बात करते हैं।',
  'Yes. We run our own AC sleeper buses direct on the Gujarat–Nepal border route (Rupaidiha), so there is no bus change on the way. The bus, the driver and the counter staff are ours — you deal with the operator directly, not a reseller.',
  'direct bus ho ki hoina, bus fernu parcha ki pardaina, sidha jane bus',
  'direct bus hai kya, bus badalni padti hai kya, seedha jane wali bus',
  'direct change transfer sleeper ac route border',
  'no change, seedha, sidha, direct',
  '["public"]','seed','Restates the assistant briefing (system prompt), 22 Sep 2026 — office may edit','verified','published');

-- The common support questions, answered from what the system ACTUALLY does
-- (cancel/refund flow, name fix, date fix, the office-only changes, paid-no-ticket,
-- tracking, fare/offers, languages, cargo). None invents a price, a % or an offer.
INSERT IGNORE INTO ai_kb_articles
 (category, slug, canonical_title, canonical_answer, nepali_content, hindi_content, english_content,
  roman_nepali_examples, roman_hindi_examples, keywords, synonyms, applicable_roles, source_type,
  source_reference, verification_status, publication_status)
VALUES
 ('cancel','cancel-refund-process','How do I cancel and get a refund?',
  'You can ask me here or use My Tickets in the app. The refund follows our published slabs — the closer to departure, the smaller it is — and I will tell you the exact amount BEFORE anything is cancelled, then cancel only after you confirm. The refund goes back to how you paid.',
  'तपाईं यहीँ मलाई भन्न सक्नुहुन्छ वा app को My Tickets प्रयोग गर्नुहोस्। फिर्ता हाम्रो तोकिएको स्ल्याब अनुसार हुन्छ — प्रस्थान जति नजिक, त्यति कम — र केही रद्द गर्नुअघि म तपाईंलाई ठ्याक्कै रकम भन्छु, अनि तपाईंले पक्का गरेपछि मात्र रद्द गर्छु। फिर्ता तपाईंले तिरेकै तरिकामा जान्छ।',
  'आप यहीं मुझे कह सकते हैं या app में My Tickets इस्तेमाल करें। रिफंड हमारे तय स्लैब के अनुसार होता है — प्रस्थान जितना पास, उतना कम — और कुछ रद्द करने से पहले मैं आपको सही रकम बताऊँगा, फिर आपकी पुष्टि पर ही रद्द करूँगा। रिफंड उसी तरीके में लौटता है जिससे आपने भुगतान किया।',
  'You can ask me here or use My Tickets in the app. The refund follows our published slabs — the closer to departure, the smaller it is — and I will tell you the exact amount BEFORE anything is cancelled, then cancel only after you confirm. The refund goes back to how you paid.',
  'ticket cancel kasari garne, cancel garna cha, paisa firta kasari, refund kati',
  'ticket cancel kaise kare, refund kaise milega, paise wapas',
  'cancel cancellation refund slab money back',
  'cancel, refund, firta, wapas, radda',
  '["public"]','seed','Reflects refund_quote then cancel flow, 22 Sep 2026 — office may edit','verified','published'),
 ('correction','name-correction-process','There is a wrong name on my ticket',
  'A wrong name can be fixed before departure — a spelling, or the wrong family member — and the ticket is re-issued and sent to you again. A name fix changes only the name, never the date, seat, bus or fare. It can be done up to twice per booking; after that the office does it.',
  'टिकटमा नाम गलत भए प्रस्थान अघि सच्याउन सकिन्छ — हिज्जे वा गलत परिवारको सदस्य — अनि टिकट फेरि बनाएर पठाइन्छ। नाम सच्याउँदा नाम मात्र बदलिन्छ, मिति, सिट, बस वा भाडा होइन। एउटा बुकिङमा दुई पटकसम्म; त्यसपछि कार्यालयले गर्छ।',
  'टिकट पर गलत नाम प्रस्थान से पहले ठीक हो सकता है — स्पेलिंग या गलत सदस्य — और टिकट दोबारा बनाकर भेजा जाता है। नाम सुधार में सिर्फ नाम बदलता है, तारीख, सीट, बस या किराया नहीं। एक बुकिंग में दो बार तक; उसके बाद ऑफिस करता है।',
  'A wrong name can be fixed before departure — a spelling, or the wrong family member — and the ticket is re-issued and sent to you again. A name fix changes only the name, never the date, seat, bus or fare. It can be done up to twice per booking; after that the office does it.',
  'ticket ma naam galat cha, naam sachyaune, naam milaideu, naam badalna',
  'ticket me naam galat hai, naam sahi karna, naam badalna',
  'name wrong correct spelling rename passenger',
  'naam, name change, correction, sachyaune',
  '["public"]','seed','Reflects rename_passenger, 22 Sep 2026 — office may edit','verified','published'),
 ('correction','date-change-process','Can I change my travel date?',
  'The outbound date can be corrected before departure when the same route, pickup and fare are available on the new date. I will show you the new date and seats and change it only after you say yes. A pickup change, a specific seat, or any fare difference is handled by the office.',
  'प्रस्थान अघि, नयाँ मितिमा उही रुट, पिकअप र भाडा उपलब्ध भए बाहिर जाने मिति सच्याउन सकिन्छ। म नयाँ मिति र सिट देखाउँछु र तपाईंले हुन्छ भनेपछि मात्र बदल्छु। पिकअप परिवर्तन, कुनै खास सिट, वा भाडाको फरक कार्यालयले हेर्छ।',
  'प्रस्थान से पहले, नई तारीख पर वही रूट, पिकअप और किराया उपलब्ध हो तो जाने की तारीख बदली जा सकती है। मैं नई तारीख और सीटें दिखाऊँगा और आपकी हाँ पर ही बदलूँगा। पिकअप बदलाव, कोई खास सीट, या किराये का फर्क ऑफिस देखता है।',
  'The outbound date can be corrected before departure when the same route, pickup and fare are available on the new date. I will show you the new date and seats and change it only after you say yes. A pickup change, a specific seat, or any fare difference is handled by the office.',
  'date change garna cha, miti sarna, arko din ko banaideu',
  'date change karni hai, tarikh badalni, dusre din',
  'date change reschedule travel day',
  'date, miti, tarikh, reschedule',
  '["public"]','seed','Reflects quote_ticket_fix then fix_ticket, 22 Sep 2026 — office may edit','verified','published'),
 ('correction','seat-change-process','Can I change my seat or berth?',
  'A specific seat or berth change is handled at the counter, not automatically — please contact the office and they will help if a seat is free. Your current seat is printed on your ticket.',
  'खास सिट वा बर्थ परिवर्तन counter मा हुन्छ, स्वतः होइन — कृपया कार्यालयमा सम्पर्क गर्नुहोस्, सिट खाली भए मिलाइदिन्छन्। तपाईंको हालको सिट टिकटमा छापिएको छ।',
  'खास सीट या बर्थ बदलाव counter पर होता है, अपने आप नहीं — कृपया ऑफिस से संपर्क करें, सीट खाली हो तो मदद करेंगे। आपकी मौजूदा सीट टिकट पर छपी है।',
  'A specific seat or berth change is handled at the counter, not automatically — please contact the office and they will help if a seat is free. Your current seat is printed on your ticket.',
  'seat change garna milcha, sit sarna, berth badalna',
  'seat change ho sakti hai, sit badalni, berth badalna',
  'seat berth change swap counter',
  'seat, sit, berth, change',
  '["public"]','seed','Reflects office-only berth changes, 22 Sep 2026 — office may edit','verified','published'),
 ('payment','paid-no-ticket-process','I paid but did not get my ticket',
  'The ticket is issued the moment your payment is verified. If you have paid and it has not arrived yet, please do NOT pay again — send me your booking reference and I will check, or the office will confirm it for you.',
  'तपाईंको भुक्तानी पुष्टि हुनासाथ टिकट बन्छ। तिरिसक्नुभयो तर आइपुगेको छैन भने कृपया फेरि नतिर्नुहोस् — मलाई आफ्नो बुकिङ नम्बर पठाउनुहोस्, म जाँच्छु, वा कार्यालयले पुष्टि गरिदिन्छ।',
  'भुगतान सत्यापित होते ही टिकट बन जाता है। यदि आपने भुगतान कर दिया और नहीं आया, तो कृपया दोबारा भुगतान न करें — मुझे अपना बुकिंग नंबर भेजें, मैं जाँचता हूँ, या ऑफिस पुष्टि कर देगा।',
  'The ticket is issued the moment your payment is verified. If you have paid and it has not arrived yet, please do NOT pay again — send me your booking reference and I will check, or the office will confirm it for you.',
  'paisa tire tara ticket aayena, payment gare ticket aayena, paisa katyo ticket chaina',
  'payment kiya ticket nahi aaya, paise kat gaye ticket nahi aaya',
  'paid payment ticket not received missing verify',
  'paid, payment, not received, aayena',
  '["public"]','seed','Reflects verify-then-issue and idempotency, 22 Sep 2026 — office may edit','verified','published'),
 ('tracking','track-bus-process','Where is my bus? Can I track it?',
  'Yes. Once the driver phone is live you can see the bus position and roughly how many minutes to your pickup. Ask me here with your booking reference, or open the live map in the app.',
  'हो। चालकको फोन live भएपछि बसको स्थिति र तपाईंको पिकअपसम्म करिब कति मिनेट बाँकी छ हेर्न सकिन्छ। यहीँ बुकिङ नम्बर सहित सोध्नुहोस्, वा app को live नक्सा खोल्नुहोस्।',
  'हाँ। ड्राइवर का फोन live होने पर बस की स्थिति और आपके पिकअप तक करीब कितने मिनट बाकी हैं देख सकते हैं। यहीं बुकिंग नंबर के साथ पूछें, या app का live मैप खोलें।',
  'Yes. Once the driver phone is live you can see the bus position and roughly how many minutes to your pickup. Ask me here with your booking reference, or open the live map in the app.',
  'bus kaha pugyo, bus track garna, bus kati ber ma aaucha',
  'bus kaha hai, bus track karna, bus kitni der me',
  'track live bus location eta where pickup',
  'track, kaha, location, live',
  '["public"]','seed','Reflects bus_eta / live map, 22 Sep 2026 — office may edit','verified','published'),
 ('fare','online-discount-offers','Is there a discount for booking online?',
  'Our fare is the same online and at the counter. We do not run a standing discount; if there is ever an offer, it is shown when you book. I will never make up a price or a discount — for the exact fare on your date, just tell me your route and date.',
  'हाम्रो भाडा online र counter मा उस्तै हो। हामी नियमित छुट दिँदैनौं; कहिल्यै offer भए बुक गर्दा देखिन्छ। म कहिल्यै भाउ वा छुट बनाउँदिन — तपाईंको मितिको ठ्याक्कै भाडाका लागि रुट र मिति भन्नुहोस्।',
  'हमारा किराया online और counter पर एक समान है। हम नियमित छूट नहीं देते; कभी offer हो तो बुकिंग के समय दिखता है। मैं कभी दाम या छूट नहीं बनाता — आपकी तारीख का सही किराया चाहिए तो रूट और तारीख बताएँ।',
  'Our fare is the same online and at the counter. We do not run a standing discount; if there is ever an offer, it is shown when you book. I will never make up a price or a discount — for the exact fare on your date, just tell me your route and date.',
  'online book garda discount cha, chhut cha ki, offer cha',
  'online booking par discount, chhut hai kya, offer hai kya',
  'discount offer online fare price same counter',
  'discount, chhut, offer, deal',
  '["public"]','seed','Reflects fixed fare + no invented offer rule, 22 Sep 2026 — office may edit','verified','published'),
 ('company','languages-supported','Which languages can I use?',
  'You can talk to us in Nepali, Hindi or English, including romanised typing. The app, the website and this chat all work in those languages.',
  'तपाईं नेपाली, हिन्दी वा अङ्ग्रेजीमा — romanised टाइप गरेर पनि — कुरा गर्न सक्नुहुन्छ। app, website र यो chat सबै ती भाषामा चल्छन्।',
  'आप नेपाली, हिंदी या अंग्रेज़ी में — romanised टाइप करके भी — बात कर सकते हैं। app, website और यह chat सभी इन भाषाओं में चलते हैं।',
  'You can talk to us in Nepali, Hindi or English, including romanised typing. The app, the website and this chat all work in those languages.',
  'kun bhasha ma kura garna milcha, nepali ma jawaf, hindi ma',
  'kaun si bhasha, nepali me baat, hindi me',
  'language nepali hindi english bhasha roman',
  'language, bhasha, nepali, hindi',
  '["public"]','seed','Reflects en/hi/ne support, 22 Sep 2026 — office may edit','verified','published'),
 ('logistics','cargo-import-export','Do you handle cargo or import-export?',
  'Yes, we also do import and export logistics on the Gujarat–Nepal route. Tell me what you need and I will connect you to the office — freight rates are quoted by the office, not here.',
  'हो, हामी गुजरात–नेपाल रुटमा import र export logistics पनि गर्छौं। तपाईंलाई के चाहिन्छ भन्नुहोस्, म कार्यालयमा जोडिदिन्छु — ढुवानी भाडा कार्यालयले भन्छ, यहाँ होइन।',
  'हाँ, हम गुजरात–नेपाल रूट पर import और export logistics भी करते हैं। आपको क्या चाहिए बताएँ, मैं ऑफिस से जोड़ दूँगा — भाड़ा ऑफिस बताता है, यहाँ नहीं।',
  'Yes, we also do import and export logistics on the Gujarat–Nepal route. Tell me what you need and I will connect you to the office — freight rates are quoted by the office, not here.',
  'cargo pathauna milcha, saman pathaune, import export garna',
  'cargo bhejna hai, saman bhejna, import export',
  'cargo freight import export logistics goods',
  'cargo, saman, freight, logistics',
  '["public"]','seed','Reflects marketing rule H (cargo to office), 22 Sep 2026 — office may edit','verified','published');
