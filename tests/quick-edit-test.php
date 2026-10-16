<?php
/**
 * =====================================================================
 *  quick-edit-test.php — Quick Edit ticket (15 Oct 2026).
 *
 *  Locks in everything the Quick Edit card on admin/booking-view.php added:
 *    A  Auth::mayReassignSeller / mayEditField + settings ticket_edit_rules
 *    B  BookingService::appendNote (remarks append, never replace, 255 cap)
 *    C  the refund bug: a full cancel after a per-seat cancel ADDS to a
 *       pending refund, never flips a processed one back; full-cancel and
 *       per-seat audits keep the old values
 *    D  super-admin fare / discount correction (booking, payment,
 *       commission UPDATED in place, cash_due) and its refusals
 *    E  "Request correction" queue: request -> apply / refuse, who may
 *    F  agent_cancel_mode (direct = today; request = no cancel)
 *    G  edit_passenger: field rights, rollback, stop fare guard, 'stops'
 *       ticket kind, remark append, gender clash, no-op save
 *    H  free-seat filter + Auto Assign (AutoBerth / SmartBook::paxFromRows)
 *    I  reschedule seat_order, new-booking sid, staff-chat preset, gates
 *    J  the page renders per role (prefilled boxes, only allowed fields)
 *
 *  Pages are driven in a child process (this file with --drive), exactly
 *  like tests/render-admin.php, so the real handlers run. Writes only
 *  throwaway rows (staff 'qetest-*', travel dates 2099-12-1x) and removes
 *  them at the end. Refuses to run unless DB_NAME contains 'test' or
 *  starts with 'lt_'.
 *
 *    SHG_DB=lt_f1 php -c .claude/php-dev.ini tests/quick-edit-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- Child mode: render one admin page as one staff member ---------- */
if (($argv[1] ?? '') === '--drive') {
    $spec = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_GET  = (array) ($spec['get'] ?? []);
    $post  = (array) ($spec['post'] ?? []);
    $page  = (string) ($spec['page'] ?? '');
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page;
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($spec['as'] ?? 0)]);
    if ($a === null) { echo "NO ADMIN\n"; exit(1); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    }
    register_shutdown_function(static function (): void {
        foreach (headers_list() as $h) { if (stripos($h, 'Location:') === 0) { echo "\n<!--HDR " . $h . " -->\n"; } }
    });
    require dirname(__DIR__) . '/admin/' . $page;
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'notify', 'booking', 'agentwallet', 'tripstatus', 'ticketedit', 'smartbook'] as $inc) {
    require_once INCLUDE_PATH . '/' . $inc . '.php';
}
if (!str_contains(DB_NAME, 'test') && !str_starts_with(DB_NAME, 'lt_')) {
    fwrite(STDERR, "Refusing: DB_NAME is not a test database\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $why = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($why !== '' ? " — $why" : '') . "\n"; }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

/** Render a page in a child process as staff #$as; returns the HTML. */
function drive(string $page, int $as, array $get = [], array $post = []): string {
    $spec = base64_encode((string) json_encode(['page' => $page, 'as' => $as, 'get' => $get, 'post' => $post]));
    $ini  = (string) php_ini_loaded_file();
    $cmd  = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '')
          . ' ' . escapeshellarg(__FILE__) . ' --drive ' . escapeshellarg($spec) . ' 2>&1';
    return (string) shell_exec($cmd);
}
/** [class, text] of the first flash on a rendered page. */
function flash(string $html): array {
    return preg_match('~<div class="flash (ok|bad|warn)">(.*?)</div>~s', $html, $m) === 1
        ? [$m[1], html_entity_decode(strip_tags($m[2]), ENT_QUOTES)] : ['', ''];
}
/** Fake the signed-in staff member for in-process Auth checks. */
function actAs(?array $a): void {
    if ($a === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => [], 'last_seen' => time()];
}
function bk(int $id): array { return (array) Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $id]); }
function lastAudit(string $action, string $pnr): ?array {
    return Database::fetch('SELECT * FROM audit_logs WHERE action = :a AND entity_id = :p ORDER BY id DESC LIMIT 1', ['a' => $action, 'p' => $pnr]);
}

const QE_DATE  = '2099-12-11';
const QE_DATE2 = '2099-12-12';
$STAFF = [];
$origSettings = [];
$madeBookings = [];

function qe_cleanup(array $staff, array $bookings): void {
    $ids = array_values(array_filter(array_map(static fn($s) => (int) ($s['id'] ?? 0), $staff)));
    $bids = $bookings;
    foreach ($ids as $id) {
        foreach (pluck(Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $id]), 'id') as $b) { $bids[] = (int) $b; }
    }
    foreach (array_unique($bids) as $b) {
        $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $b], '');
        try { Database::delete('ticket_edit_audit', 'booking_id = :b', ['b' => $b]); } catch (Throwable $e) {}
        if ($pnr !== '') {
            try { Database::run("DELETE FROM notifications WHERE title LIKE :t", ['t' => '%' . $pnr . '%']); } catch (Throwable $e) {}
        }
        try { Seats::releaseBooking($b); } catch (Throwable $e) {}
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => $b]);
        Database::delete('bookings', 'id = :i', ['i' => $b]);
    }
    foreach ($ids as $id) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
        try {
            Database::delete('staff_threads', 'agent_admin_id = :a', ['a' => $id]);
            Database::delete('staff_thread_reads', 'admin_id = :a', ['a' => $id]);
            Database::delete('staff_presence', 'admin_id = :a', ['a' => $id]);
        } catch (Throwable $e) {}
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :a', ['a' => $id]);
    }
}

echo "\n=== Quick Edit ticket (" . DB_NAME . ") ===\n";

try {
    Database::run('DELETE FROM rate_limits');
    if (!TicketEdit::available()) { echo "ticket_edit_audit missing — apply database/upgrade-2026-10-15-quick-edit.sql\n"; exit(1); }

    // Remember the settings this suite touches (restored in finally).
    foreach (['ticket_edit_rules', 'agent_cancel_mode', 'agent_commission_overrides', 'notify_ticket_edit', 'agent_wallet_enabled'] as $sk) {
        $origSettings[$sk] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $sk]);
    }
    Database::run("UPDATE settings SET svalue = '0' WHERE skey = 'notify_ticket_edit'");
    Database::run("UPDATE settings SET svalue = '1' WHERE skey = 'agent_wallet_enabled'");
    Database::run("UPDATE settings SET svalue = 'direct' WHERE skey = 'agent_cancel_mode'");
    Database::run("UPDATE settings SET svalue = :v WHERE skey = 'ticket_edit_rules'", ['v' => json_encode(Auth::TICKET_EDIT_DEFAULTS)]);
    Settings::flush();

    // ---- Staff --------------------------------------------------------
    foreach (pluck(Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'qetest-%'"), 'id') as $old) {
        qe_cleanup([['id' => (int) $old]], []);
    }
    foreach (['agent' => 'agent', 'agent2' => 'agent', 'counter' => 'counter', 'manager' => 'manager', 'ops' => 'operations', 'support' => 'support', 'boss' => 'superadmin'] as $k => $role) {
        $id = Database::insert('admins', ['username' => 'qetest-' . $k, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'full_name' => 'QE Test ' . ucfirst($k), 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
        $STAFF[$k] = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    }
    $agentId = (int) $STAFF['agent']['id'];
    try { AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('direct'), $agentId); } catch (Throwable $e) {}
    try { AgentWallet::setAgentCode((int) $STAFF['agent2']['id'], AgentWallet::nextFreeAgentCode('direct'), $agentId); } catch (Throwable $e) {}

    $route = Database::fetch("SELECT * FROM routes WHERE id = 2 AND coach_type = 'sleeper'")
          ?? Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid  = (int) $route['id'];
    $sid  = (int) Seats::schedule($rid, QE_DATE)['id'];
    $sid2 = (int) Seats::schedule($rid, QE_DATE2)['id'];
    $women = Seats::femaleSeats('sleeper');
    $free  = array_values(array_filter(Seats::availability($rid, QE_DATE)['available'] ?? [], static fn($s) => !in_array($s, $women, true)));
    $boards = Boarding::stopsFor($rid);
    $boardA = ''; $boardSame = ''; $boardDiff = '';
    foreach ($boards as $s) {   // two stops at one fare, one at another
        foreach ($boards as $t) {
            if ($boardA === '' && $s['name'] !== $t['name'] && $s['fare'] !== null && $s['fare'] === $t['fare']) { $boardA = (string) $s['name']; $boardSame = (string) $t['name']; }
        }
    }
    foreach ($boards as $s) { if ($boardA !== '' && $s['fare'] !== null && $s['fare'] !== Fare::stopFare($rid, $boardA, null)) { $boardDiff = (string) $s['name']; break; } }

    // Free cabins (both beds free), so each test booking has a cabin of its own.
    $pairs = [];
    foreach ($free as $s) {
        $u = Seats::unitKey($s, 'sleeper');
        $pairs[$u][] = $s;
    }
    $pairs = array_values(array_filter($pairs, static fn($p) => count($p) === 2));
    if (count($pairs) < 8) { echo "not enough free cabins on " . QE_DATE . "\n"; exit(1); }
    $sale = static function (array $seats, int $seller, array $pax, string $board = '', string $method = 'cash', ?float $amount = null) use ($route, $sid, &$madeBookings): array {
        $b = BookingService::counterSale($route, $sid, QE_DATE, $seats, [
            'name' => $pax[0]['name'], 'phone' => '98000' . random_int(10000, 99999), 'gender' => $pax[0]['gender'],
            'amount' => $amount ?? 2200.0 * count($seats), 'paymentMethod' => $method, 'boarding' => $board, 'passengers' => $pax,
        ], $seller, $seller === 0 ? 'admin' : 'agent');
        $madeBookings[] = (int) $b['id'];
        return BookingService::detail((string) $b['pnr']);
    };

    /* ============================================================== */
    echo "\nA · who may change what (Auth)\n";
    $own   = $sale($pairs[0], $agentId, [['name' => 'Ram Own', 'gender' => 'Male', 'age' => 30], ['name' => 'Hari Own', 'gender' => 'Male', 'age' => 8]], $boardA);
    $other = $sale([$pairs[1][0]], (int) $STAFF['agent2']['id'], [['name' => 'Sita Other', 'gender' => 'Female', 'age' => 40]], $boardA);

    actAs($STAFF['boss']);    check('super-admin may reassign the seller', Auth::mayReassignSeller());
    check('super-admin may correct the fare', Auth::mayEditField($own, 'fare'));
    actAs($STAFF['manager']); check('manager may reassign the seller (bookings.reassign, new)', Auth::mayReassignSeller() && Auth::can('bookings.reassign'));
    check('manager may NOT correct a fare directly (super-admin only)', !Auth::mayEditField($own, 'fare'));
    check('manager keeps name / payment rights', Auth::mayEditField($own, 'name') && Auth::mayEditField($own, 'payment'));
    actAs($STAFF['ops']);     check('operations may NOT reassign the seller', !Auth::mayReassignSeller());
    actAs($STAFF['counter']); check('counter may NOT reassign the seller', !Auth::mayReassignSeller());
    check('counter defaults: name, phone, pickup, seat, payment', Auth::mayEditField($own, 'name') && Auth::mayEditField($own, 'phone')
        && Auth::mayEditField($own, 'pickup') && Auth::mayEditField($own, 'seat') && Auth::mayEditField($own, 'payment'));
    check('counter keeps unlisted fields (gender) on bookings.edit', Auth::mayEditField($own, 'gender'));
    actAs($STAFF['agent']);   check('agent may NOT reassign the seller', !Auth::mayReassignSeller());
    check('agent defaults on own ticket: name + phone only', Auth::mayEditField($own, 'name') && Auth::mayEditField($own, 'phone')
        && !Auth::mayEditField($own, 'pickup') && !Auth::mayEditField($own, 'gender') && !Auth::mayEditField($own, 'seat')
        && !Auth::mayEditField($own, 'payment') && !Auth::mayEditField($own, 'fare') && !Auth::mayEditField($own, 'remark'));
    check('agent may edit nothing on another agent\'s ticket', !Auth::mayEditField($other, 'name') && !Auth::mayEditField($other, 'phone'));
    check('agent may edit nothing on a cancelled own ticket', !Auth::mayEditField(['status' => 'cancelled'] + $own, 'name'));
    actAs($STAFF['support']); check('support (no bookings.edit) edits nothing directly', !Auth::mayEditField($own, 'name') && !Auth::mayEditField($own, 'payment'));

    Database::run("UPDATE settings SET svalue = :v WHERE skey = 'ticket_edit_rules'",
        ['v' => '{"agent":{"name":true,"phone":true,"pickup":true},"counter":{"name":true,"phone":true,"pickup":true,"seat":true,"payment":false}}']);
    Settings::flush();
    actAs($STAFF['agent']);   check('rules: owner switches pickup ON for agents', Auth::mayEditField($own, 'pickup'));
    actAs($STAFF['counter']); check('rules: owner switches payment OFF for the counter', !Auth::mayEditField($own, 'payment') && Auth::mayEditField($own, 'name'));
    Database::run("UPDATE settings SET svalue = 'not json' WHERE skey = 'ticket_edit_rules'");
    Settings::flush();
    check('a broken rules setting falls back to the defaults', Auth::ticketEditRules() === Auth::TICKET_EDIT_DEFAULTS);
    Database::run("UPDATE settings SET svalue = :v WHERE skey = 'ticket_edit_rules'", ['v' => json_encode(Auth::TICKET_EDIT_DEFAULTS)]);
    Settings::flush();
    actAs(null);

    /* ============================================================== */
    echo "\nB · remarks append, never replace\n";
    $n1 = BookingService::appendNote('Seat L5 cancelled [1 Oct 10:00]', 'Wants window — Ram [15 Oct 09:00]');
    check('appendNote keeps the earlier line and adds the new one below', $n1 === "Seat L5 cancelled [1 Oct 10:00]\nWants window — Ram [15 Oct 09:00]");
    check('appendNote on an empty note is just the line', BookingService::appendNote('', 'First') === 'First');
    $long = BookingService::appendNote(str_repeat("old line xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n", 8), 'NEWEST remark');
    check('appendNote stays inside the 255-char column, newest line kept', mb_strlen($long) <= 255 && str_ends_with($long, 'NEWEST remark'));

    /* ============================================================== */
    echo "\nC · refunds: per-seat then full cancel (bug fix)\n";
    $r1 = $sale($pairs[2], 0, [['name' => 'Refund One', 'gender' => 'Male', 'age' => 30], ['name' => 'Refund Two', 'gender' => 'Male', 'age' => 31]], '', 'cash', 4000.0);
    $seatX = (string) $r1['seats'][0];
    actAs($STAFF['boss']);
    $rs = BookingService::cancelSeat((int) $r1['id'], $seatX, (int) $STAFF['boss']['id'], 'test seat cancel');
    $afterSeat = bk((int) $r1['id']);
    $seatRefund = (float) $rs['refund']['amount'];
    check('setup: per-seat cancel parks a pending refund', $seatRefund > 0 && money_is((float) $afterSeat['refund_amount'], $seatRefund) && $afterSeat['refund_status'] === 'pending',
        'refund ' . $seatRefund . ' / ' . $afterSeat['refund_status']);
    $seatAudit = lastAudit('booking.cancel_seat', (string) $r1['pnr']);
    $oldSeatVals = json_decode((string) ($seatAudit['old_value'] ?? ''), true) ?: [];
    check('per-seat cancel audit keeps the passenger name / gender / age', ($oldSeatVals['passenger'] ?? '') === 'Refund One'
        && ($oldSeatVals['gender'] ?? '') === 'Male' && (int) ($oldSeatVals['age'] ?? 0) === 30);
    check('per-seat cancel audit carries the reason in its reason column', (string) ($seatAudit['reason'] ?? '') === 'test seat cancel' || !array_key_exists('reason', (array) $seatAudit));
    check('per-seat cancel APPENDS to admin_note', str_contains((string) $afterSeat['admin_note'], 'Seat ' . $seatX . ' cancelled'));
    $rc = BookingService::cancel((string) $r1['pnr'], 'rest cancelled', false);
    $afterAll = bk((int) $r1['id']);
    check('full cancel ADDS its refund to the pending per-seat refund', money_is((float) $afterAll['refund_amount'], $seatRefund + (float) $rc['refund']['amount'])
        && $afterAll['refund_status'] === 'pending', 'got ' . $afterAll['refund_amount'] . ' want ' . ($seatRefund + (float) $rc['refund']['amount']));
    $cAudit = lastAudit('booking.cancel', (string) $r1['pnr']);
    $cOld = json_decode((string) ($cAudit['old_value'] ?? ''), true) ?: [];
    check('full-cancel audit records the old status + amount (not null)', ($cOld['status'] ?? '') === 'confirmed' && isset($cOld['amount']) && $cAudit['new_value'] !== null);

    $r2 = $sale($pairs[3], 0, [['name' => 'Paid One', 'gender' => 'Male', 'age' => 30], ['name' => 'Paid Two', 'gender' => 'Male', 'age' => 31]], '', 'cash', 4000.0);
    $rs2 = BookingService::cancelSeat((int) $r2['id'], (string) $r2['seats'][0], (int) $STAFF['boss']['id'], 'seat');
    // The owner paid the seat refund by hand.
    Database::update('bookings', ['refund_status' => 'processed'], 'id = :i', ['i' => (int) $r2['id']]);
    $paid = (float) bk((int) $r2['id'])['refund_amount'];
    $rc2 = BookingService::cancel((string) $r2['pnr'], 'rest', false);
    $after2 = bk((int) $r2['id']);
    check('a PROCESSED refund is never flipped back to pending', $after2['refund_status'] === 'processed' && money_is((float) $after2['refund_amount'], $paid));
    $c2Audit = lastAudit('booking.cancel', (string) $r2['pnr']);
    check('the unpaid remainder is written into the audit for the owner', (float) $rc2['refund']['amount'] <= 0
        || str_contains((string) $c2Audit['detail'], 'NOT added'));

    $r3 = $sale([$pairs[4][0]], 0, [['name' => 'Plain Cancel', 'gender' => 'Male', 'age' => 30]], '', 'cash', 2000.0);
    $rc3 = BookingService::cancel((string) $r3['pnr'], 'plain', false);
    $after3 = bk((int) $r3['id']);
    check('a plain cancel (no earlier refund) behaves as before', money_is((float) $after3['refund_amount'], (float) $rc3['refund']['amount'])
        && $after3['refund_status'] === ((float) $rc3['refund']['amount'] > 0 ? 'pending' : 'none'));
    actAs(null);

    /* ============================================================== */
    echo "\nD · fare correction (super-admin only, one transaction)\n";
    AgentWallet::setCommissionOverride($agentId, 'percent', null, $agentId);   // commission moves with the fare
    $f = $sale($pairs[5], $agentId, [['name' => 'Fare One', 'gender' => 'Male', 'age' => 30], ['name' => 'Fare Two', 'gender' => 'Male', 'age' => 33]], '', 'cash', 4400.0);
    $fid = (int) $f['id'];
    $commRow0 = Database::fetch("SELECT id, amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => $fid]);
    $cash0    = (float) Database::scalar("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => $fid], 0);
    $pct      = AgentWallet::commissionPercentFor($agentId);
    check('setup: percent commission and cash held were posted', $commRow0 !== null && money_is((float) $commRow0['amount'], round(4400 * $pct / 100, 2)) && money_is($cash0, 4400));

    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '4000', 'new_discount' => '0', 'reason' => 'typo']);
    check('a manager cannot correct a fare (super-admin only)', str_contains(flash($h)[1], 'Only a super-admin') && money_is((float) bk($fid)['total_amount'], 4400));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '4000', 'new_discount' => '0', 'reason' => '']);
    check('a fare correction needs a reason', flash($h)[0] === 'bad' && money_is((float) bk($fid)['total_amount'], 4400));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '-5', 'new_discount' => '0', 'reason' => 'bad']);
    check('a total below zero is refused', flash($h)[0] === 'bad' && money_is((float) bk($fid)['total_amount'], 4400));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '4400.00', 'new_discount' => '0.00', 'reason' => 'no change']);
    check('unchanged prefilled boxes are a no-op', str_contains(flash($h)[1], 'Nothing changed'));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '4000', 'new_discount' => '0', 'reason' => 'agreed price']);
    $fb = bk($fid);
    $commRow1 = Database::fetch("SELECT id, amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => $fid]);
    check('super-admin: total corrected 4400 → 4000', flash($h)[0] === 'ok' && money_is((float) $fb['total_amount'], 4000), flash($h)[1]);
    /* a VERIFIED cash sale: the payment row is what was really received, so it
       stays 4400 (Rs 400 overpaid, the owner settles) - never re-written. */
    check('a verified payment keeps the amount really received', money_is((float) Database::scalar('SELECT amount FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $fid], 0), 4400));
    check('commission row UPDATED in place to the re-rated figure (same id, one row)', $commRow1 !== null && (int) $commRow1['id'] === (int) $commRow0['id']
        && money_is((float) $commRow1['amount'], round(4000 * $pct / 100, 2))
        && (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => $fid], 0) === 1);
    check('…and the cash the seller really holds stays as collected', money_is((float) Database::scalar("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => $fid], 0), 4400));
    $fa = lastAudit('booking.fare_correct', (string) $f['pnr']);
    check('fare correction audited old → new with the reason', $fa !== null && str_contains((string) $fa['old_value'], '4400') && str_contains((string) $fa['new_value'], '4000')
        && (!array_key_exists('reason', $fa) || (string) $fa['reason'] === 'agreed price'));
    check('refund columns untouched by a fare correction', (string) $fb['refund_status'] === 'none' && money_is((float) $fb['refund_amount'], 0));
    /* still TO COLLECT (payment not verified): the payment row follows the fare */
    $pv = Database::fetch('SELECT id, status, amount FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $fid]);
    Database::update('payments', ['status' => 'cod_pending'], 'id = :i', ['i' => (int) $pv['id']]);
    TicketEdit::correctFare($fid, 3900.0, null, (int) $STAFF['boss']['id'], 'unpaid fix');
    check('an unverified payment follows the corrected fare', money_is((float) Database::scalar('SELECT amount FROM payments WHERE id = :i', ['i' => (int) $pv['id']], 0), (float) $pv['amount'] - 100));
    Database::update('payments', ['status' => (string) $pv['status'], 'amount' => (float) $pv['amount']], 'id = :i', ['i' => (int) $pv['id']]);
    TicketEdit::correctFare($fid, 4000.0, null, (int) $STAFF['boss']['id'], 'back to 4000');
    // discount only: the prefilled total stays, the total moves by the discount
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $f['pnr']], ['action' => 'fare_correct', 'new_total' => '4000.00', 'new_discount' => '200', 'reason' => 'approved discount']);
    $fb = bk($fid);
    check('discount only: coupon_discount 200 and total 4000 → 3800', money_is((float) $fb['coupon_discount'], 200) && money_is((float) $fb['total_amount'], 3800), flash($h)[1]);
    actAs($STAFF['boss']);
    $threw = '';
    try { TicketEdit::correctFare((int) $r3['id'], 100.0, null, (int) $STAFF['boss']['id'], 'x on cancelled'); } catch (Throwable $e) { $threw = $e->getMessage(); }
    check('a cancelled ticket cannot be re-priced', str_contains($threw, 'cannot be re-priced'));
    $threw = '';
    try { TicketEdit::correctFare($fid, null, 9999.0, (int) $STAFF['boss']['id'], 'too much discount'); } catch (Throwable $e) { $threw = $e->getMessage(); }
    check('a discount that takes the total below zero is refused', str_contains($threw, 'below zero'));
    actAs(null);
    AgentWallet::setCommissionOverride($agentId, null, null, $agentId);

    /* ============================================================== */
    echo "\nE · Request correction → apply / refuse\n";
    $q = $sale($pairs[6], $agentId, [['name' => 'Req One', 'gender' => 'Male', 'age' => 30], ['name' => 'Req Two', 'gender' => 'Male', 'age' => 35]], '', 'cash', 4400.0);
    $qid = (int) $q['id'];
    $h = drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_request', 'req_field' => 'fare', 'req_fare' => '4200', 'req_reason' => 'customer paid 4200']);
    $req = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'fare' ORDER BY id DESC LIMIT 1", ['b' => $qid]);
    check('agent files a fare request (outcome requested, staff:<id>)', $req !== null && $req['outcome'] === 'requested' && $req['changed_by'] === 'staff:' . $agentId
        && $req['new_value'] === '4200.00', flash($h)[1]);
    check('the request changes nothing on the booking', money_is((float) bk($qid)['total_amount'], 4400));
    check('the office bell got a notification', Database::exists("SELECT 1 FROM notifications WHERE title LIKE :t", ['t' => '%Correction request%' . $q['pnr'] . '%']));
    $h = drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_request', 'req_field' => 'fare', 'req_fare' => '4100', 'req_reason' => 'again']);
    check('a second open request for the same field is refused', str_contains(flash($h)[1], 'already waiting'));
    $h = drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_request', 'req_field' => 'fare', 'req_fare' => '4100', 'req_reason' => '']);
    check('a request needs a reason', flash($h)[0] === 'bad');
    $h = drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) $req['id'], 'decision' => 'apply']);
    check('an agent cannot decide requests', str_contains(flash($h)[1], 'Only a super-admin or manager'));
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) $req['id'], 'decision' => 'apply']);
    check('a manager may not APPLY a fare request', str_contains(flash($h)[1], 'super-admin') && money_is((float) bk($qid)['total_amount'], 4400)
        && Database::scalar('SELECT outcome FROM ticket_edit_audit WHERE id = :i', ['i' => (int) $req['id']]) === 'requested');
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) $req['id'], 'decision' => 'apply', 'decide_note' => 'ok']);
    $reqNow = Database::fetch('SELECT * FROM ticket_edit_audit WHERE id = :i', ['i' => (int) $req['id']]);
    check('super-admin applies it: total 4400 → 4200 through correctFare', money_is((float) bk($qid)['total_amount'], 4200), flash($h)[1]);
    check('the request is marked done, naming the approver', $reqNow['outcome'] === 'done' && str_contains((string) $reqNow['detail'], 'QE Test Boss'));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) $req['id'], 'decision' => 'apply']);
    check('a closed request cannot be applied twice', str_contains(flash($h)[1], 'already closed') && money_is((float) bk($qid)['total_amount'], 4200));

    // payment method: cash → upi, applied by a manager; cash_due follows
    drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_request', 'req_field' => 'payment_method', 'req_method' => 'upi', 'req_reason' => 'paid by UPI']);
    $preq = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'payment_method' AND outcome = 'requested'", ['b' => $qid]);
    check('agent files a payment-method request', $preq !== null && $preq['new_value'] === 'upi');
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) ($preq['id'] ?? 0), 'decision' => 'apply']);
    check('manager applies it: payment method is UPI', Database::scalar('SELECT method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $qid]) === 'upi', flash($h)[1]);
    check('… and the cash held is removed with it (syncCashDue)', !Database::exists("SELECT 1 FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => $qid]));

    // agent change: request → manager applies → seller moves
    $agent2Code = AgentWallet::agentCodeLabel((int) $STAFF['agent2']['id']);
    drive('booking-view.php', $agentId, ['pnr' => $q['pnr']], ['action' => 'edit_request', 'req_field' => 'agent', 'req_agent' => $agent2Code, 'req_reason' => 'sold by my colleague']);
    $areq = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'agent' AND outcome = 'requested'", ['b' => $qid]);
    check('agent files a seller-change request by agent code', $areq !== null && (int) $areq['new_value'] === (int) $STAFF['agent2']['id'], $agent2Code);
    $h = drive('booking-view.php', (int) $STAFF['ops']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) ($areq['id'] ?? 0), 'decision' => 'apply']);
    check('operations (no settings.manage) cannot decide', flash($h)[0] === 'bad' && (int) bk($qid)['sold_by_admin_id'] === $agentId);
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $q['pnr']], ['action' => 'edit_decide', 'request_id' => (string) ($areq['id'] ?? 0), 'decision' => 'apply']);
    check('manager applies it: the seller is now agent 2', (int) bk($qid)['sold_by_admin_id'] === (int) $STAFF['agent2']['id'], flash($h)[1]);

    // refuse + cancel request
    $q2 = $sale([$pairs[7][0]], $agentId, [['name' => 'Req Cancel', 'gender' => 'Male', 'age' => 30]], '', 'cash', 2200.0);
    drive('booking-view.php', $agentId, ['pnr' => $q2['pnr']], ['action' => 'edit_request', 'req_field' => 'payment_method', 'req_method' => 'bank', 'req_reason' => 'bank transfer']);
    $rreq = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'payment_method'", ['b' => (int) $q2['id']]);
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $q2['pnr']], ['action' => 'edit_decide', 'request_id' => (string) ($rreq['id'] ?? 0), 'decision' => 'refuse', 'decide_note' => 'it was cash']);
    $rreq = Database::fetch('SELECT * FROM ticket_edit_audit WHERE id = :i', ['i' => (int) ($rreq['id'] ?? 0)]);
    check('refuse: outcome refused, nothing changed', ($rreq['outcome'] ?? '') === 'refused' && str_contains((string) $rreq['detail'], 'it was cash')
        && Database::scalar('SELECT method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $q2['id']]) === 'cash');

    /* ============================================================== */
    echo "\nF · agent_cancel_mode\n";
    Database::run("UPDATE settings SET svalue = 'request' WHERE skey = 'agent_cancel_mode'");
    $h = drive('booking-view.php', $agentId, ['pnr' => $q2['pnr']], ['action' => 'cancel', 'reason' => 'passenger cannot travel']);
    check('request mode: an agent\'s Cancel files a request, the ticket stays live', bk((int) $q2['id'])['status'] === 'confirmed'
        && Database::exists("SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request' AND outcome = 'requested'", ['b' => (int) $q2['id']]), flash($h)[1]);
    $creq = (int) Database::scalar("SELECT id FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request'", ['b' => (int) $q2['id']], 0);
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $q2['pnr']], ['action' => 'edit_decide', 'request_id' => (string) $creq, 'decision' => 'apply']);
    check('a cancel request is never applied from the queue (owner settles by hand)', str_contains(flash($h)[1], 'Cancel button') && bk((int) $q2['id'])['status'] === 'confirmed');
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $q2['pnr']], ['action' => 'cancel', 'reason' => 'office cancel']);
    check('request mode never affects the office: a manager cancels directly', bk((int) $q2['id'])['status'] === 'cancelled', flash($h)[1]);
    check('… and that closes the open cancel request as done', Database::scalar('SELECT outcome FROM ticket_edit_audit WHERE id = :i', ['i' => $creq]) === 'done');
    Database::run("UPDATE settings SET svalue = 'direct' WHERE skey = 'agent_cancel_mode'");
    $q3 = $sale([$pairs[4][1]], $agentId, [['name' => 'Direct Cancel', 'gender' => 'Male', 'age' => 30]], '', 'cash', 2200.0);
    $h = drive('booking-view.php', $agentId, ['pnr' => $q3['pnr']], ['action' => 'cancel', 'reason' => 'agent cancel']);
    check('direct mode (default): the agent cancels their own sale as before', bk((int) $q3['id'])['status'] === 'cancelled', flash($h)[1]);

    // a SEAT cancel request: the seat must be on the ticket; a per-seat cancel closes only that seat's request
    $ownRow  = bk((int) $own['id']);
    $ownSeat = (string) Database::scalar('SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL ORDER BY id LIMIT 1', ['b' => (int) $own['id']], '');
    $actorA  = ['id' => $agentId, 'full_name' => 'QE Agent', 'username' => 'qe-agent'];
    $bad = '';
    try { TicketEdit::request($ownRow, 'cancel_request', 'U99', 'not mine', $actorA); } catch (Throwable $e) { $bad = $e->getMessage(); }
    check('a seat cancel request for a seat not on the ticket is refused', str_contains($bad, 'not on this ticket'), $bad);
    $sreq = TicketEdit::request($ownRow, 'cancel_request', $ownSeat, 'one seat off', $actorA);
    $dup = '';
    try { TicketEdit::request($ownRow, 'cancel_request', $ownSeat, 'again', $actorA); } catch (Throwable $e) { $dup = $e->getMessage(); }
    check('a second tap files no second request', str_contains($dup, 'already waiting'), $dup);
    check('a per-seat cancel of ANOTHER seat leaves the request open', TicketEdit::closeOpenCancels((int) $own['id'], 'test', 'L99') === 0
        && Database::scalar('SELECT outcome FROM ticket_edit_audit WHERE id = :i', ['i' => $sreq]) === 'requested');
    check('a per-seat cancel of THAT seat closes it as done', TicketEdit::closeOpenCancels((int) $own['id'], 'test', $ownSeat) === 1
        && Database::scalar('SELECT outcome FROM ticket_edit_audit WHERE id = :i', ['i' => $sreq]) === 'done');

    /* ============================================================== */
    echo "\nG · edit_passenger: rights, rollback, stops, remarks, gender\n";
    $ownPax = $own['passengers'];
    $p1 = (int) $ownPax[0]['id'];
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
        'pax' => [$p1 => ['name' => 'Ram Corrected']]]);
    check('agent corrects a name on their own ticket', Database::scalar('SELECT full_name FROM booking_passengers WHERE id = :i', ['i' => $p1]) === 'Ram Corrected', flash($h)[1]);
    check('… audited old → new', ($ea = lastAudit('booking.edit_passenger', (string) $own['pnr'])) !== null && str_contains((string) $ea['old_value'], 'Ram Own'));
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
        'pax' => [$p1 => ['name' => 'Ram Again', 'gender' => 'Female']]]);
    check('agent may not change a gender (rule off) — refused', str_contains(flash($h)[1], 'may not change'));
    check('rollback: the name in the same save was NOT applied', Database::scalar('SELECT full_name FROM booking_passengers WHERE id = :i', ['i' => $p1]) === 'Ram Corrected');
    $h = drive('booking-view.php', $agentId, ['pnr' => $other['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $other['id'],
        'pax' => [(int) $other['passengers'][0]['id'] => ['name' => 'Hacked']]]);
    check('agent cannot edit another agent\'s ticket (reads as not found)', str_contains($h, 'No booking found') || str_contains(flash($h)[1], 'not found'));
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
        'boarding_stop' => $boardSame !== '' ? $boardSame : 'Somewhere']);
    check('agent may not move the pickup (rule off)', str_contains(flash($h)[1], 'may not change'));
    $h = drive('booking-view.php', (int) $STAFF['support']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
        'pax' => [$p1 => ['name' => 'Support Edit']]]);
    check('support (no bookings.edit) is refused by the permission gate', Database::scalar('SELECT full_name FROM booking_passengers WHERE id = :i', ['i' => $p1]) === 'Ram Corrected');

    // no-op: everything prefilled and unchanged
    $cur = BookingService::detail((string) $own['pnr']);
    $noop = ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'], 'contact_phone' => (string) $cur['contact_phone'],
        'boarding_stop' => (string) $cur['legs'][0]['boarding_stop'], 'pax' => []];
    foreach ($cur['passengers'] as $pp) { $noop['pax'][(int) $pp['id']] = ['name' => $pp['full_name'], 'age' => (string) $pp['age'], 'gender' => (string) $pp['gender']]; }
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], $noop);
    check('a save with every prefilled box unchanged is a no-op', str_contains(flash($h)[1], 'Nothing changed'), flash($h)[1]);

    if ($boardA !== '' && $boardSame !== '' && $boardDiff !== '') {
        $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
            'boarding_stop' => $boardDiff]);
        $diff = abs((Fare::perSeatFor($route, $boardDiff, null) - Fare::perSeatFor($route, (string) $cur['legs'][0]['boarding_stop'], null)) * 2);
        check('counter: a pickup with another fare is refused, naming the difference', str_contains(flash($h)[1], 'differs by ' . inr($diff)), flash($h)[1]);
        $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
            'boarding_stop' => $boardSame, 'reason' => 'nearer stop']);
        check('counter: a pickup at the same fare is saved', Database::scalar("SELECT boarding_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $own['id']]) === $boardSame, flash($h)[1]);
        $tr = lastAudit('ticket.regenerated', (string) $own['pnr']);
        check('a stop-only change re-issues the ticket as kind "stops"', $tr !== null && str_contains((string) $tr['new_value'], '"kind":"stops"'));
        $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
            'boarding_stop' => $boardDiff, 'reason' => 'owner override']);
        $ea = lastAudit('booking.edit_passenger', (string) $own['pnr']);
        check('super-admin may override; the fare difference is in the audit row', Database::scalar("SELECT boarding_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $own['id']]) === $boardDiff
            && str_contains((string) $ea['new_value'], 'fare_difference'), flash($h)[1]);
    } else {
        check('route has stops at two different fares (stop fare guard)', false, 'no suitable stops on route ' . $rid);
    }
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'],
        'drop_stop' => '__other__', 'drop_other' => 'Kohalpur Chowk @ 09:00']);
    check('"Other…" stop is saved as typed text, a fake "@ time" stripped', Database::scalar("SELECT drop_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $own['id']]) === 'Kohalpur Chowk', flash($h)[1]);

    // remarks
    Database::update('bookings', ['admin_note' => 'Seat L99 cancelled: old [1 Oct 10:00]'], 'id = :i', ['i' => (int) $own['id']]);
    $rev0 = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'ticket.regenerated' AND entity_id = :p", ['p' => $own['pnr']], 0);
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'], 'remark' => 'Wants a lower berth']);
    $note = (string) bk((int) $own['id'])['admin_note'];
    check('a remark is APPENDED with who / when (earlier line kept)', str_starts_with($note, 'Seat L99 cancelled: old') && str_contains($note, 'Wants a lower berth — QE Test Counter ['), $note);
    check('a remark alone does not re-issue the ticket', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'ticket.regenerated' AND entity_id = :p", ['p' => $own['pnr']], 0) === $rev0
        && str_contains(flash($h)[1], 'Remark added'));
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $own['id'], 'remark' => 'agent note']);
    check('agent may not add remarks by default', str_contains(flash($h)[1], 'may not change'));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']], ['action' => 'edit_contact', 'admin_note' => 'power panel note']);
    $note2 = (string) bk((int) $own['id'])['admin_note'];
    check('super-admin edit_contact now APPENDS the note (did replace)', str_contains($note2, 'Wants a lower berth') && str_contains($note2, 'power panel note — QE Test Boss'));

    // gender clash in a shared cabin
    $cab = $pairs[1];   // other booking holds bed 0 (Female)
    $g = $sale([$cab[1]], (int) $STAFF['agent2']['id'], [['name' => 'Gita Mate', 'gender' => 'Female', 'age' => 25]], $boardA);
    $gp = (int) $g['passengers'][0]['id'];
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $g['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $g['id'],
        'pax' => [$gp => ['gender' => 'Male']]]);
    check('gender change refused when another booking of the other gender shares the cabin', str_contains(flash($h)[1], 'shares its cabin')
        && Database::scalar('SELECT gender FROM booking_passengers WHERE id = :i', ['i' => $gp]) === 'Female', flash($h)[1]);
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $g['pnr']], ['action' => 'edit_passenger', 'booking_id' => (string) $g['id'],
        'pax' => [$gp => ['age' => '26']]]);
    check('… other fields of that passenger still save', (int) Database::scalar('SELECT age FROM booking_passengers WHERE id = :i', ['i' => $gp], 0) === 26, flash($h)[1]);
    actAs($STAFF['counter']);
    check('genderClash() is false for the same gender', !TicketEdit::genderClash((int) $g['id'], (string) $cab[1], 'Female'));
    actAs(null);

    /* ============================================================== */
    echo "\nH · free-seat filter + Auto Assign\n";
    $av = Seats::availabilityForSchedule($sid, 'sharing');
    $womanBerth = '';
    foreach ($women as $w) { if (in_array($w, $av['available'], true)) { $womanBerth = $w; break; } }
    if ($womanBerth !== '') {
        check('women-only berth hidden for a man, offered to a woman', !TicketEdit::seatOk($av, $womanBerth, 'Male', 'sleeper') && TicketEdit::seatOk($av, $womanBerth, 'Female', 'sleeper'));
    }
    // cabin of $other: Female on bed 0, bed 1 now Gita (Female) - find a cabin with one woman and one free bed
    $sFem = $sale([$pairs[5 + 3][0] ?? $pairs[0][0]], (int) $STAFF['agent2']['id'], [['name' => 'Lone Woman', 'gender' => 'Female', 'age' => 30]], $boardA);
    $mateBed = '';
    foreach (Seats::unitSeats(Seats::unitKey((string) $sFem['seats'][0], 'sleeper'), 'sleeper') as $bed) { if ($bed !== (string) $sFem['seats'][0]) { $mateBed = $bed; } }
    $av = Seats::availabilityForSchedule($sid, 'sharing');
    check('a bed in a cabin held for women is hidden for a man', $mateBed !== '' && in_array($mateBed, $av['available'], true)
        && !TicketEdit::seatOk($av, $mateBed, 'Male', 'sleeper') && TicketEdit::seatOk($av, $mateBed, 'Female', 'sleeper'));

    $paxRows = Database::fetchAll('SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $own['id']]);
    $pf = SmartBook::paxFromRows([['gender' => 'female', 'age' => 8], ['gender' => 'M', 'age' => 66], ['gender' => 'Male', 'age' => 30, 'special_need' => 'patient'], ['gender' => '', 'age' => null]]);
    check('SmartBook::paxFromRows: child / senior / patient / adult bands + gender', $pf === [
        ['gender' => 'Female', 'band' => 'child'], ['gender' => 'Male', 'band' => 'senior'], ['gender' => 'Male', 'band' => 'patient'], ['gender' => '', 'band' => 'adult']]);
    $auto = TicketEdit::autoSeats($sid2, 'sharing', $paxRows, 'any', count($paxRows));
    $av2  = Seats::availabilityForSchedule($sid2, 'sharing');
    check('Auto Assign places the whole party on another bus', $auto['ok'] && count($auto['seats']) === count($paxRows), $auto['why']);
    check('… and the answer passes the same hard-rule check as a hand pick', $auto['ok'] && AutoBerth::checkManual($av2, $auto['seats'], SmartBook::paxFromRows($paxRows), 'sleeper') === '');
    $up = TicketEdit::autoSeats($sid2, 'sharing', [['gender' => 'Male', 'age' => 30]], 'upper', 1);
    $lo = TicketEdit::autoSeats($sid2, 'sharing', [['gender' => 'Male', 'age' => 30]], 'lower', 1);
    check('upper / lower preference is honoured for one adult', $up['ok'] && $lo['ok'] && str_starts_with((string) $up['seats'][0], 'U') && str_starts_with((string) $lo['seats'][0], 'L'),
        json_encode([$up['seats'], $lo['seats']]));
    $pv = TicketEdit::autoSeats($sid2, 'private', [], 'upper', 2);
    check('private booking: whole cabins, requested floor first', $pv['ok'] && count($pv['seats']) === 2 && str_starts_with((string) $pv['seats'][0], 'U'), json_encode($pv));

    // booking-view: the change-seat list and the Auto suggestion
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr'], 'auto_from' => (string) $own['seats'][0], 'pref' => 'upper']);
    check('ticket page: Auto Assign suggests a berth and pre-selects it', preg_match('~<option value="(U\d+)"[^>]*selected~', $h) === 1 && str_contains($h, 'Suggested:'));
    check('ticket page: the women-held bed is not offered to this male passenger', $mateBed === '' || !preg_match('~<option value="' . preg_quote($mateBed, '~') . '" data-for="[^"]*' . preg_quote((string) $own['seats'][0], '~') . '~', $h));

    // change_seat uses the departure's own coach and moves the seat
    $toSeat = '';
    foreach ($av['available'] as $s) { if (TicketEdit::seatOk($av, $s, 'Male', 'sleeper') && !in_array($s, $women, true)) { $toSeat = $s; break; } }
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], ['action' => 'change_seat', 'from_seat' => (string) $own['seats'][1], 'to_seat' => $toSeat, 'reason' => 'test move']);
    check('counter moves a seat through transferSeat', Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b AND seat_no = :s AND released_at IS NULL', ['b' => (int) $own['id'], 's' => $toSeat]), flash($h)[1]);
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']], ['action' => 'change_seat', 'from_seat' => $toSeat, 'to_seat' => (string) $own['seats'][1]]);
    check('agent may not move seats (rule off)', Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b AND seat_no = :s AND released_at IS NULL', ['b' => (int) $own['id'], 's' => $toSeat]));

    /* ============================================================== */
    echo "\nI · reschedule order, new-booking sid, chat preset, gates\n";
    $autoOwn = TicketEdit::autoSeats($sid2, 'sharing', Database::fetchAll('SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $own['id']]), 'any', 2);
    if ($autoOwn['ok']) {
        $order = array_reverse($autoOwn['seats']);   // a deliberate per-passenger order
        $h = drive('reschedule.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']], ['action' => 'reschedule', 'pnr' => (string) $own['pnr'], 'leg' => 'outbound',
            'new_date' => QE_DATE2, 'new_schedule_id' => (string) $sid2, 'seats' => $autoOwn['seats'], 'seat_order' => implode(',', $order), 'reason' => 'auto']);
        $map = Database::fetchAll('SELECT seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $own['id']]);
        check('reschedule: seat_order decides which passenger gets which berth', array_map(static fn($r) => (string) $r['seat_no'], $map) === $order, flash($h)[1]);
    } else {
        check('reschedule auto pick available', false, $autoOwn['why']);
    }
    $h = drive('reschedule.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr'], 'date' => QE_DATE, 'auto' => '1', 'pref' => 'lower']);
    check('reschedule page: Auto Assign pre-ticks the boxes and carries the order', str_contains($h, 'id="rsOrder" value="') && preg_match('~id="rsOrder" value="[LU]\d+~', $h) === 1);

    /* new-booking.php redirects (Response::redirect exits, and the CLI SAPI
       keeps no headers), so its sid guard is checked directly: the same
       query the page runs, plus the source wiring it into the redirect. */
    $nb = (string) file_get_contents(dirname(__DIR__) . '/admin/new-booking.php');
    $sidOk = static fn(int $s, string $d): bool => Database::exists('SELECT 1 FROM schedules WHERE id = :s AND route_id = :r AND travel_date = :d', ['s' => $s, 'r' => (int) $rid, 'd' => $d]);
    check('new-booking forwards a valid sid to the counter checkout', $sidOk((int) $sid, QE_DATE) && str_contains($nb, "\$qs['sid'] = \$sidQ;") && str_contains($nb, "\$qs['cname'] = \$cname;"));
    check('new-booking drops a sid of another date', !$sidOk((int) $sid2, QE_DATE));

    $h = drive('staff-chat.php', $agentId, ['pnr' => (string) $own['pnr'], 'q' => 'q_fare']);
    check('staff-chat ?q=q_fare presets the quick request', str_contains($h, '"quick":"q_fare"') && str_contains($h, "q_fare: 'भाडा"));
    $h = drive('staff-chat.php', $agentId, ['pnr' => (string) $own['pnr'], 'q' => 'q_evil<script>']);
    check('staff-chat ignores an unknown preset', str_contains($h, '"quick":""'));

    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/booking-view.php');
    check('hard delete voids the ticket ONCE (duplicate TicketSync call removed)', substr_count($src, "TicketSync::afterBookingChange(\$bid, 'delete'") === 1);
    check('change_seat uses the departure\'s own coach', str_contains($src, '$coach = Seats::coachForSchedule($sid);'));
    check('reassign gates use Auth::mayReassignSeller (booking-view, seatmap, agent-assign)', str_contains($src, "Auth::mayReassignSeller()")
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/seatmap.php'), 'Auth::mayReassignSeller()')
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/agent-assign.php'), '$canEdit = Auth::mayReassignSeller();'));
    $h = drive('agent-assign.php', (int) $STAFF['manager']['id'], ['from' => QE_DATE, 'to' => QE_DATE]);
    check('Assign Agents: a manager may change agents now', !str_contains($h, 'View only'));
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $own['pnr']], ['action' => 'reassign_agent', 'new_agent_id' => (string) $STAFF['agent2']['id']]);
    check('booking-view: a manager reassigns the seller', (int) bk((int) $own['id'])['sold_by_admin_id'] === (int) $STAFF['agent2']['id'], flash($h)[1]);
    $h = drive('booking-view.php', (int) $STAFF['ops']['id'], ['pnr' => $own['pnr']], ['action' => 'reassign_agent', 'new_agent_id' => (string) $agentId]);
    check('booking-view: operations cannot reassign', (int) bk((int) $own['id'])['sold_by_admin_id'] === (int) $STAFF['agent2']['id'] && flash($h)[0] === 'bad');
    drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']], ['action' => 'reassign_agent', 'new_agent_id' => (string) $agentId]);

    /* ============================================================== */
    echo "\nJ · the page per role\n";
    $h = drive('booking-view.php', $agentId, ['pnr' => $own['pnr']]);
    check('agent: Quick Edit card + sticky action bar render', str_contains($h, 'id="quick"') && str_contains($h, 'class="qe-bar"'));
    check('agent: name box PREFILLED with the current value', preg_match('~name="pax\[' . $p1 . '\]\[name\]"[^>]*value="Ram Corrected"~', $h) === 1);
    check('agent: no gender / pickup / seat / fare boxes (rules)', !str_contains($h, 'name="pax[' . $p1 . '][gender]"') && !str_contains($h, 'id="qe-board"')
        && !str_contains($h, 'id="qe-seat-form"') && !str_contains($h, 'name="new_total"'));
    check('agent: Request correction offered for fare / payment / agent', str_contains($h, 'name="req_field"') && str_contains($h, 'value="fare"'));
    check('agent: internal remarks are not shown', !str_contains($h, 'Wants a lower berth'));
    check('agent: Send ticket on WhatsApp button (no payments.verify)', str_contains($h, 'data-wa-purpose="booking_ticket"') || !Settings::getBool('wa_admin_tools_enabled', true));
    check('agent: Share button', str_contains($h, 'data-share-url='));
    check('agent: "Wrong" chips without the right go to Office chat with a preset', str_contains($h, 'staff-chat.php?pnr=' . $own['pnr'] . '&amp;q=q_seat'));
    $h = drive('booking-view.php', (int) $STAFF['counter']['id'], ['pnr' => $own['pnr']]);
    check('counter: pickup picker of the route\'s stops with "Other…"', str_contains($h, 'id="qe-board"') && str_contains($h, 'value="__other__"'));
    check('counter: seat + payment blocks, no fare block', str_contains($h, 'id="qe-seat-form"') && str_contains($h, 'id="qe-pay"') && !str_contains($h, 'name="new_total"'));
    check('counter: remarks shown read-only', str_contains($h, 'Wants a lower berth'));
    /* 15 Oct 2026 (1G-summary): everyone who may edit bookings sees what
       changed; the IP stays super-admin. The row only carries a new value
       when the save actually changed something, so the expectation is read
       from the register rather than assumed — and the message says what was
       there to show, so a red run names the reason. */
    $vals = Database::fetch(
        "SELECT COUNT(*) n,
                SUM(CASE WHEN new_value IS NOT NULL AND new_value <> '' THEN 1 ELSE 0 END) withNew,
                SUM(CASE WHEN old_value IS NOT NULL AND old_value <> '' THEN 1 ELSE 0 END) withOld
           FROM audit_logs WHERE entity_type = 'booking' AND entity_id = :p",
        ['p' => (string) $own['pnr']]
    ) ?? ['n' => 0, 'withNew' => 0, 'withOld' => 0];
    $hBoss = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']]);
    $why = 'rows=' . (int) $vals['n'] . ' withNew=' . (int) $vals['withNew'] . ' withOld=' . (int) $vals['withOld']
         . ' | counter id=' . (int) $STAFF['counter']['id'] . ' role=' . (string) Database::scalar('SELECT role FROM admins WHERE id = :i', ['i' => (int) $STAFF['counter']['id']], '(none)')
         . ' perms=' . substr((string) Database::scalar('SELECT COALESCE(permissions, "(null)") FROM admins WHERE id = :i', ['i' => (int) $STAFF['counter']['id']], '(none)'), 0, 60)
         . ' | page: audit panel=' . (int) str_contains($h, 'audit-timeline')
         . ' Old=' . (int) str_contains($h, 'Old: ') . ' New=' . (int) str_contains($h, '→ New:')
         . ' | boss page: panel=' . (int) str_contains($hBoss, 'audit-timeline')
         . ' Old=' . (int) str_contains($hBoss, 'Old: ') . ' New=' . (int) str_contains($hBoss, '→ New:');
    check('counter: audit old → new now visible (was super-admin only)',
        (int) $vals['withNew'] > 0 ? str_contains($h, '→ New:') : str_contains($h, 'Old: '), $why);
    $h = drive('booking-view.php', (int) $STAFF['manager']['id'], ['pnr' => $own['pnr']]);
    check('manager: reassign block in Quick Edit, outside the red panel', str_contains($h, 'id="reassign"') && !str_contains($h, 'class="power-panel"'));
    $h = drive('booking-view.php', (int) $STAFF['boss']['id'], ['pnr' => $own['pnr']]);
    check('super-admin: fare block prefilled with the total', preg_match('~name="new_total"[^>]*value="' . preg_quote(number_format((float) bk((int) $own['id'])['total_amount'], 2, '.', ''), '~') . '"~', $h) === 1);
    check('super-admin: + Add passenger (new ticket) carries route, date, sid', str_contains($h, 'new-booking.php?route=' . $rid . '&amp;date=') && str_contains($h, 'sid='));
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    actAs(null);
    try { qe_cleanup($STAFF, $madeBookings); } catch (Throwable $e) { echo "cleanup: " . $e->getMessage() . "\n"; }
    foreach ($origSettings as $sk => $row) {
        if ($row === null) { Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $sk]); continue; }
        Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $sk]);
    }
    Settings::flush();
}

echo "\n  {$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
