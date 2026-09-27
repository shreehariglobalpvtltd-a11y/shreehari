<?php
/**
 * dead-switches-test.php — the settings rows nothing reads (27 Sep 2026).
 *
 *  database/upgrade-2026-09-27-dead-switches.sql deletes eleven switches
 *  that no PHP or JavaScript on this tree reads. This suite keeps that true
 *  in both directions:
 *
 *   A. the SQL is ONE idempotent `DELETE FROM settings WHERE skey IN (...)`,
 *      names exactly the eleven, and never bundle_assets_on
 *   B. no PHP, JS or template file on this tree references a deleted key
 *      (whole word), and admin/settings.php offers no field for one — so
 *      nobody can quietly start reading a row the upgrade removes
 *   C. this database has none of the rows left (the upgrade was applied);
 *      a database that still has them fails with the command to run
 *   D. the inverse: bundle_assets_on IS read by code, proving the list was
 *      built from readers, not from a hunch
 *
 *  Run alone:  php tests/dead-switches-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
$ROOT = dirname(__DIR__);

/* The eleven, as the 27 Sep map found them. The SQL must match this list
   exactly — a key added to the SQL without a reader check, or one dropped
   from it, is caught here. */
const DEAD = [
    'trust_card_on', 'refund_ladder_on', 'women_layer_on', 'social_publish_on',
    'ai_examples_on', 'ai_memory_on', 'ai_refresh_on', 'ai_web_on',
    'notice_on', 'temples_default_on', 'pin_lock_enabled',
];

echo "\n=== Dead switches ===\n\n-- A. the upgrade file --\n";
$sqlFile = $ROOT . '/database/upgrade-2026-09-27-dead-switches.sql';
$sql = (string) @file_get_contents($sqlFile);
check('database/upgrade-2026-09-27-dead-switches.sql exists', $sql !== '');
$body = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;          // as tests/apply-sql.php reads it
preg_match('/DELETE\s+FROM\s+`?settings`?\s+WHERE\s+`?skey`?\s+IN\s*\(([^)]*)\)/i', $body, $m);
check('it is an idempotent DELETE FROM settings WHERE skey IN (...)', isset($m[1]));
preg_match_all("/'([a-z0-9_]+)'/", $m[1] ?? '', $km);
$keys = $km[1];
$sortedKeys = $keys; sort($sortedKeys);
$sortedDead = DEAD;  sort($sortedDead);
check('it names exactly the eleven no-reader switches', $sortedKeys === $sortedDead, count($keys) . ' keys');
check('…and never bundle_assets_on (which is read)', !in_array('bundle_assets_on', $keys, true));
$stmts = array_filter(array_map('trim', explode(';', $body)), static fn(string $s): bool => $s !== '');
check('one statement, nothing riding along', count($stmts) === 1, count($stmts) . ' statement(s)');

echo "\n-- B. no reader anywhere on this tree --\n";
$hits = [];
$scanned = 0;
$roots = ['includes', 'api', 'admin', 'cron', 'assets/js', 'tests', 'deploy', 'tools'];
$files = [];
foreach ($roots as $dir) {
    if (!is_dir($ROOT . '/' . $dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (in_array(strtolower($f->getExtension()), ['php', 'js', 'mjs', 'html'], true)) { $files[] = $f->getPathname(); }
    }
}
foreach ((glob($ROOT . '/*.php') ?: []) as $f) { $files[] = $f; }
$files[] = $ROOT . '/app.template.html';
$files[] = $ROOT . '/sw.js';
foreach ($files as $f) {
    if (realpath($f) === realpath(__FILE__)) { continue; }                 // this suite names them on purpose
    $src = (string) @file_get_contents($f);
    if ($src === '') { continue; }
    $scanned++;
    foreach (DEAD as $k) {
        if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $src)) {
            $hits[] = str_replace($ROOT . '/', '', str_replace('\\', '/', $f)) . ':' . $k;
        }
    }
}
check('no PHP, JS or template reads a deleted key', $hits === [], $hits === [] ? $scanned . ' files scanned' : implode(', ', $hits));
$adminSettings = (string) @file_get_contents($ROOT . '/admin/settings.php');
$uiHits = [];
foreach (DEAD as $k) { if (str_contains($adminSettings, $k)) { $uiHits[] = $k; } }
check('admin/settings.php offers no field for a deleted key', $uiHits === [], implode(', ', $uiHits));
check('the deleted keys are not public settings the SPA could still receive', !preg_match('/\b(' . implode('|', array_map(static fn(string $k): string => preg_quote($k, '/'), DEAD)) . ')\b/', (string) json_encode(Settings::publicSettings())));

echo "\n-- C. this database --\n";
try {
    $ph = [];
    $params = [];
    foreach (DEAD as $i => $k) { $ph[] = ':k' . $i; $params[':k' . $i] = $k; }
    $st = Database::pdo()->prepare('SELECT skey FROM settings WHERE skey IN (' . implode(',', $ph) . ')');
    $st->execute($params);
    $left = array_map(static fn(array $r): string => (string) $r['skey'], $st->fetchAll(PDO::FETCH_ASSOC));
    check('none of the eleven rows is left (upgrade applied)', $left === [], $left === [] ? 'clean' : 'still here: ' . implode(', ', $left) . ' — run php tests/apply-sql.php database/upgrade-2026-09-27-dead-switches.sql');
} catch (Throwable $e) {
    check('the settings table can be read', false, $e->getMessage());
}

echo "\n-- D. the inverse --\n";
$ab = (string) @file_get_contents($ROOT . '/includes/assetbundle.php');
check('bundle_assets_on is still read by includes/assetbundle.php', str_contains($ab, "'bundle_assets_on'"));
check('ai_web_agent_on (a different key) is still read by index.php', str_contains((string) @file_get_contents($ROOT . '/index.php'), "'ai_web_agent_on'"));

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
