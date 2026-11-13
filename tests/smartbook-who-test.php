<?php
/**
 * smartbook-who-test.php — 13 Nov 2026 (mg13): SmartBook::who(), the "who is
 * this mobile?" lookup behind the auto-fill in both ticket forms.
 *
 *   - a customer session (nobody signed in) never gets a stranger's party
 *   - selling staff get the last confirmed ticket's party: name, gender, band
 *   - an agent gets it only for a number they sold to themselves
 *   - a number that never travelled = found:false (the same answer as "not allowed")
 *
 * Read-only against the TEST database.   php tests/smartbook-who-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    $ok ? $PASS++ : $FAIL++;
    echo '  ' . ($ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
}
function signIn(?array $a): void
{
    if ($a === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
}

$b = Database::fetch(
    "SELECT b.id, b.contact_phone, b.sold_by_admin_id FROM bookings b
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
      WHERE b.status IN ('confirmed','completed') AND b.contact_phone NOT LIKE '0000%' AND CHAR_LENGTH(b.contact_phone) >= 10
   ORDER BY b.id DESC LIMIT 1"
);
if ($b === null) { echo "SKIP: the test database has no confirmed booking with a mobile.\n"; exit(0); }
$phone = (string) $b['contact_phone'];
$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($super === null) { echo "SKIP: no superadmin in the test database.\n"; exit(0); }

echo "-- SmartBook::who --\n";
signIn(null);
$r = SmartBook::who($phone, SmartBook::sessionMode());
check('nobody signed in: a typed number shows nothing', $r['found'] === false && $r['people'] === [] && $r['name'] === '');

signIn($super);
$mode = SmartBook::sessionMode('counter');
$r    = SmartBook::who($phone, $mode);
check('selling staff: the number is found', $r['found'] === true, 'mode ' . $mode);
check('the party comes back (1-6 people)', count($r['people']) >= 1 && count($r['people']) <= 6, count($r['people']) . ' people');
$keys = $r['people'] !== [] ? array_keys($r['people'][0]) : [];
check('each person carries name, age, gender, band', $keys === ['name', 'age', 'gender', 'band'], implode(',', $keys));
$okG = true;
foreach ($r['people'] as $p) {
    $okG = $okG && in_array($p['gender'], ['', 'Male', 'Female'], true) && in_array($p['band'], ['adult', 'child', 'senior', 'patient'], true)
        && $p['age'] >= 0 && $p['age'] < 100 && preg_match('/\(\d+\)$/', $p['name']) !== 1;
}
check('gender is Male / Female / blank, band is a known one, no "(2)" suffix in names', $okG);
check('trips counted', $r['trips'] >= 1, (string) $r['trips']);
check('direction is one of the two runs (or blank)', in_array($r['direction'], ['', 'toNepal', 'toIndia'], true), $r['direction']);

$r = SmartBook::who('9000000001', $mode);
check('a number that never travelled: found=false', $r['found'] === false && $r['trips'] === 0);
$r = SmartBook::who('abc', $mode);
check('rubbish input: found=false', $r['found'] === false);

$agent = Database::fetch("SELECT * FROM admins WHERE role = 'agent' AND is_active = 1 AND id <> :s ORDER BY id LIMIT 1", ['s' => (int) ($b['sold_by_admin_id'] ?? 0)]);
if ($agent !== null) {
    signIn($agent);
    $mine = (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings WHERE contact_phone = :p AND status IN ('confirmed','completed') AND sold_by_admin_id = :a",
        ['p' => $phone, 'a' => (int) $agent['id']], 0
    );
    $r = SmartBook::who($phone, SmartBook::sessionMode());
    check('an agent sees a party only for a number they sold to', $r['found'] === ($mine > 0), 'own sales to it: ' . $mine);
} else {
    echo "  (no second agent account — agent scope not exercised)\n";
}
signIn(null);

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
