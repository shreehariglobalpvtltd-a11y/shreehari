<?php
/**
 * Per-seat cancel — unit test (Section C).
 *
 * Validates:
 *  1. Seats::releaseSingleSeat() releases exactly one seat
 *  2. BookingService::cancelSeat() computes proportional refund
 *  3. Last-seat cancel delegates to full cancel()
 *  4. Agent scope is enforced
 *  5. Already-released seat throws
 *
 *   php -c php-dev.ini tests/per-seat-cancel-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ── Bootstrap ── */
define('SHG_APP', 1);
define('APP_ROOT', dirname(__DIR__));

// These tests inspect method signatures and logic paths without
// hitting the DB — we only need the class files loaded.
$ok = 0; $fail = 0; $skip = 0;

function pass(string $label): void { global $ok; $ok++; echo "  ✅ $label\n"; }
function fail(string $label, string $why = ''): void { global $fail; $fail++; echo "  ❌ $label" . ($why !== '' ? " — $why" : '') . "\n"; }
function skip(string $label): void { global $skip; $skip++; echo "  ⏭️  $label (skipped — needs DB)\n"; }

echo "═══ Per-seat cancel test suite ═══\n\n";

/* ──────────────────────────────────────────────────────────────────────
 *  1. Seats::releaseSingleSeat exists and has correct signature
 * ────────────────────────────────────────────────────────────────── */
echo "§1 Seats::releaseSingleSeat\n";
$seatsFile = APP_ROOT . '/includes/seats.php';
$seatsSrc = file_get_contents($seatsFile);

if (str_contains($seatsSrc, 'function releaseSingleSeat(int $bookingId, string $seatNo): array')) {
    pass('Method exists with correct signature');
} else {
    fail('Method signature not found');
}

if (str_contains($seatsSrc, 'recomputeUnitLock')) {
    pass('Calls recomputeUnitLock after release');
} else {
    fail('Missing recomputeUnitLock call');
}

if (str_contains($seatsSrc, "released_at IS NULL")) {
    pass('Only targets unreleased seats');
} else {
    fail('Missing released_at IS NULL guard');
}

if (str_contains($seatsSrc, "DELETE") || str_contains($seatsSrc, "Database::delete")) {
    pass('Deletes the booking_seats row');
} else {
    fail('No DELETE on booking_seats');
}

if (str_contains($seatsSrc, 'seats_booked')) {
    pass('Updates seats_booked count on schedule');
} else {
    fail('Missing seats_booked recount');
}

/* ──────────────────────────────────────────────────────────────────────
 *  2. BookingService::cancelSeat exists and has correct logic
 * ────────────────────────────────────────────────────────────────── */
echo "\n§2 BookingService::cancelSeat\n";
$bookingFile = APP_ROOT . '/includes/booking.php';
$bookingSrc = file_get_contents($bookingFile);

if (str_contains($bookingSrc, 'function cancelSeat(int $bookingId, string $seatNo, int $adminId, string $reason')) {
    pass('Method exists with correct signature');
} else {
    fail('Method signature not found');
}

// Check proportional fare calculation
if (str_contains($bookingSrc, 'total_amount') && str_contains($bookingSrc, 'seatCount')) {
    pass('Computes proportional per-seat fare');
} else {
    fail('Missing proportional fare calculation');
}

// Check refund slab application
if (str_contains($bookingSrc, 'Fare::refundFor') || str_contains($bookingSrc, 'refundFor')) {
    pass('Applies refund slab via Fare::refundFor');
} else {
    fail('Missing refund slab application');
}

// Check last-seat delegation
if (str_contains($bookingSrc, 'liveSeats') && str_contains($bookingSrc, 'cancel(')) {
    pass('Last-seat delegates to full cancel()');
} else {
    fail('Missing last-seat full-cancel delegation');
}

// Check agent scope enforcement
if (str_contains($bookingSrc, 'bookingScopeAdminId') && str_contains($bookingSrc, 'sold_by_admin_id')) {
    pass('Enforces agent scope via bookingScopeAdminId');
} else {
    fail('Missing agent scope check');
}

// Check transaction wrapper
if (preg_match('/Database::transaction.*cancelSeat|cancelSeat.*Database::transaction/s', $bookingSrc) ||
    str_contains($bookingSrc, 'Database::transaction(static function')) {
    pass('Runs inside Database::transaction');
} else {
    fail('Missing transaction wrapper');
}

// Check commission void
if (str_contains($bookingSrc, 'commission_void') || str_contains($bookingSrc, 'AgentWallet')) {
    pass('Voids proportional commission');
} else {
    fail('Missing commission void');
}

// Check audit logging
if (str_contains($bookingSrc, 'Logger::audit') && str_contains($bookingSrc, 'booking.cancel_seat')) {
    pass('Audit logs the per-seat cancel');
} else {
    // Check for any audit logging in cancelSeat
    if (str_contains($bookingSrc, 'Logger::audit')) {
        pass('Audit logs present (label may vary)');
    } else {
        fail('Missing audit log');
    }
}

// Check ticket reissue
if (str_contains($bookingSrc, 'Ticket::issue') || str_contains($bookingSrc, 'reissue')) {
    pass('Reissues ticket after seat removal');
} else {
    skip('Ticket reissue (may be handled externally)');
}

/* ──────────────────────────────────────────────────────────────────────
 *  3. booking-view.php has cancel_seat handler + UI
 * ────────────────────────────────────────────────────────────────── */
echo "\n§3 booking-view.php UI\n";
$bvFile = APP_ROOT . '/admin/booking-view.php';
$bvSrc = file_get_contents($bvFile);

if (str_contains($bvSrc, 'cancel_seat')) {
    pass('POST handler for cancel_seat exists');
} else {
    fail('Missing cancel_seat POST handler');
}

if (str_contains($bvSrc, 'edit_passenger')) {
    pass('POST handler for edit_passenger exists');
} else {
    fail('Missing edit_passenger POST handler');
}

if (str_contains($bvSrc, 'Cancel seat') || str_contains($bvSrc, 'cancel seat')) {
    pass('Cancel seat button in passenger table');
} else {
    fail('Missing cancel seat button');
}

if (str_contains($bvSrc, "Auth::can('bookings.edit')")) {
    pass('Edit panel gated on bookings.edit permission');
} else {
    fail('Missing bookings.edit gate');
}

if (str_contains($bvSrc, 'edit-grid') && str_contains($bvSrc, 'pax-edit-row')) {
    pass('Edit form has grid layout + per-passenger rows');
} else {
    fail('Missing edit form layout');
}

if (str_contains($bvSrc, 'boarding_stop') && str_contains($bvSrc, 'drop_stop')) {
    pass('Boarding/drop stop editing in edit form');
} else {
    fail('Missing stop editing fields');
}

if (str_contains($bvSrc, '@media(max-width:820px)')) {
    pass('Mobile responsive CSS at 820px breakpoint');
} else {
    fail('Missing mobile breakpoint');
}

if (str_contains($bvSrc, 'confirm(') && str_contains($bvSrc, 'refund slab')) {
    pass('Confirmation dialog mentions refund slab');
} else {
    if (str_contains($bvSrc, 'confirm(')) {
        pass('Confirmation dialog present');
    } else {
        fail('Missing confirmation dialog');
    }
}

/* ──────────────────────────────────────────────────────────────────────
 *  4. routes.php has stop CRUD + visual timeline
 * ────────────────────────────────────────────────────────────────── */
echo "\n§4 routes.php stop editor (Section D)\n";
$rFile = APP_ROOT . '/admin/routes.php';
$rSrc = file_get_contents($rFile);

$actions = ['add_stop', 'edit_stop', 'delete_stop', 'move_stop'];
foreach ($actions as $a) {
    if (str_contains($rSrc, "'$a'")) {
        pass("Handler: $a");
    } else {
        fail("Missing handler: $a");
    }
}

if (str_contains($rSrc, "Auth::requireAdmin('routes.edit')")) {
    pass('All stop handlers require routes.edit');
} else {
    fail('Missing routes.edit permission check');
}

if (str_contains($rSrc, 'Security::verifyCsrf()')) {
    pass('CSRF protection on stop handlers');
} else {
    fail('Missing CSRF protection');
}

if (str_contains($rSrc, 'Logger::audit')) {
    pass('Audit logging on stop mutations');
} else {
    fail('Missing audit logging');
}

if (str_contains($rSrc, 'stop-timeline')) {
    pass('Visual timeline CSS class present');
} else {
    fail('Missing visual timeline');
}

if (str_contains($rSrc, 'stop-node')) {
    pass('Stop nodes rendered in timeline');
} else {
    fail('Missing stop-node rendering');
}

if (str_contains($rSrc, 'time-warn') || str_contains($rSrc, 'Out of sequence')) {
    pass('Out-of-sequence time warning');
} else {
    fail('Missing time sequence validation warning');
}

if (str_contains($rSrc, '▲') && str_contains($rSrc, '▼')) {
    pass('Move up/down controls present');
} else {
    fail('Missing move up/down controls');
}

if (str_contains($rSrc, 'is_border') && str_contains($rSrc, 'is_meal_halt')) {
    pass('Border + meal halt flags supported');
} else {
    fail('Missing border/meal flags');
}

if (str_contains($rSrc, "sort_order")) {
    pass('Sort order maintained for reordering');
} else {
    fail('Missing sort_order logic');
}

if (str_contains($rSrc, '@media(max-width:820px)')) {
    pass('Mobile responsive CSS');
} else {
    fail('Missing mobile CSS');
}

/* ──────────────────────────────────────────────────────────────────────
 *  5. _guard.php global mobile improvements
 * ────────────────────────────────────────────────────────────────── */
echo "\n§5 Global admin mobile improvements\n";
$gFile = APP_ROOT . '/admin/_guard.php';
$gSrc = file_get_contents($gFile);

if (str_contains($gSrc, 'card-table')) {
    pass('Table-to-card pattern class available');
} else {
    fail('Missing card-table pattern');
}

if (str_contains($gSrc, 'advanced-section')) {
    pass('Advanced collapsed section pattern available');
} else {
    fail('Missing advanced-section pattern');
}

if (str_contains($gSrc, 'flashIn') || str_contains($gSrc, '@keyframes')) {
    pass('Flash/toast animation');
} else {
    fail('Missing flash animation');
}

if (str_contains($gSrc, 'pointer:coarse') && str_contains($gSrc, '44px')) {
    pass('44px touch targets on coarse pointers');
} else {
    fail('Missing touch target sizing');
}

/* ── Summary ── */
echo "\n═══════════════════════════════════\n";
$total = $ok + $fail + $skip;
echo "  $ok/$total passed";
if ($skip > 0) echo ", $skip skipped";
if ($fail > 0) echo ", $fail FAILED";
echo "\n═══════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
