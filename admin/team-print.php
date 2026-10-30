<?php
/**
 * admin/team-print.php?ids[]=1&ids[]=2&what=all|id|vc — A4 print sheets of
 * ID and/or visiting cards for the chosen members, true size, with cut
 * guides and the backs mirrored for long-edge duplex printing.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
admin_boot();
if (!(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
    http_response_code(403);
    exit('You do not have permission to print cards.');
}
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])))));
$members = [];
foreach (array_slice($ids, 0, 90) as $id) {
    $m = AgentPro::member($id);
    if ($m !== null) {
        $members[] = $m;
    }
}
if ($members === []) {
    http_response_code(400);
    exit('Choose at least one member on Team → Print cards.');
}
$what = in_array($_GET['what'] ?? '', ['id', 'vc'], true) ? (string) $_GET['what'] : 'all';
Logger::audit('agentpro.cards_printed', 'admin', implode(',', $ids), null, ['what' => $what, 'count' => count($members)], 'Card sheets printed');
header('Content-Type: text/html; charset=utf-8');
echo ApCard::printHtml($members, $what, Settings::getString('company_name', APP_NAME) . ' cards');
