<?php
/**
 * 16 Nov 2026 — owner: "pay at boarding vane option ticket chalani ma add
 * gardeu - cash ho ki online ho tyo ni; ani redirect payment lina sab suvidha
 * hunu paryo, free wala".
 *
 * Seeds one near-future departure (inside the public link's +30-day window,
 * on a date with no other booking) with synthetic bookings:
 *   A  pay at boarding (COD), 2 passengers, not collected
 *   B  pay at boarding, cash collected (BookingService::settleCod)
 *   C  online, verified (UPI)
 *   D  online, pending (the office has not checked it)
 *   E  pay at boarding sold under an AGENT, not collected
 *   F  pay at boarding, the passenger already sent a UTR (pay.php) - still due
 * and checks every chalani surface: the manifest screen, the print sheet, the
 * CSV, the PDF (ChalaniPdf::$drawn in a child process), the PNG pages and the
 * public live link - the pay mark per row (बसमा ₹X once per booking / नगद ✓ /
 * अनलाइन ✓ / अनलाइन जाँचमा), "बसमा उठाउनु पर्ने · To collect on boarding: N
 * जना · ₹X", no double counting with the नगद / अनलाइन columns, and the crew's
 * 💳 QR: only on pay-at-boarding rows still due, pointing at pay.php, and
 * opening it never marks anything paid. Everything it made is deleted after.
 *
 *   SHG_TEST_BASE=http://localhost:9202 php -c <ini> -d extension=php_curl.dll tests/u12-P2-chalani-pay-test.php
 *
 * U12_KEEP=1 leaves the synthetic seed in place (A still due) to look at in a
 * browser; the next run - or U12_CLEANUP=1 - removes it and puts everything back.
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
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:9202'), '/'));
const PHONES  = ['A' => '9100001201', 'B' => '9100001202', 'C' => '9100001203', 'D' => '9100001204', 'E' => '9100001205', 'F' => '9100001206'];
const BOSS    = 'u12_chalani_pay_boss';
const BOSS_PW = 'U12ChalaniPay77';
const AGENT   = 'u12_chalani_pay_agent';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JAR = sys_get_temp_dir() . '/shg_u12_pay_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, array $opt = []): array {
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 90]);
    if (!empty($opt['nocookie'])) { curl_setopt($ch, CURLOPT_COOKIEFILE, ''); curl_setopt($ch, CURLOPT_COOKIEJAR, ''); }
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
/** Everything the money rule reads, for these bookings - to prove opening a page / a QR changes none of it. */
function moneySnap(array $ids): string {
    if ($ids === []) { return ''; }
    $in = implode(',', array_map('intval', $ids));
    $p = Database::fetchAll("SELECT id, booking_id, status, method, amount, verified_by, verified_at FROM payments WHERE booking_id IN ($in) ORDER BY id");
    $b = Database::fetchAll("SELECT id, status, is_cod, total_amount FROM bookings WHERE id IN ($in) ORDER BY id");
    $c = [];
    try { $c = Database::fetchAll("SELECT booking_id, status, mode, amount FROM booking_collections WHERE booking_id IN ($in) ORDER BY booking_id"); } catch (Throwable $e) {}
    return md5((string) json_encode([$p, $b, $c]));
}
function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }   // the desk sale drew a ticket
            if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
            try { Database::run('DELETE FROM booking_collections WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            try { Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            try { Database::run('DELETE FROM pay_events WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('admins', 'username = :a', ['a' => BOSS]);
    Database::delete('admins', 'username = :a', ['a' => AGENT]);
}

/** Remove the seed and put the departure + the switches back as they were. */
function restoreAll(array $st): void {
    cleanup();
    $sid = (int) ($st['sched'] ?? 0);
    if ($sid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($st['date'] ?? '')) === 1) {
        $dir = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $st['date'];
        foreach (glob($dir . '/*-S' . $sid . '-*.png') ?: [] as $f) { @unlink($f); }   // the chalani pages drawn here
        @rmdir($dir);   // only when empty
    }
    if ($sid > 0) {
        if (!empty($st['made'])) {
            if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0) === 0) {
                Database::delete('schedules', 'id = :i', ['i' => $sid]);
            }
        } elseif (is_array($st['schedKeep'] ?? null)) {
            $sk = $st['schedKeep'];
            Database::update('schedules', ['is_blocked' => (int) $sk['is_blocked'], 'status' => (string) $sk['status'],
                'seats_booked' => (int) $sk['seats_booked']], 'id = :i', ['i' => (int) $sk['id']]);
        }
    }
    foreach ((array) ($st['keep'] ?? []) as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
}
$STATE = sys_get_temp_dir() . '/shg_u12_chalani_pay_keep.json';
if (is_file($STATE)) {
    restoreAll((array) json_decode((string) file_get_contents($STATE), true));
    @unlink($STATE);
    echo "  (the seed kept by an earlier U12_KEEP run was removed)\n";
}
if (getenv('U12_CLEANUP') === '1') { echo "  cleaned up\n"; exit(0); }
$KEEP = getenv('U12_KEEP') === '1';

/* the switches this test turns on, put back exactly as found */
$keep = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}

echo "\n=== Chalani: pay at boarding · cash or online · the crew's 💳 QR ===\n\n";
cleanup();

/* A date inside the live link's window (+30 days) with no booking on it at all,
   so the trip's "to collect" figure is exactly this test's seed. */
$TD = '';
for ($i = 27; $i >= 10; $i--) {
    $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
    if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
}
$schedKeep = null; $schedMade = false; $sched = null;
try {
    if ($TD === '') { throw new RuntimeException('no free date in the next 10-27 days to seed on'); }
    Settings::set('whatsapp_notify_customer', '0', 'bool', 'notify', false);
    Settings::set('whatsapp_notify_admin', '0', 'bool', 'notify', false);
    Settings::set('allow_cod', '1', 'bool', 'booking', false);
    Settings::flush();

    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $schedKeep = Database::fetch('SELECT id, is_blocked, status, seats_booked FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]);
    $sched   = Seats::schedule($routeId, $TD);
    $schedMade = $schedKeep === null;
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);

    $bossId = (int) Database::insert('admins', ['username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'U12 Pay Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    $agentId = (int) Database::insert('admins', ['username' => AGENT, 'password_hash' => password_hash('U12AgentPw!9', PASSWORD_DEFAULT),
        'full_name' => 'U12 Pay Agent', 'role' => 'agent', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    require_once INCLUDE_PATH . '/quickunlock.php';
    QuickUnlock::set($bossId, 'pin', '583942');   // the code-first door sends a PIN-less account to pin-setup first
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];

    /* Seeded as desk sales, then set to the state under test straight in the
       rows (synthetic data - no customer flow, no message). */
    $sell = static function (string $k, array $seats, string $method) use ($routeId, $TD, $bossId): array {
        $pax = [];
        foreach ($seats as $n => $s) { $pax[] = ['name' => 'U12 ' . $k . ' Pax' . ($n > 0 ? ' ' . ($n + 1) : ''), 'age' => 30 + $n, 'gender' => 'Male']; }
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => $seats, 'passengers' => $pax,
            'contact' => ['phone' => PHONES[$k]], 'bookingMode' => 'sharing', 'paymentMethod' => $method, 'isCod' => false, 'boarding' => ''],
            ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => $method]);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        return $row;
    };
    $asCod = static function (array $b, ?int $seller): void {
        Database::update('bookings', ['is_cod' => 1, 'source' => 'web', 'sold_by_admin_id' => $seller, 'status' => 'confirmed'], 'id = :i', ['i' => (int) $b['id']]);
        Database::update('payments', ['status' => 'cod_pending', 'method' => 'cod', 'mode' => 'offline', 'verified_by' => null, 'verified_at' => null],
            'booking_id = :b', ['b' => (int) $b['id']]);
    };
    $A = $sell('A', ['L20', 'L22'], 'cash'); $asCod($A, null);
    $B = $sell('B', ['L24'], 'cash');        $asCod($B, null);
    BookingService::settleCod((int) $B['id'], $bossId, 'u12 test: cash collected at boarding');
    $C = $sell('C', ['L26'], 'upi');
    Database::update('bookings', ['source' => 'web', 'sold_by_admin_id' => null], 'id = :i', ['i' => (int) $C['id']]);
    Database::update('payments', ['method' => 'upi', 'status' => 'verified'], 'booking_id = :b', ['b' => (int) $C['id']]);
    $D = $sell('D', ['L28'], 'upi');
    Database::update('bookings', ['source' => 'web', 'sold_by_admin_id' => null, 'status' => 'pending'], 'id = :i', ['i' => (int) $D['id']]);
    Database::update('payments', ['method' => 'upi', 'mode' => 'utr', 'status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'utr_number' => '612345678901'],
        'booking_id = :b', ['b' => (int) $D['id']]);
    $E = $sell('E', ['L30'], 'cash'); $asCod($E, $agentId);
    $F = $sell('F', ['L32'], 'cash'); $asCod($F, null);
    Database::update('payments', ['utr_number' => '612398765432', 'mode' => 'utr', 'admin_note' => 'ONLINE CLAIM · UPI · u12 test'],
        'booking_id = :b', ['b' => (int) $F['id']]);   // what pay.php's UTR box writes (CodPayClaim): the row stays cod_pending

    $ids = [(int) $A['id'], (int) $B['id'], (int) $C['id'], (int) $D['id'], (int) $E['id'], (int) $F['id']];
    $pay = static fn(array $b): ?array => Database::fetch('SELECT status, method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b['id']]);
    check('seeded A: pay at boarding, 2 passengers, not collected', (int) Database::scalar('SELECT is_cod FROM bookings WHERE id = :i', ['i' => (int) $A['id']], 0) === 1
        && ($pay($A)['status'] ?? '') === 'cod_pending');
    check('seeded B: pay at boarding, cash collected (verified cod)', ($pay($B)['status'] ?? '') === 'verified' && ($pay($B)['method'] ?? '') === 'cod');
    check('seeded C: online verified upi', ($pay($C)['status'] ?? '') === 'verified' && ($pay($C)['method'] ?? '') === 'upi');
    check('seeded D: online pending', ($pay($D)['status'] ?? '') === 'pending');
    check('seeded F: pay at boarding with a UTR sent, still cod_pending', ($pay($F)['status'] ?? '') === 'cod_pending'
        && (string) Database::scalar('SELECT utr_number FROM payments WHERE booking_id = :b', ['b' => (int) $F['id']], '') === '612398765432');
    check('seeded E: pay at boarding under an agent', ($pay($E)['status'] ?? '') === 'cod_pending'
        && (int) Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :i', ['i' => (int) $E['id']], 0) === $agentId);

    $tA = (float) $A['total_amount']; $tB = (float) $B['total_amount']; $tC = (float) $C['total_amount']; $tE = (float) $E['total_amount']; $tF = (float) $F['total_amount'];
    $dueA = 2 * round($tA / 2);                 // the fare split per passenger, as the chalani prints it
    $dueE = round($tE);
    $dueF = round($tF);
    $boardDue = $dueA + $dueE + $dueF;
    echo "  (date $TD · A ₹$tA · B ₹$tB · C ₹$tC · E ₹$tE · to collect ₹$boardDue)\n";

    /* ---- the one rule ---- */
    check('payMode: COD not collected = boarding', ChalaniPng::payMode(['is_cod' => 1, 'pay_status' => 'cod_pending', 'pay_method' => 'cod'], 'due') === 'boarding');
    check('payMode: COD collected = cash', ChalaniPng::payMode(['is_cod' => 1, 'pay_status' => 'verified', 'pay_method' => 'cod'], 'paid') === 'cash');
    check('payMode: agent holds the cash = agent', ChalaniPng::payMode(['is_cod' => 1, 'pay_status' => 'verified', 'pay_method' => 'cod'], 'agent') === 'agent');
    check('payMode: verified UPI / eSewa = online', ChalaniPng::payMode(['is_cod' => 0, 'pay_status' => 'verified', 'pay_method' => 'esewa'], 'paid') === 'online');
    check('payMode: a COD whose online claim waits for the office = still boarding (never auto-paid)',
        ChalaniPng::payMode(['is_cod' => 1, 'pay_status' => 'pending', 'pay_method' => 'upi'], 'due') === 'boarding');
    check('payMode: an office "Mark due" on a verified row is due, not boarding (its money stays in its column)',
        ChalaniPng::payMode(['is_cod' => 1, 'pay_status' => 'verified', 'pay_method' => 'cod'], 'due') === 'due');
    /* review 16 Nov 2026: a pay-at-boarding fare paid ONLINE and settled with the chalani
       tick "UPI" / "eSewa" (OpsHub::markPaid -> settleCod always writes method 'cod') is
       online money - as the passenger's ticket says (Ticket::paidWay) - never नगद ✓. */
    $codPaid = ['is_cod' => 1, 'pay_status' => 'verified', 'pay_method' => 'cod'];
    $tk = static fn(string $m): array => ['status' => 'paid', 'mode' => $m];
    check('payMode: COD settled with the tick "UPI" = online (not cash)', ChalaniPng::payMode($codPaid, 'paid', $tk('UPI')) === 'online');
    check('payMode: COD settled with the tick "eSewa" / "Bank" = online', ChalaniPng::payMode($codPaid, 'paid', $tk('eSewa')) === 'online'
        && ChalaniPng::payMode($codPaid, 'paid', $tk('Bank')) === 'online');
    check('payMode: COD settled with the tick "Cash" / "Agent" / "Office" = cash', ChalaniPng::payMode($codPaid, 'paid', $tk('Cash')) === 'cash'
        && ChalaniPng::payMode($codPaid, 'paid', $tk('Agent')) === 'cash' && ChalaniPng::payMode($codPaid, 'paid', $tk('Office')) === 'cash');
    check('payMode: COD settled, tick row not there but its note "Chalani tick: paid (UPI)" = online',
        ChalaniPng::payMode($codPaid + ['pay_note' => 'Chalani tick: paid (UPI)'], 'paid') === 'online');
    check('payMode: an explicit cash row stays cash whatever the tick says (an agent handing cash in by UPI)',
        ChalaniPng::payMode(['is_cod' => 0, 'pay_status' => 'verified', 'pay_method' => 'cash'], 'paid', $tk('UPI')) === 'cash');
    check('payMode: a COD ticked UPI but marked due again = due (no paid word)', ChalaniPng::payMode($codPaid, 'due', ['status' => 'due', 'mode' => 'UPI']) === 'due');
    $same = true; $diff = [];
    foreach (['cod', ''] as $pmth) {
        foreach (['Cash', 'UPI', 'eSewa', 'Bank', 'Agent', 'Office', 'GPay', 'Khalti', 'Fonepay QR', 'नगद'] as $tm) {
            foreach ([null, 'Chalani tick: paid (' . $tm . ')'] as $nt) {
                $tick = $nt === null ? $tk($tm) : null;
                $want = Ticket::paidWay($pmth, $tick, (string) $nt)[0];
                $got  = ChalaniPng::paidWay(['pay_method' => $pmth, 'pay_note' => (string) $nt], $tick);
                if ($got !== $want) { $same = false; $diff[] = "$pmth/$tm/" . ($nt === null ? 'tick' : 'note') . ": $got vs $want"; }
            }
        }
    }
    check('paidWay: the chalani\'s cash / online = the ticket\'s (Ticket::paidWay) for every tick mode, tick or note', $same, implode('; ', $diff));

    /* ---- sign in ---- */
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try', 'chalani_live')"); } catch (Throwable $e) {}
    $login = http('/admin/login.php?classic=1&other=1');
    $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => BOSS, 'password' => BOSS_PW]]);
    check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
    $q = '/admin/manifest.php?date=' . $TD . '&sid=' . (int) $sched['id'];
    $snap0 = moneySnap($ids);

    /* ---- screen ---- */
    $page = http($q);
    $sb = $page['body'];
    check('manifest screen renders', $page['code'] === 200, 'HTTP ' . $page['code']);
    check('screen: "To collect on boarding: 4 जना · ' . inr($boardDue) . '"', str_contains($sb, 'बसमा उठाउनु पर्ने · To collect on boarding: 4 जना · ' . inr($boardDue)));
    check('screen: F (UTR sent) still "बसमा ' . inr($dueF) . '" + "UTR जाँचमा" - never paid by itself', str_contains($sb, 'बसमा ' . inr($dueF) . ' · On boarding')
        && str_contains($sb, '>UTR जाँचमा · Online sent, office checking<'));
    check('screen: cash / online badge = B in cash, C online (no boarding money in either)', str_contains($sb, 'नगद · Cash ' . inr($tB) . ' — अनलाइन · Online ' . inr($tC)));
    check('screen: A carries "बसमा ' . inr($dueA) . ' · On boarding" (the booking amount)', str_contains($sb, 'बसमा ' . inr($dueA) . ' · On boarding'));
    check('screen: E (agent) carries "बसमा ' . inr($dueE) . ' · On boarding"', str_contains($sb, 'बसमा ' . inr($dueE) . ' · On boarding'));
    check('screen: B reads "नगद ✓ · Cash"', str_contains($sb, '>नगद ✓ · Cash<'));
    check('screen: C reads "अनलाइन ✓ · Online"', str_contains($sb, '>अनलाइन ✓ · Online<'));
    check('screen: D reads "अनलाइन जाँचमा" (office checking)', str_contains($sb, '>अनलाइन जाँचमा · Online, office checking<'));
    preg_match_all('/<button[^>]*data-payqr[^>]*>/s', $sb, $qb);
    $qrPnrs = [];
    foreach ($qb[0] as $tag) { if (preg_match('/data-pnr="([^"]+)"/', $tag, $pm) === 1) { $qrPnrs[] = html_entity_decode($pm[1]); } }
    check('screen: a 💳 QR on every pay-at-boarding row still due (A ×2, E, F) and nowhere else', count($qrPnrs) === 4
        && count(array_keys($qrPnrs, (string) $A['pnr'], true)) === 2 && in_array((string) $E['pnr'], $qrPnrs, true) && in_array((string) $F['pnr'], $qrPnrs, true), implode(',', $qrPnrs));
    $tagA = '';
    foreach ($qb[0] as $tag) { if (str_contains($tag, 'data-pnr="' . $A['pnr'] . '"')) { $tagA = $tag; break; } }
    $linkA = preg_match('/data-link="([^"]+)"/', $tagA, $lm) === 1 ? html_entity_decode($lm[1]) : '';
    $srcA  = preg_match('/data-qr="([^"]+)"/', $tagA, $lm) === 1 ? html_entity_decode($lm[1]) : '';
    check('screen: the QR links to the passenger\'s pay page (pay.php?pnr=…&k=the ticket key, like the ticket\'s own pay QR)',
        $linkA === appUrl('pay.php') . '?pnr=' . rawurlencode((string) $A['pnr']) . '&k=' . rawurlencode(Ticket::downloadToken((string) $A['pnr'])), $linkA);
    check('screen: the QR picture comes from this page (format=payqr), no outside service', $srcA === '/admin/manifest.php?format=payqr&pnr=' . rawurlencode((string) $A['pnr']), $srcA);
    check('screen: the QR card says the office verifies (nothing is marked paid here)', str_contains($sb, 'id="mfPayQr"') && str_contains($sb, 'It counts as paid only after the office checks it'));

    /* ---- the QR picture ---- */
    $qr = http($srcA);
    check('QR (office): a PNG', $qr['code'] === 200 && str_starts_with($qr['body'], "\x89PNG") && stripos($qr['headers'], 'image/png') !== false, 'HTTP ' . $qr['code']);
    $want = QrCode::dataUri($linkA, 8, 2);
    check('QR (office): it encodes exactly the pay link', base64_encode($qr['body']) === substr($want, (int) strpos($want, ',') + 1));
    foreach (['B' => $B, 'C' => $C, 'D' => $D] as $k => $bk) {
        $no = http('/admin/manifest.php?format=payqr&pnr=' . rawurlencode((string) $bk['pnr']));
        check("QR (office): none for $k (nothing due on boarding)", $no['code'] === 404, 'HTTP ' . $no['code']);
    }
    check('QR (office): none for a made-up PNR', http('/admin/manifest.php?format=payqr&pnr=ZZZ-0000-00000')['code'] === 404);
    check('QR (office): E (agent COD) has one', http('/admin/manifest.php?format=payqr&pnr=' . rawurlencode((string) $E['pnr']))['code'] === 200);

    /* ---- printed chalani ---- */
    $ch = http($q . '&format=chalani');
    $cb = $ch['body'];
    check('chalani sheet renders', $ch['code'] === 200, 'HTTP ' . $ch['code']);
    check('chalani: A\'s row "बसमा ₹' . number_format($dueA) . '" (once - its 2nd row reads "बसमा")', substr_count($cb, '<b class="pm pm-boarding">बसमा ₹' . number_format($dueA) . '</b>') === 1
        && substr_count($cb, '<b class="pm pm-boarding">बसमा</b>') === 1);
    check('chalani: E\'s row "बसमा ₹' . number_format($dueE) . '"', str_contains($cb, '<b class="pm pm-boarding">बसमा ₹' . number_format($dueE) . '</b>'));
    check('chalani: B "नगद ✓", C "अनलाइन ✓", D "अनलाइन जाँचमा"', str_contains($cb, '<b class="pm pm-cash">नगद ✓</b>')
        && str_contains($cb, '<b class="pm pm-online">अनलाइन ✓</b>') && str_contains($cb, '<b class="pm pm-pending">अनलाइन जाँचमा</b>'));
    check('chalani: summary "बसमा उठाउनु पर्ने · To collect on boarding: ४ जना · ₹ ' . number_format($boardDue) . '"',
        str_contains($cb, 'बसमा उठाउनु पर्ने · To collect on boarding</span><b class="bd">४ जना · ₹ ' . number_format($boardDue) . '</b>'));
    check('chalani: F carries "UTR जाँचमा" beside its बसमा mark', str_contains($cb, '<b class="pm pm-boarding">बसमा ₹' . number_format($dueF) . '</b> <b class="pm pm-pending">UTR जाँचमा</b>'));
    check('chalani: नगद = B only, अनलाइन = C only (boarding money in neither - no double count)',
        str_contains($cb, 'नगद संकलन</span><b>₹ ' . number_format($tB) . '</b>') && str_contains($cb, 'अनलाइन / UPI प्राप्ति</span><b>₹ ' . number_format($tC) . '</b>'));
    check('chalani: net = cash + online', str_contains($cb, 'खुद जम्मा संकलन</span><b class="net">₹ ' . number_format($tB + $tC) . '</b>'));
    check('chalani: A\'s cash / online cells are empty', (bool) preg_match('/<td class="r">' . preg_quote(number_format(round($tA / 2)), '/') . '<\/td>\s*<td class="r"><\/td>\s*<td class="r"><\/td>/u', $cb));
    check('chalani: the print sheet has no QR button', !str_contains($cb, 'data-payqr'));

    /* ---- CSV ---- */
    $csv = http($q . '&format=csv');
    check('CSV downloads', $csv['code'] === 200 && stripos($csv['headers'], 'text/csv') !== false);
    $lines = array_map(static fn(string $l): array => str_getcsv($l, ',', '"', ''), preg_split('/\r?\n/', ltrim($csv['body'], "\xEF\xBB\xBF")) ?: []);
    $head = []; $byPnr = []; $tc = null;
    foreach ($lines as $ln) {
        if (($ln[0] ?? '') === 'Seat') { $head = $ln; continue; }
        if (($ln[0] ?? '') === 'To collect on boarding') { $tc = $ln; continue; }
        if ($head !== [] && count($ln) === count($head)) { $row = array_combine($head, $ln); $byPnr[$row['Booking ID']][] = $row['Pay mode']; }
    }
    check('CSV: a "Pay mode" column next to Payment', ($i = array_search('Pay mode', $head, true)) !== false && ($head[$i - 1] ?? '') === 'Payment');
    check('CSV: "To collect on boarding", 4 passengers, ' . $boardDue, $tc !== null && ($tc[1] ?? '') === '4 passengers' && (float) ($tc[2] ?? 0) === (float) $boardDue, json_encode($tc));
    check('CSV: A = ON BOARDING DUE ×2', ($byPnr[$A['pnr']] ?? []) === ['ON BOARDING DUE', 'ON BOARDING DUE']);
    check('CSV: B = CASH, C = ONLINE, D = ONLINE PENDING, E = ON BOARDING DUE', ($byPnr[$B['pnr']] ?? []) === ['CASH'] && ($byPnr[$C['pnr']] ?? []) === ['ONLINE']
        && ($byPnr[$D['pnr']] ?? []) === ['ONLINE PENDING'] && ($byPnr[$E['pnr']] ?? []) === ['ON BOARDING DUE'], json_encode($byPnr));
    check('CSV: F = ON BOARDING DUE (UTR SENT)', ($byPnr[$F['pnr']] ?? []) === ['ON BOARDING DUE (UTR SENT)']);

    /* ---- PDF + PNG over HTTP ---- */
    $pdf = http($q . '&format=chalanipdf');
    check('chalani PDF builds', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code'] . ' ' . strlen($pdf['body']) . ' bytes');
    $png = http($q . '&format=chalanipng&page=1');
    check('chalani PNG page 1 draws', $png['code'] === 200 && str_starts_with($png['body'], "\x89PNG"), 'HTTP ' . $png['code']);
    $pngL = http($q . '&format=chalanipng&page=' . ChalaniPng::pageCount(array_fill(0, 6, [])));
    check('chalani PNG summary page draws', $pngL['code'] === 200 && str_starts_with($pngL['body'], "\x89PNG"), 'HTTP ' . $pngL['code']);
    check('PNG layout version moved (cached pages redraw once)', ChalaniPng::LAYOUT_VERSION >= 16);
    $fpRows = [['seat_no' => 'L1', 'pnr' => 'X', 'booking_id' => 1]];
    check('PNG fingerprint follows the pay mark', ChalaniPng::fingerprint([], $fpRows, $TD, [], ['payMark' => static fn(array $r): array => ['mode' => 'boarding', 'amt' => 100.0]])
        !== ChalaniPng::fingerprint([], $fpRows, $TD, [], ['payMark' => static fn(array $r): array => ['mode' => 'cash', 'amt' => 0.0]]));

    /* ---- the PDF draws the marks + the summary line (child process, ChalaniPdf::$drawn) ---- */
    $child = sys_get_temp_dir() . '/shg_u12_pdf_' . getmypid() . '.php';
    file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);
[$_, $root, $n] = $argv; $n = (int) $n;
require $root . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php'; require_once INCLUDE_PATH . '/pdf.php'; require_once INCLUDE_PATH . '/chalanipdf.php'; require_once INCLUDE_PATH . '/chalanipng.php';
$trip = ['schedule_id' => 1, 'route_code' => 'R2', 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'dep_time' => '13:00:00', 'coach_type' => 'sleeper',
         'bus_number' => 'GJ-02-T-0000', 'bus_name' => 'Test', 'driver_name' => '', 'driver_phone' => '', 'slot' => 1];
$ids = Seats::seatIds('sleeper', 'sharing'); $rows = [];
$modes = [['cod_pending', 'cod', 1], ['verified', 'cod', 1], ['verified', 'upi', 0], ['pending', 'upi', 0]];
for ($i = 0; $i < $n; $i++) {
    [$ps, $pm, $cod] = $modes[$i % 4];
    $rows[] = ['pax_id' => $i + 1, 'booking_id' => $i + 1, 'seat_no' => $ids[$i % count($ids)], 'booking_mode' => 'sharing', 'full_name' => 'Passenger ' . ($i + 1),
        'ticket_number' => '', 'pnr' => sprintf('ZZP-2099-%05d', $i + 1), 'contact_phone' => '9100000000', 'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha',
        'created_at' => '2099-01-01 10:00:00', 'gender' => 'Male', 'pay_status' => $ps, 'pay_method' => $pm, 'is_cod' => $cod, 'total_amount' => 2200, 'sold_by_admin_id' => null, 'source' => 'web'];
}
$state = static fn(array $r): string => $r['pay_status'] === 'verified' ? 'paid' : 'due';
$mark  = static function (array $r) use ($state): array {
    $m = ChalaniPng::payMode($r, $state($r));
    return ['mode' => $m, 'amt' => $m === 'boarding' ? 2200.0 : 0.0, 'first' => true, 'ne' => ChalaniPng::payModeLabel($m, 'ne') . ($m === 'boarding' ? ' ₹2,200' : '')];
};
$fmt = [
    'money' => static fn(array $r): array => ['ticket' => 2200.0, 'extra' => 0.0, 'cash' => $r['pay_status'] === 'verified' && $r['pay_method'] === 'cod' ? 2200.0 : 0.0,
        'online' => $r['pay_status'] === 'verified' && $r['pay_method'] === 'upi' ? 2200.0 : 0.0],
    'stopShort' => static fn(string $s): string => $s, 'isLate' => static fn(array $r): bool => false, 'bookedBy' => static fn(array $r): string => 'ONLINE',
    'stopsLine' => 'Surat (१३:००)', 'stopsLineRoman' => 'Surat (13:00)', 'placeList' => [['no' => 1, 'name' => 'Surat', 'n' => $n, 'women' => 0, 'seats' => []]],
    'placeNo' => static fn(string $s): int => 1, 'womanSingle' => static fn(array $r): bool => false, 'womenSingleCount' => 0,
    'seatInfo' => [], 'layout' => Seats::layoutFor('sleeper', 'sharing'), 'busName' => 'Test',
    'extrasLines' => [], 'penLines' => 2, 'rowState' => $state, 'infantsOf' => static fn(array $r): int => 0, 'payMark' => $mark,
];
$nb = (int) ceil($n / 4);
$totals = ['passengers' => $n, 'ticket' => 2200.0 * $n, 'cash' => 2200.0 * intdiv($n + 2, 4), 'online' => 2200.0 * intdiv($n + 1, 4), 'discount' => 0.0,
           'net' => 0.0, 'chalaniNo' => 'CH-TEST', 'extras' => 0.0, 'extrasPaid' => 0.0, 'extrasDue' => 0.0, 'infants' => 0,
           'boardDue' => 2200.0 * $nb, 'boardDueN' => $nb];
register_shutdown_function(static function (): void { fwrite(STDERR, json_encode(ChalaniPdf::$drawn)); });
ChalaniPdf::send($trip, $rows, '2099-01-01', $totals, $fmt);
PHP);
    $ini = (string) php_ini_loaded_file();
    foreach ([8, 21] as $n) {
        $p = proc_open(array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], [$child, dirname(__DIR__), (string) $n]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) : '';
        $err = is_resource($p) ? (string) stream_get_contents($pipes[2]) : '';
        if (is_resource($p)) { fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
        $j = strrpos($err, '{"rows"');
        $dr = $j !== false ? (array) json_decode(substr($err, $j), true) : [];
        $marks = (array) ($dr['marks'] ?? []);
        $pages = (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $out);
        check("PDF ($n rows): a PDF, every row carries its pay mark", str_starts_with($out, '%PDF') && count($marks) === $n, count($marks) . ' marks ' . substr($err, 0, 200));
        check("PDF ($n rows): बसमा ₹2,200 / नगद ✓ / अनलाइन ✓ / अनलाइन जाँचमा drawn", in_array('बसमा ₹2,200', $marks, true) && in_array('नगद ✓', $marks, true)
            && in_array('अनलाइन ✓', $marks, true) && in_array('अनलाइन जाँचमा', $marks, true));
        $nb = (int) ceil($n / 4);
        $want = 'बसमा उठाउनु पर्ने = ' . strtr((string) $nb, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']) . ' जना · ₹ ' . number_format(2200 * $nb);
        check("PDF ($n rows): the summary line \"$want\"", in_array($want, (array) ($dr['sum'] ?? []), true), json_encode($dr['sum'] ?? []));
        check("PDF ($n rows): nothing below the footer rule", (float) ($dr['maxBottom'] ?? 999) <= (float) ($dr['limit'] ?? 0));
        if ($n === 21) { check('PDF: 21 passengers still one sheet page (+ the seat chart)', $pages === 2, 'pages ' . $pages); }
    }
    @unlink($child);

    /* ---- the public live link ---- */
    if (OpsHub::publicOn()) {
        $key = OpsHub::publicKey();
        $pub = http('/chalani-live.php?k=' . rawurlencode($key) . '&d=' . $TD, ['nocookie' => true]);
        check('public link renders', $pub['code'] === 200, 'HTTP ' . $pub['code']);
        $sec = '';
        foreach (explode('<section class="trip">', $pub['body']) as $part) { if (str_contains($part, (string) $A['pnr'])) { $sec = $part; break; } }
        check('public: this bus is listed', $sec !== '');
        check('public: "बसमा उठाउनु पर्ने · To collect on boarding: 4 जना · ₹' . number_format($boardDue) . '"',
            str_contains($sec, 'बसमा उठाउनु पर्ने · To collect on boarding: 4 जना · ₹' . number_format($boardDue)));
        check('public: F shows "UTR जाँचमा"', str_contains($sec, '>UTR जाँचमा<'));
        check('public: the link never carries a ticket key (k)', !str_contains($pub['body'], '&amp;k=') && !str_contains($pub['body'], '&k='));
        check('public: A "बसमा ₹' . number_format($dueA) . '", E "बसमा ₹' . number_format($dueE) . '"', str_contains($sec, '>बसमा ₹' . number_format($dueA) . '<') && str_contains($sec, '>बसमा ₹' . number_format($dueE) . '<'));
        check('public: B "नगद ✓", C "अनलाइन ✓", D "अनलाइन जाँचमा"', str_contains($sec, '>नगद ✓<') && str_contains($sec, '>अनलाइन ✓<') && str_contains($sec, '>अनलाइन जाँचमा<'));
        preg_match_all('/<button[^>]*data-payqr[^>]*>/s', $sec, $pq);
        $pubPnrs = [];
        foreach ($pq[0] as $tag) { if (preg_match('/data-pnr="([^"]+)"/', $tag, $pm) === 1) { $pubPnrs[] = html_entity_decode($pm[1]); } }
        sort($pubPnrs);
        $wantP = [(string) $A['pnr'], (string) $E['pnr'], (string) $F['pnr']]; sort($wantP);
        check('public: a 💳 QR only on the three bookings due on boarding', $pubPnrs === $wantP, implode(',', $pubPnrs));
        $pTag = '';
        foreach ($pq[0] as $tag) { if (str_contains($tag, 'data-pnr="' . $A['pnr'] . '"')) { $pTag = $tag; } }
        $pLink = preg_match('/data-link="([^"]+)"/', $pTag, $lm) === 1 ? html_entity_decode($lm[1]) : '';
        $pSrc  = preg_match('/data-qr="([^"]+)"/', $pTag, $lm) === 1 ? html_entity_decode($lm[1]) : '';
        check('public: the QR links to pay.php for A', $pLink === appUrl('pay.php?pnr=' . rawurlencode((string) $A['pnr'])), $pLink);
        check('public: the QR picture is this link\'s own (?k=…&qr=PNR)', $pSrc === '?k=' . rawurlencode($key) . '&qr=' . rawurlencode((string) $A['pnr']), $pSrc);
        $pqr = http('/chalani-live.php' . $pSrc, ['nocookie' => true]);
        check('public QR: a PNG of the pay link', $pqr['code'] === 200 && base64_encode($pqr['body']) === substr(($w = QrCode::dataUri($pLink, 8, 2)), (int) strpos($w, ',') + 1), 'HTTP ' . $pqr['code']);
        $pno = http('/chalani-live.php?k=' . rawurlencode($key) . '&qr=' . rawurlencode((string) $B['pnr']), ['nocookie' => true]);
        check('public QR: none for B (cash already collected)', $pno['code'] === 404, 'HTTP ' . $pno['code']);
        $pbad = http('/chalani-live.php?k=wrongkey&qr=' . rawurlencode((string) $A['pnr']), ['nocookie' => true]);
        check('public QR: a wrong key gets nothing', $pbad['code'] === 404 && !str_starts_with($pbad['body'], "\x89PNG"), 'HTTP ' . $pbad['code']);
        check('public: no cash-confirm action on the link (no form, no POST)', !preg_match('/<form(?![^>]*method="dialog")/i', $pub['body']));
    } else {
        check('public link is off here — the same rule (ChalaniPng::payMode) drives it', true);
    }

    /* ---- opening pages and QRs never changed a payment ---- */
    check('no payment, booking or tick changed by opening the chalani, the CSV, the PDF, the PNG or any QR', moneySnap($ids) === $snap0);
    check('A, E and F (UTR sent) are still cod_pending, D still pending', ($pay($A)['status'] ?? '') === 'cod_pending' && ($pay($E)['status'] ?? '') === 'cod_pending'
        && ($pay($F)['status'] ?? '') === 'cod_pending' && ($pay($D)['status'] ?? '') === 'pending');

    /* ---- cash collected: the mark and the totals move together ---- */
    if ($KEEP) { throw new RuntimeException('KEEP'); }   // leave A due for a look
    BookingService::settleCod((int) $A['id'], $bossId, 'u12 test: collected');
    $ch2 = http($q . '&format=chalani');
    check('after A pays cash: A reads "नगद ✓" and to-collect drops to E + F',
        substr_count($ch2['body'], '<b class="pm pm-cash">नगद ✓</b>') === 3
        && str_contains($ch2['body'], 'To collect on boarding</span><b class="bd">२ जना · ₹ ' . number_format($dueE + $dueF) . '</b>'));
    check('  …and A\'s money is now in नगद (B + A)', str_contains($ch2['body'], 'नगद संकलन</span><b>₹ ' . number_format($tB + 2 * round($tA / 2)) . '</b>'));

    /* ---- review 16 Nov 2026: pay at boarding, paid ONLINE, the office ticks UPI / eSewa ----
       F paid through pay.php and sent a UTR (still cod_pending); the office checks the bank
       and ticks the chalani "UPI" - OpsHub::markPaid -> settleCod writes method 'cod'. E is
       ticked "eSewa". B (cash, already settled) gets a "Cash" tick. The chalani must read
       अनलाइन ✓ for E and F on every surface and count them in अनलाइन, like their tickets. */
    $png1 = http($q . '&format=chalanipng&page=1');
    $boss = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin'];
    $mF = OpsHub::markPaid((int) $F['id'], 'UPI', null, '', $boss);
    $mE = OpsHub::markPaid((int) $E['id'], 'eSewa', null, '', $boss);
    $mB = OpsHub::markPaid((int) $B['id'], 'Cash', null, '', $boss);
    check('ticked: F "UPI", E "eSewa", B "Cash" - F and E settled through settleCod (method cod)', $mF['ok'] && $mE['ok'] && $mB['ok']
        && ($pay($F)['status'] ?? '') === 'verified' && ($pay($F)['method'] ?? '') === 'cod' && ($pay($E)['method'] ?? '') === 'cod', $mF['msg'] . ' | ' . $mE['msg']);
    $ticks = OpsHub::collections([(int) $E['id'], (int) $F['id'], (int) $B['id']]);
    $agree = true;
    foreach (['E' => $E, 'F' => $F, 'B' => $B] as $k => $bk) {
        $pr = Database::fetch('SELECT method, admin_note FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $bk['id']]);
        $tW = Ticket::paidWay((string) $pr['method'], $ticks[(int) $bk['id']] ?? null, (string) ($pr['admin_note'] ?? ''))[0];
        $cW = ChalaniPng::paidWay(['pay_method' => $pr['method'], 'pay_note' => $pr['admin_note']], $ticks[(int) $bk['id']] ?? null);
        if ($tW !== $cW || $tW !== ($k === 'B' ? 'cash' : 'online')) { $agree = false; echo "    $k: ticket $tW, chalani $cW\n"; }
    }
    check('the passenger\'s ticket and the chalani agree: E, F online, B cash', $agree);

    $fareE = round($tE); $fareF = round($tF);
    $cashT = $tB + 2 * round($tA / 2); $onT = $tC + $fareE + $fareF;
    $ch3 = http($q . '&format=chalani');
    $cb3 = $ch3['body'];
    $rowOf = static function (string $html, string $name): string {
        foreach (explode('<tr', $html) as $seg) { if (str_contains($seg, $name . ' <small>')) { return $seg; } }
        return '';
    };
    $rF = $rowOf($cb3, 'U12 F Pax'); $rE = $rowOf($cb3, 'U12 E Pax');
    check('chalani: F (UTR paid, ticked UPI) reads "अनलाइन ✓", not "नगद ✓"', str_contains($rF, '<b class="pm pm-online">अनलाइन ✓</b>') && !str_contains($rF, 'pm-cash'));
    check('chalani: E (ticked eSewa) reads "अनलाइन ✓"', str_contains($rE, '<b class="pm pm-online">अनलाइन ✓</b>') && !str_contains($rE, 'pm-cash'));
    check('chalani: F\'s fare sits in the अनलाइन cell, the नगद cell is empty',
        (bool) preg_match('/<td class="r">' . preg_quote(number_format($fareF), '/') . '<\/td>\s*<td class="r"><\/td>\s*<td class="r">' . preg_quote(number_format($fareF), '/') . '<\/td>/u', $rF));
    check('chalani: B (ticked Cash) still "नगद ✓"', str_contains($rowOf($cb3, 'U12 B Pax'), '<b class="pm pm-cash">नगद ✓</b>'));
    check('chalani: three "नगद ✓" (A ×2, B), three "अनलाइन ✓" (C, E, F)', substr_count($cb3, '<b class="pm pm-cash">नगद ✓</b>') === 3
        && substr_count($cb3, '<b class="pm pm-online">अनलाइन ✓</b>') === 3);
    check('chalani: नगद = A + B only, अनलाइन = C + E + F (' . number_format($onT) . ')',
        str_contains($cb3, 'नगद संकलन</span><b>₹ ' . number_format($cashT) . '</b>') && str_contains($cb3, 'अनलाइन / UPI प्राप्ति</span><b>₹ ' . number_format($onT) . '</b>'));
    check('chalani: nothing left to collect on boarding', (bool) preg_match('/To collect on boarding<\/span><b[^>]*>० जना · ₹ 0<\/b>/u', $cb3));
    $sb3 = http($q)['body'];
    $grpOf = static function (string $html, string $name): string {
        foreach (explode('<tr class="mf-grp', $html) as $seg) { if (str_contains($seg, '<b>' . $name . '</b>')) { return (string) strstr($seg . '</tr>', '</tr>', true); } }
        return '';
    };
    $gF = $grpOf($sb3, 'U12 F Pax');
    check('screen: F\'s booking pill "अनलाइन ✓ · Online" (the tick says UPI beside it)', str_contains($gF, 'pm-online">अनलाइन ✓ · Online<') && !str_contains($gF, 'pm-cash') && str_contains($gF, 'UPI · '));
    check('screen: the cash / online badge = A + B in cash, C + E + F online', str_contains($sb3, 'नगद · Cash ' . inr($cashT) . ' — अनलाइन · Online ' . inr($onT)));
    $csv3 = http($q . '&format=csv');
    $pm3 = [];
    $h3 = [];
    foreach (preg_split('/\r?\n/', ltrim($csv3['body'], "\xEF\xBB\xBF")) ?: [] as $l) {
        $ln = str_getcsv($l, ',', '"', '');
        if (($ln[0] ?? '') === 'Seat') { $h3 = $ln; continue; }
        if ($h3 !== [] && count($ln) === count($h3)) { $row = array_combine($h3, $ln); $pm3[$row['Booking ID']][] = $row['Pay mode']; }
    }
    check('CSV: E = ONLINE, F = ONLINE, B = CASH', ($pm3[$E['pnr']] ?? []) === ['ONLINE'] && ($pm3[$F['pnr']] ?? []) === ['ONLINE'] && ($pm3[$B['pnr']] ?? []) === ['CASH'], json_encode($pm3));
    $png2 = http($q . '&format=chalanipng&page=1');
    check('PNG: page 1 redrawn for the new marks (a different picture)', $png2['code'] === 200 && str_starts_with($png2['body'], "\x89PNG") && md5($png2['body']) !== md5($png1['body']));
    check('PDF still builds', str_starts_with(http($q . '&format=chalanipdf')['body'], '%PDF'));
    if (OpsHub::publicOn()) {
        $pub3 = http('/chalani-live.php?k=' . rawurlencode(OpsHub::publicKey()) . '&d=' . $TD, ['nocookie' => true]);
        $cardOf = static function (string $html, string $pnr): string {
            foreach (explode('<div class="bk ', $html) as $seg) { if (str_contains($seg, $pnr)) { return $seg; } }
            return '';
        };
        $pF = $cardOf($pub3['body'], (string) $F['pnr']); $pE = $cardOf($pub3['body'], (string) $E['pnr']);
        check('public: F and E read "अनलाइन ✓", not "नगद ✓"', str_contains($pF, '>अनलाइन ✓<') && !str_contains($pF, '>नगद ✓<')
            && str_contains($pE, '>अनलाइन ✓<') && !str_contains($pE, '>नगद ✓<'));
        check('public: no 💳 QR left (nothing due on boarding)', !str_contains($pub3['body'], 'data-payqr data-pnr'));
    }
} catch (Throwable $e) {
    if ($e->getMessage() !== 'KEEP') {
        $FAIL++;
        echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
$state = ['sched' => $sched !== null ? (int) $sched['id'] : 0, 'date' => $TD, 'made' => $schedMade, 'schedKeep' => $schedKeep, 'keep' => $keep];
if ($KEEP && $sched !== null) {
    file_put_contents($STATE, (string) json_encode($state));
    echo "\n  KEPT for a look (synthetic rows): " . BASE . '/admin/manifest.php?date=' . $TD . '&sid=' . (int) $sched['id']
       . "\n  and the public link for $TD - run again (or U12_CLEANUP=1) to remove it.\n";
} else {
    restoreAll($state);
}
@unlink($JAR);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
