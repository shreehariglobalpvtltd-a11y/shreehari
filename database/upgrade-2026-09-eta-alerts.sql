-- =====================================================================
--  upgrade-2026-09-eta-alerts.sql - "bus is ~30 min from your stop"
--  (includes/etaalerts.php, cron/eta-alerts.php). 19 Sep 2026.
--
--  Additive and re-runnable: one table, two settings rows. Ships OFF.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `trip_eta_alerts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`  BIGINT UNSIGNED NOT NULL,
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `stop_name`   VARCHAR(191) NOT NULL,
  `eta_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `dist_km`     DECIMAL(6,1) NOT NULL DEFAULT 0.0,
  `channels`    VARCHAR(40) NULL COMMENT 'wa,sms,push - what actually went',
  `ok`          TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eta_once` (`booking_id`,`schedule_id`),
  KEY `ix_eta_schedule` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('eta_alert_on',      '0',  'bool', 'automation', 'Tell each passenger once when the bus is close to their own pickup (needs the driver to switch on "I am the driver" on the map)', 0),
('eta_alert_minutes', '30', 'int',  'automation', 'Bus-is-near alert: how many minutes before the bus reaches the pickup (10-90)', 0);
