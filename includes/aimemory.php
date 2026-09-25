<?php
/**
 * =====================================================================
 *  AiMemory — the assistant remembers each person (24 Sep 2026).
 *
 *  "Memory that follows the customer": one key per phone number, the same
 *  on WhatsApp, in the web chat and at the counter. Two shapes:
 *
 *    profile   — what stays true: display name, language, usual pickup,
 *                usual direction, how many trips, last trip date
 *    episodes  — what happened, dated: booked, paid, cancelled, renamed,
 *                complained, corrected the assistant, asked for a call
 *
 *  Nothing here is written by a model. The register's own events write
 *  the episodes (EventBus listeners below), so memory is exactly as true
 *  as the bookings table — and the assistant reads a short brief of it
 *  into its prompt, in the person's language. The key is a keyed hash of
 *  the number, so the tables hold no phone numbers.
 *
 *  Behind ai_memory_on (OFF). forget() erases a person on request.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiMemory
{
    private const MAX_EPISODES  = 120;   // per person; the oldest low-importance rows go first
    private const KEEP_DAYS     = 540;   // ~18 months, two festival seasons

    public static function enabled(): bool
    {
        return Settings::getBool('ai_memory_on', false);
    }

    /** One key per person: a keyed hash of the normalised number, never the number. */
    public static function key(string $phoneRaw): string
    {
        $digits = normalisePhone($phoneRaw);
        if ($digits === '') {
            $digits = preg_replace('/\D/', '', $phoneRaw) ?? '';
        }
        if ($digits === '') {
            return '';
        }
        // Country codes are not identity: 9198… and 98… are the same person.
        $digits = strlen($digits) > 10 ? substr($digits, -10) : $digits;
        return hash('sha256', 'ai-memory|' . $digits . '|' . APP_KEY);
    }

    /* ---------------------------------------------------------------
     *  Writing
     * ------------------------------------------------------------- */

    public static function remember(string $phone, string $kind, string $summary, ?int $bookingId = null, int $importance = 3, string $channel = 'system'): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $key = self::key($phone);
        if ($key === '' || trim($summary) === '') {
            return false;
        }
        try {
            Database::insert('ai_memory_episodes', [
                'owner_key'   => $key,
                'channel'     => mb_substr($channel, 0, 20),
                'kind'        => mb_substr($kind, 0, 30),
                'summary'     => mb_substr(trim($summary), 0, 500),
                'booking_id'  => $bookingId,
                'importance'  => max(1, min(5, $importance)),
                'happened_at' => date('Y-m-d H:i:s'),
                'expires_at'  => date('Y-m-d', time() + self::KEEP_DAYS * 86400),
            ]);
            Database::run(
                'INSERT INTO ai_memory_profile (owner_key, last_seen) VALUES (:k, NOW())
                 ON DUPLICATE KEY UPDATE last_seen = NOW()',
                ['k' => $key]
            );
            self::trim($key);
            return true;
        } catch (Throwable $e) {
            Logger::warning('AiMemory::remember failed', ['e' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<string, mixed> $fields display_name | language | usual_pickup | usual_direction | trip_date | notes */
    public static function touchProfile(string $phone, array $fields): void
    {
        if (!self::enabled()) {
            return;
        }
        $key = self::key($phone);
        if ($key === '') {
            return;
        }
        try {
            $set = ['last_seen' => date('Y-m-d H:i:s')];
            foreach (['display_name', 'language', 'usual_pickup', 'usual_direction', 'notes'] as $f) {
                if (isset($fields[$f]) && trim((string) $fields[$f]) !== '') {
                    $set[$f] = mb_substr(trim((string) $fields[$f]), 0, $f === 'notes' ? 500 : 120);
                }
            }
            $bump = !empty($fields['trip_date']);
            if ($bump) {
                $set['last_trip_date'] = (string) $fields['trip_date'];
            }
            $cols = array_keys($set);
            $sql  = 'INSERT INTO ai_memory_profile (owner_key, ' . implode(', ', $cols) . ($bump ? ', trips' : '') . ')
                     VALUES (:k, :' . implode(', :', $cols) . ($bump ? ', 1' : '') . ')
                     ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => "$c = VALUES($c)", $cols))
                  . ($bump ? ', trips = trips + 1' : '');
            Database::run($sql, ['k' => $key] + $set);
        } catch (Throwable $e) {
            Logger::warning('AiMemory::touchProfile failed', ['e' => $e->getMessage()]);
        }
    }

    private static function trim(string $key): void
    {
        $n = (int) Database::scalar('SELECT COUNT(*) FROM ai_memory_episodes WHERE owner_key = :k', ['k' => $key], 0);
        if ($n <= self::MAX_EPISODES) {
            return;
        }
        $ids = array_column(Database::fetchAll(
            'SELECT id FROM ai_memory_episodes WHERE owner_key = :k ORDER BY importance ASC, happened_at ASC LIMIT ' . ($n - self::MAX_EPISODES),
            ['k' => $key]
        ), 'id');
        if ($ids !== []) {
            Database::run('DELETE FROM ai_memory_episodes WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }

    /** Erase a person entirely (privacy request). Returns rows removed. */
    public static function forget(string $phone): int
    {
        $key = self::key($phone);
        if ($key === '') {
            return 0;
        }
        $n = Database::delete('ai_memory_episodes', 'owner_key = :k', ['k' => $key]);
        $n += Database::delete('ai_memory_profile', 'owner_key = :k', ['k' => $key]);
        return $n;
    }

    /* ---------------------------------------------------------------
     *  Reading
     * ------------------------------------------------------------- */

    /** @return array<string, mixed>|null */
    public static function profile(string $phone): ?array
    {
        $key = self::key($phone);
        return $key === '' ? null : Database::fetch('SELECT * FROM ai_memory_profile WHERE owner_key = :k', ['k' => $key]);
    }

    /** @return list<array<string, mixed>> newest first */
    public static function recall(string $phone, int $limit = 8): array
    {
        $key = self::key($phone);
        if ($key === '') {
            return [];
        }
        return Database::fetchAll(
            'SELECT kind, summary, happened_at, importance, channel FROM ai_memory_episodes
              WHERE owner_key = :k AND (expires_at IS NULL OR expires_at >= CURDATE())
              ORDER BY importance DESC, happened_at DESC LIMIT ' . max(1, min(30, $limit)),
            ['k' => $key]
        );
    }

    /**
     * The block the assistant reads: plain lines, no numbers, no ids.
     * Empty string when the switch is off or nothing is known — so a
     * prompt never carries an empty heading.
     */
    public static function brief(string $phone, int $limit = 6): string
    {
        if (!self::enabled()) {
            return '';
        }
        $p  = self::profile($phone);
        $ep = self::recall($phone, $limit);
        if ($p === null && $ep === []) {
            return '';
        }
        $lines = [];
        if ($p !== null) {
            $bits = [];
            if (!empty($p['display_name']))    { $bits[] = 'name ' . $p['display_name']; }
            if ((int) ($p['trips'] ?? 0) > 0)  { $bits[] = (int) $p['trips'] . ' trip(s) with us'; }
            if (!empty($p['usual_pickup']))    { $bits[] = 'usually boards at ' . $p['usual_pickup']; }
            if (!empty($p['usual_direction']))  { $bits[] = 'usually travels ' . ($p['usual_direction'] === 'toNepal' ? 'towards Nepal' : 'towards India'); }
            if (!empty($p['last_trip_date']))  { $bits[] = 'last trip ' . formatDate((string) $p['last_trip_date']); }
            if (!empty($p['language']))        { $bits[] = 'writes in ' . $p['language']; }
            if (!empty($p['notes']))           { $bits[] = (string) $p['notes']; }
            if ($bits !== []) {
                $lines[] = '• ' . implode(' · ', $bits) . '.';
            }
        }
        foreach ($ep as $e) {
            $lines[] = '• ' . formatDate(substr((string) $e['happened_at'], 0, 10)) . ' — ' . $e['summary'];
        }
        if ($lines === []) {
            return '';
        }
        return "=== WHAT WE REMEMBER ABOUT THIS PERSON (from our own register; use it, do not recite it) ===\n"
            . implode("\n", $lines) . "\n"
            . "Greet a returning traveller as one. If a memory contradicts what they say now, trust what they say now.\n\n";
    }

    /* ---------------------------------------------------------------
     *  The register writes the memory
     * ------------------------------------------------------------- */

    public static function registerListeners(): void
    {
        $leg = static function (array $b): array {
            try {
                return Database::fetch(
                    'SELECT travel_date, boarding_stop, seat_count, schedule_id FROM booking_legs WHERE booking_id = :b ORDER BY (leg_type = \'outbound\') DESC, id LIMIT 1',
                    ['b' => (int) ($b['id'] ?? 0)]
                ) ?? [];
            } catch (Throwable $e) {
                return [];
            }
        };
        $route = static function (array $b): string {
            $from = trim((string) ($b['from_city'] ?? '')); $to = trim((string) ($b['to_city'] ?? ''));
            if ($from === '' || $to === '') {
                try {
                    $r = Database::fetch(
                        'SELECT r.from_city, r.to_city FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id JOIN routes r ON r.id = s.route_id WHERE bl.booking_id = :b ORDER BY bl.id LIMIT 1',
                        ['b' => (int) ($b['id'] ?? 0)]
                    );
                    $from = trim((string) ($r['from_city'] ?? '')); $to = trim((string) ($r['to_city'] ?? ''));
                } catch (Throwable $e) { /* leave blank */ }
            }
            return $from !== '' && $to !== '' ? $from . ' → ' . $to : '';
        };
        $safe = static function (callable $fn): callable {
            return static function (array $d) use ($fn): void {
                if (!self::enabled()) { return; }
                try { $fn($d); } catch (Throwable $e) { Logger::warning('AiMemory listener failed', ['e' => $e->getMessage()]); }
            };
        };

        EventBus::on('booking.created', $safe(static function (array $d) use ($leg, $route): void {
            $b = (array) ($d['booking'] ?? []);
            $phone = (string) ($b['contact_phone'] ?? '');
            if ($phone === '') { return; }
            $l = $leg($b);
            $stop = trim((string) ($l['boarding_stop'] ?? ''));
            if (class_exists('Boarding') && $stop !== '') { $stop = (string) (Boarding::stopDisplay($stop)['name'] ?? $stop); }
            $rt = $route($b);
            $date = (string) ($l['travel_date'] ?? '');
            $pax = (int) ($l['seat_count'] ?? 1);
            $src = (string) ($b['source'] ?? 'web');
            self::remember($phone, 'booking',
                'Booked ' . $pax . ' seat(s) ' . ($rt !== '' ? $rt . ' ' : '') . ($date !== '' ? 'for ' . formatDate($date) . ' ' : '')
                . ($stop !== '' ? 'from ' . $stop . ' ' : '') . '(' . ($src === 'web' || $src === 'app' ? 'online' : $src) . ', PNR ' . ($b['pnr'] ?? '') . ')',
                (int) ($b['id'] ?? 0), 4, $src);
            $toNepal = str_contains(strtolower($rt), 'rupaidiha') || str_contains(strtolower($rt), '→ nepal');
            $name = '';
            try {
                $name = (string) (Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) ($b['id'] ?? 0)], '') ?? '');
            } catch (Throwable $e) { /* optional */ }
            self::touchProfile($phone, [
                'display_name'    => $name,
                'usual_pickup'    => $stop,
                'usual_direction' => $rt !== '' ? ($toNepal ? 'toNepal' : 'toIndia') : '',
                'trip_date'       => $date,
            ]);
        }));
        EventBus::on('booking.approved', $safe(static function (array $d) use ($route): void {
            $b = (array) ($d['booking'] ?? []);
            if (($b['contact_phone'] ?? '') === '') { return; }
            self::remember((string) $b['contact_phone'], 'paid', 'Payment verified for PNR ' . ($b['pnr'] ?? '') . ($route($b) !== '' ? ' (' . $route($b) . ')' : ''), (int) ($b['id'] ?? 0), 2);
        }));
        EventBus::on('booking.cancelled', $safe(static function (array $d): void {
            $b = (array) ($d['booking'] ?? []);
            if (($b['contact_phone'] ?? '') === '') { return; }
            $refund = is_array($d['refund'] ?? null) ? (float) ($d['refund']['amount'] ?? 0) : (float) ($d['refund'] ?? $b['refund_amount'] ?? 0);
            self::remember((string) $b['contact_phone'], 'cancel', 'Cancelled PNR ' . ($b['pnr'] ?? '') . ($refund > 0 ? ', refund ₹' . number_format($refund) . ' due' : ', no refund') . (!empty($d['reason']) ? ' — ' . mb_substr((string) $d['reason'], 0, 80) : ''), (int) ($b['id'] ?? 0), 4);
        }));
        EventBus::on('booking.rejected', $safe(static function (array $d): void {
            $b = (array) ($d['booking'] ?? []);
            if (($b['contact_phone'] ?? '') === '') { return; }
            self::remember((string) $b['contact_phone'], 'payment_rejected', 'Payment proof for PNR ' . ($b['pnr'] ?? '') . ' was rejected' . (!empty($d['reason']) ? ': ' . mb_substr((string) $d['reason'], 0, 80) : ''), (int) ($b['id'] ?? 0), 4);
        }));
    }
}
