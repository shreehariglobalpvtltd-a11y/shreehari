<?php
/**
 * admin/notify-test.php — one-click "Send test WhatsApp" for the Settings
 * page. Sends a real message through the currently-configured driver and
 * returns a structured JSON result (with the provider's real error, if any)
 * so the owner can confirm the Twilio setup works without guesswork.
 *
 * POST only, CSRF-checked, and restricted to roles that can edit settings
 * (a test send hits the paid provider). Never leaks internals — errors come
 * back as friendly, actionable text from Notify::whatsappTest().
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$canEdit = Auth::can('routes.edit') || (($admin['role'] ?? '') === 'superadmin');
if (!$canEdit) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'detail' => 'Your role can view settings but not send test messages.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Security::verifyCsrf()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'detail' => 'Session expired — reload the Settings page and try again.']);
    exit;
}

/* fx1 (26 Nov 2026): channel=alert - one sample alert through the alert bus (includes/alertbus.php),
   for the buttons on admin/alert-settings.php. Super-admin only. event = a key of AlertBus::EVENTS,
   via = inapp (the open panel) | push (this person's subscribed phones) | whatsapp (office number).
   The sample is visible only to the person who pressed the button - it never rings the office.
   The three older channels below are untouched. */
if (strtolower((string) ($_POST['channel'] ?? '')) === 'alert') {
    if (!Auth::isSuperadmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'detail' => 'नमुना सूचना सुपर-एडमिनले मात्र पठाउन सक्छ · Only the super-admin can send a test alert.']);
        exit;
    }
    try {
        require_once INCLUDE_PATH . '/alertbus.php';
        $ev  = Security::clean((string) ($_POST['event'] ?? ''), 40);
        $via = strtolower(Security::clean((string) ($_POST['via'] ?? 'inapp'), 12));
        if (!isset(AlertBus::EVENTS[$ev]) || !in_array($via, ['inapp', 'push', 'whatsapp'], true)) {
            $res = ['ok' => false, 'detail' => 'थाहा नभएको सूचना वा माध्यम · Unknown alert or channel.'];
        } else {
            $res = AlertBus::test($ev, $via, ['id' => (int) ($admin['id'] ?? 0), 'role' => (string) ($admin['role'] ?? ''),
                                              'full_name' => (string) ($admin['full_name'] ?? '')]);
            Logger::audit('alerts.test', 'settings', 'alerts', null, ['event' => $ev, 'via' => $via, 'ok' => !empty($res['ok'])]);
        }
    } catch (Throwable $e) {
        Logger::exception($e);
        $res = ['ok' => false, 'detail' => 'नमुना चलाउन सकिएन (सर्भर त्रुटि) · The test could not run (server error). Check logs/.'];
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$phone   = Security::clean($_POST['phone'] ?? '', 24);
$ct      = strtolower((string) ($_POST['country'] ?? ''));
$hint    = $ct === 'np' ? 'NP' : ($ct === 'in' ? 'IN' : null);
$channel = strtolower((string) ($_POST['channel'] ?? 'whatsapp'));

if (!class_exists('Notify')) {
    require_once INCLUDE_PATH . '/notify.php';
}

try {
    if ($channel === 'email') {
        $res = Notify::emailTest(Security::clean($_POST['email'] ?? '', 191));
    } elseif ($channel === 'sms') {
        $res = Notify::smsTest($phone, $hint);
    } else {
        $res = Notify::whatsappTest($phone, $hint);
    }
} catch (Throwable $e) {
    Logger::exception($e);
    $res = ['ok' => false, 'detail' => 'The test could not run (server error). Check logs/ (channel: ' . Security::e($channel) . ').'];
}

echo json_encode($res);
