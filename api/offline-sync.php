<?php
/**
 * POST /api/offline-sync.php — a desk's offline requests arrive (19 Sep 2026).
 *
 * Body: { items: [ {uuid, name, phone, country, gender, seats, direction,
 *                   date, boarding, pay, cash, note, takenAt}, ... ] }   max 20
 * →     { results: [ {uuid, status: ticketed|failed|received|rejected,
 *                     pnr, seats, total, reason, repeat} ] }
 *
 * Also { action: "ping" } → { on, staff } so the offline page can tell "not
 * signed in" from "switched off" from "ready" without sending a request.
 *
 * Selling staff only. Each item is seated ONCE by its uuid (see
 * includes/offlinequeue.php); sending the same batch again is harmless and is
 * exactly what the phone does after a dropped connection.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/offlinequeue.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $in    = Response::input();
    $staff = Auth::admin();

    if ((string) ($in['action'] ?? '') === 'ping') {
        Response::success([
            'on'    => OfflineQueue::enabled(),
            'staff' => $staff !== null && Auth::isSellingStaff() ? (string) ($staff['full_name'] ?? $staff['username'] ?? 'staff') : null,
        ]);
    }

    if ($staff === null || !Auth::isSellingStaff()) {
        Response::forbidden('Sign in as counter staff to send offline requests.');
    }
    if (!OfflineQueue::enabled()) {
        Response::error('The offline desk is switched off. Ask the office.', 409);
    }
    Security::requireRateLimit('offline_sync', 'admin:' . (int) $staff['id'], 30, 60);

    $items = is_array($in['items'] ?? null) ? array_slice(array_values($in['items']), 0, OfflineQueue::MAX_BATCH) : [];
    if ($items === []) {
        Response::invalid(['items' => 'Nothing to send.']);
    }

    $results = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        try {
            $results[] = OfflineQueue::sync($item, $staff);
        } catch (RuntimeException $e) {
            $results[] = ['uuid' => (string) ($item['uuid'] ?? ''), 'status' => 'rejected', 'pnr' => null, 'seats' => [],
                          'total' => null, 'reason' => $e->getMessage(), 'repeat' => false];
        }
    }
    Response::success(['results' => $results]);
} catch (Throwable $e) {
    Logger::error('offline-sync failed', ['e' => $e->getMessage()]);
    Response::error('Could not send the requests - they are still on this phone. Try again.', 500);
}
