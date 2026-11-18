<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/watemplates.php';
require_once INCLUDE_PATH . '/wasend.php';

$templateReady = Settings::getString('wa_cloud_tpl_message', '') === 'shg_office_alert';
if (!$templateReady && !in_array('--check', $argv, true)) {
    cron_done(['skipped' => 'Meta office template approval pending']);
    exit;
}

$from = date('Y-m-01', strtotime('first day of last month'));
$to = date('Y-m-t', strtotime($from));
$recipients = [];
$agentPhones = [];
$office = Settings::getString('admin_whatsapp', '') ?: Settings::officeWhatsApp();
$officeNumber = WaTemplates::intl($office, null);

if (Settings::getBool('whatsapp_notify_admin', true) && $officeNumber !== '') {
    $recipients[] = ['admin_monthly_summary', 0, $officeNumber];
}

if (Settings::getBool('agent_notify_enabled', true)) {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1") as $row) {
        $id = (int) $row['id'];
        $recipient = WaTemplates::agentRecipient($id);
        $number = WaTemplates::intl($recipient['digits'], $recipient['hint']);
        if ($number !== '' && $number !== $officeNumber) {
            $agentPhones[$number][] = $id;
        }
    }
    foreach ($agentPhones as $number => $ids) {
        // Financial statements require a number belonging to exactly one agent.
        if (count($ids) === 1) {
            $recipients[] = ['agent_statement', $ids[0], (string) $number];
        }
    }
}

if (in_array('--check', $argv, true)) {
    cron_done(['template_ready' => $templateReady, 'recipients' => count($recipients)]);
    exit;
}

$result = ['queued_or_sent' => 0, 'already_queued' => 0, 'failed' => 0];
foreach ($recipients as [$purpose, $id, $number]) {
    try {
        $dedupe = "monthly-statement|$from|$purpose|$id";
        if (Database::exists('SELECT 1 FROM wa_outbox WHERE dedupe = :key', ['key' => sha1($dedupe)])) {
            $result['already_queued']++;
            continue;
        }
        $message = WaTemplates::compose($purpose, ['agent_id' => $id, 'from' => $from, 'to' => $to], []);
        if ($message['intl'] !== $number || ($id > 0 && empty($message['mediaUrl']))) {
            $result['failed']++;
            continue;
        }
        $details = trim((string) preg_replace('/\s*\R\s*/u', ' · ', $message['text']));
        if ($id > 0) {
            $details = date('F Y', strtotime($from)) . '. Statement PDF: ' . $message['mediaUrl'] . ' · ' . $details;
        }
        $send = WaSend::fallback($number, $message['text'], $message['mediaUrl'], $purpose, [
            'admin_id' => $id,
            'source' => 'monthly_report',
            'dedupe' => $dedupe,
            'template_name' => 'shg_office_alert',
            'template_lang' => 'en',
            'tpl_vars' => ['1' => $message['label'], '2' => mb_substr($details, 0, 600)],
        ]);
        $ok = in_array($send['state'], ['queued', 'waiting', 'sent'], true);
        $result[$ok ? 'queued_or_sent' : 'failed']++;
    } catch (Throwable $e) {
        $result['failed']++;
        Logger::exception($e, 'whatsapp');
    }
}
cron_done($result, $result['failed'] === 0);
