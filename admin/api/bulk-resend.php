<?php
/**
 * admin/api/bulk-resend.php — send several tickets again on WhatsApp
 * (29 Oct 2026, management upgrade 3.1 "bulk resend ticket").
 *
 * POST (CSRF field named CSRF_TOKEN_NAME):
 *     ids[]   bookings.id values, at most BULK_RESEND_MAX
 *
 * The same send as the single "Resend" button on admin/booking-view.php —
 * Notify::resendTicketWhatsApp() per booking, one booking.resend_wa audit
 * row and one message_logs row (purpose ticket_resend) each — so the
 * idempotency guard and the retry cron never mistake a resend for the
 * first ticket. Only CONFIRMED tickets are sent; anything else is reported
 * by PNR and skipped.
 *
 * Auth: payments.verify (the single button's right). An agent's list is
 * scoped to their own sales and a desk-isolated clerk to their desk, and
 * that scope is re-checked here per booking, so a hand-made POST cannot
 * resend someone else's ticket.
 *
 * Response: JSON, HTTP 200 always —
 *     {ok:true, message, requested, sent, results:[{id,pnr,ok,error?}]}
 *     {ok:false, error}
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/notify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const BULK_RESEND_MAX = 25;

/** @param array<string,mixed> $p */
function br_out(array $p): never
{
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        br_out(['ok' => false, 'error' => 'POST only.']);
    }
    if (!Security::verifyCsrf()) {
        br_out(['ok' => false, 'error' => 'Session expired. Please refresh the page and try again.']);
    }
    if (!Auth::can('payments.verify')) {
        br_out(['ok' => false, 'error' => 'Your role cannot re-send tickets.']);
    }
    $adminId = (int) ($admin['id'] ?? 0);
    if (!Security::rateLimit('bulk_resend', 'admin:' . $adminId, 6, 60)) {
        br_out(['ok' => false, 'error' => 'Too many bulk sends in a short time — please wait a minute.']);
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn(int $v): bool => $v > 0)));
    if ($ids === []) {
        br_out(['ok' => false, 'error' => 'Tick at least one ticket.']);
    }
    if (count($ids) > BULK_RESEND_MAX) {
        br_out(['ok' => false, 'error' => 'Resend works on up to ' . BULK_RESEND_MAX . ' tickets at a time.']);
    }

    $scopeId = Auth::bookingScopeAdminId();
    $results = [];
    $sent    = 0;
    foreach ($ids as $id) {
        $row = Database::fetch('SELECT id, pnr, status, sold_by_admin_id FROM bookings WHERE id = :i', ['i' => $id]);
        if ($row === null) {
            $results[] = ['id' => $id, 'pnr' => '', 'ok' => false, 'error' => 'not found'];
            continue;
        }
        $pnr = (string) $row['pnr'];
        if (($scopeId !== null && (int) $row['sold_by_admin_id'] !== $scopeId) || !CounterDesk::bookingVisible($id)) {
            $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => 'not one of your tickets'];
            continue;
        }
        if ((string) $row['status'] !== 'confirmed') {
            $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => 'not confirmed (' . (string) $row['status'] . ')'];
            continue;
        }
        try {
            $b = BookingService::detail($pnr);
            if ($b === null) {
                throw new RuntimeException('booking could not be loaded');
            }
            $r      = Notify::resendTicketWhatsApp($b);
            $manual = (string) ($r['link'] ?? '');
            $status = $r['ok'] ? 'sent' : ($manual !== '' ? 'skipped' : 'failed');
            if (($r['provider'] ?? '') !== 'aisensy') {   // AiSensy writes its own row
                try {
                    $log = [
                        'booking_id' => $id, 'channel' => 'whatsapp', 'provider' => $manual !== '' ? 'manual' : 'twilio',
                        'to_number' => substr((string) $b['contact_phone'], 0, 32), 'body' => 'Ticket resend (admin, bulk)',
                        'status' => $status, 'provider_ref' => '', 'error' => $r['ok'] ? null : mb_substr((string) $r['detail'], 0, 255),
                    ];
                    try { Database::insert('message_logs', $log + ['purpose' => 'ticket_resend']); }
                    catch (Throwable $e) { Database::insert('message_logs', $log); }
                } catch (Throwable $e) { /* message_logs is informational */ }
            }
            Logger::audit('booking.resend_wa', 'booking', $pnr, null, ['status' => $status, 'to' => (string) $b['contact_phone'], 'bulk' => true],
                'Ticket resend on WhatsApp (bulk) by admin #' . $adminId);
            if ($r['ok']) {
                $sent++;
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => true];
            } else {
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => $manual !== '' ? 'no WhatsApp API — open the ticket to send by hand' : (string) $r['detail']];
            }
        } catch (Throwable $e) {
            $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    br_out([
        'ok'        => true,
        'message'   => $sent . ' of ' . count($ids) . ' ticket(s) sent again on WhatsApp.',
        'requested' => count($ids),
        'sent'      => $sent,
        'results'   => $results,
    ]);
} catch (Throwable $e) {
    Logger::error('bulk resend failed: ' . $e->getMessage(), [], 'whatsapp');
    br_out(['ok' => false, 'error' => 'Something went wrong — nothing further was sent.']);
}
