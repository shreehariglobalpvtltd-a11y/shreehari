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
 * @param array<string, mixed> $result
 */
function cron_done(array $result = []): void
{
    $ms = (int) round((microtime(true) - $GLOBALS['__cron_start']) * 1000);
    $result['ms'] = $ms;
    Logger::info('cron ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'job')), $result, 'cron');
    echo date('c') . ' ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
}
