<?php
/** Read-only journey list in the existing agent profile; seller scope is mandatory. */
declare(strict_types=1);
if (!defined('SHG_APP') || !isset($viewId, $today)) { http_response_code(404); exit; }
$journeyRows = Database::fetchAll(
    "SELECT bl.id, bl.schedule_id, bl.travel_date, b.pnr, b.status, s.slot, r.coach_type,
            r.from_city, r.to_city, COALESCE(s.dep_time_override,r.dep_time) AS departure,
            COALESCE(bus.bus_number,'') AS bus_number,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ')
               FROM booking_seats bs WHERE bs.leg_id=bl.id AND bs.released_at IS NULL) AS seats,
            (SELECT GROUP_CONCAT(bp.full_name ORDER BY bp.is_primary DESC,bp.id SEPARATOR ', ')
               FROM booking_passengers bp WHERE bp.booking_id=b.id AND (bp.leg_id=bl.id
                  OR (bp.leg_id IS NULL AND bl.leg_type='outbound'))) AS passengers
       FROM bookings b JOIN booking_legs bl ON bl.booking_id=b.id
       JOIN schedules s ON s.id=bl.schedule_id JOIN routes r ON r.id=s.route_id
       LEFT JOIN buses bus ON bus.id=COALESCE(s.bus_id,r.bus_id)
      WHERE b.sold_by_admin_id=:agent AND bl.travel_date BETWEEN :start AND :end
        AND b.status IN ('pending','confirmed','completed','cancelled')
      ORDER BY bl.travel_date, departure, s.slot, b.id LIMIT 300",
    ['agent'=>$viewId,'start'=>addDaysISO($today,-7),'end'=>addDaysISO($today,30)]
);
?>
<section class="panel" id="tripsByJourney">
  <h2>Passengers by journey date · यात्रा मिति अनुसार</h2>
  <p class="muted">Last 7 days and next 30 days · Up to 300 journey entries in date order.
    <a href="/admin/agent-sales.php?<?= Security::e(http_build_query(['agent'=>$viewId,'range'=>'all'])) ?>">Full sales history →</a></p>
  <?php if ($journeyRows === []): ?>
    <p class="muted">No passengers in this journey-date window.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table>
    <thead><tr><th>Journey</th><th>Route / bus</th><th>Ticket</th><th>Passengers</th><th>Seat references</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($journeyRows as $journey): ?>
    <tr>
      <td><?= Security::e(formatDate($journey['travel_date'])) ?><br><?= Security::e(formatTime($journey['departure'])) ?></td>
      <td><?= Security::e($journey['from_city'].' → '.$journey['to_city']) ?><br><?= Security::e(admin_bus_tag((int)$journey['slot'],$journey['bus_number'],'',true)) ?></td>
      <td><a href="/admin/booking-view.php?<?= Security::e(http_build_query(['pnr'=>$journey['pnr']])) ?>"><?= Security::e($journey['pnr']) ?></a></td>
      <td><?= Security::e((string)($journey['passengers'] ?? '')) ?></td>
      <td><?= Security::e((string)($journey['seats'] ?? '—')) ?></td>
      <td><?= admin_pill($journey['status']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
