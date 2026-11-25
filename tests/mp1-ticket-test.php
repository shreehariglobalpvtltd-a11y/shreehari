<?php
/**
 * mp1-ticket-test.php — the CLEAN ticket + the cash / country pay-QR rule (mp1 "tk", 25 Nov 2026).
 *
 * Seeds its OWN bookings (far date 2099-11-25, blocked schedules, phones 90000325xx,
 * names "ZZTEST mp1 ...") and removes every row and file at the end:
 *   CASH  r2 (Surat -> Rupaidiha), counter, paid in CASH       -> no pay QR anywhere, PAID · नगद प्राप्त भयो / Cash received
 *   INR   r2, 1 passenger, money due                           -> Indian UPI QR with the exact ₹ due
 *   NPR   Nepal desk sale r7 (NPR frozen 3,520), money due     -> Nepal QR (eSewa image), amount in रू, never UPI
 *   R7    r7 INR sale, money due (trip from the Nepal side)    -> Nepal QR by country (ticket_pay_qr_by_country), off -> UPI
 *   GRP   r7, 3 passengers, paid online                        -> the list, Nepal flag
 *   COD   r7 INR pay-at-boarding, due (review)                 -> the live pay.php link + ticket key, never the static Nepal image
 *   PEND  r7 INR online booking pending (review)               -> the live pay.php link, never the static Nepal image
 *   DISC  r2, ₹200 desk discount (review)                      -> "SAVED ₹200 (9.1%) · काउन्टर छुट" strip; normal fare (nf1) wins the slot
 *   GEO   r2 INR due + booked_lat / lng / place (cp1)          -> "Booked at: ..." row, the clean PNG 21 px taller
 *   NPR again with the eSewa id but no QR image                -> the ID card (due + id + PNR in remarks), no QR frame, no ring
 * Settings go into Settings' in-memory cache only (reflection); the shared DB's settings
 * rows are never written. A throw-away Nepal QR image is written to uploads/payqr/ and removed.
 *
 * With MP1_OUT=<dir> the rendered PNGs (+ the PDFs) are copied there to be looked at.
 *
 *   php -c .claude/php-dev.ini tests/mp1-ticket-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
if (!class_exists('Boarding')) { require_once INCLUDE_PATH . '/boarding.php'; }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* In-memory settings only. */
$ref   = new ReflectionClass('Settings');
$cache = $ref->getProperty('cache');
$cache->setAccessible(true);
Settings::get('upi_id');
$orig = $cache->getValue();
$setMem = static function (array $pairs) use ($cache): void {
    $c = $cache->getValue() ?? [];
    foreach ($pairs as $k => [$v, $t]) {
        $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    }
    $cache->setValue(null, $c);
    $dec = (new ReflectionClass('Settings'))->getProperty('decoded');
    $dec->setAccessible(true);
    $dec->setValue(null, []);
};
$restoreMem = static function () use ($cache, $orig): void { $cache->setValue(null, $orig); };

/* A throw-away Nepal QR image (the shape owner-setup.php saves: uploads/payqr/esewa-npj-*.png). */
$qrDir  = ROOT_PATH . '/uploads/payqr';
$qrName = 'esewa-npj-mp1test' . bin2hex(random_bytes(3)) . '.png';
$madeDir = !is_dir($qrDir) && @mkdir($qrDir, 0755, true);
$qrImg = imagecreatetruecolor(300, 300);
imagefill($qrImg, 0, 0, imagecolorallocate($qrImg, 255, 255, 255));
for ($i = 0; $i < 300; $i += 20) { imagefilledrectangle($qrImg, $i, ($i * 7) % 280, $i + 10, ($i * 7) % 280 + 18, imagecolorallocate($qrImg, 20, 120, 40)); }
imagepng($qrImg, $qrDir . '/' . $qrName);
imagedestroy($qrImg);

$setMem([
    'upi_id'                   => ['9104801507.eazypay@icici', 'string'],
    'upi_name'                 => ['S HARI GLOBAL PRIVATE LIMITED', 'string'],
    'ticket_pay_qr_upi'        => ['1', 'bool'],
    'ticket_maps_qr'           => ['1', 'bool'],
    'ticket_npr_primary'       => ['1', 'bool'],
    'ticket_nepal_office_all'  => ['1', 'bool'],
    'ticket_clean_v2_on'       => ['1', 'bool'],
    'ticket_cash_no_payqr'     => ['1', 'bool'],
    'ticket_pay_qr_by_country' => ['1', 'bool'],
    'nepal_esewa_qr'           => ['uploads/payqr/' . $qrName, 'string'],
    'nepal_esewa_id'           => ['9866201375', 'string'],
    'nepal_office_map'         => ['', 'string'],
    'india_hub_map'            => ['', 'string'],
    'nepal_company'            => ['Shree Hari Global Pvt Ltd', 'string'],
    'nepal_phone'              => ['+977 98662 01375 / 98257 88450', 'string'],
    'nepal_pan'                => ['624740276', 'string'],
    'npr_per_inr'              => ['1.6', 'float'],
    'normal_fare_on'           => ['0', 'bool'],
]);

$out = (string) (getenv('MP1_OUT') ?: '');
if ($out !== '' && !is_dir($out)) { @mkdir($out, 0777, true); }

const MP1_DATE = '2099-11-25';
$made = ['sched' => [], 'book' => []];
$cleanup = static function () use (&$made): void {
    foreach ($made['book'] as $bid => $pnr) {
        $t = Database::fetch('SELECT png_path, pdf_path FROM tickets WHERE booking_id = :b', ['b' => $bid]);
        foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) { @unlink(TICKET_PATH . '/ticket_' . $pnr . $ext); }
        foreach (['png_path', 'pdf_path'] as $c) { if (!empty($t[$c])) { @unlink(TICKET_PATH . '/' . basename((string) $t[$c])); } }
        foreach (['tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $tb) {
            try { Database::delete($tb, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('audit_logs', "entity_type = 'booking' AND entity_id = :p", ['p' => $pnr]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :b', ['b' => $bid]);
    }
    $made['book'] = [];
    foreach ($made['sched'] as $sid) {
        try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}
    }
    $made['sched'] = [];
};
$schedFor = static function (int $routeId, string $dep) use (&$made): int {
    for ($slot = 96; $slot >= 60; $slot--) {
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => $routeId, 'd' => MP1_DATE, 's' => $slot]) === null) {
            $id = (int) Database::insert('schedules', ['route_id' => $routeId, 'travel_date' => MP1_DATE, 'slot' => $slot,
                'dep_time_override' => $dep, 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            $made['sched'][] = $id;
            return $id;
        }
    }
    throw new RuntimeException('no free schedule slot');
};
/** @param list<string> $seats */
$seed = static function (string $tag, int $routeId, int $sched, string $board, string $drop, array $seats, array $extra = [], ?array $pay = null) use (&$made): int {
    $n   = count($seats);
    $pnr = 'SHG-2099-' . random_int(40000, 49999);
    $bid = (int) Database::insert('bookings', $extra + ['pnr' => $pnr, 'contact_phone' => '90000325' . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT),
        'contact_country_code' => '91', 'booking_mode' => 'sharing', 'fare_per_seat' => 2200, 'base_total' => 2200 * $n, 'total_amount' => 2200 * $n,
        'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
    $made['book'][$bid] = $pnr;
    $leg = (int) Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sched, 'travel_date' => MP1_DATE,
        'boarding_stop' => $board, 'drop_stop' => $drop, 'fare_per_seat' => 2200, 'seat_count' => $n, 'leg_total' => 2200 * $n]);
    $names = ['Ram Bahadur Thapa', 'Sita Kumari', 'Hari Prasad', 'Gita Devi'];
    foreach ($seats as $i => $s) {
        Database::insert('booking_seats', ['schedule_id' => $sched, 'seat_no' => $s, 'booking_id' => $bid, 'leg_id' => $leg]);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'MP1' . $tag . $i . substr($pnr, -4),
            'seat_no' => $s, 'full_name' => 'ZZTEST mp1 ' . $names[$i % 4], 'age' => 30 + $i, 'gender' => $i % 2 ? 'Female' : 'Male', 'is_primary' => $i === 0 ? 1 : 0]);
    }
    if ($pay !== null) {
        Database::insert('payments', $pay + ['booking_id' => $bid, 'payment_ref' => 'MP1P' . substr($pnr, -5), 'method' => 'upi', 'mode' => 'utr',
            'amount' => 2200 * $n, 'currency' => 'INR', 'status' => 'verified', 'verified_at' => date('Y-m-d H:i:s'), 'utr_number' => '123456789012']);
    }
    return $bid;
};
$load = static function (int $bid): array {
    $m = new ReflectionMethod('Ticket', 'loadBooking');
    $m->setAccessible(true);
    return $m->invoke(null, $bid);
};
$plain = static function (string $pdf): string {
    $t = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $s) { $z = @gzuncompress($s); $t .= ($z === false ? $s : $z) . "\n"; }
    }
    return $t;
};
$dims = static function (string $p): array {
    $i = @getimagesize($p);
    return $i === false ? [0, 0] : [(int) $i[0], (int) $i[1]];
};

try {
    $r2 = (int) (Database::scalar("SELECT id FROM routes WHERE route_code = 'r2'") ?? 0);
    $r7 = (int) (Database::scalar("SELECT id FROM routes WHERE route_code = 'r7'") ?? 0);
    if ($r2 === 0 || $r7 === 0) { echo "  SKIP  routes r2 / r7 missing\n"; exit(0); }
    $s2 = $schedFor($r2, '13:00:00');
    $s7 = $schedFor($r7, '18:00:00');
    $agent = (int) (Database::scalar("SELECT id FROM admins WHERE role = 'agent' AND full_name <> '' ORDER BY id LIMIT 1") ?? 0);

    $CASH = $seed('C', $r2, $s2, 'Surat', 'Rupaidiha', ['L3'], ['source' => 'counter'] + ($agent > 0 ? ['sold_by_admin_id' => $agent] : []),
        ['method' => 'cash', 'mode' => 'offline', 'utr_number' => null]);
    $INR  = $seed('I', $r2, $s2, 'Surat', 'Rupaidiha', ['L4']);
    $NPR  = $seed('N', $r7, $s7, 'Rupaidiha', 'Surat', ['L11'], ['counter_code' => 'NPJ', 'entity' => 'NP', 'source' => 'counter',
        'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => 3520]);
    $R7   = $seed('R', $r7, $s7, 'Rupaidiha', 'Surat', ['L12']);
    $GRP  = $seed('G', $r7, $s7, 'Rupaidiha', 'Surat', ['L5', 'L6', 'U7'], [], []);
    /* review (money): r7 INR pay-at-boarding / pending online tickets keep the live pay link */
    $COD  = $seed('K', $r7, $s7, 'Rupaidiha', 'Surat', ['L13'], ['is_cod' => 1]);
    $PEND = $seed('P', $r7, $s7, 'Rupaidiha', 'Surat', ['L14'], ['status' => 'pending', 'source' => 'web']);
    /* review: a desk discount (₹200 of 2,200) prints its SAVED line on the clean PNG */
    $DISC = $seed('D', $r2, $s2, 'Surat', 'Rupaidiha', ['L8'], ['coupon_discount' => 200, 'total_amount' => 2000, 'source' => 'counter']);
    $bC = $load($CASH); $bI = $load($INR); $bN = $load($NPR); $bR = $load($R7); $bG = $load($GRP);
    $bK = $load($COD); $bP = $load($PEND); $bD = $load($DISC);

    echo "\n== flag by direction ==\n";
    check('r2 (Gujarat -> Rupaidiha): India flag', Ticket::flagByDirection($bC) === 'IN', Ticket::flagByDirection($bC));
    check('r7 (Rupaidiha -> Gujarat): Nepal flag', Ticket::flagByDirection($bR) === 'NP', Ticket::flagByDirection($bR));
    check('a Nepal-issued r7 sale: Nepal flag', Ticket::flagByDirection($bN) === 'NP');

    echo "\n== rule A: cash paid -> no pay QR, only the PAID stamp ==\n";
    $qC = Ticket::paymentQr($bC);
    check('CASH: paymentQr prints no QR', $qC['qr'] === '' && $qC['paid'] === true, $qC['mode']);
    $stC = Ticket::paidStamp($bC);
    check('CASH: stamp = PAID · नगद प्राप्त भयो / Cash received', $stC['state'] === 'paid' && $stC['way'] === 'cash'
        && $stC['text'] === 'PAID · नगद प्राप्त भयो / Cash received', $stC['text']);
    $stG = Ticket::paidStamp($bG);
    check('GRP (UPI verified): stamp says paid online, never cash', $stG['way'] === 'online' && str_contains($stG['en'], 'UPI'), $stG['text']);
    check('INR due: stamp says due', Ticket::paidStamp($bI)['state'] === 'due');

    echo "\n== rule A: pay QR by country while money is due ==\n";
    $qI = Ticket::paymentQr($bI);
    check('INR due (India): Indian UPI QR with the exact ₹ due', $qI['country'] === 'IN' && $qI['mode'] === 'due'
        && str_starts_with($qI['qr'], 'upi://pay?') && str_contains($qI['qr'], 'am=2200'), $qI['qr']);
    $qN = Ticket::paymentQr($bN);
    check('NPR due: Nepal QR (eSewa image), never UPI', $qN['country'] === 'NP' && $qN['mode'] === 'npr'
        && $qN['qr'] === '' && str_ends_with(str_replace('\\', '/', $qN['esewaImg']), $qrName), $qN['mode'] . ' ' . $qN['esewaImg']);
    check('NPR due: amount in रू at the frozen rate', str_contains($qN['caption'], 'रू 3,520'), $qN['caption']);
    $qR = Ticket::paymentQr($bR);
    check('R7 INR due from the Nepal side: Nepal QR by country, रू at npr_per_inr', $qR['country'] === 'NP' && $qR['mode'] === 'npr'
        && !str_contains($qR['qr'], 'upi://') && str_contains($qR['caption'], 'रू 3,520'), $qR['mode'] . ' / ' . $qR['caption']);
    check('nprDue: frozen sale uses the frozen total, an INR sale today\'s rate', Ticket::nprDue($bN, 2200) === 3520.0 && Ticket::nprDue($bR, 1000) === 1600.0);
    $setMem(['ticket_pay_qr_by_country' => ['0', 'bool']]);
    $qR0 = Ticket::paymentQr($bR);
    check('ticket_pay_qr_by_country off: the r7 INR sale keeps the UPI QR (old rule)', $qR0['mode'] === 'due' && str_starts_with($qR0['qr'], 'upi://'), $qR0['mode']);
    check('...and a frozen NPR sale still never gets UPI', Ticket::paymentQr($bN)['mode'] === 'npr');
    $setMem(['ticket_pay_qr_by_country' => ['1', 'bool']]);
    $setMem(['nepal_esewa_qr' => ['', 'string'], 'nepal_esewa_id' => ['', 'string']]);
    $hasFixed = Ticket::esewaQrFile() !== '';
    $qRn = Ticket::paymentQr($bR);
    check('no Nepal QR / eSewa id on file: the r7 INR sale falls back to UPI (never a blank pay slot)',
        $hasFixed ? $qRn['mode'] === 'npr' : ($qRn['mode'] === 'due' && str_starts_with($qRn['qr'], 'upi://')), $qRn['mode'] . ($hasFixed ? ' (a fixed eSewa file exists)' : ''));
    $setMem(['nepal_esewa_qr' => ['uploads/payqr/' . $qrName, 'string'], 'nepal_esewa_id' => ['9866201375', 'string']]);

    echo "\n== review (money): pay-at-boarding / pending on the Nepal side keep the live pay link ==\n";
    $qK = Ticket::paymentQr($bK);
    check('R7 pay-at-boarding (COD) due: NOT the static Nepal QR - the live pay.php link with the ticket key (UTR -> COD queue)',
        $qK['country'] === 'NP' && $qK['mode'] === 'due' && str_contains($qK['qr'], 'pay.php?pnr=') && str_contains($qK['qr'], '&k=')
        && !str_contains($qK['qr'], 'upi://') && $qK['esewaImg'] === '', $qK['mode'] . ' ' . $qK['qr']);
    $qP = Ticket::paymentQr($bP);
    check('R7 pending online booking due: the live pay.php link (proof box + expiry clock), never the static image',
        $qP['mode'] === 'due' && str_contains($qP['qr'], 'pay.php?pnr=') && !str_contains($qP['qr'], '&k=') && $qP['esewaImg'] === '', $qP['mode'] . ' ' . $qP['qr']);
    check('staticPayQrOk: confirmed + not COD + no proof only', Ticket::staticPayQrOk($bR) && !Ticket::staticPayQrOk($bK) && !Ticket::staticPayQrOk($bP));
    $proofB = $bR;
    $proofB['payment'] = ['id' => 0, 'status' => 'pending', 'utr_number' => '123456789012'];
    check('staticPayQrOk: a UTR already waiting keeps the live link', !Ticket::staticPayQrOk($proofB) && Ticket::paymentQr($proofB)['mode'] === 'due');
    $js0 = (string) file_get_contents(ROOT_PATH . '/assets/js/07-checkout.js');
    check('web ticket (tkGoldPay): the same gate before the Nepal image on an INR ticket', str_contains($js0, "const staticOk = b.status === 'confirmed' && !isCod")
        && str_contains($js0, 'fromNp && staticOk'));

    echo "\n== review: the saving line (discount % + source, normal fare struck through) ==\n";
    $svD = Ticket::saveLine($bD);
    check('saveLine: a ₹200 desk discount = SAVED ₹200 (9.1%) · काउन्टर छुट', $svD['kind'] === 'disc' && str_contains($svD['text'], 'SAVED ₹200')
        && str_contains($svD['text'], '(9.1%)') && str_contains($svD['text'], 'काउन्टर छुट'), $svD['text']);
    check('saveLine: nothing saved = no strip', Ticket::saveLine($bI)['kind'] === '');
    $setMem(['normal_fare_on' => ['1', 'bool'], 'normal_fare_json' => [json_encode([(string) $r2 => 3000]), 'json']]);
    $svN = Ticket::saveLine($bD);
    check('saveLine: the route\'s real normal fare (nf1) wins the slot: ₹3,000 struck + बचत SAVED ₹1,000', $svN['kind'] === 'nf'
        && $svN['normal'] === '₹3,000' && $svN['save'] === 'बचत SAVED ₹1,000', $svN['text']);
    check('saveLine: never a normal fare on an NPR sale', Ticket::saveLine($bN)['kind'] !== 'nf');
    $setMem(['normal_fare_on' => ['0', 'bool']]);

    echo "\n== pickup phone + booked by ==\n";
    check('pickupPhone: never empty (location row, else the desk / company number)', Ticket::pickupPhone($bC) !== '', Ticket::pickupPhone($bC));
    $bb = Ticket::bookedByText($bC);
    check('bookedByText: names who cut it', $bb !== '', $bb);
    check('bookedByText: an online sale says Online', str_starts_with(Ticket::bookedByText(['source' => 'web'] + $bI + ['sold_by_admin_id' => 0]), 'Online'));
    $bbN = Ticket::bookedByText($bN);
    check('bookedByText: a Nepal counter sale prints the desk / company number (issuedBy keeps none for a desk)',
        preg_match('/\d{5}/', $bbN) === 1, $bbN);
    $bbO = Ticket::bookedByText(['sold_by_admin_id' => 0, 'source' => 'admin', 'counter_code' => ''] + $bI);
    check('bookedByText: an office sale with no desk falls back to the company line', preg_match('/\d{5}/', $bbO) === 1, $bbO);

    echo "\n== PNG (clean) ==\n";
    $pngs = [];
    foreach (['cash' => $CASH, 'inr-due' => $INR, 'npr-due' => $NPR, 'r7-due' => $R7, 'group' => $GRP, 'r7-cod' => $COD, 'disc' => $DISC] as $tag => $bid) {
        $p = Ticket::pngPath($bid, true);
        [$w, $h] = $dims($p);
        $pngs[$tag] = [$p, $h];
        check("$tag: clean PNG renders 1080 wide", $w === 1080 && $h > 1600, $w . 'x' . $h);
        if ($out !== '') { @copy($p, $out . '/mp1-' . $tag . '.png'); }
    }
    check('group: the passenger list grows the canvas', $pngs['group'][1] > $pngs['cash'][1], $pngs['group'][1] . ' > ' . $pngs['cash'][1]);
    $noteH = (int) (new ReflectionClass('Ticket'))->getConstant('PNG_NOTE_H');
    check('disc: the saving strip grows the canvas by one strip', $pngs['disc'][1] === $pngs['inr-due'][1] + $noteH, $pngs['disc'][1] . ' vs ' . $pngs['inr-due'][1] . ' + ' . $noteH);
    /* the header carries ONE flag: the India flag's saffron top band at the right edge for r2, the Nepal crimson for r7 */
    $px = static function (string $file, int $x, int $y): array {
        $im = imagecreatefrompng($file);
        $c = imagecolorat($im, $x, $y);
        imagedestroy($im);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    };
    $flagIn = DocArt::flagWidth('IN', 132);
    [$r, $g, $b] = $px($pngs['cash'][0], 1080 - 64 - intdiv($flagIn, 2), 36 + intdiv(256 - 36 - 132, 2) + 10);
    check('cash (r2): India flag in the header (saffron band)', $r > 220 && $g > 110 && $g < 190 && $b < 110, "rgb($r,$g,$b)");
    /* the saving strip sits under the payment bar: pale green at body y 1092 (the INR ticket: the passenger tiles' card white) */
    [$r, $g, $b] = $px($pngs['disc'][0], 64 + 420, 1092 + 6);
    check('disc: the pale-green saving strip under the payment bar', $g > $r && $g > $b && $r > 200 && $r < 245, "rgb($r,$g,$b)");
    [$r, $g, $b] = $px($pngs['inr-due'][0], 64 + 420, 1092 + 6);
    check('inr-due: no saving strip there (nothing saved)', !($g > $r + 6 && $g > $b + 4), "rgb($r,$g,$b)");
    $setMem(['normal_fare_on' => ['1', 'bool'], 'normal_fare_json' => [json_encode([(string) $r2 => 3000]), 'json']]);
    $pNf = Ticket::pngPath($DISC, true);
    [, $hNf] = $dims($pNf);
    check('disc + normal fare: the same one strip (normal fare struck through + saving)', $hNf === $pngs['disc'][1], $hNf . ' vs ' . $pngs['disc'][1]);
    if ($out !== '') { @copy($pNf, $out . '/mp1-disc-nf.png'); }
    $setMem(['normal_fare_on' => ['0', 'bool']]);
    /* the pill (right end of the PNR row): a pay-at-boarding ticket orange, a paid one green */
    [$r, $g, $b] = $px($pngs['r7-cod'][0], 1004, 346);
    check('r7-cod: the PAY ON BOARDING pill is orange', $r > 180 && $g > 60 && $g < 140 && $b < 60, "rgb($r,$g,$b)");
    [$r, $g, $b] = $px($pngs['cash'][0], 1004, 346);
    check('cash: the PAID pill is green', $g > $r && $g > $b, "rgb($r,$g,$b)");
    $flagNp = DocArt::flagWidth('NP', 132);
    [$r, $g, $b] = $px($pngs['r7-due'][0], 1080 - 64 - $flagNp + intdiv($flagNp * 12, 100), 36 + intdiv(256 - 36 - 132, 2) + 66);
    check('r7: Nepal flag in the header (crimson)', $r > 170 && $g < 80 && $b < 100, "rgb($r,$g,$b)");

    /* dark (text) pixels in rows [$y1..$y2], x in [$x1..$x2]: [count, leftmost x, rightmost x] */
    $ink = static function (string $file, int $x1, int $x2, int $y1, int $y2): array {
        $im = imagecreatefrompng($file);
        $n = 0; $lo = PHP_INT_MAX; $hi = -1;
        for ($y = max(0, $y1); $y <= min(imagesy($im) - 1, $y2); $y++) {
            for ($x = max(0, $x1); $x <= min(imagesx($im) - 1, $x2); $x++) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 16) & 0xFF) < 170 && (($c >> 8) & 0xFF) < 180 && ($c & 0xFF) < 200) { $n++; $lo = min($lo, $x); $hi = max($hi, $x); }
            }
        }
        imagedestroy($im);
        return [$n, $lo, $hi];
    };

    echo "\n== cp1 \"Booked at\" line on the clean PNG ==\n";
    if (!class_exists('BookGeo')) { require_once INCLUDE_PATH . '/bookgeo.php'; }
    if (!BookGeo::hasColumns()) {
        echo "  SKIP  bookings.booked_lat / booked_place missing (cp1 migration not applied)\n";
    } else {
        /* the INR ticket again (r2, 1 passenger, money due), now with the phone's GPS fix on the row */
        $GEO = $seed('A', $r2, $s2, 'Surat', 'Rupaidiha', ['L9'], ['booked_lat' => 23.109162, 'booked_lng' => 72.677421,
            'booked_acc' => 35, 'booked_place' => 'Nana Chiloda, Ahmedabad']);
        $bA   = $load($GEO);
        $geoL = Ticket::bookedAtLine($bA);
        check('geo: the cp1 helper string is non-empty (where the ticket was cut)', $geoL !== ''
            && $geoL === 'Booked at: Nana Chiloda, Ahmedabad (23.10916, 72.67742)', $geoL);
        check('geo: the same booking without a fix has no line', Ticket::bookedAtLine($bI) === '');
        $pA = Ticket::pngPath($GEO, true);
        [$wA, $hA] = $dims($pA);
        if ($out !== '') { @copy($pA, $out . '/mp1-geo.png'); }
        check('geo: the clean PNG grows by exactly one 21 px row', $wA === 1080 && $hA === $pngs['inr-due'][1] + 21, $hA . ' vs ' . $pngs['inr-due'][1] . ' + 21');
        /* the row sits where the old layout puts it: after the two statutory rows, its baseline
           33 px above the white card's bottom (H - 40), centred, nothing under it */
        $gBase = $hA - 40 - 33;
        [$gn, $glo, $ghi] = $ink($pA, 80, 1000, $gBase - 12, $gBase + 3);
        check('geo: the "Booked at" row is drawn under the statutory rows', $gn > 60, "$gn dark px");
        check('geo: ...centred on the card', $ghi > $glo && abs(intdiv($glo + $ghi, 2) - 540) <= 8, "x $glo..$ghi");
        [$gu] = $ink($pA, 40, 1040, $gBase + 6, $hA - 44);
        check('geo: ...and nothing below it overlaps the card edge', $gu === 0, "$gu dark px");
        [$sn] = $ink($pA, 80, 1000, $gBase - 21 - 12, $gBase - 21 + 3);
        check('geo: the statutory row above it is still there', $sn > 60, "$sn dark px");
        [$nn] = $ink($pngs['inr-due'][0], 80, 1000, $gBase - 12, $gBase + 3);
        check('geo: without a fix that row does not exist (the INR ticket is plain card there)', $nn === 0, "$nn dark px");
    }

    echo "\n== NPR due, eSewa id but no Nepal QR image: no empty QR frame ==\n";
    $esewaWas = Settings::getString('esewa_id', '');
    $setMem(['nepal_esewa_qr' => ['', 'string']]);   // the id stays, the image goes
    if (Ticket::esewaQrFile() !== '') {
        echo "  SKIP  a fixed eSewa QR file is on the server (esewa-qr.*), the id-only case cannot happen here\n";
    } else {
        $qNi = Ticket::paymentQr($bN);
        check('NPR id only: paymentQr = Nepal mode, no image, the id, no QR payload', $qNi['mode'] === 'npr' && $qNi['esewaImg'] === ''
            && $qNi['esewa'] === '9866201375' && $qNi['qr'] === '', $qNi['mode'] . ' / ' . $qNi['esewa']);
        /* the pay card (right half): its top found by scanning down near its right edge for the orange
           head; then (a) the row just under the head, where a QR's corner frame starts, and (b) the two
           columns 131 px either side of the card's centre, where the stamp's ring runs, carry no strong
           orange. Both probes are proven on the image card (frame) and the stamp fallback (ring). */
        $cxR = 64 + intdiv(1080 - 128 - 20, 2) + 20;
        $mx  = intdiv($cxR + 1080 - 64, 2);
        /* the deep orange of a frame / ring (212,87,12) - not the red DUE bar (206,62,52), not pale orange */
        $isOrg = static fn(int $c): bool => (($c >> 16) & 0xFF) > 170 && (($c >> 8) & 0xFF) > 72
            && (($c >> 16) & 0xFF) - (($c >> 8) & 0xFF) > 70 && (($c >> 16) & 0xFF) - ($c & 0xFF) > 130;
        $cardProbe = static function (string $file) use ($isOrg, $cxR, $mx): array {
            $im = imagecreatefrompng($file);
            $top = 0;
            for ($y = 900; $y < imagesy($im) - 400; $y++) {
                if ($isOrg(imagecolorat($im, 1080 - 64 - 30, $y))) { $top = $y; break; }
            }
            $frame = 0; $ring = 0;
            if ($top > 0) {
                for ($y = $top + 56; $y <= $top + 63; $y++) {
                    for ($x = $cxR + 12; $x <= 1080 - 64 - 12; $x++) { $frame += $isOrg(imagecolorat($im, $x, $y)) ? 1 : 0; }
                }
                for ($y = $top + 60; $y <= $top + 350; $y++) {
                    foreach ([$mx - 131, $mx + 131] as $x) { $ring += $isOrg(imagecolorat($im, $x, $y)) ? 1 : 0; }
                }
            }
            imagedestroy($im);
            return [$top, $frame, $ring];
        };
        /* probe check first, on the npr-due render still on disk (same file the id-only render replaces) */
        [$iTop, $iFrame] = $cardProbe($pngs['npr-due'][0]);
        check('probe check: the image card (npr-due) does show its corner frame there', $iTop > 900 && $iFrame > 20, "$iFrame orange px");
        $pNi = Ticket::pngPath($NPR, true);
        [$wNi, $hNi] = $dims($pNi);
        if ($out !== '') { @copy($pNi, $out . '/mp1-npr-id-only.png'); }
        check('NPR id only: the clean PNG renders, same canvas as with the image', $wNi === 1080 && $hNi === $pngs['npr-due'][1], $hNi . ' vs ' . $pngs['npr-due'][1]);
        check('NPR id only: the renderer picks the ID card (due + eSewa / Fonepay ID + PNR in remarks)', Ticket::v2PayCard($bN) === 'id', Ticket::v2PayCard($bN));
        [$cTop, $cFrame, $cRing] = $cardProbe($pNi);
        check('NPR id only: the pay card found', $cTop > 900, (string) $cTop);
        check('NPR id only: no QR corner frame under the card head', $cFrame === 0, "$cFrame orange px");
        check('NPR id only: no stamp ring either', $cRing === 0, "$cRing orange px");
        [$dn] = $ink($pNi, $cxR + 24, 1080 - 64 - 24, $cTop + 194 + 10, $cTop + 252 - 10);
        check('NPR id only: the eSewa / Fonepay ID line is printed in the card', $dn > 150, "$dn dark px");
        [$pn] = $ink($pNi, $cxR + 24, 1080 - 64 - 24, $cTop + 270, $cTop + 330);
        check('NPR id only: the remarks / PNR lines are printed under it', $pn > 150, "$pn dark px");
        $setMem(['nepal_esewa_qr' => ['uploads/payqr/' . $qrName, 'string']]);
        check('NPR with the image: the renderer keeps the image card', Ticket::v2PayCard($bN) === 'img', Ticket::v2PayCard($bN));
        check('INR due: the UPI QR card; paid cash: the stamp', Ticket::v2PayCard($bI) === 'qr' && Ticket::v2PayCard($bC) === 'stamp');
        /* neither: no image, no Nepal id and no company eSewa id (companyFor()'s payEsewa falls back to esewa_id) */
        $setMem(['nepal_esewa_qr' => ['', 'string'], 'nepal_esewa_id' => ['', 'string'], 'esewa_id' => ['', 'string']]);
        $qNn = Ticket::paymentQr($bN);
        check('NPR with neither image nor id: the old fallback (the stamp with the due in words)', Ticket::v2PayCard($bN) === 'stamp'
            && $qNn['mode'] === 'npr' && $qNn['esewa'] === '' && $qNn['esewaImg'] === '', Ticket::v2PayCard($bN) . ' / ' . $qNn['mode']);
        $pNn = Ticket::pngPath($NPR, true);
        if ($out !== '') { @copy($pNn, $out . '/mp1-npr-no-id.png'); }
        [$sTop, , $sRing] = $cardProbe($pNn);
        check('probe check: that fallback stamp does show its ring there', $sTop > 900 && $sRing > 10, "$sRing orange px");
    }
    $setMem(['nepal_esewa_qr' => ['uploads/payqr/' . $qrName, 'string'], 'nepal_esewa_id' => ['9866201375', 'string'], 'esewa_id' => [$esewaWas, 'string']]);

    echo "\n== PDF (clean) ==\n";
    $tC = $plain((string) file_get_contents(Ticket::pdfPath($CASH, true)));
    check('cash PDF: "Cash received" band', str_contains($tC, 'Cash received'));
    check('cash PDF: no pay QR caption', !str_contains($tC, 'PAY UPI') && !str_contains($tC, 'PAY INR'));
    $tI = $plain((string) file_get_contents(Ticket::pdfPath($INR, true)));
    check('INR due PDF: the UPI pay QR caption', str_contains($tI, 'PAY UPI'));
    $pN = Ticket::pdfPath($NPR, true);
    $tN = $plain((string) file_get_contents($pN));
    check('NPR due PDF: NPR 3,520 + the eSewa slot, no UPI', str_contains($tN, 'NPR 3,520') && str_contains($tN, 'eSewa') && !str_contains($tN, 'PAY UPI'));
    if ($out !== '') {
        @copy(Ticket::pdfPath($CASH), $out . '/mp1-cash.pdf');
        @copy($pN, $out . '/mp1-npr-due.pdf');
    }

    echo "\n== v2 off -> the old layout ==\n";
    $setMem(['ticket_clean_v2_on' => ['0', 'bool']]);
    $pOld = Ticket::pngPath($CASH, true);
    [, $hOld] = $dims($pOld);
    $head = (int) (new ReflectionClass('Ticket'))->getConstant('PNG_HEAD_EXTRA');
    [$r, $g, $b] = $px($pOld, 100, $head + 420);
    check('v2 off: the old layout (orange SEAT tile at body 420)', $r > 190 && $g > 60 && $g < 150 && $b < 80, "rgb($r,$g,$b)");
    check('v2 off: a different canvas than the clean one', $hOld !== $pngs['cash'][1], $hOld . ' vs ' . $pngs['cash'][1]);
    if ($out !== '') { @copy($pOld, $out . '/mp1-cash-v2off.png'); }
    check('v2 off: cash still prints no pay QR', Ticket::paymentQr($bC)['qr'] === '');
    $tOld = $plain((string) file_get_contents(Ticket::pdfPath($CASH, true)));
    check('v2 off: the PDF band is the old one (no "Cash received")', !str_contains($tOld, 'Cash received'));
    $setMem(['ticket_clean_v2_on' => ['1', 'bool']]);

    echo "\n== static ==\n";
    $src = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
    $ref1 = new ReflectionClass('Ticket');
    $pngStamp = (string) $ref1->getConstant('PNG_LAYOUT_CHANGED');
    check('PNG layout stamp moved to 25 Nov and is in the past', str_starts_with($pngStamp, '2026-11-25') && strtotime($pngStamp) <= time() + 60, $pngStamp);
    check('renderTicketPng hands over to the clean layout behind the switch', str_contains($src, 'if (self::cleanV2On()) {') && str_contains($src, 'self::renderTicketPngV2('));
    $js = (string) file_get_contents(ROOT_PATH . '/assets/js/07-checkout.js');
    check('web ticket: a paid ticket gets no pay QR (tkGoldPay null behind ticket_cash_no_payqr)', str_contains($js, 'ticket_cash_no_payqr') && str_contains($js, 'return null'));
    $p2 = (int) strpos($js, 'const primary2');
    $s2 = (int) strpos($js, 'const secondary2');
    $prim = substr($js, $p2, max(0, $s2 - $p2));
    $sec  = substr($js, $s2, 1400);
    check('web ticket: Print beside Image + WhatsApp right after confirm (not folded under More)', $p2 > 0 && $s2 > $p2
        && str_contains($prim, 'id="imgBtn"') && str_contains($prim, 'id="waBtn"') && str_contains($prim, 'id="printBtn"') && !str_contains($sec, 'id="printBtn"'));
    check('web ticket: the Print handler is still bound', str_contains($js, "$('#printBtn').onclick"));
    $sb = (string) file_get_contents(ROOT_PATH . '/assets/js/27-smartbook.js');
    check('SmartBook ticket: Share, PNG and Print (customer too) right after the sale', str_contains($sb, 'data-tk="share"') && str_contains($sb, "tr('dlPng')")
        && str_contains($sb, "(!STAFF && t.print ?"));
    $tm0 = (int) strpos($sb, 'function tkMagic(');
    $tm1 = (int) strpos($sb, '/* ------------------------------------------------------------ ticket */', $tm0);
    $tmSrc = substr($sb, $tm0, max(0, $tm1 - $tm0));
    check('SmartBook magic overlay: Share / PNG / Print on it from the first frame (spec D), Share = the card\'s own shareTicket()',
        $tm0 > 0 && str_contains($tmSrc, '+ tkmActs(r)') && str_contains($tmSrc, 'data-tkm="share"') && str_contains($tmSrc, "tr('dlPng')")
        && str_contains($tmSrc, "tr('print')") && str_contains($tmSrc, 'shareTicket(r)') && str_contains($sb, "if (act === 'share') shareTicket(r);"));
    $sbCss = (string) file_get_contents(ROOT_PATH . '/assets/css/smartbook.css');
    check('SmartBook magic overlay: the action row has no animation delay', (bool) preg_match('/\.tkm-acts\{[^}]*animation:tkmIn \.2s ease-out both\}/', $sbCss));
    $es = (string) file_get_contents(ROOT_PATH . '/admin/easy-settings.php');
    check('Easy Settings: flipping a ticket_* switch redraws cached tickets (touchTicketIdentity)', (bool) preg_match("/str_starts_with\(\\\$k, 'ticket_'\)[^\n]*\n\s*Settings::touchTicketIdentity\(\);/", $es));
    $st = (string) file_get_contents(ROOT_PATH . '/admin/settings.php');
    check('Settings save: a changed ticket / company key redraws cached tickets', str_contains($st, '$tkBefore[(string) $tk] = json_encode(Settings::get((string) $tk));')
        && (bool) preg_match('/json_encode\(Settings::get\(\$tk\)\) !== \$was\) \{\s*Settings::touchTicketIdentity\(\);/', $st));
    $sbf = new ReflectionMethod('Ticket', 'staleBefore');
    clearstatcache();
    check('staleBefore(): never earlier than ticketclean.php\'s own mtime (a clean-layout fix redraws once)',
        (int) $sbf->invoke(null, '2000-01-01 00:00:00') >= (int) filemtime(INCLUDE_PATH . '/ticketclean.php'));
    $p80 = (string) file_get_contents(ROOT_PATH . '/admin/ticket-80mm.php');
    check('80 mm receipt: the PAID stamp words, no pay QR', str_contains($p80, 'Ticket::stampFor(') && !str_contains($p80, 'paymentQr('));
    check('stampFor(): the 80 mm receipt gets the cash stamp + booked by', Ticket::stampFor($CASH)['stamp']['text'] === 'PAID · नगद प्राप्त भयो / Cash received'
        && Ticket::stampFor($CASH)['bookedBy'] !== '');
    $pdfJs = (string) file_get_contents(ROOT_PATH . '/assets/js/11-pdf-ticket.js');
    check('client PDF: the Nepal QR image is drawn as it is, never re-encoded', str_contains($pdfJs, 'if (gpay.img)') && str_contains($pdfJs, '_imgLoad(gpay.url)'));
    $sw = (string) file_get_contents(ROOT_PATH . '/sw.js');
    check('sw.js: VERSION shg-v298, ASSET_VER 20261125m (mp1 on wx1)', str_contains($sw, "var VERSION = 'shg-v298';") && str_contains($sw, "var ASSET_VER = '20261125m';"));
    $idx = (string) file_get_contents(ROOT_PATH . '/index.php');
    check('index.php hands the two switches to the app', str_contains($idx, "'ticket_cash_no_payqr'") && str_contains($idx, "'ticket_clean_v2_on'"));
} catch (Throwable $e) {
    check('ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restoreMem();
    @unlink($qrDir . '/' . $qrName);
    if ($madeDir) { @rmdir($qrDir); }
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
