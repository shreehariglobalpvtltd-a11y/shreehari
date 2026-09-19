<?php
/**
 * includes/dataaudit.php — is the register still telling the truth?
 *
 * Every rule in this system is enforced at the moment of WRITING: the sale
 * gate reasons in physical beds, the wallet is idempotent by a UNIQUE key,
 * expire.php never sweeps a paid passenger. Nothing re-reads the register
 * afterwards. So a row that went wrong — a hand edit in phpMyAdmin, a path
 * that forgot a rule, a half-applied migration — stays wrong in silence until
 * two passengers stand in front of one bed at the border.
 *
 * This class re-reads the register the way a careful clerk would at night and
 * names what does not add up. It is the "100 % correct data" guard asked for
 * on 19 Sep 2026.
 *
 * READ ONLY. Every check is a SELECT. It never repairs, never releases a
 * seat, never touches money: a monitor that "fixes" a booking it has
 * misunderstood is how one wrong row becomes fifty. It names the fault and the
 * bookings; a person decides. (Same contract as cron/health-heartbeat.php.)
 *
 * QUIET BY DESIGN. Only invariants the code itself guarantees are checked —
 * each one cites the writer that guarantees it — and only inside a recent
 * window, so an old legacy row cannot raise the same card every night for
 * ever. A monitor that cries wolf is one the owner learns to scroll past.
 *
 * Sample ids are booking ids and nothing else (never a phone, never a name):
 * Health::open() enforces that too.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/health.php';
require_once __DIR__ . '/seats.php';

final class DataAudit
{
    /** Every incident this class raises lives under this prefix. */
    public const KEY_PREFIX = 'data.';

    /** How many booking ids a card carries — enough to start, not a dump. */
    private const SAMPLE = 12;

    /**
     * Run every check.
     *
     * @return list<array{key:string, severity:string, title:string, detail:string, fix:string, ids:list<int>, count:int}>
     *         one entry per check that FOUND something; a clean check is absent.
     */
    public static function run(?int $daysBack = null): array
    {
        $daysBack = $daysBack ?? max(1, Settings::getInt('data_audit_days_back', 30));
        $since    = date('Y-m-d', strtotime('-' . $daysBack . ' days'));
        $today    = date('Y-m-d');

        $findings = [];
        foreach (self::checks() as $key => $fn) {
            try {
                $f = $fn($since, $today);
            } catch (Throwable $e) {
                /* A check that cannot run is itself worth a card — a missing
                   table after a half-applied migration is exactly the kind of
                   thing this job exists to notice. It must not take the other
                   checks down with it. */
                $f = [
                    'severity' => Health::WARN,
                    'title'    => 'Data check "' . $key . '" could not run',
                    'detail'   => substr($e->getMessage(), 0, 300),
                    'fix'      => 'Run by hand and read the error: php cron/data-audit.php --dry',
                    'ids'      => [],
                    'count'    => 1,
                ];
            }
            if ($f !== null) {
                $f['key'] = self::KEY_PREFIX . $key;
                $findings[] = $f;
            }
        }
        return $findings;
    }

    /**
     * Run, then raise a card per finding and clear the card of every check
     * that came back clean — so the owner's list is what is wrong NOW.
     *
     * @return array{checks:int, findings:int, critical:int, warn:int, keys:list<string>}
     */
    public static function runAndReport(?int $daysBack = null): array
    {
        $findings = self::run($daysBack);
        $raised   = [];
        $critical = 0;
        $warn     = 0;

        foreach ($findings as $f) {
            $raised[$f['key']] = true;
            if ($f['severity'] === Health::CRITICAL) { $critical++; }
            elseif ($f['severity'] === Health::WARN) { $warn++; }

            Health::open('data_audit', $f['key'], $f['severity'], $f['title'], $f['detail'], $f['fix'], $f['ids'], $f['count']);
        }

        foreach (array_keys(self::checks()) as $key) {
            if (!isset($raised[self::KEY_PREFIX . $key])) {
                Health::resolve(self::KEY_PREFIX . $key);
            }
        }

        return [
            'checks'   => count(self::checks()),
            'findings' => count($findings),
            'critical' => $critical,
            'warn'     => $warn,
            'keys'     => array_keys($raised),
        ];
    }

    /**
     * @return array<string, callable(string, string): ?array>
     */
    private static function checks(): array
    {
        return [
            'seat.double_bed'          => [self::class, 'seatDoubleBed'],
            'seat.ticket_without_seat' => [self::class, 'seatTicketWithoutSeat'],
            'seat.held_by_dead'        => [self::class, 'seatHeldByDeadBooking'],
            'seat.pax_mismatch'        => [self::class, 'seatPassengerMismatch'],
            'seat.counter_drift'       => [self::class, 'seatCounterDrift'],
            'money.impossible'         => [self::class, 'moneyImpossible'],
            'money.overpaid'           => [self::class, 'moneyOverpaid'],
            'money.confirmed_unpaid'   => [self::class, 'moneyConfirmedUnpaid'],
            'wallet.not_reversed'      => [self::class, 'walletNotReversed'],
            'wallet.voided_but_live'   => [self::class, 'walletVoidedButLive'],
            'hold.unswept'             => [self::class, 'holdUnswept'],
        ];
    }

    /* =================================================================
     *  Seats
     * ================================================================= */

    /**
     * One physical bed, two bookings.
     *
     * UNIQUE(schedule_id, seat_no) stops the same LABEL being sold twice, but
     * private cabin "L6" and sharing beds "L11"/"L12" are different strings
     * for the same mattress. Seats::assertAvailable() closes that at sale
     * time; this re-checks it with the very same expansion the gate uses
     * (Seats::physicalSeats), so the two can never disagree about what a
     * label means.
     */
    public static function seatDoubleBed(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT bs.schedule_id, bs.seat_no, bs.booking_id, b.booking_mode
               FROM booking_seats bs
               JOIN bookings  b ON b.id = bs.booking_id
               JOIN schedules s ON s.id = bs.schedule_id
              WHERE bs.released_at IS NULL AND s.travel_date >= :d
              ORDER BY bs.schedule_id, bs.id",
            ['d' => $today]
        );

        $owner = [];   // schedule_id => physical bed => booking_id
        $clash = [];   // booking_id => true
        $beds  = [];
        foreach ($rows as $r) {
            $sid   = (int) $r['schedule_id'];
            $bid   = (int) $r['booking_id'];
            $mode  = (string) ($r['booking_mode'] ?? 'sharing');
            $coach = Seats::coachForSchedule($sid);
            foreach (Seats::physicalSeats((string) $r['seat_no'], $mode !== '' ? $mode : 'sharing', $coach) as $p) {
                if (isset($owner[$sid][$p]) && $owner[$sid][$p] !== $bid) {
                    $clash[$owner[$sid][$p]] = true;
                    $clash[$bid]             = true;
                    $beds[$sid . ':' . $p]   = true;
                }
                $owner[$sid][$p] = $bid;
            }
        }
        if ($clash === []) {
            return null;
        }

        return [
            'severity' => Health::CRITICAL,
            'title'    => count($beds) . ' bed(s) are sold to two bookings',
            'detail'   => 'The same physical bed on an upcoming bus belongs to two different bookings (usually one Sharing and one Private). '
                        . 'Two passengers will arrive for one bed. Beds (schedule:bed): ' . implode(', ', array_slice(array_keys($beds), 0, 10)) . '.',
            'fix'      => "1. Open each booking below in Admin > Bookings.\n"
                        . "2. Call the passenger who booked LATER and move them with Seat map > Transfer seat.\n"
                        . "3. Tell the developer which screen made the second sale - the sale gate should have refused it.",
            'ids'      => self::sample(array_keys($clash)),
            'count'    => count($beds),
        ];
    }

    /**
     * A live ticket whose leg owns fewer (or more) seat rows than it says.
     *
     * booking_legs.seat_count is written with the seats (create / counterSale),
     * decremented by the per-seat cancel and rewritten by reschedule, always
     * in the same transaction as booking_seats. So for a live booking the two
     * numbers are equal, or a passenger holds a ticket for a seat the seat
     * table will happily sell to someone else.
     */
    public static function seatTicketWithoutSeat(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT l.booking_id, l.seat_count, COUNT(bs.id) AS held
               FROM booking_legs l
               JOIN bookings b ON b.id = l.booking_id
               LEFT JOIN booking_seats bs ON bs.leg_id = l.id AND bs.released_at IS NULL
              WHERE b.status IN ('pending','confirmed') AND l.travel_date >= :d
              GROUP BY l.id, l.booking_id, l.seat_count
             HAVING held <> l.seat_count
              LIMIT 200",
            ['d' => $today]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::CRITICAL,
            'title'    => count($rows) . ' live ticket(s) do not hold the seats they show',
            'detail'   => 'The ticket says N seats but the seat table holds a different number for that trip. '
                        . 'A seat printed on a ticket may be open for sale to someone else.',
            'fix'      => "1. Open each booking below and compare its seats with Admin > Seat map for that date.\n"
                        . "2. If the seat is still free, re-assign it from the booking page (Change seat).\n"
                        . "3. If it was sold again, call the passenger and move them before travel day.",
            'ids'      => self::sample(array_column($rows, 'booking_id')),
            'count'    => count($rows),
        ];
    }

    /**
     * A cancelled / rejected / expired booking still sitting on a seat.
     *
     * Seats::releaseBooking() DELETES the rows ("so the UNIQUE key stops
     * blocking it"), so a live seat row under a dead booking means the bus
     * looks fuller than it is and that bed cannot be sold.
     */
    public static function seatHeldByDeadBooking(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT DISTINCT bs.booking_id
               FROM booking_seats bs
               JOIN bookings  b ON b.id = bs.booking_id
               JOIN schedules s ON s.id = bs.schedule_id
              WHERE bs.released_at IS NULL
                AND b.status IN ('cancelled','rejected','expired')
                AND s.travel_date >= :d
              LIMIT 200",
            ['d' => $today]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' cancelled booking(s) are still blocking seats',
            'detail'   => 'These bookings are cancelled, rejected or expired but their seats were never given back, '
                        . 'so the bus shows fewer free seats than it has.',
            'fix'      => "1. Open each booking below and confirm it really is cancelled.\n"
                        . "2. Ask the developer to release its seats (Seats::releaseBooking) - do not delete rows by hand.",
            'ids'      => self::sample(array_column($rows, 'booking_id')),
            'count'    => count($rows),
        ];
    }

    /**
     * The name on the ticket sits on a seat the booking does not own.
     *
     * Passenger rows carry the same labels as the leg's seat rows
     * (transferSeat() moves both together). Compared per leg, and only for
     * passengers that name a leg, so an old row without leg_id cannot alarm.
     */
    public static function seatPassengerMismatch(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT DISTINCT p.booking_id
               FROM booking_passengers p
               JOIN bookings     b ON b.id = p.booking_id
               JOIN booking_legs l ON l.id = p.leg_id
               LEFT JOIN booking_seats bs
                      ON bs.leg_id = p.leg_id AND bs.seat_no = p.seat_no AND bs.released_at IS NULL
              WHERE b.status IN ('pending','confirmed')
                AND l.travel_date >= :d
                AND p.seat_no <> ''
                AND bs.id IS NULL
              LIMIT 200",
            ['d' => $today]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' ticket(s) print a seat the booking does not hold',
            'detail'   => 'A passenger row names a seat that is not in the seat table for that trip. '
                        . 'The ticket, the chalan and the seat map will disagree at boarding.',
            'fix'      => "1. Open each booking below: compare the passenger seats with the Seats line.\n"
                        . "2. Correct the passenger's seat from the booking page (Edit passenger) with a reason.",
            'ids'      => self::sample(array_column($rows, 'booking_id')),
            'count'    => count($rows),
        ];
    }

    /**
     * schedules.seats_booked is a cached COUNT(*) that claim() and every
     * release recompute. The results board reads it, so a drifted counter
     * shows "2 seats left" on a bus that has twenty.
     */
    public static function seatCounterDrift(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT s.id, s.seats_booked, COUNT(bs.id) AS live
               FROM schedules s
               LEFT JOIN booking_seats bs ON bs.schedule_id = s.id AND bs.released_at IS NULL
              WHERE s.travel_date >= :d
              GROUP BY s.id, s.seats_booked
             HAVING live <> s.seats_booked
              LIMIT 200",
            ['d' => $today]
        );
        if ($rows === []) {
            return null;
        }

        $list = [];
        foreach (array_slice($rows, 0, 10) as $r) {
            $list[] = '#' . (int) $r['id'] . ' says ' . (int) $r['seats_booked'] . ', has ' . (int) $r['live'];
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' upcoming bus(es) show the wrong number of booked seats',
            'detail'   => 'The "seats booked" number kept on the schedule does not match the seat table: ' . implode('; ', $list) . '. '
                        . 'Customers may see a bus as full (or empty) when it is not. No ticket is affected.',
            'fix'      => 'Ask the developer to recount these schedules (the number is rebuilt from the seat table; nothing is lost).',
            'ids'      => [],
            'count'    => count($rows),
        ];
    }

    /* =================================================================
     *  Money
     * ================================================================= */

    /** Sums that cannot be true whatever the fare rules were. */
    public static function moneyImpossible(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT id FROM bookings
              WHERE created_at >= :s
                AND (total_amount < 0 OR refund_amount < 0 OR refund_amount > total_amount + 0.01)
              LIMIT 200",
            ['s' => $since . ' 00:00:00']
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::CRITICAL,
            'title'    => count($rows) . ' booking(s) have an impossible amount',
            'detail'   => 'A negative total, a negative refund, or a refund larger than the ticket price. Reports and agent statements built on these rows are wrong.',
            'fix'      => "1. Open each booking below and read its Activity log for the edit that changed the amount.\n"
                        . "2. Correct the refund from Admin > Refunds with a reason; never edit the number in the database.",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows),
        ];
    }

    /** More verified money than the ticket costs — somebody is owed change. */
    public static function moneyOverpaid(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT b.id
               FROM bookings b
               JOIN payments p ON p.booking_id = b.id AND p.status = 'verified'
              WHERE b.created_at >= :s AND b.status IN ('pending','confirmed','completed')
              GROUP BY b.id, b.total_amount
             HAVING SUM(p.amount) > b.total_amount + 1
              LIMIT 200",
            ['s' => $since . ' 00:00:00']
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' booking(s) have more verified payment than the ticket price',
            'detail'   => 'Usually a payment verified twice, or a ticket made cheaper after it was paid. The passenger may be owed money back.',
            'fix'      => "1. Open each booking below > Payments.\n2. Reject the duplicate payment, or record the refund of the difference.",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows),
        ];
    }

    /**
     * Confirmed, not pay-on-boarding, and no verified money behind it.
     *
     * Every confirming path writes or verifies a payment row first: a counter
     * sale inserts it 'verified' in the same transaction, an online booking
     * is confirmed BY verifying its payment. COD is excluded — its cash is
     * settled later by design.
     */
    public static function moneyConfirmedUnpaid(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT b.id
               FROM bookings b
              WHERE b.created_at >= :s
                AND b.status = 'confirmed' AND b.is_cod = 0 AND b.total_amount > 0
                AND NOT EXISTS (
                      SELECT 1 FROM payments p
                       WHERE p.booking_id = b.id AND p.status = 'verified'
                    )
              LIMIT 200",
            ['s' => $since . ' 00:00:00']
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' confirmed ticket(s) have no verified payment',
            'detail'   => 'These tickets are confirmed and are not pay-on-boarding, but no payment on them was ever verified. Either the money was never checked or the payment row is missing.',
            'fix'      => "1. Open each booking below > Payments.\n2. Verify the payment that was received, or mark the ticket pay-on-boarding if that is what was agreed.",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows),
        ];
    }

    /* =================================================================
     *  Agent wallet
     * ================================================================= */

    /**
     * Cancelled or rejected, and the agent still keeps the commission.
     * AgentWallet::voidFor() writes the negative 'commission_void' row on
     * every cancel/reject; it "never throws", which also means it can fail
     * quietly — this is where that would show.
     */
    public static function walletNotReversed(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT b.id
               FROM bookings b
               JOIN agent_ledger c ON c.booking_id = b.id AND c.entry_type = 'commission' AND c.amount > 0
              WHERE b.status IN ('cancelled','rejected')
                AND COALESCE(b.cancelled_at, b.updated_at) >= :s
                AND NOT EXISTS (
                      SELECT 1 FROM agent_ledger v
                       WHERE v.booking_id = b.id AND v.entry_type = 'commission_void'
                    )
              LIMIT 200",
            ['s' => $since . ' 00:00:00']
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' cancelled ticket(s) still pay commission to the agent',
            'detail'   => 'The booking is cancelled or rejected but the commission on it was never taken back, so the agent statement shows more than is owed.',
            'fix'      => "1. Open Admin > Agents > the agent's statement and find each booking below.\n2. Post an Adjustment for the commission amount with the PNR as the reference.",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows),
        ];
    }

    /**
     * Confirmed, but its commission is still reversed. AgentWallet::accrue()
     * deletes the void row on a re-approve; if it is still there the agent is
     * unpaid for a ticket that travelled.
     */
    public static function walletVoidedButLive(string $since, string $today): ?array
    {
        $rows = Database::fetchAll(
            "SELECT b.id
               FROM bookings b
               JOIN agent_ledger v ON v.booking_id = b.id AND v.entry_type = 'commission_void'
              WHERE b.status IN ('confirmed','completed') AND b.created_at >= :s
              LIMIT 200",
            ['s' => $since . ' 00:00:00']
        );
        if ($rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => count($rows) . ' confirmed ticket(s) have their agent commission reversed',
            'detail'   => 'The booking is confirmed again but the reversal of its commission was never removed, so the agent is not being paid for it.',
            'fix'      => "1. Open each booking below and check it really is confirmed.\n2. Post an Adjustment on the agent's statement for the commission amount, PNR as the reference.",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows),
        ];
    }

    /* =================================================================
     *  Holds
     * ================================================================= */

    /**
     * Things cron/expire.php should have swept an hour ago. The heartbeat
     * already says when expire.php STOPS; this says when it runs and misses.
     * The pending-booking rule is copied from expire.php on purpose — a paid
     * passenger waiting for verification is not "unswept".
     */
    public static function holdUnswept(string $since, string $today): ?array
    {
        $cut = date('Y-m-d H:i:s', strtotime('-60 minutes'));

        $locks = (int) Database::scalar('SELECT COUNT(*) FROM seat_locks WHERE expires_at < :c', ['c' => $cut], 0);
        $rows  = Database::fetchAll(
            "SELECT b.id FROM bookings b
              WHERE b.status = 'pending' AND b.expires_at IS NOT NULL AND b.expires_at < :c
                AND NOT EXISTS (
                      SELECT 1 FROM payments p
                       WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number, '')) <> ''
                    )
                AND NOT EXISTS (SELECT 1 FROM payment_screenshots s WHERE s.booking_id = b.id)
              LIMIT 200",
            ['c' => $cut]
        );
        if ($locks === 0 && $rows === []) {
            return null;
        }

        return [
            'severity' => Health::WARN,
            'title'    => 'Unpaid holds are not being released (' . count($rows) . ' booking(s), ' . $locks . ' seat lock(s))',
            'detail'   => 'These holds ran out more than an hour ago and are still blocking seats. The clean-up job is running but not clearing them.',
            'fix'      => "Run the job by hand on the VPS and read the error:\n/usr/bin/php /var/www/shreehariglobal.in/public_html/cron/expire.php",
            'ids'      => self::sample(array_column($rows, 'id')),
            'count'    => count($rows) + $locks,
        ];
    }

    /* ================================================================= */

    /**
     * @param array<int, int|string> $ids
     * @return list<int>
     */
    private static function sample(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return array_slice($ids, 0, self::SAMPLE);
    }
}
