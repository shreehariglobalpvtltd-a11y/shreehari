<?php
/**
 * =====================================================================
 *  Seat engine
 *
 *  Generates the seat map, resolves schedules, and owns the temporary
 *  seat-hold mechanism used while a visitor is at checkout.
 *
 *  Availability is decided here and nowhere else. The browser is told
 *  which seats are free, but it is never trusted about it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Seats
{
    /**
     * Seat identifiers for a coach — the port of seatIdsFor().
     *
     *   sleeper + sharing  -> L1..L20, U1..U20   (40 berths)
     *   sleeper + private  -> L1..L15, U1..U15   (30 berths, wider cabins)
     *   seater             -> 1A..10D            (40 seats)
     *
     * @return array<int, string>
     */
    public static function seatIds(string $coachType, string $bookingType = 'sharing'): array
    {
        $ids = [];

        if ($coachType === 'sleeper') {
            $perDeck = $bookingType === 'private' ? 15 : 20;

            foreach (['L', 'U'] as $deck) {
                for ($row = 1; $row <= $perDeck; $row++) {
                    $ids[] = $deck . $row;
                }
            }

            return $ids;
        }

        for ($row = 1; $row <= 10; $row++) {
            foreach (['A', 'B', 'C', 'D'] as $column) {
                $ids[] = $row . $column;
            }
        }

        return $ids;
    }

    /**
     * Seats marked "female preferred" for a coach type.
     *
     * @return array<int, string>
     */
    public static function femaleSeats(string $coachType): array
    {
        $map = Settings::getArray('female_seats', [
            'seater'  => ['1A', '1B', '2A', '2B'],
            'sleeper' => ['L1', 'L2', 'L3'],
        ]);

        $seats = $map[$coachType] ?? [];

        return is_array($seats) ? array_values(array_map('strval', $seats)) : [];
    }

    /**
     * Find, or lazily create, the schedule row for a route on a date.
     *
     * Schedules are materialised on first demand rather than pre-generated
     * for every future date, which keeps the table small on shared hosting.
     *
     * @return array<string, mixed>
     */
    public static function schedule(int $routeId, string $travelDate): array
    {
        $existing = Database::fetch(
            'SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );

        if ($existing !== null) {
            return $existing;
        }

        $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $routeId]);

        if ($route === null) {
            throw new RuntimeException('Unknown route.');
        }

        $totalSeats = count(self::seatIds((string) $route['coach_type'], 'sharing'));

        // insertIgnore absorbs the race where two visitors open the same
        // date at once — the UNIQUE(route_id, travel_date) key decides.
        Database::insertIgnore('schedules', [
            'route_id'    => $routeId,
            'travel_date' => $travelDate,
            'bus_id'      => $route['bus_id'],
            'total_seats' => $totalSeats,
            'status'      => 'scheduled',
        ]);

        $schedule = Database::fetch(
            'SELECT * FROM schedules WHERE route_id = :r AND travel_date = :d LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );

        if ($schedule === null) {
            throw new RuntimeException('Schedule could not be created.');
        }

        return $schedule;
    }

    /**
     * Seats already sold on a schedule.
     *
     * @return array<int, string>
     */
    public static function bookedSeats(int $scheduleId): array
    {
        $rows = Database::fetchAll(
            'SELECT seat_no FROM booking_seats
              WHERE schedule_id = :s AND released_at IS NULL',
            ['s' => $scheduleId]
        );

        return array_map('strval', pluck($rows, 'seat_no'));
    }

    /**
     * Seats currently held by somebody at checkout.
     *
     * @param string $excludeToken this visitor's own token — their holds
     *                             should still look selectable to them
     * @return array<int, string>
     */
    public static function lockedSeats(int $scheduleId, string $excludeToken = ''): array
    {
        $sql = 'SELECT seat_no FROM seat_locks
                 WHERE schedule_id = :s AND expires_at > NOW()';
        $params = ['s' => $scheduleId];

        if ($excludeToken !== '') {
            $sql .= ' AND lock_token <> :t';
            $params['t'] = $excludeToken;
        }

        return array_map('strval', pluck(Database::fetchAll($sql, $params), 'seat_no'));
    }

    /**
     * Full availability picture for the seat map UI.
     *
     * @return array{
     *   scheduleId: int, total: int, all: array<int, string>,
     *   booked: array<int, string>, locked: array<int, string>,
     *   available: array<int, string>, female: array<int, string>,
     *   availableCount: int
     * }
     */
    public static function availability(int $routeId, string $travelDate, string $bookingType = 'sharing'): array
    {
        $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $routeId]);

        if ($route === null) {
            throw new RuntimeException('Unknown route.');
        }

        self::expireLocks();

        $schedule  = self::schedule($routeId, $travelDate);
        $token     = Auth::lockToken();
        $coachType = (string) $route['coach_type'];

        $all    = self::seatIds($coachType, $bookingType);
        $booked = self::bookedSeats((int) $schedule['id']);
        $locked = self::lockedSeats((int) $schedule['id'], $token);

        $unavailable = array_unique(array_merge($booked, $locked));
        $available   = array_values(array_diff($all, $unavailable));

        return [
            'scheduleId'     => (int) $schedule['id'],
            'total'          => count($all),
            'all'            => $all,
            'booked'         => array_values($booked),
            'locked'         => array_values($locked),
            'available'      => $available,
            'female'         => self::femaleSeats($coachType),
            'availableCount' => count($available),
        ];
    }

    /**
     * Hold seats for this visitor.
     *
     * Returns which seats were secured and which were taken in the
     * meantime, so the UI can show an honest message rather than
     * failing the whole selection.
     *
     * @param array<int, string> $seats
     * @return array{ok: bool, locked: array<int, string>, failed: array<int, string>, expiresAt: string}
     */
    public static function lock(int $scheduleId, array $seats, ?string $token = null): array
    {
        $token ??= Auth::lockToken();

        self::expireLocks();

        $holdMinutes = Settings::getInt('seat_hold_minutes', SEAT_HOLD_MINUTES);
        $expiresAt   = date('Y-m-d H:i:s', time() + ($holdMinutes * 60));

        $lockedNow = [];
        $failed    = [];

        $sold = self::bookedSeats($scheduleId);

        foreach ($seats as $seat) {
            $seat = strtoupper(Security::clean($seat, 10));

            if ($seat === '' || !Security::isValidSeat($seat)) {
                continue;
            }

            if (in_array($seat, $sold, true)) {
                $failed[] = $seat;
                continue;
            }

            // Refresh our own hold, or take a free one. The UNIQUE key on
            // (schedule_id, seat_no) means only one visitor can win.
            $affected = Database::query(
                'INSERT INTO seat_locks (schedule_id, seat_no, lock_token, expires_at)
                      VALUES (:s, :seat, :t, :exp)
                 ON DUPLICATE KEY UPDATE
                      lock_token = IF(expires_at < NOW() OR lock_token = VALUES(lock_token),
                                      VALUES(lock_token), lock_token),
                      expires_at = IF(lock_token = VALUES(lock_token),
                                      VALUES(expires_at), expires_at)',
                ['s' => $scheduleId, 'seat' => $seat, 't' => $token, 'exp' => $expiresAt]
            )->rowCount();

            // Confirm we actually own it now.
            $owner = Database::scalar(
                'SELECT lock_token FROM seat_locks
                  WHERE schedule_id = :s AND seat_no = :seat AND expires_at > NOW()
                  LIMIT 1',
                ['s' => $scheduleId, 'seat' => $seat]
            );

            if ($owner === $token) {
                $lockedNow[] = $seat;
            } else {
                $failed[] = $seat;
            }

            unset($affected);
        }

        return [
            'ok'        => $failed === [],
            'locked'    => $lockedNow,
            'failed'    => $failed,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Release specific holds owned by this visitor.
     *
     * @param array<int, string> $seats
     */
    public static function release(int $scheduleId, array $seats, ?string $token = null): int
    {
        $token ??= Auth::lockToken();

        $clean = [];
        foreach ($seats as $seat) {
            $seat = strtoupper(Security::clean($seat, 10));
            if ($seat !== '' && Security::isValidSeat($seat)) {
                $clean[] = $seat;
            }
        }

        if ($clean === []) {
            return 0;
        }

        $placeholders = [];
        $params       = ['s' => $scheduleId, 't' => $token];

        foreach ($clean as $index => $seat) {
            $key                = 'seat' . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $seat;
        }

        return Database::delete(
            'seat_locks',
            'schedule_id = :s AND lock_token = :t AND seat_no IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    /**
     * Drop every hold belonging to this visitor — called after checkout
     * completes or is abandoned.
     */
    public static function releaseAll(?string $token = null): int
    {
        $token ??= Auth::lockToken();

        return Database::delete('seat_locks', 'lock_token = :t', ['t' => $token]);
    }

    /**
     * Remove expired holds. Cheap, and safe to call on every request.
     */
    public static function expireLocks(): int
    {
        return Database::delete('seat_locks', 'expires_at < NOW()');
    }

    /**
     * Final pre-write check inside the booking transaction.
     *
     * Throws when any requested seat is unavailable, which rolls the
     * whole booking back rather than half-selling it.
     *
     * @param array<int, string> $seats
     * @throws RuntimeException
     */
    public static function assertAvailable(int $scheduleId, array $seats, string $token): void
    {
        if ($seats === []) {
            throw new RuntimeException('No seats were selected.');
        }

        $sold  = self::bookedSeats($scheduleId);
        $clash = array_values(array_intersect($seats, $sold));

        if ($clash !== []) {
            throw new RuntimeException(
                'These seats have just been taken: ' . implode(', ', $clash) . '. Please choose again.'
            );
        }

        // Anything held by somebody else is equally off limits.
        $heldByOthers = self::lockedSeats($scheduleId, $token);
        $conflict     = array_values(array_intersect($seats, $heldByOthers));

        if ($conflict !== []) {
            throw new RuntimeException(
                'Someone is currently booking these seats: ' . implode(', ', $conflict) . '. Please choose again.'
            );
        }
    }

    /**
     * Permanently claim seats as part of a confirmed booking.
     *
     * Relies on UNIQUE(schedule_id, seat_no) to reject a concurrent
     * double sale at the database level.
     *
     * @param array<int, string> $seats
     * @throws RuntimeException when a seat was claimed by someone else first
     */
    public static function claim(int $scheduleId, array $seats, int $bookingId, int $legId): void
    {
        foreach ($seats as $seat) {
            $inserted = Database::insertIgnore('booking_seats', [
                'schedule_id' => $scheduleId,
                'seat_no'     => $seat,
                'booking_id'  => $bookingId,
                'leg_id'      => $legId,
            ]);

            if ($inserted === 0) {
                // The row already existed — check whether it is ours or a
                // genuine collision with another booking.
                $ownerBooking = Database::scalar(
                    'SELECT booking_id FROM booking_seats
                      WHERE schedule_id = :s AND seat_no = :seat LIMIT 1',
                    ['s' => $scheduleId, 'seat' => $seat]
                );

                if ((int) $ownerBooking !== $bookingId) {
                    throw new RuntimeException(
                        'Seat ' . $seat . ' was booked by someone else moments ago. Please pick another seat.'
                    );
                }
            }
        }

        Database::query(
            'UPDATE schedules
                SET seats_booked = (
                    SELECT COUNT(*) FROM booking_seats
                     WHERE schedule_id = :s1 AND released_at IS NULL
                )
              WHERE id = :s2',
            ['s1' => $scheduleId, 's2' => $scheduleId]
        );
    }

    /**
     * Return seats to the pool when a booking is cancelled or rejected.
     */
    public static function releaseBooking(int $bookingId): int
    {
        $scheduleIds = pluck(
            Database::fetchAll(
                'SELECT DISTINCT schedule_id FROM booking_seats WHERE booking_id = :b',
                ['b' => $bookingId]
            ),
            'schedule_id'
        );

        // Delete rather than soft-release so the seat becomes immediately
        // sellable again and the UNIQUE key stops blocking it.
        $removed = Database::delete('booking_seats', 'booking_id = :b', ['b' => $bookingId]);

        foreach ($scheduleIds as $scheduleId) {
            Database::query(
                'UPDATE schedules
                    SET seats_booked = (
                        SELECT COUNT(*) FROM booking_seats
                         WHERE schedule_id = :s1 AND released_at IS NULL
                    )
                  WHERE id = :s2',
                ['s1' => $scheduleId, 's2' => $scheduleId]
            );
        }

        return $removed;
    }

    /**
     * Human label for a seat: "Lower berth 4" / "Seat 3C".
     */
    public static function label(string $seatNo): string
    {
        if (preg_match('/^([LU])(\d+)$/i', $seatNo, $match) === 1) {
            $deck = strtoupper($match[1]) === 'L' ? 'Lower' : 'Upper';
            return $deck . ' berth ' . $match[2];
        }

        return 'Seat ' . strtoupper($seatNo);
    }
}
