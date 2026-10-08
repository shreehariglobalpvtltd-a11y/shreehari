<?php
/**
 * =====================================================================
 *  placeholder-phone-test.php — the walk-in placeholder is never proof
 *
 *  The desk stores 0000000000 for a walk-in who gave no mobile. Until
 *  8 Oct 2026 every customer ownership check compared phones with a plain
 *  ===, so "+91 0000000000" (which normalises to that stored value) read
 *  the full ticket of every walk-in by PNR, downloaded it, and could
 *  cancel it. This suite pins:
 *
 *    • isPlaceholderPhone() recognises the placeholder in every spelling
 *    • a customer can never sign in / book with an all-one-digit number,
 *      while the desk (staff) still may record the walk-in placeholder
 *    • every customer door that matches phones refuses the placeholder
 *      (track, cancel, ticket download, verify, AI assistant, undo,
 *      feedback, push, split-pay, live tracking)
 *    • a real walk-in booking's track lookup with "+91 0000000000" is
 *      only the partial (status) answer
 *
 *    php -c .claude/php-dev.ini tests/placeholder-phone-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Walk-in placeholder is never proof ===\n\n";
$root = dirname(__DIR__);

echo "-- 1. isPlaceholderPhone --\n";
foreach (['0000000000', '+91 0000000000', '910000000000', '000000000000', '00910000000000', '1111111111', '9999999999', '', '98123'] as $p) {
    check("placeholder: '$p'", isPlaceholderPhone($p) === true);
}
foreach (['9834614102', '+91 98346 14102', '+977 9812345678', '9779812345678', '7359301507'] as $p) {
    check("a real number is not a placeholder: '$p'", isPlaceholderPhone($p) === false);
}

echo "-- 2. Customers can't use it; the desk still can --\n";
foreach (['0000000000', '1111111111', '9999999999'] as $p) {
    check("customer refused: $p", Security::isValidPhone($p) === false);
    check("desk may record: $p", Security::isValidPhone($p, true) === true);
}
check('a normal Indian mobile still passes', Security::isValidPhone('9834614102'));
check('a normal Nepali mobile still passes', Security::isValidPhone(normalisePhone('+977 9812345678')));

echo "-- 3. Every customer door guards the placeholder --\n";
/* 8 Oct 2026: cancel, ticket download and verify no longer match phones
   themselves — they ask Auth::customerMayOpen(), which refuses the
   placeholder first (and is pinned below and in identity-gate-test.php). */
$doors = [
    'api/track.php'            => 'isPlaceholderPhone((string) $detail[\'contact_phone\'])',
    'includes/auth.php'        => 'if (isPlaceholderPhone($contact)) {',
    'api/cancel.php'           => 'Auth::customerMayOpen($booking)',
    'download-ticket.php'      => 'Auth::customerMayOpen($booking)',
    'verify-ticket.php'        => 'Auth::customerMayOpen($booking)',
    'includes/aitools.php'     => 'isPlaceholderPhone((string) ($detail[\'contact_phone\'] ?? \'\'))',
    'includes/quickticket.php' => 'isPlaceholderPhone((string) ($booking[\'contact_phone\'] ?? \'\'))',
    'api/feedback.php'         => 'isPlaceholderPhone((string) ($booking[\'contact_phone\'] ?? \'\'))',
    'api/push.php'             => 'isPlaceholderPhone($bPhone)',
    'api/split-pay.php'        => 'isPlaceholderPhone((string) ($b[\'contact_phone\'] ?? \'\'))',
    'includes/whereis.php'     => 'isPlaceholderPhone((string) ($booking[\'contact_phone\'] ?? \'\'))',
];
foreach ($doors as $file => $needle) {
    check("$file refuses the placeholder", str_contains((string) file_get_contents($root . '/' . $file), $needle));
}
$_SESSION['shg_own_pnrs'] = ['SHG-2026-00001'];
check('Auth::customerMayOpen refuses a walk-in booking even when this session made it',
    Auth::customerMayOpen(['pnr' => 'SHG-2026-00001', 'contact_phone' => '0000000000']) === false);
unset($_SESSION['shg_own_pnrs']);

echo "-- 4. A real walk-in sale stays private over HTTP --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
$walkIn = (string) Database::scalar(
    "SELECT pnr FROM bookings WHERE contact_phone = '0000000000' AND status = 'confirmed' ORDER BY id DESC LIMIT 1", [], ''
);
if ($base === '' || $walkIn === '') {
    echo "  (skipped: " . ($base === '' ? 'SHG_TEST_BASE not set' : 'no walk-in booking in this DB') . ")\n";
} else {
    foreach (['+91 0000000000', '910000000000', '000000000000'] as $try) {
        $url = $base . '/api/track.php?pnr=' . rawurlencode($walkIn) . '&phone=' . rawurlencode($try);
        $raw = @file_get_contents($url);
        $j   = is_string($raw) ? json_decode($raw, true) : null;
        $partial = is_array($j) && !empty($j['data']['partial']) && empty($j['data']['passengers']);
        check("track $walkIn with '$try' gives only the partial answer", $partial, is_string($raw) ? substr($raw, 0, 90) : 'no response');
    }
}

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
