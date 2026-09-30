-- =====================================================================
--  Restore the owner's 9-stop timetable poster (30 Sep 2026)
--
--  upgrade-2026-09-30-canonical-stops.sql cut the Gujarat side to 7
--  towns and dropped Anand and Emli Bhupal. The owner wants the poster
--  back exactly (Emli Bhupal included):
--      Surat 13:00 · Kamrej 13:30 · Ankleshwar 15:00 · Bharuch 16:00 ·
--      Vadodara 17:00 · Anand 18:30 · Nadiad 20:00 · Emli Bhupal 21:00 ·
--      S Hari Parking, Nana Chiloda (Amd) 23:00
--  Same names as assets/js/02-config.js CONFIG.mainPoints.india and
--  upgrade-2026-09-19-timetable.sql.
--
--  SAFE BY DESIGN
--    * Idempotent — re-running writes the same rows again.
--    * Only boarding stops of active outbound Gujarat routes and drop
--      stops of active return routes are rewritten. Rupaidiha is untouched.
--    * No booking, ticket, payment, schedule or route row is touched;
--      booking_legs keep the stop text they were sold with.
--
--  ⚠️ TAKE A DATABASE BACKUP BEFORE RUNNING (the deploy workflow does).
-- =====================================================================

SET NAMES utf8mb4;

DELETE FROM `route_stops`
 WHERE `stop_type` = 'boarding'
   AND `route_id` IN (
     SELECT `id` FROM (
       SELECT `id` FROM `routes`
        WHERE `is_active` = 1
          AND LOWER(`to_city`) IN ('rupaidiha', 'nepalgunj')
          AND LOWER(`from_city`) NOT IN ('rupaidiha', 'nepalgunj')
     ) AS t
   );

INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'boarding', s.`stop_name`, s.`landmark`,
       ADDTIME(r.`dep_time`, s.`offset_from_dep`),
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'Surat'                              AS stop_name, 'Bus stand / designated point' AS landmark, '00:00:00' AS offset_from_dep, 21.1702000 AS lat, 72.8311000 AS lng, 1 AS ord
    UNION ALL SELECT 'Kamrej',                             'Shiv Shakti Hotel',            '00:30:00', 21.2729662, 72.9555969, 2
    UNION ALL SELECT 'Ankleshwar',                         'Ada Bridge',                   '02:00:00', NULL,       NULL,       3
    UNION ALL SELECT 'Bharuch',                            'Somnath Mahadev Mandir',       '03:00:00', NULL,       NULL,       4
    UNION ALL SELECT 'Vadodara',                           'Golden Chokdi',                '04:00:00', 22.3072000, 73.1812000, 5
    UNION ALL SELECT 'Anand',                              'Pipal Chautra',                '05:30:00', NULL,       NULL,       6
    UNION ALL SELECT 'Nadiad',                             'Nadiad Bridge - under bridge', '07:00:00', NULL,       NULL,       7
    UNION ALL SELECT 'Emli Bhupal',                        'Taj Hotel',                    '08:00:00', NULL,       NULL,       8
    UNION ALL SELECT 'S Hari Parking, Nana Chiloda (Amd)', NULL,                           '10:00:00', 23.1710000, 72.6230000, 9
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj');

DELETE FROM `route_stops`
 WHERE `stop_type` = 'drop'
   AND `route_id` IN (
     SELECT `id` FROM (
       SELECT `id` FROM `routes`
        WHERE `is_active` = 1
          AND LOWER(`from_city`) IN ('rupaidiha', 'nepalgunj')
          AND LOWER(`to_city`) NOT IN ('rupaidiha', 'nepalgunj')
     ) AS t
   );

INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'drop', s.`stop_name`, s.`landmark`, NULL,
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'S Hari Parking, Nana Chiloda (Amd)' AS stop_name, NULL AS landmark, 23.1710000 AS lat, 72.6230000 AS lng, 1 AS ord
    UNION ALL SELECT 'Emli Bhupal',                        'Taj Hotel',                    NULL,       NULL,       2
    UNION ALL SELECT 'Nadiad',                             'Nadiad Bridge - under bridge', NULL,       NULL,       3
    UNION ALL SELECT 'Anand',                              'Pipal Chautra',                NULL,       NULL,       4
    UNION ALL SELECT 'Vadodara',                           'Golden Chokdi',                22.3072000, 73.1812000, 5
    UNION ALL SELECT 'Bharuch',                            'Somnath Mahadev Mandir',       NULL,       NULL,       6
    UNION ALL SELECT 'Ankleshwar',                         'Ada Bridge',                   NULL,       NULL,       7
    UNION ALL SELECT 'Kamrej',                             'Shiv Shakti Hotel',            21.2729662, 72.9555969, 8
    UNION ALL SELECT 'Surat',                              'Bus stand / final drop',       21.1702000, 72.8311000, 9
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`from_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`to_city`) NOT IN ('rupaidiha', 'nepalgunj');

SELECT r.id, r.route_code, r.from_city, r.to_city, r.dep_time,
       rs.stop_type, rs.sort_order, rs.stop_name, rs.stop_time
  FROM routes r
  JOIN route_stops rs ON rs.route_id = r.id
 WHERE r.is_active = 1
 ORDER BY r.id, rs.stop_type DESC, rs.sort_order;
