-- =====================================================================
--  Upgrade: complete the RETURN fleet — Nepalgunj -> Ahmedabad (2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Why: the forward leg (Ahmedabad -> Nepalgunj) already has 5 buses
--  (r1, r2, r4, r5, r6) but the return leg had only ONE (r3). This adds the
--  4 missing return routes so BOTH directions offer 5 buses, mirroring the
--  forward fleet exactly: 3 sleeper + 2 seater each way.
--
--  Each bus now runs a forward leg AND a return leg:
--     bus 1 SHG Himalaya Express   seater   r1 forward / r3 return (existing)
--     bus 2 SHG Gandaki Sleeper    sleeper  r2 forward / r7 return (new)
--     bus 3 SHG Lumbini Deluxe     sleeper  r4 forward / r8 return (new)
--     bus 4 SHG Bheri Express      seater   r5 forward / r9 return (new)
--     bus 5 SHG Karnali AC Sleeper sleeper  r6 forward / r10 return (new)
--
--  Fares mirror each bus's forward base_fare (the per-seat fare). The
--  SHARING per-person fare is NOT set here — it stays direction-based in the
--  `cabin_pricing` setting (Nepal->India = 1800, 5% off online), which
--  already resolves from the route's to_city. Nothing about pricing changes.
--
--  Safe to re-run: INSERT IGNORE on the unique route_code key. Route stops
--  are not seeded for these (same as the forward placeholders) — add
--  boarding/drop points from Admin -> Routes if you want server-side stops.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `routes`
  (`route_code`,`from_city`,`to_city`,`bus_id`,`coach_type`,`path_id`,`dep_time`,`arr_time`,`day_offset`,`duration_text`,`distance_km`,`base_fare`,`amenities`,`crew_name`,`crew_phone`,`is_active`,`sort_order`)
VALUES
  ('r7','Nepalgunj','Ahmedabad',(SELECT id FROM buses WHERE bus_number='GJ-02-T-5580'),'sleeper','via_bahraich','17:00:00','00:54:00',2,'31h 54m',1375,2799.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,7),
  ('r8','Nepalgunj','Ahmedabad',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6011'),'sleeper','via_bahraich','17:30:00','01:39:00',2,'32h 09m',1375,2899.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,8),
  ('r9','Nepalgunj','Ahmedabad',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6022'),'seater','via_gorakhpur','18:30:00','01:55:00',2,'31h 25m',1375,2149.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','','',1,9),
  ('r10','Nepalgunj','Ahmedabad',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6033'),'sleeper','via_bahraich','19:00:00','03:09:00',2,'32h 09m',1375,2999.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,10);
