-- ============================================================
--  upgrade-2026-09-schedule-slot.sql  (5 Sep 2026)
--
--  Bus calendar: more than one bus per route per date.
--
--    schedules.slot   TINYINT UNSIGNED NOT NULL DEFAULT 1
--                     1 = the daily bus (every existing row),
--                     2, 3, ... = extra buses the office adds for that date
--
--  The unique key (route_id, travel_date) becomes (route_id, travel_date,
--  slot). Every existing row keeps slot = 1, so every "one schedule per
--  route per date" lookup in the code keeps returning exactly the row it
--  returned before (the code pins slot = 1 explicitly from this release).
--  Bookings, seats, holds and tickets are keyed by schedules.id and are not
--  touched.
--
--  Idempotent and additive: guarded ALTERs skipped when already applied.
--  The new unique key is added BEFORE the old one is dropped, so the table
--  is never without a uniqueness guard.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-schedule-slot.sql
--    VPS:   mysql shari < database/upgrade-2026-09-schedule-slot.sql
-- ============================================================

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules' AND COLUMN_NAME = 'slot');
SET @sql := IF(@have = 0,
  'ALTER TABLE `schedules` ADD COLUMN `slot` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''nth departure of this route on this date; 1 = the daily bus, 2+ = extra buses (5 Sep 2026)'' AFTER `travel_date`',
  'SELECT ''schedules.slot already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules' AND INDEX_NAME = 'uq_schedule_route_date_slot');
SET @sql := IF(@have = 0,
  'ALTER TABLE `schedules` ADD UNIQUE KEY `uq_schedule_route_date_slot` (`route_id`, `travel_date`, `slot`)',
  'SELECT ''uq_schedule_route_date_slot already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules' AND INDEX_NAME = 'uq_schedule_route_date');
SET @sql := IF(@have > 0,
  'ALTER TABLE `schedules` DROP INDEX `uq_schedule_route_date`',
  'SELECT ''uq_schedule_route_date already dropped'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules' AND INDEX_NAME = 'ix_schedules_date_slot');
SET @sql := IF(@have = 0,
  'ALTER TABLE `schedules` ADD KEY `ix_schedules_date_slot` (`travel_date`, `slot`)',
  'SELECT ''ix_schedules_date_slot already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
