<?php
/**
 * POST /api/enquiry.php — capture a homepage "Quick Booking" enquiry.
 *
 * The redesigned homepage opens straight onto a passenger card. Pressing
 * "Next →" saves the passenger's intent to the database immediately — no
 * OTP, no payment — so staff can follow up, and the visitor then carries
 * on into the normal search → seat → payment flow. Captured leads show up
 * in the admin panel under "Enquiries".
 *
 * Body: { name, phone, gender?, nationality?, age?, travelDate?, seats?, note?, source? }
 *
 * source 'complaint' is the SHG Sahayak complaint desk (Master Blueprint
 * Module 1.2/4): the chatbot files the category, reference id and PNR in
 * `note`, and the complaint lands in the same admin Enquiries inbox staff
 * already watch — one queue, not a second disconnected system.
 *
 * source 'business' (10 Oct 2026): { name, phone, service, note?, lang?,
 * website: "" } from the /business "notify me" forms — honeypot `website`,
 * `note` stored as "Service: <key> · <note>", answer in `lang`.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    // Generous limit — this is a low-friction lead form, but still bounded
    // so a script cannot flood the enquiries table.
    Security::requireRateLimit('enquiry_create', Security::clientIp(), 20, 300);

    // 10 Oct 2026 (review): fields read as raw JSON may be arrays — never cast
    // them with (string) (that is an "Array to string conversion" 500).
    $langIn = Response::field('lang', 'en');
    $lang   = is_string($langIn) && in_array($langIn, ['en', 'hi', 'ne'], true) ? $langIn : 'en';
    $bizMessage = match ($lang) {
        'hi' => 'धन्यवाद! आपकी रुचि दर्ज हो गई — सेवा खुलते ही ऑफ़िस आपको कॉल करेगा। कुछ बुक या चार्ज नहीं हुआ।',
        'ne' => 'धन्यवाद! तपाईंको रुचि दर्ता भयो — सेवा खुल्नेबित्तिकै कार्यालयले फोन गर्नेछ। केही बुक वा शुल्क लागेको छैन।',
        default => 'Thank you! Your interest is noted — our office will call you the day this service opens. Nothing is booked or charged.',
    };
    if (Response::field('source', '') === 'business') {
        // Honeypot (the hidden "website" box, as on the tour form), checked
        // before any field validation: a bot always gets the same ok answer
        // and nothing is stored.
        $hp = Response::field('website', '');
        if (is_string($hp) ? trim($hp) !== '' : !empty($hp)) {
            Logger::info('Business interest: honeypot filled', ['ip' => Security::clientIp()]);
            Response::success(['id' => 0, 'travelDate' => null, 'seats' => 1, 'ticket' => null], $bizMessage);
        }
    }

    $name  = Security::clean(Response::field('name', ''), 120);
    $phone = Security::digits(Response::field('phone', ''), 20);

    $fields = [];
    if ($name === '') {
        $fields['name'] = 'Please enter the passenger name.';
    }
    if ($phone === '') {
        $fields['phone'] = 'Please enter a mobile number.';
    } elseif (!Security::isValidPhone($phone)) {
        $fields['phone'] = 'Enter a valid mobile number.';
    }
    if ($fields !== []) {
        Response::invalid($fields);
    }

    $genderIn = Response::field('gender', '');
    $gender   = in_array($genderIn, ['Male', 'Female', 'Other'], true) ? $genderIn : null;

    $nationality = Security::clean(Response::field('nationality', ''), 60);
    $nationality = $nationality !== '' ? $nationality : null;

    $ageRaw = (int) Response::field('age', 0);
    $age    = ($ageRaw >= 1 && $ageRaw <= 120) ? $ageRaw : null;

    $travelDate = Security::clean(Response::field('travelDate', ''), 10);
    if ($travelDate !== '' && (!Security::isValidDate($travelDate) || $travelDate < todayISO())) {
        Response::invalid(['travelDate' => 'Choose a valid travel date.']);
    }
    $travelDate = $travelDate !== '' ? $travelDate : null;

    $seats = max(1, min(40, (int) Response::field('seats', 1)));

    $note = Security::clean(Response::field('note', ''), 255);
    $note = $note !== '' ? $note : null;

    // Whitelisted — never write a caller-invented source into the table.
    // 'agent_apply' is someone asking to BECOME a counter agent. It lands in
    // the same admin Enquiries inbox staff already watch, and the office then
    // phones them and creates the account by hand in Staff & Agents — there is
    // deliberately no self-service path from this form to a real login.
    $sourceIn = Response::field('source', 'quick_booking'); // strict in_array below: a non-string never matches
    // 'callback' (24 Sep 2026): the contact button on every screen — "call me
    // back". Lands in the same Enquiries inbox; the office phones them.
    // 'tour' (9 Oct 2026): a tour-package lead. The full form (package, pax,
    // ref TRV-…, budgets) is api/tour-enquiry.php; this only accepts the tag.
    // 'business' (10 Oct 2026, BIZ): "notify me" on a coming-soon line of
    // /business (import-export, truck & bulk, packaging, shop-to-Nepal). The
    // service key is kept in `note` as "Service: <key>" so the inbox and the
    // CSV show which line the person wants; the office calls them the day it
    // opens. Nothing is accepted, booked or charged.
    $source   = in_array($sourceIn, ['quick_booking', 'complaint', 'agent_apply', 'callback', 'tour', 'business'], true)
        ? $sourceIn
        : 'quick_booking';

    if ($source === 'business') {
        $svcRaw = Response::field('service', '');
        $svcIn = is_string($svcRaw) ? Security::clean($svcRaw, 30) : '';
        $svc   = in_array($svcIn, ['import_export', 'truck_bulk', 'packaging', 'shop_to_nepal'], true) ? $svcIn : 'general';
        $note  = mb_substr('Service: ' . $svc . ($note !== null ? ' · ' . $note : ''), 0, 255);
    }

    $id = Database::insert('enquiries', [
        'name'        => $name,
        'phone'       => $phone,
        'gender'      => $gender,
        'nationality' => $nationality,
        'age'         => $age,
        'travel_date' => $travelDate,
        'seats'       => $seats,
        'note'        => $note,
        'source'      => $source,
        'status'      => 'new',
        'ip'          => Security::clientIp(),
        'user_agent'  => Security::userAgent(255),
    ]);

    // 5 Oct 2026: a complaint or a call-me-back is also a Help Desk ticket, so
    // the person gets a REAL number (HD-0051) they can quote and check on
    // /help.php — the chat used to show a reference the browser made up.
    $ticket = null;
    if (in_array($source, ['complaint', 'callback'], true)) {
        try {
            require_once INCLUDE_PATH . '/helpdesk.php';
            if (HelpDesk::on()) {
                $cat = 'callback';
                if ($source === 'complaint') {
                    $cat = HelpDesk::guessCategory((string) $note);
                }
                $pnr = preg_match('/\b(SHG-\d{4}-\d{3,6}|SHG-?\d{3,6})\b/i', (string) $note, $m) === 1 ? strtoupper($m[1]) : '';
                $r = HelpDesk::open([
                    'name' => $name, 'phone' => $phone, 'category' => $cat,
                    'country' => str_starts_with($phone, '977') ? 'NP' : (string) Response::field('country', ''),
                    'message' => $source === 'callback' ? 'Please call me back.' . ($note !== null ? ' ' . $note : '') : (string) $note,
                    'pnr' => $pnr, 'source' => $source === 'complaint' ? 'chat' : 'callback',
                    'lang' => (string) Response::field('lang', 'ne'),
                ]);
                if ($r['ok']) {
                    $ticket = ['ref' => $r['ref'], 'token' => $r['token'] ?? null, 'url' => HelpDesk::statusUrl((string) $r['ref'])];
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'helpdesk');
        }
    }

    // The quick-booking wording ("let's pick your seat") is wrong for the other
    // two desks — an applicant is not about to choose a berth.
    $message = match ($source) {
        'callback'    => 'Got it — the office will call you back on this number. कार्यालयबाट यही नम्बरमा फोन आउनेछ। / ऑफ़िस इसी नंबर पर कॉल करेगा।',
        'agent_apply' => 'Application received — our office will call you on this number. आवेदन प्राप्त भयो — कार्यालयबाट फोन आउनेछ।',
        'tour'        => 'Tour enquiry received — our office will call you with a quote. टुर सोधपुछ प्राप्त भयो — कार्यालयबाट फोन आउनेछ।',
        'business'    => $bizMessage,
        'complaint'   => 'Complaint filed — our team will get back to you.',
        default       => 'Details saved — let’s pick your seat.',
    };
    if ($ticket !== null) {
        $message .= ' Help desk number: ' . $ticket['ref'] . ' / सहायता नम्बर: ' . $ticket['ref'];
    }

    Response::success([
        'id'         => $id,
        'travelDate' => $travelDate,
        'seats'      => $seats,
        'ticket'     => $ticket,
    ], $message);
} catch (Throwable $e) {
    Response::serverError($e);
}
