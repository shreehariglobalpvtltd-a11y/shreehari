<?php
/**
 * cron/agreement-expiry.php — agent / partner agreements that run out (23 Oct 2026).
 * Once a day is enough (09:10 IST, when people read WhatsApp):
 *
 *     10 9 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/agreement-expiry.php >/dev/null 2>&1
 *
 *  1. Ended (agreement_end before today, still 'signed') -> agreement_status
 *     'expired' + one WhatsApp with the renewal link (/team/agreement.php).
 *     The login is switched off too ONLY when the setting
 *     'agreement_expiry_lock' is on (default off).
 *  2. Ending in 30 / 15 / 7 days -> one WhatsApp per step (TeamExpiry).
 *  3. The office: one bell a day while anyone's agreement ends within 30 days.
 *
 *  `php cron/agreement-expiry.php --dry` counts only: no write, no WhatsApp.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/teamexpiry.php';

if (!AgentApplications::available()) {
    cron_done(['skipped' => 'agent_applications table missing']);
    exit;
}

$dry = in_array('--dry', $argv ?? [], true);
$res = TeamExpiry::run($dry);

if (!$dry && $res['due30'] > 0) {
    require_once INCLUDE_PATH . '/adminalerts.php';
    $title = $res['due30'] . ' agreement' . ($res['due30'] === 1 ? '' : 's') . ' end within 30 days';
    AdminAlerts::push('agreement_due', $title, 'Agents / partners were reminded on WhatsApp. Renew = set the agreement back to Signed after they sign again.',
        '/admin/agreements.php?tab=agents', '🔁', 'agreement_due:' . date('Ymd'));
}

cron_done($res + ['dry' => $dry]);
