-- =============================================================
--  Rename boarding/drop point labels — round 2 (Aug 28 2026)
--
--  Old name                         → New name
--  ─────────────────────────────────  ──────────────────────────────────
--  S Hari Parking                   → Emli Bhupal
--  Nana Chiloda                     → S Hari Parking, Nana Chiloda
--  Mehsana                          → Mehsana — Silver Complex
--
--  Safe: uses UPDATE (not DELETE), preserves times/coords/order.
--  Actual settings columns: skey / svalue
-- =============================================================

-- 1. Rename stops in route_stops (boarding + drop)
--    ORDER MATTERS: rename "S Hari Parking" BEFORE "Nana Chiloda"
--    gets its new compound name, to avoid a collision.

-- Step 1a: "S Hari Parking" → "Emli Bhupal" (stop_name only)
UPDATE route_stops SET stop_name = 'Emli Bhupal'
 WHERE stop_name = 'S Hari Parking';

-- Step 1b: "Nana Chiloda" → "S Hari Parking, Nana Chiloda"
UPDATE route_stops SET stop_name = 'S Hari Parking, Nana Chiloda'
 WHERE stop_name = 'Nana Chiloda';

-- Step 1c: "Mehsana" → "Mehsana — Silver Complex"
UPDATE route_stops SET stop_name = 'Mehsana — Silver Complex'
 WHERE stop_name = 'Mehsana';

-- 2. Update main_points setting if it exists
UPDATE settings SET svalue = REPLACE(svalue, 'S Hari Parking', 'Emli Bhupal')
 WHERE skey = 'main_points';

UPDATE settings SET svalue = REPLACE(svalue, 'Nana Chiloda', 'S Hari Parking, Nana Chiloda')
 WHERE skey = 'main_points';

UPDATE settings SET svalue = REPLACE(REPLACE(svalue, '"Mehsana"', '"Mehsana — Silver Complex"'), "'Mehsana'", "'Mehsana — Silver Complex'")
 WHERE skey = 'main_points';

-- Verify
SELECT stop_name, stop_time, sort_order, stop_type
  FROM route_stops
 ORDER BY route_id, stop_type, sort_order;
