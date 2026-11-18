<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/watemplates.php';
require_once INCLUDE_PATH . '/wasend.php';
require_once INCLUDE_PATH . '/watplmeta.php';

$templateReady = Settings::getString('wa_cloud_tpl_message', '') === 'shg_office_alert';
/* 19 Nov 2026 (WA-4): an agent's statement has its own template now -
   shg_agent_statement (includes/watplmeta.php: DOCUMENT header = the PDF,
   period / tickets / passengers / collected / commission / both wallet balances). Once
   APPROVED the agents' statements go even while the office template waits. */
$stmtReady = WaTplMeta::ready('shg_agent_statement');
if (!$templateReady && !$stmtReady && !in_array('--check', $argv, true)) {
    cron_done(['skipped' => 'Meta office / statement template approval pending']);
    exit;
}

$from = date('Y-m-01', strtotime('first day of last month'));
$to = date('Y-m-t', strtotime($from));
$recipients = [];
$agentPhones = [];
$office = Settings::getString('admin_whatsapp', '') ?: Settings::officeWhatsApp();
$officeNumber = WaTemplates::intl($office, null);

if ($templateReady && Settings::getBool('whatsapp_notify_admin', true) && $officeNumber !== '') {
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
    cron_done(['template_ready' => $templateReady, 'statement_template_ready' => $stmtReady,
               'statement_template' => $stmtReady ? 'shg_agent_statement' : ($templateReady ? 'shg_office_alert' : ''),
               'recipients' => count($recipients)]);
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
        $tplName = 'shg_office_alert';
        $tplVars = ['1' => $message['label'], '2' => mb_substr($details, 0, 600)];
        if ($purpose === 'agent_statement' && $stmtReady) {
            // Real figures only: period, tickets, passengers, collected (confirmed sales), commission, both wallet balances.
            $st  = WaTemplates::agentPeriodStats($id, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00', 1);
            $bal = AgentWallet::balances($id);
            $spec = WaTplMeta::forPurpose('agent_statement', [
                'period'     => formatDate($from, 'j M Y') . ' to ' . formatDate($to, 'j M Y'),
                'tickets'    => (string) $st['confirmed'],
                'passengers' => (string) $st['pax'],
                'collected'  => inr($st['revenue']),
                'commission' => inr($st['commission']),
                // Both wallets, as the WhatsApp statement shows them (bal_commission + bal_cash): never the commission alone as "the balance".
                'balance'    => 'commission due to you ' . inr((float) ($bal['commission'] ?? 0))
                              . ' · company cash you hold ' . inr((float) ($bal['cash'] ?? 0)),
                'header'     => (string) $message['mediaUrl'],
            ]);
            if ($spec !== null) {
                $tplName = $spec['name'];
                $tplVars = [];
                foreach ($spec['vars'] as $i => $v) {
                    $tplVars[(string) ($i + 1)] = $v;
                }
                $tplVars['7'] = (string) $message['mediaUrl'];      // WaSend: '7' = the header (a PDF -> DOCUMENT)
            }
        }
        if ($tplName === 'shg_office_alert' && !$templateReady) {
            $result['failed']++;
            continue;
        }
        $send = WaSend::fallback($number, $message['text'], $message['mediaUrl'], $purpose, [
            'admin_id' => $id,
            'source' => 'monthly_report',
            'dedupe' => $dedupe,
            'template_name' => $tplName,
            'template_lang' => 'en',
            'tpl_vars' => $tplVars,
        ]);
        $ok = in_array($send['state'], ['queued', 'waiting', 'sent'], true);
        $result[$ok ? 'queued_or_sent' : 'failed']++;
    } catch (Throwable $e) {
        $result['failed']++;
        Logger::exception($e, 'whatsapp');
    }
}
cron_done($result, $result['failed'] === 0);
