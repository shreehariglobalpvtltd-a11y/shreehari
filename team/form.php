<?php
/**
 * /team/form.php — "My form" in the team portal (23 Oct 2026, owner: "photo
 * rakhera nai generate garera dine agent staff haru lai, tyo online rakhne").
 *
 * The signed-in person's OWN application as the A4 form + agreement (photo,
 * every detail, signature, verification), to view, print or save as PDF any
 * time - the same page the office prints (admin/application-print.php), in
 * its applicant mode. Only this person's own record: the staff card or the
 * approved agent / partner application TeamPortal::current() found for them.
 *
 *   /team/form.php            staff card first, else the agent / partner one
 *   ?as=staff | ?as=agent     when the person has both
 *   &bw=1 &lang=… &auto=pdf   passed on to the A4 page
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/teamui.php';

$me = TeamPortal::current();
if ($me === null) {
    TeamUi::go('/team/');
}
$want  = (string) ($_GET['as'] ?? '');
$staff = $me['staff'] !== null && in_array((string) $me['staff']['status'], ['approved', 'suspended', 'exited'], true) ? $me['staff'] : null;
$agent = $me['agent'];
if ($staff !== null && ($want !== 'agent' || $agent === null)) {
    $own = ['type' => 'staff', 'id' => (int) $staff['id']];
} elseif ($agent !== null) {
    $own = ['type' => 'agent', 'id' => (int) $agent['id']];
} else {
    TeamUi::setFlash('bad', 'तपाईंको फारम भेटिएन — कार्यालयमा सोध्नुहोस् · No form found for your account.');
    TeamUi::go('/team/');
}
define('SHG_A4_PUBLIC', true);
define('SHG_A4_TEAM', $own);
require dirname(__DIR__) . '/admin/application-print.php';
