<?php
/**
 * =====================================================================
 *  desk-tools-test.php — P4 desk tools (15 Nov 2026).
 *
 *  Pure part (no database writes):
 *    - ID masking: Aadhaar shows only the last four, other IDs the last
 *      four dotted, the same answer as AgentPro::maskId; a list label
 *      never carries the full number
 *    - agent cash aging: first-in-first-out against fixture ledger rows,
 *      bucket edges 7/8, 15/16, 30/31 days, an advance, paise rounding,
 *      row order does not matter
 *    - cancellation bands: > 48 h, 24-48 h (both ends), < 24 h, after
 *      departure, unknown
 *
 *  Database part (shari_test only; every row it makes is deleted):
 *    - agent-aging.php CSV for a throwaway agent with fixture ledger rows
 *    - booking-view.php refuses an email / SMS resend POST with a bad
 *      CSRF token (no audit row), and with a good token but the switch
 *      OFF says why and sends nothing
 *    - admin/api/id-reveal.php: bad CSRF -> 419, an agent -> 403, a
 *      super-admin -> the full number + one id.reveal audit row
 *    - ops-reports.php (both tabs) and agent-aging.php render
 *
 *    php -c .claude/php-dev.ini tests/desk-tools-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: render one admin page as a given staff member ---------
   argv: --child <base64 json {page, admin, get, post, csrf, api}> */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) ($cfg['page'] ?? '');
    if (!preg_match('#^(api/)?[a-z0-9-]+\.php$#', $page)) { fwrite(STDERR, "bad page\n"); exit(2); }
    $_GET = (array) ($cfg['get'] ?? []);
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]   = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE']  = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/' . $page;
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/desktools.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
/** Run one admin page in a child PHP with a faked session; returns its output. */
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}

echo "\n=== P4 desk tools ===\n\n";

/* ---- 1. ID masking ---------------------------------------------------- */
echo "-- ID masking\n";
check('Aadhaar shows the last four only', DeskTools::maskId('Aadhaar', '1234 5678 9012') === 'XXXX XXXX 9012', DeskTools::maskId('Aadhaar', '1234 5678 9012'));
check('"Aadhar card" spelled the desk way is still Aadhaar', DeskTools::idKind('Aadhar Card') === 'aadhaar' && DeskTools::maskId('AADHAR', '123456789012') === 'XXXX XXXX 9012');
check('"Adhar" / "Adhaar" (one a) and UID count as Aadhaar; Voter ID does not',
    DeskTools::idKind('Adhar card') === 'aadhaar' && DeskTools::idKind('ADHAAR') === 'aadhaar' && DeskTools::idKind('UID') === 'aadhaar'
    && DeskTools::idKind('Voter ID') === 'voter id' && DeskTools::maskId('Adhar', '1234 5678 9012') === 'XXXX XXXX 9012');
check('citizenship: last four, rest dotted (= AgentPro::maskId)', DeskTools::maskId('Citizenship', '12-34-56789') === AgentPro::maskId('citizenship', '12-34-56789')
    && str_ends_with(DeskTools::maskId('Citizenship', '12-34-56789'), '6789') && !str_contains(DeskTools::maskId('Citizenship', '12-34-56789'), '56789'), DeskTools::maskId('Citizenship', '12-34-56789'));
check('passport label = type + masked number', DeskTools::idLabel('Passport', 'N1234567') === 'Passport ••••4567', DeskTools::idLabel('Passport', 'N1234567'));
check('an Aadhaar with under four digits is never shown whole', DeskTools::maskId('Aadhaar', '12') === '••••');
check('no number -> empty label', DeskTools::idLabel('Voter ID', '') === '' && DeskTools::idLabel(null, null) === '');
$long = 'GJE2E0001';
check('a list label never contains the full number', !str_contains(DeskTools::idLabel('Voter ID', $long), $long), DeskTools::idLabel('Voter ID', $long));

/* ---- 2. Aging maths --------------------------------------------------- */
echo "\n-- Agent cash aging (FIFO)\n";
$now = (int) strtotime('2026-11-15 12:00:00');
$at  = static fn(int $days): string => date('Y-m-d H:i:s', $now - $days * 86400);
$fx = [
    ['amount' => '1000.00', 'created_at' => $at(40)],   // cash_due
    ['amount' => '500.00',  'created_at' => $at(20)],
    ['amount' => '300.00',  'created_at' => $at(10)],
    ['amount' => '200.00',  'created_at' => $at(2)],
    ['amount' => '-1200.00', 'created_at' => $at(1)],   // cash_handover
];
$ag = DeskTools::agingBuckets($fx, $now);
check('handover clears the oldest first: 40-day 1000 gone, 300 of the 20-day left',
    $ag['buckets'] === ['0-7' => 200.0, '8-15' => 300.0, '16-30' => 300.0, '30+' => 0.0], json_encode($ag['buckets']));
check('buckets add up to the balance (2000 - 1200 = 800)', $ag['total'] === 800.0 && $ag['credit'] === 0.0, 'total ' . $ag['total']);
check('oldest unpaid cash is 20 days', $ag['oldestDays'] === 20, (string) $ag['oldestDays']);
$shuffled = [$fx[4], $fx[2], $fx[0], $fx[3], $fx[1]];
check('row order does not change the answer', DeskTools::agingBuckets($shuffled, $now) === $ag);
$edges = [];
foreach ([0 => '0-7', 7 => '0-7', 8 => '8-15', 15 => '8-15', 16 => '16-30', 30 => '16-30', 31 => '30+', 400 => '30+'] as $d => $want) {
    $edges[] = DeskTools::bucketFor($d) === $want ? '' : "$d->" . DeskTools::bucketFor($d);
}
check('bucket edges 7|8, 15|16, 30|31', array_filter($edges) === [], implode(' ', array_filter($edges)));
check('a future-dated row counts as today', DeskTools::agingBuckets([['amount' => 50, 'created_at' => $at(-3)]], $now)['buckets']['0-7'] === 50.0);
$adv = DeskTools::agingBuckets([['amount' => 100, 'created_at' => $at(5)], ['amount' => -150, 'created_at' => $at(4)]], $now);
check('handed in more than owed: nothing due, 50 ahead', $adv['total'] === 0.0 && $adv['credit'] === 50.0 && $adv['oldestDays'] === null);
$paise = DeskTools::agingBuckets([['amount' => '0.10', 'created_at' => $at(3)], ['amount' => '0.20', 'created_at' => $at(2)], ['amount' => '-0.30', 'created_at' => $at(1)]], $now);
check('paise do not drift (0.10 + 0.20 - 0.30 = 0)', $paise['total'] === 0.0 && $paise['credit'] === 0.0);
$adj = DeskTools::agingBuckets([['amount' => 400, 'created_at' => $at(35)], ['amount' => 250, 'created_at' => $at(12)], ['amount' => -100, 'created_at' => $at(3)]], $now);
check('a credit adjustment pays the 35-day cash down to 300', $adj['buckets']['30+'] === 300.0 && $adj['buckets']['8-15'] === 250.0 && $adj['total'] === 550.0);

/* ---- 3. Cancellation bands -------------------------------------------- */
echo "\n-- Cancellation bands\n";
$dep = (int) strtotime('2026-11-20 13:00:00');
$h   = 3600;
$cases = [
    [$dep - 49 * $h, 'gt48'], [$dep - 48 * $h, '24to48'], [$dep - 30 * $h, '24to48'], [$dep - 24 * $h, '24to48'],
    [$dep - 24 * $h + 1, 'lt24'], [$dep - 60, 'lt24'], [$dep, 'lt24'], [$dep + 1, 'after'], [$dep + 5 * $h, 'after'],
];
$bad = [];
foreach ($cases as [$cx, $want]) {
    $got = DeskTools::cancelBand($dep, $cx);
    if ($got !== $want) { $bad[] = round(($dep - $cx) / $h, 2) . 'h=>' . $got; }
}
check('bands: >48 | 24-48 (both ends) | <24 | after', $bad === [], implode(' ', $bad));
check('no departure on file -> unknown', DeskTools::cancelBand(null, $dep) === 'unknown' && DeskTools::cancelBand($dep, null) === 'unknown');
check('every band has a label', count(array_intersect(['gt48', '24to48', 'lt24', 'after', 'unknown'], array_keys(DeskTools::CANCEL_BANDS))) === 5);

/* ---- 4. Database part --------------------------------------------------- */
echo "\n-- Database part\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  database part: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($super <= 0) {
    echo "  \033[33mSKIP\033[0m  database part: no active super-admin\n";
    finish();
}
$startId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$made    = ['admin' => 0];
$saved   = Database::fetch("SELECT svalue FROM settings WHERE skey = 'desk_resend_email_on'");
$cleanup = static function () use (&$made, $startId, $super, $saved): void {
    if ($made['admin'] > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $made['admin']]);
        Database::delete('admins', 'id = :a', ['a' => $made['admin']]);
        Database::delete('audit_logs', "id > :s AND entity_id = :e", ['s' => $startId, 'e' => (string) $made['admin']]);
    }
    Database::delete('audit_logs', "id > :s AND action IN ('booking.resend_email', 'booking.resend_sms', 'id.reveal')", ['s' => $startId]);
    // only this test's own exports: other pages' report.export rows (other tests running at the same time) stay
    Database::delete('audit_logs', "id > :s AND action = 'report.export' AND entity_id IN ('agent-aging', 'ops-cancel', 'ops-cancel_list', 'ops-fleet')", ['s' => $startId]);
    foreach (['ticket_resend_email', 'ticket_resend_sms', 'id_reveal'] as $bk) {
        try { Database::delete('rate_limits', 'bucket = :b', ['b' => $bk]); } catch (Throwable $e) {}
    }
    if ($saved !== null) {
        Database::update('settings', ['svalue' => $saved['svalue']], "skey = 'desk_resend_email_on'");
        Settings::flush();
    }
};

try {
    // Never let this test send a real email: the resend switch is OFF while it runs.
    if ($saved !== null && (string) $saved['svalue'] !== '0') {
        Database::update('settings', ['svalue' => '0'], "skey = 'desk_resend_email_on'");
    }

    /* 4a. aging page CSV for a throwaway agent */
    $made['admin'] = Database::insert('admins', [
        'username' => 'p4-aging-' . substr(bin2hex(random_bytes(3)), 0, 6), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'P4 Aging Test', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    $realNow = time();
    foreach ([[1000, 40, 'cash_due'], [500, 20, 'cash_due'], [300, 10, 'cash_due'], [200, 2, 'cash_due'], [-1200, 1, 'cash_handover']] as [$amt, $d, $type]) {
        Database::insert('agent_ledger', ['agent_admin_id' => $made['admin'], 'booking_id' => null, 'account' => 'cash', 'entry_type' => $type,
            'amount' => $amt, 'note' => 'p4 desk-tools test', 'ref' => '', 'created_at' => date('Y-m-d H:i:s', $realNow - $d * 86400)]);
    }
    $csv  = child(['page' => 'agent-aging.php', 'admin' => $super, 'get' => ['export' => 'csv']]);
    $line = '';
    foreach (preg_split('/\R/', $csv) ?: [] as $l) { if (str_contains($l, 'P4 Aging Test')) { $line = $l; } }
    check('agent-aging CSV: 200 | 300 | 300 | 0, total 800, oldest 20 d',
        $line !== '' && str_contains($line, '200.00,300.00,300.00,0.00,800.00,20,0.00'), $line !== '' ? $line : substr($csv, 0, 160));
    $page = child(['page' => 'agent-aging.php', 'admin' => $super]);
    check('agent-aging page renders with the agent and a totals row', str_contains($page, 'P4 Aging Test') && str_contains($page, '<tfoot>') && !str_contains($page, 'Application error'));
    $agentAsk = child(['page' => 'agent-aging.php', 'admin' => $made['admin']]);
    // (the agent's own name is in the top bar of the refusal page, so look for the report heading, not the name)
    check('an agent cannot open the aging page', !str_contains($agentAsk, 'Cash held by agents')
        && (str_contains($agentAsk, 'not for your role') || str_contains($agentAsk, 'office page')));

    /* 4b. resend POST: CSRF refusal, then the OFF switch */
    $pnr = (string) Database::scalar(
        "SELECT b.pnr FROM bookings b WHERE b.status = 'confirmed'
            AND EXISTS (SELECT 1 FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id = b.id)
          ORDER BY b.id DESC LIMIT 1", [], '');
    if ($pnr === '') {
        echo "  \033[33mSKIP\033[0m  resend checks: no confirmed booking with a trip in shari_test\n";
    } else {
        $before = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action LIKE 'booking.resend_%'", ['s' => $startId], 0);
        $msgsBefore = (int) Database::scalar("SELECT COALESCE(MAX(id), 0) FROM message_logs", [], 0);
        $o = child(['page' => 'booking-view.php', 'admin' => $super, 'get' => ['pnr' => $pnr],
                    'post' => ['action' => 'resend_email', 'resend_email' => 'desk-test@example.invalid'], 'csrf' => 'bad']);
        check('email resend with a bad CSRF token is refused', str_contains($o, 'Session expired'), $pnr);
        $o2 = child(['page' => 'booking-view.php', 'admin' => $super, 'get' => ['pnr' => $pnr], 'post' => ['action' => 'resend_sms'], 'csrf' => 'bad']);
        check('SMS resend with a bad CSRF token is refused', str_contains($o2, 'Session expired'));
        $after = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action LIKE 'booking.resend_%'", ['s' => $startId], 0);
        check('a refused resend writes no audit row', $after === $before, "$before -> $after");
        $o3 = child(['page' => 'booking-view.php', 'admin' => $super, 'get' => ['pnr' => $pnr],
                     'post' => ['action' => 'resend_email', 'resend_email' => 'desk-test@example.invalid'], 'csrf' => 'valid']);
        check('with the switch OFF the email is not sent and the page says why', str_contains($o3, 'Email not sent') && str_contains($o3, 'desk_resend_email_on'));
        $sent = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE id > :m AND channel = 'email'", ['m' => $msgsBefore], 0);
        check('nothing went out (no email row in message_logs)', $sent === 0);
        check('the refused-by-switch attempt is in the audit log',
            (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'booking.resend_email' AND entity_id = :p", ['s' => $startId, 'p' => $pnr], 0) === 1);
        $view = child(['page' => 'booking-view.php', 'admin' => $super, 'get' => ['pnr' => $pnr]]);
        check('booking page: greyed Email / SMS buttons with their reason', str_contains($view, 'value="resend_email" disabled') && str_contains($view, 'value="resend_sms" disabled') && str_contains($view, 'bv-rs-why'));
        check('booking page: keyboard overlay and WhatsApp key wired', str_contains($view, 'id="bvKeys"') && str_contains($view, 'data-kbd="w"'));
        check('booking page: keys stand aside while another sheet (WhatsApp preview) is open', str_contains($view, 'function otherModal()') && str_contains($view, 'if (ov.hidden && otherModal()) return;'));
    }
    // A legacy ticket with no booking_legs row used to stop the page at Quick Edit's seat picker.
    $noLeg = (string) Database::scalar(
        "SELECT b.pnr FROM bookings b WHERE NOT EXISTS (SELECT 1 FROM booking_legs l WHERE l.booking_id = b.id) ORDER BY b.id DESC LIMIT 1", [], '');
    if ($noLeg === '') {
        echo "  \033[33mSKIP\033[0m  no-leg render: every booking in shari_test has a leg\n";
    } else {
        $nl = child(['page' => 'booking-view.php', 'admin' => $super, 'get' => ['pnr' => $noLeg]]);
        check('booking page renders a ticket with no booking_legs row', str_contains($nl, 'id="bvKeys"') && !str_contains($nl, 'Application error'), $noLeg);
    }

    /* 4c. id-reveal endpoint */
    $px = Database::fetch("SELECT id, id_type, id_number FROM booking_passengers WHERE id_number IS NOT NULL AND CHAR_LENGTH(id_number) > 4 ORDER BY id DESC LIMIT 1");
    if ($px === null) {
        echo "  \033[33mSKIP\033[0m  id-reveal checks: no passenger with an ID number\n";
    } else {
        $ref = (string) (int) $px['id'];
        $r1 = json_decode(child(['page' => 'api/id-reveal.php', 'admin' => $super, 'post' => ['kind' => 'px', 'ref' => $ref], 'csrf' => 'bad']), true);
        check('id-reveal: bad CSRF -> 419, no value', is_array($r1) && ($r1['code'] ?? 0) === 419 && !isset($r1['value']));
        $r2 = json_decode(child(['page' => 'api/id-reveal.php', 'admin' => $made['admin'], 'post' => ['kind' => 'px', 'ref' => $ref]]), true);
        check('id-reveal: an agent -> 403, no value', is_array($r2) && ($r2['code'] ?? 0) === 403 && !isset($r2['value']));
        $r3 = json_decode(child(['page' => 'api/id-reveal.php', 'admin' => $super, 'post' => ['kind' => 'px', 'ref' => $ref]]), true);
        check('id-reveal: super-admin gets the full number', is_array($r3) && ($r3['ok'] ?? false) === true && str_contains((string) ($r3['value'] ?? ''), (string) $px['id_number']));
        $aud = Database::fetch("SELECT new_value FROM audit_logs WHERE id > :s AND action = 'id.reveal' AND entity_id = :r ORDER BY id DESC LIMIT 1", ['s' => $startId, 'r' => $ref]);
        check('id-reveal: one audit row, without the number in it', $aud !== null && !str_contains((string) $aud['new_value'], (string) $px['id_number']));
    }

    /* 4d. ops reports render */
    $c1 = child(['page' => 'ops-reports.php', 'admin' => $super, 'get' => ['from' => '2026-01-01', 'to' => '2026-12-31']]);
    check('ops-reports cancellations tab renders', str_contains($c1, 'By notice before departure') && !str_contains($c1, 'Application error'));
    $c2 = child(['page' => 'ops-reports.php', 'admin' => $super, 'get' => ['tab' => 'fleet', 'from' => '2026-01-01', 'to' => '2026-12-31']]);
    check('ops-reports bus-use tab renders', str_contains($c2, 'Seat fill') && !str_contains($c2, 'Application error'));
    $c3 = child(['page' => 'ops-reports.php', 'admin' => $super, 'get' => ['export' => 'fleet', 'from' => '2026-01-01', 'to' => '2026-12-31']]);
    check('ops-reports bus-use CSV has a TOTAL line', str_contains($c3, 'Seats offered') && str_contains($c3, 'TOTAL'));
} catch (Throwable $e) {
    check('database part ran without an exception', false, $e->getMessage());
} finally {
    $cleanup();
    check('cleanup: the throwaway agent and its ledger rows are gone',
        $made['admin'] === 0 || ((int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id = :a', ['a' => $made['admin']], 0) === 0
            && (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $made['admin']], 0) === 0));
}
finish();
