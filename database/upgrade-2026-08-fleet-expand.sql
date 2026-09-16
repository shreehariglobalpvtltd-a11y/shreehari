-- =====================================================================
--  Upgrade: expand the fleet to 5 forward buses (2026-08)
--  Run this ONCE on an existing live database (hPanel -> phpMyAdmin ->
--  select your database -> SQL tab -> paste -> Go).
--
--  Adds the 3 new placeholder buses (and their Ahmedabad->Nepalgunj routes)
--  to the SERVER tables, matching the buses the website already shows, so
--  the admin panel (Trips / Seat Map / Routes) and server-side booking know
--  the same fleet. Names & numbers are placeholders — rename/renumber them
--  from the admin console. Safe to re-run (INSERT IGNORE on the unique
--  bus_number / route_code keys). Route stops are not seeded for these
--  placeholders; add boarding/drop points from the admin console if needed.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `buses`
  (`bus_number`,`bus_name`,`coach_type`,`total_seats`,`amenities`,`is_active`)
VALUES
  ('GJ-02-T-6011','SHG Lumbini Deluxe','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1),
  ('GJ-02-T-6022','SHG Bheri Express','seater',40,'["AC","Charging Point","Water Bottle","Border Assistance"]',1),
  ('GJ-02-T-6033','SHG Karnali AC Sleeper','sleeper',40,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]',1);

INSERT IGNORE INTO `routes`
  (`route_code`,`from_city`,`to_city`,`bus_id`,`coach_type`,`path_id`,`dep_time`,`arr_time`,`day_offset`,`duration_text`,`distance_km`,`base_fare`,`amenities`,`crew_name`,`crew_phone`,`is_active`,`sort_order`)
VALUES
  ('r4','Ahmedabad','Nepalgunj',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6011'),'sleeper','via_bahraich','19:30:00','03:39:00',2,'32h 09m',1375,2899.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,4),
  ('r5','Ahmedabad','Nepalgunj',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6022'),'seater','via_gorakhpur','07:30:00','14:55:00',1,'31h 25m',1375,2149.00,'["AC","Charging Point","Water Bottle","Border Assistance"]','','',1,5),
  ('r6','Ahmedabad','Nepalgunj',(SELECT id FROM buses WHERE bus_number='GJ-02-T-6033'),'sleeper','via_bahraich','21:00:00','05:09:00',2,'32h 09m',1375,2999.00,'["AC Sleeper","Blanket","Charging Point","Water Bottle"]','','',1,6);
