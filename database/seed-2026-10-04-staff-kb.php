<?php
// One-off: staff knowledge-base articles for the WhatsApp assistant (4 Oct 2026).
// Facts verified against live settings / code the same day. Re-runnable: skips
// an article whose slug already exists.
define('SHG_CLI', 1);
require '/var/www/shreehariglobal.in/public_html/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiknowledge.php';

$actor = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
$staff = ['agent', 'counter', 'support', 'manager'];

$direct = (int) Settings::getFloat('agent_flat_direct', 200.0);
$joint  = (int) Settings::getFloat('agent_flat_joint', 400.0);

$articles = [
    [
        'category' => 'agent',
        'canonical_title' => 'How is my agent commission calculated?',
        'canonical_answer' => "Commission is a flat amount per passenger (seat), not a percentage: Rs {$direct} per passenger for a direct agent and Rs {$joint} per passenger for a team / organisation (joint) agent. "
            . "The office can set a special rate for one agent in Agent 360 (Report tab); a changed rate applies to FUTURE sales only. "
            . "When a ticket is cancelled, its commission is reversed. Commission is credited when the ticket is confirmed and is shown in your statement as earned / reversed / paid out.",
        'nepali_content' => "कमिसन प्रतिशतमा होइन, प्रति यात्रु (सिट) फिक्स रकम हो: डाइरेक्ट एजेन्टलाई प्रति यात्रु रु {$direct}, टिम / संस्था (joint) एजेन्टलाई प्रति यात्रु रु {$joint}। "
            . "अफिसले Agent 360 मा कुनै एजेन्टको छुट्टै रेट राख्न सक्छ — नयाँ रेट आगामी बिक्रीमा मात्र लागू हुन्छ। टिकट रद्द भए त्यसको कमिसन फिर्ता (reverse) हुन्छ। टिकट कन्फर्म भएपछि कमिसन जम्मा हुन्छ र statement मा देखिन्छ।",
        'hindi_content' => "कमीशन प्रतिशत में नहीं, प्रति यात्री (सीट) फिक्स है: डायरेक्ट एजेंट को प्रति यात्री रु {$direct}, टीम / संस्था (joint) एजेंट को प्रति यात्री रु {$joint}। ऑफिस Agent 360 में किसी एजेंट का अलग रेट रख सकता है — नया रेट आगे की बिक्री पर ही लागू। टिकट कैंसल होने पर उसका कमीशन वापस हो जाता है।",
        'roman_nepali_examples' => 'mero commission kati, agent commission kati huncha, per seat kati paincha, commission kasari hisab huncha',
        'roman_hindi_examples' => 'mera commission kitna, agent commission kitna milta hai, per seat kitna',
        'keywords' => 'agent commission rate per seat passenger flat direct joint team earned reversed',
        'synonyms' => 'commission, kamisan, कमिसन, कमीशन, rate',
        'source_reference' => "Live settings agent_commission_mode=flat_per_seat, agent_flat_direct={$direct}, agent_flat_joint={$joint}; AgentWallet::flatRateFor — 4 Oct 2026",
    ],
    [
        'category' => 'agent',
        'canonical_title' => 'How do I get the bus chalani (passenger list)?',
        'canonical_answer' => "The chalani is sent automatically by email about 2 hours before every departure: the office gets the full passenger list with the seat-chart picture, and each agent / counter gets the list of ONLY their own passengers on that bus (at the email saved on their profile). "
            . "The office can also open Admin -> Bus Calendar -> the bus -> Chalani / Challan any time and send the picture on WhatsApp to the driver or an agent. If you are not getting the email, ask the office to add your email to your agent / counter profile.",
        'nepali_content' => "चलानी हरेक बस छुट्नुभन्दा करिब २ घण्टा अघि इमेलमा आफैँ आउँछ: अफिसलाई पूरा यात्रु सूची र सिट-चार्टको फोटो, अनि हरेक एजेन्ट / काउन्टरलाई त्यो बसमा आफूले बुक गरेका यात्रु मात्र (प्रोफाइलमा भएको इमेलमा)। "
            . "अफिसले जुनसुकै बेला Admin → Bus Calendar → बस → Chalani / Challan खोलेर फोटो ड्राइभर वा एजेन्टलाई WhatsApp गर्न सक्छ। इमेल आएन भने अफिसलाई आफ्नो प्रोफाइलमा इमेल थप्न भन्नुहोस्।",
        'hindi_content' => "चलानी हर बस छूटने से करीब 2 घंटे पहले ईमेल में अपने आप आती है: ऑफिस को पूरी यात्री सूची और सीट-चार्ट फोटो, और हर एजेंट / काउंटर को उस बस में अपने बुक किए यात्री ही। ऑफिस Admin → Bus Calendar → बस → Chalani खोलकर फोटो WhatsApp भी कर सकता है।",
        'roman_nepali_examples' => 'chalani kasari paune, chalani pathaideu, bus ko yatri list, manifest chahiyo, chalani email ma aaucha',
        'roman_hindi_examples' => 'chalani kaise mile, passenger list chahiye, manifest bhejo',
        'keywords' => 'chalani chalan challan manifest passenger list seat chart bus departure email',
        'synonyms' => 'chalani, chalan, challan, चलानी, manifest, yatri list',
        'source_reference' => 'cron/chalani-mail.php (chalani_mail_hours=2, own passengers only for sellers) + admin Bus Calendar chalan_send — 4 Oct 2026',
    ],
    [
        'category' => 'agent',
        'canonical_title' => 'How does the passenger get the ticket after I book?',
        'canonical_answer' => "As soon as the ticket is confirmed: (1) the passenger gets a WhatsApp ticket message on their own number (Indian +91 or Nepali +977); (2) the agent / counter who sold it gets the ticket picture (PNG) by email, to forward or print; (3) the ticket can always be opened or downloaded from the ticket link or with the PNR. "
            . "Enter the passenger's full 10-digit mobile number correctly — a wrong or short number means WhatsApp cannot reach them.",
        'nepali_content' => "टिकट कन्फर्म हुनेबित्तिकै: (१) यात्रुको आफ्नै नम्बरमा (भारत +91 वा नेपाल +977) WhatsApp मा टिकट जान्छ; (२) बेच्ने एजेन्ट / काउन्टरको इमेलमा टिकटको फोटो (PNG) जान्छ — फरवार्ड वा प्रिन्ट गर्न; (३) PNR वा टिकट लिङ्कबाट जुनसुकै बेला टिकट खोल्न / डाउनलोड गर्न सकिन्छ। "
            . "यात्रुको पूरा १० अंकको मोबाइल नम्बर सही राख्नुहोस् — गलत वा छोटो नम्बर भए WhatsApp पुग्दैन।",
        'hindi_content' => "टिकट कन्फर्म होते ही: (1) यात्री के अपने नंबर पर (भारत +91 या नेपाल +977) WhatsApp पर टिकट जाता है; (2) बेचने वाले एजेंट / काउंटर के ईमेल पर टिकट की फोटो (PNG) जाती है; (3) PNR या टिकट लिंक से कभी भी टिकट खोल / डाउनलोड कर सकते हैं। यात्री का पूरा 10 अंक का मोबाइल नंबर सही डालें।",
        'roman_nepali_examples' => 'ticket kasari janchha, yatri lai ticket gayena, ticket whatsapp ma aaucha, ticket email ma aaucha',
        'roman_hindi_examples' => 'ticket kaise jayega, passenger ko ticket nahi mila, ticket whatsapp pe aata hai',
        'keywords' => 'ticket delivery whatsapp email png passenger agent counter confirmed phone number',
        'synonyms' => 'ticket, tikat, टिकट, ticket copy',
        'source_reference' => 'AiSensy shg_ticket_confirmed (approved, delivered +91 and +977 on 4 Oct 2026) + includes/sellermail.php (Brevo, delivered) — 4 Oct 2026',
    ],
    [
        'category' => 'agent',
        'canonical_title' => 'How do I see my statement and balance?',
        'canonical_answer' => "Your statement shows the opening balance, commission earned / reversed / paid out, cash collected and handed over, and the closing balance (what the company owes you or what you hold). "
            . "The office prepares it in Agent 360 (Report / Statement) for any period and can send it to you as a picture or on WhatsApp. Ask the office for 'my statement' with the dates you need.",
        'nepali_content' => "Statement मा सुरु मौज्दात, कमिसन — कमाएको / फिर्ता भएको / भुक्तानी भएको, उठाएको र बुझाएको नगद, र अन्तिम मौज्दात (कम्पनीले तपाईंलाई दिनुपर्ने वा तपाईंसँग भएको रकम) हुन्छ। "
            . "अफिसले Agent 360 (Report / Statement) मा जुनसुकै अवधिको बनाउँछ र फोटो वा WhatsApp मा पठाउन सक्छ। चाहिएको मिति भनेर अफिससँग 'mero statement' माग्नुहोस्।",
        'hindi_content' => "Statement में शुरुआती बैलेंस, कमीशन — कमाया / वापस / भुगतान, नकद जमा और सौंपा, और अंतिम बैलेंस होता है। ऑफिस Agent 360 में किसी भी अवधि का बनाकर फोटो या WhatsApp पर भेज सकता है।",
        'roman_nepali_examples' => 'mero statement pathaideu, mero balance kati, hisab kitab, kati paisa baki',
        'roman_hindi_examples' => 'mera statement bhejo, balance kitna hai, hisab',
        'keywords' => 'statement balance ledger hisab commission cash settlement payout agent',
        'synonyms' => 'statement, hisab, खाता विवरण, balance',
        'source_reference' => 'WaTemplates agent_statement text (opening/closing, earned/reversed/paid, cash) + admin/agent-360.php Report tab — 4 Oct 2026',
    ],
];

foreach ($articles as $a) {

    $exists = Database::scalar('SELECT id FROM ai_kb_articles WHERE canonical_title = :t', ['t' => $a['canonical_title']]);
    if ($exists !== null) {
        echo "exists #{$exists}: {$a['canonical_title']}\n";
        continue;
    }
    $id = AiKb::save($a + ['publication_status' => 'published', 'applicable_roles' => $staff], $actor);
    echo "saved #{$id}: {$a['canonical_title']}\n";
}

// The gaps the bot logged as unanswered now have an article.
foreach (['agent commission' => 'How is my agent commission calculated?', 'chalani' => 'How do I get the bus chalani (passenger list)?', 'chalani manifest' => 'How do I get the bus chalani (passenger list)?'] as $q => $t) {
    $aid = Database::scalar('SELECT id FROM ai_kb_articles WHERE canonical_title = :t', ['t' => $t]);
    if ($aid !== null) {
        Database::run("UPDATE ai_unanswered_questions SET status = 'answered', article_id = :a WHERE normalized_question = :q AND status = 'new'", ['a' => $aid, 'q' => $q]);
    }
}

// Does the assistant find them?
foreach (['agent commission kati', 'chalani kasari paune', 'yatri lai ticket kasari janchha', 'mero statement'] as $q) {
    $hits = AiKb::search($q, 'agent', 1);
    echo "search '{$q}' (agent) -> ", $hits !== [] ? ($hits[0]['canonical_title'] ?? json_encode($hits[0])) : '(none)', "\n";
}
$pub = AiKb::search('agent commission kati', 'public', 1);
echo "same question as a customer -> ", $pub !== [] ? ($pub[0]['canonical_title'] ?? '?') : '(none — staff only, correct)', "\n";
