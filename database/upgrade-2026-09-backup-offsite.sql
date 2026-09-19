-- =====================================================================
--  upgrade-2026-09-backup-offsite.sql - encrypted off-site backup
--  (includes/offsitebackup.php, cron/backup-offsite.php). 19 Sep 2026.
--
--  Additive and re-runnable: three settings rows. Ships OFF.
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('backup_offsite_on',       '0', 'bool',   'automation', 'Off-site backup: email last night''s database backup, encrypted, every night', 0),
('backup_offsite_password', '',  'string', 'automation', 'Off-site backup PASSWORD (10+ characters). Write it on paper - without it a backup cannot be opened by anyone', 0),
('backup_offsite_email',    '',  'string', 'automation', 'Off-site backup: mailbox that receives it (empty = the admin email)', 0);
