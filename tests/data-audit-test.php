<?php
/**
 * =====================================================================
 *  data-audit-test.php — the night audit names the RIGHT fault, and only
 *  when there is one (19 Sep 2026).
 *
 *  includes/dataaudit.php re-reads the register and raises a card per
 *  broken invariant. A card that is wrong is worse than none — the owner
 *  learns to scroll past it — so this suite plants one broken row per
 *  check and asserts that exactly that check moves, plants the look-alikes
 *  that must NOT alarm (a paid passenger waiting for verification, a
 *  pay-on-boarding ticket), and proves the cards clear themselves once the
 *  rows are gone.
 *
 *  The test database is not a clean slate, so nothing here asserts "zero":
 *  every assertion is a DELTA against a baseline taken before planting.
 *
 *    php -c .claude/php-dev.ini tests/data-audit-test.php
 *
 *  Plants rows by raw INSERT on purpose (the service layer would refuse to
 *  write them — that is the point), tagged SHG-AUDIT-*, on a far-future
 *  date, and deletes every one of them plus the incidents it raised.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/dataaudit.php';

const AUD_DATE = '2099-11-23';
const AUD_TAG  = 'SHG-AUDIT-';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** @return array<string,int> finding count per key (absent = 0) */
function counts(): array {
    $out = [];
    foreach (DataAudit::run(30) as $f) { $out[$f['key']] = (int) $f['count']; }
    return $out;
}
function at(array $c, string $key): int { return (int) ($c['data.' . $key] ?? 0); }

$cleanup = static function (): void {
    $ids = array_column(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . AUD_TAG . "%'"), 'id');
    foreach ($ids as $id) {
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $id]);   // no FK on the ledger
        Database::delete('bookings', 'id = :b', ['b' => (int) $id]);               // legs / seats / pax / payments cascade
    }
    Database::run("DELETE FROM seat_locks WHERE lock_token LIKE 'audit-test-%'");
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => AUD_DATE]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => AUD_DATE]);
    Database::run("DELETE FROM health_incidents WHERE dedupe_key LIKE 'data.%'");
};

$n = 0;
/** Raw booking + one leg. Returns [bookingId, legId]. */
function plant(int $sid, array $booking, int $seatCount): array {
    global $n; $n++;
    $bid = Database::insert('bookings', $booking + [
        'pnr'           => AUD_TAG . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . '-' . substr(md5((string) microtime(true)), 0, 6),
        'contact_phone' => '9000000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
        'booking_mode'  => 'sharing',
        'total_amount'  => 1000,
        'status'        => 'confirmed',
        'source'        => 'admin',
    ]);
    $lid = Database::insert('booking_legs', [
        'booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => AUD_DATE,
        'seat_count' => $seatCount, 'fare_per_seat' => 1000, 'leg_total' => 1000,
    ]);
    return [$bid, $lid];
}
function seat(int $sid, int $bid, int $lid, string $seatNo): void {
    Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => $seatNo, 'booking_id' => $bid, 'leg_id' => $lid]);
}
function pay(int $bid, float $amount, string $status = 'verified', ?string $utr = null): void {
    global $n; $n++;
    Database::insert('payments', [
        'booking_id' => $bid, 'payment_ref' => 'PAY-AUDIT-' . $n . '-' . substr(md5((string) microtime(true)), 0, 6),
        'method' => 'cash', 'mode' => 'offline', 'amount' => $amount, 'status' => $status, 'utr_number' => $utr,
    ]);
}

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$agent = Database::fetch('SELECT id FROM admins ORDER BY id LIMIT 1');

if ($route === null || $agent === null) {
    echo "  \033[33mSKIP\033[0m  needs an active sleeper route and one admin row on this DB\n";
} else {
    $cleanup();
    try {
        $sid     = (int) Seats::schedule((int) $route['id'], AUD_DATE)['id'];
        $coach   = Seats::coachForSchedule($sid);
        $agentId = (int) $agent['id'];

        /* A private label and a sharing bed UNDER it whose strings differ —
           the clash UNIQUE(schedule_id, seat_no) cannot see. Asked of the
           engine, not hard-coded, so a re-shaped coach cannot stale this. */
        $privLabel = null; $bedUnder = null;
        foreach (Seats::seatIds($coach, 'private') as $label) {
            foreach (Seats::physicalSeats($label, 'private', $coach) as $bed) {
                if ($bed !== $label) { $privLabel = $label; $bedUnder = $bed; break 2; }
            }
        }
        $free = array_values(array_diff(
            Seats::seatIds($coach, 'sharing'),
            $privLabel !== null ? Seats::physicalSeats($privLabel, 'private', $coach) : []
        ));

        echo "-- baseline --\n";
        $base = counts();
        check('the audit runs on this database without a check erroring',
            array_filter(DataAudit::run(30), static fn($f) => str_contains($f['title'], 'could not run')) === []);

        echo "-- one broken row per check --\n";

        // seat.double_bed
        if ($privLabel !== null) {
            [$bA, $lA] = plant($sid, ['booking_mode' => 'private'], 1); seat($sid, $bA, $lA, $privLabel); pay($bA, 1000);
            [$bB, $lB] = plant($sid, [], 1);                            seat($sid, $bB, $lB, (string) $bedUnder); pay($bB, 1000);
            $c = counts();
            check("private $privLabel + sharing $bedUnder (one mattress) → seat.double_bed",
                at($c, 'seat.double_bed') === at($base, 'seat.double_bed') + 1, at($base, 'seat.double_bed') . ' -> ' . at($c, 'seat.double_bed'));
            $f = DataAudit::seatDoubleBed(date('Y-m-d'), date('Y-m-d'));
            check('...is CRITICAL and names both bookings',
                $f !== null && $f['severity'] === Health::CRITICAL
                && (at($base, 'seat.double_bed') > 0 || (in_array($bA, $f['ids'], true) && in_array($bB, $f['ids'], true))));
        } else {
            echo "  \033[33mSKIP\033[0m  this coach has no private label over differently-named beds\n";
        }

        // seat.ticket_without_seat — says 2, holds 1
        $before = counts();
        [$bC, $lC] = plant($sid, [], 2); seat($sid, $bC, $lC, $free[0]); pay($bC, 1000);
        $c = counts();
        check('leg says 2 seats, seat table holds 1 → seat.ticket_without_seat',
            at($c, 'seat.ticket_without_seat') === at($before, 'seat.ticket_without_seat') + 1);

        // seat.held_by_dead + wallet.not_reversed — cancelled, seat kept, commission kept
        $before = $c;
        [$bD, $lD] = plant($sid, ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], 1);
        seat($sid, $bD, $lD, $free[1]);
        Database::insert('agent_ledger', ['agent_admin_id' => $agentId, 'booking_id' => $bD, 'account' => 'commission', 'entry_type' => 'commission', 'amount' => 50]);
        $c = counts();
        check('cancelled booking still on a seat → seat.held_by_dead',
            at($c, 'seat.held_by_dead') === at($before, 'seat.held_by_dead') + 1);
        check('cancelled booking, commission never reversed → wallet.not_reversed',
            at($c, 'wallet.not_reversed') === at($before, 'wallet.not_reversed') + 1);
        check('...a dead booking is NOT reported as a ticket without a seat',
            at($c, 'seat.ticket_without_seat') === at($before, 'seat.ticket_without_seat'));

        // seat.pax_mismatch
        $before = $c;
        [$bE, $lE] = plant($sid, [], 1); seat($sid, $bE, $lE, $free[2]); pay($bE, 1000);
        Database::insert('booking_passengers', ['booking_id' => $bE, 'leg_id' => $lE, 'passenger_ref' => 'AUD-' . $bE . '-1', 'seat_no' => $free[3], 'full_name' => 'Audit Fixture', 'is_primary' => 1]);
        $c = counts();
        check("ticket prints {$free[3]}, booking holds {$free[2]} → seat.pax_mismatch",
            at($c, 'seat.pax_mismatch') === at($before, 'seat.pax_mismatch') + 1);
        Database::update('booking_passengers', ['seat_no' => $free[2]], 'booking_id = :b', ['b' => $bE]);
        check('...and clears when the passenger row names the held seat',
            at(counts(), 'seat.pax_mismatch') === at($before, 'seat.pax_mismatch'));

        // seat.counter_drift — raw seat rows never touched schedules.seats_booked
        check('raw seat rows left schedules.seats_booked behind → seat.counter_drift',
            at($c, 'seat.counter_drift') === at($base, 'seat.counter_drift') + 1);

        // money.impossible
        $before = counts();
        [$bF, $lF] = plant($sid, ['refund_amount' => 1500], 1); seat($sid, $bF, $lF, $free[4]); pay($bF, 1000);
        $c = counts();
        check('refund 1500 on a 1000 ticket → money.impossible',
            at($c, 'money.impossible') === at($before, 'money.impossible') + 1);

        // money.overpaid + wallet.voided_but_live
        $before = $c;
        [$bG, $lG] = plant($sid, [], 1); seat($sid, $bG, $lG, $free[5]); pay($bG, 1000); pay($bG, 1000);
        Database::insert('agent_ledger', ['agent_admin_id' => $agentId, 'booking_id' => $bG, 'account' => 'commission', 'entry_type' => 'commission', 'amount' => 50]);
        Database::insert('agent_ledger', ['agent_admin_id' => $agentId, 'booking_id' => $bG, 'account' => 'commission', 'entry_type' => 'commission_void', 'amount' => -50]);
        $c = counts();
        check('two verified 1000 payments on a 1000 ticket → money.overpaid',
            at($c, 'money.overpaid') === at($before, 'money.overpaid') + 1);
        check('confirmed ticket with its commission still reversed → wallet.voided_but_live',
            at($c, 'wallet.voided_but_live') === at($before, 'wallet.voided_but_live') + 1);

        // money.confirmed_unpaid — and the pay-on-boarding look-alike
        $before = $c;
        [$bH, $lH] = plant($sid, [], 1); seat($sid, $bH, $lH, $free[6]);
        $c = counts();
        check('confirmed, not COD, no verified payment → money.confirmed_unpaid',
            at($c, 'money.confirmed_unpaid') === at($before, 'money.confirmed_unpaid') + 1);
        [$bI, $lI] = plant($sid, ['is_cod' => 1], 1); seat($sid, $bI, $lI, $free[7]); pay($bI, 1000, 'cod_pending');
        check('...a pay-on-boarding ticket is NOT reported (its cash is settled later by design)',
            at(counts(), 'money.confirmed_unpaid') === at($c, 'money.confirmed_unpaid'));

        // hold.unswept — and the paid-pending look-alike expire.php protects
        $before = counts();
        $old = date('Y-m-d H:i:s', strtotime('-2 hours'));
        [$bJ, $lJ] = plant($sid, ['status' => 'pending', 'expires_at' => $old], 1); seat($sid, $bJ, $lJ, $free[8]);
        $c = counts();
        check('pending, unpaid, window ran out 2h ago → hold.unswept',
            at($c, 'hold.unswept') === at($before, 'hold.unswept') + 1);
        [$bK, $lK] = plant($sid, ['status' => 'pending', 'expires_at' => $old], 1); seat($sid, $bK, $lK, $free[9]);
        pay($bK, 1000, 'pending', 'UTR-AUDIT-1');
        check('...a passenger who sent a UTR and waits for verification is NOT "unswept"',
            at(counts(), 'hold.unswept') === at($c, 'hold.unswept'));
        Database::insert('seat_locks', ['schedule_id' => $sid, 'seat_no' => $free[10], 'lock_token' => 'audit-test-1', 'expires_at' => $old]);
        check('a seat lock that expired 2h ago counts too',
            at(counts(), 'hold.unswept') === at($c, 'hold.unswept') + 1);

        echo "-- cards --\n";
        $r = DataAudit::runAndReport(30);
        check('runAndReport() reports what it raised', $r['findings'] >= 9 && $r['critical'] >= 2, json_encode($r));
        $card = Database::fetch("SELECT * FROM health_incidents WHERE dedupe_key = 'data.money.impossible'");
        check('a card is open under kind data_audit', $card !== null && $card['status'] === 'open' && $card['kind'] === 'data_audit');
        check('...and carries booking ids only (no phone can reach the card)',
            $card !== null && preg_match('/^[0-9, ]*$/', (string) $card['sample_ids']) === 1 && !str_contains((string) $card['detail'], '9000000'),
            (string) ($card['sample_ids'] ?? ''));
        DataAudit::runAndReport(30);
        $again = Database::fetch("SELECT occurrences FROM health_incidents WHERE dedupe_key = 'data.money.impossible'");
        check('a second night bumps the same card instead of writing a new one',
            (int) Database::scalar("SELECT COUNT(*) FROM health_incidents WHERE dedupe_key = 'data.money.impossible'", [], 0) === 1
            && (int) ($again['occurrences'] ?? 0) > (int) $card['occurrences']);

        echo "-- self-healing --\n";
        $ids = array_column(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . AUD_TAG . "%'"), 'id');
        foreach ($ids as $id) {
            Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $id]);
            Database::delete('bookings', 'id = :b', ['b' => (int) $id]);
        }
        Database::run("DELETE FROM seat_locks WHERE lock_token LIKE 'audit-test-%'");
        $after = counts();
        $back  = true;
        foreach (array_keys($after + $base) as $k) { if (($after[$k] ?? 0) !== ($base[$k] ?? 0)) { $back = false; echo "      $k: " . ($base[$k] ?? 0) . ' -> ' . ($after[$k] ?? 0) . "\n"; } }
        check('with the planted rows gone every count is back at its baseline', $back);
        DataAudit::runAndReport(30);
        if (at($base, 'money.impossible') === 0) {
            $card = Database::fetch("SELECT status FROM health_incidents WHERE dedupe_key = 'data.money.impossible'");
            check('...and the card resolves itself', $card !== null && $card['status'] === 'resolved');
        }

        echo "-- read only --\n";
        $src = (string) file_get_contents(INCLUDE_PATH . '/dataaudit.php');
        check('dataaudit.php contains no write to the register',
            preg_match('/Database::(insert|insertIgnore|update|delete|run|query)\s*\(/', $src) === 0
            && preg_match('/\b(UPDATE|DELETE|INSERT)\b\s+[`a-z_]/', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src)) === 0);
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
