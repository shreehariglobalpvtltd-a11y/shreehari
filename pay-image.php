<?php
/**
 * =====================================================================
 *  pay-image.php — payment ticket PNG for the WhatsApp bookingPlaced msg
 *
 *  Reached as:  /pay-image.php?pnr=SHG-2026-00436
 *
 *  Returns a 900×1200 PNG that looks like a ticket but carries a BIG
 *  UPI-pay QR (620px) instead of the boarding verify QR. Scan it with
 *  any UPI app (GPay / PhonePe / Paytm / BHIM) and the amount + PNR
 *  are pre-filled — no typing, no copy-paste.
 *
 *  Notify::bookingPlaced() attaches this URL as WhatsApp media so the
 *  customer sees the full ticket-look photo the moment they open the
 *  message. Ticket.php's boarding-verify QR is kept for CONFIRMED
 *  tickets — this file is only for the pay-me stage.
 *
 *  8 Oct 2026 (D4-M5): the same payable rule as pay.php — the QR asks
 *  only for what is DUE (total minus verified payments) and only while
 *  the booking can take money (pending / rejected, or a confirmed
 *  pay-at-counter ticket not settled). Otherwise a plain "Nothing to pay"
 *  picture with no QR, no route, no seats and no qr_view event. A payable
 *  picture keeps the route and date but no longer prints seat numbers
 *  (PNRs are sequential; the payer does not need them). Rate-limited.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';

$pnr = strtoupper(trim((string) ($_GET['pnr'] ?? '')));
if ($pnr === '' || !preg_match('/^[A-Z0-9-]{6,40}$/', $pnr)) {
    http_response_code(400);
    exit('Invalid PNR');
}

/* Own bucket (not pay.php's): WhatsApp's media fetcher pulls this picture
   from a few shared addresses, so it must not share the tap-to-pay budget. */
if (!Security::rateLimit('pay_image', Security::clientIp(), 30, 300)) {
    http_response_code(429);
    header('Retry-After: 300');
    exit('Too many requests');
}

$booking = Database::fetch(
    'SELECT id, pnr, total_amount, status, is_cod FROM bookings WHERE pnr = :p LIMIT 1',
    ['p' => $pnr]
);
if ($booking === null) {
    http_response_code(404);
    exit('Booking not found');
}

$total   = (float) ($booking['total_amount'] ?? 0);
$paid    = (float) Database::scalar(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
    ['b' => (int) $booking['id']],
    0
);
$due     = round(max(0, $total - $paid), 2);
$bStatus = (string) ($booking['status'] ?? '');
$payable = $due > 0 && (in_array($bStatus, ['pending', 'rejected'], true)
    || ($bStatus === 'confirmed' && (int) ($booking['is_cod'] ?? 0) === 1));

$upiId   = Settings::getString('upi_id', '');
$upiName = Settings::getString('upi_name', Settings::getString('company_name', APP_NAME));
if ($payable && $upiId === '') {
    http_response_code(503);
    exit('UPI not configured');
}
$company = Settings::getString('company_name', APP_NAME);

if (!$payable) {
    /* "Nothing to pay": no QR, no route / date / seats, no qr_view event. */
    $im    = imagecreatetruecolor(900, 600);
    $bgC   = imagecolorallocate($im, 245, 247, 251);
    $cardC = imagecolorallocate($im, 255, 255, 255);
    $lineC = imagecolorallocate($im, 210, 218, 232);
    $navyC = imagecolorallocate($im, 16, 32, 74);
    $inkC  = imagecolorallocate($im, 30, 34, 46);
    $mutC  = imagecolorallocate($im, 110, 120, 140);
    imagefilledrectangle($im, 0, 0, 900, 600, $bgC);
    imagefilledrectangle($im, 40, 40, 860, 560, $cardC);
    imagerectangle($im, 40, 40, 860, 560, $lineC);
    imagefilledrectangle($im, 40, 40, 860, 150, $navyC);
    $fontN = __DIR__ . '/assets/fonts/NotoSansDevanagari.ttf';
    $put = static function (float $size, int $x, int $y, int $col, string $text) use ($im, $fontN): void {
        if (class_exists('DevShape') && DevShape::needs($text) && DevShape::gdText($im, $size, $x, $y, $col, $text, [[0, 0]])) {
            return;
        }
        if (is_file($fontN)) {
            imagettftext($im, $size, 0, $x, $y, $col, $fontN, dev_shape($text));
        } else {
            imagestring($im, 5, $x, $y - 14, $text, $col);
        }
    };
    $white = imagecolorallocate($im, 255, 255, 255);
    $put(24, 80, 108, $white, $company);
    $put(28, 80, 250, $inkC, 'Nothing to pay');
    $put(20, 80, 300, $inkC, 'भुक्तानी बाँकी छैन');
    $put(15, 80, 360, $mutC, 'PNR ' . $pnr);
    $put(15, 80, 420, $mutC, 'Cancelled, expired or already paid — please contact the office.');
    $put(15, 80, 460, $mutC, 'रद्द / म्याद सकिएको / भुक्तानी भइसकेको — कार्यालयमा सम्पर्क गर्नुहोस्।');
    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
    }
    imagepng($im, null, 6);
    imagedestroy($im);
    exit;
}
$total = $due;   // the QR asks only for what is still owed

// Route info (best-effort).
$route = '';
$travelDate = '';
$seats = '';
try {
    /* booking_legs has no route_id (it was always schedule_id → route), so
       this lookup failed silently and the picture never printed its route
       or date. Through the schedule, as everywhere else. */
    $leg = Database::fetch(
        "SELECT bl.travel_date, r.from_city, r.to_city
           FROM booking_legs bl
           LEFT JOIN schedules s ON s.id = bl.schedule_id
           LEFT JOIN routes r ON r.id = s.route_id
          WHERE bl.booking_id = :b
          ORDER BY (bl.leg_type = 'outbound') DESC, bl.id ASC LIMIT 1",
        ['b' => (int) $booking['id']]
    );
    if ($leg !== null) {
        $from = trim((string) ($leg['from_city'] ?? ''));
        $to   = trim((string) ($leg['to_city'] ?? ''));
        if ($from !== '' && $to !== '') {
            $route = $from . ' – ' . $to;   // en dash: the font has no → (it printed an empty box)
        }
        if (!empty($leg['travel_date'])) {
            $ts = strtotime((string) $leg['travel_date']);
            if ($ts !== false) {
                $travelDate = date('d M Y', $ts);
            }
        }
    }
    // Seat numbers are no longer printed here (D4-M5): the payer does not
    // need them, and a PNR is not a secret.
} catch (Throwable $e) {
    // best-effort — a missing table must not stop the QR generation
}

$upiUri = upiLink($upiId, $upiName, $total, $pnr);

require_once INCLUDE_PATH . '/payevents.php';
PayEvents::log((int) ($booking['id'] ?? 0), $pnr, 'qr_view');

// ---------- Compose the image ----------
$W = 900;
$H = 1250;
$im = imagecreatetruecolor($W, $H);

$white   = imagecolorallocate($im, 255, 255, 255);
$bg      = imagecolorallocate($im, 245, 247, 251);
$navy    = imagecolorallocate($im, 16, 32, 74);
$navyHi  = imagecolorallocate($im, 32, 52, 108);
$ink     = imagecolorallocate($im, 30, 34, 46);
$mut     = imagecolorallocate($im, 110, 120, 140);
$line    = imagecolorallocate($im, 210, 218, 232);
$green   = imagecolorallocate($im, 10, 124, 47);
$orange  = imagecolorallocate($im, 234, 128, 32);
$red     = imagecolorallocate($im, 200, 55, 55);
$gold    = imagecolorallocate($im, 236, 186, 90);
$tile    = imagecolorallocate($im, 250, 251, 253);

imagefilledrectangle($im, 0, 0, $W, $H, $bg);

// Rounded card
$cardX1 = 40; $cardY1 = 40; $cardX2 = $W - 40; $cardY2 = $H - 40;
imagefilledrectangle($im, $cardX1, $cardY1, $cardX2, $cardY2, $white);
imagerectangle($im, $cardX1, $cardY1, $cardX2, $cardY2, $line);

$font = dirname(__FILE__) . '/assets/fonts/NotoSansDevanagari.ttf';

/* Devanagari through HarfBuzz (DevShape): this image used to print मति, सटि,
   यात्‌रा — no shaping and not even dev_shape(). Latin keeps the old path. */
$drawText = function ($size, $x, $y, $col, $text, $bold = false) use ($im, $font) {
    if (class_exists('DevShape') && DevShape::needs($text)
        && DevShape::gdText($im, (float) $size, (int) $x, (int) $y, $col, $text, $bold ? [[0, 0], [1, 0], [0, 1]] : [[0, 0]])) {
        return;
    }
    $text = dev_shape($text);
    imagettftext($im, $size, 0, $x, $y, $col, $font, $text);
    if ($bold) {
        imagettftext($im, $size, 0, $x + 1, $y, $col, $font, $text);
        imagettftext($im, $size, 0, $x, $y + 1, $col, $font, $text);
    }
};

$textWidth = function ($size, $text) use ($font) {
    if (class_exists('DevShape') && DevShape::needs($text) && ($sw = DevShape::gdWidth((float) $size, $text)) !== null) {
        return $sw;
    }
    $box = imagettfbbox($size, 0, $font, dev_shape($text));
    return abs($box[2] - $box[0]);
};

// Header band (navy)
imagefilledrectangle($im, $cardX1, $cardY1, $cardX2, $cardY1 + 140, $navy);
$drawText(26, $cardX1 + 40, $cardY1 + 60, $white, $company, true);
$drawText(17, $cardX1 + 40, $cardY1 + 100, $gold, 'भुक्तानी टिकट · Payment Ticket', false);

// "PAYMENT PENDING" pill (right side)
$pillTxt = 'PAYMENT PENDING';
$pw = $textWidth(17, $pillTxt) + 40;
imagefilledrectangle($im, $cardX2 - 30 - $pw, $cardY1 + 40, $cardX2 - 30, $cardY1 + 92, $red);
$drawText(17, $cardX2 - 30 - $pw + 20, $cardY1 + 73, $white, $pillTxt, true);

// Booking summary strip
$y = $cardY1 + 180;
$drawText(15, $cardX1 + 40, $y, $mut, 'बुकिङ नं. · PNR', false);
$drawText(24, $cardX1 + 40, $y + 34, $ink, $pnr, true);

if ($route !== '') {
    $drawText(15, $cardX1 + 40, $y + 78, $mut, 'यात्रा · Route', false);
    $drawText(20, $cardX1 + 40, $y + 108, $ink, $route, false);
}
if ($travelDate !== '') {
    $drawText(15, $cardX1 + 460, $y + 78, $mut, 'मिति · Date', false);
    $drawText(20, $cardX1 + 460, $y + 108, $ink, $travelDate, false);
}
if ($seats !== '') {
    $drawText(15, $cardX1 + 40, $y + 152, $mut, 'सिट · Seat(s)', false);
    $drawText(20, $cardX1 + 40, $y + 182, $ink, $seats, false);
}

// Big amount box
$amtY = $cardY1 + 400;
imagefilledrectangle($im, $cardX1 + 40, $amtY, $cardX2 - 40, $amtY + 100, $tile);
imagerectangle($im, $cardX1 + 40, $amtY, $cardX2 - 40, $amtY + 100, $line);
$drawText(15, $cardX1 + 60, $amtY + 32, $mut, 'तिर्नुपर्ने रकम · Pay this amount', false);
$amtStr = '₹ ' . number_format($total, 2);
$drawText(38, $cardX1 + 60, $amtY + 78, $green, $amtStr, true);

// QR block
$qrTmp = tempnam(sys_get_temp_dir(), 'shgpay');
try {
    /* margin 4, ECC_M — a big, hard-edged QR. Scale 12 first; a long payee
       name makes the code bigger, and at 12 it ran past the bottom of the
       picture and cut off the "scan to pay" caption and the UPI ID, so step
       the (whole-pixel) scale down until QR + caption fit the card. */
    $qrRoom = $H - ($amtY + 130) - 170;
    $qr     = false;
    foreach ([12, 11, 10, 9, 8, 7] as $qrScale) {
        QrCode::png($upiUri, $qrTmp, $qrScale, 4, QrCode::ECC_M);
        $qr = @imagecreatefromstring((string) file_get_contents($qrTmp));
        if ($qr === false || imagesx($qr) <= $qrRoom) {
            break;
        }
        imagedestroy($qr);
        $qr = false;
    }
    if ($qr === false) {
        QrCode::png($upiUri, $qrTmp, 7, 4, QrCode::ECC_M);
        $qr = @imagecreatefromstring((string) file_get_contents($qrTmp));
    }
    if ($qr !== false) {
        if (!imageistruecolor($qr)) { imagepalettetotruecolor($qr); }
        $qrPx = imagesx($qr);
        // Center the QR horizontally
        $qrY = $amtY + 130;
        $qrX = (int) (($W - $qrPx) / 2);
        // White card behind QR for scanability
        $pad = 20;
        imagefilledrectangle($im, $qrX - $pad, $qrY - $pad, $qrX + $qrPx + $pad, $qrY + $qrPx + $pad, $white);
        imagerectangle($im, $qrX - $pad, $qrY - $pad, $qrX + $qrPx + $pad, $qrY + $qrPx + $pad, $line);
        imagecopy($im, $qr, $qrX, $qrY, 0, 0, $qrPx, $qrPx);
        imagedestroy($qr);

        // Caption below the QR
        $capY = $qrY + $qrPx + $pad + 40;
        $cap1 = 'यहाँ स्क्यान गरेर तिर्नुहोस्';
        $cap2 = 'Scan to Pay above amount';
        $w1 = $textWidth(20, $cap1);
        $w2 = $textWidth(15, $cap2);
        $drawText(20, (int) (($W - $w1) / 2), $capY, $navy, $cap1, true);
        $drawText(15, (int) (($W - $w2) / 2), $capY + 30, $mut, $cap2, false);

        // UPI ID line
        $upiLine = 'UPI: ' . $upiId;
        $wu = $textWidth(14, $upiLine);
        $drawText(14, (int) (($W - $wu) / 2), $capY + 62, $ink, $upiLine, false);
    }
} catch (Throwable $e) {
    Logger::error('pay-image QR failed: ' . $e->getMessage(), ['pnr' => $pnr]);
} finally {
    @unlink($qrTmp);
}

// Output PNG
if (!headers_sent()) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300');
}
imagepng($im, null, 6);
imagedestroy($im);
