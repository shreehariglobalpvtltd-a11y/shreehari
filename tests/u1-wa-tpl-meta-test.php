<?php
/**
 * =====================================================================
 *  u1-wa-tpl-meta-test.php — WA-2 (19 Nov 2026): the Meta template
 *  registry (includes/watplmeta.php).
 *
 *    A. every DEF is Meta-valid: name, body < 550, {{1}}..{{n}} in order,
 *       one single-line example per variable (no newline / tab), no
 *       variable at the start / end, URL button {{1}} at the end, UTILITY
 *    B. submitMissing(): the 7 hand-made templates and any name the WABA
 *       already has are NEVER posted; a dry run posts nothing; a real run
 *       posts only the missing ones (media headers get an upload handle,
 *       the FLOW form is skipped while no Flow is published); a second
 *       run posts nothing (idempotent)
 *    C. sync() stores APPROVED / PENDING / MISSING; ready() only for
 *       APPROVED, never while wa_tpl_meta_on = 0 or just refused (132001)
 *    D. forPurpose(): null until APPROVED; then the exact variables, the
 *       DOCUMENT header, the URL button value; ticket = first READY of
 *       [whatsapp_template_name, shg_ticket_v2]
 *    E. the monthly statement cron (--check) picks shg_agent_statement
 *       once APPROVED; tools/wa-meta-templates.php --list runs
 *
 *  No network: the Graph call and the sample upload are stubbed. kv_store
 *  scope wa_tpl_meta is cleared before and after.
 *    php -c .claude/php-dev.ini tests/u1-wa-tpl-meta-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/watplmeta.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function tset(string $k, mixed $v): void {
    Settings::get('__load__');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $r->getValue();
    $c[$k] = ['value' => is_bool($v) ? ($v ? '1' : '0') : (string) $v, 'type' => is_bool($v) ? 'bool' : 'string', 'public' => false];
    $r->setValue(null, $c);
}
$clean = static fn() => Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta'");
$clean();
tset('wa_tpl_meta_on', true);
tset('wa_flow_book_id', '');
tset('whatsapp_template_name', 'shg_ticket_image');
tset('whatsapp_template_lang', 'en');

echo "\n=== WA-2: the Meta template registry ===\n\n";

/* ---------------- A ---------------- */
echo "-- A. definitions --\n";
$want = ['shg_ticket_v2', 'shg_pay_request', 'shg_trip_reminder', 'shg_bus_live', 'shg_ticket_changed', 'shg_agent_statement',
         'shg_withdraw_update', 'shg_daily_collection', 'shg_driver_manifest', 'shg_booking_form'];
check('all 10 new templates are defined', array_diff($want, array_keys(WaTplMeta::DEFS)) === []);
check('the 7 hand-made ones are known', count(WaTplMeta::KNOWN) === 7 && isset(WaTplMeta::KNOWN['shg_ticket_image'], WaTplMeta::KNOWN['shg_office_alert']));
check('no name is both known and defined', array_intersect(array_keys(WaTplMeta::KNOWN), array_keys(WaTplMeta::DEFS)) === []);
foreach (WaTplMeta::defs() as $n => $d) {
    $bad = WaTplMeta::validate($n, $d);
    check("$n is valid", $bad === [], implode('; ', $bad));
    $p = WaTplMeta::payload($n, $d, 'HANDLE', '123');
    check("$n payload: UTILITY, en", $p['category'] === 'UTILITY' && $p['language'] === 'en');
}
check('ticket_v2 has the same 6 variables as shg_ticket_image', WaTplMeta::DEFS['shg_ticket_v2']['vars'] === WaTplMeta::KNOWN['shg_ticket_image']['vars']);
check('statement + driver list carry a DOCUMENT header', WaTplMeta::DEFS['shg_agent_statement']['header'] === 'DOCUMENT' && WaTplMeta::DEFS['shg_driver_manifest']['header'] === 'DOCUMENT');
check('validate() catches a newline in an example', WaTplMeta::validate('x_bad', ['body' => 'Hi {{1}} ok', 'vars' => ['a'], 'examples' => ["two\nlines"], 'header' => '']) !== []);
check('validate() catches a variable at the end', WaTplMeta::validate('x_bad', ['body' => 'Hello {{1}}', 'vars' => ['a'], 'examples' => ['x'], 'header' => '']) !== []);
check('flat() makes one line', WaTplMeta::flat("a\nb\tc") === 'a · b c' && WaTplMeta::flat('') === '-');

/* ---------------- B ---------------- */
echo "\n-- B. submitMissing --\n";
$remote = [];
foreach (array_keys(WaTplMeta::KNOWN) as $n) {
    $remote[$n] = ['name' => $n, 'status' => $n === 'shg_ticket_image' ? 'APPROVED' : 'PENDING', 'category' => 'UTILITY', 'language' => 'en', 'id' => 'id_' . $n];
}
$remote['shg_ticket_v2'] = ['name' => 'shg_ticket_v2', 'status' => 'PENDING', 'category' => 'UTILITY', 'language' => 'en', 'id' => 'id_v2'];
$posts = [];
WaTplMeta::$graphHook = static function (string $method, string $path, ?array $body) use (&$remote, &$posts): array {
    if ($method === 'GET') {
        return ['http' => 200, 'json' => ['data' => array_values($remote)]];
    }
    $posts[] = $body;
    $remote[(string) $body['name']] = ['name' => $body['name'], 'status' => 'PENDING', 'category' => 'UTILITY', 'language' => 'en', 'id' => 'new_' . $body['name']];
    return ['http' => 200, 'json' => ['id' => 'new_' . $body['name'], 'status' => 'PENDING', 'category' => 'UTILITY']];
};
$uploads = [];
WaTplMeta::$uploadHook = static function (string $kind) use (&$uploads): string { $uploads[] = $kind; return 'h_' . $kind; };

$dry = WaTplMeta::submitMissing(true);
$acts = array_column($dry['rows'], 'action', 'name');
check('dry run: ok and nothing posted', $dry['ok'] && $posts === [] && $uploads === []);
check('dry run: the 7 hand-made ones are "known"', count(array_filter($acts, static fn($a) => $a === 'known')) === 7);
check('dry run: a name the WABA has is "exists"', ($acts['shg_ticket_v2'] ?? '') === 'exists');
check('dry run: the form waits for a published Flow', ($acts['shg_booking_form'] ?? '') === 'skipped');
check('dry run: shg_pay_request would be submitted', ($acts['shg_pay_request'] ?? '') === 'would_submit');

$real = WaTplMeta::submitMissing(false);
$postedNames = array_column($posts, 'name');
check('real run posts only the missing 8', $real['ok'] && $real['submitted'] === 8 && count($posts) === 8, implode(',', $postedNames));
check('never posts a hand-made name or an existing one', array_intersect($postedNames, array_merge(array_keys(WaTplMeta::KNOWN), ['shg_ticket_v2', 'shg_booking_form'])) === []);
check('media headers got an upload handle', in_array('DOCUMENT', $uploads, true) && in_array('IMAGE', $uploads, true));
$stmtPost = array_values(array_filter($posts, static fn($p) => $p['name'] === 'shg_agent_statement'))[0] ?? [];
check('statement post: DOCUMENT header with the handle', ($stmtPost['components'][0]['format'] ?? '') === 'DOCUMENT' && ($stmtPost['components'][0]['example']['header_handle'][0] ?? '') === 'h_DOCUMENT');
$payPost = array_values(array_filter($posts, static fn($p) => $p['name'] === 'shg_pay_request'))[0] ?? [];
$btn = end($payPost['components'])['buttons'][0] ?? [];
check('pay post: URL button to pay.php?pnr={{1}} with a full example', ($btn['type'] ?? '') === 'URL' && str_ends_with((string) ($btn['url'] ?? ''), '/pay.php?pnr={{1}}') && str_ends_with((string) ($btn['example'][0] ?? ''), 'pnr=SHG-2026-00584'));
check('submitted status stored as PENDING', WaTplMeta::status('shg_pay_request') === 'PENDING');
$posts = [];
$again = WaTplMeta::submitMissing(false);
check('second run posts nothing (idempotent)', $again['ok'] && $again['submitted'] === 0 && $posts === []);

/* ---------------- C ---------------- */
echo "\n-- C. sync / ready --\n";
$remote['shg_pay_request']['status'] = 'APPROVED';
$remote['shg_agent_statement']['status'] = 'APPROVED';
$remote['shg_trip_reminder']['status'] = 'REJECTED';
unset($remote['shg_bus_live']);
$s = WaTplMeta::sync();
check('sync ok', $s['ok']);
check('APPROVED stored', WaTplMeta::status('shg_pay_request') === 'APPROVED');
check('REJECTED stored', WaTplMeta::status('shg_trip_reminder') === 'REJECTED');
check('a name missing in the WABA is MISSING', WaTplMeta::status('shg_bus_live') === 'MISSING');
check('ready() only for APPROVED', WaTplMeta::ready('shg_pay_request') && !WaTplMeta::ready('shg_trip_reminder') && !WaTplMeta::ready('shg_withdraw_update'));
check('lastSync() is set', WaTplMeta::lastSync() >= time() - 5);
tset('wa_tpl_meta_on', false);
check('wa_tpl_meta_on = 0: nothing is ready', !WaTplMeta::ready('shg_pay_request'));
tset('wa_tpl_meta_on', true);

// The stale hand-set login template flag (live: APPROVED + manual, but not in this WABA).
require_once INCLUDE_PATH . '/waauthotp.php';
WaAuthOtp::$graphHook = static fn(string $m, string $p, ?array $b): array => ['http' => 200, 'json' => []];
tset('wa_auth_otp_on', true);
tset('wa_auth_tpl_status', 'APPROVED');
tset('wa_auth_tpl_manual', true);
check('sync saw shg_login_code MISSING', WaTplMeta::status('shg_login_code') === 'MISSING');
tset('wa_auth_trust_sync', true);
check('login template: a synced MISSING beats the stale APPROVED', WaAuthOtp::ready() === false);
tset('wa_auth_trust_sync', false);
check('wa_auth_trust_sync = 0: the old flag rules again', WaAuthOtp::ready() === true);
WaAuthOtp::$graphHook = null;

/* ---------------- D ---------------- */
echo "\n-- D. forPurpose --\n";
$pay = WaTplMeta::forPurpose('pay', ['amount' => '₹2,800', 'seat' => 'L3, L4', 'date' => '20 Nov 2026', 'pnr' => 'SHG-2099-90111']);
check('pay: shg_pay_request', ($pay['name'] ?? '') === 'shg_pay_request');
check('pay: vars amount, seat, date in order', ($pay['vars'] ?? []) === ['₹2,800', 'L3, L4', '20 Nov 2026']);
check('pay: URL button value is the PNR', ($pay['buttons'][0]['text'] ?? '') === 'SHG-2099-90111' && ($pay['buttons'][0]['sub_type'] ?? '') === 'url');
check('pay without a PNR: null (the button cannot work)', WaTplMeta::forPurpose('pay', ['amount' => '₹1']) === null);
check('a missing value is "-", never invented', (WaTplMeta::forPurpose('pay', ['pnr' => 'SHG-2099-90111'])['vars'] ?? []) === ['-', '-', '-']);
check('reminder: null while REJECTED', WaTplMeta::forPurpose('reminder_2h', ['name' => 'X']) === null);
check('withdraw: null while never approved', WaTplMeta::forPurpose('withdraw', ['request' => 'WD-1']) === null);
$st = WaTplMeta::forPurpose('agent_statement', ['period' => '1 Oct 2026 to 31 Oct 2026', 'tickets' => '4', 'passengers' => '5', 'collected' => '₹9,000', 'commission' => '₹900', 'balance' => '₹100', 'header' => 'https://example.test/download-statement.php?f=a.pdf']);
check('statement: DOCUMENT header + 6 vars', ($st['header']['document'] ?? '') !== '' && count($st['vars'] ?? []) === 6);
check('statement without an https PDF: null', WaTplMeta::forPurpose('agent_statement', ['period' => 'x', 'header' => 'http://insecure/x.pdf']) === null);

$tv = ['1' => 'Ram', '2' => 'Surat', '3' => 'Rupaidiha', '4' => '20 Nov 2026', '5' => 'L3', '6' => 'SHG-2099-90111', '7' => 'https://example.test/ticket.png'];
$t = WaTplMeta::forPurpose('ticket', ['vars' => $tv]);
check('ticket: the configured shg_ticket_image (APPROVED)', ($t['name'] ?? '') === 'shg_ticket_image' && ($t['header']['image'] ?? '') === 'https://example.test/ticket.png' && count($t['vars'] ?? []) === 6);
WaTplMeta::noteRefused('shg_ticket_image', 132001);
check('ticket: refused just now and v2 not approved -> no template', WaTplMeta::ticketTemplate()['name'] === '' && WaTplMeta::forPurpose('ticket', ['vars' => $tv]) === null);
$remote['shg_ticket_v2']['status'] = 'APPROVED';
WaTplMeta::sync();
check('ticket: refused + v2 APPROVED -> shg_ticket_v2', WaTplMeta::ticketTemplate()['name'] === 'shg_ticket_v2' && (WaTplMeta::forPurpose('ticket', ['vars' => $tv])['name'] ?? '') === 'shg_ticket_v2');
WaTplMeta::noteRefused('shg_ticket_v2', 131047);
check('a non-template error (131047) is not a refusal', !WaTplMeta::refusedRecently('shg_ticket_v2'));

/* ---------------- E ---------------- */
echo "\n-- E. cron + CLI --\n";
$php = PHP_BINARY;
$ini = php_ini_loaded_file();
$cmd = escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(ROOT_PATH . '/cron/wa-monthly-statements.php') . ' --check 2>&1';
$out = (string) shell_exec($cmd);
check('monthly cron --check picks shg_agent_statement once APPROVED', str_contains($out, 'shg_agent_statement'), mb_substr(trim($out), 0, 160));
$cmd = escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(ROOT_PATH . '/tools/wa-meta-templates.php') . ' --list 2>&1';
$out = (string) shell_exec($cmd);
check('tools/wa-meta-templates.php --list lists the registry, no "!" problems', str_contains($out, 'shg_driver_manifest') && str_contains($out, 'APPROVED') && !str_contains($out, '  ! '));

WaTplMeta::$graphHook = null;
WaTplMeta::$uploadHook = null;
$clean();

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
