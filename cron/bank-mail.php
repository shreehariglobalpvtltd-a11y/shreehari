<?php
/**
 * cron/bank-mail.php — read new Global IME Bank credit alerts from the Gmail box (25 Nov 2026, mp1 "pay").
 *
 * Recommended: every 2 minutes (crontab line in deploy/VPS-SETUP.md, section 7).
 *
 * Exits at once while bankmail_on is off (still writes its heartbeat). The mailbox is opened
 * read-only; each credit goes to PayCredits (auto-confirm only when every money rule holds,
 * otherwise 'unmatched' + an admin bell). See includes/bankmail.php.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/bankmail.php';

if (!BankMail::on()) {
    cron_done(['skipped' => 'bankmail_on off']);
    return;
}

$r = BankMail::poll(50);
cron_done([
    'ok'         => $r['ok'],
    'msg'        => $r['msg'],
    'seen'       => $r['seen'],
    'credits'    => $r['credits'],
    'confirmed'  => $r['confirmed'],
    'unmatched'  => $r['unmatched'],
    'duplicates' => $r['duplicates'],
    'last_uid'   => $r['last_uid'],
], (bool) $r['ok']);
