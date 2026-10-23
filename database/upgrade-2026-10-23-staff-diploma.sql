-- =====================================================================
--  23 Oct 2026 (review of the one form): the form offers "Diploma / ITI"
--  as the highest education, and StaffApps::QUALS now keeps it - the
--  staff_applications.qualification ENUM gets 'diploma' at the END, so no
--  stored value changes.
--
--  Idempotent: run it twice, nothing changes the second time.
-- =====================================================================

SET NAMES utf8mb4;

SET @t := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME = 'qualification');
SET @sql := IF(@t IS NOT NULL AND @t NOT LIKE '%''diploma''%',
  'ALTER TABLE `staff_applications` MODIFY `qualification` ENUM(''below_see'',''see'',''plus2'',''bachelor'',''master'',''other'',''diploma'') NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
