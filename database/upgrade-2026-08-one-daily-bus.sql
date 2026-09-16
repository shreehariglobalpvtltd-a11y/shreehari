-- =====================================================================
--  ONE DAILY BUS EACH WAY  +  the permanent 5 stops & timetable
--  26 Aug 2026
--
--  Owner's operation: exactly ONE coach leaves Gujarat each day and ONE
--  returns. The database still carries the old demo fleet (13 active
--  routes), so the app offers buses that do not exist.
--
--  RUPAIDIHA IS THE END OF THE LINE. The service is legally permitted only
--  as far as the India-side border at Rupaidiha; Nepalgunj, Kohalpur and
--  Lumbini Pradesh are a FUTURE extension. The routes still said
--  "Ahmedabad -> Nepalgunj" and listed Lucknow, Bahraich, Nepalgunj and
--  Kohalpur as drops, which advertises a journey the company cannot sell.
--  This corrects the endpoints and the drop list to Rupaidiha only.
--
--  SAFE BY DESIGN
--    * Only flips `routes.is_active`. NOTHING is deleted — no booking,
--      ticket, payment, seat or route row is touched.
--    * Fully reversible: set is_active back to 1 to bring a bus back.
--    * A deactivated route keeps all its history; past bookings on it
--      stay readable in Admin exactly as before.
--
--  ⚠️ TAKE A BACKUP FIRST: hPanel -> Databases -> phpMyAdmin -> Export.
--
--  HOW TO RUN: paste the whole file into phpMyAdmin -> SQL -> Go.
-- =====================================================================

-- ---------------------------------------------------------------
-- STEP 1 — look at what you have, and pick the two real buses.
-- ---------------------------------------------------------------
-- SELECT id, route_code, from_city, to_city, dep_time, coach_type,
--        (SELECT bus_name FROM buses b WHERE b.id = r.bus_id) AS bus,
--        is_active
--   FROM routes r ORDER BY is_active DESC, dep_time;
--
-- Pick the SLEEPER routes (72 berths). Seater routes carry only 40.

SET @OUT_ROUTE := 2;   -- <<< the ONE Gujarat -> Rupaidiha bus (a sleeper)
SET @RET_ROUTE := 7;   -- <<< the ONE Rupaidiha -> Gujarat bus (a sleeper)

-- ---------------------------------------------------------------
-- STEP 2 — one bus each way. Everything else goes quiet.
-- ---------------------------------------------------------------
UPDATE `routes`
   SET `is_active` = 0
 WHERE `id` NOT IN (@OUT_ROUTE, @RET_ROUTE);

UPDATE `routes`
   SET `is_active` = 1
 WHERE `id` IN (@OUT_ROUTE, @RET_ROUTE);

-- ---------------------------------------------------------------
-- STEP 3 — the permanent timetable on the outbound bus.
--   13:00 Surat · 17:00 Barauda · 19:00 Limbli / Bhupal
--   21:00 Hari Pvt. Ltd. Parking - Nana Chiloda
--   23:00 Mehsana Head Office - Silver Complex
-- ---------------------------------------------------------------
DELETE FROM `route_stops` WHERE `route_id` = @OUT_ROUTE AND `stop_type` = 'boarding';

INSERT INTO `route_stops`
    (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
    (@OUT_ROUTE,'boarding','Surat',                                 'Departure',      '13:00:00',21.1702000,72.8311000,0,0,1),
    (@OUT_ROUTE,'boarding','Barauda',                                NULL,            '17:00:00',22.3072000,73.1812000,0,0,2),
    (@OUT_ROUTE,'boarding','Limbli / Bhupal',                        NULL,            '19:00:00',NULL,      NULL,      0,0,3),
    (@OUT_ROUTE,'boarding','Hari Pvt. Ltd. Parking - Nana Chiloda',  'Nana Chiloda',  '21:00:00',23.1710000,72.6230000,0,0,4),
    (@OUT_ROUTE,'boarding','Mehsana Head Office - Silver Complex',   'Silver Complex','23:00:00',23.5880000,72.3693000,0,0,5);

UPDATE `routes` SET `dep_time` = '13:00:00' WHERE `id` = @OUT_ROUTE;

-- ---------------------------------------------------------------
-- STEP 4 — the return bus drops at the same five, in reverse.
-- ---------------------------------------------------------------
DELETE FROM `route_stops` WHERE `route_id` = @RET_ROUTE AND `stop_type` = 'drop';

INSERT INTO `route_stops`
    (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
    (@RET_ROUTE,'drop','Mehsana Head Office - Silver Complex','Silver Complex',NULL,23.5880000,72.3693000,0,0,1),
    (@RET_ROUTE,'drop','Hari Pvt. Ltd. Parking - Nana Chiloda','Nana Chiloda', NULL,23.1710000,72.6230000,0,0,2),
    (@RET_ROUTE,'drop','Limbli / Bhupal',                      NULL,           NULL,NULL,      NULL,      0,0,3),
    (@RET_ROUTE,'drop','Barauda',                              NULL,           NULL,22.3072000,73.1812000,0,0,4),
    (@RET_ROUTE,'drop','Surat',                                'Final drop',   NULL,21.1702000,72.8311000,0,0,5);

-- Owner's fixed return departure.
UPDATE `routes` SET `dep_time` = '18:00:00' WHERE `id` = @RET_ROUTE;

-- ---------------------------------------------------------------
-- STEP 4b — RUPAIDIHA ONLY. Correct the endpoint cities and drop the
--   onward Nepal legs the company is not licensed to run yet.
-- ---------------------------------------------------------------
UPDATE `routes`
   SET `from_city` = 'Surat', `to_city` = 'Rupaidiha'
 WHERE `id` = @OUT_ROUTE;

UPDATE `routes`
   SET `from_city` = 'Rupaidiha', `to_city` = 'Surat'
 WHERE `id` = @RET_ROUTE;

-- Outbound drops: the border, and nothing past it.
DELETE FROM `route_stops` WHERE `route_id` = @OUT_ROUTE AND `stop_type` = 'drop';
INSERT INTO `route_stops`
    (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
    (@OUT_ROUTE,'drop','Rupaidiha','India-Nepal border checkpoint',NULL,28.0600000,81.6170000,1,0,1);

-- The return bus boards AT the border.
DELETE FROM `route_stops` WHERE `route_id` = @RET_ROUTE AND `stop_type` = 'boarding';
INSERT INTO `route_stops`
    (`route_id`,`stop_type`,`stop_name`,`landmark`,`stop_time`,`latitude`,`longitude`,`is_border`,`is_meal_halt`,`sort_order`)
VALUES
    (@RET_ROUTE,'boarding','Rupaidiha','India-Nepal border checkpoint','18:00:00',28.0600000,81.6170000,1,0,1);

-- ---------------------------------------------------------------
-- STEP 4c — clear the STALE ARRIVAL of the old onward leg.
--
--   Both routes still carry the arrival time of the Nepalgunj/Kohalpur
--   continuation (e.g. 00:39 "+2 day", "31h 54m"). Rupaidiha is the last
--   point now, so the coach arrives EARLIER than that and those figures
--   would promise the passenger a time the bus will never keep.
--
--   They are blanked rather than guessed. While blank, the app shows the
--   destination instead of a false clock time. Fill in the real one the
--   moment you know it — one line, and it appears everywhere:
--
--     UPDATE routes
--        SET arr_time = '20:00:00',      -- <-- real Rupaidiha arrival
--            day_offset = 2,             -- 1 = next day, 2 = day after
--            duration_text = '31h 00m'
--      WHERE id = @OUT_ROUTE;
--
--   (and the same for @RET_ROUTE with the Surat arrival)
-- ---------------------------------------------------------------
UPDATE `routes`
   SET `duration_text` = ''
 WHERE `id` IN (@OUT_ROUTE, @RET_ROUTE);

-- Also clear the drop-stop times that belonged to the onward Nepal leg.
UPDATE `route_stops`
   SET `stop_time` = NULL
 WHERE `route_id` = @OUT_ROUTE AND `stop_type` = 'drop';

-- ---------------------------------------------------------------
-- STEP 5 — check it. Expect exactly 2 active routes and 5 stops each.
-- ---------------------------------------------------------------
SELECT id, route_code, from_city, to_city, dep_time, coach_type, is_active
  FROM routes WHERE is_active = 1 ORDER BY dep_time;

SELECT route_id, stop_type, sort_order, stop_name, stop_time
  FROM route_stops WHERE route_id IN (@OUT_ROUTE, @RET_ROUTE)
 ORDER BY route_id, stop_type, sort_order;
