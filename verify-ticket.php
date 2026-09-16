<?php
/**
 * verify-ticket.php?pnr=...&k=...   — public ticket verification page.
 *
 * A shareable, phone-friendly VALID/CANCELLED/USED status page. Same
 * anti-enumeration guard as download-ticket.php: sequential PNRs are
 * not a secret, so a caller must either present the keyed download
 * token (that ships in every WhatsApp/email link) or be the signed-in
 * owner/staff. Without proof, the page renders "Please open this link
 * from the message we sent you." rather than confirming a PNR exists.
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
            $user = Auth::user();
            if ($user !== null
                && normalisePhone((string) ($user['phone'] ?? '')) !== ''
                && normalisePhone((string) ($user['phone'] ?? '')) === normalisePhone((string) ($booking['contact_phone'] ?? ''))) {
                $authed = true;
            }
            if (!$authed && Auth::can('bookings.view')) {
                $scope = Auth::bookingScopeAdminId();
                if ($scope === null || $scope === (int) ($booking['sold_by_admin_id'] ?? 0)) {
                    $authed = true;
                }
            }
        }
    }
}

/* ---- Compose what the card shows ---- */
$state  = 'unknown';       // unknown | valid | pending | cancelled | departed | used
$title  = 'Verification unavailable';
$sub    = 'Please open this ticket from the message we sent you.';
$color  = '#94a3b8';       // slate
$icon   = 'ℹ️';

if ($authed && $booking !== null) {
    $status  = strtolower((string) ($booking['status'] ?? ''));
    $scanned = (string) ($booking['ticket']['scanned_at'] ?? '');
    if (in_array($status, ['cancelled', 'rejected'], true)) {
        $state = 'cancelled';
        $title = 'Cancelled — रद्द';
        $sub   = 'This ticket is not valid for travel.';
        $color = '#dc2626';
        $icon  = '❌';
    } elseif ($scanned !== '') {
        $state = 'used';
        $title = 'Already scanned — प्रयोग भइसकेको';
        $sub   = 'This ticket was accepted at boarding on ' . formatDate($scanned) . '.';
        $color = '#f59e0b';
        $icon  = '⚠️';
    } elseif ($status === 'confirmed') {
        $state = 'valid';
        $title = 'Valid — मान्य';
        $sub   = 'Ready for boarding. Please carry a photo ID.';
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
    $routeLine = trim((string) ($leg['boarding_stop'] ?? ($booking['from_city'] ?? '')))
        . ' → ' . trim((string) ($leg['drop_stop'] ?? ($booking['to_city'] ?? '')));
    /* The time shown is the pickup at the passenger's own boarding point
       (carried in the label as "@ HH:MM"), falling back to the route's
       departure for a leg without one. */
    if (!class_exists('Boarding')) { require_once INCLUDE_PATH . '/boarding.php'; }
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
$live = ($authed && $booking !== null) ? Ticket::liveStatus($booking) : null;

/* Never let a browser or an intermediate proxy cache a verification
   page — the answer changes the moment the crew scans the ticket. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A3A6A">
<meta name="robots" content="noindex, nofollow">
<title>Ticket Verification · <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #f5f6fa; color: #0f172a;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased; }
  body { min-height: 100vh; min-height: 100dvh; padding: env(safe-area-inset-top, 0) 16px env(safe-area-inset-bottom, 24px); }
  .wrap { max-width: 480px; margin: 0 auto; padding: 24px 0; }
  .hdr { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
  .hdr img { width: 40px; height: 40px; border-radius: 8px; }
  .hdr b { font-size: 15px; letter-spacing: 0.02em; }
  .hdr small { display: block; color: #64748b; font-size: 12px; }
  .card { background: #fff; border-radius: 18px; padding: 28px 20px; text-align: center;
    box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08); border-top: 6px solid <?= $color ?>; }
  .icon { font-size: 56px; line-height: 1; margin-bottom: 6px; }
  .status-title { font-size: 22px; font-weight: 700; color: <?= $color ?>; margin: 4px 0 6px; }
  .status-sub { color: #475569; font-size: 14px; line-height: 1.45; margin: 0 auto; max-width: 380px; }
  .details { background: #fff; border-radius: 18px; margin-top: 16px; padding: 4px 8px;
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.06); }
  .row { display: flex; padding: 12px 12px; border-top: 1px solid #eef2f7; align-items: center; gap: 8px; }
  .row:first-child { border-top: none; }
  .row .k { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; flex: 0 0 90px; }
  .row .v { font-size: 15px; color: #0f172a; font-weight: 500; text-align: right; flex: 1; word-break: break-word; }
  .foot { text-align: center; margin-top: 20px; color: #94a3b8; font-size: 12px; line-height: 1.6; }
  .foot a { color: #1A3A6A; text-decoration: none; font-weight: 600; }
  .live-pill { display: inline-block; margin-top: 12px; padding: 6px 14px; border-radius: 999px;
    font-size: 12px; font-weight: 700; color: #fff; background: <?= $color ?>; }
  .pnr { font-family: 'SF Mono', Consolas, monospace; letter-spacing: 0.06em; font-size: 13px; color: #64748b; }
  .cta { display: block; background: #1A3A6A; color: #fff; text-decoration: none; text-align: center;
    padding: 14px; border-radius: 12px; margin-top: 14px; font-weight: 600; }

  /* Secondary action: same shape, quieter, so the PNG stays the obvious one. */
  .cta-alt { background: transparent; color: #1A3A6A; border: 1.5px solid #C9D6EA;
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
    <?php if ($paxLine !== ''): ?><div class="row"><span class="k">Passenger</span><span class="v"><?= htmlspecialchars($paxLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if ($routeLine !== ' → '): ?><div class="row"><span class="k">Route</span><span class="v"><?= htmlspecialchars($routeLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if (trim($dateLine, '·  ') !== ''): ?><div class="row"><span class="k">Date · Time</span><span class="v"><?= htmlspecialchars($dateLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
    <?php if ($seatLine !== ''): ?><div class="row"><span class="k">Seat</span><span class="v"><?= htmlspecialchars($seatLine, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
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
    Suspect fraud? Contact the office: <a href="tel:+919104801507">+91 91048 01507</a><br>
    <?= date('Y') ?> <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>
  </div>
</div>
</body>
</html>
