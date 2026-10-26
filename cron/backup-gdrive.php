<?php
/**
 * cron/backup-gdrive.php — tonight's backup into the owner's Google Drive (5 Oct 2026).
 *
 * Runs after cron/backup.php (02:15). Does nothing unless Google Drive is
 * connected on admin/backup.php AND "copy to Drive every night" is on
 * (gdrive_auto). includes/gdrive.php has the design.
 *
 *   crontab (www-data):
 *     40 2 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/backup-gdrive.php
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/gdrive.php';

if (!GDrive::connected() || !Settings::getBool('gdrive_auto', false)) {
    cron_done(['skipped' => GDrive::connected() ? 'gdrive_auto = 0' : 'Google Drive not connected']);
    return;
}
$r = GDrive::backupNow('cron');
cron_done($r, (bool) ($r['ok'] ?? false));
