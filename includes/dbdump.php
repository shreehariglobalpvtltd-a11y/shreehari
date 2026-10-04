<?php
/**
 * =====================================================================
 *  DbDump — a database backup on demand (5 Oct 2026)
 *
 *  The same pure-PHP dump cron/backup.php writes every night (gzipped SQL
 *  in BACKUP_PATH, newest backup_keep kept), callable from the Backup page
 *  and the Google Drive upload when no fresh nightly file exists. Records
 *  the dump in `backups` with the right column types (the nightly job's
 *  insert has been failing quietly: it put 'cron' into a number column).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DbDump
{
    private function __construct() {}

    /**
     * @return array{ok:bool, file:string, path:string, size:int, tables:int, rows:int, error?:string}
     */
    public static function run(string $trigger = 'manual', ?int $by = null): array
    {
        @set_time_limit(300);
        ensureDir(BACKUP_PATH);
        $file = BACKUP_PATH . '/backup_' . date('Ymd_His') . '.sql.gz';
        $gz   = @gzopen($file, 'wb9');
        if ($gz === false) {
            return ['ok' => false, 'file' => '', 'path' => '', 'size' => 0, 'tables' => 0, 'rows' => 0, 'error' => 'cannot write to the backup folder'];
        }
        $rows = 0;
        try {
            gzwrite($gz, "-- S Hari Global database backup\n-- " . date('c') . " ({$trigger})\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = Database::fetchAll('SHOW TABLES');
            foreach ($tables as $t) {
                $table  = str_replace('`', '', (string) array_values($t)[0]);
                $create = Database::fetch('SHOW CREATE TABLE `' . $table . '`');
                $ddl    = $create['Create Table'] ?? ($create['Create View'] ?? '');
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n");
                if (isset($create['Create View'])) {
                    continue;
                }
                for ($offset = 0; ; $offset += 500) {
                    $data = Database::fetchAll("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}");
                    if ($data === []) {
                        break;
                    }
                    foreach ($data as $r) {
                        $cols = implode(',', array_map(static fn ($c) => '`' . $c . '`', array_keys($r)));
                        $vals = implode(',', array_map(static fn ($v) => $v === null ? 'NULL'
                            : "'" . str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", "\\n", "\\r"], (string) $v) . "'", array_values($r)));
                        gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});\n");
                        $rows++;
                    }
                }
                gzwrite($gz, "\n");
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($file);
            Logger::exception($e, 'backup');
            return ['ok' => false, 'file' => '', 'path' => '', 'size' => 0, 'tables' => 0, 'rows' => 0, 'error' => 'dump failed: ' . $e->getMessage()];
        }
        gzclose($gz);
        $size = (int) (@filesize($file) ?: 0);

        $keep = max(3, Settings::getInt('backup_keep', 14));
        $all  = glob(BACKUP_PATH . '/backup_*.sql.gz') ?: [];
        rsort($all);
        foreach (array_slice($all, $keep) as $old) {
            @unlink($old);
        }
        try {
            Database::insert('backups', [
                'filename' => basename($file), 'file_size' => $size, 'table_count' => count($tables),
                'row_count' => $rows, 'trigger_type' => $trigger === 'cron' ? 'cron' : 'manual',
                'created_by' => $by, 'status' => 'success', 'note' => $trigger,
            ]);
        } catch (Throwable $ignored) {
        }
        return ['ok' => true, 'file' => basename($file), 'path' => $file, 'size' => $size, 'tables' => count($tables), 'rows' => $rows];
    }

    /** Newest local backup file (nightly or manual), or null. */
    public static function latest(): ?array
    {
        $all = glob(BACKUP_PATH . '/backup_*.sql.gz') ?: [];
        rsort($all);
        foreach ($all as $f) {
            if (is_readable($f) && (int) @filesize($f) > 0) {
                return ['file' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
            }
        }
        return null;
    }

    /** @return list<array{file:string,size:int,mtime:int}> newest first */
    public static function listLocal(int $max = 20): array
    {
        $all = glob(BACKUP_PATH . '/backup_*.sql.gz') ?: [];
        rsort($all);
        $out = [];
        foreach (array_slice($all, 0, $max) as $f) {
            $out[] = ['file' => basename($f), 'size' => (int) @filesize($f), 'mtime' => (int) @filemtime($f)];
        }
        return $out;
    }
}
