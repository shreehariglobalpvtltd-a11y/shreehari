-- =====================================================================
--  upgrade-2026-10-19-agent-website-card.sql
--
--  Owner, 19 Oct 2026: "agent haru ko form ... website ma name, number,
--  photo, address rakher live gardinu ... admin le add remove edit manage
--  garna milos ... whatsapp ma msg garna milne". Each approved application
--  becomes a card on the website, and the office decides what it shows:
--    web_live   1 = on the website once approved, 0 = hidden by the office
--    web_photo  1 = the applicant's photo may be shown on the website
--    web_addr   the PUBLIC address line (shop / office), typed by the office.
--               Never the home address from the KYC form.
--    web_key    random key for /api/agent-photo.php?k=... (no guessable ids)
--
--  Additive and re-runnable: each column is added only when missing.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'web_live');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `web_live` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = on the website once approved''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'web_photo');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `web_photo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = photo shown on the website''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'web_addr');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `web_addr` VARCHAR(200) NULL COMMENT ''public shop or office address, typed by the office''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'web_key');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `web_key` CHAR(20) NULL COMMENT ''random key of the public photo URL'', ADD UNIQUE KEY `uq_agent_app_web_key` (`web_key`)',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
