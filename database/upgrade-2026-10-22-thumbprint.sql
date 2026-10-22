-- =====================================================================
--  22 Oct 2026 (owner: "face id ni lagau, fingerprint ni rakhna milnu
--  paryo, natra fingerprint scan garera camera bata rakhdinus"):
--  the applicant's thumbprint, photographed with the phone camera and
--  turned into an ink-style print on the phone, for the staff form and
--  the agent / partner form - plus the agent form's phone passkey
--  (fingerprint / face unlock; only the public credential is kept,
--  the fingerprint itself never leaves the phone).
--
--  Idempotent: run it twice, nothing changes the second time.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'thumb_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE `staff_applications` ADD COLUMN `thumb_path` VARCHAR(160) NULL COMMENT ''thumbprint PNG from the phone camera'' AFTER `signature_path`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'thumb');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `thumb` MEDIUMTEXT NULL COMMENT ''data URI PNG of the thumbprint from the phone camera, staff-only'' AFTER `signature`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'webauthn_cred');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `webauthn_cred` TEXT NULL COMMENT ''phone passkey (public credential only) from the online form'' AFTER `thumb`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- the agent's latest marksheet / education certificate (owner: "document, marksheet upload garne"), optional
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'edu_cert');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `edu_cert` MEDIUMTEXT NULL COMMENT ''data URI of the marksheet / education certificate, staff-only'' AFTER `webauthn_cred`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
