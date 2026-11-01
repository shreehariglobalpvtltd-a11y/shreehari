<?php
/**
 * GET /api/routine.php — the coming departures of the 3-bus routine, for the
 * "कुन दिन कुन बस" box beside the booking form on the home page (owner,
 * 31 Oct 2026: "home page mai, ticket kinne aaspaas, customer lai pani thaha
 * hos kaile janchha").
 *
 * Read-only, public, no personal data: date, BUS 0N, direction, departure
 * time and the plate only once the office has entered it (BusRoutine hides
 * an XXXX placeholder). Trips the office switched off are left out, so the
 * box never offers a bus the search will not sell.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/busroutine.php';

try {
    $days = max(1, min(30, (int) ($_GET['days'] ?? 14)));
    $out  = [];
    $now = date('H:i');
    foreach (BusRoutine::departures(todayISO(), $days) as $d) {
        // Today's bus that has already left is not offered.
        if ($d['date'] === todayISO() && $d['depTime'] !== '' && $d['depTime'] <= $now) {
            continue;
        }
        $out[] = [
            'date'      => $d['date'],
            'line'      => $d['line'],
            'toNepal'   => $d['direction'] === BusRoutine::DIR_TO_NEPAL,
            'from'      => $d['from'],
            'to'        => $d['to'],
            'depTime'   => $d['depTime'],
            'busNumber' => $d['busNumber'],
        ];
    }

    Response::$publicCacheSeconds = 60;
    Response::success([
        'on'         => BusRoutine::enabled(),
        'today'      => todayISO(),
        'departures' => $out,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
