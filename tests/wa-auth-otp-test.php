<?php
/**
 * wa-auth-otp-test.php — 15 Nov 2026. includes/waauthotp.php with the Graph
 * call replaced (no network): create / refresh / send payloads, the switch,
 * code extraction, and that Notify::whatsapp hands a code message to the
 * template once it is APPROVED (and never a normal message).
 *
 *     php -c .claude/php-dev.ini tests/wa-auth-otp-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/waauthotp.php';
require_once INCLUDE_PATH . '/notify.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== WhatsApp login codes on our own Meta AUTHENTICATION template ===\n\n";

$keys = ['wa_auth_tpl_status', 'wa_auth_tpl_id', 'wa_auth_tpl_checked', 'wa_auth_otp_on', 'wa_auth_tpl_manual'];
$keep = [];
foreach ($keys as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
register_shutdown_function(static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        $row === null ? Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k])
                      : Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => (string) $row['svalue'], 'k' => $k]);
    }
});
foreach ($keys as $k) {
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
}
Settings::flush();

$CALLS = [];
$META_STATUS = 'PENDING';
WaAuthOtp::$graphHook = static function (string $m, string $path, ?array $body) use (&$CALLS, &$META_STATUS): array {
    $CALLS[] = [$m, $path, $body];
    if ($m === 'POST' && str_contains($path, '/message_templates')) {
        return ['http' => 200, 'json' => ['id' => '99887766', 'status' => 'PENDING', 'category' => 'AUTHENTICATION']];
    }
    if ($m === 'GET' && str_contains($path, '/message_templates')) {
        return ['http' => 200, 'json' => ['data' => [['id' => '99887766', 'name' => WaAuthOtp::NAME, 'language' => 'en', 'status' => $META_STATUS]]]];
    }
    if ($m === 'POST' && str_contains($path, '/messages')) {
        return ['http' => 200, 'json' => ['messages' => [['id' => 'wamid.TEST1']]]];
    }
    return ['http' => 400, 'json' => ['error' => ['message' => 'unexpected']]];
};

echo "-- before Meta approves --\n";
check('not ready before it exists', !WaAuthOtp::ready() && WaAuthOtp::status() === '');
$r = WaAuthOtp::send('919812345678', '482611');
check('no send before approval', !$r['success'] && $CALLS === []);

$r = WaAuthOtp::create();
$req = $CALLS[0][2] ?? [];
check('create asks for an AUTHENTICATION template', $r['ok'] && ($req['category'] ?? '') === 'AUTHENTICATION' && ($req['name'] ?? '') === WaAuthOtp::NAME, json_encode($r));
$comp = array_column((array) ($req['components'] ?? []), null, 'type');
check('body carries the security line', !empty($comp['BODY']['add_security_recommendation']));
check('footer carries the expiry minutes', (int) ($comp['FOOTER']['code_expiration_minutes'] ?? 0) >= 1);
check('copy-code button', ($comp['BUTTONS']['buttons'][0]['otp_type'] ?? '') === 'COPY_CODE');
check('status saved as PENDING', WaAuthOtp::status() === 'PENDING');
check('still not ready while pending', !WaAuthOtp::ready());

echo "-- approved --\n";
$META_STATUS = 'APPROVED';
$s = WaAuthOtp::refresh();
check('refresh reads APPROVED', $s['status'] === 'APPROVED' && WaAuthOtp::status() === 'APPROVED');
check('ready once approved', WaAuthOtp::ready());
$CALLS = [];
$r = WaAuthOtp::send('+91 98123 45678', '482611');
$b = $CALLS[0][2] ?? [];
check('send accepted', $r['success'] && $r['message_id'] === 'wamid.TEST1', json_encode($r));
check('to = digits only', ($b['to'] ?? '') === '919812345678');
check('template + language', ($b['template']['name'] ?? '') === WaAuthOtp::NAME && ($b['template']['language']['code'] ?? '') === 'en');
check('code in the body', ($b['template']['components'][0]['parameters'][0]['text'] ?? '') === '482611');
check('code in the copy button', ($b['template']['components'][1]['sub_type'] ?? '') === 'url' && ($b['template']['components'][1]['parameters'][0]['text'] ?? '') === '482611');

echo "-- which messages go on it --\n";
check('code found in a Nepali login text', WaAuthOtp::codeIn("S Hari एजेन्ट लगइन कोड: 482611\n10 मिनेट सम्म मान्य") === '482611');
check('no code in a plain text', WaAuthOtp::codeIn('Your bus leaves at 13:00 from Surat') === '');
$CALLS = [];
check('a normal message is never sent as a code', WaAuthOtp::sendFromText('919812345678', 'Namaste, your seat is A3. Bus GJ-02-T-5580.', 'staff_note') === null && $CALLS === []);
$r = WaAuthOtp::sendFromText('919812345678', "S Hari एजेन्ट लगइन कोड: 731904\n10 मिनेट सम्म मान्य। यो कोड कसैलाई नबताउनुहोस्।", 'otp');
check('a login-code message goes on the template', $r !== null && $r['success'] && ($CALLS[0][2]['template']['components'][0]['parameters'][0]['text'] ?? '') === '731904');

$CALLS = [];
$ok = Notify::whatsapp('9812345678', "S Hari एडमिन लगइन कोड: 552210\n10 मिनेट सम्म मान्य।", null, 'IN', [], null, ['purpose' => 'otp']);
$sent = array_values(array_filter($CALLS, static fn(array $c): bool => str_contains($c[1], '/messages')));
check('Notify::whatsapp sends a login code on the template', $ok === true && ($sent[0][2]['template']['components'][0]['parameters'][0]['text'] ?? '') === '552210', json_encode($ok));

echo "-- made by hand in WhatsApp Manager (API refused) --\n";
Database::run("DELETE FROM settings WHERE skey IN ('wa_auth_tpl_status','wa_auth_tpl_id','wa_auth_tpl_checked')");
Settings::flush();
check('fresh: not ready', !WaAuthOtp::ready());
WaAuthOtp::markManual(true, 1);
$CALLS = [];
check('marked by the owner: ready without asking Meta for the status', WaAuthOtp::ready() && WaAuthOtp::manual() && $CALLS === []);
$r = WaAuthOtp::send('9779812345678', '246810');
check('and a code goes on it', $r['success'] && ($CALLS[0][2]['to'] ?? '') === '9779812345678');
WaAuthOtp::markManual(false, 1);
check('removed: not ready', !WaAuthOtp::ready());
WaAuthOtp::markManual(true, 1);

echo "-- switch --\n";
Settings::set('wa_auth_otp_on', false, 'bool', 'whatsapp', false);
$CALLS = [];
check('switched off: not ready, nothing sent', !WaAuthOtp::ready() && !WaAuthOtp::send('919812345678', '111222')['success'] && $CALLS === []);
Settings::set('wa_auth_otp_on', true, 'bool', 'whatsapp', false);

echo "\n" . ($FAIL === 0 ? "ALL PASS: $PASS" : "FAILED: $FAIL (passed $PASS)") . "\n";
exit($FAIL === 0 ? 0 : 1);
