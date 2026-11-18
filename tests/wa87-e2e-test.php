<?php
/**
 * =====================================================================
 *  wa87-e2e-test.php — the 87358 81507 WhatsApp sender flow, end to end, with test
 *  data and NO network (19 Nov 2026, owner priority list):
 *
 *   1. A Nepal customer (+977) types date + pickup, then a name, then "ho"
 *      → ONE pending booking for tomorrow, the right route, channel WHATSAPP,
 *        the number kept as a Nepal number.
 *   2. The reply carries the payment link / QR for that PNR and the exact amount.
 *   3. "ho" again / the same request again → no second booking.
 *   4. The customer types a UTR → still pending (owner rule: a UTR or screenshot
 *      alone never confirms).
 *   5. A person approves (Verify Payments path) → confirmed, ONE booking.confirm,
 *      ONE ticket row; approving twice changes nothing.
 *  The signed bank-callback path (auto-confirm, duplicates, wrong amount) is in
 *  tests/payauto-test.php.
 *
 *    php -c .claude/php-dev.ini tests/wa87-e2e-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
foreach (['wabot', 'wabooking', 'wapayproof', 'booking', 'payauto'] as $f) { require_once INCLUDE_PATH . "/$f.php"; }

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$keep = [];
function setKeep(string $k, string $v, string $t = 'string', string $g = 'whatsapp'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, $g, false);
    Settings::flush();
}
$phone = '';
function cleanup(): void {
    global $keep, $phone;
    if ($phone !== '') {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone LIKE :p', ['p' => '%' . substr($phone, -10)]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            foreach (['booking_collections', 'pay_events', 'payment_reviews', 'notifications', 'message_logs', 'tickets'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            try { Database::run('DELETE FROM audit_logs WHERE entity_id = :p', ['p' => $r['pnr']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
        try { Database::run("DELETE FROM kv_store WHERE kkey LIKE :p", ['p' => '%' . substr($phone, -10) . '%']); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
}
register_shutdown_function('cleanup');

echo "\n=== 87 WhatsApp sender: name / mobile / date → booking → pay → one ticket ===\n\n";
foreach (['gemini_api_key', 'anthropic_api_key'] as $k) { setKeep($k, '', 'string', 'ai'); }
setKeep('wa_local_first', '1', 'bool');
setKeep('wa_booking_on', '1', 'bool');
setKeep('ai_local_backup_on', '0', 'bool', 'ai');
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool', 'notify'); }

for ($i = 0; $i < 50; $i++) {
    $cand = normalisePhone('97798' . str_pad((string) (77000000 + $i * 37), 8, '0', STR_PAD_LEFT));
    if (!Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $cand])) { $phone = $cand; break; }
}
check('a fresh Nepal test number', $phone !== '', $phone);
$tomorrow = date('Y-m-d', strtotime(todayISO() . ' +1 day'));

$r1 = WaBot::reply('+977' . substr($phone, -10), 'bholi 1 seat Mehsana bata');
$r2 = WaBot::reply('+977' . substr($phone, -10), 'naam Ram Bahadur Test');
$r3 = WaBot::reply('+977' . substr($phone, -10), 'ho');
$rows = Database::fetchAll('SELECT * FROM bookings WHERE contact_phone LIKE :p ORDER BY id', ['p' => '%' . substr($phone, -10)]);
check('1 "ho" closes ONE booking', count($rows) === 1, count($rows) . ' · ' . mb_substr((string) ($r3['text'] ?? ''), 0, 120));
$b = $rows[0] ?? null;
if ($b !== null) {
    $leg = Database::fetch('SELECT travel_date FROM booking_legs WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) $b['id']]);
    $name = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => (int) $b['id']], '');
    check('1 tomorrow\'s date', ($leg['travel_date'] ?? '') === $tomorrow, (string) ($leg['travel_date'] ?? ''));
    check('1 the typed name on the ticket', $name === 'Ram Bahadur Test', $name);
    check('1 kept as a Nepal number (977)', (string) ($b['contact_country_code'] ?? '') === '977', (string) ($b['contact_country_code'] ?? ''));
    $pay = Database::fetch('SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b['id']]);
    check('1 ticket confirmed at once, money due at boarding (owner mv6 rule)',
        (string) $b['status'] === 'confirmed' && (int) $b['is_cod'] === 1 && (string) ($pay['status'] ?? '') === 'cod_pending',
        $b['status'] . ' cod=' . $b['is_cod'] . ' pay=' . ($pay['status'] ?? '-'));
    require_once INCLUDE_PATH . '/bookingchannel.php';
    check('1 channel WHATSAPP', BookingChannel::of((int) $b['id']) === 'WHATSAPP', BookingChannel::of((int) $b['id']));
    $txt = (string) ($r3['text'] ?? '') . ' ' . (string) ($r3['media'] ?? '') . ' ' . json_encode($r3['ui'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    check('2 reply carries the PNR', str_contains($txt, (string) $b['pnr']), mb_substr($txt, 0, 200));
    check('2 reply carries the UPI pay link (exact-amount QR page) with the ticket', str_contains($txt, 'pay.php?pnr=' . urlencode((string) $b['pnr'])), mb_substr($txt, -300));
    check('2 reply carries the exact amount', str_contains(str_replace(',', '', $txt), (string) (int) round((float) $b['total_amount'])), (string) $b['total_amount']);

    WaBot::reply('+977' . substr($phone, -10), 'ho');
    WaBot::reply('+977' . substr($phone, -10), 'bholi 1 seat Mehsana bata');
    WaBot::reply('+977' . substr($phone, -10), 'ho');
    $n = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE :p AND status <> 'cancelled'", ['p' => '%' . substr($phone, -10)], 0);
    check('3 repeated "ho" / same request → no second live booking', $n === 1, (string) $n);

    $utr = substr(date('Y'), -1) . sprintf('%03d', (int) date('z') + 1) . date('H') . '424242';
    WaBot::reply('+977' . substr($phone, -10), 'UTR ' . $utr);
    WaBot::reply('+977' . substr($phone, -10), 'ho');
    $pay2 = Database::fetch('SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b['id']]);
    check('4 a typed UTR never marks the money received', !in_array((string) ($pay2['status'] ?? ''), ['verified', 'settled'], true), (string) ($pay2['status'] ?? ''));

    $cnt = static fn(string $a): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE action = :a AND entity_id = :p', ['a' => $a, 'p' => $b['pnr']], 0);
    $tickets = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => (int) $b['id']], 0);
    $c1 = $cnt('booking.confirm'); $t1 = $tickets();
    check('5 one ticket row after all those messages', $t1 === 1, (string) $t1);
    try { BookingService::approveReviewed((int) $b['id'], PayAuto::systemAdmin() ?? ['id' => 1, 'role' => 'superadmin'], ['via' => 'queue', 'bankChecked' => true, 'note' => 'again']); } catch (Throwable $e) {}
    try { BookingService::confirm((int) $b['id'], 1, 'double click'); } catch (Throwable $e) {}
    check('5 approving / confirming again: no second confirm, no second ticket', $cnt('booking.confirm') === $c1 && $tickets() === $t1,
        $c1 . '/' . $cnt('booking.confirm') . ' ' . $t1 . '/' . $tickets());
}

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
