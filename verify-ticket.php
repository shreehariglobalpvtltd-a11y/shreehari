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

Security::requireRateLimit('ticket_verify', Security::clientIp(), 60, 60);

$pnr   = Security::clean($_GET['pnr'] ?? '', 40);
$token = Security::clean($_GET['k']   ?? '', 64);

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
$live = ($authed && $booking !== null && $state !== 'expired') ? Ticket::liveStatus($booking) : null;

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
    --due-bg: #fff4e0; --due-text: #8a4600; --due-line: #f2b04c; color-scheme: light; }
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
  .hdr img { width: 40px; height: 40px; border-radius: 8px; }
  .hdr b { font-size: 15px; letter-spacing: 0.02em; }
  .hdr small { display: block; color: var(--key); font-size: 13px; }
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
    <img src="/assets/img/logo.png?v=20260829e" alt="" onerror="this.style.display='none'">
    <div>
      <b><?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></b>
      <small>Ticket verification · टिकट प्रमाणीकरण</small>
    </div>
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
  <?php elseif (!$authed): ?>
    <a class="cta" href="/#/my">Open My Bookings</a>
  <?php endif; ?>

  <div class="foot">
    <?php $vPh = (string) $issuer['phone']; $vTel = (string) preg_replace('/[^0-9+]/', '', $vPh); ?>
    Suspect fraud? Contact the office: <a href="tel:<?= htmlspecialchars($vTel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($vPh, ENT_QUOTES, 'UTF-8') ?></a><br>
    <?= date('Y') ?> <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>
  </div>
</div>
</body>
</html>
