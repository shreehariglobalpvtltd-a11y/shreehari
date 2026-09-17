-- =====================================================================
--  Upgrade 2026-09 — Agent KYC (ID document files + verification state)
--  17 Sep 2026
--
--  Run ONCE on the live database (phpMyAdmin -> SQL tab -> paste -> Go),
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-09-agent-kyc.sql
--
--  Fully idempotent: every ALTER is guarded against information_schema
--  and skipped when the column is already there, so re-running is a no-op
--  and it is safe to run while the site is live.
--
--  WHAT THIS ADDS, and why each default is what it is
--  --------------------------------------------------
--  Until now an agent's KYC was two text fields (id_type / id_number) and
--  a face photo. The office wanted the document itself on file and a
--  verification state it can stand behind:
--
--    kyc_doc_path     the ID document (front) — relative path under
--                     uploads/agents-kyc/. NEVER linked from /uploads
--                     directly (nginx serves that tree publicly); streamed
--                     through an admin-gated script instead.
--    kyc_doc2_path    a second page / back side / address proof.
--    kyc_status       none | submitted | verified | rejected.
--                     DEFAULT 'none' so every existing agent reads as
--                     "nothing on file" and NOTHING changes on deploy —
--                     selling is only gated on KYC once the office turns
--                     on agent_kyc_required in Settings.
--    kyc_note         what the verifier wrote (why rejected, what to fix).
--    kyc_verified_by  admins.id who verified, kyc_verified_at when.
--    id_expires_on    document expiry, so an expired passport can be
--                     flagged before it becomes a problem at the border.
--
--  NB: no semicolon inside any quoted ALTER string — tests/apply-sql.php
--  splits this file on ";" and would cut the statement in half.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_doc_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_doc_path` VARCHAR(255) NULL COMMENT ''ID document, relative path under uploads/agents-kyc/'' AFTER `photo_path`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_doc2_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_doc2_path` VARCHAR(255) NULL COMMENT ''second page / back side / address proof'' AFTER `kyc_doc_path`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_status');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_status` ENUM(''none'',''submitted'',''verified'',''rejected'') NOT NULL DEFAULT ''none'' COMMENT ''none = nothing on file (default for every existing agent)'' AFTER `kyc_doc2_path`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_note');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_note` VARCHAR(255) NULL COMMENT ''verifier note — why rejected / what to fix'' AFTER `kyc_status`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_verified_by');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_verified_by` BIGINT UNSIGNED NULL COMMENT ''admins.id who verified'' AFTER `kyc_note`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'kyc_verified_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `kyc_verified_at` DATETIME NULL AFTER `kyc_verified_by`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_profiles' AND COLUMN_NAME = 'id_expires_on');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_profiles` ADD COLUMN `id_expires_on` DATE NULL COMMENT ''ID document expiry'' AFTER `kyc_verified_at`',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The switch that makes KYC block selling. OFF by default: every existing
-- agent reads kyc_status = 'none' until the office verifies them, so
-- turning this on before that would stop every counter at once.
INSERT IGNORE INTO `settings` (`skey`,`svalue`,`stype`,`sgroup`,`label`,`is_public`) VALUES
('agent_kyc_required','0','bool','agent','Agents must be KYC-verified before they can sell (off = KYC is informational only)',0);
