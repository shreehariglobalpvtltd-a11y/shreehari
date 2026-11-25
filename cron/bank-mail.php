<?php
/**
 * cron/bank-mail.php — read new bank credit alerts from the bank Gmail boxes (25 Nov 2026, mp1 "pay";
 * mp2 "bank Gmail": every box the owner added on Payment Management).
 *
 * Recommended: every 2 minutes (crontab line in deploy/VPS-SETUP.md, section 7).
 *
 * Exits at once when there is nothing to read (still writes its heartbeat): before the mp2
 * migration, while bankmail_on is off; after it, while no bank Gmail box is active and the
 * old single-box settings are not waiting to become account #1. Each active box is read
 * read-only - a "Google login" box through the Gmail API, an app-password box through IMAP -
 * with its own rules (BankMail::poll -> pollAccount), and every mail it looks at goes to its
 * "📬 पछिल्लो mail" log. Each credit goes to PayCredits: while pay_bankmail_autoconfirm_on is
 * off (the default) a credit that passes every money rule is only a SUGGESTION ('matched', the
 * booking stays pending, a bell: a person checks the bank app and presses Confirm on Payment
 * Management); an alert with no PNR whose amount only ONE booking owes is an amount-only
 * suggestion (never auto); anything else is 'unmatched' + an admin bell. See includes/bankmail.php.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/bankmail.php';

if (!BankMail::anythingToRun()) {
    cron_done(['skipped' => BankMailAccounts::ready() ? 'no active bank Gmail account' : 'bankmail_on off']);
    return;
}

$r = BankMail::poll(50);
cron_done([
    'ok'         => $r['ok'],
    'msg'        => $r['msg'],
    'accounts'   => $r['accounts'] ?? 1,
    'seen'       => $r['seen'],
    'credits'    => $r['credits'],
    'confirmed'  => $r['confirmed'],
    'suggested'  => $r['suggested'] ?? 0,
    'unmatched'  => $r['unmatched'],
    'duplicates' => $r['duplicates'],
    'last_uid'   => $r['last_uid'] ?? 0,
], (bool) $r['ok']);
