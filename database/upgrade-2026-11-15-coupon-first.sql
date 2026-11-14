-- =====================================================================
--  upgrade-2026-11-15-coupon-first.sql
--
--  A coupon may be for a passenger's FIRST booking only. One nullable
--  flag on coupons:
--    first_booking_only   1 = refused once the mobile has any confirmed /
--                         completed booking (Fare::couponDiscount)
--                         NULL / 0 = no such rule (every coupon today)
--
--  The per_user_limit rule is unchanged. Additive and re-runnable: the
--  column is added only when missing. Code that runs before this file
--  simply sees no flag and applies no first-booking rule.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'first_booking_only');
SET @sql := IF(@col = 0,
  'ALTER TABLE `coupons` ADD COLUMN `first_booking_only` TINYINT(1) NULL DEFAULT NULL COMMENT ''1 = only for a first booking of the mobile'' AFTER `per_user_limit`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
