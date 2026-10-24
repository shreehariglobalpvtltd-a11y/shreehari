-- =====================================================================
--  upgrade-2026-10-24-scan-autofill.sql
--
--  Owner, 24 Oct 2026: "khaali form print garera, haat le bharera, scan
--  garepachhi process garera OCR garera form ma automatic attach hune,
--  form automatic feel hune." The applicant prints the blank A4 form
--  (/agreements/form.php?auto=pdf), fills it by hand offline, then scans
--  or photographs the filled pages. /api/agreement-scan.php reads the
--  WHOLE filled form with the same Gemini vision the ID reader uses and
--  pre-fills the empty on-screen boxes; the scanned pages ride along and
--  are attached to the application for the office.
--
--  Three columns on each application table (agent + staff):
--    scan_doc         the scanned page(s), a JSON list of data: URIs,
--                     office-only (like doc_photo, never under /uploads)
--    scan_ocr         the fields the AI read, as JSON, for the office
--    scan_ocr_status  read | partial | notform | unavailable
--    scanned_at       when the scan was read
--
--  Additive and re-runnable: each column is added only when missing, and
--  nothing is reordered (so stored values never shift).
-- =====================================================================

SET NAMES utf8mb4;

-- ---- agent_applications (individual agent + Pvt. Ltd. partner) ----

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'scan_doc');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `scan_doc` MEDIUMTEXT NULL COMMENT ''JSON list of data URIs of the scanned filled form, staff-only''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'scan_ocr');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `scan_ocr` TEXT NULL COMMENT ''JSON of the fields the AI read from the scan''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'scan_ocr_status');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `scan_ocr_status` VARCHAR(16) NULL COMMENT ''read, partial, notform or unavailable''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'agent_applications' AND COLUMN_NAME = 'scanned_at');
SET @sql := IF(@col = 0,
  'ALTER TABLE `agent_applications` ADD COLUMN `scanned_at` DATETIME NULL COMMENT ''when the scanned form was read''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- staff_applications (staff onboarding form) ----

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_applications');

SET @col := IF(@tbl = 0, 1, (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'scan_doc'));
SET @sql := IF(@col = 0,
  'ALTER TABLE `staff_applications` ADD COLUMN `scan_doc` MEDIUMTEXT NULL COMMENT ''JSON list of data URIs of the scanned filled form, staff-only''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := IF(@tbl = 0, 1, (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'scan_ocr'));
SET @sql := IF(@col = 0,
  'ALTER TABLE `staff_applications` ADD COLUMN `scan_ocr` TEXT NULL COMMENT ''JSON of the fields the AI read from the scan''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := IF(@tbl = 0, 1, (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'scan_ocr_status'));
SET @sql := IF(@col = 0,
  'ALTER TABLE `staff_applications` ADD COLUMN `scan_ocr_status` VARCHAR(16) NULL COMMENT ''read, partial, notform or unavailable''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := IF(@tbl = 0, 1, (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'scanned_at'));
SET @sql := IF(@col = 0,
  'ALTER TABLE `staff_applications` ADD COLUMN `scanned_at` DATETIME NULL COMMENT ''when the scanned form was read''',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
