<?php
/**
 * database/upgrade-2026-09-settings-json-repair.php
 *
 * One-time repair (4 Sep 2026): every `settings` row with stype = 'json'
 * whose value was wrapped in extra layers of JSON encoding by the old
 * Settings::setMany() path is decoded back to the object it represents
 * and re-stored once, cleanly. Rows that are already clean are untouched.
 *
 * Idempotent: run it as many times as you like. Read-only preview:
 *   php database/upgrade-2026-09-settings-json-repair.php --dry-run
 * Apply:
 *   php database/upgrade-2026-09-settings-json-repair.php
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$rows = Database::fetchAll("SELECT skey, svalue FROM settings WHERE stype = 'json'");
$fixed = 0;
$clean = 0;
$bad   = 0;

foreach ($rows as $row) {
    $key = (string) $row['skey'];
    $raw = (string) ($row['svalue'] ?? '');
    $first = json_decode($raw, true);
    if ($raw === '' || json_last_error() !== JSON_ERROR_NONE) {
        echo str_pad($key, 24) . " INVALID  (left as is)\n";
        $bad++;
        continue;
    }
    if (!is_string($first)) {
        $clean++;
        continue;                                   // already a real object/array
    }
    try {
        $value = Settings::decodeJsonLayers($raw, $key);
    } catch (InvalidArgumentException $e) {
        echo str_pad($key, 24) . " INVALID  " . $e->getMessage() . "\n";
        $bad++;
        continue;
    }
    if (is_string($value)) {
        echo str_pad($key, 24) . " string value, nothing to peel\n";
        $clean++;
        continue;
    }
    $layers = 0;
    $probe = $raw;
    while (is_string($probe = json_decode($probe, true)) && $layers < 12) { $layers++; }
    echo str_pad($key, 24) . ' peeled ' . ($layers + 1) . " layer(s), " . strlen($raw) . ' -> '
        . strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . " bytes"
        . ($dry ? '  [dry-run]' : '') . "\n";
    if (!$dry) {
        Database::query(
            'UPDATE settings SET svalue = :v WHERE skey = :k',
            ['v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'k' => $key]
        );
        $fixed++;
    }
}

echo "\n" . count($rows) . " json rows: {$clean} clean, {$fixed} repaired" . ($dry ? ' (would be)' : '') . ", {$bad} invalid\n";
exit($bad > 0 ? 1 : 0);
