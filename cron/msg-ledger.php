<?php
/**
 * cron/msg-ledger.php — the WhatsApp Manager's book, every 2 minutes
 * (5 Oct 2026, see includes/msgledger.php).
 *
 *   crontab (www-data, so the day's log stays writable by the site):
 *   *\/2 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/msg-ledger.php
 *
 * 1. copies new message_logs rows (SMS / WhatsApp / email) into msg_ledger;
 * 2. asks AiSensy what happened to recent sends (delivered / read / failed);
 * 3. reads the AiSensy wallet + account health (every ~5 min);
 * 4. refreshes the template list (every 6 h) and prices final rows.
 *
 * Read-only towards customers: this job never sends a message.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/msgledger.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';

if (!MsgLedger::ready()) {
    cron_done(['skipped' => 'msg_ledger missing or wam_on off']);
    exit;
}

$out = ['backfill' => MsgLedger::backfillAiSensyLog()];
// A backlog (first run: all of message_logs) is copied in batches, 25 s max.
$t0 = microtime(true);
$sync = ['copied' => 0];
do {
    $s = MsgLedger::sync(2000);
    $sync['copied'] += $s['copied'];
    $sync += $s;
} while ($s['copied'] >= 2000 && microtime(true) - $t0 < 25);
$out += $sync;
$out['poll'] = MsgLedger::poll(40);
$p = MsgLedger::snapCredit();
$out['credit'] = $p['credit'] ?? null;
$out['templates'] = count(MsgLedger::refreshTemplates());
$out['priced'] = MsgLedger::price();
cron_done($out);
