<?php
/**
 * whatsapp/test-aisensy.php — AiSensy send test (3 Oct 2026).
 *
 *   /whatsapp/test-aisensy.php?secret=shg2026test            -> ticket template
 *   /whatsapp/test-aisensy.php?secret=shg2026test&tpl=reminder
 *   /whatsapp/test-aisensy.php?secret=shg2026test&tpl=cancel
 *   /whatsapp/test-aisensy.php?secret=shg2026test&tpl=none    -> project status only
 *
 * Sends ONE test message to the fixed test number (+919157001507, or the
 * settings value aisensy_test_to) and prints the API response as JSON.
 * The secret can be changed with the settings key aisensy_test_secret.
 * Wrong / missing secret answers 404, like any unknown URL.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/aisensy.php';

$expected = AiSensyAPI::config('AISENSY_TEST_SECRET', 'aisensy_test_secret', 'shg2026test');
$given    = (string) ($_GET['secret'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$to  = AiSensyAPI::config('AISENSY_TEST_TO', 'aisensy_test_to', '919157001507');
$tpl = (string) ($_GET['tpl'] ?? 'ticket');

$result = null;
switch ($tpl) {
    case 'none':
        break;
    case 'reminder':
        $result = AiSensyAPI::sendBookingReminder($to, 'Test Passenger', '13:00');
        break;
    case 'cancel':
        $result = AiSensyAPI::sendCancellationNotice($to, 'Test Passenger', 1800);
        break;
    default:
        $tpl = 'ticket';
        $result = AiSensyAPI::sendTicketConfirmation($to, 'Test Passenger', 'Surat', 'Rupaidiha',
            date('Y-m-d', strtotime('+1 day')), 'LA1', 'SHG-TEST-' . date('His'));
}

$project = AiSensyAPI::projectInfo();
$p = is_array($project['response'] ?? null) ? $project['response'] : [];

echo json_encode([
    'configured' => AiSensyAPI::enabled(),
    'mode'       => AiSensyAPI::mode(),
    'project'    => [
        'http'         => $project['http'] ?? 0,
        'id'           => $p['id'] ?? AiSensyAPI::projectId(),
        'status'       => $p['status'] ?? null,
        'sandbox'      => $p['sandbox'] ?? null,
        'wa_number'    => $p['wa_number'] ?? null,
        'plan'         => $p['active_plan'] ?? null,
        'tier'         => $p['wa_messaging_tier'] ?? null,
        'error'        => $project['error'] ?? '',
    ],
    'test'       => $result === null ? null : [
        'template' => $tpl,
        'to'       => AiSensyAPI::normalizePhone($to),
        'result'   => $result,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
