-- =====================================================================
--  Bus Management Upgrade — 2026-08-28
--
--  1. Add seat_model + floors columns to buses
--  2. Standardise all sleeper buses to 72 seats / 2 floors
--  3. Update schedules.total_seats for sleeper buses
--  4. Update schedules where no bus assigned but route has sleeper coach
--
--  Safe to re-run: all statements are idempotent.
-- =====================================================================

-- 1a. Add seat_model column (if missing)
SET @col1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buses' AND COLUMN_NAME = 'seat_model');
SET @s1 := IF(@col1 = 0,
  "ALTER TABLE `buses` ADD COLUMN `seat_model` VARCHAR(30) NOT NULL DEFAULT '72-sleeper' COMMENT 'layout key: 72-sleeper | 60-sleeper | 45-seater | 50-seater | custom' AFTER `total_seats`",
  'DO 0');
PREPARE st1 FROM @s1; EXECUTE st1; DEALLOCATE PREPARE st1;

-- 1b. Add floors column (if missing)
SET @col2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buses' AND COLUMN_NAME = 'floors');
SET @s2 := IF(@col2 = 0,
  'ALTER TABLE `buses` ADD COLUMN `floors` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT \'number of decks: 1 = single, 2 = double-decker\' AFTER `seat_model`',
  'DO 0');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;

-- 2. Standardise sleeper buses → 72 seats, 2 floors, seat_model '72-sleeper'
UPDATE `buses`
   SET `total_seats` = 72,
       `seat_model`  = '72-sleeper',
       `floors`      = 2
 WHERE `coach_type` = 'sleeper';

-- Seater buses → seat_model tag (doesn't change their existing total_seats)
UPDATE `buses`
   SET `seat_model` = CASE
         WHEN `total_seats` >= 65 THEN '72-sleeper'
         WHEN `total_seats` >= 55 THEN '60-sleeper'
         WHEN `total_seats` >= 48 THEN '50-seater'
         ELSE '45-seater'
       END,
       `floors`     = 1
 WHERE `coach_type` = 'seater'
   AND (`seat_model` IS NULL OR `seat_model` = '72-sleeper');  -- only if untouched

-- 3. Update schedules that are linked to a sleeper bus (via bus_id)
UPDATE `schedules` s
  JOIN `buses` b ON b.id = s.bus_id
   SET s.`total_seats` = 72
 WHERE b.`coach_type` = 'sleeper'
   AND s.`total_seats` < 72;

-- 4. Update schedules linked via route.bus_id (legacy — some routes may carry
--    the bus reference rather than the schedule row itself)
UPDATE `schedules` s
  JOIN `routes` r ON r.id = s.route_id
  JOIN `buses`  b ON b.id = r.bus_id
   SET s.`total_seats` = 72
 WHERE b.`coach_type` = 'sleeper'
   AND s.`total_seats` < 72
   AND s.bus_id IS NULL;

-- 5. If the routes themselves hold the coach_type, also update routeless schedules
UPDATE `schedules` s
  JOIN `routes` r ON r.id = s.route_id
   SET s.`total_seats` = 72
 WHERE r.`coach_type` = 'sleeper'
   AND s.`total_seats` < 72;

SELECT
  CONCAT('buses: ', (SELECT COUNT(*) FROM `buses`), ' total, ',
         (SELECT COUNT(*) FROM `buses` WHERE coach_type='sleeper'), ' sleeper @ 72 seats') AS status;

SELECT
  CONCAT('schedules: ', (SELECT COUNT(*) FROM `schedules` WHERE total_seats=72), ' at 72 seats / ',
         (SELECT COUNT(*) FROM `schedules`), ' total') AS status;
