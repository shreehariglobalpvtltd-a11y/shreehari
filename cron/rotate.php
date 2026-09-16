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

// Sign-in history past its retention window (Settings -> login_history_days,
// default 180). Kept far longer than the other rows here on purpose: this is
// the security record, and it is the one thing you want still to exist when
// somebody finally asks how an account was accessed.
$loginsPurged = LoginLog::prune();

// Automation journal + exactly-once claims (Settings -> automation_log_days,
// default 30). Most claim keys are date/schedule-scoped and safe to prune, but
// 'pending-alert:<booking_id>' is booking-scoped and its "one reminder ever"
// only holds while the row survives: a proof-uploaded booking left unreviewed
// has expires_at NULL, so it never auto-expires and can outlive the window
// while still matching the pending sweep. Keep such a claim until its booking
// is no longer 'pending' — after that the alert can never fire again, so the
// row is safe to drop at any age. Table may not be migrated yet — degrade
// quietly like the rest of this file's best-effort work.
$autoPurged = 0;
try {
    $autoPurged = Database::query(
        "DELETE al FROM automation_log al
          WHERE al.created_at < DATE_SUB(NOW(), INTERVAL " . max(7, Settings::getInt('automation_log_days', 30)) . " DAY)
            AND (
                 al.dedupe_key IS NULL
                 OR al.dedupe_key NOT LIKE 'pending-alert:%'
                 OR NOT EXISTS (
                       SELECT 1 FROM bookings b
                        WHERE b.id = CAST(SUBSTRING(al.dedupe_key, 15) AS UNSIGNED)
                          AND b.status = 'pending'
                    )
            )"
    )->rowCount();
} catch (Throwable $e) {
    // automation_log missing — nothing to prune.
}

// Product beacon rows (Settings -> events_retention_days, default 90). This
// is the promise api/events.php makes in its own header, kept here where the
// pruning actually happens: the beacon collects redacted behaviour, and it
// forgets it on a schedule. A retention window that only exists in a comment
// is not a retention window.
$eventsPurged = 0;
try {
    $eventsPurged = Database::query(
        'DELETE FROM app_events WHERE created_at < DATE_SUB(NOW(), INTERVAL '
            . max(7, min(730, Settings::getInt('events_retention_days', 90))) . ' DAY)'
    )->rowCount();
} catch (Throwable $e) {
    // app_events missing — nothing to prune.
}

// Health incidents that were resolved a while ago. The open ones are never
// touched: an incident stays until the fault stops, however long that takes.
$incidentsPurged = 0;
try {
    $incidentsPurged = Database::query(
        "DELETE FROM health_incidents
          WHERE status = 'resolved'
            AND resolved_at < DATE_SUB(NOW(), INTERVAL "
            . max(7, min(365, Settings::getInt('incident_retention_days', 60))) . ' DAY)'
    )->rowCount();
} catch (Throwable $e) {
    // health_incidents missing — nothing to prune.
}

cron_done([
    'logFilesRemoved'  => $logsRemoved,
    'rateLimitsPurged' => $rlPurged,
    'otpPurged'        => $otpPurged,
    'loginHistoryPurged' => $loginsPurged,
    'automationPurged' => $autoPurged,
    'eventsPurged'     => $eventsPurged,
    'incidentsPurged'  => $incidentsPurged,
]);
