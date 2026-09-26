<?php
/**
 * tests/delivery-fallback-test.php — the office gets the ticket when the
 * passenger cannot (26 Sep 2026).
 *
 * Owner: "error bhayo bhane 9104801507 yo WhatsApp ma data send gardine, yo
 * name lai yo ticket send gardinu bhanera ticket link ra number deu".
 *
 * Runs against the TEST database with whatsapp_driver pinned to
 * click_to_chat, so nothing leaves the box: Notify::whatsapp() writes a
 * 'failed' row for the passenger, and deliveryFallback() writes the office
 * row (purpose delivery_fallback) whose body we inspect. Everything it
 * writes is removed at the end, and the pinned settings are restored.
 *
 *   php tests/delivery-fallback-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/ticket.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 300) : '') . "\n"; }
}
echo "\n=== Ticket delivery fallback to the office ===\n\n";

$PINNED = ['whatsapp_driver', 'wa_delivery_fallback_on', 'wa_delivery_fallback_hours', 'admin_whatsapp', 'admin_email', 'whatsapp_paused_until'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$pin = static function (string $k, string $v): void {
    Database::run("INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES (:k, :v, 'string', 'test', 0)
                   ON DUPLICATE KEY UPDATE svalue = :v2", ['k' => $k, 'v' => $v, 'v2' => $v]);
};
$cleanup = static function () use ($PINNED, $prior): void {
    try { Database::run("DELETE FROM message_logs WHERE to_number IN ('919100009999', '919100008888', '+919100008888') OR purpose = 'delivery_fallback'"); } catch (Throwable $e) {}
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        try {
            if ($row === null) { Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]); }
            else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
        } catch (Throwable $e) {}
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);

$booking = Database::fetch("SELECT id, pnr FROM bookings WHERE status = 'confirmed' ORDER BY id DESC LIMIT 1");
if ($booking === null) {
    echo "  (no confirmed booking in this database — nothing to test)\n\n";
    exit(0);
}
$bid = (int) $booking['id'];
$pnr = (string) $booking['pnr'];

$pin('whatsapp_driver', 'click_to_chat');
$pin('wa_delivery_fallback_on', '1');
$pin('wa_delivery_fallback_hours', '24');
$pin('admin_whatsapp', '919100009999');      // the fake office
$pin('admin_email', '');                      // no mail from a test
try { Database::run("DELETE FROM settings WHERE skey = 'whatsapp_paused_until'"); } catch (Throwable $e) {}
try { Settings::flush(); } catch (Throwable $e) {}
try { Database::run("DELETE FROM message_logs WHERE to_number IN ('919100009999', '919100008888') OR purpose = 'delivery_fallback'"); } catch (Throwable $e) {}

/* 1. A ticket the sender cannot deliver → one office row with the facts. */
$r = Notify::whatsapp('+919100008888', 'Test ticket ' . $pnr, null, 'IN', [], $bid, ['purpose' => 'ticket']);
check('sender refused (click_to_chat returns a link, not true)', $r !== true && $r !== false);
$office = Database::fetch("SELECT * FROM message_logs WHERE purpose = 'delivery_fallback' AND booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $bid]);
check('office row written', $office !== null);
$body = (string) ($office['body'] ?? '');
check('to the office number', (string) ($office['to_number'] ?? '') === '919100009999', (string) ($office['to_number'] ?? ''));
check('carries the PNR', str_contains($body, $pnr));
check('carries the passenger number', str_contains($body, '919100008888'));
check('carries the ticket picture link', str_contains($body, Ticket::imageUrl($pnr)));
check('carries the one-tap forward link', str_contains($body, 'https://wa.me/919100008888?text='));
check('says what to do, in Nepali', str_contains($body, 'पठाइदिनुहोस्'));
check('carries the admin link', str_contains($body, 'admin/booking-view.php?id=' . $bid));
check('carries the reason', str_contains($body, 'कारण:'));

/* 2. The same failure again inside the window → no second office row. */
Notify::whatsapp('+919100008888', 'Test ticket again ' . $pnr, null, 'IN', [], $bid, ['purpose' => 'ticket']);
$n = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE purpose = 'delivery_fallback' AND booking_id = :b", ['b' => $bid], 0);
check('one office alert per booking per window', $n === 1, (string) $n);

/* 3. The office's own failed alert never triggers another (no loop). */
$before = $n;
Notify::whatsapp('919100009999', 'office alert', null, 'IN', [], $bid, ['purpose' => 'delivery_fallback']);
Notify::whatsapp('919100009999', 'admin note', null, 'IN', [], $bid, ['purpose' => 'admin_note']);
$after = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE purpose = 'delivery_fallback' AND booking_id = :b", ['b' => $bid], 0);
check('office purposes do not loop', $after === $before + 1, "$before → $after");

/* 4. Switched off → nothing. */
Database::run("DELETE FROM message_logs WHERE purpose = 'delivery_fallback'");
$pin('wa_delivery_fallback_on', '0');
try { Settings::flush(); } catch (Throwable $e) {}
Notify::whatsapp('+919100008888', 'Test ticket ' . $pnr, null, 'IN', [], $bid, ['purpose' => 'ticket']);
$off = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE purpose = 'delivery_fallback'", [], 0);
check('wa_delivery_fallback_on = 0 → no office row', $off === 0, (string) $off);

/* 5. Direct call with no booking → nothing, no error. */
$pin('wa_delivery_fallback_on', '1');
try { Settings::flush(); } catch (Throwable $e) {}
Notify::deliveryFallback(0, '', 'ticket', 'x');
Notify::deliveryFallback(null, '', 'ticket', 'x');
check('no booking → silent', (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE purpose = 'delivery_fallback'", [], 0) === 0);

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL ? 1 : 0);
