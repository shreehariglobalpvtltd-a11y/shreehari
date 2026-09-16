<?php
/**
 * tests/webpush-test.php — Web Push crypto against the RFC 8291 vectors,
 * VAPID signing, and the subscription store.
 *
 *   php -c .claude/php-dev.ini tests/webpush-test.php
 *
 * The encryption check is the one that matters: a payload that does not
 * byte-match Appendix A of RFC 8291 would be silently dropped by every push
 * service ("201 accepted", nothing ever shown on the phone).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/webpush.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Web Push — RFC 8291 vectors, VAPID, subscription store ===\n\n";

check('crypto primitives present (openssl_pkey_derive, hash_hkdf, aes-128-gcm)', WebPush::available());

/* ---- 1. RFC 8291 Appendix A ------------------------------------------ */
$V = [
    'plaintext'  => 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24',
    'as_private' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
    'as_public'  => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8',
    'ua_private' => 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94',
    'ua_public'  => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'auth'       => 'BTBZMqHH6r4Tts7J_aSIgg',
    'salt'       => 'DGv6ra1nlYgDCS1FRnbzlw',
    'body'       => 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
];
$plain = WebPush::b64uDecode($V['plaintext']);
check('vector plaintext decodes', $plain === 'When I grow up, I want to be a watermelon', $plain);

$asPub = WebPush::derivePublic(WebPush::b64uDecode($V['as_private']));
check('derivePublic(as_private) == as_public', $asPub !== null && WebPush::b64u($asPub) === $V['as_public']);
$uaPub = WebPush::derivePublic(WebPush::b64uDecode($V['ua_private']));
check('derivePublic(ua_private) == ua_public', $uaPub !== null && WebPush::b64u($uaPub) === $V['ua_public']);

$body = '';
try {
    $body = WebPush::encrypt($plain, $V['ua_public'], $V['auth'], WebPush::b64uDecode($V['as_private']), WebPush::b64uDecode($V['salt']));
} catch (Throwable $e) {
    check('encrypt() ran', false, $e->getMessage());
}
check('encrypted body matches RFC 8291 A.1 byte for byte', WebPush::b64u($body) === $V['body'],
    strlen($body) . ' bytes');
check('header: salt | rs=4096 | idlen=65 | as_public', substr($body, 0, 16) === WebPush::b64uDecode($V['salt'])
    && unpack('N', substr($body, 16, 4))[1] === 4096 && ord($body[20]) === 65
    && WebPush::b64u(substr($body, 21, 65)) === $V['as_public']);

try {
    $round = WebPush::decrypt(WebPush::b64uDecode($V['body']), WebPush::b64uDecode($V['ua_private']), WebPush::b64uDecode($V['auth']));
    check('the RFC body decrypts with the browser key back to the plaintext', $round === $plain, $round);
} catch (Throwable $e) {
    check('decrypt() ran', false, $e->getMessage());
}

/* ---- 2. Fresh keys + random round trip -------------------------------- */
$pair = WebPush::generateKeyPair();
check('generateKeyPair(): 65-byte point + 32-byte scalar', $pair !== null && strlen($pair['pub']) === 65 && strlen($pair['priv']) === 32);
if ($pair !== null) {
    check('  ...and the public point matches the scalar', WebPush::derivePublic($pair['priv']) === $pair['pub']);
    $ua   = WebPush::generateKeyPair();
    $auth = random_bytes(16);
    $msg  = json_encode(['title' => 'बस ढिलो · Bus delayed ~30 min', 'body' => 'Surat → Rupaidiha · तपाईंको बस', 'url' => '/#/ticket/SHG-2026-00001'], JSON_UNESCAPED_UNICODE);
    try {
        $enc = WebPush::encrypt((string) $msg, WebPush::b64u($ua['pub']), WebPush::b64u($auth));
        $dec = WebPush::decrypt($enc, $ua['priv'], $auth);
        check('random keys: encrypt → decrypt round trip (Devanagari payload)', $dec === $msg);
        $enc2 = WebPush::encrypt((string) $msg, WebPush::b64u($ua['pub']), WebPush::b64u($auth));
        check('  ...each message uses a fresh salt + sender key', $enc2 !== $enc);
    } catch (Throwable $e) {
        check('random round trip ran', false, $e->getMessage());
    }
}

/* ---- 3. Rejections ---------------------------------------------------- */
try { WebPush::encrypt('x', 'AAAA', $V['auth']); check('a malformed p256dh is refused', false); }
catch (Throwable $e) { check('a malformed p256dh is refused', true, $e->getMessage()); }
try { WebPush::encrypt(str_repeat('a', 4000), $V['ua_public'], $V['auth']); check('an oversized payload is refused', false); }
catch (Throwable $e) { check('an oversized payload is refused', true, $e->getMessage()); }

/* ---- 4. VAPID ---------------------------------------------------------- */
$hdr = '';
try {
    $hdr = WebPush::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc:def', $V['as_private'], $V['as_public']);
} catch (Throwable $e) {
    check('vapidAuthorization ran', false, $e->getMessage());
}
check('Authorization: vapid t=<jwt>, k=<key>', preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $hdr, $m) === 1);
if (!empty($m)) {
    [$h, $c, $s] = explode('.', $m[1]);
    $hj = json_decode(WebPush::b64uDecode($h), true);
    $cj = json_decode(WebPush::b64uDecode($c), true);
    check('JWT header is ES256', ($hj['alg'] ?? '') === 'ES256' && ($hj['typ'] ?? '') === 'JWT');
    check('JWT aud is the push service origin only', ($cj['aud'] ?? '') === 'https://fcm.googleapis.com');
    check('JWT exp within 24h', ($cj['exp'] ?? 0) > time() && ($cj['exp'] ?? 0) <= time() + 86400);
    check('JWT sub is mailto:/https:', str_starts_with((string) ($cj['sub'] ?? ''), 'mailto:') || str_starts_with((string) ($cj['sub'] ?? ''), 'https://'));
    check('k= is the VAPID public key', $m[2] === $V['as_public']);
    $raw = WebPush::b64uDecode($s);
    check('signature is raw r||s (64 bytes)', strlen($raw) === 64);
    // Re-encode as DER and verify with the public key: proves the JWT really is signed by as_private.
    $int = static function (string $v): string {
        $v = ltrim($v, "\0");
        if ($v === '' || (ord($v[0]) & 0x80)) { $v = "\0" . $v; }
        return "\x02" . chr(strlen($v)) . $v;
    };
    $der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    $der = "\x30" . chr(strlen($der)) . $der;
    $pubKey = openssl_pkey_get_public(WebPush::publicPem(WebPush::b64uDecode($V['as_public'])));
    check('signature verifies with the public key', $pubKey !== false && openssl_verify($h . '.' . $c, $der, $pubKey, OPENSSL_ALGO_SHA256) === 1);
}

/* ---- 5. Store (test DB) ------------------------------------------------- */
$hasTable = true;
try { Database::query('SELECT 1 FROM push_subscriptions LIMIT 1'); Database::query('SELECT 1 FROM push_log LIMIT 1'); }
catch (Throwable $e) { $hasTable = false; }
check('push_subscriptions + push_log exist (database/upgrade-2026-09-pwa-master.sql)', $hasTable);

if ($hasTable) {
    $ep1 = 'https://push.example.invalid/shg-test/' . bin2hex(random_bytes(8));
    $ep2 = 'https://push.example.invalid/shg-test/' . bin2hex(random_bytes(8));
    $cleanup = static function () use ($ep1, $ep2): void {
        foreach ([$ep1, $ep2] as $e) {
            $id = Database::scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($e)], 0);
            if ($id) { Database::delete('push_log', 'subscription_id = :i', ['i' => (int) $id]); }
            Database::delete('push_subscriptions', 'endpoint_hash = :h', ['h' => sha1($e)]);
        }
    };
    try {
        $sub = WebPush::normaliseSubscription(['endpoint' => $ep1, 'keys' => ['p256dh' => $V['ua_public'], 'auth' => $V['auth']]]);
        check('normaliseSubscription accepts a real browser shape', $sub !== null);
        check('  ...and refuses http:// or short keys', WebPush::normaliseSubscription(['endpoint' => 'http://x', 'keys' => ['p256dh' => $V['ua_public'], 'auth' => $V['auth']]]) === null
            && WebPush::normaliseSubscription(['endpoint' => $ep1, 'keys' => ['p256dh' => 'abc', 'auth' => $V['auth']]]) === null);
        $id  = WebPush::subscribe($sub, ['phone' => '91000099001', 'lang' => 'ne', 'ua' => 'test', 'booking_id' => null]);
        $id2 = WebPush::subscribe($sub, ['phone' => '91000099001', 'lang' => 'hi']);
        check('subscribe() upserts by endpoint (same id twice)', $id > 0 && $id === $id2, "id $id");
        $row = Database::fetch('SELECT * FROM push_subscriptions WHERE id = :i', ['i' => $id]);
        check('  ...phone stored normalised, lang refreshed', ($row['phone'] ?? '') === normalisePhone('91000099001') && ($row['lang'] ?? '') === 'hi');
        check('forPhone() finds it', count(WebPush::forPhone('91000099001')) === 1);

        $newSub = WebPush::normaliseSubscription(['endpoint' => $ep2, 'keys' => ['p256dh' => $V['ua_public'], 'auth' => $V['auth']]]);
        $nid = WebPush::resubscribe($ep1, $newSub);
        check('resubscribe() moves the row to the new endpoint and retires the old', $nid !== null && $nid !== $id
            && (int) Database::scalar('SELECT is_active FROM push_subscriptions WHERE id = :i', ['i' => $id], 1) === 0
            && (string) Database::scalar('SELECT phone FROM push_subscriptions WHERE id = :i', ['i' => (int) $nid], '') === normalisePhone('91000099001'));
        check('resubscribe() with an unknown old endpoint is refused', WebPush::resubscribe('https://push.example.invalid/nope', $newSub) === null);

        // A send to a dead port fails fast and is journaled; the row is NOT retired on one failure.
        $dead = Database::fetch('SELECT * FROM push_subscriptions WHERE id = :i', ['i' => (int) $nid]);
        $dead['endpoint'] = 'https://127.0.0.1:9/dead';
        $r = WebPush::send($dead, ['title' => 'test', 'body' => 'x', 'event' => 'test']);
        check('send() to a dead endpoint reports failure without throwing', $r['ok'] === false && $r['error'] !== '');
        $after = Database::fetch('SELECT fail_count, is_active FROM push_subscriptions WHERE id = :i', ['i' => (int) $nid]);
        check('  ...fail_count incremented, still active after one failure', (int) $after['fail_count'] === 1 && (int) $after['is_active'] === 1);
        check('  ...and push_log has the row', (int) Database::scalar('SELECT COUNT(*) FROM push_log WHERE subscription_id = :i', ['i' => (int) $nid], 0) === 1);

        check('unsubscribe() retires the endpoint', WebPush::unsubscribe($ep2) && (int) Database::scalar('SELECT is_active FROM push_subscriptions WHERE id = :i', ['i' => (int) $nid], 1) === 0);
    } finally {
        $cleanup();
    }
}

/* ---- 6. Payload wording ------------------------------------------------- */
$p = WebPush::tripEventPayload('delayed', 'SHG-2026-00042', ['route' => 'Surat → Rupaidiha', 'date' => '13 Sep 2026', 'depTime' => '1:00 PM', 'delayMinutes' => 45, 'delayNote' => 'Traffic near Baroda']);
check('delayed payload names the minutes and opens the ticket', str_contains($p['title'], '45') && str_contains($p['url'], '#/ticket/SHG-2026-00042') && str_contains($p['body'], 'Traffic near Baroda'));
check('  ...tag is per event + PNR', $p['tag'] === 'shg-delayed-shg-2026-00042');

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
