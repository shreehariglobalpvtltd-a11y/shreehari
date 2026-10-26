<?php
/**
 * cron/_cron.php — shared bootstrap for scheduled jobs.
 *
 * Runs from the command line (cPanel cron) with no guard, or over the
 * web only when ?token= matches CRON_TOKEN. This lets Hostinger accounts
 * that can only schedule a URL still run the jobs securely.
 *
 * Usage in a job:
 *     require __DIR__ . '/_cron.php';
 *     ... do work ...
 *     cron_done(['expired' => $n]);
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? '');
    if (!defined('CRON_TOKEN') || $token === '' || !hash_equals(CRON_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$GLOBALS['__cron_start'] = microtime(true);

/**
 * Print a one-line result and finish.
 *
 * NOTE: this prints, it does NOT exit — a job that calls cron_done() and
 * then keeps working carries on. Several already rely on that.
 *
 * Since 8 Sep 2026 it also writes the job's heartbeat. A cron job that
 * stops running is otherwise completely invisible: the crontab still lists
 * it, the code is still on disk, and nothing anywhere says "the nightly
 * brain has not run since Tuesday". cron/health-heartbeat.php turns that
 * silence into an incident, and this line is what feeds it.
 *
 * @param array<string, mixed> $result
 * @param bool $ok false when the job finished but did not do its job — the
 *                 heartbeat then counts a failure streak while still
 *                 recording that the job is at least alive.
 */
function cron_done(array $result = [], bool $ok = true): void
{
    $ms = (int) round((microtime(true) - $GLOBALS['__cron_start']) * 1000);
    $result['ms'] = $ms;
    $job = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'job'));

    Logger::info('cron ' . $job, $result, 'cron');

    // Best-effort, and deliberately after the log: a heartbeat table that is
    // missing (migration not yet applied on this server) must never turn a
    // successful job into a failed one.
    try {
        require_once INCLUDE_PATH . '/health.php';
        Health::beat($job, $ms, $result, $ok);
    } catch (Throwable $e) {
        // The file log above already recorded the run.
    }

    echo date('c') . ' ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
}
