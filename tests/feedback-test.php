<?php
/**
 * =====================================================================
 *  POST-JOURNEY RATING — api/feedback.php
 *
 *  The `feedback` table shipped in schema.sql with the first build and was
 *  never wired to anything. This suite covers the endpoint that finally
 *  uses it, and it leans hardest on the two rules that matter:
 *
 *    - a PNR is NOT a bearer token. They are minted sequentially
 *      (SHG-2026-00001, -00002 …), so anyone could count upwards. A
 *      caller must hold the HMAC ticket key or be the signed-in owner.
 *    - a journey can only be rated once it has arrived, and only once.
 *
 *  Driven over real HTTP, because both of those live in the request path.
 *
 *    1. dev server on :8899
 *    2. php -c .claude/php-dev.ini tests/feedback-test.php
 *
 *  Rates one existing arrived booking and deletes the row again.
 *  CLI only.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ticket.php';

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');

$PASS = 0;
$FAIL = 0;

function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

/** @return array{code:int, body:string, json:array} */
function req(string $jar, string $method, string $path, array $opt = []): array
{
    $ch = curl_init(BASE . $path);
    $headers = ['Accept: application/json'];
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
    ];
    if (isset($opt['json'])) {
        $headers[] = 'Content-Type: application/json';
        if (!empty($opt['csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['csrf']; }
        $o[CURLOPT_POSTFIELDS] = json_encode($opt['json']);
    }
    $o[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $o);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true) ?: []];
}

/* A live server is required; without it every assertion below would
   "pass" or "fail" for reasons that have nothing to do with the code. */
(function (): void {
    $ch = curl_init(BASE . '/index.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
    $bad = curl_errno($ch) !== 0 || (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 0;
    curl_close($ch);
    if ($bad) {
        fwrite(STDERR, "\n  CANNOT RUN — no server at " . BASE . "\n"
            . "  Start it:  preview_start \"shari-php\"\n\n");
        exit(2);
    }
})();

echo "\n=== Post-journey rating (api/feedback.php) ===\n\n";

$jar     = sys_get_temp_dir() . '/shg_fb_' . getmypid() . '.cookies';
$pnr     = '';
$bid     = 0;
$restore = null;

try {
    Database::pdo()->exec('DELETE FROM rate_limits');

    /* ---- pick a confirmed booking and make its trip "arrived" ------- */
    $row = Database::fetch(
        "SELECT b.id, b.pnr, bl.schedule_id
           FROM bookings b
           JOIN booking_legs bl ON bl.booking_id = b.id
          WHERE b.status = 'confirmed'
          ORDER BY b.id DESC LIMIT 1"
    );
    if ($row === null) {
        check('a confirmed booking exists to rate', false, 'seed the test DB first');
        throw new RuntimeException('no fixture');
    }
    $bid  = (int) $row['id'];
    $pnr  = (string) $row['pnr'];
    $sid  = (int) $row['schedule_id'];
    $key  = Ticket::downloadToken($pnr);

    Database::pdo()->prepare('DELETE FROM feedback WHERE booking_id = ?')->execute([$bid]);

    $wasStatus = (string) Database::scalar('SELECT status FROM schedules WHERE id = :s', ['s' => $sid], 'scheduled');
    $restore   = static function () use ($sid, $wasStatus, $bid): void {
        Database::update('schedules', ['status' => $wasStatus], 'id = :s', ['s' => $sid]);
        Database::pdo()->prepare('DELETE FROM feedback WHERE booking_id = ?')->execute([$bid]);
    };

    /* ---- a session + CSRF token, exactly as the SPA gets them ------- */
    $home = req($jar, 'GET', '/index.php');
    preg_match('/"csrf"\s*:\s*"([a-f0-9]+)"/', $home['body'], $m);
    $csrf = $m[1] ?? '';
    check('the app hands out a CSRF token', $csrf !== '');

    /* ---- 1. cannot rate a trip that has not arrived ----------------- */
    Database::update('schedules', ['status' => 'departed'], 'id = :s', ['s' => $sid]);
    $early = req($jar, 'POST', '/api/feedback.php',
        ['csrf' => $csrf, 'json' => ['pnr' => $pnr, 'k' => $key, 'rating' => 5]]);
    check('a journey still under way cannot be rated', $early['code'] === 409,
        'HTTP ' . $early['code']);

    /* ---- 2. the bus arrives ----------------------------------------- */
    Database::update('schedules', ['status' => 'arrived'], 'id = :s', ['s' => $sid]);

    /* ---- 3. a stranger holding only the PNR is refused --------------- */
    $stranger = sys_get_temp_dir() . '/shg_fb_x_' . getmypid() . '.cookies';
    $sh = req($stranger, 'GET', '/index.php');
    preg_match('/"csrf"\s*:\s*"([a-f0-9]+)"/', $sh['body'], $m2);
    $csrf2 = $m2[1] ?? '';
    $noKey = req($stranger, 'POST', '/api/feedback.php',
        ['csrf' => $csrf2, 'json' => ['pnr' => $pnr, 'rating' => 5]]);
    check('the PNR alone is NOT enough to rate someone else\'s trip', $noKey['code'] === 403,
        'HTTP ' . $noKey['code']);
    $badKey = req($stranger, 'POST', '/api/feedback.php',
        ['csrf' => $csrf2, 'json' => ['pnr' => $pnr, 'k' => str_repeat('0', 20), 'rating' => 5]]);
    check('  ...and a wrong key is refused too', $badKey['code'] === 403, 'HTTP ' . $badKey['code']);
    @unlink($stranger);

    /* ---- 4. no CSRF token at all ------------------------------------ */
    $noCsrf = req($jar, 'POST', '/api/feedback.php',
        ['json' => ['pnr' => $pnr, 'k' => $key, 'rating' => 5]]);
    check('a request with no CSRF token is refused', $noCsrf['code'] === 419,
        'HTTP ' . $noCsrf['code']);

    /* ---- 5. out-of-range scores ------------------------------------- */
    $bad = req($jar, 'POST', '/api/feedback.php',
        ['csrf' => $csrf, 'json' => ['pnr' => $pnr, 'k' => $key, 'rating' => 9]]);
    check('a rating outside 1..5 is rejected', $bad['code'] === 422, 'HTTP ' . $bad['code']);

    /* ---- 6. the happy path ------------------------------------------ */
    $ok = req($jar, 'POST', '/api/feedback.php', ['csrf' => $csrf, 'json' => [
        'pnr' => $pnr, 'k' => $key,
        'rating' => 4, 'comfort' => 5, 'punctuality' => 3,
        'comment' => 'Seat was clean, bus left 20 min late.',
    ]]);
    check('the ticket holder can rate an arrived journey', $ok['code'] === 200,
        'HTTP ' . $ok['code'] . ' ' . substr($ok['body'], 0, 90));

    $saved = Database::fetch('SELECT * FROM feedback WHERE booking_id = :b', ['b' => $bid]);
    check('  the row is stored', $saved !== null);
    check('  rating + sub-scores kept', $saved && (int) $saved['rating'] === 4
        && (int) $saved['comfort'] === 5 && (int) $saved['punctuality'] === 3);
    check('  a sub-score the passenger skipped stays NULL, not 0',
        $saved && $saved['staff'] === null);
    check('  the comment is stored', $saved && str_contains((string) $saved['comment'], 'Seat was clean'));
    check('  it is NOT public until someone in the office says so',
        $saved && (int) $saved['is_public'] === 0);
    check('  the lead passenger name is captured', $saved && trim((string) $saved['name']) !== '');

    /* ---- 7. one per booking ------------------------------------------ */
    $again = req($jar, 'POST', '/api/feedback.php',
        ['csrf' => $csrf, 'json' => ['pnr' => $pnr, 'k' => $key, 'rating' => 1]]);
    check('the same journey cannot be rated twice', $again['code'] === 409,
        'HTTP ' . $again['code']);
    $n = (int) Database::scalar('SELECT COUNT(*) FROM feedback WHERE booking_id = :b', ['b' => $bid], 0);
    check('  still exactly one row', $n === 1, $n . ' rows');

} catch (Throwable $e) {
    if ($e->getMessage() !== 'no fixture') {
        check('unexpected error: ' . $e->getMessage(), false);
    }
} finally {
    if ($restore !== null) { $restore(); }
    @unlink($jar);
    try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
}

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
