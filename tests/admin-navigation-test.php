<?php
declare(strict_types=1);
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/adminnavigation.php';
function expect(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$items = [
    ['href'=>'index.php','section'=>''],
    ['href'=>'smartbook.php','section'=>'Bookings & tickets','perm'=>'bookings.view','hot'=>true],
    ['href'=>'seatmap.php','section'=>'Seat management'],
    ['href'=>'gps-center.php','section'=>'Trips, buses & chalani'],
    ['href'=>'team.php','section'=>'Agents & counters'],
    ['href'=>'staff-applications.php','section'=>'Trips, buses & chalani'],
    ['href'=>'drivers.php','section'=>'Trips, buses & chalani'],
    ['href'=>'cash-book.php','section'=>'Agents & counters'],
    ['href'=>'staff.php','section'=>'Agents & counters'],
    ['href'=>'accounting.php','section'=>'Ledger & payments'],
    ['href'=>'analytics.php','section'=>'Expenses & reports'],
    ['href'=>'routes.php#pickups','section'=>'Settings'],
    ['href'=>'tours.php','section'=>'Website content'],
];
$groups = AdminNavigation::groups($items);
expect(array_keys($groups) === ['operations','team','finance'], 'three categories in business order');
$flat = array_merge(...array_values($groups));
expect(count($flat) === count($items)-1, 'dashboard stands alone; all other links survive');
foreach (array_slice($items,1) as $item) {
    expect(count(array_filter($flat, static fn($r) => $r === $item)) === 1, 'preserve each complete item once');
}
expect(AdminNavigation::category($items[6]) === 'team', 'crew belongs with staff');
expect(AdminNavigation::category($items[7]) === 'operations', 'daily cash book stays with operations');
expect(AdminNavigation::category($items[8]) === 'finance', 'permissions belong in system settings');
expect(AdminNavigation::groups([$items[1]]) === ['operations'=>[$items[1]]], 'do not introduce unauthorized links or empty groups');
expect(AdminNavigation::groups([]) === [], 'empty authorized menu stays empty');
foreach (['driver'=>'staff','staff'=>'staff','agent'=>'agent','partner'=>'partner'] as $role=>$family) {
    expect(AdminNavigation::teamFamily($role) === $family, 'staff and partner separation');
}
echo "Admin categories: link preservation, ordering, permission-filtered input and team families passed\n";
