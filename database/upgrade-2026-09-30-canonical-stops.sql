-- =====================================================================
--  Sync route_stops to the 7 canonical boarding towns  (30 Sep 2026)
--
--  The front-end picker (CONFIG.mainPoints.india / seedRoutes()) lists
--  7 Gujarat boarding towns matching the company banner:
--      Surat, Kamrej, Ankeshwar, Bharuch, Vadodara, Nadiad,
--      Chiloda (AMD)
--
--  Earlier migrations wrote 4–9 stops per route with different names.
--  This migration rewrites them to the 7-town list.
--
--  SAFE BY DESIGN
--    * Idempotent — re-running changes nothing further.
--    * Targets only boarding stops on outbound Gujarat routes and drop
--      stops on return routes. Border stops (Rupaidiha) are untouched.
--    * No booking, ticket, payment, schedule or route row is affected.
--
--  ⚠️ TAKE A DATABASE BACKUP BEFORE RUNNING.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  OUTBOUND routes: Gujarat → Rupaidiha
--  Replace boarding stops with the 7 canonical towns.
-- =====================================================================

DELETE FROM `route_stops`
 WHERE `stop_type` = 'boarding'
   AND `route_id` IN (
     SELECT `id` FROM `routes`
      WHERE `is_active` = 1
        AND LOWER(`to_city`) IN ('rupaidiha', 'nepalgunj')
        AND LOWER(`from_city`) NOT IN ('rupaidiha', 'nepalgunj')
   );

INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'boarding', s.`stop_name`, s.`landmark`,
       ADDTIME(r.`dep_time`, s.`offset_from_dep`),
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'Surat'              AS stop_name, 'Bus stand / designated point'  AS landmark, '00:00:00' AS offset_from_dep, 21.1702000 AS lat, 72.8311000 AS lng, 1 AS ord
    UNION ALL SELECT 'Kamrej',               'Shiv Shakti Hotel',            '00:30:00', 21.2729662, 72.9555969, 2
    UNION ALL SELECT 'Ankeshwar',            'Ada Bridge',                   '02:00:00', NULL,       NULL,       3
    UNION ALL SELECT 'Bharuch',              'Somnath Mahadev Mandir',       '03:00:00', NULL,       NULL,       4
    UNION ALL SELECT 'Vadodara',             'Golden Chokdi',                '04:00:00', 22.3072000, 73.1812000, 5
    UNION ALL SELECT 'Nadiad',               'Nadiad Bridge - under bridge', '06:00:00', NULL,       NULL,       6
    UNION ALL SELECT 'Chiloda (AMD)',        'S Hari Parking, Nana Chiloda', '08:00:00', 23.1710000, 72.6230000, 7
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj');


-- =====================================================================
--  RETURN routes: Rupaidiha → Gujarat
--  Replace drop stops with the 7 canonical towns in reverse order.
--  Border boarding (Rupaidiha) is untouched.
-- =====================================================================

DELETE FROM `route_stops`
 WHERE `stop_type` = 'drop'
   AND `route_id` IN (
     SELECT `id` FROM `routes`
      WHERE `is_active` = 1
        AND LOWER(`from_city`) IN ('rupaidiha', 'nepalgunj')
        AND LOWER(`to_city`) NOT IN ('rupaidiha', 'nepalgunj')
   );

INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'drop', s.`stop_name`, s.`landmark`,
       NULL,
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'Chiloda (AMD)'      AS stop_name, 'S Hari Parking, Nana Chiloda' AS landmark, 23.1710000 AS lat, 72.6230000 AS lng, 1 AS ord
    UNION ALL SELECT 'Nadiad',               'Nadiad Bridge - under bridge', NULL,       NULL,       2
    UNION ALL SELECT 'Vadodara',             'Golden Chokdi',                22.3072000, 73.1812000, 3
    UNION ALL SELECT 'Bharuch',              'Somnath Mahadev Mandir',       NULL,       NULL,       4
    UNION ALL SELECT 'Ankeshwar',            'Ada Bridge',                   NULL,       NULL,       5
    UNION ALL SELECT 'Kamrej',               'Shiv Shakti Hotel',            21.2729662, 72.9555969, 6
    UNION ALL SELECT 'Surat',               'Bus stand / final drop',       21.1702000, 72.8311000, 7
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`from_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`to_city`) NOT IN ('rupaidiha', 'nepalgunj');


-- =====================================================================
--  VERIFY — should show 7 boarding and/or 7 drop stops per route.
-- =====================================================================
SELECT r.id, r.route_code, r.from_city, r.to_city,
       rs.stop_type, rs.sort_order, rs.stop_name, rs.stop_time
  FROM routes r
  JOIN route_stops rs ON rs.route_id = r.id
 WHERE r.is_active = 1
   AND (
     (LOWER(r.to_city) IN ('rupaidiha','nepalgunj') AND LOWER(r.from_city) NOT IN ('rupaidiha','nepalgunj'))
     OR
     (LOWER(r.from_city) IN ('rupaidiha','nepalgunj') AND LOWER(r.to_city) NOT IN ('rupaidiha','nepalgunj'))
   )
 ORDER BY r.id, rs.stop_type DESC, rs.sort_order;
