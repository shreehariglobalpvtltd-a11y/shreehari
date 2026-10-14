-- =====================================================================
--  15 Oct 2026 - SmartBook v5 phase 3: one booking engine (includes/
--  smartbook.php + includes/autoberth.php, api/smartbook.php).
--
--      php tests/apply-sql.php database/upgrade-2026-10-15-smartbook-engine.sql
--
--  ADDITIVE ONLY - no column holding data is dropped or renamed.
--
--  1. smartbook_requests - idempotency keys. A double tap, a retry after a
--     slow network or a payment callback received twice carries the same
--     key and gets the SAME booking back, never a second one.
--  2. route_stops.visible_to - which booking modes offer a stop
--     (customer / agent / counter). Every existing stop, Rajkot included,
--     is visible to all three, so nothing changes until the office edits it.
--  3. Settings: the auto-berth soft-rule weights (seat_rules), the COD caps,
--     the online hold, and the Default Rules (empty = built-in order).
--
--  Re-running is harmless.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `smartbook_requests` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `idem_key`    VARCHAR(64)     NOT NULL,
  `booking_id`  BIGINT UNSIGNED NULL DEFAULT NULL,
  `pnr`         VARCHAR(40)     NULL DEFAULT NULL,
  `mode`        VARCHAR(12)     NOT NULL DEFAULT 'customer',
  `state`       ENUM('working','done','failed') NOT NULL DEFAULT 'working',
  `response`    LONGTEXT        NULL DEFAULT NULL,
  `ip_address`  VARCHAR(45)     NULL DEFAULT NULL,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sbreq_key` (`idem_key`),
  KEY `ix_sbreq_booking` (`booking_id`),
  KEY `ix_sbreq_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'route_stops' AND column_name = 'visible_to');
SET @s := IF(@c = 0,
  "ALTER TABLE `route_stops` ADD COLUMN `visible_to` SET('customer','agent','counter') NOT NULL DEFAULT 'customer,agent,counter' AFTER `is_active`",
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT IGNORE INTO `settings` (`skey`, `svalue`, `stype`, `sgroup`, `label`, `is_public`) VALUES
 ('seat_rules', '{"together":50,"femaleNearFemale":40,"careLower":35,"lowerFirst":20,"maleUpper":15,"emergencyAble":10,"rowBalance":1}', 'json', 'booking', 'Auto-berth soft rules (weights): together, femaleNearFemale, careLower, lowerFirst, maleUpper, emergencyAble, rowBalance', 0),
 ('cod_max_pax_per_mobile',  '6',  'int',  'booking', 'Most pay-at-boarding (Cash) passengers one mobile may hold on one departure', 1),
 ('cod_autocancel_minutes',  '0',  'int',  'booking', 'Auto-cancel unpaid Cash bookings this many minutes before their boarding time (0 = off)', 0),
 ('smartbook_online_hold_min', '15', 'int', 'booking', 'Minutes an Online (QR) booking keeps its berths while the customer pays', 1),
 ('default_rules', '[]', 'json', 'booking', 'SmartBook Default Rules (Admin -> Default Rules), empty = built-in order', 0);
