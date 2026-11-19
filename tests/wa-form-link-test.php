<?php
/**
 * wa-form-link-test.php — the WhatsApp booking link + one-message form (19 Nov 2026).
 *   formToSentence: labelled forms (ne / en / mixed) → the plain sentence; other text untouched.
 *   A full form + "ho" sells ONE ticket to the typed number with the typed name;
 *   "एजेन्ट: <code>" credits that agent (sold_by_admin_id), a wrong code credits nobody.
 *   wa.php builds the wa.me link with the form (and the agent's code).
 *     php -c .claude/php-dev.ini tests/wa-form-link-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--page') {
    parse_str((string) ($argv[2] ?? ''), $_GET);
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__) . '/wa.php';
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
foreach (['wabot', 'wabooking', 'booking', 'agentwallet'] as $f) { require_once INCLUDE_PATH . "/$f.php"; }
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $x = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($x !== '' ? " — $x" : '') . "\n"; }
}
$keep = [];
function setKeep(string $k, string $v, string $t = 'string', string $g = 'ai'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, $g, false);
    Settings::flush();
}
$phones = [];
function cleanup(): void {
    global $keep, $phones;
    foreach ($phones as $p) {
        $d = substr($p, -10);
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone LIKE :p', ['p' => "%$d"]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            foreach (['message_logs', 'notifications', 'tickets', 'pay_events', 'agent_ledger'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            try { Database::run('DELETE FROM audit_logs WHERE entity_id = :p', ['p' => $r['pnr']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
        try { Database::run('DELETE FROM kv_store WHERE kkey LIKE :p', ['p' => "%$d%"]); } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
}
register_shutdown_function('cleanup');

echo "\n=== WhatsApp booking link + one-message form ===\n\n";
$s = WaBooking::formToSentence("🎫 टिकट बुक\nनाम: Ram Bahadur Thapa\nमोबाइल: 98123 45601\nचढ्ने ठाउँ: Mehsana\nमिति: 20/11/2026\nसिट: 2");
check('Nepali form → sentence', $s === 'naam Ram Bahadur Thapa, number 9812345601, Mehsana bata, 20/11/2026, 2 seat', $s);
$s = WaBooking::formToSentence("Name - Sita Gurung\nMobile = +977 9812345602\nFrom: Surat\nDate: bholi");
check('English form with - and = → sentence, 1 seat by default', $s === 'naam Sita Gurung, number +9779812345602, Surat bata, bholi, 1 seat', $s);
$s = WaBooking::formToSentence("नाम: Hari\nमोबाइल: \nचढ्ने ठाउँ: \nमिति: भोलि\nसिट: 1");
check('empty fields are left out', $s === 'naam Hari, भोलि, 1 seat', $s);
foreach (['bholi 1 seat Mehsana bata', 'mero ticket kaha cha?', "नाम: \nमोबाइल: \nमिति: ", 'time: 10:30 ma aaunu'] as $t) {
    check('untouched: ' . mb_substr(str_replace("\n", ' / ', $t), 0, 30), WaBooking::formToSentence($t) === $t);
}

foreach (['gemini_api_key', 'anthropic_api_key'] as $k) { setKeep($k, ''); }
setKeep('ai_local_backup_on', '0', 'bool');
setKeep('wa_local_first', '1', 'bool', 'whatsapp');
setKeep('wa_booking_on', '1', 'bool', 'whatsapp');
foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'email_enabled' => '0'] as $k => $v) { setKeep($k, $v, 'bool', 'notify'); }

$agents = array_values(array_filter(AgentWallet::sellableAgents(), static fn(array $a): bool => ($a['type'] ?? '') !== 'staff' && empty($a['co'])));
$code = (string) ($agents[0]['code'] ?? '');
$agentId = $code !== '' ? (int) AgentWallet::resolveAgentCodeFromString($code) : 0;
check('a test agent code exists', $agentId > 0, $code);
$tom = date('d/m/Y', strtotime(todayISO() . ' +1 day'));
$run = static function (string $agentLine, int $n) use (&$phones, $tom): ?array {
    $from  = '+97798777' . sprintf('%05d', 51000 + $n);
    $typed = '98123' . sprintf('%05d', 46000 + $n);
    $phones[] = $from; $phones[] = $typed;
    WaBot::reply($from, "🎫 टिकट बुक\nनाम: Kamala Devi Sharma\nमोबाइल: $typed\nचढ्ने ठाउँ: Mehsana\nमिति: $tom\nसिट: 1" . $agentLine);
    WaBot::reply($from, 'ho');
    $rows = Database::fetchAll('SELECT b.*, (SELECT full_name FROM booking_passengers WHERE booking_id = b.id LIMIT 1) pax FROM bookings b WHERE b.contact_phone = :p', ['p' => $typed]);
    return count($rows) === 1 ? $rows[0] : null;
};
$b = $run('', 1);
check('form + "ho" → one ticket for the typed number and name', $b !== null && (string) $b['pax'] === 'Kamala Devi Sharma', json_encode($b['pax'] ?? null));
check('no agent line → a direct sale', $b !== null && (int) ($b['sold_by_admin_id'] ?? 0) === 0);
$b = $run("\nएजेन्ट: $code", 2);
check('"एजेन्ट: code" → credited to that agent', $b !== null && (int) ($b['sold_by_admin_id'] ?? 0) === $agentId, (string) ($b['sold_by_admin_id'] ?? 'none'));
$b = $run("\nAgent: ZZ-99999", 3);
check('a wrong agent code credits nobody', $b !== null && (int) ($b['sold_by_admin_id'] ?? 0) === 0);

$ini = php_ini_loaded_file() ?: '';
$page = static function (string $qs) use ($ini): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --page ' . escapeshellarg($qs) . ' 2>&1');
};
$h = $page('go=0');
check('wa.php: wa.me link with the form', (bool) preg_match('#https://wa\.me/\d{11,13}\?text=#', $h) && str_contains($h, 'चढ्ने ठाउँ') && !preg_match('/Fatal|Warning/', $h), mb_substr(strip_tags($h), 0, 120));
$h = $page('go=0&a=' . rawurlencode($code));
check('wa.php?a=code: the agent line is in the form', str_contains($h, 'एजेन्ट: ' . $code));
$h = $page('go=0&a=ZZ-99999');
check('wa.php: an unknown agent code is dropped', !str_contains($h, 'ZZ-99999'));

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
