-- =====================================================================
--  WhatsApp: everyday questions answered on the VPS (23 Sep 2026).
--  Additive and re-runnable. Switch OFF by default (house rule) — turned on
--  separately after deploy. Read by includes/wafaq.php.
--
--  NOTE for apply-sql.php: it splits on semicolons, so no text below may
--  contain one.
-- =====================================================================

INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,label,is_public) VALUES
 ('wa_faq_on','0','bool','ai','WhatsApp: answer everyday questions (fare, time, offers, website, office, FAQ) on the VPS without an AI call',0);

-- Two facts the assistant already states in its briefing (includes/aiprompt.php), now in the
-- knowledge base so the VPS can answer them without the model.
INSERT IGNORE INTO ai_kb_articles
 (category, slug, canonical_title, canonical_answer, nepali_content, hindi_content, english_content,
  roman_nepali_examples, roman_hindi_examples, keywords, synonyms, applicable_roles, source_type,
  source_reference, verification_status, publication_status)
VALUES
 ('luggage','luggage-allowance','How much luggage can I carry?',
  'Each passenger may carry 1 suitcase (up to 20 kg) and 1 cabin bag free. Extra luggage is charged — ask the counter for the rate.',
  'हरेक यात्रुले १ सुटकेस (२० केजीसम्म) र १ क्याबिन झोला निःशुल्क लैजान पाउनुहुन्छ। थप सामानको शुल्क लाग्छ — दर counter मा सोध्नुहोस्।',
  'हर यात्री 1 सूटकेस (20 किलो तक) और 1 केबिन बैग मुफ्त ले जा सकता है। ज़्यादा सामान का शुल्क लगता है — दर counter पर पूछें।',
  'Each passenger may carry 1 suitcase (up to 20 kg) and 1 cabin bag free. Extra luggage is charged — ask the counter for the rate.',
  'luggage kati lana milcha, saman kati kg lana milcha, jhola kati bokna milcha, luggage kati kg',
  'kitna saman le ja sakte, luggage kitna allowed, kitne kg saman',
  'luggage baggage bag suitcase kg weight allowance jhola',
  'luggage, baggage, jhola, suitcase, kg',
  '["public"]','seed','Restates the assistant briefing (aiprompt.php luggage line), 23 Sep 2026 — office may edit','verified','published'),
 ('border','border-documents','What documents do I need at the border?',
  'No visa is needed for Indian or Nepali citizens, but photo ID is checked at the Rupaidiha–Jamunaha border: passport or voter ID for Indians, citizenship certificate or passport for Nepalis. The check takes about 20–40 minutes and the bus waits for everyone. Indian 200 and 500 rupee notes are not accepted in Nepal.',
  'भारतीय र नेपाली नागरिकलाई भिसा चाहिँदैन, तर रुपैडिहा–जमुनाहा सीमामा फोटो परिचयपत्र जाँचिन्छ: भारतीयका लागि passport वा voter ID, नेपालीका लागि नागरिकता प्रमाणपत्र वा passport। जाँचमा करिब २०–४० मिनेट लाग्छ र बसले सबैलाई पर्खन्छ। भारतीय २०० र ५०० का नोट नेपालमा चल्दैनन्।',
  'भारतीय और नेपाली नागरिकों को वीज़ा नहीं चाहिए, पर रुपईडीहा–जमुनहा सीमा पर फोटो पहचान पत्र जाँचा जाता है: भारतीयों के लिए passport या voter ID, नेपालियों के लिए नागरिकता प्रमाणपत्र या passport। जाँच में करीब 20–40 मिनट लगते हैं और बस सबका इंतज़ार करती है। भारतीय 200 और 500 के नोट नेपाल में नहीं चलते।',
  'No visa is needed for Indian or Nepali citizens, but photo ID is checked at the Rupaidiha–Jamunaha border: passport or voter ID for Indians, citizenship certificate or passport for Nepalis. The check takes about 20–40 minutes and the bus waits for everyone. Indian 200 and 500 rupee notes are not accepted in Nepal.',
  'border ma k document chahincha, nagarikta chahincha, passport chahincha, visa chahincha, kagaj k chahincha, border ma id',
  'border par kya document chahiye, passport chahiye kya, visa chahiye kya, id proof',
  'border document documents id passport visa citizenship voter nagarikta kagaj notes',
  'document, kagaj, id, passport, visa, nagarikta, border',
  '["public"]','seed','Restates the assistant briefing (aiprompt.php border line), 23 Sep 2026 — office may edit','verified','published');
