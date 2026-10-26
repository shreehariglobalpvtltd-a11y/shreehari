<?php
/**
 * upgrade-2026-10-04-counter-desks.php — carry the office's own desk list
 * into the new counter_locations TABLE (4 Oct 2026).
 *
 * Run AFTER database/upgrade-2026-10-04-counter-desks.sql:
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-10-04-counter-desks.php   (local)
 *   php database/upgrade-2026-10-04-counter-desks.php                          (VPS)
 *
 * The SQL seeds the eleven desks with the repo's names. The live Settings
 * row may spell them differently (the office edits it by hand — live NPJ is
 * "Nepalgunj — Puspalal Chowk") or carry a desk the seed has never heard of.
 * This step makes the office's list win:
 *
 *   - a code in the Settings row and in the table  -> the table takes the
 *     Settings NAME, but only while the row is untouched (created_at =
 *     updated_at), so a desk edited on Admin -> Counters is never overwritten
 *     by re-running this.
 *   - a code only in the Settings row -> inserted as an India / INR desk of
 *     the India company. The office sets country and money on the screen;
 *     nothing about a desk nobody described is guessed.
 *
 * Phone, address and hours are never written here. Idempotent. Prints what
 * it did; changes no booking, payment or ledger row.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!CounterDesk::hasTable()) {
    fwrite(STDERR, "counter_locations table missing - run database/upgrade-2026-10-04-counter-desks.sql first.\n");
    exit(1);
}

$office  = Settings::counterLocationsRaw();
$renamed = 0;
$added   = 0;
$sort    = (int) Database::scalar('SELECT COALESCE(MAX(sort_order), 0) FROM counter_locations');

foreach ($office as $code => $name) {
    $row = Database::fetch('SELECT id, name, created_at, updated_at FROM counter_locations WHERE code = :c', ['c' => $code]);
    if ($row === null) {
        $sort += 10;
        Database::insert('counter_locations', [
            'code' => $code, 'name' => $name, 'country' => 'IN', 'currency' => 'INR',
            'entity' => 'IN', 'sort_order' => $sort,
        ]);
        echo "added    {$code}  {$name}  (IN / INR - set country and money on Admin -> Counters)\n";
        $added++;
        continue;
    }
    if ((string) $row['name'] !== $name && (string) $row['created_at'] === (string) $row['updated_at']) {
        Database::run('UPDATE counter_locations SET name = :n, updated_at = created_at WHERE id = :i',
            ['n' => $name, 'i' => (int) $row['id']]);
        echo "renamed  {$code}  \"{$row['name']}\" -> \"{$name}\"\n";
        $renamed++;
    }
}

CounterDesk::flush();
echo "done: {$renamed} renamed, {$added} added, " . count(CounterDesk::all()) . " desks in the table.\n";
