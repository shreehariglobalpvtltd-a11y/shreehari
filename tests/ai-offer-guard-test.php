<?php
/**
 * =====================================================================
 *  ai-offer-guard-test.php — the AI never states a discount the office
 *  is not running (23 Oct 2026, owner: "S Hari AI le 20% off bhanchha,
 *  sarasar galat").
 *
 *    php tests/ai-offer-guard-test.php
 *
 *  No AI key needed: AiAgent::offerGuard() is checked on fixed replies,
 *  once with no offer running and once with a 10% test offer. Test
 *  database only; the test offer is removed at the end.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/fare.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const OG_CODE = 'TESTOFFERGUARD';

// Park every running auto offer so "none running" is really none.
$parked = Database::fetchAll('SELECT id FROM coupons WHERE is_active = 1 AND auto_apply = 1');
foreach ($parked as $p) {
    Database::run('UPDATE coupons SET is_active = 0 WHERE id = :id', ['id' => $p['id']]);
}
Database::run('DELETE FROM coupons WHERE code = :c', ['c' => OG_CODE]);

try {
    echo "\n— no offer running —\n";
    check('runningOffers() is empty', Fare::runningOffers() === []);

    $r = AiAgent::offerGuard("नमस्ते 🙏\nअहिले online बुक गर्दा 20% छुट छ!\nभाडा ₹2000।", 'ne');
    check('Nepali "20% छुट" is removed', !str_contains($r, '20%'), $r);
    check('  and the true line takes its place', str_contains($r, 'कुनै छुट/अफर चलिरहेको छैन'), $r);
    check('  other lines are kept', str_contains($r, 'नमस्ते') && str_contains($r, '₹2000'), $r);

    $r = AiAgent::offerGuard('Book online and get 20% off on your ticket. Fare is ₹2000.', 'en');
    check('English "20% off" is removed', !str_contains($r, '20%') && str_contains($r, 'No discount or offer'), $r);

    $r = AiAgent::offerGuard('अभी 20% की छूट मिल रही है, आप बुक कीजिए।', 'hi');
    check('Hindi "20% की छूट" is removed in Hindi', !str_contains($r, '20%') && str_contains($r, 'कोई छूट'), $r);

    $r = AiAgent::offerGuard('online booking ma 20 percent discount chha', '');
    check('roman "20 percent discount" is removed', !str_contains($r, '20 percent'), $r);

    $refund = "↩️ रद्द गर्दा फिर्ता:\n• 24 घण्टा अगाडि: 90%\n• बस छुट्ने 6 घण्टा भित्र: 50%";
    check('refund percentages pass untouched (छुट्ने = departs)', AiAgent::offerGuard($refund, 'ne') === $refund);

    $plain = 'बस साँझ 6 बजे छुट्छ। भाडा ₹2000।';
    check('a reply with no % is returned as it is', AiAgent::offerGuard($plain, 'ne') === $plain);

    echo "\n— a 10% offer running —\n";
    Database::insert('coupons', [
        'code' => OG_CODE, 'title' => 'Test offer 10%', 'discount_type' => 'percent', 'discount_value' => 10,
        'max_discount' => null, 'min_amount' => 0, 'usage_limit' => null, 'per_user_limit' => 0,
        'is_active' => 1, 'auto_apply' => 1,
    ]);
    $ok10 = 'अहिले 10% छुट चलिरहेको छ, 24 घण्टा अगाडि बुक गर्दा।';
    check('the real 10% offer is kept', AiAgent::offerGuard($ok10, 'ne') === $ok10);
    $r = AiAgent::offerGuard('Today you get 20% off!', 'en');
    check('20% is replaced by the real offer line', !str_contains($r, '20%') && str_contains($r, 'Test offer 10%'), $r);
} finally {
    Database::run('DELETE FROM coupons WHERE code = :c', ['c' => OG_CODE]);
    foreach ($parked as $p) {
        Database::run('UPDATE coupons SET is_active = 1 WHERE id = :id', ['id' => $p['id']]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
