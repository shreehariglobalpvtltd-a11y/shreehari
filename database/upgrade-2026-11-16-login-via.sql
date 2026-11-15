-- =====================================================================
--  upgrade-2026-11-16-login-via.sql
--
--  16 Nov 2026 (master upgrade v2, login P1-6d): the sign-in history
--  (Sessions -> Login history, admin_login_events) says HOW each attempt
--  was made: password, otp, pin, pattern, whatsapp_hi, whatsapp_otp,
--  passkey ... PIN / pattern misses on the code door are recorded there
--  too (they used to reach audit_logs only).
--
--  admin_sessions.via already exists (VARCHAR(20)); nothing changes there.
--
--  Additive and re-runnable. The code works without this column (it is
--  checked with LoginLog::viaReady() and simply not written).
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'admin_login_events' AND COLUMN_NAME = 'via');
SET @sql := IF(@col = 0,
  'ALTER TABLE `admin_login_events` ADD COLUMN `via` VARCHAR(20) NULL DEFAULT NULL COMMENT ''how: password / otp / pin / pattern / whatsapp_hi / passkey ...'' AFTER `outcome`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
