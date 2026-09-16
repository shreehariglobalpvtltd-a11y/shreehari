-- =====================================================================
--  Upgrade 2026-08 — three single-origin launch buses (2 Sep 2026)
--
--  Adds three straight-leg buses from Gujarat to the India–Nepal border
--  at Rupaidiha, one boarding town each:
--      r11  Mehsana     → Rupaidiha   (inaugural 2 Sep)
--      r12  Godhra      → Rupaidiha   (inaugural 3 Sep)
--      r13  Himatnagar  → Rupaidiha   (inaugural 4 Sep)
--
--  These route_codes MUST match the r11–r13 objects in
--  assets/js/02-config.js seedRoutes(): the client shows the bus from the
--  seed, then resolves the real numeric route id through /api/search.php by
--  route_code before booking. If a code is missing here, that bus shows but
--  cannot be booked.
--
--  Single-leg model: exactly ONE boarding stop (the origin) and ONE drop
--  (the border). Every seat rides the whole way — no per-segment resale.
--
--  Idempotent: routes upsert on the UNIQUE route_code; stops are wiped and
--  re-inserted for just these three routes, so re-running is harmless.
--
--  Run ONCE on the live database (phpMyAdmin → SQL tab, or
--  `mysql shari_db < database/upgrade-2026-08-origin-buses.sql`).
--  Launch defaults for dep/arr time, fare and coach type — the owner can
--  change any of them per bus in Admin → Manage Routes afterwards.
-- =====================================================================

SET NAMES utf8mb4;

-- 1) The three routes (upsert on route_code).
INSERT INTO `routes`
  (`route_code`,`from_city`,`to_city`,`coach_type`,`path_id`,`dep_time`,`arr_time`,
   `day_offset`,`duration_text`,`base_fare`,`amenities`,`is_active`,`sort_order`)
VALUES
  ('r11','Mehsana','Rupaidiha','sleeper','via_gorakhpur','15:00:00','20:00:00',
     1,'~29h',1800.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle","Border Assistance"]',1,11),
  ('r12','Godhra','Rupaidiha','sleeper','via_gorakhpur','15:00:00','20:00:00',
     1,'~29h',1800.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle","Border Assistance"]',1,12),
  ('r13','Himatnagar','Rupaidiha','sleeper','via_gorakhpur','15:00:00','20:00:00',
     1,'~29h',1800.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle","Border Assistance"]',1,13)
ON DUPLICATE KEY UPDATE
  `from_city`     = VALUES(`from_city`),
  `to_city`       = VALUES(`to_city`),
  `coach_type`    = VALUES(`coach_type`),
  `path_id`       = VALUES(`path_id`),
  `dep_time`      = VALUES(`dep_time`),
  `arr_time`      = VALUES(`arr_time`),
  `day_offset`    = VALUES(`day_offset`),
  `duration_text` = VALUES(`duration_text`),
  `base_fare`     = VALUES(`base_fare`),
  `amenities`     = VALUES(`amenities`),
  `is_active`     = 1,
  `sort_order`    = VALUES(`sort_order`);

-- 2) Rebuild the boarding + drop stops for exactly these three routes.
DELETE FROM `route_stops`
 WHERE `route_id` IN (SELECT `id` FROM `routes` WHERE `route_code` IN ('r11','r12','r13'));

-- Boarding stop (one per route — the origin town), timed at departure.
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`,'boarding','Mehsana — SHG Head Office','Sliver Complex, Near Shilpa Garage',
       '15:00:00',23.5880000,72.3690000,0,0,1
  FROM `routes` r WHERE r.`route_code` = 'r11';
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`,'boarding','Godhra — SHG Counter','Bus Stand',
       '15:00:00',22.7788000,73.6143000,0,0,1
  FROM `routes` r WHERE r.`route_code` = 'r12';
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`,'boarding','Himatnagar — SHG Counter','Bus Stand',
       '15:00:00',23.5980000,72.9630000,0,0,1
  FROM `routes` r WHERE r.`route_code` = 'r13';

-- Drop stop (one per route — the Rupaidiha border), timed at arrival.
INSERT INTO `route_stops`
  (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
SELECT r.`id`,'drop','Rupaidiha — India / Nepal Border','SSB Checkpoint',
       '20:00:00',28.0600000,81.6170000,1,0,1
  FROM `routes` r WHERE r.`route_code` IN ('r11','r12','r13');
