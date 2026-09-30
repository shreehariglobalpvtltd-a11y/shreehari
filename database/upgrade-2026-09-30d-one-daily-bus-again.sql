-- =====================================================================
--  One daily bus again (30 Sep 2026)
--
--  Owner: "exactly one bus a day each way, as it was from the start —
--  keep that one as the default". The three single-origin launch buses
--  (r11 Mehsana, r12 Godhra, r13 Himatnagar -> Rupaidiha) were still
--  active, and the 30 Sep stop migrations had given them the Surat stop
--  list by mistake.
--
--  1. Put r11-r13 back to their own single boarding town (as in
--     upgrade-2026-08-origin-buses.sql), so their history reads right.
--  2. Switch r11-r13 off — but NOT a route that still has a live booking
--     for today or later; that one stays on so no passenger is stranded,
--     and the office can switch it off in Admin -> Manage Routes after
--     the trip.
--  Nothing is deleted: no booking, ticket, payment or seat row changes.
--  Idempotent and reversible (set is_active = 1 to bring a bus back).
-- =====================================================================
SET NAMES utf8mb4;

DELETE FROM `route_stops`
 WHERE `stop_type` = 'boarding'
   AND `route_id` IN (SELECT `id` FROM (SELECT `id` FROM `routes` WHERE `route_code` IN ('r11','r12','r13')) AS t);

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

UPDATE `routes` r
   SET r.`is_active` = 0
 WHERE r.`route_code` IN ('r11','r12','r13')
   AND NOT EXISTS (
     SELECT 1
       FROM `booking_legs` bl
       JOIN `schedules` s ON s.`id` = bl.`schedule_id`
       JOIN `bookings` b  ON b.`id` = bl.`booking_id`
      WHERE s.`route_id` = r.`id`
        AND bl.`travel_date` >= CURDATE()
        AND b.`status` IN ('pending','confirmed')
   );

-- Report: which buses are on sale now, and any r11-r13 kept on for passengers.
SELECT r.id, r.route_code, r.from_city, r.to_city, r.dep_time, r.is_active,
       (SELECT COUNT(*) FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id
          JOIN bookings b ON b.id = bl.booking_id
         WHERE s.route_id = r.id AND bl.travel_date >= CURDATE() AND b.status IN ('pending','confirmed')) AS upcoming_bookings
  FROM routes r
 WHERE r.is_active = 1 OR r.route_code IN ('r11','r12','r13')
 ORDER BY r.is_active DESC, r.sort_order, r.id;
