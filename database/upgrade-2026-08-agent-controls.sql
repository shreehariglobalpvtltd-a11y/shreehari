-- =====================================================================
--  Upgrade 2026-08 — Agent identity, booking limits and route permissions
--
--  Run ONCE on the live database (hPanel -> phpMyAdmin -> your database ->
--  SQL tab -> paste -> Go), or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-08-agent-controls.sql
--
--  Fully idempotent: every ALTER is guarded against information_schema and
--  the new table is CREATE TABLE IF NOT EXISTS, so re-running is a no-op.
--  Safe to run while the site is live — nothing here rewrites existing rows.
--
--  WHAT THIS ADDS, and why each default is what it is
--  --------------------------------------------------
--  1) Agent identity on admin_profiles — the ID document and photo a
--     counter agent is issued against. Nullable: existing agents predate
--     the requirement and must not be locked out for having no photo.
--
--  2) daily_booking_limit — a cap on how many bookings one agent may
--     create in a day. DEFAULT 0 MEANS NO LIMIT, matching the existing
--     cash_limit convention in this table, so every agent that already
--     exists keeps selling exactly as before until someone sets a cap.
--     Note this is a COUNT of bookings; cash_limit is an AMOUNT of money.
--     They answer different questions and neither substitutes for the other.
--
--  3) agent_route_permissions — which routes an agent may sell.
--     DELIBERATELY DEFAULT-OPEN: an agent with NO rows here may sell every
--     route. Only once a row exists is that agent restricted to the routes
--     listed. Default-closed would have silently stopped every existing
--     agent from selling the moment this migration ran.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Identity + limit columns on admin_profiles
-- ---------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'id_type');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `id_type` VARCHAR(40) NULL COMMENT ''Aadhaar / Citizenship / PAN / Passport'' AFTER `address`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'id_number');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `id_number` VARCHAR(60) NULL COMMENT ''document number as printed'' AFTER `id_type`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'photo_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `photo_path` VARCHAR(255) NULL COMMENT ''relative path under uploads/agents/'' AFTER `id_number`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'daily_booking_limit');
SET @sql := IF(@col = 0,
  -- NB: no semicolon inside this string literal — tests/apply-sql.php splits
  -- the file on ";" and would cut the statement in half.
  'ALTER TABLE `admin_profiles` ADD COLUMN `daily_booking_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''bookings per day, 0 = no limit'' AFTER `cash_limit`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Why an agent is suspended, so the reason survives a reactivation and can
-- be shown back to them at the sign-in attempt.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'suspended_reason');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `suspended_reason` VARCHAR(255) NULL AFTER `daily_booking_limit`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'suspended_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `suspended_at` DATETIME NULL AFTER `suspended_reason`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 2) Route permissions.
--    NO ROWS for an agent  = may sell every route (the default).
--    ONE OR MORE ROWS      = may sell only the routes listed.
--    ON DELETE CASCADE both ways: removing a staff account or retiring a
--    route must never leave a permission row pointing at nothing.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_route_permissions` (
  `admin_id`   BIGINT UNSIGNED NOT NULL COMMENT 'admins.id of the counter agent',
  `route_id`   BIGINT UNSIGNED NOT NULL,
  `created_by` BIGINT UNSIGNED NULL COMMENT 'admins.id who granted it',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`, `route_id`),
  KEY `ix_arp_route` (`route_id`),
  CONSTRAINT `fk_arp_admin` FOREIGN KEY (`admin_id`)
    REFERENCES `admins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_arp_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3) Settings (Admin -> Settings, group "agent").
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_daily_booking_limit','0','int','agent','Default bookings-per-day cap for a new agent (0 = no limit)',0);
