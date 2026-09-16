<?php
/**
 * Paid-but-pending hold — regression test (27 Aug 2026)
 *
 * Business rule (owner): "admin le accept nagarne bela samma pending ma
 * basnu parne" — once a customer has sent payment proof, the booking waits
 * on the ADMIN and must never be swept away by the unpaid-booking cron.
 *
 * Before the fix, cron/expire.php expired ANY pending booking past its
 * expires_at, including one whose customer had already transferred the fare
 * and submitted a UTR. The seats were released and resold while the money
 * sat in the company UPI account, and because 'expired' is a terminal state
 * the customer could not even re-submit their proof.
 *
 *   php -c .claude/php-dev.ini tests/paid-pending-hold-test.php
 *
 * Writes only throwaway rows on a far-future date and cleans up after
 * itself. CLI only.
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
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const TD    = '2099-11-11';
const PHONE = '9100000771';

function cleanup(): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = '" . PHONE . "'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules',    'travel_date = :d', ['d' => TD]);
}

/**
 * Run exactly the sweep cron/expire.php runs, without shelling out to it.
 * Kept identical in intent to the cron so the test cannot pass against a
 * query the cron does not actually use.
 */
function runSweep(): int {
    $stale = Database::fetchAll(
        "SELECT b.id, b.pnr FROM bookings b
          WHERE b.status = 'pending'
            AND b.expires_at IS NOT NULL
            AND b.expires_at < NOW()
            AND NOT EXISTS (
                  SELECT 1 FROM payments p
                   WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number, '')) <> ''
                )
            AND NOT EXISTS (
                  SELECT 1 FROM payment_screenshots s WHERE s.booking_id = b.id
                )
          LIMIT 500"
    );

    $n = 0;
    foreach ($stale as $b) {
        Database::transaction(static function () use ($b, &$n): void {
            Seats::releaseBooking((int) $b['id']);
            Database::update('bookings',
                ['status' => 'expired', 'cancel_reason' => 'Payment window elapsed'],
                'id = :id AND status = :s',
                ['id' => (int) $b['id'], 's' => 'pending']
            );
            $n++;
        });
    }
    return $n;
}

/** Book one seat as a normal (non-COD) online customer. */
function book(array $route, string $seat, string $name): array {
    return BookingService::create([
        'routeId'       => (int) $route['id'],
        'travelDate'    => TD,
        'seats'         => [$seat],
        'passengers'    => [['name' => $name, 'age' => 30, 'gender' => 'Male']],
        'contact'       => ['phone' => PHONE],
        'bookingMode'   => 'sharing',
        'paymentMethod' => 'upi',
        'isCod'         => false,
        'boarding'      => '',
    ]);
}

function expiresAt(string $pnr): ?string {
    $v = Database::scalar('SELECT expires_at FROM bookings WHERE pnr = :p', ['p' => $pnr]);
    return ($v === null || $v === false) ? null : (string) $v;
}
function statusOf(string $pnr): string {
    return (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pnr], '');
}
function agePastDue(string $pnr): void {
    Database::query(
        "UPDATE bookings SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE pnr = :p",
        ['p' => $pnr]
    );
}

echo "\n=== Paid-but-pending must survive the expiry sweep ===\n\n";
cleanup();

try {
    $route = Database::fetch(
        "SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1"
    );
    if ($route === null) { echo "  no active sleeper route — cannot run\n"; exit(1); }
    echo "  route: {$route['route_code']} {$route['from_city']} -> {$route['to_city']}\n\n";

    /* ---- CONTROL — a genuinely abandoned booking MUST still expire ------ */
    echo "-- CONTROL: unpaid booking, no proof --\n";
    $a = book($route, 'L11', 'Control Abandoned');
    check('unpaid booking starts with an expiry clock', expiresAt($a['pnr']) !== null,
          'expires_at=' . (expiresAt($a['pnr']) ?? 'NULL'));

    agePastDue($a['pnr']);
    $swept = runSweep();
    check('sweep expires the abandoned booking', statusOf($a['pnr']) === 'expired',
          'status=' . statusOf($a['pnr']) . ", swept=$swept");
    check('its seat is released back to the pool',
          !Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $a['id']]));

    /* ---- THE BUG — customer paid, admin has not looked yet ------------- */
    echo "\n-- REGRESSION: customer submitted a UTR, admin asleep --\n";
    $b = book($route, 'L12', 'Paid Awaiting Admin');
    check('paid-path booking also starts with a clock', expiresAt($b['pnr']) !== null);

    BookingService::submitPaymentProof($b['pnr'], [
        'utr'       => '4455667788990',
        'payerName' => 'Paid Awaiting Admin',
        'method'    => 'upi',
    ]);

    check('submitting a UTR clears the expiry clock', expiresAt($b['pnr']) === null,
          'expires_at=' . (expiresAt($b['pnr']) ?? 'NULL'));
    check('booking is still PENDING (not auto-confirmed)', statusOf($b['pnr']) === 'pending',
          'status=' . statusOf($b['pnr']));

    /* Force a stale clock back on, to prove the cron guard holds even if some
       other code path re-sets expires_at. */
    agePastDue($b['pnr']);
    $swept = runSweep();

    check('sweep REFUSES to expire a booking with a UTR', statusOf($b['pnr']) === 'pending',
          'status=' . statusOf($b['pnr']) . ", swept=$swept");
    check('its seat is still held for the passenger',
          Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $b['id']]));

    /* ---- SCREENSHOT-ONLY customer (never types a UTR) ------------------ */
    echo "\n-- REGRESSION: screenshot uploaded, no UTR typed --\n";
    $c = book($route, 'L13', 'Screenshot Only');
    $payId = (int) Database::scalar(
        'SELECT id FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
        ['b' => (int) $c['id']], 0
    );
    Database::insert('payment_screenshots', [
        'payment_id'    => $payId,
        'booking_id'    => (int) $c['id'],
        'file_path'     => '2099/11/test-proof.png',
        'original_name' => 'test-proof.png',
        'mime_type'     => 'image/png',
        'file_size'     => 1234,
        'sha256'        => str_repeat('a', 64),
        'uploaded_ip'   => '127.0.0.1',
    ]);

    agePastDue($c['pnr']);
    $swept = runSweep();
    check('sweep REFUSES to expire a booking with a screenshot', statusOf($c['pnr']) === 'pending',
          'status=' . statusOf($c['pnr']) . ", swept=$swept");
    check('its seat is still held',
          Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => (int) $c['id']]));

    /* ---- SEAT PARKING — a blank submit must NOT stop the clock --------- */
    echo "\n-- ABUSE: blank proof must not park a seat forever --\n";
    $d = book($route, 'L14', 'Blank Submitter');
    BookingService::submitPaymentProof($d['pnr'], ['utr' => '', 'payerName' => '', 'method' => 'upi']);
    check('blank UTR leaves the expiry clock running', expiresAt($d['pnr']) !== null,
          'expires_at=' . (expiresAt($d['pnr']) ?? 'NULL'));

    agePastDue($d['pnr']);
    runSweep();
    check('sweep still expires the blank submitter', statusOf($d['pnr']) === 'expired',
          'status=' . statusOf($d['pnr']));

    /* ---- ADMIN ACCEPTS — the normal happy path still works ------------- */
    echo "\n-- HAPPY PATH: admin finally accepts the paid booking --\n";
    $adminId = (int) Database::scalar(
        "SELECT id FROM admins WHERE is_active = 1 ORDER BY (role = 'superadmin') DESC, id ASC LIMIT 1",
        [], 0
    );
    BookingService::confirm((int) $b['id'], $adminId, 'verified by test');
    check('admin can confirm the held booking', statusOf($b['pnr']) === 'confirmed',
          'status=' . statusOf($b['pnr']));
    check('a ticket was issued',
          Database::exists('SELECT 1 FROM tickets WHERE booking_id = :b', ['b' => (int) $b['id']]));

} catch (Throwable $e) {
    $FAIL++;
    echo "  \033[31mERROR\033[0m  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

cleanup();
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
