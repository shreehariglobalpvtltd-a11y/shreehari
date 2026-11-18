<?php
/**
 * includes/driverlist.php — the passenger list to the bus driver on WhatsApp
 * (19 Nov 2026, WA-5; owner: "5 jana bhaye pachhi driver lai yatri ko list
 * aafai jaos").
 *
 *   crewFor(sid)  WHO drives: the trip's driver (schedules.driver_id, set on
 *                 admin/trips.php) -> the bus's default driver
 *                 (buses.default_driver_id, admin/buses.php - buses rotate
 *                 daily, so the list follows the coach) -> the route crew
 *                 (routes.crew_name / crew_phone) -> none. Inactive drivers
 *                 are skipped. WaLocal (the bot's "driver number") and the
 *                 manual chalan send (WaTemplates) ask the same function.
 *   text(sid)     header (route, date, time, bus) + the pickups in route order,
 *                 each passenger as "सिट नाम" (NO phone numbers unless
 *                 driver_list_phones = 1) + "बोर्डिङमा उठाउनु n जना ₹x" from
 *                 the chalani's own money rule (ChalaniPng::departureMoney).
 *   decide()      pure: 'first' when confirmed + pending passengers first reach
 *                 driver_list_min_pax (5) inside driver_list_window_hours (24),
 *                 'update' every driver_list_step (5) more, 'final' once inside
 *                 driver_list_final_hours (2) before departure; never after it.
 *   run()         every cron/office-report.php run (*\/15): today's and
 *                 tomorrow's departures. The kv claim (kv_store global
 *                 'driver_list.<sid>' = {n, final, at}) is written BEFORE the
 *                 send - at most once beats twice. No driver phone = nothing
 *                 sent and the departure is counted as no_driver (the office
 *                 counter-day report says "driver number missing").
 *
 * Switch: driver_list_on (default 1). Never sends to a passenger; never writes
 * a booking.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DriverList
{
    /** Test seam: when set, called instead of Notify::whatsapp(phone, text, media, hint, meta) -> bool|string. */
    public static ?Closure $transport = null;

    public static function on(): bool
    {
        return Settings::getBool('driver_list_on', true);
    }

    private static function hasBusDefault(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = CounterDesk::hasColumn('buses', 'default_driver_id');
        }
        return $has;
    }

    /**
     * Who drives departure $sid. @return array{name:string,phone:string,source:string,driver_id:int}
     */
    public static function crewFor(int $sid): array
    {
        $none = ['name' => '', 'phone' => '', 'source' => 'none', 'driver_id' => 0];
        if ($sid <= 0) {
            return $none;
        }
        try {
            $s = Database::fetch(
                'SELECT s.driver_id, COALESCE(s.bus_id, r.bus_id) AS bus_id, r.crew_name, r.crew_phone'
                . (self::hasBusDefault() ? ', bu.default_driver_id' : ', NULL AS default_driver_id') . '
                   FROM schedules s JOIN routes r ON r.id = s.route_id
                   LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
                  WHERE s.id = :s',
                ['s' => $sid]
            );
        } catch (Throwable $e) {
            return $none;
        }
        if ($s === null) {
            return $none;
        }
        foreach ([['trip', (int) ($s['driver_id'] ?? 0)], ['bus', (int) ($s['default_driver_id'] ?? 0)]] as [$src, $did]) {
            if ($did <= 0) {
                continue;
            }
            $d = Database::fetch('SELECT id, full_name, phone FROM drivers WHERE id = :i AND is_active = 1', ['i' => $did]);
            if ($d !== null && trim((string) $d['phone']) !== '') {
                return ['name' => trim((string) $d['full_name']), 'phone' => trim((string) $d['phone']), 'source' => $src, 'driver_id' => (int) $d['id']];
            }
        }
        $ph = trim((string) ($s['crew_phone'] ?? ''));
        if ($ph !== '') {
            return ['name' => trim((string) ($s['crew_name'] ?? '')), 'phone' => $ph, 'source' => 'route', 'driver_id' => 0];
        }
        return $none;
    }

    /** confirmed + pending passengers on the departure (cancelled / rejected are off the bus). */
    public static function paxCount(int $sid): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_passengers bp JOIN booking_legs bl ON bl.id = bp.leg_id JOIN bookings b ON b.id = bp.booking_id
              WHERE bl.schedule_id = :s AND b.status IN ('confirmed','pending','completed')", ['s' => $sid], 0);
    }

    /** The departure facts the header prints. @return array<string,mixed>|null */
    public static function trip(int $sid): ?array
    {
        return Database::fetch(
            "SELECT s.id, s.travel_date, s.status, s.is_blocked, s.slot, r.id AS route_id, r.from_city, r.to_city, r.route_code,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
                    bu.bus_number, bu.bus_name
               FROM schedules s JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
              WHERE s.id = :s",
            ['s' => $sid]
        );
    }

    /**
     * The list as the driver reads it. Names and seats by pickup, in the
     * route's own order; phones only when driver_list_phones = 1.
     */
    public static function text(int $sid, string $kind = ''): string
    {
        require_once INCLUDE_PATH . '/chalanipng.php';
        require_once INCLUDE_PATH . '/seats.php';
        $t = self::trip($sid);
        if ($t === null) {
            return '';
        }
        $dm    = ChalaniPng::departureMoney($sid, null, 'stop');
        $rows  = $dm['rows'];
        $coach = (string) ($t['coach_type'] ?? 'sleeper');
        $phones = Settings::getBool('driver_list_phones', false);
        $short = static function (string $label): string {
            $s = (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label);
            $s = (string) preg_replace('/\s*@.*$/u', '', $s);
            $s = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $s);
            $s = trim((string) preg_replace('/\s{2,}/u', ' ', $s), " \t·");
            return $s !== '' ? $s : trim($label);
        };
        $groups = [];
        foreach ($rows as $r) {
            $stop = $r['boarding_stop'] ? $short((string) $r['boarding_stop']) : '—';
            $seat = Seats::displayLabel((string) $r['seat_no'], $coach, (string) (($r['booking_mode'] ?? '') ?: 'sharing'));
            $ph   = (string) ($r['contact_phone'] ?? '');
            $groups[$stop][] = $seat . ' ' . trim((string) $r['full_name'])
                . ($phones && $ph !== '' && preg_match('/^0+$/', $ph) !== 1 ? ' ' . $ph : '');
        }
        $plate = trim((string) ($t['bus_number'] ?? ''));
        $head  = match ($kind) { 'final' => '🚌 अन्तिम यात्रु सूची · Final list', 'update' => '🚌 यात्रु सूची (थपियो) · Updated list', default => '🚌 यात्रु सूची · Passenger list' };
        $lines = [
            $head,
            trim((string) $t['from_city']) . ' → ' . trim((string) $t['to_city']) . ' · ' . date('j M', (int) strtotime((string) $t['travel_date']))
                . ' · ' . substr((string) $t['dep_time'], 0, 5) . ($plate !== '' ? ' · बस ' . $plate : '') . ' · ' . count($rows) . ' यात्रु',
        ];
        $i = 0;
        foreach ($groups as $stop => $list) {
            $i++;
            $lines[] = '📍 ' . $i . '. ' . $stop . ' (' . count($list) . '): ' . implode(', ', $list);
        }
        $tot = $dm['totals'];
        $lines[] = 'बोर्डिङमा उठाउनु: ' . (int) $tot['boardDueN'] . ' जना ' . inr((float) $tot['boardDue']);
        $lines[] = 'नाम र सिट बोर्डिङमा जाँच्नुहोस्। · S Hari Global';
        return implode("\n", $lines);
    }

    /**
     * Pure: should a list go now? null | 'first' | 'update' | 'final'.
     *
     * @param array{n?:int,final?:bool|int}|null $last the kv claim of the last send
     */
    public static function decide(?array $last, int $pax, int $depTs, int $now): ?string
    {
        if ($depTs <= 0 || $now >= $depTs || $pax <= 0) {
            return null;
        }
        $min    = max(1, Settings::getInt('driver_list_min_pax', 5));
        $step   = max(1, Settings::getInt('driver_list_step', 5));
        $final  = max(0, Settings::getInt('driver_list_final_hours', 2));
        $window = max(1, Settings::getInt('driver_list_window_hours', 24));
        if ($depTs - $now > $window * 3600) {
            return null;
        }
        if ($final > 0 && $depTs - $now <= $final * 3600) {
            return empty($last['final']) ? 'final' : null;
        }
        if ($last === null || !isset($last['n'])) {
            return $pax >= $min ? 'first' : null;
        }
        return $pax >= (int) $last['n'] + $step ? 'update' : null;
    }

    /**
     * One pass over today's and tomorrow's departures.
     *
     * @return array{departures:int,sent:int,failed:int,no_driver:int,missing:list<string>,dry:bool,would?:list<array<string,mixed>>}
     */
    public static function run(bool $dry = false, ?int $now = null, ?array $onlySids = null): array
    {
        $now = $now ?? time();
        $out = ['departures' => 0, 'sent' => 0, 'failed' => 0, 'no_driver' => 0, 'missing' => [], 'dry' => $dry];
        if ($dry) { $out['would'] = []; }
        $day = date('Y-m-d', $now);
        $sql = "SELECT s.id, s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city
                  FROM schedules s JOIN routes r ON r.id = s.route_id
                 WHERE r.is_active = 1 AND s.status <> 'cancelled' AND COALESCE(s.is_blocked, 0) = 0";
        $args = [];
        if ($onlySids !== null) {
            $ids = array_values(array_filter(array_map('intval', $onlySids), static fn(int $i): bool => $i > 0));
            if ($ids === []) { return $out; }
            $sql .= ' AND s.id IN (' . implode(',', $ids) . ')';
        } else {
            $sql .= ' AND s.travel_date BETWEEN :d AND :d2';
            $args = ['d' => $day, 'd2' => date('Y-m-d', $now + 86400)];
        }
        foreach (Database::fetchAll($sql, $args) as $s) {
            try {
                $sid   = (int) $s['id'];
                $depTs = (int) strtotime((string) $s['travel_date'] . ' ' . substr((string) $s['dep_time'], 0, 8));
                $pax   = self::paxCount($sid);
                $key   = 'driver_list.' . $sid;
                $raw   = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => $key]);
                $last  = is_string($raw) ? (json_decode($raw, true) ?: null) : null;
                $kind  = self::decide(is_array($last) ? $last : null, $pax, $depTs, $now);
                if ($kind === null) {
                    continue;
                }
                $out['departures']++;
                $crew = self::crewFor($sid);
                if ($crew['phone'] === '') {
                    $out['no_driver']++;
                    $out['missing'][] = trim((string) $s['from_city']) . ' → ' . trim((string) $s['to_city']) . ' ' . substr((string) $s['dep_time'], 0, 5)
                        . ' (' . date('j M', (int) strtotime((string) $s['travel_date'])) . ')';
                    continue;   // nothing claimed: it goes once a driver number is set
                }
                $text = self::text($sid, $kind);
                if ($dry) {
                    $out['would'][] = ['sid' => $sid, 'kind' => $kind, 'pax' => $pax, 'to' => $crew['source'], 'text' => $text];
                    continue;
                }
                /* the claim first: a half-failed send is never repeated */
                $claim = (string) json_encode(['n' => $pax, 'final' => $kind === 'final' || !empty($last['final']), 'at' => date('Y-m-d H:i:s', $now), 'kind' => $kind]);
                Database::run(
                    "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'driver-list')
                     ON DUPLICATE KEY UPDATE kvalue = :v2",
                    ['k' => $key, 'v' => $claim, 'v2' => $claim]
                );
                $ok = self::deliver($sid, $crew, $text, $pax);
                $ok ? $out['sent']++ : $out['failed']++;
                Logger::info('driver list ' . $kind, ['sid' => $sid, 'pax' => $pax, 'to' => $crew['source'], 'ok' => $ok], 'whatsapp');
            } catch (Throwable $e) {
                $out['failed']++;
                Logger::warning('driver list failed', ['sid' => (int) ($s['id'] ?? 0), 'err' => $e->getMessage()], 'whatsapp');
            }
        }
        return $out;
    }

    /**
     * The chalani page-1 picture for the driver - ONLY when driver_list_phones
     * is on. That page draws every passenger's MOBILE, the fares, cash / online
     * and agent codes, so attaching it while the setting says "no phones"
     * would hand the driver (and anyone he forwards the 2-day link to) every
     * number anyway (review fix, 19 Nov 2026). Off: the text list only.
     */
    public static function chalaniLink(int $sid): ?string
    {
        if ($sid <= 0 || !Settings::getBool('driver_list_phones', false)) {
            return null;
        }
        $exp = time() + 2 * 86400;
        return appUrl('download-chalan.php?' . http_build_query([
            'sid' => $sid, 'doc' => 'chalani', 'page' => 1, 'exp' => $exp,
            'k' => substr(Security::sign('chalan-dl|' . $sid . '|chalani|1|' . $exp), 0, 20),
        ]));
    }

    /** One send: the text (+ the chalani page 1 picture when phones may be shared); the approved shg_driver_manifest template when Meta says it is ready. */
    private static function deliver(int $sid, array $crew, string $text, int $pax): bool
    {
        $png  = self::chalaniLink($sid);
        $meta = ['purpose' => 'driver_list', 'label' => 'Driver list'];
        /* shg_driver_manifest has a DOCUMENT header: it can only go with the
           chalani attached, so with phones off the free text is the only way */
        if ($png !== null && (class_exists('WaTplMeta') || is_file(INCLUDE_PATH . '/watplmeta.php'))) {
            try {
                require_once INCLUDE_PATH . '/watplmeta.php';
                if (class_exists('WaTplMeta') && method_exists('WaTplMeta', 'forPurpose')) {
                    $t = self::trip($sid);
                    $spec = WaTplMeta::forPurpose('driver_list', [
                        'bus' => trim((string) ($t['bus_number'] ?? '') . ' ' . (string) ($t['bus_name'] ?? '')) ?: (string) ($t['route_code'] ?? ''),
                        'date' => $t !== null ? date('j M Y', (int) strtotime((string) $t['travel_date'])) : '',
                        'passengers' => (string) $pax, 'header' => $png,
                    ]);
                    if ($spec !== null) {
                        $meta['meta_tpl'] = $spec;   // used only outside the 24 h window (Notify: free reply first)
                    }
                }
            } catch (Throwable $e) {
                // the free text still goes
            }
        }
        $hint = resolvePhoneCountry('', (string) $crew['phone']) ?: null;
        if (self::$transport !== null) {
            return (self::$transport)((string) $crew['phone'], $text, $png, $hint, $meta) === true;
        }
        require_once INCLUDE_PATH . '/notify.php';
        return Notify::whatsapp((string) $crew['phone'], $text, $png, $hint, [], null, $meta) === true;
    }
}
