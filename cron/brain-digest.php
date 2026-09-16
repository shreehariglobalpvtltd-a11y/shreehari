<?php
/**
 * cron/brain-digest.php — the morning brief (recommended: 06:00).
 *
 * The night shift (cron/brain-nightly.php) has already thought. This job
 * only READS what it wrote and hands it to whoever opens the desk: how many
 * cards are waiting, the top few names with the reason in one line, the
 * requests that came in overnight on WhatsApp, and any alert that needs a
 * decision today.
 *
 * Deliberately a separate job from the thinking:
 *   · the brief must not depend on a long scan finishing at 06:00;
 *   · the office can silence the message (brain_digest_on) and still keep
 *     the queue on the desk;
 *   · a re-run re-sends nothing — the claim is per reported day.
 *
 * The message goes to admin_whatsapp (falling back to company_phone) and to
 * admin_email when set. No passenger is ever messaged by this job.
 *
 *   crontab:  0 6 * * *  curl -s "https://shreehariglobal.in/cron/brain-digest.php?token=CRON_TOKEN"
 *   CLI:      php cron/brain-digest.php [--force] [--dry]
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/ticketbrain.php';
require_once INCLUDE_PATH . '/notify.php';

Settings::flush();

$today = date('Y-m-d');
$argvL = $argv ?? [];
$force = PHP_SAPI === 'cli' ? in_array('--force', $argvL, true) : (string) ($_GET['force'] ?? '') === '1';
$dry   = PHP_SAPI === 'cli' ? in_array('--dry', $argvL, true)   : (string) ($_GET['dry'] ?? '') === '1';

if (!TicketBrain::enabled()) {
    cron_done(['skipped' => 'brain_on is off']);
    exit(0);
}
if (!TicketBrain::ready()) {
    cron_done([
        'skipped' => 'brain tables missing',
        'hint'    => 'Run database/upgrade-2026-09-quickbot-brain.sql, then this cron works.',
    ]);
    exit(0);
}
if (!Settings::getBool('brain_digest_on', true)) {
    cron_done(['skipped' => 'brain_digest_on is off']);
    exit(0);
}

// Toggle checked BEFORE the claim (the tripEventEnabled contract): switching
// the digest on later must not find its day already eaten by a silent run.
if (!$dry && !$force && !EventBus::claim('brain-digest:' . $today, 'cron.brain_digest')) {
    cron_done(['skipped' => 'already sent for ' . $today]);
    exit(0);
}

/* ---- what the desk will find waiting ---------------------------------- */
// enrich:false — the brief needs names and reasons, not a live seat map for
// every card (that is the desk's job when it opens the queue).
$cards  = TicketBrain::queue($today, 5, null, false);
$drafts = TicketBrain::drafts(5);
$alerts = TicketBrain::openAlerts($today, 6);
$acc    = TicketBrain::accuracy(30);
$counts = TicketBrain::pending();

$company = Settings::getString('company_name', APP_NAME);
$lines   = ['🌅 ' . $company . ' — QuickBot morning brief', formatDate($today, 'l, j M Y'), ''];

$lines[] = sprintf(
    '🎫 %d ready card%s · 📥 %d new request%s',
    (int) $counts['cards'],
    (int) $counts['cards'] === 1 ? '' : 's',
    (int) $counts['drafts'],
    (int) $counts['drafts'] === 1 ? '' : 's'
);

if ($cards !== []) {
    $lines[] = '';
    $lines[] = 'Likely to call today:';
    foreach ($cards as $i => $c) {
        $who  = $c['passenger'] !== '' ? $c['passenger'] : $c['phone'];
        $when = $c['travelDate'] !== '' ? formatDate((string) $c['travelDate'], 'j M') : 'date open';
        $lines[] = sprintf(
            '%d. %s (%s) — %s, %d seat%s · %d%% sure',
            $i + 1,
            $who,
            $c['phone'],
            $when,
            (int) $c['seats'],
            (int) $c['seats'] === 1 ? '' : 's',
            (int) round(((int) $c['score']) / 10)
        );
        $why = $c['reason'][0] ?? '';
        if ($why !== '') {
            $lines[] = '   ' . $why;
        }
    }
}

if ($drafts !== []) {
    $lines[] = '';
    $lines[] = 'Came in overnight:';
    foreach ($drafts as $d) {
        $lines[] = sprintf(
            '• %s — "%s"%s',
            $d['passenger'] !== '' ? $d['passenger'] : $d['phone'],
            mb_substr((string) $d['text'], 0, 70),
            $d['flags'] !== [] ? ' ⚠️' : ''
        );
    }
}

if ($alerts !== []) {
    $lines[] = '';
    $lines[] = 'Needs a decision:';
    foreach ($alerts as $a) {
        $mark = (string) $a['severity'] === 'high' ? '🔴' : ((string) $a['severity'] === 'warn' ? '🟠' : '🔵');
        $lines[] = $mark . ' ' . (string) $a['title'];
    }
}

if ((int) $acc['sample'] >= 10) {
    $lines[] = '';
    $lines[] = sprintf('Brain accuracy (30 days): %d%% of %d cards became tickets.', (int) round(((float) $acc['hitRate']) * 100), (int) $acc['sample']);
}

if ($cards === [] && $drafts === [] && $alerts === []) {
    $lines[] = '';
    $lines[] = 'Quiet start — nothing predicted and nothing waiting.';
}

$text = implode("\n", $lines);
$out  = [
    'date'   => $today,
    'cards'  => count($cards),
    'drafts' => count($drafts),
    'alerts' => count($alerts),
];

if ($dry) {
    echo $text, "\n\n";
    $out['dry'] = true;
    cron_done($out);
    exit(0);
}

/* ---- deliver ---------------------------------------------------------- */
$phone = Settings::getString('admin_whatsapp', Settings::officePhone());
if ($phone !== '' && Settings::getBool('whatsapp_notify_admin', true)) {
    try {
        $out['whatsapp'] = Notify::whatsapp($phone, $text) !== false ? 'sent' : 'failed';
    } catch (Throwable $e) {
        Logger::exception($e);
        $out['whatsapp'] = 'error';
    }
} else {
    $out['whatsapp'] = 'no admin number';
}

$email = Settings::getString('admin_email', Settings::getString('company_email', ''));
if ($email !== '') {
    try {
        $html = '<pre style="font:14px/1.6 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap">'
              . e($text) . '</pre>';
        $out['email'] = Notify::email($email, 'QuickBot morning brief · ' . $today, $html, $text) ? 'sent' : 'failed';
    } catch (Throwable $e) {
        Logger::exception($e);
        $out['email'] = 'error';
    }
}

cron_done($out);
