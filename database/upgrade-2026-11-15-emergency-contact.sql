-- =====================================================================
--  upgrade-2026-11-15-emergency-contact.sql
--
--  A passenger's emergency contact (P7): one optional person per
--  booking the office / the driver may ring if something happens on the
--  road. Three nullable columns on bookings:
--    emergency_name      who to ask for                 (may stay NULL)
--    emergency_phone     '+9779812345678' / '+919812345678' - the dial
--                        code is kept: India and Nepal share 10 digits
--    emergency_relation  father / mother / husband / wife / brother /
--                        sister / friend / other (EmergencyContact::RELATIONS)
--
--  Not bookings.alt_phone: that is the booker's own second SIM (the ticket
--  goes to it). Nothing is ever sent to an emergency contact.
--
--  Additive and re-runnable: each column is added only when missing.
--  Code that runs before this file sees no columns and stores nothing
--  (EmergencyContact::available()).
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'emergency_name');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `emergency_name` VARCHAR(80) NULL DEFAULT NULL COMMENT ''emergency contact: name (optional)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'emergency_phone');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `emergency_phone` VARCHAR(20) NULL DEFAULT NULL COMMENT ''emergency contact: +<dial code><10 digits>''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'emergency_relation');
SET @sql := IF(@col = 0,
  'ALTER TABLE `bookings` ADD COLUMN `emergency_relation` VARCHAR(30) NULL DEFAULT NULL COMMENT ''emergency contact: relation key (EmergencyContact::RELATIONS)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
