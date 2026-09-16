-- =====================================================================
--  Canonical route stops + fixed daily timetable
--  Master prompt §2, §3, §28 — 26 Aug 2026
--
--  The five Gujarat-side stop names and their departure times are
--  PERMANENT. This makes the DATABASE the authoritative source for them
--  (§28) instead of the names being retyped in frontend files.
--
--  SAFE BY DESIGN:
--    * Idempotent — re-running it changes nothing further.
--    * NON-DESTRUCTIVE — it does not delete a single booking, ticket,
--      payment or route. Existing routes keep working exactly as they do.
--    * The only rows it rewrites are `route_stops` for the ONE outbound
--      route it is pointed at, plus that route's dep_time.
--
--  ⚠️ TAKE A DATABASE BACKUP BEFORE RUNNING (hPanel → Databases →
--     phpMyAdmin → Export). §29/§30.
--
--  HOW TO USE
--    1. Set @OUT_ROUTE below to the id of the Gujarat -> Rupaidiha route
--       this timetable belongs to (see the SELECT in step 0).
--    2. Run the whole file.
--    3. Re-run the verification SELECT at the bottom.
-- =====================================================================

-- ---- STEP 0: which routes exist? (run this first, then set @OUT_ROUTE)
-- SELECT id, route_code, from_city, to_city, dep_time, is_active FROM routes ORDER BY id;

SET @OUT_ROUTE := 1;    -- <<< the Gujarat -> Rupaidiha route id
SET @RET_ROUTE := 3;    -- <<< the Rupaidiha -> Gujarat route id (0 = skip)

-- =====================================================================
--  OUTBOUND — Gujarat -> Rupaidih.  Fixed daily timetable (§3):
--    13:00 Surat · 17:00 Barauda · 19:00 Limbli / Bhupal
--    21:00 Hari Pvt. Ltd. Parking - Nana Chiloda
--    23:00 Mehsana Head Office - Silver Complex
-- =====================================================================

-- Replace only this route's BOARDING stops. Drops are untouched.
DELETE FROM `route_stops` WHERE `route_id` = @OUT_ROUTE AND `stop_type` = 'boarding';

INSERT INTO `route_stops`
    (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`, `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
VALUES
    (@OUT_ROUTE, 'boarding', 'Surat',                                        'Departure',       '13:00:00', 21.1702000, 72.8311000, 0, 0, 1),
    (@OUT_ROUTE, 'boarding', 'Barauda',                                     NULL,              '17:00:00', 22.3072000, 73.1812000, 0, 0, 2),
    (@OUT_ROUTE, 'boarding', 'Limbli / Bhupal',                              NULL,              '19:00:00', NULL,       NULL,       0, 0, 3),
    (@OUT_ROUTE, 'boarding', 'Hari Pvt. Ltd. Parking - Nana Chiloda',        'Nana Chiloda',    '21:00:00', 23.1710000, 72.6230000, 0, 0, 4),
    (@OUT_ROUTE, 'boarding', 'Mehsana Head Office - Silver Complex',         'Silver Complex',  '23:00:00', 23.5880000, 72.3693000, 0, 0, 5);

-- The route's own departure time is the first stop's time.
UPDATE `routes` SET `dep_time` = '13:00:00' WHERE `id` = @OUT_ROUTE;

-- =====================================================================
--  RETURN — Rupaidih -> Gujarat.  Same five canonical Gujarat-side names
--  in reverse order as DROP points (§4: reverse direction of the same
--  operational route, NOT duplicate locations).
-- =====================================================================

DELETE FROM `route_stops` WHERE `route_id` = @RET_ROUTE AND `stop_type` = 'drop' AND @RET_ROUTE > 0;

INSERT INTO `route_stops`
    (`route_id`, `stop_type`, `stop_name`, `landmark`, `stop_time`, `latitude`, `longitude`, `is_border`, `is_meal_halt`, `sort_order`)
SELECT s.route_id, s.stop_type, s.stop_name, s.landmark, s.stop_time,
       s.latitude, s.longitude, s.is_border, s.is_meal_halt, s.sort_order
  FROM (
    -- Column aliases are required: an unaliased UNION derived table gives
    -- every literal the same generated name and MySQL rejects it (1060).
    SELECT @RET_ROUTE AS route_id, 'drop' AS stop_type,
           'Mehsana Head Office - Silver Complex' AS stop_name, 'Silver Complex' AS landmark,
           NULL AS stop_time, 23.5880000 AS latitude, 72.3693000 AS longitude,
           0 AS is_border, 0 AS is_meal_halt, 1 AS sort_order
    UNION ALL SELECT @RET_ROUTE, 'drop', 'Hari Pvt. Ltd. Parking - Nana Chiloda', 'Nana Chiloda', NULL, 23.1710000, 72.6230000, 0, 0, 2
    UNION ALL SELECT @RET_ROUTE, 'drop', 'Limbli / Bhupal',                       NULL,           NULL, NULL,       NULL,       0, 0, 3
    UNION ALL SELECT @RET_ROUTE, 'drop', 'Barauda',                              NULL,           NULL, 22.3072000, 73.1812000, 0, 0, 4
    UNION ALL SELECT @RET_ROUTE, 'drop', 'Surat',                                 'Final drop',   NULL, 21.1702000, 72.8311000, 0, 0, 5
  ) AS s
 WHERE @RET_ROUTE > 0;

-- =====================================================================
--  VERIFY — both should list the canonical names in order.
-- =====================================================================
-- SELECT route_id, stop_type, sort_order, stop_name, stop_time
--   FROM route_stops
--  WHERE route_id IN (@OUT_ROUTE, @RET_ROUTE)
--  ORDER BY route_id, stop_type, sort_order;
