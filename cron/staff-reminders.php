<?php
/**
 * cron/staff-reminders.php — staff applications that are waiting (22 Oct 2026).
 * Every 30 minutes:
 *
 *     *\/30 * * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/staff-reminders.php >/dev/null 2>&1
 *
 *  1. Documents nobody read yet: the form never waits for the reader, so an
 *     application whose ID / licence photo was not checked on the page is
 *     read here (StaffApps::recheck, at most 3 a run — Gemini takes ~7 s
 *     each). Only while 'staff_doc_verify' is on.
 *  2. The applicant: pending or under review for more than 48 hours and not
 *     nudged yet -> ONE WhatsApp "we are still looking at it" (reminded_at
 *     makes it once per application; at most 10 a run).
 *  3. The office: anything pending / under review for more than 7 days ->
 *     one bell in Admin -> Notifications and one WhatsApp note a day.
 *
 * Read-mostly against the live DB: the only writes are reminded_at and the
 * document-check columns of the rows it touched.
 */

declare(strict_types=1);

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/staffapplications.php';

if (!StaffApps::available()) {
    cron_done(['skipped' => 'staff_applications table missing']);
    exit;
}

$checked = 0;
$nudged  = 0;
$stale   = 0;

// 1. documents not read yet
if (StaffApps::docVerifyOn()) {
    $ids = Database::fetchAll(
        "SELECT id FROM staff_applications
          WHERE doc_verify_status = 'unchecked' AND status IN ('pending','under_review')
            AND id_front_path IS NOT NULL AND created_at < NOW() - INTERVAL 10 MINUTE
          ORDER BY id LIMIT 3"
    );
    foreach ($ids as $r) {
        try {
            StaffApps::recheck((int) $r['id']);
            $checked++;
        } catch (Throwable $e) {
            Logger::warning('staff-reminders: document check failed: ' . $e->getMessage(), ['id' => (int) $r['id']], 'cron');
        }
    }
}

// 2. the applicant, once, after 48 hours
$wait = Database::fetchAll(
    "SELECT * FROM staff_applications
      WHERE status IN ('pending','under_review') AND reminded_at IS NULL
        AND created_at < NOW() - INTERVAL 48 HOUR
      ORDER BY created_at LIMIT 10"
);
foreach ($wait as $r) {
    // Marked first: a WhatsApp that fails is not retried every 30 minutes.
    Database::update('staff_applications', ['reminded_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $r['id']]);
    if (StaffApps::sendWa($r, StaffApps::waText('reminder', $r), 'staff_app_reminder') === true) {
        $nudged++;
    }
}

// 3. the office, once a day, when someone has waited a week
$stale = (int) Database::scalar(
    "SELECT COUNT(*) FROM staff_applications WHERE status IN ('pending','under_review') AND created_at < NOW() - INTERVAL 7 DAY", [], 0);
if ($stale > 0) {
    require_once INCLUDE_PATH . '/adminalerts.php';
    $title = $stale . ' staff application' . ($stale === 1 ? '' : 's') . ' waiting more than 7 days';
    $made = AdminAlerts::push('staff_stale', $title, 'Drivers / conductors / office staff who applied online are still waiting for a decision.',
        '/admin/staff-applications.php?status=pending', '🧑‍✈️', 'staff_stale:' . date('Ymd'));
    if ($made > 0) {
        Notify::adminNote('Staff applications waiting', ['Waiting > 7 days' => (string) $stale, 'Review' => appUrl('admin/staff-applications.php')]);
    }
}

cron_done(['checked' => $checked, 'nudged' => $nudged, 'stale' => $stale]);
