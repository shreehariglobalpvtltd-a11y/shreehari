-- =====================================================================
--  Upgrade 2026-10-01 - Per-pickup fare + ON/OFF for every route stop,
--  and Rajkot back as a Gujarat pickup  (owner ask, 1 Oct 2026 evening)
--
--  Run ONCE on the live database:
--      deploy.sh --migrate database/upgrade-2026-10-01-stop-fares.sql
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-10-01-stop-fares.sql
--
--  OWNER ASK
--    * Every pickup in Admin gets a fare field, an ON/OFF switch, reorder
--      and edit (admin/routes.php stop editor).
--    * Gujarat -> Rupaidiha: Emli/Bhupal and Ahmedabad (S Hari Parking,
--      Nana Chiloda) = Rs 2000, every other Gujarat pickup up to Surat =
--      Rs 2200.
--    * Keep every current pickup and add Rajkot back, manageable from Admin.
--
--  WHAT THIS DOES
--    1. route_stops.fare      DECIMAL(10,2) NULL - the per-seat sharing fare
--       for THIS pickup. NULL = the direction default (Admin -> Settings,
--       fare_to_nepal / fare_to_india).
--    2. route_stops.is_active TINYINT(1) DEFAULT 1 - OFF hides the stop from
--       search, the pickers, the counter and the AI. Rows are never deleted
--       (old bookings carry the stop text they were sold with).
--    3. Seeds fares on the boarding stops of ACTIVE Gujarat -> Nepal routes,
--       only where fare IS NULL (a fare already typed in Admin is never
--       overwritten): Emli / Bhupal / Bhopal and Chiloda / S Hari Parking /
--       Ahmedabad = 2000.00, every other boarding stop = 2200.00.
--       Return-route (Rupaidiha -> Gujarat) drop stops stay NULL = the
--       direction default (1800): the owner priced only the outbound side.
--    4. Inserts Rajkot as the LAST boarding stop of every active Gujarat ->
--       Nepal route that has no Rajkot row yet: no time (the owner has not
--       given one - it follows the route departure until Admin sets it),
--       landmark 'Rajkot office pickup (+91 94268 27507)', fare 2200, ON.
--       No map coordinates: Rajkot is off the Surat - Ahmedabad line, and a
--       guessed point would bend the live map and the bus-is-near ETA line.
--    5. Adds the Rajkot desk to counter_locations and company_counters when
--       it is not there yet (string append, idempotent).
--
--  SAFE BY DESIGN
--    * Idempotent: guarded ALTERs, fare seeds only fill NULLs, Rajkot is
--      inserted only when absent, the settings appends test for RJT first.
--    * No booking, leg, seat, ticket, payment or schedule row is touched.
--
--  TAKE A DATABASE BACKUP BEFORE RUNNING (deploy.sh --migrate does).
-- =====================================================================

SET NAMES utf8mb4;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops' AND COLUMN_NAME = 'fare');
SET @sql := IF(@have = 0,
  'ALTER TABLE `route_stops` ADD COLUMN `fare` DECIMAL(10,2) NULL DEFAULT NULL COMMENT ''per-seat sharing fare for this pickup - NULL = direction default'' AFTER `stop_time`',
  'SELECT ''route_stops.fare already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops' AND COLUMN_NAME = 'is_active');
SET @sql := IF(@have = 0,
  'ALTER TABLE `route_stops` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = switched OFF in Admin - not offered for sale'' AFTER `sort_order`',
  'SELECT ''route_stops.is_active already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Rajkot - LAST pickup of every active Gujarat -> Nepal route that lacks one
--    (last, so the default pickup and the picker's first town stay as today).
INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`, `fare`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`, `is_active`)
SELECT r.`id`, 'boarding', 'Rajkot', 'Rajkot office pickup (+91 94268 27507)', NULL, 2200.00,
       NULL, NULL, 0, 0,
       (SELECT COALESCE(MAX(s2.`sort_order`), 0) + 1 FROM `route_stops` s2
         WHERE s2.`route_id` = r.`id` AND s2.`stop_type` = 'boarding'),
       1
  FROM `routes` r
 WHERE r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj', 'kohalpur')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj', 'kohalpur')
   AND NOT EXISTS (
     SELECT 1 FROM `route_stops` s
      WHERE s.`route_id` = r.`id` AND s.`stop_type` = 'boarding'
        AND LOWER(s.`stop_name`) LIKE 'rajkot%'
   );

-- 3a. Emli / Bhupal and Ahmedabad (S Hari Parking, Nana Chiloda) = 2000.
UPDATE `route_stops` s
  JOIN `routes` r ON r.`id` = s.`route_id`
   SET s.`fare` = 2000.00
 WHERE s.`fare` IS NULL
   AND s.`stop_type` = 'boarding'
   AND r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj', 'kohalpur')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj', 'kohalpur')
   AND (LOWER(s.`stop_name`) LIKE '%emli%'
     OR LOWER(s.`stop_name`) LIKE '%bhupal%'
     OR LOWER(s.`stop_name`) LIKE '%bhopal%'
     OR LOWER(s.`stop_name`) LIKE '%chiloda%'
     OR LOWER(s.`stop_name`) LIKE '%hari parking%'
     OR LOWER(s.`stop_name`) LIKE '%ahmedabad%');

-- 3b. Every other Gujarat pickup on those routes = 2200.
UPDATE `route_stops` s
  JOIN `routes` r ON r.`id` = s.`route_id`
   SET s.`fare` = 2200.00
 WHERE s.`fare` IS NULL
   AND s.`stop_type` = 'boarding'
   AND r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj', 'kohalpur')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj', 'kohalpur');

-- 5. The Rajkot desk on the counter list and the printed counters line.
UPDATE `settings`
   SET `svalue` = CONCAT(TRIM(TRAILING '\n' FROM COALESCE(`svalue`, '')), '\nRJT|Rajkot - Office +91 94268 27507')
 WHERE `skey` = 'counter_locations'
   AND COALESCE(`svalue`, '') NOT LIKE '%RJT|%';

UPDATE `settings`
   SET `svalue` = CONCAT(COALESCE(`svalue`, ''), ' · Rajkot +91 94268 27507')
 WHERE `skey` = 'company_counters'
   AND COALESCE(`svalue`, '') <> ''
   AND LOWER(`svalue`) NOT LIKE '%rajkot%';

SELECT r.`route_code`, s.`stop_type`, s.`sort_order`, s.`stop_name`, s.`stop_time`, s.`fare`, s.`is_active`
  FROM `routes` r
  JOIN `route_stops` s ON s.`route_id` = r.`id`
 WHERE r.`is_active` = 1
 ORDER BY r.`id`, s.`stop_type`, s.`sort_order`;
