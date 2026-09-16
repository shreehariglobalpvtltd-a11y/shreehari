-- =============================================================
--  Rename boarding/drop point labels to the owner's new names
--  (Aug 2026 master prompt)
--
--  Old name                              → New name
--  ──────────────────────────────────────   ────────────────
--  Barauda                               → Baroda
--  Limbli / Bhupal                       → S Hari Parking
--  Hari Pvt. Ltd. Parking - Nana Chiloda → Nana Chiloda
--  Mehsana Head Office - Silver Complex  → Mehsana
--
--  Also removes "Mahesh Rawal" crew name from routes table.
--  Safe: uses UPDATE (not DELETE), preserves times/coords/order.
-- =============================================================

-- 1. Rename stops in route_stops (boarding + drop)
UPDATE route_stops SET stop_name = 'Baroda'
 WHERE stop_name = 'Barauda';

UPDATE route_stops SET stop_name = 'S Hari Parking'
 WHERE stop_name = 'Limbli / Bhupal';

UPDATE route_stops SET stop_name = 'Nana Chiloda', landmark = NULL
 WHERE stop_name = 'Hari Pvt. Ltd. Parking - Nana Chiloda';

UPDATE route_stops SET stop_name = 'Mehsana'
 WHERE stop_name = 'Mehsana Head Office - Silver Complex';

-- 2. Update main_points setting if it exists (JSON stored in settings table)
--    Actual columns: skey / svalue (not setting_key / setting_value)
UPDATE settings
   SET svalue = REPLACE(svalue, 'Barauda', 'Baroda')
 WHERE skey = 'main_points';

UPDATE settings
   SET svalue = REPLACE(svalue, 'Limbli / Bhupal', 'S Hari Parking')
 WHERE skey = 'main_points';

UPDATE settings
   SET svalue = REPLACE(svalue, 'Hari Pvt. Ltd. Parking - Nana Chiloda', 'Nana Chiloda')
 WHERE skey = 'main_points';

UPDATE settings
   SET svalue = REPLACE(svalue, 'Mehsana Head Office - Silver Complex', 'Mehsana')
 WHERE skey = 'main_points';

-- 3. Remove "Mahesh Rawal" from routes crew_name (not a known person)
UPDATE routes SET crew_name = '', crew_phone = NULL
 WHERE crew_name = 'Mahesh Rawal';

-- 4. Remove "Mahesh Rawal" from drivers table
UPDATE drivers SET full_name = '', is_active = 0
 WHERE full_name = 'Mahesh Rawal';

-- 5. Fix "Sliver Complex" → "Silver Complex" everywhere
UPDATE route_stops SET landmark = REPLACE(landmark, 'Sliver', 'Silver')
 WHERE landmark LIKE '%Sliver%';

UPDATE settings SET svalue = REPLACE(svalue, 'Sliver', 'Silver')
 WHERE svalue LIKE '%Sliver%';

-- Verify
SELECT stop_name, stop_time, sort_order FROM route_stops
 WHERE stop_type = 'boarding' ORDER BY route_id, sort_order;
