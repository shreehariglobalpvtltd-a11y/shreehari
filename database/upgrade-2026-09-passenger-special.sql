-- ============================================================
--  upgrade-2026-09-passenger-special.sql  (4 Sep 2026)
--
--  Patient / birami mode: one nullable column on booking_passengers that
--  flags a passenger for priority boarding ('patient' | 'senior' |
--  'pregnant'). Shown on the ticket, the manifest and the booking view.
--
--  Idempotent and additive: a guarded ALTER that is skipped when the column
--  already exists. Safe to run more than once.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-passenger-special.sql
--    VPS:   mysql shari < database/upgrade-2026-09-passenger-special.sql
-- ============================================================

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_passengers' AND COLUMN_NAME = 'special_need');
SET @sql := IF(@have = 0,
  'ALTER TABLE `booking_passengers` ADD COLUMN `special_need` VARCHAR(40) NULL COMMENT ''patient | senior | pregnant — priority boarding (4 Sep 2026)'' AFTER `nationality`',
  'SELECT ''booking_passengers.special_need already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
