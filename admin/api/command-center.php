<?php
/**
 * admin/api/command-center.php — the dashboard's live counter board, alert
 * feed and approvals count, polled every 60 s (29 Oct 2026, management
 * upgrade 2.4 / 4.3). Read-only JSON; same gate as the dashboard.
 *
 *   {ok, at, counters:[{id,name,desk,desk_name,state,since,shift_id,tickets,cash,upi,drawer}],
 *    alerts:[{level,icon,text,href}], approvals:[{label,n,amount,href}]}
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/commandcenter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $today = todayISO();
    echo json_encode([
        'ok'        => true,
        'at'        => date('H:i:s'),
        'counters'  => CommandCenter::counterBoard($today),
        'alerts'    => CommandCenter::alerts($today),
        'approvals' => CommandCenter::approvals(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Logger::error('command-center poll failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'unavailable']);
}
