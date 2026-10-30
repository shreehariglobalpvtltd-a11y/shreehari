<?php
/**
 * =====================================================================
 *  tools/team-purge-archived.php — delete archived / disabled members for
 *  good WITH their history (31 Oct 2026, owner: "disable gareko purano
 *  member — baki ticket haru ni chhan — sab delete gardeu, dhyan rakha";
 *  for the 13 agents the 30 Oct reset archived: "sab delete — customer name
 *  number for data"). CLI only. DRY RUN unless --go.
 *
 *    php tools/team-purge-archived.php --all-archived --actor=1 --tickets=delete
 *    php tools/team-purge-archived.php --ids=35,38 --actor=1 --tickets=office --reason="..." --go
 *
 *  --ids=<admins.id,...>  the people (each must be archived, or an agent whose
 *                         login is off; never a super-admin, never --actor)
 *  --all-archived         every archived member, and every role=agent login
 *                         that is off (a join form still waiting for approval
 *                         is an applicant and is never included)
 *  --tickets=office       (default) their tickets stay, in the office's name;
 *                         the office note says who sold them
 *  --tickets=delete       their old tickets go too, with every row under them;
 *                         a trip still to come (not cancelled) and a ticket
 *                         another member is also on always stay. The customer
 *                         name + mobile of every deleted ticket is saved into
 *                         user_profiles first (and a customers-kept-*.csv).
 *  --actor=<id>           the super-admin doing it (written in the audit log)
 *  --reason="..."         kept in the audit log
 *  --go                   do it. Without it: the per-person preview + totals.
 *
 *  Each person is one TeamPurge::purgeWithHistory() call: one transaction, a
 *  gzip JSON before-image in BACKUP_PATH/team-purge/ written first; a failure
 *  rolls back that person only and the run goes on.
 *  Run as root on the VPS: the new backup files are handed to the owner of
 *  BACKUP_PATH afterwards (www-data), so the web page can still write there.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teampurge.php';

// Plain text + exit 1 on any uncaught error (bootstrap's handler prints HTML and exits 0).
set_exception_handler(static function (Throwable $e): void {
    Logger::exception($e);
    fwrite(STDERR, 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

$usage = "Usage: php tools/team-purge-archived.php (--ids=<id,id> | --all-archived) --actor=<superadmin id> [--tickets=office|delete] [--reason=\"...\"] [--go]\n"
       . "  Dry run (a per-person preview) unless --go. --tickets=office (default) keeps their tickets in the office's name;\n"
       . "  --tickets=delete deletes the old ones too (upcoming trips and shared tickets always stay; customers are saved first).\n";
$fail = static function (string $msg, int $code = 1) use ($usage): never {
    fwrite(STDERR, "ERROR: $msg\n\n$usage");
    exit($code);
};

$ids = null; $all = false; $tickets = 'office'; $actorId = 0; $reason = ''; $go = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--go') { $go = true; continue; }
    if ($a === '--all-archived') { $all = true; continue; }
    if ($a === '--help' || $a === '-h') { echo $usage; exit(0); }
    if (preg_match('/^--ids=(.*)$/', $a, $m) === 1) {
        $v = str_replace(' ', '', $m[1]);
        if (preg_match('/^[1-9]\d{0,9}(,[1-9]\d{0,9})*$/', $v) !== 1) { $fail('--ids: use ids separated by commas, e.g. --ids=35,38'); }
        $ids = array_values(array_unique(array_map('intval', explode(',', $v))));
        continue;
    }
    if (preg_match('/^--tickets=(.*)$/', $a, $m) === 1) {
        if (!in_array($m[1], TeamPurge::TICKET_MODES, true)) { $fail('--tickets must be office or delete'); }
        $tickets = $m[1];
        continue;
    }
    if (preg_match('/^--actor=(\d+)$/', $a, $m) === 1) { $actorId = (int) $m[1]; continue; }
    if (str_starts_with($a, '--reason=')) { $reason = substr($a, 9); continue; }
    $fail("unknown argument $a");
}
if (($ids === null) === !$all) { $fail('give exactly one of --ids=... or --all-archived'); }
if ($actorId <= 0) { $fail('--actor=<id of a super-admin> is required'); }
$actor = Database::fetch('SELECT id, username, full_name, role, is_active FROM admins WHERE id = :i', ['i' => $actorId]);
if ($actor === null || (string) $actor['role'] !== 'superadmin') { $fail("--actor=$actorId is not a super-admin login"); }

// Only archived / disabled members, whichever way they were named.
$cands = [];
foreach (TeamPurge::disabledMembers($actorId) as $dm) { $cands[$dm['id']] = $dm; }
$people = [];
$refused = [];
if ($all) {
    $people = array_values($cands);
} else {
    foreach ($ids as $i) {
        if (isset($cands[$i])) {
            $people[] = $cands[$i];
            continue;
        }
        $r = Database::fetch('SELECT id, username, role, is_active FROM admins WHERE id = :i', ['i' => $i]);
        $refused[] = "#$i " . ($r === null ? 'does not exist'
            : ($r['role'] === 'superadmin' ? 'is a super-admin' : ($i === $actorId ? 'is --actor' : 'is neither archived nor a disabled agent (or still waits for approval)')));
    }
}

$db = (string) Database::scalar('SELECT DATABASE()', [], '?');
$txt = static fn(string $s, int $w): string => mb_strimwidth(preg_replace('/\s+/u', ' ', $s) ?? '', 0, $w, '…');
echo 'TEAM PURGE WITH HISTORY - ' . ($go ? 'APPLY' : 'DRY RUN (nothing is written)') . " - database $db - tickets: $tickets"
    . ' - actor #' . $actorId . ' ' . $actor['username'] . "\n";
foreach ($refused as $r) { echo "SKIPPED: $r\n"; }
if ($people === []) { echo "Nobody to delete.\n"; exit($refused === [] ? 0 : 2); }

printf("\n%-6s %-26s %-10s %-9s %5s %5s %5s %5s %6s %6s %6s %6s %12s\n", 'id', 'name', 'code', 'role', 'tix', 'up', 'past', 'canc', 'del', 'kept', 'money', 'actor', 'value');
$tot = ['tix' => 0, 'up' => 0, 'past' => 0, 'canc' => 0, 'del' => 0, 'kept' => 0, 'money' => 0, 'actor' => 0, 'value' => 0.0, 'cust' => 0];
$notes = [];
foreach ($people as $p) {
    $f = TeamPurge::forcePreview('admin', $p['id']);
    $m = $f[$tickets];
    printf("%-6d %-26s %-10s %-9s %5d %5d %5d %5d %6d %6d %6d %6d %12s\n", $p['id'], $txt($p['name'], 26), $txt((string) $f['code'], 10), $txt($p['role'], 9),
        $f['bookings'], $f['upcoming'], $f['past'], $f['cancelled'], $m['bookings_deleted'], $m['bookings_detached'], $f['money_rows'], $f['actor_rows'],
        number_format((float) $f['value'], 2));
    $tot['tix'] += $f['bookings']; $tot['up'] += $f['upcoming']; $tot['past'] += $f['past']; $tot['canc'] += $f['cancelled'];
    $tot['del'] += $m['bookings_deleted']; $tot['kept'] += $m['bookings_detached']; $tot['money'] += $f['money_rows'];
    $tot['actor'] += $f['actor_rows']; $tot['value'] += $f['value'];
    $tot['cust'] += $tickets === 'delete' ? (int) $f['delete']['customers'] : 0;
    if ($f['refuse'] !== '') { $notes[] = "#{$p['id']} REFUSED: {$f['refuse']}"; }
    foreach ($f['blockers'] as $b) { $notes[] = "#{$p['id']} BLOCKER (nothing of this person would be deleted): $b"; }
    foreach ($f['actor_left'] as $b) { $notes[] = "#{$p['id']} left as is (column may not be empty): $b"; }
    foreach ($f['cascade'] as $b) { $notes[] = "#{$p['id']} deleted by the database's own rule (ON DELETE CASCADE): $b"; }
    foreach ($f['unknown'] as $b) { $notes[] = "#{$p['id']} other column naming this id, left as is: $b"; }
    foreach ($f['payroll'] as $b) { $notes[] = "#{$p['id']} drivers / payroll (phone-keyed), left as is: {$b['count']} x {$b['label']}"; }
    if ($tickets === 'delete' && $m['kept_shared'] > 0) { $notes[] = "#{$p['id']} {$m['kept_shared']} past ticket(s) kept: another member is also on them"; }
    if ($tickets === 'delete' && $f['delete']['customers_skipped'] > 0) { $notes[] = "#{$p['id']} {$f['delete']['customers_skipped']} deleted ticket(s) carry a placeholder / staff number: no customer saved for those"; }
}
printf("%-6s %-26s %-10s %-9s %5d %5d %5d %5d %6d %6d %6d %6d %12s\n", '', 'TOTAL (' . count($people) . ')', '', '', $tot['tix'], $tot['up'], $tot['past'], $tot['canc'],
    $tot['del'], $tot['kept'], $tot['money'], $tot['actor'], number_format($tot['value'], 2));
echo "  tix = tickets sold / handled, up = trip still to come (always kept), past / canc = past live / cancelled-expired,\n"
   . "  del / kept = tickets deleted / kept in the office's name with --tickets=$tickets, money = own money/account rows deleted,\n"
   . "  actor = rows on other people's records whose \"done by\" is cleared, value = confirmed ticket value.\n";
if ($tickets === 'delete') { echo "  Customers saved into user_profiles before their tickets go: {$tot['cust']}\n"; }
foreach ($notes as $n) { echo "Note: $n\n"; }

if (!$go) {
    echo "\nDRY RUN - nothing written. Add --go to delete (each person: backup first, one transaction).\n";
    exit(0);
}

echo "\n";
$ok = 0; $bad = 0; $made = [];
foreach ($people as $p) {
    try {
        $r = TeamPurge::purgeWithHistory('admin', $p['id'], $actor, TeamPurge::CONFIRM_ALL, $reason, $tickets);
        $ok++;
        $made[] = $r['backup'];
        if (($r['customers_csv'] ?? '') !== '') { $made[] = $r['customers_csv']; }
        echo "OK   #{$p['id']} " . TeamPurge::resultText($r) . "\n";
    } catch (Throwable $ex) {
        $bad++;
        if (!$ex instanceof TeamPurgeError) { Logger::exception($ex); }
        echo "FAIL #{$p['id']} {$p['name']}: " . $ex->getMessage() . " (nothing of this person was changed)\n";
    }
}

// Run as root: hand the new backup files to the owner of BACKUP_PATH (www-data) so the web page can still write there.
$dir = BACKUP_PATH . '/team-purge';
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_dir($dir)) {
    $st = @stat(BACKUP_PATH);
    if (is_array($st) && $st['uid'] !== 0) {
        @chown($dir, $st['uid']); @chgrp($dir, $st['gid']);
        $walk = static function (string $path) use (&$walk, $st): void {
            @chown($path, $st['uid']); @chgrp($path, $st['gid']);
            if (is_dir($path)) { foreach (scandir($path) ?: [] as $n) { if ($n !== '.' && $n !== '..') { $walk($path . '/' . $n); } } }
        };
        foreach ($made as $b) {
            foreach ([$dir . '/' . $b, $dir . '/' . preg_replace('/\.json\.gz$/', '', $b) . '-files'] as $path) {
                if (file_exists($path)) { $walk($path); }
            }
        }
    }
}

printf("\nDone: %d deleted, %d not deleted. Backups in %s/team-purge/:\n", $ok, $bad, BACKUP_PATH);
foreach ($made as $b) { echo "  $b\n"; }
exit($bad > 0 ? 1 : 0);
