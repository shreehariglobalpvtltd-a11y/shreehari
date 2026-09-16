<?php
/**
 * =====================================================================
 *  tests/pwa-http-test.php — the PWA master upgrade over real HTTP
 *  (13 Sep 2026).
 *
 *      SHG_TEST_BASE=http://localhost:8931 php -c .claude/php-dev.ini tests/pwa-http-test.php
 *
 *  Proves, against a running server:
 *    · the page ships the push key, the PWA script and the new hosts; the
 *      manifest and the worker carry the install / offline / push pieces;
 *    · /api/push.php — key endpoint, CSRF and identity gates, a keyed ticket
 *      link may subscribe, resubscribe needs the OLD endpoint, unsubscribe;
 *    · /api/occupancy.php — a sane fill for each of the next days, capped;
 *    · split-pay — only the booking's own mobile opens the shares, the
 *      shares add up to the fare, a forged share key is refused, a claim
 *      records name + UTR and never confirms the ticket, the public share
 *      page renders the invalid / open / claimed states.
 *
 *  It creates ONE pending two-seat online booking, and releases its seats
 *  and deletes it at the end (every FK to bookings cascades or nulls).
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/webpush.php';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$JAR  = sys_get_temp_dir() . '/shg_pwa_' . getmypid() . '.cookies';
$JAR2 = sys_get_temp_dir() . '/shg_pwa2_' . getmypid() . '.cookies';
@unlink($JAR);
@unlink($JAR2);

/** @return array{code:int, body:string, err:string, json:mixed} */
function req(string $method, string $path, array $opt = []): array
{
    global $JAR;
    $jar = $opt['jar'] ?? $JAR;
    $ch  = curl_init(BASE . $path);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    }
    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body, 'err' => $err, 'json' => json_decode((string) $body, true)];
}

/** The boot payload a page load hands the browser. */
function bootOf(string $html): array
{
    preg_match('/window\.SHG_BOOT = (\{.*?\});/s', $html, $m);
    $b = $m ? json_decode($m[1], true) : null;
    return is_array($b) ? $b : [];
}

/** A browser-shaped push subscription with a real P-256 key. */
function fakeSub(): array
{
    $pair = WebPush::generateKeyPair();
    return [
        'endpoint' => 'https://push.example.invalid/shg-http-test/' . bin2hex(random_bytes(10)),
        'keys'     => ['p256dh' => WebPush::b64u($pair['pub']), 'auth' => WebPush::b64u(random_bytes(16))],
    ];
}

// Rate buckets from earlier local runs must not fail this one (test DB only).
try { Database::query('DELETE FROM rate_limits'); } catch (Throwable $e) {}

echo "\n=== PWA master upgrade — over HTTP (" . BASE . ") ===\n\n";

$bookingId = 0;
$endpoints = [];

try {
    /* ---- 1. the page, the manifest, the worker --------------------- */
    $home = req('GET', '/');
    check('GET / answers 200', $home['code'] === 200, 'HTTP ' . $home['code'] . ($home['err'] !== '' ? ' ' . $home['err'] : ''));
    if ($home['code'] !== 200) {
        throw new RuntimeException('server not reachable at ' . BASE);
    }
    $boot    = bootOf($home['body']);
    $csrf    = (string) ($boot['csrf'] ?? '');
    $pushKey = (string) ($boot['push']['key'] ?? '');
    $rawKey  = WebPush::b64uDecode($pushKey);
    check('boot payload parses with a CSRF token', $boot !== [] && $csrf !== '');
    check('push is on and the VAPID public key reaches the page',
        ($boot['push']['on'] ?? false) === true && strlen($rawKey) === 65 && $rawKey[0] === "\x04", strlen($pushKey) . ' chars');
    check('the PWA script is on the page', str_contains($home['body'], '/assets/js/17-pwa.js?v='));
    check('iOS home-screen title is SHG Bus', str_contains($home['body'], 'name="apple-mobile-web-app-title" content="SHG Bus"'));
    check('route guide + UPI app hosts are in the markup',
        str_contains($home['body'], 'id="snRouteGuide"') && str_contains($home['body'], 'id="upiApps"'));

    $man = req('GET', '/manifest.webmanifest');
    $mj  = is_array($man['json']) ? $man['json'] : [];
    check('manifest: SHG Bus, standalone, portrait, start /',
        ($mj['short_name'] ?? '') === 'SHG Bus' && ($mj['display'] ?? '') === 'standalone'
        && ($mj['orientation'] ?? '') === 'portrait' && ($mj['start_url'] ?? '') === '/');
    $maskable = false;
    foreach ((array) ($mj['icons'] ?? []) as $ic) {
        if (str_contains((string) ($ic['purpose'] ?? ''), 'maskable')) { $maskable = true; }
    }
    check('manifest: a maskable icon exists', $maskable);

    $sw = req('GET', '/sw.js');
    check('sw.js: push, notificationclick and pushsubscriptionchange handlers',
        str_contains($sw['body'], "addEventListener('push'") && str_contains($sw['body'], "addEventListener('notificationclick'")
        && str_contains($sw['body'], "addEventListener('pushsubscriptionchange'"));
    check('sw.js: precaches the PWA script and the offline page',
        str_contains($sw['body'], "'/assets/js/17-pwa.js?v=' + ASSET_VER") && str_contains($sw['body'], "'/offline.html'"));

    $off = req('GET', '/offline.html');
    check('offline.html is served and reads the saved tickets', $off['code'] === 200 && str_contains($off['body'], 'shg:bookings'));

    /* ---- 2. push endpoint gates ------------------------------------ */
    $k = req('GET', '/api/push.php?action=key');
    check('push key endpoint matches the page', ($k['json']['ok'] ?? false) === true && ($k['json']['data']['key'] ?? '') === $pushKey);

    $sub1 = fakeSub();
    $endpoints[] = $sub1['endpoint'];
    $r = req('POST', '/api/push.php', ['json' => ['action' => 'subscribe', 'subscription' => $sub1]]);
    check('subscribe without the CSRF token is refused (419)', $r['code'] === 419, 'HTTP ' . $r['code']);
    $r = req('POST', '/api/push.php', ['headers' => ['X-CSRF-Token: ' . $csrf], 'json' => ['action' => 'subscribe', 'subscription' => $sub1]]);
    check('a guest with no ticket cannot subscribe (401)', $r['code'] === 401, 'HTTP ' . $r['code']);
    check('  ...and nothing was stored', (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($sub1['endpoint'])], 0) === 0);

    /* ---- 3. seat fill ---------------------------------------------- */
    $route = Database::fetch('SELECT from_city, to_city FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    $from  = (string) ($route['from_city'] ?? 'Surat');
    $to    = (string) ($route['to_city'] ?? 'Rupaidiha');
    $o     = req('GET', '/api/occupancy.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . '&days=7');
    $days  = $o['json']['data']['days'] ?? [];
    check('occupancy answers for ' . $from . ' → ' . $to,
        ($o['json']['ok'] ?? false) === true && is_array($days) && count($days) >= 1 && count($days) <= 7, count((array) $days) . ' days');
    $sane = true;
    $anySeats = false;
    foreach ((array) $days as $d) {
        if (!isset($d['pct'], $d['total'], $d['left']) || $d['pct'] < 0 || $d['pct'] > 100 || $d['left'] > $d['total']) { $sane = false; }
        if (($d['total'] ?? 0) > 0) { $anySeats = true; }
    }
    check('every day carries a 0–100 % fill with left <= total', $sane);
    check('at least one day has a bus with seats', $anySeats);
    $o2 = req('GET', '/api/occupancy.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . '&days=99');
    check('days is capped at 14', count((array) ($o2['json']['data']['days'] ?? [])) <= 14);

    /* ---- 4. a pending two-seat online booking to split -------------- */
    $date = date('Y-m-d', strtotime('+4 days'));
    $s    = req('POST', '/api/search.php', ['json' => ['from' => $from, 'to' => $to, 'date' => $date]]);
    $res  = $s['json']['data']['results'][0] ?? null;
    check('search finds a bus to book against', is_array($res), 'HTTP ' . $s['code'] . ' ' . substr($s['body'], 0, 160));
    if (!is_array($res)) {
        throw new RuntimeException('no bus to book');
    }
    $date = (string) ($s['json']['data']['date'] ?? $date);
    $av   = req('POST', '/api/seats.php', ['json' => ['routeId' => $res['routeId'], 'date' => $date]]);
    $free = (array) ($av['json']['data']['available'] ?? []);
    $fem  = (array) ($av['json']['data']['female'] ?? []);
    $pick = [];
    foreach ($free as $cand) {
        if (!in_array($cand, $fem, true)) { $pick[] = $cand; }
        if (count($pick) === 2) { break; }
    }
    check('two free berths that are not women-reserved', count($pick) === 2, json_encode($pick));
    if (count($pick) !== 2) {
        throw new RuntimeException('not enough free berths');
    }

    $phone     = '97' . random_int(10000000, 99999999);
    $isSleeper = ($res['coachType'] ?? '') === 'sleeper';
    $bk = req('POST', '/api/book.php', [
        'headers' => ['X-CSRF-Token: ' . $csrf],
        'json'    => [
            'routeId'    => $res['routeId'], 'travelDate' => $date, 'seats' => $pick,
            'passengers' => [
                ['seat' => $pick[0], 'name' => 'PWA Split Tester', 'age' => 31, 'gender' => 'Male'],
                ['seat' => $pick[1], 'name' => 'PWA Split Friend', 'age' => 29, 'gender' => 'Male'],
            ],
            'contact'       => ['phone' => $phone, 'email' => 'pwa@example.com', 'idType' => 'Voter ID', 'idNum' => 'GJPWA0001'],
            'bookingMode'   => $isSleeper ? 'sharing' : null,
            'boarding'      => is_string($res['boarding'][0] ?? null) ? $res['boarding'][0] : $from,
            'drop'          => is_string($res['drop'][0] ?? null) ? $res['drop'][0] : $to,
            'paymentMethod' => 'upi',
            'isCod'         => false,
        ],
    ]);
    $pnr = (string) ($bk['json']['data']['pnr'] ?? '');
    check('a guest two-seat UPI booking is created', $pnr !== '', 'HTTP ' . $bk['code'] . ' ' . substr($bk['body'], 0, 240));
    if ($pnr === '') {
        throw new RuntimeException('booking failed');
    }
    $row = Database::fetch('SELECT id, status, is_cod, total_amount FROM bookings WHERE pnr = :p', ['p' => $pnr]) ?? [];
    $bookingId = (int) ($row['id'] ?? 0);
    check('  ...pending, not cash', ($row['status'] ?? '') === 'pending' && (int) ($row['is_cod'] ?? 1) === 0, json_encode($row));

    /* ---- 5. split-pay ------------------------------------------------ */
    $h2    = req('GET', '/', ['jar' => $JAR2]);           // a stranger's fresh session
    $csrf2 = (string) (bootOf($h2['body'])['csrf'] ?? '');
    $H2    = ['X-CSRF-Token: ' . $csrf2];

    $r = req('POST', '/api/split-pay.php', ['jar' => $JAR2, 'json' => ['action' => 'plan', 'pnr' => $pnr, 'phone' => $phone]]);
    check('split-pay without the CSRF token is refused (419)', $r['code'] === 419, 'HTTP ' . $r['code']);
    $r = req('POST', '/api/split-pay.php', ['jar' => $JAR2, 'headers' => $H2, 'json' => ['action' => 'plan', 'pnr' => $pnr, 'phone' => '9000000001']]);
    check('a stranger with the wrong mobile cannot open the shares (403)', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = req('POST', '/api/split-pay.php', ['jar' => $JAR2, 'headers' => $H2, 'json' => ['action' => 'plan', 'pnr' => $pnr, 'phone' => $phone]]);
    $shares = (array) ($r['json']['data']['shares'] ?? []);
    check('the booking mobile opens one share per seat', ($r['json']['ok'] ?? false) === true && count($shares) === 2,
        'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    $sum = array_sum(array_map(static fn($x): float => (float) ($x['amount'] ?? 0), $shares));
    check('  ...the shares add up to the fare exactly', abs($sum - (float) ($row['total_amount'] ?? 0)) < 0.01, $sum . ' vs ' . ($row['total_amount'] ?? '?'));
    req('POST', '/api/split-pay.php', ['jar' => $JAR2, 'headers' => $H2, 'json' => ['action' => 'plan', 'pnr' => $pnr, 'phone' => $phone]]);
    check('  ...a second plan reuses the same rows',
        (int) Database::scalar('SELECT COUNT(*) FROM payment_shares WHERE booking_id = :b', ['b' => $bookingId], 0) === 2);

    $key1 = substr(Security::sign('split|' . $pnr . '|1'), 0, 20);
    $key2 = substr(Security::sign('split|' . $pnr . '|2'), 0, 20);
    check('the share link carries the signed key', str_contains((string) ($shares[0]['link'] ?? ''), 'k=' . $key1));

    $claim = static fn(array $extra): array => req('POST', '/api/split-pay.php', ['jar' => $JAR2, 'headers' => $H2,
        'json' => array_merge(['action' => 'claim', 'pnr' => $pnr, 's' => 1, 'k' => $key1, 'name' => 'Share Friend', 'utr' => '425600001111'], $extra)]);
    $r = $claim(['k' => 'forged00000000000000']);
    check('a forged share key is refused (403)', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = $claim(['s' => 2]);
    check('a key for share 1 cannot claim share 2 (403)', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = $claim(['utr' => '12']);
    check('a claim needs a real UTR (422)', $r['code'] === 422, 'HTTP ' . $r['code']);
    $r = $claim([]);
    check('a friend records their share with name + UTR', ($r['json']['data']['share']['status'] ?? '') === 'claimed',
        'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    $db = Database::fetch('SELECT status, payer_name, utr FROM payment_shares WHERE booking_id = :b AND share_no = 1', ['b' => $bookingId]) ?? [];
    check('  ...stored on the share row', ($db['status'] ?? '') === 'claimed' && ($db['utr'] ?? '') === '425600001111' && ($db['payer_name'] ?? '') === 'Share Friend');
    check('  ...and the ticket is still pending (a claim never confirms)',
        (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bookingId], '') === 'pending');

    $pg = req('GET', '/pay-share.php?pnr=' . rawurlencode($pnr) . '&s=1&k=bad', ['jar' => $JAR2]);
    check('share page: a bad key says the link is not valid', $pg['code'] === 200 && str_contains($pg['body'], 'not valid'));
    $pg2 = req('GET', '/pay-share.php?pnr=' . rawurlencode($pnr) . '&s=2&k=' . $key2, ['jar' => $JAR2]);
    check('share page: an open share shows its amount and the pay form',
        $pg2['code'] === 200 && str_contains($pg2['body'], 'id="claimForm"') && str_contains($pg2['body'], 'share 2 of 2'));
    $pg1 = req('GET', '/pay-share.php?pnr=' . rawurlencode($pnr) . '&s=1&k=' . $key1, ['jar' => $JAR2]);
    check('share page: a claimed share says who paid', str_contains($pg1['body'], 'Recorded as paid'));

    /* ---- 6. a keyed ticket link may subscribe this phone ------------ */
    $sub2 = fakeSub();
    $endpoints[] = $sub2['endpoint'];
    $r = req('POST', '/api/push.php', ['jar' => $JAR2, 'headers' => $H2,
        'json' => ['action' => 'subscribe', 'subscription' => $sub2, 'pnr' => $pnr, 'k' => 'wrongkey000000000000']]);
    check('a wrong ticket key cannot subscribe (401)', $r['code'] === 401, 'HTTP ' . $r['code']);
    $r = req('POST', '/api/push.php', ['jar' => $JAR2, 'headers' => $H2,
        'json' => ['action' => 'subscribe', 'subscription' => $sub2, 'pnr' => $pnr, 'k' => Ticket::downloadToken($pnr), 'lang' => 'ne']]);
    check('the keyed ticket link subscribes this phone', ($r['json']['ok'] ?? false) === true, 'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    $ps = Database::fetch('SELECT booking_id, phone, lang FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($sub2['endpoint'])]) ?? [];
    check('  ...tied to the booking and its mobile',
        (int) ($ps['booking_id'] ?? 0) === $bookingId && ($ps['phone'] ?? '') === normalisePhone($phone), json_encode($ps));
    check('  ...so a delay alert for this ticket reaches it', count(WebPush::forBooking($bookingId)) === 1);
    $st = req('POST', '/api/push.php', ['jar' => $JAR2, 'headers' => $H2, 'json' => ['action' => 'status', 'endpoint' => $sub2['endpoint']]]);
    check('status reports it registered without echoing the full mobile',
        ($st['json']['data']['registered'] ?? false) === true && !str_contains((string) ($st['json']['data']['phone'] ?? ''), $phone));

    $sub3 = fakeSub();
    $endpoints[] = $sub3['endpoint'];
    $r = req('POST', '/api/push.php', ['json' => ['action' => 'resubscribe', 'oldEndpoint' => 'https://push.example.invalid/never-seen', 'subscription' => $sub3]]);
    check('resubscribe with an unknown old endpoint is refused (404)', $r['code'] === 404, 'HTTP ' . $r['code']);
    $r = req('POST', '/api/push.php', ['json' => ['action' => 'resubscribe', 'oldEndpoint' => $sub2['endpoint'], 'subscription' => $sub3]]);
    check('the worker can move a rotated subscription (no CSRF, old endpoint proves it)',
        ($r['json']['ok'] ?? false) === true
        && (int) Database::scalar('SELECT booking_id FROM push_subscriptions WHERE endpoint_hash = :h AND is_active = 1', ['h' => sha1($sub3['endpoint'])], 0) === $bookingId,
        'HTTP ' . $r['code']);
    check('  ...and the old endpoint is retired',
        (int) Database::scalar('SELECT is_active FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($sub2['endpoint'])], 1) === 0);
    $r = req('POST', '/api/push.php', ['jar' => $JAR2, 'headers' => $H2, 'json' => ['action' => 'unsubscribe', 'endpoint' => $sub3['endpoint']]]);
    check('unsubscribe turns alerts off for the ticket', ($r['json']['data']['ok'] ?? false) === true && count(WebPush::forBooking($bookingId)) === 0);
} catch (Throwable $e) {
    check('suite ran to the end', false, $e->getMessage());
} finally {
    foreach ($endpoints as $ep) {
        try {
            $id = (int) Database::scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($ep)], 0);
            if ($id > 0) {
                Database::delete('push_log', 'subscription_id = :i', ['i' => $id]);
                Database::delete('push_subscriptions', 'id = :i', ['i' => $id]);
            }
        } catch (Throwable $e) {}
    }
    if ($bookingId > 0) {
        try {
            Seats::releaseBooking($bookingId);
            Database::delete('bookings', 'id = :i', ['i' => $bookingId]);
        } catch (Throwable $e) {
            echo "  (cleanup: booking {$bookingId} not removed: {$e->getMessage()})\n";
        }
    }
    @unlink($JAR);
    @unlink($JAR2);
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
