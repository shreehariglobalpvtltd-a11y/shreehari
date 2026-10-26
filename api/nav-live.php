<?php
/**
 * GET /api/nav-live.php?ch=share:<token>,bus,rep&since=<ms>[&lat=..&lng=..]
 *
 * The long-poll door to SHARI NAV live (Phase 2, 22 Oct 2026), for phones
 * whose network blocks WebSockets. Same channels and the same {ch, d, t}
 * events as wss://…/live/, read straight from the tables. Waits up to 4 s
 * for something newer than `since`, then answers {now, ev:[...]} - the
 * page calls again with since = now. The wait is short on purpose: every
 * waiting phone holds one of the 24 PHP workers.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/navlive.php';

header('Cache-Control: no-store');

try {
    Security::requireRateLimit('nav_live_poll', Security::clientIp(), 90, 60);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $chs = array_slice(array_unique(array_filter(explode(',', (string) ($_GET['ch'] ?? '')))), 0, 6);
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $here = $lat !== false && $lng !== false && NavLive::inArea((float) $lat, (float) $lng) ? [(float) $lat, (float) $lng] : null;

    $collect = static function () use ($chs, $since, $here): array {
        $ev = [];
        foreach ($chs as $ch) {
            if (preg_match('/^share:([a-f0-9]{32})$/', $ch, $m) === 1) {
                $row = NavLive::share($m[1]);
                if ($row !== null && (int) NavLive::ms($row['updated_at']) > $since) {
                    $ev[] = ['ch' => $ch, 'd' => NavLive::shareOut($row, $since === 0), 't' => NavLive::ms($row['updated_at'])];
                }
            } elseif ($ch === 'bus') {
                $snap = NavLive::buses();
                if ((int) $snap['t'] > $since) {
                    $ev[] = ['ch' => 'bus', 'd' => $snap, 't' => $snap['t']];
                }
            } elseif ($ch === 'rep' && $here !== null && $since > 0) {
                /* first call: the page loads the list from nav-reports.php; here only changes */
                $rows = Database::fetchAll(
                    'SELECT * FROM nav_reports WHERE updated_at > :s AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2
                      ORDER BY updated_at LIMIT 50',
                    ['s' => date('Y-m-d H:i:s', intdiv($since, 1000)), 'la1' => $here[0] - 0.3, 'la2' => $here[0] + 0.3,
                     'lo1' => $here[1] - 0.35, 'lo2' => $here[1] + 0.35]
                );
                foreach ($rows as $r) {
                    $o = NavLive::reportOut($r);
                    $ev[] = ['ch' => 'rep', 'd' => ['op' => $o['status'] === 'active' ? 'upd' : 'del', 'r' => $o], 't' => NavLive::ms($r['updated_at'])];
                }
            }
        }
        return $ev;
    };

    $ev = [];
    for ($i = 0; $i < 5; $i++) {
        $ev = $collect();
        if ($ev !== [] || $since === 0 || connection_aborted()) {
            break;
        }
        sleep(1);
    }
    Response::success(['now' => (int) round(microtime(true) * 1000) - 1000, 'ev' => $ev]);
} catch (Throwable $e) {
    Response::serverError($e);
}
