<?php
/**
 * /api/tour-enquiry.php — "Apply / Enquire" for a tour package (9 Oct 2026).
 * Modelled on api/agreement-apply.php.
 *
 * GET  -> {"ok":true,"csrf":"<token>"}  starts the session and hands the
 *         cached /tours pages their CSRF token (the pages never embed one).
 * POST -> Content-Type: application/json, X-CSRF-Token: <token>
 *         {"package":"<slug>"|"", "name","phone","adults","children",
 *          "month":"YYYY-MM","date":"YYYY-MM-DD","from_city","message",
 *          "lang":"ne"|"hi"|"en","website":""}
 *      200 {"ok":true,"ref":"TRV-000123","message":"…","duplicate":bool}
 *      422 {"ok":false,"error":"…","fields":{"phone":"…"}}
 *      419 bad / missing token, 413 too large, 429 too many, 405 other methods.
 *
 * Gates, in order (as on the agreement form):
 *   token -> body ≤ 32 KB -> a loose per-IP budget on every POST (30 an hour)
 *   -> validation (422, not charged below) -> honeypot ("website" filled = a
 *   bot: the same answer shape, a believable ref, nothing stored) -> an EXACT
 *   resend of an enquiry stored in the last 24 h = its ref, nothing stored
 *   -> the real budgets for a row about to be stored: 10 an hour per IP
 *   (skipped for signed-in staff), 3 a day per phone, 30 an hour and 150 a
 *   day for the whole site -> store in `enquiries` (source = 'tour') -> one
 *   WhatsApp line to the office, at most 10 an hour, never fatal.
 *
 * NEVER takes a payment and never confirms anything: the office calls back
 * with a quote; any bus seat is then booked by staff through the normal flow.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/contentpages.php';

const TRV_BODY_MAX = 32_000;

/** @return array<string,string> */
function trv_words(string $lang): array
{
    $w = [
        'en' => ['ok' => 'Thank you! Your enquiry number is {ref}. Our office will call you with a quote — nothing is booked or charged until you agree.',
                 'dup' => 'We already have this enquiry — number {ref}. Our office will call you.',
                 'invalid' => 'Please check the highlighted boxes.', 'busy' => 'Too many enquiries right now. Please call the office: {phone}',
                 'name' => 'Please write your name.', 'phone' => 'Please enter a valid mobile number.', 'package' => 'That package is not available.',
                 'date' => 'Choose a travel date from today onwards.', 'month' => 'Choose this month or a later one.', 'pax' => 'Adults 1–40, children 0–20.'],
        'hi' => ['ok' => 'धन्यवाद! आपका पूछताछ नंबर {ref} है। ऑफ़िस कोटेशन के साथ कॉल करेगा — आपकी सहमति के बिना कुछ बुक या चार्ज नहीं होगा।',
                 'dup' => 'यह पूछताछ पहले से मिल चुकी है — नंबर {ref}। ऑफ़िस आपको कॉल करेगा।',
                 'invalid' => 'कृपया लाल बॉक्स जाँचें।', 'busy' => 'अभी बहुत ज़्यादा पूछताछ हैं। कृपया ऑफ़िस को कॉल करें: {phone}',
                 'name' => 'कृपया अपना नाम लिखें।', 'phone' => 'कृपया सही मोबाइल नंबर लिखें।', 'package' => 'यह पैकेज उपलब्ध नहीं है।',
                 'date' => 'आज या बाद की तारीख चुनें।', 'month' => 'यह या आगे का महीना चुनें।', 'pax' => 'वयस्क 1–40, बच्चे 0–20।'],
        'ne' => ['ok' => 'धन्यवाद! तपाईंको सोधपुछ नम्बर {ref} हो। कार्यालयले कोटेसनसहित फोन गर्नेछ — तपाईंको सहमतिबिना केही बुक वा शुल्क लाग्दैन।',
                 'dup' => 'यो सोधपुछ पहिल्यै आइसकेको छ — नम्बर {ref}। कार्यालयले फोन गर्नेछ।',
                 'invalid' => 'कृपया रातो भएका बाकस जाँच्नुहोस्।', 'busy' => 'अहिले धेरै सोधपुछ आएका छन्। कृपया कार्यालयमा फोन गर्नुहोस्: {phone}',
                 'name' => 'कृपया आफ्नो नाम लेख्नुहोस्।', 'phone' => 'कृपया सही मोबाइल नम्बर लेख्नुहोस्।', 'package' => 'यो प्याकेज उपलब्ध छैन।',
                 'date' => 'आज वा पछिको मिति छान्नुहोस्।', 'month' => 'यो वा पछिको महिना छान्नुहोस्।', 'pax' => 'वयस्क १–४०, बालबालिका ०–२०।'],
    ];
    return $w[$lang] ?? $w['ne'];
}

function trv_ref(int $id): string
{
    return 'TRV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function trv_answer(string $ref, bool $duplicate, string $lang): never
{
    $w = trv_words($lang);
    Response::json(['ok' => true, 'ref' => $ref, 'message' => str_replace('{ref}', $ref, $w[$duplicate ? 'dup' : 'ok']), 'duplicate' => $duplicate]);
}

/** Which of the 9 Oct columns exist (the code may ship before the migration). @return array<string,bool> */
function trv_cols(): array
{
    $have = [];
    try {
        foreach (Database::fetchAll("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries'") as $r) {
            $have[(string) $r['COLUMN_NAME']] = true;
        }
    } catch (Throwable $e) {
        // keep empty: only the basic columns are written
    }
    return $have;
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        Security::requireRateLimit('trv_token', Security::clientIp(), 60, 300);
        Response::json(['ok' => true, 'csrf' => Security::csrfToken()]);
    }
    if ($method !== 'POST') {
        Response::error('This endpoint takes GET or POST.', 405);
    }

    Security::requireCsrf();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > TRV_BODY_MAX) {
        Response::error('That is too large to send.', 413);
    }
    Response::$maxInputBytes = TRV_BODY_MAX;

    $lang = (string) Response::field('lang', 'ne');
    $lang = in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'ne';
    $w    = trv_words($lang);
    $ip   = Security::clientIp();
    $busy = str_replace('{phone}', Settings::officePhone(), $w['busy']);

    if (!Security::rateLimit('trv_ip', $ip, 30, 3600)) {
        Response::error($busy, 429);
    }

    /* ---- validation ---- */
    $one = static fn(mixed $v, int $max): string => Security::clean(trim((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '')), $max);
    $errors = [];
    $name = $one(Response::field('name', ''), 120);
    if (mb_strlen($name) < 2) {
        $errors['name'] = $w['name'];
    }
    $phoneRaw = is_scalar(Response::field('phone', '')) ? (string) Response::field('phone', '') : '';
    $phone = Security::digits($phoneRaw, 20);
    if (str_starts_with($phone, '00')) {
        $phone = substr($phone, 2);
    }
    if (!Security::isValidPhone($phone)) {
        $errors['phone'] = $w['phone'];
    }
    $pkg = null;
    $slug = $one(Response::field('package', ''), 80);
    if ($slug !== '') {
        $pkg = ContentPages::package($slug);
        if ($pkg === null || $pkg['status'] !== 'published') {
            $errors['package'] = $w['package'];
            $pkg = null;
        }
    }
    $adultsIn = Response::field('adults', 1);
    $kidsIn = Response::field('children', 0);
    $adults = is_numeric($adultsIn) ? (int) $adultsIn : -1;
    $kids = $kidsIn === '' || $kidsIn === null ? 0 : (is_numeric($kidsIn) ? (int) $kidsIn : -1);
    if ($adults < 1 || $adults > 40 || $kids < 0 || $kids > 20) {
        $errors['adults'] = $w['pax'];
    }
    $date = $one(Response::field('date', ''), 10);
    if ($date !== '' && (!Security::isValidDate($date) || $date < todayISO())) {
        $errors['date'] = $w['date'];
    }
    $monthIn = $one(Response::field('month', ''), 30);
    $month = '';
    if ($monthIn !== '') {
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthIn, $mm) === 1) {
            if ((int) $mm[2] < 1 || (int) $mm[2] > 12 || $monthIn < date('Y-m')) {
                $errors['month'] = $w['month'];
            } else {
                $month = $monthIn;
            }
        } else {
            $month = $monthIn;   // a browser without a month picker: kept as typed ("Nov 2026")
        }
    }
    $from = $one(Response::field('from_city', ''), 80);
    $msgRaw = Response::field('message', '');
    $message = Security::clean(is_scalar($msgRaw) ? (string) $msgRaw : '', 1000);
    if ($errors !== []) {
        Response::invalid($errors, $w['invalid']);
    }

    $title = $pkg !== null ? (string) $pkg['title_en'] : 'Custom trip';
    $details = implode("\n", array_filter([
        'Package: ' . $title . ($pkg !== null ? ' (' . $pkg['slug'] . ')' : ''),
        'Travellers: ' . $adults . ' adult(s)' . ($kids > 0 ? ', ' . $kids . ' child(ren)' : ''),
        $month !== '' ? 'Month: ' . $month : '',
        $date !== '' ? 'Date: ' . $date : '',
        $from !== '' ? 'From: ' . $from : '',
        $message !== '' ? 'Message: ' . $message : '',
        'Language: ' . $lang,
    ]));
    $note = mb_substr('Tour: ' . $title . ' · ' . $adults . ($kids > 0 ? '+' . $kids : '') . ' pax'
        . ($month !== '' ? ' · ' . $month : '') . ($date !== '' ? ' · ' . $date : '') . ($from !== '' ? ' · from ' . $from : ''), 0, 255);

    /* ---- honeypot ---- */
    $hp = Response::field('website', '');
    if (is_string($hp) ? trim($hp) !== '' : !empty($hp)) {
        Logger::info('Tour enquiry: honeypot filled', ['ip' => $ip]);
        $key = hash('sha256', $phone . '|' . $details);
        $seen = is_array($_SESSION['trv_hp'] ?? null) ? $_SESSION['trv_hp'] : [];
        $dup = isset($seen[$key]);
        if (!$dup) {
            $top = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM enquiries', [], 0);
            $seen[$key] = trv_ref($top + random_int(1, 3));
            $_SESSION['trv_hp'] = array_slice($seen, -20, null, true);
        }
        trv_answer((string) $seen[$key], $dup, $lang);
    }

    $cols = trv_cols();
    $full = isset($cols['details'], $cols['ref'], $cols['package_id'], $cols['pax_adults'], $cols['pax_children'], $cols['from_city'], $cols['lang']);

    /* ---- the same enquiry again (double tap, slow network) ---- */
    $since = date('Y-m-d H:i:s', time() - 86400);
    $prev = $full
        ? Database::fetch("SELECT id, ref FROM enquiries WHERE source = 'tour' AND phone = :p AND details = :d AND created_at >= :s ORDER BY id DESC LIMIT 1",
            ['p' => $phone, 'd' => $details, 's' => $since])
        : Database::fetch("SELECT id FROM enquiries WHERE source = 'tour' AND phone = :p AND note = :n AND created_at >= :s ORDER BY id DESC LIMIT 1",
            ['p' => $phone, 'n' => $note, 's' => $since]);
    if ($prev !== null) {
        trv_answer((string) (($prev['ref'] ?? '') ?: trv_ref((int) $prev['id'])), true, $lang);
    }

    /* ---- the budgets, only for a row about to be stored ---- */
    if (!Auth::isAdmin() && !Security::rateLimit('trv_ip_ok', $ip, 10, 3600)) {
        Response::error($busy, 429);
    }
    if (!Security::rateLimit('trv_phone', $phone, 3, 86400)) {
        Response::error($busy, 429);
    }
    if (!Security::rateLimit('trv_all_h', 'global', 30, 3600) || !Security::rateLimit('trv_all_d', 'global', 150, 86400)) {
        Logger::warning('Tour enquiry: the site-wide budget is used up', ['ip' => $ip]);
        Response::error($busy, 429);
    }

    $row = [
        'name' => $name, 'phone' => $phone, 'travel_date' => $date !== '' ? $date : null,
        'seats' => max(1, min(40, $adults + $kids)), 'note' => $note, 'source' => 'tour', 'status' => 'new',
        'ip' => $ip, 'user_agent' => Security::userAgent(255),
    ];
    if ($full) {
        $row += ['package_id' => $pkg !== null ? (int) $pkg['id'] : null, 'details' => $details, 'pax_adults' => $adults,
                 'pax_children' => $kids, 'from_city' => $from !== '' ? $from : null, 'lang' => $lang];
    } else {
        Logger::warning('Tour enquiry: enquiries lacks the 9 Oct columns — run database/upgrade-2026-10-09-content.sql');
    }
    $id = Database::insert('enquiries', $row);
    $ref = trv_ref($id);
    if ($full) {
        Database::update('enquiries', ['ref' => $ref], 'id = :id', ['id' => $id]);
    }

    /* ---- one line to the office: at most 10 an hour, never fatal ---- */
    if (Security::rateLimit('trv_note', 'global', 10, 3600)) {
        try {
            Notify::adminNote('New tour enquiry', [
                'Ref'     => $ref,
                'Package' => mb_substr($title, 0, 60),
                'Name'    => mb_substr($name, 0, 60),
                'Phone'   => $phone,
                'Pax'     => $adults . ($kids > 0 ? ' + ' . $kids . ' child' : ''),
                'When'    => trim($month . ' ' . $date),
                'From'    => mb_substr($from, 0, 40),
            ]);
        } catch (Throwable $e) {
            Logger::warning('Tour enquiry: office note not sent: ' . $e->getMessage());
        }
    } else {
        Logger::info('Tour enquiry: office note skipped (10 an hour reached)', ['ref' => $ref]);
    }

    trv_answer($ref, false, $lang);
} catch (Throwable $e) {
    Response::serverError($e);
}
