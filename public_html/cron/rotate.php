<?php
/**
 * cron/rotate.php — prune old log files and stale rate-limit rows.
 * Recommended: daily.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';

$logsRemoved = Logger::rotate(Settings::getInt('log_keep_days', 60));

// Rate-limit counters older than a day are dead weight.
$rlPurged = Database::query(
    'DELETE FROM rate_limits
      WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)
        AND (blocked_until IS NULL OR blocked_until < NOW())'
)->rowCount();

// Verified/used OTP codes and anything expired can go too.
$otpPurged = Database::query(
    'DELETE FROM otp_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
)->rowCount();

cron_done(['logFilesRemoved' => $logsRemoved, 'rateLimitsPurged' => $rlPurged, 'otpPurged' => $otpPurged]);
