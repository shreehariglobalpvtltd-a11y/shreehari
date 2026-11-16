<?php
/**
 * 16 Nov 2026 — G1: a TEMPORARY password gets no staff power on the pages
 * outside admin/ either.
 *
 * Since mv5 a session whose account must change its temporary password
 * (admins.must_change_pw) is sent to change-password.php by every admin page
 * (Auth::requireAdmin) and answered 403 by every /api/ endpoint
 * (includes/bootstrap.php section 6b). The public pages that also serve staff
 * asked only Auth::admin() / Auth::can(), which never look at the flag, so such
 * a session could still open ANY passenger's ticket keyless, read the full
 * verify card, pull a chalan or an agent statement. They now ask
 * Auth::staffActive() in their STAFF branch (same rule as 6b: the session flag
 * OR the admins row read now).
 *
 * G1 refix (same day): the member's OWN pages ride on the same admin session and
 * were missed - me/ (index, form.php with the full ID / PAN / bank numbers,
 * doc.php with the KYC images + upload into the KYC record, print.php with the
 * company ID card) and team/ (home, form, cv, card, agreement through
 * TeamPortal::current()). They now send such a session to change-password.php
 * (me/doc.php: 403, like /api/).
 *
 *   A  CLI: Auth::staffActive() itself (guest, clean, session flag, fresh row)
 *      and Auth::can() left exactly as it was
 *   B  a clean superadmin session uses every staff branch (keyless ticket PDF +
 *      PNG, office DUPLICATE reprint, full verify card, chalan, statement) and
 *      its own me/ + team/ pages
 *   C  the SAME account after the office sets must_change_pw = 1 mid-session
 *      gets the guest answer on every one (302 to My Bookings / "Verification
 *      unavailable" / 403 / change-password.php) and never the staff data; set
 *      back to 0 it works again. C signs OUT and in again first (a sign-in on a
 *      signed-in jar is a no-op), checks that a new session really opened and
 *      that every refusal came inside Auth::admin()'s 30 s re-check window - so
 *      only the FRESH row read can have refused, not the session flag
 *   D  a session that SIGNED IN on a temporary password: guest answers too
 *      (me/ + team/ pages included, an upload with a valid CSRF token refused
 *      before it touches the KYC record), while admin/ still sends it to
 *      change-password.php
 *   E  the customer paths are untouched: the signed-key PNG (what WhatsApp /
 *      Meta's crawler fetches) + PDF, the keyed verify card, the signed chalan
 *      and statement links - for a guest AND for the temporary-password session
 *      (which may not turn ?dup=1 into the office reprint); a guest on me/ keeps
 *      its old answers
 *   F  payment/check_payment_status.php (staff-only bank status): HTTP when the
 *      local ICICI config exists, else the gate is checked in the source
 *
 *   php -c .claude/php-dev.ini tests/u12-G1-temp-pw-pages-test.php
 *   (SHG_TEST_BASE=http://localhost:<port> to use a running server; with none the
 *    suite starts its own php -S on a free port and stops it on exit)
 *
 * Throwaway rows on a far-future date + two temporary superadmins; everything
 * (bookings, schedule, chalan rows + file, ticket files AND their render .lock
 * files, statement file, accounts, admin_sessions, admin_login_events, the
 * ap_members row me/ makes, automation_log rows, and the audit_logs rows this run
 * wrote about its own accounts / booking / bus) is removed at the end and checked.
 * No setting is changed: the sale's EventBus fan-out (WhatsApp, mail, seat card)
 * is muted in this process instead of flipping shared switches.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/statement.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/agentpro.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }

/* Same as tests/manifest-test.php: SHG_TEST_BASE wins; else our OWN php -S on a
   free port serving THIS checkout with this run's php + ini, stopped on exit. */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {   // up to ~10 s for the server to answer
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());

const TD       = '2098-04-17';
const PHONE    = '9100001212';
const BOSS     = 'u12_g1_boss';
const BOSS_PW  = 'U12G1Boss!83';
const TEMP     = 'u12_g1_temp';
const TEMP_PW  = 'U12G1Temp!47';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$JARS = [];
function jar(string $name): string {
    global $JARS;
    if (!isset($JARS[$name])) {
        $JARS[$name] = sys_get_temp_dir() . '/shg_u12g1_' . $name . '_' . getmypid() . '.txt';
        @unlink($JARS[$name]);
    }
    return $JARS[$name];
}
/** @return array{code:int, body:string, headers:string, type:string, location:string} */
function http(string $jarName, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $jar = jar($jarName);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    if (isset($opt['multipart'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['multipart']); }
    if (isset($opt['headers'])) { curl_setopt($ch, CURLOPT_HTTPHEADER, $opt['headers']); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hlen);
    return [
        'code'     => $code,
        'body'     => substr($raw, $hlen),
        'headers'  => $head,
        'type'     => preg_match('/^Content-Type:\s*([^;\r\n]+)/mi', $head, $m) === 1 ? strtolower(trim($m[1])) : '',
        'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $m) === 1 ? trim($m[1]) : '',
    ];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function signIn(string $jarName, string $user, string $pw): array {
    $login = http($jarName, '/admin/login.php?classic=1&other=1');
    return http($jarName, '/admin/login.php', ['form' => [
        CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => $user, 'password' => $pw]]);
}
function clearBuckets(): void {
    try {
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN
            ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try', 'ticket_dl', 'ticket_verify', 'chalan_dl', 'stmt_dl')");
    } catch (Throwable $e) {}
}
function loginCount(string $user): int {
    return (int) Database::scalar("SELECT COUNT(*) FROM admin_login_events WHERE username = :u AND outcome = 'success'", ['u' => $user], 0);
}
/** a page that sends the session to admin/change-password.php (Response::redirect 302 / TeamUi::go 303) */
function toChange(array $r): bool { return in_array($r['code'], [302, 303], true) && str_contains($r['location'], '/admin/change-password.php'); }
/** the me/doc.php refusal: 403 JSON, the same words as /api/ (bootstrap 6b) */
function refusedTemp(array $r): bool { return $r['code'] === 403 && str_contains($r['body'], 'temporary password') && !str_contains($r['body'], '"docs"'); }
function isPng(array $r): bool { return $r['code'] === 200 && str_starts_with($r['body'], "\x89PNG"); }
function isPdf(array $r): bool { return $r['code'] === 200 && str_starts_with($r['body'], '%PDF'); }
function sumOf(array $r): string { return 'HTTP ' . $r['code'] . ($r['location'] !== '' ? ' -> ' . $r['location'] : '') . ' ' . ($r['type'] ?: '-') . ' ' . strlen($r['body']) . 'B'; }

$made = ['admins' => [], 'pnr' => '', 'bookingId' => 0, 'sched' => 0, 'stmt' => ''];
/* audit_logs rows this run writes (sign-ins, PIN set, chalan made, own form opened, the sale) are
   removed by what they name - this run's accounts / booking / bus - and only above this id, so no
   older history and no other suite's rows are touched. */
define('AUDIT_FROM', (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0));
/** @return array{0:string, 1:array<string, mixed>} the WHERE (after "id > :from AND") naming this run's rows */
function auditMine(): array {
    global $made;
    $ids = array_values(array_filter(array_map('intval', $made['admins'])));
    $or = [];
    $p  = ['from' => AUDIT_FROM];
    if ($ids !== []) {
        $in  = implode(',', $ids);                                               // ints only
        $ins = implode(',', array_map(static fn(int $i): string => "'" . $i . "'", $ids));
        $or[] = "(actor_type = 'admin' AND actor_id IN ($in))";
        $or[] = "(entity_type = 'admin' AND entity_id IN ($ins))";
    }
    if ((int) $made['bookingId'] > 0) {
        $or[] = "(entity_type = 'booking' AND entity_id IN (:bid, :pnr))";
        $p['bid'] = (string) $made['bookingId'];
        $p['pnr'] = (string) $made['pnr'];
    }
    if ((int) $made['sched'] > 0) {
        $or[] = "(entity_type IN ('schedule', 'challan') AND entity_id = :sid)";
        $p['sid'] = (string) $made['sched'];
    }
    return $or === [] ? ['0 = 1', $p] : ['(' . implode(' OR ', $or) . ')', $p];
}
function cleanup(): void {
    global $made;
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => PHONE]) as $r) {
        try { Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM booking_collections WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM automation_log WHERE data LIKE :p", ['p' => '%"' . $r['pnr'] . '"%']); } catch (Throwable $e) {}
        // ticket_<PNR>.pdf / .png AND the render locks Ticket::renderWithLock leaves beside them (.pdf.lock / .png.lock)
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '.*') ?: [] as $f) { @unlink($f); }
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => TD]) as $s) {
        try { Database::run('DELETE FROM challan_reports WHERE schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    $cdir = rtrim(UPLOAD_PATH, '/\\') . '/challan/' . TD;
    foreach (glob($cdir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($cdir);
    if ($made['stmt'] !== '') { @unlink(Statement::dir() . '/' . $made['stmt']); }
    foreach ([BOSS, TEMP] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            // me/form.php + me/print.php make the member row (AgentPro::ensureMember); an upload would make an ap_applications row
            try { Database::delete('ap_members', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try {
                /* only if the doc.php gate ever regresses: the probe upload went into agents-kyc/ and the form's
                   extras (AgentPro::memberAddDoc) - remove the file with the row */
                foreach (Database::fetchAll('SELECT extras FROM ap_applications WHERE admin_id = :a', ['a' => $id]) as $ar) {
                    foreach (jsonColumn($ar['extras'] ?? null) as $xd) {
                        $abs = is_array($xd) ? AgentPro::privateAbs((string) ($xd['path'] ?? '')) : null;
                        if ($abs !== null) { @unlink($abs); }
                    }
                }
                Database::delete('ap_applications', 'admin_id = :a', ['a' => $id]);
            } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
    try { Database::delete('admin_login_events', 'username IN (:a, :b)', ['a' => BOSS, 'b' => TEMP]); } catch (Throwable $e) {}
    [$where, $p] = auditMine();
    try { Database::run("DELETE FROM audit_logs WHERE id > :from AND $where", $p); } catch (Throwable $e) {}
}

echo "\n=== G1: a temporary password gets no staff power on pages outside admin/ ===\n";
echo "  base " . BASE . "\n\n";
cleanup();

/* The sale's fan-out (customer + office WhatsApp, seller mail, seat card, GemVault)
   is not what this suite tests and must not reach anyone: mute the listeners in THIS
   process only. Shared settings stay untouched (other suites run on the same DB). */
(new ReflectionProperty(EventBus::class, 'listeners'))->setValue(null, []);

try {
    /* ---------------- seed ---------------- */
    $mk = static function (string $user, string $pw, int $must): int {
        $id = (int) Database::insert('admins', [
            'username' => $user, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'full_name' => 'U12 G1 ' . $user, 'role' => 'superadmin',
            'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => $must,
        ]);
        QuickUnlock::set($id, 'pin', $user === BOSS ? '615384' : '724913');   // the code-first door wants a PIN first
        return $id;
    };
    $bossId = $mk(BOSS, BOSS_PW, 0);
    $tempId = $mk(TEMP, TEMP_PW, 1);
    $made['admins'] = [$bossId, $tempId];

    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);
    $sid     = (int) $sched['id'];
    $made['sched'] = $sid;
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => $sid]);
    $sale = BookingService::counterSale($route, $sid, TD, ['L8'],
        ['name' => 'Gate Test Passenger', 'phone' => PHONE, 'gender' => 'Male', 'paymentMethod' => 'cash'], $bossId, 'counter');
    $pnr = (string) ($sale['pnr'] ?? '');
    $made['pnr'] = $pnr;
    $made['bookingId'] = (int) ($sale['id'] ?? 0);
    check('seeded a confirmed counter sale on a far-future bus', $pnr !== '' && (string) ($sale['status'] ?? '') === 'confirmed', $pnr);

    // a statement file the way Statement::agentStatementPdf() names it (the gate is what is tested, not the PDF)
    $made['stmt'] = 'agent-' . $bossId . '-2098-04-01-2098-04-30-' . bin2hex(random_bytes(4)) . '.pdf';
    ensureDir(Statement::dir());
    file_put_contents(Statement::dir() . '/' . $made['stmt'], "%PDF-1.4\n% u12 G1 gate test\n%%EOF\n");
    check('a statement file is in place', Statement::validName($made['stmt']) && is_file(Statement::dir() . '/' . $made['stmt']), $made['stmt']);

    $key      = Ticket::downloadToken($pnr);
    $exp      = time() + 3600;
    $chalanK  = substr(Security::sign('chalan-dl|' . $sid . '|challan|1|' . $exp), 0, 20);
    $stmtK    = Statement::token($made['stmt'], $exp);
    $U = [
        'pdf'      => '/download-ticket.php?pnr=' . rawurlencode($pnr),
        'png'      => '/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&view=1',
        'dup'      => '/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&view=1&dup=1',
        'pdfK'     => '/download-ticket.php?pnr=' . rawurlencode($pnr) . '&k=' . $key,
        'pngK'     => '/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&k=' . $key,
        'dupK'     => '/download-ticket.php?pnr=' . rawurlencode($pnr) . '&img=1&view=1&dup=1&k=' . $key,
        'verify'   => '/verify-ticket.php?pnr=' . rawurlencode($pnr),
        'verifyK'  => '/verify-ticket.php?pnr=' . rawurlencode($pnr) . '&k=' . $key,
        'chalan'   => '/download-chalan.php?sid=' . $sid,
        'chalanK'  => '/download-chalan.php?sid=' . $sid . '&exp=' . $exp . '&k=' . $chalanK,
        'stmt'     => '/download-statement.php?f=' . rawurlencode($made['stmt']),
        'stmtK'    => '/download-statement.php?f=' . rawurlencode($made['stmt']) . '&exp=' . $exp . '&k=' . $stmtK,
    ];
    // the verify card names the passenger as first name + last initial ("Gate P.") - to someone entitled only
    $verifiedStaff = static fn(array $r): bool => $r['code'] === 200 && str_contains($r['body'], 'Valid — मान्य')
        && str_contains($r['body'], 'SECURITY CODE') && str_contains($r['body'], '>' . $pnr . '<') && str_contains($r['body'], '>Gate P.<');
    $verifiedGuest = static fn(array $r): bool => $r['code'] === 200 && str_contains($r['body'], 'Verification unavailable')
        && !str_contains($r['body'], 'SECURITY CODE') && !str_contains($r['body'], 'Gate P.') && !str_contains($r['body'], '>' . $pnr . '<');
    $guestTicket = static fn(array $x): bool => $x['code'] === 302 && str_contains($x['location'], '#/my?pnr=')
        && !str_starts_with($x['body'], '%PDF') && !str_starts_with($x['body'], "\x89PNG");
    // GET admin/change-password.php: 200 for ANY signed-in session (it is the one page a temporary password may open), a login bounce for a guest
    $signedIn = static fn(string $j): array => http($j, '/admin/change-password.php');

    /* ---------------- A. the helper itself (CLI, hand-built sessions) ---------------- */
    echo "\n  A. Auth::staffActive()\n";
    unset($_SESSION[ADMIN_SESSION_KEY]);
    check('a guest is not active staff', Auth::staffActive() === null);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin', 'permissions' => [],
        'last_seen' => time(), 'logged_in_at' => time(), 'must_change_pw' => false];
    $sa = Auth::staffActive();
    check('a clean session is active staff (the same row admin() gives)', $sa !== null && (int) $sa['id'] === $bossId);
    $_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] = true;
    check('the session flag alone (row clean) makes it a guest', Auth::staffActive() === null);
    check('  ...while Auth::can() keeps its global behaviour (not changed by G1)', Auth::can('bookings.view') === true && Auth::admin() !== null);
    $_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] = false;
    Database::update('admins', ['must_change_pw' => 1], 'id = :i', ['i' => $bossId]);
    check('a fresh row flag (session still clean) makes it a guest — no 30 s wait', Auth::staffActive() === null);
    Database::update('admins', ['must_change_pw' => 0], 'id = :i', ['i' => $bossId]);
    check('the row cleared again: active staff again', Auth::staffActive() !== null);
    unset($_SESSION[ADMIN_SESSION_KEY]);

    /* ---------------- B. a clean staff session uses every staff branch ---------------- */
    echo "\n  B. clean superadmin session\n";
    clearBuckets();
    $r = signIn('boss', BOSS, BOSS_PW);
    check('the superadmin signs in (classic door)', in_array($r['code'], [200, 302], true), sumOf($r));
    $png = http('boss', $U['png']);
    check('keyless ticket PNG: staff branch serves it', isPng($png), sumOf($png));
    $pdf = http('boss', $U['pdf']);
    check('keyless ticket PDF: staff branch serves it', isPdf($pdf), sumOf($pdf));
    $dup = http('boss', $U['dup']);
    check('?dup=1 gives staff the DUPLICATE reprint (not the normal PNG)', isPng($dup) && md5($dup['body']) !== md5($png['body']), sumOf($dup));
    $v = http('boss', $U['verify']);
    check('keyless verify page: the full staff card (Valid + security code + PNR)', $verifiedStaff($v), 'HTTP ' . $v['code']);
    $c = http('boss', $U['chalan']);
    check('keyless chalan: staff branch serves the PNG', isPng($c), sumOf($c));
    $s = http('boss', $U['stmt']);
    check('keyless statement: staff branch serves the PDF', isPdf($s), sumOf($s));
    // the member's own pages (me/, team/) open for a clean session - the positive side of the D refusals
    $bossName = 'U12 G1 ' . BOSS;
    $x = http('boss', '/me/');
    check('me/: the member corner opens, signed in', $x['code'] === 200 && str_contains($x['body'], '"signed":true'), sumOf($x));
    $x = http('boss', '/me/form.php');
    check('me/form.php: the own filled form', $x['code'] === 200 && str_contains($x['body'], $bossName), sumOf($x));
    $x = http('boss', '/me/print.php');
    check('me/print.php: the own ID card print page', $x['code'] === 200 && str_contains($x['body'], $bossName), sumOf($x));
    $x = http('boss', '/me/doc.php?list=1');
    $xj = json_decode($x['body'], true);
    check('me/doc.php?list=1: the own document list (JSON)', $x['code'] === 200 && is_array($xj) && ($xj['ok'] ?? false) === true && isset($xj['labels']), sumOf($x));
    $x = http('boss', '/team/');
    check('team/: the signed-in home', $x['code'] === 200 && str_contains($x['body'], 'नमस्ते'), sumOf($x));
    foreach (['/team/form.php', '/team/cv.php', '/team/card.php', '/team/agreement.php'] as $tu) {
        $x = http('boss', $tu);
        check("$tu: not sent to change-password.php", in_array($x['code'], [200, 302, 303], true) && !toChange($x), sumOf($x));
    }

    /* ---------------- C. same session, office sets must_change_pw mid-session ---------------- */
    echo "\n  C. same account after must_change_pw = 1 (row only; the session flag is still clean)\n";
    /* A NEW session first, so this part runs inside one 30 s re-check window of Auth::admin():
       the session flag stays clean and only the FRESH row read can refuse. Sign OUT before signing in:
       signIn() on a jar that is already signed in is a no-op (admin/login.php sends a signed-in visitor
       on before it reads the POST), so checked_at would stay from B and a slow B would turn every
       refusal below into a session-flag refusal without anyone noticing. */
    $x = http('boss', '/admin/logout.php');
    check('signed out first (logout.php -> login page)', in_array($x['code'], [302, 303], true) && str_contains($x['location'], 'login.php'), sumOf($x));
    $x = $signedIn('boss');
    check('  (really signed out: change-password.php bounces to the login page)', $x['code'] === 302 && str_contains($x['location'], 'login.php'), sumOf($x));
    clearBuckets();
    $okBefore = loginCount(BOSS);
    signIn('boss', BOSS, BOSS_PW);
    $cAt = microtime(true);
    check('a NEW session opened (one more successful sign-in for the account)', loginCount(BOSS) === $okBefore + 1, ($okBefore + 1) . ' expected, ' . loginCount(BOSS) . ' found');
    $x = http('boss', $U['pdf']);
    check('signed in again: still the staff PDF', isPdf($x), sumOf($x));
    Database::update('admins', ['must_change_pw' => 1], 'id = :i', ['i' => $bossId]);
    $x = http('boss', $U['png']);
    check('keyless ticket PNG: guest answer (302 to My Bookings), no image', $guestTicket($x), sumOf($x));
    $x = http('boss', $U['pdf']);
    check('keyless ticket PDF: guest answer (302 to My Bookings), no PDF', $guestTicket($x), sumOf($x));
    $x = http('boss', $U['dup']);
    check('keyless ?dup=1: guest answer, no reprint', $guestTicket($x), sumOf($x));
    $x = http('boss', $U['verify']);
    check('keyless verify page: the guest card ("Verification unavailable", no code, no name)', $verifiedGuest($x), 'HTTP ' . $x['code']);
    $x = http('boss', $U['chalan']);
    check('keyless chalan: 403, no image', $x['code'] === 403 && !str_starts_with($x['body'], "\x89PNG"), sumOf($x));
    $x = http('boss', $U['stmt']);
    check('keyless statement: 403, no PDF', $x['code'] === 403 && !str_starts_with($x['body'], '%PDF'), sumOf($x));
    $x = http('boss', $U['dupK']);
    check('with the customer key, ?dup=1 serves the NORMAL ticket, never the office reprint', isPng($x) && md5($x['body']) === md5($png['body']), sumOf($x));
    $x = http('boss', '/me/form.php');
    check('me/form.php: sent to change-password.php, no ID / PAN / bank numbers', toChange($x) && !str_contains($x['body'], $bossName), sumOf($x));
    $x = http('boss', '/me/doc.php?list=1');
    check('me/doc.php?list=1: 403, no document list', refusedTemp($x), sumOf($x));
    $x = http('boss', '/team/');
    check('team/: sent to change-password.php', toChange($x), sumOf($x));
    $cEl = microtime(true) - $cAt;
    check('every refusal above came inside ONE 30 s re-check window of the new session (so from the fresh row read, not the session flag)',
        $cEl < 28.0, sprintf('%.1f s after sign-in', $cEl));
    $x = $signedIn('boss');
    check('  (the session is still signed in all along: change-password.php opens)', $x['code'] === 200, sumOf($x));
    Database::update('admins', ['must_change_pw' => 0], 'id = :i', ['i' => $bossId]);
    $x = http('boss', $U['pdf']);
    if (!isPdf($x)) {
        /* Only if this part outlived Auth::admin()'s 30 s window: the re-check copied the
           flag into the session, and the next re-check (<= 30 s) drops it again. */
        sleep(31);
        $x = http('boss', $U['pdf']);
    }
    check('flag cleared by the office: the same session gets the staff PDF again', isPdf($x), sumOf($x));

    /* ---------------- D. a session that signed in on a temporary password ---------------- */
    echo "\n  D. signed in on a temporary password\n";
    clearBuckets();
    $r = signIn('temp', TEMP, TEMP_PW);
    check('the temporary password signs in', in_array($r['code'], [200, 302], true), sumOf($r));
    $x = http('temp', '/admin/index.php');
    check('admin/ sends it to change-password.php (requireAdmin)', $x['code'] === 302 && str_contains($x['location'], 'change-password.php'), sumOf($x));
    foreach (['png' => 'keyless ticket PNG', 'pdf' => 'keyless ticket PDF', 'dup' => 'keyless ?dup=1'] as $k => $label) {
        $x = http('temp', $U[$k]);
        check($label . ': guest answer (302 to My Bookings)', $guestTicket($x), sumOf($x));
    }
    $x = http('temp', $U['verify']);
    check('keyless verify page: the guest card', $verifiedGuest($x), 'HTTP ' . $x['code']);
    $x = http('temp', $U['chalan']);
    check('keyless chalan: 403', $x['code'] === 403 && !str_starts_with($x['body'], "\x89PNG"), sumOf($x));
    $x = http('temp', $U['stmt']);
    check('keyless statement: 403', $x['code'] === 403 && !str_starts_with($x['body'], '%PDF'), sumOf($x));
    // the member's own pages: guest until the password is changed
    $tempName = 'U12 G1 ' . TEMP;
    foreach (['/me/' => 'me/ (member corner)', '/me/form.php' => 'me/form.php (full ID / PAN / bank numbers)',
              '/me/print.php' => 'me/print.php (company ID card)'] as $mu => $label) {
        $x = http('temp', $mu);
        check("$label: sent to change-password.php, no member data", toChange($x) && !str_contains($x['body'], $tempName), sumOf($x));
    }
    $x = http('temp', '/me/doc.php?list=1');
    check('me/doc.php?list=1: 403, no document list', refusedTemp($x), sumOf($x));
    $x = http('temp', '/me/doc.php?k=kyc1');
    check('me/doc.php?k=kyc1: 403, no KYC image', refusedTemp($x), sumOf($x));
    // an upload with EVERYTHING right (the page's own CSRF token, a real image, a label) still stops at the gate
    $pngFile = sys_get_temp_dir() . '/shg_u12g1_probe_' . getmypid() . '.png';
    file_put_contents($pngFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $tok = csrfFrom(http('temp', '/admin/change-password.php')['body']);
    $x = http('temp', '/me/doc.php', ['multipart' => [CSRF_TOKEN_NAME => $tok, 'label' => 'U12 G1 probe',
        'file' => new CURLFile($pngFile, 'image/png', 'probe.png')], 'headers' => ['X-CSRF-Token: ' . $tok]]);
    @unlink($pngFile);
    check('POST me/doc.php with a valid CSRF token + image: 403 before any upload', $tok !== '' && refusedTemp($x), sumOf($x));
    check('  ...nothing reached the KYC record: no ap_applications row, no member row made for it',
        (int) Database::scalar('SELECT COUNT(*) FROM ap_applications WHERE admin_id = :a', ['a' => $tempId], 0) === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM ap_members WHERE admin_id = :a', ['a' => $tempId], 0) === 0);
    foreach (['/team/', '/team/form.php', '/team/cv.php', '/team/card.php', '/team/agreement.php'] as $tu) {
        $x = http('temp', $tu);
        check("$tu: sent to change-password.php", toChange($x) && !str_contains($x['body'], $tempName), sumOf($x));
    }
    $x = $signedIn('temp');
    check('  (still signed in: change-password.php opens for it)', $x['code'] === 200, sumOf($x));
    $x = $signedIn('guest');
    check('  (a guest is bounced from change-password.php - so 200 above means signed in)', $x['code'] === 302 && str_contains($x['location'], 'login.php'), sumOf($x));

    /* ---------------- E. customer paths untouched ---------------- */
    echo "\n  E. signed-key / customer paths (guest, and the temporary-password session)\n";
    clearBuckets();
    foreach (['guest' => 'guest (WhatsApp / Meta crawler)', 'temp' => 'temporary-password session'] as $j => $who) {
        $x = http($j, $U['pngK']);
        check("$who: signed-key ticket PNG 200 image/png", isPng($x) && $x['type'] === 'image/png', sumOf($x));
        $x = http($j, $U['pdfK']);
        check("$who: signed-key ticket PDF 200 application/pdf", isPdf($x) && $x['type'] === 'application/pdf', sumOf($x));
        $x = http($j, $U['verifyK']);
        check("$who: keyed verify page reads Valid", $x['code'] === 200 && str_contains($x['body'], 'Valid — मान्य'), 'HTTP ' . $x['code']);
        $x = http($j, $U['chalanK']);
        check("$who: signed chalan link 200", isPng($x), sumOf($x));
        $x = http($j, $U['stmtK']);
        check("$who: signed statement link 200", isPdf($x), sumOf($x));
    }
    $x = http('guest', $U['dupK']);
    check('guest: ?dup=1 with the key is the normal ticket, never the office reprint', isPng($x) && md5($x['body']) === md5($png['body']), sumOf($x));
    $x = http('guest', $U['pdf']);
    check('guest without a key: still the 302 to My Bookings (unchanged)', $guestTicket($x), sumOf($x));
    $x = http('guest', '/me/doc.php?list=1');
    check('guest on me/doc.php: still 401 "Please sign in" (unchanged)', $x['code'] === 401, sumOf($x));
    $x = http('guest', '/me/form.php');
    check('guest on me/form.php: still sent to /me/ (unchanged)', $x['code'] === 302 && str_ends_with($x['location'], '/me/'), sumOf($x));

    /* ---------------- F. payment/check_payment_status.php ---------------- */
    echo "\n  F. payment/check_payment_status.php (staff-only bank status)\n";
    $src = (string) file_get_contents(ROOT_PATH . '/payment/check_payment_status.php');
    check('the gate asks Auth::staffActive()', str_contains($src, 'if (Auth::staffActive() === null) {'));
    if (is_file(ROOT_PATH . '/payment/icici_config.php')) {
        $bad = '/payment/check_payment_status.php?txn_no=%21%21';   // invalid: refused with 400 BEFORE any bank call
        $x = http('guest', $bad);
        check('guest: 401', $x['code'] === 401, sumOf($x));
        $x = http('temp', $bad);
        check('temporary-password session: 403 (change it first)', $x['code'] === 403 && str_contains($x['body'], 'temporary password'), sumOf($x));
        $x = http('boss', $bad);
        check('clean staff session passes the gate (400 for the bad txn_no, no bank call)', $x['code'] === 400, sumOf($x));
    } else {
        echo "  SKIP  HTTP part: payment/icici_config.php is not on this machine (the page cannot load without it)\n";
    }

    /* every staff branch on these pages goes through the helper */
    foreach (['download-ticket.php' => 2, 'verify-ticket.php' => 1, 'download-chalan.php' => 1, 'download-statement.php' => 1] as $f => $n) {
        $code = (string) file_get_contents(ROOT_PATH . '/' . $f);
        check("$f: $n staff branch(es) on Auth::staffActive()", substr_count($code, 'Auth::staffActive() !== null') === $n);
    }
    /* ...and every member page on the admin session asks it too (the ones B-D reach over HTTP, read here so
       a page added later without the gate, or a gate moved below the data, shows up as well) */
    foreach (['me/index.php' => 'Response::redirect(\'admin/change-password.php\')', 'me/form.php' => 'Response::redirect(\'admin/change-password.php\')',
              'me/print.php' => 'Response::redirect(\'admin/change-password.php\')', 'me/doc.php' => 'Response::forbidden(',
              'team/index.php' => 'TeamUi::go(\'/admin/change-password.php\')', 'team/form.php' => 'TeamUi::go(\'/admin/change-password.php\')',
              'team/cv.php' => 'TeamUi::go(\'/admin/change-password.php\')', 'team/card.php' => 'TeamUi::go(\'/admin/change-password.php\')',
              'team/agreement.php' => 'TeamUi::go(\'/admin/change-password.php\')'] as $f => $then) {
        $code = (string) file_get_contents(ROOT_PATH . '/' . $f);
        $gate = strpos($code, 'Auth::staffActive() === null');
        check("$f: gated on Auth::staffActive() (" . $then . ')', $gate !== false && strpos($code, $then, $gate) !== false);
    }
    $meDir = array_map('basename', glob(ROOT_PATH . '/me/*.php') ?: []);
    sort($meDir);
    check('me/: no page beyond the four gated ones and the signed-link doc-share.php',
        $meDir === ['doc-share.php', 'doc.php', 'form.php', 'index.php', 'print.php'], implode(', ', $meDir));
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

unset($_SESSION[ADMIN_SESSION_KEY]);
$locksMade = $made['pnr'] !== '' ? count(glob(TICKET_PATH . '/ticket_' . $made['pnr'] . '.*.lock') ?: []) : 0;
cleanup();
foreach ($JARS as $j) { @unlink($j); }

echo "\n  cleanup\n";
$ids = array_values(array_filter(array_map('intval', $made['admins'])));
if ($ids !== []) {
    $in = implode(',', $ids);   // ints only
    check('no test account left', (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE id IN ($in)", [], 0) === 0);
    check('no admin_sessions row left', (int) Database::scalar("SELECT COUNT(*) FROM admin_sessions WHERE admin_id IN ($in)", [], 0) === 0);
    check('no ap_members / ap_applications row left', (int) Database::scalar("SELECT COUNT(*) FROM ap_members WHERE admin_id IN ($in)", [], 0) === 0
        && (int) Database::scalar("SELECT COUNT(*) FROM ap_applications WHERE admin_id IN ($in)", [], 0) === 0);
}
[$aw, $ap] = auditMine();
check('no audit_logs row of this run left', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :from AND $aw", $ap, 0) === 0);
check('no admin_login_events row left', (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username IN (:a, :b)', ['a' => BOSS, 'b' => TEMP], 0) === 0);
check('no test booking left', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => PHONE], 0) === 0);
check('no schedule left on the test date', (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date = :d', ['d' => TD], 0) === 0);
check('no ticket file (nor its render .lock) / statement / chalan file left',
    ($made['pnr'] === '' || (glob(TICKET_PATH . '/ticket_' . $made['pnr'] . '.*') ?: []) === [])
    && ($made['stmt'] === '' || !is_file(Statement::dir() . '/' . $made['stmt']))
    && !is_dir(rtrim(UPLOAD_PATH, '/\\') . '/challan/' . TD), $locksMade . ' render lock(s) made and removed');

echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
