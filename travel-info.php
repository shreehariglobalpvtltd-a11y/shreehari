<?php
/**
 * Standalone travel-info page: India <-> Nepal border at Rupaidiha / Nepalgunj
 * (2 Oct 2026). Documents, the crossing, money, customs, a checklist and an FAQ
 * in English, Hindi and Nepali.
 *
 * All three languages are server-rendered; a [data-lang] attribute on <html>
 * shows one of them (no fetch, no SPA bundle). ?lang=en|hi|ne picks the
 * server-side default, then the SPA's own 'shg:lang' localStorage key and the
 * phone's language. Contact numbers come from Settings (one source), never
 * typed here. Served at /travel-info (nginx exact location / dev router) and
 * at /travel-info.php. Keep it under 40 KB: Devanagari is 3 bytes a letter.
 */
declare(strict_types=1);
if (!defined('SHG_APP')) { define('SHG_APP', true); }
require_once __DIR__ . '/includes/bootstrap.php';

$baseUrl = rtrim(APP_URL, '/');
$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$lang = in_array($_GET['lang'] ?? '', ['en', 'hi', 'ne'], true) ? (string) $_GET['lang'] : 'en';

$co     = Settings::company();
$phone  = Settings::officePhone();
$tel    = preg_replace('/[^0-9+]/', '', $phone) ?? '';
$waDig  = Settings::officeWhatsApp();
$waShow = strlen($waDig) === 12 && str_starts_with($waDig, '91')
    ? '+91 ' . substr($waDig, 2, 5) . ' ' . substr($waDig, 7)
    : '+' . $waDig;
$peg    = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);

/* company_counters ("Town +91 NNNNN NNNNN · Town …") -> tap-to-call links. */
$counters = '';
foreach (preg_split('/\s*·\s*/u', (string) $co['counters']) ?: [] as $part) {
    $part = trim($part);
    if (preg_match('/^(.*?)\s*(\+?\d[\d\s-]{7,})$/u', $part, $m)) {
        $counters .= '<li>' . $e($m[1]) . ' <a href="tel:' . $e(preg_replace('/[^0-9+]/', '', $m[2])) . '">' . $e(trim($m[2])) . '</a></li>';
    } elseif ($part !== '') {
        $counters .= '<li>' . $e($part) . '</li>';
    }
}

/* ---------------------------------------------------------------------
 *  Words. Strings are trusted HTML written here; {PEGTXT} is filled in.
 * ------------------------------------------------------------------- */
$C = [
'en' => [
    'title' => 'Travel Info — India ⇄ Nepal Border (Rupaidiha / Nepalgunj) | ' . $co['name'],
    'h' => 'Travel Info — India ⇄ Nepal border',
    'sub' => 'Rupaidiha (India) ⇄ Nepalgunj (Nepal) · read before you travel',
    'back' => '← Back to Home', 'book' => 'Book a seat', 'wa' => 'WhatsApp us', 'call' => 'Call',
    'waText' => 'Hello, travel info question',
    'alert' => '<b>Rules change.</b> Confirm the latest rules with immigration or call our office before you travel. This page is general guidance, not legal advice.',
    'pegTxt' => 'The NPR amount uses the fixed rate ₹1 = NPR {PEG}.',
    'docsH' => 'Documents to carry',
    'docs' => [
        ['Indian citizens', 'A valid <b>Indian passport</b> or <b>Voter ID (EPIC)</b> — what Nepal immigration commonly accepts. <b>Aadhaar alone is often not accepted</b>, so do not travel with only Aadhaar. No visa needed.'],
        ['Nepali citizens', '<b>Citizenship certificate (नागरिकता)</b> or <b>Nepali passport</b>. No visa needed for India.'],
        ['Children &amp; minors', 'Carry a birth certificate or school photo ID. A child should travel with a parent or guardian who has their own valid ID.'],
        ['Foreign nationals (not Indian or Nepali)', 'Valid passport and visa needed, with exit and entry stamps from Indian and Nepal immigration — without these stamps the open border is not for you. Check visa rules with the embassy before booking.'],
    ],
    'docsNote' => 'Carry originals plus a photocopy; book in the name on your ID. Documents are the passenger’s responsibility — if entry is refused, our <a href="/refund-policy">refund policy</a> applies.',
    'borderH' => 'At the border: Rupaidiha ⇄ Nepalgunj',
    'border' => [
        'Our coach ends at <b>Rupaidiha</b>, on the Indian side. Your ticket shows the exact drop point.',
        'Cross on foot or by e-rickshaw / tanga. ID and bags may be checked at the Indian post (SSB) and the Nepal post at Jamunaha.',
        'Nepalgunj town is a short ride beyond the border — agree the fare before you get in.',
        'Nepal time is 15 minutes ahead of Indian time.',
        'Going to Gujarat? The coach leaves from Rupaidiha: cross early — queues can be long — and reach the pickup by the reporting time on your ticket.',
    ],
    'moneyH' => 'Money &amp; currency',
    'money' => [
        'Fares are in Indian rupees (₹). INR is widely accepted near the border.',
        'Carry small INR notes — large notes may be refused on the Nepal side.',
        'Paying from Nepal? Use eSewa (QR on our payment page). {PEGTXT}',
    ],
    'customsH' => 'Customs basics',
    'customs' => [
        'Personal luggage only. Declare goods for sale, gold or jewellery beyond personal use, and large amounts of cash.',
        'Never carry narcotics, weapons, wildlife products, fake goods — or a parcel for a stranger.',
        'Keep bills for new phones, electronics and jewellery.',
    ],
    'checkH' => 'Before you travel — checklist',
    'check' => [
        'Original photo ID (passport / Voter ID / नागरिकता) + a photocopy',
        'Ticket image or PNR (SHG-…) saved on your phone',
        'Phone charged; a power bank helps',
        'At the pickup by the reporting time on your ticket — at least 30 min early',
        'Small INR notes, water and your medicines',
        'Luggage: 1 bag up to 20 kg + 1 cabin bag up to 7 kg free (<a href="/#/terms">Terms</a>). Keep valuables and documents with you.',
        'Women travelling alone: berths reserved for women are marked on the seat map — ask the office for help.',
    ],
    'faqH' => 'Frequently asked questions',
    'faq' => [
        ['Is Aadhaar enough to cross into Nepal?', 'Usually not. Nepal immigration often does not accept Aadhaar on its own. Carry a valid Indian passport or Voter ID (EPIC).'],
        ['Do children need ID?', 'Yes — a birth certificate or school photo ID for each child, travelling with a parent or guardian who has valid ID. Rules differ by age; confirm before travel.'],
        ['How long does the border crossing take?', 'Usually not long, but it varies — queues grow at festivals, holidays and rush hours. Keep spare time, especially to catch our coach at Rupaidiha.'],
        ['Can I pay in Nepali rupees?', 'Fares are in ₹. From Nepal, pay by eSewa (QR on the payment page). {PEGTXT} UPI and cash at our counters also work — ask the office before paying cash in NPR.'],
        ['What if I miss the bus?', 'Call or WhatsApp the office at once. A no-show without cancelling gets no refund — see our <a href="/refund-policy">Refund &amp; Cancellation Policy</a>.'],
        ['How do I find my ticket?', 'Open the ticket link in our WhatsApp message, or <a href="/#/my">My Trips</a> on this website on the phone you booked with. Or WhatsApp / call the office with your PNR (SHG-…) or mobile number.'],
    ],
    'contactH' => 'Contact us', 'contactLead' => 'Not sure about a document or rule? Ask us before you travel.',
    'counters' => 'Counters', 'reviewed' => 'Last reviewed: 2 October 2026',
],
'hi' => [
    'title' => 'यात्रा जानकारी — भारत ⇄ नेपाल बॉर्डर (रूपईडीहा / नेपालगंज) | ' . $co['name'],
    'h' => 'यात्रा जानकारी — भारत ⇄ नेपाल बॉर्डर',
    'sub' => 'रूपईडीहा (भारत) ⇄ नेपालगंज (नेपाल) · यात्रा से पहले ज़रूर पढ़ें',
    'back' => '← होम पर वापस', 'book' => 'सीट बुक करें', 'wa' => 'WhatsApp करें', 'call' => 'कॉल करें',
    'waText' => 'नमस्ते, यात्रा जानकारी चाहिए',
    'alert' => '<b>नियम बदलते रहते हैं।</b> यात्रा से पहले इमिग्रेशन या हमारे ऑफ़िस से ताज़ा नियम पक्का करें। यह सामान्य जानकारी है, कानूनी सलाह नहीं।',
    'pegTxt' => 'NPR राशि तय दर ₹1 = NPR {PEG} से बनती है।',
    'docsH' => 'साथ रखने वाले दस्तावेज़',
    'docs' => [
        ['भारतीय नागरिक', 'वैध <b>भारतीय पासपोर्ट</b> या <b>वोटर ID (EPIC)</b> — नेपाल इमिग्रेशन आमतौर पर यही मानता है। <b>सिर्फ आधार अक्सर मान्य नहीं होता</b>, इसलिए केवल आधार लेकर न जाएँ। वीज़ा नहीं चाहिए।'],
        ['नेपाली नागरिक', '<b>नागरिकता प्रमाणपत्र</b> या <b>नेपाली पासपोर्ट</b>। भारत के लिए वीज़ा नहीं चाहिए।'],
        ['बच्चे / नाबालिग', 'जन्म प्रमाणपत्र या स्कूल का फोटो ID रखें। बच्चा माता-पिता या अभिभावक के साथ हो, जिनके पास अपनी वैध ID हो।'],
        ['विदेशी नागरिक (भारतीय या नेपाली नहीं)', 'वैध पासपोर्ट और वीज़ा ज़रूरी है, साथ में भारत और नेपाल इमिग्रेशन की निकास/प्रवेश मुहर — इन मुहरों के बिना खुला बॉर्डर आपके लिए नहीं है। बुकिंग से पहले दूतावास से वीज़ा नियम पता करें।'],
    ],
    'docsNote' => 'मूल दस्तावेज़ और एक फोटोकॉपी रखें; टिकट ID वाले नाम से बुक करें। सही दस्तावेज़ यात्री की ज़िम्मेदारी है — प्रवेश न मिले तो हमारी <a href="/refund-policy">रिफंड नीति</a> लागू होती है।',
    'borderH' => 'बॉर्डर पर: रूपईडीहा ⇄ नेपालगंज',
    'border' => [
        'हमारी बस भारतीय तरफ़ <b>रूपईडीहा</b> में रुकती है। उतरने की सही जगह टिकट पर लिखी है।',
        'पैदल या ई-रिक्शा / तांगे से बॉर्डर पार करें। भारतीय चौकी (SSB) और नेपाल की जमुनाहा चौकी पर ID और सामान की जाँच हो सकती है।',
        'नेपालगंज शहर बॉर्डर से थोड़ी दूरी पर है — बैठने से पहले किराया तय करें।',
        'नेपाल का समय भारत से 15 मिनट आगे है।',
        'गुजरात जाना है? बस रूपईडीहा से चलती है: जल्दी पार करें — कतार लंबी हो सकती है — और टिकट पर लिखे रिपोर्टिंग समय तक पिकअप पर पहुँचें।',
    ],
    'moneyH' => 'पैसा और मुद्रा',
    'money' => [
        'किराया भारतीय रुपये (₹) में है। बॉर्डर के पास भारतीय रुपया ख़ूब चलता है।',
        'छोटे भारतीय नोट रखें — नेपाल की तरफ़ बड़े नोट लेने से मना किया जा सकता है।',
        'नेपाल से भुगतान? eSewa से करें (पेमेंट पेज पर QR)। {PEGTXT}',
    ],
    'customsH' => 'कस्टम (सीमा शुल्क) की बुनियादी बातें',
    'customs' => [
        'सिर्फ निजी सामान। बेचने का सामान, निजी उपयोग से ज़्यादा सोना/गहने और बड़ी नकद राशि घोषित करें।',
        'नशीले पदार्थ, हथियार, वन्यजीव उत्पाद, नकली सामान — या किसी अनजान का पार्सल कभी न ले जाएँ।',
        'नए फ़ोन, इलेक्ट्रॉनिक्स और गहनों के बिल साथ रखें।',
    ],
    'checkH' => 'यात्रा से पहले चेकलिस्ट',
    'check' => [
        'मूल फोटो ID (पासपोर्ट / वोटर ID / नागरिकता) + एक फोटोकॉपी',
        'टिकट की इमेज या PNR (SHG-…) फ़ोन में सेव',
        'फ़ोन चार्ज; पावर बैंक काम आएगा',
        'टिकट पर लिखे रिपोर्टिंग समय तक पिकअप पर — कम से कम 30 मिनट पहले',
        'छोटे भारतीय नोट, पानी और अपनी दवाइयाँ',
        'सामान: 1 बैग 20 किलो तक + 1 केबिन बैग 7 किलो तक मुफ़्त (<a href="/#/terms">नियम व शर्तें</a>)। कीमती सामान और दस्तावेज़ अपने पास रखें।',
        'अकेली महिला यात्री: महिलाओं के लिए आरक्षित बर्थ सीट मैप पर दिखती हैं — मदद के लिए ऑफ़िस से पूछें।',
    ],
    'faqH' => 'अक्सर पूछे जाने वाले सवाल',
    'faq' => [
        ['क्या नेपाल जाने के लिए आधार काफ़ी है?', 'आमतौर पर नहीं। नेपाल इमिग्रेशन अक्सर सिर्फ आधार नहीं मानता। वैध भारतीय पासपोर्ट या वोटर ID (EPIC) साथ रखें।'],
        ['क्या बच्चों को ID चाहिए?', 'हाँ — हर बच्चे का जन्म प्रमाणपत्र या स्कूल फोटो ID, और साथ में वैध ID वाले माता-पिता या अभिभावक। उम्र के हिसाब से नियम अलग हैं; यात्रा से पहले पक्का करें।'],
        ['बॉर्डर पार करने में कितना समय लगता है?', 'आमतौर पर ज़्यादा नहीं, पर बदलता रहता है — त्योहार, छुट्टी और भीड़ में कतार लंबी होती है। ख़ासकर रूपईडीहा से बस पकड़नी हो तो समय हाथ में रखें।'],
        ['क्या नेपाली रुपये में भुगतान कर सकते हैं?', 'किराया ₹ में है। नेपाल से eSewa से भुगतान करें (पेमेंट पेज पर QR)। {PEGTXT} UPI और हमारे काउंटर पर नकद भी चलता है — NPR में नकद देने से पहले ऑफ़िस से पूछें।'],
        ['बस छूट जाए तो क्या करें?', 'तुरंत ऑफ़िस को कॉल या WhatsApp करें। बिना रद्द किए न आने पर रिफंड नहीं मिलता — देखें <a href="/refund-policy">रिफंड और कैंसिलेशन नीति</a>।'],
        ['अपना टिकट कैसे ढूँढें?', 'हमारे WhatsApp संदेश का टिकट लिंक खोलें, या जिस फ़ोन से बुक किया उस पर इस वेबसाइट में <a href="/#/my">My Trips</a> देखें। या PNR (SHG-…) / मोबाइल नंबर के साथ ऑफ़िस को WhatsApp / कॉल करें।'],
    ],
    'contactH' => 'संपर्क करें', 'contactLead' => 'किसी दस्तावेज़ या नियम पर शक है? यात्रा से पहले हमसे पूछें।',
    'counters' => 'काउंटर', 'reviewed' => 'आख़िरी समीक्षा: 2 अक्टूबर 2026',
],
'ne' => [
    'title' => 'यात्रा जानकारी — भारत ⇄ नेपाल बोर्डर (रुपैडिहा / नेपालगञ्ज) | ' . $co['name'],
    'h' => 'यात्रा जानकारी — भारत ⇄ नेपाल बोर्डर',
    'sub' => 'रुपैडिहा (भारत) ⇄ नेपालगञ्ज (नेपाल) · यात्राअघि अवश्य पढ्नुहोस्',
    'back' => '← गृहपृष्ठमा फर्कनुहोस्', 'book' => 'सिट बुक गर्नुहोस्', 'wa' => 'WhatsApp गर्नुहोस्', 'call' => 'फोन गर्नुहोस्',
    'waText' => 'नमस्ते, यात्रा जानकारी चाहियो',
    'alert' => '<b>नियम परिवर्तन भइरहन्छन्।</b> यात्राअघि अध्यागमन वा हाम्रो कार्यालयमा फोन गरेर पछिल्लो नियम पक्का गर्नुहोस्। यो सामान्य जानकारी हो, कानुनी सल्लाह होइन।',
    'pegTxt' => 'NPR रकम तोकिएको दर ₹1 = NPR {PEG} अनुसार हुन्छ।',
    'docsH' => 'साथमा राख्नुपर्ने कागजात',
    'docs' => [
        ['भारतीय नागरिक', 'मान्य <b>भारतीय राहदानी (पासपोर्ट)</b> वा <b>मतदाता परिचयपत्र (भोटर ID / EPIC)</b> — नेपाल अध्यागमनले सामान्यतया यिनै मान्छ। <b>आधार मात्र प्रायः मान्य हुँदैन</b>, त्यसैले आधार मात्र लिएर नजानुहोस्। भिसा चाहिँदैन।'],
        ['नेपाली नागरिक', '<b>नागरिकताको प्रमाणपत्र</b> वा <b>नेपाली राहदानी</b>। भारत जान भिसा चाहिँदैन।'],
        ['बालबालिका / नाबालक', 'जन्मदर्ता प्रमाणपत्र वा विद्यालयको फोटो ID राख्नुहोस्। बालबालिका आफ्नै मान्य ID भएका आमाबुबा वा अभिभावकसँग हुनुपर्छ।'],
        ['विदेशी नागरिक (भारतीय वा नेपाली होइन)', 'मान्य राहदानी र भिसा चाहिन्छ, साथै भारत र नेपाल अध्यागमनको निकासी/प्रवेश छाप — यी छापबिना खुला बोर्डर तपाईंका लागि होइन। बुकिङअघि दूतावासबाट भिसा नियम बुझ्नुहोस्।'],
    ],
    'docsNote' => 'सक्कली कागजात र एउटा फोटोकपी राख्नुहोस्; ID मा भएकै नाममा टिकट बुक गर्नुहोस्। सही कागजात यात्रुको जिम्मेवारी हो — प्रवेश नपाए हाम्रो <a href="/refund-policy">फिर्ता नीति</a> लागू हुन्छ।',
    'borderH' => 'बोर्डरमा: रुपैडिहा ⇄ नेपालगञ्ज',
    'border' => [
        'हाम्रो बस भारततर्फ <b>रुपैडिहा</b> मा रोकिन्छ। ओर्लने ठ्याक्कै ठाउँ टिकटमा लेखिएको हुन्छ।',
        'पैदल वा ई-रिक्सा / टाँगाबाट बोर्डर पार गर्नुहोस्। भारतीय चौकी (SSB) र नेपालको जमुनाहा चौकीमा ID र सामान जाँच हुन सक्छ।',
        'नेपालगञ्ज बजार बोर्डरबाट नजिकै छ — चढ्नुअघि भाडा तय गर्नुहोस्।',
        'नेपालको समय भारतभन्दा १५ मिनेट अगाडि छ।',
        'गुजरात जाँदै हुनुहुन्छ? बस रुपैडिहाबाट छुट्छ: चाँडै पार गर्नुहोस् — लाइन लामो हुन सक्छ — र टिकटमा लेखिएको रिपोर्टिङ समयसम्म चढ्ने ठाउँमा पुग्नुहोस्।',
    ],
    'moneyH' => 'पैसा र मुद्रा',
    'money' => [
        'भाडा भारतीय रुपैयाँ (₹) मा हुन्छ। बोर्डर नजिक भारतीय रुपैयाँ धेरै चल्छ।',
        'साना भारतीय नोट राख्नुहोस् — नेपालतर्फ ठूला नोट नलिन सक्छन्।',
        'नेपालबाट भुक्तानी? eSewa बाट गर्नुहोस् (भुक्तानी पेजमा QR)। {PEGTXT}',
    ],
    'customsH' => 'भन्सारका आधारभूत कुरा',
    'customs' => [
        'व्यक्तिगत सामान मात्र। बिक्रीका सामान, व्यक्तिगत प्रयोगभन्दा बढी सुन/गहना र ठूलो नगद रकम घोषणा गर्नुहोस्।',
        'लागूऔषध, हतियार, वन्यजन्तुका उत्पादन, नक्कली सामान — वा नचिनेको मान्छेको पार्सल कहिल्यै नबोक्नुहोस्।',
        'नयाँ फोन, इलेक्ट्रोनिक्स र गहनाको बिल साथमा राख्नुहोस्।',
    ],
    'checkH' => 'यात्राअघिको चेकलिस्ट',
    'check' => [
        'सक्कली फोटो ID (राहदानी / भोटर ID / नागरिकता) + एउटा फोटोकपी',
        'टिकटको फोटो वा PNR (SHG-…) फोनमा सेभ',
        'फोन चार्ज; पावर बैंक भए राम्रो',
        'टिकटमा लेखिएको रिपोर्टिङ समयसम्म चढ्ने ठाउँमा — कम्तीमा ३० मिनेट अगाडि',
        'साना भारतीय नोट, पानी र आफ्नो औषधि',
        'सामान: १ झोला २० किलोसम्म + १ क्याबिन झोला ७ किलोसम्म निःशुल्क (<a href="/#/terms">नियम तथा सर्त</a>)। बहुमूल्य सामान र कागजात आफूसँगै राख्नुहोस्।',
        'एक्लै यात्रा गर्ने महिला: महिलाका लागि छुट्याइएका बर्थ सिट म्यापमा देखिन्छन् — सहयोगका लागि कार्यालयलाई सोध्नुहोस्।',
    ],
    'faqH' => 'धेरै सोधिने प्रश्न',
    'faq' => [
        ['नेपाल जान आधार मात्र पुग्छ?', 'सामान्यतया पुग्दैन। नेपाल अध्यागमनले आधार मात्र प्रायः मान्दैन। मान्य भारतीय राहदानी वा भोटर ID (EPIC) साथमा राख्नुहोस्।'],
        ['बालबालिकालाई ID चाहिन्छ?', 'चाहिन्छ — हरेक बालबालिकाको जन्मदर्ता वा विद्यालयको फोटो ID, र साथमा मान्य ID भएका आमाबुबा वा अभिभावक। उमेरअनुसार नियम फरक हुन्छ; यात्राअघि पक्का गर्नुहोस्।'],
        ['बोर्डर पार गर्न कति समय लाग्छ?', 'प्रायः धेरै लाग्दैन, तर फरक पर्छ — चाडपर्व, बिदा र भीडमा लाइन लामो हुन्छ। विशेषगरी रुपैडिहामा बस समात्नु छ भने समय बचाएर राख्नुहोस्।'],
        ['नेपाली रुपैयाँमा तिर्न मिल्छ?', 'भाडा ₹ मा हुन्छ। नेपालबाट eSewa मार्फत तिर्नुहोस् (भुक्तानी पेजमा QR)। {PEGTXT} UPI र हाम्रो काउन्टरमा नगद पनि मिल्छ — NPR मा नगद तिर्नुअघि कार्यालयलाई सोध्नुहोस्।'],
        ['बस छुट्यो भने के गर्ने?', 'तुरुन्तै कार्यालयलाई फोन वा WhatsApp गर्नुहोस्। रद्द नगरी नआएमा पैसा फिर्ता हुँदैन — <a href="/refund-policy">रद्द तथा फिर्ता नीति</a> हेर्नुहोस्।'],
        ['मेरो टिकट कसरी भेट्ने?', 'हाम्रो WhatsApp सन्देशको टिकट लिङ्क खोल्नुहोस्, वा बुक गरेकै फोनमा यो वेबसाइटको <a href="/#/my">My Trips</a> हेर्नुहोस्। वा PNR (SHG-…) / मोबाइल नम्बरसहित कार्यालयलाई WhatsApp / फोन गर्नुहोस्।'],
    ],
    'contactH' => 'सम्पर्क गर्नुहोस्', 'contactLead' => 'कुनै कागजात वा नियमबारे शंका छ? यात्राअघि हामीलाई सोध्नुहोस्।',
    'counters' => 'काउन्टर', 'reviewed' => 'अन्तिम समीक्षा: २ अक्टोबर २०२६',
],
];

/* ---------------------------------------------------------------------
 *  One block per language, same structure for all three.
 * ------------------------------------------------------------------- */
$fill = static function (string $s, array $c) use ($peg): string {
    $pegTxt = $peg > 0 ? str_replace('{PEG}', number_format($peg, 2), $c['pegTxt']) : '';
    return trim(str_replace('{PEGTXT}', $pegTxt, $s));
};
$li = static fn(array $items, array $c): string => implode('', array_map(static fn(string $s): string => '<li>' . $fill($s, $c) . '</li>', $items));
$wa = static fn(string $text): string => 'https://wa.me/' . $e($waDig) . '?text=' . rawurlencode($text);

$block = static function (string $l, array $c) use ($e, $fill, $li, $wa, $tel): string {
    $docs = '';
    foreach ($c['docs'] as [$t, $d]) {
        $docs .= '<h4>' . $t . '</h4><p>' . $d . '</p>';
    }
    $faq = '';
    foreach ($c['faq'] as [$q, $a]) {
        $faq .= '<details><summary>' . $q . '</summary><p>' . $fill($a, $c) . '</p></details>';
    }
    return '<div class="L" lang="' . $l . '">'
        . '<a href="/" class="back">' . $c['back'] . '</a>'
        . '<h2>' . $c['h'] . '</h2><p class="subtitle">' . $c['sub'] . '</p>'
        . '<div class="cta"><a class="btn hot" href="/">' . $c['book'] . '</a>'
        . '<a class="btn wa" href="' . $wa($c['waText']) . '" target="_blank" rel="noopener">' . $c['wa'] . '</a>'
        . '<a class="btn" href="tel:' . $e($tel) . '">' . $c['call'] . '</a></div>'
        . '<div class="card alert" role="note"><p>' . $c['alert'] . '</p></div>'
        . '<div class="card"><h3>' . $c['docsH'] . '</h3>' . $docs . '<p class="note">' . $c['docsNote'] . '</p></div>'
        . '<div class="card"><h3>' . $c['borderH'] . '</h3><ul>' . $li($c['border'], $c) . '</ul>'
        . '<h4>' . $c['moneyH'] . '</h4><ul>' . $li($c['money'], $c) . '</ul>'
        . '<h4>' . $c['customsH'] . '</h4><ul>' . $li($c['customs'], $c) . '</ul></div>'
        . '<div class="card"><h3>' . $c['checkH'] . '</h3><ul class="check">' . $li($c['check'], $c) . '</ul></div>'
        . '<div class="card faq"><h3>' . $c['faqH'] . '</h3>' . $faq . '</div>'
        . '</div>';
};
/* A short label in all three languages, for the shared contact card. */
$tri = static fn(string $k): string => '<span class="l" lang="en">' . $C['en'][$k] . '</span><span class="l" lang="hi">' . $C['hi'][$k] . '</span><span class="l" lang="ne">' . $C['ne'][$k] . '</span>';

/* FAQPage JSON-LD from the English Q&As (plain text). */
$plain = static fn(string $s): string => html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$faqLd = [];
foreach ($C['en']['faq'] as [$q, $a]) {
    $faqLd[] = ['@type' => 'Question', 'name' => $plain($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $plain($fill($a, $C['en']))]];
}
$ld = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'FAQPage', 'inLanguage' => 'en', 'url' => $baseUrl . '/travel-info', 'mainEntity' => $faqLd],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $baseUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Travel Info', 'item' => $baseUrl . '/travel-info'],
        ]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$titles = json_encode(array_map(static fn(array $c): string => $c['title'], $C), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$desc = 'Before you cross the India–Nepal border at Rupaidiha ⇄ Nepalgunj: documents (passport, Voter ID, नागरिकता), currency, customs and FAQ. भारत–नेपाल बॉर्डर यात्रा जानकारी · नेपाल–भारत नाका यात्रा जानकारी.';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" data-lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($C[$lang]['title']) ?></title>
<script>(function(){var d=document.documentElement,ok={en:1,hi:1,ne:1},l=null;try{if(ok[new URLSearchParams(location.search).get('lang')])return;var s=localStorage.getItem('shg:lang');if(ok[s])l=s;else{var n=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||''];for(var i=0;i<n.length;i++){var c=String(n[i]||'').toLowerCase().slice(0,2);if(c==='hi'||c==='ne'){l=c;break}}}}catch(e){}if(l){d.setAttribute('data-lang',l);d.lang=l}})();</script>
<meta name="description" content="<?= $e($desc) ?>">
<meta property="og:title" content="Travel Info — India ⇄ Nepal Border | <?= $e($co['name']) ?>">
<meta property="og:description" content="<?= $e($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $e($baseUrl) ?>/travel-info">
<meta property="og:image" content="<?= $e($baseUrl) ?>/assets/img/og-shg.png">
<link rel="canonical" href="<?= $e($baseUrl) ?>/travel-info">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="icon" type="image/svg+xml" href="/icon-shg.svg">
<meta name="theme-color" content="#0E1E40">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&display=swap"></noscript>
<style>
/* Design system tokens (assets/css/ds.css, 4 Oct 2026): royal is the only action colour, navy bars, red = 3 px accent. */
:root{--navy:#0C306C;--mid:#0054A8;--royal-strong:#003C90;--tint:#EEF4FC;--on-royal:#fff;--red:#DC143C;--gold:#0054A8;--link:#0054A8;--wa:#11703F;--bg:#F4F6FB;--card:#fff;--text:#16233C;--muted:#5C6B85;--border:#E4E9F1}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0B1220;--card:#111D33;--text:#E8F0FB;--muted:#93A4BE;--border:#22314A;--navy:#0F1A2E;--mid:#5B9BE8;--royal-strong:#8FBFFF;--tint:#152645;--on-royal:#0B1220;--red:#FF5C74;--gold:#5B9BE8;--link:#8FBFFF;--wa:#5FD39A}}
:root[data-theme="dark"]{--bg:#0B1220;--card:#111D33;--text:#E8F0FB;--muted:#93A4BE;--border:#22314A;--navy:#0F1A2E;--mid:#5B9BE8;--royal-strong:#8FBFFF;--tint:#152645;--on-royal:#0B1220;--red:#FF5C74;--gold:#5B9BE8;--link:#8FBFFF;--wa:#5FD39A}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari','Nirmala UI',sans-serif;font-size:16px;background:var(--bg);color:var(--text);line-height:1.7;-webkit-text-size-adjust:100%}
a{color:var(--link)}
.header{background:var(--navy);color:#fff;padding:16px;text-align:center;border-bottom:3px solid var(--red)}
a:focus-visible,summary:focus-visible{outline:3px solid var(--mid);outline-offset:2px}
.header a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:10px}
.header img{height:36px;width:auto}
.header h1{font-family:'Cinzel',Georgia,serif;font-size:clamp(14px,3vw,18px);font-weight:700}
.langbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;gap:10px;padding:8px 16px;background:var(--card);border-bottom:1px solid var(--border);box-shadow:0 2px 8px rgba(14,30,64,.08)}
.lb{min-width:64px;min-height:48px;display:inline-flex;align-items:center;justify-content:center;padding:0 16px;border:2px solid var(--border);border-radius:12px;color:var(--text);text-decoration:none;font-weight:800;font-size:17px}
html[data-lang="en"] .lb[data-l="en"],html[data-lang="hi"] .lb[data-l="hi"],html[data-lang="ne"] .lb[data-l="ne"]{background:var(--mid);border-color:var(--mid);color:var(--on-royal)}
.L,.l{display:none}
html[data-lang="en"] .L[lang="en"],html[data-lang="hi"] .L[lang="hi"],html[data-lang="ne"] .L[lang="ne"]{display:block}
html[data-lang="en"] .l[lang="en"],html[data-lang="hi"] .l[lang="hi"],html[data-lang="ne"] .l[lang="ne"]{display:inline}
.container{max-width:800px;margin:0 auto;padding:20px 16px 56px}
.back{display:inline-flex;align-items:center;min-height:48px;font-weight:600;text-decoration:none}
h2{font-family:'Cinzel',Georgia,serif;font-size:clamp(22px,6vw,28px);font-weight:800;line-height:1.3;margin-bottom:6px}
.L[lang="hi"] h2,.L[lang="ne"] h2{font-family:inherit}
.subtitle{color:var(--muted);margin-bottom:18px}
.cta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px}
.btn{flex:1 1 140px;min-height:48px;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);font-weight:800;text-decoration:none;text-align:center}
.btn.hot{background:var(--mid);border-color:var(--mid);color:var(--on-royal)}
.btn.hot:hover{background:var(--royal-strong)}
.btn.wa{background:var(--wa);border-color:var(--wa);color:var(--on-royal)}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px 16px;margin-bottom:16px}
.card h3{font-size:19px;font-weight:800;line-height:1.4;margin-bottom:10px}
.card h4{font-size:16px;font-weight:800;margin:14px 0 4px}
.card h3+h4{margin-top:0}
.card ul{padding-left:20px}
.card li{margin-bottom:8px}
.card ul.plain{list-style:none;padding-left:0}
.plain li{margin:0}.plain a{display:inline-block;padding:8px 0}
.alert{background:var(--tint);border-color:var(--mid);border-left-width:4px}
ul.check{list-style:none;padding-left:0}
.check li{position:relative;padding-left:30px}
.check li::before{content:'☐';position:absolute;left:0;color:var(--gold);font-weight:800}
.note{font-size:15px;color:var(--muted);margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
details{border-top:1px solid var(--border)}
details:first-of-type{border-top:0}
summary{cursor:pointer;font-weight:700;padding:12px 0;min-height:48px}
details p{padding-bottom:12px}
.footer{background:var(--navy);color:#B8C2D6;text-align:center;padding:24px 16px;font-size:14px}
.footer a{color:var(--gold);text-decoration:none}
</style>
<script type="application/ld+json"><?= $ld ?></script>
</head>
<body>
<div class="header">
  <a href="/"><img src="/assets/img/logo.png" alt="S Hari Global" width="49" height="36" loading="eager"><h1><?= $e($co['name']) ?></h1></a>
</div>
<nav class="langbar" aria-label="Language / भाषा">
  <a class="lb" href="?lang=en" data-l="en" hreflang="en" lang="en" aria-label="English"<?= $lang === 'en' ? ' aria-current="true"' : '' ?>>EN</a>
  <a class="lb" href="?lang=hi" data-l="hi" hreflang="hi" lang="hi" aria-label="हिन्दी"<?= $lang === 'hi' ? ' aria-current="true"' : '' ?>>हिं</a>
  <a class="lb" href="?lang=ne" data-l="ne" hreflang="ne" lang="ne" aria-label="नेपाली"<?= $lang === 'ne' ? ' aria-current="true"' : '' ?>>ने</a>
</nav>
<main class="container">
<?php foreach (['en', 'hi', 'ne'] as $l) { echo $block($l, $C[$l]), "\n"; } ?>
<div class="card" id="contact"><h3><?= $tri('contactH') ?></h3><p><?= $tri('contactLead') ?></p><ul class="plain">
<li>📞 <a href="tel:<?= $e($tel) ?>"><?= $e($phone) ?></a></li>
<li>💬 WhatsApp <a href="<?= $wa('Hello / नमस्ते — Travel Info') ?>" target="_blank" rel="noopener"><?= $e($waShow) ?></a></li>
<li>✉️ <a href="mailto:<?= $e($co['email']) ?>"><?= $e($co['email']) ?></a></li></ul>
<?php if ($counters !== '') { ?><h4><?= $tri('counters') ?></h4><ul class="plain"><?= $counters ?></ul><?php } ?>
<p class="note"><?= $tri('reviewed') ?></p></div>
</main>
<div class="footer">
  © <?= date('Y') ?> <?= $e($co['name']) ?> · CIN: <?= $e($co['cin']) ?> ·
  <a href="/refund-policy">Refund Policy</a> ·
  <a href="/#/terms">Terms</a> ·
  <a href="/">Home</a>
</div>
<script>
(function(){var d=document.documentElement,T=<?= $titles ?>;
function mark(){var l=d.getAttribute('data-lang');if(T[l])document.title=T[l];[].forEach.call(document.querySelectorAll('.lb'),function(b){if(b.getAttribute('data-l')===l)b.setAttribute('aria-current','true');else b.removeAttribute('aria-current')})}
mark();
document.querySelector('.langbar').addEventListener('click',function(ev){var b=ev.target.closest?ev.target.closest('.lb'):null;if(!b)return;ev.preventDefault();var l=b.getAttribute('data-l');d.setAttribute('data-lang',l);d.lang=l;try{localStorage.setItem('shg:lang',l)}catch(e){}try{var u=new URL(location.href);if(u.searchParams.has('lang')){u.searchParams.set('lang',l);history.replaceState(null,'',u.toString())}}catch(e){}mark();window.scrollTo(0,0)});
})();
</script>
</body>
</html>
