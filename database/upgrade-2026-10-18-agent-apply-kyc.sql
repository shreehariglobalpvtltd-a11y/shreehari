-- =====================================================================
--  upgrade-2026-10-18-agent-apply-kyc.sql
--
--  Owner, 18 Oct 2026: "agent ko online form - naam, document, phone,
--  location, tyahi app bata kheecheko photo, auntha le gareko digital
--  signature". The simple form (/agreements/apply.html) sends three more
--  things with an application:
--    doc_photo  the ID document photographed with the phone camera
--    signature  the applicant's finger signature (PNG)
--    geo        "lat,lng,accuracy_m" from the phone's location, when allowed
--  Both images are data: URIs in the database, like `photo`, because nginx
--  serves /uploads to anyone. Staff with KYC rights only (admin/agreements).
--
--  Additive and re-runnable: each column is added only when missing.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'doc_photo');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `doc_photo` MEDIUMTEXT NULL COMMENT ''data URI of the ID document photo, staff-only'' AFTER `photo`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'signature');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `signature` MEDIUMTEXT NULL COMMENT ''data URI PNG of the finger signature, staff-only'' AFTER `doc_photo`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'geo');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `geo` VARCHAR(64) NULL COMMENT ''lat,lng,accuracy metres from the phone, when allowed'' AFTER `signature`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
