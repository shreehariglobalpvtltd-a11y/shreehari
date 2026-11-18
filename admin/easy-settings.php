<?php
/**
 * admin/easy-settings.php — 🤖 Easy Settings: say it, the app changes it.
 *
 * 6 Nov 2026 (owner: "setting ma API halne thau sabai bhanda sajilo; AI ni hos,
 * tesllai j bhane ni, jun bhasa ma bhane ni change gardos manually bhanda;
 * installed AI lai command dine, tesle kaam gardos; API / password halne thau
 * ekdam kam — pura app automatic"). One page, three things:
 *
 *   1. 🤖 COMMAND  — type (or speak) in Nepali / Hindi / English / roman:
 *                    "whatsapp bot band gara", "PIN login on", "agent le cancel
 *                    garda request matra". Words are matched first (no AI
 *                    needed); anything the words miss goes to the VPS model
 *                    (ApAi, Ollama) or the cloud brain, which may ONLY pick
 *                    from the whitelist below. Every change is shown first
 *                    (old → new) and saved only on "✅ Yes, change".
 *   2. ⚡ SWITCHES  — the everyday on/off switches, one tap each.
 *   3. 🔑 ONE KEY BOX — paste any API key; the app recognises whose key it is
 *                    (sk-ant- → Claude, AIza → Gemini, sk- → OpenAI, EAA →
 *                    Meta WhatsApp/Facebook …) and files it. Keys are
 *                    write-only: this page shows only ✅ set / ❌ missing.
 *
 * 16 Nov 2026 (Master v2, W3): every v2.0 switch (all OFF until the owner
 * flips it) is here too, plus a 🔢 card for the number knobs (device limit,
 * daily WhatsApp cap, group discount, auto-arrive, agent settlement days);
 * "Yes" on an AI change may WhatsApp the office one line (ai_change_wa_on,
 * default OFF — AiOps::confirm). Same words work by sentence.
 *
 * 16 Nov 2026 (Master v2, X5): + the last owner-decision switches — return
 * discount % / days, luggage fee per piece, infant on the lap + its fare,
 * festival colours, login look (classic | gold), Facebook comments + their AI
 * replies. All OFF / 0 / classic until the owner flips them; the festival
 * dates themselves are edited on admin/themes.php.
 *
 * 16 Nov 2026 (Master v2, Y2): S Hari AI makes an offer / coupon from a
 * sentence ("coupon DIWALI10 10% max 200 till 30 Nov") — shown first, made
 * only on "Yes", and always made OFF (never auto-applied): it gives no
 * discount until the office switches it ON on admin/offers.php. "offer band
 * DIWALI10" switches an ON offer OFF; the AI never switches one ON. Needs
 * coupons.edit (the Offers page's own gate). Four more switches from the Y
 * units: agent follow-ups, cancel follow-up ₹ threshold, agent cash on
 * WhatsApp, offer link in the WhatsApp inbox — all OFF / 0.
 *
 * Nothing here can touch a setting outside $ES_KEYS; every save is audited
 * (Settings::set + Logger::audit, secrets masked). Super-admin / settings
 * managers only (Auth::canManageSettings, the same gate as settings.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
require_once INCLUDE_PATH . '/aiops.php';
/* 6 Nov 2026: S Hari AI also changes fares, routes, bus numbers, extra seats, commission — each
   action checks its own permission (AiOps::allowed), so a routes / schedules manager may command too. */
$canCmd = $canEdit || Auth::can('routes.edit') || Auth::can('schedules.manage') || Auth::can('coupons.edit');   // Y2: an offers manager may make an offer by sentence
$e = static fn($v): string => Security::e((string) ($v ?? ''));

/* ---- the whitelist: key => [label, type, words that name it, options] ----
   16 Nov 2026 (v2.0): the switches, their words and the whole command path live in
   AiOps (EASY_KEYS, propose, confirm) so the in-page bubble (admin/api/ai-settings.php)
   answers exactly like this page. */
$ES_KEYS = AiOps::EASY_KEYS;
$ES_SECRETS = [
    'anthropic_api_key'  => ['🟠 Claude (Anthropic)', '/^sk-ant-[A-Za-z0-9_\-]{20,}$/'],
    'gemini_api_key'     => ['🔵 Gemini (Google)', '/^AIza[0-9A-Za-z_\-]{30,}$/'],
    'openrouter_api_key' => ['🟣 OpenRouter', '/^sk-or-[A-Za-z0-9_\-]{20,}$/'],
    'openai_api_key'     => ['🟢 OpenAI', '/^sk-(proj-)?[A-Za-z0-9_\-]{20,}$/'],
    'grok_api_key'       => ['⚫ Grok (xAI)', '/^xai-[A-Za-z0-9_\-]{20,}$/'],
    'whatsapp_api_token' => ['💬 Meta WhatsApp token', '/^EAA[A-Za-z0-9]{40,}$/'],
    'aisensy_api_key'    => ['💬 AiSensy key', '/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/'],
];
/* ---- 19 Nov 2026 (Nepal ops, units u1..u10): the new settings, one card per group ----
   key => [label (Nepali · English), type, hint, spec]. Types: bool | int (min/max) | money
   (0..max, 2 decimals, stored as text like ai_review_usd) | enum (opts) | text (re = the
   allowed shape, '' always allowed unless 'need') | map (a maps link or "lat, lng", the same
   check as admin/settings.php) | json (an object; '{}' allowed) | list (comma words -> JSON
   list, only 'opts' words) | phones (WhatsApp numbers with 91 / 977, stored "91…,977…").
   Spec 'on_from' = a page that must switch the key ON (its checks run there); here it can
   only go OFF. 'def' = the default the code reads the key with, so a key with
   no row shows what really happens. Only these keys can be written by act=nset; each save
   is CSRF-checked, Auth::canManageSettings only, audited settings.easy (old -> new).
   Not here on purpose: wa_auth_tpl_last_test, wa_flow_waba, wa_flow_dropped (state the code
   writes itself, not owner choices). */
$ES_NEW = [
    '💬 WhatsApp' => [
        'wa_queue_meta_on'       => ['📮 टिकट पर्खाइ: Meta बाट पनि · Ticket queue also via Meta', 'bool', 'A ticket that could not reach a number is sent free on its next message; on = also through Meta (not only AiSensy).', ['def' => true]],
        'wa_ticket_alt_on'       => ['📲 दोस्रो नम्बरमा पनि टिकट · Ticket to the 2nd number too', 'bool', 'The booking\'s second number (India / Nepal) gets the ticket as well.', ['def' => true]],
        'wa_pay_cta_on'          => ['💳 "Pay ₹X" बटन · Pay button on pending tickets', 'bool', 'Inside the 24 h window a pending payment gets one "Pay" button instead of a long link. Never confirms a payment by itself.', ['def' => true]],
        'wa_tpl_meta_on'         => ['📑 Approved template मात्र · Use approved Meta templates', 'bool', 'Outside the 24 h window send only templates Meta has approved; a refused one is skipped for a while.', ['def' => true]],
        'wa_tpl_auto_submit'     => ['📤 Template आफैं Meta मा पठाउने · Auto-submit missing templates', 'bool', 'off = the office submits templates by hand; on = the cron asks Meta to create missing ones (Meta write).', ['def' => false]],
        'wa_tpl_refused_hours'   => ['⏳ Refused template रोक्ने घण्टा · Hours to skip a refused template', 'int', 'After Meta refuses a template (132001 etc.) it is not tried again for this many hours. 0 = try every time.', ['def' => 6, 'min' => 0, 'max' => 72, 'unit' => 'h']],
        'wa_tpl_sync_minutes'    => ['🔄 Template status जाँच · Template status check every', 'int', 'How often the retry cron reads template status from Meta (a free GET). 0 = never.', ['def' => 60, 'min' => 0, 'max' => 1440, 'unit' => 'min']],
        'wa_reply_pdf_doc_on'    => ['📄 Bot जवाफसँग PDF · Bot replies send the PDF document', 'bool', 'A bot reply with a ticket sends the text first, then the PDF as a document.', ['def' => true]],
        'wa_stmt_pdf_doc_on'     => ['📄 एजेन्ट विवरण PDF · Agent statement as a PDF document', 'bool', 'The agent statement goes as a PDF document after its text.', ['def' => true]],
        'wa_auth_trust_sync'     => ['🔐 Login code template जाँच · Trust the synced login-code template', 'bool', 'Use the template sync result to decide whether the shg_login_code template may be sent.', ['def' => true]],
        'whatsapp_app_id'        => ['🆔 Meta App नम्बर · Meta App ID', 'text', 'The number of the Meta app (developers.facebook.com). Not a secret. Blank = not set.', ['def' => '', 're' => '/^\d{5,20}$/', 'max' => 20]],
        'wa_flow_on'             => ['📝 WhatsApp भित्र बुकिङ फारम · WhatsApp Flow form', 'bool', 'The in-WhatsApp booking form. Here it can only be switched OFF; switch it ON on WhatsApp form (wa-form.php), which first checks Meta shows the form PUBLISHED in this WhatsApp account.', ['def' => false, 'on_from' => 'wa-form.php']],
        'wa_flow_tpl_category'   => ['🏷 Form template प्रकार · Form template category', 'enum', 'The category the booking-form template is submitted under.', ['def' => 'UTILITY', 'opts' => ['UTILITY' => 'UTILITY', 'MARKETING' => 'MARKETING']]],
        'wa_flow_cron_recreate'  => ['♻️ पुरानो form फेरि बनाउने · Cron re-creates the form', 'bool', 'off = only drop the old form; on = the cron creates and publishes a new one (Meta write).', ['def' => false]],
        'fb_msgr_form_on'        => ['💙 Messenger बाट booking · Messenger guided booking', 'bool', 'Needs fb_msgr_sell on too; without it Messenger still goes to the assistant.', ['def' => false]],
        'wa_pay_dup_utr_block'   => ['🚫 एउटै UTR दोहोरो · Refuse a repeated UTR', 'bool', 'A UTR already used on another booking is refused on WhatsApp (the office still checks every payment).', ['def' => true]],
        'wa_office_words_on'     => ['🏢 Office शब्द (report, agents …) · Office one-word answers', 'bool', 'Office numbers may type report / agents / revenue and get the answer.', ['def' => true]],
        'wa_customer_words_on'   => ['🙋 Customer शब्द (status, track) · Customer one-word answers', 'bool', 'A passenger may type status / track and get their own booking.', ['def' => true]],
        'wa_find_resend_on'      => ['🔎 नाम + नम्बरले टिकट खोज · Find + resend a ticket', 'bool', 'Name + mobile finds the ticket and sends it again, only to the number on the booking.', ['def' => true]],
        'wa_ticket_word_link_on' => ['🔗 "ticket" शब्दमा link · "ticket" word sends the link', 'bool', 'Typing "ticket" sends the ticket link of the sender\'s own booking.', ['def' => true]],
    ],
    '🎫 Ticket' => [
        'ticket_pay_qr_upi'  => ['💳 टिकटमा UPI QR · UPI pay QR on the ticket', 'bool', 'Confirmed, non-COD tickets with nothing paid print a UPI QR with the amount; others print the live pay link.', ['def' => true]],
        'ticket_verify_qr'   => ['✅ जाँच्ने QR · Verify QR on the ticket', 'bool', 'The QR the boarding scanner reads.', ['def' => true]],
        'ticket_npr_primary' => ['🇳🇵 नेपाली रुपैयाँ पहिले · NPR first on Nepal sales', 'bool', 'A Nepal-company sale shows रू first and ₹ in brackets.', ['def' => true]],
        'ticket_maps_qr'     => ['🗺 नक्सा QR · Map QRs on the ticket', 'bool', 'Office / hub map QRs at the foot of the ticket.', ['def' => true]],
        'nepal_office_map'   => ['📍 नेपाल कार्यालय नक्सा · Nepal office map', 'map', 'A Google Maps link or "lat, lng". Blank = a search for Hotel Darbar, Pushpalal Chowk, Nepalgunj.', ['def' => '']],
        'india_hub_map'      => ['📍 भारत hub नक्सा · India hub map', 'map', 'A Google Maps link or "lat, lng". Blank = the S Hari Parking stop\'s own pin.', ['def' => '']],
    ],
    '🇳🇵 Nepal / Desk' => [
        'sales_close_clock'          => ['⏰ बुकिङ बन्द हुने समय · Sales closing clock (JSON)', 'json', 'Per route code: {"r7":{"all":"17:00","tz":"Asia/Kathmandu"}}; keys all / online / agent / counter / staff / whatsapp / ai. {} = off (no clock). Office (superadmin, manager) is never blocked.', ['def' => '{"r7":{"all":"17:00","tz":"Asia/Kathmandu"},"r2":{"online":"19:00","tz":"Asia/Kolkata"}}']],
        'np_agent_desk_on'           => ['🏪 नेपाली agent लाई desk · Nepal agents get a branch desk', 'bool', 'On approval a +977 agent is linked to a Nepal branch desk.', ['def' => false]],
        'np_agent_default_desk'      => ['🏪 नेपाल desk कोड · Default Nepal desk code', 'text', 'The desk a new Nepal agent is linked to (e.g. NPJ). Blank = a desk for their town.', ['def' => 'NPJ', 're' => '/^[A-Za-z0-9_-]{2,12}$/', 'max' => 12]],
        'chalani_auto_desk_currency' => ['💱 Chalani desk को मुद्रामा · Chalani in the desk\'s currency', 'bool', 'A Nepal desk\'s chalani shows money in NPR; India desks in ₹.', ['def' => true]],
    ],
    '🤝 Agents' => [
        'staff_commission_off'               => ['🧑‍💼 Staff लाई commission छैन · Staff earn a head count, not commission', 'bool', 'Staff / office logins that sell get जना only; agents and counters are unchanged.', ['def' => true]],
        'staff_commission_counter_staff_off' => ['🧑‍💼 Counter staff (SHG-S) लाई पनि · Also counter logins with a staff number', 'bool', 'off = counter logins with an SHG-S staff number earn again. A counter without a staff number always keeps its commission.', ['def' => true]],
        'agent_payout_verify_required'       => ['✔️ Payout अघि खाता जाँच · Verify the payout account first', 'bool', 'A withdrawal waits until the office ticks the agent\'s payout account as verified. Paying stays manual.', ['def' => false]],
    ],
    '🪪 Cards' => [
        'ap_id_front_style'       => ['🪪 परिचय-पत्र डिजाइन · ID card design', 'enum', 'The office default ID card front.', ['def' => 'royal', 'opts' => ['royal' => 'Royal Gold', 'modern' => 'Modern', 'heritage' => 'Heritage', 'portrait' => 'Portrait (13 Nov)']]],
        'ap_vc_style'             => ['📇 भिजिटिङ कार्ड डिजाइन · Visiting card design', 'enum', 'theme = the member\'s own visiting-card theme.', ['def' => 'royal', 'opts' => ['royal' => 'Royal Gold', 'modern' => 'Modern', 'heritage' => 'Heritage', 'theme' => 'Member theme']]],
        'ap_vc_back_qr'           => ['🔳 Visiting card पछाडिको QR · Visiting card back QR', 'enum', 'web = the website; webref = the website with the agent\'s code; wa = WhatsApp chat.', ['def' => 'web', 'opts' => ['web' => 'Website', 'webref' => 'Website + agent code', 'wa' => 'WhatsApp']]],
        'ap_card_route_np'        => ['🛣 कार्डमा रुट (नेपाली सदस्य) · Card route (Nepal member)', 'text', 'The route line printed on a Nepal member\'s card.', ['def' => 'नेपालगञ्ज/Rupaidiha → Surat', 'max' => 60]],
        'ap_card_route_in'        => ['🛣 कार्डमा रुट (भारतीय सदस्य) · Card route (India member)', 'text', 'The route line printed on an India member\'s card.', ['def' => 'Surat → Rupaidiha / Nepalgunj', 'max' => 60]],
        'ap_card_nepal_first'     => ['🇳🇵 नेपाली सदस्यको card मा नेपाल पहिले · Nepal flag + company first', 'bool', 'A +977 member\'s card puts the Nepal flag and the Nepal company first.', ['def' => true]],
        'ap_card_bilingual'       => ['🔤 नेपाली · English labels', 'bool', 'Card labels in Nepali and English.', ['def' => true]],
        'ap_card_signature_np'    => ['✍️ नेपाल कार्डको हस्ताक्षर नाम · Nepal card signature line', 'text', 'The name under the signature on Nepal cards. Blank = the default.', ['def' => '', 'max' => 40]],
        'ap_card_classic'         => ['↩️ पुरानो card · Classic (13 Nov) cards', 'bool', 'Roll back: every card prints the old portrait design.', ['def' => false]],
        'ap_card_cache'           => ['⚡ कार्ड छिटो खोल्ने (cache) · Card cache', 'bool', 'Keep rendered cards so they open fast.', ['def' => true]],
        'ap_card_prerender'       => ['⚡ Card आफैं बनाउने · Pre-render cards', 'bool', 'Render all designs in the background when an agent gets an ID.', ['def' => true]],
        'ap_card_prerender_print' => ['🖨 A4 sheet पनि · Pre-render the A4 print sheets', 'bool', 'Also render the A4 ID / visiting-card sheets ahead.', ['def' => true]],
        'ap_card_harfbuzz'        => ['🔠 HarfBuzz (नेपाली अक्षर) · HarfBuzz text shaping', 'bool', 'Shape Devanagari properly where the server has HarfBuzz. off = the old byte-identical cards.', ['def' => true]],
    ],
    '🔐 Login' => [
        'login_mobile_first_on'       => ['📱 मोबाइल पहिले login · Mobile-first login door', 'bool', 'Blocked until the shg_login_code WhatsApp template is live (approved in WhatsApp Manager); keep off till then.', ['def' => false]],
        'login_phone_id_on'           => ['📱 फोन चिन्ने · Phone-ID sign-in', 'bool', 'A known phone is recognised at the login door.', ['def' => true]],
        'login_remember_days'         => ['🗓 Field staff signed in (दिन) · Field roles stay signed in', 'int', 'Days an agent / counter / scanner … stays signed in on their own phone. 0 = off.', ['def' => 365, 'min' => 0, 'max' => 730, 'unit' => 'days']],
        'login_remember_office_hours' => ['🏢 Office signed in (घण्टा) · Office roles stay signed in', 'int', 'A fixed cap from sign-in for every other role; the idle timeout still applies.', ['def' => 24, 'min' => 0, 'max' => 720, 'unit' => 'h']],
        'login_remember_roles'        => ['👥 Field role हरू · Field roles', 'list', 'Comma list. Superadmin, manager and accountant are never field roles.', ['def' => ['agent', 'counter', 'scanner', 'support', 'operations', 'staff'], 'opts' => ['agent', 'counter', 'scanner', 'support', 'operations', 'staff', 'official']]],
        'customer_remember_days'      => ['🧳 यात्री signed in (दिन) · Passengers stay signed in', 'int', 'Days a passenger stays signed in after a proven door. 0 = off.', ['def' => 365, 'min' => 0, 'max' => 730, 'unit' => 'days']],
    ],
    '🧠 AI' => [
        'ai_review_on'         => ['🌙 राति AI review · Nightly AI review', 'bool', 'A paid Claude call once a night, inside the two caps below.', ['def' => true]],
        'ai_review_hour'       => ['🕛 Review गर्ने घण्टा (IST) · Review hour (IST)', 'int', 'The hour the nightly review may run.', ['def' => 0, 'min' => 0, 'max' => 23, 'unit' => 'h']],
        'ai_review_model'      => ['🧠 Review को AI मोडेल · Review model', 'text', 'Claude model id for the nightly review.', ['def' => 'claude-opus-5-5', 're' => '/^claude-[a-z0-9.\-]{3,60}$/', 'need' => true, 'max' => 64]],
        /* the two money caps use the SAME limits as admin/ai-center.php (10 / 300), so a value
           saved here is never silently cut by the next AI Control Center save */
        'ai_review_usd'        => ['💵 राति review को सीमा (USD) · Review cap per night (USD)', 'money', 'The review stops before this much is spent in one night. 0 = no review. Most 10 (same as AI Control Center).', ['def' => '1.50', 'max' => 10]],
        'ai_review_month_usd'  => ['💵 महिनाको review सीमा (USD) · Review cap per month (USD)', 'money', 'All nightly reviews together stay under this. Most 300 (same as AI Control Center).', ['def' => '45', 'max' => 300]],
        'ai_review_max_tokens' => ['🔢 Review को लामो जवाफ सीमा · Review max tokens', 'int', 'Longest answer the review may write.', ['def' => 16000, 'min' => 4000, 'max' => 64000]],
        'ai_review_autolearn'  => ['📚 Review आफैं सिक्ने · Review lessons go live', 'bool', 'off = the review writes drafts the office approves; on = its lessons go live by themselves.', ['def' => false]],
        'ai_review_in_digest'  => ['📰 Digest मा review · Review in the daily digest', 'bool', 'The review\'s lines appear in the office digest.', ['def' => true]],
        'ai_teach_max_tokens'  => ['🔢 AiTeach जवाफ सीमा · AiTeach max tokens', 'int', 'Longest answer an AiTeach round may write.', ['def' => 12000, 'min' => 4000, 'max' => 64000]],
        'ai_staff_provider'    => ['🧑‍💼 Staff को AI दिमाग · Staff AI brain', 'enum', 'anthropic = staff and office turns use the staff model below; off = they use the agent model ladder.', ['def' => 'anthropic', 'opts' => ['anthropic' => 'Anthropic', 'off' => 'Off']]],
        'ai_staff_model'       => ['🧠 Staff को AI मोडेल · Staff AI model', 'text', 'Claude model id for staff and office turns.', ['def' => 'claude-haiku-5-5', 're' => '/^claude-[a-z0-9.\-]{3,60}$/', 'need' => true, 'max' => 64]],
        'mk_default_lang'      => ['🗣 Default भाषा · Default contact language', 'enum', 'The language of a contact whose language is not known yet.', ['def' => 'ne', 'opts' => ['ne' => 'नेपाली', 'hi' => 'हिन्दी', 'en' => 'English']]],
        'mk_auto_langs'        => ['🗣 Marketing भाषाहरू · Marketing languages', 'text', 'Comma list of ne, hi, en. The default language is always one.', ['def' => 'ne,hi,en', 're' => '/^(ne|hi|en)(,(ne|hi|en)){0,2}$/', 'need' => true, 'max' => 8]],
        'stop_learn_on'        => ['📍 GPS बाट stop समय सिक्ने · Learn stop times from GPS', 'bool', 'The night cron learns boarding / halt times; shown as suggestions only (Routes → Halts: "Use this time").', ['def' => true]],
        'stop_learn_trips'     => ['📍 कति trip हेर्ने · Trips to learn from', 'int', 'The last N trips of each route.', ['def' => 10, 'min' => 1, 'max' => 60]],
        'stop_learn_min'       => ['📍 कम्तीमा trip · Minimum trips for a suggestion', 'int', 'A stop needs this many trips before a time is suggested.', ['def' => 3, 'min' => 1, 'max' => 30]],
        'ai_local_backup_on'   => ['🖥 Local AI ब्याकअप · Local AI backup', 'bool', 'When every cloud AI (Claude, Gemini) fails, the model on our own VPS answers in words only - it never books, changes or takes money.', ['def' => true]],
        'eta_learned_on'       => ['⏱ सिकेको समयले ETA · ETA uses learned times', 'bool', 'Customer ETAs use the learned stop times.', ['def' => false]],
    ],
    '💳 Payments / Search' => [
        'pay_upi_app_buttons'     => ['📱 UPI app बटन · UPI app buttons on the pay page', 'bool', 'GPay / PhonePe / Paytm buttons on pay.php.', ['def' => true]],
        'pay_app_chooser_on'      => ['📱 App छान्ने · Phone app chooser', 'bool', 'Let the phone offer its own app chooser for the UPI link.', ['def' => false]],
        'pay_claim_screenshot_on' => ['🖼 Screenshot सहित claim · Payment claim with a screenshot', 'bool', 'The pay page asks for a screenshot with the UTR. The office still confirms by hand.', ['def' => true]],
        'search_low_seats_at'     => ['🪑 "केवल N सिट बाँकी" · Low-seats badge at', 'int', 'Search results say "Only N seats left" at or below this. 0 = never.', ['def' => 6, 'min' => 0, 'max' => 60, 'unit' => 'seats']],
    ],
    '🧾 Chalani / Reports' => [
        'counter_day_close_on'         => ['🔒 Counter दिन बन्द · Counter day close', 'bool', 'One close per desk per day, totals frozen; office only.', ['def' => true]],
        'counter_day_report_on'        => ['📊 Counter दिनको report · Counter day report on WhatsApp', 'bool', 'Each counter\'s day totals go to the office numbers once per evening.', ['def' => true]],
        'counter_day_report_time'      => ['🕘 Report समय · Report time (IST)', 'text', 'HH:MM, e.g. 21:30.', ['def' => '21:30', 're' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'need' => true, 'max' => 5]],
        'counter_day_report_numbers'   => ['📞 थप नम्बर · Extra report numbers', 'phones', 'Comma list of WhatsApp numbers WITH the country code: 91 + 10 digits (India) or 977 + 10 digits (Nepal); a leading + is fine. A bare 10-digit number is refused (it would go to India). Besides the office numbers.', ['def' => '', 'max' => 400]],
        'office_report_email_fallback' => ['📧 WhatsApp नपुगे Email · Email fallback', 'bool', 'E-mail the report only when every WhatsApp copy failed.', ['def' => true]],
        'driver_list_on'               => ['🚌 Driver लाई list · Driver passenger list', 'bool', 'The driver gets the chalani picture as the bus fills.', ['def' => true]],
        'driver_list_min_pax'          => ['🚌 पहिलो पटक N जना · First list at', 'int', 'Passengers before the first list goes.', ['def' => 5, 'min' => 1, 'max' => 60, 'unit' => 'pax']],
        'driver_list_step'             => ['🚌 हरेक N जनामा · Again every', 'int', 'A new list after this many more passengers.', ['def' => 5, 'min' => 1, 'max' => 60, 'unit' => 'pax']],
        'driver_list_final_hours'      => ['🚌 अन्तिम list · Final list before departure', 'int', 'Hours before departure for the final list. 0 = no final list.', ['def' => 2, 'min' => 0, 'max' => 48, 'unit' => 'h']],
        'driver_list_window_hours'     => ['🚌 कहिलेदेखि · Start sending within', 'int', 'Lists are sent only for departures within this many hours.', ['def' => 24, 'min' => 1, 'max' => 168, 'unit' => 'h']],
        'driver_list_phones'           => ['📞 Driver list मा फोन · Passenger phones on the driver list', 'bool', 'on = the driver also gets the chalani picture with passenger phones.', ['def' => false]],
    ],
];
/* the settings group a key gets when it has no row yet (an existing row keeps its own) */
$ES_NEW_GROUP = ['💬 WhatsApp' => 'whatsapp', '🎫 Ticket' => 'tickets', '🇳🇵 Nepal / Desk' => 'booking', '🤝 Agents' => 'agent',
                 '🪪 Cards' => 'agentpro', '🔐 Login' => 'security', '🧠 AI' => 'ai', '💳 Payments / Search' => 'payments', '🧾 Chalani / Reports' => 'reports'];
/* the fold-out titles as the owner reads them (Nepali · English); the keys above stay the ids */
$ES_NEW_TITLE = ['💬 WhatsApp' => '💬 ह्वाट्सएप · WhatsApp', '🎫 Ticket' => '🎫 टिकट · Ticket', '🇳🇵 Nepal / Desk' => '🇳🇵 नेपाल / काउन्टर · Nepal / Desk',
                 '🤝 Agents' => '🤝 एजेन्ट · Agents', '🪪 Cards' => '🪪 कार्ड · Cards', '🔐 Login' => '🔐 लगइन · Login', '🧠 AI' => '🧠 एआई · AI',
                 '💳 Payments / Search' => '💳 भुक्तानी / खोज · Payments / Search', '🧾 Chalani / Reports' => '🧾 चलानी / रिपोर्ट · Chalani / Reports'];
$esNewFlat = [];
foreach ($ES_NEW as $g => $rows) {
    foreach ($rows as $k => $def) { $esNewFlat[$k] = $def + [4 => $g]; }
}
/** the value as it is used now (the code default when there is no row), as the page shows it */
$esNewCur = static function (string $k) use ($esNewFlat): string {
    [, $t, , $spec] = $esNewFlat[$k];
    $d = $spec['def'] ?? '';
    return match ($t) {
        'bool'  => Settings::getBool($k, (bool) $d) ? '1' : '0',
        'int'   => (string) Settings::getInt($k, (int) $d),
        'json'  => (static function () use ($k, $d): string {
            $v = Settings::get($k, null);
            if ($v === null) { return (string) $d; }
            return is_string($v) ? $v : ($v === [] ? '{}' : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        })(),
        'list'  => implode(',', array_map('strval', Settings::getArray($k, (array) $d))),
        default => Settings::getString($k, (string) $d),
    };
};
/** a posted value checked against its key's rule: [value to store, stype] or a Nepali · English error */
$esNewCheck = static function (string $k, string $raw) use ($esNewFlat): array|string {
    [, $t, , $spec] = $esNewFlat[$k];
    $raw = trim($raw);
    switch ($t) {
        case 'bool':
            return [$raw === '1', 'bool'];
        case 'int':
            if (preg_match('/^-?\d{1,7}$/', $raw) !== 1 || (int) $raw < (int) $spec['min'] || (int) $raw > (int) $spec['max']) {
                return '🔢 यो संख्या मिलेन · Not a valid value — use ' . $spec['min'] . ' to ' . $spec['max'] . '.';
            }
            return [(string) (int) $raw, 'int'];
        case 'money':
            if (preg_match('/^\d{1,5}(\.\d{1,2})?$/', $raw) !== 1 || (float) $raw > (float) $spec['max']) {
                return '🔢 यो रकम मिलेन · Not a valid amount — use 0 to ' . $spec['max'] . ' (two decimals at most).';
            }
            return [$raw, 'string'];
        case 'enum':
            return isset($spec['opts'][$raw]) ? [$raw, 'string'] : '✖ यो विकल्प छैन · Not one of the choices.';
        case 'map':
            if ($raw === '') { return ['', 'string']; }
            if (!class_exists('Ticket')) { require_once INCLUDE_PATH . '/ticket.php'; }
            if (preg_match('#^\s*(https?://\S+|-?\d{1,2}(\.\d+)?\s*[,; ]\s*-?\d{1,3}(\.\d+)?)\s*$#i', $raw) !== 1 || Ticket::mapLink($raw) === '') {
                return '📍 Google Maps link (with @lat,lng) वा "lat, lng" राख्नुहोस् · Paste a Google Maps link with a pin, or type "lat, lng".';
            }
            return [$raw, 'string'];
        case 'json':
            $j = json_decode($raw === '' ? '{}' : $raw, true);
            if (!is_array($j) || ($j !== [] && array_is_list($j))) {
                return '✖ JSON मिलेन · Not a valid JSON object — example {"r7":{"all":"17:00","tz":"Asia/Kathmandu"}}, or {} for off.';
            }
            return [$j === [] ? '{}' : (string) json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json'];
        case 'list':
            $words = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower($raw)) ?: []))));
            if (array_diff($words, (array) $spec['opts']) !== []) {
                return '✖ यो role मिलेन · Use only: ' . implode(', ', (array) $spec['opts']) . '.';
            }
            return [(string) json_encode($words), 'json'];
        case 'phones':
            /* full international numbers only: OfficeReport::counterDayNumbers() sends the digits
               as they are and a bare 98xxxxxxxx would become +91 98xxxxxxxx (a wrong India number).
               Items split on , ; or new lines; inside one item spaces, dashes, brackets and a leading
               + are dropped; an item that is still not one number is tried word by word. */
            if (mb_strlen($raw) > (int) ($spec['max'] ?? 400)) {
                return '📞 धेरै लामो · Too long — at most ' . (int) ($spec['max'] ?? 400) . ' characters.';
            }
            $okNum = static fn(string $d): bool => preg_match('/^(91[6-9]\d{9}|9779[6-8]\d{8})$/', $d) === 1;
            $nums = [];
            foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $item) {
                $item = trim($item);
                if ($item === '') { continue; }
                $parts = preg_match('/^\+?[\d\s\-().]+$/', $item) === 1 && $okNum((string) preg_replace('/\D/', '', $item))
                    ? [(string) preg_replace('/\D/', '', $item)]
                    : (preg_split('/\s+/', $item) ?: []);
                foreach ($parts as $p) {
                    $d = preg_match('/^\+?\d+$/', (string) $p) === 1 ? ltrim((string) $p, '+') : '';
                    if (!$okNum($d)) {
                        return '📞 देशको कोड चाहिन्छ · Country code required — "' . mb_substr((string) $p, 0, 20)
                            . '" is not 91 + 10 digits (India) or 977 + 10 digits (Nepal), e.g. 9779812345678.';
                    }
                    $nums[$d] = true;
                }
            }
            return [implode(',', array_keys($nums)), 'string'];
        default:   // text
            if (mb_strlen($raw) > (int) ($spec['max'] ?? 200) || ($raw === '' && !empty($spec['need']))
                || ($raw !== '' && isset($spec['re']) && preg_match($spec['re'], $raw) !== 1)) {
                return '✖ यो मान मिलेन · Not a valid value for ' . $k . '.';
            }
            return [$raw, 'string'];
    }
};
$curVal = static fn(string $k): string => AiOps::easyCurrent($k);
$show   = static fn(string $k, string $v): string => AiOps::easyShow($k, $v);
$save   = static function (string $k, string $v) use ($admin): void {
    AiOps::saveSwitch($k, $v, (int) $admin['id']);
};

$flash = null;
$proposal = null;   // [key => new value] waiting for "Yes"
$opsProp  = [];     // AiOps actions waiting for "Yes"
$cmdText = '';
$backTo  = '';
/* the switches above are known to S Hari AI too (label, type, options, value now) */
AiOps::registerEasyKeys();
$cleanBack = static function (string $b): string {
    return preg_match('/^[a-z0-9\-]+\.php(\?[A-Za-z0-9_=&%\-.:]*)?$/', $b) === 1 ? $b : '';
};
// a routes / schedules manager may send commands (cmd / yes / no) only — never switches or keys
if (($canEdit || ($canCmd && in_array((string) ($_POST['act'] ?? ''), ['cmd', 'yes', 'no'], true))) && Security::isPost()) {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Page was open too long — try again.'];
    } elseif ($act === 'cmd') {
        $cmdText = Security::clean((string) ($_POST['cmd'] ?? ''), 400);
        $backTo  = $cleanBack((string) ($_POST['back'] ?? ''));
        if ($cmdText !== '') {
            /* words first: the operations (fare, route, bus, extra, commission, fine, seat,
               new pickup), then the one-tap switches; the AI only when the words found
               nothing and asked nothing (AiOps::propose) */
            $pr = AiOps::propose($cmdText, $canEdit);
            if ($pr['switches'] === [] && $pr['ops'] === []) {
                $flash = ['bad', AiOps::emptyMessage($pr)];
            } else {
                $proposal = $pr['switches'];
                $opsProp  = $pr['ops'];
                $_SESSION['es_prop'] = ['p' => $pr['switches'], 'ops' => $pr['ops'], 't' => time(), 'via' => $pr['via'], 'cmd' => $cmdText, 'back' => $backTo];
                AiOps::auditProposed($pr);   // 16 Nov 2026 (P3-1c): what was shown, with the parsed actions
            }
        }
    } elseif ($act === 'yes') {
        $p = $_SESSION['es_prop'] ?? null;
        unset($_SESSION['es_prop']);
        if (!is_array($p) || (int) ($p['t'] ?? 0) < time() - 600) {
            $flash = ['bad', 'Time ran out — say it again.'];
        } else {
            $done = AiOps::confirm(['switches' => (array) ($p['p'] ?? []), 'ops' => (array) ($p['ops'] ?? []), 'cmd' => (string) ($p['cmd'] ?? ''), 'via' => (string) ($p['via'] ?? '')], $admin, $canEdit);
            $backTo = (string) ($p['back'] ?? '');
            $flash = ['ok', '✅ ' . ($done !== [] ? implode(' · ', $done) : 'Nothing changed.')];
        }
    } elseif ($act === 'no') {
        // 16 Nov 2026 (P3-1c): a "No" is logged too (settings.ai_declined), nothing is written
        $p = $_SESSION['es_prop'] ?? null;
        unset($_SESSION['es_prop']);
        if (is_array($p)) {
            AiOps::auditDeclined(['switches' => (array) ($p['p'] ?? []), 'ops' => (array) ($p['ops'] ?? []), 'cmd' => (string) ($p['cmd'] ?? ''), 'via' => (string) ($p['via'] ?? '')]);
            $backTo = (string) ($p['back'] ?? '');
        }
        $flash = ['ok', '✖ केही बदलिएन · Nothing changed.'];
    } elseif ($act === 'toggle') {
        $k = (string) ($_POST['key'] ?? '');
        if (isset($ES_KEYS[$k]) && $ES_KEYS[$k][1] === 'bool') {
            $v = $curVal($k) === '1' ? '0' : '1';
            $save($k, $v);
            $flash = ['ok', '✅ ' . $ES_KEYS[$k][0] . ' → ' . $show($k, $v)];
        } elseif (isset($ES_KEYS[$k]) && $ES_KEYS[$k][1] === 'enum') {
            $v = (string) ($_POST['value'] ?? '');
            if (isset($ES_KEYS[$k][3][$v])) { $save($k, $v); $flash = ['ok', '✅ ' . $ES_KEYS[$k][0] . ' → ' . $show($k, $v)]; }
        }
    } elseif ($act === 'setnum') {
        // 16 Nov 2026 (Master v2, W3): the number knobs (device limit, daily WhatsApp cap, group discount …)
        $k = (string) ($_POST['key'] ?? '');
        $v = isset($ES_KEYS[$k]) ? AiOps::easyNumber($k, (string) ($_POST['value'] ?? '')) : null;
        if ($v === null) {
            $spec  = isset($ES_KEYS[$k][3]['min']) ? $ES_KEYS[$k][3] : ['min' => '?', 'max' => '?'];
            $flash = ['bad', '🔢 यो संख्या मिलेन · Not a valid value — use ' . $spec['min'] . ' to ' . $spec['max'] . '.'];
        } elseif ($v === $curVal($k)) {
            $flash = ['ok', '✖ केही बदलिएन · Nothing changed.'];
        } else {
            $save($k, $v);
            $flash = ['ok', '✅ ' . $ES_KEYS[$k][0] . ' → ' . $show($k, $v)];
        }
    } elseif ($act === 'nset') {
        // 19 Nov 2026 (Nepal ops): one of the $ES_NEW keys - nothing outside that list
        $k = (string) ($_POST['key'] ?? '');
        if (!isset($esNewFlat[$k])) {
            $flash = ['bad', '✖ यो सेटिङ यहाँबाट बदलिँदैन · That setting is not on this page.'];
        } else {
            $old = $esNewCur($k);
            $raw = $esNewFlat[$k][1] === 'bool' ? ($old === '1' ? '0' : '1') : (string) ($_POST['value'] ?? '');
            $chk = $esNewCheck($k, $raw);
            if (is_string($chk)) {
                $flash = ['bad', $chk];
            } elseif ($chk[0] === true && isset($esNewFlat[$k][3]['on_from'])) {
                // e.g. wa_flow_on: ON only where its own checks run (wa-form.php asks Meta first)
                $flash = ['bad', '✖ यहाँबाट ON हुँदैन · This can only be switched ON on ' . $esNewFlat[$k][3]['on_from'] . ' (it checks first). OFF works here.'];
            } else {
                [$val, $stype] = $chk;
                $newShow = $stype === 'bool' ? ($val ? '1' : '0') : ($esNewFlat[$k][1] === 'list' ? implode(',', (array) json_decode((string) $val, true)) : (string) $val);
                if ($newShow === $old) {
                    $flash = ['ok', '✖ केही बदलिएन · Nothing changed.'];
                } else {
                    $grp = (string) (Database::scalar('SELECT sgroup FROM settings WHERE skey = :k', ['k' => $k], '') ?: ($ES_NEW_GROUP[$esNewFlat[$k][4]] ?? 'general'));
                    Settings::set($k, $val, $stype, $grp);
                    Logger::audit('settings.easy', 'setting', $k, ['value' => $old], ['value' => $newShow], 'Easy Settings (19 Nov card) by admin #' . (int) $admin['id']);
                    $flash = ['ok', '✅ ' . $esNewFlat[$k][0] . ' → ' . ($stype === 'bool' ? ($val ? 'ON' : 'OFF') : $newShow)];
                }
            }
        }
    } elseif ($act === 'key') {
        $raw = trim((string) ($_POST['secret'] ?? ''));
        $raw = trim($raw, " \t\n\r\"'");
        $hit = '';
        foreach ($ES_SECRETS as $k => $def) {
            if (preg_match($def[1], $raw) === 1) { $hit = $k; break; }
        }
        if ($raw === '') {
            $flash = ['bad', 'Key खाली छ · Paste a key first.'];
        } elseif ($hit === '') {
            $flash = ['bad', '🤔 यो कुन कम्पनीको key हो चिनिएन — पूरा key copy गरेर फेरि paste गर्नुहोस्, वा "All settings" मा राख्नुहोस्।'];
        } else {
            Settings::set($hit, $raw, 'string', 'keys');
            Logger::audit('settings.easy_key', 'setting', $hit, null, ['value' => '***' . substr($raw, -4)], 'Key filed by Easy Settings (auto-detected)');
            $flash = ['ok', '✅ ' . $ES_SECRETS[$hit][0] . ' key राखियो · saved (…' . substr($raw, -4) . ')'];
        }
    }
}

admin_header('🤖 S Hari AI · सेटिङ र काम (बोलेर)', 'easy-settings');
?>
<style>
.es{max-width:880px;display:grid;gap:16px}
.es .card{background:var(--card,#fff);border:1px solid var(--line,#dcdcdc);border-radius:18px;padding:16px 18px}
.es h2{margin:0 0 8px;font-size:18px}
.es .cmd{display:flex;gap:8px;flex-wrap:wrap}
.es .cmd input{flex:1 1 260px;min-height:54px;font-size:17px;padding:10px 14px;border-radius:12px;border:1.5px solid var(--line,#9aa6ba);background:var(--bg,#f6f8fc);color:inherit}
.es .cmd .btn{min-height:54px;font-size:16px;font-weight:800}
.es .ex{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.es .ex button{border:1px dashed var(--line,#c9d3e3);background:transparent;color:inherit;border-radius:999px;padding:5px 11px;font-size:12.5px;cursor:pointer}
/* 14 Nov 2026 (staff 360px audit): the example chips were 27px on a touch phone */
@media(max-width:820px) and (pointer:coarse){.es .ex button{min-height:40px;padding:5px 14px;font-size:13px}}
.es .prop{border:2px solid #F0B44C;background:#FFF8E6;color:#3A2A00;border-radius:14px;padding:12px 14px;margin-top:10px}
.es .prop li{margin:4px 0;font-size:15px}
.es .sw{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px}
.es .sw form{margin:0}
.es .sw button{width:100%;display:flex;justify-content:space-between;align-items:center;gap:8px;min-height:50px;border-radius:12px;border:1.5px solid var(--line,#c9d3e3);background:var(--bg,#f6f8fc);color:inherit;font:inherit;font-size:14px;font-weight:700;padding:8px 12px;cursor:pointer;text-align:left}
.es .pill{flex:0 0 auto;font-size:12px;font-weight:900;border-radius:999px;padding:3px 10px}
.es .pill.on{background:#16A34A;color:#fff}.es .pill.off{background:#9CA3AF;color:#fff}
.es .keys{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.es .keys span{font-size:12.5px;font-weight:700;border-radius:999px;padding:4px 10px;border:1px solid var(--line,#dcdcdc)}
.es .muted{font-size:12.5px;color:var(--mut,#5c6b85);line-height:1.5}
.es .mic{min-width:54px}
.es-find{display:flex;flex-direction:column;gap:4px;margin-top:8px;max-height:420px;overflow:auto}
.es-hit{display:block;text-align:left;border:1px solid var(--line,#dcdcdc);border-radius:10px;padding:8px 10px;background:var(--card,#fff);color:inherit;text-decoration:none;font:inherit;font-size:14px;cursor:pointer}
.es-hit small{display:block;color:var(--mut,#5c6b85);font-size:11.5px}.es-hit.pg{border-left:4px solid #2E5FA8}.es-hit.st{border-left:4px solid #F0B44C}
/* 16 Nov 2026 (W3): the number knobs */
.es .sw form.num{display:flex;flex-direction:column;gap:6px;border:1.5px solid var(--line,#c9d3e3);border-radius:12px;padding:8px 12px;background:var(--bg,#f6f8fc)}
.es .num label{display:flex;flex-direction:column;gap:2px;font-size:14px;font-weight:700}
.es .num label small{font-weight:600;font-size:12px;color:var(--mut,#5c6b85)}
.es .num .nr{display:flex;gap:6px}
.es .num input{flex:1 1 auto;min-width:0;min-height:44px;font-size:16px;padding:6px 10px;border-radius:10px;border:1.5px solid var(--line,#9aa6ba);background:var(--card,#fff);color:inherit}
.es .sw .num button{width:auto;flex:0 0 auto;min-height:44px;justify-content:center;background:#16A34A;border-color:#16A34A;color:#fff}
/* 19 Nov 2026: the new-settings card (one fold per group) */
.es .esn-g{border-top:1px solid var(--line,#dcdcdc);padding:8px 0}
.es .esn-g summary{cursor:pointer;font-weight:800;font-size:15px;min-height:40px;display:flex;align-items:center;gap:6px}
.es .sw .num button.esn-t{width:100%;justify-content:space-between;background:var(--card,#fff);border-color:var(--line,#c9d3e3);color:inherit}
.es .num select,.es .num textarea{flex:1 1 auto;min-width:0;min-height:44px;font:inherit;font-size:15px;padding:6px 10px;border-radius:10px;border:1.5px solid var(--line,#9aa6ba);background:var(--card,#fff);color:inherit}
.es .num textarea{font-family:ui-monospace,Consolas,monospace;font-size:13px}
.es .num code{font-size:11px;opacity:.75;word-break:break-all}
</style>
<div class="es">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if ($backTo !== ''): ?><p style="margin:0"><a class="btn ghost" href="/admin/<?= $e($backTo) ?>">← फर्कनुहोस् · Back</a></p><?php endif; ?>
<?php if (!$canEdit): ?><div class="flash bad">सेटिङ बदल्ने अनुमति छैन · You may view only; ask the super-admin.</div><?php endif; ?>

  <div class="card">
    <h2>🤖 S Hari AI लाई भन्नुहोस् · Tell the AI what to change</h2>
    <form method="post" class="cmd">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd">
      <input id="esCmd" name="cmd" value="<?= $e($cmdText) ?>" placeholder="जस्तै: Surat ko bhada 2300 · 9 Nov ko bus band · Bus 02 ko number GJ05AB1234 · whatsapp bot band gara" <?= $canCmd ? '' : 'disabled' ?> autocomplete="off">
      <button type="button" class="btn ghost mic" id="esMic" title="बोलेर · Speak" hidden>🎤</button>
      <button class="btn ok" type="submit" <?= $canCmd ? '' : 'disabled' ?>>पठाउनुहोस् →</button>
    </form>
    <div class="ex">
      <?php foreach (['Surat ko bhada 2300', 'sabai pickup ko bhada 100 badha do', 'bholi 1 baje jane bus band', 'Seat U15 block karo kal', 'Naya pickup: Gandhinagar 22:30', 'Bus 02 ko number GJ05AB1234', 'Rajkot route band', 'Surat Rupaidiha matra', '10 extra seat', 'cancel fine 500', 'SHG-0027 commission 250', 'whatsapp bot band gara', 'PIN pattern login chalu',
                      '24 ghanta reminder on karo', 'device limit agent 2', 'bholi jane bus 2 ghanta late', 'coupon DIWALI10 10% max 200 till 30 Nov', 'naya coupon SHG50 ₹50 off 50 jana'] as $x): ?>
        <button type="button" onclick="document.getElementById('esCmd').value=this.textContent;document.getElementById('esCmd').focus()"><?= $e($x) ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($proposal !== null || $opsProp !== []): ?>
      <div class="prop">
        <b>यो बदल्ने हो? · Change this?</b> <small class="muted">(<?= $e($_SESSION['es_prop']['via'] ?? '') ?>)</small>
        <ul>
          <?php foreach ((array) $proposal as $k => $v): ?>
            <li><?= $e($ES_KEYS[$k][0]) ?>: <s><?= $e($show((string) $k, $curVal((string) $k))) ?></s> → <b><?= $e($show((string) $k, (string) $v)) ?></b></li>
          <?php endforeach; ?>
          <?php foreach ($opsProp as $a): foreach (AiOps::preview($a) as [$what, $old, $new]): if ((string) $old === (string) $new) { continue; } ?>
            <li><?= $e($what) ?>: <s><?= $e($old) ?></s> → <b><?= $e($new) ?></b></li>
          <?php endforeach; endforeach; ?>
        </ul>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <form method="post" style="margin:0">
            <?= Security::csrfField() ?><input type="hidden" name="act" value="yes">
            <button class="btn ok" type="submit">✅ हो, बदल्नुहोस् · Yes, change</button>
          </form>
          <form method="post" style="margin:0">
            <?= Security::csrfField() ?><input type="hidden" name="act" value="no">
            <button class="btn ghost" type="submit">✖ होइन · No</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <p class="muted">नेपाली, हिन्दी, English — जुन भाषामा पनि। शब्दले बुझिएन भने VPS को AI ले बुझ्छ। सधैं पहिला देखाउँछ, अनि मात्र बदल्छ।</p>
  </div>

  <?php
  /* 6 Nov 2026 (owner: "S Hari AI lai sabai setting ko har ek kuna tha hos"):
     every admin page and every plain setting, searchable in one box. A tap on a
     setting puts it in the AI box above, so it changes the same safe way. */
  $allSets = [];
  try {
      foreach (Database::fetchAll("SELECT skey, svalue, stype, sgroup, label FROM settings WHERE stype IN ('bool','int','float','string') ORDER BY sgroup, skey") as $r) {
          if (AiOps::settingEditable((string) $r['skey'])) { $allSets[] = $r; }
      }
  } catch (Throwable $ex) { $allSets = []; }
  $allPages = admin_nav();
  ?>
  <div class="card">
    <h2>🔎 कुन सेटिङ कहाँ? · Find any page or setting</h2>
    <input id="esFind" placeholder="खोज्नुहोस्: fare, seat, chalani, whatsapp, commission, refund, extra, bus …" style="width:100%;min-height:48px;font-size:16px;padding:8px 12px;border-radius:12px;border:1.5px solid var(--line,#9aa6ba);background:var(--bg,#f6f8fc);color:inherit" autocomplete="off">
    <div id="esFindOut" class="es-find"></div>
    <p class="muted"><?= count($allPages) ?> pages · <?= count($allSets) ?> settings. Setting मा ट्याप गर्दा माथिको AI box मा आउँछ — मान लेखेर पठाउनुहोस्।</p>
  </div>
  <script>
  (function(){
    var pages = <?= json_encode(array_map(static fn(array $i): array => ['l' => (string) $i['label'], 'h' => str_starts_with((string) $i['href'], '/') ? (string) $i['href'] : '/admin/' . $i['href'], 's' => (string) ($i['section'] ?? '')], $allPages), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var sets = <?= json_encode(array_map(static fn(array $r): array => ['k' => (string) $r['skey'], 'l' => (string) ($r['label'] ?? ''), 'g' => (string) $r['sgroup'], 't' => (string) $r['stype'], 'v' => mb_substr((string) $r['svalue'], 0, 40)], $allSets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var box = document.getElementById('esFind'), out = document.getElementById('esFindOut'), cmd = document.getElementById('esCmd');
    function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function run(){
      var q = box.value.trim().toLowerCase(); out.innerHTML = '';
      if (q.length < 2) return;
      var words = q.split(/\s+/), html = [], n = 0;
      pages.forEach(function(p){ var hay = (p.l + ' ' + p.s + ' ' + p.h).toLowerCase();
        if (words.every(function(w){ return hay.indexOf(w) >= 0; }) && n < 12) { n++; html.push('<a class="es-hit pg" href="' + esc(p.h) + '">📄 ' + esc(p.l) + ' <small>' + esc(p.s) + '</small></a>'); } });
      var m = 0;
      sets.forEach(function(s){ var hay = (s.k.replace(/_/g, ' ') + ' ' + s.l + ' ' + s.g).toLowerCase();
        if (words.every(function(w){ return hay.indexOf(w) >= 0; }) && m < 25) { m++; html.push('<button type="button" class="es-hit st" data-k="' + esc(s.k) + '" data-t="' + esc(s.t) + '">⚙️ ' + esc(s.l || s.k) + ' <small>' + esc(s.k) + ' = ' + esc(s.t === 'bool' ? (s.v === '1' ? 'ON' : 'OFF') : s.v) + '</small></button>'); } });
      out.innerHTML = html.length ? html.join('') : '<p class="muted">केही भेटिएन · nothing found</p>';
    }
    box.addEventListener('input', run);
    out.addEventListener('click', function(e){
      var b = e.target.closest('button.es-hit'); if (!b || !cmd) return;
      cmd.value = b.getAttribute('data-k') + (b.getAttribute('data-t') === 'bool' ? ' on' : ' ');
      cmd.focus(); window.scrollTo({top: 0, behavior: 'smooth'});
    });
  })();
  </script>

  <div class="card">
    <h2>⚡ एक ट्याप switch · One-tap switches</h2>
    <div class="sw">
      <?php foreach ($ES_KEYS as $k => $def): if ($def[1] !== 'bool' && $def[1] !== 'enum') { continue; } $v = $curVal($k); ?>
        <form method="post">
          <?= Security::csrfField() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="key" value="<?= $e($k) ?>">
          <?php if ($def[1] === 'bool'): ?>
            <button type="submit" <?= $canEdit ? '' : 'disabled' ?>><span><?= $e($def[0]) ?></span><span class="pill <?= $v === '1' ? 'on' : 'off' ?>"><?= $v === '1' ? 'ON' : 'OFF' ?></span></button>
          <?php else:
            /* 16 Nov 2026 (W3): the next choice in the list — there are three two-way switches now, not only direct / request */
            $opts = array_keys($def[3]);
            $at   = array_search($v, $opts, true);
            $next = (string) $opts[$at === false ? 0 : ($at + 1) % count($opts)]; ?>
            <input type="hidden" name="value" value="<?= $e($next) ?>">
            <button type="submit" <?= $canEdit ? '' : 'disabled' ?> title="<?= $e($show($k, $v)) ?>"><span><?= $e($def[0]) ?></span><span class="pill on"><?= $e(strtoupper($v)) ?></span></button>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>🔢 संख्या सेटिङ · Number settings</h2>
    <div class="sw">
      <?php foreach ($ES_KEYS as $k => $def): if ($def[1] !== 'int' && $def[1] !== 'float') { continue; } $v = $curVal($k); $spec = (array) $def[3]; ?>
        <form method="post" class="num">
          <?= Security::csrfField() ?><input type="hidden" name="act" value="setnum"><input type="hidden" name="key" value="<?= $e($k) ?>">
          <label for="esn-<?= $e($k) ?>"><span><?= $e($def[0]) ?></span><small><?= $e($show($k, $v)) ?><?= (string) ($spec['zero'] ?? '') !== '' ? ' · 0 = ' . $e($spec['zero']) : '' ?></small></label>
          <span class="nr">
            <input id="esn-<?= $e($k) ?>" type="number" name="value" value="<?= $e($v) ?>" min="<?= $e($spec['min']) ?>" max="<?= $e($spec['max']) ?>" step="<?= $def[1] === 'float' ? '0.5' : '1' ?>" inputmode="<?= $def[1] === 'float' ? 'decimal' : 'numeric' ?>" <?= $canEdit ? '' : 'disabled' ?>>
            <button class="btn ok" type="submit" <?= $canEdit ? '' : 'disabled' ?>>💾</button>
          </span>
        </form>
      <?php endforeach; ?>
    </div>
    <p class="muted">यिनलाई बोलेर पनि बदल्न सकिन्छ — जस्तै "device limit agent 2", "whatsapp daily cap 500", "group discount 10 percent", "return discount 5 percent", "luggage fee 200", "cancel followup 3000"।</p>
  </div>

  <div class="card" id="es-new">
    <h2>🆕 नयाँ सेटिङ (19 Nov) · New settings</h2>
    <p class="muted" style="margin-top:0">WhatsApp, ticket, Nepal desk, agents, cards, login, AI, payments, chalani. हरेक बदलाव audit हुन्छ · Every change is audited. The value shown is what the app uses now (the code default when it was never saved).</p>
    <?php foreach ($ES_NEW as $g => $rows): ?>
      <details class="esn-g">
        <summary><?= $e($ES_NEW_TITLE[$g] ?? $g) ?> <small class="muted">(<?= count($rows) ?>)</small></summary>
        <div class="sw esn">
          <?php foreach ($rows as $k => [$lbl, $t, $hint, $spec]): $v = $esNewCur($k); ?>
            <form method="post" class="num" data-esn="<?= $e($k) ?>">
              <?= Security::csrfField() ?><input type="hidden" name="act" value="nset"><input type="hidden" name="key" value="<?= $e($k) ?>">
              <?php if ($t === 'bool'): ?>
                <button type="submit" class="esn-t" <?= $canEdit ? '' : 'disabled' ?>><span><?= $e($lbl) ?></span><span class="pill <?= $v === '1' ? 'on' : 'off' ?>"><?= $v === '1' ? 'ON' : 'OFF' ?></span></button>
                <small class="muted"><?= $e($hint) ?> <code><?= $e($k) ?></code><?php if (isset($spec['on_from'])): ?> · <a href="<?= $e($spec['on_from']) ?>">ON गर्न यहाँ · Switch ON here →</a><?php endif; ?></small>
              <?php else: ?>
                <label for="esx-<?= $e($k) ?>"><span><?= $e($lbl) ?></span><small><?= $e($hint) ?> <code><?= $e($k) ?></code></small></label>
                <span class="nr">
                  <?php if ($t === 'enum'): ?>
                    <select id="esx-<?= $e($k) ?>" name="value" <?= $canEdit ? '' : 'disabled' ?>>
                      <?php foreach ($spec['opts'] as $ov => $ol): ?><option value="<?= $e($ov) ?>"<?= (string) $ov === $v ? ' selected' : '' ?>><?= $e($ol) ?></option><?php endforeach; ?>
                    </select>
                  <?php elseif ($t === 'json'): ?>
                    <textarea id="esx-<?= $e($k) ?>" name="value" rows="3" spellcheck="false" <?= $canEdit ? '' : 'disabled' ?>><?= $e($v) ?></textarea>
                  <?php elseif ($t === 'int'): ?>
                    <input id="esx-<?= $e($k) ?>" type="number" name="value" value="<?= $e($v) ?>" min="<?= $e($spec['min']) ?>" max="<?= $e($spec['max']) ?>" step="1" inputmode="numeric" <?= $canEdit ? '' : 'disabled' ?>>
                  <?php elseif ($t === 'money'): ?>
                    <input id="esx-<?= $e($k) ?>" type="number" name="value" value="<?= $e($v) ?>" min="0" max="<?= $e($spec['max']) ?>" step="0.01" inputmode="decimal" <?= $canEdit ? '' : 'disabled' ?>>
                  <?php else: ?>
                    <input id="esx-<?= $e($k) ?>" type="text" name="value" value="<?= $e($v) ?>" maxlength="<?= (int) ($spec['max'] ?? 400) ?>" autocomplete="off" <?= $canEdit ? '' : 'disabled' ?>>
                  <?php endif; ?>
                  <button class="btn ok" type="submit" <?= $canEdit ? '' : 'disabled' ?>>💾</button>
                </span>
                <?php if (isset($spec['unit'])): ?><small class="muted"><?= $e($v) ?> <?= $e($spec['unit']) ?></small><?php endif; ?>
              <?php endif; ?>
            </form>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>🔑 कुनै पनि API key यहाँ paste · Paste any API key here</h2>
    <form method="post" class="cmd" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="key">
      <input type="password" name="secret" placeholder="sk-ant-… / AIza… / EAA… — कुन हो app आफैं चिन्छ" <?= $canEdit ? '' : 'disabled' ?> autocomplete="new-password">
      <button class="btn ok" type="submit" <?= $canEdit ? '' : 'disabled' ?>>💾 राख्नुहोस्</button>
    </form>
    <div class="keys">
      <?php foreach ($ES_SECRETS as $k => $def): $has = Settings::getString($k, '') !== ''; ?>
        <span style="<?= $has ? 'border-color:#16A34A' : '' ?>"><?= $e($def[0]) ?> <?= $has ? '✅' : '❌' ?></span>
      <?php endforeach; ?>
    </div>
    <p class="muted">Key कहिल्यै फेरि देखाइँदैन (सुरक्षाका लागि) — ✅ = राखिएको। अरू सबै सेटिङ: <a href="/admin/settings.php">⚙️ All settings →</a> · Local AI: <a href="/admin/ai-local.php">AI (VPS) →</a></p>
  </div>
</div>
<script>
(function(){
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition, b = document.getElementById('esMic'), i = document.getElementById('esCmd');
  if (!SR || !b || !i || i.disabled) return;
  b.hidden = false;
  b.onclick = function(){
    var r = new SR(); r.lang = 'ne-NP'; r.interimResults = false;
    b.textContent = '⏺'; r.onresult = function(ev){ i.value = ev.results[0][0].transcript; };
    r.onend = function(){ b.textContent = '🎤'; if (i.value) i.form.submit(); };
    try { r.start(); } catch (e) { b.textContent = '🎤'; }
  };
})();
</script>
<?php admin_footer();
