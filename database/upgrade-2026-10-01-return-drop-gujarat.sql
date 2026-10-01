-- =====================================================================
--  Return trips drop at "Gujarat" only  (1 Oct 2026)
--
--  Owner ask: "Rupaidiha dekhi only Gujarat haldeu — jaha jharne manually
--  jharchhan, thau ko name nalekhne." On the Rupaidiha -> Gujarat coach the
--  passenger tells the crew where to get off; the booking and the ticket
--  no longer name a town. Every active return route (from Rupaidiha /
--  Nepalgunj) gets ONE drop stop, "Gujarat".
--
--  SAFE BY DESIGN
--    * Idempotent — a route that already has only "Gujarat" is untouched.
--    * Only drop stops of active return routes change. Boarding stops,
--      bookings, tickets and schedules are not touched; old bookings keep
--      the drop text they were sold with.
--    * Search still finds "Rupaidiha -> Surat": api/search.php matches the
--      route's own to_city before it looks at stops.
--
--  TAKE A DATABASE BACKUP BEFORE RUNNING.
-- =====================================================================

SET NAMES utf8mb4;

DELETE FROM `route_stops`
 WHERE `stop_type` = 'drop'
   AND `stop_name` <> 'Gujarat'
   AND `route_id` IN (
     SELECT `id` FROM (
       SELECT `id` FROM `routes`
        WHERE `is_active` = 1
          AND LOWER(`from_city`) IN ('rupaidiha', 'nepalgunj')
     ) AS r
   );

INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'drop', 'Gujarat', NULL, NULL, NULL, NULL, 0, 0, 1
  FROM `routes` r
 WHERE r.`is_active` = 1
   AND LOWER(r.`from_city`) IN ('rupaidiha', 'nepalgunj')
   AND NOT EXISTS (
     SELECT 1 FROM `route_stops` s
      WHERE s.`route_id` = r.`id` AND s.`stop_type` = 'drop' AND s.`stop_name` = 'Gujarat'
   );
