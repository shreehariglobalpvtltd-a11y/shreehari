<?php
/**
 * =====================================================================
 *  checkout-ticket-premium-test.php — the customer screens tell the truth
 *  (wave 2 FD, 8 Oct 2026)
 *
 *  The client half lives in tests/checkout-ticket-premium-test.js (it runs
 *  the shipped JS functions). This suite:
 *
 *    • hands that test the SERVER's Boarding::pickupTs() for the same
 *      pickups, so the Trip Companion's countdown can never drift from the
 *      reminders / live pill again (21:00, 23:00, past midnight)
 *    • pins the server payload the new ticket screen reads (read-only HTTP,
 *      when SHG_TEST_BASE is set): contactCountry, verifyUrl, the keyed
 *      ticketUrl + trackUrl, wa.state, delayMin / tripStatus, pickupMap,
 *      live_status — and that the key really opens the PNG for a stranger
 *      browser (no session) and the track token opens /api/whereis.php
 *
 *    php -c .claude/php-dev.ini tests/checkout-ticket-premium-test.php
 *    SHG_TEST_BASE=http://localhost:8986 php ... (adds the HTTP half)
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  {$l}" . ($extra !== '' ? " — {$extra}" : '') . "\n"; }
}

echo "\n=== Checkout + ticket premium (server half) ===\n\n";
$root = dirname(__DIR__);

echo "-- 1. Trip Companion pickup time = Boarding::pickupTs (client test with server answers) --\n";
/* the same cases as the JS test (route r2 departs 13:00, r7 18:00) */
$cases = [
    'surat13'      => ['2026-10-20', 'Surat · Departure @ 13:00 [21.1702,72.8311]', '13:00'],
    'chiloda21'    => ['2026-10-20', 'Hari Pvt. Ltd. Parking - Nana Chiloda @ 21:00', '13:00'],
    'mehsana23'    => ['2026-10-20', 'Mehsana Head Office - Silver Complex @ 23:00 [23.588,72.369]', '13:00'],
    'pastMidnight' => ['2026-10-20', 'Vadodara @ 00:30', '13:00'],
    'noTime'       => ['2026-10-20', 'Kamrej', '13:00'],
    'r7Return'     => ['2026-10-20', 'Rupaidiha @ 18:00', '18:00'],
];
$srv = [];
foreach ($cases as $k => [$d, $stop, $dep]) {
    $srv[$k] = Boarding::pickupTs($d, $stop, null, $dep);
}
check('the server puts a 21:00 pickup at 21:00 IST and 00:30 on the next day',
    date('Y-m-d H:i', $srv['chiloda21']) === '2026-10-20 21:00' && date('Y-m-d H:i', $srv['pastMidnight']) === '2026-10-21 00:30',
    date('Y-m-d H:i', $srv['chiloda21']) . ' / ' . date('Y-m-d H:i', $srv['pastMidnight']));
$tmp = tempnam(sys_get_temp_dir(), 'shgfd');
file_put_contents($tmp, json_encode($srv));
exec('node --version 2>&1', $probe, $pc);
if ($pc !== 0) {
    echo "  (skipped: node not on PATH — the client half needs it)\n";
} else {
    $out = [];
    exec('node ' . escapeshellarg($root . '/tests/checkout-ticket-premium-test.js') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    $fails = array_values(array_filter($out, static fn(string $l): bool => str_contains($l, 'FAIL')));
    $sum   = (string) (array_values(array_filter($out, static fn(string $l): bool => (bool) preg_match('/\d+ passed, \d+ failed/', $l)))[0] ?? '');
    check('client test (shipped JS, with server pickup times) passes', $rc === 0, trim($sum) . ($fails ? ' — ' . trim(implode(' | ', array_slice($fails, 0, 4))) : ''));
    check('...and it compared every pickup with the server', count(array_filter($out, static fn(string $l): bool => str_contains($l, 'same as the server Boarding::pickupTs'))) === count($cases));
}
@unlink($tmp);

echo "-- 2. The ticket payload the app reads (read-only HTTP) --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
$row = Database::fetch(
    "SELECT b.pnr, b.contact_phone FROM bookings b
      WHERE b.status = 'confirmed' AND b.contact_phone REGEXP '^[6-9][0-9]{9}$'
        AND b.contact_phone NOT IN ('0000000000','1111111111','9999999999')
        AND EXISTS (SELECT 1 FROM booking_legs bl WHERE bl.booking_id = b.id)
      ORDER BY b.id DESC LIMIT 1"
);
if ($base === '' || $row === null) {
    echo "  (skipped: " . ($base === '' ? 'SHG_TEST_BASE not set' : 'no confirmed booking with a real mobile in this DB') . ")\n";
} else {
    $get = static function (string $url) use ($base): array {
        $ch = curl_init($base . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        return ['code' => $code, 'body' => substr($raw, $hs), 'ctype' => preg_match('/^Content-Type:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
    };
    $pnr = (string) $row['pnr'];
    $r   = $get('/api/track.php?pnr=' . rawurlencode($pnr) . '&phone=' . rawurlencode((string) $row['contact_phone']));
    $j   = json_decode($r['body'], true);
    $d   = is_array($j) ? ($j['data'] ?? []) : [];
    check("track.php answers $pnr in full for its own mobile", $r['code'] === 200 && empty($d['partial']), 'HTTP ' . $r['code']);
    foreach (['contactCountry', 'verifyUrl', 'ticketUrl', 'trackUrl', 'delayMin', 'delayNote', 'tripStatus', 'live_status', 'paidAmount', 'isCod'] as $k) {
        check("payload carries '$k'", array_key_exists($k, $d));
    }
    check("payload carries 'pickupMap' (null when the stop has no official coordinates)", array_key_exists('pickupMap', $d) && ($d['pickupMap'] === null || str_starts_with((string) $d['pickupMap'], 'https://www.google.com/maps/')));
    check("wa is null or carries state delivered|pending|failed", !array_key_exists('wa', $d) || $d['wa'] === null || in_array($d['wa']['state'] ?? '', ['delivered', 'pending', 'failed'], true), json_encode($d['wa'] ?? null));
    $k  = (string) (parse_url((string) ($d['ticketUrl'] ?? ''), PHP_URL_QUERY) ? (static function (string $q): string { parse_str($q, $o); return (string) ($o['k'] ?? ''); })((string) parse_url((string) $d['ticketUrl'], PHP_URL_QUERY)) : '');
    $tk = (string) (parse_url((string) ($d['trackUrl'] ?? ''), PHP_URL_QUERY) ? (static function (string $q): string { parse_str($q, $o); return (string) ($o['k'] ?? ''); })((string) parse_url((string) $d['trackUrl'], PHP_URL_QUERY)) : '');
    check('ticketUrl carries the download key the app keeps (dlKey)', $k !== '' && Ticket::checkDownloadToken($pnr, $k));
    check('verifyUrl is the keyed verify page (the on-screen QR)', str_contains((string) ($d['verifyUrl'] ?? ''), 'verify-ticket.php?pnr=') && str_contains((string) $d['verifyUrl'], '&k='));
    $img = $get('/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&view=1&k=' . rawurlencode($k));
    check('the key opens the server PNG for a browser with NO session (guest / next day / relative)', $img['code'] === 200 && str_starts_with($img['ctype'], 'image/png'), 'HTTP ' . $img['code'] . ' ' . $img['ctype']);
    $none = $get('/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&view=1');
    check('...and without the key it does not', $none['code'] !== 200 || !str_starts_with($none['ctype'], 'image/png'), 'HTTP ' . $none['code']);
    $w = $get('/api/whereis.php?pnr=' . rawurlencode($pnr) . '&k=' . rawurlencode($tk));
    $wj = json_decode($w['body'], true);
    check('the track token opens /api/whereis.php (Trip Companion banner)', $tk !== '' && $w['code'] === 200 && !empty($wj['ok']) && array_key_exists('live', $wj['data'] ?? []), 'HTTP ' . $w['code']);
    $w2 = $get('/api/whereis.php?pnr=' . rawurlencode($pnr));
    check('...and no key, no session = not found', $w2['code'] === 404, 'HTTP ' . $w2['code']);
}

echo "-- 3. The page shell --\n";
$tpl = (string) file_get_contents($root . '/app.template.html');
check('checkout has the live "still needed" line and the refund chip', str_contains($tpl, 'id="coWhy"') && str_contains($tpl, 'id="coRefundChip"'));
check('checkout field errors have ids for aria-describedby', str_contains($tpl, 'id="cPhoneErr"') && str_contains($tpl, 'id="cEmailErr"') && str_contains($tpl, 'id="idNumErr"'));
check('the checkout no longer shows the old "<24h = no refund" rule', !str_contains($tpl, '&lt;24h = no refund') && !str_contains($tpl, '&lt;24h → no refund'));

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
