-- =====================================================================
--  Upgrade 2026-10-09 - Route facts: road km per stop + en-route halts
--  (owner ask #11 "kati km, kati thau rokinchha, khana-nasta kaha")
--
--  Run ONCE on the live database:
--      deploy.sh --migrate database/upgrade-2026-10-09-route-details.sql
--  or from the CLI:
--      php tests/apply-sql.php database/upgrade-2026-10-09-route-details.sql
--
--  WHAT THIS DOES
--    1. route_stops.km_from_origin DECIMAL(7,1) NULL - cumulative ROAD km
--       from the route's first stop. NULL = not measured. Admin -> Routes
--       edits it per stop, or fills it with "Measure road km" (our own
--       Valhalla engine, on press only).
--    2. route_halts - en-route halts that are not pickups or drops
--       (breakfast / lunch / dinner / tea / fuel / rest / border). A halt
--       is NEVER a route_stops row: the stop_type ENUM (boarding|drop) is
--       what search, the cut-off and the seat map sell from, and a third
--       value would sort first in EtaAlerts::routeLine.
--       offset_min = minutes after the route departure (a retimed bus moves
--       its halts with it), clock_time = the same moment on the timetable
--       clock (display only), is_approx = 1 -> shown with "approx".
--    3. Seeds km_from_origin for the stops of the Surat -> Rupaidiha and
--       Rupaidiha -> Surat routes by matching the stop NAME (measured on our
--       Valhalla engine, bus costing, 9 Oct 2026):
--         outbound  Surat 0, Kamrej 27, Ankleshwar 70, Bharuch 85,
--                   Vadodara 173, Anand 219, Nadiad 241,
--                   S Hari Parking Nana Chiloda 316, Rupaidiha 1688
--         return    Rupaidiha 0, Nana Chiloda 1372, Nadiad 1447, Anand 1469,
--                   Vadodara 1515, Bharuch 1603, Ankleshwar 1618,
--                   Kamrej 1661, Surat 1688
--       Emli Bhupal (location not surveyed), Rajkot (office pickup off the
--       main line) and any other stop stay NULL - never guessed.
--       Only NULL values are filled: a km the office typed or measured is
--       never overwritten.
--    4. Seeds the TYPICAL halt plan on those two routes, flagged approximate
--       (corridor town names only - the exact dhaba is chosen by the crew),
--       only for a route that has no halt rows yet:
--         Surat -> Rupaidiha (dep 13:00): tea ~03:00 near Udaipur 15 min,
--           breakfast ~08:00 near Kota 30, lunch ~13:30 near Jhansi 40,
--           tea ~17:30 near Kanpur 15, dinner ~20:00 near Lucknow 30
--         Rupaidiha -> Surat (dep 18:00): dinner ~21:30 near Lucknow 30,
--           tea ~03:00 near Jhansi 15, breakfast ~08:30 near Kota 30,
--           lunch ~13:30 near Udaipur 40, tea ~17:30 before Ahmedabad 15
--
--  NOT TOUCHED (deliberately)
--    * routes.distance_km (the SEO route pages read it as Ahmedabad ->
--      border, 1375) - the website shows both spans from the stop km.
--    * routes.arr_time / day_offset / duration_text - the arrival stays
--      hidden until the office confirms it in Admin -> Routes.
--    * stop_type values, latitude / longitude (ETA alerts read coordinates).
--    * No booking, leg, seat, ticket, payment or schedule row.
--
--  SAFE BY DESIGN
--    * Idempotent: guarded ALTER, CREATE TABLE IF NOT EXISTS, km seeds fill
--      NULLs only, halts are inserted only for a route without any halt.
--    * No semicolons inside strings or comments (tests/apply-sql.php splits
--      on them).
--
--  TAKE A DATABASE BACKUP BEFORE RUNNING (deploy.sh --migrate does).
-- =====================================================================

SET NAMES utf8mb4;

-- 1. route_stops.km_from_origin -----------------------------------------
SET @have := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'route_stops' AND COLUMN_NAME = 'km_from_origin');
SET @sql := IF(@have = 0,
  'ALTER TABLE `route_stops` ADD COLUMN `km_from_origin` DECIMAL(7,1) NULL DEFAULT NULL COMMENT ''cumulative road km from the route origin - NULL = not measured'' AFTER `longitude`',
  'SELECT ''route_stops.km_from_origin already present'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. route_halts ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `route_halts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_id`       BIGINT UNSIGNED NOT NULL,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `name`           VARCHAR(120) NOT NULL COMMENT 'corridor town, e.g. Kota',
  `kind`           ENUM('meal','breakfast','lunch','dinner','tea','fuel','rest','border') NOT NULL DEFAULT 'rest',
  `clock_time`     TIME NULL DEFAULT NULL COMMENT 'timetable clock of the halt - display only',
  `offset_min`     INT NULL DEFAULT NULL COMMENT 'minutes after the route departure - the truth for times',
  `halt_min`       SMALLINT UNSIGNED NOT NULL DEFAULT 15 COMMENT 'how long the bus stands',
  `km_from_origin` DECIMAL(7,1) NULL DEFAULT NULL COMMENT 'road km from the route origin',
  `landmark`       VARCHAR(191) NULL DEFAULT NULL,
  `note`           VARCHAR(255) NULL DEFAULT NULL,
  `is_approx`      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = typical plan, shown as approximate',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_halts_route` (`route_id`, `is_active`, `sort_order`),
  CONSTRAINT `fk_halts_route` FOREIGN KEY (`route_id`)
    REFERENCES `routes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3a. Stop km, Surat -> Rupaidiha (fills NULLs only) -----------------------
UPDATE `route_stops` rs
  JOIN `routes` r ON r.id = rs.route_id
   SET rs.km_from_origin = CASE
         WHEN LOWER(rs.stop_name) LIKE '%kamrej%'                                                  THEN 27.0
         WHEN LOWER(rs.stop_name) LIKE '%ankle%' OR LOWER(rs.stop_name) LIKE '%ankeshwar%'         THEN 70.0
         WHEN LOWER(rs.stop_name) LIKE '%bharuch%'                                                 THEN 85.0
         WHEN LOWER(rs.stop_name) LIKE '%vadodara%' OR LOWER(rs.stop_name) LIKE '%baroda%'
           OR LOWER(rs.stop_name) LIKE '%barauda%'                                                 THEN 173.0
         WHEN LOWER(rs.stop_name) LIKE '%anand%'                                                   THEN 219.0
         WHEN LOWER(rs.stop_name) LIKE '%nadiad%'                                                  THEN 241.0
         WHEN LOWER(rs.stop_name) LIKE '%chiloda%' OR LOWER(rs.stop_name) LIKE '%hari%parking%'    THEN 316.0
         WHEN LOWER(rs.stop_name) LIKE '%rupaidiha%'                                               THEN 1688.0
         WHEN LOWER(rs.stop_name) LIKE '%surat%'                                                   THEN 0.0
         ELSE NULL
       END
 WHERE rs.km_from_origin IS NULL
   AND LOWER(r.from_city) LIKE '%surat%'
   AND LOWER(r.to_city)   LIKE '%rupaidiha%';

-- 3b. Stop km, Rupaidiha -> Surat (fills NULLs only) -----------------------
UPDATE `route_stops` rs
  JOIN `routes` r ON r.id = rs.route_id
   SET rs.km_from_origin = CASE
         WHEN LOWER(rs.stop_name) LIKE '%rupaidiha%'                                               THEN 0.0
         WHEN LOWER(rs.stop_name) LIKE '%chiloda%' OR LOWER(rs.stop_name) LIKE '%hari%parking%'    THEN 1372.0
         WHEN LOWER(rs.stop_name) LIKE '%nadiad%'                                                  THEN 1447.0
         WHEN LOWER(rs.stop_name) LIKE '%anand%'                                                   THEN 1469.0
         WHEN LOWER(rs.stop_name) LIKE '%vadodara%' OR LOWER(rs.stop_name) LIKE '%baroda%'
           OR LOWER(rs.stop_name) LIKE '%barauda%'                                                 THEN 1515.0
         WHEN LOWER(rs.stop_name) LIKE '%bharuch%'                                                 THEN 1603.0
         WHEN LOWER(rs.stop_name) LIKE '%ankle%' OR LOWER(rs.stop_name) LIKE '%ankeshwar%'         THEN 1618.0
         WHEN LOWER(rs.stop_name) LIKE '%kamrej%'                                                  THEN 1661.0
         WHEN LOWER(rs.stop_name) LIKE '%surat%'                                                   THEN 1688.0
         ELSE NULL
       END
 WHERE rs.km_from_origin IS NULL
   AND LOWER(r.from_city) LIKE '%rupaidiha%'
   AND LOWER(r.to_city)   LIKE '%surat%';

-- 4a. Typical halts, Surat -> Rupaidiha (only for a route with no halt yet)
INSERT INTO `route_halts`
    (`route_id`, `sort_order`, `name`, `kind`, `clock_time`, `offset_min`, `halt_min`, `km_from_origin`, `landmark`, `note`, `is_approx`, `is_active`)
SELECT r.id, p.so, p.nm, p.kd, p.ck, p.om, p.hm, p.km, NULL, p.nt, 1, 1
  FROM `routes` r
  JOIN (
        SELECT 1 AS so, 'Udaipur' AS nm, 'tea'       AS kd, '03:00:00' AS ck,  840 AS om, 15 AS hm,  558.0 AS km, 'Night tea and toilet break - exact dhaba chosen by the crew' AS nt
        UNION ALL SELECT 2, 'Kota',    'breakfast', '08:00:00', 1140, 30,  841.0, 'Breakfast halt - exact hotel chosen by the crew, times vary with traffic'
        UNION ALL SELECT 3, 'Jhansi',  'lunch',     '13:30:00', 1470, 40, 1164.0, 'Lunch halt - exact hotel chosen by the crew, times vary with traffic'
        UNION ALL SELECT 4, 'Kanpur',  'tea',       '17:30:00', 1710, 15, 1393.0, 'Tea break - exact stop chosen by the crew'
        UNION ALL SELECT 5, 'Lucknow', 'dinner',    '20:00:00', 1860, 30, 1483.0, 'Dinner halt - exact hotel chosen by the crew, times vary with traffic'
       ) p
 WHERE LOWER(r.from_city) LIKE '%surat%'
   AND LOWER(r.to_city)   LIKE '%rupaidiha%'
   AND NOT EXISTS (SELECT 1 FROM `route_halts` h WHERE h.route_id = r.id);

-- 4b. Typical halts, Rupaidiha -> Surat (only for a route with no halt yet)
INSERT INTO `route_halts`
    (`route_id`, `sort_order`, `name`, `kind`, `clock_time`, `offset_min`, `halt_min`, `km_from_origin`, `landmark`, `note`, `is_approx`, `is_active`)
SELECT r.id, p.so, p.nm, p.kd, p.ck, p.om, p.hm, p.km, NULL, p.nt, 1, 1
  FROM `routes` r
  JOIN (
        SELECT 1 AS so, 'Lucknow' AS nm, 'dinner'    AS kd, '21:30:00' AS ck,  210 AS om, 30 AS hm,  205.0 AS km, 'Dinner halt - exact hotel chosen by the crew, times vary with traffic' AS nt
        UNION ALL SELECT 2, 'Jhansi',    'tea',       '03:00:00',  540, 15,  524.0, 'Night tea and toilet break - exact dhaba chosen by the crew'
        UNION ALL SELECT 3, 'Kota',      'breakfast', '08:30:00',  870, 30,  847.0, 'Breakfast halt - exact hotel chosen by the crew, times vary with traffic'
        UNION ALL SELECT 4, 'Udaipur',   'lunch',     '13:30:00', 1170, 40, 1130.0, 'Lunch halt - exact hotel chosen by the crew, times vary with traffic'
        UNION ALL SELECT 5, 'Ahmedabad', 'tea',       '17:30:00', 1410, 15,   NULL, 'Tea break before Ahmedabad - exact stop chosen by the crew'
       ) p
 WHERE LOWER(r.from_city) LIKE '%rupaidiha%'
   AND LOWER(r.to_city)   LIKE '%surat%'
   AND NOT EXISTS (SELECT 1 FROM `route_halts` h WHERE h.route_id = r.id);

-- 5. Check it --------------------------------------------------------------
SELECT r.route_code, r.from_city, r.to_city,
       (SELECT COUNT(*) FROM `route_stops` s WHERE s.route_id = r.id AND s.km_from_origin IS NOT NULL) AS stops_with_km,
       (SELECT COUNT(*) FROM `route_stops` s WHERE s.route_id = r.id AND s.km_from_origin IS NULL)     AS stops_without_km,
       (SELECT COUNT(*) FROM `route_halts` h WHERE h.route_id = r.id AND h.is_active = 1)            AS halts
  FROM `routes` r
 WHERE r.is_active = 1;
