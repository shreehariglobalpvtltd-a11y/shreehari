<?php
/**
 * AI Watch — the "what needs you now" panel (includes/aiwatch.php, 2 Oct 2026).
 * CLI only, DB-backed.
 *
 *   php tests/aiwatch-test.php
 *
 * Locks in:
 *   • every suggestion has the 7 keys and a known level, and the list is
 *     ranked high → warn → info → ok;
 *   • permission gating: a probe that denies everything yields nothing, a
 *     probe that allows only customers.view yields only customer items;
 *   • a new enquiry / an open support request / an offline sale waiting
 *     each raise their line, and it goes away when the row is handled;
 *   • a pickup with no time is named;
 *   • the seller list never throws for an unknown id and only links to
 *     pages a seller has;
 *   • render(): calm "all clear" for an empty list, escapes text, folds
 *     anything past SHOW behind "Show all".
 *   • 15 Oct 2026 (dashboard): every office card opens a page the role it
 *     is shown to may open (each role's own permission list, read from
 *     Auth); the payments card counts Verify Payments' proof list; the
 *     agent-cash card opens Settlements; "Plan extra bus" needs
 *     schedules.view; the new payout and cancel / refund request cards
 *     come and go with their rows and never link a refused page; a
 *     counter clerk's own cards open counter screens (cash book / shift,
 *     tomorrow's manifest, only-my-pending) and carry no commission card.
 *
 * Throwaway rows tagged 'AIW-TEST' / PNR 'AIWT-'; cleans up after itself.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/aiwatch.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function keysOf(array $items): array { return array_map(static fn(array $s): string => (string) $s['key'], $items); }
function hasKey(array $items, string $prefix): bool {
    foreach ($items as $s) { if (str_starts_with((string) $s['key'], $prefix)) { return true; } }
    return false;
}
function cleanup(): void {
    Database::run("DELETE FROM enquiries WHERE note = 'AIW-TEST'");
    Database::run("DELETE FROM support_tickets WHERE ticket_ref LIKE 'AIW-TEST%'");
    Database::run("DELETE FROM offline_requests WHERE note = 'AIW-TEST'");
    // 15 Oct 2026: throwaway bookings (PNR AIWT-…) and what hangs off them
    $ids = array_map('intval', array_column(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'AIWT-%'"), 'id'));
    if ($ids !== []) {
        $in = implode(',', $ids);
        foreach (['ticket_edit_audit', 'agent_ledger', 'payments', 'booking_seats', 'booking_passengers', 'booking_legs'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id IN ($in)"); } catch (Throwable $e) { /* table absent */ }
        }
        Database::run("DELETE FROM bookings WHERE id IN ($in)");
    }
    try { Database::run("DELETE FROM agent_payout_requests WHERE note = 'AIW-TEST'"); } catch (Throwable $e) { /* not migrated */ }
}
/** A throwaway booking (PNR AIWT-…), optionally with an outbound leg. */
function seedBooking(array $b, ?int $scheduleId = null, ?string $travel = null): int {
    $id = Database::insert('bookings', $b + [
        'pnr' => 'AIWT-' . strtoupper(bin2hex(random_bytes(4))), 'contact_phone' => '9100000778',
        'total_amount' => 1000, 'status' => 'pending',
    ]);
    if ($scheduleId !== null) {
        Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => $scheduleId, 'leg_type' => 'outbound',
            'travel_date' => $travel ?? todayISO(), 'boarding_stop' => 'Test', 'drop_stop' => 'Test']);
    }
    return $id;
}
function pnrOf(int $id): string { return (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $id]); }
function item(array $items, string $key): array {
    foreach ($items as $s) { if ($s['key'] === $key) { return $s; } }
    return [];
}

$all  = static fn(string $p): bool => true;
$none = static fn(string $p): bool => false;
$cust = static fn(string $p): bool => $p === 'customers.view';

try {
    cleanup();

    /* ── Shape + ranking ─────────────────────────────────────────── */
    $items = AiWatch::forOffice($all);
    check('forOffice returns a list', array_is_list($items), count($items) . ' items');
    $shapeOk = true; $levelsOk = true;
    foreach ($items as $s) {
        foreach (['key', 'level', 'icon', 'title', 'detail', 'href', 'cta'] as $k) {
            if (!isset($s[$k]) || !is_string($s[$k]) || $s[$k] === '') { $shapeOk = false; }
        }
        if (!in_array($s['level'] ?? '', ['high', 'warn', 'info', 'ok'], true)) { $levelsOk = false; }
        if (!str_starts_with((string) ($s['href'] ?? ''), '/admin/')) { $shapeOk = false; }
    }
    check('every item has key/level/icon/title/detail/href/cta and an /admin/ link', $shapeOk);
    check('every level is high|warn|info|ok', $levelsOk);
    $rank = ['high' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
    $order = array_map(static fn(array $s): int => $rank[$s['level']], $items);
    $sorted = $order; sort($sorted);
    check('ranked most urgent first', $order === $sorted, implode(',', $order));
    check('keys are unique', count(array_unique(keysOf($items))) === count($items));

    /* ── Permission gating ───────────────────────────────────────── */
    check('a probe that denies everything yields nothing', AiWatch::forOffice($none) === []);

    /* ── Enquiry line comes and goes ─────────────────────────────── */
    $before = AiWatch::forOffice($cust);
    Database::insert('enquiries', ['name' => 'AIW Test', 'phone' => '9100000777', 'note' => 'AIW-TEST', 'status' => 'new', 'source' => 'test']);
    $with = AiWatch::forOffice($cust);
    check('customers.view only → only customer items', array_diff(keysOf($with), ['enq']) === [], implode(',', keysOf($with)));
    check('a new enquiry raises the call-back line', hasKey($with, 'enq'));
    $enq = array_values(array_filter($with, static fn(array $s): bool => $s['key'] === 'enq'))[0] ?? [];
    check('…pointing at the enquiries page', ($enq['href'] ?? '') === '/admin/enquiries.php');
    Database::run("UPDATE enquiries SET status = 'contacted' WHERE note = 'AIW-TEST'");
    $after = AiWatch::forOffice($cust);
    check('…and goes back to the earlier state once contacted', keysOf($after) === keysOf($before));

    /* ── Support request (urgent → high) ─────────────────────────── */
    $hadSupport = hasKey(AiWatch::forOffice(static fn(string $p): bool => $p === 'support.view'), 'support');
    Database::insert('support_tickets', [
        'ticket_ref' => 'AIW-TEST-1', 'name' => 'AIW Test', 'phone' => '9100000777', 'subject' => 'test',
        'message' => 'test', 'category' => 'other', 'priority' => 'urgent', 'status' => 'open',
        'source' => 'test', 'dedupe_key' => hash('sha256', 'AIW-TEST-' . microtime()),
    ]);
    $sup = array_values(array_filter(AiWatch::forOffice(static fn(string $p): bool => $p === 'support.view'),
        static fn(array $s): bool => $s['key'] === 'support'));
    check('an urgent open request raises a HIGH support line', ($sup[0]['level'] ?? '') === 'high', $sup[0]['title'] ?? 'missing');
    Database::run("UPDATE support_tickets SET status = 'resolved' WHERE ticket_ref LIKE 'AIW-TEST%'");
    check('…and clears when resolved (back to before)', hasKey(AiWatch::forOffice(static fn(string $p): bool => $p === 'support.view'), 'support') === $hadSupport);

    /* ── Offline desk ────────────────────────────────────────────── */
    Database::insert('offline_requests', [
        'uuid' => sprintf('%08x-aaaa-4bbb-8ccc-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
        'admin_id' => 1, 'name' => 'AIW Test', 'phone' => '9100000777', 'seats' => 1, 'direction' => 'toNepal',
        'travel_date' => '2099-12-01', 'note' => 'AIW-TEST', 'taken_at' => date('Y-m-d H:i:s'),
        'received_at' => date('Y-m-d H:i:s'), 'status' => 'received',
    ]);
    check('an offline sale waiting raises its line', hasKey(AiWatch::forOffice(static fn(string $p): bool => $p === 'bookings.view'), 'offline'));

    /* ── Pickup without a time ───────────────────────────────────── */
    $untimed = (int) Database::scalar(
        "SELECT COUNT(*) FROM route_stops rs JOIN routes r ON r.id = rs.route_id
          WHERE r.is_active = 1 AND rs.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_time IS NULL"
    );
    check('a pickup with no time is named exactly when one exists',
        hasKey(AiWatch::forOffice(static fn(string $p): bool => $p === 'routes.view'), 'pickup') === ($untimed > 0),
        $untimed . ' untimed pickup(s)');

    /* ── 15 Oct 2026: payments card = Verify Payments' proof list ──── */
    $sched = Database::fetch(
        "SELECT s.id, s.travel_date FROM schedules s JOIN routes r ON r.id = s.route_id
          WHERE r.is_active = 1 AND s.travel_date = :d ORDER BY s.id LIMIT 1",
        ['d' => addDaysISO(todayISO(), 1)]
    );
    $proofBefore = AiWatch::proofsWaiting();
    $pb = seedBooking(['status' => 'pending']);
    Database::insert('payments', ['booking_id' => $pb, 'payment_ref' => 'AIWT-P-' . bin2hex(random_bytes(5)), 'method' => 'upi',
        'amount' => 1000, 'utr_number' => 'AIWT' . random_int(10000000, 99999999), 'status' => 'pending']);
    $pa = seedBooking(['status' => 'pending']);
    Database::insert('payments', ['booking_id' => $pa, 'payment_ref' => 'AIWT-P-' . bin2hex(random_bytes(5)), 'method' => 'upi',
        'amount' => 1000, 'status' => 'pending']);
    $proofAfter = AiWatch::proofsWaiting();
    check('a pending booking with a UTR is one more proof to verify', $proofAfter['n'] === $proofBefore['n'] + 1, $proofBefore['n'] . ' → ' . $proofAfter['n']);
    check('…a hold with no UTR / screenshot is not (it waits for payment)', $proofAfter['await'] === $proofBefore['await'] + 1);
    $pay = item(AiWatch::forOffice(static fn(string $p): bool => $p === 'payments.view'), 'pay');
    check('the payments card counts exactly that proof list', str_starts_with($pay['title'] ?? '', $proofAfter['n'] . ' payment'), $pay['title'] ?? 'missing');
    check('…and opens that list (tab=pending&sub=proof)', ($pay['href'] ?? '') === '/admin/payments.php?tab=pending&sub=proof');

    /* ── Payouts asked for / overdue ─────────────────────────────── */
    $agentId = (int) Database::scalar("SELECT id FROM admins WHERE role = 'agent' ORDER BY id LIMIT 1", [], 0);
    $payBefore = AiWatch::payoutCounts();
    $payoutsMigrated = true;
    try {
        Database::insert('agent_payout_requests', ['agent_admin_id' => $agentId, 'amount' => 321, 'note' => 'AIW-TEST', 'status' => 'open']);
    } catch (Throwable $e) { $payoutsMigrated = false; }
    $payAfter = AiWatch::payoutCounts();
    check('an open payout request is counted with its amount', !$payoutsMigrated
        || ($payAfter['requests'] === $payBefore['requests'] + 1 && abs($payAfter['requestAmt'] - $payBefore['requestAmt'] - 321) < 0.01));
    $comView = static fn(string $p): bool => $p === 'commissions.view';
    $po = item(AiWatch::forOffice($comView), 'payouts');
    check('…and raises the payouts card (commissions.view) to Settlements', !$payoutsMigrated || ($po !== [] && $po['href'] === '/admin/settlements.php?tab=pending'), $po['title'] ?? 'missing');
    check('…never shown without commissions.view', !hasKey(AiWatch::forOffice(static fn(string $p): bool => $p === 'payments.view'), 'payouts'));
    require_once INCLUDE_PATH . '/agentsettlement.php';
    $due  = max(1, Settings::getInt('alert_settlement_due_days', 15));
    $late = count(array_filter(AgentSettlement::pending(), static fn(array $p): bool => $p['commission'] > 0.009 && $p['days'] > $due && $p['open'] === null));
    check('overdue commission uses Settlements\' "Over N days" rule', $payAfter['late'] === $late && $payAfter['dueDays'] === $due, $late . ' late');
    $cashCard = item(AiWatch::forOffice($comView), 'cash');
    check('the agent-cash card opens Settlements, never Accounting (reports.view)',
        !str_contains(implode(' ', array_column(AiWatch::forOffice($comView), 'href')), 'accounting.php')
        && ($cashCard === [] || $cashCard['href'] === '/admin/settlements.php?tab=pending'));

    /* ── Customers asking to cancel / for a refund ───────────────── */
    $creqBefore = AiWatch::cancelRequestCounts();
    $live = seedBooking(['status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s')], $sched ? (int) $sched['id'] : null, $sched['travel_date'] ?? null);
    $gone = seedBooking(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
    $done = seedBooking(['status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s')]);
    foreach ([[$live, 'requested'], [$live, 'requested'], [$gone, 'requested'], [$done, 'done']] as [$bid, $outcome]) {
        Database::insert('ticket_edit_audit', ['booking_id' => $bid, 'pnr' => pnrOf($bid), 'field_changed' => 'cancel_request',
            'old_value' => 'confirmed', 'new_value' => 'cancel requested', 'changed_by' => 'whatsapp:919100000778',
            'otp_verified' => 1, 'outcome' => $outcome, 'detail' => 'AIW-TEST']);
    }
    $creq = AiWatch::cancelRequestCounts();
    check('a WhatsApp cancel request on a live ticket is counted once (two requests, one ticket)', $creq['wa'] === $creqBefore['wa'] + 1, $creqBefore['wa'] . ' → ' . $creq['wa']);
    $bv = static fn(string $p): bool => $p === 'bookings.view';
    $cr = item(AiWatch::forOffice($bv), 'cancel-req');
    check('…raises the cancel-request card for bookings.view', $cr !== []);
    check('…opening the ticket when it is the only one, else the WhatsApp routing list',
        ($cr['href'] ?? '') === ($creq['wa'] === 1 ? '/admin/booking-view.php?pnr=' . rawurlencode(pnrOf($live)) : '/admin/wa-routing.php'), $cr['href'] ?? '');
    check('…says the office settles it by hand (advisory only)', str_contains($cr['detail'] ?? '', 'by hand'));
    Database::run("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :i", ['i' => $live]);
    check('…and drops off once the ticket is cancelled', AiWatch::cancelRequestCounts()['wa'] === $creqBefore['wa']);

    $supBefore = item(AiWatch::forOffice(static fn(string $p): bool => $p === 'support.view'), 'support');
    $supN = static fn(array $s): int => $s === [] ? 0 : (int) $s['title'];
    Database::insert('support_tickets', [
        'ticket_ref' => 'AIW-TEST-R1', 'name' => 'AIW Test', 'phone' => '9100000777', 'subject' => 'refund', 'message' => 'money back',
        'category' => 'refund', 'priority' => 'high', 'status' => 'in_progress', 'source' => 'test', 'dedupe_key' => hash('sha256', 'AIW-R-' . microtime()),
    ]);
    $supView = AiWatch::forOffice(static fn(string $p): bool => $p === 'support.view');
    $rr = item($supView, 'refund-req');
    check('a Help Desk refund ticket in progress raises its own card', $rr !== [] && $rr['href'] === '/admin/support-inbox.php?cat=refund', $rr['title'] ?? 'missing');
    check('…HIGH when the ticket is high / urgent', ($rr['level'] ?? '') === 'high');
    check('…and is not counted twice in the general support card', $supN(item($supView, 'support')) === $supN($supBefore));
    check('…never shown without support.view', !hasKey(AiWatch::forOffice($bv), 'refund-req'));

    /* ── "Plan extra bus" needs the page it opens (schedules.view) ── */
    $noSched = AiWatch::forOffice(static fn(string $p): bool => in_array($p, ['dashboard.view', 'payments.view'], true));
    check('no "Plan extra bus" card without schedules.view', !hasKey($noSched, 'full-'));

    /* ── Every card opens a page the role it is shown to may open ── */
    $GATES = [
        'payments.php' => ['payments.view'], 'trips.php' => ['schedules.view'], 'buses.php' => ['schedules.view'],
        'promo-card.php' => ['dashboard.view'], 'wa-pending.php' => ['bookings.view'], 'agent.php' => ['bookings.view', 'commissions.view'],
        'settlements.php' => ['commissions.view'], 'accounting.php' => ['payments.view', 'reports.view'], 'refunds.php' => ['refunds.view'],
        'support-inbox.php' => ['support.view'], 'offline-requests.php' => ['bookings.view'], 'enquiries.php' => ['customers.view'],
        'routes.php' => ['routes.view'], 'payroll.php' => ['payments.view', 'reports.view'], 'booking-view.php' => ['bookings.view'],
        'wa-routing.php' => ['bookings.view'],
    ];
    $drift = [];
    foreach ($GATES as $page => $perms) {
        $src = (string) @file_get_contents(dirname(__DIR__) . '/admin/' . $page);
        foreach ($perms as $perm) {
            // admin_boot('x'), admin_boot($agentView ? '…' : 'x') or Auth::requireAdmin('x')
            if (preg_match("~(admin_boot|requireAdmin)\([^;]*'" . preg_quote($perm, '~') . "'~", $src) !== 1) { $drift[] = "$page:$perm"; }
        }
    }
    check('the page-gate map matches each page\'s own admin_boot / requireAdmin', $drift === [], implode(', ', $drift));
    $roles = (new ReflectionClass('Auth'))->getReflectionConstant('ROLE_PERMISSIONS')->getValue();
    foreach (['manager', 'accountant', 'operations', 'support', 'viewer'] as $role) {
        $perms = $roles[$role] ?? [];
        $items = AiWatch::forOffice(static fn(string $p): bool => in_array($p, $perms, true));
        $bad = [];
        foreach ($items as $s) {
            $page = basename((string) parse_url($s['href'], PHP_URL_PATH));
            if (!isset($GATES[$page])) { $bad[] = 'unmapped ' . $page; continue; }
            foreach ($GATES[$page] as $need) { if (!in_array($need, $perms, true)) { $bad[] = $page . ' needs ' . $need; } }
            if ($role === 'viewer' && !in_array($page, Auth::VIEWER_PAGES, true)) { $bad[] = $page . ' not a viewer page'; }
        }
        check("$role: every card opens a page the role may open", $bad === [], count($items) . ' cards' . ($bad ? ' — ' . implode('; ', $bad) : ''));
    }

    /* ── Seller list ─────────────────────────────────────────────── */
    $mine = AiWatch::forSeller(987654321);
    check('forSeller never throws for an unknown id', is_array($mine));
    $tomorrow = addDaysISO(todayISO(), 1);
    $sellerPages = ['/admin/bookings.php?status=pending', '/admin/agent.php', '/admin/agent-passengers.php', '/admin/quick-ticket.php', '/admin/agent-sales.php',
                    // 15 Oct 2026: a counter clerk's own screens
                    '/admin/bookings.php?status=pending&mine=1', '/admin/cash-book.php?status=collected', '/admin/shift.php', '/admin/manifest.php?date=' . $tomorrow];
    check('seller items only link to seller pages', array_diff(array_map(static fn(array $s): string => $s['href'], $mine), $sellerPages) === []);
    check('an unknown seller has no pending / cash / tomorrow lines',
        !hasKey($mine, 'my-pending') && !hasKey($mine, 'my-cash') && !hasKey($mine, 'my-tomorrow'));

    /* ── A counter clerk's cards open counter screens ─────────────── */
    $ctr = (int) Database::scalar("SELECT id FROM admins WHERE username = 'aiw-test-counter'", [], 0);
    if ($ctr === 0) {
        $ctr = Database::insert('admins', ['username' => 'aiw-test-counter', 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'full_name' => 'AIW Test Counter', 'role' => 'counter', 'is_active' => 0, 'must_change_pw' => 0]);
    }
    $p1 = seedBooking(['status' => 'pending', 'sold_by_admin_id' => $ctr]);
    seedBooking(['status' => 'confirmed', 'sold_by_admin_id' => $ctr, 'confirmed_at' => date('Y-m-d H:i:s')], $sched ? (int) $sched['id'] : null, $tomorrow);
    Database::insert('agent_ledger', ['agent_admin_id' => $ctr, 'booking_id' => $p1, 'account' => 'cash', 'entry_type' => 'cash_due', 'amount' => 1000, 'note' => 'AIW-TEST']);
    $cItems = AiWatch::forSeller($ctr);   // role read from admins
    $ctrPerms = $roles['counter'] ?? [];
    $cPend = item($cItems, 'my-pending');
    check('counter: "my pending" opens only my pending tickets (mine=1)', ($cPend['href'] ?? '') === '/admin/bookings.php?status=pending&mine=1', $cPend['href'] ?? 'missing');
    require_once INCLUDE_PATH . '/countershift.php';
    $cCash = item($cItems, 'my-cash');
    check('counter: "my cash" opens the cash book (or the shift screen when shifts are on)',
        ($cCash['href'] ?? '') === (CounterShift::enabled() ? '/admin/shift.php' : '/admin/cash-book.php?status=collected'), $cCash['href'] ?? 'missing');
    $cTom = item($cItems, 'my-tomorrow');
    check('counter: "travel tomorrow" opens tomorrow\'s manifest', !$sched || ($cTom['href'] ?? '') === '/admin/manifest.php?date=' . $tomorrow, $cTom['href'] ?? 'missing');
    check('counter: no commission card', !hasKey($cItems, 'my-month'));
    check('counter: never an agent-only page (agent.php / agent-passengers / agent-sales)',
        preg_grep('~/admin/agent(-passengers|-sales)?\.php~', array_column($cItems, 'href')) === []);
    $cGate = ['bookings.php' => 'bookings.view', 'cash-book.php' => 'bookings.view', 'shift.php' => 'bookings.view',
              'manifest.php' => 'bookings.view', 'quick-ticket.php' => 'bookings.view'];
    check('counter: every card opens a page the counter role holds',
        array_filter($cItems, static fn(array $s): bool => !in_array($cGate[basename((string) parse_url($s['href'], PHP_URL_PATH))] ?? '__none__', $ctrPerms, true)) === []);
    $aItems = AiWatch::forSeller($ctr, 'agent');
    check('an agent\'s cards are unchanged (wallet / my passengers / register)',
        (item($aItems, 'my-cash')['href'] ?? '') === '/admin/agent.php' && (item($aItems, 'my-pending')['href'] ?? '') === '/admin/bookings.php?status=pending'
        && (!$sched || (item($aItems, 'my-tomorrow')['href'] ?? '') === '/admin/agent-passengers.php'));

    /* ── Rendering ───────────────────────────────────────────────── */
    $empty = AiWatch::render([]);
    check('empty list renders the calm all-clear line', str_contains($empty, 'All clear'));
    $evil = ['key' => 'x', 'level' => 'warn', 'icon' => '!', 'title' => '<script>alert(1)</script>', 'detail' => 'a & b', 'href' => '/admin/x.php?a=1&b=2', 'cta' => 'Go'];
    $html = AiWatch::render([$evil]);
    check('text is escaped', !str_contains($html, '<script>alert(1)') && str_contains($html, '&lt;script&gt;'));
    check('href ampersands are escaped', str_contains($html, '/admin/x.php?a=1&amp;b=2'));
    $many = array_fill(0, AiWatch::SHOW + 3, $evil);
    $html = AiWatch::render($many);
    check('items past SHOW are folded', substr_count($html, 'aw-more') === 3 && str_contains($html, 'Show all ' . (AiWatch::SHOW + 3)));
    check('CSS is emitted once per page', substr_count($empty . $html, '<style>') === 1);
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
}

echo "\n----------------------------------------\n  $PASS passed, $FAIL failed\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
