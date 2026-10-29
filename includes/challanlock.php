<?php
/**
 * =====================================================================
 *  ChallanLock — "this is the list the driver has" (29 Oct 2026,
 *  management upgrade 3.3: finalize lock, history, diff).
 *
 *  FINALIZE stores a snapshot of the departure's passenger list (PNR,
 *  seat, name, pickup, drop, payment) with who and when. From then on the
 *  hub shows what changed since — passengers added, removed, moved seat
 *  or pickup — so the office knows to reprint and resend before the
 *  driver boards the wrong list.
 *
 *  It is a SOFT lock on purpose: selling is never blocked. Late tickets
 *  are legitimate here (the 24 h counter grace sells a seat on a bus that
 *  already left), and refusing them would push the desk back to paper.
 *  Re-finalizing (after resending the new challan) takes a new snapshot;
 *  every finalize is kept as a version.
 *
 *  HISTORY lists every rendering of the challan picture (challan_reports,
 *  written by ChallanPng) and every finalize, oldest first, numbered.
 *
 *  Table challan_locks self-creates; reads survive its absence.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ChallanLock
{
    private static bool $ready = false;

    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS challan_locks (
                    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    schedule_id   INT UNSIGNED NOT NULL,
                    version       INT UNSIGNED NOT NULL,
                    snapshot      MEDIUMTEXT   NOT NULL,
                    pax_count     INT UNSIGNED NOT NULL DEFAULT 0,
                    note          VARCHAR(255) NULL,
                    finalized_by  INT UNSIGNED NULL,
                    finalized_at  DATETIME     NOT NULL,
                    released_at   DATETIME     NULL,
                    released_by   INT UNSIGNED NULL,
                    UNIQUE KEY uq_sched_ver (schedule_id, version),
                    KEY idx_sched (schedule_id, released_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('challan_locks unavailable: ' . $e->getMessage());
            self::$ready = false;
        }
        return self::$ready;
    }

    /**
     * The departure's passenger list as the challan prints it, keyed by a
     * stable passenger id.
     *
     * @return array<string, array{pnr: string, seat: string, name: string, board: string, drop: string, pay: string}>
     */
    public static function current(int $scheduleId): array
    {
        $out = [];
        foreach (Database::fetchAll(
            "SELECT bp.id, bp.seat_no, bp.full_name, b.pnr, bl.boarding_stop, bl.drop_stop, b.is_cod,
                    (SELECT p.status FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay
               FROM booking_passengers bp
               JOIN bookings b      ON b.id = bp.booking_id
               JOIN booking_legs bl ON bl.id = bp.leg_id
              WHERE bl.schedule_id = :s AND b.status IN ('confirmed','pending','completed')
              ORDER BY LENGTH(bp.seat_no), bp.seat_no, bp.id",
            ['s' => $scheduleId]
        ) as $r) {
            $out[(string) $r['id']] = [
                'pnr'   => (string) $r['pnr'],
                'seat'  => (string) ($r['seat_no'] ?? ''),
                'name'  => (string) ($r['full_name'] ?? ''),
                'board' => self::stopName((string) ($r['boarding_stop'] ?? '')),
                'drop'  => self::stopName((string) ($r['drop_stop'] ?? '')),
                'pay'   => (string) ($r['pay'] ?? ((int) $r['is_cod'] === 1 ? 'cod_pending' : '')),
            ];
        }
        return $out;
    }

    /** "Surat · Departure @ 13:00 [21.17,72.83]" → "Surat". */
    private static function stopName(string $s): string
    {
        $s = (string) preg_replace('/\s*\[[^\]]*\]\s*$/', '', $s);
        return trim((string) preg_replace('/\s*·.*$/u', '', $s));
    }

    /** The live (not released) finalize of a departure, or null. */
    public static function active(int $scheduleId): ?array
    {
        if (!self::ensure()) {
            return null;
        }
        try {
            return Database::fetch(
                'SELECT l.*, a.full_name AS by_name FROM challan_locks l LEFT JOIN admins a ON a.id = l.finalized_by
                  WHERE l.schedule_id = :s AND l.released_at IS NULL ORDER BY l.version DESC LIMIT 1',
                ['s' => $scheduleId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Finalize (or re-finalize) the list the driver now has. Returns the version. */
    public static function finalize(int $scheduleId, int $by, string $note = ''): int
    {
        if (!self::ensure()) {
            throw new RuntimeException('Finalize is not available yet — ask the developer to check the database.');
        }
        if (Database::fetch('SELECT id FROM schedules WHERE id = :s', ['s' => $scheduleId]) === null) {
            throw new RuntimeException('Departure not found.');
        }
        $snap = self::current($scheduleId);
        $now  = date('Y-m-d H:i:s');
        return (int) Database::transaction(static function () use ($scheduleId, $by, $note, $snap, $now): int {
            Database::run('UPDATE challan_locks SET released_at = :n, released_by = :b WHERE schedule_id = :s AND released_at IS NULL',
                ['n' => $now, 'b' => $by ?: null, 's' => $scheduleId]);
            $ver = (int) Database::scalar('SELECT COALESCE(MAX(version), 0) + 1 FROM challan_locks WHERE schedule_id = :s', ['s' => $scheduleId], 1);
            Database::insert('challan_locks', [
                'schedule_id'  => $scheduleId,
                'version'      => $ver,
                'snapshot'     => (string) json_encode($snap, JSON_UNESCAPED_UNICODE),
                'pax_count'    => count($snap),
                'note'         => ($n = Security::clean($note, 255)) !== '' ? $n : null,
                'finalized_by' => $by ?: null,
                'finalized_at' => $now,
            ]);
            Logger::audit('challan.finalize', 'schedule', (string) $scheduleId, null, ['version' => $ver, 'passengers' => count($snap)], $note);
            return $ver;
        });
    }

    /** Lift the finalize (office), e.g. the trip was rebuilt. */
    public static function release(int $scheduleId, int $by, string $reason): void
    {
        $reason = Security::clean($reason, 255);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Write why the finalize is lifted.');
        }
        $lock = self::active($scheduleId);
        if ($lock === null) {
            return;
        }
        Database::run('UPDATE challan_locks SET released_at = NOW(), released_by = :b WHERE id = :i', ['b' => $by ?: null, 'i' => (int) $lock['id']]);
        Logger::audit('challan.release', 'schedule', (string) $scheduleId, ['version' => (int) $lock['version']], null, $reason, $reason);
    }

    /**
     * What changed since the active finalize.
     *
     * @return array{added: list<array<string,string>>, removed: list<array<string,string>>, changed: list<array{before: array<string,string>, after: array<string,string>}>, n: int}
     */
    public static function diff(int $scheduleId): array
    {
        $out  = ['added' => [], 'removed' => [], 'changed' => [], 'n' => 0];
        $lock = self::active($scheduleId);
        if ($lock === null) {
            return $out;
        }
        $was = json_decode((string) $lock['snapshot'], true);
        $was = is_array($was) ? $was : [];
        $now = self::current($scheduleId);
        foreach ($now as $id => $p) {
            if (!isset($was[$id])) {
                $out['added'][] = $p;
            } elseif ($was[$id]['seat'] !== $p['seat'] || $was[$id]['board'] !== $p['board'] || $was[$id]['drop'] !== $p['drop'] || $was[$id]['name'] !== $p['name']) {
                $out['changed'][] = ['before' => $was[$id], 'after' => $p];
            }
        }
        foreach ($was as $id => $p) {
            if (!isset($now[$id])) {
                $out['removed'][] = $p;
            }
        }
        $out['n'] = count($out['added']) + count($out['removed']) + count($out['changed']);
        return $out;
    }

    /**
     * Every rendering and every finalize of this departure's challan,
     * oldest first: [{when, kind: render|finalize|release, what, by}].
     *
     * @return list<array{when: string, kind: string, what: string, by: string}>
     */
    public static function history(int $scheduleId): array
    {
        $rows = [];
        try {
            foreach (Database::fetchAll(
                'SELECT c.generated_at, c.trigger_event, c.fingerprint, a.full_name
                   FROM challan_reports c LEFT JOIN admins a ON a.id = c.generated_by
                  WHERE c.schedule_id = :s ORDER BY c.id',
                ['s' => $scheduleId]
            ) as $r) {
                $rows[] = ['when' => (string) $r['generated_at'], 'kind' => 'render',
                           'what' => 'Challan picture drawn (' . (string) $r['trigger_event'] . ') · data ' . substr((string) $r['fingerprint'], 0, 8),
                           'by' => (string) ($r['full_name'] ?? '')];
            }
        } catch (Throwable $e) { /* challan_reports optional */ }
        if (self::ensure()) {
            try {
                foreach (Database::fetchAll(
                    'SELECT l.*, a.full_name AS by_name, r.full_name AS rel_name FROM challan_locks l
                       LEFT JOIN admins a ON a.id = l.finalized_by LEFT JOIN admins r ON r.id = l.released_by
                      WHERE l.schedule_id = :s ORDER BY l.version',
                    ['s' => $scheduleId]
                ) as $l) {
                    $rows[] = ['when' => (string) $l['finalized_at'], 'kind' => 'finalize',
                               'what' => 'Finalized v' . (int) $l['version'] . ' · ' . (int) $l['pax_count'] . ' passengers' . ((string) ($l['note'] ?? '') !== '' ? ' · ' . $l['note'] : ''),
                               'by' => (string) ($l['by_name'] ?? '')];
                    if ((string) ($l['released_at'] ?? '') !== '') {
                        $rows[] = ['when' => (string) $l['released_at'], 'kind' => 'release',
                                   'what' => 'v' . (int) $l['version'] . ' replaced / lifted', 'by' => (string) ($l['rel_name'] ?? '')];
                    }
                }
            } catch (Throwable $e) { /* no locks yet */ }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['when'], $b['when']));
        return $rows;
    }
}
