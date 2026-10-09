<?php
/**
 * /api/report-fake.php — "Report a fake ticket" (9 Oct 2026, anti-fake).
 *
 * Owner: "ticket fake badi hun layo — yeslai manage garnu paryo." The
 * person holding a doubtful ticket (a passenger, a hotel, a border agent,
 * a crew member) tells the office from the verify page or the app.
 *
 *   GET  → {ok, data:{csrf}}           the session's CSRF token for the form
 *   POST pnr, phone, message, photo (optional image/PDF), website (honeypot)
 *        + CSRF (shg_csrf field or X-CSRF-Token header)
 *        → {ok, ref, data:{ref}}       the help-desk reference (HD-0123)
 *
 * What it does: opens an URGENT help-desk ticket in the 'fake_ticket'
 * category (HelpDesk::open - SLA, auto-assign and the office alert come
 * with it), rings the panel bell (AdminAlerts 'fake_report') and keeps an
 * audit row. The optional photo is stored under UPLOAD_PATH/fraud/Y/m.
 *
 * What it never does: say whether a PNR exists (the answer is the same for
 * a real, a cancelled and a made-up reference), or touch the booking -
 * nothing is cancelled, voided or refunded because someone reported it.
 *
 * Abuse guards: rate limit 5 reports per hour per IP ('fake_report'), a
 * honeypot field bots fill in (they get a quiet success and nothing is
 * stored), the help desk's own same-number/same-words dedupe, and the
 * upload checks of Security::validateUpload().
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/adminalerts.php';

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        Security::requireRateLimit('csrf_refresh', Security::clientIp(), 30, 300);
        header('Cache-Control: no-store');
        Response::success(['csrf' => Security::csrfToken()]);
    }

    Security::requirePost();
    Security::requireCsrf();

    $ip = Security::clientIp();

    /* Honeypot: a real person never sees the "website" box. A bot that fills
       it gets the same success shape and nothing is written. */
    $honey = trim((string) ($_POST['website'] ?? Response::field('website', '')));
    if ($honey !== '') {
        Logger::warning('report-fake honeypot hit', ['ip' => $ip], 'security');
        Response::json(['ok' => true, 'ref' => '', 'data' => ['ref' => '']]);
    }

    if (!Security::rateLimit('fake_report', $ip, 5, 3600)) {
        Response::error('Too many reports from this connection. Please call the office instead. / धेरै रिपोर्ट भयो — कृपया कार्यालयमा फोन गर्नुहोस्।', 429);
    }

    $pnrIn   = strtoupper(Security::clean((string) ($_POST['pnr'] ?? Response::field('pnr', '')), 40));
    $pnr     = Security::isValidPnr($pnrIn) ? $pnrIn : '';
    $phone   = Security::clean((string) ($_POST['phone'] ?? Response::field('phone', '')), 20);
    $message = Security::clean((string) ($_POST['message'] ?? Response::field('message', '')), 1200);
    $source  = Security::clean((string) ($_POST['source'] ?? Response::field('source', 'web')), 20);
    $lang    = Security::clean((string) ($_POST['lang'] ?? Response::field('lang', 'ne')), 4);

    $digits = normalisePhone($phone);
    if (strlen($digits) < 9 || strlen($digits) > 10) {
        Response::invalid(['phone' => 'Enter your mobile number so the office can call you back. / मोबाइल नम्बर लेख्नुहोस्।']);
    }
    if ($pnr === '' && mb_strlen(trim($message)) < 3) {
        Response::invalid(['message' => 'Write the ticket number or what looks wrong. / टिकट नम्बर वा के शंका लाग्यो लेख्नुहोस्।']);
    }

    /* Optional photo of the doubtful ticket. A bad file does not lose the
       report: the text still goes through, the reply says the photo did not. */
    $photoRel  = '';
    $photoNote = '';
    if (isset($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $check = Security::validateUpload($_FILES['photo']);
        if ($check['ok'] ?? false) {
            $sub = 'fraud/' . date('Y') . '/' . date('m');
            $dir = UPLOAD_PATH . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $name = Security::safeFilename((string) $check['ext']);
            if (is_dir($dir) && move_uploaded_file((string) $_FILES['photo']['tmp_name'], $dir . '/' . $name)) {
                $photoRel = $sub . '/' . $name;
            } else {
                $photoNote = 'photo not saved';
            }
        } else {
            $photoNote = (string) ($check['error'] ?? 'photo not accepted');
        }
    }

    $sourceLabel = ['verify_page' => 'verify page', 'app' => 'app', 'web' => 'website'][$source] ?? 'website';
    $body = '⚑ Fake / suspicious ticket report · नक्कली टिकट शंका' . "\n"
          . 'Reported PNR / code: ' . ($pnr !== '' ? $pnr : ($pnrIn !== '' ? $pnrIn . ' (not a valid PNR format)' : '—')) . "\n"
          . 'From: ' . $sourceLabel
          . ($photoRel !== '' ? "\n" . 'Photo kept: uploads/' . $photoRel : '')
          . ($photoNote !== '' ? "\n" . 'Photo: ' . $photoNote : '')
          . (trim($message) !== '' ? "\n\n" . trim($message) : '');

    $res = HelpDesk::open([
        'phone'    => $phone,
        'category' => 'fake_ticket',
        'message'  => $body,
        'pnr'      => $pnr,
        'source'   => 'web',
        'lang'     => in_array($lang, ['ne', 'en', 'hi'], true) ? $lang : 'ne',
    ]);
    if (!($res['ok'] ?? false)) {
        if (($res['error'] ?? '') === 'phone') {
            Response::invalid(['phone' => 'Enter your mobile number so the office can call you back. / मोबाइल नम्बर लेख्नुहोस्।']);
        }
        Response::error('Could not send the report right now. Please call the office. / अहिले पठाउन सकिएन, कार्यालयमा फोन गर्नुहोस्।', 503);
    }
    $ref = (string) ($res['ref'] ?? '');

    if (empty($res['duplicate'])) {
        // the bell: open the booking when the reference is a real one, else the inbox
        $link = '/admin/support-inbox.php?cat=fake_ticket';
        if ($pnr !== '' && BookingService::findByPnr($pnr) !== null) {
            $link = '/admin/booking-view.php?pnr=' . rawurlencode($pnr);
        }
        AdminAlerts::push('fake_report', '⚑ Fake ticket reported' . ($pnr !== '' ? ' · ' . $pnr : ''),
            $ref . ' · ' . mb_substr(trim($message) !== '' ? trim($message) : 'No details given', 0, 160),
            $link, '⚑', 'fakerep:' . $ref);
        Logger::audit('ticket.fake_report', 'booking', $pnr !== '' ? $pnr : '-', null, [
            'ref' => $ref, 'source' => $sourceLabel, 'photo' => $photoRel !== '', 'phone' => maskPhone($digits),
        ], 'reported from ' . $ip);
    }

    // The same answer whether or not the PNR exists (no enumeration).
    Response::json(['ok' => true, 'ref' => $ref, 'data' => ['ref' => $ref], 'photo' => $photoNote === '' ? null : $photoNote]);
} catch (Throwable $e) {
    Response::serverError($e);
}
