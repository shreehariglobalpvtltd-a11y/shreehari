<?php
/**
 * =====================================================================
 *  ticket-premium-test.php — the ticket, its payload and the trip
 *  notices tell the truth (8 Oct 2026 premium upgrade, TKT).
 *
 *    A. WhatsApp state is real: AiSensy "accepted" is pending, only a
 *       delivered/read row says delivered, a failure says failed, and a
 *       seller's copy to another number never answers for the passenger.
 *    B. The app payload carries contactCountry, verifyUrl, live_status and
 *       the trip's delay / status; the PDF stub QR carries no phone and an
 *       old signed payload (with the phone) still verifies.
 *    C. Stop labels print without "@ 21:00" or "[lat,lng]"; the PNG renders
 *       with the floor on the seat chip, the right-hand tiles inside the
 *       card and the pickup-map QR BESIDE the verification QR when the pay
 *       QR is drawn (pixel checks; the images are written next to the
 *       scratch folder for a human look when SHG_TKT_OUT is set).
 *    D. The passenger's pickup time drives liveStatus and the reminders
 *       (Boarding::pickupTs): dep 13:00 + pickup 21:00 at 15:00 → upcoming;
 *       a 23:00 pickup gets the 2-hour reminder at ~21:00, not at 11:00.
 *    E. Trip cancelled + delay notices: 'cancelled' is a known trip event;
 *       delay 30 then 90 = two claims, 90 again = none; nothing is sent
 *       (every channel switched off for the run, restored after).
 *    F. track.php: ETA wording in hi/ne/en names the stop AND the minutes;
 *       the track token opens track.php + api/whereis.php but not the
 *       ticket download; WhereIs keeps a last-known position honest.
 *    G. api/eta.php answers live:false unless exactly one trip of the
 *       route is on the road.                                     [HTTP]
 *    H. Public pages: route pages no longer accept Aadhaar alone and say
 *       30 minutes; refund-policy has a fixed revision date.       [HTTP]
 *
 *    SHG_TEST_BASE=http://localhost:8992 php -c .claude/php-dev.ini tests/ticket-premium-test.php
 *
 *  Writes only throwaway rows (PNR SHG-TEST-TKP…, travel dates in 2099)
 *  and restores every setting / schedule override it touches. CLI only.
 * =====================================================================
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
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';
require_once INCLUDE_PATH . '/whereis.php';
require_once INCLUDE_PATH . '/helpdesk.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$BASE = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
function http(string $path, bool $follow = false): array {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => $follow]);
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body];
}
$serverUp = $BASE !== '' && http('/index.php')['code'] === 200;

const TKP_DATE  = '2099-12-07';
const TKP_DATE2 = '2099-12-08';
const TKP_PHONE = '9198700611';
const TKP_ALT   = '9198700612';
const TKP_PNR   = 'SHG-TEST-TKP1';
const TKP_PNR2  = 'SHG-TEST-TKP2';
const TKP_PNR3  = 'SHG-TEST-TKP3';

/* Every outbound channel off for the run — nothing real may leave. */
$switches = ['whatsapp_notify_customer', 'sms_notify_customer', 'push_enabled', 'email_enabled', 'trip_reminders_enabled', 'reminder_12h_enabled', 'reminder_2h_enabled'];
$saved = [];
foreach ($switches as $k) {
    $saved[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
function setSwitch(string $k, string $v): void {
    if (Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k])) {
        Database::query('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $v, 'k' => $k]);
    } else {
        Database::query("INSERT INTO settings (skey, svalue, stype, sgroup) VALUES (:k, :v, 'bool', 'notify')", ['k' => $k, 'v' => $v]);
    }
    Settings::flush();
}
foreach (['whatsapp_notify_customer', 'sms_notify_customer', 'push_enabled', 'email_enabled'] as $k) { setSwitch($k, '0'); }
// The reminder/claim machinery itself stays ON so the claims can be counted.
foreach (['trip_reminders_enabled', 'reminder_12h_enabled', 'reminder_2h_enabled'] as $k) { setSwitch($k, '1'); }

$livebusSaved = Database::fetch("SELECT kvalue, updated_at FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' LIMIT 1");
$overrides = [];   // schedule id => original dep_time_override (section G)

function tkpCleanup(): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-TEST-TKP%'") as $r) {
        $bid = (int) $r['id'];
        Database::delete('trip_events', 'booking_id = :b', ['b' => $bid]);
        Database::query("DELETE FROM automation_log WHERE dedupe_key LIKE :k", ['k' => 'trip:%:' . $bid . ':%']);
        Database::delete('msg_ledger', 'booking_id = :b', ['b' => $bid]);
        Database::delete('message_logs', 'booking_id = :b', ['b' => $bid]);
        Database::delete('tickets', 'booking_id = :b', ['b' => $bid]);
        Database::delete('bookings', 'id = :b', ['b' => $bid]);
    }
    foreach ([TKP_DATE, TKP_DATE2] as $d) {
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
    Database::query("DELETE FROM msg_ledger WHERE to_number IN ('91" . TKP_PHONE . "','91" . TKP_ALT . "','919000000001')");
}

/** A confirmed booking on $sid with one passenger on $seat. */
function tkpBooking(string $pnr, int $sid, string $date, string $board, string $seat = 'U14', array $extra = []): int {
    $bid = Database::insert('bookings', $extra + [
        'pnr' => $pnr, 'contact_phone' => TKP_PHONE, 'contact_country_code' => '977', 'alt_phone' => TKP_ALT,
        'contact_email' => '', 'status' => 'confirmed', 'total_amount' => 2070.00, 'confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    $legId = Database::insert('booking_legs', [
        'booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $date,
        'boarding_stop' => $board, 'drop_stop' => 'Rupaidiha', 'seat_count' => 1, 'fare_per_seat' => 2070.00, 'leg_total' => 2070.00,
    ]);
    Database::insert('booking_passengers', [
        'booking_id' => $bid, 'leg_id' => $legId, 'seat_no' => $seat, 'full_name' => 'Premium Test', 'passenger_ref' => generatePassengerRef(),
    ]);
    return $bid;
}

function ledger(int $bid, string $to, string $purpose, string $status, string $provider = 'aisensy'): void {
    Database::insert('msg_ledger', [
        'provider' => $provider, 'channel' => 'whatsapp', 'to_number' => $to, 'booking_id' => $bid, 'purpose' => $purpose,
        'label' => 'test', 'category' => 'utility', 'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
    ]);
}

echo "\n=== Ticket premium: honest ticket, payload and trip notices ===\n\n";
tkpCleanup();
$route = Database::fetch("SELECT * FROM routes WHERE route_code = 'r2' LIMIT 1")
      ?? Database::fetch("SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
check('fixture: a route to sell on', $route !== null);
$rid = (int) $route['id'];
$sid = (int) Seats::schedule($rid, TKP_DATE)['id'];
Database::update('schedules', ['status' => 'scheduled', 'delay_minutes' => 0, 'delay_note' => null], 'id = :i', ['i' => $sid]);
$bid = tkpBooking(TKP_PNR, $sid, TKP_DATE, 'Hari Pvt. Ltd. Parking - Nana Chiloda @ 21:00 [23.171,72.623]');

try {
    /* ---------------------------------------------------------------- */
    echo "-- A. WhatsApp state is real --\n";
    check('no message yet → null', shg_wa_last($bid, TKP_PHONE) === null);
    ledger($bid, '91' . TKP_PHONE, 'aisensy_ticket', 'accepted');
    $w = shg_wa_last($bid, TKP_PHONE);
    check('AiSensy accepted → pending, not ok', ($w['state'] ?? '') === 'pending' && ($w['ok'] ?? true) === false, json_encode($w));
    check('  the number is masked to its last four', ($w['last4'] ?? '') === substr(TKP_PHONE, -4));
    ledger($bid, '91' . TKP_PHONE, 'aisensy_ticket', 'sent');
    check('AiSensy "sent" (single tick) is still pending', (shg_wa_last($bid, TKP_PHONE)['state'] ?? '') === 'pending');
    ledger($bid, '91' . TKP_PHONE, 'ticket', 'delivered');
    $w = shg_wa_last($bid, TKP_PHONE);
    check('delivered → delivered and ok', ($w['state'] ?? '') === 'delivered' && ($w['ok'] ?? false) === true, json_encode($w));
    ledger($bid, '91' . TKP_ALT, 'ticket_resend', 'read');
    check('a read copy to the ALT phone counts as delivered', (shg_wa_last($bid, TKP_PHONE)['state'] ?? '') === 'delivered');
    ledger($bid, '91' . TKP_PHONE, 'aisensy_ticket', 'failed');
    check('a newer failure → failed', (shg_wa_last($bid, TKP_PHONE)['state'] ?? '') === 'failed');
    ledger($bid, '919000000001', 'seller_copy', 'delivered');
    ledger($bid, '919000000001', 'aisensy_ticket', 'delivered');   // even mislabelled, another number never answers
    $w = shg_wa_last($bid, TKP_PHONE);
    check('a newer seller copy to another number is ignored', ($w['state'] ?? '') === 'failed' && ($w['last4'] ?? '') === substr(TKP_PHONE, -4), json_encode($w));
    Database::delete('msg_ledger', 'booking_id = :b', ['b' => $bid]);
    Database::insert('message_logs', ['booking_id' => $bid, 'channel' => 'whatsapp', 'purpose' => 'aisensy_ticket', 'provider' => 'aisensy',
        'to_number' => '91' . TKP_PHONE, 'status' => 'sent', 'provider_ref' => '']);
    $w = shg_wa_last($bid, TKP_PHONE);
    check('falls back to message_logs: AiSensy sent → pending', ($w['state'] ?? '') === 'pending', json_encode($w));
    Database::insert('message_logs', ['booking_id' => $bid, 'channel' => 'whatsapp', 'purpose' => 'seller_copy', 'provider' => 'aisensy',
        'to_number' => '919000000001', 'status' => 'sent', 'provider_ref' => '']);
    check('  and the seller copy there is ignored too', (shg_wa_last($bid, TKP_PHONE)['last4'] ?? '') === substr(TKP_PHONE, -4));
    check('api/track.php passes the contact phone', str_contains((string) file_get_contents(dirname(__DIR__) . '/api/track.php'), "shg_wa_last((int) (\$detail['id'] ?? 0), (string) (\$detail['contact_phone'] ?? ''))"));

    /* ---------------------------------------------------------------- */
    echo "\n-- B. payload + QR --\n";
    Database::update('schedules', ['delay_minutes' => 45, 'delay_note' => 'Traffic near Baroda'], 'id = :i', ['i' => $sid]);
    $detail = BookingService::detail(TKP_PNR);
    $p = shg_customer_payload($detail);
    check('contactCountry rides along', ($p['contactCountry'] ?? null) === '977', (string) ($p['contactCountry'] ?? 'missing'));
    check('verifyUrl = keyed verify page', str_contains((string) ($p['verifyUrl'] ?? ''), 'verify-ticket.php?pnr=' . rawurlencode(TKP_PNR) . '&k=' . Ticket::downloadToken(TKP_PNR)), (string) ($p['verifyUrl'] ?? 'missing'));
    check('live_status is in the payload', array_key_exists('live_status', $p) && is_array($p['live_status']));
    check('delay + trip status of the first leg', ($p['delayMin'] ?? null) === 45 && ($p['delayNote'] ?? '') === 'Traffic near Baroda' && ($p['tripStatus'] ?? '') === 'scheduled',
        json_encode(['d' => $p['delayMin'] ?? null, 'n' => $p['delayNote'] ?? null, 's' => $p['tripStatus'] ?? null]));
    check('  and on the leg itself', (int) ($p['legs'][0]['delayMin'] ?? -1) === 45 && ($p['legs'][0]['tripStatus'] ?? '') === 'scheduled');
    foreach (['pnr', 'status', 'total', 'paidAmount', 'isCod', 'seats', 'passengers', 'legs', 'payment', 'canCancel', 'ticketUrl', 'trackUrl', 'createdAt'] as $k) {
        if (!array_key_exists($k, $p)) { check("existing key '$k' kept", false); }
    }
    check('every existing key is kept', true);
    $pend = $p; Database::update('bookings', ['status' => 'pending'], 'id = :b', ['b' => $bid]);
    $pp = shg_customer_payload(BookingService::detail(TKP_PNR));
    check('no verifyUrl until confirmed', array_key_exists('verifyUrl', $pp) && $pp['verifyUrl'] === null);
    Database::update('bookings', ['status' => 'confirmed'], 'id = :b', ['b' => $bid]);
    Database::update('schedules', ['delay_minutes' => 0, 'delay_note' => null], 'id = :i', ['i' => $sid]);

    $qb = ['pnr' => TKP_PNR, 'travel_date' => TKP_DATE, 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'contact_phone' => TKP_PHONE,
           'ticket_number' => 'TKT-1', 'dep_time' => '13:00:00', 'bus_number' => 'GJ 01 KT 4455'];
    $qr = Ticket::qrPayload($qb, ['U14']);
    $parts = explode('|', $qr);
    check('the PDF stub QR carries no phone (field 5 blank)', !str_contains($qr, TKP_PHONE) && ($parts[5] ?? 'x') === '', $qr);
    check('  and still verifies', Ticket::verifyQrPayload($qr) === TKP_PNR);
    $oldCore = implode('|', ['SHG-TICKET', TKP_PNR, TKP_DATE, 'U14', 'Surat->Rupaidiha', TKP_PHONE, 'TKT-1', '1', '13:00', 'GJ 01 KT 4455']);
    $old = $oldCore . '|' . substr(Security::sign($oldCore), 0, 16);
    check('an OLD payload that carried the phone still verifies', Ticket::verifyQrPayload($old) === TKP_PNR);
    $v1Core = implode('|', ['SHG-TICKET', TKP_PNR, TKP_DATE, 'U14', 'Surat->Rupaidiha', TKP_PHONE]);
    check('  and so does a v1 payload', Ticket::verifyQrPayload($v1Core . '|' . substr(Security::sign($v1Core), 0, 16)) === TKP_PNR);

    /* ---------------------------------------------------------------- */
    echo "\n-- C. print labels + the PNG --\n";
    check("stopForPrint drops '@ 21:00' and '[..]'", Ticket::stopForPrint('Hari Pvt. Ltd. Parking - Nana Chiloda · Nana Chiloda @ 21:00 [23.171,72.623]') === 'Hari Pvt. Ltd. Parking - Nana Chiloda · Nana Chiloda',
        Ticket::stopForPrint('Hari Pvt. Ltd. Parking - Nana Chiloda · Nana Chiloda @ 21:00 [23.171,72.623]'));
    check("  a label with only '@ 9:05' too", Ticket::stopForPrint('Barauda @ 9:05') === 'Barauda');
    check('  a plain label is untouched', Ticket::stopForPrint('Rupaidiha · India-Nepal border') === 'Rupaidiha · India-Nepal border');
    check('floor from the canonical id: L → 1F, U → 2F, other → none', Ticket::floorCode('L3') === '1F' && Ticket::floorCode('U14') === '2F' && Ticket::floorCode('4C') === '');

    $rc  = new ReflectionClass('Ticket');
    $png = $rc->getMethod('renderTicketPng'); $png->setAccessible(true);
    $out = getenv('SHG_TKT_OUT') ?: sys_get_temp_dir();
    $base = [
        'id' => 0, 'pnr' => 'SHG-2026-04462', 'status' => 'confirmed', 'currency' => 'INR', 'total_amount' => 6210, 'base_total' => 6210,
        'fare_per_seat' => 2070, 'coupon_discount' => 0, 'is_cod' => 1, 'source' => 'web', 'counter_code' => '', 'entity' => '', 'sold_by_admin_id' => 0,
        'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'route_id_for_stop' => $rid,
        'boarding_stop' => 'Mehsana Head Office - Silver Complex @ 23:00', 'drop_stop' => 'Rupaidiha', 'boarding_time' => null,
        'dep_time' => '13:00:00', 'travel_date' => '2026-10-20', 'bus_number' => 'GJ 01 KT 4455', 'bus_name' => 'SHG Bus',
        'coach_type' => 'sleeper', 'booking_mode' => 'sharing', 'contact_phone' => '9876543210', 'created_at' => '2026-10-08 10:00:00',
        'payment' => null, 'verified_paid' => 0,
        'passengers' => [
            ['full_name' => 'सीता शर्मा', 'seat_no' => 'L3', 'age' => 61, 'gender' => 'female'], ['full_name' => 'Gita Sharma', 'seat_no' => 'L4', 'age' => 30, 'gender' => 'female'],
            ['full_name' => 'Krishna Bahadur Shrestha Chhetri', 'seat_no' => 'U9', 'age' => 40, 'gender' => 'male'],
        ],
    ];
    $tk = ['id' => 0, 'ticket_number' => 'TKT-20261008-0461', 'reissue_count' => 0, 'reissued_at' => null, 'qr_payload' => ''];
    $mehsanaGeo = Ticket::pickupGeo($base);
    $upiOn = Settings::getString('upi_id', '') !== '';
    $f1 = $out . '/tkp_family_cod.png';
    $png->invoke(null, $base, $tk, $f1);
    $im = @imagecreatefrompng($f1);
    check('family COD ticket renders 1080 wide', $im !== false && imagesx($im) === 1080, $im ? imagesx($im) . 'x' . imagesy($im) : 'no image');
    if ($im !== false) {
        $rgb = static function ($im, int $x, int $y): array { $c = imagecolorat($im, $x, $y); return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255]; };
        /* Tile face colour: [246,248,252] until the GOLD template (9 Oct
           2026) made the tiles warm white on the ivory card (Ticket::PNG_TILE). */
        $isTile = static fn(array $c): bool => $c === [255, 253, 246];
        check('right-hand tiles end inside the card (x=1030 is not tile colour)', !$isTile($rgb($im, 1030, 800)) && !$isTile($rgb($im, 1030, 950)) && $isTile($rgb($im, 1010, 800)),
            json_encode([$rgb($im, 1030, 800), $rgb($im, 1010, 800)]));
        $grow = max(0, (60 + 3 * 44 + 16) - 108);
        $dark = static function ($im, int $x1, int $y1, int $x2, int $y2) use ($rgb): int {
            $n = 0; for ($x = $x1; $x < $x2; $x += 3) { for ($y = $y1; $y < $y2; $y += 3) { $c = $rgb($im, $x, $y); if ($c[0] < 60 && $c[1] < 60 && $c[2] < 60) { $n++; } } } return $n;
        };
        if ($upiOn && $mehsanaGeo !== null && Settings::getBool('ticket_maps_qr', true)) {
            check('pay QR drawn → the pickup-map QR sits BESIDE the verification QR', $dark($im, 345, 1335 + $grow, 525, 1515 + $grow) > 200, 'dark samples ' . $dark($im, 345, 1335 + $grow, 525, 1515 + $grow));
            check('  and the gap between the two QRs stays clear', $dark($im, 324, 1320 + $grow, 338, 1530 + $grow) === 0);
        } else {
            echo "  SKIP  map-QR-beside-pay check (upi_id empty or Mehsana stop has no coordinates)\n";
        }
        imagedestroy($im);
    }
    $single = $base; $single['pnr'] = 'SHG-2026-04461'; $single['is_cod'] = 0; $single['total_amount'] = 2070;
    $single['payment'] = ['status' => 'verified', 'method' => 'upi', 'amount' => 2070, 'utr_number' => 'UTR 412345678901']; $single['verified_paid'] = 2070;
    $single['boarding_stop'] = 'Hari Pvt. Ltd. Parking - Nana Chiloda · Nana Chiloda @ 21:00 [23.171,72.623]';
    $single['passengers'] = [['full_name' => 'Ramesh Kumar Thapa', 'seat_no' => 'U14', 'age' => 34, 'gender' => 'male']];
    $f2 = $out . '/tkp_single_paid.png';
    $png->invoke(null, $single, $tk, $f2);
    check('single paid ticket renders', is_file($f2) && (getimagesize($f2)[0] ?? 0) === 1080);
    $src = (string) file_get_contents(dirname(__DIR__) . '/includes/ticket.php');
    check('the seat chip prints the floor (Nepali word + Roman code)', str_contains($src, "'माथि 2F'") && str_contains($src, "'तल 1F'"));
    check('the PNR stays at least 36px', preg_match('/\$pnrW = self::gdWidth\((\d+), \$pnr\)/', $src, $m) === 1 && (int) $m[1] >= 36);
    check('the PDF prints INR amounts, not a dropped ₹', !preg_match('/renderTicketPdf.*?inr\(/s', substr($src, (int) strpos($src, 'private static function renderTicketPdf'), (int) strpos($src, 'private static function renderInvoicePdf') - (int) strpos($src, 'private static function renderTicketPdf'))));
    $pdfM = $rc->getMethod('renderTicketPdf'); $pdfM->setAccessible(true);
    $tkq = $tk; $tkq['qr_payload'] = Ticket::qrPayload($single, ['U14']);
    $pdfObj = $pdfM->invoke(null, $single, $tkq);
    $fp = $out . '/tkp_single_paid.pdf'; $pdfObj->save($fp);
    $raw = (string) file_get_contents($fp);
    check('PDF renders', str_starts_with($raw, '%PDF'));
    // The content streams are deflated: read the text the page really draws.
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $sm);
    foreach ($sm[1] as $chunk) { $u = @gzuncompress($chunk); if ($u !== false) { $raw .= "\n" . $u; } }
    check('PDF band says INR', str_contains($raw, 'INR 2,070'));
    check('PDF carries no relative "UPCOMING IN" pill, a static departure instead', !str_contains($raw, 'UPCOMING IN') && str_contains($raw, 'DEPARTS TUE 20 OCT'));
    check('PDF seat box carries the floor', str_contains($raw, '2F'));

    /* ---------------------------------------------------------------- */
    echo "\n-- D. pickup time drives the clock --\n";
    $today = date('Y-m-d');
    $t = Boarding::pickupTs($today, 'Nana Chiloda @ 21:00', null, '13:00:00');
    check('pickupTs reads the label time', $t === strtotime($today . ' 21:00'));
    check('  boarding_time wins over the label', Boarding::pickupTs($today, 'Nana Chiloda @ 21:00', '22:15:00', '13:00:00') === strtotime($today . ' 22:15'));
    check('  no label time → the route departure', Boarding::pickupTs($today, 'Surat', null, '13:00:00') === strtotime($today . ' 13:00'));
    check('  a pickup past midnight is the next day', Boarding::pickupTs($today, 'Vadodara @ 00:00', null, '21:00:00') === strtotime($today . ' 00:00 +1 day'));
    check('  a bad date gives 0', Boarding::pickupTs('', 'x', null, '13:00') === 0);
    $lb = ['status' => 'confirmed', 'travel_date' => $today, 'dep_time' => '13:00:00', 'boarding_stop' => 'Hari Pvt. Ltd. Parking - Nana Chiloda @ 21:00'];
    $ls = Ticket::liveStatus($lb, strtotime($today . ' 15:00'));
    check('dep 13:00 + pickup 21:00 at 15:00 → upcoming', ($ls['key'] ?? '') === 'upcoming', json_encode($ls, JSON_UNESCAPED_UNICODE));
    $ls = Ticket::liveStatus($lb, strtotime($today . ' 21:10'));
    check('  at 21:10 → departed, in Nepali too', ($ls['key'] ?? '') === 'departed' && str_contains((string) $ls['label'], 'छुटिसक्यो') && !str_contains((string) $ls['label'], 'Timoff'), (string) ($ls['label'] ?? ''));
    $ls = Ticket::liveStatus(['status' => 'confirmed', 'legs' => [['travel_date' => $today, 'dep_time' => '13:00:00', 'boarding_stop' => 'Mehsana @ 23:00', 'schedule_status' => 'scheduled']]], strtotime($today . ' 13:05'));
    check('  the detail() shape reads the leg pickup too (Mehsana 23:00 at 13:05)', ($ls['key'] ?? '') === 'upcoming');
    $ls = Ticket::liveStatus(['status' => 'confirmed', 'legs' => [['travel_date' => $today, 'dep_time' => '13:00:00', 'boarding_stop' => 'Surat', 'schedule_status' => 'cancelled']]], strtotime($today . ' 10:00'));
    check('a trip the office cancelled is never "Upcoming"', ($ls['key'] ?? '') === 'trip_cancelled', json_encode($ls, JSON_UNESCAPED_UNICODE));

    check('reminder bands: 700 min → 12h, 300 → none, 90 → 2h, 0 → none', TripNotify::reminderEvent(700) === 'reminder_12h' && TripNotify::reminderEvent(300) === null
        && TripNotify::reminderEvent(90) === 'reminder_2h' && TripNotify::reminderEvent(-5) === null);
    /* A real run: the route's departure has ALREADY gone (2 h ago) but this
       passenger's pickup is 90 min away — the old SQL never even saw it. */
    $dep = time() - 2 * 3600; $pick = time() + 90 * 60;
    $schedDate = date('Y-m-d', $dep);
    // Its own slot-7 schedule, so the real daily trip on that date is untouched.
    Database::query('DELETE FROM schedules WHERE route_id = :r AND slot = 7 AND travel_date BETWEEN :a AND :b', ['r' => $rid, 'a' => date('Y-m-d', time() - 2 * 86400), 'b' => date('Y-m-d', time() + 2 * 86400)]);
    $sidR = (int) Database::insert('schedules', ['route_id' => $rid, 'travel_date' => $schedDate, 'slot' => 7, 'status' => 'scheduled', 'dep_time_override' => date('H:i:s', $dep)]);
    $GLOBALS['tkpSched'] = $sidR;
    $bidR = tkpBooking(TKP_PNR2, $sidR, $schedDate, 'Mehsana Head Office - Silver Complex @ ' . date('H:i', $pick));
    TripNotify::runReminders();
    $ev = pluck(Database::fetchAll('SELECT `event` FROM trip_events WHERE booking_id = :b', ['b' => $bidR]), 'event');
    check('a pickup 90 min away gets the 2-hour reminder although the origin left 2 h ago', $ev === ['reminder_2h'], json_encode($ev));
    TripNotify::runReminders();
    check('  exactly once', count(Database::fetchAll('SELECT id FROM trip_events WHERE booking_id = :b', ['b' => $bidR])) === 1);
    /* … and a 23:00 pickup on a 13:00 route is NOT reminded "2 hours" at 11:00. */
    Database::delete('trip_events', 'booking_id = :b', ['b' => $bidR]);
    $dep2 = time() + 30 * 60; $pick2 = time() + 10 * 3600;
    Database::update('schedules', ['travel_date' => date('Y-m-d', $dep2), 'dep_time_override' => date('H:i:s', $dep2)], 'id = :i', ['i' => $sidR]);
    Database::update('booking_legs', ['travel_date' => date('Y-m-d', $dep2), 'boarding_stop' => 'Mehsana Head Office - Silver Complex @ ' . date('H:i', $pick2)], 'booking_id = :b', ['b' => $bidR]);
    TripNotify::runReminders();
    $ev = pluck(Database::fetchAll('SELECT `event` FROM trip_events WHERE booking_id = :b', ['b' => $bidR]), 'event');
    check('origin leaves in 30 min, pickup in 10 h → no reminder yet (dead band)', $ev === [], json_encode($ev));
    Database::delete('trip_events', 'booking_id = :b', ['b' => $bidR]);
    Database::delete('bookings', 'id = :b', ['b' => $bidR]);
    Database::delete('booking_legs', 'schedule_id = :s', ['s' => $sidR]);
    Database::delete('schedules', 'id = :s', ['s' => $sidR]);
    $GLOBALS['tkpSched'] = 0;

    $body = new ReflectionMethod('Notify', 'tripEventBody'); $body->setAccessible(true);
    $f = ['route' => 'Surat → Rupaidiha', 'date' => '20 Oct 2026', 'depTime' => '11:00 PM', 'seats' => 'C8', 'boarding' => 'Mehsana · 23:00', 'pickupToday' => '0'];
    $b12 = $body->invoke(null, 'reminder_12h', 'S Hari Global', 'SHG-X', $f);
    check('12h reminder names the pickup date + time', str_contains($b12['whatsapp'], '20 Oct 2026') && str_contains($b12['whatsapp'], '11:00 PM'));
    $f['pickupToday'] = '1';
    $b12t = $body->invoke(null, 'reminder_12h', 'S Hari Global', 'SHG-X', $f);
    check('  says "today", not "tomorrow", when the pickup is today', !str_contains($b12t['whatsapp'], 'भोलि') && str_contains($b12t['whatsapp'], 'आज'), mb_substr($b12t['whatsapp'], 0, 80));

    /* ---------------------------------------------------------------- */
    echo "\n-- E. cancelled + delay notices (nothing sent) --\n";
    $r = Notify::tripEvent(['id' => $bid, 'pnr' => TKP_PNR, 'contact_phone' => '9800000000'], 'cancelled', ['route' => 'Surat → Rupaidiha', 'date' => '7 Dec 2099']);
    check("'cancelled' is a known trip event", ($r['detail'] ?? '') !== 'Unknown trip event.', (string) ($r['detail'] ?? ''));
    check('  and nothing was delivered (all channels off)', ($r['ok'] ?? true) === false && ($r['channels'] ?? 'x') === '');
    $cb = $body->invoke(null, 'cancelled', 'S Hari Global', 'SHG-X', ['route' => 'Surat → Rupaidiha', 'date' => '7 Dec 2099', 'depTime' => '', 'seats' => '', 'boarding' => '']);
    check('cancel text is bilingual and promises no refund', str_contains($cb['whatsapp'], 'रद्द') && !preg_match('/refund|फिर्ता|रिफन्ड/iu', $cb['whatsapp'] . $cb['sms']), mb_substr($cb['whatsapp'], 0, 120));
    if (!class_exists('WebPush')) { require_once INCLUDE_PATH . '/webpush.php'; }
    $pp = WebPush::tripEventPayload('cancelled', 'SHG-X', ['route' => 'Surat → Rupaidiha']);
    check('push payload for a cancelled trip', str_contains($pp['title'], 'रद्द') && !preg_match('/refund/i', $pp['body']), $pp['title']);
    $claims = static fn(): int => (int) Database::scalar("SELECT COUNT(*) FROM automation_log WHERE dedupe_key LIKE :k", ['k' => 'trip:delayed:' . $bid . ':' . $sid . ':%'], 0);
    TripNotify::notifyDelay($sid, 30, 'traffic');
    TripNotify::notifyDelay($sid, 90, 'traffic');
    check('delay 30 then 90 → two claims', $claims() === 2, (string) $claims());
    $again = TripNotify::notifyDelay($sid, 90, 'traffic');
    check('  90 again → no new claim', $claims() === 2 && str_contains((string) ($again['message'] ?? ''), 'already'), (string) ($again['message'] ?? ''));
    check('  the delay never wrote trip_events (no ENUM slot)', (int) Database::scalar("SELECT COUNT(*) FROM trip_events WHERE booking_id = :b AND `event` = ''", ['b' => $bid], 0) === 0);
    $c1 = TripNotify::notifyCancellation($sid, 'bus breakdown');
    $c2 = TripNotify::notifyCancellation($sid, 'bus breakdown');
    check('cancellation claims once per booking, after a delay went out', (int) Database::scalar("SELECT COUNT(*) FROM automation_log WHERE dedupe_key = :k", ['k' => 'trip:cancelled:' . $bid . ':' . $sid], 0) === 1
        && (int) ($c2['skipped'] ?? 0) >= 1, json_encode([$c1['message'] ?? '', $c2['message'] ?? '']));
    check('admin/trips.php reports what notifyDelay did', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/trips.php'), '$res = TripNotify::notifyDelay('));
    $ctxBoard = (string) file_get_contents(dirname(__DIR__) . '/includes/tripnotify.php');
    check('trip messages print the stop name · time, never [lat,lng]', str_contains($ctxBoard, "Boarding::stopDisplay((string) (\$row['boarding_stop'] ?? ''))"));

    /* ---------------------------------------------------------------- */
    echo "\n-- F. track.php + WhereIs --\n";
    $trackSrc = (string) file_get_contents(dirname(__DIR__) . '/track.php');
    preg_match_all("/'eta' => '([^']+)'/u", $trackSrc, $mm);
    check('three ETA templates found', count($mm[1]) === 3, (string) count($mm[1]));
    foreach ($mm[1] as $tpl) {
        $line = sprintf($tpl, 25, 'Mehsana');
        check('  "' . $tpl . '" → names the stop and 25 min', str_contains($line, 'Mehsana') && str_contains($line, '25') && !str_contains($line, ' 0 '), $line);
    }
    check('JS paint fills the same positional tokens', str_contains($trackSrc, 'fill(T.eta, [d.etaMin, d.stop])') && str_contains($trackSrc, 'sprintf($T[\'eta\'], (int) $status[\'etaMin\'], (string) $status[\'stop\'])'));
    check('the pulse respects reduced motion', str_contains($trackSrc, 'prefers-reduced-motion'));
    check('saved theme is painted before first frame (light/dark/amoled)', str_contains($trackSrc, "localStorage.getItem('shg:theme')") && str_contains($trackSrc, 'data-display="amoled"'));

    $row = BookingService::findByPnr(TKP_PNR);
    $tt = Ticket::trackToken(TKP_PNR);
    check('track token differs from the download key', $tt !== '' && $tt !== Ticket::downloadToken(TKP_PNR));
    check('trackUrl carries the track token', str_contains(Ticket::trackUrl(TKP_PNR), '&k=' . $tt));
    check('WhereIs opens with the track token AND the old download key', WhereIs::authorised($row, $tt) && WhereIs::authorised($row, Ticket::downloadToken(TKP_PNR)));
    check('  but not with a wrong one', !WhereIs::authorised($row, 'nope'));
    check('the track token is not a download key', !Ticket::checkDownloadToken(TKP_PNR, $tt));

    /* Last-known: a stale fix on a trip that has left, on this route. */
    $now = strtotime(TKP_DATE . ' 16:30');
    Database::update('schedules', ['status' => 'departed'], 'id = :i', ['i' => $sid]);
    $stop = Database::fetch("SELECT latitude, longitude, stop_name FROM route_stops WHERE route_id = :r AND stop_type = 'boarding' AND latitude IS NOT NULL ORDER BY stop_time LIMIT 1 OFFSET 1", ['r' => $rid]);
    $kv = static function (float $lat, float $lng, int $at): void {
        Database::query("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
        Database::insert('kv_store', ['kscope' => 'global', 'kkey' => 'livebus', 'kvalue' => json_encode(['lat' => $lat, 'lng' => $lng, 'acc' => 20, 'spd' => 15, 'stop' => '', 'at' => $at * 1000]),
            'updated_by' => 'tkp-test', 'updated_at' => date('Y-m-d H:i:s', $at)]);
    };
    if ($stop !== null) {
        $kv((float) $stop['latitude'] + 0.01, (float) $stop['longitude'], $now - 600);
        $st = WhereIs::status($row, $now);
        check('stale fix on a departed trip → not live, last known kept', $st['live'] === false && !empty($st['lastKnown']) && ($st['lastStop'] ?? '') !== '' && $st['etaMin'] === null,
            json_encode(['live' => $st['live'], 'lk' => $st['lastKnown'] ?? null, 'stop' => $st['lastStop'] ?? null, 'age' => $st['lastAgeMin'] ?? null, 'state' => $st['state'], 'why' => $st['reason']]));
        $kv((float) $stop['latitude'] + 0.01, (float) $stop['longitude'], $now - 8 * 3600);
        $st = WhereIs::status($row, $now);
        check('  a fix older than 6 h is not shown at all', empty($st['lastKnown']));
        $kv(28.06, 84.0, $now - 600);   // far off this route
        $st = WhereIs::status($row, $now);
        check('  an off-route position is never a last-known', empty($st['lastKnown']));
        Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);
        $kv((float) $stop['latitude'] + 0.01, (float) $stop['longitude'], strtotime(TKP_DATE . ' 08:50'));
        $st = WhereIs::status($row, strtotime(TKP_DATE . ' 09:00'));
        check('  a trip that has not left yet shows no last-known', empty($st['lastKnown']), (string) $st['state']);
    } else {
        echo "  SKIP  last-known (route has no stop with coordinates)\n";
    }
    Database::query("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'tkp-test'");
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);

    if (!$serverUp) {
        echo "\n  SKIP  HTTP parts — set SHG_TEST_BASE to a running server\n";
    } else {
        $q = '?pnr=' . rawurlencode(TKP_PNR) . '&k=';
        $r1 = http('/track.php' . $q . $tt);
        check('track token opens track.php', $r1['code'] === 200 && str_contains($r1['body'], TKP_PNR), 'HTTP ' . $r1['code']);
        check('  its share link stays track-only (no download key on the page)', !str_contains($r1['body'], Ticket::downloadToken(TKP_PNR)));
        check('  the page pre-paints the saved theme', str_contains($r1['body'], "localStorage.getItem('shg:theme')"));
        $r2 = http('/api/whereis.php' . $q . $tt);
        $j = json_decode($r2['body'], true) ?: [];
        check('track token opens api/whereis.php', $r2['code'] === 200 && !empty($j['ok']), 'HTTP ' . $r2['code']);
        $r3 = http('/download-ticket.php' . $q . $tt . '&img=1');
        check('track token does NOT open the ticket download', $r3['code'] !== 200, 'HTTP ' . $r3['code']);
        $r4 = http('/track.php' . $q . Ticket::downloadToken(TKP_PNR));
        check('links already sent (download key) still open track.php', $r4['code'] === 200);

        /* ---------------------------------------------------------- */
        echo "\n-- G. api/eta.php only answers for one running trip --\n";
        $code = (string) $route['route_code'];
        $first = Database::fetch("SELECT latitude, longitude FROM route_stops WHERE route_id = :r AND stop_type = 'boarding' AND latitude IS NOT NULL ORDER BY stop_time LIMIT 1", ['r' => $rid]);
        $near = Database::fetchAll("SELECT id, dep_time_override FROM schedules WHERE route_id = :r AND travel_date BETWEEN :a AND :b",
            ['r' => $rid, 'a' => date('Y-m-d', time() - 86400), 'b' => date('Y-m-d')]);
        foreach ($near as $n) { $overrides[(int) $n['id']] = $n['dep_time_override']; }
        // Push every real trip of this route out of the on-the-road window.
        $hour = (int) date('G');
        $away = $hour >= 17 ? '00:05:00' : date('H:i:s', time() + 2 * 3600);
        foreach ($near as $n) {
            $isToday = Database::scalar('SELECT travel_date FROM schedules WHERE id = :i', ['i' => (int) $n['id']]) === date('Y-m-d');
            Database::update('schedules', ['dep_time_override' => $isToday ? $away : '00:05:00'], 'id = :i', ['i' => (int) $n['id']]);
        }
        if ($first !== null) {
            $kv((float) $first['latitude'], (float) $first['longitude'] + 0.002, time());
            $e0 = json_decode(http('/api/eta.php?route=' . rawurlencode($code))['body'], true) ?: [];
            check('a fresh fix but NO trip on the road → live:false', ($e0['data']['live'] ?? null) === false, substr(json_encode($e0), 0, 120));
            $today = date('Y-m-d');
            $existedT = Database::exists('SELECT 1 FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $rid, 'd' => $today]);
            $sidT = (int) Seats::schedule($rid, $today)['id'];
            if (!$existedT) { $GLOBALS['tkpSched'] = $sidT; }   // made by this test: removed in finally
            if (!array_key_exists($sidT, $overrides)) { $overrides[$sidT] = Database::scalar('SELECT dep_time_override FROM schedules WHERE id = :i', ['i' => $sidT]); }
            $origStatus = (string) Database::scalar('SELECT status FROM schedules WHERE id = :i', ['i' => $sidT], 'scheduled');
            Database::update('schedules', ['dep_time_override' => date('H:i:s', max(strtotime($today . ' 00:00'), time() - 3600)), 'status' => 'scheduled'], 'id = :i', ['i' => $sidT]);
            $e1 = json_decode(http('/api/eta.php?route=' . rawurlencode($code))['body'], true) ?: [];
            check('exactly one trip on the road → live with stops', ($e1['data']['live'] ?? null) === true && !empty($e1['data']['stops']), substr(json_encode($e1), 0, 120));
            Database::update('schedules', ['status' => $origStatus], 'id = :i', ['i' => $sidT]);
        } else {
            echo "  SKIP  eta.php (no stop with coordinates)\n";
        }

        /* ---------------------------------------------------------- */
        echo "\n-- H. public pages --\n";
        foreach (['/bus/surat-to-rupaidiha-bus' => 'en', '/hi/bus/surat-to-rupaidiha-bus' => 'hi', '/ne/bus/surat-to-rupaidiha-bus' => 'ne'] as $path => $l) {
            $pg = http($path);
            if ($pg['code'] !== 200) { check("route page $path renders", false, 'HTTP ' . $pg['code']); continue; }
            $new = ['en' => 'Aadhaar alone is often not accepted', 'hi' => 'अकेले आधार को अक्सर नहीं माना जाता', 'ne' => 'आधार मात्र प्रायः मान्य हुँदैन'][$l];
            $old = ['en' => 'passport or Aadhaar with a photo', 'hi' => 'फोटो वाला आधार', 'ne' => 'फोटो भएको आधार'][$l];
            check("$l: Aadhaar only with the 'not accepted alone' wording", str_contains($pg['body'], $new) && !str_contains($pg['body'], $old));
            check("$l: 30 minutes, like the ticket", str_contains($pg['body'], $l === 'en' ? '30 minutes' : ($l === 'hi' ? '30 मिनट' : '३० मिनेट')) && !str_contains($pg['body'], '20 minutes'));
            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $pg['body'], $ld);
            check("$l: JSON-LD parses", isset($ld[1]) && json_decode($ld[1], true) !== null);
            check("$l: CTA keeps the direction", str_contains($pg['body'], 'href="/?src=route&amp;dir=go&amp;town=surat#/"'), '');
            check("$l: theme pre-paint + AMOLED", str_contains($pg['body'], "localStorage.getItem('shg:theme')") && str_contains($pg['body'], '[data-display="amoled"]'));
        }
        $back = http('/bus/rupaidiha-to-surat-bus');
        if ($back['code'] === 200) {
            check('return page CTA asks for the back direction', str_contains($back['body'], 'dir=back&amp;town=surat'));
        }
        $rp = http('/refund-policy.php');
        check('refund-policy: fixed revision date', str_contains($rp['body'], 'Last updated: 8 October 2026'));
        check('  never date()', !str_contains((string) file_get_contents(dirname(__DIR__) . '/refund-policy.php'), "date('j F Y')"));
        check('  h1 is the page title', (bool) preg_match('#<h1[^>]*>Refund &amp; Cancellation Policy</h1>#', $rp['body']));
        foreach (['/refund-policy.php', '/travel-info.php', '/help.php'] as $pth) {
            $pg = http($pth);
            check("$pth pre-paints the saved theme + AMOLED", str_contains($pg['body'], "localStorage.getItem('shg:theme')") && str_contains($pg['body'], '[data-display="amoled"]'));
        }
        $ti = http('/travel-info.php');
        check('travel-info: h1 is the page title, the brand is not an h1', !preg_match('#<h1>[^<]*S Hari Global#i', $ti['body']) && substr_count($ti['body'], '<h1') >= 1);
    }

    echo "\n-- I. help desk 'Bus location' reply --\n";
    $canned = HelpDesk::canned();
    $loc = array_values(array_filter($canned, static fn(array $c): bool => str_contains($c['b'], '{track}')));
    check("the default 'Bus location' reply uses {track}", Settings::getArray('helpdesk_canned', []) !== [] || count($loc) === 1);
    check('support-inbox fills {track} with the keyed tracker', str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/support-inbox.php'), "Ticket::trackUrl((string) \$r['pnr'])"));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (!empty($GLOBALS['tkpSched'])) {
        foreach (Database::fetchAll('SELECT booking_id FROM booking_legs WHERE schedule_id = :s', ['s' => (int) $GLOBALS['tkpSched']]) as $lr) {
            Database::delete('trip_events', 'booking_id = :b', ['b' => (int) $lr['booking_id']]);
            Database::delete('bookings', 'id = :b', ['b' => (int) $lr['booking_id']]);
        }
        Database::delete('schedules', 'id = :s', ['s' => (int) $GLOBALS['tkpSched']]);
    }
    foreach ($overrides as $id => $ov) {
        Database::update('schedules', ['dep_time_override' => $ov], 'id = :i', ['i' => $id]);
    }
    Database::query("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' AND updated_by = 'tkp-test'");
    if ($livebusSaved !== null && !Database::exists("SELECT 1 FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'")) {
        Database::insert('kv_store', ['kscope' => 'global', 'kkey' => 'livebus', 'kvalue' => $livebusSaved['kvalue'], 'updated_at' => $livebusSaved['updated_at']]);
    }
    tkpCleanup();
    foreach ($saved as $k => $row) {
        if ($row === null) { Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $k]); }
        else { Database::query('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
