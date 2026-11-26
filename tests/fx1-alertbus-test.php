<?php
/**
 * =====================================================================
 *  fx1-alertbus-test.php — the call-style ring (26 Nov 2026, fx1).
 *
 *  includes/alertbus.php + its hooks:
 *    - tables + seeded switches; alert_bus_on = 0 makes everything a no-op
 *    - the journal tail: first use only baselines the cursor; created /
 *      approved / expired / digest rows map to the right alert; a counter
 *      approval and a stale row raise nothing; a second pass adds nothing
 *    - an agent sale carries name + seats + amount and never a phone number
 *    - AdminAlerts::push still returns its id / still dedupes, and hands a
 *      request / SOS to the bus (a website sale is NOT taken from the bell)
 *    - who may see what: role matrix, agent = own tickets, counter = own
 *      desk, viewer nothing, the seller is not rung (alert_ring_self)
 *    - feed, Seen (idempotent, stops it for everyone), the signed token
 *    - web push repeats with a stub sender: once per person per ring, again
 *      only after alert_ring_repeat_min, nothing after Seen, nothing in quiet
 *      hours except SOS, no claim burned while the switch is off, burst merge
 *    - office WhatsApp: 0 by default, once per alert when switched on
 *    - sw.js: old payloads build exactly the old notification; the ring
 *      fields work; nothing outside the two listeners changed
 *    - admin_poll_js / admin_footer hooks, notify-test.php gate, php -l
 *
 *  shari_test only. Every row it makes carries its own marker and is
 *  deleted; every setting and counter is restored. It never runs an
 *  open-ended sweep over other sessions' journal rows.
 *    php -c .claude/php-dev.ini tests/fx1-alertbus-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/admin/_guard.php';      // admin_footer(), admin_poll_js(), and the app
require_once INCLUDE_PATH . '/adminalerts.php';
require_once INCLUDE_PATH . '/alertbus.php';
require_once INCLUDE_PATH . '/webpush.php';

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? ' — ' . $why : '') . "\n"; }
}

echo "\n=== fx1 · alert bus: ring until Seen, role matrix, push repeats ===\n\n";

$ROOT     = dirname(__DIR__);
$tag      = 'fx1ab' . substr((string) time(), -6);
$PNR      = 'SHG-TEST-' . strtoupper($tag);
$PHONE    = '9800000099';
$admins   = [];
$bookings = [];
$journal  = [];          // automation_log ids this test inserted
$bells    = [];          // notifications ids
$claims   = [];          // automation_log dedupe keys this test claimed
$srcKeys  = [];          // alert_events.src_key this test raised directly
$subs     = [];          // push endpoints (marker URLs) this test made
$settings = [];
$state    = [];          // alert_bus_state k => original v (or null)
$rlBefore = null;
$calls    = [];          // stub push calls
$waCalls  = [];
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

$keep = static function (string $key) use (&$settings): void {
    if (!array_key_exists($key, $settings)) {
        $settings[$key] = Database::fetch('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $key]);
    }
};
$set = static function (string $key, $value, string $type) use ($keep): void {
    $keep($key);
    Settings::set($key, $value, $type, 'alerts');
    Settings::flush();
    AlertBus::flush();
};
$blank = static function (string $key) use ($keep): void {          // back to "use the code default"
    $keep($key);
    Database::run("UPDATE settings SET svalue = '' WHERE skey = :k", ['k' => $key]);
    Settings::flush();
    AlertBus::flush();
};
$keepState = static function (string $k) use (&$state): void {
    if (!array_key_exists($k, $state)) {
        $v = Database::scalar('SELECT v FROM alert_bus_state WHERE k = :k', ['k' => $k], null);
        $state[$k] = $v === null ? null : (int) $v;
    }
};
$mkAdmin = static function (string $suffix, string $role, string $desk = '') use (&$admins, $tag): int {
    $id = (int) Database::insert('admins', [
        'username' => $tag . $suffix, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'Fx1 ' . ucfirst($suffix) . ' Tester', 'role' => $role, 'is_active' => 1,
    ]);
    $admins[] = $id;
    if ($desk !== '') {
        Database::insert('admin_profiles', ['admin_id' => $id, 'counter_code' => $desk, 'counter_name' => 'Fx1 desk ' . $desk]);
    }
    return $id;
};
/* $cutter = bookings.booked_by_admin_id: the clerk who cut a desk sale that was then credited to $seller (an agent code) */
$mkBooking = static function (?int $seller, string $source, float $amount, int $seats, string $desk = '', int $cod = 0, ?int $cutter = null) use (&$bookings, $PNR, $PHONE): array {
    $pnr = $PNR . count($bookings);
    $bid = (int) Database::insert('bookings', [
        'pnr' => $pnr, 'contact_phone' => $PHONE, 'contact_email' => '', 'status' => 'pending', 'source' => $source,
        'total_amount' => $amount, 'sold_by_admin_id' => $seller, 'counter_code' => $desk !== '' ? $desk : null, 'is_cod' => $cod,
    ] + ($cutter !== null ? ['booked_by_admin_id' => $cutter] : []));
    $bookings[] = $bid;
    for ($i = 1; $i <= $seats; $i++) {
        Database::insert('booking_passengers', [
            'booking_id' => $bid, 'seat_no' => 'F' . $i, 'full_name' => 'Fx1 Secret Passenger', 'passenger_ref' => generatePassengerRef(),
        ]);
    }
    return ['id' => $bid, 'pnr' => $pnr];
};
/** One journal row exactly as EventBus::journal() writes it. */
$mkJournal = static function (string $event, array $data, ?string $dedupe = null) use (&$journal): int {
    $id = (int) Database::insert('automation_log', [
        'event' => $event, 'dedupe_key' => $dedupe, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'status' => 'ok',
    ]);
    $journal[] = $id;
    return $id;
};
$sweepOne = static fn(int $id): array => AlertBus::sweep($id - 1, $id);      // exactly this row, never a neighbour's
$alert = static fn(string $src): ?array => Database::fetch('SELECT * FROM alert_events WHERE src_key = :k', ['k' => $src]);
$raise = static function (string $event, array $ctx, string $key, ?int $now = null) use (&$srcKeys): int {
    $srcKeys[] = $key;
    return AlertBus::raise($event, $ctx, $key, $now);
};
function actAs(?int $id, string $role = 'superadmin'): void
{
    if ($id === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => 'fx1', 'role' => $role, 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
}

try {
    /* ---------------- 0. tables, seeds, a clean slate of switches ---------------- */
    check('migration: alert_events / alert_deliveries / alert_bus_state exist', AlertBus::ready());
    $seeded = (int) Database::scalar("SELECT COUNT(*) FROM settings WHERE skey IN ('alert_bus_on','alert_ring_on','alert_ring_seconds','alert_ring_repeat_min',
        'alert_ring_repeat_max','alert_ring_burst','alert_ring_self','alert_push_on','alert_wa_on','alert_matrix','alert_role_prefs')", [], 0);
    check('migration: the 11 switches are seeded', $seeded === 11, (string) $seeded);
    $pub = (int) Database::scalar("SELECT COUNT(*) FROM settings WHERE skey LIKE 'alert\\_%' AND sgroup = 'alerts' AND is_public = 1", [], 0);
    check('…none of them is public', $pub === 0, (string) $pub);
    $sql = (string) file_get_contents($ROOT . '/database/upgrade-2026-11-26-fx1-alertbus.sql');
    check('…the migration ships WhatsApp OFF, is additive only (no DROP / DELETE / TRUNCATE / plain INSERT)', str_contains($sql, "('alert_wa_on',           '0',")
        && preg_match('/\b(DROP|DELETE|TRUNCATE|ALTER)\b/i', (string) preg_replace('/^--.*$/m', '', $sql)) === 0
        && substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4 && substr_count($sql, 'INSERT IGNORE') === 1 && !preg_match('/INSERT\s+INTO/i', $sql));
    check('migration: alert_push_optin (the phones that tapped "Ring on this phone") exists', (static function (): bool {
        try { Database::query('SELECT endpoint_hash, admin_id, pw, created_at FROM alert_push_optin LIMIT 0'); return true; } catch (Throwable $e) { return false; }
    })());

    if (!AlertBus::ready()) {
        throw new RuntimeException('apply database/upgrade-2026-11-26-fx1-alertbus.sql to shari_test first');
    }
    foreach (['alert_bus_on' => [true, 'bool'], 'alert_ring_on' => [true, 'bool'], 'alert_push_on' => [true, 'bool'], 'alert_wa_on' => [false, 'bool'],
              'alert_ring_self' => [false, 'bool'], 'alert_ring_seconds' => [60, 'int'], 'alert_ring_repeat_min' => [2, 'int'],
              'alert_ring_repeat_max' => [3, 'int'], 'alert_ring_burst' => [6, 'int'], 'push_enabled' => [true, 'bool'],
              'counter_desk_isolation' => [false, 'bool']] as $k => [$v, $t]) {
        $set($k, $v, $t);
    }
    $blank('alert_matrix');
    $blank('alert_role_prefs');
    $rlBefore = Database::fetch("SELECT * FROM rate_limits WHERE bucket = 'alert_wa' AND identifier = 'office'");
    AlertBus::$pusher = static function (int $adminId, array $payload, string $event, int $ttl, string $urgency, string $topic) use (&$calls): array {
        $calls[] = compact('adminId', 'payload', 'event', 'ttl', 'urgency', 'topic');
        return ['sent' => 1, 'failed' => 0, 'skipped' => 0];
    };
    AlertBus::$waSender = static function (string $headline, array $facts, ?int $bookingId) use (&$waCalls): void {
        $waCalls[] = compact('headline', 'facts', 'bookingId');
    };

    $cfg = AlertBus::cfg();
    check('cfg(): 60 s ring, again every 120 s, 3 repeats, window 420 s', $cfg['seconds'] === 60 && $cfg['repeat'] === 120 && $cfg['max'] === 3 && $cfg['window'] === 420, json_encode($cfg));

    $MGR = $mkAdmin('mgr', 'manager');
    $SUP = $mkAdmin('sup', 'support');
    $ACC = $mkAdmin('acc', 'accountant');
    $VIW = $mkAdmin('viw', 'viewer');
    $AGA = $mkAdmin('aga', 'agent');
    $AGB = $mkAdmin('agb', 'agent');
    $C1  = $mkAdmin('ca1', 'counter', 'FXA');
    $C1B = $mkAdmin('ca2', 'counter', 'FXA');
    $C2  = $mkAdmin('cb1', 'counter', 'FXB');
    $who = static fn(int $id, string $role): array => ['id' => $id, 'role' => $role];

    $bOnline = $mkBooking(null, 'web', 1800.0, 1);
    $bAgent  = $mkBooking($AGA, 'agent', 3600.0, 2);
    $bDesk   = $mkBooking($C1, 'counter', 2200.0, 1, 'FXA', 1);

    /* ---------------- 1. the master switch ---------------- */
    $set('alert_bus_on', false, 'bool');
    $jOff = $mkJournal('booking.created', ['booking.id' => $bOnline['id'], 'booking.pnr' => $bOnline['pnr']]);
    check('bus off: on() is false', !AlertBus::on());
    check('bus off: raise stores nothing', $raise('new_booking', ['title' => 'x'], $tag . ':off') === 0 && $alert($tag . ':off') === null);
    $r = $sweepOne($jOff);
    check('bus off: sweep reads nothing', !empty($r['off']) && $r['raised'] === 0 && $alert('j:' . $jOff) === null);
    check('bus off: fromBell stores nothing', AlertBus::fromBell('driver_sos', 999999999, 't', 'b') === 0);
    $f = AlertBus::feed($who($MGR, 'manager'));
    check('bus off: the feed says off and is empty', $f['on'] === false && $f['rings'] === [] && $f['soft'] === []);
    check('bus off: deliver sends nothing', AlertBus::deliver(50)['push'] === 0 && $calls === []);
    $set('alert_bus_on', true, 'bool');

    /* ---------------- 2. first use only sets the cursor ---------------- */
    $keepState('journal');
    Database::run("DELETE FROM alert_bus_state WHERE k = 'journal'");
    $before = (int) Database::scalar('SELECT COUNT(*) FROM alert_events', [], 0);
    $topBefore = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM automation_log', [], 0);
    $r = AlertBus::sweep();
    $cur = (int) Database::scalar("SELECT v FROM alert_bus_state WHERE k = 'journal'", [], -1);
    check('first sweep: baseline only - the cursor jumps to the newest journal id, nothing is raised',
        !empty($r['baseline']) && $r['raised'] === 0 && $cur >= $topBefore && $alert('j:' . $jOff) === null, json_encode($r) . ' cur ' . $cur);
    check('…and no alert row appeared for old journal rows', (int) Database::scalar('SELECT COUNT(*) FROM alert_events', [], 0) === $before);
    // put the cursor back exactly as it was for whoever else uses this database
    if ($state['journal'] === null) { Database::run("DELETE FROM alert_bus_state WHERE k = 'journal'"); }
    else { Database::run("UPDATE alert_bus_state SET v = :v WHERE k = 'journal'", ['v' => $state['journal']]); }

    /* ---------------- 3. journal rows -> alerts ---------------- */
    $j1 = $mkJournal('booking.created', ['booking.id' => $bOnline['id'], 'booking.pnr' => $bOnline['pnr'], 'booking.status' => 'pending', 'booking.total_amount' => '1800.00']);
    $j2 = $mkJournal('booking.created', ['booking.id' => $bAgent['id'], 'booking.pnr' => $bAgent['pnr'], 'booking.sold_by_admin_id' => $AGA, 'booking.total_amount' => '3600.00']);
    $j3 = $mkJournal('booking.created', ['booking.id' => $bDesk['id'], 'booking.pnr' => $bDesk['pnr'], 'booking.sold_by_admin_id' => $C1]);
    $j4 = $mkJournal('booking.approved', ['booking.id' => $bDesk['id'], 'booking.pnr' => $bDesk['pnr'], 'via' => 'counter']);
    $j5 = $mkJournal('booking.approved', ['booking.id' => $bAgent['id'], 'booking.pnr' => $bAgent['pnr'], 'booking.sold_by_admin_id' => $AGA, 'via' => 'admin']);
    $j6 = $mkJournal('booking.expired', ['booking.id' => $bOnline['id'], 'booking.pnr' => $bOnline['pnr']]);
    $j7 = $mkJournal('cron.admin_digest', [], 'admin-digest:' . $tag . ':2001-02-03');
    $j8 = $mkJournal('booking.created', ['booking.id' => $bOnline['id'], 'booking.pnr' => $bOnline['pnr']]);
    Database::run('UPDATE automation_log SET created_at = NOW() - INTERVAL 2 HOUR WHERE id = :i', ['i' => $j8]);
    $j9 = $mkJournal('booking.payment_uploaded', ['booking.id' => $bOnline['id']]);
    $raised = 0;
    foreach ([$j1, $j2, $j3, $j4, $j5, $j6, $j7, $j8, $j9] as $j) { $raised += $sweepOne($j)['raised']; }
    check('sweep: 9 journal rows -> 6 alerts', $raised === 6, (string) $raised);

    $a1 = $alert('j:' . $j1);
    check('website / WhatsApp sale -> new_booking, rings, links the ticket', $a1 !== null && $a1['event'] === 'new_booking' && (int) $a1['ring'] === 1
        && $a1['link'] === '/admin/booking-view.php?pnr=' . rawurlencode($bOnline['pnr']) && str_contains((string) $a1['title'], $bOnline['pnr'])
        && str_contains((string) $a1['title'], 'नयाँ टिकट') && $a1['ring_until'] !== null && $a1['actor_admin_id'] === null);
    check('…ring_until = created + 420 s', $a1 !== null && strtotime((string) $a1['ring_until']) - strtotime((string) $a1['created_at']) === 420);
    check('…body: seats + amount', $a1 !== null && str_contains((string) $a1['body'], '1 सिट') && str_contains((string) $a1['body'], '1,800'), (string) ($a1['body'] ?? ''));

    $a2 = $alert('j:' . $j2);
    check('agent sale -> agent_ticket with name + seats + amount', $a2 !== null && $a2['event'] === 'agent_ticket' && (int) $a2['ring'] === 1
        && (int) $a2['actor_admin_id'] === $AGA && str_contains((string) $a2['body'], 'Fx1 Aga Tester') && str_contains((string) $a2['body'], '2 सिट (F1, F2)')
        && str_contains((string) $a2['body'], '3,600'), (string) ($a2['body'] ?? ''));
    $text = json_encode([$a1, $a2], JSON_UNESCAPED_UNICODE);
    check('…no customer phone number or passenger name anywhere in the alert', !str_contains((string) $text, $PHONE) && !str_contains((string) $text, 'Secret Passenger'));

    $a3 = $alert('j:' . $j3);
    check('counter sale -> desk_ticket, soft (no ring), carries the desk; pay-at-boarding is said, no pay-QR wording',
        $a3 !== null && $a3['event'] === 'desk_ticket' && (int) $a3['ring'] === 0 && $a3['desk_code'] === 'FXA' && $a3['ring_until'] === null
        && str_contains((string) $a3['body'], 'pay at boarding') && stripos((string) $a3['body'], 'qr') === false, (string) ($a3['body'] ?? ''));
    check('a counter approval of its own sale raises nothing (the sale already told the office)', $alert('j:' . $j4) === null);
    $a5 = $alert('j:' . $j5);
    check('office approval -> payment_confirmed for the seller\'s ticket', $a5 !== null && $a5['event'] === 'payment_confirmed' && (int) $a5['actor_admin_id'] === $AGA && (int) $a5['ring'] === 0);
    $a6 = $alert('j:' . $j6);
    check('hold released -> hold_expired', $a6 !== null && $a6['event'] === 'hold_expired' && str_contains((string) $a6['title'], $bOnline['pnr']));
    $a7 = $alert('j:' . $j7);
    check('the digest claim row -> report_ready with its day', $a7 !== null && $a7['event'] === 'report_ready' && str_contains((string) $a7['body'], '2001-02-03'));
    check('a 2-hour-old journal row raises nothing (stale)', $alert('j:' . $j8) === null);
    check('an event the bus does not listen to raises nothing', $alert('j:' . $j9) === null);

    $again = 0;
    foreach ([$j1, $j2, $j3, $j5, $j6, $j7] as $j) { $again += $sweepOne($j)['raised']; }
    $mine = (int) Database::scalar('SELECT COUNT(*) FROM alert_events WHERE src_key IN (' . implode(',', array_map(static fn(int $j): string => "'j:" . $j . "'", $journal)) . ')', [], 0);
    check('a second pass over the same rows adds nothing', $again === 0 && $mine === 6, $again . ' / ' . $mine);
    /* A desk sale is re-credited to an agent code BEFORE the journal row is written
       (BookingService::attributeDeskSale): sold_by = the agent, booked_by = the clerk who cut it. */
    if (!CounterDesk::hasColumn('bookings', 'booked_by_admin_id')) {
        check('desk sale credited to an agent (skipped: bookings.booked_by_admin_id is not on this database)', true);
    } else {
        $bCred  = $mkBooking($AGB, 'counter', 2500.0, 1, 'FXA', 0, $C1);       // counter C1 cut it, agent B's code got the credit
        $bCred2 = $mkBooking($AGA, 'admin', 2100.0, 1, '', 0, $MGR);           // the manager cut it at the office, agent A's code
        $bSelf  = $mkBooking($AGA, 'agent', 1900.0, 1, '', 0, $AGA);           // an agent's own sale: cutter = seller
        $jc1 = $mkJournal('booking.created', ['booking.id' => $bCred['id'], 'booking.pnr' => $bCred['pnr'], 'booking.sold_by_admin_id' => $AGB]);
        $jc2 = $mkJournal('booking.created', ['booking.id' => $bCred2['id'], 'booking.pnr' => $bCred2['pnr'], 'booking.sold_by_admin_id' => $AGA]);
        $jc3 = $mkJournal('booking.created', ['booking.id' => $bSelf['id'], 'booking.pnr' => $bSelf['pnr'], 'booking.sold_by_admin_id' => $AGA]);
        $jc4 = $mkJournal('booking.approved', ['booking.id' => $bCred['id'], 'booking.pnr' => $bCred['pnr'], 'booking.sold_by_admin_id' => $AGB, 'via' => 'admin']);
        foreach ([$jc1, $jc2, $jc3, $jc4] as $j) { $sweepOne($j); }
        $d1 = $alert('j:' . $jc1);
        $d2 = $alert('j:' . $jc2);
        $d3 = $alert('j:' . $jc3);
        $d4 = $alert('j:' . $jc4);
        check('a counter sale credited to an agent code is still a desk ticket: soft, no call, names the CLERK, never the credited agent',
            $d1 !== null && $d1['event'] === 'desk_ticket' && (int) $d1['ring'] === 0 && (int) $d1['actor_admin_id'] === $C1 && $d1['desk_code'] === 'FXA'
            && str_contains((string) $d1['body'], 'Fx1 Ca1 Tester') && !str_contains((string) $d1['body'], 'Fx1 Agb Tester'), json_encode($d1, JSON_UNESCAPED_UNICODE));
        check('…the clerk who cut it is not told about it; the other clerk of that desk and the office are; another desk and the credited agent are not',
            $d1 !== null && !AlertBus::visibleTo($who($C1, 'counter'), $d1) && AlertBus::visibleTo($who($C1B, 'counter'), $d1) && AlertBus::visibleTo($who($MGR, 'manager'), $d1)
            && !AlertBus::visibleTo($who($C2, 'counter'), $d1) && !AlertBus::visibleTo($who($AGB, 'agent'), $d1));
        check('an office sale credited to an agent code: desk ticket by the manager - the manager is not rung for their own sale',
            $d2 !== null && $d2['event'] === 'desk_ticket' && (int) $d2['ring'] === 0 && (int) $d2['actor_admin_id'] === $MGR
            && str_contains((string) $d2['body'], 'Fx1 Mgr Tester') && !AlertBus::visibleTo($who($MGR, 'manager'), $d2) && AlertBus::visibleTo($who(1, 'superadmin'), $d2));
        check('an agent\'s own sale (cutter = seller) is still an agent ticket that rings', $d3 !== null && $d3['event'] === 'agent_ticket' && (int) $d3['ring'] === 1
            && (int) $d3['actor_admin_id'] === $AGA);
        check('a later payment on the credited sale still belongs to the credited agent (and to the desk)', $d4 !== null && $d4['event'] === 'payment_confirmed'
            && (int) $d4['actor_admin_id'] === $AGB && AlertBus::visibleTo($who($AGB, 'agent'), $d4) && AlertBus::visibleTo($who($C1, 'counter'), $d4));
        AlertBus::ack((int) $d3['id'], $who(1, 'superadmin'));          // do not leave it ringing for the sections below
    }

    $none = true;
    foreach (['parcel', 'पार्सल', 'refunded', 'cancelled'] as $w) {
        foreach ([$a1, $a2, $a3, $a5, $a6, $a7] as $a) { $none = $none && stripos((string) $a['title'] . ' ' . (string) $a['body'], $w) === false; }
    }
    check('wording: no parcel, no "cancelled / refunded" in any alert', $none);

    /* ---------------- 4. the bell hook ---------------- */
    $bells[] = $n1 = AdminAlerts::push('ticket_request', 'Date change request · ' . $bAgent['pnr'], 'Wants 3 Dec — call ' . $PHONE . ' · #77', '/admin/bookings.php', '📝');
    $c1 = $alert('bell:' . $n1);
    check('AdminAlerts::push still returns the notification id', $n1 > 0 && (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE id = :i', ['i' => $n1]) === 1);
    check('a date-change request -> change_request, worded as a REQUEST, scoped to the seller', $c1 !== null && $c1['event'] === 'change_request'
        && str_contains((string) $c1['title'], 'अनुरोध') && (int) $c1['actor_admin_id'] === $AGA && (int) $c1['booking_id'] === $bAgent['id'] && (int) $c1['ring'] === 0);
    check('…a phone number typed into the note is hidden', $c1 !== null && !str_contains((string) $c1['body'], $PHONE) && str_contains((string) $c1['body'], $bAgent['pnr']), (string) ($c1['body'] ?? ''));
    $claims[] = 'alert:' . $tag . ':dd';
    $bells[] = $n2 = AdminAlerts::push('driver_sos', '🆘 SOS — Bus 03', 'Near Kohalpur', '/admin/map.php', '🆘', $tag . ':dd');
    $n3 = AdminAlerts::push('driver_sos', '🆘 SOS — Bus 03', 'Near Kohalpur', '/admin/map.php', '🆘', $tag . ':dd');
    $c2 = $alert('bell:' . $n2);
    check('AdminAlerts::push still dedupes (same key -> 0, one bell, one alert)', $n2 > 0 && $n3 === 0);
    check('a driver SOS -> driver_sos, rings', $c2 !== null && $c2['event'] === 'driver_sos' && (int) $c2['ring'] === 1 && $c2['link'] === '/admin/map.php');
    $bells[] = $n4 = AdminAlerts::push('online_booking', '🎉 New online customer · ' . $bOnline['pnr'], 'Booked on the website', '/admin/bookings.php', '🎉');
    check('a website sale is NOT taken from the bell (the journal has it - no double ring)', $n4 > 0 && $alert('bell:' . $n4) === null);
    $bells[] = $n5 = AdminAlerts::push('fake_scan', 'Suspicious ticket', 'x', '/admin/scan.php', '🛡️');
    check('other bell kinds stay bell-only', $n5 > 0 && $alert('bell:' . $n5) === null);

    /* ---------------- 5. who may see what ---------------- */
    $vis = static fn(array $w, ?array $row): bool => $row !== null && AlertBus::visibleTo($w, $row);
    check('everyone who heard the old chime still gets a new ticket (manager, accountant, counter) + support; a viewer never',
        $vis($who($MGR, 'manager'), $a1) && $vis($who($SUP, 'support'), $a1) && $vis($who($ACC, 'accountant'), $a1) && $vis($who($C2, 'counter'), $a1)
        && !$vis($who($VIW, 'viewer'), $a1));
    $set('counter_desk_isolation', true, 'bool');
    check('…with counter_desk_isolation on, a counter gets its own desk only (no online tickets)', !$vis($who($C2, 'counter'), $a1) && $vis($who($C1B, 'counter'), $a3));
    $set('counter_desk_isolation', false, 'bool');
    check('a counter never gets another desk\'s or an agent\'s ticket, whatever the matrix says', !$vis($who($C2, 'counter'), $a3) && !$vis($who($C2, 'counter'), $a2) && !$vis($who($C2, 'counter'), $a5));
    check('accountant sees payment_confirmed, support does not', $vis($who($ACC, 'accountant'), $a5) && !$vis($who($SUP, 'support'), $a5));
    check('agent: own ticket\'s payment + request yes, another agent\'s never', $vis($who($AGA, 'agent'), $a5) && $vis($who($AGA, 'agent'), $c1)
        && !$vis($who($AGB, 'agent'), $a5) && !$vis($who($AGB, 'agent'), $c1));
    check('agent: never the office\'s new-ticket ring, never another seller\'s sale', !$vis($who($AGA, 'agent'), $a1) && !$vis($who($AGB, 'agent'), $a2));
    check('counter: its own desk yes, another desk no', $vis($who($C1B, 'counter'), $a3) && !$vis($who($C2, 'counter'), $a3));
    check('the seller is not told about the ticket they just cut', !$vis($who($C1, 'counter'), $a3));
    $set('alert_ring_self', true, 'bool');
    check('…unless alert_ring_self is on', $vis($who($C1, 'counter'), $a3));
    $set('alert_ring_self', false, 'bool');
    check('a superadmin sees everything', $vis($who(1, 'superadmin'), $a1) && $vis($who(1, 'superadmin'), $a5) && $vis($who(1, 'superadmin'), $c2));

    $keep('alert_matrix');
    $in = [];
    foreach (AlertBus::EVENTS as $ev => $def) { $in[$ev] = ['roles' => array_fill_keys($def[5], '1'), 'ring' => $def[4] ? '1' : '']; }
    $in['new_booking'] = ['roles' => ['accountant' => '1', 'hacker' => '1', 'viewer' => '1'], 'ring' => ''];
    $in['no_such_event'] = ['roles' => ['manager' => '1'], 'ring' => '1'];
    $saved = AlertBus::saveMatrix($in);
    $m = AlertBus::matrix();
    check('saveMatrix: whitelist - unknown roles / events dropped, viewer can never be added', $saved['new_booking']['roles'] === ['accountant']
        && !isset($saved['no_such_event']) && $m['new_booking']['roles'] === ['accountant'] && $m['new_booking']['ring'] === false);
    check('…the matrix now decides: accountant yes, manager no, the owner still yes', $vis($who($ACC, 'accountant'), $a1) && !$vis($who($MGR, 'manager'), $a1)
        && $vis($who(1, 'superadmin'), $a1));
    check('…other rows kept their defaults', $m['driver_sos']['ring'] === true && in_array('support', $m['driver_sos']['roles'], true));
    $blank('alert_matrix');
    check('an empty matrix setting = the code defaults', AlertBus::matrix()['new_booking']['roles'] === AlertBus::EVENTS['new_booking'][5] && AlertBus::matrix()['new_booking']['ring'] === true);

    /* ---------------- 6. the panel feed + Seen ---------------- */
    $now = time();
    $f = AlertBus::feed($who($MGR, 'manager'), $now);
    $ringIds = array_column($f['rings'], 'id');
    check('feed (manager): on, ring on, the numbers, my two open rings', $f['on'] === true && $f['ring'] === true && $f['me'] === $MGR && $f['seconds'] === 60 && $f['repeat'] === 120
        && $f['max'] === 3 && in_array((int) $a1['id'], $ringIds, true) && in_array((int) $a2['id'], $ringIds, true) && $f['ringCount'] >= 2);
    $item = null;
    foreach ($f['rings'] as $it) { if ($it['id'] === (int) $a2['id']) { $item = $it; } }
    check('…a ring item: title, body, link, sound, start time, the sale channel read now', $item !== null && $item['sound'] === 'ring' && $item['link'] !== ''
        && abs($item['at'] - $now) < 30 && $item['channel'] !== '' && $item['loud'] === false && $item['test'] === false, json_encode($item, JSON_UNESCAPED_UNICODE));
    check('…the feed carries no phone number', !str_contains((string) json_encode($f, JSON_UNESCAPED_UNICODE), $PHONE));
    $fa = AlertBus::feed($who($AGA, 'agent'), $now);
    $softIds = array_column($fa['soft'], 'id');
    check('feed (agent A): no office ring, own payment + request as soft alerts', !in_array((int) $a1['id'], array_column($fa['rings'], 'id'), true)
        && in_array((int) $a5['id'], $softIds, true) && in_array((int) $c1['id'], $softIds, true));
    $fb = AlertBus::feed($who($AGB, 'agent'), $now);
    check('feed (agent B): none of agent A\'s alerts', array_intersect([(int) $a2['id'], (int) $a5['id'], (int) $c1['id']], array_merge(array_column($fb['rings'], 'id'), array_column($fb['soft'], 'id'))) === []);
    $set('alert_ring_on', false, 'bool');
    $f0 = AlertBus::feed($who($MGR, 'manager'), $now);
    check('alert_ring_on = 0: the feed carries no rings (the old chime takes over)', $f0['ring'] === false && $f0['rings'] === [] && $f0['on'] === true);
    $set('alert_ring_on', true, 'bool');

    check('Seen: a viewer is refused', AlertBus::ack((int) $a1['id'], $who($VIW, 'viewer'))['ok'] === false);
    check('Seen: an agent cannot silence the office ring', (AlertBus::ack((int) $a1['id'], $who($AGA, 'agent'))['why'] ?? '') === 'not yours');
    $k1 = AlertBus::ack((int) $a1['id'], $who($MGR, 'manager'), 'panel');
    $k2 = AlertBus::ack((int) $a1['id'], $who($SUP, 'support'), 'panel');
    $row = $alert('j:' . $j1);
    check('Seen: the first tap wins, the second is a quiet success', $k1 === ['ok' => true, 'first' => true] && $k2 === ['ok' => true, 'first' => false]
        && (int) $row['acked_by'] === $MGR && $row['ack_via'] === 'panel' && $row['acked_at'] !== null);
    $fs = AlertBus::feed($who($SUP, 'support'), $now);
    check('…and it stopped for everyone: support\'s feed no longer has it', !in_array((int) $a1['id'], array_column($fs['rings'], 'id'), true)
        && in_array((int) $a2['id'], array_column($fs['rings'], 'id'), true));
    check('Seen: an unknown alert is a clean "gone"', (AlertBus::ack(999999999, $who($MGR, 'manager'))['why'] ?? '') === 'gone');

    /* Seen by an agent / a counter must never silence the OFFICE's alert about their own ticket or account */
    $kSoft = AlertBus::ack((int) $c1['id'], $who($AGA, 'agent'));
    check('Seen on a soft (non-ringing) alert writes nothing - an agent cannot mark the office bell row read', $kSoft['ok'] === true && $kSoft['first'] === false
        && $alert('bell:' . $n1)['acked_at'] === null && (int) Database::scalar('SELECT is_read FROM notifications WHERE id = :i', ['i' => $n1]) === 0, json_encode($kSoft));
    $bells[] = $nReq = (int) Database::insert('notifications', ['audience' => 'admin', 'icon' => '📝', 'title' => 'Cancel request · ' . $bAgent['pnr'], 'body' => $tag, 'link' => '/admin/bookings.php']);
    $pr1 = AlertBus::raise('change_request', ['title' => '📝 request ' . $tag, 'body' => 'b', 'actor' => $AGA, 'desk' => 'FXA', 'ring' => true], 'bell:' . $nReq, $now);
    $inRings = static fn(array $w, int $id): bool => in_array($id, array_column(AlertBus::feed($w, time())['rings'], 'id'), true);
    check('a ringing cancel request: the agent whose ticket it is, the desk and the office all hear it', $pr1 > 0 && $inRings($who($AGA, 'agent'), $pr1)
        && $inRings($who($C1, 'counter'), $pr1) && $inRings($who($MGR, 'manager'), $pr1) && !$inRings($who($AGB, 'agent'), $pr1));
    $kA  = AlertBus::ack($pr1, $who($AGA, 'agent'));
    $kA2 = AlertBus::ack($pr1, $who($AGA, 'agent'));
    $kC  = AlertBus::ack($pr1, $who($C1, 'counter'));
    $rowP = $alert('bell:' . $nReq);
    check('Seen by the AGENT is personal: quiet for the agent, acked_at stays empty, the office still hears it', $kA === ['ok' => true, 'first' => true, 'personal' => true]
        && $kA2 === ['ok' => true, 'first' => false, 'personal' => true] && $rowP['acked_at'] === null && $rowP['acked_by'] === null
        && !$inRings($who($AGA, 'agent'), $pr1) && $inRings($who($MGR, 'manager'), $pr1) && $inRings($who(1, 'superadmin'), $pr1), json_encode([$kA, $kA2]));
    check('Seen by a COUNTER on a request is personal too - and the office bell row stays unread', ($kC['personal'] ?? false) === true && !$inRings($who($C1, 'counter'), $pr1)
        && $inRings($who($SUP, 'support'), $pr1) && (int) Database::scalar('SELECT is_read FROM notifications WHERE id = :i', ['i' => $nReq]) === 0);
    $calls = [];
    AlertBus::$recipients = [['id' => $AGA, 'role' => 'agent', 'full_name' => 'a'], ['id' => $MGR, 'role' => 'manager', 'full_name' => 'm']];
    AlertBus::deliver(200, $now + 1, $pr1);
    $gotP = array_column(array_values(array_filter($calls, static fn(array $c): bool => (int) ($c['payload']['alertId'] ?? 0) === $pr1)), 'adminId');
    AlertBus::$recipients = null;
    $calls = [];
    check('…after a personal Seen that person\'s phone is not rung again, the manager\'s still is', $gotP === [$MGR], json_encode($gotP));
    check('Seen all by the agent: nothing of the office\'s is closed', AlertBus::ackAll($who($AGA, 'agent')) === 0 && $alert('bell:' . $nReq)['acked_at'] === null
        && $alert('j:' . $j2)['acked_at'] === null);
    $kM = AlertBus::ack($pr1, $who($MGR, 'manager'));
    check('Seen by the office closes it for everybody and marks the bell row read', $kM === ['ok' => true, 'first' => true] && (int) $alert('bell:' . $nReq)['acked_by'] === $MGR
        && (int) Database::scalar('SELECT is_read FROM notifications WHERE id = :i', ['i' => $nReq]) === 1 && !$inRings($who(1, 'superadmin'), $pr1));
    $nb1 = $raise('new_booking', ['title' => '🎫 counter seen ' . $tag, 'body' => 'b'], $tag . ':nb1', $now);
    $cr1 = $raise('agent_credit_low', ['title' => '💳 credit ' . $tag, 'body' => 'b', 'actor' => $AGA, 'ring' => true], $tag . ':cr1', $now);
    $kN  = AlertBus::ack($nb1, $who($C2, 'counter'));
    $kCr = AlertBus::ack($cr1, $who($AGA, 'agent'));
    check('a counter\'s Seen on a NEW TICKET still answers for the office (it serves that customer)', $kN === ['ok' => true, 'first' => true] && (int) $alert($tag . ':nb1')['acked_by'] === $C2);
    check('an agent cannot silence the office\'s warning about their own credit', ($kCr['personal'] ?? false) === true && $alert($tag . ':cr1')['acked_at'] === null
        && $inRings($who($ACC, 'accountant'), $cr1) && !$inRings($who($AGA, 'agent'), $cr1));
    check('answersForAll(): office roles yes; agent never; counter only for a sale; a test sample yes', AlertBus::answersForAll($who($SUP, 'support'), ['event' => 'driver_sos', 'src_key' => 'x'])
        && !AlertBus::answersForAll($who($AGA, 'agent'), ['event' => 'payment_confirmed', 'src_key' => 'x']) && AlertBus::answersForAll($who($C1, 'counter'), ['event' => 'desk_ticket', 'src_key' => 'x'])
        && !AlertBus::answersForAll($who($C1, 'counter'), ['event' => 'driver_sos', 'src_key' => 'x']) && AlertBus::answersForAll($who($AGA, 'agent'), ['event' => 'new_booking', 'src_key' => 'test:1:ab']));
    AlertBus::ack($cr1, $who(1, 'superadmin'));

    $tok = AlertBus::ackToken((int) $c2['id'], $SUP);
    check('token: 32 chars, right alert + person only', strlen($tok) === 32 && AlertBus::checkAckToken((int) $c2['id'], $SUP, $tok)
        && !AlertBus::checkAckToken((int) $c2['id'], $MGR, $tok) && !AlertBus::checkAckToken((int) $a2['id'], $SUP, $tok)
        && !AlertBus::checkAckToken((int) $c2['id'], $SUP, str_repeat('0', 32)) && !AlertBus::checkAckToken((int) $c2['id'], $SUP, ''));
    $sup = AlertBus::adminById($SUP);
    $k3 = AlertBus::ack((int) $c2['id'], ['id' => (int) $sup['id'], 'role' => (string) $sup['role']], 'push');
    check('Seen from the phone: the SOS is acked and its bell row is marked read', $k3['first'] === true && $alert('bell:' . $n2)['ack_via'] === 'push'
        && (int) Database::scalar('SELECT is_read FROM notifications WHERE id = :i', ['i' => $n2]) === 1);
    check('adminById: an inactive / unknown account is nobody', AlertBus::adminById(999999999) === null);

    /* ---------------- 7. phones: push repeats (stub sender) ---------------- */
    AlertBus::$recipients = [
        ['id' => $MGR, 'role' => 'manager', 'full_name' => 'm'], ['id' => $SUP, 'role' => 'support', 'full_name' => 's'],
        ['id' => $AGA, 'role' => 'agent', 'full_name' => 'a'], ['id' => $VIW, 'role' => 'viewer', 'full_name' => 'v'],
    ];
    $t0  = time();
    $e1  = $raise('new_booking', ['title' => '🎫 नयाँ टिकट आयो · New ticket · ' . $PNR . 'X', 'body' => '1 सिट · ₹1,800', 'link' => '/admin/booking-view.php?pnr=' . $PNR . 'X'], $tag . ':e1', $t0);
    $rowE1 = $alert($tag . ':e1');
    $dl = static fn(int $id): int => (int) Database::scalar('SELECT COUNT(*) FROM alert_deliveries WHERE alert_id = :a', ['a' => $id], 0);
    $for = static function (int $id) use (&$calls): array {
        return array_values(array_filter($calls, static fn(array $c): bool => (int) ($c['payload']['alertId'] ?? 0) === $id));
    };

    $calls = [];
    $set('alert_push_on', false, 'bool');
    AlertBus::deliver(200, $t0 + 1, $e1);
    check('push switch off: nothing sent and NO claim burned', $calls === [] && $dl($e1) === 0);
    $set('alert_push_on', true, 'bool');
    $set('push_enabled', false, 'bool');
    AlertBus::deliver(200, $t0 + 1, $e1);
    check('push_enabled off (the site-wide switch): nothing sent, no claim', $calls === [] && $dl($e1) === 0);
    $set('push_enabled', true, 'bool');

    $r = AlertBus::deliver(200, $t0 + 2, $e1);
    $got = array_column($for($e1), 'adminId');
    sort($got);
    $exp = [$MGR, $SUP];
    sort($exp);
    check('first ring: one push each to manager + support, none to the agent or the viewer', $got === $exp && $r['push'] === 2 && $dl($e1) === 2, json_encode($got));
    $p = $for($e1)[0]['payload'] ?? [];
    check('payload: requireInteraction, renotify, tag, vibrate, Seen / Open actions', ($p['requireInteraction'] ?? null) === true && ($p['renotify'] ?? null) === true
        && ($p['tag'] ?? '') === 'shg-ring-' . $e1 && is_array($p['vibrate'] ?? null) && count($p['vibrate']) >= 5
        && array_column($p['actions'] ?? [], 'action') === ['seen', 'open'] && ($p['ring'] ?? 0) === 1);
    check('payload: a relative /admin link, the ack door and a token that verifies for that person', ($p['url'] ?? '') === '/admin/booking-view.php?pnr=' . $PNR . 'X'
        && ($p['ackUrl'] ?? '') === '/api/alert-ack.php' && AlertBus::checkAckToken($e1, (int) $p['adminId'], (string) $p['ackToken']) && ($p['ackAll'] ?? 1) === 0);
    $c0 = $for($e1)[0];
    check('…sent urgent, with a TTL inside the ring window and a Topic so repeats replace each other', $c0['urgency'] === 'high' && $c0['ttl'] >= 60 && $c0['ttl'] <= 120
        && $c0['topic'] === 'ring' . $e1 && $c0['event'] === 'alert_new_booking', json_encode([$c0['ttl'], $c0['topic'], $c0['event']]));
    check('payload: no phone number', !str_contains((string) json_encode($p, JSON_UNESCAPED_UNICODE), $PHONE));

    $calls = [];
    AlertBus::deliver(200, $t0 + 30, $e1);
    AlertBus::deliver(200, $t0 + 110, $e1);
    check('same ring again: nothing more (one push per person per ring)', $calls === [] && $dl($e1) === 2);
    AlertBus::deliver(200, $t0 + 121, $e1);
    check('after alert_ring_repeat_min: the second ring, once each', count($for($e1)) === 2 && $dl($e1) === 4);
    check('attempt(): ring 0 at 5 s, ring 1 at 125 s, ring 3 at 361 s, none at 421 s', (AlertBus::attempt($rowE1, $t0 + 5)['k'] ?? -1) === 0
        && (AlertBus::attempt($rowE1, $t0 + 125)['k'] ?? -1) === 1 && (AlertBus::attempt($rowE1, $t0 + 361)['k'] ?? -1) === 3 && AlertBus::attempt($rowE1, $t0 + 421) === null);
    $calls = [];
    AlertBus::ack($e1, $who($SUP, 'support'), 'push', $t0 + 130);
    AlertBus::deliver(200, $t0 + 241, $e1);
    check('after Seen: no third ring for anyone', $calls === [] && $dl($e1) === 4);

    /* quiet hours: 00:00-23:59 for managers */
    $keep('alert_role_prefs');
    $pr = [];
    foreach (AlertBus::ROLES as $rr) { $pr[$rr] = ['sound' => '1', 'volume' => 80, 'quiet_from' => '', 'quiet_to' => '']; }
    $pr['manager'] = ['sound' => '', 'volume' => 250, 'quiet_from' => '00:00', 'quiet_to' => '23:59'];
    $pr['support']['quiet_from'] = 'nonsense';
    AlertBus::saveRolePrefs($pr);
    $rp = AlertBus::rolePrefs();
    check('role prefs: saved, clamped and validated', $rp['manager']['sound'] === false && $rp['manager']['volume'] === 100 && $rp['manager']['quiet_from'] === '00:00'
        && $rp['support']['quiet_from'] === '' && $rp['support']['sound'] === true);
    $noon = strtotime(date('Y-m-d') . ' 12:00:00');
    check('inQuiet: inside for the manager, never for support', AlertBus::inQuiet('manager', $noon) && !AlertBus::inQuiet('support', $noon));
    $over = ['x' => ['quiet_from' => '22:00', 'quiet_to' => '06:00']];
    check('inQuiet: a window over midnight', AlertBus::inQuiet('x', strtotime(date('Y-m-d') . ' 23:30:00'), $over) && AlertBus::inQuiet('x', strtotime(date('Y-m-d') . ' 05:59:00'), $over)
        && !AlertBus::inQuiet('x', $noon, $over));
    $e2 = $raise('new_booking', ['title' => '🎫 quiet test', 'body' => 'b'], $tag . ':e2', $noon);
    $e3 = $raise('driver_sos', ['title' => '🆘 quiet test', 'body' => 'b'], $tag . ':e3', $noon);
    $calls = [];
    AlertBus::deliver(200, $noon + 1, $e2);
    AlertBus::deliver(200, $noon + 1, $e3);
    check('quiet hours: the manager is not pushed a new ticket, support is', array_column($for($e2), 'adminId') === [$SUP]);
    $sos = array_column($for($e3), 'adminId');
    sort($sos);
    check('quiet hours: a driver SOS still reaches the manager', $sos === $exp, json_encode($sos));
    $fq = AlertBus::feed($who($MGR, 'manager'), $noon + 1);
    $sosItem = null;
    foreach ($fq['rings'] as $it) { if ($it['id'] === $e3) { $sosItem = $it; } }
    check('feed in quiet hours: quiet + sound off for the role, the SOS item is marked loud with the siren', $fq['quiet'] === true && $fq['sound'] === false
        && $fq['volume'] === 100 && $sosItem !== null && $sosItem['loud'] === true && $sosItem['sound'] === 'siren');
    AlertBus::ack($e2, $who($SUP, 'support'));
    AlertBus::ack($e3, $who($SUP, 'support'));
    $blank('alert_role_prefs');

    /* burst: more open rings than alert_ring_burst -> one merged notification, only the newest repeats */
    AlertBus::$recipients = [['id' => $AGA, 'role' => 'agent', 'full_name' => 'a']];
    $set('alert_ring_burst', 2, 'int');
    $tb = time();
    $bIds = [];
    foreach ([1, 2, 3] as $i) {
        $bIds[] = $raise('payment_confirmed', ['title' => '✅ burst ' . $i, 'body' => 'b', 'actor' => $AGA, 'ring' => true], $tag . ':b' . $i, $tb);
    }
    $calls = [];
    AlertBus::deliver(200, $tb + 1);
    $mineB = array_values(array_filter($calls, static fn(array $c): bool => $c['adminId'] === $AGA && in_array((int) $c['payload']['alertId'], $bIds, true)));
    /* fx4: a flood used to send one push per alert still at its first ring (3 buzzes, all the same merged card) */
    check('burst: 3 open rings > burst 2 -> ONE push in all: the merged notification for the newest (tag + Topic many, count 3, Seen = all)', count($mineB) === 1
        && (int) $mineB[0]['payload']['alertId'] === max($bIds)
        && array_unique(array_column(array_column($mineB, 'payload'), 'tag')) === ['shg-ring-many'] && array_unique(array_column($mineB, 'topic')) === ['ringmany']
        && str_contains((string) $mineB[0]['payload']['title'], '3') && $mineB[0]['payload']['ackAll'] === 1, (string) count($mineB));
    $calls = [];
    AlertBus::deliver(200, $tb + 2);
    check('burst: the next pass inside the same ring sends nothing more (the older alerts take no claim of their own)',
        array_filter($calls, static fn(array $c): bool => $c['adminId'] === $AGA) === []
        && (int) Database::scalar("SELECT COUNT(*) FROM alert_deliveries WHERE alert_id IN (" . implode(',', $bIds) . ") AND channel = 'push' AND admin_id = :a", ['a' => $AGA]) === 1);
    $bp = $mineB[0]['payload'] ?? ['alertId' => 0, 'ackToken' => ''];
    check('burst: its token is signed as "all" - and a single-alert token can never be used as "all"', AlertBus::checkAckToken((int) $bp['alertId'], $AGA, (string) $bp['ackToken'], true)
        && !AlertBus::checkAckToken((int) $bp['alertId'], $AGA, (string) $bp['ackToken'], false)
        && !AlertBus::checkAckToken((int) $bp['alertId'], $AGA, AlertBus::ackToken((int) $bp['alertId'], $AGA), true));
    check('isOpen(): true while it rings, false once its repeats are used up', AlertBus::isOpen((int) $bp['alertId'], $tb + 5) && !AlertBus::isOpen((int) $bp['alertId'], $tb + 421));
    $calls = [];
    AlertBus::deliver(200, $tb + 121);
    $mineB = array_values(array_filter($calls, static fn(array $c): bool => $c['adminId'] === $AGA));
    check('burst: the repeat is ONE push (the newest), not three', count($mineB) === 1 && (int) $mineB[0]['payload']['alertId'] === max($bIds), (string) count($mineB));
    $n = AlertBus::ackAll($who($AGA, 'agent'), 'push');
    check('Seen all from the agent\'s phone: the agent\'s three rings go quiet FOR THE AGENT (personal), nothing is closed for the office', $n === 3
        && (int) Database::scalar('SELECT COUNT(*) FROM alert_events WHERE id IN (' . implode(',', $bIds) . ') AND acked_at IS NOT NULL') === 0
        && (int) Database::scalar("SELECT COUNT(*) FROM alert_deliveries WHERE alert_id IN (" . implode(',', $bIds) . ") AND channel = 'seen' AND admin_id = :a", ['a' => $AGA]) === 3
        && $alert('j:' . $j2)['acked_at'] === null);
    $calls = [];
    AlertBus::deliver(200, $tb + 241);
    check('…and the agent\'s phone gets no further repeat of them', array_filter($calls, static fn(array $c): bool => $c['adminId'] === $AGA) === []);
    foreach ($bIds as $bId) { AlertBus::ack($bId, $who($ACC, 'accountant')); }     // one by one: never "all" on a shared database
    check('Seen by the office closes them', (int) Database::scalar('SELECT COUNT(*) FROM alert_events WHERE id IN (' . implode(',', $bIds) . ') AND acked_at IS NOT NULL') === 3);

    /* fx4 review: "Seen all" (a merged notification tapped later, or the card) is capped at the alert the
       person was shown - a LATER alert, and above all a driver SOS, must keep ringing for the office. */
    $tq = time();
    $qA = $raise('payment_confirmed', ['title' => '✅ cap A', 'body' => 'a', 'ring' => true], $tag . ':capA', $tq);
    $qB = $raise('payment_confirmed', ['title' => '✅ cap B', 'body' => 'b', 'ring' => true], $tag . ':capB', $tq + 1);
    $qS = $raise('driver_sos', ['title' => '🆘 cap SOS', 'body' => 's', 'ring' => true], $tag . ':capS', $tq + 2);
    $nq = AlertBus::ackAll($who($MGR, 'manager'), 'push', $tq + 3, $qA);
    check('Seen all capped at alert A: A is closed, the LATER alert B keeps ringing', $alert($tag . ':capA')['acked_at'] !== null && $alert($tag . ':capB')['acked_at'] === null, (string) $nq);
    check('…and a driver SOS is never silenced by "Seen all", even below the cap', AlertBus::ackAll($who($MGR, 'manager'), 'panel', $tq + 4, $qS - 1) >= 0
        && $alert($tag . ':capS')['acked_at'] === null && $alert($tag . ':capB')['acked_at'] !== null);
    check('…only its own Seen stops the SOS', AlertBus::ack($qS, $who($MGR, 'manager'), 'panel', $tq + 5)['ok'] === true && $alert($tag . ':capS')['acked_at'] !== null);
    $set('alert_ring_burst', 6, 'int');

    /* ---------------- 7b. WHICH phones ring: only the ones that tapped "Ring on this phone" ---------------- */
    $mkSub = static function (string $n, ?int $adminId) use (&$subs, $tag): string {
        $ep = 'https://push.invalid/' . $tag . '/' . $n;
        Database::insert('push_subscriptions', ['endpoint' => $ep, 'endpoint_hash' => sha1($ep), 'p256dh' => 'p-' . $n, 'auth' => 'a-' . $n,
            'phone' => '', 'ua' => 'Fx1 test phone ' . $n, 'is_active' => 1, 'admin_id' => $adminId]);
        $subs[] = $ep;
        return $ep;
    };
    $epM = $mkSub('m', $MGR);                  // the manager allowed notifications for Office chat only
    $epS = $mkSub('s', $SUP);                  // so did support
    AlertBus::$recipients = null;              // read the real tables from here on (the sender stays a stub)
    $tp = time();
    $e4 = $raise('new_booking', ['title' => '🎫 opt-in ' . $tag, 'body' => 'b'], $tag . ':e4', $tp);
    $calls = [];
    AlertBus::deliver(400, $tp + 1, $e4);
    /* this test's own people only: the database is shared, somebody else's phone may be switched on too */
    $ours = static fn(int $id): array => array_values(array_filter($for($id), static fn(array $c): bool => in_array($c['adminId'], [$MGR, $SUP], true)));
    check('a phone that only has Office chat push is NOT rung (no tap on "Ring on this phone")', $ours($e4) === [] && AlertBus::ringSubs($MGR) === [] && !AlertBus::phoneRings($epM));
    check('the panel\'s quiet sync never switches such a phone on', AlertBus::pushOptIn($epM, $MGR, false) === false && !AlertBus::phoneRings($epM) && AlertBus::ringSubs($MGR) === []);
    check('the tap switches it on - for that person, that handset', AlertBus::pushOptIn($epM, $MGR, true) === true && AlertBus::phoneRings($epM, $MGR) && !AlertBus::phoneRings($epM, $SUP)
        && count(AlertBus::ringSubs($MGR)) === 1 && AlertBus::ringSubs($MGR)[0]['endpoint'] === $epM && !array_key_exists('ring_hash', AlertBus::ringSubs($MGR)[0])
        && !array_key_exists('ring_pw', AlertBus::ringSubs($MGR)[0]) && AlertBus::ringSubs($SUP) === []);
    $calls = [];
    AlertBus::deliver(400, $tp + 2, $e4);
    check('…now the manager is rung, support (chat only) still is not', array_values(array_unique(array_column($ours($e4), 'adminId'))) === [$MGR], json_encode(array_column($ours($e4), 'adminId')));
    $listed = array_values(array_filter(AlertBus::ringPhones(300), static fn(array $p): bool => $p['admin_id'] === $MGR));
    check('ringPhones(): the office sees who / which browser / since when - no endpoint, no keys', count($listed) === 1 && $listed[0]['name'] === 'Fx1 Mgr Tester' && $listed[0]['live'] === true
        && $listed[0]['key'] === sha1($epM) && $listed[0]['ua'] === 'Fx1 test phone m' && !str_contains((string) json_encode($listed), 'push.invalid') && !str_contains((string) json_encode($listed), 'p-m'));
    /* the REAL sender (no stub), limited to this test's manager: it must try the handset that tapped the button and
       leave the same person's chat-only handset alone. The marker handsets have garbage keys, so nothing can be delivered. */
    if (!WebPush::enabled()) {
        check('real sender targets only ringing handsets (skipped: web push has no keys on this machine)', true);
    } else {
        $epM2 = $mkSub('m2', $MGR);            // a second phone of the manager: Office chat only
        $e6   = $raise('new_booking', ['title' => '🎫 opt-in 3 ' . $tag, 'body' => 'b'], $tag . ':e6', $tp);
        $stub = AlertBus::$pusher;
        AlertBus::$pusher = null;
        AlertBus::$recipients = [['id' => $MGR, 'role' => 'manager', 'full_name' => 'm']];
        try { $r6 = AlertBus::deliver(10, $tp + 1, $e6); } finally { AlertBus::$pusher = $stub; AlertBus::$recipients = null; }
        $tried = static fn(string $ep): bool => Database::scalar('SELECT last_sent_at FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($ep)], null) !== null;
        check('the real sender tries ONLY the handset that tapped the button - never that person\'s chat-only handset', $r6['push'] === 0 && $r6['failed'] === 1
            && $tried($epM) && !$tried($epM2) && (string) Database::scalar("SELECT detail FROM alert_deliveries WHERE alert_id = :a AND admin_id = :m AND channel = 'push'", ['a' => $e6, 'm' => $MGR], '') === 'sent 0, failed 1, skipped 0',
            json_encode($r6));
        AlertBus::ack($e6, $who($MGR, 'manager'));
    }
    /* a shared counter phone: the handset already rings, somebody else signs in on it */
    check('quiet sync on a handset that already rings moves the ring to whoever is signed in', AlertBus::pushOptIn($epM, $SUP, false) === true && AlertBus::phoneRings($epM, $SUP) && !AlertBus::phoneRings($epM, $MGR));
    check('…and it rings nobody until the subscription itself is bound to that person too', AlertBus::ringSubs($SUP) === [] && AlertBus::ringSubs($MGR) === []);
    Database::run('UPDATE push_subscriptions SET admin_id = :a WHERE endpoint_hash = :h', ['a' => $SUP, 'h' => sha1($epM)]);
    check('…then it rings for the new person only', count(AlertBus::ringSubs($SUP)) === 1 && AlertBus::ringSubs($MGR) === []);
    /* a password change / office reset releases that account's phones */
    Database::run('UPDATE admins SET password_hash = :p WHERE id = :i', ['p' => password_hash('changed-' . $tag, PASSWORD_BCRYPT), 'i' => $SUP]);
    $paused = array_values(array_filter(AlertBus::ringPhones(300), static fn(array $p): bool => $p['admin_id'] === $SUP));
    $e5 = $raise('new_booking', ['title' => '🎫 opt-in 2 ' . $tag, 'body' => 'b'], $tag . ':e5', $tp);
    $calls = [];
    AlertBus::deliver(400, $tp + 3, $e5);
    check('after a password change that account\'s phone stops ringing (listed as paused)', AlertBus::ringSubs($SUP) === [] && $ours($e5) === [] && count($paused) === 1 && $paused[0]['live'] === false);
    check('…and picks the ring up again when its owner opens the panel on it (quiet sync)', AlertBus::pushOptIn($epM, $SUP, false) === true && count(AlertBus::ringSubs($SUP)) === 1);
    /* the push service rotated the endpoint: the ring follows it */
    $epNew = 'https://push.invalid/' . $tag . '/m-rotated';
    $subs[] = $epNew;
    $newId = WebPush::resubscribe($epM, ['endpoint' => $epNew, 'p256dh' => 'p-new', 'auth' => 'a-new']);
    check('pushsubscriptionchange: the rotated endpoint keeps ringing, the old one does not', $newId !== null && AlertBus::phoneRings($epNew, $SUP) && !AlertBus::phoneRings($epM)
        && count(AlertBus::ringSubs($SUP)) === 1 && AlertBus::ringSubs($SUP)[0]['endpoint'] === $epNew);
    /* off again */
    check('"Stop ringing this phone": only its owner can, and only the ring goes - the subscription (Office chat) stays bound', AlertBus::pushOptOut($epNew, $MGR) === false
        && AlertBus::pushOptOut($epNew, $SUP) === true && !AlertBus::phoneRings($epNew) && AlertBus::ringSubs($SUP) === []
        && (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = :h AND is_active = 1 AND admin_id = :a', ['h' => sha1($epNew), 'a' => $SUP]) === 1);
    AlertBus::pushOptIn($epS, $SUP, true);
    check('the office can take a (lost) phone off the ring; a made-up handle does nothing', AlertBus::removeRingPhone('zz') === false && AlertBus::removeRingPhone(str_repeat('0', 40)) === false
        && AlertBus::removeRingPhone(sha1($epS)) === true && !AlertBus::phoneRings($epS) && AlertBus::ringSubs($SUP) === []);
    AlertBus::pushOptIn($epS, $SUP, true);
    check('releasePhonesOf(): every phone of one account off the ring (the hook for session-end / sign-out), nobody else\'s', AlertBus::releasePhonesOf(0) === 0
        && AlertBus::releasePhonesOf($SUP) === 1 && !AlertBus::phoneRings($epS) && AlertBus::releasePhonesOf($SUP) === 0);
    AlertBus::ack($e4, $who($MGR, 'manager'));
    AlertBus::ack($e5, $who($MGR, 'manager'));

    /* ---------------- 8. office WhatsApp ---------------- */
    check('WhatsApp: 0 messages so far (alert_wa_on ships off)', $waCalls === []);
    $set('alert_wa_on', true, 'bool');
    AlertBus::$recipients = [];
    $tw = time();
    $w1 = $raise('agent_ticket', ['title' => '🧾 wa test', 'body' => 'Fx1 · 2 सिट · ₹3,600', 'actor' => $AGA, 'booking_id' => $bAgent['id']], $tag . ':w1', $tw);
    $w2 = $raise('new_booking', ['title' => '🎫 wa test', 'body' => 'b'], $tag . ':w2', $tw);
    $w3 = $raise('driver_sos', ['title' => '🆘 wa test', 'body' => 'b'], $tag . ':w3', $tw);
    AlertBus::deliver(200, $tw + 1, $w1);
    AlertBus::deliver(200, $tw + 2, $w1);
    AlertBus::deliver(200, $tw + 1, $w2);
    AlertBus::deliver(200, $tw + 1, $w3);
    check('WhatsApp on: an agent ticket goes once (not on the repeat); a customer\'s own booking and an SOS never (they already send their own)',
        count($waCalls) === 1 && $waCalls[0]['headline'] === '🧾 wa test' && $waCalls[0]['bookingId'] === $bAgent['id'], (string) count($waCalls));
    check('…logged as one delivery row for the office number', (int) Database::scalar("SELECT COUNT(*) FROM alert_deliveries WHERE alert_id = :a AND channel = 'whatsapp' AND admin_id = 0", ['a' => $w1]) === 1);
    /* fx4: the hourly budget is used up -> nothing goes AND no claim row is left behind, so a later pass can still send it */
    Database::run("UPDATE rate_limits SET hits = 99, window_start = :w WHERE bucket = 'alert_wa' AND identifier = 'office'", ['w' => date('Y-m-d H:i:s')]);
    $w4 = $raise('agent_ticket', ['title' => '🧾 wa limit', 'body' => 'b', 'actor' => $AGA], $tag . ':w4', $tw);
    $waBefore = count($waCalls);
    AlertBus::deliver(200, $tw + 3, $w4);
    check('WhatsApp budget used up: no message, and the claim row was taken back (claim first, then the limit)', count($waCalls) === $waBefore
        && (int) Database::scalar("SELECT COUNT(*) FROM alert_deliveries WHERE alert_id = :a AND channel = 'whatsapp'", ['a' => $w4]) === 0);
    $set('alert_wa_on', false, 'bool');
    foreach ([$w1, $w2, $w3, $w4] as $w) { AlertBus::ack($w, $who(1, 'superadmin')); }

    /* ---------------- 8b. agent near the credit limit: one alert per agent per day ---------------- */
    require_once INCLUDE_PATH . '/agentwallet.php';
    require_once INCLUDE_PATH . '/agentcredit.php';
    if (!AgentCredit::hasLimitColumn()) {
        check('credit alert (skipped: admin_profiles.credit_limit is not on this database)', true);
    } else {
        $keep('agent_credit_limit_default');
        $keep('agent_credit_warn_pct');
        Settings::set('agent_credit_limit_default', '0', 'float', 'agent');
        Settings::set('agent_credit_warn_pct', '80', 'int', 'agent');
        Settings::flush();
        AgentCredit::setLimit($AGB, 10000.0, 0);
        AgentWallet::record($AGB, 'cash_due', 9000.0, ['note' => $tag . ' credit test dues', 'by' => 0]);
        $keepState('credit_at');
        Database::run("DELETE FROM alert_bus_state WHERE k = 'credit_at'");
        $creditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM alert_events', [], 0);
        $tc = time();
        $n1 = AlertBus::creditWatch($tc);
        $cr = $alert('credit:' . $AGB . ':' . date('Y-m-d', $tc));
        $srcKeys[] = 'credit:' . $AGB . ':' . date('Y-m-d', $tc);
        check('credit: an agent at 90 % of the limit -> agent_credit_low with name, %, owed / limit', $n1 >= 1 && $cr !== null && $cr['event'] === 'agent_credit_low'
            && (int) $cr['actor_admin_id'] === $AGB && str_contains((string) $cr['body'], 'Fx1 Agb Tester') && str_contains((string) $cr['body'], '90%')
            && str_contains((string) $cr['body'], '9,000') && str_contains((string) $cr['body'], '10,000') && (int) $cr['ring'] === 0, (string) ($cr['body'] ?? 'no row'));
        check('…the agent and the accountant see it, another agent and support do not', $vis($who($AGB, 'agent'), $cr) && $vis($who($ACC, 'accountant'), $cr)
            && !$vis($who($AGA, 'agent'), $cr) && !$vis($who($SUP, 'support'), $cr));
        check('…an agent with no limit raised nothing', $alert('credit:' . $AGA . ':' . date('Y-m-d', $tc)) === null);
        check('…at most once every 10 minutes (a second call does nothing)', AlertBus::creditWatch($tc + 60) === 0);
        Database::run("UPDATE alert_bus_state SET v = 0 WHERE k = 'credit_at'");
        check('…and once per agent per day (a later pass does not repeat it)', AlertBus::creditWatch($tc + 700) === 0
            && (int) Database::scalar('SELECT COUNT(*) FROM alert_events WHERE src_key = :k', ['k' => 'credit:' . $AGB . ':' . date('Y-m-d', $tc)]) === 1);
        // credit alerts this pass raised for other people's test agents on the shared database: this test made them
        foreach (Database::fetchAll("SELECT src_key FROM alert_events WHERE id > :f AND src_key LIKE 'credit:%'", ['f' => $creditFrom]) as $x) { $srcKeys[] = (string) $x['src_key']; }
    }

    /* ---------------- 9. the test button ---------------- */
    AlertBus::$recipients = null;
    $calls = [];
    $t1 = AlertBus::test('new_booking', 'inapp', ['id' => $MGR, 'role' => 'manager', 'full_name' => 'm']);
    $trow = isset($t1['id']) ? Database::fetch('SELECT * FROM alert_events WHERE id = :i', ['i' => $t1['id']]) : null;
    if ($trow !== null) { $srcKeys[] = (string) $trow['src_key']; }
    check('test (panel): stored, marked [TEST], visible ONLY to the person who pressed it', $t1['ok'] === true && $trow !== null && str_contains((string) $trow['title'], '[TEST]')
        && AlertBus::visibleTo($who($MGR, 'manager'), $trow) && !AlertBus::visibleTo($who($SUP, 'support'), $trow) && !AlertBus::visibleTo($who(1, 'superadmin'), $trow));
    $t2 = AlertBus::test('driver_sos', 'push', ['id' => $MGR, 'role' => 'manager', 'full_name' => 'm']);
    $trow2 = isset($t2['id']) ? Database::fetch('SELECT * FROM alert_events WHERE id = :i', ['i' => $t2['id']]) : null;
    if ($trow2 !== null) { $srcKeys[] = (string) $trow2['src_key']; }
    $tc = isset($t2['id']) ? $for((int) $t2['id']) : [];
    check('test (phone): one push, to that person only, logged as alert_test', $t2['ok'] === true && count($tc) === 1 && $tc[0]['adminId'] === $MGR && $tc[0]['event'] === 'alert_test');
    $waCalls = [];
    $t3 = AlertBus::test('new_booking', 'whatsapp', ['id' => $MGR, 'role' => 'manager']);
    check('test (WhatsApp): one line handed to the office sender', $t3['ok'] === true && count($waCalls) === 1 && str_contains($waCalls[0]['headline'], '[TEST]'));
    check('test: an unknown alert is refused', AlertBus::test('nope', 'inapp', ['id' => $MGR, 'role' => 'manager'])['ok'] === false);
    foreach ([$trow, $trow2] as $tr) { if ($tr !== null) { AlertBus::ack((int) $tr['id'], $who($MGR, 'manager')); } }
    check('recent(): the log lists alerts with who tapped Seen', (static function () use ($e1): bool {
        foreach (AlertBus::recent(200) as $r) { if ((int) $r['id'] === $e1) { return (string) $r['acked_name'] === 'Fx1 Sup Tester' && (int) $r['pushes'] === 4; } }
        return false;
    })());
    $logIds = static fn(?array $viewer): array => array_map('intval', array_column(AlertBus::recent(200, $viewer), 'id'));
    $all = $logIds(null);
    $sup = $logIds($who($SUP, 'support'));
    check('recent(viewer): the log follows the matrix - support reads the new ticket but not a payment it is not told about; the full log has both',
        in_array((int) $a1['id'], $all, true) && in_array((int) $a5['id'], $all, true) && in_array((int) $a1['id'], $sup, true) && !in_array((int) $a5['id'], $sup, true));
    check('…an agent\'s log: own ticket\'s payment only; a clerk sees their own sale there (the "not rung for my own sale" rule is about ringing, not knowing)',
        $logIds($who($AGB, 'agent')) === array_values(array_intersect($logIds($who($AGB, 'agent')), array_map('intval', array_column(
            Database::fetchAll('SELECT id FROM alert_events WHERE actor_admin_id = :a', ['a' => $AGB]), 'id'))))
        && in_array((int) $a5['id'], $logIds($who($AGA, 'agent')), true) && !in_array((int) $a1['id'], $logIds($who($AGA, 'agent')), true)
        && in_array((int) $a3['id'], $logIds($who($C1, 'counter')), true) && $logIds($who($VIW, 'viewer')) === []);

    /* ---------------- 10. small pure helpers ---------------- */
    $s = AlertBus::scrub('Call 9812345678 or +91 98765 43210 or 98765-43210 · SHG-20261126-123456789 · ₹1,23,456 · 2026-11-26 14:30 · #1234');
    check('scrub(): phone numbers go, PNR / amount / date / ids stay', !str_contains($s, '9812345678') && !str_contains($s, '43210') && str_contains($s, 'SHG-20261126-123456789')
        && str_contains($s, '₹1,23,456') && str_contains($s, '2026-11-26 14:30') && str_contains($s, '#1234'), $s);
    check('raise(): an off-site link is dropped, an unknown event refused', (static function () use ($raise, $alert, $tag): bool {
        $id = $raise('bus_late', ['title' => 't', 'link' => 'https://evil.example/x'], $tag . ':lk');
        return $id > 0 && $alert($tag . ':lk')['link'] === null && $raise('no_such', ['title' => 't'], $tag . ':zz') === 0;
    })());
    check('raise(): the same source key is stored once', $raise('bus_late', ['title' => 't2'], $tag . ':lk') === 0);
    check('WebPush::topicHeader: URL-safe, at most 32 chars', WebPush::topicHeader('ring 12/..#x') === 'ring12x' && strlen(WebPush::topicHeader(str_repeat('a', 80))) === 32 && WebPush::topicHeader('') === '');
    $sig = new ReflectionMethod('WebPush', 'sendToAdmin');
    $ps  = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $sig->getParameters());
    check('WebPush::sendToAdmin(adminId, payload, event, ttl, urgency, topic) - the three new ones optional', $ps === ['adminId', 'payload', 'event', 'ttl', 'urgency', 'topic']
        && $sig->getNumberOfRequiredParameters() === 2 && $sig->getParameters()[3]->getDefaultValue() === 86400);

    /* ---------------- 11. the panel hooks ---------------- */
    $js = admin_poll_js();
    check('admin_poll_js: still the px1 baseline + chime, now with the ring hook (same poller)', str_contains($js, 'shg_admin_last_maxb') && str_contains($js, 'chime(); showTicketBanner(')
        && str_contains($js, 'window.SHGRing.tick()') && str_contains($js, '!ringing') && substr_count($js, 'setInterval(') === 1);
    $footer = static function (): string {
        $_SERVER['SCRIPT_NAME'] = '/admin/bookings.php';
        ob_start();
        try { admin_footer(); } finally { $out = (string) ob_get_clean(); }
        return $out;
    };
    actAs($MGR, 'manager');
    $o = $footer();
    check('admin_footer: the ring script is printed (versioned, deferred) before the poller', preg_match('~<script src="/assets/js/admin-ring\.js\?v=\d+" defer></script><script>\(function \(\) \{\s+var KEY_LAST~', $o) === 1);
    $set('alert_ring_on', false, 'bool');
    $o2 = $footer();
    check('alert_ring_on = 0: no ring script - the page is as before', !str_contains($o2, '<script src="/assets/js/admin-ring.js') && str_contains($o2, 'shg_admin_last_maxb'));
    $set('alert_ring_on', true, 'bool');
    $set('alert_bus_on', false, 'bool');
    check('alert_bus_on = 0: no ring script either', !str_contains($footer(), '<script src="/assets/js/admin-ring.js'));
    $set('alert_bus_on', true, 'bool');
    /* the files are deployed but the migration has not run yet: the old chime must stay exactly as it was */
    $rp = new ReflectionProperty('AlertBus', 'ready');
    $rp->setAccessible(true);
    $rp->setValue(null, false);
    $o3 = $footer();
    AlertBus::flush();
    check('tables missing (migration not run yet): no ring script - the px1 chime + green bar are not taken over', !str_contains($o3, '<script src="/assets/js/admin-ring.js')
        && str_contains($o3, 'shg_admin_last_maxb') && str_contains($o3, 'chime(); showTicketBanner('));
    check('…and with the tables back the script is printed again', str_contains($footer(), '<script src="/assets/js/admin-ring.js'));
    actAs(null);

    $nt = (string) file_get_contents($ROOT . '/admin/notify-test.php');
    check('notify-test.php: channel=alert is super-admin only and whitelisted; the old gate line is unchanged', str_contains($nt, "=== 'alert') {\n    if (!Auth::isSuperadmin()) {")
        && str_contains($nt, 'isset(AlertBus::EVENTS[$ev])') && str_contains($nt, "\$canEdit = Auth::can('routes.edit') || ((\$admin['role'] ?? '') === 'superadmin');")
        && str_contains($nt, '$res = Notify::whatsappTest($phone, $hint);'));
    $ring = (string) file_get_contents($ROOT . '/assets/js/admin-ring.js');
    check('admin-ring.js: one AudioContext, the sound switch, reduced motion, wake lock, no timer of its own for polling', substr_count($ring, 'new AC()') === 1
        && str_contains($ring, "ls('shg_admin_sound') !== '0'") && str_contains($ring, 'prefers-reduced-motion:no-preference') && str_contains($ring, "wakeLock.request('screen')")
        && !str_contains($ring, 'setInterval(tick') && !str_contains($ring, '$'));
    check('admin-ring.js: never full screen on a selling screen; seven voices; under 40 KB', str_contains($ring, "/quick-ticket|new-booking/") && str_contains($ring, '!selling()')
        && preg_match_all('/^    (ring|chime|tick|alert|siren|ding|pop): function/m', $ring) === 7 && strlen($ring) < 40000, (string) strlen($ring));
    check('admin-ring.js: only a TAP asks the server to switch a handset on (ask: 1); the quiet sync sends ask: 0; a Stop button exists and never unsubscribes the browser',
        str_contains($ring, "action: 'subscribe', ask: ask ? 1 : 0") && str_contains($ring, 'enablePush(false)') && str_contains($ring, "action: 'unsubscribe', endpoint: s.endpoint")
        && str_contains($ring, 'Stop ringing this phone') && !str_contains($ring, '.unsubscribe()'));
    check('sound kit README records how each sound is made', (static function () use ($ROOT): bool {
        $md = (string) @file_get_contents($ROOT . '/assets/sound/README.md');
        foreach (['`ring`', '`chime`', '`tick`', '`alert`', '`siren`', '`ding`', '`pop`'] as $v) { if (!str_contains($md, $v)) { return false; } }
        return str_contains($md, 'licence-free');
    })());

    /* ---------------- 12. sw.js ---------------- */
    $sw = (string) file_get_contents($ROOT . '/sw.js');
    check('sw.js: still starts with its BOM, still the three listeners', str_starts_with($sw, "\xEF\xBB\xBF") && str_contains($sw, "addEventListener('push'")
        && str_contains($sw, "addEventListener('notificationclick'") && str_contains($sw, "addEventListener('pushsubscriptionchange'"));
    check('sw.js: the default notification options are the old lines, untouched', str_contains($sw,
        "    tag: data.tag || 'shg',\n    renotify: !!data.tag,\n    lang: data.lang || 'ne',\n    data: { url: data.url || '/#/my' },\n    vibrate: [120, 60, 120]\n  };"));
    $head = @shell_exec('git -C ' . escapeshellarg($ROOT) . ' show HEAD:sw.js 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
    if (is_string($head) && $head !== '') {
        $cut = static fn(string $s, string $from): string => (string) substr($s, 0, (int) strpos($s, $from));
        $tail = static fn(string $s, string $from): string => (string) substr($s, (int) strpos($s, $from));
        check('sw.js vs git HEAD: nothing before the push listener changed (VERSION, ASSET_VER, PRECACHE, fetch)', $cut($sw, "self.addEventListener('push'") === $cut($head, "self.addEventListener('push'"));
        check('sw.js vs git HEAD: pushsubscriptionchange onwards is identical', $tail($sw, "self.addEventListener('pushsubscriptionchange'") === $tail($head, "self.addEventListener('pushsubscriptionchange'"));
    } else {
        check('sw.js vs git HEAD (skipped: git not available here)', true);
    }

    $node = trim((string) @shell_exec(DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    if ($node === '') {
        check('sw.js behaviour in node (skipped: node not installed here)', true);
    } else {
        $harness = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tag . '-sw.js';
        file_put_contents($harness, <<<'JS'
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8').replace(/^﻿/, '');
const handlers = {}, shown = [], posts = [], opened = [], focused = [], messages = [];
let wins = [];
const self = {
  location: { hostname: 'shreehariglobal.network', origin: 'https://shreehariglobal.network' },
  addEventListener: (n, f) => { handlers[n] = f; },
  registration: { showNotification: (t, o) => { shown.push({ t: t, o: o }); return Promise.resolve(); } },
  clients: { matchAll: async () => wins, openWindow: async (u) => { opened.push(u); } },
  skipWaiting: () => Promise.resolve()
};
const ctx = { self: self, caches: {}, fetch: async (u, o) => { posts.push({ u: u, body: o && o.body ? JSON.parse(o.body) : null, method: o && o.method }); return { ok: true }; },
  URL: URL, Promise: Promise, setTimeout: setTimeout, clearTimeout: clearTimeout, console: console };
vm.createContext(ctx); vm.runInContext(src, ctx);
async function push(data) { shown.length = 0; let p; handlers.push({ data: { json: () => data, text: () => JSON.stringify(data) }, waitUntil: (x) => { p = x; } }); await p; return shown[0]; }
async function click(data, action, w) {
  posts.length = 0; opened.length = 0; focused.length = 0; messages.length = 0; wins = w || [];
  let p, closed = false;
  handlers.notificationclick({ action: action || '', notification: { data: data, close: () => { closed = true; } }, waitUntil: (x) => { p = x; } });
  await p;
  return { posts: posts.slice(), opened: opened.slice(), focused: focused.slice(), messages: messages.slice(), closed: closed };
}
const win = (u) => ({ url: u, focus: async () => { focused.push(u); }, postMessage: (m) => { messages.push(m); } });
(async () => {
  const out = {};
  out.old = await push({ title: 'T', body: 'B', url: '/#/ticket/X', tag: 'trip-1', lang: 'ne' });
  out.bare = await push({});
  out.ring = await push({ title: 'R', body: 'b', url: '/admin/booking-view.php?pnr=SHG-X', tag: 'shg-ring-7', ring: 1, requireInteraction: true, renotify: true,
    vibrate: [400, 200, 400], actions: [{ action: 'seen', title: 'Seen' }, { action: 'open', title: 'Open' }, { action: 'x', title: 'third' }, { bad: 1 }],
    alertId: 7, adminId: 3, ackUrl: '/api/alert-ack.php', ackToken: 'tok', ackAll: 0 });
  out.silent = await push({ title: 'S', silent: true });
  const ringData = { url: '/admin/booking-view.php?pnr=SHG-X', alertId: 7, adminId: 3, ackUrl: '/api/alert-ack.php', ackToken: 'tok', ackAll: 0 };
  out.seen = await click(ringData, 'seen', [win('https://shreehariglobal.network/admin/index.php')]);
  out.open = await click(ringData, 'open', [win('https://shreehariglobal.network/admin/index.php')]);
  out.evil = await click({ url: '/admin/x.php', alertId: 7, adminId: 3, ackUrl: 'https://evil.example/api/alert-ack.php', ackToken: 't' }, 'seen', []);
  out.customer = await click({ url: '/#/my' }, '', [win('https://shreehariglobal.network/')]);
  out.customerNoWin = await click({ url: '/#/ticket/Z' }, '', []);
  out.staff = await click({ url: '/admin/staff-chat.php?t=5' }, '', [win('https://shreehariglobal.network/admin/index.php')]);
  console.log(JSON.stringify(out));
})().catch((e) => { console.log(JSON.stringify({ error: String(e && e.stack || e) })); });
JS);
        $raw = (string) @shell_exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($ROOT . '/sw.js') . ' 2>&1');
        @unlink($harness);
        $o = json_decode(trim($raw), true);
        if (!is_array($o) || isset($o['error'])) {
            check('sw.js runs in the node harness', false, mb_substr($raw, 0, 400));
        } else {
            $old = ['body' => 'B', 'icon' => '/assets/img/icon-192.png', 'badge' => '/assets/img/badge-96.png', 'tag' => 'trip-1', 'renotify' => true,
                    'lang' => 'ne', 'data' => ['url' => '/#/ticket/X'], 'vibrate' => [120, 60, 120]];
            check('sw push: an OLD payload builds exactly the old notification (same keys, same order, nothing added)', $o['old']['t'] === 'T' && json_encode($o['old']['o']) === json_encode($old), json_encode($o['old']));
            check('sw push: an empty payload = the old defaults', $o['bare']['t'] === 'S Hari Global' && json_encode($o['bare']['o']) === json_encode(['body' => '', 'icon' => '/assets/img/icon-192.png',
                'badge' => '/assets/img/badge-96.png', 'tag' => 'shg', 'renotify' => false, 'lang' => 'ne', 'data' => ['url' => '/#/my'], 'vibrate' => [120, 60, 120]]), json_encode($o['bare']));
            $ro = $o['ring']['o'];
            check('sw push: a ring payload stays on screen, re-alerts, buzzes long, has Seen / Open (max 2, malformed dropped)', ($ro['requireInteraction'] ?? false) === true
                && $ro['renotify'] === true && $ro['vibrate'] === [400, 200, 400] && $ro['actions'] === [['action' => 'seen', 'title' => 'Seen'], ['action' => 'open', 'title' => 'Open']]
                && $ro['tag'] === 'shg-ring-7', json_encode($ro));
            check('sw push: the ack fields travel in notification.data', ($ro['data']['alertId'] ?? 0) === 7 && ($ro['data']['adminId'] ?? 0) === 3 && ($ro['data']['ackToken'] ?? '') === 'tok'
                && ($ro['data']['ackUrl'] ?? '') === '/api/alert-ack.php' && $ro['data']['url'] === '/admin/booking-view.php?pnr=SHG-X');
            check('sw push: silent drops the vibration (a browser refuses both together)', ($o['silent']['o']['silent'] ?? false) === true && !isset($o['silent']['o']['vibrate']));
            check('sw click "Seen": closes, POSTs the ack, opens and focuses nothing', $o['seen']['closed'] === true && count($o['seen']['posts']) === 1
                && $o['seen']['posts'][0]['u'] === '/api/alert-ack.php' && $o['seen']['posts'][0]['method'] === 'POST'
                && $o['seen']['posts'][0]['body'] === ['id' => 7, 'admin' => 3, 't' => 'tok', 'all' => 0] && $o['seen']['opened'] === [] && $o['seen']['focused'] === [], json_encode($o['seen']));
            check('sw click "Open" on a ring: acks, then opens the ticket', count($o['open']['posts']) === 1 && $o['open']['opened'] === ['/admin/booking-view.php?pnr=SHG-X']);
            check('sw click: an ack URL that is not this site\'s own door is never fetched', $o['evil']['posts'] === []);
            check('sw click: a customer notification behaves exactly as before (message + focus, no ack, no new window)', $o['customer']['posts'] === [] && $o['customer']['opened'] === []
                && $o['customer']['focused'] === ['https://shreehariglobal.network/'] && $o['customer']['messages'] === [['type' => 'shg-open', 'url' => '/#/my']], json_encode($o['customer']));
            check('sw click: a customer notification with no window open still opens one', $o['customerNoWin']['opened'] === ['/#/ticket/Z']);
            check('sw click: a staff link now really opens (it used to only focus the old window)', $o['staff']['opened'] === ['/admin/staff-chat.php?t=5'] && $o['staff']['posts'] === []);
        }
    }

    /* ---------------- 12b. admin-ring.js in node with a tiny fake page (no browser needed) ---------------- */
    if ($node === '') {
        check('admin-ring.js behaviour in node (skipped: node not installed here)', true);
    } else {
        $harness = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tag . '-ring.js';
        file_put_contents($harness, <<<'JS'
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
function El(tag) { this.tag = tag; this.children = []; this.className = ''; this.textContent = ''; this.attrs = {}; this.listeners = {}; this.style = {}; }
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
El.prototype.removeAttribute = function (k) { delete this.attrs[k]; };
El.prototype.appendChild = function (c) { this.children.push(c); return c; };
El.prototype.removeChild = function (c) { const i = this.children.indexOf(c); if (i >= 0) { this.children.splice(i, 1); } };
Object.defineProperty(El.prototype, 'firstChild', { get: function () { return this.children[0] || null; } });
El.prototype.addEventListener = function (n, f) { (this.listeners[n] = this.listeners[n] || []).push(f); };
El.prototype.click = function () { (this.listeners.click || []).forEach(function (f) { f({}); }); };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
async function scene(o) {
  const st = { osc: 0, acks: [], feeds: 0, ctxs: 0, vib: 0, wake: 0, timers: [] };
  const store = (init) => { const m = Object.assign({}, init || {}); return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); } }; };
  const doc = { body: new El('body'), head: new El('head'), readyState: 'complete', visibilityState: 'visible', activeElement: null,
    createElement: (t) => { const e = new El(t); e.focus = function () { doc.activeElement = e; }; return e; },
    getElementById: (id) => (id === 'smartBook' && o.smartBook ? new El('div') : null),
    querySelector: (s) => (s.indexOf('shg-admin-csrf') >= 0 ? { getAttribute: () => 'CSRF123' } : null),
    addEventListener: () => {} };
  function AC() { st.ctxs++; this.state = o.suspended ? 'suspended' : 'running'; this.currentTime = 0; this.destination = {}; }
  AC.prototype.resume = function () {};
  AC.prototype.createOscillator = function () { return { type: '', frequency: { value: 0, setValueAtTime() {}, linearRampToValueAtTime() {} }, connect() {}, start() { st.osc++; }, stop() {} }; };
  AC.prototype.createGain = function () { return { gain: { value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} }; };
  let feed = o.feed, feedOk = true;
  st.push = [];
  const nav = { vibrate: () => { st.vib++; return true; }, wakeLock: { request: () => { st.wake++; return Promise.resolve({ release() {}, addEventListener() {} }); } } };
  if (o.ua) { nav.userActivation = { hasBeenActive: false }; }   // a freshly loaded page nobody has touched
  if (o.push) {       // a handset whose browser already has a push subscription (say, from Office chat)
    const sub = { endpoint: 'https://push.invalid/h', toJSON: () => ({ endpoint: 'https://push.invalid/h', keys: {} }) };
    const reg = { pushManager: { getSubscription: () => Promise.resolve(sub) } };
    nav.serviceWorker = { register: () => Promise.resolve(reg), ready: Promise.resolve(reg), getRegistration: () => Promise.resolve(reg) };
  }
  const ctx = { document: doc, location: { pathname: o.path || '/admin/bookings.php', href: '' },
    navigator: nav,
    localStorage: store(o.ls), sessionStorage: store(o.ss), AudioContext: AC, addEventListener: () => {},
    fetch: (u, opt) => {
      if (u.indexOf('alert-feed') >= 0) {
        st.feeds++;
        if (!feedOk) { return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({ ok: false }) }); }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, data: JSON.parse(JSON.stringify(feed)) }) });
      }
      if (u.indexOf('alert-push') >= 0) {
        const body = opt && opt.body ? JSON.parse(opt.body) : null;
        if (body) { st.push.push(body); }
        const data = !body ? { enabled: true, publicKey: 'AAAA' } : body.action === 'subscribe' ? { id: 1, ring: o.push === 'rings' } : { stopped: true };
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, data: data }) });
      }
      st.acks.push({ u: u, headers: opt.headers, body: JSON.parse(opt.body) });
      if (o.ackStatus) { return Promise.resolve({ ok: false, status: o.ackStatus, json: () => Promise.resolve({ ok: false }) }); }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, data: { acked: 1 } }) });
    },
    setInterval: (f, ms) => { const t = setInterval(f, ms); st.timers.push(t); return t; }, clearInterval: clearInterval, setTimeout: setTimeout, clearTimeout: clearTimeout,
    Promise: Promise, Date: Date, JSON: JSON, Math: Math, parseInt: parseInt, String: String, Array: Array, Uint8Array: Uint8Array, console: console };
  if (o.push) { ctx.PushManager = function () {}; ctx.Notification = { permission: 'granted' }; }
  ctx.window = ctx;
  vm.createContext(ctx); vm.runInContext(src, ctx);
  await wait(40);
  const find = (id) => doc.body.children.filter((c) => c.id === id)[0] || null;
  const snap = () => { const r = find('shgRing'), b = find('shgRingBar'), s = find('shgRingSoft'), c = find('shgRingSnd');
    return { card: r ? r.className : null, bar: b ? b.className : null, soft: s ? s.className : null, osc: st.osc, feeds: st.feeds, ctxs: st.ctxs, wake: st.wake,
      chip: c ? (c.style.display || '') : null, chipText: c ? c.textContent : '', softText: s ? s.textContent : '', ringing: ctx.SHGRing.ringing,
      offer: r ? r.children[0].children[7].className : null, stop: r ? r.children[0].children[8].className : null, push: st.push.slice(),
      title: r ? r.children[0].children[1].textContent : '', active: ctx.SHGRing.active, focusOnButton: !!(doc.activeElement && doc.activeElement.tag === 'button') }; };
  const out = { first: snap() };
  if (o.then) {
    const card = find('shgRing');
    if (o.then === 'seen') { card.children[0].children[6].children[0].click(); await wait(30); out.acks = st.acks; feed = Object.assign({}, feed, { rings: [], ringCount: 0 }); }
    if (o.then === 'feed2') { feed = o.feed2; ctx.SHGRing.tick(); await wait(20); out.throttled = st.feeds; ctx.SHGRing.refresh(); await wait(40); }
    if (o.then === 'wait') { await wait(1150); }
    if (o.then === 'stop') { card.children[0].children[8].children[0].click(); await wait(40); }
    if (o.then === 'fail') { feedOk = false; ctx.SHGRing.refresh(); await wait(40); }
    if (o.then === 'chip') { const c = find('shgRingSnd'); if (c) { c.click(); } await wait(300); }
    out.second = snap();
  }
  st.timers.forEach(clearInterval);
  return out;
}
(async () => {
  const now = Math.floor(Date.now() / 1000);
  const base = { on: true, ring: true, now: now, seconds: 60, repeat: 120, max: 3, rings: [], ringCount: 0, soft: [], sound: true, volume: 80, quiet: false, push: { on: false } };
  const ring = (id, ago, extra) => Object.assign({ id: id, event: 'new_booking', title: 'T' + id, body: 'b', link: '/admin/booking-view.php?pnr=X', icon: 'i', sound: 'ring', at: now - ago, pnr: '', channel: 'Online', loud: false, test: false }, extra || {});
  const f = (rings, extra) => Object.assign({}, base, { rings: rings, ringCount: rings.length }, extra || {});
  const out = {};
  out.idle = await scene({ feed: f([]) });
  out.ringing = await scene({ feed: f([ring(7, 5)]), then: 'seen' });
  out.waiting = await scene({ feed: f([ring(8, 70)]) });
  out.expired = await scene({ feed: f([ring(9, 3 * 120 + 61)]) });
  out.selling = await scene({ feed: f([ring(10, 5)]), path: '/admin/quick-ticket.php', then: 'wait' });
  out.smartbook = await scene({ feed: f([ring(11, 5)]), smartBook: true });
  out.muted = await scene({ feed: f([ring(12, 5)]), ls: { shg_admin_sound: '0' } });
  out.quiet = await scene({ feed: f([ring(13, 5)], { quiet: true }) });
  out.quietSos = await scene({ feed: f([ring(14, 5, { loud: true, sound: 'siren', event: 'driver_sos' })], { quiet: true }) });
  out.locked = await scene({ feed: f([ring(15, 5)]), suspended: true });
  out.many = await scene({ feed: f([ring(18, 5), ring(17, 6), ring(16, 7)], { ringCount: 14 }), then: 'seen' });
  out.softFirst = await scene({ feed: f([], { soft: [ring(20, 5, { sound: 'chime' })] }) });
  out.softNew = await scene({ feed: f([], { soft: [ring(20, 5, { sound: 'chime' })] }), then: 'feed2', feed2: f([], { soft: [ring(21, 1, { sound: 'chime' }), ring(20, 5, { sound: 'chime' })] }) });
  out.off = await scene({ feed: Object.assign({}, base, { on: false, ring: false }) });
  const withPush = { me: 5, push: { on: true } };
  out.chatOnly = await scene({ feed: f([ring(30, 5)], withPush), push: 'chat' });
  out.ringsHere = await scene({ feed: f([ring(31, 5)], withPush), push: 'rings', then: 'stop' });
  out.feedFail = await scene({ feed: f([ring(40, 5)]), then: 'fail' });
  out.ackExpired = await scene({ feed: f([ring(41, 5)]), then: 'seen', ackStatus: 419 });
  out.ackDown = await scene({ feed: f([ring(42, 5)]), then: 'seen', ackStatus: 500 });
  out.chipIdle = await scene({ feed: f([]), ua: true, then: 'chip' });
  out.chipOff = await scene({ feed: Object.assign({}, base, { on: false, ring: false }), ua: true });
  out.chipMuted = await scene({ feed: f([]), ua: true, ls: { shg_admin_sound: '0' } });
  out.sellLocked = await scene({ feed: f([ring(43, 5)]), path: '/admin/quick-ticket.php', suspended: true });
  console.log(JSON.stringify(out));
  process.exit(0);
})().catch((e) => { console.log(JSON.stringify({ error: String(e && e.stack || e) })); process.exit(0); });
JS);
        $raw = (string) @shell_exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($ROOT . '/assets/js/admin-ring.js') . ' 2>&1');
        @unlink($harness);
        $o = json_decode(trim($raw), true);
        if (!is_array($o) || isset($o['error'])) {
            check('admin-ring.js runs in the node harness', false, mb_substr($raw, 0, 600));
        } else {
            $has = static fn(?string $cls, string $w): bool => $cls !== null && in_array($w, preg_split('/\s+/', trim($cls)) ?: [], true);
            check('ring JS: nothing open -> no card, no sound, one feed request, no AudioContext made', $o['idle']['first']['card'] === null && $o['idle']['first']['osc'] === 0
                && $o['idle']['first']['feeds'] === 1 && $o['idle']['first']['ctxs'] === 0 && $o['idle']['first']['active'] === true, json_encode($o['idle']));
            $r1 = $o['ringing']['first'];
            check('ring JS: a ring 5 s old -> full-screen card, ringing, the bell sounds, ONE AudioContext, screen kept awake', $has($r1['card'], 'on') && $has($r1['card'], 'ringing')
                && !$has($r1['card'], 'locked') && !$has($r1['bar'], 'on') && $r1['osc'] === 8 && $r1['ctxs'] === 1 && $r1['wake'] === 1 && $r1['title'] === 'T7', json_encode($r1));
            check('ring JS: the keyboard focus is NOT put on a button (a space bar cannot tap Seen)', $r1['focusOnButton'] === false);
            check('ring JS: Seen -> POST /api/alert-ack.php {ids} with the CSRF header, card gone at once', count($o['ringing']['acks'] ?? []) === 1
                && $o['ringing']['acks'][0]['u'] === '/api/alert-ack.php' && $o['ringing']['acks'][0]['body'] === ['ids' => [7]]
                && ($o['ringing']['acks'][0]['headers']['X-CSRF-Token'] ?? '') === 'CSRF123' && !$has($o['ringing']['second']['card'], 'on') && !$has($o['ringing']['second']['bar'], 'on'), json_encode($o['ringing']));
            check('ring JS: between two rings (70 s) -> slim bar only, silent', !$has($o['waiting']['first']['card'], 'on') && $has($o['waiting']['first']['bar'], 'on') && $o['waiting']['first']['osc'] === 0);
            check('ring JS: after the last repeat the card never rings again', !$has($o['expired']['first']['card'], 'on') && $o['expired']['first']['osc'] === 0);
            check('ring JS: quick-ticket -> never full screen, slim bar + ONE short tick (not again a second later)', !$has($o['selling']['first']['card'], 'on') && $has($o['selling']['first']['bar'], 'on')
                && $o['selling']['first']['osc'] === 1 && $o['selling']['second']['osc'] === 1, json_encode($o['selling']));
            check('ring JS: SmartBook -> slim bar, never full screen', !$has($o['smartbook']['first']['card'], 'on') && $has($o['smartbook']['first']['bar'], 'on'));
            check('ring JS: sound switch off (shg_admin_sound = 0) -> the card shows, nothing sounds', $has($o['muted']['first']['card'], 'on') && $o['muted']['first']['osc'] === 0);
            check('ring JS: quiet hours -> slim bar, silent', !$has($o['quiet']['first']['card'], 'on') && $has($o['quiet']['first']['bar'], 'on') && $o['quiet']['first']['osc'] === 0);
            check('ring JS: quiet hours + driver SOS -> full screen with the siren', $has($o['quietSos']['first']['card'], 'on') && $has($o['quietSos']['first']['card'], 'sos') && $o['quietSos']['first']['osc'] === 2);
            check('ring JS: browser still holds the sound -> "tap for sound" on the card', $has($o['locked']['first']['card'], 'locked') && $o['locked']['first']['osc'] === 0);
            // fx4: "all" now carries the newest ring id shown (upto), so a later alert keeps ringing
            check('ring JS: a flood (14 open, 3 listed) -> one card with the count; Seen answers ALL up to the newest shown', str_contains((string) $o['many']['first']['title'], '14')
                && ($o['many']['acks'][0]['body']['all'] ?? null) === 1 && (int) ($o['many']['acks'][0]['body']['upto'] ?? 0) > 0, json_encode($o['many']));
            check('ring JS: soft alerts already there when the page opens make no sound', $o['softFirst']['first']['osc'] === 0 && !$has($o['softFirst']['first']['soft'], 'on'));
            check('ring JS: a NEW soft alert -> a quiet line + its short sound; a tick inside 4 s is not a second request', $has($o['softNew']['second']['soft'], 'on')
                && $o['softNew']['second']['osc'] === 3 && $o['softNew']['throttled'] === 1 && $o['softNew']['second']['feeds'] === 2, json_encode($o['softNew']));
            check('ring JS: server says off -> SHGRing.active is false (the old chime keeps working)', $o['off']['first']['active'] === false && $o['off']['first']['card'] === null);
            $c0 = $o['chatOnly']['first'];
            check('ring JS: a handset that only allowed Office chat is NOT switched on by itself - the sync says ask: 0, and the card then offers "Ring on this phone"',
                count($c0['push']) === 1 && $c0['push'][0]['action'] === 'subscribe' && $c0['push'][0]['ask'] === 0 && $has($c0['offer'], 'on') && !$has($c0['stop'], 'on'), json_encode($c0));
            $h0 = $o['ringsHere']['first'];
            $h1 = $o['ringsHere']['second'];
            check('ring JS: a handset that rings shows "Stop ringing this phone" (no offer); the tap posts unsubscribe with its endpoint and the button goes',
                $has($h0['stop'], 'on') && !$has($h0['offer'], 'on') && count($h1['push']) === 2 && $h1['push'][1] === ['action' => 'unsubscribe', 'endpoint' => 'https://push.invalid/h']
                && !$has($h1['stop'], 'on'), json_encode([$h0, $h1]));
            /* fx4 follow-ups from the audit */
            $ff = $o['feedFail'];
            check('ring JS: the feed answers an error (500) -> the card and the bar go and SHGRing.ringing is false (no ringing from a stale list)',
                $has($ff['first']['card'], 'on') && $ff['first']['ringing'] === true && !$has($ff['second']['card'], 'on') && !$has($ff['second']['bar'], 'on')
                && $ff['second']['ringing'] === false && $ff['second']['active'] === false && $ff['second']['feeds'] === 2, json_encode($ff));
            $ae = $o['ackExpired'];
            check('ring JS: Seen refused with 419 (session / CSRF expired) -> the ring stays quiet in this tab and a "reload" line shows',
                count($ae['acks'] ?? []) === 1 && !$has($ae['second']['card'], 'on') && !$has($ae['second']['bar'], 'on') && $has($ae['second']['soft'], 'on')
                && str_contains((string) $ae['second']['softText'], 'reload') && str_contains((string) $ae['second']['softText'], 'रिलोड'), json_encode($ae));
            $ad = $o['ackDown'];
            check('ring JS: Seen hit a server error (500) -> the ring comes back (a retry may work)', count($ad['acks'] ?? []) === 1 && $has($ad['second']['card'], 'on'), json_encode($ad));
            $ci = $o['chipIdle'];
            check('ring JS: a freshly loaded page nobody touched -> "tap to enable ring sound" chip, no AudioContext made; a tap on it unlocks the sound and hides it',
                $ci['first']['chip'] === 'block' && $ci['first']['ctxs'] === 0 && $ci['first']['card'] === null && str_contains((string) $ci['first']['chipText'], 'Tap to enable ring sound')
                && str_contains((string) $ci['first']['chipText'], 'ट्याप') && $ci['second']['chip'] === 'none' && $ci['second']['ctxs'] === 1, json_encode($ci));
            check('ring JS: no chip when the ring is off for this person, or the sound switch is off, or the browser has no activation flag',
                $o['chipOff']['first']['chip'] === null && $o['chipMuted']['first']['chip'] === null && $o['idle']['first']['chip'] === null,
                json_encode([$o['chipOff']['first'], $o['chipMuted']['first']]));
            $sl = $o['sellLocked']['first'];
            check('ring JS: selling screen + sound still locked -> the slim bar carries the 🔊 hint (class locked), no separate chip',
                $has($sl['bar'], 'on') && $has($sl['bar'], 'locked') && !$has($sl['card'], 'on') && ($sl['chip'] === null || $sl['chip'] === 'none'), json_encode($sl));
        }
    }

    /* ---------------- 12c. the real endpoints over HTTP (opt-in) ----------------
       SHG_TEST_BASE=http://localhost:8971 php -c .claude/php-dev.ini tests/fx1-alertbus-test.php
       (a dev server on THIS worktree and this database; skipped without it) */
    $base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
    if ($base === '' || !function_exists('curl_init')) {
        check('HTTP checks of the endpoints + settings page (skipped: set SHG_TEST_BASE)', true);
    } else {
        require_once INCLUDE_PATH . '/quickunlock.php';
        $pw   = bin2hex(random_bytes(8)) . 'Aa!7';
        $jars = [];
        $jar  = static function (string $who) use (&$jars, $tag): string {
            return $jars[$who] ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tag . '_' . $who . '.txt';
        };
        $http = static function (string $who, string $url, array $opt = []) use ($base, $jar): array {
            $ch = curl_init($base . $url);
            $h  = $opt['headers'] ?? [];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
            if ($who !== '') { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar($who)); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar($who)); }
            if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
            if (isset($opt['json'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'])); $h[] = 'Content-Type: application/json'; }
            if ($h !== []) { curl_setopt($ch, CURLOPT_HTTPHEADER, $h); }
            $raw  = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hl   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            return ['code' => $code, 'body' => substr($raw, $hl), 'headers' => substr($raw, 0, $hl), 'json' => json_decode(substr($raw, $hl), true)];
        };
        $csrfFrom = static function (string $h): string {
            if (preg_match('/<meta name="shg-admin-csrf" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
            if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
            return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) === 1 ? $m[1] : '';
        };
        $mkLogin = static function (string $suffix, string $role) use (&$admins, $tag, $pw, $http, $csrfFrom): int {
            $id = (int) Database::insert('admins', ['username' => $tag . $suffix, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => 'Fx1 Http ' . $suffix,
                'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
            $admins[] = $id;
            QuickUnlock::set($id, 'pin', '583941');           // the code-first door sends a PIN-less account to pin-setup first
            $page = $http($suffix, '/admin/login.php?classic=1&other=1');
            $http($suffix, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => $csrfFrom($page['body']), 'classic' => '1', 'username' => $tag . $suffix, 'password' => $pw]]);
            return $id;
        };
        /* the local sign-in budget (same reset the other HTTP suites do, e.g. u11-Y4): three test sign-ins
           from one loopback address would otherwise trip it on a re-run */
        $freeDoor = static function (): void {
            try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try', 'alert_ack_t')"); } catch (Throwable $e) {}
        };
        if ($http('', '/api/alert-feed.php')['code'] === 0) {
            check('dev server answers at ' . $base, false);
        } else {
            $freeDoor();
            check('HTTP feed without a session: 401', $http('', '/api/alert-feed.php')['code'] === 401);
            check('HTTP ack: GET refused (405), no session + no token 401, cron door 403 without its token',
                $http('', '/api/alert-ack.php')['code'] === 405 && $http('', '/api/alert-ack.php', ['json' => ['id' => 1]])['code'] === 401 && $http('', '/cron/alert-ring.php')['code'] === 403);
            $boss = $mkLogin('hboss', 'superadmin');
            $hsup = $mkLogin('hsup', 'support');
            $hviw = $mkLogin('hview', 'viewer');
            $pg   = $http('hboss', '/admin/alert-settings.php');
            $csrf = $csrfFrom($pg['body']);
            check('HTTP settings page: 200, no PHP warning, matrix + role prefs + test buttons + Save, ring script once', $pg['code'] === 200
                && !preg_match('~<b>(Warning|Notice|Fatal error|Deprecated)</b>|Uncaught~', $pg['body']) && str_contains($pg['body'], 'name="m[new_booking][roles][manager]"')
                && str_contains($pg['body'], 'name="p[manager][quiet_from]"') && str_contains($pg['body'], 'data-as-test="driver_sos"') && str_contains($pg['body'], '💾 सेभ गर्नुहोस्')
                && substr_count($pg['body'], '<script src="/assets/js/admin-ring.js?v=') === 1, 'HTTP ' . $pg['code']);
            check('…the owner column is ticked and locked', preg_match('~name="m\[new_booking\]\[roles\]\[superadmin\]" value="1" checked disabled~', $pg['body']) === 1);
            $ps    = $http('hsup', '/admin/alert-settings.php');
            $csrfS = $csrfFrom($ps['body']);
            check('HTTP settings page for support: readable, no Save, no test buttons', $ps['code'] === 200 && !str_contains($ps['body'], 'data-as-test=') && !str_contains($ps['body'], '💾 सेभ गर्नुहोस्'));

            $f = $http('hboss', '/api/alert-feed.php');
            check('HTTP feed: {ok, data{on, ring, now, rings, soft, push}}, no-store', $f['code'] === 200 && ($f['json']['ok'] ?? false) === true && ($f['json']['data']['on'] ?? null) === true
                && is_array($f['json']['data']['rings'] ?? null) && abs(($f['json']['data']['now'] ?? 0) - time()) < 10 && preg_match('/Cache-Control:\s*no-store/i', $f['headers']) === 1, mb_substr($f['body'], 0, 200));
            $t = $http('hboss', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'new_booking', 'via' => 'inapp'], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            $tid = (int) ($t['json']['id'] ?? 0);
            check('HTTP test button as super-admin: ok', $t['code'] === 200 && ($t['json']['ok'] ?? false) === true && $tid > 0, 'HTTP ' . $t['code'] . ' ' . mb_substr($t['body'], 0, 160));
            check('HTTP test button: support 403, no CSRF 400, unknown alert ok=false',
                $http('hsup', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'new_booking', 'via' => 'inapp'], 'headers' => ['X-CSRF-Token: ' . $csrfS]])['code'] === 403
                && $http('hboss', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'new_booking', 'via' => 'inapp']])['code'] === 400
                && ($http('hboss', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'zz', 'via' => 'inapp'], 'headers' => ['X-CSRF-Token: ' . $csrf]])['json']['ok'] ?? true) === false);
            check('HTTP notify-test: the old WhatsApp test gate is unchanged (support without routes.edit = 403)',
                $http('hsup', '/admin/notify-test.php', ['form' => ['channel' => 'whatsapp', 'phone' => ''], 'headers' => ['X-CSRF-Token: ' . $csrfS]])['code'] === 403);
            $inFeed = static fn(array $r, int $id): bool => array_filter($r['json']['data']['rings'] ?? [], static fn(array $x): bool => $x['id'] === $id) !== [];
            check('HTTP feed: the boss gets the test ring, support does not', $inFeed($http('hboss', '/api/alert-feed.php'), $tid) && !$inFeed($http('hsup', '/api/alert-feed.php'), $tid));
            /* a page the view-only account may open (follow its own redirects), for its CSRF token */
            $vHome = $http('hview', '/admin/index.php');
            for ($hop = 0; $hop < 4 && in_array($vHome['code'], [301, 302, 303], true) && preg_match('/^Location:\s*(\S+)/mi', $vHome['headers'], $lm) === 1; $hop++) {
                $to = (string) parse_url($lm[1], PHP_URL_PATH) . (parse_url($lm[1], PHP_URL_QUERY) ? '?' . parse_url($lm[1], PHP_URL_QUERY) : '');
                $vHome = $http('hview', str_starts_with($to, '/') ? $to : '/admin/' . $to);
            }
            $vTok = $csrfFrom($vHome['body']);
            $codes = [
                $http('hboss', '/api/alert-ack.php', ['json' => ['ids' => [$tid]]])['code'],
                $http('hsup', '/api/alert-ack.php', ['json' => ['ids' => [$tid]], 'headers' => ['X-CSRF-Token: ' . $csrfS]])['code'],
                $http('hview', '/api/alert-ack.php', ['json' => ['all' => 1], 'headers' => ['X-CSRF-Token: ' . $vTok]])['code'],
            ];
            /* with its token the viewer is refused for its ROLE (403); if no page gave it a token, the CSRF door refuses first (419) */
            check('HTTP ack: no CSRF 419; not yours 403; a viewer refused', $codes[0] === 419 && $codes[1] === 403 && $codes[2] === ($vTok !== '' ? 403 : 419),
                json_encode($codes) . ' viewer page HTTP ' . $vHome['code'] . ($vTok !== '' ? ' (token)' : ' (no token)'));
            $a = $http('hboss', '/api/alert-ack.php', ['json' => ['ids' => [$tid]], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            $b = $http('hboss', '/api/alert-ack.php', ['json' => ['ids' => [$tid]], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            check('HTTP ack with CSRF: acked 1, again 0, gone from the feed', ($a['json']['data']['acked'] ?? -1) === 1 && ($b['json']['data']['acked'] ?? -1) === 0
                && !$inFeed($http('hboss', '/api/alert-feed.php'), $tid), $a['body']);
            $t2   = $http('hboss', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'driver_sos', 'via' => 'inapp'], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            $tid2 = (int) ($t2['json']['id'] ?? 0);
            $bad  = $http('', '/api/alert-ack.php', ['json' => ['id' => $tid2, 'admin' => $boss, 't' => str_repeat('a', 32)]]);
            $oth  = $http('', '/api/alert-ack.php', ['json' => ['id' => $tid2, 'admin' => $hsup, 't' => AlertBus::ackToken($tid2, $boss)]]);
            $up   = $http('', '/api/alert-ack.php', ['json' => ['id' => $tid2, 'admin' => $boss, 't' => AlertBus::ackToken($tid2, $boss), 'all' => 1]]);
            check('HTTP token ack: a single-alert token sent with all=1 is refused (403)', $up['code'] === 403, 'HTTP ' . $up['code']);
            $none = Database::scalar('SELECT acked_at FROM alert_events WHERE id = :i', ['i' => $tid2], null);
            $good = $http('', '/api/alert-ack.php', ['json' => ['id' => $tid2, 'admin' => $boss, 't' => AlertBus::ackToken($tid2, $boss)]]);
            $rowT = Database::fetch('SELECT acked_by, ack_via FROM alert_events WHERE id = :i', ['i' => $tid2]);
            check('HTTP token ack (the notification\'s Seen, no cookie): wrong / another person\'s token 403, the right one acks via push', $bad['code'] === 403 && $oth['code'] === 403
                && $none === null && $good['code'] === 200 && (int) ($rowT['acked_by'] ?? 0) === $boss && ($rowT['ack_via'] ?? '') === 'push', $good['body']);
            /* a single-alert token must not carry a list: `ids` is not signed */
            $t3   = $http('hboss', '/admin/notify-test.php', ['form' => ['channel' => 'alert', 'event' => 'new_booking', 'via' => 'inapp'], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            $tid3 = (int) ($t3['json']['id'] ?? 0);
            $sm   = $http('', '/api/alert-ack.php', ['json' => ['id' => $tid2, 'admin' => $boss, 't' => AlertBus::ackToken($tid2, $boss), 'ids' => [$tid3]]]);
            check('HTTP token ack + ids[]: the list is ignored - a token for one alert can never mark another one seen', $tid3 > 0 && $sm['code'] === 200
                && ($sm['json']['data']['acked'] ?? -1) === 0 && Database::scalar('SELECT acked_at FROM alert_events WHERE id = :i', ['i' => $tid3], null) === null, 'HTTP ' . $sm['code'] . ' ' . $sm['body']);
            $http('hboss', '/api/alert-ack.php', ['json' => ['ids' => [$tid3]], 'headers' => ['X-CSRF-Token: ' . $csrf]]);

            /* the log on the settings page follows the matrix; the list of ringing phones is for whoever may edit */
            $raise('payment_confirmed', ['title' => '✅ httplog ' . $tag, 'body' => 'b'], $tag . ':hl1');
            $epH = $mkSub('h', $boss);
            AlertBus::pushOptIn($epH, $boss, true);
            $pgB = $http('hboss', '/admin/alert-settings.php');
            $pgS = $http('hsup', '/admin/alert-settings.php');
            check('HTTP settings page: support\'s log has no alert its role is not told about; the owner\'s has it', $pgB['code'] === 200 && $pgS['code'] === 200
                && str_contains($pgB['body'], 'httplog ' . $tag) && !str_contains($pgS['body'], 'httplog ' . $tag));
            check('HTTP settings page: "Phones that ring" + Remove only for whoever may edit; a Stop button for this phone for everyone', str_contains($pgB['body'], 'Fx1 test phone h')
                && str_contains($pgB['body'], 'name="action" value="phone_off"') && !str_contains($pgS['body'], 'Fx1 test phone h') && !str_contains($pgS['body'], 'phone_off')
                && str_contains($pgS['body'], 'id="asPushOff"') && !str_contains($pgB['body'], 'push.invalid'));
            $http('hsup', '/admin/alert-settings.php', ['form' => [CSRF_TOKEN_NAME => $csrfS, 'action' => 'phone_off', 'h' => sha1($epH)]]);
            $stillOn = AlertBus::phoneRings($epH, $boss);
            $rm = $http('hboss', '/admin/alert-settings.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'phone_off', 'h' => sha1($epH), 'o' => $boss]]);
            check('HTTP remove a ringing phone: support refused, the owner removes it', $stillOn && $rm['code'] === 200 && !AlertBus::phoneRings($epH) && !str_contains($rm['body'], 'Fx1 test phone h'));

            $p = $http('hboss', '/api/alert-push.php');
            $st0 = $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'status', 'endpoint' => $epH], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
            $un0 = $http('hsup', '/api/alert-push.php', ['json' => ['action' => 'unsubscribe', 'endpoint' => $epH], 'headers' => ['X-CSRF-Token: ' . $csrfS]]);
            check('HTTP alert-push status / unsubscribe: a handset that does not ring says ring=false, stopped=false', $st0['code'] === 200 && ($st0['json']['data']['ring'] ?? null) === false
                && $un0['code'] === 200 && ($un0['json']['data']['stopped'] ?? null) === false, $st0['body'] . ' ' . $un0['body']);
            if (($p['json']['data']['enabled'] ?? false) !== true) {
                check('HTTP alert-push subscribe flows (skipped: web push has no keys on this machine)', true);
            } else {
                $epR  = 'https://push.invalid/' . $tag . '/http';
                $subs[] = $epR;
                $subJ = ['endpoint' => $epR, 'keys' => ['p256dh' => WebPush::b64u(str_repeat("\x04", 65)), 'auth' => WebPush::b64u(str_repeat("\x01", 16))]];
                $q  = $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'subscribe', 'subscription' => $subJ], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
                $no = (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($epR)], 0);
                $a1h = $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'subscribe', 'ask' => 1, 'subscription' => $subJ], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
                $st1 = $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'status', 'endpoint' => $epR], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
                $st2 = $http('hsup', '/api/alert-push.php', ['json' => ['action' => 'status', 'endpoint' => $epR], 'headers' => ['X-CSRF-Token: ' . $csrfS]]);
                check('HTTP subscribe without the tap (quiet sync): nothing is bound, ring=false', $q['code'] === 200 && ($q['json']['data']['ring'] ?? null) === false && $no === 0, $q['body']);
                check('HTTP subscribe with the tap (ask=1): this handset rings for that person only', $a1h['code'] === 200 && ($a1h['json']['data']['ring'] ?? null) === true
                    && ($st1['json']['data']['ring'] ?? null) === true && ($st2['json']['data']['ring'] ?? null) === false && count(AlertBus::ringSubs($boss)) === 1, $a1h['body']);
                $q2 = $http('hsup', '/api/alert-push.php', ['json' => ['action' => 'subscribe', 'subscription' => $subJ], 'headers' => ['X-CSRF-Token: ' . $csrfS]]);
                check('HTTP quiet sync on a handset that already rings: it follows whoever is signed in (shared phone)', ($q2['json']['data']['ring'] ?? null) === true
                    && count(AlertBus::ringSubs($hsup)) === 1 && AlertBus::ringSubs($boss) === []);
                $un1 = $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'unsubscribe', 'endpoint' => $epR], 'headers' => ['X-CSRF-Token: ' . $csrf]]);
                $un2 = $http('hsup', '/api/alert-push.php', ['json' => ['action' => 'unsubscribe', 'endpoint' => $epR], 'headers' => ['X-CSRF-Token: ' . $csrfS]]);
                check('HTTP "Stop ringing this phone": not somebody else\'s; the owner\'s stops; the subscription row itself is kept', ($un1['json']['data']['stopped'] ?? null) === false
                    && ($un2['json']['data']['stopped'] ?? null) === true && AlertBus::ringSubs($hsup) === []
                    && (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint_hash = :h AND is_active = 1 AND admin_id = :a', ['h' => sha1($epR), 'a' => $hsup], 0) === 1);
            }
            $codes = [
                $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'subscribe']])['code'],
                $http('hboss', '/api/alert-push.php', ['json' => ['action' => 'subscribe', 'subscription' => ['endpoint' => 'http://x']], 'headers' => ['X-CSRF-Token: ' . $csrf]])['code'],
                $http('hview', '/api/alert-push.php')['code'],
            ];
            check('HTTP alert-push: status {enabled, publicKey}; POST without CSRF 419; bad subscription 422/503; viewer 403', $p['code'] === 200
                && array_key_exists('enabled', $p['json']['data'] ?? []) && array_key_exists('publicKey', $p['json']['data'] ?? [])
                && $codes[0] === 419 && in_array($codes[1], [422, 503], true) && $codes[2] === 403, json_encode($codes));

            $form = [CSRF_TOKEN_NAME => $csrf, 'action' => 'save', 'c' => ['alert_bus_on' => '1', 'alert_ring_on' => '1', 'alert_push_on' => '1', 'alert_ring_seconds' => '45',
                     'alert_ring_repeat_min' => '3', 'alert_ring_repeat_max' => '99', 'alert_ring_burst' => '6'], 'm' => [], 'p' => []];
            foreach (AlertBus::EVENTS as $ev => $def) { $form['m'][$ev] = ['roles' => array_fill_keys($def[5], '1')] + ($def[4] ? ['ring' => '1'] : []); }
            $form['m']['desk_ticket']['ring'] = '1';
            foreach (AlertBus::ROLES as $rr) { $form['p'][$rr] = ['sound' => '1', 'volume' => '70', 'quiet_from' => '', 'quiet_to' => '']; }
            $form['p']['counter'] = ['volume' => '40', 'quiet_from' => '22:00', 'quiet_to' => '06:00'];
            $sv = $http('hboss', '/admin/alert-settings.php', ['form' => $form]);
            Settings::flush();
            AlertBus::flush();
            check('HTTP save: stored + clamped (45 s, 3 min, 10 max), unticked switches off, matrix + role prefs kept, page shows them', $sv['code'] === 200 && str_contains($sv['body'], 'सेभ भयो')
                && Settings::getInt('alert_ring_seconds') === 45 && Settings::getInt('alert_ring_repeat_min') === 3 && Settings::getInt('alert_ring_repeat_max') === 10
                && Settings::getBool('alert_wa_on', true) === false && AlertBus::matrix()['desk_ticket']['ring'] === true
                && AlertBus::rolePrefs()['counter'] === ['sound' => false, 'volume' => 40, 'quiet_from' => '22:00', 'quiet_to' => '06:00']
                && str_contains($sv['body'], 'name="c[alert_ring_seconds]" value="45"'), 'HTTP ' . $sv['code']);
            $http('hsup', '/admin/alert-settings.php', ['form' => [CSRF_TOKEN_NAME => $csrfS, 'action' => 'save', 'c' => ['alert_wa_on' => '1']]]);
            $http('hboss', '/admin/alert-settings.php', ['form' => ['action' => 'save', 'c' => ['alert_wa_on' => '1']]]);
            Settings::flush();
            check('HTTP save by support, or without CSRF: refused, nothing changed', Settings::getBool('alert_wa_on', true) === false && Settings::getInt('alert_ring_seconds') === 45);
            foreach ([$tid, $tid2, $tid3] as $x) {
                $rk = Database::scalar('SELECT src_key FROM alert_events WHERE id = :i', ['i' => $x], null);
                if (is_string($rk)) { $srcKeys[] = $rk; }
            }
            $freeDoor();
        }
        foreach ($jars as $j) { @unlink($j); }
    }

    /* ---------------- 13. php -l ---------------- */
    $php = PHP_BINARY;
    $ini = php_ini_loaded_file();
    $bad = [];
    foreach (['includes/alertbus.php', 'includes/adminalerts.php', 'api/alert-feed.php', 'api/alert-ack.php', 'api/alert-push.php', 'admin/alert-settings.php',
              'admin/notify-test.php', 'admin/_guard.php', 'cron/alert-ring.php', 'api/push.php'] as $f) {
        $outL = (string) @shell_exec(escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1');
        if (!str_contains($outL, 'No syntax errors')) { $bad[] = $f; }
    }
    check('php -l: every touched PHP file parses (webpush.php is loaded by this test itself)', $bad === [], implode(', ', $bad));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    AlertBus::$pusher = null;
    AlertBus::$waSender = null;
    AlertBus::$recipients = null;
    actAs(null);
    try {
        $keys = array_merge($srcKeys, array_map(static fn(int $j): string => 'j:' . $j, $journal), array_map(static fn(int $b): string => 'bell:' . $b, array_filter($bells)));
        foreach (array_chunk(array_values(array_unique($keys)), 50) as $chunk) {
            $ph = [];
            $bind = [];
            foreach ($chunk as $i => $k) { $ph[] = ':k' . $i; $bind['k' . $i] = $k; }
            foreach (Database::fetchAll('SELECT id FROM alert_events WHERE src_key IN (' . implode(',', $ph) . ')', $bind) as $r) {
                Database::delete('alert_deliveries', 'alert_id = :a', ['a' => (int) $r['id']]);
                Database::delete('alert_events', 'id = :a', ['a' => (int) $r['id']]);
            }
        }
        foreach ($admins as $aid) {                       // any test: row a failed run left for these people
            foreach (Database::fetchAll('SELECT id FROM alert_events WHERE src_key LIKE :k', ['k' => 'test:' . $aid . ':%']) as $r) {
                Database::delete('alert_deliveries', 'alert_id = :a', ['a' => (int) $r['id']]);
                Database::delete('alert_events', 'id = :a', ['a' => (int) $r['id']]);
            }
        }
        foreach ($subs as $ep) {                          // this test's marker handsets and their ring switch
            try { Database::delete('alert_push_optin', 'endpoint_hash = :h', ['h' => sha1($ep)]); } catch (Throwable $ignored) {}
            $sid = (int) Database::scalar('SELECT id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($ep)], 0);
            if ($sid > 0) {
                try { Database::delete('push_log', 'subscription_id = :s', ['s' => $sid]); } catch (Throwable $ignored) {}
            }
            Database::delete('push_subscriptions', 'endpoint_hash = :h', ['h' => sha1($ep)]);
        }
        foreach ($admins as $aid) {
            try { Database::delete('alert_push_optin', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
            try { Database::delete('push_subscriptions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
        }
        foreach ($journal as $j) { Database::delete('automation_log', 'id = :i', ['i' => $j]); }
        foreach ($claims as $c) { Database::delete('automation_log', 'dedupe_key = :k', ['k' => $c]); }
        foreach (array_filter($bells) as $b) { Database::delete('notifications', 'id = :i', ['i' => $b]); }
        foreach ($bookings as $bid) {
            Database::delete('booking_passengers', 'booking_id = :b', ['b' => $bid]);
            Database::delete('bookings', 'id = :i', ['i' => $bid]);
        }
        foreach ($admins as $aid) {
            try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
            try { Database::run("DELETE FROM audit_logs WHERE id > :s AND entity_id = :a AND action LIKE 'agent.%'", ['s' => $startAudit, 'a' => (string) $aid]); } catch (Throwable $ignored) {}
            Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]);
            // what the opt-in HTTP sign-ins of these test people left
            try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
            try { Database::run("DELETE FROM rate_limits WHERE identifier = :i AND bucket IN ('alert_feed', 'alert_ack', 'alert_push')", ['i' => 'admin:' . $aid]); } catch (Throwable $ignored) {}
            try { Database::run("DELETE FROM audit_logs WHERE id > :s AND actor_type = 'admin' AND actor_id = :a", ['s' => $startAudit, 'a' => $aid]); } catch (Throwable $ignored) {}
            Database::delete('admins', 'id = :a', ['a' => $aid]);
        }
        foreach ($state as $k => $v) {
            if ($v === null) { Database::run('DELETE FROM alert_bus_state WHERE k = :k', ['k' => $k]); }
            else { Database::run('INSERT INTO alert_bus_state (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['k' => $k, 'v' => $v]); }
        }
        // the WhatsApp budget counter: back to what it was
        Database::run("DELETE FROM rate_limits WHERE bucket = 'alert_wa' AND identifier = 'office'");
        if (is_array($rlBefore)) {
            unset($rlBefore['id']);
            Database::insert('rate_limits', $rlBefore);
        }
        foreach ($settings as $key => $row) {
            Database::delete('settings', 'skey = :k', ['k' => $key]);
            if ($row !== null) {
                Database::insert('settings', $row);
            }
        }
        Settings::flush();
    } catch (Throwable $e) {
        check('cleanup', false, $e->getMessage());
    }
}

$left = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username LIKE :u', ['u' => $tag . '%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p', ['p' => $PNR . '%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM alert_events WHERE src_key LIKE :k', ['k' => $tag . ':%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM automation_log WHERE dedupe_key LIKE :k', ['k' => '%' . $tag . '%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint LIKE :e', ['e' => 'https://push.invalid/' . $tag . '/%'], 0);
foreach ($subs as $ep) {
    try { $left += (int) Database::scalar('SELECT COUNT(*) FROM alert_push_optin WHERE endpoint_hash = :h', ['h' => sha1($ep)], 0); } catch (Throwable $ignored) {}
}
check('cleanup left nothing behind', $left === 0, (string) $left);

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
