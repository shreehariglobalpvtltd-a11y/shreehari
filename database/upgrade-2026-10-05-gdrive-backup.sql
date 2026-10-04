-- =====================================================================
--  5 Oct 2026 - Backups to Google Drive (includes/gdrive.php,
--  admin/backup.php, cron/backup-gdrive.php). Settings rows only.
--  The client secret and the (encrypted) refresh token are written by
--  admin/backup.php when the owner connects; they are never seeded here.
-- =====================================================================

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('gdrive_auto',    '0',  'bool', 'backup', 'Google Drive: copy the nightly backup to Drive by itself', 0),
 ('gdrive_keep',    '30', 'int',  'backup', 'Google Drive: keep this many backup copies (older ones go to the Drive trash)', 0),
 ('gdrive_encrypt', '1',  'bool', 'backup', 'Google Drive: encrypt copies with the off-site backup password when it is set', 0);
