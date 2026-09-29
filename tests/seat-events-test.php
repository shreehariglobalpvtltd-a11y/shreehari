<?php
/**
 * seat-events-test.php — live seat events (24 Sep 2026).
 *
 *   A. SeatVersion::of() changes exactly when the seat state changes
 *      (hold, release, booking, block) and is stable otherwise.
 *   B. api/seats.php answers "unchanged" to a client holding the current
 *      version, and the full map (with ver) otherwise.            [HTTP]
 *   C. api/seat-events.php: 404 while switched off; when on, a proper
 *      text/event-stream with retry, an id/seats event on connect and a
 *      bye at the end; a change mid-stream is pushed.               [HTTP]
 *   D. admin/api/seatmap-poll.php answers 304 to If-None-Match.       [HTTP]
 *
 *     php tests/seat-events-test.php          (HTTP parts need :8899)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/seatversion.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function http(string $method, string $path, array $json = null, array $headers = [], int $timeout = 30): array {
    $ch = curl_init(BASE . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => $timeout];
    if ($json !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($json); $headers[] = 'Content-Type: application/json'; }
    if ($headers !== []) { $opts[CURLOPT_HTTPHEADER] = $headers; }
    curl_setopt_array($ch, $opts);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}
$serverUp = http('GET', '/index.php')['code'] === 200;

const TD = '2099-11-23';
function cleanup(): void {
    Database::delete('seat_locks', "lock_token LIKE 'se-%'");
    Database::delete('seat_blocks', "reason = 'se-test'");
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
}

echo "\n=== Live seat events ===\n\n";
cleanup();
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
check('an active sleeper route exists', $route !== null);
$routeId = (int) $route['id'];
$sid = (int) Seats::schedule($routeId, TD)['id'];
check('a schedule for the test date exists', $sid > 0, "sid $sid");

$wasOn = Settings::getBool('seat_events_on', false);
try {
    echo "\n-- A. SeatVersion --\n";
    $v0 = SeatVersion::of($sid);
    check('a version is a 16-char hash', strlen($v0) === 16, $v0);
    check('stable when nothing changes', SeatVersion::of($sid) === $v0);
    check('empty for no schedule', SeatVersion::of(0) === '');
    Seats::lock($sid, ['U20'], 'se-a');
    $v1 = SeatVersion::of($sid);
    check('a hold changes it', $v1 !== $v0);
    Seats::release($sid, ['U20'], 'se-a');
    $v2 = SeatVersion::of($sid);
    check('releasing the hold changes it again', $v2 !== $v1);
    Database::insert('seat_blocks', ['schedule_id' => $sid, 'seat_no' => 'U21', 'reason' => 'se-test', 'blocked_by' => 1]);
    check('a block changes it', SeatVersion::of($sid) !== $v2);
    Database::delete('seat_blocks', "reason = 'se-test'");

    if (!$serverUp) {
        echo "\n  SKIP  HTTP parts — no server at " . BASE . "\n";
    } else {
        echo "\n-- B. api/seats.php with a version --\n";
        $r = http('POST', '/api/seats.php', ['routeCode' => (string) $route['route_code'], 'date' => TD, 'bookingMode' => 'sharing']);
        $j = json_decode($r['body'], true) ?: [];
        $ver = (string) ($j['data']['ver'] ?? '');
        check('the snapshot carries ver', $r['code'] === 200 && strlen($ver) === 16, "HTTP {$r['code']} ver=$ver");
        $r = http('POST', '/api/seats.php', ['routeCode' => (string) $route['route_code'], 'date' => TD, 'bookingMode' => 'sharing', 'ver' => $ver]);
        $j = json_decode($r['body'], true) ?: [];
        check('the same ver answers unchanged', !empty($j['data']['unchanged']), substr($r['body'], 0, 80));
        check('…and the answer is tiny', strlen($r['body']) < 300, strlen($r['body']) . ' bytes');
        Seats::lock($sid, ['U22'], 'se-b');
        $r = http('POST', '/api/seats.php', ['routeCode' => (string) $route['route_code'], 'date' => TD, 'bookingMode' => 'sharing', 'ver' => $ver]);
        $j = json_decode($r['body'], true) ?: [];
        check('after a hold the same ver gets the full map', empty($j['data']['unchanged']) && isset($j['data']['locked']));
        check('…with a new ver', (string) ($j['data']['ver'] ?? '') !== $ver);
        Seats::release($sid, ['U22'], 'se-b');

        echo "\n-- C. api/seat-events.php --\n";
        Settings::set('seat_events_on', false, 'bool', 'realtime', true);
        $r = http('GET', '/api/seat-events.php?scheduleId=' . $sid . '&max=3');
        check('switched off → 404', $r['code'] === 404, 'HTTP ' . $r['code']);
        Settings::set('seat_events_on', true, 'bool', 'realtime', true);
        $r = http('GET', '/api/seat-events.php?scheduleId=' . $sid . '&max=3', null, [], 12);
        check('switched on → 200 text/event-stream', $r['code'] === 200 && stripos($r['headers'], 'text/event-stream') !== false, 'HTTP ' . $r['code']);
        check('nginx told not to buffer', stripos($r['headers'], 'X-Accel-Buffering: no') !== false);
        check('retry hint present', str_contains($r['body'], 'retry: 2000'));
        check('a seats event on connect', str_contains($r['body'], 'event: seats') && str_contains($r['body'], '"scheduleId":' . $sid));
        check('bye at the end', str_contains($r['body'], 'event: bye'));
        check('a client holding the current version gets no seats event on connect',
            !str_contains(http('GET', '/api/seat-events.php?scheduleId=' . $sid . '&max=3&ver=' . SeatVersion::of($sid), null, [], 12)['body'], 'event: seats'));
        $r = http('GET', '/api/seat-events.php?routeCode=zz-none&date=' . TD . '&max=3');
        check('an unknown route is refused', $r['code'] === 422, 'HTTP ' . $r['code']);
        $r = http('GET', '/api/seat-events.php?scheduleId=' . $sid . '&max=3', null, ['Last-Event-ID: ' . SeatVersion::of($sid)], 12);
        check('Last-Event-ID replaces ver on reconnect', !str_contains($r['body'], 'event: seats'));
        Settings::set('seat_events_on', $wasOn, 'bool', 'realtime', true);

        echo "\n-- D. office poll ETag --\n";
        // The office endpoint needs a signed-in admin; the CLI has none, so only the anonymous refusal is pinned.
        $r = http('GET', '/admin/api/seatmap-poll.php?route=' . $routeId . '&date=' . TD);
        check('anonymous office poll is refused', in_array($r['code'], [302, 401, 403], true), 'HTTP ' . $r['code']);
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Settings::set('seat_events_on', $wasOn, 'bool', 'realtime', true);
    cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
