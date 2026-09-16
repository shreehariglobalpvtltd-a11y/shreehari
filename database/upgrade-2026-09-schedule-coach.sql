-- ============================================================
--  upgrade-2026-09-schedule-coach.sql  (4 Sep 2026)
--
--  Per-departure SEAT LAYOUT — an extra bus may run a different kind of
--  coach than its route normally does.
--
--    schedules.coach_type_override  ENUM('seater','sleeper') NULL
--        NULL  = use the route's coach_type, exactly as before
--        set   = THIS departure runs that coach, so its seat map, seat ids,
--                emergency berths and gender rules all follow it
--
--  Additive and NULL for every existing row, so the daily bus and every
--  ticket already sold are untouched: `COALESCE(s.coach_type_override,
--  r.coach_type)` returns precisely what `r.coach_type` returned before.
--
--  The override is only ever written when the office adds or edits an extra
--  bus in the admin panel, and only while that departure has no seats sold —
--  changing the layout under a sold seat would strand it.
--
--    local: "C:\Program Files\MariaDB 12.3\bin\mysql.exe" -h127.0.0.1 -P3307 -uroot shari_test < database/upgrade-2026-09-schedule-coach.sql
--    VPS:   mysql shari < database/upgrade-2026-09-schedule-coach.sql
-- ============================================================

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedules' AND COLUMN_NAME = 'coach_type_override');
SET @sql := IF(@have = 0,
  'ALTER TABLE `schedules` ADD COLUMN `coach_type_override` ENUM(''seater'',''sleeper'') NULL COMMENT ''this departure runs a different coach than the route; NULL = the route''''s own coach_type (4 Sep 2026)'' AFTER `slot`',
  'SELECT ''schedules.coach_type_override already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) AS rows_with_an_override FROM `schedules` WHERE `coach_type_override` IS NOT NULL;
