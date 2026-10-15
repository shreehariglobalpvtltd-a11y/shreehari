<?php
/**
 * =====================================================================
 *  export-filters-test.php — Module 2 (3 Sep 2026): the bookings export
 *  honours EVERY filter the on-screen list has (the "export date bug").
 *
 *  For each filter it counts the matching bookings straight from the DB and
 *  compares with the number of data rows in the CSV export.php returns for
 *  the same query string. Also checks the PDF path accepts the filters, and
 *  that agent-sales.php's export link carries the agent id + the correct
 *  inclusive end date for the "Yesterday" preset.
 *
 *  Needs the dev server on :8899 and curl:
 *    php -c .claude/php-dev.ini -d extension=php_curl.dll tests/export-filters-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$jar = sys_get_temp_dir() . '/shg_export_' . getmypid() . '.cookies'; @unlink($jar);
function req(string $method, string $url, ?array $form = null): array {
    global $jar;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($form !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'type' => $type];
}
/** Data rows in a CSV body (BOM + header stripped). */
function csvRows(string $body): int {
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body)) ?: [], static fn($l) => $l !== ''));
    return max(0, count($lines) - 1);
}

// Throwaway superadmin.
$user = 'export-super'; $pw = 'Export@12345';
try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
$hash = password_hash($pw, PASSWORD_BCRYPT);
$id = Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
if ($id) {
    Database::query('UPDATE admins SET password_hash=:h, role=\'superadmin\', is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=:i', ['h' => $hash, 'i' => $id]);
} else {
    Database::query("INSERT INTO admins (username, password_hash, full_name, role, is_active, must_change_pw) VALUES (:u, :h, 'Export Test', 'superadmin', 1, 0)", ['u' => $user, 'h' => $hash]);
}
$lp = req('GET', '/admin/login.php');
preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
$li = req('POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);

echo "\n=== Export CSV matches the DB for each filter ===\n";
$all = (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0);
$csv = req('GET', '/admin/export.php');
check('unfiltered export = all bookings', csvRows($csv['body']) === min($all, 10000), 'csv=' . csvRows($csv['body']) . ' db=' . $all);

// Travel-date filter (the reported bug).
$td = Database::fetch("SELECT bl.travel_date d, COUNT(DISTINCT b.id) c
                         FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                        GROUP BY bl.travel_date ORDER BY c DESC, d DESC LIMIT 1");
if ($td !== null) {
    $r = req('GET', '/admin/export.php?travel_from=' . $td['d'] . '&travel_to=' . $td['d']);
    check('travel_from/travel_to = ' . $td['d'] . ' → ' . $td['c'] . ' rows', csvRows($r['body']) === (int) $td['c'], 'csv=' . csvRows($r['body']));
    check('… and the travel filter actually narrowed the file', (int) $td['c'] === $all || csvRows($r['body']) < csvRows($csv['body']));
    $pdf = req('GET', '/admin/export.php?format=pdf&travel_from=' . $td['d'] . '&travel_to=' . $td['d']);
    check('PDF export accepts the same filters', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code'] . ' ' . $pdf['type']);
} else { echo "  SKIP  no bookings with an outbound leg\n"; }

// Route filter.
$rt = Database::fetch("SELECT s.route_id rid, COUNT(DISTINCT b.id) c
                         FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                         JOIN schedules s ON s.id = bl.schedule_id GROUP BY s.route_id ORDER BY c DESC LIMIT 1");
if ($rt !== null) {
    $r = req('GET', '/admin/export.php?route=' . (int) $rt['rid']);
    check('route=' . $rt['rid'] . ' → ' . $rt['c'] . ' rows', csvRows($r['body']) === (int) $rt['c'], 'csv=' . csvRows($r['body']));
}

// Agent filter (supervisor scope).
$ag = Database::fetch("SELECT sold_by_admin_id aid, COUNT(*) c FROM bookings WHERE sold_by_admin_id IS NOT NULL GROUP BY sold_by_admin_id ORDER BY c DESC LIMIT 1");
if ($ag !== null) {
    $r = req('GET', '/admin/export.php?agent=' . (int) $ag['aid']);
    check('agent=' . $ag['aid'] . ' → ' . $ag['c'] . ' rows', csvRows($r['body']) === (int) $ag['c'], 'csv=' . csvRows($r['body']));
} else { echo "  SKIP  no agent-sold bookings\n"; }

// Payment status + source + status combined.
$ps = (int) Database::scalar("SELECT COUNT(*) FROM bookings b WHERE EXISTS (SELECT 1 FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'verified')", [], 0);
$r = req('GET', '/admin/export.php?pay_status=verified');
check('pay_status=verified → ' . $ps . ' rows', csvRows($r['body']) === $ps, 'csv=' . csvRows($r['body']));
/* 5 Oct 2026: "online" is the ONLINE channel tag now (includes/bookingchannel.php) -
   a self-booked web sale; an agent-code sale and a Google Ads sale are their own tags. */
$src = (int) Database::scalar("SELECT COUNT(*) FROM bookings b WHERE (" . BookingChannel::sqlExpr('b') . ") = 'ONLINE' AND b.status = 'confirmed'", [], 0);
$r = req('GET', '/admin/export.php?source=online&status=confirmed');
check('source=online&status=confirmed → ' . $src . ' rows', csvRows($r['body']) === $src, 'csv=' . csvRows($r['body']));
$r = req('GET', '/admin/export.php?format=xlsx&pay_status=verified');
// export.php serves native XLSX only when ZipArchive is loaded; otherwise the same filtered CSV.
check('xlsx export still served with filters', $r['code'] === 200 && (str_starts_with($r['body'], 'PK') || csvRows($r['body']) === $ps), 'HTTP ' . $r['code'] . (str_starts_with($r['body'], 'PK') ? ' xlsx' : ' csv-fallback'));

/* 15 Oct 2026 (chalani register): the column funnels and "only my sales" reach
   the file exactly as the screen applies them - one case per key, each against
   an independent count straight from the DB. */
echo "\n=== Column filters (f_ticket / f_pax / f_mob / f_seat / mine) ===\n";
require_once INCLUDE_PATH . '/seats.php';
$head = preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv['body']) ?? '')[0] ?? '';
check('export header carries Ticket no + Payment status + Seat ids', str_contains($head, 'Ticket no') && str_contains($head, 'Payment status') && str_contains($head, 'Seat ids'), $head);
$tk = Database::fetch('SELECT t.ticket_number FROM tickets t ORDER BY t.id DESC LIMIT 1');
if ($tk !== null) {
    $frag = substr((string) $tk['ticket_number'], -6);
    $want = (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE b.pnr LIKE :a OR EXISTS (SELECT 1 FROM tickets t WHERE t.booking_id = b.id AND t.ticket_number LIKE :b)',
        ['a' => '%' . $frag . '%', 'b' => '%' . $frag . '%'], 0);
    $r = req('GET', '/admin/export.php?f_ticket=' . urlencode($frag));
    check('f_ticket=' . $frag . ' → ' . $want . ' rows', csvRows($r['body']) === $want, 'csv=' . csvRows($r['body']));
} else { echo "  SKIP  no tickets\n"; }
$px = Database::fetch("SELECT full_name FROM booking_passengers WHERE CHAR_LENGTH(full_name) >= 4 ORDER BY id DESC LIMIT 1");
if ($px !== null) {
    $frag = mb_substr((string) $px['full_name'], 0, 4);
    $want = (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE EXISTS (SELECT 1 FROM booking_passengers bp WHERE bp.booking_id = b.id AND bp.full_name LIKE :n)', ['n' => '%' . $frag . '%'], 0);
    $r = req('GET', '/admin/export.php?f_pax=' . urlencode($frag));
    check('f_pax=' . $frag . ' → ' . $want . ' rows', csvRows($r['body']) === $want, 'csv=' . csvRows($r['body']));
}
$mb = Database::fetch("SELECT contact_phone FROM bookings WHERE contact_phone REGEXP '^[0-9]{10,}$' ORDER BY id DESC LIMIT 1");
if ($mb !== null) {
    $frag = substr((string) $mb['contact_phone'], -6);
    $want = (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE b.contact_phone LIKE :m', ['m' => '%' . $frag . '%'], 0);
    $r = req('GET', '/admin/export.php?f_mob=' . urlencode(substr($frag, 0, 3) . '-' . substr($frag, 3)));
    check('f_mob=' . $frag . ' (typed with a dash) → ' . $want . ' rows', csvRows($r['body']) === $want, 'csv=' . csvRows($r['body']));
}
/* f_seat: the PRINTED label of a live sharing berth. Oracle = every live seat
   expanded to the beds it occupies and run through Seats::displayLabel() in
   PHP (not the new inverse helper): a booking matches when one of its beds
   prints as the typed label (a private cabin covers two). */
$live = Database::fetchAll("SELECT bs.booking_id, bs.seat_no, COALESCE(b.booking_mode, 'sharing') AS mode, COALESCE(s.coach_type_override, r.coach_type, 'sleeper') AS coach
                              FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
                              LEFT JOIN schedules s ON s.id = bs.schedule_id LEFT JOIN routes r ON r.id = s.route_id
                             WHERE bs.released_at IS NULL AND bs.seat_no REGEXP '^[LU][0-9]+$' ORDER BY bs.id DESC");
$sample = array_values(array_filter($live, static fn(array $x): bool => $x['mode'] !== 'private'))[0] ?? null;
if ($sample !== null) {
    $lbl  = Seats::displayLabel((string) $sample['seat_no'], (string) $sample['coach'], 'sharing');
    $hit  = [];
    foreach ($live as $ls) {
        $beds = Seats::physicalSeats((string) $ls['seat_no'], (string) $ls['mode'], (string) $ls['coach']);
        $bedLabels = array_map(static fn($bd): string => Seats::displayLabel((string) $bd, (string) $ls['coach'], 'sharing'), $beds);
        if (in_array($lbl, $bedLabels, true) || strtoupper((string) $ls['seat_no']) === strtoupper($lbl)) {
            $hit[(int) $ls['booking_id']] = true;
        }
    }
    $r = req('GET', '/admin/export.php?f_seat=' . urlencode($lbl));
    check('f_seat=' . $lbl . ' (as printed; stored ' . $sample['seat_no'] . ') → ' . count($hit) . ' rows', csvRows($r['body']) === count($hit), 'csv=' . csvRows($r['body']));
} else { echo "  SKIP  no live sleeper seats\n"; }
$r = req('GET', '/admin/export.php?mine=1');
$mine = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = (SELECT id FROM admins WHERE username = :u)', ['u' => $user], 0);
check('mine=1 → only the signed-in seller\'s sales (' . $mine . ')', csvRows($r['body']) === $mine, 'csv=' . csvRows($r['body']));

echo "\n=== agent-sales.php export link ===\n";
$as = req('GET', '/admin/agent-sales.php?range=yesterday');
check('agent-sales renders', $as['code'] === 200, 'HTTP ' . $as['code']);
if (preg_match('~/admin/export\.php\?([^"]+)~', $as['body'], $lm)) {
    parse_str(html_entity_decode($lm[1]), $qs);
    $yesterday = addDaysISO(todayISO(), -1);
    check('"Yesterday" export ends on yesterday (was: today as well)', ($qs['to'] ?? '') === $yesterday, 'to=' . ($qs['to'] ?? '?') . ' expected ' . $yesterday);
    check('"Yesterday" export starts on yesterday', ($qs['from'] ?? '') === $yesterday, 'from=' . ($qs['from'] ?? '?'));
    check('export link carries the viewed agent id (supervisor scope)', isset($qs['agent']) && ctype_digit((string) $qs['agent']), 'agent=' . ($qs['agent'] ?? 'missing'));
} else {
    check('export link found on agent-sales.php', false);
}

try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
@unlink($jar);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
