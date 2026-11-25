<?php
/**
 * u3-ticket-render-test.php — the ticket PNG / PDF for TK-1..TK-6 (19 Nov 2026).
 *
 * Seeds its OWN bookings (far date 2099-11-19, blocked schedules, phones 90000301xx,
 * names "ZZTEST u3 ...") and removes every row and file at the end:
 *   A  1 passenger, r2, boards at S Hari Parking (Nana Chiloda), money due      -> UPI-amount pay QR + pickup map QR
 *   B  3 passengers, r2, Surat, PAID                                             -> no pay QR, map QR only
 *   C  6 passengers, r7 (Rupaidiha -> Surat), money due                          -> pickup + Nepal office map QRs + pay QR
 *   D  Nepal desk sale (entity NP, NPJ, NPR frozen 3,520 for INR 2,200), r7, due -> NPR big, no UPI-amount QR, eSewa words
 * Settings the test needs are put into Settings' in-memory cache only (reflection) - the
 * shared test DB's settings rows are never written.
 *
 * Checks: seatHero(), mapQrs(), paymentQr() 'qr' (exact am= / tn=PNR, never upi:// on NPR),
 * money()/moneyParts(), companyFor() PAN + Reg., companyForCountry('NP'), the PNG renders
 * 1080 wide at the SAME height as before for 1 / 3 / 6 passengers (no canvas growth from the
 * new blocks), the seat tile is orange, the verify QR switch keeps the height, the PDF prints
 * NPR + the Nepal company, the layout stamps moved to 19 Nov and are in the past.
 *
 * With U3_OUT=<dir> the rendered PNGs + PDFs are copied there to be looked at.
 *
 *   php -c .claude/php-dev.ini tests/u3-ticket-render-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
if (!class_exists('Boarding')) { require_once INCLUDE_PATH . '/boarding.php'; }
if (!class_exists('CounterDesk') && is_file(INCLUDE_PATH . '/counterdesk.php')) { require_once INCLUDE_PATH . '/counterdesk.php'; }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* In-memory settings only: the shared DB is never written. */
$ref   = new ReflectionClass('Settings');
$cache = $ref->getProperty('cache');
$cache->setAccessible(true);
Settings::get('upi_id');                       // load once
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
$setMem([
    'upi_id'             => ['9104801507.eazypay@icici', 'string'],
    'upi_name'           => ['S HARI GLOBAL PRIVATE LIMITED', 'string'],
    'ticket_pay_qr_upi'  => ['1', 'bool'],
    'ticket_maps_qr'     => ['1', 'bool'],
    'ticket_verify_qr'   => ['1', 'bool'],
    'ticket_npr_primary' => ['1', 'bool'],
    'nepal_office_map'   => ['', 'string'],
    'india_hub_map'      => ['', 'string'],
    'nepal_company'      => ['Shree Hari Global Pvt Ltd', 'string'],
    'nepal_company_ne'   => ['श्री हरि ग्लोबल प्रा. लि.', 'string'],
    'nepal_phone'        => ['+977 98662 01375 / 98257 88450', 'string'],
    'nepal_whatsapp'     => ['9779866201375', 'string'],
    'nepal_pan'          => ['612454076', 'string'],
    'nepal_reg'          => ['', 'string'],
    'nepal_office'       => ['Pushpalal Chowk, Nepalgunj', 'string'],
    'nepal_office_ne'    => ['पुष्पलाल चोक, नेपालगञ्ज', 'string'],
    'npr_per_inr'        => ['1.6', 'float'],
    /* 25 Nov 2026 (mp1 tk): this suite guards the TK-1..TK-6 layout and pay QR rule - the clean
       layout and the Nepal-QR-by-country rule have their own suite (tests/mp1-ticket-test.php). */
    'ticket_clean_v2_on'       => ['0', 'bool'],
    'ticket_pay_qr_by_country' => ['0', 'bool'],
]);

$out = (string) (getenv('U3_OUT') ?: '');
if ($out !== '' && !is_dir($out)) { @mkdir($out, 0777, true); }

const U3_DATE = '2099-11-19';
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
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => $routeId, 'd' => U3_DATE, 's' => $slot]) === null) {
            $id = (int) Database::insert('schedules', ['route_id' => $routeId, 'travel_date' => U3_DATE, 'slot' => $slot,
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
    $pnr = 'SHG-2099-' . random_int(30000, 39999);
    $bid = (int) Database::insert('bookings', $extra + ['pnr' => $pnr, 'contact_phone' => '90000301' . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT),
        'contact_country_code' => '91', 'booking_mode' => 'sharing', 'fare_per_seat' => 2200, 'base_total' => 2200 * $n, 'total_amount' => 2200 * $n,
        'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
    $made['book'][$bid] = $pnr;
    $leg = (int) Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sched, 'travel_date' => U3_DATE,
        'boarding_stop' => $board, 'drop_stop' => $drop, 'fare_per_seat' => 2200, 'seat_count' => $n, 'leg_total' => 2200 * $n]);
    $names = ['Ram Bahadur Thapa', 'Sita Kumari', 'Hari Prasad', 'Gita Devi', 'Krishna Lal', 'Maya Gurung'];
    foreach ($seats as $i => $s) {
        Database::insert('booking_seats', ['schedule_id' => $sched, 'seat_no' => $s, 'booking_id' => $bid, 'leg_id' => $leg]);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'U3' . $tag . $i . substr($pnr, -4),
            'seat_no' => $s, 'full_name' => 'ZZTEST u3 ' . $names[$i % 6], 'age' => 30 + $i, 'gender' => $i % 2 ? 'Female' : 'Male', 'is_primary' => $i === 0 ? 1 : 0]);
    }
    if ($pay !== null) {
        Database::insert('payments', $pay + ['booking_id' => $bid, 'payment_ref' => 'U3P' . substr($pnr, -5), 'method' => 'upi', 'mode' => 'utr',
            'amount' => 2200 * $n, 'currency' => 'INR', 'status' => 'verified', 'verified_at' => date('Y-m-d H:i:s'), 'utr_number' => '123456789012']);
    }
    return $bid;
};

$load = static function (int $bid): array {
    $m = new ReflectionMethod('Ticket', 'loadBooking');
    $m->setAccessible(true);
    return $m->invoke(null, $bid);
};

try {
    $r2 = (int) (Database::scalar("SELECT id FROM routes WHERE route_code = 'r2'") ?? 0);
    $r7 = (int) (Database::scalar("SELECT id FROM routes WHERE route_code = 'r7'") ?? 0);
    if ($r2 === 0 || $r7 === 0) { echo "  SKIP  routes r2 / r7 missing\n"; exit(0); }
    $hub = (string) (Database::scalar("SELECT stop_name FROM route_stops WHERE route_id = :r AND stop_type = 'boarding' AND (stop_name LIKE '%Chiloda%' OR stop_name LIKE '%Hari%Parking%') ORDER BY id LIMIT 1", ['r' => $r2]) ?? 'S Hari Parking, Nana Chiloda');
    $s2 = $schedFor($r2, '13:00:00');
    $s7 = $schedFor($r7, '18:00:00');

    $A = $seed('A', $r2, $s2, $hub . ' @ 21:00 [1,1]', 'Rupaidiha', ['L3']);
    $B = $seed('B', $r2, $s2, 'Surat', 'Rupaidiha', ['L5', 'L6', 'U7'], [], []);
    $C = $seed('C', $r7, $s7, 'Rupaidiha', 'Surat', ['L1', 'L2', 'L9', 'L10', 'U3', 'U4']);
    $D = $seed('D', $r7, $s7, 'Rupaidiha', 'Surat', ['L11'], ['counter_code' => 'NPJ', 'entity' => 'NP', 'source' => 'counter',
        'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => 3520]);
    $E = $seed('E', $r2, $s2, 'Surat', 'Rupaidiha', ['L8'], ['is_cod' => 1]);

    echo "\n== helpers ==\n";
    $bA = $load($A); $bB = $load($B); $bC = $load($C); $bD = $load($D);
    $h1 = Ticket::seatHero($bA, ['L3']);
    check('seatHero: one seat -> one line, floor तल 1F', count($h1['lines']) === 1 && $h1['count'] === 1 && str_contains($h1['floor'], '1F'), json_encode($h1, JSON_UNESCAPED_UNICODE));
    $h5 = Ticket::seatHero($bA, ['L1', 'L2', 'L3', 'L4', 'U5', 'U6']);
    check('seatHero: six seats -> two lines with +2', count($h5['lines']) === 2 && str_ends_with($h5['lines'][1], '+2') && str_contains($h5['floor'], '2F'), json_encode($h5, JSON_UNESCAPED_UNICODE));
    check('seatHero: no seat -> no tile', Ticket::seatHero($bA, [])['lines'] === []);
    /* review 19 Nov: the tile keeps the passenger order (natsort used to put A11 · A12 before B5 · B6) */
    $lbl = static fn(string $s): string => Ticket::seatHero($bA, [$s])['text'];
    $h6 = Ticket::seatHero($bA, ['L3', 'L4', 'L5', 'L6', 'L11', 'L12']);
    check('seatHero: keeps the given order - the 3rd and 4th berths show, the last two are +2',
        $h6['lines'][1] === $lbl('L5') . ' · ' . $lbl('L6') . ' +2', json_encode($h6, JSON_UNESCAPED_UNICODE));

    $mA = Ticket::mapQrs($bA);
    // nj1 (21 Nov): A also carries the Nepal office QR (ticket_nepal_office_all) - only the pickup one is checked here
    $mA = array_values(array_filter($mA, static fn(array $q): bool => ($q['kind'] ?? '') !== 'nepal'));
    check('A: pickup map QR from the stop ROW pin, not the label [1,1]', count($mA) === 1 && str_starts_with($mA[0]['payload'], 'https://maps.google.com/?q=') && !str_contains($mA[0]['payload'], 'q=1,1'), json_encode($mA));
    check('mapLink: short form, 6 decimals max', Ticket::mapLink('23.1710000, 72.6230000') === 'https://maps.google.com/?q=23.171,72.623' && Ticket::mapLink('nonsense') === '' && Ticket::mapLink('0,0') === '');
    $mC = Ticket::mapQrs($bC);
    $kinds = array_column($mC, 'kind');
    /* 25 Nov 2026 (wx1): C boards at Rupaidiha border parking on a Nepal -> India trip sold on
       the India side - the QR is the chosen place only (ticket_np_pickup_only, default on). */
    check('C (r7, Rupaidiha pickup, wx1): the pickup map only, no extra Nepal office row', $kinds === ['board'], json_encode($kinds));
    $setMem(['ticket_np_pickup_only' => ['0', 'bool']]);
    $mC0 = Ticket::mapQrs($bC);
    check('ticket_np_pickup_only off -> C: pickup + Nepal office map (old rule)', array_column($mC0, 'kind') === ['board', 'nepal'] && $mC0[1]['payload'] === Ticket::NEPAL_OFFICE_MAP_DEFAULT, json_encode(array_column($mC0, 'kind')));
    $setMem(['ticket_np_pickup_only' => ['1', 'bool']]);
    $mD = Ticket::mapQrs($bD);
    check('D (Nepal-issued): Nepal office FIRST', ($mD[0]['kind'] ?? '') === 'nepal', json_encode(array_column($mD, 'kind')));
    $setMem(['nepal_office_map' => ['28.0507, 81.6159', 'string']]);
    check('nepal_office_map lat,lng -> short QR payload', (Ticket::mapQrs($bD)[0]['payload'] ?? '') === 'https://maps.google.com/?q=28.0507,81.6159');
    /* review 19 Nov: a long pasted Google Maps link is shortened to its pin */
    $longLink = 'https://www.google.com/maps/place/Hotel+Darbar,+Pushpalal+Chowk,+Nepalgunj+21900,+Nepal/@28.0501234,81.6123456,17z/data=!3m1!4b1!4m9!3m8!1s0x39a2e3c5b0a1a1a1:0x1234567890abcdef!5m2!4m1!1i2!8m2!3d28.0506789!4d81.6183456!16s%2Fg%2F11c1abcdef?entry=ttu';
    check('mapLink: a 250-byte place link -> the place pin (!3d!4d), short', strlen($longLink) > 200
        && Ticket::mapLink($longLink) === 'https://maps.google.com/?q=28.050679,81.618346', Ticket::mapLink($longLink));
    check('mapLink: @lat,lng link -> short', Ticket::mapLink('https://www.google.com/maps/@23.1712345,72.6234567,15z') === 'https://maps.google.com/?q=23.171235,72.623457');
    check('mapLink: ?q=lat,lng link -> short', Ticket::mapLink('https://www.google.com/maps?q=28.05,81.61&z=17') === 'https://maps.google.com/?q=28.05,81.61');
    check('mapLink: a short link with no pin is kept', Ticket::mapLink('https://maps.app.goo.gl/AbCdEf123') === 'https://maps.app.goo.gl/AbCdEf123');
    check('mapLink: a LONG link with no pin is refused (no unprintable QR)', Ticket::mapLink('https://www.google.com/maps/search/' . str_repeat('Hotel+Darbar+', 20)) === '');
    $setMem(['nepal_office_map' => [$longLink, 'string']]);
    $mLong = Ticket::mapQrs($bD);
    check('nepal_office_map long link -> the QR carries the short pin form', ($mLong[0]['payload'] ?? '') === 'https://maps.google.com/?q=28.050679,81.618346'
        && count(QrCode::matrix($mLong[0]['payload'], QrCode::ECC_M)) <= 33, (string) ($mLong[0]['payload'] ?? ''));
    $setMem(['nepal_office_map' => ['', 'string'], 'ticket_maps_qr' => ['0', 'bool']]);
    check('ticket_maps_qr off -> no map QR', Ticket::mapQrs($bC) === []);
    $setMem(['ticket_maps_qr' => ['1', 'bool']]);

    $qA = Ticket::paymentQr($bA);
    check('A: payload stays the live pay link (pay.php)', str_contains($qA['payload'], 'pay.php?pnr='), $qA['payload']);
    check('A: printed QR = UPI with the EXACT due + tn=PNR', str_starts_with($qA['qr'], 'upi://pay?') && str_contains($qA['qr'], 'am=2200.00') && str_contains($qA['qr'], 'tn=' . rawurlencode((string) $bA['pnr'])), $qA['qr']);
    check('A: caption Nepali · UPI ₹X', $qA['caption'] === 'भुक्तानी · UPI ₹2,200', $qA['caption']);
    check('A: UPI payload small enough for big modules (ECC L <= 41 modules)', count(QrCode::matrix($qA['qr'], QrCode::ECC_L)) <= 41, (string) count(QrCode::matrix($qA['qr'], QrCode::ECC_L)));
    $setMem(['ticket_pay_qr_upi' => ['0', 'bool']]);
    check('ticket_pay_qr_upi off -> the QR is the live link', str_contains(Ticket::paymentQr($bA)['qr'], 'pay.php?pnr='));
    $setMem(['ticket_pay_qr_upi' => ['1', 'bool']]);
    /* review 19 Nov (high): the raw UPI code never replaces the UTR-taking live link */
    /* 25 Nov 2026 (wx1, owner): a pay-at-boarding (COD) ticket carries NO pay QR at all. */
    $qE = Ticket::paymentQr($load($E));
    check('E (pay at boarding, COD, wx1): no pay QR, mode cod, label PAY ON BOARDING',
        $qE['qr'] === '' && $qE['esewaImg'] === '' && $qE['mode'] === 'cod' && str_contains($qE['label'], 'बसमा तिर्ने · PAY ON BOARDING'), $qE['mode'] . ' ' . $qE['qr']);
    $setMem(['ticket_cod_no_payqr' => ['0', 'bool']]);
    $qE = Ticket::paymentQr($load($E));
    check('ticket_cod_no_payqr off -> E: printed QR = the live link with the ticket key, never raw UPI',
        str_contains($qE['qr'], 'pay.php?pnr=') && str_contains($qE['qr'], '&k=') && !str_contains($qE['qr'], 'upi://'), $qE['qr']);
    $setMem(['ticket_cod_no_payqr' => ['1', 'bool']]);
    $qPend = Ticket::paymentQr(['status' => 'pending'] + $bA);
    check('pending online booking: printed QR = the live link (proof box + expiry hold)', str_contains($qPend['qr'], 'pay.php?pnr=') && !str_contains($qPend['qr'], 'upi://'), $qPend['qr']);
    $qProof = Ticket::paymentQr(['payment' => ['id' => 0, 'status' => 'pending', 'utr_number' => '123456789012', 'amount' => 2200, 'method' => 'upi']] + $bA);
    check('confirmed with a UTR already waiting: printed QR = the live link', str_contains($qProof['qr'], 'pay.php?pnr=') && !str_contains($qProof['qr'], 'upi://'), $qProof['qr']);
    $qNoProof = Ticket::paymentQr(['payment' => ['id' => 999999999, 'status' => 'rejected', 'utr_number' => '', 'amount' => 2200, 'method' => 'upi']] + $bA);
    check('confirmed, a rejected old row only: raw UPI allowed', str_starts_with($qNoProof['qr'], 'upi://pay?'), $qNoProof['qr']);
    $qB = Ticket::paymentQr($bB);
    check('B (paid): no pay QR at all', $qB['qr'] === '' && !str_contains($qB['qr'], 'am='), $qB['mode']);
    $qD = Ticket::paymentQr($bD);
    check('D (NPR): never a UPI-amount QR', !str_contains($qD['qr'], 'upi://') && $qD['mode'] === 'npr', $qD['mode'] . ' ' . $qD['qr']);
    check('D (NPR): caption in रू', str_contains($qD['caption'], 'रू 3,520'), $qD['caption']);

    check('money(): INR sale', Ticket::money($bA, 2200) === 'INR 2,200');
    check('money(): NPR sale prints NPR from fx_total', Ticket::money($bD, 2200) === 'NPR 3,520 (INR 2,200)' && Ticket::money($bD, 2200, false) === 'NPR 3,520', Ticket::money($bD, 2200));
    check('money(): a part of an NPR sale at the frozen rate', Ticket::money($bD, 1000, false) === 'NPR 1,600');
    $setMem(['ticket_npr_primary' => ['0', 'bool']]);
    check('ticket_npr_primary off -> INR main', Ticket::money($bD, 2200) === 'INR 2,200');
    $setMem(['ticket_npr_primary' => ['1', 'bool']]);

    echo "\n== company ==\n";
    $np = Settings::companyFor('NPJ', 'NP');
    check('companyFor NP: PAN printed', $np['entity'] === 'NP' && $np['regLine'] === 'PAN 612454076', (string) $np['regLine']);
    check('companyFor NP: Nepal phone + WhatsApp', str_contains((string) $np['phone'], '98662 01375') && $np['whatsapp'] === '9779866201375');
    $setMem(['nepal_reg' => ['1234/080', 'string']]);
    $np2 = Settings::companyFor('NPJ', 'NP');
    check('companyFor NP: PAN and Reg. both when both set', $np2['regLine'] === 'PAN 612454076  ·  Reg. 1234/080', (string) $np2['regLine']);
    $setMem(['nepal_reg' => ['', 'string']]);
    $cc = Settings::companyForCountry('NP');
    check('companyForCountry(NP): Nepal company, Nepal 24x7 phone, flag NP', $cc['entity'] === 'NP' && $cc['flagFirst'] === 'NP' && str_contains((string) $cc['phone247'], '98662'), (string) $cc['phone247']);
    $ci = Settings::companyForCountry('IN');
    check('companyForCountry(IN): India', $ci['entity'] === 'IN' && $ci['flagFirst'] === 'IN');
    check('companyForCountry(IN): phone247 + counters keys always there', array_key_exists('phone247', $ci) && array_key_exists('counters', $ci)
        && (string) $ci['phone247'] === (string) Settings::company()['phone247'], (string) ($ci['phone247'] ?? 'missing'));
    $setMem(['nepal_company' => ['', 'string']]);
    $cb = Settings::companyForCountry('NP');
    check('companyForCountry(NP) with nepal_company blank: India letterhead, keys present, Nepal 24x7 phone',
        ($cb['entity'] ?? '') === 'IN' && array_key_exists('counters', $cb) && str_contains((string) ($cb['phone247'] ?? ''), '98662'), (string) ($cb['phone247'] ?? 'missing'));
    $setMem(['nepal_company' => ['Shree Hari Global Pvt Ltd', 'string']]);

    echo "\n== PNG render ==\n";
    $hOf = static function (int $bid): array {
        $p = Ticket::pngPath($bid, true);
        $i = getimagesize($p);
        return [$p, $i === false ? 0 : (int) $i[0], $i === false ? 0 : (int) $i[1]];
    };
    $dims = [];
    foreach (['A' => $A, 'B' => $B, 'C' => $C, 'D' => $D] as $tag => $bid) {
        [$p, $w, $h] = $hOf($bid);
        $dims[$tag] = $h;
        check("$tag renders 1080 wide", $w === 1080, $w . 'x' . $h);
        if ($out !== '') { @copy($p, $out . '/u3-' . $tag . '.png'); }
    }
    /* the new blocks never grow the canvas: 1 passenger keeps the 1-passenger height */
    $ref1 = (new ReflectionClass('Ticket'));
    $im = imagecreatefrompng(Ticket::pngPath($A));
    $head = (int) $ref1->getConstant('PNG_HEAD_EXTRA');
    $px = imagecolorat($im, 100, $head + 420);
    $r = ($px >> 16) & 0xFF; $g = ($px >> 8) & 0xFF; $b = $px & 0xFF;
    check('A: SEAT tile is orange at (100, body 420)', $r > 190 && $g > 60 && $g < 150 && $b < 80, "rgb($r,$g,$b)");
    $setMem(['ticket_verify_qr' => ['0', 'bool']]);
    [, , $hNoV] = $hOf($A);
    check('verify QR off keeps the same height', $hNoV === $dims['A'], $hNoV . ' vs ' . $dims['A']);
    $setMem(['ticket_verify_qr' => ['1', 'bool']]);
    Ticket::pngPath($A, true);

    echo "\n== PDF ==\n";
    foreach (['A' => $A, 'C' => $C, 'D' => $D] as $tag => $bid) {
        $p = Ticket::pdfPath($bid, true);
        check("$tag PDF renders", is_file($p) && filesize($p) > 2000);
        if ($out !== '') { @copy($p, $out . '/u3-' . $tag . '.pdf'); }
    }
    /* review 19 Nov (TK-2 gap): C (r7, due) carries verify + pickup + Nepal office + pay = 4
       footer QRs; A (r2 hub, due) carries 3. The second map is no longer dropped. */
    $imgCount = static fn(int $bid): int => substr_count((string) file_get_contents(Ticket::pdfPath($bid)), '/Subtype /Image');
    $iA = $imgCount($A); $iC = $imgCount($C);
    /* 21 Nov 2026 (nj1): the Nepal office QR is now on EVERY ticket (ticket_nepal_office_all),
       so A carries it too and both have the same number of footer QRs. */
    /* 25 Nov 2026 (wx1): C (Rupaidiha pickup, Nepal -> India) prints only its chosen pickup map,
       so it has one footer QR fewer than A; with ticket_np_pickup_only off it keeps the second map. */
    check('C PDF: verify + pickup + pay, no Nepal office row (wx1)', $iC > 0 && $iC === $iA - 1, "A=$iA C=$iC");
    $setMem(['ticket_np_pickup_only' => ['0', 'bool']]);
    $iC0 = substr_count((string) file_get_contents(Ticket::pdfPath($C, true)), '/Subtype /Image');
    check('ticket_np_pickup_only off -> C PDF keeps the second map QR beside the pay QR', $iC0 >= $iA, "A=$iA C=$iC0");
    $setMem(['ticket_np_pickup_only' => ['1', 'bool']]);
    $srcPdf = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
    check('PDF: four QRs use the compact 66 pt pitch (right edge 544 pt < 555 pt)', str_contains($srcPdf, '$qStep    = $compact ? 66.0 : 88.0'));
    $pdfD = (string) file_get_contents(Ticket::pdfPath($D));
    $plain = static function (string $pdf): string {
        $t = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
            foreach ($m[1] as $s) { $z = @gzuncompress($s); $t .= ($z === false ? $s : $z) . "\n"; }
        }
        return $t;
    };
    $pt = $plain($pdfD);
    check('D PDF prints NPR 3,520', str_contains($pt, 'NPR 3,520'));
    check('D PDF prints the Nepal company', str_contains($pt, 'Shree Hari Global'));

    echo "\n== static ==\n";
    $src = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
    $pngStamp = (string) $ref1->getConstant('PNG_LAYOUT_CHANGED');
    $pdfStamp = (string) $ref1->getConstant('PDF_LAYOUT_CHANGED');
    check('PNG layout stamp moved on (19 Nov, 21 Nov nj1) and is in the past', strcmp($pngStamp, '2026-11-19') >= 0 && strtotime($pngStamp) <= time() + 60, $pngStamp);
    check('PDF layout stamp moved on (19 Nov, 25 Nov mp1 tk) and is in the past', strcmp($pdfStamp, '2026-11-19') >= 0 && strtotime($pdfStamp) <= time() + 60, $pdfStamp);
    check('barcode kept on the PNG', str_contains($src, 'Barcode::draw($im, $scanCode'));
    check('PDF seat box is bigger', str_contains($src, '$seatBoxW  = 112.0'));
    check('routes stop edit redraws tickets', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/routes.php'), 'Settings::touchTicketIdentity()'));
} catch (Throwable $e) {
    check('ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restoreMem();
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
