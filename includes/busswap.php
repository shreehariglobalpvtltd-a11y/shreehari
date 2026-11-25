<?php
/**
 * includes/busswap.php — "बस साट्नुहोस् · Swap bus" in one click (op5, 25 Nov 2026).
 *
 * The four places that could change the coach under a trip (Date View,
 * Bus Calendar, Fleet "run on date", Bus Control) wrote schedules.bus_id and
 * stopped: no passenger was told, every ticket PNG / PDF / QR they already
 * held kept the OLD bus number, live tracking stayed on the old coach, and
 * Bus Control did not even run the capacity guard. BusSwap::swap() is now the
 * one door (behind bus_swap_v2_on, default 1):
 *
 *   preview(sid, bus)  read-only: trip, old / new coach, sold beds vs the new
 *                      bus's seats, every passenger, and - when the new bus
 *                      is smaller - WHO would have no seat (the latest
 *                      bookings) so the office moves them first. Never writes.
 *   swap(...)          refuses cancelled / arrived trips, the bus already on
 *                      another trip that date, and everything
 *                      Seats::assertBusSwapSafe() refuses (coach type,
 *                      capacity, inactive bus). A DEPARTED trip (breakdown on
 *                      the road) is allowed; booking rows, seats and the
 *                      boarded / checked-in marks are never touched - only
 *                      schedules.bus_id + total_seats (one transaction, row
 *                      locked) and live_trips.bus_id so the GPS follows the
 *                      new coach. Audited trip.swap_bus with the reason.
 *   after the commit   every booking's ticket is re-minted through
 *                      TicketSync 'trip_change' (new QR, old PNG/PDF gone,
 *                      REV +1); the chalani PNG names its file after a
 *                      fingerprint that includes bus_number, so it redraws by
 *                      itself. Each CONFIRMED passenger is told ONCE
 *                      (EventBus::claim 'trip:bus_changed:<b>:<s>:<bus>:<seq>')
 *                      through Notify::ticketChanged() - the existing
 *                      ticket_update path with its WaRoute rescue onto the old
 *                      working template - and the trip's driver / crew
 *                      (DriverList::crewFor) gets the new number + the list.
 *                      A send that did not go releases its claim, so pressing
 *                      the swap again for the same bus resends only those -
 *                      and only to the bookings that were CONFIRMED on the trip
 *                      at swap time (their ids ride in the audit row), never to
 *                      tickets sold on the new bus afterwards. A swap nobody
 *                      could see (pinning the rotation's own bus) has nothing
 *                      to resend. The crew notice is for the trip that is close
 *                      (today / tomorrow, on the road) or the explicit Swap
 *                      card; a re-plan weeks ahead on the Bus Calendar / Fleet
 *                      leaves the crew to the usual driver list (DriverList cron).
 *
 * Switches (read inline): bus_swap_v2_on (1), bus_swap_notify_on (1); the
 * passenger notice also honours notify_ticket_edit / wa_ticket_rescue_on.
 * Never writes bookings.status, never marks anything paid.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/ticketsync.php';

final class BusSwap
{
    /** Test seam: (bookingId, pnr, ctx) -> ['ok'=>bool,'detail'=>string] instead of Notify::ticketChanged(). */
    public static ?Closure $sender = null;

    /** Test seam: (phone, text, hint) -> bool instead of Notify::whatsapp() for the crew. */
    public static ?Closure $crewTransport = null;

    public static function on(): bool
    {
        return Settings::getBool('bus_swap_v2_on', true);
    }

    public static function notifyOn(): bool
    {
        return Settings::getBool('bus_swap_notify_on', true);
    }

    /** The trip with its effective coach and current bus. @return array<string,mixed>|null */
    public static function trip(int $sid): ?array
    {
        if ($sid <= 0) {
            return null;
        }
        return Database::fetch(
            "SELECT s.id, s.route_id, s.travel_date, s.status, s.bus_id AS sched_bus_id, s.total_seats, s.driver_id,
                    COALESCE(s.bus_id, r.bus_id) AS eff_bus_id,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
                    r.route_code, r.from_city, r.to_city
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :s",
            ['s' => $sid]
        );
    }

    /** @return array<string,mixed>|null */
    public static function bus(int $busId): ?array
    {
        if ($busId <= 0) {
            return null;
        }
        return Database::fetch(
            'SELECT id, bus_number, bus_name, coach_type, total_seats, is_active FROM buses WHERE id = :i',
            ['i' => $busId]
        );
    }

    /** What the passenger reads as the bus: the public number, else the name, else '—'. */
    public static function label(?array $bus): string
    {
        if ($bus === null) {
            return '—';
        }
        $no = Fleet::publicNumber((string) ($bus['bus_number'] ?? ''));
        if ($no !== '') {
            return $no;
        }
        $name = trim((string) ($bus['bus_name'] ?? ''));
        return $name !== '' ? $name : '—';
    }

    /**
     * Every live booking on the trip, one row per booking leg on this schedule.
     *
     * @return list<array<string,mixed>>
     */
    public static function bookings(int $sid): array
    {
        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, b.contact_phone, b.booking_mode, b.created_at,
                    bl.id AS leg_id, bl.boarding_time, bl.boarding_stop,
                    (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ',')
                       FROM booking_seats bs
                      WHERE bs.booking_id = b.id AND bs.schedule_id = bl.schedule_id AND bs.released_at IS NULL) AS seats,
                    (SELECT COUNT(*) FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND (bp.leg_id = bl.id OR bp.leg_id IS NULL)) AS pax,
                    (SELECT COUNT(*) FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND (bp.leg_id = bl.id OR bp.leg_id IS NULL) AND bp.boarded_at IS NOT NULL) AS boarded,
                    (SELECT bp.full_name FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND (bp.leg_id = bl.id OR bp.leg_id IS NULL)
                      ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS name
               FROM booking_legs bl
               JOIN bookings b ON b.id = bl.booking_id
              WHERE bl.schedule_id = :s AND b.status IN ('confirmed','pending','completed')
              ORDER BY b.created_at, b.id",
            ['s' => $sid]
        );
        return array_values($rows);
    }

    /** Physical beds a booking holds on this trip (a private cabin is 2). */
    private static function beds(array $row, string $coach): int
    {
        $n = [];
        foreach (array_filter(explode(',', (string) ($row['seats'] ?? ''))) as $seat) {
            foreach (Seats::physicalSeats(trim($seat), (string) (($row['booking_mode'] ?? '') ?: 'sharing'), $coach) as $bed) {
                $n[$bed] = true;
            }
        }
        return count($n);
    }

    /** Another live trip that date already runs this bus? @return array<string,mixed>|null */
    private static function clash(int $sid, int $busId, string $date): ?array
    {
        return Database::fetch(
            "SELECT s.id, r.from_city, r.to_city FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.bus_id = :b AND s.travel_date = :d AND s.status <> 'cancelled' AND s.id <> :s LIMIT 1",
            ['b' => $busId, 'd' => $date, 's' => $sid]
        );
    }

    /**
     * The latest swap of this trip from the audit trail (seq, from, to, labels).
     *
     * 'visible' = the passenger saw a different bus number; 'bids' = the
     * bookings confirmed on the trip at swap time (null on a row without the
     * list - such a swap is never resumed).
     *
     * @return array{count:int, to:int, from:int, seq:int, v2:bool, fromLabel:string, toLabel:string, visible:bool, bids:?list<int>}
     */
    public static function lastSwap(int $sid): array
    {
        $out = ['count' => 0, 'to' => 0, 'from' => 0, 'seq' => 0, 'v2' => false, 'fromLabel' => '', 'toLabel' => '',
                'visible' => false, 'bids' => null];
        try {
            $out['count'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'trip.swap_bus' AND entity_type = 'schedule' AND entity_id = :s",
                ['s' => (string) $sid], 0
            );
            $row = Database::fetch(
                "SELECT old_value, new_value FROM audit_logs
                  WHERE action = 'trip.swap_bus' AND entity_type = 'schedule' AND entity_id = :s
                  ORDER BY id DESC LIMIT 1",
                ['s' => (string) $sid]
            );
        } catch (Throwable $e) {
            return $out;
        }
        if ($row !== null) {
            $old = json_decode((string) ($row['old_value'] ?? ''), true);
            $new = json_decode((string) ($row['new_value'] ?? ''), true);
            $old = is_array($old) ? $old : [];
            $new = is_array($new) ? $new : [];
            $out['to']        = (int) ($new['bus_id'] ?? 0);
            $out['from']      = (int) ($old['bus_id'] ?? 0);
            $out['seq']       = (int) ($new['seq'] ?? 0);
            $out['v2']        = !empty($new['v2']);
            $out['fromLabel'] = (string) ($old['label'] ?? '');
            $out['toLabel']   = (string) ($new['label'] ?? '');
            $oldEff = (int) (($old['effective_bus_id'] ?? 0) ?: ($old['bus_id'] ?? 0));
            $out['visible']   = array_key_exists('visible', $new) ? !empty($new['visible']) : ($oldEff !== $out['to']);
            if ($out['fromLabel'] !== '' && $out['fromLabel'] === $out['toLabel']) {
                $out['visible'] = false;
            }
            if (isset($new['bids']) && is_array($new['bids'])) {
                $out['bids'] = array_values(array_map('intval', $new['bids']));
            }
        }
        return $out;
    }

    /**
     * Read-only check of a swap. Never throws for a refusal - the reasons are
     * in 'errors' so the screen can show them next to the passenger list.
     *
     * @return array<string,mixed>
     */
    public static function preview(int $sid, int $newBusId): array
    {
        $trip = self::trip($sid);
        if ($trip === null) {
            return ['ok' => false, 'errors' => ['That trip no longer exists. · यो ट्रिप भेटिएन।'], 'trip' => null,
                    'oldBus' => null, 'newBus' => null, 'passengers' => [], 'noSeat' => [], 'beds' => 0, 'newSeats' => 0,
                    'overflow' => 0, 'pax' => 0, 'confirmed' => 0, 'boarded' => 0, 'resume' => false];
        }
        $coach  = (string) ($trip['coach_type'] ?? 'sleeper');
        $oldBus = self::bus((int) ($trip['eff_bus_id'] ?? 0));
        $newBus = self::bus($newBusId);
        $errors = [];
        $status = (string) ($trip['status'] ?? '');
        if ($status === 'cancelled') {
            $errors[] = 'This trip is cancelled. · यो ट्रिप रद्द भइसक्यो।';
        } elseif ($status === 'arrived') {
            $errors[] = 'This trip has already arrived. · यो ट्रिप पुगिसक्यो।';
        }
        $resume = false;
        if ($newBus === null) {
            $errors[] = 'Choose a bus. · बस छान्नुहोस्।';
        } elseif ((int) ($trip['sched_bus_id'] ?? 0) === $newBusId) {
            $last = self::lastSwap($sid);
            /* The swap is done; pressing again only resends what did not go -
               and only when the passengers saw a change (a pin of the
               rotation's own bus told nobody, so there is nothing to resend). */
            if ($last['v2'] && $last['to'] === $newBusId && $last['visible'] && $last['bids'] !== null) {
                $resume = true;
            } else {
                $errors[] = 'This trip already runs that bus. · यो ट्रिपमा यही बस छ।';
            }
        } else {
            $c = self::clash($sid, $newBusId, (string) $trip['travel_date']);
            if ($c !== null) {
                $errors[] = 'Bus ' . self::label($newBus) . ' already runs ' . $c['from_city'] . ' → ' . $c['to_city']
                    . ' that day. · यो बस त्यो दिन अर्को ट्रिपमा छ।';
            }
            try {
                Seats::assertBusSwapSafe($sid, $newBusId);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $passengers = [];
        $beds = 0; $pax = 0; $confirmed = 0; $boarded = 0;
        foreach (self::bookings($sid) as $r) {
            $b = self::beds($r, $coach);
            $beds += $b;
            $pax  += (int) $r['pax'];
            $boarded += (int) $r['boarded'];
            if ((string) $r['status'] === 'confirmed') {
                $confirmed++;
            }
            $seatList = array_values(array_filter(array_map('trim', explode(',', (string) ($r['seats'] ?? '')))));
            $passengers[] = [
                'bookingId' => (int) $r['id'],
                'pnr'       => (string) $r['pnr'],
                'name'      => (string) ($r['name'] ?? ''),
                'status'    => (string) $r['status'],
                'seats'     => Seats::displayLabels($seatList, $coach, (string) (($r['booking_mode'] ?? '') ?: 'sharing')),
                'beds'      => $b,
                'pax'       => (int) $r['pax'],
                'boarded'   => (int) $r['boarded'],
                'createdAt' => (string) ($r['created_at'] ?? ''),
            ];
        }
        $newSeats = $newBus !== null ? (int) ($newBus['total_seats'] ?? 0) : 0;
        $overflow = ($newSeats > 0 && !$resume) ? max(0, $beds - $newSeats) : 0;

        /* Who has no seat on the smaller bus: the latest bookings, until the
           beds fit. The office moves (Transfer) or cancels them first; the
           swap itself refuses while anyone would be left standing. */
        $noSeat = [];
        if ($overflow > 0) {
            $need = $overflow;
            foreach (array_reverse($passengers) as $p) {
                if ($need <= 0) {
                    break;
                }
                $noSeat[] = $p;
                $need -= max(1, (int) $p['beds']);
            }
        }

        return [
            'ok'         => $errors === [],
            'errors'     => $errors,
            'trip'       => $trip,
            'oldBus'     => $oldBus,
            'newBus'     => $newBus,
            'oldLabel'   => self::label($oldBus),
            'newLabel'   => self::label($newBus),
            'passengers' => $passengers,
            'noSeat'     => $noSeat,
            'beds'       => $beds,
            'newSeats'   => $newSeats,
            'overflow'   => $overflow,
            'pax'        => $pax,
            'confirmed'  => $confirmed,
            'boarded'    => $boarded,
            'resume'     => $resume,
        ];
    }

    /**
     * Swap the bus under a trip and tell everyone once.
     *
     * $opts: notify (bool, default true - and bus_swap_notify_on), crew (bool,
     * default true), via (string, which screen).
     *
     * @return array<string,mixed> moved, tickets, sent, failed, skipped, already, crew, failedList, message
     * @throws RuntimeException (user-safe) on any refusal
     */
    public static function swap(int $sid, int $newBusId, int $adminId, string $reason = '', array $opts = []): array
    {
        $reason = trim(mb_substr((string) preg_replace('/\s+/u', ' ', strip_tags($reason)), 0, 160));
        $via    = trim(mb_substr((string) ($opts['via'] ?? 'trip_dashboard'), 0, 40));
        $notify = (($opts['notify'] ?? true) !== false) && self::notifyOn();
        $crewOn = $notify && (($opts['crew'] ?? true) !== false);

        $pv = self::preview($sid, $newBusId);
        if (!$pv['ok']) {
            throw new RuntimeException(implode(' ', $pv['errors']));
        }
        $trip   = $pv['trip'];
        $resume = (bool) $pv['resume'];
        $last   = self::lastSwap($sid);

        $liveRepointed = 0;
        $onlyBids = null;
        if ($resume) {
            $seq      = max(1, $last['seq']);
            $oldLabel = $last['fromLabel'] !== '' ? $last['fromLabel'] : '—';
            $newLabel = $last['toLabel'] !== '' ? $last['toLabel'] : (string) $pv['newLabel'];
            $visible  = $last['visible'];   // preview only resumes a visible swap
            $onlyBids = $last['bids'] ?? [];
        } else {
            $seq      = $last['count'] + 1;
            $oldLabel = (string) $pv['oldLabel'];
            $newLabel = (string) $pv['newLabel'];
            $oldEff   = (int) ($trip['eff_bus_id'] ?? 0);
            $schedBus = (int) ($trip['sched_bus_id'] ?? 0);
            $visible  = $oldEff !== $newBusId;   // pinning the rotation's own bus changes nothing the passenger sees
            if ((string) $pv['oldLabel'] !== '—' && (string) $pv['oldLabel'] === (string) $pv['newLabel']) {
                $visible = false;                 // two coaches, one public number: nothing to tell
            }
            /* Who was on the trip at swap time: a re-press never reaches past them. */
            $bidsAtSwap = [];
            foreach ($pv['passengers'] as $p) {
                if ((string) $p['status'] === 'confirmed') {
                    $bidsAtSwap[(int) $p['bookingId']] = true;
                }
            }
            $bidsAtSwap = array_keys($bidsAtSwap);

            $check = Seats::assertBusSwapSafe($sid, $newBusId);
            $liveRepointed = (int) Database::transaction(static function () use ($sid, $newBusId, $check, $schedBus): int {
                $lock = Database::fetchForUpdate('SELECT id, bus_id, status FROM schedules WHERE id = :id', ['id' => $sid]);
                if ($lock === []) {
                    throw new RuntimeException('That trip no longer exists.');
                }
                if ((int) ($lock[0]['bus_id'] ?? 0) !== $schedBus) {
                    throw new RuntimeException('Someone changed this trip\'s bus just now — reload and check again.');
                }
                if (in_array((string) $lock[0]['status'], ['cancelled', 'arrived'], true)) {
                    throw new RuntimeException('This trip is ' . $lock[0]['status'] . ' — the bus cannot be swapped.');
                }
                $set = ['bus_id' => $newBusId];
                if ((int) $check['newTotalSeats'] > 0) {
                    $set['total_seats'] = (int) $check['newTotalSeats'];
                }
                Database::update('schedules', $set, 'id = :id', ['id' => $sid]);
                $n = 0;
                if (!class_exists('LiveTrips')) {
                    require_once __DIR__ . '/livetrips.php';
                }
                if (LiveTrips::available()) {
                    $n = Database::update('live_trips', ['bus_id' => $newBusId], 'trip_ref = :r', ['r' => LiveTrips::tripRef($sid)]);
                }
                return $n;
            });

            Logger::audit('trip.swap_bus', 'schedule', (string) $sid,
                ['bus_id' => $trip['sched_bus_id'] ?? null, 'effective_bus_id' => $oldEff ?: null,
                 'label' => $oldLabel, 'total_seats' => $trip['total_seats'] ?? null],
                ['bus_id' => $newBusId, 'label' => $newLabel, 'total_seats' => (int) $check['newTotalSeats'],
                 'bookedCount' => (int) $check['bookedCount'], 'via' => $via, 'v2' => 1, 'seq' => $seq,
                 'live_trip_repointed' => $liveRepointed, 'trip_status' => (string) $trip['status'],
                 'visible' => $visible ? 1 : 0, 'bids' => $bidsAtSwap],
                'Bus ' . $oldLabel . ' → ' . $newLabel . ' · ' . $trip['from_city'] . '→' . $trip['to_city'] . ' ' . $trip['travel_date']
                    . ' · ' . $pv['pax'] . ' passengers · via ' . $via . ' · by admin #' . $adminId,
                $reason);
        }

        /* The crew notice is for a trip that is close or on the road (the
           breakdown case) and for the explicit Swap card. Re-planning an
           empty or far-off date on the Bus Calendar / Fleet tells nobody in
           the cab - the driver list goes out the day before as usual. */
        $crewWhy = '';
        if ($crewOn && $visible && $via !== 'trip_dashboard') {
            $soon = (string) $trip['travel_date'] <= date('Y-m-d', strtotime('+1 day'))
                 || in_array((string) $trip['status'], ['departed', 'running', 'boarding'], true);
            if (!$soon || (int) $pv['pax'] === 0) {
                $crewOn  = false;
                $crewWhy = (int) $pv['pax'] === 0 ? 'not told (no passengers)' : 'not told yet (driver list goes the day before)';
            }
        }

        $fan = self::fanOut($sid, $newBusId, $trip, $oldLabel, $newLabel, $seq, $reason, $adminId,
            $notify && $visible, $crewOn && $visible, $resume, $onlyBids);
        if ($fan['crew'] === '' && $crewWhy !== '') {
            $fan['crew'] = $crewWhy;
        }

        $fan['moved']         = $resume ? 0 : (int) $pv['pax'];
        $fan['passengers']    = (int) $pv['pax'];
        $fan['resume']        = $resume;
        $fan['liveRepointed'] = $liveRepointed;
        $fan['oldLabel']      = $oldLabel;
        $fan['newLabel']      = $newLabel;
        $fan['seq']           = $seq;
        $fan['message'] = ($resume
                ? 'Bus already ' . $newLabel . ' — only the messages that had not gone were tried again. '
                : 'Bus swapped ' . $oldLabel . ' → ' . $newLabel . '. ' . $fan['moved'] . ' passengers moved, ')
            . $fan['tickets'] . ' tickets redrawn, ' . $fan['sent'] . ' messages sent'
            . ', ' . $fan['failed'] . ' failed'
            . ($fan['already'] > 0 ? ', ' . $fan['already'] . ' already told' : '')
            . ($fan['crew'] !== '' ? ' · crew: ' . $fan['crew'] : '')
            . ($liveRepointed > 0 ? ' · live tracking now follows ' . $newLabel : '') . '.';

        Logger::audit('trip.swap_bus.notify', 'schedule', (string) $sid, null,
            ['seq' => $seq, 'resume' => $resume, 'tickets' => $fan['tickets'], 'sent' => $fan['sent'], 'failed' => $fan['failed'],
             'skipped' => $fan['skipped'], 'already' => $fan['already'], 'crew' => $fan['crew'], 'failedList' => array_slice($fan['failedList'], 0, 20)],
            $fan['message'], $reason);

        return $fan;
    }

    /**
     * Tickets + messages after the swap. Passengers first, then the crew.
     *
     * @return array<string,mixed>
     */
    private static function fanOut(int $sid, int $newBusId, array $trip, string $oldLabel, string $newLabel, int $seq, string $reason,
                                   int $adminId, bool $notify, bool $crewOn, bool $resume, ?array $onlyBids = null): array
    {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(300);
            @ignore_user_abort(true);
        }
        $out = ['tickets' => 0, 'ticketFailed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'already' => 0,
                'crew' => '', 'failedList' => []];
        $coach = (string) ($trip['coach_type'] ?? 'sleeper');
        if (!class_exists('Notify')) {
            require_once __DIR__ . '/notify.php';
        }

        $scope = $onlyBids !== null ? array_fill_keys(array_map('intval', $onlyBids), true) : null;
        foreach (self::bookings($sid) as $r) {
            $bid = (int) $r['id'];
            $pnr = (string) $r['pnr'];
            if ($scope !== null && !isset($scope[$bid])) {
                continue;   // sold / confirmed after the swap: they bought on the new bus already
            }
            $key = 'trip:bus_changed:' . $bid . ':' . $sid . ':' . $newBusId . ':' . $seq;
            $wantMsg = $notify && (string) $r['status'] === 'confirmed' && Notify::usablePhone($r['contact_phone'] ?? '') !== '';
            $claimed = false;
            if ($wantMsg) {
                $claimed = EventBus::claim($key, 'trip.bus_changed');
            }
            if ($resume && !$claimed) {
                $wantMsg ? $out['already']++ : $out['skipped']++;
                continue;   // on a re-press only the bookings whose message has not gone yet
            }

            $seatList = array_values(array_filter(array_map('trim', explode(',', (string) ($r['seats'] ?? '')))));
            $seats    = Seats::displayLabels($seatList, $coach, (string) (($r['booking_mode'] ?? '') ?: 'sharing'));
            if (!$resume) {   // a re-press only resends; the tickets were redrawn at swap time
                $t = TicketSync::afterBookingChange($bid, 'trip_change', ['seats' => 'Bus ' . $newLabel, 'busOld' => $oldLabel, 'busNew' => $newLabel],
                    ['notify' => false, 'reason' => $reason !== '' ? $reason : 'bus swap', 'actor' => 'admin #' . $adminId]);
                if (!empty($t['ok']) && !empty($t['hadTicket'])) {
                    $out['tickets']++;
                } elseif (empty($t['ok'])) {
                    $out['ticketFailed']++;
                }
            }

            if (!$wantMsg) {
                $out['skipped']++;
                continue;
            }
            if (!$claimed) {
                $out['already']++;
                continue;
            }

            $timeTs = (string) (($r['boarding_time'] ?? '') ?: ($trip['dep_time'] ?? ''));
            $ctx = [
                'busOld' => $oldLabel,
                'busNew' => $newLabel,
                'seats'  => $seats,
                'time'   => trim(formatTime($timeTs) . ' · ' . formatDate((string) $trip['travel_date'], 'j M'), ' ·'),
            ];
            $res = self::send($bid, $pnr, $ctx);
            if (!empty($res['ok'])) {
                $out['sent']++;
            } else {
                $out['failed']++;
                $out['failedList'][] = $pnr . ': ' . mb_substr((string) ($res['detail'] ?? 'not sent'), 0, 90);
                self::release($key);   // a re-press tries this one again
            }
        }

        if ($crewOn) {
            $out['crew'] = self::tellCrew($sid, $trip, $oldLabel, $newLabel, $seq, $reason);
        }
        return $out;
    }

    /** @return array{ok:bool, detail:string} */
    private static function send(int $bookingId, string $pnr, array $ctx): array
    {
        try {
            if (self::$sender !== null) {
                $r = (self::$sender)($bookingId, $pnr, $ctx);
                return ['ok' => !empty($r['ok']), 'detail' => (string) ($r['detail'] ?? '')];
            }
            if (!class_exists('BookingService')) {
                require_once __DIR__ . '/booking.php';
            }
            $detail = BookingService::detail($pnr);
            if ($detail === null) {
                return ['ok' => false, 'detail' => 'booking detail unavailable'];
            }
            $r = Notify::ticketChanged($detail, 'trip_change', $ctx);
            return ['ok' => !empty($r['ok']), 'detail' => (string) ($r['detail'] ?? '')];
        } catch (Throwable $e) {
            Logger::error('BusSwap notify failed', ['pnr' => $pnr, 'e' => $e->getMessage()], 'notify');
            return ['ok' => false, 'detail' => 'notify failed: ' . $e->getMessage()];
        }
    }

    private static function release(string $key): void
    {
        try {
            Database::query('DELETE FROM automation_log WHERE dedupe_key = :k', ['k' => substr($key, 0, 120)]);
        } catch (Throwable $ignored) {
        }
    }

    /** The trip's driver / crew: new number + the passenger list. '' = nobody to tell. */
    private static function tellCrew(int $sid, array $trip, string $oldLabel, string $newLabel, int $seq, string $reason): string
    {
        try {
            require_once __DIR__ . '/driverlist.php';
            $crew = DriverList::crewFor($sid);
            $phone = trim((string) ($crew['phone'] ?? ''));
            if ($phone === '') {
                return 'no driver number';
            }
            $key = 'trip:bus_changed:crew:' . $sid . ':' . $seq;
            if (!EventBus::claim($key, 'trip.bus_changed.crew')) {
                return 'already told';
            }
            $pax  = DriverList::paxCount($sid);
            $text = "🚌 बस साटियो · Bus swapped\n"
                . trim((string) $trip['from_city']) . ' → ' . trim((string) $trip['to_city'])
                . ' · ' . formatDate((string) $trip['travel_date'], 'j M') . ' · ' . formatTime((string) ($trip['dep_time'] ?? '')) . "\n"
                . 'बस नम्बर: ' . $oldLabel . ' -> ' . $newLabel . "\n"
                . ($reason !== '' ? 'कारण: ' . $reason . "\n" : '')
                . 'यात्रु: ' . $pax . " जना · सिट उही\n\n"
                . DriverList::text($sid, 'update');
            $hint = resolvePhoneCountry('', $phone) ?: null;
            if (self::$crewTransport !== null) {
                $ok = (self::$crewTransport)($phone, $text, $hint) === true;
            } else {
                $ok = Notify::whatsapp($phone, $text, null, $hint, [], null, ['purpose' => 'driver_list', 'label' => 'Bus swap']) === true;
            }
            if (!$ok) {
                self::release($key);
            }
            $who = trim((string) ($crew['name'] ?? '')) ?: 'driver';
            return $ok ? $who . ' told' : $who . ' not reached';
        } catch (Throwable $e) {
            Logger::warning('BusSwap crew notice failed', ['sid' => $sid, 'e' => $e->getMessage()], 'notify');
            return 'crew notice failed';
        }
    }
}
