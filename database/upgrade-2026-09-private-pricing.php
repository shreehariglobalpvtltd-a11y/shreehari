<?php
/**
 * upgrade-2026-09-private-pricing.php — set Private Sleeper cabin prices to the
 * owner's figures (3 Sep 2026):
 *
 *   Private Single = ₹3,800   (single_1pax)
 *   Private Double = ₹7,600   (double_2pax)
 *
 * The authoritative price lives in the `cabin_pricing` settings row (it overrides
 * both the server Fare::pricing() fallback and the client CONFIG.cabinPricing via
 * applyServerPricing()). This updates ONLY the two private rates — sharing rates,
 * sharingByDir and onlineDiscountPct are left exactly as they are. Flat pricing:
 * online == offline. Idempotent — safe to run more than once.
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-09-private-pricing.php      (local)
 *   php database/upgrade-2026-09-private-pricing.php                              (VPS)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PRIVATE_SINGLE = 3800;
$PRIVATE_DOUBLE = 7600;

$cp = Settings::getArray('cabin_pricing', []);
if (!isset($cp['private']) || !is_array($cp['private'])) {
    fwrite(STDERR, "cabin_pricing.private missing — aborting (nothing changed).\n");
    exit(1);
}

$before = json_encode($cp['private'], JSON_UNESCAPED_UNICODE);

foreach (['single_1pax' => $PRIVATE_SINGLE, 'double_2pax' => $PRIVATE_DOUBLE] as $key => $price) {
    if (!isset($cp['private'][$key]) || !is_array($cp['private'][$key])) {
        $cp['private'][$key] = [];
    }
    $cp['private'][$key]['offline'] = $price;   // flat pricing: online == offline
    $cp['private'][$key]['online']  = $price;
}

// Preserve the row's public flag + group so it keeps shipping in SHG_BOOT.
Settings::set('cabin_pricing', $cp, 'json', 'pricing', true);

// Task 9: seed the counter discount cap so it appears in Admin → Settings and
// is editable. The code already defaults to 15% if this row is absent, so this
// only makes the limit visible/adjustable; existing value (if any) is kept.
if (Settings::get('counter_max_discount_pct', null) === null) {
    Settings::set('counter_max_discount_pct', 15, 'float', 'booking', true);
    echo "Seeded counter_max_discount_pct = 15%\n";
} else {
    echo "counter_max_discount_pct already set = " . Settings::getFloat('counter_max_discount_pct', 0) . "%\n";
}

$after = json_encode(Settings::getArray('cabin_pricing', [])['private'] ?? [], JSON_UNESCAPED_UNICODE);

echo "Private pricing updated.\n";
echo "  before: $before\n";
echo "  after : $after\n";
echo "  Single = ₹{$PRIVATE_SINGLE} · Double = ₹{$PRIVATE_DOUBLE}\n";
exit(0);
