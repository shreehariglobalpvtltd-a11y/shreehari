<?php
/**
 * admin/api/bulk-cancel.php — cancel many tickets in one action (4 Sep 2026).
 *
 * POST (CSRF field named CSRF_TOKEN_NAME):
 *     ids[]    bookings.id values (max BookingService::BULK_CANCEL_MAX)
 *     reason   free text, printed on every cancelled booking + the audit row
 *     confirm  must be "1" — the server-side half of the confirmation step;
 *              the register's modal sets it only after the operator has seen
 *              the list of PNRs and pressed "Yes, cancel".
 *
 * Auth: any staff session holding bookings.view may CALL this; whether each
 * booking may actually be cancelled is decided per booking inside
 * BookingService::bulkCancel() via Auth::mayCancelBooking():
 *     bookings.cancel      (manager / superadmin / extra grant) → any ticket
 *     bookings.cancel_own  (agent)                              → only tickets they sold
 * A ticket the caller may not cancel is skipped and reported by PNR — never
 * cancelled. Every call writes one booking.bulk_cancel audit row (who, how
 * many, which PNRs, the reason) plus the usual booking.cancel row per PNR.
 *
 * Response: JSON, HTTP 200 always —
 *     {ok:true,  message, requested, cancelled, failed, skipped, results:[{id,pnr,ok,error?,refund?}]}
 *     {ok:false, error}
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @param array<string,mixed> $p */
function bc_out(array $p): void
{
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bc_out(['ok' => false, 'error' => 'POST only.']);
    }
    if (!Security::verifyCsrf()) {
        bc_out(['ok' => false, 'error' => 'Session expired. Please refresh the page and try again.']);
    }
    if (!Auth::mayCancelAny()) {
        Logger::warning('Bulk cancel refused: role may not cancel', ['admin' => $admin['username'] ?? '']);
        bc_out(['ok' => false, 'error' => 'Your role does not allow cancelling tickets.']);
    }

    $adminId = (int) ($admin['id'] ?? 0);
    // 10 bulk actions a minute per staff account is plenty for a counter and
    // stops a stuck "retry" loop from hammering the cancel path.
    if (!Security::rateLimit('bulk_cancel', 'admin:' . $adminId, 10, 60)) {
        bc_out(['ok' => false, 'error' => 'Too many bulk actions in a short time — please wait a moment.']);
    }
    if ((string) ($_POST['confirm'] ?? '') !== '1') {
        bc_out(['ok' => false, 'error' => 'Please confirm the cancellation first.']);
    }

    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) {
        $ids = preg_split('/[\s,]+/', $ids) ?: [];
    }
    if (!is_array($ids)) {
        $ids = [];
    }
    $reason = Security::clean((string) ($_POST['reason'] ?? ''), 255);

    $r = BookingService::bulkCancel($ids, $reason, $adminId);

    $msg = $r['cancelled'] . ' of ' . $r['requested'] . ' ticket' . ($r['requested'] === 1 ? '' : 's') . ' cancelled.'
         . ($r['skipped'] > 0 ? ' ' . $r['skipped'] . ' skipped (not yours / already closed).' : '')
         . ($r['failed'] > 0 ? ' ' . $r['failed'] . ' could not be cancelled.' : '');

    bc_out(['ok' => true, 'message' => $msg] + $r);
} catch (RuntimeException $e) {
    bc_out(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    Logger::error('bulk-cancel fatal', ['err' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'admin');
    bc_out(['ok' => false, 'error' => 'Something went wrong. Nothing further was cancelled.']);
}
