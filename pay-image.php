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

$booking = Database::fetch(
    'SELECT id, pnr, total_amount, status FROM bookings WHERE pnr = :p LIMIT 1',
    ['p' => $pnr]
);
if ($booking === null) {
    http_response_code(404);
    exit('Booking not found');
}

$total = (float) ($booking['total_amount'] ?? 0);
if ($total <= 0) {
    http_response_code(400);
    exit('Amount not set');
}

$upiId   = Settings::getString('upi_id', '');
$upiName = Settings::getString('upi_name', Settings::getString('company_name', APP_NAME));
if ($upiId === '') {
    http_response_code(503);
    exit('UPI not configured');
}
$company = Settings::getString('company_name', APP_NAME);

// Route info (best-effort).
$route = '';
$travelDate = '';
$seats = '';
try {
    $leg = Database::fetch(
        "SELECT bl.travel_date, bl.route_id, r.from_city, r.to_city
           FROM booking_legs bl
           LEFT JOIN routes r ON r.id = bl.route_id
          WHERE bl.booking_id = :b
          ORDER BY bl.id ASC LIMIT 1",
        ['b' => (int) $booking['id']]
    );
    if ($leg !== null) {
        $from = trim((string) ($leg['from_city'] ?? ''));
        $to   = trim((string) ($leg['to_city'] ?? ''));
        if ($from !== '' && $to !== '') {
            $route = $from . ' → ' . $to;
        }
        if (!empty($leg['travel_date'])) {
            $ts = strtotime((string) $leg['travel_date']);
            if ($ts !== false) {
                $travelDate = date('d M Y', $ts);
            }
        }
    }
    $seatRows = Database::fetchAll(
        "SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY id ASC",
        ['b' => (int) $booking['id']]
    );
    if ($seatRows !== null && count($seatRows) > 0) {
        $seats = implode(', ', array_column($seatRows, 'seat_no'));
    }
} catch (Throwable $e) {
    // best-effort — a missing table must not stop the QR generation
}

$upiUri = upiLink($upiId, $upiName, $total, $pnr);

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

$drawText = function ($size, $x, $y, $col, $text, $bold = false) use ($im, $font) {
    imagettftext($im, $size, 0, $x, $y, $col, $font, $text);
    if ($bold) {
        imagettftext($im, $size, 0, $x + 1, $y, $col, $font, $text);
        imagettftext($im, $size, 0, $x, $y + 1, $col, $font, $text);
    }
};

$textWidth = function ($size, $text) use ($font) {
    $box = imagettfbbox($size, 0, $font, $text);
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
    // scale 12, margin 4, ECC_M — yields a big, hard-edged QR
    QrCode::png($upiUri, $qrTmp, 12, 4, QrCode::ECC_M);
    $qr = @imagecreatefromstring((string) file_get_contents($qrTmp));
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
