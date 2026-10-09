<?php
/**
 * verify-ticket.php?pnr=...&k=...   — public ticket verification page.
 *
 * A shareable, phone-friendly VALID/CANCELLED/USED/JOURNEY-OVER status
 * page. Same anti-enumeration guard as download-ticket.php: sequential
 * PNRs are not a secret, so a caller must either present the keyed
 * download token (that ships in every WhatsApp/email link), be the
 * owning customer (Auth::customerMayOpen — booked in this browser, or
 * proved the number with the WhatsApp code; a typed number alone is
 * never proof) or staff. Without proof, the page renders "Please open
 * this link from the message we sent you." rather than confirming a PNR
 * exists.
 *
 * Also accepts ?p=<full-qr-payload> so a passenger who scans the QR
 * with any camera app lands on a real page instead of a raw pipe-
 * string — verifyQrPayload() checks the HMAC and, if valid, returns
 * the PNR the payload named.
 *
 * The page carries no cache headers and no personally identifying
 * detail beyond first name + last-initial, route, date, time, seat
 * and ticket number — the minimum a bus operator or a checkpoint
 * officer needs to see and no more. Staff who are logged in see the
 * full detail row (contact + ID) they would see in the admin panel.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

/* An HTML 429 (9 Oct 2026): requireRateLimit() answers in JSON, which a
   phone camera opening this page showed as raw text. */
if (!Security::rateLimit('ticket_verify', Security::clientIp(), 60, 60)) {
    http_response_code(429);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Please wait · कृपया पर्खनुहोस्</title>'
       . '<style>body{margin:0;font-family:-apple-system,Segoe UI,Roboto,"Noto Sans Devanagari",sans-serif;background:#f5f6fa;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}'
       . '.c{max-width:420px;background:#fff;border-radius:18px;padding:28px 22px;text-align:center;box-shadow:0 4px 24px rgba(15,23,42,.08);border-top:6px solid #f59e0b}'
       . 'h1{font-size:20px;margin:6px 0}p{color:#475569;line-height:1.5}@media (prefers-color-scheme:dark){body{background:#0B1220;color:#e8eef8}.c{background:#131d30}p{color:#c3cfdf}}</style></head>'
       . '<body><div class="c"><h1>Too many checks · धेरै पटक जाँच भयो</h1><p>Please wait a minute and scan again.<br>कृपया एक मिनेट पर्खेर फेरि स्क्यान गर्नुहोस्।</p></div></body></html>';
    exit;
}

$pnr   = Security::clean($_GET['pnr'] ?? '', 40);
$token = Security::clean($_GET['k']   ?? '', 64);

/* NOT GENUINE (9 Oct 2026, owner: "ticket fake badi hun layo"). A link that
   CARRIES a key which does not match, or a scanned pipe payload whose
   signature is wrong, is a forged or altered ticket. Both are decided from
   the key / signature alone — no database lookup — so the answer reveals
   nothing about whether the PNR exists. A link with NO key (typed by hand,
   an old bookmark) is not called fake: it gets the neutral "unavailable". */
$forged    = false;
$forgedPnr = '';
if ($pnr !== '' && $token !== '' && Security::isValidPnr($pnr) && !Ticket::checkDownloadToken($pnr, $token)) {
    $forged    = true;
    $forgedPnr = strtoupper($pnr);
}

/* Camera-scan path: the passenger opened the QR-encoded pipe payload
   directly. verifyQrPayload() only returns a PNR when the signature
   matches, so a tampered payload is indistinguishable from garbage
   here — we neither confirm nor deny the PNR. */
if ($pnr === '' && isset($_GET['p'])) {
    $payload = Security::clean((string) $_GET['p'], 400);
    $decoded = Ticket::verifyQrPayload($payload);
    if ($decoded !== null) {
        $pnr   = $decoded;
        $token = Ticket::downloadToken($pnr);   // the QR signature is proof-of-genuine already
    } elseif (str_starts_with($payload, 'SHG-TICKET|')) {
        // our ticket format with a broken signature: altered or forged
        $forged = true;
        $pp     = explode('|', $payload);
        $forgedPnr = (isset($pp[1]) && Security::isValidPnr((string) $pp[1])) ? strtoupper((string) $pp[1]) : '';
        $pnr = $forgedPnr;
        $token = '';
    }
}

$valid   = $pnr !== '' && Security::isValidPnr($pnr);
$booking = null;
$authed  = false;

if ($valid) {
    // detail() over findByPnr() so legs / passengers / seats / ticket
    // are hydrated in the same shape the admin panel uses — the details
    // panel below reads leg.boarding_stop, passengers[0], seats, and
    // ticket.scanned_at (the "was this ticket already accepted at
    // boarding?" flag) all off the same array.
    $booking = BookingService::detail($pnr);
    if ($booking !== null) {
        // Same 3-tier auth as download-ticket.php.
        if ($token !== '' && Ticket::checkDownloadToken($pnr, $token)) {
            $authed = true;
        } else {
            // The owning customer: booked in this browser session, or an
            // OTP-verified owner (8 Oct 2026). The walk-in placeholder never.
            $authed = Auth::customerMayOpen($booking);
            if (!$authed && Auth::can('bookings.view')) {
                $scope = Auth::bookingScopeAdminId();
                if ($scope === null || $scope === (int) ($booking['sold_by_admin_id'] ?? 0)) {
                    $authed = true;
                }
            }
        }
    }
}

/* ---- Is the journey over? (D6-09, 8 Oct 2026) ----
   A confirmed ticket from last month used to read green "Valid · Ready for
   boarding". Surat → Rupaidiha takes about 30 h (routes.day_offset), so a
   passenger is still checked at the border the day AFTER travel_date: the
   cut is the scheduled ARRIVAL (travel_date + day_offset + arr_time) plus
   12 h — or 1 h once the office marked the trip arrived. A route whose
   arrival is blanked or unknown waits until travel_date + 3 days. A round
   trip is over only when its last leg is. */
$journeyOver = false;
$cashDue     = 0.0;
if ($authed && $booking !== null) {
    $lastEnd = null;
    foreach (($booking['legs'] ?? []) as $lg) {
        $td = (string) ($lg['travel_date'] ?? '');
        if (!Security::isValidDate($td)) {
            continue;
        }
        $fallback = (int) strtotime($td . ' 00:00:00 +3 day');
        $dayOff   = (int) Database::scalar(
            'SELECT r.day_offset FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s',
            ['s' => (int) ($lg['schedule_id'] ?? 0)],
            1
        );
        $arr   = substr(trim((string) ($lg['arr_time'] ?? '')), 0, 5);
        $arrTs = preg_match('/^\d{1,2}:\d{2}$/', $arr) === 1 ? strtotime($td . ' ' . $arr . ' +' . max(0, $dayOff) . ' day') : false;
        if ($arrTs === false) {
            $end = $fallback;
        } else {
            $end = ($lg['schedule_status'] ?? '') === 'arrived' ? $arrTs + 3600 : $arrTs + 12 * 3600;
            // an arrival the office blanked ("no false clock") is not trusted to end a ticket early
            if (trim((string) ($lg['duration_text'] ?? '')) === '' && ($lg['schedule_status'] ?? '') !== 'arrived') {
                $end = max($end, $fallback);
            }
        }
        $lastEnd = max((int) ($lastEnd ?? 0), (int) $end);
    }
    $journeyOver = $lastEnd !== null && time() > $lastEnd;

    /* Cash still owed (pay-at-counter, D4-06): total minus VERIFIED payments
       only — a screenshot or a pending transfer is never counted as paid. */
    $paidVerified = (float) Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
        ['b' => (int) ($booking['id'] ?? 0)],
        0
    );
    $cashDue = (float) Ticket::paymentSummary([
        'total_amount'  => (float) ($booking['total_amount'] ?? 0),
        'verified_paid' => $paidVerified,
    ])['due'];
}
$inrPlain = static fn(float $a): string => 'INR ' . number_format($a, fmod($a, 1.0) !== 0.0 ? 2 : 0);

/* ---- Compose what the card shows ---- */
$state  = 'unknown';       // unknown | valid | pending | cancelled | expired | used
$title  = 'Verification unavailable';
$sub    = 'Please open this ticket from the message we sent you.';
$color  = '#94a3b8';       // slate
$icon   = 'ℹ️';

if ($authed && $booking !== null) {
    $status  = strtolower((string) ($booking['status'] ?? ''));
    $scanned = (string) ($booking['ticket']['scanned_at'] ?? '');
    // tickets.is_void (1 Oct 2026): set by TicketSync when a booking is
    // cancelled / rejected / deleted, cleared on re-approval. A voided ticket
    // never reads VALID here even if the booking row has since moved on.
    $voided  = (int) ($booking['ticket']['is_void'] ?? 0) === 1;
    if (in_array($status, ['cancelled', 'rejected'], true) || $voided) {
        $state = 'cancelled';
        $title = $voided && !in_array($status, ['cancelled', 'rejected'], true) ? 'Voided — रद्द' : 'Cancelled — रद्द';
        $sub   = 'This ticket is not valid for travel.';
        $color = '#dc2626';
        $icon  = '❌';
    } elseif ($status === 'completed' || ($status === 'confirmed' && $journeyOver)) {
        $state = 'expired';
        $title = 'Journey over — यात्रा सकियो';
        $sub   = 'Not valid for a new journey · नयाँ यात्राको लागि मान्य छैन';
        $color = '#64748b';
        $icon  = '🏁';
    } elseif ($scanned !== '') {
        $state = 'used';
        $title = 'Already scanned — प्रयोग भइसकेको';
        $sub   = 'This ticket was accepted at boarding on ' . formatDate($scanned) . '.';
        $color = '#f59e0b';
        $icon  = '⚠️';
    } elseif ($status === 'confirmed') {
        $state = 'valid';
        $title = 'Valid — मान्य';
        $sub   = $cashDue > 0
            ? 'Ready for boarding — collect ' . $inrPlain($cashDue) . ' cash first. Please carry a photo ID.'
            : 'Ready for boarding. Please carry a photo ID.';
        $color = '#16a34a';
        $icon  = '✅';
    } elseif ($status === 'pending') {
        $state = 'pending';
        $title = 'Payment pending — बुझाउनुहोस्';
        $sub   = 'This booking is not confirmed yet. Complete payment to activate the ticket.';
        $color = '#0ea5e9';
        $icon  = '⏳';
    } else {
        $state = 'unknown';
        $title = 'Status: ' . strtoupper($status);
        $sub   = 'Contact the office if this looks wrong.';
    }
}

/* A forged key or signature, for anyone the page could not otherwise
   recognise (the owner in their own browser and staff still see the real
   ticket). Logged and raised to the office once per PNR per hour. */
if ($forged && !$authed) {
    $state = 'forged';
    $title = 'NOT GENUINE — नक्कली हुन सक्छ';
    $sub   = 'This link or QR was not issued by us, or it has been altered. Do not accept it for travel. · यो टिकट हामीले जारी गरेको होइन वा परिवर्तन गरिएको छ — यात्राका लागि स्वीकार नगर्नुहोस्।';
    $color = '#b91c1c';
    $icon  = '⛔';
    try {
        $fp = $forgedPnr !== '' ? $forgedPnr : 'unknown';
        Logger::audit('ticket.verify_forged', 'booking', $fp, null,
            ['ip' => Security::clientIp(), 'via' => isset($_GET['p']) ? 'qr_payload' : 'key', 'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)],
            'verify page: forged or altered ticket key / signature');
        if (!class_exists('AdminAlerts') && is_file(INCLUDE_PATH . '/adminalerts.php')) {
            require_once INCLUDE_PATH . '/adminalerts.php';
        }
        if (class_exists('AdminAlerts')) {
            AdminAlerts::push('fake_verify', '⛔ Fake / altered ticket checked · ' . $fp,
                'Someone opened the verify page with a key or QR signature that does not match' . ($forgedPnr !== '' ? ' PNR ' . $forgedPnr : '') . '. Check who is holding this ticket.',
                $forgedPnr !== '' ? '/admin/booking-view.php?pnr=' . rawurlencode($forgedPnr) : '/admin/bookings.php',
                '⛔', 'verifyforged:' . $fp . ':' . date('YmdH'));
        }
    } catch (Throwable $e) {
        // logging must never break the page
    }
}

/* A trip the office cancelled overrides the green headline (the booking
   stays confirmed until the owner settles it by hand — no refund wording). */
$liveEarly = ($authed && $booking !== null && $state === 'valid') ? Ticket::liveStatus($booking) : null;
if ($liveEarly !== null && ($liveEarly['key'] ?? '') === 'trip_cancelled') {
    $state = 'trip_cancelled';
    $title = 'Trip cancelled — यात्रा रद्द';
    $sub   = 'This bus does not run. The office will contact you about this ticket. · कार्यालयले तपाईंलाई सम्पर्क गर्नेछ।';
    $color = '#dc2626';
    $icon  = '🚫';
}

/* Security code (9 Oct 2026): the same six letters the PNG / PDF print
   beside the QR for THIS revision — shown only to someone entitled to the
   ticket, so a stranger cannot read a code off the page to fake one. */
$secCode = '';
if ($authed && $booking !== null && in_array($state, ['valid', 'used', 'trip_cancelled', 'expired', 'pending'], true)) {
    $secCode = Ticket::securityCodeFor((int) ($booking['id'] ?? 0));
}
$checkedAt = date('j M Y, g:i:s A') . ' IST';

/* Anonymise the passenger name for the public panel — first name +
   first letter of the last name is enough for a person to recognise
   their own ticket without leaking to a bystander who scanned it. */
$paxLine = '';
$dateLine = '';
$routeLine = '';
$seatLine = '';
$ticketNoLine = '';
if ($authed && $booking !== null) {
    $legs = $booking['legs'] ?? [];
    $leg  = $legs[0] ?? [];
    $pax  = ($booking['passengers'] ?? [])[0] ?? ($leg['passengers'][0] ?? []);
    $paxName = trim((string) ($pax['full_name'] ?? ''));
    if ($paxName !== '') {
        $parts = preg_split('/\s+/', $paxName) ?: [$paxName];
        $first = (string) $parts[0];
        $lastInitial = count($parts) > 1 ? mb_substr(end($parts), 0, 1) . '.' : '';
        $paxLine = trim($first . ' ' . $lastInitial);
    }
    /* The time shown is the pickup at the passenger's own boarding point
       (carried in the label as "@ HH:MM"), falling back to the route's
       departure for a leg without one. */
    if (!class_exists('Boarding')) { require_once INCLUDE_PATH . '/boarding.php'; }
    /* Route row: stop NAMES only (D7-13). The stored labels carry "@ HH:MM"
       and "[lat,lng]" for the machines; the time is in its own row. */
    $routeFrom = (string) Boarding::stopDisplay(trim((string) ($leg['boarding_stop'] ?? '')), trim((string) ($leg['from_city'] ?? ($booking['from_city'] ?? ''))))['name'];
    $routeTo   = (string) Boarding::stopDisplay(trim((string) ($leg['drop_stop'] ?? '')), trim((string) ($leg['to_city'] ?? ($booking['to_city'] ?? ''))))['name'];
    $routeLine = trim($routeFrom) . ' → ' . trim($routeTo);
    // seats.php is not on this public page's require chain (no autoloader), so
    // load it before the seat-label formatter or the verify card fatals.
    if (!class_exists('Seats')) { require_once INCLUDE_PATH . '/seats.php'; }
    $pickupHHMM = Boarding::stopDisplay((string) ($leg['boarding_stop'] ?? ''))['time'];
    $dateLine  = formatDate((string) ($leg['travel_date'] ?? '')) . '  ·  '
        . ($pickupHHMM !== null ? $pickupHHMM : substr((string) ($leg['dep_time'] ?? ''), 0, 5));
    $seatLine  = implode(' · ', array_map(static fn($s) => Seats::displayLabel((string) ($s['seat_no'] ?? $s), 'sleeper', (string) ($booking['booking_mode'] ?? 'sharing')), $booking['seats'] ?? []));
    $ticketNoLine = (string) ($booking['ticket_number'] ?? '');
}

/* Live status (Departed / Departing / Upcoming) as a secondary pill —
   safe to show publicly, same info the passenger already has on their
   own My Bookings screen. */
$live = ($authed && $booking !== null && !in_array($state, ['expired', 'trip_cancelled'], true)) ? Ticket::liveStatus($booking) : null;

/* The domain this page lives on, for "genuine only on …" — the company's
   own web setting, never the request Host (a lookalike site would print
   its own name). */
$genuineHost = '';
try { $genuineHost = strtolower(trim((string) (Settings::company()['web'] ?? ''))); } catch (Throwable $e) {}
$genuineHost = preg_replace('#^https?://(www\.)?|/.*$#i', '', $genuineHost) ?: 'shreehariglobal.in';

/* Inline SVG flags (explicit fills — no emoji, which Windows prints as "IN"/"NP"). */
$flagSvg = static function (string $cc, int $h = 18): string {
    if (class_exists('Brand') && method_exists('Brand', 'flagSvg')) {
        try { $s = (string) Brand::flagSvg($cc, $h); if ($s !== '') { return $s; } } catch (Throwable $e) {}
    }
    if ($cc === 'IN') {
        $w = (int) round($h * 1.5);
        return '<svg class="flg" width="' . $w . '" height="' . $h . '" viewBox="0 0 30 20" role="img" aria-label="India"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#FF9933"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.6" fill="none" stroke="#000080" stroke-width=".7"/><circle cx="15" cy="10" r=".6" fill="#000080"/><rect width="30" height="20" fill="none" stroke="rgba(0,0,0,.18)" stroke-width=".5"/></svg>';
    }
    $w = (int) round($h * 0.78);
    return '<svg class="flg" width="' . $w . '" height="' . $h . '" viewBox="0 0 26 32" role="img" aria-label="Nepal"><path d="M2 2 23 15H9.6L23 30H2Z" fill="#DC143C" stroke="#003893" stroke-width="2.2" stroke-linejoin="miter"/><circle cx="8" cy="11" r="2.4" fill="#fff"/><circle cx="8" cy="23" r="3" fill="#fff"/></svg>';
};

/* Never let a browser or an intermediate proxy cache a verification
   page — the answer changes the moment the crew scans the ticket. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/* The verify page names the company that ISSUED the ticket (4 Oct 2026) -
   but only to someone entitled to see the ticket; a stranger with a bare
   PNR gets the site's own name and learns nothing about the sale. */
$issuer  = ($authed && $booking !== null)
    ? Settings::companyFor((string) ($booking['counter_code'] ?? ''), (string) ($booking['entity'] ?? ''))
    : Settings::companyFor(null, null);
$company = (string) $issuer['name'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A3A6A">
<meta name="robots" content="noindex, nofollow">
<title>Ticket Verification · <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></title>
<script>
/* Theme pre-paint (D1-10, 8 Oct 2026): the same choice the app saved —
   localStorage 'shg:theme' = light | dark | amoled — else the phone's own
   setting, stamped before the first paint so a dark-mode passenger is not
   flashed white. Mirrors the app's pre-paint (app.template.html). */
(function () {
  try {
    var d = document.documentElement, t = (localStorage.getItem('shg:theme') || '').replace(/"/g, '');
    if (t !== 'light' && t !== 'dark' && t !== 'amoled') t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    d.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
    if (t === 'amoled') d.setAttribute('data-display', 'amoled');
    var m = document.querySelector('meta[name="theme-color"]');
    if (m && t !== 'light') m.setAttribute('content', t === 'amoled' ? '#000000' : '#0F1A2E');
  } catch (e) { /* storage blocked: light, as before */ }
})();
</script>
<style>
  :root { --bg: #f5f6fa; --card: #fff; --text: #0f172a; --sub: #475569; --key: #64748b; --line: #eef2f7;
    --foot: #4b5b70; --link: #1A3A6A; --cta: #1A3A6A; --cta-alt-line: #C9D6EA; --shadow: rgba(15, 23, 42, 0.08);
    --due-bg: #fff4e0; --due-text: #8a4600; --due-line: #f2b04c; color-scheme: light;
    --sec-a: #fcf7e8; --sec-b: #f8efd6; --sec-line: #e3c77c; --ok-text: #11703f; --ok-bg: #e4f6ec;
    --bad-text: #b42525; --bad-btn: #b91c1c; }
  :root[data-theme="dark"] { --sec-a: #2a2410; --sec-b: #241f0d; --sec-line: #7a6326; --ok-text: #8ee0b0; --ok-bg: #0f3322;
    --bad-text: #ff9b9b; --bad-btn: #c0262d; }
  :root[data-theme="dark"] { --bg: #0B1220; --card: #131d30; --text: #e8eef8; --sub: #c3cfdf; --key: #a3b3c9; --line: #22324a;
    --foot: #a9b8cc; --link: #9cc2ff; --cta: #2b5aa8; --cta-alt-line: #3b5b8c; --shadow: rgba(0, 0, 0, 0.45);
    --due-bg: #3a2806; --due-text: #ffd48a; --due-line: #9a6a1c; color-scheme: dark; }
  :root[data-theme="dark"][data-display="amoled"] { --bg: #000; --card: #0b0f16; --line: #1b2535; --shadow: rgba(0, 0, 0, 0); }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans Devanagari', 'Nirmala UI', sans-serif;
    -webkit-font-smoothing: antialiased; }
  body { min-height: 100vh; min-height: 100dvh; padding: env(safe-area-inset-top, 0) 16px env(safe-area-inset-bottom, 24px); }
  .wrap { max-width: 480px; margin: 0 auto; padding: 24px 0; }
  .hdr { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
  /* the emblem alone, its own aspect (it was squashed into a 40x40 box) */
  .hdr img { height: 44px; width: auto; max-width: 96px; object-fit: contain; flex: 0 0 auto;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.18)); }
  .hdr .hdr-t { flex: 1 1 auto; min-width: 0; }
  .hdr b { font-size: 15px; letter-spacing: 0.02em; }
  .hdr small { display: block; color: var(--key); font-size: 13px; }
  .hdr .flags { display: flex; gap: 6px; align-items: center; flex: 0 0 auto; }
  .flg { display: inline-block; vertical-align: middle; border-radius: 2px; }
  .sec { margin: 18px auto 0; max-width: 300px; border-radius: 14px; padding: 12px 14px 10px;
    background: repeating-linear-gradient(115deg, var(--sec-a) 0 10px, var(--sec-b) 10px 20px);
    border: 1.5px solid var(--sec-line); position: relative; overflow: hidden; }
  .sec .k { font-size: 12.5px; color: var(--key); letter-spacing: 0.04em; }
  .sec .code { font: 700 34px/1.15 'SF Mono', Consolas, 'Roboto Mono', monospace; letter-spacing: 0.18em; color: var(--text); margin-top: 2px; }
  .sec .hint { font-size: 12.5px; color: var(--sub); margin-top: 2px; }
  .sec::after { content: ''; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(100deg, transparent 30%, rgba(255, 255, 255, 0.45) 45%, transparent 60%);
    transform: translateX(-100%); animation: secShine 3.6s ease-in-out infinite; }
  @keyframes secShine { 0%, 40% { transform: translateX(-100%); } 80%, 100% { transform: translateX(100%); } }
  @media (prefers-reduced-motion: reduce) { .sec::after { animation: none; display: none; } }
  .stamp { margin-top: 14px; font-size: 13px; color: var(--sub); }
  .stamp b { color: var(--text); font-weight: 600; }
  .genuine { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 13px; font-weight: 600;
    color: var(--ok-text); background: var(--ok-bg); border-radius: 999px; padding: 5px 12px; }
  .report { margin-top: 16px; background: var(--card); border-radius: 18px; padding: 4px 16px; box-shadow: 0 2px 12px var(--shadow); }
  .report summary { cursor: pointer; list-style: none; padding: 14px 0; font-weight: 700; color: var(--bad-text);
    display: flex; align-items: center; gap: 8px; min-height: 44px; }
  .report summary::-webkit-details-marker { display: none; }
  .report form { padding: 0 0 16px; display: grid; gap: 10px; }
  .report label { font-size: 13px; color: var(--key); display: grid; gap: 4px; }
  .report input, .report textarea { font: inherit; font-size: 16px; padding: 11px 12px; border-radius: 10px;
    border: 1.5px solid var(--line); background: var(--bg); color: var(--text); width: 100%; }
  .report textarea { min-height: 84px; resize: vertical; }
  .report button { font: inherit; font-weight: 700; font-size: 16px; min-height: 48px; border: 0; border-radius: 12px;
    background: var(--bad-btn); color: #fff; cursor: pointer; }
  .report button[disabled] { opacity: 0.6; }
  .report .msg { font-size: 14px; line-height: 1.5; }
  .report .msg.ok { color: var(--ok-text); } .report .msg.bad { color: var(--bad-text); }
  .card { background: var(--card); border-radius: 18px; padding: 28px 20px; text-align: center;
    box-shadow: 0 4px 24px var(--shadow); border-top: 6px solid <?= $color ?>; }
  .icon { font-size: 56px; line-height: 1; margin-bottom: 6px; }
  .status-title { font-size: 22px; font-weight: 700; color: <?= $color ?>; margin: 4px 0 6px; }
  :root[data-theme="dark"] .status-title { filter: brightness(1.25); }
  .status-sub { color: var(--sub); font-size: 16px; line-height: 1.5; margin: 0 auto; max-width: 380px; }
  .details { background: var(--card); border-radius: 18px; margin-top: 16px; padding: 4px 8px;
    box-shadow: 0 2px 12px var(--shadow); }
  .row { display: flex; padding: 12px 12px; border-top: 1px solid var(--line); align-items: center; gap: 8px; }
  .row:first-child { border-top: none; }
  /* the label takes what it needs (bilingual labels differ a lot in width), the value the rest */
  .row .k { font-size: 13px; line-height: 1.4; color: var(--key); flex: 0 1 auto; max-width: 42%; min-width: 0; }
  .row .v { font-size: 16px; color: var(--text); font-weight: 500; text-align: right; flex: 1 1 0; min-width: 0; word-break: break-word; }
  .row.due { background: var(--due-bg); border: 1.5px solid var(--due-line); border-radius: 12px; margin: 8px 0; }
  .row.due .k, .row.due .v { color: var(--due-text); font-weight: 700; }
  .foot { text-align: center; margin-top: 20px; color: var(--foot); font-size: 13.5px; line-height: 1.6; }
  .foot a { color: var(--link); text-decoration: none; font-weight: 600; }
  .live-pill { display: inline-block; margin-top: 12px; padding: 6px 14px; border-radius: 999px;
    font-size: 13px; font-weight: 700; color: #fff; background: <?= $color ?>; }
  .pnr { font-family: 'SF Mono', Consolas, monospace; letter-spacing: 0.03em; font-size: 14px; color: var(--sub); }
  .cta { display: block; background: var(--cta); color: #fff; text-decoration: none; text-align: center;
    padding: 14px; border-radius: 12px; margin-top: 14px; font-weight: 600; }

  /* Secondary action: same shape, quieter, so the PNG stays the obvious one. */
  .cta-alt { background: transparent; color: var(--link); border: 1.5px solid var(--cta-alt-line);
             margin-top: 8px; font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <img src="/assets/img/logo-mark-96h.png?v=20261009t" alt="" width="85" height="44" onerror="this.style.display='none'">
    <div class="hdr-t">
      <b><?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></b>
      <small>Ticket verification · टिकट प्रमाणीकरण</small>
    </div>
    <span class="flags"><?= $flagSvg('IN', 18) ?><?= $flagSvg('NP', 20) ?></span>
  </div>

  <div class="card">
    <div class="icon" aria-hidden="true"><?= $icon ?></div>
    <div class="status-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="status-sub"><?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?></div>
    <?php if ($live !== null): ?>
      <span class="live-pill" style="background: <?= $live['color'] === 'red' ? '#dc2626' : ($live['color'] === 'orange' ? '#f59e0b' : '#0ea5e9') ?>">
        <?= htmlspecialchars($live['label'], ENT_QUOTES, 'UTF-8') ?>
      </span>
    <?php endif; ?>
    <?php if ($secCode !== ''): ?>
      <div class="sec" aria-label="Security code">
        <div class="k">सुरक्षा कोड · SECURITY CODE</div>
        <div class="code"><?= htmlspecialchars(Ticket::securityCodeGrouped($secCode), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="hint">Must match the code printed on the ticket · टिकटमा छापिएको कोडसँग मिल्नुपर्छ</div>
      </div>
    <?php endif; ?>
    <div class="stamp">Checked at · जाँच गरिएको समय: <b><?= htmlspecialchars($checkedAt, ENT_QUOTES, 'UTF-8') ?></b></div>
    <div class="genuine">✓ Genuine only on <?= htmlspecialchars($genuineHost, ENT_QUOTES, 'UTF-8') ?> · यही साइटमा मात्र सक्कली</div>
  </div>

  <?php if ($authed && $booking !== null): ?>
  <div class="details">
    <?php if ($state === 'valid' && $cashDue > 0): ?><div class="row due"><span class="k">नगद बाँकी · Cash due</span><span class="v"><?= htmlspecialchars($inrPlain($cashDue), ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if ($paxLine !== ''): ?><div class="row"><span class="k">यात्रु · Passenger</span><span class="v"><?= htmlspecialchars($paxLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if ($routeLine !== ' → '): ?><div class="row"><span class="k">रुट · Route</span><span class="v"><?= htmlspecialchars($routeLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if (trim($dateLine, '·  ') !== ''): ?><div class="row"><span class="k">मिति · समय</span><span class="v"><?= htmlspecialchars($dateLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if ($seatLine !== ''): ?><div class="row"><span class="k">सिट · Seat</span><span class="v"><?= htmlspecialchars($seatLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <div class="row"><span class="k">PNR</span><span class="v pnr"><?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php if ($ticketNoLine !== ''): ?><div class="row"><span class="k">Ticket</span><span class="v pnr"><?= htmlspecialchars($ticketNoLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($authed && $booking !== null && $state === 'valid'): ?>
    <?php /* This is where a passenger lands after scanning their OWN ticket,
         and it was the one surface still steering them to the PDF. The HD
         PNG has been the primary format since 5 Sep — it is what WhatsApp
         and email send and what the office reprints — so it leads here too,
         with the PDF one tap away for anyone who needs to print. */ ?>
    <a class="cta" href="<?= htmlspecialchars(Ticket::imageUrl($pnr), ENT_QUOTES, 'UTF-8') ?>">Download ticket</a>
    <a class="cta cta-alt" href="<?= htmlspecialchars(Ticket::downloadUrl($pnr), ENT_QUOTES, 'UTF-8') ?>">PDF for printing</a>
  <?php elseif (!$authed && $state !== 'forged'): ?>
    <a class="cta" href="/#/my">Open My Bookings</a>
  <?php endif; ?>

  <?php $vPh = (string) $issuer['phone']; $vTel = (string) preg_replace('/[^0-9+]/', '', $vPh); ?>
  <?php /* Report this ticket (9 Oct 2026) — in EVERY state, a stranger included:
       the person holding a doubtful ticket is exactly who should be able to
       tell the office. Posts to api/report-fake.php with the session's CSRF
       token (fetched fresh from api/csrf.php); the PNR field carries only
       what the caller already had in the link. */ ?>
  <details class="report"<?= $state === 'forged' ? ' open' : '' ?>>
    <summary>⚑ Report this ticket · नक्कली टिकट रिपोर्ट</summary>
    <form id="rfForm" novalidate>
      <label>PNR
        <input name="pnr" id="rfPnr" maxlength="40" autocomplete="off" value="<?= htmlspecialchars($state === 'forged' ? $forgedPnr : ($authed ? $pnr : (Security::isValidPnr($pnr) ? strtoupper($pnr) : '')), ENT_QUOTES, 'UTF-8') ?>">
      </label>
      <label>Your mobile · तपाईंको मोबाइल
        <input name="phone" id="rfPhone" type="tel" inputmode="tel" maxlength="16" autocomplete="tel" required>
      </label>
      <label>What looks wrong? · के शंका लाग्यो?
        <textarea name="message" id="rfMsg" maxlength="600" placeholder="e.g. the seller asked for cash, the code does not match · जस्तै: कोड मिलेन"></textarea>
      </label>
      <button type="submit" id="rfBtn">Send to the office · कार्यालयमा पठाउनुहोस्</button>
      <div class="msg" id="rfOut" role="status" aria-live="polite"></div>
    </form>
  </details>
  <script>
  (function () {
    var f = document.getElementById('rfForm'); if (!f) return;
    var out = document.getElementById('rfOut'), btn = document.getElementById('rfBtn');
    var tel = <?= json_encode($vTel) ?>, telTxt = <?= json_encode($vPh) ?>;
    function say(ok, html) { out.className = 'msg ' + (ok ? 'ok' : 'bad'); out.innerHTML = html; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var fallback = function () {
      return 'Could not send right now · अहिले पठाउन सकिएन. ' + (tel ? 'Please call <a href="tel:' + esc(tel) + '">' + esc(telTxt) + '</a>.' : '');
    };
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var phone = (document.getElementById('rfPhone').value || '').replace(/[^\d+]/g, '');
      if (phone.replace(/\D/g, '').length < 8) { say(false, 'Please enter your mobile number · मोबाइल नम्बर लेख्नुहोस्'); return; }
      btn.disabled = true;
      fetch('/api/csrf.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var tok = (j && (j.data && j.data.csrf || j.csrf)) || '';
          var fd = new FormData();
          fd.append('pnr', (document.getElementById('rfPnr').value || '').trim().toUpperCase());
          fd.append('phone', phone);
          fd.append('message', document.getElementById('rfMsg').value || '');
          fd.append('source', 'verify_page');
          fd.append('shg_csrf', tok);
          return fetch('/api/report-fake.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': tok } });
        })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (j) {
          if (j && j.ok) {
            var ref = (j.data && j.data.ref) || j.ref || '';
            say(true, 'Thank you — the office has your report' + (ref ? ' (ref <b>' + esc(ref) + '</b>)' : '') + '. धन्यवाद, कार्यालयले हेर्नेछ।');
            f.querySelectorAll('input,textarea,button').forEach(function (el) { el.disabled = true; });
          } else {
            say(false, (j && j.error ? esc(j.error) + '<br>' : '') + fallback());
            btn.disabled = false;
          }
        })
        .catch(function () { say(false, fallback()); btn.disabled = false; });
    });
  })();
  </script>

  <div class="foot">
    Suspect fraud? Contact the office: <a href="tel:<?= htmlspecialchars($vTel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($vPh, ENT_QUOTES, 'UTF-8') ?></a><br>
    <?= date('Y') ?> <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>
  </div>
</div>
</body>
</html>
