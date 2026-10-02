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
 *
 * Throwaway rows tagged 'AIW-TEST'; cleans up after itself.
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

    /* ── Seller list ─────────────────────────────────────────────── */
    $mine = AiWatch::forSeller(987654321);
    check('forSeller never throws for an unknown id', is_array($mine));
    $sellerPages = ['/admin/bookings.php?status=pending', '/admin/agent.php', '/admin/agent-passengers.php', '/admin/quick-ticket.php', '/admin/agent-sales.php'];
    check('seller items only link to seller pages', array_diff(array_map(static fn(array $s): string => $s['href'], $mine), $sellerPages) === []);
    check('an unknown seller has no pending / cash / tomorrow lines',
        !hasKey($mine, 'my-pending') && !hasKey($mine, 'my-cash') && !hasKey($mine, 'my-tomorrow'));

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
