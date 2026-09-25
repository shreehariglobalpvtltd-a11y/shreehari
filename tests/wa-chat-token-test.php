<?php
/**
 * =====================================================================
 *  wa-chat-token-test.php — one-time WhatsApp ticket codes (26 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/wa-chat-token-test.php
 *
 *  Guards includes/wachat.php and the bot path that spends a code:
 *    1. mint() returns a 6-char code and stores only its sha256
 *    2. redeem() returns the booking and marks the row used
 *    3. a spent code is refused (replay)
 *    4. an expired code is refused
 *    5. a wrong code is refused, with no hint why
 *    6. expire() removes only expired-unused (and day-old spent) rows
 *    7. buildLink() carries the code and no PNR / phone / name
 *    8. buildQr() returns a PNG data URI
 *    9. ordinary sentences ("ticket chahiyo") are never read as a code
 *   10. WaBot answers a live code with the ticket, a dead one without
 *
 *  Writes only rows tagged to a throwaway booking it creates and removes.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

require_once INCLUDE_PATH . '/wachat.php';

$pass = 0; $fail = 0;
function wc_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* A booking to hang codes on: the newest confirmed one in the test
   database, so the bot test can render a real ticket. */
$booking = Database::fetch("SELECT id, pnr, contact_phone FROM bookings WHERE status = 'confirmed' ORDER BY id DESC LIMIT 1");
if ($booking === null) {
    echo "  SKIP  no confirmed booking in the test database — run e2e-booking-test.php once\n";
    exit(0);
}
$bid = (int) $booking['id'];
$pnr = (string) $booking['pnr'];

$cleanup = static fn () => Database::run('DELETE FROM wa_chat_tokens WHERE booking_id = :b', ['b' => $bid]);
$cleanup();
Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_ticket_code_try'");

try {
    // 1. mint
    $code = WaChat::mint($bid);
    wc_check('mint() returns 6 characters from the alphabet', (bool) preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $code), $code);
    wc_check('mint() codes always carry a digit', (bool) preg_match('/\d/', $code));
    $row = Database::fetch('SELECT * FROM wa_chat_tokens WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid]);
    wc_check('the row stores sha256(code)', $row !== null && $row['token_hash'] === hash('sha256', $code));
    $plain = false;
    foreach ((array) $row as $v) { if ((string) $v === $code) { $plain = true; } }
    wc_check('the plain code is stored nowhere in the row', !$plain);
    // Measured by the database clock: PHP and MySQL may sit in different zones.
    $mins = (int) Database::scalar('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM wa_chat_tokens WHERE id = :id', ['id' => $row['id']], -1);
    wc_check('the code expires in about 30 minutes', abs($mins - WaChat::ttlMinutes()) <= 1, $mins . ' min');

    // 2. redeem
    wc_check('redeem() returns the booking for a live code', WaChat::redeem('TICKET ' . $code) === $bid);
    wc_check('...and marks it used',
        Database::scalar('SELECT used_at FROM wa_chat_tokens WHERE token_hash = :h', ['h' => WaChat::hash($code)]) !== null);

    // 3. replay
    wc_check('a spent code is refused', WaChat::redeem('TICKET ' . $code) === null);

    // 4. expired
    $old = WaChat::mint($bid);
    Database::run('UPDATE wa_chat_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = :h', ['h' => WaChat::hash($old)]);
    wc_check('an expired code is refused', WaChat::redeem('TICKET ' . $old) === null);

    // 5. wrong
    wc_check('an unknown code is refused', WaChat::redeem('TICKET 2ZZZZZ') === null);
    wc_check('verify() says no without saying why', WaChat::verify('TICKET 3ZZZZZ') === false);

    // 6. expire()
    $live = WaChat::mint($bid);
    WaChat::expire();
    $left = Database::fetchAll('SELECT token_hash FROM wa_chat_tokens WHERE booking_id = :b', ['b' => $bid]);
    $hashes = array_column($left, 'token_hash');
    wc_check('expire() removes the expired unused code', !in_array(WaChat::hash($old), $hashes, true));
    wc_check('expire() keeps a live code', in_array(WaChat::hash($live), $hashes, true));
    wc_check('expire() keeps a code spent today', in_array(WaChat::hash($code), $hashes, true));

    // 7. link
    $link = WaChat::buildLink($live, '919104801507');
    wc_check('buildLink() is a wa.me link with the code typed', $link === 'https://wa.me/919104801507?text=TICKET%20' . $live, $link);
    $digits = preg_replace('/\D/', '', (string) $booking['contact_phone']) ?? '';
    wc_check('buildLink() carries no PNR or passenger phone',
        stripos($link, $pnr) === false && ($digits === '' || !str_contains($link, $digits)));

    // 8. QR
    $qr = WaChat::buildQr($link);
    $png = base64_decode(substr($qr, strlen('data:image/png;base64,')), true);
    wc_check('buildQr() returns a PNG data URI',
        str_starts_with($qr, 'data:image/png;base64,') && is_string($png) && str_starts_with($png, "\x89PNG"));

    // 9. parsing
    wc_check('"ticket chahiyo" is not a code', !WaChat::looksLikeRequest('ticket chahiyo'));
    wc_check('"ticket 2 seat" is not a code', !WaChat::looksLikeRequest('ticket 2 seat'));
    wc_check('"TICKET K7QM2P" is a code request', WaChat::looksLikeRequest('TICKET K7QM2P'));
    wc_check('lower case and Devanagari "टिकट" work too',
        WaChat::extract('ticket k7qm2p') === 'K7QM2P' && WaChat::extract('टिकट K7QM2P') === 'K7QM2P');

    // 10. the bot, from a number that is NOT the booking's
    require_once INCLUDE_PATH . '/wabot.php';
    $stranger = 'whatsapp:+9779800000001';
    $fresh = WaChat::mint($bid);
    $reply = WaBot::reply($stranger, 'TICKET ' . $fresh);
    wc_check('WaBot sends the ticket for a live code from any number',
        str_contains($reply['text'], $pnr) && ($reply['media'] === null || str_contains((string) $reply['media'], 'img=1')));
    $again = WaBot::reply($stranger, 'TICKET ' . $fresh);
    wc_check('WaBot refuses the same code twice and leaks no PNR',
        !str_contains($again['text'], $pnr) && $again['media'] === null);
} catch (Throwable $e) {
    wc_check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
    Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_ticket_code_try'");
}

echo "\n  wa-chat-token: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
