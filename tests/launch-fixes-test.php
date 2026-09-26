<?php
/**
 * Launch fixes (25 Aug 2026) — focused regression test for:
 *   M1  counterSale prices sharing sleeper by DIRECTION (toNepal 1800 / toIndia 2000)
 *   M2  cancelling an UNCOLLECTED COD booking parks NO refund; a collected one does
 *   H2  markBoarded() boards once and refuses a re-scan; stamps passengers
 *
 *   php -c .claude/php-dev.ini tests/launch-fixes-test.php
 *
 * Writes only throwaway rows (PNRs prefixed SHG-TEST-) on a far-future date
 * and cleans up after itself. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS,$FAIL; if($ok){$PASS++;echo "  \033[32mPASS\033[0m  $l\n";}else{$FAIL++;echo "  \033[31mFAIL\033[0m  $l\n";} }

const TD = '2099-12-24';

function cleanup(): void {
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-TEST%' OR contact_phone='9198765432'"), 'id') as $id) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]); // cascades to legs/seats/pax/payments/tickets
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
}

echo "\n=== Launch fixes — M1 / M2 / H2 ===\n\n";
cleanup();

/* Fixture predates the L5+L6 staff reservation — pin the old L1 layout
   for the run; restored to the production layout before exit. */
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $admin = Database::fetch("SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1");
    $adminId = (int) ($admin['id'] ?? 0);
    check('a test admin exists', $adminId > 0);

    /* pick one sleeper route toward Nepal and one toward India */
    $toNepal = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 AND to_city IN ('Nepalgunj','Rupaidiha','Kohalpur') ORDER BY id LIMIT 1");
    $toIndia = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 AND to_city IN ('Ahmedabad','Mehsana','Surat','Vadodara') ORDER BY id LIMIT 1");
    check('found a sleeper route toward Nepal', $toNepal !== null);
    check('found a sleeper route toward India', $toIndia !== null);

    $dir = Fare::dirFares();
    echo "  (dir fares: toNepal={$dir['toNepal']} toIndia={$dir['toIndia']})\n";

    /* ---- M1 — counter sale prices by direction ------------------------- */
    if ($toNepal) {
        $sch = Seats::schedule((int) $toNepal['id'], TD);
        $b = BookingService::counterSale($toNepal, (int) $sch['id'], TD, ['L5'],
            ['name' => 'M1 Nepal', 'phone' => '9198765432', 'gender' => 'Male', 'paymentMethod' => 'cash'],
            $adminId, 'counter');
        $wantN = Fare::pointFare((string) $toNepal['from_city'], (string) $toNepal['to_city']);
        check("M1: toNepal counter fare = the board fare for that pair ({$wantN}) — got {$b['fare_per_seat']}",
            abs((float) $b['fare_per_seat'] - $wantN) < 0.01);
    }
    if ($toIndia) {
        $sch = Seats::schedule((int) $toIndia['id'], TD);
        $b = BookingService::counterSale($toIndia, (int) $sch['id'], TD, ['L6'],
            ['name' => 'M1 India', 'phone' => '9198765432', 'gender' => 'Male', 'paymentMethod' => 'cash'],
            $adminId, 'counter');
        $wantI = Fare::pointFare((string) $toIndia['from_city'], (string) $toIndia['to_city']);
        check("M1: toIndia counter fare = the board fare for that pair ({$wantI}) — got {$b['fare_per_seat']}",
            abs((float) $b['fare_per_seat'] - $wantI) < 0.01);
    }

    /* ---- M2 — uncollected COD cancel parks NO refund ------------------- */
    $r = $toNepal ?: $toIndia;
    $req = [
        'routeId' => (int) $r['id'], 'travelDate' => TD, 'seats' => ['L10'],
        'passengers' => [['name' => 'M2 COD', 'gender' => 'Male', 'age' => 30]],
        'contact' => ['phone' => '9198765432'],
        'bookingMode' => 'sharing', 'cabinType' => 'single',
        'isCod' => true, 'paymentMethod' => 'cod',
    ];
    $cod = BookingService::create($req);
    $codPnr = (string) $cod['pnr'];
    $pay = Database::fetch("SELECT status FROM payments WHERE booking_id=:b ORDER BY id DESC LIMIT 1", ['b' => $cod['id']]);
    check("M2: COD booking is confirmed with payment still cod_pending — status={$cod['status']}/pay={$pay['status']}",
        $cod['status'] === 'confirmed' && $pay['status'] === 'cod_pending');
    $res = BookingService::cancel($codPnr, 'test cancel', true);
    check('M2: cancelling an UNCOLLECTED COD parks refund = 0 — got ' . $res['refund']['amount'],
        (float) $res['refund']['amount'] === 0.0);

    /* a COD whose cash WAS collected (settleCod) then cancelled DOES refund */
    $req['seats'] = ['L11']; $req['passengers'][0]['name'] = 'M2 COD paid';
    $cod2 = BookingService::create($req);
    BookingService::settleCod((int) $cod2['id'], $adminId, 'cash in hand');
    $res2 = BookingService::cancel((string) $cod2['pnr'], 'test cancel paid', true);
    check('M2: cancelling a COLLECTED COD still refunds by slab — got ' . $res2['refund']['amount'],
        (float) $res2['refund']['amount'] > 0.0);

    /* ---- H2 — boarding once, reject re-scan --------------------------- */
    $req['seats'] = ['L12']; $req['passengers'][0]['name'] = 'H2 Board';
    $bk = BookingService::create($req);                 // COD → confirmed + ticket issued
    $bp1 = BookingService::markBoarded((string) $bk['pnr'], $adminId);
    check('H2: first scan boards the ticket — state=' . $bp1['state'], $bp1['state'] === 'boarded');
    $paxBoarded = Database::scalar("SELECT boarded_at FROM booking_passengers WHERE booking_id=:b LIMIT 1", ['b' => $bk['id']]);
    check('H2: passenger stamped boarded_at', $paxBoarded !== null && $paxBoarded !== '');
    $bp2 = BookingService::markBoarded((string) $bk['pnr'], $adminId);
    check('H2: second scan is refused as ALREADY boarded — state=' . $bp2['state'], $bp2['state'] === 'already');
    check('H2: scan_count incremented to 2 — got ' . $bp2['scanCount'], (int) $bp2['scanCount'] === 2);

} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
}

cleanup();
Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
