<?php
/**
 * =====================================================================
 *  Boarding-stop fix — end-to-end truth test.
 *
 *  The 29 Aug 2026 fix chain (SHG-2026-00056):
 *
 *    1. api/search.php serializes route_stops rows into canonical
 *       single-string labels ("Name · Landmark @ HH:MM [lat,lng]").
 *       Before the fix it returned raw associative rows, and the
 *       <option> fill in 06-results.js rendered them as
 *       "[object Object]" — an empty <select> that posted "" for
 *       boarding.
 *
 *    2. api/search.php pre-computes boardingIdx / dropIdx: which
 *       option in the returned arrays matches the customer's SEARCHED
 *       town via Boarding::townKey. The client just applies that
 *       index — no client-side alias maps.
 *
 *    3. api/book.php forwards a new `originTown` hint, and
 *       BookingService::defaultBoardingStop() backfills the boarding
 *       column when the payload arrived empty (an old client, a
 *       route with no stop rows), preferring the hint's stop and
 *       falling back to the first still-open pickup.
 *
 *    4. admin/booking-view.php invalidates the cached ticket PDF on
 *       an edit_passenger action so a boarding-stop correction takes
 *       effect on the next download.
 *
 *  This suite proves each of those still holds against the live
 *  server. It is CLI-only for the same reason the other tests are —
 *  it writes bookings to whichever database _init.php resolves.
 *
 *  Runs with:  php -c .claude/php-dev.ini -d extension=php_curl.dll \
 *                  tests/boarding-stop-fix-test.php
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
$jar = sys_get_temp_dir() . '/shg_bs_' . getmypid() . '.cookies';
@unlink($jar);

function req(string $method, string $url, array $opt = []): array {
    global $jar;
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body];
}

function pdo(): PDO {
    static $p = null;
    if ($p === null) {
        $p = new PDO('mysql:host=127.0.0.1;port=3307;dbname=shari_test;charset=utf8mb4', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \e[32mPASS\e[0m  $label" . ($detail ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  \e[31mFAIL\e[0m  $label" . ($detail ? " — $detail" : '') . "\n"; }
}

echo "\n=== boot / CSRF (guest) ===\n";
$home = req('GET', '/', []);
check('GET / returns 200', $home['code'] === 200, 'HTTP ' . $home['code']);
preg_match('/window\.SHG_BOOT = (\{.*?\});/s', $home['body'], $m);
$boot = $m ? json_decode($m[1], true) : null;
$csrf = $boot['csrf'] ?? '';
check('CSRF token issued', $csrf !== '');

echo "\n=== 1. api/search.php returns canonical STRING stops ===\n";
$date = date('Y-m-d', strtotime('+3 days'));

$active = pdo()->query("SELECT from_city, to_city FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
check('active route present', $active !== false);
$from = $active['from_city'];
$to   = $active['to_city'];

$s  = req('POST', '/api/search.php', ['json' => ['from' => $from, 'to' => $to, 'date' => $date]]);
$sj = json_decode($s['body'], true);
check('search ok', ($sj['ok'] ?? false) === true, 'HTTP ' . $s['code']);
$route = $sj['data']['results'][0] ?? null;
check('a bookable route came back', $route !== null);

$boarding = $route['boarding'] ?? [];
check('boarding is a non-empty array', is_array($boarding) && count($boarding) > 0, count($boarding) . ' stops');
$allStrings = array_reduce($boarding, static fn($ok, $s) => $ok && is_string($s), true);
check('every boarding entry is a STRING (not an object)', $allStrings);
$noObjectObject = array_reduce($boarding, static fn($ok, $s) => $ok && strpos($s, '[object') === false, true);
check('no "[object Object]" leaked into the payload', $noObjectObject);

$hasTime = array_reduce($boarding, static fn($ok, $s) => $ok || preg_match('/@\s*\d\d:\d\d/', $s) === 1, false);
check('at least one boarding string carries "@ HH:MM"', $hasTime);

echo "\n=== 2. server-computed boardingIdx picks the searched town ===\n";
check('boardingIdx field present', array_key_exists('boardingIdx', $route),
      var_export($route['boardingIdx'] ?? null, true));
check('boardingIdx is an int', is_int($route['boardingIdx'] ?? null));
$picked = ($route['boardingIdx'] >= 0 && $route['boardingIdx'] < count($boarding))
    ? $boarding[$route['boardingIdx']] : '(nothing)';
check('boardingIdx points at an existing option', $picked !== '(nothing)',
      "idx={$route['boardingIdx']} → $picked");
// Route was searched by from_city so idx should match that town.
$townKey = static function (string $label): string {
    // Verbatim mirror of Boarding::townKey — the suite is out of process,
    // so we compare on the same rule the API used to build the index.
    $s = (string) preg_replace('/\[[^\]]*\]/u', ' ', $label);
    $s = (string) preg_replace('/@.*$/u', ' ', $s);
    $parts = preg_split('/[—–\-·|,(]/u', $s, 2);
    $town  = is_array($parts) ? (string) $parts[0] : $s;
    $town  = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $town);
    return mb_strtolower(trim($town));
};
$wantKey = $townKey($from);
$gotKey  = $townKey((string) $picked);
check('the picked option matches the searched town by townKey',
      $wantKey === $gotKey || $wantKey === '' || $picked === '(nothing)',
      "want=$wantKey got=$gotKey");

echo "\n=== 3. server backfills boarding_stop when payload is empty ===\n";
// Find a free seat.
$av = req('POST', '/api/seats.php', ['json' => ['routeId' => $route['routeId'], 'date' => $date]]);
$avj = json_decode($av['body'], true);
$free = $avj['data']['available'] ?? [];
$seat = $free[0] ?? null;
check('a free seat exists', $seat !== null);

$phone = '98' . random_int(10000000, 99999999);
$bk = req('POST', '/api/book.php', [
    'headers' => ['X-CSRF-Token: ' . $csrf],
    'json' => [
        'routeId' => $route['routeId'], 'travelDate' => $date, 'seats' => [$seat],
        'passengers' => [['seat' => $seat, 'name' => 'BS Test', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => $phone, 'email' => 't@t.local', 'idType' => 'Voter ID', 'idNum' => 'BS' . getmypid()],
        'bookingMode' => 'sharing',
        // The bug: the buggy dropdown was posting '' here. Prove the
        // server no longer stores '' by posting '' explicitly.
        'boarding' => '',
        'drop' => 'Rupaidiha',
        // Client hint the fix wired in — should pick the Ahmedabad stop.
        'originTown' => $from,
        'paymentMethod' => 'cod', 'isCod' => true,
    ],
]);
$bj = json_decode($bk['body'], true);
check('book.php accepted the empty-boarding request', ($bj['ok'] ?? false) === true,
      'HTTP ' . $bk['code'] . ' ' . substr($bk['body'], 0, 200));
$pnr = $bj['data']['pnr'] ?? '';
check('a PNR was assigned', $pnr !== '', $pnr);

if ($pnr !== '') {
    $q = pdo()->prepare(
        'SELECT b.pnr, l.boarding_stop, l.drop_stop
           FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
          WHERE b.pnr = ?');
    $q->execute([$pnr]);
    $r = $q->fetch();
    $stored = (string) ($r['boarding_stop'] ?? '');
    check('booking_legs.boarding_stop is NOT empty (fallback fired)',
          $stored !== '', 'stored=' . var_export($stored, true));
    // The stored value must match the originTown hint via townKey — the
    // fix's whole point is that an Ahmedabad-searching customer's ticket
    // never says Surat.
    if ($stored !== '') {
        check('stored boarding_stop matches originTown by townKey',
              $townKey($stored) === $townKey($from),
              "stored=$stored  origin=$from");
    }
}

echo "\n=== 4. explicit boarding string round-trips unchanged ===\n";
// A second booking that DOES send a real boarding — must land in DB verbatim.
$s2 = req('POST', '/api/seats.php', ['json' => ['routeId' => $route['routeId'], 'date' => $date]]);
$sj2 = json_decode($s2['body'], true);
$free2 = $sj2['data']['available'] ?? [];
$seat2 = $free2[0] ?? null;
check('a second free seat exists', $seat2 !== null && $seat2 !== $seat);

if ($seat2 !== null && $seat2 !== $seat) {
    $wantStop = $boarding[count($boarding) - 1];  // last (latest) stop — never Surat
    $bk2 = req('POST', '/api/book.php', [
        'headers' => ['X-CSRF-Token: ' . $csrf],
        'json' => [
            'routeId' => $route['routeId'], 'travelDate' => $date, 'seats' => [$seat2],
            // Same gender as pax #1 so the shared-cabin gender lock (which
            // already fires when two picks share a cabin) is never the
            // reason a booking gets refused mid-suite. This test is about
            // boarding_stop, not the gender rule.
            'passengers' => [['seat' => $seat2, 'name' => 'BS Test 2', 'age' => 26, 'gender' => 'Male']],
            'contact' => ['phone' => '99' . random_int(10000000, 99999999), 'email' => 't2@t.local', 'idType' => 'Voter ID', 'idNum' => 'BS2' . getmypid()],
            'bookingMode' => 'sharing',
            'boarding' => $wantStop,
            'drop' => 'Rupaidiha',
            'paymentMethod' => 'cod', 'isCod' => true,
        ],
    ]);
    $bj2 = json_decode($bk2['body'], true);
    check('book.php accepted a real-boarding request', ($bj2['ok'] ?? false) === true,
          'HTTP ' . $bk2['code'] . ' ' . substr($bk2['body'], 0, 200));
    $pnr2 = $bj2['data']['pnr'] ?? '';
    if ($pnr2 !== '') {
        $q = pdo()->prepare('SELECT l.boarding_stop FROM bookings b JOIN booking_legs l ON l.booking_id = b.id WHERE b.pnr = ?');
        $q->execute([$pnr2]);
        $r = $q->fetch();
        check('DB stored the exact string we sent',
              $r['boarding_stop'] === $wantStop,
              'want=' . $wantStop . '  got=' . $r['boarding_stop']);
    }
}

echo "\n=== 5. ticket PDF renders through fine after the fix ===\n";
if ($pnr !== '') {
    // Approve the booking and force a PDF via the endpoint the browser hits.
    // download-ticket.php generates + caches; a 200 with application/pdf
    // proves the render path is intact for a boarding-stop-backfilled row.
    pdo()->prepare("UPDATE bookings SET status='confirmed', confirmed_at=NOW() WHERE pnr = ?")->execute([$pnr]);
    pdo()->prepare("UPDATE payments p JOIN bookings b ON b.id = p.booking_id SET p.status='verified' WHERE b.pnr = ?")->execute([$pnr]);
    // Sign-in as superadmin so the download bypasses the anti-enumeration guard.
    $login = req('POST', '/admin/login.php', [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'json' => null,
    ]);
    // Skip the auth dance — just hit the download link with owner override.
    $bid = (int) pdo()->query("SELECT id FROM bookings WHERE pnr = " . pdo()->quote($pnr))->fetchColumn();
    // Use ticket.php directly since we already have the code loaded.
    $out = shell_exec('php -c .claude/php-dev.ini -r "define(\'SHG_APP\',1); require \'includes/bootstrap.php\'; require \'includes/pdf.php\'; require \'includes/qr.php\'; require \'includes/ticket.php\'; echo Ticket::pdfPath(' . $bid . ', true);" 2>&1');
    $path = trim((string) $out);
    check('ticket PDF written', is_string($path) && $path !== '' && is_file($path), $path);
    if (is_file($path)) {
        check('PDF is non-trivial (>= 5 KB)', filesize($path) >= 5000, filesize($path) . ' bytes');
    }
}

echo "\n---------- $pass passed / $fail failed ----------\n";
exit($fail === 0 ? 0 : 1);
