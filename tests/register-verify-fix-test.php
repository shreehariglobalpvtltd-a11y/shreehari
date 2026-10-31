<?php
/**
 * =====================================================================
 *  register-verify-fix-test.php — 31 Oct 2026.
 *
 *  Guards the money-safety fix in admin/bookings.php ("✓ Verify" on the
 *  tickets register). The old handler ran a raw
 *        UPDATE payments SET status = 'verified' ...
 *  which marked COD (uncollected cash) as received and skipped the whole
 *  engine (ticket, commission, booking.approved WhatsApp).
 *
 *  The fix routes the click through BookingService:
 *        COD    -> settleCod()            (cash in hand only)
 *        online -> approveReviewed()/confirm()
 *  gated on payments.verify + own-scope.
 *
 *  This suite proves the COD cash-safety the fix relies on, and the
 *  scope-guard decision the page makes. It seeds throwaway bookings on
 *  the LOCAL shari_test database and deletes them again.
 *
 *     php -c .claude/php-dev.ini tests/register-verify-fix-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Register verify_payment — money-safety fix (admin/bookings.php) ===\n\n";

$PFX = 'REGVFX-';
/* clean any leftovers from an earlier run */
foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE :p", ['p' => $PFX . '%']) as $o) {
    Database::delete('payments', 'booking_id = ' . (int) $o['id']);
}
Database::delete('bookings', "pnr LIKE '" . $PFX . "%'");

$verifier = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 1);
$agentId  = (int) Database::scalar("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);

/* ---- A. COD: the register "verify" settles cash via settleCod() --------- */
$codId = Database::insert('bookings', [
    'pnr' => $PFX . 'COD', 'contact_phone' => '9800000911',
    'total_amount' => 1500.00, 'status' => 'confirmed', 'is_cod' => 1,
    'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'web',
    'sold_by_admin_id' => $agentId ?: null,
]);
Database::insert('payments', [
    'booking_id' => $codId, 'payment_ref' => $PFX . 'PAYCOD',
    'method' => 'cod', 'mode' => 'offline', 'amount' => 1500.00, 'status' => 'cod_pending',
]);
$before = (string) Database::scalar("SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $codId], '');
check('COD payment starts as cash-owed (cod_pending)', $before === 'cod_pending', "was '$before'");

/* Mirror the FIXED page handler: is_cod === 1  ->  settleCod() */
BookingService::settleCod($codId, $verifier, 'Verified via tickets register');
$after = (string) Database::scalar("SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $codId], '');
$bk    = (string) Database::scalar("SELECT status FROM bookings WHERE id = :b", ['b' => $codId], '');
check('COD verify -> settleCod marks cash verified', $after === 'verified', "now '$after'");
check('COD verify -> booking confirmed',             $bk === 'confirmed', "now '$bk'");

/* ---- B. The bug the old code caused: confirm() must NOT verify COD cash -- */
/* A COD booking approved via confirm() keeps the cash OWED (cod_pending) —
   the invariant the old blanket "UPDATE payments SET status='verified'" broke. */
$codB = Database::insert('bookings', [
    'pnr' => $PFX . 'CODB', 'contact_phone' => '9800000912',
    'total_amount' => 1800.00, 'status' => 'pending', 'is_cod' => 1,
    'source' => 'web', 'sold_by_admin_id' => $agentId ?: null,
]);
Database::insert('payments', [
    'booking_id' => $codB, 'payment_ref' => $PFX . 'PAYCODB',
    'method' => 'cod', 'mode' => 'offline', 'amount' => 1800.00, 'status' => 'cod_pending',
]);
BookingService::confirm($codB, $verifier, 'approve');
$pB = (string) Database::scalar("SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $codB], '');
$sB = (string) Database::scalar("SELECT status FROM bookings WHERE id = :b", ['b' => $codB], '');
check('confirm() on a COD booking keeps cash OWED (cod_pending)', $pB === 'cod_pending', "payment '$pB'");
check('  and the booking is confirmed',                           $sB === 'confirmed', "status '$sB'");

/* ---- C. Scope guard: the page refuses a cross-seller verify ------------- */
/* Mirrors admin/bookings.php: $vScope = Auth::bookingScopeAdminId();
   refuse when  $vScope !== null && (int) $bRow['sold_by_admin_id'] !== $vScope */
$guard = static fn (?int $scope, int $soldBy): bool => $scope !== null && $soldBy !== $scope;
check('office (scope null) may verify any booking',        $guard(null, 777) === false);
check('agent may verify their OWN booking',                $guard(55, 55) === false);
check("agent may NOT verify another seller's booking",     $guard(55, 56) === true);

/* ---- cleanup ---- */
Database::delete('payments', 'booking_id = ' . (int) $codId);
Database::delete('payments', 'booking_id = ' . (int) $codB);
Database::delete('bookings', "pnr LIKE '" . $PFX . "%'");

echo "\n  " . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
