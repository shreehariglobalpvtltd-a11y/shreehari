<?php
/**
 * =====================================================================
 *  mp1-chalani-test.php — release ch: the uniform chalani (25 Nov 2026).
 *
 *  On a seeded far-future departure (ZZTEST mp1ch bookings):
 *    A pay at boarding, 2 pax, due        E cash sale with ₹200 desk extras + discount
 *    B pay at boarding, cash collected    F cash sale held by an AGENT (with agent)
 *    C UPI verified (online)              G online pending, office ticked PAID (other)
 *    D UPI pending (online, office checking)
 *    H cash verified, office "Mark due" (the double-counting trap)
 *
 *  1. ChalaniStrip: amount = cash + online + due + other, per row and whole bus;
 *     the strip totals equal the sum of the rows; cash / online equal
 *     departureMoney()'s columns less what the office marked due (no second
 *     money rule); due = pay at boarding still due + online pending + the
 *     office-marked-due row H (red badge, never green); every row is Paid XOR
 *     Due; the Due box = the Due-badge rows, cash + online + other = the
 *     Paid-badge rows; "other" (G) is shown, so the visible boxes add to Total.
 *  2. livePayload (the 30 s poll): strip = fromDm, D flips to PAID when its
 *     payment is verified, fp moves, key stays; a new passenger moves key.
 *  3. chalani.export defaults: superadmin, counter, manager, operations yes;
 *     agent, support, accountant, official, viewer, scanner no; a per-person
 *     grant opens it; chalani_export_perm_on = 0 falls back to bookings.view.
 *  4. Over HTTP (own php -S on a free port, this worktree): ?format=json needs
 *     a login, is scoped for an agent; PNG / PDF / share refused without
 *     chalani.export, allowed for counter; the screen carries the strip, the
 *     tel: link and the counter block (not for an agent). Review 25 Nov:
 *     download-chalan.php without a key, the Bus Chalan hub's chalani card and
 *     its "Chalani page N" WhatsApp send also need chalani.export.
 *  5. A sample PNG page is rendered and copied to MP1_CH_OUT (default: the
 *     session scratchpad chalani/ folder) to look at.
 *
 *    php -c .claude/php-dev.ini tests/mp1-chalani-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';
require_once INCLUDE_PATH . '/chalanistrip.php';
require_once INCLUDE_PATH . '/quickunlock.php';

const PHONES = ['A' => '9000025101', 'B' => '9000025102', 'C' => '9000025103', 'D' => '9000025104',
                'E' => '9000025105', 'F' => '9000025106', 'G' => '9000025107', 'H' => '9000025108', 'N' => '9000025109'];
const USERS  = ['boss' => 'superadmin', 'ctr' => 'counter', 'agt' => 'agent', 'sup' => 'support', 'supx' => 'support'];
const PW     = 'Mp1Chalani!2026x';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l, string $why): void { echo "  SKIP  $l — $why\n"; }
function uname(string $k): string { return 'mp1ch_' . $k; }

function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
            foreach (['booking_collections', 'agent_ledger', 'pay_events'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    foreach (array_keys(USERS) as $k) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => uname($k)], 0);
        if ($id > 0) {
            foreach (['admin_profiles' => 'admin_id', 'admin_quick_unlock' => 'admin_id', 'admin_sessions' => 'admin_id'] as $t => $c) {
                try { Database::run("DELETE FROM $t WHERE $c = :i", ['i' => $id]); } catch (Throwable $e) {}
            }
            try { Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
            try { Database::delete('admins', 'id = :i', ['i' => $id]); } catch (Throwable $e) {
                Database::update('admins', ['is_active' => 0, 'username' => uname($k) . '_x' . $id], 'id = :i', ['i' => $id]);
            }
        }
    }
}
function asUser(array $u): void {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'role' => (string) $u['role'],
        'permissions' => $u['perms'] ?? [], 'last_seen' => time(), 'logged_in_at' => time()];
}

/* ---- HTTP helpers (own built-in server on this worktree) ---- */
$BASE = '';
$JARS = [];
function http(string $who, string $url, array $opt = []): array {
    global $BASE, $JARS;
    $jar = $JARS[$who] ??= sys_get_temp_dir() . '/shg_mp1ch_' . $who . '_' . getmypid() . '.txt';
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($opt['json']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . (string) ($opt['csrf'] ?? '')]);
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function login(string $who, string $user): bool {
    try { Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket IN ('code_login_enter','quick_unlock','code_login_try')"); } catch (Throwable $e) {}
    $login = http($who, '/admin/login.php?classic=1&other=1');
    $r = http($who, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => $user, 'password' => PW]]);
    if ($r['code'] !== 302) {
        $w = http($who, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($r['body'] ?: $login['body']), 'classic' => '1', 'mode' => 'who', 'who' => $user, 'cc' => '91']]);
        $r = http($who, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($w['body']), 'classic' => '1', 'mode' => 'pw', 'password' => PW]]);
    }
    return $r['code'] === 302 && !str_contains($r['headers'], 'login.php');
}
function startServer(string $root): array {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    if ($s === false) { return [null, 0]; }
    $port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
    fclose($s);
    $ini = $root . '/.claude/php-dev.ini';
    $cmd = [PHP_BINARY, '-c', $ini, '-S', '127.0.0.1:' . $port, '-t', $root];
    $log = sys_get_temp_dir() . '/shg_mp1ch_srv_' . getmypid() . '.log';
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
    if (!is_resource($proc)) { return [null, 0]; }
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); return [$proc, $port]; }
    }
    proc_terminate($proc);
    return [null, 0];
}

echo "\n=== mp1 · release ch: uniform chalani (strip, live poll, chalani.export, counter money) ===\n\n";
$keepSet = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod', 'email_enabled', 'chalani_export_perm_on', 'chalani_money_strip_on', 'chalani_live_poll_on', 'chalani_counter_totals_on', 'role_permissions_override', 'wa_admin_tools_enabled'] as $k) {
    $keepSet[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($keepSet): void {
    foreach ($keepSet as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); continue; }
        Database::run('INSERT INTO settings (skey, svalue, stype, sgroup) VALUES (:k, :v, :t, :g)
                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), stype = VALUES(stype), sgroup = VALUES(sgroup)',
            ['k' => $k, 'v' => $row['svalue'], 't' => $row['stype'], 'g' => $row['sgroup']]);
    }
    Settings::flush();
};
cleanup();
$TD = '';
for ($i = 75; $i >= 61; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$sched = null; $schedMade = false; $proc = null; $sid = 0;
$OUT = rtrim((string) (getenv('MP1_CH_OUT') ?: 'C:/Users/hp/AppData/Local/Temp/claude/8353c69271db1ac8/1bdafb45-beb9-4d8a-b106-49a39a100e11/scratchpad/chalani'), '/\\');
try {
    if ($TD === '') { throw new RuntimeException('no free date 61-75 days out'); }
    foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'allow_cod' => '1', 'email_enabled' => '0', 'wa_admin_tools_enabled' => '1'] as $k => $v) {
        Settings::set($k, $v, 'bool', 'notify', false);
    }
    foreach (['chalani_export_perm_on', 'chalani_money_strip_on', 'chalani_live_poll_on', 'chalani_counter_totals_on'] as $k) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);   // the code defaults (all on)
    }
    Database::delete('settings', 'skey = :k', ['k' => 'role_permissions_override']);
    Settings::flush();

    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $schedMade = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]) === null;
    $sched   = Seats::schedule($routeId, $TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $sid = (int) $sched['id'];

    $U = [];
    foreach (USERS as $k => $role) {
        $id = (int) Database::insert('admins', ['username' => uname($k), 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
            'full_name' => 'ZZTEST mp1ch ' . $k, 'role' => $role, 'permissions' => json_encode($k === 'supx' ? ['chalani.export'] : []),
            'is_active' => 1, 'must_change_pw' => 0]);
        try { QuickUnlock::set($id, 'pin', '583942'); } catch (Throwable $e) {}
        $U[$k] = ['id' => $id, 'username' => uname($k), 'role' => $role, 'perms' => $k === 'supx' ? ['chalani.export'] : []];
    }
    $bossId = $U['boss']['id'];
    asUser($U['boss']);

    $sell = static function (string $k, array $seats, string $method) use ($routeId, $TD, $bossId): array {
        $pax = [];
        foreach ($seats as $n => $s) { $pax[] = ['name' => 'ZZTEST mp1ch ' . $k . ($n > 0 ? ' ' . ($n + 1) : ''), 'age' => 30 + $n, 'gender' => 'Male']; }
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => $seats, 'passengers' => $pax,
            'contact' => ['phone' => PHONES[$k]], 'bookingMode' => 'sharing', 'paymentMethod' => $method, 'isCod' => false, 'boarding' => ''],
            ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => $method]);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        return $row;
    };
    $asCod = static function (array $b): void {
        Database::update('bookings', ['is_cod' => 1, 'source' => 'web', 'sold_by_admin_id' => null, 'status' => 'confirmed'], 'id = :i', ['i' => (int) $b['id']]);
        Database::update('payments', ['status' => 'cod_pending', 'method' => 'cod', 'mode' => 'offline', 'verified_by' => null, 'verified_at' => null], 'booking_id = :b', ['b' => (int) $b['id']]);
    };
    $asOnline = static function (array $b, string $status): void {
        Database::update('bookings', ['source' => 'web', 'sold_by_admin_id' => null, 'status' => $status === 'verified' ? 'confirmed' : 'pending'], 'id = :i', ['i' => (int) $b['id']]);
        Database::update('payments', ['method' => 'upi', 'status' => $status] + ($status === 'verified' ? [] : ['verified_by' => null, 'verified_at' => null]), 'booking_id = :b', ['b' => (int) $b['id']]);
    };
    $A = $sell('A', ['L20', 'L22'], 'cash'); $asCod($A);
    $B = $sell('B', ['L24'], 'cash'); $asCod($B);
    BookingService::settleCod((int) $B['id'], $bossId, 'mp1ch test: cash collected');
    $C = $sell('C', ['L26'], 'upi'); $asOnline($C, 'verified');
    $D = $sell('D', ['L28'], 'upi'); $asOnline($D, 'pending');
    $E = $sell('E', ['L30', 'L32'], 'cash');
    $eTot = (float) $E['total_amount'] + 200;
    Database::update('bookings', ['luggage_pieces' => 1, 'luggage_fee' => 200, 'total_amount' => $eTot, 'base_total' => (float) $E['total_amount'] + 300], 'id = :i', ['i' => (int) $E['id']]);
    Database::update('payments', ['amount' => $eTot, 'method' => 'cash', 'status' => 'verified'], 'booking_id = :b', ['b' => (int) $E['id']]);
    $F = $sell('F', ['L34'], 'cash');
    Database::update('bookings', ['sold_by_admin_id' => $U['agt']['id']], 'id = :i', ['i' => (int) $F['id']]);
    $G = $sell('G', ['L36'], 'upi'); $asOnline($G, 'pending');
    OpsHub::ensureCollections();
    Database::query("INSERT INTO booking_collections (booking_id, status, mode, amount, note, agent_admin_id, ledger_id, marked_by, marked_at)
                     VALUES (:b, 'paid', 'Cash', :a, 'mp1ch test tick', NULL, NULL, :by, NOW())", ['b' => (int) $G['id'], 'a' => (float) $G['total_amount'], 'by' => $bossId]);
    $H = $sell('H', ['L18'], 'cash');
    OpsHub::markDue((int) $H['id'], ['id' => $bossId]);
    echo "  (date $TD · sid $sid)\n";

    /* ---- 1. the strip ---- */
    $dm = ChalaniPng::departureMoney($sid);
    $st = ChalaniStrip::fromDm($dm);
    $t  = $dm['totals'];
    check('10 passengers on the seed (A2 B C D E2 F G H)', $st['passengers'] === 10 && $t['passengers'] === 10, (string) $st['passengers']);
    $sumRows = ['amount' => 0.0, 'cash' => 0.0, 'online' => 0.0, 'due' => 0.0, 'other' => 0.0];
    $rowsClose = true; $xor = true;
    foreach ($st['rows'] as $line) {
        foreach ($sumRows as $k => $_) { $sumRows[$k] += $line[$k]; }
        if (abs($line['amount'] - ($line['cash'] + $line['online'] + $line['due'] + $line['other'])) > 0.001) { $rowsClose = false; }
        if ($line['paid'] === ($line['due'] > 0)) { $xor = false; }
    }
    check('every row: amount = cash + online + due + other', $rowsClose);
    check('every row is Paid XOR Due', $xor);
    $diff = [];
    foreach ($sumRows as $k => $v) { if (abs($v - $st[$k]) > 0.01) { $diff[] = "$k strip " . $st[$k] . " rows $v"; } }
    check('strip totals = the sum of the rows', $diff === [], implode('; ', $diff));
    check('whole bus: amount = cash + online + due + other', abs($st['amount'] - ($st['cash'] + $st['online'] + $st['due'] + $st['other'])) < 0.01,
        json_encode(array_diff_key($st, ['rows' => 1])));
    check('strip cash / online = departureMoney columns less the office-marked-due row (no second rule)',
        abs($st['cash'] + $st['markedCash'] - $t['cash']) < 0.01 && abs($st['online'] + $st['markedOnline'] - $t['online']) < 0.01,
        json_encode([$st['cash'], $st['markedCash'], $t['cash'], $st['online'], $st['markedOnline'], $t['online']]));
    check('strip amount = ticket value + extras', abs($st['amount'] - ($t['ticket'] + $t['extras'])) < 0.01, json_encode([$st['amount'], $t['ticket'], $t['extras']]));
    $farePer = $dm['farePer'];
    $amtOf = static function (array $b) use ($dm, $farePer): float {
        $s = 0.0;
        foreach ($dm['rows'] as $r) { if ((int) $r['booking_id'] === (int) $b['id']) { $s += $farePer($r) + $dm['extraOnRow']($r); } }
        return $s;
    };
    $hRow = null;
    foreach ($dm['rows'] as $r) { if ((int) $r['booking_id'] === (int) $H['id']) { $hRow = $r; } }
    $hLine = $hRow !== null ? $st['rows'][(int) $hRow['pax_id']] : null;
    check('H (cash verified, office Mark due): counted once, in due (out of नगद), Due not Paid', $hLine !== null && $hLine['mode'] === 'due'
        && $hLine['cash'] < 0.01 && abs($hLine['due'] - $hLine['amount']) < 0.01 && abs($hLine['markedCash'] - $hLine['amount']) < 0.01
        && $hLine['amount'] > 0 && $hLine['paid'] === false, json_encode($hLine));
    $hBd = $hLine !== null ? ChalaniStrip::badge($hLine['mode'], $hLine['paid'], $hLine['markedCash'] + $hLine['markedOnline'] > 0) : [];
    check('H badge: red Due · Office marked due (never the green Paid)', ($hBd['cls'] ?? '') === 'due' && ($hBd['en'] ?? '') === 'Due · Office marked due'
        && str_contains((string) ($hBd['ne'] ?? ''), 'बाँकी'), json_encode($hBd, JSON_UNESCAPED_UNICODE));
    $hCol = null;
    foreach ($dm['rows'] as $r) { if ((int) $r['booking_id'] === (int) $H['id']) { $hCol = $dm['money']($r); } }
    check('H: the PNG column still holds its fare (DUE chip there), so strip and PNG agree it is due', $hCol !== null && $hCol['cash'] > 0
        && ($dm['pmBook'][(int) $H['id']] ?? '') === 'due');
    $expDue = $t['boardDue'] + $amtOf($D) + $amtOf($H);
    check('due = pay at boarding still due (A) + online pending (D) + office-marked due (H)', abs($st['due'] - $expDue) < 0.01, json_encode([$st['due'], $expDue]));
    check('dueN = A 2 + D 1 + H 1 passengers', $st['dueN'] === 4, (string) $st['dueN']);
    $dueRows = 0.0; $paidRows = 0.0;
    foreach ($st['rows'] as $line) { if ($line['paid']) { $paidRows += $line['amount']; } else { $dueRows += $line['amount']; } }
    check('Due box = the rows with a Due badge', abs($dueRows - $st['due']) < 0.01, json_encode([$dueRows, $st['due']]));
    check('cash + online + other = the rows with a Paid badge', abs($paidRows - ($st['cash'] + $st['online'] + $st['other'])) < 0.01,
        json_encode([$paidRows, $st['cash'], $st['online'], $st['other']]));
    check('other (G) is shown, so the VISIBLE boxes add up to the Total', ChalaniStrip::hasOther($st)
        && abs(($st['cash'] + $st['online'] + $st['due'] + $st['other']) - $st['amount']) < 0.01 && isset(ChalaniStrip::labels()['other']));
    check('no other: the fifth box stays hidden', !ChalaniStrip::hasOther(['other' => 0.0]));
    check('other = G (office tick, no verified payment)', abs($st['other'] - $amtOf($G)) < 0.01, json_encode([$st['other'], $amtOf($G)]));
    $fLine = null;
    foreach ($dm['rows'] as $r) { if ((int) $r['booking_id'] === (int) $F['id']) { $fLine = $st['rows'][(int) $r['pax_id']]; } }
    check('F (agent cash): Paid · With agent, in नगद', $fLine !== null && $fLine['mode'] === 'agent' && $fLine['paid'] && $fLine['cash'] > 0
        && ChalaniStrip::badge('agent', true)['en'] === 'Paid · With agent');
    check('badges: Paid green class, Due red class, Cash / Online words', ChalaniStrip::badge('cash', true)['cls'] === 'paid'
        && ChalaniStrip::badge('online', true)['en'] === 'Paid · Online' && ChalaniStrip::badge('boarding', false)['cls'] === 'due'
        && ChalaniStrip::badge('cash', true)['en'] === 'Paid · Cash');
    check('labels: Nepali · English', ChalaniStrip::labels()['due'] === 'ड्यू बाँकी · Due' && ChalaniStrip::labels()['pax'] === 'जम्मा यात्री · Total');
    check('row(): columns above the fare still close the sum', (static function (): bool {
        $l = ChalaniStrip::row(['ticket' => 100, 'extra' => 0, 'cash' => 120, 'online' => 0], 'cash');
        return abs($l['amount'] - ($l['cash'] + $l['online'] + $l['due'] + $l['other'])) < 0.001 && $l['due'] === 0.0;
    })());
    check('empty bus: zero strip', ChalaniStrip::fromDm(ChalaniPng::departureMoney(0))['passengers'] === 0);

    /* ---- 2. live payload ---- */
    $pmStaff = static fn(array $pmk, bool $withAmt = true): string => ChalaniPng::payModeLabel((string) $pmk['mode'], 'staff');
    $lp1 = ChalaniStrip::livePayload($dm, $pmStaff);
    check('live: strip = fromDm', abs($lp1['strip']['due'] - $st['due']) < 0.01 && $lp1['strip']['pax'] === 10 && count($lp1['rows']) === 10);
    check('live: one group per booking (8)', count($lp1['groups']) === 8, (string) count($lp1['groups']));
    $hLive = null;
    foreach ($lp1['rows'] as $r) { if ($r['b'] === (int) $H['id']) { $hLive = $r; } }
    check('live: H polls as Due (red) · Office marked due', $hLive !== null && $hLive['pd'] === 'due' && str_contains($hLive['pdTxt'], 'Office marked due'), json_encode($hLive, JSON_UNESCAPED_UNICODE));
    check('live: the other figure travels with the strip', !empty($lp1['strip']['hasOther']) && is_string($lp1['strip']['txt']['other'] ?? null)
        && abs($lp1['strip']['other'] - $st['other']) < 0.01);
    $dPax = 0;
    foreach ($lp1['rows'] as $r) { if ($r['b'] === (int) $D['id']) { $dPax = $r['p']; check('live: D starts Due (red)', $r['pd'] === 'due'); } }
    Database::update('payments', ['status' => 'verified', 'verified_by' => $bossId, 'verified_at' => date('Y-m-d H:i:s')], 'booking_id = :b', ['b' => (int) $D['id']]);
    Database::update('bookings', ['status' => 'confirmed'], 'id = :i', ['i' => (int) $D['id']]);
    $lp2 = ChalaniStrip::livePayload(ChalaniPng::departureMoney($sid), $pmStaff);
    $dNow = null;
    foreach ($lp2['rows'] as $r) { if ($r['p'] === $dPax) { $dNow = $r; } }
    check('live: an online payment verified → D flips to PAID · Online', $dNow !== null && $dNow['pd'] === 'paid' && $dNow['pm'] === 'online', json_encode($dNow));
    check('live: fp moves, key (passenger set) stays', $lp2['fp'] !== $lp1['fp'] && $lp2['key'] === $lp1['key']);
    check('live: strip due falls by D, online rises by D', abs(($lp1['strip']['due'] - $lp2['strip']['due']) - $amtOf($D)) < 0.01
        && abs(($lp2['strip']['online'] - $lp1['strip']['online']) - $amtOf($D)) < 0.01);
    $lp3 = ChalaniStrip::livePayload(ChalaniPng::departureMoney($sid), $pmStaff);
    check('live: nothing changed → same fp', $lp3['fp'] === $lp2['fp']);
    $N = $sell('N', ['L16'], 'cash');
    $lp4 = ChalaniStrip::livePayload(ChalaniPng::departureMoney($sid), $pmStaff);
    check('live: a new passenger moves key (page offers a reload)', $lp4['key'] !== $lp2['key'] && $lp4['strip']['pax'] === 11);
    $lpA = ChalaniStrip::livePayload(ChalaniPng::departureMoney($sid, $U['agt']['id']), $pmStaff);
    check('live: an agent scope sees only its own rows (F)', count($lpA['rows']) === 1 && $lpA['rows'][0]['b'] === (int) $F['id']);

    /* ---- 3. chalani.export defaults ---- */
    foreach (['counter', 'manager', 'operations'] as $role) {
        check("chalani.export: $role yes by default", in_array('chalani.export', Auth::rolePermissions($role), true));
    }
    foreach (['agent', 'support', 'accountant', 'official', 'viewer', 'scanner'] as $role) {
        check("chalani.export: $role not by default", !in_array('chalani.export', Auth::rolePermissions($role), true));
    }
    check('chalani.export is in the permission catalog (matrix / per-person grant)', isset(Auth::permissionList()['chalani.export']));
    asUser($U['boss']); check('superadmin may export', ChalaniStrip::mayExport());
    asUser($U['ctr']);  check('counter may export', ChalaniStrip::mayExport());
    asUser($U['agt']);  check('agent may not export (agent copy is agent_chalani_on, apart)', !ChalaniStrip::mayExport());
    asUser($U['sup']);  check('support (staff, no grant) may not export', !ChalaniStrip::mayExport());
    asUser($U['supx']); check('support with a per-person chalani.export grant may export', ChalaniStrip::mayExport());
    Settings::set('chalani_export_perm_on', '0', 'bool', 'booking', false); Settings::flush();
    asUser($U['sup']);  check('switch off → bookings.view is enough again (support)', ChalaniStrip::mayExport());
    Database::delete('settings', 'skey = :k', ['k' => 'chalani_export_perm_on']); Settings::flush();
    Settings::set('role_permissions_override', json_encode(['counter' => ['chalani.export' => false]]), 'json', 'security', false); Settings::flush();
    asUser($U['ctr']);  check('the matrix can take chalani.export off counter', !ChalaniStrip::mayExport());
    Database::delete('settings', 'skey = :k', ['k' => 'role_permissions_override']); Settings::flush();
    asUser($U['agt']);  check('counter block hidden for an agent', ChalaniStrip::counterView() === null);
    asUser($U['sup']);  check('counter block hidden for staff (support)', ChalaniStrip::counterView() === null);
    asUser($U['ctr']);  check('counter block: counter sees its desk', ChalaniStrip::counterView() === 'desk');
    asUser($U['boss']); check('counter block: office sees all', ChalaniStrip::counterView() === 'all');
    $ctAll = ChalaniStrip::counterToday('all', $bossId);
    $bossLine = null;
    foreach ($ctAll['people'] as $p) { if ((int) $p['who'] === $bossId) { $bossLine = $p; } }
    check('counter block: per person who cut tickets today (boss cut the seed)', $bossLine !== null && $bossLine['tickets'] >= 1
        && abs($bossLine['amount'] - ($bossLine['cash'] + $bossLine['online'] + $bossLine['due'])) < 0.01, json_encode($bossLine));
    $ctSum = 0.0;
    foreach ($ctAll['lines'] as $l) { $ctSum += $l['cash']; }
    check('counter block: totals = sum of its lines', abs($ctSum - (float) $ctAll['totals']['cash']) < 0.01);
    $ctMe = ChalaniStrip::counterToday('desk', $U['ctr']['id']);
    check('counter block: a counter with no desk sees only its own line', array_filter($ctMe['lines'], static fn(array $l): bool => $l['key'] !== 'AG:' . $U['ctr']['id']) === []
        && array_filter($ctMe['people'], static fn(array $p): bool => (int) $p['who'] !== $U['ctr']['id']) === []);
    Settings::set('chalani_counter_totals_on', '0', 'bool', 'booking', false); Settings::flush();
    check('chalani_counter_totals_on = 0 hides the block', ChalaniStrip::counterView() === null);
    Database::delete('settings', 'skey = :k', ['k' => 'chalani_counter_totals_on']); Settings::flush();
    asUser($U['boss']);

    /* share links: the same key download-chalan.php checks */
    $links = ChalaniStrip::shareLinks($sid, 2, 2);
    $ok = count($links) === 2;
    foreach ($links as $p => $u) {
        parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
        $ok = $ok && hash_equals(substr(Security::sign('chalan-dl|' . $sid . '|chalani|' . $p . '|' . (int) $q['exp']), 0, 20), (string) $q['k'])
            && (int) $q['exp'] > time() + 86400;
    }
    check('share: one signed, expiring download-chalan link per page', $ok);
    $txt = ChalaniStrip::shareText(['from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'dep_time' => '13:00:00', 'bus_number' => 'GJ05'], $TD, 'CH-X', $st, $links);
    check('share text: Nepali labels, Roman values, the links', str_contains($txt, 'जम्मा यात्री 10') && str_contains($txt, 'बाँकी ₹') && str_contains($txt, $links[2]));
    check('share text: carries the other figure too', str_contains($txt, 'अन्य भुक्तानी ₹'));

    /* ---- 5. sample PNG (direct render, the manifest's own closures) ---- */
    $dmP = ChalaniPng::departureMoney($sid);
    $trip = Database::fetch("SELECT s.id AS schedule_id, s.travel_date, s.slot, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time, COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
            bu.bus_number, bu.bus_name, '' AS driver_name, '' AS driver_phone
       FROM schedules s JOIN routes r ON r.id = s.route_id LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id) WHERE s.id = :s", ['s' => $sid]);
    $pmB = $dmP['pmBook'];
    $fmt = ['money' => $dmP['money'], 'stopShort' => static fn(string $s): string => trim((string) preg_replace('/\s*\[[^\]]*\]\s*|\s*@.*$/u', '', $s)),
        'isLate' => static fn(array $r): bool => false, 'bookedBy' => static fn(array $r): string => !empty($r['sold_by_admin_id']) ? Ticket::issuedBy($r)['code'] : 'ONLINE',
        'stopsLine' => '', 'stopsLineRoman' => '', 'placeList' => [], 'busName' => trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? '')),
        'rowState' => static fn(array $r): string => (string) ($dmP['state'][(int) $r['booking_id']] ?? 'due'),
        'payMark' => static fn(array $r): array => ['mode' => (string) ($pmB[(int) $r['booking_id']] ?? 'due'), 'amt' => 0.0, 'first' => false, 'ne' => '', 'claim' => false]];
    $res = ChalaniPng::render($trip, $dmP['rows'], $TD, $dmP['totals'] + ['chalaniNo' => 'CH-TEST'], $fmt, 1);
    check('sample PNG page renders', is_file($res['path']) && str_starts_with((string) file_get_contents($res['path'], false, null, 0, 4), "\x89PNG"));
    $fpOn = ChalaniPng::fingerprint($trip, $dmP['rows'], $TD, $dmP['totals'], $fmt);
    Settings::set('chalani_money_strip_on', '0', 'bool', 'booking', false); Settings::flush();
    check('PNG fingerprint follows the strip switch (cached pages redraw)', ChalaniPng::fingerprint($trip, $dmP['rows'], $TD, $dmP['totals'], $fmt) !== $fpOn);
    Database::delete('settings', 'skey = :k', ['k' => 'chalani_money_strip_on']); Settings::flush();
    check('PNG layout version bumped to 18+', ChalaniPng::LAYOUT_VERSION >= 18);
    if (@is_dir($OUT) || @mkdir($OUT, 0775, true)) {
        @copy($res['path'], $OUT . '/mp1-chalani-direct-p1.png');
        echo "  (sample PNG: $OUT/mp1-chalani-direct-p1.png)\n";
    }

    /* ---- 4. over HTTP ---- */
    if (!function_exists('curl_init')) {
        skip('HTTP checks', 'curl not loaded');
    } else {
        [$proc, $port] = startServer($ROOT);
        if ($proc === null) {
            skip('HTTP checks', 'could not start php -S');
        } else {
            $BASE = 'http://127.0.0.1:' . $port;
            $q = '/admin/manifest.php?date=' . $TD . '&sid=' . $sid;
            $anon = http('anon', $q . '&format=json');
            check('json: no login → no data (redirect to login)', $anon['code'] !== 200 || !str_contains($anon['body'], '"strip"'), 'HTTP ' . $anon['code']);
            if (!login('boss', uname('boss'))) {
                skip('HTTP checks as users', 'login flow did not sign in');
            } else {
                $j = http('boss', $q . '&format=json');
                $jd = json_decode($j['body'], true);
                check('json: superadmin gets the live payload', $j['code'] === 200 && is_array($jd) && !empty($jd['ok']) && (int) $jd['strip']['pax'] === 11, 'HTTP ' . $j['code'] . ' ' . substr($j['body'], 0, 160));
                check('json: no-store', stripos($j['headers'], 'no-store') !== false);
                $scr = http('boss', $q);
                check('screen: strip, live note, row badges', $scr['code'] === 200 && str_contains($scr['body'], 'id="mfStrip"') && str_contains($scr['body'], 'data-mf-pd')
                    && str_contains($scr['body'], 'id="mfLive"') && str_contains($scr['body'], 'ड्यू बाँकी · Due'));
                check('screen: tap-to-call tel: link on rows', preg_match('/href="tel:\+?9000025101"/', $scr['body']) === 1);
                check('screen: today\'s counter money for the office', str_contains($scr['body'], 'आजको काउन्टर हिसाब'));
                check('screen: PDF / PNG / WhatsApp buttons', str_contains($scr['body'], 'format=chalanipdf') && str_contains($scr['body'], 'format=chalanipng') && str_contains($scr['body'], 'format=chalanishare'));
                check('screen: booked-by name', str_contains($scr['body'], 'बुक गर्ने · by'));
                check('screen: the fifth "Other paid" box shows (G), strip in 5 columns', str_contains($scr['body'], 'data-strip="other"')
                    && preg_match('/<div class="s-other"(?! hidden)/', $scr['body']) === 1 && str_contains($scr['body'], 'mf-strip has-other'));
                check('screen: H shows the red Office-marked-due badge', str_contains($scr['body'], 'Due · Office marked due'));
                $png = http('boss', $q . '&format=chalanipng&page=1');
                check('PNG page 1 (superadmin)', $png['code'] === 200 && str_starts_with($png['body'], "\x89PNG"), 'HTTP ' . $png['code']);
                if ($png['code'] === 200 && (@is_dir($OUT) || @mkdir($OUT, 0775, true))) {
                    file_put_contents($OUT . '/mp1-chalani-http-p1.png', $png['body']);
                    echo "  (sample PNG via the page: $OUT/mp1-chalani-http-p1.png)\n";
                }
                $pdf = http('boss', $q . '&format=chalanipdf');
                check('PDF builds (superadmin)', str_starts_with($pdf['body'], '%PDF'));
                if (str_starts_with($pdf['body'], '%PDF') && is_dir($OUT)) { file_put_contents($OUT . '/mp1-chalani.pdf', $pdf['body']); }
                $sheet = http('boss', $q . '&format=chalani');
                check('print sheet carries the strip', $sheet['code'] === 200 && str_contains($sheet['body'], 'ड्यू बाँकी'));
                check('print sheet carries the other figure', str_contains($sheet['body'], 'अन्य भुक्तानी'));
                $dl = http('boss', '/download-chalan.php?sid=' . $sid . '&doc=chalani&page=1');
                check('download-chalan: superadmin opens a drawn chalani page without a key', $dl['code'] === 200 && str_starts_with($dl['body'], "\x89PNG"), 'HTTP ' . $dl['code']);
                $hub = http('boss', '/admin/chalan.php?sid=' . $sid);
                check('Bus Chalan hub: chalani card + "Chalani page" sends for chalani.export', $hub['code'] === 200
                    && str_contains($hub['body'], 'value="chalani:1"') && str_contains($hub['body'], 'format=chalanipng') && !str_contains($hub['body'], 'chChalaniLocked'));
                $bCsrf = preg_match('/<meta name="csrf" content="([^"]+)"/', $hub['body'], $mm) === 1 ? html_entity_decode($mm[1]) : '';
                $wp = http('boss', '/admin/api/wa-send.php', ['json' => ['action' => 'preview', 'purpose' => 'chalan_send', 'sid' => $sid, 'doc' => 'chalani', 'page' => 1,
                    'target' => 'custom', 'phone' => '9000025199', 'country' => 'IN'], 'csrf' => $bCsrf]);
                $wj = json_decode($wp['body'], true);
                check('WhatsApp chalan_send: superadmin may send a chalani page', is_array($wj) && !empty($wj['ok']) && str_contains((string) ($wj['text'] ?? ''), 'Chalani page 1'),
                    substr($wp['body'], 0, 200));
                $sh = http('boss', $q . '&format=chalanishare');
                check('share: opens wa.me with the text + a signed link', $sh['code'] === 302 && preg_match('/^Location: https:\/\/wa\.me\/\?text=.*download-chalan\.php/mi', $sh['headers']) === 1);
            }
            if (login('ctr', uname('ctr'))) {
                $p = http('ctr', $q . '&format=chalanipng&page=1');
                check('counter: PNG allowed (chalani.export)', $p['code'] === 200, 'HTTP ' . $p['code']);
                $c = http('ctr', $q);
                check('counter: counter block shown', str_contains($c['body'], 'आजको काउन्टर हिसाब'));
            } else { skip('counter HTTP checks', 'login flow did not sign in'); }
            if (login('sup', uname('sup'))) {
                $p = http('sup', $q . '&format=chalanipng&page=1');
                check('support (no grant): PNG refused 403', $p['code'] === 403, 'HTTP ' . $p['code']);
                $p2 = http('sup', $q . '&format=chalanipdf');
                check('support (no grant): PDF refused 403', $p2['code'] === 403, 'HTTP ' . $p2['code']);
                $sh = http('sup', $q . '&format=chalanishare');
                check('support (no grant): share refused 403', $sh['code'] === 403, 'HTTP ' . $sh['code']);
                $j = http('sup', $q . '&format=json');
                check('support: json follows the page gate (bookings.view)', $j['code'] === 200 && str_contains($j['body'], '"strip"'));
                $c = http('sup', $q);
                check('support: no counter block, no export buttons', !str_contains($c['body'], 'आजको काउन्टर हिसाब') && !str_contains($c['body'], 'format=chalanishare'));
                $dl = http('sup', '/download-chalan.php?sid=' . $sid . '&doc=chalani&page=1');
                check('support (no grant): download-chalan chalani page without a key refused 403', $dl['code'] === 403, 'HTTP ' . $dl['code']);
                $dlc = http('sup', '/download-chalan.php?sid=' . $sid . '&doc=challan');
                check('support: the challan seat picture stays on bookings.view', $dlc['code'] === 200, 'HTTP ' . $dlc['code']);
                $sl = ChalaniStrip::shareLinks($sid, 1, 2)[1];
                $sg = http('anon2', '/download-chalan.php?' . (string) parse_url($sl, PHP_URL_QUERY));
                check('a signed share link still opens the page (no login)', $sg['code'] === 200 && str_starts_with($sg['body'], "\x89PNG"), 'HTTP ' . $sg['code']);
                $hub = http('sup', '/admin/chalan.php?sid=' . $sid);
                check('Bus Chalan hub (support): no chalani preview / PDF / PNG / print, no "Chalani page" send', $hub['code'] === 200
                    && !str_contains($hub['body'], 'format=chalanipng') && !str_contains($hub['body'], 'format=chalanipdf') && !str_contains($hub['body'], 'value="chalani:')
                    && str_contains($hub['body'], 'chChalaniLocked') && str_contains($hub['body'], 'value="challan"'), 'HTTP ' . $hub['code']);
                $sCsrf = preg_match('/<meta name="csrf" content="([^"]+)"/', $hub['body'], $mm) === 1 ? html_entity_decode($mm[1]) : '';
                $wctx = ['action' => 'preview', 'purpose' => 'chalan_send', 'sid' => $sid, 'page' => 1, 'target' => 'custom', 'phone' => '9000025199', 'country' => 'IN'];
                $wp = json_decode(http('sup', '/admin/api/wa-send.php', ['json' => $wctx + ['doc' => 'chalani'], 'csrf' => $sCsrf])['body'], true);
                check('WhatsApp chalan_send (support): a chalani page is refused', is_array($wp) && empty($wp['ok']) && str_contains((string) ($wp['error'] ?? ''), 'chalani.export'),
                    json_encode($wp, JSON_UNESCAPED_UNICODE));
                $wc = json_decode(http('sup', '/admin/api/wa-send.php', ['json' => $wctx + ['doc' => 'challan'], 'csrf' => $sCsrf])['body'], true);
                check('WhatsApp chalan_send (support): the challan seat picture still goes', is_array($wc) && !empty($wc['ok']), json_encode($wc, JSON_UNESCAPED_UNICODE));
            } else { skip('support HTTP checks', 'login flow did not sign in'); }
            if (login('agt', uname('agt'))) {
                $j = http('agt', $q . '&format=json');
                $jd = json_decode($j['body'], true);
                check('agent: json scoped to its own sale', is_array($jd) && !empty($jd['ok']) && count($jd['rows']) === 1, substr($j['body'], 0, 160));
                $sh = http('agt', $q . '&format=chalanishare');
                check('agent: never shares', $sh['code'] === 403, 'HTTP ' . $sh['code']);
                $c = http('agt', $q);
                check('agent: no counter block', !str_contains($c['body'], 'आजको काउन्टर हिसाब'));
            } else { skip('agent HTTP checks', 'login flow did not sign in'); }
        }
    }
} catch (Throwable $e) {
    $fail++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
if (is_resource($proc)) { proc_terminate($proc); @proc_close($proc); }
/* MP1_CH_KEEP=1 leaves the seed and the users for a look in a browser (the next run removes them) */
$keep = getenv('MP1_CH_KEEP') === '1';
if ($keep && $sched !== null) {
    echo "\n  KEPT: /admin/manifest.php?date=$TD&sid=" . (int) $sched['id'] . '  (users ' . uname('boss') . ' / ' . uname('ctr') . ")\n";
} else {
    cleanup();
}
if (!$keep && $sched !== null) {
    $dir = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $TD;
    foreach (glob($dir . '/*-S' . (int) $sched['id'] . '-*.png') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
    if ($schedMade && (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => (int) $sched['id']], 0) === 0) {
        Database::delete('schedules', 'id = :i', ['i' => (int) $sched['id']]);
    }
}
$restore();
foreach ($JARS as $j) { @unlink($j); }
unset($_SESSION[ADMIN_SESSION_KEY]);
echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
