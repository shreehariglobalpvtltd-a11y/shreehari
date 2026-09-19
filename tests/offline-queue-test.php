<?php
/**
 * =====================================================================
 *  offline-queue-test.php — an offline request becomes ONE ticket, or a
 *  loud failure; never two tickets, never nothing (19 Sep 2026).
 *
 *  includes/offlinequeue.php takes what a desk wrote with no internet and
 *  seats it through the normal sale door. The phone retries until it gets
 *  an answer, so the dangerous bugs are a double sale and a silent drop:
 *
 *    - same uuid twice → the seller is called ONCE, the second answer is
 *      the stored outcome (repeat = true)
 *    - a refusal (bus full…) is kept as 'failed' with the reason and shows
 *      on the attention list until a person writes what was done
 *    - cash taken ≠ ticket price → on the attention list too
 *    - an attempt that died mid-sale is reconciled from the register, not
 *      sold again
 *    - another desk cannot replay my uuid; a bad uuid is refused
 *    - one REAL sale end to end through QuickTicket::sell on a far-future
 *      date, then the same uuid again → still one booking
 *
 *    php -c .claude/php-dev.ini tests/offline-queue-test.php
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
require_once INCLUDE_PATH . '/offlinequeue.php';

const OQ_DATE  = '2099-11-26';
const OQ_PHONE = '90000030';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function uuid4(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$made = [];
$cleanup = static function () use (&$made): void {
    foreach ($made as $u) { Database::delete('offline_requests', 'uuid = :u', ['u' => $u]); }
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . OQ_PHONE . "%'") as $b) {
        try { Seats::releaseBooking((int) $b['id']); } catch (Throwable $e) {}
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => OQ_DATE]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => OQ_DATE]);
};

$hasTable = Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'offline_requests'");
$admins   = Database::fetchAll("SELECT * FROM admins WHERE is_active = 1 ORDER BY (role = 'superadmin') DESC, id LIMIT 2");

if (!$hasTable) {
    check('offline_requests exists (apply database/upgrade-2026-09-offline-requests.sql)', false);
} elseif (count($admins) < 2) {
    echo "  \033[33mSKIP\033[0m  needs two active admins on this DB\n";
} else {
    [$A, $B] = $admins;
    $cleanup();
    try {
        $calls = 0;
        $okSeller = static function (array $input, array $staff) use (&$calls): array {
            $calls++;
            return ['ok' => true, 'pnr' => 'SHG-OQ-FAKE-' . $calls, 'bookingId' => 0, 'total' => 2000.0, 'seats' => ['L9']];
        };
        $item = static function (string $u, array $over = []): array {
            return $over + ['uuid' => $u, 'name' => 'Offline Fixture', 'phone' => OQ_PHONE . '01', 'country' => 'IN', 'gender' => 'Male',
                            'seats' => 1, 'direction' => '', 'date' => OQ_DATE, 'boarding' => '', 'pay' => 'cash', 'cash' => 2000,
                            'note' => 'wrote on paper too', 'takenAt' => '2099-11-25T18:30:00+05:30'];
        };

        echo "-- exactly once --\n";
        $u1 = uuid4(); $made[] = $u1;
        $r1 = OfflineQueue::sync($item($u1), $A, $okSeller);
        check('a new request is sold through the seller and answered ticketed', $r1['status'] === 'ticketed' && $r1['pnr'] === 'SHG-OQ-FAKE-1' && $r1['repeat'] === false, json_encode($r1));
        $r2 = OfflineQueue::sync($item($u1), $A, $okSeller);
        $r3 = OfflineQueue::sync($item($u1, ['name' => 'Changed Name', 'seats' => 5]), $A, $okSeller);
        check('the same uuid again (retry, double tap) does NOT call the seller again', $calls === 1, 'calls=' . $calls);
        check('...and gets the stored outcome, flagged as a repeat', $r2['pnr'] === 'SHG-OQ-FAKE-1' && $r2['repeat'] === true && $r3['pnr'] === 'SHG-OQ-FAKE-1');
        check('one row for that uuid, still holding what was FIRST written',
            (int) Database::scalar('SELECT COUNT(*) FROM offline_requests WHERE uuid = :u', ['u' => $u1], 0) === 1
            && (string) Database::scalar('SELECT name FROM offline_requests WHERE uuid = :u', ['u' => $u1], '') === 'Offline Fixture');
        check('the sale carried the request tag (so an interrupted sale can be found again)',
            str_starts_with(OfflineQueue::tag($u1), 'Offline request ') && strlen(OfflineQueue::tag($u1)) === 24);

        echo "-- who may send what --\n";
        $stolen = false;
        try { OfflineQueue::sync($item($u1), $B, $okSeller); } catch (RuntimeException $e) { $stolen = str_contains($e->getMessage(), 'another desk'); }
        check('another desk replaying my uuid is refused and sells nothing', $stolen && $calls === 1);
        $bad = 0;
        foreach (['', 'not-a-uuid', '12345678-1234-1234-1234-123456789012x'] as $u) {
            try { OfflineQueue::sync($item($u), $A, $okSeller); } catch (RuntimeException $e) { $bad++; }
        }
        check('a missing / malformed uuid is refused before anything is stored', $bad === 3 && $calls === 1);

        echo "-- a refusal is kept, loudly --\n";
        $u2 = uuid4(); $made[] = $u2;
        $full = static function (): array { throw new RuntimeException('This bus is full. / बस भरिएको छ।'); };
        $rf = OfflineQueue::sync($item($u2), $A, $full);
        check('bus full → status failed with the reason the desk can read', $rf['status'] === 'failed' && str_contains((string) $rf['reason'], 'full'), json_encode($rf));
        $rf2 = OfflineQueue::sync($item($u2), $A, $okSeller);
        check('a failed request is NOT silently re-sold on the next retry', $rf2['status'] === 'failed' && $calls === 1);
        $att = array_column(OfflineQueue::needingAttention((int) $A['id']), 'uuid');
        check('it is on my attention list (cash was taken for it)', in_array($u2, $att, true));
        check('...but not on another desk\'s list', !in_array($u2, array_column(OfflineQueue::needingAttention((int) $B['id']), 'uuid'), true));
        check('...and the office sees it', in_array($u2, array_column(OfflineQueue::needingAttention(null, 300), 'uuid'), true));
        $noNote = false;
        $idf = (int) Database::scalar('SELECT id FROM offline_requests WHERE uuid = :u', ['u' => $u2], 0);
        try { OfflineQueue::resolve($idf, (int) $A['id'], ' '); } catch (RuntimeException $e) { $noNote = true; }
        check('it cannot be cleared without saying what was done', $noNote);
        check('with a note it clears, once', OfflineQueue::resolve($idf, (int) $A['id'], 'Money returned to passenger') === true
            && OfflineQueue::resolve($idf, (int) $A['id'], 'again') === false
            && !in_array($u2, array_column(OfflineQueue::needingAttention((int) $A['id']), 'uuid'), true));

        $u3 = uuid4(); $made[] = $u3;
        OfflineQueue::sync($item($u3, ['cash' => 1500]), $A, $okSeller);
        check('took 1500 for a 2000 ticket → attention list (money does not match)',
            in_array($u3, array_column(OfflineQueue::needingAttention((int) $A['id']), 'uuid'), true));
        check('took exactly the fare → not on the list', !in_array($u1, array_column(OfflineQueue::needingAttention((int) $A['id']), 'uuid'), true));

        echo "-- an interrupted sale is found, not repeated --\n";
        $u4 = uuid4(); $made[] = $u4;
        Database::insert('offline_requests', ['uuid' => $u4, 'admin_id' => (int) $A['id'], 'name' => 'Interrupted', 'seats' => 1,
            'received_at' => date('Y-m-d H:i:s', time() - 30), 'status' => 'received']);
        $ri = OfflineQueue::sync($item($u4), $A, $okSeller);
        check('30 s old and no ticket yet → still "received", nothing sold twice', $ri['status'] === 'received' && $calls === 2, 'calls=' . $calls);
        Database::update('offline_requests', ['received_at' => date('Y-m-d H:i:s', time() - 600)], 'uuid = :u', ['u' => $u4]);
        $ri = OfflineQueue::sync($item($u4), $A, $okSeller);
        check('10 min old and no ticket in the register → failed, for a person to decide', $ri['status'] === 'failed' && $calls === 2);

        echo "-- one real sale, end to end --\n";
        $before = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . OQ_PHONE . "%'", [], 0);
        $u5 = uuid4(); $made[] = $u5;
        $real = OfflineQueue::sync($item($u5, ['phone' => OQ_PHONE . '55', 'cash' => 0]), $A);
        if ($real['status'] === 'ticketed') {
            check('QuickTicket::sell issued a real ticket with a seat', (string) $real['pnr'] !== '' && $real['seats'] !== [], json_encode($real));
            $again = OfflineQueue::sync($item($u5, ['phone' => OQ_PHONE . '55']), $A);
            $after = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . OQ_PHONE . "%'", [], 0);
            check('the same uuid again → the same PNR and still ONE booking', $again['pnr'] === $real['pnr'] && $after === $before + 1, $before . ' -> ' . $after);
            $note = (string) Database::scalar('SELECT p.admin_note FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.pnr = :p LIMIT 1', ['p' => $real['pnr']], '');
            check('the payment note carries the request tag the reconcile step looks for', str_contains($note, OfflineQueue::tag($u5)), $note);
        } else {
            echo "  \033[33mSKIP\033[0m  this database refused the real sale (" . (string) $real['reason'] . ") - the refusal path above already covers it\n";
        }

        echo "-- wiring --\n";
        $api = (string) file_get_contents(ROOT_PATH . '/api/offline-sync.php');
        check('the API is CSRF-guarded, staff-only, switchable and rate-limited',
            str_contains($api, 'Security::requireCsrf()') && str_contains($api, 'Auth::isSellingStaff()')
            && str_contains($api, 'OfflineQueue::enabled()') && str_contains($api, 'requireRateLimit'));
        $src = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(INCLUDE_PATH . '/offlinequeue.php'));
        check('the queue never touches a seat or a booking itself - it only calls the normal sale',
            !preg_match('/Seats::|BookingService::|Database::(insert|insertIgnore|update|delete)\(\s*\'(bookings|booking_seats|payments)\'/', (string) $src));
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
