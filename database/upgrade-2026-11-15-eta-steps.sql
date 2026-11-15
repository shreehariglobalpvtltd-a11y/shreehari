-- =====================================================================
--  upgrade-2026-11-15-eta-steps.sql  (P8, 15 Nov 2026)
--
--  "Bus about 15 min / 5 min from your stop" - a web push to the phone
--  that turned on alerts for the ticket (includes/etaalerts.php,
--  EtaAlerts::runSteps, called by cron/eta-alerts.php).
--
--  eta_alert_steps is the claim ledger: one row per booking, trip and
--  step, written BEFORE the push goes, so a slow push service or two
--  overlapping cron runs can never send the same step twice
--  (UNIQUE booking_id + schedule_id + step_min). schedule_id is in the
--  key so a booking with an outbound and a return leg gets each trip's
--  steps once, like trip_eta_alerts.
--
--  Two switches, both read with the same defaults in code, so a server
--  without these rows behaves exactly the same:
--    eta_push_steps_on  0  = nothing is sent (default OFF)
--    eta_push_steps     '15,5' = minutes before the stop
--  WhatsApp is never used for these steps - web push only.
--
--  Additive and safe to re-run: CREATE TABLE IF NOT EXISTS, and
--  INSERT IGNORE never overwrites a value the office already set. No
--  foreign keys: a booking deleted by the office leaves its rows as
--  history. Until this file has run the step run reports
--  "eta_alert_steps missing" and sends nothing.
--
--    mysql shari < database/upgrade-2026-11-15-eta-steps.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eta_alert_steps` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED  NOT NULL,
  `schedule_id` BIGINT UNSIGNED  NOT NULL,
  `step_min`    TINYINT UNSIGNED NOT NULL COMMENT 'the step: 15 or 5 minutes before the stop',
  `stop_name`   VARCHAR(191)     NOT NULL DEFAULT '',
  `eta_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'minutes the engine measured when it fired',
  `dist_km`     DECIMAL(6,1)     NOT NULL DEFAULT 0.0,
  `pushed`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'phones the push reached',
  `ok`          TINYINT(1)       NOT NULL DEFAULT 0,
  `sent_at`     DATETIME         NOT NULL COMMENT 'claimed, just before the push',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eta_step_once` (`booking_id`, `schedule_id`, `step_min`),
  KEY `ix_eta_step_schedule` (`schedule_id`),
  KEY `ix_eta_step_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES
  ('eta_push_steps_on', '0',    'bool',   'automation', 'Bus-is-near web push at 15 and 5 minutes before the pickup (only phones that turned on alerts for the ticket, never WhatsApp)', 0),
  ('eta_push_steps',    '15,5', 'string', 'automation', 'Bus-is-near web push steps, minutes before the pickup, comma separated (5-60)', 0);
