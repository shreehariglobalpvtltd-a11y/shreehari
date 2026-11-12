-- =====================================================================
--  upgrade-2026-11-12-security-question.sql
--
--  Owner, 12 Nov 2026: "app kholne bittikai setup — pattern / PIN ra
--  security key, DOB jasto secure question, harek agent / admin / staff
--  ko ID ma." Each account keeps ONE question and the bcrypt of its
--  answer. It is used for one thing only: on a phone that already
--  remembers the account, a forgotten PIN can be replaced after the
--  answer (admin/recover.php). The password door is never opened by it.
--
--  Two columns on admins:
--    sec_q        the question text            (NULL = not set)
--    sec_a_hash   bcrypt of the tidied answer  (NULL = not set)
--
--  Additive and re-runnable: each column is added only when missing.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'sec_q');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `sec_q` VARCHAR(160) NULL COMMENT ''security question (forgot PIN)''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'sec_a_hash');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admins` ADD COLUMN `sec_a_hash` VARCHAR(255) NULL COMMENT ''bcrypt of the security answer''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
