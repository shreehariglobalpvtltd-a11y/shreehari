<?php
/** me/print.php — print my own ID card and visiting card at true size (A4). ?only=id|vc */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro-card.php';

Security::sendHeaders();
$admin = Auth::admin();
if ($admin === null) {
    Response::redirect('me/');
}
$m = AgentPro::member((int) $admin['id']);
if ($m === null) {
    http_response_code(404);
    exit('No such member.');
}
$only = in_array($_GET['only'] ?? '', ['id', 'vc'], true) ? (string) $_GET['only'] : 'all';
header('Content-Type: text/html; charset=utf-8');
echo ApCard::printHtml([$m], $only, $m['name'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : ''));
