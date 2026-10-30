<?php
/**
 * me/form.php — my own filled application form (A4, 2 pages) with Print /
 * PDF / PNG. ?auto=pdf starts the download on open.
 *
 * Only for the signed-in member (agent code + WhatsApp OTP, passkey, or the
 * office sign-in - the same session as /me/ and /me/print.php). It names the
 * row from the session, never from the URL, and shows the member's own ID,
 * PAN and bank numbers in full. Renderer: includes/agentpro-form.php.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro-form.php';

Security::sendHeaders();
$admin = Auth::admin();
if ($admin === null) {
    Response::redirect('me/');
}
if (!AgentPro::available()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Agent Pro is not installed yet.');
}
$id = (int) $admin['id'];
$m = AgentPro::member($id);
if ($m === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No such member.');
}
$app = ApForm::applicationFor($id);
$auto = in_array($_GET['auto'] ?? '', ['pdf', 'print'], true) ? (string) $_GET['auto'] : '';
Logger::audit('agentpro.form_self', 'admin', (string) $id, null, ['auto' => $auto], 'Member opened their own form');

ApForm::noStore();
echo ApForm::render($app, $m, [
    'full'      => true,
    'mode'      => 'member',
    'auto'      => $auto,
    'back'      => '/me/',
    'backLabel' => '← मेरो ID · My ID',
]);
