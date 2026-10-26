<?php
/**
 * upgrade-2026-09-fares.php — make the flat fares editable from the panel
 * (3 Sep 2026, Module 2 of the admin upgrade).
 *
 * Until now Fare::dirFares() hard-coded 2000 (toNepal) / 1800 (toIndia) and
 * the only override key (main_fares) was written by a dead in-app admin box.
 * Admin → Settings → "Fares & booking rules" now edits two real settings rows:
 *
 *   fare_to_nepal   Gujarat → Rupaidiha, ₹ per person (sharing sleeper)
 *   fare_to_india   Rupaidiha → Gujarat, ₹ per person
 *
 * Seeded from whatever Fare::dirFares() returns TODAY (main_fares override
 * included), so the live price does not change by running this. Also marks
 * max_seats_per_booking and counter_max_discount_pct public so the customer
 * app can read the same seat cap the counter uses (Module 3). Idempotent.
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-09-fares.php      (local)
 *   php database/upgrade-2026-09-fares.php                              (VPS)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

$effective = Fare::dirFares();   // read BEFORE seeding: falls back to code/main_fares

$labels = [
    'fare_to_nepal' => 'Fare Gujarat → Rupaidiha (₹ / person)',
    'fare_to_india' => 'Fare Rupaidiha → Gujarat (₹ / person)',
];
foreach (['fare_to_nepal' => 'toNepal', 'fare_to_india' => 'toIndia'] as $key => $dir) {
    $current = Settings::get($key, null);
    if ($current === null || (float) $current <= 0) {
        $val = (float) ($effective[$dir] ?? 0) > 0 ? (float) $effective[$dir] : ($dir === 'toNepal' ? 2000.0 : 1800.0);
        Settings::set($key, $val, 'float', 'pricing', true);
        echo "Seeded {$key} = {$val}\n";
    } else {
        echo "{$key} already set = {$current}\n";
    }
}

// Ensure the two rows exist even when the code default was used, then set
// label + public flag directly: Settings::set() only updates svalue/stype on
// an existing row (ON DUPLICATE KEY), so is_public/sgroup/label need SQL.
foreach ($labels as $key => $label) {
    Database::query(
        'UPDATE settings SET label = :l, sgroup = :g, is_public = 1 WHERE skey = :k',
        ['l' => $label, 'g' => 'pricing', 'k' => $key]
    );
}

// Same cap everywhere: the SPA reads public settings, so flag these public
// (value untouched). Rows are created with the code defaults if missing.
foreach ([
    'max_seats_per_booking'    => ['int',   'booking', (string) Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING)],
    'counter_max_discount_pct' => ['float', 'booking', (string) Settings::getFloat('counter_max_discount_pct', 15.0)],
] as $key => [$type, $group, $val]) {
    Settings::set($key, $val, $type, $group, true);
    Database::query('UPDATE settings SET is_public = 1 WHERE skey = :k', ['k' => $key]);
    echo "{$key} = {$val} (public)\n";
}

Settings::flush();
$after = Fare::dirFares();
echo "Fare::dirFares() now: toNepal={$after['toNepal']} toIndia={$after['toIndia']}\n";
exit(0);
