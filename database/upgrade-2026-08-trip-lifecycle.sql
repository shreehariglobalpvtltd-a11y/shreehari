-- =====================================================================
--  Upgrade 2026-08 — Automatic trip-lifecycle messaging + refund desk
--  (V6 §"WhatsApp + SMS Automation" and §"Refund System")
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-08-trip-lifecycle.sql
--
--  Fully idempotent and additive: only CREATE TABLE IF NOT EXISTS and
--  INSERT IGNORE. No existing table is altered, so running it twice — or
--  running it while the site is live — changes nothing that already exists.
--
--  What it powers
--  --------------
--   * cron/reminders.php  — the 12-hour and 2-hour departure reminders,
--     sent automatically to every confirmed passenger. No admin action.
--   * Admin -> Trips      — one-click "Bus started" / "Reached border" /
--     "Arrived" buttons that notify that trip's whole passenger list.
--   * Exactly-once delivery: `trip_events` carries UNIQUE(booking, leg,
--     event), so a cron that runs every 5 minutes — or two crons racing —
--     can never send the same passenger the same reminder twice.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Per-passenger delivery ledger — the exactly-once guarantee.
--    One row per (booking, schedule, event). The row is CLAIMED with
--    INSERT IGNORE *before* the message is sent; a second attempt gets
--    rowCount 0 and skips. `ok` then records whether the provider took it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trip_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NOT NULL,
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `event`       ENUM('reminder_12h','reminder_2h','departed','border','arrived') NOT NULL,
  `channels`    VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'wa / sms / wa,sms',
  `ok`          TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = a provider accepted it',
  `detail`      VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trip_event` (`booking_id`,`schedule_id`,`event`),
  KEY `ix_trip_event_sched` (`schedule_id`,`event`),
  KEY `ix_trip_event_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2) Per-trip status ledger — what the operator marked, and when.
--    Kept separate from `schedules` so this migration never has to ALTER
--    a live table (and so "reached border", which has no schedules.status
--    equivalent, has somewhere to live). departed/arrived ALSO move
--    schedules.status, which already carries those values.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trip_status` (
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `event`       ENUM('departed','border','arrived') NOT NULL,
  `admin_id`    BIGINT UNSIGNED NULL COMMENT 'who marked it',
  `notified`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'passengers messaged',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`,`event`),
  KEY `ix_trip_status_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3) Settings (Admin -> Settings, group "notify"). Every one defaults to
--    ON except the master switch's dependants being individually toggleable,
--    so after this migration the reminders work as soon as the cron runs.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('trip_reminders_enabled','1','bool',  'notify','Automatic trip messages master switch (reminders + bus started / border / arrived)',0),
('reminder_12h_enabled',  '1','bool',  'notify','Send the 12-hours-before-departure reminder',0),
('reminder_2h_enabled',   '1','bool',  'notify','Send the 2-hours-before-departure reminder',0),
('trip_status_notify',    '1','bool',  'notify','Notify passengers when staff mark Bus started / Reached border / Arrived',0),
('border_point_name',     'Rupaidiha ⇄ Jamunaha','string','notify','Border crossing name used in the "reached border" message',0),
('refund_notify_customer','1','bool',  'notify','Message the customer when a refund is approved or declined',0);
