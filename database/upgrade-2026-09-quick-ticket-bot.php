<?php
/**
 * upgrade-2026-09-quick-ticket-bot.php — Quick Ticket for everyone + the
 * AI Ticket Bot (owner ask, 6 Sep 2026).
 *
 * Seeds the settings rows the feature reads, so the office can change them
 * from Admin → Settings (Settings::setMany() only updates rows that exist):
 *
 *   quick_ticket_customer_on   bool   1 = passengers may self-serve a Quick
 *                                     Ticket from the app (default); 0 = the
 *                                     banner falls back to a WhatsApp request
 *                                     and only the desk sells             (public)
 *   quick_ticket_default_boarding string the desk's default pickup town ('' = auto)
 *   quick_ticket_default_pay   string cash | upi | esewa | bank (desk default)
 *   ai_bot_on                  bool   1 = the AI Ticket Bot suggests on the desk
 *   ai_bot_window_days         int    how far back the bot learns (default 90)
 *   ai_bot_cache_min           int    minutes the learned patterns are cached (30)
 *
 * Idempotent: existing rows are kept exactly as they are.
 *
 *   php -c .claude/php-dev.ini database/upgrade-2026-09-quick-ticket-bot.php   (local)
 *   php database/upgrade-2026-09-quick-ticket-bot.php                            (VPS)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$rows = [
    // key, default, type, group, public, label
    ['quick_ticket_customer_on',    '1',    'bool',   'booking', true,  'Quick Ticket self-service for passengers ON (1) / OFF (0)'],
    ['quick_ticket_default_boarding', '',   'string', 'booking', false, 'Quick Ticket desk default pickup town (blank = automatic)'],
    // One-click Quick Ticket for passengers (6 Sep 2026): the pickup their
    // card comes up with. The company's own yard; a town word is enough.
    ['quick_ticket_customer_boarding', 'S Hari Parking', 'string', 'booking', false, 'Quick Ticket passenger default pickup (blank = first pickup ahead)'],
    ['quick_ticket_default_pay',    'cash', 'string', 'booking', false, 'Quick Ticket desk default payment received (cash / upi / esewa / bank)'],
    ['ai_bot_on',                   '1',    'bool',   'booking', false, 'AI Ticket Bot suggestions on the desk ON (1) / OFF (0)'],
    ['ai_bot_window_days',          '90',   'int',    'booking', false, 'AI Ticket Bot: learn from verified tickets of the last N days'],
    ['ai_bot_cache_min',            '30',   'int',    'booking', false, 'AI Ticket Bot: minutes the learned patterns are cached'],
    // QuickBot hardening (7 Sep 2026): both were read with built-in defaults
    // before; seeded so the office can tune them from Settings.
    ['quick_ticket_undo_min',       '10',   'int',    'booking', false, 'QuickBot: minutes a passenger may undo a one-tap ticket free'],
    ['quick_ticket_max_open',       '3',    'int',    'booking', false, 'QuickBot: unpaid pay-on-boarding tickets one mobile may hold at once (0 = no cap)'],
];

foreach ($rows as [$key, $default, $type, $group, $public, $label]) {
    if (Settings::get($key, null) === null
        && !Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $key])) {
        Settings::set($key, $default, $type, $group, $public);
        echo "seeded {$key} = " . var_export($default, true) . "\n";
    } else {
        echo "{$key} already set\n";
    }
    Database::query(
        'UPDATE settings SET label = :l WHERE skey = :k AND (label IS NULL OR label = \'\')',
        ['l' => $label, 'k' => $key]
    );
}
Settings::flush();
exit(0);
