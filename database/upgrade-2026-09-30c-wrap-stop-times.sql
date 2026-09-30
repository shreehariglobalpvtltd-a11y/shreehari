-- =====================================================================
--  Wrap boarding times past midnight (30 Sep 2026)
--
--  upgrade-2026-09-30b-restore-poster-stops.sql adds each stop's offset
--  to the route's dep_time. On the routes that leave at 15:00 (r11-r13)
--  the last stop landed on 25:00:00, which is not a clock time. A stop
--  after midnight is written as the clock time of the next day (01:00).
--  Idempotent: a second run finds nothing >= 24:00:00.
-- =====================================================================
SET NAMES utf8mb4;

UPDATE `route_stops`
   SET `stop_time` = SUBTIME(`stop_time`, '24:00:00')
 WHERE `stop_time` >= '24:00:00';

SELECT r.id, r.route_code, r.from_city, r.to_city, r.dep_time,
       rs.stop_type, rs.sort_order, rs.stop_name, rs.stop_time
  FROM routes r
  JOIN route_stops rs ON rs.route_id = r.id
 WHERE r.is_active = 1
 ORDER BY r.id, rs.stop_type DESC, rs.sort_order;
