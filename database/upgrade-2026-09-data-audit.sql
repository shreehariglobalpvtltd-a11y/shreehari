-- =====================================================================
--  upgrade-2026-09-data-audit.sql — settings for the night data audit
--  (cron/data-audit.php, includes/dataaudit.php). 19 Sep 2026.
--
--  Additive and re-runnable: two settings rows, no table, no column.
--  The audit itself is read-only; its cards go to health_incidents, which
--  already exists (upgrade-2026-09-gemvault-spine.sql).
-- =====================================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('data_audit_on',        '1',  'bool', 'automation', 'Night data audit: re-check seats, payments and agent commission and raise a card when something does not add up (read-only)', 0),
('data_audit_days_back', '30', 'int',  'automation', 'Night data audit: how many days back the payment and commission checks look', 0);
