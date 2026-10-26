<?php
/**
 * cron/backup.php — a pure-PHP database dump (no mysqldump needed).
 *
 * Writes a gzipped .sql to /backup and keeps the most recent N. Runs on
 * shared hosting where shell access to mysqldump is unavailable.
 * Recommended: daily.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';

$keep  = Settings::getInt('backup_keep', 14);
$stamp = date('Ymd_His');
$file  = BACKUP_PATH . '/backup_' . $stamp . '.sql.gz';

ensureDir(BACKUP_PATH);
$gz = gzopen($file, 'wb9');
if ($gz === false) {
    http_response_code(500);
    exit('Could not open backup file for writing.');
}

$w = static function (string $s) use ($gz): void { gzwrite($gz, $s); };

$w("-- S Hari Global database backup\n-- " . date('c') . "\n");
$w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

$tables = Database::fetchAll('SHOW TABLES');
$rowsTotal = 0;

foreach ($tables as $row) {
    $table = (string) array_values($row)[0];

    // Structure
    $create = Database::fetch('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
    $ddl    = $create['Create Table'] ?? ($create['Create View'] ?? '');
    $w("DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n");

    // Data, streamed in pages so a large table never blows memory.
    $offset = 0;
    $page   = 500;
    while (true) {
        $data = Database::fetchAll("SELECT * FROM `{$table}` LIMIT {$page} OFFSET {$offset}");
        if ($data === []) { break; }

        foreach ($data as $r) {
            $cols = array_map(static fn($c) => '`' . $c . '`', array_keys($r));
            $vals = array_map(static function ($v) {
                if ($v === null) { return 'NULL'; }
                return "'" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], (string) $v) . "'";
            }, array_values($r));
            $w('INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
            $rowsTotal++;
        }
        $offset += $page;
    }
    $w("\n");
}

$w("SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);

// Retention: keep the newest $keep dumps.
$all = glob(BACKUP_PATH . '/backup_*.sql.gz') ?: [];
rsort($all);
$removed = 0;
foreach (array_slice($all, $keep) as $old) {
    if (@unlink($old)) { $removed++; }
}

// Record it for the admin backup list, if the table is present.
try {
    Database::insert('backups', [
        'filename'   => basename($file),
        'file_size'  => (int) (@filesize($file) ?: 0),
        'table_count'=> count($tables),
        'row_count'  => $rowsTotal,
        'created_by' => 'cron',
    ]);
} catch (Throwable $e) { /* backups table optional */ }

cron_done([
    'file'    => basename($file),
    'sizeKB'  => (int) round((@filesize($file) ?: 0) / 1024),
    'tables'  => count($tables),
    'rows'    => $rowsTotal,
    'pruned'  => $removed,
]);
