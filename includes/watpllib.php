<?php
/**
 * =====================================================================
 *  includes/watpllib.php — WhatsApp template library (8 Oct 2026)
 *
 *  Owner master prompt 2.0, Phase 3.4: "at least 30 editable template
 *  drafts" for customers, marketing, agents and staff, with variables
 *  such as {{customer_name}} whose values come ONLY from system records.
 *
 *    BUILTIN     the drafts that ship with the app (English, Nepali, Hindi).
 *    body()      the office's edited copy (table wa_tpl_library) or the
 *                built-in draft.
 *    resolve()   fills a body from VERIFIED records — a booking by PNR, an
 *                agent by id, the live fares / running offer from the
 *                settings and coupons tables. A variable the records cannot
 *                answer stays MISSING and the preview says so; nothing is
 *                ever guessed or invented.
 *    aiRewrite() asks the One AI (AiBrain) for another wording and then
 *                checks it: the same placeholders, no number that is not in
 *                the draft or the live facts, no discount the office never
 *                offered. A failed check returns nothing. The result is a
 *                suggestion — a person saves it.
 *    forMeta()   the {{1}}, {{2}} … form Meta / AiSensy need for approval,
 *                with the variable each number stands for.
 *
 *  Nothing here sends a message. Sending stays where it already is
 *  (Campaigns, wa-compose, Notify) with their consent and opt-out rules.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaTplLib
{
    public const LANGS = ['en' => 'English', 'ne' => 'नेपाली', 'hi' => 'हिन्दी', 'gu' => 'ગુજરાતી'];

    public const CATS = [
        'customer'  => 'Customer',
        'marketing' => 'Marketing',
        'agent'     => 'Agent',
        'staff'     => 'Staff & counter',
    ];

    /**
     * Every variable a template may use and where its value comes from.
     * src: booking (needs a PNR) | agent (needs an agent) | live (settings,
     * fares, running offer — always available) | manual (typed by the
     * authorised sender at send time, e.g. a duty notice).
     *
     * @var array<string, array{0:string, 1:string}>
     */
    public const VARS = [
        'customer_name'     => ['booking', 'Lead passenger name on the booking'],
        'booking_id'        => ['booking', 'PNR'],
        'travel_date'       => ['booking', 'Travel date'],
        'departure_time'    => ['booking', 'Departure time at the passenger\'s pickup'],
        'route'             => ['booking', 'From → To of the booking'],
        'pickup_point'      => ['booking', 'Boarding point on the booking'],
        'pickup_time'       => ['booking', 'Pickup time at that boarding point'],
        'pickup_map'        => ['booking', 'Google Maps link of the official pickup (only when it has coordinates)'],
        'seat_number'       => ['booking', 'Seat numbers'],
        'bus_number'        => ['booking', 'Bus number of the departure'],
        'ticket_price'      => ['booking', 'Total fare of the booking'],
        'amount_due'        => ['booking', 'Amount still to pay'],
        'refund_amount'     => ['booking', 'Refund amount recorded by the office'],
        'ticket_link'       => ['booking', 'Signed ticket download link'],
        'agent_name'        => ['agent', 'Agent name'],
        'agent_code'        => ['agent', 'Agent ID (SHG-0001)'],
        'commission_amount' => ['agent', 'Commission balance owed to the agent'],
        'balance_due'       => ['agent', 'Cash the agent holds for the company'],
        'sales_count'       => ['agent', 'Tickets sold by the agent this month'],
        'sales_amount'      => ['agent', 'Value of those tickets'],
        'company_name'      => ['live', 'Company name'],
        'support_phone'     => ['live', 'Office support number'],
        'website'           => ['live', 'Website'],
        'fare_to_nepal'     => ['live', 'Current fare Gujarat → Nepal border'],
        'fare_to_india'     => ['live', 'Current fare Nepal border → Gujarat'],
        'discount_code'     => ['live', 'Code of the running offer (Offers & Discounts)'],
        'offer_text'        => ['live', 'The running offer in one line'],
        'offer_until'       => ['live', 'Last day of the running offer'],
        'notice_text'       => ['manual', 'Notice typed by the sender (duty, counter or emergency notice)'],
    ];

    /**
     * The shipped drafts. meta = the Meta template category it would be
     * submitted under (UTILITY = service, MARKETING = promotion).
     *
     * @var array<string, array{cat:string, title:string, meta:string, en:string, ne:string, hi:string}>
     */
    public const BUILTIN = [
        // ---------------- Customer ----------------
        'welcome' => ['cat' => 'customer', 'title' => 'Welcome message', 'meta' => 'UTILITY',
            'en' => "Namaste {{customer_name}} 🙏 Welcome to {{company_name}}. Book your India–Nepal sleeper seat at {{website}} or reply here. Help: {{support_phone}}",
            'ne' => "नमस्ते {{customer_name}} 🙏 {{company_name}} मा स्वागत छ। भारत–नेपाल स्लिपर सिट {{website}} मा बुक गर्नुहोस् वा यहीँ लेख्नुहोस्। सहयोग: {{support_phone}}",
            'hi' => "नमस्ते {{customer_name}} 🙏 {{company_name}} में आपका स्वागत है। भारत–नेपाल स्लीपर सीट {{website}} पर बुक करें या यहीं लिखें। मदद: {{support_phone}}"],
        'booking_confirmation' => ['cat' => 'customer', 'title' => 'Booking confirmation', 'meta' => 'UTILITY',
            'en' => "✅ Booking confirmed, {{customer_name}}.\nPNR: {{booking_id}}\n{{route}} · {{travel_date}}\nSeat: {{seat_number}} · Pickup: {{pickup_point}} {{pickup_time}}\nFare: {{ticket_price}}",
            'ne' => "✅ बुकिङ पक्का भयो, {{customer_name}}।\nPNR: {{booking_id}}\n{{route}} · {{travel_date}}\nसिट: {{seat_number}} · चढ्ने ठाउँ: {{pickup_point}} {{pickup_time}}\nभाडा: {{ticket_price}}",
            'hi' => "✅ बुकिंग पक्की, {{customer_name}}।\nPNR: {{booking_id}}\n{{route}} · {{travel_date}}\nसीट: {{seat_number}} · पिकअप: {{pickup_point}} {{pickup_time}}\nकिराया: {{ticket_price}}"],
        'ticket_png' => ['cat' => 'customer', 'title' => 'Ticket PNG delivery', 'meta' => 'UTILITY',
            'en' => "🎫 Your ticket {{booking_id}} is attached. Show it at boarding. Download again: {{ticket_link}}",
            'ne' => "🎫 तपाईंको टिकट {{booking_id}} संलग्न छ। चढ्दा देखाउनुहोस्। फेरि डाउनलोड: {{ticket_link}}",
            'hi' => "🎫 आपका टिकट {{booking_id}} संलग्न है। बोर्डिंग पर दिखाएँ। दोबारा डाउनलोड: {{ticket_link}}"],
        'payment_pending' => ['cat' => 'customer', 'title' => 'Payment pending', 'meta' => 'UTILITY',
            'en' => "⏳ {{customer_name}}, booking {{booking_id}} is waiting for payment of {{amount_due}}. Pay to keep seat {{seat_number}}. Help: {{support_phone}}",
            'ne' => "⏳ {{customer_name}}, बुकिङ {{booking_id}} को {{amount_due}} भुक्तानी बाँकी छ। सिट {{seat_number}} राख्न भुक्तानी गर्नुहोस्। सहयोग: {{support_phone}}",
            'hi' => "⏳ {{customer_name}}, बुकिंग {{booking_id}} का {{amount_due}} भुगतान बाकी है। सीट {{seat_number}} रखने के लिए भुगतान करें। मदद: {{support_phone}}"],
        'payment_success' => ['cat' => 'customer', 'title' => 'Payment successful', 'meta' => 'UTILITY',
            'en' => "💚 Payment received for {{booking_id}}. Your seat {{seat_number}} on {{travel_date}} is confirmed.",
            'ne' => "💚 {{booking_id}} को भुक्तानी प्राप्त भयो। {{travel_date}} को सिट {{seat_number}} पक्का भयो।",
            'hi' => "💚 {{booking_id}} का भुगतान मिल गया। {{travel_date}} की सीट {{seat_number}} पक्की है।"],
        'travel_reminder' => ['cat' => 'customer', 'title' => 'Travel reminder (day before)', 'meta' => 'UTILITY',
            'en' => "🚌 Reminder: you travel tomorrow, {{travel_date}}. PNR {{booking_id}} · Seat {{seat_number}}. Be at {{pickup_point}} by {{pickup_time}}. Bring a photo ID.",
            'ne' => "🚌 सम्झना: भोलि {{travel_date}} यात्रा छ। PNR {{booking_id}} · सिट {{seat_number}}। {{pickup_time}} भित्र {{pickup_point}} आइपुग्नुहोस्। परिचयपत्र ल्याउनुहोस्।",
            'hi' => "🚌 याद दिलाना: कल {{travel_date}} यात्रा है। PNR {{booking_id}} · सीट {{seat_number}}। {{pickup_time}} तक {{pickup_point}} पहुँचें। फोटो ID साथ लाएँ।"],
        'departure_reminder' => ['cat' => 'customer', 'title' => 'Departure reminder (same day)', 'meta' => 'UTILITY',
            'en' => "⏰ Your bus {{bus_number}} reaches {{pickup_point}} at {{pickup_time}} today. Please come 30 minutes early. Map: {{pickup_map}}",
            'ne' => "⏰ तपाईंको बस {{bus_number}} आज {{pickup_time}} मा {{pickup_point}} आइपुग्छ। ३० मिनेट अगाडि आउनुहोस्। नक्सा: {{pickup_map}}",
            'hi' => "⏰ आपकी बस {{bus_number}} आज {{pickup_time}} पर {{pickup_point}} पहुँचेगी। 30 मिनट पहले आएँ। मैप: {{pickup_map}}"],
        'pickup_details' => ['cat' => 'customer', 'title' => 'Pickup location details', 'meta' => 'UTILITY',
            'en' => "📍 Pickup for {{booking_id}}: {{pickup_point}} at {{pickup_time}}. Directions: {{pickup_map}}",
            'ne' => "📍 {{booking_id}} को चढ्ने ठाउँ: {{pickup_point}}, समय {{pickup_time}}। बाटो: {{pickup_map}}",
            'hi' => "📍 {{booking_id}} का पिकअप: {{pickup_point}}, समय {{pickup_time}}। रास्ता: {{pickup_map}}"],
        'bus_delay' => ['cat' => 'customer', 'title' => 'Bus delay information', 'meta' => 'UTILITY',
            'en' => "⚠️ Update for {{booking_id}}: {{notice_text}} Your seat {{seat_number}} is safe. Help: {{support_phone}}",
            'ne' => "⚠️ {{booking_id}} को जानकारी: {{notice_text}} तपाईंको सिट {{seat_number}} सुरक्षित छ। सहयोग: {{support_phone}}",
            'hi' => "⚠️ {{booking_id}} की जानकारी: {{notice_text}} आपकी सीट {{seat_number}} सुरक्षित है। मदद: {{support_phone}}"],
        'booking_cancelled' => ['cat' => 'customer', 'title' => 'Booking cancellation', 'meta' => 'UTILITY',
            'en' => "❌ Booking {{booking_id}} ({{travel_date}}, seat {{seat_number}}) is cancelled. Questions: {{support_phone}}",
            'ne' => "❌ बुकिङ {{booking_id}} ({{travel_date}}, सिट {{seat_number}}) रद्द भयो। जानकारी: {{support_phone}}",
            'hi' => "❌ बुकिंग {{booking_id}} ({{travel_date}}, सीट {{seat_number}}) रद्द हुई। जानकारी: {{support_phone}}"],
        'refund_update' => ['cat' => 'customer', 'title' => 'Refund update', 'meta' => 'UTILITY',
            'en' => "💸 Refund for {{booking_id}}: {{refund_amount}} has been processed by our office. Questions: {{support_phone}}",
            'ne' => "💸 {{booking_id}} को फिर्ता: {{refund_amount}} कार्यालयबाट पठाइयो। जानकारी: {{support_phone}}",
            'hi' => "💸 {{booking_id}} का रिफंड: {{refund_amount}} ऑफिस से भेजा गया। जानकारी: {{support_phone}}"],
        'feedback_request' => ['cat' => 'customer', 'title' => 'Feedback request', 'meta' => 'UTILITY',
            'en' => "🙏 Thank you for travelling with {{company_name}}, {{customer_name}}. How was your trip {{booking_id}}? Reply 1–5 ⭐.",
            'ne' => "🙏 {{company_name}} सँग यात्रा गर्नुभएकोमा धन्यवाद, {{customer_name}}। यात्रा {{booking_id}} कस्तो रह्यो? १–५ ⭐ लेख्नुहोस्।",
            'hi' => "🙏 {{company_name}} के साथ यात्रा के लिए धन्यवाद, {{customer_name}}। यात्रा {{booking_id}} कैसी रही? 1–5 ⭐ लिखें।"],
        // ---------------- Marketing ----------------
        'new_customer_offer' => ['cat' => 'marketing', 'title' => 'New customer offer', 'meta' => 'MARKETING',
            'en' => "Namaste {{customer_name}} 🙏 Travel India ⇄ Nepal in a sleeper bus. {{offer_text}} Code: {{discount_code}}. Book: {{website}}",
            'ne' => "नमस्ते {{customer_name}} 🙏 भारत ⇄ नेपाल स्लिपर बसमा यात्रा गर्नुहोस्। {{offer_text}} कोड: {{discount_code}}। बुक: {{website}}",
            'hi' => "नमस्ते {{customer_name}} 🙏 भारत ⇄ नेपाल स्लीपर बस में यात्रा करें। {{offer_text}} कोड: {{discount_code}}। बुक: {{website}}"],
        'returning_customer_offer' => ['cat' => 'marketing', 'title' => 'Returning customer offer', 'meta' => 'MARKETING',
            'en' => "{{customer_name}}, thank you for travelling with us before 🙏 {{offer_text}} Book your next seat: {{website}}",
            'ne' => "{{customer_name}}, हामीसँग पहिले यात्रा गर्नुभएकोमा धन्यवाद 🙏 {{offer_text}} अर्को सिट बुक गर्नुहोस्: {{website}}",
            'hi' => "{{customer_name}}, पहले हमारे साथ यात्रा करने के लिए धन्यवाद 🙏 {{offer_text}} अगली सीट बुक करें: {{website}}"],
        'advance_booking' => ['cat' => 'marketing', 'title' => 'Advance booking discount', 'meta' => 'MARKETING',
            'en' => "📅 Book early, pay less: {{offer_text}} Valid till {{offer_until}}. Fare Gujarat → Nepal {{fare_to_nepal}}. {{website}}",
            'ne' => "📅 चाँडो बुक, कम तिर्नुहोस्: {{offer_text}} {{offer_until}} सम्म। भाडा गुजरात → नेपाल {{fare_to_nepal}}। {{website}}",
            'hi' => "📅 जल्दी बुक, कम भुगतान: {{offer_text}} {{offer_until}} तक। किराया गुजरात → नेपाल {{fare_to_nepal}}। {{website}}"],
        'group_booking' => ['cat' => 'marketing', 'title' => 'Group booking offer', 'meta' => 'MARKETING',
            'en' => "👨‍👩‍👧‍👦 Travelling as a family or group? Seats are kept together in one cabin. Fare {{fare_to_nepal}} per seat. Call {{support_phone}} or book {{website}}",
            'ne' => "👨‍👩‍👧‍👦 परिवार वा समूहमा यात्रा? सबै सिट एउटै केबिनमा सँगै। प्रति सिट {{fare_to_nepal}}। फोन {{support_phone}} वा बुक {{website}}",
            'hi' => "👨‍👩‍👧‍👦 परिवार या ग्रुप में यात्रा? सभी सीटें एक केबिन में साथ। प्रति सीट {{fare_to_nepal}}। फोन {{support_phone}} या बुक {{website}}"],
        'dashain_special' => ['cat' => 'marketing', 'title' => 'Dashain special', 'meta' => 'MARKETING',
            'en' => "🪔 Going home for Dashain? {{offer_text}} Book your sleeper seat now: {{website}}",
            'ne' => "🪔 दशैंमा घर जाँदै हुनुहुन्छ? {{offer_text}} अहिले नै स्लिपर सिट बुक गर्नुहोस्: {{website}}",
            'hi' => "🪔 दशैं पर घर जा रहे हैं? {{offer_text}} अभी स्लीपर सीट बुक करें: {{website}}"],
        'tihar_special' => ['cat' => 'marketing', 'title' => 'Tihar special', 'meta' => 'MARKETING',
            'en' => "✨ Celebrate Tihar with family. {{offer_text}} Book early, seats fill fast: {{website}}",
            'ne' => "✨ तिहार परिवारसँग मनाउनुहोस्। {{offer_text}} चाँडै बुक गर्नुहोस्, सिट छिट्टै भरिन्छ: {{website}}",
            'hi' => "✨ तिहार परिवार के साथ मनाएँ। {{offer_text}} जल्दी बुक करें, सीटें जल्दी भरती हैं: {{website}}"],
        'diwali_offer' => ['cat' => 'marketing', 'title' => 'Diwali offer', 'meta' => 'MARKETING',
            'en' => "🪔 Happy Diwali from {{company_name}}! {{offer_text}} Book: {{website}}",
            'ne' => "🪔 {{company_name}} बाट दीपावलीको शुभकामना! {{offer_text}} बुक: {{website}}",
            'hi' => "🪔 {{company_name}} की ओर से दीपावली की शुभकामनाएँ! {{offer_text}} बुक: {{website}}"],
        'limited_time' => ['cat' => 'marketing', 'title' => 'Limited-time promotion', 'meta' => 'MARKETING',
            'en' => "⏳ Only till {{offer_until}}: {{offer_text}} Code {{discount_code}}. {{website}}",
            'ne' => "⏳ {{offer_until}} सम्म मात्र: {{offer_text}} कोड {{discount_code}}। {{website}}",
            'hi' => "⏳ सिर्फ़ {{offer_until}} तक: {{offer_text}} कोड {{discount_code}}। {{website}}"],
        'route_availability' => ['cat' => 'marketing', 'title' => 'Route availability update', 'meta' => 'MARKETING',
            'en' => "🚌 Daily sleeper bus Gujarat ⇄ Rupaidiha (Nepal border). Fare {{fare_to_nepal}} going, {{fare_to_india}} return. Book: {{website}}",
            'ne' => "🚌 दैनिक स्लिपर बस गुजरात ⇄ रुपैडिहा (नेपाल बोर्डर)। भाडा जाँदा {{fare_to_nepal}}, फर्कंदा {{fare_to_india}}। बुक: {{website}}",
            'hi' => "🚌 रोज़ाना स्लीपर बस गुजरात ⇄ रुपईडीहा (नेपाल बॉर्डर)। किराया जाते {{fare_to_nepal}}, लौटते {{fare_to_india}}। बुक: {{website}}"],
        // ---------------- Agent ----------------
        'agent_daily_summary' => ['cat' => 'agent', 'title' => 'Daily agent sales summary', 'meta' => 'UTILITY',
            'en' => "📊 {{agent_name}} ({{agent_code}}): this month {{sales_count}} tickets, {{sales_amount}}. Commission due to you {{commission_amount}}.",
            'ne' => "📊 {{agent_name}} ({{agent_code}}): यो महिना {{sales_count}} टिकट, {{sales_amount}}। तपाईंको कमिसन {{commission_amount}}।",
            'hi' => "📊 {{agent_name}} ({{agent_code}}): इस महीने {{sales_count}} टिकट, {{sales_amount}}। आपका कमीशन {{commission_amount}}।"],
        'agent_weekly_statement' => ['cat' => 'agent', 'title' => 'Weekly agent statement', 'meta' => 'UTILITY',
            'en' => "🧾 Statement for {{agent_name}} ({{agent_code}}): cash to deposit {{balance_due}}, commission {{commission_amount}}. Questions: {{support_phone}}",
            'ne' => "🧾 {{agent_name}} ({{agent_code}}) को विवरण: जम्मा गर्नुपर्ने नगद {{balance_due}}, कमिसन {{commission_amount}}। जानकारी: {{support_phone}}",
            'hi' => "🧾 {{agent_name}} ({{agent_code}}) का स्टेटमेंट: जमा करने वाली नकद {{balance_due}}, कमीशन {{commission_amount}}। जानकारी: {{support_phone}}"],
        'agent_commission' => ['cat' => 'agent', 'title' => 'Commission statement', 'meta' => 'UTILITY',
            'en' => "💰 {{agent_name}}, your commission balance is {{commission_amount}} ({{sales_count}} tickets this month).",
            'ne' => "💰 {{agent_name}}, तपाईंको कमिसन बाँकी {{commission_amount}} (यो महिना {{sales_count}} टिकट)।",
            'hi' => "💰 {{agent_name}}, आपका कमीशन बैलेंस {{commission_amount}} (इस महीने {{sales_count}} टिकट)।"],
        'agent_payment_reminder' => ['cat' => 'agent', 'title' => 'Pending payment reminder', 'meta' => 'UTILITY',
            'en' => "🔔 {{agent_name}} ({{agent_code}}): please deposit {{balance_due}} collected from passengers. Thank you.",
            'ne' => "🔔 {{agent_name}} ({{agent_code}}): यात्रुबाट उठाएको {{balance_due}} कार्यालयमा जम्मा गर्नुहोस्। धन्यवाद।",
            'hi' => "🔔 {{agent_name}} ({{agent_code}}): यात्रियों से ली गई {{balance_due}} राशि ऑफिस में जमा करें। धन्यवाद।"],
        'agent_policy_update' => ['cat' => 'agent', 'title' => 'Agent policy update', 'meta' => 'UTILITY',
            'en' => "📢 Agent notice from {{company_name}}: {{notice_text}}",
            'ne' => "📢 {{company_name}} बाट एजेन्ट सूचना: {{notice_text}}",
            'hi' => "📢 {{company_name}} की एजेंट सूचना: {{notice_text}}"],
        'agent_new_route' => ['cat' => 'agent', 'title' => 'New route notice', 'meta' => 'UTILITY',
            'en' => "🆕 Route notice for agents: {{notice_text}} Current fares: {{fare_to_nepal}} / {{fare_to_india}}.",
            'ne' => "🆕 एजेन्टका लागि रुट सूचना: {{notice_text}} हालको भाडा: {{fare_to_nepal}} / {{fare_to_india}}।",
            'hi' => "🆕 एजेंटों के लिए रूट सूचना: {{notice_text}} वर्तमान किराया: {{fare_to_nepal}} / {{fare_to_india}}।"],
        // ---------------- Staff & counter ----------------
        'staff_duty' => ['cat' => 'staff', 'title' => 'Staff duty notice', 'meta' => 'UTILITY',
            'en' => "👷 Duty notice: {{notice_text}}",
            'ne' => "👷 ड्युटी सूचना: {{notice_text}}",
            'hi' => "👷 ड्यूटी सूचना: {{notice_text}}"],
        'counter_update' => ['cat' => 'staff', 'title' => 'Counter operation update', 'meta' => 'UTILITY',
            'en' => "🏢 Counter update: {{notice_text}} Fares today: {{fare_to_nepal}} / {{fare_to_india}}.",
            'ne' => "🏢 काउन्टर सूचना: {{notice_text}} आजको भाडा: {{fare_to_nepal}} / {{fare_to_india}}।",
            'hi' => "🏢 काउंटर सूचना: {{notice_text}} आज का किराया: {{fare_to_nepal}} / {{fare_to_india}}।"],
        'emergency_notice' => ['cat' => 'staff', 'title' => 'Emergency notice', 'meta' => 'UTILITY',
            'en' => "🚨 URGENT from {{company_name}}: {{notice_text}} Call {{support_phone}}.",
            'ne' => "🚨 {{company_name}} बाट जरुरी: {{notice_text}} फोन {{support_phone}}।",
            'hi' => "🚨 {{company_name}} से ज़रूरी: {{notice_text}} फोन {{support_phone}}।"],
        'daily_departure' => ['cat' => 'staff', 'title' => 'Daily departure information', 'meta' => 'UTILITY',
            'en' => "🚌 Today's departure: {{notice_text}}",
            'ne' => "🚌 आजको प्रस्थान: {{notice_text}}",
            'hi' => "🚌 आज का प्रस्थान: {{notice_text}}"],
        'company_announcement' => ['cat' => 'staff', 'title' => 'Company announcement', 'meta' => 'UTILITY',
            'en' => "📣 {{company_name}}: {{notice_text}}",
            'ne' => "📣 {{company_name}}: {{notice_text}}",
            'hi' => "📣 {{company_name}}: {{notice_text}}"],
    ];

    private function __construct() {}

    /** The office's edits, keyed "tkey|lang" — empty when the table is not there yet. */
    private static function edits(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        try {
            foreach (Database::fetchAll('SELECT tkey, lang, body, meta_name, meta_status, updated_at FROM wa_tpl_library') as $r) {
                $cache[$r['tkey'] . '|' . $r['lang']] = $r;
            }
        } catch (Throwable $e) {
            $cache = [];
        }
        return $cache;
    }

    public static function flush(): void
    {
        // the static cache lives inside edits(); a fresh request re-reads it
    }

    public static function exists(string $key): bool
    {
        return isset(self::BUILTIN[$key]);
    }

    /** Body for one template + language: the saved edit, else the built-in draft ('' for gu until written). */
    public static function body(string $key, string $lang): string
    {
        $e = self::edits()[$key . '|' . $lang] ?? null;
        if ($e !== null && trim((string) $e['body']) !== '') {
            return (string) $e['body'];
        }
        return (string) (self::BUILTIN[$key][$lang] ?? '');
    }

    /** @return array{meta_name:string, meta_status:string, edited:bool, updated_at:string} */
    public static function state(string $key, string $lang): array
    {
        $e = self::edits()[$key . '|' . $lang] ?? null;
        return [
            'meta_name'   => (string) ($e['meta_name'] ?? ''),
            'meta_status' => (string) ($e['meta_status'] ?? 'draft'),
            'edited'      => $e !== null,
            'updated_at'  => (string) ($e['updated_at'] ?? ''),
        ];
    }

    /** @return list<string> the {{variables}} a body uses, in order of first appearance */
    public static function varsIn(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $body, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** @return list<string> placeholders that are not in VARS (typos the office must fix) */
    public static function unknownVars(string $body): array
    {
        return array_values(array_filter(self::varsIn($body), static fn(string $v): bool => !isset(self::VARS[$v])));
    }

    /**
     * Save the office's copy. Refuses unknown placeholders and empty bodies.
     * meta_status records what Meta answered (draft / submitted / approved / rejected).
     */
    public static function save(string $key, string $lang, string $body, string $metaName, string $metaStatus, int $by): void
    {
        if (!self::exists($key) || !isset(self::LANGS[$lang])) {
            throw new InvalidArgumentException('Unknown template or language.');
        }
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > 1024) {
            throw new InvalidArgumentException('The message must be 1–1024 characters (Meta\'s limit).');
        }
        $bad = self::unknownVars($body);
        if ($bad !== []) {
            throw new InvalidArgumentException('Unknown variable: {{' . implode('}}, {{', $bad) . '}} — use only the listed variables.');
        }
        $metaStatus = in_array($metaStatus, ['draft', 'submitted', 'approved', 'rejected'], true) ? $metaStatus : 'draft';
        $metaName = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($metaName)), 0, 80);
        Database::run(
            'INSERT INTO wa_tpl_library (tkey, lang, body, meta_name, meta_status, updated_by)
             VALUES (:k, :l, :b, :n, :s, :u)
             ON DUPLICATE KEY UPDATE body = VALUES(body), meta_name = VALUES(meta_name),
                                     meta_status = VALUES(meta_status), updated_by = VALUES(updated_by)',
            ['k' => $key, 'l' => $lang, 'b' => $body, 'n' => $metaName !== '' ? $metaName : null, 's' => $metaStatus, 'u' => $by]
        );
        Logger::audit('wa_template.save', 'wa_template', $key . '/' . $lang, null, null, 'status ' . $metaStatus);
    }

    /** Back to the shipped draft. */
    public static function reset(string $key, string $lang): void
    {
        Database::run('DELETE FROM wa_tpl_library WHERE tkey = :k AND lang = :l', ['k' => $key, 'l' => $lang]);
        Logger::audit('wa_template.reset', 'wa_template', $key . '/' . $lang, null, null, 'back to the built-in draft');
    }

    /* ------------------------------------------------------------------
     *  Values — from records only
     * ------------------------------------------------------------------ */

    /** @return array<string, string> the always-available facts (settings, fares, running offer) */
    public static function liveValues(): array
    {
        $co  = Settings::company();
        $out = [
            'company_name'  => (string) ($co['name'] ?? ''),
            'support_phone' => (string) ($co['phone'] ?? ''),
            'website'       => (string) ($co['web'] ?? ''),
        ];
        try {
            require_once __DIR__ . '/fare.php';
            $f = Fare::dirFares();
            $out['fare_to_nepal'] = inr((float) $f['toNepal']);
            $out['fare_to_india'] = inr((float) $f['toIndia']);
            $o = Fare::runningOffers()[0] ?? null;
            if ($o !== null && (float) $o['value'] > 0) {
                $out['discount_code'] = (string) $o['code'];
                $out['offer_text']    = Fare::offerLine($o) . '.';
                if ($o['until'] !== '') {
                    $out['offer_until'] = date('j M Y', (int) strtotime($o['until']));
                }
            }
        } catch (Throwable $e) {
            // no fares / coupons: those variables stay missing
        }
        return array_filter($out, static fn($v): bool => $v !== '');
    }

    /** @return array<string, string> booking facts by PNR (empty when the PNR is unknown) */
    public static function bookingValues(string $pnr): array
    {
        $pnr = strtoupper(trim($pnr));
        if ($pnr === '') {
            return [];
        }
        $b = Database::fetch(
            "SELECT b.id, b.pnr, b.status, b.total_amount, b.currency, b.refund_amount, b.booking_mode,
                    l.travel_date, l.boarding_stop, l.drop_stop, l.boarding_time,
                    r.id AS route_id_for_stop, r.from_city, r.to_city, r.coach_type,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, bus.bus_number
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bus ON bus.id = s.bus_id
              WHERE b.pnr = :p LIMIT 1",
            ['p' => $pnr]
        );
        if ($b === null) {
            return [];
        }
        require_once __DIR__ . '/ticket.php';
        require_once __DIR__ . '/seats.php';
        $pax = Database::fetchAll('SELECT full_name, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $b['id']]);
        $seatList = array_values(array_filter(array_map(
            static fn($p) => Seats::displayLabel((string) $p['seat_no'], (string) ($b['coach_type'] ?? 'sleeper'), (string) ($b['booking_mode'] ?? 'sharing')),
            $pax
        )));
        $stop = static function (string $label): string {
            $s = preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label) ?? $label;
            $s = preg_replace('/\s*@\s*[0-2]?\d:\d{2}\s*$/u', '', trim($s)) ?? $s;
            return trim($s, " \t·");
        };
        $pickTime = '';
        if (preg_match('/@\s*([0-2]?\d:\d{2})/', (string) $b['boarding_stop'], $tm) === 1) {
            $pickTime = $tm[1];
        } elseif (!empty($b['boarding_time'])) {
            $pickTime = substr((string) $b['boarding_time'], 0, 5);
        }
        $paid = 0.0;
        try {
            $paid = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $b['id']], 0);
        } catch (Throwable $e) {
            $paid = 0.0;
        }
        $total = (float) $b['total_amount'];
        $due = (string) $b['status'] === 'confirmed' && $paid <= 0 ? 0.0 : max(0.0, $total - $paid);
        $out = [
            'customer_name'  => trim((string) ($pax[0]['full_name'] ?? '')),
            'booking_id'     => (string) $b['pnr'],
            'travel_date'    => formatDate((string) $b['travel_date'], 'D, j M Y'),
            'departure_time' => $pickTime !== '' ? $pickTime : substr((string) ($b['dep_time'] ?? ''), 0, 5),
            'route'          => ($stop((string) $b['boarding_stop']) ?: (string) $b['from_city']) . ' → ' . ($stop((string) $b['drop_stop']) ?: (string) $b['to_city']),
            'pickup_point'   => $stop((string) $b['boarding_stop']),
            'pickup_time'    => $pickTime,
            'seat_number'    => implode(', ', $seatList),
            'bus_number'     => trim((string) ($b['bus_number'] ?? '')),
            'ticket_price'   => ((string) $b['currency'] === 'NPR' ? 'NPR ' . number_format($total) : inr($total)),
            'amount_due'     => $due > 0 ? inr($due) : '',
            'refund_amount'  => (float) ($b['refund_amount'] ?? 0) > 0 ? inr((float) $b['refund_amount']) : '',
            'ticket_link'    => Ticket::downloadUrl((string) $b['pnr']),
        ];
        $geo = Ticket::pickupGeo($b);
        if ($geo !== null) {
            $out['pickup_map'] = Ticket::pickupMapsUrl($geo);
        }
        return array_filter($out, static fn($v): bool => $v !== '');
    }

    /** @return array<string, string> agent facts (empty when the id is not an agent / staff seller) */
    public static function agentValues(int $agentId): array
    {
        if ($agentId <= 0) {
            return [];
        }
        $a = Database::fetch('SELECT id, full_name, username FROM admins WHERE id = :i', ['i' => $agentId]);
        if ($a === null) {
            return [];
        }
        require_once __DIR__ . '/agentwallet.php';
        $bal = AgentWallet::balances($agentId);
        $from = date('Y-m-01');
        $sales = Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt FROM bookings
              WHERE sold_by_admin_id = :a AND status = 'confirmed' AND created_at >= :f",
            ['a' => $agentId, 'f' => $from . ' 00:00:00']
        );
        $out = [
            'agent_name'        => trim((string) ($a['full_name'] ?: $a['username'])),
            'agent_code'        => AgentWallet::agentCodeLabel($agentId),
            'commission_amount' => inr((float) $bal['commission']),
            'balance_due'       => inr(max(0.0, (float) $bal['cash'])),
            'sales_count'       => (string) (int) ($sales['n'] ?? 0),
            'sales_amount'      => inr((float) ($sales['amt'] ?? 0)),
        ];
        return array_filter($out, static fn($v): bool => $v !== '');
    }

    /**
     * Fill a body. $ctx: pnr, agent_id, notice_text (manual). Returns the
     * text with every resolvable variable replaced and the list of
     * variables that could NOT be answered — those stay as {{name}} so a
     * sender can never mistake a gap for a value.
     *
     * @return array{text:string, missing:list<string>, values:array<string,string>}
     */
    public static function resolve(string $body, array $ctx = []): array
    {
        $vals = self::liveValues();
        if (!empty($ctx['pnr'])) {
            $vals = array_merge($vals, self::bookingValues((string) $ctx['pnr']));
        }
        if (!empty($ctx['agent_id'])) {
            $vals = array_merge($vals, self::agentValues((int) $ctx['agent_id']));
        }
        $notice = trim((string) ($ctx['notice_text'] ?? ''));
        if ($notice !== '') {
            $vals['notice_text'] = mb_substr(preg_replace('/\s+/u', ' ', $notice) ?? $notice, 0, 600);
        }
        $missing = [];
        $text = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vals, &$missing): string {
            if (isset($vals[$m[1]]) && $vals[$m[1]] !== '') {
                return $vals[$m[1]];
            }
            $missing[] = $m[1];
            return '{{' . $m[1] . '}}';
        }, $body) ?? $body;
        $used = array_flip(self::varsIn($body));
        return ['text' => $text, 'missing' => array_values(array_unique($missing)), 'values' => array_intersect_key($vals, $used)];
    }

    /**
     * Meta wants numbered placeholders. Returns the body with {{1}}, {{2}} …
     * and the variable each number stands for (the AiSensy campaign maps them).
     *
     * @return array{body:string, map:list<string>}
     */
    public static function forMeta(string $body): array
    {
        $vars = self::varsIn($body);
        $num  = array_flip($vars);
        $out  = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn(array $m): string => '{{' . ($num[$m[1]] + 1) . '}}', $body) ?? $body;
        return ['body' => $out, 'map' => $vars];
    }

    /* ------------------------------------------------------------------
     *  AI wording — suggestion only, checked against the facts
     * ------------------------------------------------------------------ */

    /**
     * Why a suggested wording is refused, or '' when it passes. The rules
     * are the ones the owner set for every AI message: the AI never invents
     * a price, date, discount or availability.
     */
    public static function checkSuggestion(string $original, string $suggestion): string
    {
        $s = trim($suggestion);
        if ($s === '' || mb_strlen($s) > 1024) {
            return 'empty or longer than 1024 characters';
        }
        $a = self::varsIn($original);
        $b = self::varsIn($s);
        sort($a); sort($b);
        if ($a !== $b) {
            return 'the variables changed (must keep exactly: {{' . implode('}}, {{', self::varsIn($original)) . '}})';
        }
        if (self::unknownVars($s) !== []) {
            return 'unknown variable';
        }
        // Numbers: only those already in the draft (outside placeholders) or a live fact.
        $strip = static fn(string $t): string => (string) preg_replace('/\{\{[^}]*\}\}/', ' ', $t);
        $digits = static function (string $t): array {
            $t = strtr($t, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
            preg_match_all('/\d+/', $t, $m);
            return array_map(static fn($n) => ltrim($n, '0') === '' ? '0' : ltrim($n, '0'), $m[0] ?? []);
        };
        $ok = $digits($strip($original));
        foreach (self::liveValues() as $v) {
            $ok = array_merge($ok, $digits((string) $v));
        }
        $ok[] = date('Y');
        foreach ($digits($strip($s)) as $n) {
            if (!in_array($n, $ok, true)) {
                return 'invented the number ' . $n;
            }
        }
        // A promise the draft never made.
        $promise = '/(%|\bfree\b|फ्री|मुफ्त|निःशुल्क|निशुल्क|cashback|guarantee|गारंटी|ग्यारेन्टी|\bdiscount\b|छुट(?![\x{093E}-\x{094D}])|छूट)/iu';
        if (preg_match($promise, $strip($s), $hit) === 1 && preg_match($promise, $strip($original)) !== 1) {
            return 'promises "' . $hit[0] . '" which the draft does not';
        }
        if (preg_match('~https?://|www\.|wa\.me~iu', $strip($s)) === 1 && preg_match('~https?://|www\.|wa\.me~iu', $strip($original)) !== 1) {
            return 'added a link (links come only from {{website}} / {{ticket_link}} / {{pickup_map}})';
        }
        return '';
    }

    /**
     * Another wording from the One AI. $length: short | medium | detailed.
     *
     * @return array{text:string, provider:string}|array{error:string}
     */
    public static function aiRewrite(string $key, string $lang, string $length = 'medium'): array
    {
        if (!self::exists($key) || !isset(self::LANGS[$lang])) {
            return ['error' => 'Unknown template or language.'];
        }
        require_once __DIR__ . '/aibrain.php';
        if (!AiBrain::configured()) {
            return ['error' => 'No AI key is set (Admin → One AI). The drafts work without it.'];
        }
        $src = self::body($key, $lang);
        if ($src === '') {
            $src = self::body($key, 'en');
        }
        $tpl = self::BUILTIN[$key];
        $len = ['short' => 'under 160 characters', 'medium' => 'under 320 characters', 'detailed' => 'under 600 characters'][$length] ?? 'under 320 characters';
        $langName = ['en' => 'English', 'ne' => 'Nepali (Devanagari)', 'hi' => 'Hindi (Devanagari)', 'gu' => 'Gujarati (Gujarati script)'][$lang];
        $system = "You rewrite WhatsApp message templates for S Hari Global, an India-Nepal sleeper bus company.\n"
            . "Rules you must follow exactly:\n"
            . "- Write in {$langName}, {$len}, friendly and professional, at most 2 emoji.\n"
            . "- Keep EVERY placeholder exactly as written, e.g. {{customer_name}}; do not add new placeholders, do not translate them.\n"
            . "- Never write a price, date, time, discount, percentage, seat count, phone number or link yourself — those come only from the placeholders.\n"
            . "- Do not promise anything the original does not say.\n"
            . "- Reply with the message text only, no quotes, no explanation.";
        $ans = AiBrain::text($system, [['role' => 'user', 'content' => "Template purpose: " . $tpl['title'] . "\nOriginal:\n" . $src]], 500, 'wa-template');
        if ($ans === null) {
            return ['error' => 'The AI did not answer — try again.'];
        }
        $text = trim(trim((string) $ans['text']), "\"'`“”");
        $why = self::checkSuggestion($src, $text);
        if ($why !== '') {
            return ['error' => 'AI wording refused: ' . $why . '. Try again or edit by hand.'];
        }
        return ['text' => $text, 'provider' => (string) $ans['provider']];
    }
}
