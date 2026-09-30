-- =====================================================================
--  Sync route_stops to the 9 canonical boarding towns  (30 Sep 2026)
--
--  The front-end picker (CONFIG.mainPoints.india / seedRoutes()) lists
--  9 Gujarat boarding towns:
--      Surat, Kamrej, Ankleshwar, Bharuch, Vadodara, Anand, Nadiad,
--      Emli Bhupal, S Hari Parking Nana Chiloda
--
--  Earlier migrations wrote only 4–5 stops per outbound route, often with
--  different compound names (Barauda, Limbli / Bhupal, Hari Pvt. Ltd.
--  Parking - Nana Chiloda, Mehsana Head Office - Silver Complex). The
--  town-picker intersects CONFIG with server stops through townKeyJS(),
--  but "Barauda" ≠ "Vadodara" and "Limbli / Bhupal" ≠ "Emli Bhupal",
--  so the picker shrank to 1–3 towns instead of showing all 9.
--
--  This migration rewrites the boarding stops for the OUTBOUND Gujarat →
--  Rupaidiha route(s) to the full 9-town list, and adds them as drop
--  stops on RETURN Rupaidiha → Gujarat route(s) in reverse order.
--
--  SAFE BY DESIGN
--    * Idempotent — re-running changes nothing further.
--    * Targets only boarding stops on outbound Gujarat routes and drop
--      stops on return routes. Border stops (Rupaidiha) are untouched.
--    * No booking, ticket, payment, schedule or route row is affected.
--
--  ⚠️ TAKE A DATABASE BACKUP BEFORE RUNNING.
--
--  HOW TO USE
--    1. The migration auto-detects outbound/return route ids.
--    2. Paste the whole file into phpMyAdmin → SQL → Go.
--    3. Re-run the verification SELECT at the bottom.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  OUTBOUND routes: Gujarat → Rupaidiha
--  Replace boarding stops with the 9 canonical towns.
--  Finds every active route whose to_city is Rupaidiha and from_city
--  is NOT Rupaidiha (i.e. outbound from Gujarat).
-- =====================================================================

-- Delete existing boarding stops for outbound routes.
DELETE FROM `route_stops`
 WHERE `stop_type` = 'boarding'
   AND `route_id` IN (
     SELECT `id` FROM `routes`
      WHERE `is_active` = 1
        AND LOWER(`to_city`) IN ('rupaidiha', 'nepalgunj')
        AND LOWER(`from_city`) NOT IN ('rupaidiha', 'nepalgunj')
   );

-- Insert the 9 canonical boarding stops for each outbound route.
-- Times are based on the Surat 13:00 departure; they can be adjusted
-- per route in Admin → Manage Routes afterwards.
INSERT INTO `route_stops`
  (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`,
   `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT r.`id`, 'boarding', s.`stop_name`, s.`landmark`,
       ADDTIME(r.`dep_time`, s.`offset_from_dep`),
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'Surat'                          AS stop_name, 'Bus stand / designated point'  AS landmark, '00:00:00' AS offset_from_dep, 21.1702000 AS lat, 72.8311000 AS lng, 1 AS ord
    UNION ALL SELECT 'Kamrej',               'Shiv Shakti Hotel',            '00:30:00', 21.2729662, 72.9555969, 2
    UNION ALL SELECT 'Ankleshwar',           'Ada Bridge',                   '02:00:00', NULL,       NULL,       3
    UNION ALL SELECT 'Bharuch',              'Somnath Mahadev Mandir',       '03:00:00', NULL,       NULL,       4
    UNION ALL SELECT 'Vadodara',             'Golden Chokdi',                '04:00:00', 22.3072000, 73.1812000, 5
    UNION ALL SELECT 'Anand',                'Pipal Chautra',                '05:30:00', NULL,       NULL,       6
    UNION ALL SELECT 'Nadiad',               'Nadiad Bridge - under bridge', '07:00:00', NULL,       NULL,       7
    UNION ALL SELECT 'Emli Bhupal',          'Taj Hotel',                    '08:00:00', NULL,       NULL,       8
    UNION ALL SELECT 'S Hari Parking, Nana Chiloda', 'Nana Chiloda (Amd)',   '10:00:00', 23.1710000, 72.6230000, 9
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`to_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`from_city`) NOT IN ('rupaidiha', 'nepalgunj');


-- =====================================================================
--  RETURN routes: Rupaidiha → Gujarat
--  Replace drop stops with the 9 canonical towns in reverse order.
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
       NULL,  -- drop times vary; fill in Admin → Manage Routes
       s.`lat`, s.`lng`, 0, 0, s.`ord`
  FROM `routes` r
  CROSS JOIN (
    SELECT 'S Hari Parking, Nana Chiloda' AS stop_name, 'Nana Chiloda (Amd)' AS landmark, 23.1710000 AS lat, 72.6230000 AS lng, 1 AS ord
    UNION ALL SELECT 'Emli Bhupal',          'Taj Hotel',                    NULL,       NULL,       2
    UNION ALL SELECT 'Nadiad',               'Nadiad Bridge - under bridge', NULL,       NULL,       3
    UNION ALL SELECT 'Anand',                'Pipal Chautra',                NULL,       NULL,       4
    UNION ALL SELECT 'Vadodara',             'Golden Chokdi',                22.3072000, 73.1812000, 5
    UNION ALL SELECT 'Bharuch',              'Somnath Mahadev Mandir',       NULL,       NULL,       6
    UNION ALL SELECT 'Ankleshwar',           'Ada Bridge',                   NULL,       NULL,       7
    UNION ALL SELECT 'Kamrej',               'Shiv Shakti Hotel',            21.2729662, 72.9555969, 8
    UNION ALL SELECT 'Surat',                'Bus stand / final drop',       21.1702000, 72.8311000, 9
  ) AS s
 WHERE r.`is_active` = 1
   AND LOWER(r.`from_city`) IN ('rupaidiha', 'nepalgunj')
   AND LOWER(r.`to_city`) NOT IN ('rupaidiha', 'nepalgunj');


-- =====================================================================
--  VERIFY — should show 9 boarding and/or 9 drop stops per route.
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
