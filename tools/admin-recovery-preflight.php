<?php
/** Read-only deployment check. CLI only; never prints records or credentials. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    // Legacy bootstrap/DB failures may otherwise terminate with exit status 0.
    if (!$completed) { fwrite(STDERR, "FAIL preflight terminated before completion\n"); exit(1); }
});
$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) { $fail++; }
};
try {
    require dirname(__DIR__) . '/admin/_guard.php';
    foreach (['LiveSync', 'Brand', 'Seats', 'AgentWallet'] as $class) {
        $check('dependency ' . $class, class_exists($class));
    }
    foreach (['admin_multi_bus_days', 'admin_bus_tag', 'admin_page_head', 'admin_kpi',
              'admin_empty', 'admin_avatar', 'admin_wa_button', 'admin_wa_js'] as $function) {
        $check('helper ' . $function, function_exists($function));
    }
    foreach (['staff_applications'=>['id','admin_id'],
              'admin_profiles'=>['admin_id','parent_agent_id','monthly_salary','duty_status','assigned_bus_id'],
              'bookings'=>['id','sold_by_admin_id','parent_agent_id'],
              'booking_seats'=>['schedule_id','seat_no','booking_id','released_at'],
              'schedules'=>['id','route_id','travel_date','slot']] as $table=>$columns) {
        $actual = array_column(Database::fetchAll(
            'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t',
            ['t'=>$table]), 'column_name');
        $check('schema ' . $table, array_diff($columns, $actual) === []);
    }
    $indexes=Database::fetchAll("SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
        FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='booking_seats'
          AND non_unique=0 GROUP BY index_name");
    $check('seat inventory uniqueness', in_array('schedule_id,seat_no', array_column($indexes,'cols'),true));
    $check('staff reference convention', str_contains((string)file_get_contents(dirname(__DIR__).'/admin/agents.php'),
        "CONCAT('STF-', LPAD(sap.id, 6, '0'))"));
    $check('sync version read', LiveSync::version('all') !== 'err');
} catch (Throwable $e) {
    // Exception details remain in private logs; operator gets a stable failure.
    $check('dependency/schema inspection (' . get_class($e) . ')', false);
}
$completed = true;
echo $fail === 0 ? "READY: read-only recovery checks passed\n" : "NOT READY: inspect failed checks\n";
exit($fail === 0 ? 0 : 1);
