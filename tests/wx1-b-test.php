<?php
/**
 * wx1-b-test.php — ticket: no pay QR on cash, pickup map QR everywhere, Nepal two pickups (25 Nov 2026).
 *
 * Owner: a pay-at-boarding (cash) ticket carries NO payment QR; every ticket / receipt / app
 * PDF carries the map QR of the place the passenger chose to board; from Nepal a customer
 * boards only at the route's own two pickups (Nepalgunj office, Rupaidiha border parking),
 * and the ticket QR is that chosen place only.
 *
 * Seeds its OWN bookings (far date 2099-11-25, blocked schedules, phones 90000325xx, names
 * "ZZTEST wx1 ...") and removes every row and file at the end:
 *   A  r2, Surat, online, money due   -> pay QR (unchanged)
 *   E  r2, Surat, pay at boarding due -> no pay QR (mode cod), PNG + PDF render, one image fewer
 * Settings go into Settings' in-memory cache only (reflection) - the DB settings are never written.
 *
 *   php -c .claude/php-dev.ini tests/wx1-b-test.php
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

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$ref   = new ReflectionClass('Settings');
$cache = $ref->getProperty('cache');
$cache->setAccessible(true);
Settings::get('upi_id');
$orig = $cache->getValue();
$setMem = static function (array $pairs) use ($cache, $ref): void {
    $c = $cache->getValue() ?? [];
    foreach ($pairs as $k => [$v, $t]) {
        $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    }
    $cache->setValue(null, $c);
    $dec = $ref->getProperty('decoded');
    $dec->setAccessible(true);
    $dec->setValue(null, []);
};
$restoreMem = static function () use ($cache, $orig, $ref): void {
    $cache->setValue(null, $orig);
    $dec = $ref->getProperty('decoded'); $dec->setAccessible(true); $dec->setValue(null, []);
};
$setMem([
    'upi_id'                  => ['9104801507.eazypay@icici', 'string'],
    'upi_name'                => ['S HARI GLOBAL PRIVATE LIMITED', 'string'],
    'ticket_pay_qr_upi'       => ['1', 'bool'],
    'ticket_maps_qr'          => ['1', 'bool'],
    'ticket_verify_qr'        => ['1', 'bool'],
    'ticket_cod_no_payqr'     => ['1', 'bool'],
    'ticket_np_pickup_only'   => ['1', 'bool'],
    'ticket_nepal_office_all' => ['1', 'bool'],
    'nepal_pickup_two_only'   => ['1', 'bool'],
    'nepal_office_map'        => ['', 'string'],
    'india_hub_map'           => ['', 'string'],
    'nepal_company'           => ['Shree Hari Global Pvt Ltd', 'string'],
]);

const WX1_DATE = '2099-11-25';
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
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => $routeId, 'd' => WX1_DATE, 's' => $slot]) === null) {
            $id = (int) Database::insert('schedules', ['route_id' => $routeId, 'travel_date' => WX1_DATE, 'slot' => $slot,
                'dep_time_override' => $dep, 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            $made['sched'][] = $id;
            return $id;
        }
    }
    throw new RuntimeException('no free schedule slot');
};
$seed = static function (string $tag, int $routeId, int $sched, string $board, string $drop, string $seat, array $extra = []) use (&$made): int {
    $pnr = 'SHG-2099-' . random_int(40000, 49999);
    $bid = (int) Database::insert('bookings', $extra + ['pnr' => $pnr, 'contact_phone' => '90000325' . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT),
        'contact_country_code' => '91', 'booking_mode' => 'sharing', 'fare_per_seat' => 2200, 'base_total' => 2200, 'total_amount' => 2200,
        'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
    $made['book'][$bid] = $pnr;
    $leg = (int) Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sched, 'travel_date' => WX1_DATE,
        'boarding_stop' => $board, 'drop_stop' => $drop, 'fare_per_seat' => 2200, 'seat_count' => 1, 'leg_total' => 2200]);
    Database::insert('booking_seats', ['schedule_id' => $sched, 'seat_no' => $seat, 'booking_id' => $bid, 'leg_id' => $leg]);
    Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $leg, 'passenger_ref' => 'WX1' . $tag . substr($pnr, -4),
        'seat_no' => $seat, 'full_name' => 'ZZTEST wx1 Ram Bahadur', 'age' => 33, 'gender' => 'Male', 'is_primary' => 1]);
    return $bid;
};
$load = static function (int $bid): array {
    $m = new ReflectionMethod('Ticket', 'loadBooking');
    $m->setAccessible(true);
    return $m->invoke(null, $bid);
};

try {
    echo "\n== 1. no pay QR on a pay-at-boarding ticket ==\n";
    $r2 = (int) (Database::scalar("SELECT id FROM routes WHERE route_code = 'r2'") ?? 0);
    if ($r2 === 0) { echo "  SKIP  route r2 missing\n"; } else {
        $s2 = $schedFor($r2, '13:00:00');
        $A = $seed('A', $r2, $s2, 'Surat', 'Rupaidiha', 'L3');
        $E = $seed('E', $r2, $s2, 'Surat', 'Rupaidiha', 'L4', ['is_cod' => 1]);
        $bA = $load($A); $bE = $load($E);
        $qA = Ticket::paymentQr($bA);
        $qE = Ticket::paymentQr($bE);
        check('A (online, due): still a pay QR', $qA['qr'] !== '' && $qA['mode'] === 'due', $qA['mode']);
        check('E (pay at boarding, due): no pay QR, no eSewa image, mode cod', $qE['qr'] === '' && $qE['esewaImg'] === '' && $qE['mode'] === 'cod', $qE['mode'] . ' ' . $qE['qr']);
        check('E: label बसमा तिर्ने · PAY ON BOARDING + the amount', str_starts_with($qE['label'], 'बसमा तिर्ने · PAY ON BOARDING ') && str_contains($qE['label'], '2,200'), $qE['label']);
        $setMem(['ticket_cod_no_payqr' => ['0', 'bool']]);
        check('ticket_cod_no_payqr off -> E gets the old live pay link back', str_contains(Ticket::paymentQr($bE)['qr'], 'pay.php?pnr='));
        $setMem(['ticket_cod_no_payqr' => ['1', 'bool']]);
        $gone = Ticket::paymentQr(['status' => 'cancelled'] + $bE);
        check('a cancelled pay-at-boarding ticket is not "cod" (nothing to collect)', $gone['mode'] !== 'cod' && $gone['qr'] === '', $gone['mode']);

        $pA = Ticket::pngPath($A, true); $pE = Ticket::pngPath($E, true);
        $iA = @getimagesize($pA); $iE = @getimagesize($pE);
        check('E PNG renders 1080 wide at the same height as A', $iA !== false && $iE !== false && (int) $iE[0] === 1080 && (int) $iE[1] === (int) $iA[1],
            ($iE ? $iE[0] . 'x' . $iE[1] : 'none') . ' vs ' . ($iA ? $iA[0] . 'x' . $iA[1] : 'none'));
        $out = (string) (getenv('WX1_OUT') ?: '');
        if ($out !== '') { @mkdir($out, 0777, true); @copy($pE, $out . '/wx1-E.png'); @copy($pA, $out . '/wx1-A.png'); }
        $imgs = static fn(int $bid): int => substr_count((string) file_get_contents(Ticket::pdfPath($bid, true)), '/Subtype /Image');
        $nA = $imgs($A); $nE = $imgs($E);
        check('E PDF: one image fewer than A (no pay QR)', $nE === $nA - 1, "A=$nA E=$nE");
        if ($out !== '') { @copy(Ticket::pdfPath($E), $out . '/wx1-E.pdf'); }
        $src = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
        check('PDF: the pay-at-boarding words block (no QR) prints PAY ON BOARDING', str_contains($src, "'PAY ON BOARDING', 38.0, 7.0") && str_contains($src, '$codWords'));
    }

    echo "\n== 2. ticket map QR = the chosen pickup (Nepal -> India) ==\n";
    $kinds = static fn(array $b): array => array_column(Ticket::mapQrs($b), 'kind');
    $bNpj = ['boarding_stop' => 'Nepalgunj Office · Puspalal Chowk @ 16:00 [28.0507,81.6159]', 'from_city' => 'Nepalgunj', 'to_city' => 'Surat'];
    $bRup = ['boarding_stop' => 'Rupaidiha Border Parking @ 18:00 [27.9935,81.6801]', 'from_city' => 'Rupaidiha', 'to_city' => 'Surat'];
    $bInd = ['boarding_stop' => 'Surat @ 13:00 [21.17,72.83]', 'from_city' => 'Surat', 'to_city' => 'Rupaidiha'];
    check('Boarding: Nepalgunj -> Surat is toIndia', Boarding::routeDirection(['from_city' => 'Nepalgunj', 'to_city' => 'Surat']) === 'toIndia');
    check('Nepalgunj office pickup: only the pickup QR (it IS the office)', $kinds($bNpj) === ['board'], json_encode($kinds($bNpj)));
    check('Rupaidiha border parking pickup: only the pickup QR', $kinds($bRup) === ['board'], json_encode($kinds($bRup)));
    check('India -> Nepal (ticket_nepal_office_all): pickup + Nepal office, unchanged', $kinds($bInd) === ['board', 'nepal'], json_encode($kinds($bInd)));
    check('Nepal-issued ticket keeps the Nepal office first (unchanged)', ($kinds(['entity' => 'NP', 'counter_code' => 'NPJ'] + $bRup)[0] ?? '') === 'nepal');
    $setMem(['nepal_office_map' => ['28.0507, 81.6159', 'string']]);
    $bPin = ['boarding_stop' => 'Office @ 16:00 [28.0507,81.6159]', 'from_city' => 'Nepalgunj', 'to_city' => 'Surat'];
    check('a pickup whose pin IS the Nepal office pin: no second office QR', $kinds($bPin) === ['board'], json_encode($kinds($bPin)));
    $setMem(['nepal_office_map' => ['', 'string']]);
    $bNoPin = ['boarding_stop' => 'Kohalpur Chowk', 'from_city' => 'Nepalgunj', 'to_city' => 'Surat'];
    check('another Nepal pickup keeps the Nepal office QR', in_array('nepal', $kinds($bNoPin), true), json_encode($kinds($bNoPin)));
    $setMem(['ticket_np_pickup_only' => ['0', 'bool']]);
    check('ticket_np_pickup_only off -> the old rule (pickup + Nepal office)', $kinds($bRup) === ['board', 'nepal'] && $kinds($bNpj) === ['board', 'nepal'],
        json_encode([$kinds($bRup), $kinds($bNpj)]));
    $setMem(['ticket_np_pickup_only' => ['1', 'bool']]);
    check('no pin on the Nepalgunj pickup -> the office row stays (the chosen place)',
        (static function () use ($kinds): bool {
            $k = $kinds(['boarding_stop' => 'Nepalgunj Office · Puspalal Chowk', 'from_city' => 'Nepalgunj', 'to_city' => 'Surat']);
            return $k !== [] && ($k === ['board'] || in_array('nepal', $k, true));
        })());
    /* review fix: a Rupaidiha pickup with no pin (and no office place in that town) must
       keep the Nepal office QR - never a ticket with no map QR at all. */
    $kRupNoPin = $kinds(['boarding_stop' => 'Rupaidiha Border Parking @ 18:00', 'from_city' => 'Rupaidiha', 'to_city' => 'Surat']);
    check('no pin on the Rupaidiha pickup -> never an empty map QR list (office row stays)',
        $kRupNoPin === ['nepal'] || $kRupNoPin === ['board'], json_encode($kRupNoPin));
    check('07-checkout: family share green wins over #ticketCard .btn-wa (!important inline)',
        str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/js/07-checkout.js'), 'background:#25d366 !important;border-color:#1ebe5b !important;color:#fff !important'));

    echo "\n== 3. Nepal pickups: no free-text Other for a customer ==\n";
    $r7 = Database::fetch("SELECT id, from_city, to_city FROM routes WHERE route_code = 'r7' AND is_active = 1");
    if ($r7 === null || !Boarding::isNepalTown((string) $r7['from_city'])) {
        echo "  SKIP  active Nepal-origin route r7 missing\n";
    } else {
        $w = bookingWindow();
        $date = min(addDaysISO($w['from'], 3), $w['to']);
        $req = static fn(int $rid, string $other): array => ['routeId' => $rid, 'travelDate' => $date, 'seats' => ['L1'],
            'passengers' => [['name' => 'ZZTEST wx1 Other', 'age' => 30, 'gender' => 'Male']], 'contact' => ['phone' => '9000032599'],
            'boarding' => BookingService::BOARDING_OTHER, 'boardingOther' => $other];
        $msg = static function (array $r): string {
            try { BookingService::create($r); return 'created?'; } catch (Throwable $e) { return $e->getMessage(); }
        };
        $m1 = $msg($req((int) $r7['id'], 'x'));
        check('customer, Nepal -> India, Other pickup: refused with the list message', str_contains($m1, 'From Nepal, please choose a pickup from the list'), $m1);
        $setMem(['nepal_pickup_two_only' => ['0', 'bool']]);
        $m2 = $msg($req((int) $r7['id'], 'x'));   // 'x' < 3 letters: manualStop refuses it, so nothing is ever written
        check('nepal_pickup_two_only off -> not refused for being Other', !str_contains($m2, 'From Nepal'), $m2);
        $setMem(['nepal_pickup_two_only' => ['1', 'bool']]);
        if ($r2 > 0) {
            $m3 = $msg($req($r2, 'x'));
            check('India -> Nepal Other pickup: not touched by the Nepal rule', !str_contains($m3, 'From Nepal'), $m3);
        }
    }
    $bsrc = (string) file_get_contents(INCLUDE_PATH . '/booking.php');
    check('server: staff sales ($seller / selling staff) keep Other', str_contains($bsrc, "\$boardingIsOther && \$seller === null && !Auth::isSellingStaff()"));

    echo "\n== 4. app + receipts (static) ==\n";
    $root = dirname(__DIR__);
    $idx  = (string) file_get_contents($root . '/index.php');
    foreach (['ticket_cod_no_payqr', 'ticket_maps_qr', 'nepal_pickup_two_only', 'family_share_btn_on'] as $k) {
        check("boot exposes $k", str_contains($idx, "\$boot['settings']['$k']"));
    }
    $co = (string) file_get_contents($root . '/assets/js/07-checkout.js');
    check('07-checkout: tkGoldPay returns null on a cash ticket behind ticket_cod_no_payqr', str_contains($co, "ticket_cod_no_payqr !== false)) return null;"));
    check('07-checkout: the pay figure draws the PAY ON BOARDING stamp when there is no QR', str_contains($co, 'tkg-cod-stamp')
        && (str_contains($co, "(gp2 ? makeQR(gp2.url, 240) : Promise.resolve(''))") || str_contains($co, 'if (gp2 && !gp2.img) makeQR(gp2.url, 240)')));   // mp1 merge: no QR drawn without gp2 (and not over the Nepal QR image)
    check('07-checkout: family share = one green button, navigator.share else wa.me', str_contains($co, 'function famShareBtn') && str_contains($co, 'navigator.share({'));
    $si = (string) file_get_contents($root . '/assets/js/08-signin.js');
    check('08-signin: My Trips card carries the family share button', str_contains($si, 'famShareBtn(b.trackUrl)'));
    $pdf = (string) file_get_contents($root . '/assets/js/11-pdf-ticket.js');
    check('11-pdf-ticket: pickup map card captioned चढ्ने ठाउँ · PICKUP MAP', str_contains($pdf, "'चढ्ने ठाउँ · PICKUP MAP'") && str_contains($pdf, 'makeQR(String(b.pickupMap), 240)'));
    $res = (string) file_get_contents($root . '/assets/js/06-results.js');
    check('06-results: Nepal-origin customer leg skips Other + near-me', str_contains($res, 'function nepalPickupOnly') && str_contains($res, "nepal_pickup_two_only"));
    $tr = (string) file_get_contents($root . '/api/track.php');
    check('api/track.php: pickupMap = the ticket map QR board row', str_contains($tr, 'Ticket::mapQrs(') && str_contains($tr, "=== 'board'"));
    $t80 = (string) file_get_contents($root . '/admin/ticket-80mm.php');
    check('admin/ticket-80mm.php: pickup map QR under the verify QR', str_contains($t80, 'Ticket::mapQrs(') && str_contains($t80, 'alt="Pickup map QR"'));
    if (is_file('C:/Program Files/nodejs/node.exe') || trim((string) @shell_exec('node --version')) !== '') {
        foreach (['06-results.js', '07-checkout.js', '08-signin.js', '11-pdf-ticket.js'] as $js) {
            $o = []; $rc = 0;
            exec('node --check ' . escapeshellarg($root . '/assets/js/' . $js) . ' 2>&1', $o, $rc);
            check("node --check $js", $rc === 0, implode(' ', $o));
        }
    }
} catch (Throwable $e) {
    check('ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restoreMem();
}

/* 25 Nov 2026 (wx1-b2): WhatsApp texts - {{6}} = waAmount (रू on an NPR sale), the pickup
   map line (= the ticket PICKUP QR) behind wa_pickup_map_link_on, AI luggage from settings.
   No booking is seeded: the helper gets a booking array with no id (no DB read). */
echo "\n== 5. WhatsApp pickup map + {{6}} amount + AI luggage ==\n";
try {
    require_once INCLUDE_PATH . '/notify.php';
    $nsrc = (string) file_get_contents(INCLUDE_PATH . '/notify.php');
    check('notify: template {{6}} = self::waAmount($booking)', str_contains($nsrc, "'6' => self::waAmount(\$booking),")
        && !str_contains($nsrc, "'6' => inr((float) (\$booking['total_amount'] ?? 0)),"));
    check('notify: map line in bookingConfirmed (after समय), resend + correction texts',
        substr_count($nsrc, '. self::pickupMapLine($booking)') === 3
        && str_contains($nsrc, "\"समय: \" . \$waTime . \"\\n\" : '')\n                  . self::pickupMapLine(\$booking)"));
    check('notify: line text Nepali first', str_contains($nsrc, '"📍 चढ्ने ठाउँको नक्सा · Pickup map: "'));

    $rm = new ReflectionMethod('Notify', 'pickupMapUrl'); $rm->setAccessible(true);
    $rl = new ReflectionMethod('Notify', 'pickupMapLine'); $rl->setAccessible(true);
    // A Nepal -> India pickup whose label froze the live Rupaidiha border-parking pin.
    $bk = ['boarding_stop' => 'Rupaidiha @ 18:00 [27.9996732,81.5867382]', 'route_id_for_stop' => 0,
           'from_city' => 'Rupaidiha', 'to_city' => 'Ahmedabad', 'counter_code' => '', 'entity' => ''];
    $setMem(['ticket_maps_qr' => ['1', 'bool'], 'wa_pickup_map_link_on' => ['1', 'bool']]);
    $board = '';
    foreach (Ticket::mapQrs($bk) as $q) { if ($q['kind'] === 'board') { $board = $q['payload']; break; } }
    $u = (string) $rm->invoke(null, $bk);
    check('pickupMapUrl = the ticket PICKUP QR payload', $u !== '' && $u === $board, $u);
    check('pickupMapUrl = the pinned place', $u === 'https://maps.google.com/?q=27.999673,81.586738', $u);   // mapLink: 6 decimals
    // wx6: a Nepal -> India booking's line goes on with the journey (office -> our auto -> bus)
    check('pickupMapLine carries the url', str_starts_with((string) $rl->invoke(null, $bk), "📍 चढ्ने ठाउँको नक्सा · Pickup map: " . $u . "\n"));
    $setMem(['wa_pickup_map_link_on' => ['0', 'bool']]);
    check('switch wa_pickup_map_link_on off -> no map line', (string) $rm->invoke(null, $bk) === '' && !str_contains((string) $rl->invoke(null, $bk), 'Pickup map:'));
    $setMem(['wa_pickup_map_link_on' => ['1', 'bool'], 'ticket_maps_qr' => ['0', 'bool']]);
    check('ticket_maps_qr off -> no map line (same as the ticket)', !str_contains((string) $rl->invoke(null, $bk), 'Pickup map:'));
    $setMem(['ticket_maps_qr' => ['1', 'bool']]);
    check('helper never throws on an empty booking', (string) $rm->invoke(null, []) === '');

    // waAmount: an NPR sale (frozen NPR) vs a ₹ sale - {{6}} reads the same string.
    $inrOnly = Notify::waAmount(['total_amount' => 2800]);
    check('waAmount: ₹ sale stays ₹', $inrOnly === inr(2800.0), $inrOnly);
    $setMem(['ticket_npr_primary' => ['1', 'bool']]);
    $nprBk = ['total_amount' => 2800, 'fx_currency' => 'NPR', 'fx_total' => 4480, 'fx_rate' => 1.6];
    $nprTxt = Notify::waAmount($nprBk);
    check('waAmount: NPR sale reads रू NPR first, ₹ in brackets', $nprTxt === 'रू ' . indianNumber(4480.0) . ' (' . inr(2800.0) . ')', $nprTxt);

    // AI prompt: luggage from the settings.
    require_once INCLUDE_PATH . '/aiprompt.php';
    $setMem(['luggage_free_kg' => ['23', 'int'], 'cabin_bag_kg' => ['5', 'int']]);
    $p = ai_system_prompt();
    check('aiprompt: luggage line from luggage_free_kg + cabin_bag_kg', str_contains($p, 'Luggage: 1 suitcase (23 kg) + 1 cabin bag (5 kg) per passenger free'));
    check('aiprompt: no hard-coded 20 kg left', !str_contains((string) file_get_contents(INCLUDE_PATH . '/aiprompt.php'), '1 suitcase (20 kg)'));
} catch (Throwable $e) {
    check('section 5 ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $restoreMem();
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
