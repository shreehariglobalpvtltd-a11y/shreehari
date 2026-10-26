<?php
/**
 * =====================================================================
 *  Health — every fault names itself, in the owner's language.
 *
 *  The expensive failures in this system have all been SILENT ones. The
 *  WhatsApp sender was disabled by Meta and 19 tickets in a row failed with
 *  63112 while the Twilio console still said "online". Nepali numbers were
 *  being dialled as Indian ones for weeks. A test suite reported green
 *  while failing 24 of 28 checks because it never called exit(). In each
 *  case the information existed — in a log file nobody opens.
 *
 *  This class turns those into incidents: one row per FAULT CLASS, not one
 *  per failure, so an outage is a single card carrying a count instead of
 *  four hundred lines burying the one thing that matters. Each incident
 *  says, in plain words, what broke, how many tickets it touched, and what
 *  the owner has to do about it.
 *
 *  TWO RULES
 *  ---------
 *  1. OBSERVE ONLY. Nothing in this file sends a message, edits a booking,
 *     releases a seat or fixes anything. A monitor that also repairs is a
 *     monitor whose false positive corrupts data; detection and enforcement
 *     stay separate on purpose.
 *  2. NO PII. detail and fix_steps are explanations; sample_ids holds
 *     booking ids. No phone number, no passenger name, no PNR body — the
 *     incident list is read on a phone over someone's shoulder.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Health
{
    public const CRITICAL = 'critical';
    public const WARN     = 'warn';
    public const INFO     = 'info';

    /**
     * Raise (or refresh) one incident.
     *
     * The dedupe key is what makes this idempotent: calling open() every ten
     * minutes for an outage that is still happening bumps occurrences and
     * last_seen_at on the SAME row rather than writing a new one. A key
     * should identify the FAULT, not the moment — 'wa.63112' not
     * 'wa.63112.2026-09-08T14:20'.
     *
     * Never throws: a monitor that can 500 a cron run is worse than no
     * monitor, because it takes the rest of the job down with it.
     *
     * @param list<int|string> $sampleIds booking ids only — never phones
     * @return int the incident id, or 0 when the write failed
     */
    public static function open(
        string $kind,
        string $dedupeKey,
        string $severity,
        string $title,
        string $detail = '',
        string $fixSteps = '',
        array $sampleIds = [],
        int $occurrences = 1
    ): int {
        try {
            $now = date('Y-m-d H:i:s');
            $sev = in_array($severity, [self::CRITICAL, self::WARN, self::INFO], true) ? $severity : self::WARN;

            /* Booking ids and nothing else. Stripping punctuation is not
               enough on its own: '+977 9812345678' would survive as a bare
               phone number, and this table is read on a phone in a public
               place. A bookings.id in this system is a handful of digits, so
               anything longer than nine is not an id and is dropped. */
            $samples = implode(',', array_slice(array_values(array_filter(
                array_map(static fn($v): string => trim((string) $v), $sampleIds),
                static fn(string $v): bool => preg_match('/^\d{1,9}$/', $v) === 1
            )), 0, 20));

            /* An incident that was resolved and is happening AGAIN must come
               back as open — otherwise the second outage of the same kind is
               invisible for as long as the first one's row survives. */
            Database::query(
                'INSERT INTO health_incidents
                    (kind, dedupe_key, severity, title, detail, fix_steps, sample_ids,
                     occurrences, status, first_seen_at, last_seen_at)
                 VALUES (:k, :d, :sev, :t, :det, :fix, :s, :occ, \'open\', :f, :l)
                 ON DUPLICATE KEY UPDATE
                    severity      = VALUES(severity),
                    title         = VALUES(title),
                    detail        = VALUES(detail),
                    fix_steps     = VALUES(fix_steps),
                    sample_ids    = VALUES(sample_ids),
                    occurrences   = occurrences + VALUES(occurrences),
                    status        = \'open\',
                    resolved_at   = NULL,
                    last_seen_at  = VALUES(last_seen_at)',
                [
                    'k'   => substr($kind, 0, 60),
                    'd'   => substr($dedupeKey, 0, 120),
                    'sev' => $sev,
                    't'   => substr($title, 0, 160),
                    'det' => $detail !== '' ? $detail : null,
                    'fix' => $fixSteps !== '' ? $fixSteps : null,
                    's'   => $samples !== '' ? substr($samples, 0, 255) : null,
                    'occ' => max(1, $occurrences),
                    'f'   => $now,
                    'l'   => $now,
                ]
            );

            return (int) Database::scalar(
                'SELECT id FROM health_incidents WHERE dedupe_key = :d LIMIT 1',
                ['d' => substr($dedupeKey, 0, 120)],
                0
            );
        } catch (Throwable $e) {
            try {
                Logger::warning('health incident write failed', [
                    'kind' => $kind, 'error' => $e->getMessage(),
                ], 'health');
            } catch (Throwable $ignored) {
            }
            return 0;
        }
    }

    /**
     * Mark a fault class as no longer happening.
     *
     * Called by the same monitor that raised it, on the tick where the fault
     * is absent — self-healing incidents mean the list on the owner's screen
     * is what is wrong NOW, which is the only version of that list anyone
     * reads twice.
     */
    public static function resolve(string $dedupeKey): bool
    {
        try {
            return Database::run(
                "UPDATE health_incidents
                    SET status = 'resolved', resolved_at = :n
                  WHERE dedupe_key = :d AND status <> 'resolved'",
                ['d' => substr($dedupeKey, 0, 120), 'n' => date('Y-m-d H:i:s')]
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The open incidents, worst first.
     *
     * @return list<array<string,mixed>>
     */
    public static function open_list(int $limit = 50, ?string $kind = null): array
    {
        try {
            $params = [];
            $where  = "status IN ('open','ack')";
            if ($kind !== null && $kind !== '') {
                $where .= ' AND kind = :k';
                $params['k'] = $kind;
            }
            $limit = max(1, min(200, $limit));
            return Database::fetchAll(
                "SELECT * FROM health_incidents
                  WHERE {$where}
                  ORDER BY FIELD(severity,'critical','warn','info'), last_seen_at DESC
                  LIMIT {$limit}",
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Counts for the health screen's headline.
     *
     * @return array{critical:int, warn:int, info:int, total:int}
     */
    public static function summary(): array
    {
        $out = ['critical' => 0, 'warn' => 0, 'info' => 0, 'total' => 0];
        try {
            $rows = Database::fetchAll(
                "SELECT severity, COUNT(*) AS n FROM health_incidents
                  WHERE status IN ('open','ack') GROUP BY severity"
            );
            foreach ($rows as $r) {
                $sev = (string) $r['severity'];
                if (isset($out[$sev])) {
                    $out[$sev] = (int) $r['n'];
                    $out['total'] += (int) $r['n'];
                }
            }
        } catch (Throwable $e) {
            // A missing table (migration not yet run on live) reads as "no
            // incidents", which is the honest answer: we know nothing.
        }
        return $out;
    }

    /* =================================================================
     *  Heartbeat
     * ================================================================= */

    /**
     * Record that a cron job just ran. Called from cron_done().
     *
     * Best-effort by design: a job whose real work succeeded must not be
     * reported as failed because the heartbeat table is missing on a server
     * where the migration has not been applied yet.
     *
     * @param array<string,mixed> $result the line cron_done() is about to print
     */
    public static function beat(string $job, int $ms, array $result = [], bool $ok = true): void
    {
        try {
            $now  = date('Y-m-d H:i:s');
            $json = substr((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 500);

            Database::query(
                'INSERT INTO cron_runs
                    (job, last_run_at, last_ok_at, last_ms, last_result, ok_streak, fail_streak, runs_total)
                 VALUES (:j, :n, :okAt, :ms, :res, :okS, :failS, 1)
                 ON DUPLICATE KEY UPDATE
                    last_run_at = VALUES(last_run_at),
                    last_ok_at  = COALESCE(VALUES(last_ok_at), last_ok_at),
                    last_ms     = VALUES(last_ms),
                    last_result = VALUES(last_result),
                    ok_streak   = IF(:okS2 = 1, ok_streak + 1, 0),
                    fail_streak = IF(:okS3 = 1, 0, fail_streak + 1),
                    runs_total  = runs_total + 1',
                [
                    'j'     => substr($job, 0, 60),
                    'n'     => $now,
                    'okAt'  => $ok ? $now : null,
                    'ms'    => max(0, $ms),
                    'res'   => $json,
                    'okS'   => $ok ? 1 : 0,
                    'failS' => $ok ? 0 : 1,
                    'okS2'  => $ok ? 1 : 0,
                    'okS3'  => $ok ? 1 : 0,
                ]
            );
        } catch (Throwable $e) {
            // Silent: see the docblock. The file log still has the run.
        }
    }

    /**
     * How each job is doing, and how late it is.
     *
     * @return list<array<string,mixed>>
     */
    public static function heartbeats(): array
    {
        try {
            $rows = Database::fetchAll('SELECT * FROM cron_runs ORDER BY job');
            $now  = time();
            foreach ($rows as &$r) {
                $last = strtotime((string) $r['last_run_at']);
                $r['age_min'] = $last !== false ? (int) floor(($now - $last) / 60) : null;
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}
