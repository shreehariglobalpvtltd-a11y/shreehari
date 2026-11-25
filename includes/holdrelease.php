<?php
/**
 * includes/holdrelease.php — every unpaid HOLD frees itself (op5, 25 Nov 2026).
 *
 * Owner: "a seat that nobody paid for must come back by itself, 15-30 min".
 *
 * ONE owner window, seat_hold_minutes (default 20, allowed 15-30), used by
 * every hold that is NOT a sale:
 *
 *   - the checkout seat lock (seat_locks, public seat picker)  -> Seats::lock()
 *   - a web / app / WhatsApp booking left unpaid (pending)    -> BookingService::create()
 *   - the SmartBook Online (QR) hold is never LONGER than it  -> SmartBook
 *
 * Never touched, by design (a person decides those):
 *   - confirmed sales, pay-at-boarding / COD (confirmed, expires_at NULL)
 *   - a pending booking whose customer already sent a UTR or a screenshot
 *     (BookingService::holdForAdminReview cleared its clock; the sweep's
 *     NOT EXISTS pair checks again) — shown as "proof waiting", not freed
 *   - staff / agent / counter sales (confirmed at once)
 *   - office seat blocks (seat_blocks: a manual tool, no clock)
 *
 * The sweep is the one cron/expire.php always ran (same SQL, same guarded
 * UPDATE, same Seats::releaseBooking + BookingService::releaseCoupon +
 * booking.expired journal), moved here so a test can call it, plus one
 * Logger::audit row per release and an optional short WhatsApp notice.
 *
 * Switches (read inline, never in the boot list):
 *   seat_hold_autorelease_on  default true   - the one window above; off = the
 *                                             old numbers (lock SEAT_HOLD_MINUTES /
 *                                             seat_hold_minutes, pending 120)
 *   hold_expiry_notice_on     default false  - "your hold expired" WhatsApp, only
 *                                             inside the free 24 h window (no
 *                                             template: 18-19 Nov templates fail
 *                                             with 132001)
 */
declare(strict_types=1);

final class HoldRelease
{
    public const MIN_MIN     = 15;
    public const MAX_MIN     = 30;
    public const DEFAULT_MIN = 20;

    /** Is the one owner window in force? */
    public static function on(): bool
    {
        return Settings::getBool('seat_hold_autorelease_on', true);
    }

    /** The owner's window, always 15-30 (default 20). */
    public static function minutes(): int
    {
        $m = Settings::getInt('seat_hold_minutes', self::DEFAULT_MIN);
        return max(self::MIN_MIN, min(self::MAX_MIN, $m));
    }

    /** Checkout seat-lock minutes. Switch off = exactly the old read. */
    public static function lockMinutes(): int
    {
        if (!self::on()) {
            return Settings::getInt('seat_hold_minutes', defined('SEAT_HOLD_MINUTES') ? (int) SEAT_HOLD_MINUTES : 30);
        }
        return self::minutes();
    }

    /**
     * Unpaid pending-booking minutes. Never longer than the owner's window
     * while the switch is on; a shorter booking_expiry_minutes still wins.
     */
    public static function pendingMinutes(): int
    {
        $old = max(1, Settings::getInt('booking_expiry_minutes', 120));
        return self::on() ? min($old, self::minutes()) : $old;
    }

    /** SmartBook Online hold: its own setting, capped by the owner's window. */
    public static function smartbookMinutes(): int
    {
        $sb = max(5, Settings::getInt('smartbook_online_hold_min', 15));
        return self::on() ? min($sb, self::minutes()) : $sb;
    }

    /**
     * Drop expired checkout seat locks, one audit row per bus (cron only:
     * Seats::lock() also sweeps them quietly on every request — an expired
     * lock never blocks anyone, every reader filters expires_at > NOW()).
     */
    public static function sweepLocks(): int
    {
        $rows = [];
        if (self::on()) {
            try {
                $rows = Database::fetchAll(
                    "SELECT schedule_id, GROUP_CONCAT(seat_no ORDER BY seat_no SEPARATOR ' ') AS seats, COUNT(*) AS n
                       FROM seat_locks WHERE expires_at < NOW() GROUP BY schedule_id LIMIT 200"
                );
            } catch (Throwable $e) {
                $rows = [];
            }
        }
        $freed = Seats::expireLocks();
        if ($freed > 0) {
            foreach ($rows as $r) {
                Logger::audit('seat.hold_released', 'schedule', (string) $r['schedule_id'],
                    ['held' => (string) $r['seats']], ['held' => ''],
                    'Checkout seat hold elapsed - ' . (int) $r['n'] . ' seat(s) free again (auto)');
            }
        }
        return $freed;
    }

    /**
     * Expire unpaid pending bookings whose window has elapsed and free their
     * seats. Idempotent: a second run finds nothing (the guarded UPDATE only
     * matches status = 'pending').
     *
     * @param array<int,int> $onlyIds limit the sweep to these booking ids (tests)
     * @return array{expired:int, pnrs:array<int,string>, noticed:int}
     */
    public static function expireStale(int $limit = 500, array $onlyIds = []): array
    {
        $limit = max(1, min(2000, $limit));
        $ids   = array_values(array_filter(array_map('intval', $onlyIds), static fn(int $i): bool => $i > 0));
        $extra = $ids !== [] ? ' AND b.id IN (' . implode(',', $ids) . ')' : '';

        /* Never sweep a booking the customer has already paid for. BookingService
           clears expires_at the moment proof arrives, so this NOT EXISTS pair is
           belt-and-braces -- it also protects rows whose clock was set before that
           fix existed, and any future path that forgets to stop the clock. A paid
           passenger losing their seat to a cron job is not a recoverable error. */
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
                    )" . $extra . "
              LIMIT " . $limit
        );

        $out = ['expired' => 0, 'pnrs' => [], 'noticed' => 0];
        foreach ($stale as $b) {
            try {
                $seatList = '';
                // The SELECT ran outside any transaction, so a row can be approved
                // (pending->confirmed) or have proof land (expires_at NULLed) in the
                // gap before we reach it. Re-assert the FULL expirable condition in
                // the guarded UPDATE and release seats ONLY when it actually matched
                // one row — otherwise we would delete the seats out from under a
                // just-confirmed, paid passenger. rowCount is the authority here, not
                // the stale SELECT.
                $didExpire = Database::transaction(static function () use ($b, &$seatList): bool {
                    $seatList = (string) Database::scalar(
                        "SELECT GROUP_CONCAT(seat_no ORDER BY seat_no SEPARATOR ' ') FROM booking_seats WHERE booking_id = :b",
                        ['b' => (int) $b['id']], ''
                    );
                    $hit = Database::update('bookings',
                        ['status' => 'expired', 'cancel_reason' => 'Payment window elapsed'],
                        'id = :id AND status = :s AND expires_at IS NOT NULL AND expires_at < NOW()',
                        ['id' => (int) $b['id'], 's' => 'pending']
                    );
                    if ($hit < 1) {
                        return false;                                // no longer expirable — leave it alone
                    }
                    Seats::releaseBooking((int) $b['id']);          // free the seats (counts + cabin locks)
                    BookingService::releaseCoupon((int) $b['id']);  // an unpaid booking gives its offer use back
                    return true;
                });
                if (!$didExpire) {
                    continue;
                }
                $out['expired']++;
                $out['pnrs'][] = (string) $b['pnr'];
                // Post-commit only — never inside the closure, or a rolled-back
                // expiry would still have "happened" in the log.
                EventBus::emit('booking.expired', ['booking' => ['id' => (int) $b['id'], 'pnr' => (string) $b['pnr']]]);
                if (self::on()) {
                    Logger::audit('booking.hold_expired', 'booking', (string) $b['pnr'],
                        ['status' => 'pending', 'seats' => $seatList], ['status' => 'expired', 'seats' => ''],
                        'Unpaid hold elapsed - seats free again (auto)');
                }
                if (self::notice((int) $b['id'])) {
                    $out['noticed']++;
                }
            } catch (Throwable $e) {
                Logger::error('expire booking failed', ['pnr' => $b['pnr'], 'err' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * Optional "your hold expired" WhatsApp (hold_expiry_notice_on, default
     * off). Free-form ONLY while the customer's 24 h window is open — no
     * template, so the 132001 template failures cannot touch it. Never throws.
     */
    public static function notice(int $bookingId): bool
    {
        if (!Settings::getBool('hold_expiry_notice_on', false)) {
            return false;
        }
        try {
            require_once __DIR__ . '/notify.php';
            require_once __DIR__ . '/wasend.php';
            $b = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bookingId]);
            if ($b === null || Notify::usablePhone($b['contact_phone'] ?? '') === '') {
                return false;
            }
            $hint = Notify::bookingCountryHint($b);
            $intl = WaSend::intl((string) $b['contact_phone'], (string) ($hint ?? ''));
            if (!WaSend::windowOpen($intl)) {
                return false;                                   // outside 24 h: no template, no message
            }
            $text = "🚌 " . Settings::getString('company_name', defined('APP_NAME') ? (string) APP_NAME : 'S Hari') . "\n"
                  . 'बुकिङ ' . (string) $b['pnr'] . ': भुक्तानी नआएकाले सिट होल्ड सकियो, सिट फेरि खुला छ।' . "\n"
                  . 'फेरि बुक गर्न "hi" लेख्नुहोस्। / Your unpaid hold expired; reply "hi" to book again.';
            $r = Notify::whatsapp((string) $b['contact_phone'], $text, null, $hint, [], (int) $b['id'], ['purpose' => 'hold_expired']);
            return $r === true;
        } catch (Throwable $e) {
            Logger::error('hold expiry notice failed', ['booking' => $bookingId, 'err' => $e->getMessage()]);
            return false;
        }
    }

    /** Whole minutes left (rounded up), or null when no clock / already over. */
    public static function minutesLeft(mixed $secondsLeft): ?int
    {
        if ($secondsLeft === null || $secondsLeft === '') {
            return null;
        }
        $s = (int) $secondsLeft;
        return $s > 0 ? (int) ceil($s / 60) : null;
    }

    /**
     * What is held right now, for the office (read-only).
     *
     * @return array{locks:array<int,array<string,mixed>>, pending:array<int,array<string,mixed>>, proof:array<int,array<string,mixed>>}
     */
    public static function heldNow(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $out = ['locks' => [], 'pending' => [], 'proof' => []];
        try {
            $out['locks'] = Database::fetchAll(
                "SELECT sl.schedule_id, s.travel_date, r.from_city, r.to_city,
                        GROUP_CONCAT(sl.seat_no ORDER BY sl.seat_no SEPARATOR ' ') AS seats,
                        MIN(TIMESTAMPDIFF(SECOND, NOW(), sl.expires_at)) AS secs
                   FROM seat_locks sl
                   JOIN schedules s ON s.id = sl.schedule_id
                   JOIN routes r ON r.id = s.route_id
                  WHERE sl.expires_at > NOW()
                  GROUP BY sl.schedule_id, sl.lock_token, s.travel_date, r.from_city, r.to_city
                  ORDER BY secs LIMIT " . $limit
            );
            $out['pending'] = Database::fetchAll(
                "SELECT b.pnr, b.contact_phone, b.total_amount, TIMESTAMPDIFF(SECOND, NOW(), b.expires_at) AS secs,
                        (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ') FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats
                   FROM bookings b
                  WHERE b.status = 'pending' AND b.expires_at IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number, '')) <> '')
                    AND NOT EXISTS (SELECT 1 FROM payment_screenshots ps WHERE ps.booking_id = b.id)
                  ORDER BY b.expires_at LIMIT " . $limit
            );
            $out['proof'] = Database::fetchAll(
                "SELECT b.pnr, b.contact_phone, b.total_amount, b.created_at,
                        (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ') FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats
                   FROM bookings b
                  WHERE b.status = 'pending'
                    AND (b.expires_at IS NULL
                         OR EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number, '')) <> '')
                         OR EXISTS (SELECT 1 FROM payment_screenshots ps WHERE ps.booking_id = b.id))
                  ORDER BY b.id DESC LIMIT " . $limit
            );
        } catch (Throwable $e) {
            Logger::error('heldNow failed', ['err' => $e->getMessage()]);
        }
        return $out;
    }
}
