-- =====================================================================
--  upgrade-2026-10-30-agentpro2.sql
--
--  Owner, 30 Oct 2026: drivers and conductors join through AgentPro
--  (join.php / office form) like every other member of staff, get the
--  ID card, AND sign in to the driver app (driver/, api/driver.php) so the
--  bus shows live on the admin map and the passenger track page.
--
--    ap_members.crew_role  'driver' / 'conductor' (NULL = not bus crew).
--                          The admins row keeps role 'scanner' (boarding
--                          staff: tickets.scan only) - admins.role is not
--                          widened, so no permission or login page changes.
--    ap_members.driver_id  drivers.id of their driver-app account
--                          (phone + PIN, includes/driverauth.php).
--
--  Additive and re-runnable: each column is added only when missing, and
--  nothing runs when ap_members is not installed yet (DO 0). The code works
--  without this file too (AgentPro::memberHas guards; it then falls back to
--  the application role and the drivers row with the same mobile).
--  (apply-sql.php splits on semicolons - none inside the strings.)
-- =====================================================================

SET NAMES utf8mb4;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members');

-- ---- crew_role ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'crew_role');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `crew_role` VARCHAR(20) NULL COMMENT ''driver / conductor - also signs in to the driver app (driver/)''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- driver_id ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'driver_id');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `driver_id` BIGINT UNSIGNED NULL COMMENT ''drivers.id of the driver-app account (phone + PIN)''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- index on driver_id ----
SET @ix := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND INDEX_NAME = 'ix_ap_members_driver');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'driver_id');
SET @sql := IF(@t = 1 AND @col = 1 AND @ix = 0,
  'ALTER TABLE `ap_members` ADD INDEX `ix_ap_members_driver` (`driver_id`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
