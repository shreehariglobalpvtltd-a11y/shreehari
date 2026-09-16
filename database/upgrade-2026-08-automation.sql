-- =====================================================================
--  Automation layer (Aug 2026) — EventBus journal + control-panel toggles
--
--  100% additive and idempotent: CREATE TABLE IF NOT EXISTS plus
--  INSERT IGNORE only. Safe to re-run. Nothing is altered or dropped.
--
--  Apply locally:
--    php -c .claude/php-dev.ini tests/apply-sql.php database/upgrade-2026-08-automation.sql
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. automation_log — journal of every emitted event, and the
--     exactly-once ledger for cron alerts/digests (UNIQUE dedupe_key,
--     claimed with INSERT IGNORE the same way trip_events works).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `automation_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event`      VARCHAR(50)  NOT NULL,
  `dedupe_key` VARCHAR(120) NULL COMMENT 'exactly-once claims: low-seat:<sid>, agent-summary:<date>, ...',
  `data`       LONGTEXT     NULL COMMENT 'slim JSON payload (ids + scalars)',
  `status`     ENUM('ok','fail') NOT NULL DEFAULT 'ok',
  `error`      VARCHAR(500) NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_automation_dedupe` (`dedupe_key`),
  KEY `idx_event_date` (`event`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. Automation control panel — settings rows (their own sgroup, so
--     Admin -> Settings auto-renders an "Automation" panel; the admin
--     form can only edit keys that already EXIST, hence seed-first).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
  ('email_enabled',                   '1',  'bool', 'automation', 'Email notifications (master switch)',                        0),
  ('email_attach_ics',                '1',  'bool', 'automation', 'Attach calendar event (.ics) to ticket emails',              0),
  ('email_attach_pdf',                '1',  'bool', 'automation', 'Attach ticket PDF to ticket emails',                         0),
  ('notify_customer_on_create',       '1',  'bool', 'automation', 'WhatsApp customer when a booking is placed',                 0),
  ('notify_proof_uploaded',           '1',  'bool', 'automation', 'Alert admin when payment proof is uploaded',                 0),
  ('notify_cod_settled',              '1',  'bool', 'automation', 'Notify customer + agent when COD cash is settled',           0),
  ('agent_notify_enabled',            '1',  'bool', 'automation', 'WhatsApp the selling agent on approve / reject / cancel',    0),
  ('agent_daily_summary_enabled',     '1',  'bool', 'automation', 'Agent daily summary at 9 PM (cron/daily-summary.php)',       0),
  ('admin_daily_digest_enabled',      '1',  'bool', 'automation', 'Admin daily revenue digest at 9 PM',                         0),
  ('low_seat_alert_enabled',          '1',  'bool', 'automation', 'Low-seat alert to admin (cron/alerts.php)',                  0),
  ('low_seat_threshold',              '10', 'int',  'automation', 'Low-seat alert threshold (seats remaining)',                 0),
  ('pending_approval_alert_enabled',  '1',  'bool', 'automation', 'Remind admin about unreviewed payment proofs',               0),
  ('approval_reminder_minutes',       '30', 'int',  'automation', 'Remind after payment proof waits (minutes)',                 0),
  ('automation_log_days',             '30', 'int',  'automation', 'Keep automation log for (days)',                             0);
