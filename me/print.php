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
// 30 Oct 2026: ?only=form = my filled A4 application form (me/form.php)
if (($_GET['only'] ?? '') === 'form') {
    Response::redirect('me/form.php');
}
$only = in_array($_GET['only'] ?? '', ['id', 'vc'], true) ? (string) $_GET['only'] : 'all';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
$html = ApCard::printHtml([$m], $only, $m['name'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : ''));
// the toolbar also offers the filled form as a PDF (left out of the paper: .bar is hidden in print)
$formBtn = '<a href="/me/form.php?auto=pdf" target="_blank" rel="noopener" style="font:inherit;font-weight:700;padding:10px 18px;min-height:44px;display:inline-flex;align-items:center;border-radius:10px;background:#fff;color:#13224F;text-decoration:none">📄 Form PDF</a>';
echo str_replace('<button onclick="window.print()">', $formBtn . '<button onclick="window.print()">', $html);
