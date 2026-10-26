<?php
/**
 * TripStatus — the state ladder.
 *
 *   php -c .claude/php-dev.ini tests/tripstatus-test.php
 *
 * Every case pins a fake NOW so the test is deterministic. Runs offline —
 * TripNotify::statusMap() is only called via annotate(), and only when
 * the class is already loaded.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/tripstatus.php';

$P = 0; $F = 0;
function ok(string $l, bool $c): void { global $P, $F; if ($c) { $P++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $F++; echo "  \033[31mFAIL\033[0m  $l\n"; } }

echo "\n=== TripStatus — 9-state ladder ===\n\n";

// A trip: Aug 30 2026, dep 18:30, arr 06:30 next day.
$row = ['travel_date' => '2026-08-30', 'dep_time' => '18:30:00', 'arr_time' => '06:30:00', 'day_offset' => 2, 'status' => 'scheduled', 'delay_minutes' => 0];

// 1. 30 hours before departure → Upcoming (blue-ish grey)
$s = TripStatus::compute($row, strtotime('2026-08-29 12:30'));
ok('30h before → Upcoming',        $s['state'] === TripStatus::STATE_UPCOMING);

// 2. 2 hours before → Departing Soon
$s = TripStatus::compute($row, strtotime('2026-08-30 16:30'));
ok('2h before → Departing Soon',   $s['state'] === TripStatus::STATE_DEPARTING);

// 3. 20 minutes before → Boarding
$s = TripStatus::compute($row, strtotime('2026-08-30 18:10'));
ok('20m before → Boarding',        $s['state'] === TripStatus::STATE_BOARDING);
ok('  Boarding.minutesTo ≈ 20',    $s['minutesTo'] >= 19 && $s['minutesTo'] <= 21);

// 4. Exactly at dep_time (no departed mark, no delay) → Departed (short grace)
$s = TripStatus::compute($row, strtotime('2026-08-30 18:31'));
ok('At dep_time (no mark) → Departed', $s['state'] === TripStatus::STATE_DEPARTED);

// 5. 30 min past dep_time (no departed mark, no delay) → Delayed
$s = TripStatus::compute($row, strtotime('2026-08-30 19:00'));
ok('30m past + no mark → Delayed', $s['state'] === TripStatus::STATE_DELAYED);

// 6. delay_minutes=60 shifts effective dep_time — 20m before EFFECTIVE = Boarding
$rowDelay = ['delay_minutes' => 60] + $row;
$s = TripStatus::compute($rowDelay, strtotime('2026-08-30 19:10'));
ok('delay=60m, 20m before effective dep → Boarding',
    $s['state'] === TripStatus::STATE_BOARDING);

// 6b. delay_minutes=60 and now=18:45 → 45m before EFFECTIVE, past SCHEDULED = Departing Soon
$s = TripStatus::compute($rowDelay, strtotime('2026-08-30 18:45'));
ok('delay=60m, 15m past scheduled but pre-effective → Departing Soon',
    $s['state'] === TripStatus::STATE_DEPARTING);

// 7. delay_minutes=60 AND we're past even the effective dep_time → Delayed
$s = TripStatus::compute($rowDelay, strtotime('2026-08-30 20:00'));
ok('delay=60m, 30m past effective → Delayed',
    $s['state'] === TripStatus::STATE_DELAYED);

// 8. Departed mark present, no arrived mark → On Route
$rowMark = $row;
$rowMark['marks'] = ['departed' => '2026-08-30 18:32:00'];
$s = TripStatus::compute($rowMark, strtotime('2026-08-30 22:00'));
ok('departed mark + no arrived → On Route', $s['state'] === TripStatus::STATE_ON_ROUTE);

// 9. Departed + border mark → On Route with border detail
$rowMark['marks']['border'] = '2026-08-31 01:00:00';
$s = TripStatus::compute($rowMark, strtotime('2026-08-31 01:20'));
ok('departed + border → On Route (border detail)',
    $s['state'] === TripStatus::STATE_ON_ROUTE && str_contains($s['detail'], 'border'));

// 10. 30 minutes after arr_time (no arrived mark) → Arrived (auto)
$s = TripStatus::compute($rowMark, strtotime('2026-08-31 07:15'));
ok('30m past arr_time (no arrived mark) → Arrived (auto)',
    $s['state'] === TripStatus::STATE_ARRIVED);

// 11. Explicit arrived mark, recent → Arrived
$rowMark['marks']['arrived'] = '2026-08-31 06:35:00';
$s = TripStatus::compute($rowMark, strtotime('2026-08-31 06:40'));
ok('arrived mark, <1h ago → Arrived', $s['state'] === TripStatus::STATE_ARRIVED);

// 12. Arrived mark > 1h ago → Completed
$s = TripStatus::compute($rowMark, strtotime('2026-08-31 08:00'));
ok('arrived mark, >1h ago → Completed', $s['state'] === TripStatus::STATE_COMPLETED);

// 13. status=cancelled dominates everything
$rowC = ['status' => 'cancelled'] + $rowMark;
$s = TripStatus::compute($rowC, strtotime('2026-08-30 12:00'));
ok('status=cancelled → Cancelled', $s['state'] === TripStatus::STATE_CANCELLED);

// 14. Meta lookup completeness — all 9 states have a colour
$states = [TripStatus::STATE_UPCOMING, TripStatus::STATE_DEPARTING, TripStatus::STATE_BOARDING,
    TripStatus::STATE_DEPARTED, TripStatus::STATE_ON_ROUTE, TripStatus::STATE_ARRIVED,
    TripStatus::STATE_COMPLETED, TripStatus::STATE_DELAYED, TripStatus::STATE_CANCELLED];
$ok = true;
foreach ($states as $st) {
    $m = TripStatus::meta($st);
    if (!isset($m['label']) || !isset($m['color']) || strlen($m['color']) !== 7) { $ok = false; break; }
}
ok('all 9 states carry a #RRGGBB colour', $ok);

// 15. annotate() adds a _status without falling over on empty
$out = TripStatus::annotate([]);
ok('annotate([]) → []', $out === []);

$out = TripStatus::annotate([['id' => 999999, 'travel_date' => '2099-01-01', 'dep_time' => '10:00:00']]);
ok('annotate() attaches _status', isset($out[0]['_status']['state']) && $out[0]['_status']['state'] === TripStatus::STATE_UPCOMING);

// ─── Phase 6: isEditable / isBookable tests ────────────────

echo "\n=== Phase 6 — edit-window gates ===\n\n";

// 17. Upcoming → editable + bookable
$s = $row; // scheduled, 30h away
ok('Upcoming → isEditable=true',  TripStatus::isEditable($s, strtotime('2026-08-29 12:30')));
ok('Upcoming → isBookable=true',  TripStatus::isBookable($s, strtotime('2026-08-29 12:30')));

// 18. Departing Soon → editable + bookable
ok('Departing → isEditable=true', TripStatus::isEditable($row, strtotime('2026-08-30 16:30')));
ok('Departing → isBookable=true', TripStatus::isBookable($row, strtotime('2026-08-30 16:30')));

// 19. Boarding → editable but NOT bookable (too close, existing edits ok)
ok('Boarding → isEditable=true',  TripStatus::isEditable($row, strtotime('2026-08-30 18:10')));
ok('Boarding → isBookable=false', !TripStatus::isBookable($row, strtotime('2026-08-30 18:10')));

// 20. Delayed → editable + bookable (bus hasn't left yet)
$rowDelay2 = ['delay_minutes' => 60] + $row;
ok('Delayed → isEditable=true',   TripStatus::isEditable($rowDelay2, strtotime('2026-08-30 20:00')));
ok('Delayed → isBookable=true',   TripStatus::isBookable($rowDelay2, strtotime('2026-08-30 20:00')));

// 21. Departed (mark) → NOT editable, NOT bookable
$rowDep = $row;
$rowDep['marks'] = ['departed' => '2026-08-30 18:35:00'];
ok('Departed → isEditable=false', !TripStatus::isEditable($rowDep, strtotime('2026-08-30 19:00')));
ok('Departed → isBookable=false', !TripStatus::isBookable($rowDep, strtotime('2026-08-30 19:00')));

// 22. Arrived → NOT editable
$rowArr = $row;
$rowArr['marks'] = ['departed' => '2026-08-30 18:35:00', 'arrived' => '2026-08-31 06:35:00'];
ok('Arrived → isEditable=false', !TripStatus::isEditable($rowArr, strtotime('2026-08-31 06:40')));

// 23. Completed → NOT editable
ok('Completed → isEditable=false', !TripStatus::isEditable($rowArr, strtotime('2026-08-31 08:00')));

// 24. Cancelled → NOT editable
$rowCan = ['status' => 'cancelled'] + $row;
ok('Cancelled → isEditable=false', !TripStatus::isEditable($rowCan, strtotime('2026-08-30 12:00')));
ok('Cancelled → isBookable=false', !TripStatus::isBookable($rowCan, strtotime('2026-08-30 12:00')));

// ─── Counter gate (owner ask 2 Sep): wider than the website ──
// The desk must be able to add a passenger who was missed even after the
// coach has left and while it is still running — up until it arrives.
echo "\n=== Counter booking gate (wider than website) ===\n\n";
ok('Boarding -> counterBookable=true',      TripStatus::isCounterBookable($row, strtotime('2026-08-30 18:10')));
ok('Departed (no mark) -> counterBookable=true', TripStatus::isCounterBookable($row, strtotime('2026-08-30 18:33')));
ok('On route (departed mark) -> counterBookable=true', TripStatus::isCounterBookable($rowDep, strtotime('2026-08-30 22:00')));
ok('Arrived -> counterBookable=false',      !TripStatus::isCounterBookable($rowArr, strtotime('2026-08-31 06:40')));
ok('Cancelled -> counterBookable=false',    !TripStatus::isCounterBookable($rowCan, strtotime('2026-08-30 12:00')));
// The website gate must NOT widen — a customer still cannot buy onto a bus that left.
ok('Departed -> isBookable=false (website still closed)', !TripStatus::isBookable($row, strtotime('2026-08-30 18:33')));

echo "\n----------------------------------------\n";
echo "  $P passed, $F failed\n";
echo "----------------------------------------\n\n";
exit($F === 0 ? 0 : 1);
