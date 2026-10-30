<?php
/**
 * TeamPurge::purgeWithHistory() — the delete WITH history (31 Oct 2026, owner:
 * "disable gareko purano member — baki ticket haru ni chhan — sab delete
 * gardeu, dhyan rakha" / "sab delete — customer name number for data").
 *
 * Builds three throwaway agents on a throwaway route:
 *   A  archived, 3 tickets (past confirmed, upcoming confirmed, cancelled),
 *      money rows, a chat thread, and "done by" rows on agent C's data
 *      → deleted in 'office' mode: every ticket stays, detached, noted.
 *   B  login off, 5 tickets (past, upcoming, cancelled with a placeholder
 *      phone, past shared with C as sub-agent, past for a customer whose
 *      name the office locked), a counter shift + expense
 *      → deleted in 'delete' mode: past / cancelled go with every row under
 *      them, upcoming + shared stay; the customers are saved first.
 *   C  an active agent: none of C's rows may change (except the "done by"
 *      names A left on them).
 * Also: the refusals (non-super actor, wrong confirm, bad mode, super-admin
 * target, own login), disabledMembers(), forcePreview(), the snapshot (v3)
 * and the customers CSV. Cleans up everything it made, snapshot files too.
 *
 *   php -c .claude/php-dev.ini tests/teampurge-force-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teampurge.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectRefusal(string $l, callable $fn, string $needle = ''): void
{
    try { $fn(); check($l . ' (expected refusal)', false); }
    catch (TeamPurgeError $e) { check($l . ' → "' . mb_strimwidth($e->getMessage(), 0, 90, '…') . '"', $needle === '' || str_contains($e->getMessage(), $needle)); }
    catch (Throwable $e) { check($l . ' → unexpected ' . get_class($e) . ': ' . $e->getMessage(), false); }
}
function cnt(string $sql, array $p = []): int { return (int) Database::scalar($sql, $p, 0); }

$tag = 'tpf' . bin2hex(random_bytes(3));
$made = ['admins' => [], 'bookings' => [], 'schedules' => [], 'routes' => [], 'office_tasks' => [], 'phones' => [], 'files' => [], 'backups' => []];
$profileBefore = [];

$cleanup = static function () use (&$made, &$profileBefore): void {
    $bids = $made['bookings'];
    foreach ($made['admins'] as $aid) {
        foreach (['sold_by_admin_id', 'sub_agent_admin_id', 'parent_agent_id'] as $c) {
            foreach (Database::fetchAll("SELECT id FROM bookings WHERE $c = :a", ['a' => $aid]) as $r) { $bids[] = (int) $r['id']; }
        }
    }
    $bids = array_values(array_unique($bids));
    if ($bids !== []) {
        $in = implode(',', array_map('intval', $bids));
        foreach (['ticket_links', 'message_logs', 'trip_events', 'agent_ledger', 'payment_reviews', 'delivery_log', 'wa_ticket_queue'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id IN ($in)"); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM bookings WHERE id IN ($in)"); } catch (Throwable $e) { echo "  cleanup bookings: {$e->getMessage()}\n"; }
    }
    foreach ($made['admins'] as $aid) {
        foreach ([['agent_ledger', 'agent_admin_id'], ['agent_settlements', 'agent_admin_id'], ['agent_payout_requests', 'agent_admin_id'],
                  ['ap_deposit_requests', 'admin_id'], ['staff_threads', 'agent_admin_id'], ['offline_tickets', 'agent_admin_id'],
                  ['ap_members', 'admin_id'], ['admin_profiles', 'admin_id']] as [$t, $c]) {
            try { Database::run("DELETE FROM $t WHERE $c = :a", ['a' => $aid]); } catch (Throwable $e) {}
        }
        try {
            foreach (Database::fetchAll('SELECT id FROM counter_shifts WHERE admin_id = :a', ['a' => $aid]) as $r) {
                Database::run('DELETE FROM counter_shift_expenses WHERE shift_id = :s', ['s' => $r['id']]);
            }
            Database::run('DELETE FROM counter_shifts WHERE admin_id = :a', ['a' => $aid]);
        } catch (Throwable $e) {}
        try { if (AgentWallet::agentCodeFor($aid) !== null) { AgentWallet::setAgentCode($aid, null, 0); } } catch (Throwable $e) {}
    }
    foreach ($made['office_tasks'] as $id) { try { Database::run('DELETE FROM office_tasks WHERE id = :i', ['i' => $id]); } catch (Throwable $e) {} }
    if ($made['admins'] !== []) {
        try { Database::run('DELETE FROM admins WHERE id IN (' . implode(',', array_map('intval', $made['admins'])) . ')'); } catch (Throwable $e) { echo "  cleanup admins: {$e->getMessage()}\n"; }
    }
    foreach ($made['schedules'] as $id) { try { Database::run('DELETE FROM schedules WHERE id = :i', ['i' => $id]); } catch (Throwable $e) { echo "  cleanup schedule: {$e->getMessage()}\n"; } }
    foreach ($made['routes'] as $id) { try { Database::run('DELETE FROM routes WHERE id = :i', ['i' => $id]); } catch (Throwable $e) {} }
    foreach ($made['phones'] as $ph) {
        try {
            if (isset($profileBefore[$ph])) {
                $row = $profileBefore[$ph];
                unset($row['customer_no']);
                Database::update('user_profiles', $row, 'phone = :p', ['p' => $ph]);
            } else {
                Database::run('DELETE FROM user_profiles WHERE phone = :p', ['p' => $ph]);
            }
        } catch (Throwable $e) {}
    }
    // The archive map keys of our people.
    try {
        $map = Settings::getArray(TeamPurge::ARCHIVE_KEY, []);
        $n = count($map);
        foreach ($made['admins'] as $aid) { unset($map['account:' . $aid]); }
        if (count($map) !== $n) { Settings::set(TeamPurge::ARCHIVE_KEY, $map, 'json', 'agent', false); }
    } catch (Throwable $e) {}
    $rm = static function (string $p) use (&$rm): void {
        if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) { if ($n !== '.' && $n !== '..') { $rm($p . '/' . $n); } } @rmdir($p); }
        elseif (is_file($p)) { @unlink($p); }
    };
    foreach ($made['files'] as $f) { $rm($f); }
    foreach ($made['backups'] as $f) { $rm($f); }
    Settings::flush();
};

try {
    echo "Setup ($tag)\n";
    $now = date('Y-m-d H:i:s');
    $mkAdmin = static function (string $u, string $role, int $active) use (&$made, $tag): int {
        $id = Database::insert('admins', ['username' => $tag . '-' . $u, 'password_hash' => password_hash('x' . $tag, PASSWORD_DEFAULT),
            'full_name' => 'TPF ' . ucfirst($u), 'role' => $role, 'is_active' => $active, 'must_change_pw' => 0,
            'phone' => '93' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT)]);
        $made['admins'][] = $id;
        return $id;
    };
    $super  = $mkAdmin('super', 'superadmin', 1);
    $super2 = $mkAdmin('super2', 'superadmin', 1);
    $A = $mkAdmin('agent-a', 'agent', 1);
    $B = $mkAdmin('agent-b', 'agent', 0);
    $C = $mkAdmin('agent-c', 'agent', 1);
    $actor = ['id' => $super, 'role' => 'superadmin', 'username' => $tag . '-super'];

    $route = Database::insert('routes', ['route_code' => strtoupper($tag), 'from_city' => 'Testpur', 'to_city' => 'Nowhere', 'dep_time' => '18:00:00', 'arr_time' => '08:00:00']);
    $made['routes'][] = $route;
    $past = Database::insert('schedules', ['route_id' => $route, 'travel_date' => '2020-01-15']);
    $future = Database::insert('schedules', ['route_id' => $route, 'travel_date' => '2099-10-01']);
    $made['schedules'][] = $past; $made['schedules'][] = $future;

    $seat = 0;
    $newPhone = '98' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $lockPhone = '97' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $made['phones'] = [$newPhone, $lockPhone];
    $ticketDir = TICKET_PATH;
    if (!is_dir($ticketDir)) { @mkdir($ticketDir, 0775, true); }

    /** One booking with a leg, a seat, a passenger, a ticket (+ a PDF file), a payment, a short link and a message log. */
    $mkBooking = static function (array $b, int $sid, string $date, string $pax, string $phone) use (&$made, &$seat, $tag, $ticketDir): int {
        $pnr = strtoupper($tag) . str_pad((string) (count($made['bookings']) + 1), 3, '0', STR_PAD_LEFT);
        $id = Database::insert('bookings', $b + ['pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => '91',
            'total_amount' => 1500, 'source' => 'agent', 'fare_per_seat' => 1500, 'base_total' => 1500]);
        $made['bookings'][] = $id;
        $leg = Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => $sid, 'travel_date' => $date, 'leg_type' => 'outbound', 'seat_count' => 1, 'leg_total' => 1500]);
        $seat++;
        Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => 'Z' . $seat, 'booking_id' => $id, 'leg_id' => $leg]);
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => $pnr . '-1', 'seat_no' => 'Z' . $seat, 'full_name' => $pax, 'is_primary' => 1]);
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => $pnr . '-2', 'seat_no' => 'Z' . $seat, 'full_name' => $pax . ' Jr', 'is_primary' => 0]);
        $pdf = 'ticket_' . $pnr . '.pdf';
        file_put_contents($ticketDir . '/' . $pdf, '%PDF-1.4 test ' . $pnr);
        $made['files'][] = $ticketDir . '/' . $pdf;
        Database::insert('tickets', ['booking_id' => $id, 'ticket_number' => 'T' . $pnr, 'qr_payload' => 'x', 'qr_hash' => hash('sha256', $pnr),
            'issued_at' => date('Y-m-d H:i:s'), 'pdf_path' => $pdf]);
        Database::insert('payments', ['booking_id' => $id, 'payment_ref' => 'P' . $pnr, 'amount' => 1500, 'status' => 'verified']);
        Database::insert('ticket_links', ['booking_id' => $id, 'short_code' => substr($tag . $pnr, -16), 'full_url' => 'https://x/' . $pnr, 'expires_at' => '2099-01-01 00:00:00']);
        Database::insert('message_logs', ['booking_id' => $id, 'to_number' => $phone, 'body' => 'test']);
        return $id;
    };

    // ---- A: three tickets, money, chat, and "done by" rows on C's data
    $a1 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $A, 'admin_note' => str_repeat('n', 250)], $past, '2020-01-15', 'Ram A Past', '98' . substr($newPhone, 2, 7) . '9');
    $a2 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $A], $future, '2099-10-01', 'Ram A Future', '9811100002');
    $a3 = $mkBooking(['status' => 'cancelled', 'sold_by_admin_id' => $A], $future, '2099-10-01', 'Ram A Cancel', '9811100003');
    Database::insert('agent_ledger', ['agent_admin_id' => $A, 'booking_id' => $a1, 'entry_type' => 'commission', 'amount' => 75]);
    Database::insert('agent_ledger', ['agent_admin_id' => $A, 'entry_type' => 'adjustment', 'amount' => -20, 'note' => 'fine']);
    Database::insert('agent_settlements', ['agent_admin_id' => $A, 'period_from' => '2020-01-01', 'period_to' => '2020-01-31']);
    Database::insert('agent_payout_requests', ['agent_admin_id' => $A, 'amount' => 50, 'status' => 'paid']);
    Database::insert('ap_deposit_requests', ['admin_id' => $A, 'amount' => 500, 'purpose' => 'security', 'method' => 'upi', 'status' => 'confirmed']);
    $thread = Database::insert('staff_threads', ['agent_admin_id' => $A, 'subject' => 'test', 'msg_count' => 1]);
    Database::insert('staff_messages', ['thread_id' => $thread, 'sender_admin_id' => $A, 'side' => 'staff', 'body' => 'hello']);
    // C's own data that A only recorded / checked
    $c1 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $C], $past, '2020-01-15', 'Cust of C', '9811100009');
    $cLedger = Database::insert('agent_ledger', ['agent_admin_id' => $C, 'booking_id' => $c1, 'entry_type' => 'commission', 'amount' => 60, 'created_by' => $A]);
    Database::update('payments', ['verified_by' => $A], 'booking_id = :b', ['b' => $c1]);
    $task = Database::insert('office_tasks', ['title' => 'tpf task', 'assigned_to' => $A, 'created_by' => $C, 'created_at' => $now, 'updated_at' => $now]);
    $made['office_tasks'][] = $task;
    TeamPurge::archive($A, false, $actor, 'test archive');

    // ---- B: five tickets, a counter shift, money
    $profileBefore[$lockPhone] = null;
    Database::insert('user_profiles', ['phone' => $lockPhone, 'full_name' => 'Locked Name', 'name_locked' => 1, 'travel_count' => 4, 'lifetime_value' => 6000, 'notes' => 'VIP']);
    unset($profileBefore[$lockPhone]);   // our own row: the cleanup deletes it
    $b1 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $B], $past, '2020-01-15', 'Sita Test Kumari', $newPhone);
    $b2 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $B], $future, '2099-10-01', 'Hari Upcoming', '9811100012');
    $b3 = $mkBooking(['status' => 'cancelled', 'sold_by_admin_id' => $B], $future, '2099-10-01', 'Walk In', '0000000000');
    $b4 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $B, 'sub_agent_admin_id' => $C], $past, '2020-01-15', 'Shared Pax', '9811100014');
    $b5 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $B], $past, '2020-01-15', 'Wrong Name', $lockPhone);
    // C sold it, A was the parent agent: shared, so it stays in both modes; the note must say "parent agent"
    $a5 = $mkBooking(['status' => 'confirmed', 'sold_by_admin_id' => $C, 'parent_agent_id' => $A], $past, '2020-01-15', 'Parent Case', '9811100015');
    Database::insert('agent_ledger', ['agent_admin_id' => $B, 'booking_id' => $b1, 'entry_type' => 'commission', 'amount' => 75]);
    $shift = Database::insert('counter_shifts', ['admin_id' => $B, 'opened_at' => $now]);
    Database::insert('counter_shift_expenses', ['shift_id' => $shift, 'category' => 'tea', 'amount' => 20, 'created_at' => $now]);
    Database::insert('offline_tickets', ['agent_admin_id' => $B, 'paper_ticket_no' => $tag . '-1', 'travel_date' => '2020-01-15', 'passenger_name' => 'Paper Pax', 'booking_id' => $b1]);
    $ph0 = Database::fetch("SELECT * FROM user_profiles WHERE phone = '0000000000'");
    TeamPurge::resetCache();
    $filesBefore = count(glob(BACKUP_PATH . '/team-purge/*') ?: []);

    echo "\nRefusals\n";
    expectRefusal('non-super actor', fn() => TeamPurge::purgeWithHistory('admin', $A, ['id' => $C, 'role' => 'manager'], 'DELETE ALL'), 'super-admin');
    expectRefusal('wrong confirm word (DELETE)', fn() => TeamPurge::purgeWithHistory('admin', $A, $actor, 'DELETE'), 'DELETE ALL');
    expectRefusal('unknown tickets mode', fn() => TeamPurge::purgeWithHistory('admin', $A, $actor, 'DELETE ALL', '', 'everything'));
    expectRefusal('super-admin target', fn() => TeamPurge::purgeWithHistory('admin', $super2, $actor, 'DELETE ALL'), 'super');
    expectRefusal('own login', fn() => TeamPurge::purgeWithHistory('admin', $A, ['id' => $A, 'role' => 'superadmin'], 'DELETE ALL'), 'own');
    check('refusals changed nothing (A still has 3 tickets + its login)',
        cnt('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a', ['a' => $A]) === 3 && cnt('SELECT COUNT(*) FROM admins WHERE id = :a', ['a' => $A]) === 1);
    check('a refused run leaves no backup file behind', count(glob(BACKUP_PATH . '/team-purge/*') ?: []) === $filesBefore);

    echo "\nList + preview\n";
    $dis = array_column(TeamPurge::disabledMembers($super), 'id');
    check('disabledMembers lists archived A and login-off B', in_array($A, $dis, true) && in_array($B, $dis, true));
    check('disabledMembers never lists a super-admin or active C', !in_array($super2, $dis, true) && !in_array($C, $dis, true));
    $h = TeamPurge::history('admin', $A);
    check('history() still blocks the normal delete of A', $h['blocks'] !== [] && !$h['purgeable']);
    $fa = TeamPurge::forcePreview('admin', $A);
    check('preview A: 4 tickets, 1 upcoming, 2 past, 1 cancelled, 1 shared', $fa['bookings'] === 4 && $fa['upcoming'] === 1 && $fa['past'] === 2
        && $fa['cancelled'] === 1 && $fa['shared'] === 1);
    check('preview A: delete mode would delete 2, keep 2', $fa['delete']['bookings_deleted'] === 2 && $fa['delete']['bookings_detached'] === 2);
    check('preview A: money rows counted (' . $fa['money_rows'] . ')', $fa['money_rows'] >= 6);
    check('preview A: "done by" rows on others counted (' . $fa['actor_rows'] . ')', $fa['actor_rows'] >= 3);
    check('preview A: NOT NULL office_tasks.assigned_to reported as left', (bool) array_filter($fa['actor_left'], static fn(string $s): bool => str_starts_with($s, 'office_tasks.assigned_to')));
    $fb = TeamPurge::forcePreview('admin', $B);
    check('preview B: delete 3, keep 2 (1 upcoming + 1 shared)', $fb['delete']['bookings_deleted'] === 3 && $fb['delete']['kept_upcoming'] === 1 && $fb['delete']['kept_shared'] === 1);
    check('preview B: 2 customers kept, 1 placeholder skipped', $fb['delete']['customers'] === 2 && $fb['delete']['customers_skipped'] === 1);
    $html = TeamPurge::panelHtml($h, '/admin/team-member.php?id=' . $A, ['action' => 'purge'], '', true);
    check('panel shows the red DELETE ALL form to a super-admin', str_contains($html, 'name="tickets" value="delete"') && str_contains($html, 'value="purge_all"') && str_contains($html, 'pattern="DELETE ALL"'));
    check('panel hides it from a non-super', !str_contains(TeamPurge::panelHtml($h, '/x', ['action' => 'purge'], '', false), 'purge_all'));

    echo "\nOffice mode (A)\n";
    $ra = TeamPurge::purgeWithHistory('admin', $A, $actor, ' DELETE ALL ', 'test office mode', 'office');
    $made['backups'][] = BACKUP_PATH . '/team-purge/' . $ra['backup'];
    $made['backups'][] = BACKUP_PATH . '/team-purge/' . preg_replace('/\.json\.gz$/', '', $ra['backup']) . '-files';
    echo '  ' . TeamPurge::resultText($ra) . "\n";
    check('result: 0 deleted, 4 detached, 1 upcoming', $ra['bookings_deleted'] === 0 && $ra['bookings_detached'] === 4 && $ra['kept_upcoming'] === 1);
    check('all 4 tickets of A still exist (with legs + tickets)',
        cnt("SELECT COUNT(*) FROM bookings WHERE id IN ($a1,$a2,$a3,$a5)") === 4 && cnt("SELECT COUNT(*) FROM booking_legs WHERE booking_id IN ($a1,$a2,$a3,$a5)") === 4
        && cnt("SELECT COUNT(*) FROM tickets WHERE booking_id IN ($a1,$a2,$a3,$a5)") === 4);
    check('…and none names A any more', cnt('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a OR sub_agent_admin_id = :b OR parent_agent_id = :c', ['a' => $A, 'b' => $A, 'c' => $A]) === 0
        && cnt("SELECT COUNT(*) FROM bookings WHERE id IN ($a1,$a2,$a3) AND sold_by_admin_id IS NULL") === 3);
    check('parent-agent ticket: seller C stays, note says "Parent agent deleted"', cnt('SELECT COUNT(*) FROM bookings WHERE id = :b AND sold_by_admin_id = :c AND parent_agent_id IS NULL AND admin_note = :n',
        ['b' => $a5, 'c' => $C, 'n' => '[Parent agent deleted: TPF Agent-a #' . $A . ']']) === 1);
    $note = (string) Database::scalar('SELECT admin_note FROM bookings WHERE id = :b', ['b' => $a1], '');
    check('office note: "[Sold by deleted agent … #' . $A . ']" fits the 255 column', str_ends_with($note, '#' . $A . ']') && str_contains($note, '[Sold by deleted agent TPF Agent-a') && mb_strlen($note) <= 255);
    check('note on an empty admin_note is just the tag', str_starts_with((string) Database::scalar('SELECT admin_note FROM bookings WHERE id = :b', ['b' => $a2], ''), '[Sold by deleted agent'));
    check('A\'s money rows are gone (ledger, settlements, payouts, payments-in, chat)',
        cnt('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $A]) === 0 && cnt('SELECT COUNT(*) FROM agent_settlements WHERE agent_admin_id = :a', ['a' => $A]) === 0
        && cnt('SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $A]) === 0 && cnt('SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a', ['a' => $A]) === 0
        && cnt('SELECT COUNT(*) FROM staff_threads WHERE agent_admin_id = :a', ['a' => $A]) === 0 && cnt('SELECT COUNT(*) FROM staff_messages WHERE thread_id = :t', ['t' => $thread]) === 0);
    check('C\'s ledger row stays, its "created_by" is cleared', (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE id = :i AND agent_admin_id = :c AND created_by IS NULL', ['i' => $cLedger, 'c' => $C], 0) === 1);
    check('C\'s payment stays, "verified_by" cleared', cnt('SELECT COUNT(*) FROM payments WHERE booking_id = :b AND verified_by IS NULL', ['b' => $c1]) === 1);
    check('NOT NULL office task left as it is and reported', cnt('SELECT COUNT(*) FROM office_tasks WHERE id = :i AND assigned_to = :a', ['i' => $task, 'a' => $A]) === 1
        && (bool) array_filter($ra['actor_rows_left'], static fn(string $s): bool => str_starts_with($s, 'office_tasks.assigned_to')));
    check('A\'s login, card and archive entry are gone', cnt('SELECT COUNT(*) FROM admins WHERE id = :a', ['a' => $A]) === 0
        && cnt('SELECT COUNT(*) FROM ap_members WHERE admin_id = :a', ['a' => $A]) === 0 && TeamPurge::archived($A) === null);
    check('office mode keeps the ticket files', is_file(TICKET_PATH . '/ticket_' . strtoupper($tag) . '001.pdf'));
    $snapFile = BACKUP_PATH . '/team-purge/' . $ra['backup'];
    $snap = is_file($snapFile) ? json_decode((string) gzdecode((string) file_get_contents($snapFile)), true) : null;
    check('snapshot written (v3, purge_all, tickets=office)', is_array($snap) && $snap['version'] === 3 && $snap['what'] === 'purge_all' && $snap['tickets'] === 'office');
    check('snapshot holds A\'s login, ledger rows and settlement', is_array($snap) && count($snap['rows']['admins'] ?? []) === 1
        && count($snap['rows']['agent_ledger'] ?? []) === 2 && count($snap['rows']['agent_settlements'] ?? []) === 1);
    check('snapshot holds the before-image of the 4 detached tickets', is_array($snap)
        && count(array_filter($snap['changed']['bookings'] ?? [], static fn(array $r): bool => (int) $r['sold_by_admin_id'] === $A || (int) $r['parent_agent_id'] === $A)) === 4);
    check('snapshot holds C\'s ledger row as it was (created_by = A)', is_array($snap)
        && (bool) array_filter($snap['changed']['agent_ledger'] ?? [], static fn(array $r): bool => (int) $r['id'] === $cLedger && (int) $r['created_by'] === $A));
    check('snapshot never holds a password hash', is_array($snap) && ($snap['rows']['admins'][0]['password_hash'] ?? '') === '[removed]');
    unset($snap);

    echo "\nDelete mode (B)\n";
    $rb = TeamPurge::purgeWithHistory('admin', $B, $actor, 'DELETE ALL', 'test delete mode', 'delete');
    $made['backups'][] = BACKUP_PATH . '/team-purge/' . $rb['backup'];
    $made['backups'][] = BACKUP_PATH . '/team-purge/' . preg_replace('/\.json\.gz$/', '', $rb['backup']) . '-files';
    if (($rb['customers_csv'] ?? '') !== '') { $made['backups'][] = BACKUP_PATH . '/team-purge/' . $rb['customers_csv']; }
    echo '  ' . TeamPurge::resultText($rb) . "\n";
    check('result: 3 deleted, 2 kept (1 upcoming, 1 shared)', $rb['bookings_deleted'] === 3 && $rb['bookings_detached'] === 2 && $rb['kept_upcoming'] === 1 && $rb['kept_shared'] === 1);
    check('past + cancelled + locked-customer tickets are gone', cnt("SELECT COUNT(*) FROM bookings WHERE id IN ($b1,$b3,$b5)") === 0);
    $gone = "$b1,$b3,$b5";
    $left = 0;
    foreach (['booking_legs', 'booking_seats', 'booking_passengers', 'tickets', 'payments', 'ticket_links', 'message_logs'] as $t) {
        $left += cnt("SELECT COUNT(*) FROM $t WHERE booking_id IN ($gone)");
    }
    check('…with every row under them (legs, seats, passengers, tickets, payments, links, messages)', $left === 0);
    check('upcoming ticket kept, detached, noted', cnt('SELECT COUNT(*) FROM bookings WHERE id = :b AND sold_by_admin_id IS NULL AND admin_note LIKE :n', ['b' => $b2, 'n' => '%[Sold by deleted agent%']) === 1
        && cnt('SELECT COUNT(*) FROM booking_legs WHERE booking_id = :b', ['b' => $b2]) === 1);
    check('shared ticket kept: B removed, sub-agent C still on it', cnt('SELECT COUNT(*) FROM bookings WHERE id = :b AND sold_by_admin_id IS NULL AND sub_agent_admin_id = :c', ['b' => $b4, 'c' => $C]) === 1);
    check('B\'s counter shift + its expense, ledger and paper ticket are gone', cnt('SELECT COUNT(*) FROM counter_shifts WHERE admin_id = :a', ['a' => $B]) === 0
        && cnt('SELECT COUNT(*) FROM counter_shift_expenses WHERE shift_id = :s', ['s' => $shift]) === 0
        && cnt('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $B]) === 0 && cnt('SELECT COUNT(*) FROM offline_tickets WHERE agent_admin_id = :a', ['a' => $B]) === 0);
    check('ticket PDF of a deleted booking left the tickets folder', !is_file(TICKET_PATH . '/ticket_' . strtoupper($tag) . '005.pdf'));
    check('ticket PDF of the kept upcoming booking is still there', is_file(TICKET_PATH . '/ticket_' . strtoupper($tag) . '006.pdf'));
    $np = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => $newPhone]);
    check('new customer saved: phone + primary passenger name, 1 trip, channel agent, note',
        $np !== null && $np['full_name'] === 'Sita Test Kumari' && (int) $np['travel_count'] === 1 && (float) $np['lifetime_value'] === 1500.0
        && str_contains((string) $np['channels'], 'agent') && str_contains((string) $np['notes'], 'kept from deleted tickets of agent TPF Agent-b') && $np['last_travel_date'] === '2020-01-15'
        && $np['country_code'] === '91');
    $lp = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => $lockPhone]);
    check('locked name NOT overwritten; trips + value added; note appended',
        $lp !== null && $lp['full_name'] === 'Locked Name' && (int) $lp['travel_count'] === 5 && (float) $lp['lifetime_value'] === 7500.0
        && str_starts_with((string) $lp['notes'], "VIP\n") && str_contains((string) $lp['notes'], '(1 trip)'));
    $ph0b = Database::fetch("SELECT * FROM user_profiles WHERE phone = '0000000000'");
    check('placeholder 0000000000 never saved / touched', $ph0b == $ph0);
    check('result: 1 new + 1 known customer, 1 skipped', $rb['customers_new'] === 1 && $rb['customers_kept'] === 1 && $rb['customers_skipped'] === 1);
    $csvFile = BACKUP_PATH . '/team-purge/' . ($rb['customers_csv'] ?? '');
    $csv = is_file($csvFile) ? (string) file_get_contents($csvFile) : '';
    check('customers CSV next to the snapshot lists both numbers', $csv !== '' && str_contains($csv, $newPhone) && str_contains($csv, $lockPhone) && str_contains($csv, 'Sita Test Kumari Jr'));
    $snap = json_decode((string) gzdecode((string) file_get_contents(BACKUP_PATH . '/team-purge/' . $rb['backup'])), true);
    $sb = array_map('intval', array_column($snap['rows']['bookings'] ?? [], 'id'));
    sort($sb);
    check('snapshot holds the 3 deleted bookings', $sb === [$b1, $b3, $b5]);
    check('snapshot holds their legs, seats, passengers, tickets, payments', count($snap['rows']['booking_legs'] ?? []) === 3 && count($snap['rows']['booking_seats'] ?? []) === 3
        && count($snap['rows']['booking_passengers'] ?? []) === 6 && count($snap['rows']['tickets'] ?? []) === 3 && count($snap['rows']['payments'] ?? []) === 3);
    check('snapshot holds the locked profile as it was', (bool) array_filter($snap['changed']['user_profiles'] ?? [], static fn(array $r): bool => $r['phone'] === $lockPhone && (int) $r['travel_count'] === 4));
    check('snapshot embeds the deleted ticket PDF', array_key_exists('tickets/ticket_' . strtoupper($tag) . '005.pdf', $snap['files'] ?? []));
    unset($snap);

    echo "\nUntouched\n";
    check('C\'s login, ticket, ledger row are all still there', cnt('SELECT COUNT(*) FROM admins WHERE id = :c', ['c' => $C]) === 1
        && cnt('SELECT COUNT(*) FROM bookings WHERE id = :b AND sold_by_admin_id = :c', ['b' => $c1, 'c' => $C]) === 1
        && cnt('SELECT COUNT(*) FROM booking_legs WHERE booking_id = :b', ['b' => $c1]) === 1 && cnt('SELECT COUNT(*) FROM agent_ledger WHERE id = :i', ['i' => $cLedger]) === 1);
    check('the schedules both agents sold on are still there', cnt("SELECT COUNT(*) FROM schedules WHERE id IN ($past,$future)") === 2);
    check('the other super-admin is untouched', cnt('SELECT COUNT(*) FROM admins WHERE id = :s', ['s' => $super2]) === 1);
    check('both runs are in the audit log (team.purged_all)', cnt("SELECT COUNT(*) FROM audit_logs WHERE action = 'team.purged_all' AND entity_id IN (:r1, :r2)",
        ['r1' => 'ACC-' . $A, 'r2' => 'ACC-' . $B]) === 2);
} catch (Throwable $e) {
    check('test run threw ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
    $left = cnt('SELECT COUNT(*) FROM admins WHERE username LIKE :t', ['t' => $tag . '-%']) + cnt('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :t', ['t' => strtoupper($tag) . '%'])
          + cnt('SELECT COUNT(*) FROM user_profiles WHERE phone IN (:p1, :p2)', ['p1' => $newPhone ?? '', 'p2' => $lockPhone ?? '']);
    check('cleanup: nothing of this run is left (rows, snapshots, CSV, ticket files)', $left === 0
        && array_filter($made['backups'], 'file_exists') === []
        && glob(TICKET_PATH . '/ticket_' . strtoupper($tag) . '*') === []);
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
