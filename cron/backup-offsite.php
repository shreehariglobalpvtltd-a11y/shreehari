<?php
/**
 * cron/backup-offsite.php — send last night's backup off this server,
 * encrypted. (includes/offsitebackup.php has the design and the reasoning.)
 *
 * Recommended: daily, after backup.php (02:15).
 *   crontab:   45 2 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/backup-offsite.php
 *   or by URL: https://www.shreehariglobal.in/cron/backup-offsite.php?token=CRON_TOKEN
 *
 * Switch: settings.backup_offsite_on (default off). Needs
 * backup_offsite_password (the owner's, 10+ characters, also kept on paper).
 * How to open a backup: docs/RESTORE.md.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/offsitebackup.php';

if (!OffsiteBackup::enabled()) {
    cron_done(['skipped' => 'backup_offsite_on = 0']);
    return;
}

$r = OffsiteBackup::run();
cron_done($r, (bool) ($r['ok'] ?? false));
