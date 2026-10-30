-- =====================================================================
--  upgrade-2026-10-31-idc-web.sql
--
--  Owner, 31 Oct 2026: "live website ma jas-jasle form bharera verified
--  admin le dinchha, live ma home page ma photo haru dekhiyos" - every
--  AgentPro member the office approved shows with their photo on the home
--  page (#homeRoles, includes/agentpro-web.php ApWeb).
--
--    ap_members.web_show   NULL = automatic (active login + active card +
--                          photo + office approval / KYC verified)
--                          1    = always show (still needs an active
--                                 login, an active card and a photo)
--                          0    = never show
--
--  Additive and re-runnable: the column is added only when missing, and
--  nothing runs when ap_members is not installed yet (DO 0). The code
--  works without this file too (every member is then "automatic").
--  (apply-sql.php splits on semicolons - none inside the strings.)
-- =====================================================================

SET NAMES utf8mb4;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members');

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'web_show');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_members` ADD COLUMN `web_show` TINYINT(1) NULL DEFAULT NULL COMMENT ''home page: NULL automatic, 1 always show, 0 hide''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
