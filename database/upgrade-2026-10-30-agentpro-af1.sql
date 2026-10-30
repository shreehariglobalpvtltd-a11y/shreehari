-- =====================================================================
--  upgrade-2026-10-30-agentpro-af1.sql
--
--  Owner, 30 Oct 2026: AgentPro (join.php) becomes the ONE form system.
--    * the office fills a form for someone fast (join.php?office=1) and may
--      skip the WhatsApp check with a reason  -> wa_verified_via 'office',
--      office_filled_by = the admin who filled / vouched for it
--    * the printed paper form has more boxes than the online one had:
--      occupation, education, experience, skills, languages, PAN
--    * office_edited_at: the office changed a SENT form (approve refreshes the account)
--
--  Additive and re-runnable: each column is added only when missing, the
--  ENUM only gains a value (stored values never change), and nothing runs
--  when ap_applications is not installed yet (DO 0).
--  (apply-sql.php splits on semicolons - none inside the strings.)
-- =====================================================================

SET NAMES utf8mb4;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications');

-- ---- wa_verified_via gains 'office' (the office vouched for the number) ----
SET @sql := IF(@t = 1,
  'ALTER TABLE `ap_applications` MODIFY `wa_verified_via` ENUM(''otp'',''reverse'',''office'') NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- office_filled_by ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'office_filled_by');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `office_filled_by` INT NULL COMMENT ''admins.id of the office user who filled the form or skipped the WhatsApp check''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- occupation ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'occupation');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `occupation` VARCHAR(120) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- education ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'education');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `education` VARCHAR(120) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- experience ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'experience');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `experience` VARCHAR(160) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- skills ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'skills');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `skills` VARCHAR(200) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- languages ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'languages');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `languages` VARCHAR(120) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- pan (the PAN written on the form, even when the ID used is Aadhaar) ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'pan');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `pan` VARCHAR(12) NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- office_edited_at (the office changed the form after it was sent: approve copies it into the profile) ----
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_applications' AND COLUMN_NAME = 'office_edited_at');
SET @sql := IF(@t = 1 AND @col = 0,
  'ALTER TABLE `ap_applications` ADD COLUMN `office_edited_at` DATETIME NULL COMMENT ''set when the office edits a submitted form - approve refreshes the account from it''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
