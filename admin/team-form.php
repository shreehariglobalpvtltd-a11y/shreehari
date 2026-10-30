<?php
/**
 * admin/team-form.php — one Agent Pro application as the filled A4 form
 * (2 pages: letterhead, photo, every field, documents, thumb, verification,
 * approval, declaration, office-use box), with Print / PDF / PNG.
 *
 *   ?app=<ap_applications.id>     that application (any status - the office
 *                                 can print a draft it is finishing too)
 *   ?admin=<admins.id>            the application that made this member; a
 *                                 member with none gets the form from their
 *                                 member record
 *   &auto=pdf | &auto=print       start the download / print on open
 *
 * Who: a super-admin, a manager, or anyone with staff.manage. The ID number,
 * PAN, bank account and the document / thumbprint pictures print in full
 * for a super-admin or manager only (masked for the rest). Every view is
 * written to the audit log. Renderer: includes/agentpro-form.php (ApForm).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro-form.php';
$admin = admin_boot();

$isBoss = Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager';
if (!($isBoss || Auth::can('staff.manage'))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('You do not have permission to view application forms.');
}
if (!AgentPro::available()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Agent Pro is not installed yet.');
}

$appId   = max(0, (int) ($_GET['app'] ?? 0));
$adminId = max(0, (int) ($_GET['admin'] ?? 0));
$app = $appId > 0 ? AgentPro::application($appId) : ($adminId > 0 ? ApForm::applicationFor($adminId) : null);
$memberId = $app !== null ? (int) ($app['admin_id'] ?? 0) : $adminId;
$m = $memberId > 0 ? AgentPro::member($memberId, false) : null;
if ($app === null && $m === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No such application or member.');
}

$auto = in_array($_GET['auto'] ?? '', ['pdf', 'print'], true) ? (string) $_GET['auto'] : '';
Logger::audit(
    'agentpro.form_viewed',
    $app !== null ? 'application' : 'admin',
    $app !== null ? (string) $app['ref'] : (string) $memberId,
    null,
    ['full' => $isBoss, 'auto' => $auto, 'member' => $memberId],
    'Filled application form opened'
);

$back = $memberId > 0 && ($app === null || (string) $app['status'] === 'approved')
    ? '/admin/team-member.php?id=' . $memberId
    : '/admin/team.php?tab=approvals';

Security::sendHeaders();
ApForm::noStore();
echo ApForm::render($app, $m, [
    'full'      => $isBoss,
    'mode'      => 'admin',
    'auto'      => $auto,
    'back'      => $back,
    'backLabel' => '← टिम · Team',
]);
