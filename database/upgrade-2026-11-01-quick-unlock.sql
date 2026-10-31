-- =====================================================================
--  upgrade-2026-11-01-quick-unlock.sql
--
--  Owner, 1 Nov 2026: "agent, staff portal ma Android / iPhone jastai
--  pattern ra PIN le login hune — suru ma pattern, ani PIN, password,
--  ID, forget — full flexible." Every staff / agent / office account may
--  add a 4-6 digit PIN and/or a 3x3 pattern as a quick way through the
--  one door (admin/login.php). The password, the ID, the WhatsApp doors
--  and "forgot password" all stay exactly as they are.
--
--  Four columns on admins:
--    pin_hash             bcrypt of the PIN        (NULL = no PIN set)
--    pattern_hash         bcrypt of the pattern, stored as the canonical
--                         node string "0-1-2-5"    (NULL = no pattern set)
--    unlock_failed        wrong PIN / pattern tries in a row
--    unlock_locked_until  quick unlock refused until this time (the
--                         password door is never locked by it)
--
--  Plus the switch quick_unlock_on (default ON).
--
--  Additive and re-runnable: each column is added only when missing.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'pin_hash');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `pin_hash` VARCHAR(255) NULL COMMENT ''bcrypt of the quick-unlock PIN''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'pattern_hash');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `pattern_hash` VARCHAR(255) NULL COMMENT ''bcrypt of the quick-unlock pattern''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'unlock_failed');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `unlock_failed` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''wrong PIN / pattern tries in a row''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'unlock_locked_until');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `unlock_locked_until` DATETIME NULL COMMENT ''quick unlock refused until then''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, is_public) VALUES
  ('quick_unlock_on', '1', 'bool', 'security', 0);
