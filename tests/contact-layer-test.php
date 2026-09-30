<?php
/**
 * contact-layer-test.php — the office one tap away on every screen (24 Sep 2026).
 *
 *   A. The served app carries the contact dial and SHG_BOOT.contact.   [HTTP]
 *   B. "Call me back" is a whitelisted enquiry source that lands in the
 *      office inbox with the number it must call.                     [HTTP]
 *   C. An invented source still falls back to quick_booking.           [HTTP]
 *   D. Every label the dial uses exists in en / hi / ne.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
$CSRF = '';
function req(string $method, string $path, ?array $json = null): array {
    global $CSRF;
    $ch = curl_init(BASE . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => '/tmp/ct-jar', CURLOPT_COOKIEFILE => '/tmp/ct-jar'];
    if ($json !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'X-CSRF-Token: ' . $CSRF]; }
    curl_setopt_array($ch, $o);
    $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $c, 'body' => $b];
}
const PHONE = '9198700099';
Database::delete('enquiries', 'phone = :p', ['p' => PHONE]);
Database::delete('rate_limits', "bucket LIKE 'enquiry%'");

echo "\n=== Contact layer ===\n\n";
@unlink('/tmp/ct-jar');
$home = req('GET', '/index.php');
$cfg  = json_decode(req('GET', '/api/config.php')['body'], true) ?: [];
$CSRF = (string) ($cfg['data']['csrfToken'] ?? '');
if ($home['code'] !== 200) {
    echo "  SKIP  no server at " . BASE . "\n";
} else {
    echo "-- A. the dial is on every screen --\n";
    check('the app carries the contact button', str_contains($home['body'], 'id="ctFab"'));
    check('…and the sheet with call / WhatsApp / chat / call-back', str_contains($home['body'], 'id="ctCall"') && str_contains($home['body'], 'id="ctWa"') && str_contains($home['body'], 'id="ctChat"') && str_contains($home['body'], 'id="ctCbOpen"'));
    check('SHG_BOOT ships the office phone and WhatsApp digits', (bool) preg_match('/"contact":\{"phone":"[^"]+","wa":"\d{10,15}"\}/', $home['body']));

    echo "\n-- B. call me back --\n";
    $r = req('POST', '/api/enquiry.php', ['name' => 'Call Me', 'phone' => PHONE, 'source' => 'callback', 'note' => 'Call me back · #/seats', 'seats' => 1]);
    $j = json_decode($r['body'], true) ?: [];
    check('the request is accepted', $r['code'] === 200 && !empty($j['ok']), substr($r['body'], 0, 120));
    $row = Database::fetch('SELECT source, phone, note FROM enquiries WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => PHONE]);
    check('it lands in the Enquiries inbox as a callback', $row !== null && $row['source'] === 'callback', (string) ($row['source'] ?? 'none'));
    check('with the number the office must call', ($row['phone'] ?? '') === PHONE);
    check('the reply says the office will call, in three scripts', str_contains((string) ($j['message'] ?? ''), 'call you back') && str_contains((string) $j['message'], 'फोन'));

    echo "\n-- C. an invented source --\n";
    Database::delete('rate_limits', "bucket LIKE 'enquiry%'");
    $r = req('POST', '/api/enquiry.php', ['name' => 'Odd Source', 'phone' => PHONE, 'source' => 'hack_the_planet', 'seats' => 1]);
    $row = Database::fetch('SELECT source FROM enquiries WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => PHONE]);
    check('falls back to quick_booking', ($row['source'] ?? '') === 'quick_booking', (string) ($row['source'] ?? 'none'));
}

echo "\n-- D. labels --\n";
$i18n = (string) file_get_contents(dirname(__DIR__) . '/assets/js/04-i18n.js');
foreach (['ctFabLbl', 'ctTitle', 'ctHours', 'ctCall', 'ctWa', 'ctChat', 'ctCallback', 'ctCbName', 'ctCbPhone', 'ctCbSend', 'ctCbNeed', 'ctCbOk', 'ctCbFail', 'ctWaHello'] as $k) {
    check("$k in en / hi / ne", substr_count($i18n, ' ' . $k . ': ') === 3, substr_count($i18n, ' ' . $k . ': ') . ' found');
}

Database::delete('enquiries', 'phone = :p', ['p' => PHONE]);
echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
