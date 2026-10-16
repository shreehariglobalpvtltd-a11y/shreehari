<?php
/**
 * Dev helper: apply a .sql file to the configured database through the
 * app's own PDO connection (so it uses config.local.php in dev). CLI only.
 *
 *     php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-08-seat-units.sql
 *
 * Strips `-- ` line comments and runs each `;`-separated statement. Safe to
 * re-run for idempotent migrations (CREATE TABLE IF NOT EXISTS etc.).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: apply-sql.php <file.sql>\n");
    exit(1);
}

$sql   = (string) file_get_contents($file);
$sql   = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql; // drop line comments
$stmts = array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '');

$pdo  = Database::pdo();
$fail = 0;
foreach ($stmts as $stmt) {
    try {
        /* 24 Sep 2026: query() + closeCursor() instead of exec(). The guarded
           ALTERs (SET @sql := IF(...) / PREPARE / EXECUTE) return a one-row
           "already present" result set, and exec() left it unbuffered, so the
           NEXT statement died with "Cannot execute queries while other
           unbuffered queries are active" and every guarded migration reported
           a false failure through this helper. */
        $st = $pdo->query($stmt);
        if ($st instanceof PDOStatement) {
            try { $st->fetchAll(); } catch (Throwable $ignored) {}
            $st->closeCursor();
        }
        echo 'OK  ' . substr(preg_replace('/\s+/', ' ', $stmt) ?? '', 0, 64) . "\n";
    } catch (Throwable $e) {
        $fail++;
        echo 'ERR ' . $e->getMessage() . "\n";
    }
}
echo $fail === 0 ? "done\n" : "done with {$fail} error(s)\n";
exit($fail === 0 ? 0 : 1);
